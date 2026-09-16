<?php

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function removeBOM(string $str): string
{
    $bom = pack('H*', 'EFBBBF');
    if (substr($str, 0, 3) === $bom) {
        return substr($str, 3);
    }
    return $str;
}

function normalizeCsvText($value): string
{
    $value = removeBOM((string)$value);

    if (preg_match('//u', $value) !== 1) {
        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
    }

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }

    $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
    return trim((string)$value);
}

require_once __DIR__ . '/../mod_local/etl_address_validation.php';

function normalizeHeader(string $header): string
{
    $header = removeBOM($header);
    $header = preg_replace('/[\x00-\x1F\x7F]/u', '', $header);
    $header = strtolower(trim($header));

    $replace = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n'
    ];

    $header = strtr($header, $replace);
    $header = preg_replace('/\s+/', ' ', $header);

    return $header;
}

function detectDelimiter(string $filePath): string
{
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ';';
    }

    $firstLine = fgets($handle);
    fclose($handle);

    if ($firstLine === false) {
        return ';';
    }

    $semicolonCount = substr_count($firstLine, ';');
    $commaCount     = substr_count($firstLine, ',');

    return ($semicolonCount >= $commaCount) ? ';' : ',';
}

function resolveColumnIndexes(array $headers): array|false
{
    $requiredAliases = [
        'codigo' => ['codigo', 'codigo local', 'cod local', 'cod_local'],
    ];

    $optionalAliases = [
        'division'        => ['division', 'division local', 'division empresa', 'nombre division', 'id division'],
        'nombre_local'    => ['nombre local', 'nombre', 'nombre_local', 'local'],
        'direccion'       => ['direccion', 'dirección'],
        'comuna'          => ['comuna'],
        'cuenta'          => ['cuenta'],
        'cadena'          => ['cadena'],
        'zona'            => ['zona', 'nombre zona'],
        'distrito'        => ['distrito', 'nombre distrito'],
        'nombre_vendedor' => ['nombre vendedor', 'vendedor', 'vendedor nombre'],
        'jefe_venta'      => ['jefe de venta', 'jefe de ventas', 'jefe venta', 'jefe ventas', 'nombre jefe de venta', 'jefeventa'],
    ];

    // etlLookupKey elimina BOM, tildes, espacios, guiones y diferencias de
    // mayúsculas. Esto evita que Excel/Windows haga irreconocible una cabecera
    // visualmente correcta como "Código Local".
    $normalizedHeaders = array_map(static function ($header): string {
        return etlLookupKey(normalizeCsvText($header));
    }, $headers);
    $indexes = [];

    foreach ($requiredAliases as $logical => $possibles) {
        $found = false;
        $possibleKeys = array_map('etlLookupKey', $possibles);
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibleKeys, true)) {
                $indexes[$logical] = $idx;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    foreach ($optionalAliases as $logical => $possibles) {
        $indexes[$logical] = null;
        $possibleKeys = array_map('etlLookupKey', $possibles);
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibleKeys, true)) {
                $indexes[$logical] = $idx;
                break;
            }
        }
    }

    return $indexes;
}

function isNonEmptyCsvRow(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') {
            return true;
        }
    }
    return false;
}

function publicUrlFromPath(string $absolutePath, string $documentRoot): string
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if (strpos($absolutePath, $documentRoot) === 0) {
        return substr($absolutePath, strlen($documentRoot));
    }

    return '';
}

function appendFailureToReport(string $reportPath, array $failure): void
{
    $fp = fopen($reportPath, 'a');
    if (!$fp) {
        return;
    }

    fputcsv($fp, [
        $failure['line'] ?? '',
        $failure['codigo'] ?? '',
        $failure['nombre'] ?? '',
        $failure['division_id'] ?? '',
        $failure['division_archivo'] ?? '',
        $failure['estado_validacion'] ?? 'ERROR_IMPORTACION',
        $failure['direccion_original'] ?? '',
        $failure['direccion_limpia'] ?? '',
        $failure['direccion_google'] ?? '',
        $failure['comuna'] ?? '',
        $failure['distrito'] ?? '',
        $failure['zona'] ?? '',
        $failure['region'] ?? '',
        $failure['zona_archivo'] ?? '',
        $failure['estado_zona'] ?? '',
        $failure['distrito_archivo'] ?? '',
        $failure['estado_distrito'] ?? '',
        $failure['vendedor_archivo'] ?? '',
        $failure['estado_vendedor'] ?? '',
        $failure['jefe_venta_archivo'] ?? '',
        $failure['estado_jefe_venta'] ?? '',
        $failure['reason'] ?? '',
        $failure['response_id'] ?? ''
    ], ';');

    fclose($fp);
}

function appendValidationRecord(string $validationPath, array $record): bool
{
    $fp = fopen($validationPath, 'a');
    if (!$fp) {
        return false;
    }

    $written = fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fclose($fp);
    return $written !== false;
}

function appendPreviewRecord(string $previewPath, array $record): bool
{
    $fp = fopen($previewPath, 'a');
    if (!$fp) {
        return false;
    }

    $written = fputcsv($fp, [
        $record['line'] ?? '',
        $record['codigo'] ?? '',
        $record['division_id'] ?? '',
        $record['division_archivo'] ?? '',
        $record['tipo_actualizacion'] ?? '',
        $record['nombre'] ?? '',
        $record['direccion_actual'] ?? '',
        $record['direccion_original'] ?? '',
        $record['direccion_limpia'] ?? '',
        $record['direccion_nueva'] ?? '',
        $record['direccion_google'] ?? '',
        $record['comuna'] ?? '',
        $record['distrito'] ?? '',
        $record['zona'] ?? '',
        $record['region'] ?? '',
        $record['cuenta'] ?? '',
        $record['cadena'] ?? '',
        $record['zona_actual'] ?? '',
        $record['zona_archivo'] ?? '',
        $record['estado_zona'] ?? '',
        $record['distrito_actual'] ?? '',
        $record['distrito_archivo'] ?? '',
        $record['estado_distrito'] ?? '',
        $record['vendedor_actual'] ?? '',
        $record['vendedor_archivo'] ?? '',
        $record['estado_vendedor'] ?? '',
        $record['jefe_venta_actual'] ?? '',
        $record['jefe_venta_archivo'] ?? '',
        $record['estado_jefe_venta'] ?? '',
        $record['estado_validacion'] ?? '',
        $record['reason'] ?? '',
        $record['lat'] ?? '',
        $record['lng'] ?? '',
        $record['response_id'] ?? ''
    ], ';');

    fclose($fp);
    return $written !== false;
}

function etlInspectAssignmentPerson(mysqli $conn, string $type, string $name): array
{
    $definitions = [
        'vendedor' => ['table' => 'vendedor', 'column' => 'nombre_vendedor'],
        'jefe_venta' => ['table' => 'jefe_venta', 'column' => 'nombre'],
    ];
    $name = normalizeCsvText($name);
    if ($name === '') {
        return ['id' => null, 'matches' => 0, 'status' => 'SIN_CAMBIO', 'name' => ''];
    }
    if (!isset($definitions[$type])) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA', 'name' => ''];
    }

    $name = etlNormalizeOptionalPerson($name);
    $definition = $definitions[$type];
    $sql = "SELECT id FROM {$definition['table']} WHERE LOWER(TRIM({$definition['column']})) = LOWER(TRIM(?)) ORDER BY id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA', 'name' => $name];
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($id);
    $ids = [];
    while ($stmt->fetch()) {
        $ids[] = (int)$id;
    }
    $stmt->close();

    $matches = count($ids);
    return [
        'id' => $ids[0] ?? null,
        'matches' => $matches,
        'status' => $matches > 1 ? 'DUPLICADO_EN_BASE' : ($matches === 1 ? 'EXISTENTE' : 'NUEVO'),
        'name' => $name,
    ];
}

function etlGetOrCreateAssignmentPerson(mysqli $conn, string $type, string $name, array &$errors, int $lineNumber)
{
    $inspection = etlInspectAssignmentPerson($conn, $type, $name);
    if ($inspection['status'] === 'SIN_CAMBIO') {
        return null;
    }
    if ($inspection['status'] === 'ERROR_CONSULTA') {
        $errors[] = "No se pudo consultar el catálogo '$type' en la línea $lineNumber: " . $conn->error;
        return false;
    }
    if ($inspection['status'] === 'DUPLICADO_EN_BASE') {
        $errors[] = "El nombre '{$inspection['name']}' aparece más de una vez en el catálogo '$type'.";
        return false;
    }

    $canonicalName = (string)$inspection['name'];
    if ($inspection['id'] !== null) {
        $id = (int)$inspection['id'];
        if ($type === 'vendedor') {
            $stmt = $conn->prepare('UPDATE vendedor SET nombre_vendedor=? WHERE id=?');
        } else {
            $stmt = $conn->prepare('UPDATE jefe_venta SET nombre=? WHERE id=?');
        }
        if (!$stmt) {
            $errors[] = "No se pudo normalizar '$canonicalName' en la línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt->bind_param('si', $canonicalName, $id);
        if (!$stmt->execute()) {
            $errors[] = "No se pudo normalizar '$canonicalName' en la línea $lineNumber: " . $stmt->error;
            $stmt->close();
            return false;
        }
        $stmt->close();
        return $id;
    }

    if ($type === 'vendedor') {
        $externalId = 0;
        $stmt = $conn->prepare('INSERT INTO vendedor (id_vendedor, nombre_vendedor) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('is', $externalId, $canonicalName);
        }
    } else {
        $stmt = $conn->prepare('INSERT INTO jefe_venta (nombre) VALUES (?)');
        if ($stmt) {
            $stmt->bind_param('s', $canonicalName);
        }
    }
    if (!$stmt || !$stmt->execute()) {
        $errors[] = "No se pudo crear '$canonicalName' en el catálogo '$type' (línea $lineNumber): " . ($stmt ? $stmt->error : $conn->error);
        if ($stmt) {
            $stmt->close();
        }
        return false;
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function etlInspectTerritoryAssignment(mysqli $conn, string $type, string $name, ?int $zoneId = null): array
{
    $name = etlUpper($name);
    if ($name === '') {
        return ['id' => null, 'zone_id' => $zoneId, 'matches' => 0, 'status' => 'SIN_CAMBIO', 'name' => ''];
    }

    if ($type === 'zona') {
        $stmt = $conn->prepare('SELECT id FROM zona WHERE LOWER(TRIM(nombre_zona))=LOWER(TRIM(?)) ORDER BY id');
        if ($stmt) {
            $stmt->bind_param('s', $name);
        }
    } elseif ($type === 'distrito' && $zoneId !== null && $zoneId > 0) {
        $stmt = $conn->prepare('SELECT id, id_zona FROM distrito WHERE LOWER(TRIM(nombre_distrito))=LOWER(TRIM(?)) AND id_zona=? ORDER BY id');
        if ($stmt) {
            $stmt->bind_param('si', $name, $zoneId);
        }
    } elseif ($type === 'distrito') {
        $stmt = $conn->prepare('SELECT id, id_zona FROM distrito WHERE LOWER(TRIM(nombre_distrito))=LOWER(TRIM(?)) ORDER BY id');
        if ($stmt) {
            $stmt->bind_param('s', $name);
        }
    } else {
        return ['id' => null, 'zone_id' => $zoneId, 'matches' => 0, 'status' => 'ERROR_CONSULTA', 'name' => $name];
    }

    if (!$stmt || !$stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }
        return ['id' => null, 'zone_id' => $zoneId, 'matches' => 0, 'status' => 'ERROR_CONSULTA', 'name' => $name];
    }

    $ids = [];
    $zoneIds = [];
    if ($type === 'zona') {
        $stmt->bind_result($id);
        while ($stmt->fetch()) {
            $ids[] = (int)$id;
        }
    } else {
        $stmt->bind_result($id, $districtZoneId);
        while ($stmt->fetch()) {
            $ids[] = (int)$id;
            $zoneIds[] = (int)$districtZoneId;
        }
    }
    $stmt->close();

    $matches = count($ids);
    return [
        'id' => $ids[0] ?? null,
        'zone_id' => $type === 'zona' ? ($ids[0] ?? null) : ($zoneIds[0] ?? $zoneId),
        'matches' => $matches,
        'status' => $matches > 1 ? 'DUPLICADO_EN_BASE' : ($matches === 1 ? 'EXISTENTE' : 'NUEVO'),
        'name' => $name,
    ];
}

function updateLocalFieldsByCodigo(
    mysqli $conn,
    string $codigo,
    int $divisionId,
    array $updates,
    array &$errors,
    int $lineNumber
): bool {
    $definitions = [
        'nombre' => ['column' => 'nombre', 'type' => 's'],
        'direccion_original' => ['column' => 'direccion_original', 'type' => 's'],
        'direccion' => ['column' => 'direccion', 'type' => 's'],
        'direccion_google' => ['column' => 'direccion_google', 'type' => 's'],
        'estado_address_validation' => ['column' => 'estado_address_validation', 'type' => 's'],
        'google_response_id' => ['column' => 'google_response_id', 'type' => 's'],
        'id_comuna' => ['column' => 'id_comuna', 'type' => 'i'],
        'lat' => ['column' => 'lat', 'type' => 'd'],
        'lng' => ['column' => 'lng', 'type' => 'd'],
        'id_cuenta' => ['column' => 'id_cuenta', 'type' => 'i'],
        'id_cadena' => ['column' => 'id_cadena', 'type' => 'i'],
        'id_zona' => ['column' => 'id_zona', 'type' => 'i'],
        'id_distrito' => ['column' => 'id_distrito', 'type' => 'i'],
        'id_jefe_venta' => ['column' => 'id_jefe_venta', 'type' => 'i'],
        'id_vendedor' => ['column' => 'id_vendedor', 'type' => 'i'],
    ];
    $sets = [];
    $types = '';
    $params = [];
    foreach ($definitions as $key => $definition) {
        if (!array_key_exists($key, $updates)) {
            continue;
        }
        $sets[] = $definition['column'] . '=?';
        $types .= $definition['type'];
        $params[] = $updates[$key];
    }
    if (!$sets) {
        $errors[] = "No hay campos para actualizar en el local '$codigo' (línea $lineNumber).";
        return false;
    }
    if (!empty($updates['direccion_validada'])) {
        $sets[] = 'direccion_validada_at=NOW()';
    }
    $sets[] = 'updated_at=NOW()';
    $sql = 'UPDATE local SET ' . implode(', ', $sets) . ' WHERE codigo=? AND id_division=? AND deleted_at IS NULL';
    $types .= 'si';
    $params[] = $codigo;
    $params[] = $divisionId;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "No se pudo preparar la actualización del local '$codigo' en la línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $errors[] = "No se pudo actualizar el local '$codigo' en la línea $lineNumber: " . $stmt->error;
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}

function getRegionId(mysqli $conn, string $regionName, array &$errors, int $lineNumber): int|false
{
    $stmt = $conn->prepare("SELECT id FROM region WHERE LOWER(region) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT region en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $regionName);
    $stmt->execute();
    $stmt->bind_result($regionId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$regionId;
    }

    $stmtIns = $conn->prepare("INSERT INTO region (region) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT region en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $regionName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando región '$regionName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getComunaId(mysqli $conn, string $comunaName, int $regionId, array &$errors, int $lineNumber): int|false
{
    $stmt = $conn->prepare("SELECT id FROM comuna WHERE LOWER(comuna) = LOWER(?) AND id_region = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT comuna en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('si', $comunaName, $regionId);
    $stmt->execute();
    $stmt->bind_result($comunaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$comunaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO comuna (comuna, id_region) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT comuna en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $comunaName, $regionId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando comuna '$comunaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getCuentaId(mysqli $conn, string $cuentaName, array &$errors, int $lineNumber): int|false
{
    $stmt = $conn->prepare("SELECT id FROM cuenta WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT cuenta en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $cuentaName);
    $stmt->execute();
    $stmt->bind_result($cuentaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$cuentaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO cuenta (nombre) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT cuenta en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $cuentaName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando cuenta '$cuentaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getZonaId(mysqli $conn, string $zonaName, array &$errors, int $lineNumber): int|false
{
    $zonaName = etlUpper($zonaName);
    $stmt = $conn->prepare("SELECT id FROM zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT zona en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $zonaName);
    $stmt->execute();
    $stmt->bind_result($zonaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        $stmtUpdate = $conn->prepare('UPDATE zona SET nombre_zona=? WHERE id=?');
        if (!$stmtUpdate) {
            $errors[] = "Error normalizando zona '$zonaName' en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmtUpdate->bind_param('si', $zonaName, $zonaId);
        if (!$stmtUpdate->execute()) {
            $errors[] = "Error normalizando zona '$zonaName' en línea $lineNumber: " . $stmtUpdate->error;
            $stmtUpdate->close();
            return false;
        }
        $stmtUpdate->close();
        return (int)$zonaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO zona (nombre_zona) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT zona en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $zonaName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando zona '$zonaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getCadenaData(mysqli $conn, string $cadenaName, ?int $cuentaId, array &$errors, int $lineNumber): array|false
{
    if ($cuentaId !== null) {
        $stmt = $conn->prepare("
            SELECT id, id_cuenta
            FROM cadena
            WHERE LOWER(nombre) = LOWER(?)
              AND id_cuenta = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT cadena en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('si', $cadenaName, $cuentaId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, id_cuenta
            FROM cadena
            WHERE LOWER(nombre) = LOWER(?)
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT cadena en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('s', $cadenaName);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        return [
            'id'        => (int)$row['id'],
            'id_cuenta' => isset($row['id_cuenta']) ? (int)$row['id_cuenta'] : null
        ];
    }

    if ($cuentaId === null) {
        $errors[] = "La cadena '$cadenaName' no existe y no se indicó una cuenta para crearla en línea $lineNumber.";
        return false;
    }

    $stmtIns = $conn->prepare("INSERT INTO cadena (nombre, id_cuenta) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT cadena en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $cadenaName, $cuentaId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando cadena '$cadenaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return [
        'id'        => $newId,
        'id_cuenta' => $cuentaId
    ];
}

function getDistritoData(mysqli $conn, string $distritoName, ?int $zonaId, array &$errors, int $lineNumber): array|false
{
    $distritoName = etlUpper($distritoName);
    if ($zonaId !== null) {
        $stmt = $conn->prepare("
            SELECT id, id_zona
            FROM distrito
            WHERE LOWER(nombre_distrito) = LOWER(?)
              AND id_zona = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT distrito en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('si', $distritoName, $zonaId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, id_zona
            FROM distrito
            WHERE LOWER(nombre_distrito) = LOWER(?)
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT distrito en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('s', $distritoName);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $districtId = (int)$row['id'];
        $stmtUpdate = $conn->prepare('UPDATE distrito SET nombre_distrito=? WHERE id=?');
        if (!$stmtUpdate) {
            $errors[] = "Error normalizando distrito '$distritoName' en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmtUpdate->bind_param('si', $distritoName, $districtId);
        if (!$stmtUpdate->execute()) {
            $errors[] = "Error normalizando distrito '$distritoName' en línea $lineNumber: " . $stmtUpdate->error;
            $stmtUpdate->close();
            return false;
        }
        $stmtUpdate->close();
        return [
            'id'      => $districtId,
            'id_zona' => isset($row['id_zona']) ? (int)$row['id_zona'] : null
        ];
    }

    if ($zonaId === null) {
        $errors[] = "El distrito '$distritoName' no existe y no se indicó una zona para crearlo en línea $lineNumber.";
        return false;
    }

    $stmtIns = $conn->prepare("INSERT INTO distrito (nombre_distrito, id_zona) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT distrito en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $distritoName, $zonaId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando distrito '$distritoName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return [
        'id'      => $newId,
        'id_zona' => $zonaId
    ];
}

function getExistingLocalByCode(mysqli $conn, string $codigo, int $divisionId, array &$errors, int $lineNumber): array|false
{
    $sql = "
        SELECT
            l.id,
            l.codigo,
            l.id_division,
            l.nombre,
            l.direccion,
            l.direccion_original,
            l.direccion_google,
            l.estado_address_validation,
            l.google_response_id,
            l.lat,
            l.lng,
            l.id_comuna,
            l.id_cuenta,
            l.id_cadena,
            l.id_zona,
            l.id_distrito,
            l.id_vendedor,
            l.id_jefe_venta,
            c.comuna,
            r.region,
            cu.nombre AS cuenta,
            ca.nombre AS cadena,
            ca.id_cuenta AS cadena_id_cuenta,
            z.nombre_zona AS zona,
            d.nombre_distrito AS distrito,
            d.id_zona AS distrito_id_zona,
            v.nombre_vendedor,
            jv.nombre AS jefe_venta
        FROM local l
        LEFT JOIN comuna c   ON c.id = l.id_comuna
        LEFT JOIN region r   ON r.id = c.id_region
        LEFT JOIN cuenta cu  ON cu.id = l.id_cuenta
        LEFT JOIN cadena ca  ON ca.id = l.id_cadena
        LEFT JOIN zona z     ON z.id = l.id_zona
        LEFT JOIN distrito d ON d.id = l.id_distrito
        LEFT JOIN vendedor v ON v.id = l.id_vendedor
        LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
        WHERE l.codigo = ?
          AND l.id_division = ?
          AND l.deleted_at IS NULL
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "Error preparando SELECT local en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('si', $codigo, $divisionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $errors[] = "El código '$codigo' no existe en la división seleccionada (ID $divisionId).";
        return false;
    }

    return $row;
}

function geocodeRequest(string $address, string $apiKey): array|false
{
    $addrEnc = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addrEnc}&key={$apiKey}&components=country:CL";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $resp = json_decode($response, true);
    if (!is_array($resp) || !isset($resp['status'])) {
        return false;
    }

    if ($resp['status'] === 'OK' && !empty($resp['results'][0]['geometry']['location'])) {
        return [
            'lat' => (float)$resp['results'][0]['geometry']['location']['lat'],
            'lng' => (float)$resp['results'][0]['geometry']['location']['lng']
        ];
    }

    return false;
}

function geocodeAddress(string $direccion, string $comuna, string $region, string $apiKey, array &$errors, int $lineNumber): array|false
{
    $candidates = [
        "{$direccion}, {$comuna}, {$region}, Chile",
        "{$direccion}, {$comuna}, Chile"
    ];

    foreach ($candidates as $candidate) {
        $coords = geocodeRequest($candidate, $apiKey);
        if ($coords !== false) {
            return $coords;
        }
    }

    $errors[] = "Geocoding fallido en línea $lineNumber para la dirección '{$direccion}, {$comuna}, {$region}'.";
    return false;
}

function updateLocalByCodigo(
    mysqli $conn,
    string $codigo,
    int $divisionId,
    string $nombre,
    string $direccionOriginal,
    string $direccion,
    string $direccionGoogle,
    string $validationStatus,
    string $googleResponseId,
    int $comunaId,
    float $lat,
    float $lng,
    ?int $cuentaId,
    ?int $cadenaId,
    ?int $zonaId,
    ?int $distritoId,
    ?int $jefeVentaId,
    ?int $vendedorId,
    array &$errors,
    int $lineNumber
): bool {
    $sql = "
        UPDATE local
        SET
            nombre = ?,
            direccion_original = ?,
            direccion = ?,
            direccion_google = ?,
            estado_address_validation = ?,
            google_response_id = ?,
            direccion_validada_at = NOW(),
            id_comuna = ?,
            lat = ?,
            lng = ?,
            id_cuenta = ?,
            id_cadena = ?,
            id_zona = ?,
            id_distrito = ?,
            id_jefe_venta = ?,
            id_vendedor = ?,
            updated_at = NOW()
        WHERE codigo = ?
          AND id_division = ?
          AND deleted_at IS NULL
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "Error preparando UPDATE local en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param(
        'ssssssiddiiiiiisi',
        $nombre,
        $direccionOriginal,
        $direccion,
        $direccionGoogle,
        $validationStatus,
        $googleResponseId,
        $comunaId,
        $lat,
        $lng,
        $cuentaId,
        $cadenaId,
        $zonaId,
        $distritoId,
        $jefeVentaId,
        $vendedorId,
        $codigo,
        $divisionId
    );

    if (!$stmt->execute()) {
        $errors[] = "Error actualizando local '$codigo' de la división $divisionId en línea $lineNumber: " . $stmt->error;
        $stmt->close();
        return false;
    }

    $stmt->close();

    return true;
}

function updateLocalAssignmentsByCodigo(
    mysqli $conn,
    string $codigo,
    int $divisionId,
    ?int $jefeVentaId,
    ?int $vendedorId,
    array &$errors,
    int $lineNumber
): bool {
    $stmt = $conn->prepare("
        UPDATE local
        SET id_jefe_venta=?, id_vendedor=?, updated_at=NOW()
        WHERE codigo=?
          AND id_division=?
          AND deleted_at IS NULL
    ");
    if (!$stmt) {
        $errors[] = "Error preparando responsables del local '$codigo' en la línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('iisi', $jefeVentaId, $vendedorId, $codigo, $divisionId);
    if (!$stmt->execute()) {
        $errors[] = "Error actualizando responsables del local '$codigo' en la línea $lineNumber: " . $stmt->error;
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}

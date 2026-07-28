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
        'codigo'       => ['codigo', 'codigo local', 'cod local', 'cod_local'],
        'nombre_local' => ['nombre local', 'nombre', 'nombre_local', 'local'],
        'direccion'    => ['direccion', 'dirección'],
        'comuna'       => ['comuna'],
    ];

    $optionalAliases = [
        'cuenta'   => ['cuenta'],
        'cadena'   => ['cadena']
    ];

    $normalizedHeaders = array_map('normalizeHeader', $headers);
    $indexes = [];

    foreach ($requiredAliases as $logical => $possibles) {
        $found = false;
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibles, true)) {
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
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibles, true)) {
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
        $failure['estado_validacion'] ?? 'ERROR_IMPORTACION',
        $failure['direccion_original'] ?? '',
        $failure['direccion_limpia'] ?? '',
        $failure['direccion_google'] ?? '',
        $failure['comuna'] ?? '',
        $failure['distrito'] ?? '',
        $failure['zona'] ?? '',
        $failure['region'] ?? '',
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
        $record['estado_validacion'] ?? '',
        $record['reason'] ?? '',
        $record['lat'] ?? '',
        $record['lng'] ?? '',
        $record['response_id'] ?? ''
    ], ';');

    fclose($fp);
    return $written !== false;
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
        return [
            'id'      => (int)$row['id'],
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
            l.lat,
            l.lng,
            l.id_comuna,
            l.id_cuenta,
            l.id_cadena,
            l.id_zona,
            l.id_distrito,
            c.comuna,
            r.region,
            cu.nombre AS cuenta,
            ca.nombre AS cadena,
            ca.id_cuenta AS cadena_id_cuenta,
            z.nombre_zona AS zona,
            d.nombre_distrito AS distrito,
            d.id_zona AS distrito_id_zona
        FROM local l
        LEFT JOIN comuna c   ON c.id = l.id_comuna
        LEFT JOIN region r   ON r.id = c.id_region
        LEFT JOIN cuenta cu  ON cu.id = l.id_cuenta
        LEFT JOIN cadena ca  ON ca.id = l.id_cadena
        LEFT JOIN zona z     ON z.id = l.id_zona
        LEFT JOIN distrito d ON d.id = l.id_distrito
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
        'ssssssiddiiiisi',
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
        $codigo,
        $divisionId
    );

    if (!$stmt->execute()) {
        $errors[] = "Error actualizando local '$codigo' de la división $divisionId en línea $lineNumber: " . $stmt->error;
        $stmt->close();
        return false;
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($affectedRows < 1) {
        $errors[] = "El local '$codigo' de la división $divisionId no fue modificado en la línea $lineNumber.";
        return false;
    }

    return true;
}

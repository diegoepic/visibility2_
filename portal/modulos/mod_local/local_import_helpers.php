<?php

require_once __DIR__ . '/../mod_etl/etl_locales_helpers.php';

function resolveLocalImportIndexes(array $headers): array|false
{
    $aliases = [
        'codigo' => ['codigo', 'codigo local'],
        'canal' => ['canal'],
        'subcanal' => ['subcanal'],
        'cuenta' => ['cuenta'],
        'cadena' => ['cadena'],
        'nombre_local' => ['nombre local', 'nombre'],
        'direccion' => ['direccion'],
        'comuna' => ['comuna'],
        'nombre_vendedor' => ['nombre vendedor', 'vendedor'],
        'jefe_venta' => ['jefe de venta', 'jefe venta']
    ];
    $normalized = array_map('normalizeHeader', $headers);
    $indexes = [];
    foreach ($aliases as $field => $names) {
        $indexes[$field] = null;
        foreach ($normalized as $index => $header) {
            if (in_array($header, $names, true)) {
                $indexes[$field] = $index;
                break;
            }
        }
        if ($indexes[$field] === null) {
            return false;
        }
    }
    return $indexes;
}

function localImportAcquireLock(mysqli $conn, int $jobId): bool
{
    $name = 'local_import_job_' . $jobId;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($value);
    $stmt->fetch();
    $stmt->close();
    return (int)$value === 1;
}

function localImportReleaseLock(mysqli $conn, int $jobId): void
{
    $name = 'local_import_job_' . $jobId;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if ($stmt) {
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }
}

function localImportInspectPerson(mysqli $conn, string $table, string $column, string $name): array
{
    $name = etlNormalizeOptionalPerson($name);
    $allowed = [
        'vendedor' => 'nombre_vendedor',
        'jefe_venta' => 'nombre'
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA'];
    }
    $sql = "SELECT id FROM $table WHERE LOWER(TRIM($column)) = LOWER(TRIM(?)) ORDER BY id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA'];
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($id);
    $ids = [];
    while ($stmt->fetch()) {
        $ids[] = (int)$id;
    }
    $stmt->close();
    $count = count($ids);
    return [
        'id' => $ids[0] ?? null,
        'matches' => $count,
        'status' => $count > 1 ? 'DUPLICADO_EN_BASE' : ($count === 1 ? 'EXISTENTE' : 'NUEVO')
    ];
}

function localImportGetPersonId(mysqli $conn, string $table, string $column, string $name, array &$errors, int $line): int|false
{
    $name = etlNormalizeOptionalPerson($name);
    $inspection = localImportInspectPerson($conn, $table, $column, $name);
    if ($inspection['id'] !== null) {
        return (int)$inspection['id'];
    }
    if ($inspection['status'] === 'ERROR_CONSULTA') {
        $errors[] = "No se pudo consultar $table en linea $line: " . $conn->error;
        return false;
    }
    if ($table === 'vendedor') {
        $externalId = 0;
        $stmt = $conn->prepare('INSERT INTO vendedor (id_vendedor, nombre_vendedor) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('is', $externalId, $name);
        }
    } else {
        $stmt = $conn->prepare('INSERT INTO jefe_venta (nombre) VALUES (?)');
        if ($stmt) {
            $stmt->bind_param('s', $name);
        }
    }
    if (!$stmt || !$stmt->execute()) {
        $errors[] = "No se pudo crear $table '$name' en linea $line: " . ($stmt ? $stmt->error : $conn->error);
        if ($stmt) {
            $stmt->close();
        }
        return false;
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function localImportGetSimpleId(mysqli $conn, string $type, string $name, ?int $parentId, array &$errors, int $line): int|false
{
    $definitions = [
        'canal' => ['table' => 'canal', 'column' => 'nombre_canal', 'parent' => null, 'parent_column' => null],
        'subcanal' => ['table' => 'subcanal', 'column' => 'nombre_subcanal', 'parent' => true, 'parent_column' => 'id_canal']
    ];
    if (!isset($definitions[$type])) {
        $errors[] = "Catalogo invalido en linea $line.";
        return false;
    }
    $d = $definitions[$type];
    if ($d['parent']) {
        $sql = "SELECT id FROM {$d['table']} WHERE LOWER({$d['column']})=LOWER(?) AND {$d['parent_column']}=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('si', $name, $parentId);
        }
    } else {
        $sql = "SELECT id FROM {$d['table']} WHERE LOWER({$d['column']})=LOWER(?) LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $name);
        }
    }
    if (!$stmt) {
        $errors[] = "No se pudo consultar $type en linea $line: " . $conn->error;
        return false;
    }
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists) {
        return (int)$id;
    }
    if ($d['parent']) {
        $sql = "INSERT INTO {$d['table']} ({$d['column']}, {$d['parent_column']}) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('si', $name, $parentId);
        }
    } else {
        $sql = "INSERT INTO {$d['table']} ({$d['column']}) VALUES (?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $name);
        }
    }
    if (!$stmt || !$stmt->execute()) {
        $errors[] = "No se pudo crear $type '$name' en linea $line: " . ($stmt ? $stmt->error : $conn->error);
        if ($stmt) {
            $stmt->close();
        }
        return false;
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function localImportExists(mysqli $conn, string $codigo, int $divisionId, array &$errors, int $line): ?bool
{
    $stmt = $conn->prepare('SELECT id FROM local WHERE codigo=? AND id_division=? LIMIT 1');
    if (!$stmt) {
        $errors[] = "No se pudo validar el codigo en linea $line: " . $conn->error;
        return null;
    }
    $stmt->bind_param('si', $codigo, $divisionId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function localImportInsert(mysqli $conn, array $data, array &$errors, int $line): bool
{
    $sql = 'INSERT INTO local (codigo,nombre,direccion,direccion_original,direccion_google,estado_address_validation,google_response_id,id_cuenta,id_cadena,id_comuna,id_empresa,id_canal,id_subcanal,lat,lng,relevancia,id_zona,id_distrito,id_jefe_venta,id_vendedor,id_division,direccion_validada_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "No se pudo preparar el local en linea $line: " . $conn->error;
        return false;
    }
    $stmt->bind_param(
        'sssssssiiiiiiddiiiiii',
        $data['codigo'], $data['nombre'], $data['direccion'], $data['direccion_original'],
        $data['direccion_google'], $data['estado'], $data['response_id'], $data['id_cuenta'],
        $data['id_cadena'], $data['id_comuna'], $data['id_empresa'], $data['id_canal'],
        $data['id_subcanal'], $data['lat'], $data['lng'], $data['relevancia'], $data['id_zona'],
        $data['id_distrito'], $data['id_jefe_venta'], $data['id_vendedor'], $data['id_division']
    );
    if (!$stmt->execute()) {
        $errors[] = "No se pudo insertar el local '{$data['codigo']}' en linea $line: " . $stmt->error;
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}

function localImportAppendPreview(string $path, array $r): bool
{
    $fp = fopen($path, 'a');
    if (!$fp) {
        return false;
    }
    $ok = fputcsv($fp, [
        $r['codigo'] ?? '', $r['canal'] ?? '', $r['subcanal'] ?? '', $r['cuenta'] ?? '', $r['cadena'] ?? '',
        $r['nombre'] ?? '', $r['direccion_nueva'] ?? '', $r['comuna'] ?? '', $r['distrito'] ?? '',
        $r['zona'] ?? '', $r['region'] ?? '', 0, $r['vendedor_id'] ?? 'SE CREARA',
        $r['nombre_vendedor'] ?? '', $r['jefe_venta'] ?? '', $r['jefe_id'] ?? 'SE CREARA',
        $r['vendedor_status'] ?? '', $r['jefe_status'] ?? '', $r['direccion_original'] ?? '',
        $r['direccion_limpia'] ?? '', $r['direccion_google'] ?? '', $r['comuna_original'] ?? '',
        $r['comuna_google'] ?? '', $r['estado'] ?? '', $r['motivo'] ?? '', $r['lat'] ?? '',
        $r['lng'] ?? '', $r['response_id'] ?? '', $r['line'] ?? ''
    ], ';');
    fclose($fp);
    return $ok !== false;
}

function localImportAppendFailure(string $path, array $r, string $reason = ''): void
{
    $fp = fopen($path, 'a');
    if (!$fp) {
        return;
    }
    fputcsv($fp, [
        $r['line'] ?? '', $r['codigo'] ?? '', $r['nombre'] ?? '', $r['estado'] ?? 'ERROR_IMPORTACION',
        $r['direccion_original'] ?? '', $r['direccion_limpia'] ?? '', $r['direccion_google'] ?? '',
        $r['comuna'] ?? '', $r['distrito'] ?? '', $r['zona'] ?? '', $r['region'] ?? '',
        $reason !== '' ? $reason : ($r['motivo'] ?? ''), $r['response_id'] ?? ''
    ], ';');
    fclose($fp);
}

function localImportAppendAccepted(string $path, array $r): void
{
    $fp = fopen($path, 'a');
    if (!$fp) {
        return;
    }
    fputcsv($fp, [
        $r['line'], $r['codigo'], $r['nombre'], $r['estado'], $r['direccion_original'],
        $r['direccion_limpia'], $r['direccion_google'], $r['direccion_nueva'], $r['comuna'],
        $r['distrito'], $r['zona'], $r['region'], $r['cadena'], $r['cuenta'], $r['lat'],
        $r['lng'], $r['response_id']
    ], ';');
    fclose($fp);
}

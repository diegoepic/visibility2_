<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require __DIR__ . '/etl_locales_helpers.php';

if (!isset($usuario_id)) {
    jsonResponse(['success' => false, 'message' => 'Usuario no autenticado.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken    = trim($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    jsonResponse(['success' => false, 'message' => 'Token CSRF inválido.'], 400);
}

$jobId = (int)($_POST['job_id'] ?? 0);
$limit = (int)($_POST['limit'] ?? 1000);
$limit = ($limit > 0 && $limit <= 1000) ? $limit : 1000;

if ($jobId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Job inválido.'], 400);
}

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("
    SELECT *
    FROM etl_locales_jobs
    WHERE id = ?
      AND user_id = ?
    LIMIT 1
");
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'Error preparando consulta job: ' . $conn->error], 500);
}

$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$job = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    jsonResponse(['success' => false, 'message' => 'Job no encontrado.'], 404);
}

if (!file_exists($job['file_path'])) {
    $stmtErr = $conn->prepare("UPDATE etl_locales_jobs SET status='failed', last_error='Archivo CSV no encontrado', updated_at=NOW() WHERE id=? LIMIT 1");
    if ($stmtErr) {
        $stmtErr->bind_param('i', $jobId);
        $stmtErr->execute();
        $stmtErr->close();
    }
    jsonResponse(['success' => false, 'message' => 'El archivo del job no existe.'], 500);
}

if ($job['status'] === 'completed') {
    $reportUrl = $job['report_path'] ? publicUrlFromPath($job['report_path'], $_SERVER['DOCUMENT_ROOT']) : '';
    $progress  = ((int)$job['total_rows'] > 0)
        ? round((((int)$job['processed_rows']) / ((int)$job['total_rows'])) * 100, 2)
        : 100;

    jsonResponse([
        'success'        => true,
        'done'           => true,
        'job_id'         => (int)$job['id'],
        'total_rows'     => (int)$job['total_rows'],
        'processed_rows' => (int)$job['processed_rows'],
        'updated_rows'   => (int)$job['updated_rows'],
        'failed_rows'    => (int)$job['failed_rows'],
        'batch_updated'  => 0,
        'batch_failed'   => 0,
        'progress'       => $progress,
        'reportUrl'      => $reportUrl
    ]);
}

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET status='processing', updated_at=NOW() WHERE id=? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();
}

$google_maps_api_key = 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw';

$delimiter   = $job['delimiter'] ?: ';';
$filePath    = $job['file_path'];
$reportPath  = $job['report_path'];
$totalRows   = (int)$job['total_rows'];
$startOffset = (int)$job['processed_rows'];

$handle = fopen($filePath, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el archivo del job.'], 500);
}

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'El CSV del job está vacío.'], 400);
}

if (count($headers) > 0) {
    $headers[0] = removeBOM($headers[0]);
}

$indexes = resolveColumnIndexes($headers);
if ($indexes === false) {
    fclose($handle);
    jsonResponse([
        'success' => false,
        'message' => 'Los encabezados del archivo no son válidos.'
    ], 400);
}

$physicalLineNumber = 1;
$skippedDataRows = 0;

while ($skippedDataRows < $startOffset && ($row = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;
    if (!isNonEmptyCsvRow($row)) {
        continue;
    }
    $skippedDataRows++;
}

$batchProcessed = 0;
$batchUpdated   = 0;
$batchFailed    = 0;
$errors         = [];
$seenCodesBatch = [];

while ($batchProcessed < $limit && ($dataLine = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;

    if (!isNonEmptyCsvRow($dataLine)) {
        continue;
    }

    $batchProcessed++;

    $codigo      = trim((string)($dataLine[$indexes['codigo']] ?? ''));
    $nombreLocal = trim((string)($dataLine[$indexes['nombre_local']] ?? ''));
    $direccion   = trim((string)($dataLine[$indexes['direccion']] ?? ''));
    $comunaName  = trim((string)($dataLine[$indexes['comuna']] ?? ''));
    $regionName  = trim((string)($dataLine[$indexes['region']] ?? ''));

    $cuentaName   = ($indexes['cuenta']   !== null) ? trim((string)($dataLine[$indexes['cuenta']]   ?? '')) : '';
    $cadenaName   = ($indexes['cadena']   !== null) ? trim((string)($dataLine[$indexes['cadena']]   ?? '')) : '';
    $zonaName     = ($indexes['zona']     !== null) ? trim((string)($dataLine[$indexes['zona']]     ?? '')) : '';
    $distritoName = ($indexes['distrito'] !== null) ? trim((string)($dataLine[$indexes['distrito']] ?? '')) : '';

    if ($codigo === '' || $nombreLocal === '' || $direccion === '' || $comunaName === '' || $regionName === '') {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => 'Faltan campos requeridos.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }

    if (isset($seenCodesBatch[$codigo])) {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => 'Código repetido dentro del mismo lote.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }
    $seenCodesBatch[$codigo] = true;

    $localActual = getExistingLocalByCode($conn, $codigo, $errors, $physicalLineNumber);
    if ($localActual === false) {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => array_pop($errors) ?: 'No se encontró el local.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }

    $regionId = getRegionId($conn, $regionName, $errors, $physicalLineNumber);
    if ($regionId === false) {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => array_pop($errors) ?: 'No se pudo resolver la región.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }

    $comunaId = getComunaId($conn, $comunaName, $regionId, $errors, $physicalLineNumber);
    if ($comunaId === false) {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => array_pop($errors) ?: 'No se pudo resolver la comuna.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }

    $cuentaId   = !empty($localActual['id_cuenta'])   ? (int)$localActual['id_cuenta']   : null;
    $cadenaId   = !empty($localActual['id_cadena'])   ? (int)$localActual['id_cadena']   : null;
    $zonaId     = !empty($localActual['id_zona'])     ? (int)$localActual['id_zona']     : null;
    $distritoId = !empty($localActual['id_distrito']) ? (int)$localActual['id_distrito'] : null;

    if ($cuentaName !== '') {
        $tmpCuentaId = getCuentaId($conn, $cuentaName, $errors, $physicalLineNumber);
        if ($tmpCuentaId === false) {
            $failure = [
                'line'   => $physicalLineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => array_pop($errors) ?: 'No se pudo resolver la cuenta.'
            ];
            $batchFailed++;
            appendFailureToReport($reportPath, $failure);
            continue;
        }
        $cuentaId = $tmpCuentaId;
    }

    if ($cadenaName !== '') {
        $cadenaData = getCadenaData($conn, $cadenaName, $cuentaId, $errors, $physicalLineNumber);
        if ($cadenaData === false) {
            $failure = [
                'line'   => $physicalLineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => array_pop($errors) ?: 'No se pudo resolver la cadena.'
            ];
            $batchFailed++;
            appendFailureToReport($reportPath, $failure);
            continue;
        }
        $cadenaId = (int)$cadenaData['id'];
        if ($cuentaId === null && isset($cadenaData['id_cuenta'])) {
            $cuentaId = (int)$cadenaData['id_cuenta'];
        }
    }

    if ($zonaName !== '') {
        $tmpZonaId = getZonaId($conn, $zonaName, $errors, $physicalLineNumber);
        if ($tmpZonaId === false) {
            $failure = [
                'line'   => $physicalLineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => array_pop($errors) ?: 'No se pudo resolver la zona.'
            ];
            $batchFailed++;
            appendFailureToReport($reportPath, $failure);
            continue;
        }
        $zonaId = $tmpZonaId;
    }

    if ($distritoName !== '') {
        $distritoData = getDistritoData($conn, $distritoName, $zonaId, $errors, $physicalLineNumber);
        if ($distritoData === false) {
            $failure = [
                'line'   => $physicalLineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => array_pop($errors) ?: 'No se pudo resolver el distrito.'
            ];
            $batchFailed++;
            appendFailureToReport($reportPath, $failure);
            continue;
        }
        $distritoId = (int)$distritoData['id'];
        if ($zonaId === null && isset($distritoData['id_zona'])) {
            $zonaId = (int)$distritoData['id_zona'];
        }
    }

    $direccionActual = trim((string)($localActual['direccion'] ?? ''));
    $comunaActual    = trim((string)($localActual['comuna'] ?? ''));
    $regionActual    = trim((string)($localActual['region'] ?? ''));
    $latActual       = $localActual['lat'] !== null ? (float)$localActual['lat'] : null;
    $lngActual       = $localActual['lng'] !== null ? (float)$localActual['lng'] : null;

    $needGeocode = false;

    if ($latActual === null || $lngActual === null || $latActual == 0.0 || $lngActual == 0.0) {
        $needGeocode = true;
    }

    if (strcasecmp($direccionActual, $direccion) !== 0 ||
        strcasecmp($comunaActual, $comunaName) !== 0 ||
        strcasecmp($regionActual, $regionName) !== 0) {
        $needGeocode = true;
    }

    $coords = [
        'lat' => $latActual ?? 0,
        'lng' => $lngActual ?? 0
    ];

    if ($needGeocode) {
        $coords = geocodeAddress($direccion, $comunaName, $regionName, $google_maps_api_key, $errors, $physicalLineNumber);
        if ($coords === false) {
            $failure = [
                'line'   => $physicalLineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => array_pop($errors) ?: 'No se pudo geolocalizar la dirección.'
            ];
            $batchFailed++;
            appendFailureToReport($reportPath, $failure);
            continue;
        }
    }

    $ok = updateLocalByCodigo(
        $conn,
        $codigo,
        $nombreLocal,
        $direccion,
        $comunaId,
        (float)$coords['lat'],
        (float)$coords['lng'],
        $cuentaId,
        $cadenaId,
        $zonaId,
        $distritoId,
        $errors,
        $physicalLineNumber
    );

    if (!$ok) {
        $failure = [
            'line'   => $physicalLineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => array_pop($errors) ?: 'No se pudo actualizar el local.'
        ];
        $batchFailed++;
        appendFailureToReport($reportPath, $failure);
        continue;
    }

    $batchUpdated++;
}

fclose($handle);

$newProcessed = $startOffset + $batchProcessed;
$newUpdated   = ((int)$job['updated_rows']) + $batchUpdated;
$newFailed    = ((int)$job['failed_rows']) + $batchFailed;
$done         = ($newProcessed >= $totalRows);
$newStatus    = $done ? 'completed' : 'processing';

$reportUrl = $reportPath ? publicUrlFromPath($reportPath, $_SERVER['DOCUMENT_ROOT']) : '';
$progress  = ($totalRows > 0) ? round(($newProcessed / $totalRows) * 100, 2) : 100;

$stmt = $conn->prepare("
    UPDATE etl_locales_jobs
    SET
        processed_rows = ?,
        updated_rows   = ?,
        failed_rows    = ?,
        status         = ?,
        updated_at     = NOW()
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo actualizar el job: ' . $conn->error], 500);
}

$stmt->bind_param(
    'iiisi',
    $newProcessed,
    $newUpdated,
    $newFailed,
    $newStatus,
    $jobId
);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar avance del job.'], 500);
}
$stmt->close();

jsonResponse([
    'success'        => true,
    'done'           => $done,
    'job_id'         => $jobId,
    'total_rows'     => $totalRows,
    'processed_rows' => $newProcessed,
    'updated_rows'   => $newUpdated,
    'failed_rows'    => $newFailed,
    'batch_updated'  => $batchUpdated,
    'batch_failed'   => $batchFailed,
    'progress'       => $progress,
    'reportUrl'      => $reportUrl
]);
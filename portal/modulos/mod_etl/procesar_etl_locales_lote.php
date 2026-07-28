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
$postToken = trim($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    jsonResponse(['success' => false, 'message' => 'Token CSRF inválido.'], 400);
}

$jobId = (int)($_POST['job_id'] ?? 0);
$limit = (int)($_POST['limit'] ?? 100);
$limit = ($limit > 0 && $limit <= 250) ? $limit : 100;
if ($jobId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Job inválido.'], 400);
}

$conn->set_charset('utf8mb4');
$stmt = $conn->prepare('SELECT * FROM etl_locales_jobs WHERE id = ? AND user_id = ? LIMIT 1');
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

$previewUrl = !empty($job['preview_path'])
    ? publicUrlFromPath($job['preview_path'], $_SERVER['DOCUMENT_ROOT'])
    : '';
$reportUrl = !empty($job['report_path'])
    ? publicUrlFromPath($job['report_path'], $_SERVER['DOCUMENT_ROOT'])
    : '';

if (in_array($job['status'], ['preview_completed', 'applying', 'completed'], true)) {
    jsonResponse([
        'success' => true,
        'done' => true,
        'phase' => 'preview',
        'job_id' => (int)$job['id'],
        'total_rows' => (int)$job['total_rows'],
        'processed_rows' => (int)$job['processed_rows'],
        'accepted_rows' => (int)$job['accepted_rows'],
        'review_rows' => (int)$job['review_rows'],
        'rejected_rows' => (int)$job['rejected_rows'],
        'progress' => 100,
        'previewReportUrl' => $previewUrl,
        'reportUrl' => (int)$job['rejected_rows'] > 0 ? $reportUrl : ''
    ]);
}

if (!in_array($job['status'], ['pending_preview', 'processing_preview'], true)) {
    jsonResponse(['success' => false, 'message' => 'El job no está disponible para validación.'], 409);
}

$divisionId = (int)($job['id_division'] ?? 0);
if ($divisionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'El job no tiene una división válida.'], 409);
}

$filePath = (string)$job['file_path'];
$previewPath = (string)($job['preview_path'] ?? '');
$validationPath = (string)($job['validation_path'] ?? '');
$reportPath = (string)($job['report_path'] ?? '');
if (!is_file($filePath) || $previewPath === '' || $validationPath === '') {
    jsonResponse(['success' => false, 'message' => 'Faltan archivos requeridos para validar el job.'], 500);
}

$apiKey = etlLoadGoogleMapsApiKey();
if ($apiKey === '') {
    jsonResponse(['success' => false, 'message' => 'Falta configurar GOOGLE_MAPS_API_KEY para Address Validation API.'], 500);
}

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET status='processing_preview', updated_at=NOW() WHERE id=? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();
}

$delimiter = $job['delimiter'] ?: ';';
$handle = fopen($filePath, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el archivo del job.'], 500);
}

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'El CSV del job está vacío.'], 400);
}
$headers[0] = removeBOM($headers[0] ?? '');
$indexes = resolveColumnIndexes($headers);
if ($indexes === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'Los encabezados del archivo no son válidos.'], 400);
}

$startOffset = (int)$job['processed_rows'];
$physicalLineNumber = 1;
$skippedRows = 0;
while ($skippedRows < $startOffset && ($row = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;
    if (isNonEmptyCsvRow($row)) {
        $skippedRows++;
    }
}

$batchProcessed = 0;
$batchAccepted = 0;
$batchReview = 0;
$batchRejected = 0;
$errors = [];

while ($batchProcessed < $limit && ($dataLine = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $physicalLineNumber++;
    if (!isNonEmptyCsvRow($dataLine)) {
        continue;
    }
    $batchProcessed++;

    $codigo = etlUpper($dataLine[$indexes['codigo']] ?? '');
    $nombreOriginal = normalizeCsvText($dataLine[$indexes['nombre_local']] ?? '');
    $direccionOriginal = normalizeCsvText($dataLine[$indexes['direccion']] ?? '');
    $comunaOriginal = normalizeCsvText($dataLine[$indexes['comuna']] ?? '');
    $cuentaName = ($indexes['cuenta'] !== null) ? etlUpper($dataLine[$indexes['cuenta']] ?? '') : '';
    $cadenaName = ($indexes['cadena'] !== null) ? etlUpper($dataLine[$indexes['cadena']] ?? '') : '';

    $record = [
        'line' => $physicalLineNumber,
        'codigo' => $codigo,
        'division_id' => $divisionId,
        'nombre' => $nombreOriginal,
        'direccion_actual' => '',
        'direccion_original' => $direccionOriginal,
        'direccion_limpia' => '',
        'direccion_nueva' => '',
        'direccion_google' => '',
        'comuna' => $comunaOriginal,
        'distrito' => '',
        'zona' => '',
        'region' => '',
        'cuenta' => $cuentaName,
        'cadena' => $cadenaName,
        'estado_validacion' => 'ERROR_ENTRADA',
        'reason' => '',
        'lat' => null,
        'lng' => null,
        'response_id' => ''
    ];

    if ($codigo === '' || $nombreOriginal === '' || $direccionOriginal === '' || $comunaOriginal === '') {
        $record['reason'] = 'Faltan campos requeridos.';
    } else {
        $territory = etlLookupTerritoryByComuna($comunaOriginal);
        if ($territory === null) {
            $record['estado_validacion'] = 'COMUNA_SIN_MAPEO';
            $record['reason'] = "La comuna '$comunaOriginal' no existe en el catálogo territorial.";
        } else {
            $record['comuna'] = $territory['comuna'];
            $record['distrito'] = $territory['distrito'];
            $record['zona'] = $territory['zona'];
            $record['region'] = $territory['region'];
            $record['nombre'] = etlBuildLocalName($codigo, $nombreOriginal, $record['comuna']);
            $record['direccion_limpia'] = etlCleanAddress($direccionOriginal, $record['comuna']);

            $localActual = getExistingLocalByCode($conn, $codigo, $divisionId, $errors, $physicalLineNumber);
            if ($localActual === false) {
                $record['estado_validacion'] = 'LOCAL_NO_ENCONTRADO';
                $record['reason'] = array_pop($errors) ?: 'No se encontró el local.';
            } else {
                $record['direccion_actual'] = normalizeCsvText($localActual['direccion'] ?? '');
                $validation = etlValidateAddress(
                    $record['direccion_limpia'],
                    $record['comuna'],
                    $apiKey,
                    $physicalLineNumber
                );
                $record['estado_validacion'] = $validation['status'];
                $record['reason'] = $validation['reason'];
                $record['direccion_nueva'] = etlPreferredAddress($validation, $record['direccion_limpia']);
                $record['direccion_google'] = $validation['formatted_address'];
                $record['lat'] = $validation['lat'];
                $record['lng'] = $validation['lng'];
                $record['response_id'] = $validation['response_id'];
            }
        }
    }

    if ($record['estado_validacion'] === 'ACEPTADA') {
        $batchAccepted++;
    } elseif ($record['estado_validacion'] === 'REVISION') {
        $batchReview++;
    } else {
        $batchRejected++;
        appendFailureToReport($reportPath, $record);
    }

    if (!appendPreviewRecord($previewPath, $record) || !appendValidationRecord($validationPath, $record)) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'No se pudo guardar el resultado de la validación.'], 500);
    }
}
fclose($handle);

$newProcessed = $startOffset + $batchProcessed;
$newAccepted = (int)$job['accepted_rows'] + $batchAccepted;
$newReview = (int)$job['review_rows'] + $batchReview;
$newRejected = (int)$job['rejected_rows'] + $batchRejected;
$totalRows = (int)$job['total_rows'];
$done = $newProcessed >= $totalRows;
$newStatus = $done ? 'preview_completed' : 'processing_preview';
$progress = $totalRows > 0 ? round(($newProcessed / $totalRows) * 100, 2) : 100;

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET processed_rows=?, accepted_rows=?, review_rows=?, rejected_rows=?, failed_rows=?, status=?, updated_at=NOW() WHERE id=? LIMIT 1");
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo actualizar el avance del job: ' . $conn->error], 500);
}
$stmt->bind_param('iiiiisi', $newProcessed, $newAccepted, $newReview, $newRejected, $newRejected, $newStatus, $jobId);
$stmt->execute();
$stmt->close();

jsonResponse([
    'success' => true,
    'done' => $done,
    'phase' => 'preview',
    'job_id' => $jobId,
    'total_rows' => $totalRows,
    'processed_rows' => $newProcessed,
    'accepted_rows' => $newAccepted,
    'review_rows' => $newReview,
    'rejected_rows' => $newRejected,
    'progress' => $progress,
    'previewReportUrl' => $done ? publicUrlFromPath($previewPath, $_SERVER['DOCUMENT_ROOT']) : '',
    'reportUrl' => $done && $newRejected > 0 ? publicUrlFromPath($reportPath, $_SERVER['DOCUMENT_ROOT']) : ''
]);

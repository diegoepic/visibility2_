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
$stmt = $conn->prepare('SELECT * FROM etl_locales_jobs WHERE id=? AND user_id=? LIMIT 1');
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo consultar el job.'], 500);
}
$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute();
$result = $stmt->get_result();
$job = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$job) {
    jsonResponse(['success' => false, 'message' => 'Job no encontrado.'], 404);
}

$reportUrl = !empty($job['report_path'])
    ? publicUrlFromPath($job['report_path'], $_SERVER['DOCUMENT_ROOT'])
    : '';
if ($job['status'] === 'completed') {
    jsonResponse([
        'success' => true,
        'done' => true,
        'phase' => 'apply',
        'job_id' => $jobId,
        'total_rows' => (int)$job['total_rows'],
        'processed_rows' => (int)$job['apply_processed_rows'],
        'applied_rows' => (int)$job['applied_rows'],
        'failed_rows' => (int)$job['apply_failed_rows'],
        'progress' => 100,
        'reportUrl' => (int)$job['apply_failed_rows'] > 0 ? $reportUrl : ''
    ]);
}
if ($job['status'] !== 'applying') {
    jsonResponse(['success' => false, 'message' => 'La aplicación de este job no está autorizada.'], 409);
}

$divisionId = (int)($job['id_division'] ?? 0);
$scope = strtolower(trim((string)($job['apply_scope'] ?? '')));
$allowedStatuses = $scope === 'accepted_review' ? ['ACEPTADA', 'REVISION'] : ['ACEPTADA'];
$validationPath = (string)($job['validation_path'] ?? '');
$reportPath = (string)($job['report_path'] ?? '');
if ($divisionId <= 0 || !is_file($validationPath)) {
    jsonResponse(['success' => false, 'message' => 'El job no tiene división o validación disponible.'], 500);
}

$handle = fopen($validationPath, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir la validación guardada.'], 500);
}

$startOffset = (int)$job['apply_processed_rows'];
$skipped = 0;
while ($skipped < $startOffset && fgets($handle) !== false) {
    $skipped++;
}

$batchProcessed = 0;
$batchApplied = 0;
$batchFailed = 0;
$errors = [];

while ($batchProcessed < $limit && ($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $batchProcessed++;
    $record = json_decode($line, true);
    if (!is_array($record)) {
        $batchFailed++;
        continue;
    }

    $status = (string)($record['estado_validacion'] ?? 'ERROR_VALIDACION');
    if (!in_array($status, $allowedStatuses, true)) {
        $batchFailed++;
        if ($status === 'REVISION' && $scope === 'accepted_only') {
            $record['reason'] = 'No actualizado: el usuario eligió aplicar solo direcciones aprobadas. ' . ($record['reason'] ?? '');
            appendFailureToReport($reportPath, $record);
        }
        continue;
    }

    $codigo = (string)$record['codigo'];
    $lineNumber = (int)($record['line'] ?? 0);
    $localActual = getExistingLocalByCode($conn, $codigo, $divisionId, $errors, $lineNumber);
    if ($localActual === false) {
        $record['reason'] = array_pop($errors) ?: 'El local ya no existe en la división seleccionada.';
        appendFailureToReport($reportPath, $record);
        $batchFailed++;
        continue;
    }

    $regionId = getRegionId($conn, (string)$record['region'], $errors, $lineNumber);
    $comunaId = $regionId !== false
        ? getComunaId($conn, (string)$record['comuna'], $regionId, $errors, $lineNumber)
        : false;
    $zonaId = getZonaId($conn, (string)$record['zona'], $errors, $lineNumber);
    $distritoData = $zonaId !== false
        ? getDistritoData($conn, (string)$record['distrito'], $zonaId, $errors, $lineNumber)
        : false;

    if ($regionId === false || $comunaId === false || $zonaId === false || $distritoData === false) {
        $record['reason'] = array_pop($errors) ?: 'No se pudo resolver el territorio.';
        appendFailureToReport($reportPath, $record);
        $batchFailed++;
        continue;
    }
    $distritoId = (int)$distritoData['id'];

    $cuentaId = !empty($localActual['id_cuenta']) ? (int)$localActual['id_cuenta'] : null;
    $cadenaId = !empty($localActual['id_cadena']) ? (int)$localActual['id_cadena'] : null;
    if (!empty($record['cuenta'])) {
        $cuentaId = getCuentaId($conn, (string)$record['cuenta'], $errors, $lineNumber);
    }
    if ($cuentaId === false) {
        $record['reason'] = array_pop($errors) ?: 'No se pudo resolver la cuenta.';
        appendFailureToReport($reportPath, $record);
        $batchFailed++;
        continue;
    }
    if (!empty($record['cadena'])) {
        $cadenaData = getCadenaData($conn, (string)$record['cadena'], $cuentaId, $errors, $lineNumber);
        if ($cadenaData === false) {
            $record['reason'] = array_pop($errors) ?: 'No se pudo resolver la cadena.';
            appendFailureToReport($reportPath, $record);
            $batchFailed++;
            continue;
        }
        $cadenaId = (int)$cadenaData['id'];
        if ($cuentaId === null && isset($cadenaData['id_cuenta'])) {
            $cuentaId = (int)$cadenaData['id_cuenta'];
        }
    }

    if ($record['lat'] === null || $record['lng'] === null) {
        $record['reason'] = 'La validación guardada no contiene coordenadas.';
        appendFailureToReport($reportPath, $record);
        $batchFailed++;
        continue;
    }

    $ok = updateLocalByCodigo(
        $conn,
        $codigo,
        $divisionId,
        (string)$record['nombre'],
        (string)$record['direccion_original'],
        (string)$record['direccion_nueva'],
        (string)$record['direccion_google'],
        $status,
        (string)$record['response_id'],
        (int)$comunaId,
        (float)$record['lat'],
        (float)$record['lng'],
        $cuentaId,
        $cadenaId,
        (int)$zonaId,
        $distritoId,
        $errors,
        $lineNumber
    );

    if (!$ok) {
        $record['reason'] = array_pop($errors) ?: 'No se pudo actualizar el local.';
        appendFailureToReport($reportPath, $record);
        $batchFailed++;
        continue;
    }
    $batchApplied++;
}
fclose($handle);

$newProcessed = $startOffset + $batchProcessed;
$newApplied = (int)$job['applied_rows'] + $batchApplied;
$newFailed = (int)$job['apply_failed_rows'] + $batchFailed;
$totalRows = (int)$job['total_rows'];
$done = $newProcessed >= $totalRows;
$newStatus = $done ? 'completed' : 'applying';
$progress = $totalRows > 0 ? round(($newProcessed / $totalRows) * 100, 2) : 100;

$stmt = $conn->prepare('UPDATE etl_locales_jobs SET apply_processed_rows=?, applied_rows=?, apply_failed_rows=?, updated_rows=?, failed_rows=?, status=?, updated_at=NOW() WHERE id=? LIMIT 1');
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar el avance de aplicación.'], 500);
}
$stmt->bind_param('iiiiisi', $newProcessed, $newApplied, $newFailed, $newApplied, $newFailed, $newStatus, $jobId);
$stmt->execute();
$stmt->close();

jsonResponse([
    'success' => true,
    'done' => $done,
    'phase' => 'apply',
    'job_id' => $jobId,
    'total_rows' => $totalRows,
    'processed_rows' => $newProcessed,
    'applied_rows' => $newApplied,
    'failed_rows' => $newFailed,
    'progress' => $progress,
    'reportUrl' => $done && $newFailed > 0 ? publicUrlFromPath($reportPath, $_SERVER['DOCUMENT_ROOT']) : ''
]);

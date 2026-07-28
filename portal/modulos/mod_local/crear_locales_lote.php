<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(270);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require __DIR__ . '/local_import_helpers.php';

if (!isset($usuario_id)) jsonResponse(['success'=>false,'message'=>'Usuario no autenticado.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Metodo no permitido.'], 405);
$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken = trim($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) jsonResponse(['success'=>false,'message'=>'Token CSRF invalido.'], 400);
$jobId = (int)($_POST['job_id'] ?? 0);
$limit = min(1000, max(1, (int)($_POST['limit'] ?? 1000)));
if ($jobId <= 0) jsonResponse(['success'=>false,'message'=>'Job invalido.'], 400);
session_write_close();

$conn->set_charset('utf8mb4');
$stmt = $conn->prepare('SELECT * FROM local_import_jobs WHERE id=? AND user_id=? LIMIT 1');
if (!$stmt) jsonResponse(['success'=>false,'message'=>'No se pudo consultar el job. Verifica la migracion de local_import_jobs.'], 500);
$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute(); $job = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$job) jsonResponse(['success'=>false,'message'=>'Job no encontrado.'], 404);
if ($job['status'] === 'completed') {
    jsonResponse(['success'=>true,'done'=>true,'job_id'=>$jobId,'total_rows'=>(int)$job['total_rows'],'processed_rows'=>(int)$job['apply_processed_rows'],'inserted_rows'=>(int)$job['inserted_rows'],'failed_rows'=>(int)$job['apply_failed_rows'],'progress'=>100,'acceptedReportUrl'=>(int)$job['inserted_rows'] > 0 ? publicUrlFromPath($job['accepted_report_path'], $_SERVER['DOCUMENT_ROOT']) : '','reportUrl'=>(int)$job['apply_failed_rows'] > 0 ? publicUrlFromPath($job['failure_report_path'], $_SERVER['DOCUMENT_ROOT']) : '']);
}
if ($job['status'] !== 'applying') jsonResponse(['success'=>false,'message'=>'La creacion de este job no esta autorizada.'], 409);
if (!localImportAcquireLock($conn, $jobId)) jsonResponse(['success'=>false,'message'=>'Este lote ya se esta procesando.'], 409);
$handle = fopen($job['validation_path'], 'r');
if (!$handle) { localImportReleaseLock($conn, $jobId); jsonResponse(['success'=>false,'message'=>'No se pudo abrir la validacion guardada.'], 500); }
$start = (int)$job['apply_processed_rows']; $skipped = 0;
while ($skipped < $start && fgets($handle) !== false) $skipped++;
$allowed = $job['apply_scope'] === 'accepted_review' ? ['ACEPTADA','REVISION'] : ['ACEPTADA'];
$deadline = microtime(true) + 240;
$batch = 0; $inserted = 0; $failed = 0;
while ($batch < $limit && microtime(true) < $deadline && ($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $batch++;
    $r = json_decode($line, true);
    if (!is_array($r)) { $failed++; continue; }
    if (!in_array($r['estado'] ?? '', $allowed, true)) {
        $failed++;
        if (($r['estado'] ?? '') === 'REVISION' && $job['apply_scope'] === 'accepted_only') localImportAppendFailure($job['failure_report_path'], $r, 'No creado: el usuario eligio solo aprobados. ' . ($r['motivo'] ?? ''));
        continue;
    }
    $errors = []; $lineNumber = (int)($r['line'] ?? 0);
    $exists = localImportExists($conn, (string)$r['codigo'], (int)$job['id_division'], $errors, $lineNumber);
    if ($exists === null || $exists) {
        localImportAppendFailure($job['failure_report_path'], $r, $exists ? 'El codigo ya existe en esta division.' : (array_pop($errors) ?: 'No se pudo validar el codigo.'));
        $failed++; continue;
    }
    if ($r['lat'] === null || $r['lng'] === null) {
        localImportAppendFailure($job['failure_report_path'], $r, 'La validacion no contiene latitud y longitud.');
        $failed++; continue;
    }
    $conn->begin_transaction();
    $regionId = getRegionId($conn, (string)$r['region'], $errors, $lineNumber);
    $comunaId = $regionId !== false ? getComunaId($conn, (string)$r['comuna'], $regionId, $errors, $lineNumber) : false;
    $canalId = localImportGetSimpleId($conn, 'canal', (string)$r['canal'], null, $errors, $lineNumber);
    $subcanalId = $canalId !== false ? localImportGetSimpleId($conn, 'subcanal', (string)$r['subcanal'], $canalId, $errors, $lineNumber) : false;
    $cuentaId = getCuentaId($conn, (string)$r['cuenta'], $errors, $lineNumber);
    $cadenaData = $cuentaId !== false ? getCadenaData($conn, (string)$r['cadena'], $cuentaId, $errors, $lineNumber) : false;
    $zonaId = getZonaId($conn, (string)$r['zona'], $errors, $lineNumber);
    $distritoData = $zonaId !== false ? getDistritoData($conn, (string)$r['distrito'], $zonaId, $errors, $lineNumber) : false;
    $jefeId = localImportGetPersonId($conn, 'jefe_venta', 'nombre', (string)$r['jefe_venta'], $errors, $lineNumber);
    $vendedorId = localImportGetPersonId($conn, 'vendedor', 'nombre_vendedor', (string)$r['nombre_vendedor'], $errors, $lineNumber);
    if ($regionId===false || $comunaId===false || $canalId===false || $subcanalId===false || $cuentaId===false || $cadenaData===false || $zonaId===false || $distritoData===false || $jefeId===false || $vendedorId===false) {
        $conn->rollback(); localImportAppendFailure($job['failure_report_path'], $r, array_pop($errors) ?: 'No se pudieron resolver los catalogos.'); $failed++; continue;
    }
    $data = [
        'codigo'=>(string)$r['codigo'],'nombre'=>(string)$r['nombre'],'direccion'=>(string)$r['direccion_nueva'],
        'direccion_original'=>(string)$r['direccion_original'],'direccion_google'=>(string)$r['direccion_google'],
        'estado'=>(string)$r['estado'],'response_id'=>(string)$r['response_id'],'id_cuenta'=>(int)$cuentaId,
        'id_cadena'=>(int)$cadenaData['id'],'id_comuna'=>(int)$comunaId,'id_empresa'=>(int)$job['id_empresa'],
        'id_canal'=>(int)$canalId,'id_subcanal'=>(int)$subcanalId,'lat'=>(float)$r['lat'],'lng'=>(float)$r['lng'],
        'relevancia'=>0,'id_zona'=>(int)$zonaId,'id_distrito'=>(int)$distritoData['id'],
        'id_jefe_venta'=>(int)$jefeId,'id_vendedor'=>(int)$vendedorId,'id_division'=>(int)$job['id_division']
    ];
    if (!localImportInsert($conn, $data, $errors, $lineNumber)) {
        $conn->rollback(); localImportAppendFailure($job['failure_report_path'], $r, array_pop($errors) ?: 'No se pudo insertar el local.'); $failed++; continue;
    }
    $conn->commit(); $inserted++; localImportAppendAccepted($job['accepted_report_path'], $r);
}
fclose($handle);
$processed = $start + $batch; $inserted += (int)$job['inserted_rows']; $failed += (int)$job['apply_failed_rows'];
$total = (int)$job['total_rows']; $done = $processed >= $total; $status = $done ? 'completed' : 'applying';
$stmt = $conn->prepare('UPDATE local_import_jobs SET apply_processed_rows=?,inserted_rows=?,apply_failed_rows=?,status=?,updated_at=NOW() WHERE id=? LIMIT 1');
$stmt->bind_param('iiisi', $processed, $inserted, $failed, $status, $jobId); $stmt->execute(); $stmt->close();
localImportReleaseLock($conn, $jobId);
$progress = $total > 0 ? round($processed * 100 / $total, 2) : 100;
jsonResponse(['success'=>true,'done'=>$done,'job_id'=>$jobId,'batch_rows'=>$batch,'total_rows'=>$total,'processed_rows'=>$processed,'inserted_rows'=>$inserted,'failed_rows'=>$failed,'progress'=>$progress,'acceptedReportUrl'=>$done && $inserted > 0 ? publicUrlFromPath($job['accepted_report_path'], $_SERVER['DOCUMENT_ROOT']) : '','reportUrl'=>$done && $failed > 0 ? publicUrlFromPath($job['failure_report_path'], $_SERVER['DOCUMENT_ROOT']) : '']);

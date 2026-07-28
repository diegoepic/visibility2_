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
if (!$stmt) jsonResponse(['success'=>false,'message'=>'No se pudo consultar el job. Verifica que ejecutaste la migracion 2026_07_22_local_import_jobs.sql.'], 500);
$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$job) jsonResponse(['success'=>false,'message'=>'Job no encontrado.'], 404);
if ($job['status'] === 'validation_completed' || $job['status'] === 'applying' || $job['status'] === 'completed') {
    jsonResponse(['success'=>true,'done'=>true,'job_id'=>$jobId,'total_rows'=>(int)$job['total_rows'],'processed_rows'=>(int)$job['validation_processed_rows'],'accepted_rows'=>(int)$job['accepted_rows'],'review_rows'=>(int)$job['review_rows'],'rejected_rows'=>(int)$job['rejected_rows'],'progress'=>100,'previewReportUrl'=>publicUrlFromPath($job['preview_path'], $_SERVER['DOCUMENT_ROOT']),'reportUrl'=>(int)$job['rejected_rows'] > 0 ? publicUrlFromPath($job['failure_report_path'], $_SERVER['DOCUMENT_ROOT']) : '']);
}
if (!in_array($job['status'], ['pending_validation','validating'], true)) jsonResponse(['success'=>false,'message'=>'El job no esta disponible para validar.'], 409);
if (!localImportAcquireLock($conn, $jobId)) jsonResponse(['success'=>false,'message'=>'Este lote ya se esta procesando.'], 409);

$apiKey = etlLoadGoogleMapsApiKey();
if ($apiKey === '') {
    localImportReleaseLock($conn, $jobId);
    jsonResponse(['success'=>false,'message'=>'Falta configurar GOOGLE_MAPS_API_KEY.'], 500);
}
$handle = fopen($job['file_path'], 'r');
if (!$handle) {
    localImportReleaseLock($conn, $jobId);
    jsonResponse(['success'=>false,'message'=>'No se pudo abrir el archivo del job.'], 500);
}
$headers = fgetcsv($handle, 200000, $job['csv_delimiter']);
$indexes = $headers !== false ? resolveLocalImportIndexes($headers) : false;
if ($indexes === false) {
    fclose($handle); localImportReleaseLock($conn, $jobId);
    jsonResponse(['success'=>false,'message'=>'Los encabezados guardados ya no son validos.'], 500);
}
$start = (int)$job['validation_processed_rows'];
$skipped = 0; $physicalLine = 1;
while ($skipped < $start && ($row = fgetcsv($handle, 200000, $job['csv_delimiter'])) !== false) {
    $physicalLine++;
    if (isNonEmptyCsvRow($row)) $skipped++;
}
$deadline = microtime(true) + 240;
$batch = 0; $accepted = 0; $review = 0; $rejected = 0;
while ($batch < $limit && microtime(true) < $deadline && ($row = fgetcsv($handle, 200000, $job['csv_delimiter'])) !== false) {
    $physicalLine++;
    if (!isNonEmptyCsvRow($row)) continue;
    $batch++;
    $record = [
        'line'=>$physicalLine,
        'codigo'=>etlUpper($row[$indexes['codigo']] ?? ''),
        'canal'=>etlUpper($row[$indexes['canal']] ?? ''),
        'subcanal'=>etlUpper($row[$indexes['subcanal']] ?? ''),
        'cuenta'=>etlUpper($row[$indexes['cuenta']] ?? ''),
        'cadena'=>etlUpper($row[$indexes['cadena']] ?? ''),
        'nombre_original'=>normalizeCsvText($row[$indexes['nombre_local']] ?? ''),
        'nombre'=>'',
        'direccion_original'=>normalizeCsvText($row[$indexes['direccion']] ?? ''),
        'direccion_limpia'=>'','direccion_nueva'=>'','direccion_google'=>'',
        'comuna_original'=>normalizeCsvText($row[$indexes['comuna']] ?? ''),
        'comuna'=>'','comuna_google'=>'','distrito'=>'','zona'=>'','region'=>'',
        'nombre_vendedor'=>etlNormalizeOptionalPerson($row[$indexes['nombre_vendedor']] ?? ''),
        'jefe_venta'=>etlNormalizeOptionalPerson($row[$indexes['jefe_venta']] ?? ''),
        'vendedor_id'=>null,'vendedor_status'=>'','jefe_id'=>null,'jefe_status'=>'',
        'estado'=>'ERROR_ENTRADA','motivo'=>'','lat'=>null,'lng'=>null,'response_id'=>''
    ];
    $required = [$record['codigo'],$record['canal'],$record['subcanal'],$record['cuenta'],$record['cadena'],$record['nombre_original'],$record['direccion_original'],$record['comuna_original']];
    if (in_array('', $required, true)) {
        $record['motivo'] = 'Algun campo requerido esta vacio.';
    } else {
        $territory = etlLookupTerritoryByComuna($record['comuna_original']);
        if ($territory === null) {
            $record['estado'] = 'COMUNA_SIN_MAPEO';
            $record['motivo'] = "La comuna '{$record['comuna_original']}' no existe en el catalogo territorial.";
        } else {
            $record['comuna']=$territory['comuna']; $record['distrito']=$territory['distrito']; $record['zona']=$territory['zona']; $record['region']=$territory['region'];
            $record['nombre']=etlBuildLocalName($record['codigo'], $record['nombre_original'], $record['comuna']);
            $record['direccion_limpia']=etlCleanAddress($record['direccion_original'], $record['comuna']);
            $errors = [];
            $exists = localImportExists($conn, $record['codigo'], (int)$job['id_division'], $errors, $physicalLine);
            if ($exists === null || $exists) {
                $record['estado'] = $exists ? 'LOCAL_EXISTENTE' : 'ERROR_CONSULTA';
                $record['motivo'] = $exists ? 'El codigo ya existe en la division seleccionada.' : (array_pop($errors) ?: 'No se pudo validar el codigo.');
            } else {
                $seller = localImportInspectPerson($conn, 'vendedor', 'nombre_vendedor', $record['nombre_vendedor']);
                $boss = localImportInspectPerson($conn, 'jefe_venta', 'nombre', $record['jefe_venta']);
                $record['vendedor_id']=$seller['id']; $record['vendedor_status']=$seller['status'];
                $record['jefe_id']=$boss['id']; $record['jefe_status']=$boss['status'];
                $validation = etlValidateAddress($record['direccion_limpia'], $record['comuna'], $apiKey, $physicalLine);
                $record['estado']=$validation['status']; $record['motivo']=$validation['reason'];
                $record['direccion_google']=$validation['formatted_address'];
                $record['direccion_nueva']=etlPreferredAddress($validation, $record['direccion_limpia']);
                $record['comuna_google']=$validation['suggested_locality'];
                $record['lat']=$validation['lat']; $record['lng']=$validation['lng']; $record['response_id']=$validation['response_id'];
            }
        }
    }
    if ($record['nombre'] === '') $record['nombre'] = etlBuildLocalName($record['codigo'], $record['nombre_original'], $record['comuna_original']);
    if ($record['direccion_limpia'] === '') $record['direccion_limpia'] = etlUpper($record['direccion_original']);
    if ($record['direccion_nueva'] === '') $record['direccion_nueva'] = $record['direccion_limpia'];
    if ($record['estado'] === 'ACEPTADA') $accepted++; elseif ($record['estado'] === 'REVISION') $review++; else { $rejected++; localImportAppendFailure($job['failure_report_path'], $record); }
    if (!appendValidationRecord($job['validation_path'], $record) || !localImportAppendPreview($job['preview_path'], $record)) {
        fclose($handle); localImportReleaseLock($conn, $jobId);
        jsonResponse(['success'=>false,'message'=>'No se pudo guardar el avance del lote.'], 500);
    }
}
fclose($handle);
$processed = $start + $batch;
$accepted += (int)$job['accepted_rows']; $review += (int)$job['review_rows']; $rejected += (int)$job['rejected_rows'];
$total = (int)$job['total_rows']; $done = $processed >= $total;
$status = $done ? 'validation_completed' : 'validating';
$stmt = $conn->prepare('UPDATE local_import_jobs SET validation_processed_rows=?,accepted_rows=?,review_rows=?,rejected_rows=?,status=?,updated_at=NOW() WHERE id=? LIMIT 1');
$stmt->bind_param('iiiisi', $processed, $accepted, $review, $rejected, $status, $jobId);
$stmt->execute(); $stmt->close();
localImportReleaseLock($conn, $jobId);
$progress = $total > 0 ? round($processed * 100 / $total, 2) : 100;
jsonResponse(['success'=>true,'done'=>$done,'job_id'=>$jobId,'batch_rows'=>$batch,'total_rows'=>$total,'processed_rows'=>$processed,'accepted_rows'=>$accepted,'review_rows'=>$review,'rejected_rows'=>$rejected,'progress'=>$progress,'previewReportUrl'=>$done ? publicUrlFromPath($job['preview_path'], $_SERVER['DOCUMENT_ROOT']) : '','reportUrl'=>$done && $rejected > 0 ? publicUrlFromPath($job['failure_report_path'], $_SERVER['DOCUMENT_ROOT']) : '']);

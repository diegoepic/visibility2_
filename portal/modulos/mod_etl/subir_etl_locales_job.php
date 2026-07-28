<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$divisionId = (int)($_POST['division_id'] ?? 0);
$createScope = strtolower(trim((string)($_POST['create_scope'] ?? 'accepted_only')));
$sessionEmpresaId = (int)($empresa_id ?? ($_SESSION['empresa_id'] ?? 0));

if ($divisionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Debes seleccionar una división.'], 400);
}
if (!in_array($createScope, ['accepted_only', 'accepted_review'], true)) {
    jsonResponse(['success' => false, 'message' => 'La selección de estados para actualizar no es válida.'], 400);
}

$stmtDivision = $conn->prepare("SELECT 1 FROM division_empresa WHERE id = ? AND id_empresa = ? AND estado = 1 LIMIT 1");
if (!$stmtDivision) {
    jsonResponse(['success' => false, 'message' => 'No se pudo validar la división seleccionada.'], 500);
}
$stmtDivision->bind_param('ii', $divisionId, $sessionEmpresaId);
$stmtDivision->execute();
$stmtDivision->store_result();
$validDivision = $stmtDivision->num_rows === 1;
$stmtDivision->close();
if (!$validDivision) {
    jsonResponse(['success' => false, 'message' => 'La división seleccionada no pertenece a la empresa activa.'], 400);
}

if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No se subió correctamente el archivo CSV.'], 400);
}

$originalName = $_FILES['csvFile']['name'] ?? 'archivo.csv';
$extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($extension !== 'csv') {
    jsonResponse(['success' => false, 'message' => 'El archivo debe ser CSV.'], 400);
}

$uploadDir = __DIR__ . '/uploads/jobs/';
$reportDir = __DIR__ . '/uploads/reports/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}

$storedName = 'etl_locales_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
$filePath   = $uploadDir . $storedName;

if (!move_uploaded_file($_FILES['csvFile']['tmp_name'], $filePath)) {
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar el archivo subido.'], 500);
}

$delimiter = detectDelimiter($filePath);

$handle = fopen($filePath, 'r');
if (!$handle) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el archivo CSV.'], 400);
}

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'El archivo CSV está vacío.'], 400);
}

if (count($headers) > 0) {
    $headers[0] = removeBOM($headers[0]);
}

$indexes = resolveColumnIndexes($headers);
if ($indexes === false) {
    fclose($handle);
    @unlink($filePath);
    jsonResponse([
        'success' => false,
        'message' => 'Los encabezados no son válidos. Deben incluir: Código Local, Nombre, Dirección y Comuna. Cuenta y Cadena son opcionales.'
    ], 400);
}

$totalRows = 0;
$seenCodes = [];
$duplicateCodes = [];

while (($row = fgetcsv($handle, 200000, $delimiter)) !== false) {
    if (!isNonEmptyCsvRow($row)) {
        continue;
    }

    $totalRows++;

    $codigo = trim((string)($row[$indexes['codigo']] ?? ''));
    if ($codigo !== '') {
        if (isset($seenCodes[$codigo])) {
            $duplicateCodes[$codigo] = true;
        } else {
            $seenCodes[$codigo] = true;
        }
    }
}
fclose($handle);

if (!empty($duplicateCodes)) {
    @unlink($filePath);
    $codes = array_slice(array_keys($duplicateCodes), 0, 10);
    jsonResponse([
        'success' => false,
        'message' => 'El archivo contiene códigos repetidos. Ejemplos: ' . implode(', ', $codes)
    ], 400);
}

$reportName = 'etl_locales_fallidos_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
$reportPath = $reportDir . $reportName;
$previewName = 'etl_locales_revision_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
$previewPath = $reportDir . $previewName;
$validationName = 'etl_locales_validation_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jsonl';
$validationPath = $uploadDir . $validationName;

$fp = fopen($reportPath, 'w');
if (!$fp) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el reporte de errores.'], 500);
}
fputcsv($fp, [
    'linea','codigo','nombre local','division id','estado address validation',
    'direccion original','direccion limpia','direccion google','comuna','distrito',
    'zona','region','motivo de fallo','response id'
], ';');
fclose($fp);

$previewFp = fopen($previewPath, 'w');
if (!$previewFp) {
    @unlink($filePath);
    @unlink($reportPath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el archivo de revisión.'], 500);
}
fwrite($previewFp, "\xEF\xBB\xBF");
fputcsv($previewFp, [
    'linea','codigo','division id','nombre local','direccion actual en base',
    'direccion original archivo','direccion limpia','direccion propuesta','direccion completa google',
    'comuna','distrito','zona','region','cuenta','cadena','estado address validation',
    'motivo','lat','lng','response id'
], ';');
fclose($previewFp);

$validationFp = fopen($validationPath, 'w');
if (!$validationFp) {
    @unlink($filePath);
    @unlink($reportPath);
    @unlink($previewPath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear la caché de validación.'], 500);
}
fclose($validationFp);

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("
    INSERT INTO etl_locales_jobs
    (
        user_id,
        id_division,
        create_scope,
        original_name,
        file_path,
        delimiter,
        total_rows,
        processed_rows,
        updated_rows,
        failed_rows,
        status,
        report_path,
        preview_path,
        validation_path,
        last_error,
        created_at,
        updated_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'pending_preview', ?, ?, ?, NULL, NOW(), NOW())
");

if (!$stmt) {
    @unlink($filePath);
    @unlink($reportPath);
    @unlink($previewPath);
    @unlink($validationPath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el job: ' . $conn->error], 500);
}

$stmt->bind_param(
    'iissssisss',
    $usuario_id,
    $divisionId,
    $createScope,
    $originalName,
    $filePath,
    $delimiter,
    $totalRows,
    $reportPath,
    $previewPath,
    $validationPath
);

if (!$stmt->execute()) {
    $stmt->close();
    @unlink($filePath);
    @unlink($reportPath);
    @unlink($previewPath);
    @unlink($validationPath);
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar el job: ' . $conn->error], 500);
}

$jobId = (int)$stmt->insert_id;
$stmt->close();

jsonResponse([
    'success'    => true,
    'job_id'     => $jobId,
    'total_rows' => $totalRows,
    'message'    => 'Archivo cargado correctamente. Job creado.'
]);

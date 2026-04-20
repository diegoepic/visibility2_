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
        'message' => 'Los encabezados no son válidos. Deben incluir al menos: codigo, nombre local, direccion, comuna, region. Opcionales: cuenta, cadena, zona, distrito.'
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

$fp = fopen($reportPath, 'w');
if (!$fp) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el reporte de errores.'], 500);
}
fputcsv($fp, ['linea', 'codigo', 'nombre local', 'motivo de fallo'], ';');
fclose($fp);

$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("
    INSERT INTO etl_locales_jobs
    (
        user_id,
        original_name,
        file_path,
        delimiter,
        total_rows,
        processed_rows,
        updated_rows,
        failed_rows,
        status,
        report_path,
        last_error,
        created_at,
        updated_at
    )
    VALUES (?, ?, ?, ?, ?, 0, 0, 0, 'pending', ?, NULL, NOW(), NOW())
");

if (!$stmt) {
    @unlink($filePath);
    @unlink($reportPath);
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el job: ' . $conn->error], 500);
}

$stmt->bind_param(
    'isssis',
    $usuario_id,
    $originalName,
    $filePath,
    $delimiter,
    $totalRows,
    $reportPath
);

if (!$stmt->execute()) {
    $stmt->close();
    @unlink($filePath);
    @unlink($reportPath);
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
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require __DIR__ . '/local_import_helpers.php';

if (!isset($usuario_id)) {
    jsonResponse(['success' => false, 'message' => 'Usuario no autenticado.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Metodo no permitido.'], 405);
}
$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken = trim($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    jsonResponse(['success' => false, 'message' => 'Token CSRF invalido.'], 400);
}

$empresaId = (int)($_POST['empresa_id'] ?? 0);
$divisionId = (int)($_POST['division_id'] ?? 0);
if ($empresaId <= 0 || $divisionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Debes seleccionar empresa y division.'], 400);
}
$stmt = $conn->prepare('SELECT 1 FROM division_empresa WHERE id=? AND id_empresa=? AND estado=1 LIMIT 1');
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo validar la division.'], 500);
}
$stmt->bind_param('ii', $divisionId, $empresaId);
$stmt->execute();
$stmt->store_result();
$validDivision = $stmt->num_rows === 1;
$stmt->close();
if (!$validDivision) {
    jsonResponse(['success' => false, 'message' => 'La division no pertenece a la empresa seleccionada.'], 400);
}
if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No se recibio correctamente el archivo CSV.'], 400);
}
$originalName = (string)($_FILES['csvFile']['name'] ?? 'locales.csv');
if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
    jsonResponse(['success' => false, 'message' => 'El archivo debe ser CSV.'], 400);
}

$uploadDir = __DIR__ . '/uploads/jobs/';
$reportDir = __DIR__ . '/uploads/reports/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    jsonResponse(['success' => false, 'message' => 'No se pudo crear la carpeta de jobs.'], 500);
}
if (!is_dir($reportDir) && !mkdir($reportDir, 0755, true)) {
    jsonResponse(['success' => false, 'message' => 'No se pudo crear la carpeta de reportes.'], 500);
}
$token = date('Ymd_His') . '_' . bin2hex(random_bytes(5));
$filePath = $uploadDir . 'local_import_' . $token . '.csv';
if (!move_uploaded_file($_FILES['csvFile']['tmp_name'], $filePath)) {
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar el archivo subido.'], 500);
}
$delimiter = detectDelimiter($filePath);
$handle = fopen($filePath, 'r');
if (!$handle) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el CSV.'], 400);
}
$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false || resolveLocalImportIndexes($headers) === false) {
    fclose($handle);
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'Encabezados invalidos. Usa las 10 columnas exactas de la plantilla.'], 400);
}
$indexes = resolveLocalImportIndexes($headers);
$totalRows = 0;
$seen = [];
$duplicates = [];
while (($row = fgetcsv($handle, 200000, $delimiter)) !== false) {
    if (!isNonEmptyCsvRow($row)) {
        continue;
    }
    $totalRows++;
    $code = etlUpper($row[$indexes['codigo']] ?? '');
    if ($code !== '' && isset($seen[$code])) {
        $duplicates[$code] = true;
    }
    $seen[$code] = true;
}
fclose($handle);
if ($totalRows === 0) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'El CSV no contiene filas de datos.'], 400);
}
if ($duplicates) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'El archivo contiene codigos repetidos: ' . implode(', ', array_slice(array_keys($duplicates), 0, 10))], 400);
}

$previewPath = $reportDir . 'locales_revision_' . $token . '.csv';
$validationPath = $uploadDir . 'locales_validation_' . $token . '.jsonl';
$acceptedPath = $reportDir . 'locales_creados_' . $token . '.csv';
$failurePath = $reportDir . 'locales_no_creados_' . $token . '.csv';
$files = [
    $previewPath => ['codigo','canal','subcanal','cuenta','cadena','nombre local','direccion','comuna','distrito','zona','region','relevancia','id vendedor','nombre vendedor','jefe de venta','id jefe de venta','estado vendedor','estado jefe de venta','direccion original','direccion limpia','direccion completa google','comuna original','comuna google','estado address validation','motivo','lat','lng','response id','linea origen'],
    $acceptedPath => ['linea','codigo','nombre local','estado address validation','direccion original','direccion limpia','direccion google','direccion guardada','comuna','distrito','zona','region','cadena','cuenta','lat','lng','response id'],
    $failurePath => ['linea','codigo','nombre local','estado address validation','direccion original','direccion limpia','direccion google','comuna','distrito','zona','region','motivo','response id']
];
foreach ($files as $path => $columns) {
    $fp = fopen($path, 'w');
    if (!$fp) {
        @unlink($filePath);
        jsonResponse(['success' => false, 'message' => 'No se pudieron iniciar los reportes.'], 500);
    }
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $columns, ';');
    fclose($fp);
}
if (file_put_contents($validationPath, '') === false) {
    @unlink($filePath);
    jsonResponse(['success' => false, 'message' => 'No se pudo iniciar la cache de validacion.'], 500);
}

$conn->set_charset('utf8mb4');
$sql = "INSERT INTO local_import_jobs (user_id,id_empresa,id_division,original_name,file_path,csv_delimiter,total_rows,status,preview_path,validation_path,accepted_report_path,failure_report_path,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'pending_validation',?,?,?,?,NOW(),NOW())";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo crear el job: ' . $conn->error], 500);
}
$stmt->bind_param('iiisssissss', $usuario_id, $empresaId, $divisionId, $originalName, $filePath, $delimiter, $totalRows, $previewPath, $validationPath, $acceptedPath, $failurePath);
if (!$stmt->execute()) {
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar el job: ' . $stmt->error], 500);
}
$jobId = (int)$stmt->insert_id;
$stmt->close();
jsonResponse(['success' => true, 'job_id' => $jobId, 'total_rows' => $totalRows, 'message' => 'Archivo recibido. Comenzara la validacion por lotes.']);

<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

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
$scope = strtolower(trim((string)($_POST['apply_scope'] ?? '')));
if ($jobId <= 0 || !in_array($scope, ['accepted_only', 'accepted_review'], true)) {
    jsonResponse(['success' => false, 'message' => 'Job o selección de estados inválida.'], 400);
}

$conn->set_charset('utf8mb4');
$stmt = $conn->prepare("SELECT status, validation_path, accepted_rows, review_rows FROM etl_locales_jobs WHERE id=? AND user_id=? LIMIT 1");
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
if ($job['status'] !== 'preview_completed') {
    jsonResponse(['success' => false, 'message' => 'Primero debes terminar y descargar la revisión.'], 409);
}
if (!is_file($job['validation_path'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'No se encontró la validación guardada del job.'], 500);
}

$eligibleRows = (int)$job['accepted_rows'];
if ($scope === 'accepted_review') {
    $eligibleRows += (int)$job['review_rows'];
}
if ($eligibleRows <= 0) {
    jsonResponse(['success' => false, 'message' => 'No existen direcciones elegibles para aplicar con esta opción.'], 409);
}

$stmt = $conn->prepare("UPDATE etl_locales_jobs SET apply_scope=?, apply_processed_rows=0, applied_rows=0, apply_failed_rows=0, updated_rows=0, status='applying', updated_at=NOW() WHERE id=? AND user_id=? LIMIT 1");
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'No se pudo iniciar la aplicación de cambios.'], 500);
}
$stmt->bind_param('sii', $scope, $jobId, $usuario_id);
if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(['success' => false, 'message' => 'No se pudo guardar la autorización de actualización.'], 500);
}
$stmt->close();

jsonResponse([
    'success' => true,
    'job_id' => $jobId,
    'apply_scope' => $scope,
    'eligible_rows' => $eligibleRows,
    'message' => 'Aplicación autorizada.'
]);

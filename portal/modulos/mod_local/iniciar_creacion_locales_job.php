<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require __DIR__ . '/local_import_helpers.php';

if (!isset($usuario_id)) jsonResponse(['success'=>false,'message'=>'Usuario no autenticado.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'Metodo no permitido.'], 405);
$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken = trim($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) jsonResponse(['success'=>false,'message'=>'Token CSRF invalido.'], 400);
$jobId = (int)($_POST['job_id'] ?? 0);
$scope = strtolower(trim((string)($_POST['create_scope'] ?? 'accepted_only')));
if ($jobId <= 0 || !in_array($scope, ['accepted_only','accepted_review'], true)) jsonResponse(['success'=>false,'message'=>'Solicitud de creacion invalida.'], 400);

$stmt = $conn->prepare('SELECT status,accepted_rows,review_rows FROM local_import_jobs WHERE id=? AND user_id=? LIMIT 1');
if (!$stmt) jsonResponse(['success'=>false,'message'=>'No se pudo consultar el job. Verifica la migracion de local_import_jobs.'], 500);
$stmt->bind_param('ii', $jobId, $usuario_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$job) jsonResponse(['success'=>false,'message'=>'Job no encontrado.'], 404);
if ($job['status'] !== 'validation_completed') jsonResponse(['success'=>false,'message'=>'La validacion debe terminar antes de crear locales.'], 409);
$creatable = (int)$job['accepted_rows'] + ($scope === 'accepted_review' ? (int)$job['review_rows'] : 0);
if ($creatable <= 0) jsonResponse(['success'=>false,'message'=>'No hay locales seleccionados que se puedan crear.'], 409);

$stmt = $conn->prepare("UPDATE local_import_jobs SET apply_scope=?,apply_processed_rows=0,inserted_rows=0,apply_failed_rows=0,status='applying',updated_at=NOW() WHERE id=? AND user_id=? LIMIT 1");
$stmt->bind_param('sii', $scope, $jobId, $usuario_id);
$stmt->execute();
$stmt->close();
jsonResponse(['success'=>true,'job_id'=>$jobId,'creatable_rows'=>$creatable,'message'=>'Creacion por lotes iniciada.']);

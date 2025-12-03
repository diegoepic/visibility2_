<?php
// eliminar_pregunta_fotoIW.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['status'=>'error','message'=>'Sesión no iniciada']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método inválido']); exit;
}
if (
  empty($_POST['csrf_token']) ||
  !isset($_SESSION['csrf_token']) ||
  $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'CSRF inválido']); exit;
}

$resp_id = isset($_POST['resp_id']) ? (int)$_POST['resp_id'] : 0;
$usuario = (int)$_SESSION['usuario_id'];
if ($resp_id <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'resp_id inválido']); exit;
}

require_once __DIR__ . '/con_.php';

// 1) Traer fila y validar que pertenezca al usuario y sea IW de su empresa
$sql = "
  SELECT r.id, r.answer_text, q.id AS qid, f.id_empresa, f.tipo
    FROM form_question_responses r
    JOIN form_questions q ON q.id = r.id_form_question
    JOIN formulario f     ON f.id = q.id_formulario
   WHERE r.id = ? AND r.id_usuario = ?
   LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $resp_id, $usuario);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  http_response_code(404);
  echo json_encode(['status'=>'error','message'=>'Foto no encontrada']); exit;
}
if ((int)$row['tipo'] !== 2 || (int)$row['id_empresa'] !== (int)$_SESSION['empresa_id']) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Sin permiso']); exit;
}

$webPath = $row['answer_text']; // ej: /uploads/fotos_IW/2025-09-04/IW_...webp
$absPath = $_SERVER['DOCUMENT_ROOT'] . $webPath;

$stmt = $conn->prepare("DELETE FROM form_question_photo_meta WHERE resp_id=?");
$stmt->bind_param("i", $resp_id);
$stmt->execute();
$stmt->close();


// 2) Borrar fila
$stmt = $conn->prepare("DELETE FROM form_question_responses WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $resp_id);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'No se pudo eliminar en BD']); exit;
}

// 3) Borrar archivo (si existe)
if (is_file($absPath)) {
  @unlink($absPath);
}

echo json_encode(['status'=>'success']);

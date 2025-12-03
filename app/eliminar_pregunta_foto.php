<?php

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
  echo json_encode(['status'=>'error','message'=>'Sesión no iniciada']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status'=>'error','message'=>'Método inválido']); exit;
}
if (
  empty($_POST['csrf_token']) ||
  !isset($_SESSION['csrf_token']) ||
  $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
  echo json_encode(['status'=>'error','message'=>'CSRF inválido']); exit;
}

$resp_id = isset($_POST['resp_id']) ? intval($_POST['resp_id']) : 0;
$qid     = isset($_POST['id_form_question']) ? intval($_POST['id_form_question']) : 0;
$visita  = isset($_POST['visita_id']) ? intval($_POST['visita_id']) : 0;
$usuario = intval($_SESSION['usuario_id']);

if ($resp_id <= 0 || $qid <= 0 || $visita <= 0) {
  echo json_encode(['status'=>'error','message'=>'Parámetros inválidos']); exit;
}

$sql = "SELECT id, answer_text
          FROM form_question_responses
         WHERE id=? AND id_form_question=? AND visita_id=? AND id_usuario=?
         LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $resp_id, $qid, $visita, $usuario);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
  echo json_encode(['status'=>'error','message'=>'No existe o sin permisos']); exit;
}
$row = $res->fetch_assoc();
$stmt->close();

// 2) Borrar archivo físico si existe
$answer_url = $row['answer_text']; // ej: /visibility2/app/uploads/uploads_fotos_pregunta/xxx.ext
$full_path  = $_SERVER['DOCUMENT_ROOT'] . $answer_url;
if (is_file($full_path)) {
  @unlink($full_path);
}

// 3) Borrar la fila en BD
$del = $conn->prepare("DELETE FROM form_question_responses WHERE id=? AND id_usuario=?");
$del->bind_param("ii", $resp_id, $usuario);
if (!$del->execute()) {
  echo json_encode(['status'=>'error','message'=>'Error al borrar en BD: '.$del->error]); exit;
}
$del->close();

echo json_encode(['status'=>'success']);

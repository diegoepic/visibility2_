<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';
header('Content-Type: application/json');

// 1️⃣ Validar sesión + csrf opcional
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'No autenticado']);
    exit;
}

$uid   = intval($_SESSION['usuario_id']);
$idLoc = isset($_POST['id_local'])   ? intval($_POST['id_local'])   : 0;
$qid   = isset($_POST['id_pregunta'])? intval($_POST['id_pregunta']): 0;
$resp  = $_POST['respuesta'] ?? null;
$valor = isset($_POST['valor'])      ? intval($_POST['valor'])      : null;

// 2️⃣ Validaciones mínimas
if (!$qid || !$idLoc || $resp===null) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Parámetros faltantes']);
    exit;
}

// 3️⃣ Insertar _o_ actualizar la respuesta
// (depende si permites múltiples o sólo una por pregunta)
$sql = "
  INSERT INTO form_question_responses
    (id_form_question,id_local,id_usuario,answer_text,valor,created_at)
  VALUES (?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE
    answer_text = VALUES(answer_text),
    valor       = VALUES(valor),
    created_at  = NOW()
";
$stmt = $conn->prepare($sql);
$stmt->bind_param(
  "iiisi",
  $qid,
  $idLoc,
  $uid,
  $resp,
  $valor
);
if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$stmt->error]);
  exit;
}
echo json_encode(['status'=>'success']);

<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit("Sesión expirada"); }

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idForm = isset($_POST['idForm']) ? (int)$_POST['idForm'] : 0;
$idQ    = isset($_POST['idQuestion']) ? (int)$_POST['idQuestion'] : 0;
$text   = trim($_POST['text'] ?? '');

if ($idForm<=0 || $idQ<=0 || $text===''){ http_response_code(400); exit("Datos inválidos"); }

$st=$conn->prepare("UPDATE form_questions SET question_text=?, id_question_set_question=NULL WHERE id=? AND id_formulario=?");
$st->bind_param("sii", $text, $idQ, $idForm);
$st->execute(); $st->close();

echo "ok";

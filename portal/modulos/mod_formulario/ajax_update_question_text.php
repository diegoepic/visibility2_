<?php
// ajax_update_question_text.php — Actualiza el texto de una pregunta (edición inline)

session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo "No autorizado."; exit(); }

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  http_response_code(403);
  echo "Token CSRF inválido.";
  exit();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST'){ http_response_code(405); echo "Método inválido."; exit(); }

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$idSet      = isset($_POST['idSet']) ? (int)$_POST['idSet'] : 0;
$idQuestion = isset($_POST['idQuestion']) ? (int)$_POST['idQuestion'] : 0;
$text       = isset($_POST['text']) ? trim($_POST['text']) : '';

if ($idSet<=0 || $idQuestion<=0 || $text===''){ http_response_code(400); echo "Parámetros inválidos."; exit(); }

// Verificar pertenencia
$st = $conn->prepare("SELECT id FROM question_set_questions WHERE id=? AND id_question_set=?");
$st->bind_param("ii", $idQuestion, $idSet);
$st->execute();
$exists = $st->get_result()->fetch_assoc();
$st->close();

if (!$exists){ http_response_code(404); echo "Pregunta no encontrada."; exit(); }

// Update
$st = $conn->prepare("UPDATE question_set_questions SET question_text=?, updated_at=NOW() WHERE id=? AND id_question_set=?");
$st->bind_param("sii", $text, $idQuestion, $idSet);
$ok = $st->execute();
$st->close();

if ($ok){ echo "OK"; }
else { http_response_code(500); echo "No se pudo actualizar."; }

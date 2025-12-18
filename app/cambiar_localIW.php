<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
  http_response_code(401);
  echo json_encode(['status'=>'error','message'=>'Sesión no iniciada']);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método inválido']);
  exit;
}
if (empty($_POST['csrf_token']) || ($_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? ''))) {
  if (function_exists('deny_csrf_json')) { deny_csrf_json(); }
  http_response_code(419);
  echo json_encode(['status'=>'error','message'=>'CSRF inválido']);
  exit;
}

require_once __DIR__ . '/con_.php';

$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)$_SESSION['empresa_id'];

$idCampana  = (int)($_POST['idCampana'] ?? 0);
$visita_id  = (int)($_POST['visita_id'] ?? 0);
$new_local  = (int)($_POST['id_local']   ?? 0);

if ($idCampana<=0 || $visita_id<=0 || $new_local<=0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'Parámetros insuficientes']);
  exit;
}

/* 1) Validar campaña IW de la misma empresa.
      Nota: ya NO restringimos por división; solo verificamos empresa y que requiera local. */
$stmt = $conn->prepare("
  SELECT id_empresa, iw_requiere_local
  FROM formulario
  WHERE id=? AND tipo=2
  LIMIT 1
");
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$stmt->bind_result($formEmp, $reqLocal);
$okCamp = $stmt->fetch();
$stmt->close();

if (!$okCamp || (int)$formEmp !== $empresa_id || (int)$reqLocal !== 1) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Campaña inválida o no requiere local']);
  exit;
}

/* 2) Validar visita del usuario/campaña y leer old_local (debe estar abierta) */
$stmt = $conn->prepare("
  SELECT id_local
  FROM visita
  WHERE id=? AND id_usuario=? AND id_formulario=? AND fecha_fin IS NULL
  LIMIT 1
");
$stmt->bind_param("iii", $visita_id, $usuario_id, $idCampana);
$stmt->execute();
$stmt->bind_result($old_local);
$okVis = $stmt->fetch();
$stmt->close();

if (!$okVis) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Visita inválida']);
  exit;
}
$old_local = (int)$old_local;

/* 3) Validar que el nuevo local pertenezca a la MISMA EMPRESA (sin filtrar por división) */
$q = $conn->prepare("SELECT id FROM local WHERE id=? AND id_empresa=? LIMIT 1");
$q->bind_param("ii", $new_local, $empresa_id);
$q->execute();
$q->bind_result($lid);
$okLoc = $q->fetch();
$q->close();

if (!$okLoc) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Local inválido (empresa distinta)']);
  exit;
}

/* 4) Transacción: actualizar visita + cascadear a respuestas/meta + mover clave de sesión */
$conn->begin_transaction();
try {
  // Actualizar visita
  $u1 = $conn->prepare("
    UPDATE visita
       SET id_local=?
     WHERE id=? AND id_usuario=? AND id_formulario=? AND fecha_fin IS NULL
  ");
  $u1->bind_param("iiii", $new_local, $visita_id, $usuario_id, $idCampana);
  $u1->execute();
  $u1->close();

  // Cascada en respuestas
  $u2 = $conn->prepare("
    UPDATE form_question_responses
       SET id_local=?
     WHERE visita_id=? AND id_usuario=?
  ");
  $u2->bind_param("iii", $new_local, $visita_id, $usuario_id);
  $u2->execute();
  $u2->close();

  // Cascada en meta de fotos (si existe la tabla)
  $hasMeta = $conn->query("SHOW TABLES LIKE 'form_question_photo_meta'");
  if ($hasMeta && $hasMeta->num_rows > 0) {
    $u3 = $conn->prepare("
      UPDATE form_question_photo_meta
         SET id_local=?
       WHERE visita_id=? AND id_usuario=?
    ");
    $u3->bind_param("iii", $new_local, $visita_id, $usuario_id);
    $u3->execute();
    $u3->close();
  }
  if ($hasMeta instanceof mysqli_result) { $hasMeta->free(); }

  // Mover la clave de sesión para reusar la visita
  if (!isset($_SESSION['iw_visitas'])) $_SESSION['iw_visitas'] = [];
  // Elimina cualquier clave previa que apunte a esta visita
  foreach ($_SESSION['iw_visitas'] as $k => $val) {
    if ((int)$val === $visita_id) unset($_SESSION['iw_visitas'][$k]);
  }
  $newKey = $idCampana . ':' . $new_local;
  $_SESSION['iw_visitas'][$newKey] = $visita_id;

  $conn->commit();
  echo json_encode(['status'=>'success','id_local'=>$new_local]);

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'No se pudo cambiar el local']);
}

<?php
header('Content-Type: application/json');
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
  http_response_code(401);
  echo json_encode(['status'=>'error','message'=>'Sesión no iniciada']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método inválido']); exit;
}
if (empty($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  if (function_exists('deny_csrf_json')) { deny_csrf_json(); }
  http_response_code(419);
  echo json_encode(['status'=>'error','message'=>'CSRF inválido']); exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

$idCampana = isset($_POST['idCampana']) ? (int)$_POST['idCampana'] : 0;
if ($idCampana <= 0) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'ID de campaña inválido']); exit; }

function normCoord($v, $min, $max) {
  if ($v === '' || $v === null) return null;
  if (!is_numeric($v)) return null;
  $f = (float)$v;
  if ($f < $min || $f > $max) return null;
  return round($f, 6);
}
$lat = isset($_POST['latitud'])  ? normCoord($_POST['latitud'],  -90,  90)
     : (isset($_POST['lat'])     ? normCoord($_POST['lat'],      -90,  90) : null);
$lng = isset($_POST['longitud']) ? normCoord($_POST['longitud'], -180, 180)
     : (isset($_POST['lng'])     ? normCoord($_POST['lng'],      -180, 180) : null);

$usuarioId = (int)$_SESSION['usuario_id'];
$empresaId = (int)$_SESSION['empresa_id'];

/* ==== Permisos + flag requiere local (ya NO bloqueamos por división) ==== */
$stmt = $conn->prepare("SELECT id_empresa, iw_requiere_local FROM formulario WHERE id=? AND tipo=2 LIMIT 1");
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$stmt->bind_result($formEmpresaId, $requiereLocal);
$ok = $stmt->fetch();
$stmt->close();

if (!$ok || (int)$formEmpresaId !== $empresaId) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'No tienes permiso para esta campaña']); exit;
}

/* ==== Validar id_local si la campaña lo exige (solo empresa) ==== */
$id_local = 0;
if ((int)$requiereLocal === 1) {
  $id_local = isset($_POST['id_local']) ? (int)$_POST['id_local'] : 0;
  if ($id_local <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Debes seleccionar un local para esta campaña']); exit;
  }
  // Sólo comprobamos que el local pertenezca a la misma empresa (sin filtrar por división)
  $q = $conn->prepare("SELECT id FROM local WHERE id=? AND id_empresa=? LIMIT 1");
  $q->bind_param("ii", $id_local, $empresaId);
  $q->execute(); $q->bind_result($lid); $hasLocal = $q->fetch(); $q->close();
  if (!$hasLocal) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Local inválido (empresa distinta)']); exit;
  }
}

/* ==== Reusar visita activa en sesión por (campaña + local) ==== */
if (empty($_SESSION['iw_visitas'])) $_SESSION['iw_visitas'] = [];
$sessKey = $idCampana . ':' . (int)$id_local;

if (!empty($_SESSION['iw_visitas'][$sessKey])) {
  $visitaIdSess = (int)$_SESSION['iw_visitas'][$sessKey];
  $q = $conn->prepare("SELECT id FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? AND id_local=? AND fecha_fin IS NULL LIMIT 1");
  $q->bind_param("iiii", $visitaIdSess, $usuarioId, $idCampana, $id_local);
  $q->execute(); $q->bind_result($foundId); $okReuse = $q->fetch(); $q->close();
  if ($okReuse && (int)$foundId > 0) { echo json_encode(['status'=>'success','visita_id'=>$foundId]); exit; }
  unset($_SESSION['iw_visitas'][$sessKey]);
}

/* ==== Crear visita ==== */
$sqlIns = "INSERT INTO visita (id_usuario, id_formulario, id_local, fecha_inicio, latitud, longitud)
           VALUES (?, ?, ?, NOW(), ?, ?)";
$stmt = $conn->prepare($sqlIns);
if (!$stmt) { http_response_code(500); echo json_encode(['status'=>'error','message'=>'Error interno (insert/prepare)']); exit; }

$stmt->bind_param("iiidd", $usuarioId, $idCampana, $id_local, $lat, $lng);
if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'No se pudo crear la visita']); exit;
}
$visitaId = $stmt->insert_id;
$stmt->close();

$_SESSION['iw_visitas'][$sessKey] = (int)$visitaId;

echo json_encode(['status'=>'success', 'visita_id'=>$visitaId]);

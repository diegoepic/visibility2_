<?php
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

$qid       = isset($_POST['id_form_question']) ? (int)$_POST['id_form_question'] : 0;
$visita_id = isset($_POST['visita_id']) ? (int)$_POST['visita_id'] : 0;
$usuario   = (int)$_SESSION['usuario_id'];

if ($qid <= 0 || $visita_id <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'Parámetros inválidos']); exit;
}

require_once __DIR__ . '/con_.php';

$empresaId = (int)$_SESSION['empresa_id'];

/* ===== Prefijos de uploads =====
   Ajusta IW_UPLOAD_URL_1 e IW_UPLOAD_URL_2 según cómo guardas en el endpoint de subida.
   Si solo usas uno, puedes dejar solo IW_UPLOAD_URL_1 y quitar la condición OR del SQL.
*/
define('IW_UPLOAD_URL_1', '/uploads/fotos_IW');
define('IW_UPLOAD_URL_2', '/visibility2/app/uploads/fotos_IW'); // fallback si migraste

function iw_abs_url(string $path): string {
  if ($path === '' ) return $path;
  if (preg_match('#^https?://#i', $path)) return $path;
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
  $host  = $_SERVER['HTTP_HOST'] ?? '';
  return $proto . $host . $path;
}

// 1) Verificar pregunta -> campaña IW empresa
$sqlPerm = "
  SELECT fq.id_formulario, f.tipo, f.id_empresa
    FROM form_questions fq
    JOIN formulario f ON f.id = fq.id_formulario
   WHERE fq.id = ?
   LIMIT 1
";
$stmt = $conn->prepare($sqlPerm);
$stmt->bind_param("i", $qid);
$stmt->execute();
$meta = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$meta || (int)$meta['tipo'] !== 2 || (int)$meta['id_empresa'] !== $empresaId) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Sin permiso']); exit;
}
$idCampana = (int)$meta['id_formulario'];

// 2) Validar visita del usuario y de esa campaña
$sqlV = "SELECT id FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? LIMIT 1";
$stmt = $conn->prepare($sqlV);
$stmt->bind_param("iii", $visita_id, $usuario, $idCampana);
$stmt->execute();
$stmt->bind_result($vId); $okV = $stmt->fetch(); $stmt->close();
if (!$okV) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Visita no válida']); exit;
}

// 3) Listar fotos (acepta ambos prefijos de forma robusta)
$sql = "
  SELECT id, answer_text
    FROM form_question_responses
   WHERE visita_id = ?
     AND id_form_question = ?
     AND id_usuario = ?
     AND id_option = 0
     AND (
          answer_text LIKE CONCAT(?, '/%')
       OR answer_text LIKE CONCAT(?, '/%')
     )
   ORDER BY id ASC
";
$stmt = $conn->prepare($sql);
$pref1 = IW_UPLOAD_URL_1;
$pref2 = IW_UPLOAD_URL_2;
$stmt->bind_param("iiiss", $visita_id, $qid, $usuario, $pref1, $pref2);
$stmt->execute();
$res = $stmt->get_result();

$fotos = [];
while ($row = $res->fetch_assoc()) {
  $fotos[] = [
    'resp_id' => (int)$row['id'],
    // Devuelve absoluta para que siempre cargue bien
    'fotoUrl' => iw_abs_url($row['answer_text']),
  ];
}
$stmt->close();

echo json_encode(['status' => 'success', 'fotos' => $fotos], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

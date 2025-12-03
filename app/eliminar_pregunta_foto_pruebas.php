<?php
// /visibility2/app/eliminar_pregunta_foto_pruebas.php
// Borrado robusto de foto-respuesta (tipo 7) con:
// - JSON o form-data
// - CSRF (header/body)
// - Idempotencia (request_log -> 'pregunta_foto_delete')
// - Normalización de URL (http(s)://dominio + /visibility2/app/ -> uploads/...)
// - Transacción para meta+respuesta y borrado físico seguro

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* ---------------- Helpers ---------------- */
function json_reply(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function get_header_lower(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$key]) && $_SERVER[$key] !== '' ? trim((string)$_SERVER[$key]) : null;
}
function read_csrf(?array $json = null): ?string {
  $h = get_header_lower('X-CSRF-Token');
  if (!empty($h)) return $h;
  if (isset($_POST['csrf_token']) && $_POST['csrf_token']!=='') return (string)$_POST['csrf_token'];
  if ($json && isset($json['csrf_token']) && $json['csrf_token']!=='') return (string)$json['csrf_token'];
  return null;
}
function read_json_body(): array {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') === false) return [];
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
/** Normaliza y reinyecta X-Idempotency-Key (header/body/JSON) para idempotency.php */
function sanitize_idempotency_key(?array $json = null): void {
  $raw = get_header_lower('X-Idempotency-Key');
  if (!$raw && isset($_POST['X_Idempotency_Key'])) $raw = (string)$_POST['X_Idempotency_Key'];
  if (!$raw && $json && isset($json['X_Idempotency_Key'])) $raw = (string)$json['X_Idempotency_Key'];
  if ($raw !== null && $raw !== '') {
    $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
    if (strlen($k) > 64) $k = substr(hash('sha256', $k), 0, 64);
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
    $_POST['X_Idempotency_Key'] = $k; // para compatibilidad
  }
}
/** Quita dominio/prefijo y vuelve ruta en forma uploads/... */
function normalize_upload_rel(string $u): string {
  $u = trim($u);
  // quitar dominio si viene absoluta
  $u = preg_replace('#^https?://[^/]+#i', '', $u);
  // quitar prefijo /visibility2/app/
  if (strpos($u, '/visibility2/app/') === 0) $u = substr($u, strlen('/visibility2/app/'));
  // homogeneizar si ya viene bien
  if (strpos($u, 'uploads/') === 0) return $u;
  // tolerar rutas que empiezan con /uploads
  if (strpos($u, '/uploads/') === 0) return ltrim($u, '/');
  return $u; // si no calza, devolver tal cual (no se borrará si no parte en uploads/)
}

/* ---------------- Validaciones básicas ---------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Allow: POST');
  json_reply(405, ['status' => 'error', 'message' => 'Método inválido']);
}
if (empty($_SESSION['usuario_id'])) {
  json_reply(401, ['status' => 'error', 'message' => 'Sesión no iniciada']);
}
$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

/* Mezclar JSON body con $_POST (prioriza POST tradicional) */
$J = read_json_body();
$POST = $_POST + $J;

/* CSRF */
$csrf = read_csrf($J);
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  json_reply(419, ['status' => 'error', 'message' => 'CSRF inválido o ausente']);
}

/* ---------------- Conexión BD ---------------- */
/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
  if (!isset($conn) || !($conn instanceof mysqli)) {
    json_reply(500, ['status' => 'error', 'message' => 'Sin conexión a BD']);
  }
}
@$conn->set_charset('utf8mb4');

/* ---------------- Idempotencia ---------------- */
require_once __DIR__ . '/lib/idempotency.php';
sanitize_idempotency_key($J);
idempo_claim_or_fail($conn, 'pregunta_foto_delete'); // si ya existe respuesta previa, responde y exit

/* ---------------- Inputs ---------------- */
$resp_id          = isset($POST['resp_id']) ? (int)$POST['resp_id'] : 0;
$id_form_question = isset($POST['id_form_question']) ? (int)$POST['id_form_question'] : 0;
$visita_id        = isset($POST['visita_id']) ? (int)$POST['visita_id'] : 0;

if ($resp_id <= 0 || $id_form_question <= 0 || $visita_id <= 0) {
  idempo_store_and_reply($conn, 'pregunta_foto_delete', 400, [
    'status' => 'error', 'message' => 'Parámetros inválidos'
  ]);
  exit;
}

/* ---------------- Cargar y validar pertenencia ---------------- */
$sql = "
  SELECT r.id, r.answer_text AS url, r.id_form_question, r.id_local, r.visita_id
    FROM form_question_responses r
    JOIN form_questions q ON q.id = r.id_form_question
    JOIN formulario f     ON f.id = q.id_formulario AND f.id_empresa = ?
   WHERE r.id = ?
     AND r.id_form_question = ?
     AND r.visita_id = ?
     AND r.id_usuario = ?
   LIMIT 1
";
$st = $conn->prepare($sql);
if (!$st) {
  idempo_store_and_reply($conn, 'pregunta_foto_delete', 500, [
    'status' => 'error', 'message' => 'Error preparando consulta'
  ]);
  exit;
}
$st->bind_param('iiiii', $empresa_id, $resp_id, $id_form_question, $visita_id, $usuario_id);

$row = null;
$use_get_result = method_exists($st, 'get_result');
if (!$st->execute()) {
  $st->close();
  idempo_store_and_reply($conn, 'pregunta_foto_delete', 500, [
    'status' => 'error', 'message' => 'Error al ejecutar consulta'
  ]);
  exit;
}
if ($use_get_result) {
  $res = $st->get_result();
  if (!$res || $res->num_rows === 0) {
    $st->close();
    idempo_store_and_reply($conn, 'pregunta_foto_delete', 200, [
      'status' => 'success',
      'message' => 'Nada que eliminar (ya no existe o no aplica).',
      'resp_id' => $resp_id,
      'id_form_question' => $id_form_question,
      'visita_id' => $visita_id,
      'already_deleted' => true
    ]);
    exit;
  }
  $row = $res->fetch_assoc();
  $st->close();
} else {
  $st->bind_result($rid, $rurl, $rqid, $rlocal, $rvisita);
  if (!$st->fetch()) {
    $st->close();
    idempo_store_and_reply($conn, 'pregunta_foto_delete', 200, [
      'status' => 'success',
      'message' => 'Nada que eliminar (ya no existe o no aplica).',
      'resp_id' => $resp_id,
      'id_form_question' => $id_form_question,
      'visita_id' => $visita_id,
      'already_deleted' => true
    ]);
    exit;
  }
  $row = [
    'id' => (int)$rid,
    'url' => (string)$rurl,
    'id_form_question' => (int)$rqid,
    'id_local' => (int)$rlocal,
    'visita_id' => (int)$rvisita
  ];
  $st->close();
}


$storedUrl = (string)$row['url'];
$relUrl    = normalize_upload_rel($storedUrl); // uploads/... (si es posible)
$absolute  = (strpos($relUrl, 'uploads/') === 0) ? '/visibility2/app/'.$relUrl : $storedUrl;

/* ---------------- Transacción: borrar meta y respuesta ---------------- */
$conn->begin_transaction();
try {
  // 1) Meta (si existe la tabla; prepare falla si no existe → silenciar)
  if ($delM = $conn->prepare("DELETE FROM form_question_photo_meta WHERE resp_id = ?")) {
    $delM->bind_param('i', $resp_id);
    $delM->execute();
    $delM->close();
  }

  // 2) Respuesta (restringimos por usuario por seguridad)
  $delR = $conn->prepare("DELETE FROM form_question_responses WHERE id = ? AND id_usuario = ?");
  if (!$delR) { throw new Exception('Error preparando delete respuesta'); }
  $delR->bind_param('ii', $resp_id, $usuario_id);
  if (!$delR->execute()) { throw new Exception('Error al eliminar respuesta'); }
  $delR->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  idempo_store_and_reply($conn, 'pregunta_foto_delete', 500, [
    'status' => 'error', 'message' => 'No se pudo eliminar la respuesta'
  ]);
  exit;
}

/* ---------------- Borrado físico seguro (y sidecar) ---------------- */
$deleted_file = false;
$deleted_side = false;
if (strpos($relUrl, 'uploads/') === 0) {
  $uploadsRoot  = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
  $relSanitized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relUrl, '/')); // uploads/...
  $abs          = __DIR__ . DIRECTORY_SEPARATOR . $relSanitized;

  // confirmar que está bajo /app/uploads
  $absReal = file_exists($abs) ? realpath($abs) : $abs;
  if ($absReal && strpos($absReal, $uploadsRoot) === 0 && is_file($absReal)) {
    $deleted_file = @unlink($absReal);
    $sidecar = $absReal . '.json';
    if (is_file($sidecar)) { $deleted_side = @unlink($sidecar); }
  }
}

/* ---------------- Respuesta (idempotente) ---------------- */
idempo_store_and_reply($conn, 'pregunta_foto_delete', 200, [
  'status'  => 'success',
  'message' => 'Foto eliminada correctamente',
  'resp_id' => $resp_id,
  'id_form_question' => $id_form_question,
  'visita_id' => $visita_id,
  'absolute' => $absolute,
  'already_deleted' => false,
  'deleted_file' => (bool)$deleted_file,
  'deleted_sidecar' => (bool)$deleted_side
]);
exit;

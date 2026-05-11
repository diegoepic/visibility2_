<?php
// /visibility2/app/eliminar_material_foto_pruebas.php
// Borrado de foto de material (tabla fotoVisita) con:
// - CSRF (header/body)
// - Validación de propiedad (usuario + empresa)
// - Idempotencia
// - Borrado físico seguro del archivo y sidecar .json

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
function read_csrf_mf(?array $json = null): ?string {
    $h = get_header_lower('X-CSRF-Token');
    if (!empty($h)) return $h;
    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] !== '') return (string)$_POST['csrf_token'];
    if ($json && isset($json['csrf_token']) && $json['csrf_token'] !== '') return (string)$json['csrf_token'];
    return null;
}
function read_json_body_mf(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false) return [];
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function sanitize_idemp_key_mf(?array $json = null): void {
    $raw = get_header_lower('X-Idempotency-Key');
    if (!$raw && isset($_POST['X_Idempotency_Key'])) $raw = (string)$_POST['X_Idempotency_Key'];
    if (!$raw && $json && isset($json['X_Idempotency_Key'])) $raw = (string)$json['X_Idempotency_Key'];
    if ($raw !== null && $raw !== '') {
        $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
        if (strlen($k) > 64) $k = substr(hash('sha256', $k), 0, 64);
        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
        $_POST['X_Idempotency_Key'] = $k;
    }
}
function normalize_upload_rel_mf(string $u): string {
    $u = trim($u);
    $u = preg_replace('#^https?://[^/]+#i', '', $u);
    if (strpos($u, '/visibility2/app/') === 0) $u = substr($u, strlen('/visibility2/app/'));
    if (strpos($u, 'uploads/') === 0) return $u;
    if (strpos($u, '/uploads/') === 0) return ltrim($u, '/');
    return $u;
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

$J    = read_json_body_mf();
$POST = $_POST + $J;

$csrf = read_csrf_mf($J);
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
sanitize_idemp_key_mf($J);
idempo_claim_or_fail($conn, 'material_foto_delete');

/* ---------------- Inputs ---------------- */
$id_foto              = isset($POST['id_foto'])              ? (int)$POST['id_foto']              : 0;
$id_formularioQuestion = isset($POST['id_formularioQuestion']) ? (int)$POST['id_formularioQuestion'] : 0;
$visita_id            = isset($POST['visita_id'])            ? (int)$POST['visita_id']            : 0;

if ($id_foto <= 0 || $id_formularioQuestion <= 0) {
    idempo_store_and_reply($conn, 'material_foto_delete', 400, [
        'status' => 'error', 'message' => 'Parámetros inválidos'
    ]);
    exit;
}

/* ---------------- Cargar y validar pertenencia ---------------- */
$sql = "
    SELECT fv.id, fv.url, fv.id_formularioQuestion, fv.visita_id
      FROM fotoVisita fv
      JOIN formulario f ON f.id = fv.id_formulario AND f.id_empresa = ?
     WHERE fv.id = ?
       AND fv.id_formularioQuestion = ?
       AND fv.id_usuario = ?
     LIMIT 1
";
$st = $conn->prepare($sql);
if (!$st) {
    idempo_store_and_reply($conn, 'material_foto_delete', 500, [
        'status' => 'error', 'message' => 'Error preparando consulta'
    ]);
    exit;
}
$st->bind_param('iiii', $empresa_id, $id_foto, $id_formularioQuestion, $usuario_id);

$row = null;
$use_get_result = method_exists($st, 'get_result');
if (!$st->execute()) {
    $st->close();
    idempo_store_and_reply($conn, 'material_foto_delete', 500, [
        'status' => 'error', 'message' => 'Error al ejecutar consulta'
    ]);
    exit;
}
if ($use_get_result) {
    $res = $st->get_result();
    if (!$res || $res->num_rows === 0) {
        $st->close();
        idempo_store_and_reply($conn, 'material_foto_delete', 200, [
            'status'       => 'success',
            'message'      => 'Nada que eliminar (ya no existe o no aplica).',
            'id_foto'      => $id_foto,
            'already_deleted' => true
        ]);
        exit;
    }
    $row = $res->fetch_assoc();
    $st->close();
} else {
    $st->bind_result($rid, $rurl, $rqid, $rvisita);
    if (!$st->fetch()) {
        $st->close();
        idempo_store_and_reply($conn, 'material_foto_delete', 200, [
            'status'       => 'success',
            'message'      => 'Nada que eliminar (ya no existe o no aplica).',
            'id_foto'      => $id_foto,
            'already_deleted' => true
        ]);
        exit;
    }
    $row = [
        'id'                  => (int)$rid,
        'url'                 => (string)$rurl,
        'id_formularioQuestion' => (int)$rqid,
        'visita_id'           => (int)$rvisita,
    ];
    $st->close();
}

$storedUrl = (string)$row['url'];
$relUrl    = normalize_upload_rel_mf($storedUrl);
$absolute  = (strpos($relUrl, 'uploads/') === 0) ? '/visibility2/app/' . $relUrl : $storedUrl;

/* ---------------- Transacción: borrar meta y registro ---------------- */
$conn->begin_transaction();
try {
    // 1) Metadata (tabla opcional — try-catch para sobrevivir MYSQLI_REPORT_STRICT)
    try {
        $delM = $conn->prepare("DELETE FROM fotoVisita_meta WHERE id_foto = ?");
        $delM->bind_param('i', $id_foto);
        $delM->execute();
        $delM->close();
    } catch (Throwable $_) { /* fotoVisita_meta no existe en esta instalación */ }

    // 2) Registro principal (restringimos por usuario por seguridad)
    $delR = $conn->prepare("DELETE FROM fotoVisita WHERE id = ? AND id_usuario = ?");
    if (!$delR) { throw new Exception('Error preparando delete fotoVisita'); }
    $delR->bind_param('ii', $id_foto, $usuario_id);
    if (!$delR->execute()) { throw new Exception('Error al eliminar fotoVisita'); }
    $delR->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    idempo_store_and_reply($conn, 'material_foto_delete', 500, [
        'status' => 'error', 'message' => 'No se pudo eliminar el registro'
    ]);
    exit;
}

/* ---------------- Borrado físico seguro (y sidecar) ---------------- */
$deleted_file = false;
$deleted_side = false;
if (strpos($relUrl, 'uploads/') === 0) {
    $uploadsRoot  = realpath(__DIR__ . '/uploads') ?: (__DIR__ . '/uploads');
    $relSanitized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relUrl, '/'));
    $abs          = __DIR__ . DIRECTORY_SEPARATOR . $relSanitized;
    $absReal      = file_exists($abs) ? realpath($abs) : $abs;
    if ($absReal && strpos($absReal, $uploadsRoot) === 0 && is_file($absReal)) {
        $deleted_file = @unlink($absReal);
        $sidecar = $absReal . '.json';
        if (is_file($sidecar)) { $deleted_side = @unlink($sidecar); }
    }
}

/* ---------------- Respuesta idempotente ---------------- */
idempo_store_and_reply($conn, 'material_foto_delete', 200, [
    'status'         => 'success',
    'message'        => 'Foto eliminada correctamente',
    'id_foto'        => $id_foto,
    'absolute'       => $absolute,
    'already_deleted' => false,
    'deleted_file'   => (bool)$deleted_file,
    'deleted_sidecar' => (bool)$deleted_side
]);
exit;

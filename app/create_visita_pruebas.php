<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
@set_time_limit(20);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/* -------------------- Conexión -------------------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'No hay conexión válida a la base de datos.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
@$conn->set_charset('utf8mb4');

/* -------------------- Idempotencia -------------------- */
require_once __DIR__ . '/lib/idempotency.php';

/* -------------------- Helpers -------------------- */
function json_fail(int $code, string $message, array $extra = []): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'status' => 'error', 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $payload): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'status' => 'ok'] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_header_lower(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) && $_SERVER[$key] !== '' ? trim((string)$_SERVER[$key]) : null;
}

function read_csrf(): ?string {
    $h = get_header_lower('X-CSRF-Token');
    if (!empty($h)) return $h;
    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] !== '') return (string)$_POST['csrf_token'];
    if (isset($_GET['csrf_token']) && $_GET['csrf_token'] !== '') return (string)$_GET['csrf_token'];
    return null;
}

function norm_lat($v): float {
    $x = is_numeric($v) ? (float)$v : 0.0;
    if ($x > 90) $x = 90.0;
    if ($x < -90) $x = -90.0;
    return $x;
}

function norm_lng($v): float {
    $x = is_numeric($v) ? (float)$v : 0.0;
    if ($x > 180) $x = 180.0;
    if ($x < -180) $x = -180.0;
    return $x;
}

// -------------------------------------------------------------
// Nueva versión para MySQL 8: evita 0000-00-00 00:00:00
// -------------------------------------------------------------
function normalize_datetime(?string $s): ?string {
    if (!$s) return null;
    if ($s === '0000-00-00 00:00:00') return null;
    $ts = @strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function allow_cors_and_options(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allow  = 'https://visibility.cl';

    if ($origin && preg_match('#https://([a-z0-9.-]+\.)?visibility\.cl$#i', $origin)) {
        $allow = $origin;
    }

    header('Vary: Origin');
    header('Access-Control-Allow-Origin: ' . $allow);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token, X-Idempotency-Key, X-HTTP-Method-Override, X_Offline_Queue, X-Offline-Queue');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        echo '';
        exit;
    }
}

function sanitize_idempotency_key(): void {
    $raw = get_header_lower('X-Idempotency-Key');
    if (!$raw && isset($_POST['X_Idempotency_Key'])) {
        $raw = (string)$_POST['X_Idempotency_Key'];
    }

    if ($raw !== null && $raw !== '') {
        $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
        if (strlen($k) > 64) {
            $k = substr(hash('sha256', $k), 0, 64);
        }
        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
        $_POST['X_Idempotency_Key'] = $k;
    }
}

/* -------------------- CORS / OPTIONS -------------------- */
allow_cors_and_options();

/* -------------------- Método -------------------- */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = get_header_lower('X-HTTP-Method-Override')
    ?? ($_POST['_method'] ?? null)
    ?? ($_GET['_method'] ?? null);

if ($override) {
    $method = strtoupper((string)$override);
}

if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    json_fail(405, 'Método no permitido. Usa POST.');
}

/* -------------------- Inputs -------------------- */
$form_id = isset($_POST['id_formulario']) ? (int)$_POST['id_formulario'] : (int)($_GET['id_formulario'] ?? 0);
$local_id = isset($_POST['id_local']) ? (int)$_POST['id_local'] : (int)($_GET['id_local'] ?? 0);

if ($form_id === 0) {
    $form_id = isset($_POST['idCampana']) ? (int)$_POST['idCampana'] : (int)($_GET['idCampana'] ?? 0);
}
if ($local_id === 0) {
    $local_id = isset($_POST['idLocal']) ? (int)$_POST['idLocal'] : (int)($_GET['idLocal'] ?? 0);
}

$client_guid = isset($_POST['client_guid']) ? trim((string)$_POST['client_guid']) : (string)($_GET['client_guid'] ?? '');
$visita_local = isset($_POST['visita_local_id']) ? trim((string)$_POST['visita_local_id']) : (string)($_GET['visita_local_id'] ?? '');
$lat = norm_lat($_POST['lat'] ?? ($_GET['lat'] ?? 0));
$lng = norm_lng($_POST['lng'] ?? ($_GET['lng'] ?? 0));
$started_at_in = isset($_POST['started_at']) ? trim((string)$_POST['started_at']) : (string)($_GET['started_at'] ?? '');
$started_at = normalize_datetime($started_at_in);

/* -------------------- Seguridad base -------------------- */
if (!isset($_SESSION['usuario_id'])) {
    json_fail(401, 'No autenticado.', ['error_code' => 'NO_SESSION', 'retryable' => false]);
}
$user_id = (int)$_SESSION['usuario_id'];
$csrf_session = $_SESSION['csrf_token'] ?? null;

$csrf = read_csrf();
if (empty($csrf) || empty($csrf_session) || !hash_equals($csrf_session, $csrf)) {
    json_fail(419, 'CSRF inválido o ausente.', ['error_code' => 'CSRF_INVALID', 'retryable' => false]);
}

/* -------------------- TEST MODE -------------------- */
if (getenv('V2_TEST_MODE') === '1') {
    $allowedHosts = ['localhost', '127.0.0.1', 'dev.visibility.cl', 'staging.visibility.cl'];
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';

    if (!in_array($currentHost, $allowedHosts, true)) {
        json_fail(403, 'TEST_MODE está deshabilitado en producción.', [
            'error_code' => 'TEST_MODE_FORBIDDEN',
            'retryable' => false
        ]);
    }

    sanitize_idempotency_key();
    $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ($_POST['X_Idempotency_Key'] ?? 'test_key');

    if (!isset($_SESSION['test_idempo'])) {
        $_SESSION['test_idempo'] = [];
    }

    if (isset($_SESSION['test_idempo'][$key])) {
        json_ok($_SESSION['test_idempo'][$key] + ['idempotent' => true]);
    }

    $payload = [
        'visita_id' => 1000 + count($_SESSION['test_idempo']),
        'client_guid' => $client_guid ?: 'test-guid'
    ];

    $_SESSION['test_idempo'][$key] = $payload;
    session_write_close();
    json_ok($payload);
}

session_write_close();

if ($form_id <= 0 || $local_id <= 0) {
    json_fail(400, 'Parámetros inválidos: id_formulario/idCampana e id_local/idLocal son requeridos.', [
        'error_code' => 'VALIDATION',
        'retryable' => false
    ]);
}

/* -------------------- Permisos -------------------- */
$perm_ok = false;
$st = $conn->prepare("
    SELECT 1
    FROM formularioQuestion
    WHERE id_formulario = ?
      AND id_local = ?
      AND id_usuario = ?
    LIMIT 1
");
if (!$st) {
    json_fail(500, 'Error preparando validación de permisos.', ['db_error' => $conn->error]);
}
$st->bind_param('iii', $form_id, $local_id, $user_id);
if (!$st->execute()) {
    $err = $st->error;
    $st->close();
    json_fail(500, 'Error ejecutando validación de permisos.', ['db_error' => $err]);
}
$st->store_result();
$perm_ok = ($st->num_rows > 0);
$st->close();

if (!$perm_ok) {
    json_fail(403, 'No tienes permisos para crear una visita en este local/campaña.', [
        'error_code' => 'FORBIDDEN',
        'retryable' => false
    ]);
}

/* -------------------- Idempotencia -------------------- */
sanitize_idempotency_key();
idempo_claim_or_fail($conn, 'create_visita');

/* -------------------- Lógica principal -------------------- */
try {
    @$conn->query('SET SESSION innodb_lock_wait_timeout = 3');
    $conn->begin_transaction();

    $now = date('Y-m-d H:i:s');
    $visita_id = 0;
    $reused = false;

    // -------------------------------------------------------
    // 1) Reusar visita abierta por client_guid
    // -------------------------------------------------------
    if ($client_guid !== '') {
        $sel = $conn->prepare("
            SELECT id, fecha_fin
            FROM visita
            WHERE id_usuario = ?
              AND id_formulario = ?
              AND id_local = ?
              AND client_guid = ?
              AND (fecha_fin IS NULL)
            ORDER BY id DESC
            LIMIT 1
        ");
        $sel->bind_param('iiis', $user_id, $form_id, $local_id, $client_guid);
        $sel->execute();
        $sel->bind_result($row_id, $row_fin);

        if ($sel->fetch()) {
            $visita_id = (int)$row_id;
            $reused = true;
        }
        $sel->close();
    }

    // -------------------------------------------------------
    // 2) Reusar cualquier visita abierta del mismo trío
    // -------------------------------------------------------
    if ($visita_id === 0) {
        $sel2 = $conn->prepare("
            SELECT id, client_guid
            FROM visita
            WHERE id_usuario = ?
              AND id_formulario = ?
              AND id_local = ?
              AND (fecha_fin IS NULL)
            ORDER BY id DESC
            LIMIT 1
        ");
        $sel2->bind_param('iii', $user_id, $form_id, $local_id);
        $sel2->execute();
        $sel2->bind_result($row2_id, $row2_guid);

        if ($sel2->fetch()) {
            $visita_id = (int)$row2_id;
            $client_guid = $row2_guid ?: ($client_guid ?: '');
            $reused = true;
        }
        $sel2->close();
    }

    // -------------------------------------------------------
    // 3) Crear si no existe
    // -------------------------------------------------------
    if ($visita_id === 0) {
        if ($client_guid === '') {
            try {
                $client_guid = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $client_guid = substr(hash('sha1', uniqid((string)$user_id, true)), 0, 32);
            }
        } elseif (strlen($client_guid) > 64) {
            $client_guid = substr(hash('sha256', $client_guid), 0, 64);
        }

        $fecha_ini = $started_at ?: $now;

        $ins = $conn->prepare("
            INSERT INTO visita
                (id_usuario, id_formulario, id_local, client_guid, fecha_inicio, latitud, longitud)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param('iiissdd', $user_id, $form_id, $local_id, $client_guid, $fecha_ini, $lat, $lng);

        if (!$ins->execute()) {
            $err = (string)$ins->error;
            $ins->close();
            throw new RuntimeException('Error insert visita: ' . $err);
        }

        $visita_id = (int)$ins->insert_id;
        $ins->close();
    } else {
        $reuse_started_at = $started_at ?: $now;

        $upd = $conn->prepare("
            UPDATE visita
            SET fecha_inicio = ?,
                latitud = IF(latitud IS NULL OR latitud = 0, ?, latitud),
                longitud = IF(longitud IS NULL OR longitud = 0, ?, longitud)
            WHERE id = ?
            LIMIT 1
        ");
        $upd->bind_param('sddi', $reuse_started_at, $lat, $lng, $visita_id);
        $upd->execute();
        $upd->close();
    }

    if (!$conn->commit()) {
        throw new RuntimeException('No se pudo confirmar la transacción');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['current_visita_id'] = $visita_id;
    session_write_close();

    $payload = [
        'visita_id' => $visita_id,
        'client_guid' => $client_guid,
        'reused' => $reused,
        'now' => $now,
        'started_at' => $started_at ?: $now,
        'server_time' => $now
    ];

    if ($visita_local !== '') {
        $payload['visita_local_id'] = $visita_local;
    }

    if (function_exists('idempo_get_key') && idempo_get_key()) {
        idempo_store_and_reply($conn, 'create_visita', 200, ['status' => 'ok'] + $payload);
    } else {
        json_ok($payload);
    }

} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        @mysqli_rollback($conn);
    }

    error_log('create_visita_pruebas.php ERROR: ' . $e->getMessage());
    error_log('create_visita_pruebas.php TRACE: ' . $e->getTraceAsString());

    json_fail(500, 'Error interno al crear la visita.', [
        'error' => 'E_CREATE_VISITA',
        'debug_message' => $e->getMessage()
    ]);
}
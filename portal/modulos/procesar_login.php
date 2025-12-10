<?php
declare(strict_types=1);


$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);

error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

/* ===================== Config ===================== */
const FAILED_WINDOW_MINUTES = 15;  // ventana para umbral de fallos
const FAILED_THRESHOLD      = 10;  // umbral de fallos en ventana
const BACKOFF_BASE_MS       = 60;  // backoff mínimo por fallo
const BACKOFF_STEP_MS       = 20;  // incremento por fallo
const BACKOFF_MAX_MS        = 600; // tope

// Duración por número de bloqueos en últimas 24h
function lockDurationByHistory(int $locks24h): string {
  if ($locks24h >= 3) return '24 HOUR';
  if ($locks24h >= 1) return '60 MINUTE';
  return '15 MINUTE';
}

function app_key(): string {
  $k = getenv('APP_KEY');
  return $k && strlen($k) >= 16 ? $k : 'change_this_APP_KEY_in_env_please';
}

function mail_from(): string {
  $m = getenv('MAIL_FROM');
  return $m && strpos($m, '@') !== false ? $m : 'no-reply@visibility.cl';
}

/* ===================== Helpers ===================== */

function ip_addr(): string {
  // Evitar cabeceras spoofeadas; usa REMOTE_ADDR
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function user_agent(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}
function now_str(): string {
  return date('Y-m-d H:i:s');
}
function dt_minus(string $spec): string {
  // $spec ejemplo "-15 minutes", "-24 hours"
  return date('Y-m-d H:i:s', strtotime($spec));
}
function jsons(array $a): string {
  return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function micro_backoff(int $failedCount = 0): void {
  $ms = BACKOFF_BASE_MS + $failedCount * BACKOFF_STEP_MS + random_int(0, 50);
  $ms = min($ms, BACKOFF_MAX_MS);
  usleep($ms * 1000);
}

function fail_and_back_generic(): never {
  $_SESSION['error_login'] = "Usuario o contraseña incorrectos.";
  header("Location: /index.php");
  exit();
}
function fail_and_back_locked(string $until): never {
  $_SESSION['error_login'] = "Cuenta bloqueada temporalmente. Intenta nuevamente después de: " . $until;
  header("Location: /index.php");
  exit();
}

function send_security_email(string $to, string $subject, string $textBody): void {
  if (!$to || strpos($to, '@') === false) return;
  $headers = [];
  $headers[] = 'From: ' . mail_from();
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $textBody, implode("\r\n", $headers));
}

/* Logs */
function log_attempt(mysqli $conn, ?int $userId, string $usuarioInput, string $outcome, string $reason): void {
  $sql = "INSERT INTO login_attempts (usuario, ip, intento_at, user_id, outcome, reason)
          VALUES (?, INET6_ATON(?), NOW(), ?, ?, ?)";
  if ($st = $conn->prepare($sql)) {
    $ip = ip_addr();
    $st->bind_param("ssiss", $usuarioInput, $ip, $userId, $outcome, $reason);
    $st->execute();
    $st->close();
  }
}
function log_security_event(mysqli $conn, ?int $userId, string $type, array $meta = []): void {
  $sql = "INSERT INTO security_events (user_id, type, ip, user_agent, meta_json, created_at)
          VALUES (?, ?, INET6_ATON(?), ?, ?, NOW())";
  if ($st = $conn->prepare($sql)) {
    $ip = ip_addr();
    $ua = user_agent();
    $metaJson = jsons($meta);
    $st->bind_param("issss", $userId, $type, $ip, $ua, $metaJson);
    $st->execute();
    $st->close();
  }
}

/* Seguridad por usuario */
function ensure_user_security_row(mysqli $conn, int $userId): void {
  $sql = "INSERT IGNORE INTO user_security (user_id, failed_count, consecutive_locks, notify_email, updated_at)
          VALUES (?, 0, 0, 1, NOW())";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("i", $userId);
    $st->execute();
    $st->close();
  }
}
function get_user_security(mysqli $conn, int $userId): ?array {
  $sql = "SELECT failed_count, lock_until, consecutive_locks, last_failed_at, last_success_at,
                 INET6_NTOA(last_success_ip) AS last_success_ip_txt,
                 last_success_ua, notify_email
          FROM user_security WHERE user_id = ? LIMIT 1";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("i", $userId);
    $st->execute();
    $res = $st->get_result();
    $row = $res->fetch_assoc();
    $st->close();
    return $row ?: null;
  }
  return null;
}

function set_lock(mysqli $conn, int $userId, string $lockIntervalSpec, array $meta): void {
  $sql = "UPDATE user_security
          SET lock_until = DATE_ADD(NOW(), INTERVAL {$lockIntervalSpec}),
              failed_count = 0,
              consecutive_locks = consecutive_locks + 1,
              updated_at = NOW()
          WHERE user_id = ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("i", $userId);
    $st->execute();
    $st->close();
  }
  log_security_event($conn, $userId, 'account_locked', $meta);
}
function reset_failures_and_lock(mysqli $conn, int $userId): void {
  $sql = "UPDATE user_security
          SET failed_count = 0, lock_until = NULL, updated_at = NOW()
          WHERE user_id = ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("i", $userId);
    $st->execute();
    $st->close();
  }
}
function increment_failed(mysqli $conn, int $userId): void {
  $sql = "UPDATE user_security
          SET failed_count = failed_count + 1, last_failed_at = NOW(), updated_at = NOW()
          WHERE user_id = ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("i", $userId);
    $st->execute();
    $st->close();
  }
}
function count_failures_last_minutes(mysqli $conn, int $userId, int $minutes): int {
  $since = date('Y-m-d H:i:s', time() - $minutes * 60);
  $sql = "SELECT COUNT(*) AS n FROM login_attempts
          WHERE user_id = ? AND outcome = 'failure' AND intento_at > ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("is", $userId, $since);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($res['n'] ?? 0);
  }
  return 0;
}
function count_locks_last_24h(mysqli $conn, int $userId): int {
  $since = date('Y-m-d H:i:s', time() - 24 * 3600);
  $sql = "SELECT COUNT(*) AS n FROM security_events
          WHERE user_id = ? AND type = 'account_locked' AND created_at > ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("is", $userId, $since);
    $st->execute();
    $res = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($res['n'] ?? 0);
  }
  return 0;
}

if (!function_exists('fpr_salt')) {
  function fpr_salt(): string {
    $k = getenv('APP_KEY');
    // usa APP_KEY si existe (>=16 chars) o cae en una constante fija
    return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
  }
}

if (!function_exists('session_fingerprint')) {
  function session_fingerprint(): string {
    $sid = session_id();                // mismo ID en todo el request
    $salt = fpr_salt();                 // misma SALT en toda la app
    return hash('sha256', $sid.'|'.$salt, true); // 32 bytes binarios
  }
}

function upsert_session(mysqli $conn, int $userId, string $fpr): void {
  $sql = "INSERT INTO user_sessions (user_id, session_fpr, created_at, last_seen_at, ip, user_agent)
          VALUES (?, ?, NOW(), NOW(), INET6_ATON(?), ?)";
  if ($st = $conn->prepare($sql)) {
    $ip = ip_addr();
    $ua = user_agent();
    // bind_param no tiene tipo binario explícito; 's' sirve para varbinary
    $st->bind_param("isss", $userId, $fpr, $ip, $ua);
    $st->execute();
    $st->close();
  }
}
function revoke_other_sessions(mysqli $conn, int $userId, string $currentFpr): void {
  $sql = "UPDATE user_sessions
          SET revoked_at = NOW()
          WHERE user_id = ?
            AND revoked_at IS NULL
            AND session_fpr <> ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("is", $userId, $currentFpr);
    $st->execute();
    $st->close();
  }
}
function is_new_device_or_network(?array $sec): bool {
  if (!$sec) return false;
  if (empty($sec['last_success_at'])) return false; // primera vez: no alertar

  $prevUA = (string)($sec['last_success_ua'] ?? '');
  $prevIP = (string)($sec['last_success_ip_txt'] ?? '');
  $currUA = user_agent();
  $currIP = ip_addr();

  $uaChanged = ($prevUA !== '' && $prevUA !== $currUA);
  $ipChanged = ($prevIP !== '' && $prevIP !== $currIP);
  return $uaChanged || $ipChanged;
}


function update_last_success(mysqli $conn, int $userId): void {
  $sql = "UPDATE user_security
          SET last_success_at = NOW(),
              last_success_ip = INET6_ATON(?),
              last_success_ua = ?,
              updated_at = NOW()
          WHERE user_id = ?";
  if ($st = $conn->prepare($sql)) {
    $ip = ip_addr();
    $ua = user_agent();
    $st->bind_param("ssi", $ip, $ua, $userId);
    $st->execute();
    $st->close();
  }
}

/* ===================== Entrada ===================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: ../index.php");
  exit();
}

if (
  empty($_POST['csrf_token']) ||
  empty($_SESSION['csrf_token']) ||
  !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
  fail_and_back_generic();
}


$usuario = trim($_POST['usuario'] ?? '');
$clave   = $_POST['clave'] ?? '';

if ($usuario === '' || $clave === '') {
  fail_and_back_generic();
}

/* ===================== Buscar usuario ===================== */

$stmt = $conn->prepare("
  SELECT 
    u.id, u.rut, u.nombre, u.apellido, u.email, u.usuario, u.fotoPerfil, u.clave,
    u.id_perfil, u.id_empresa, u.id_division, u.login_count, u.fechaCreacion,
    u.telefono, p.nombre AS perfil_nombre, e.nombre AS empresa_nombre
  FROM usuario AS u
  INNER JOIN perfil AS p  ON p.id = u.id_perfil
  INNER JOIN empresa AS e ON e.id = u.id_empresa
  WHERE (u.usuario = ? OR u.email = ?) AND u.activo = 1
  LIMIT 1
");
if (!$stmt) { fail_and_back_generic(); }
$stmt->bind_param("ss", $usuario, $usuario);
$stmt->execute();
$res = $stmt->get_result();
$found = $res && $res->num_rows === 1;
$u = $found ? $res->fetch_assoc() : null;
$stmt->close();

/* ===================== Chequear lock por cuenta ===================== */

if ($found) {
  ensure_user_security_row($conn, (int)$u['id']);
  $sec = get_user_security($conn, (int)$u['id']);
  if ($sec && !empty($sec['lock_until'])) {
    $lockUntil = (string)$sec['lock_until'];
    $now = now_str();
    if ($lockUntil > $now) {
      log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'locked');
      fail_and_back_locked($lockUntil);
    }
  }
}

/* ===================== Verificación de contraseña ===================== */

$valid = $found && password_verify($clave, $u['clave'] ?? '');

/* ===================== FALLÓ ===================== */
if (!$valid) {
  if ($found) {
    increment_failed($conn, (int)$u['id']);
    log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'bad_password');

    // Ventana de fallos por usuario
    $fails = count_failures_last_minutes($conn, (int)$u['id'], FAILED_WINDOW_MINUTES);
    if ($fails >= FAILED_THRESHOLD) {
      $locks24 = count_locks_last_24h($conn, (int)$u['id']);
      $interval = lockDurationByHistory($locks24);

      set_lock(
        $conn,
        (int)$u['id'],
        $interval,
        [
          'reason'   => 'threshold_reached',
          'window'   => FAILED_WINDOW_MINUTES . 'm',
          'fails'    => $fails,
          'duration' => $interval
        ]
      );

      // Email de cuenta bloqueada
      if (!empty($u['email'])) {
        $untilQ = $conn->query("SELECT lock_until FROM user_security WHERE user_id = " . (int)$u['id'] . " LIMIT 1");
        $rowU = $untilQ ? $untilQ->fetch_assoc() : null;
        $untilStr = $rowU ? (string)$rowU['lock_until'] : 'próximos minutos';
        $msg = "Hola {$u['nombre']},\n\n"
             . "Tu cuenta fue bloqueada temporalmente por múltiples intentos fallidos.\n"
             . "Bloqueo hasta: {$untilStr}\n\n"
             . "IP reciente: " . ip_addr() . "\nNavegador: " . user_agent() . "\n\n"
             . "Si no fuiste tú, te recomendamos cambiar tu contraseña al recuperar el acceso.\n\n"
             . "Equipo Visibility";
        send_security_email($u['email'], 'Tu cuenta ha sido bloqueada temporalmente', $msg);
      }

      // Mensaje al usuario
      $untilQ = $conn->prepare("SELECT DATE_FORMAT(lock_until, '%Y-%m-%d %H:%i:%s') AS lu FROM user_security WHERE user_id = ? LIMIT 1");
      $lu = null;
      if ($untilQ) {
        $uid = (int)$u['id'];
        $untilQ->bind_param("i", $uid);
        $untilQ->execute();
        $r = $untilQ->get_result()->fetch_assoc();
        $lu = $r['lu'] ?? null;
        $untilQ->close();
      }
      fail_and_back_locked($lu ?: 'próximos minutos');
    }

    // Backoff proporcional al contador visible (no crítico si falla)
    $secRow = get_user_security($conn, (int)$u['id']);
    $failedCount = (int)($secRow['failed_count'] ?? 0);
    micro_backoff($failedCount);
  } else {
    // usuario no encontrado → intento fallido sin user_id
    log_attempt($conn, null, $usuario, 'failure', 'unknown_user');
    micro_backoff(1);
  }

  fail_and_back_generic();
}

/* ===================== EXITO ===================== */

// Bloquear perfiles no permitidos en PORTAL (p.ej. ejecutores)
if ((int)$u['id_perfil'] === 3) {
  log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'perfil_denegado');
  $_SESSION['error_login'] = "Perfil sin permisos, ingresa desde la app.";
  header("Location: ../index.php");
  exit();
}

// Reset de fallos / lock
reset_failures_and_lock($conn, (int)$u['id']);

// Rehash si es necesario
$needRehash = defined('PASSWORD_ARGON2ID')
  ? password_needs_rehash($u['clave'], PASSWORD_ARGON2ID)
  : password_needs_rehash($u['clave'], PASSWORD_DEFAULT);
if ($needRehash) {
  $newHash = defined('PASSWORD_ARGON2ID') ? password_hash($clave, PASSWORD_ARGON2ID) : password_hash($clave, PASSWORD_DEFAULT);
  if ($up = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?")) {
    $uid = (int)$u['id'];
    $up->bind_param("si", $newHash, $uid);
    $up->execute();
    $up->close();
  }
}

// Métricas y logs
if ($up = $conn->prepare("UPDATE usuario SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")) {
  $uid = (int)$u['id'];
  $up->bind_param("i", $uid);
  $up->execute();
  $up->close();
}
log_attempt($conn, (int)$u['id'], $usuario, 'success', 'login_ok');

// Regenerar ID de sesión y establecer variables
session_regenerate_id(true);

$_SESSION['usuario_id']         = (int)$u['id'];
$_SESSION['usuario_nombre']     = (string)$u['nombre'];
$_SESSION['usuario_apellido']   = (string)$u['apellido'];
$_SESSION['usuario_fotoPerfil'] = (string)$u['fotoPerfil'];
$_SESSION['usuario_perfil']     = (int)$u['id_perfil'];
$_SESSION['perfil_nombre']      = (string)$u['perfil_nombre'];
$_SESSION['empresa_nombre']     = (string)$u['empresa_nombre'];
$_SESSION['empresa_id']         = (int)$u['id_empresa'];
$_SESSION['usuario_fechaCreacion'] = $u['fechaCreacion'];
$_SESSION['email']              = (string)$u['email'];
$_SESSION['telefono']           = (string)$u['telefono'];
$_SESSION['division_id']        = isset($u['id_division']) ? (int)$u['id_division'] : 0;

// Sesión única: revoca otras y registra la actual
$fpr = session_fingerprint();
revoke_other_sessions($conn, (int)$u['id'], $fpr);
upsert_session($conn, (int)$u['id'], $fpr);

// Alerta de nuevo dispositivo/ubicación (si corresponde)
$secBefore = get_user_security($conn, (int)$u['id']);
if (is_new_device_or_network($secBefore)) {
  if (!empty($u['email'])) {
    $msg = "Hola {$u['nombre']},\n\n"
         . "Detectamos un nuevo inicio de sesión en tu cuenta.\n"
         . "Fecha: " . now_str() . "\n"
         . "IP: " . ip_addr() . "\n"
         . "Navegador: " . user_agent() . "\n\n"
         . "Si no fuiste tú, te recomendamos cambiar tu contraseña y contactar a soporte.\n\n"
         . "Equipo Visibility";
    send_security_email($u['email'], 'Nuevo inicio de sesión en tu cuenta', $msg);
  }
  log_security_event($conn, (int)$u['id'], 'new_device', []);
}

// Actualiza último login exitoso
update_last_success($conn, (int)$u['id']);

// Redirigir al home
header("Location: ../home.php");
exit();

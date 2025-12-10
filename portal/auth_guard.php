<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function app_key_guard(): string {
  $k = getenv('APP_KEY');
  return $k && strlen($k) >= 16 ? $k : 'change_this_APP_KEY_in_env_please';
}
function session_fingerprint_guard(): string {
  return hash_hmac('sha256', session_id(), app_key_guard(), true); // binario 32 bytes
}
function ip_addr_guard(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function user_agent_guard(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}
function env_int(string $key, int $default): int {
  $v = getenv($key);
  if ($v === false) return $default;
  $n = filter_var($v, FILTER_VALIDATE_INT);
  return ($n === false) ? $default : $n;
}

// Configurable por entorno: minutos de inactividad permitidos
$MAX_IDLE_MINUTES = env_int('PORTAL_IDLE_MINUTES', 60);

// 1) Exigir variables de sesión mínimas
if (empty($_SESSION['usuario_id'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: index.php?session_expired=1');
  exit();
}

$userId = (int)$_SESSION['usuario_id'];
$fpr    = session_fingerprint_guard();

// 2) Cargar la sesión registrada en BD
$sql = "SELECT id, revoked_at, last_seen_at,
               TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) AS idle_min
        FROM user_sessions
        WHERE user_id = ? AND session_fpr = ?
        LIMIT 1";
$st = $conn->prepare($sql);
if (!$st) {
  // Si no podemos validar, por seguridad, forzamos login
  $_SESSION = [];
  session_destroy();
  header('Location: index.php?session_expired=1');
  exit();
}
$st->bind_param("is", $userId, $fpr);
$st->execute();
$res = $st->get_result();
$row = $res->fetch_assoc();
$st->close();

// 3) Si no existe registro o está revocada → fuera
if (!$row || !empty($row['revoked_at'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: index.php?session_expired=1');
  exit();
}

// 4) Expiración por inactividad
$idle = (int)($row['idle_min'] ?? 0);
if ($MAX_IDLE_MINUTES > 0 && $idle > $MAX_IDLE_MINUTES) {
  // Marca como revocada por inactividad (opcional)
  if ($upd = $conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE id = ?")) {
    $sid = (int)$row['id'];
    $upd->bind_param("i", $sid);
    $upd->execute();
    $upd->close();
  }
  $_SESSION = [];
  session_destroy();
  header('Location: index.php?session_expired=1');
  exit();
}

// 5) Refresco de last_seen/ip/ua
if ($upd = $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW(), ip = INET6_ATON(?), user_agent = ? WHERE id = ?")) {
  $ip = ip_addr_guard();
  $ua = user_agent_guard();
  $sid = (int)$row['id'];
  $upd->bind_param("ssi", $ip, $ua, $sid);
  $upd->execute();
  $upd->close();
}

// (Opcional) En este punto podrías validar perfil/roles de portal con $_SESSION['usuario_perfil']
// y redirigir si no cumple.

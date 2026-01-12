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

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}


require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
require_once __DIR__ . '/lib/remember.php';

function ip_addr(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function user_agent(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}
function fpr_salt(): string {
  $k = getenv('APP_KEY');
  return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
}
function session_fingerprint(): string {
  $sid  = session_id();
  $salt = fpr_salt(); // Debe coincidir con _session_guard.php y procesar_login.php
  return hash('sha256', $sid . '|' . $salt, true); // 32 bytes binarios (VARBINARY(32))
}


if (!empty($_SESSION['usuario_id']) && isset($conn) && $conn instanceof mysqli) {
  $uid = (int)$_SESSION['usuario_id'];
  $fpr = session_fingerprint();


  if ($st = $conn->prepare("UPDATE user_sessions
                              SET revoked_at = NOW()
                            WHERE user_id = ? AND session_fpr = ? AND revoked_at IS NULL")) {
    $st->bind_param("is", $uid, $fpr);
    $st->execute();
    $st->close();
  }

  // Evento de seguridad (opcional)
  if ($st = $conn->prepare("INSERT INTO security_events (user_id, type, ip, user_agent, meta_json, created_at)
                            VALUES (?, 'logout', INET6_ATON(?), ?, '{}', NOW())")) {
    $ip = ip_addr();
    $ua = user_agent();
    $st->bind_param("iss", $uid, $ip, $ua);
    $st->execute();
    $st->close();
  }
}

// Revocar remember token actual
if (isset($conn) && $conn instanceof mysqli) {
  $cookie = parse_remember_cookie();
  if ($cookie) {
    remember_revoke_token($conn, $cookie['selector']);
  }
}
clear_remember_cookie();

// ----- Limpiar sesión y cookie -----
$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
  );
}

session_destroy();


header('Location: login.php');
exit();

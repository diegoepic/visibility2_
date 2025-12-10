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

// Conexión a BD
require_once __DIR__ . '/../con_.php';

/* Utiles */
function ip_addr(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function user_agent(): string {
  return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
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

  // (Opcional) Evento de seguridad
  if ($st = $conn->prepare("INSERT INTO security_events (user_id, type, ip, user_agent, meta_json, created_at)
                            VALUES (?, 'logout', INET6_ATON(?), ?, '{}', NOW())")) {
    $ip = ip_addr();
    $ua = user_agent();
    $st->bind_param("iss", $uid, $ip, $ua);
    $st->execute();
    $st->close();
  }
}


$_SESSION = [];

if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params['path'], $params['domain'],
    $params['secure'], $params['httponly']
  );
}

session_destroy();


header('Location: /index.php');

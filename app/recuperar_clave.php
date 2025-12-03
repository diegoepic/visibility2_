<?php
// recuperar_clave.php (reemplazo seguro)
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'domain' => '',
  'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'
]);
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

function json_ok(string $msg){ echo json_encode(['status'=>'ok','message'=>$msg]); exit; }
function json_err(string $msg){ echo json_encode(['status'=>'error','message'=>$msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_err('Método inválido'); }

// CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || empty($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  // respuesta neutra para no filtrar nada
  json_ok('Si el correo existe, te enviaremos un enlace para restablecer tu contraseña.');
}

$email = trim(strtolower($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  // respuesta neutra
  json_ok('Si el correo existe, te enviaremos un enlace para restablecer tu contraseña.');
}

// Rate-limit simple: máx 3 solicitudes / 15 min por IP y por email
$conn->query("
  CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARBINARY(16) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX(email), INDEX(created_at)
  ) ENGINE=InnoDB
");
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$check = $conn->prepare("SELECT COUNT(*) c FROM password_reset_requests
                         WHERE email=? AND ip=INET6_ATON(?) AND created_at > (NOW() - INTERVAL 15 MINUTE)");
$check->bind_param('ss', $email, $ip);
$check->execute();
$c = (int)$check->get_result()->fetch_assoc()['c'];
$check->close();
if ($c >= 3) {
  // respuesta neutra
  json_ok('Si el correo existe, te enviaremos un enlace para restablecer tu contraseña.');
}

// Busca usuario
$stmt = $conn->prepare("SELECT id, email, nombre FROM usuario WHERE email = ? AND activo = 1 LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Siempre responde neutro, pero si existe el usuario, genera token y envía mail
if ($user) {
  // Genera selector + token
  $selector = bin2hex(random_bytes(8));          // 16 chars
  $token    = bin2hex(random_bytes(32));         // 64 chars
  $hash     = hash('sha256', $token);            // 64 chars hex
  $ttlMin   = 30;

  // Guarda token
  $ins = $conn->prepare("INSERT INTO password_resets (user_id, selector, token_hash, expires_at, used, created_at, ip)
                         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, NOW(), INET6_ATON(?))");
  $uid = (int)$user['id'];
  $ins->bind_param('issis', $uid, $selector, $hash, $ttlMin, $ip);
  $ins->execute();
  $ins->close();

  // Guarda intento
  $ri = $conn->prepare("INSERT INTO password_reset_requests (email, ip, created_at) VALUES (?, INET6_ATON(?), NOW())");
  $ri->bind_param('ss', $email, $ip);
  $ri->execute();
  $ri->close();

  // Enlace de restablecimiento
  $scheme = $secure ? 'https' : 'http';
  // Ajusta esta ruta si tu portal vive en otro subpath
  $base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/visibility2/portal';
  $link   = $base . '/reset_password.php?selector=' . urlencode($selector) . '&token=' . urlencode($token);

  // Envía el correo
  $to = $user['email'];
  $subject = 'Restablecer contraseña - Visibility 2';
  $msg  = "Hola ".$user['nombre'].",\r\n\r\n";
  $msg .= "Recibimos una solicitud para restablecer tu contraseña.\r\n";
  $msg .= "Haz clic en el siguiente enlace (válido por 30 minutos):\r\n\r\n";
  $msg .= $link . "\r\n\r\n";
  $msg .= "Si no solicitaste este cambio, puedes ignorar este mensaje.\r\n";
  $headers  = "From: no-reply@visibility.cl\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

  // TIP: ideal usar SMTP (PHPMailer). Esto es fallback.
  @mail($to, $subject, $msg, $headers);
}

// Respuesta neutra SIEMPRE
json_ok('Si el correo existe, te enviaremos un enlace para restablecer tu contraseña.');

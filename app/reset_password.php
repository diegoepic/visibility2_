<?php
// reset_password.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'domain' => '',
  'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'
]);
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function render_form(string $selector, string $token, string $msg = ''): void {
  $safeSel = htmlspecialchars($selector, ENT_QUOTES, 'UTF-8');
  $safeTok = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
  $alert   = $msg ? '<div class="alert" style="color:#b33;">'.htmlspecialchars($msg,ENT_QUOTES,'UTF-8').'</div>' : '';
  echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Restablecer contraseña</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
</head>
<body class="container" style="max-width:560px; margin-top:40px;">
  <h2>Restablecer contraseña</h2>
  $alert
  <form method="POST">
    <input type="hidden" name="csrf_token" value="{$_SESSION['csrf_token']}">
    <input type="hidden" name="selector"   value="$safeSel">
    <input type="hidden" name="token"      value="$safeTok">
    <div class="form-group">
      <label>Nueva contraseña</label>
      <input class="form-control" type="password" name="pass1" required minlength="8" autocomplete="new-password">
    </div>
    <div class="form-group">
      <label>Repetir contraseña</label>
      <input class="form-control" type="password" name="pass2" required minlength="8" autocomplete="new-password">
    </div>
    <button class="btn btn-primary" type="submit">Guardar</button>
  </form>
</body>
</html>
HTML;
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $selector = $_GET['selector'] ?? '';
  $token    = $_GET['token'] ?? '';
  if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    render_form('', '', 'Enlace inválido.');
  }
  render_form($selector, $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    render_form('', '', 'Solicitud inválida.');
  }

  $selector = $_POST['selector'] ?? '';
  $token    = $_POST['token'] ?? '';
  $pass1    = $_POST['pass1'] ?? '';
  $pass2    = $_POST['pass2'] ?? '';

  if (!preg_match('/^[a-f0-9]{16}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    render_form('', '', 'Enlace inválido.');
  }
  if ($pass1 !== $pass2) {
    render_form($selector, $token, 'Las contraseñas no coinciden.');
  }
  if (strlen($pass1) < 8) {
    render_form($selector, $token, 'La contraseña debe tener al menos 8 caracteres.');
  }

  // Busca el token
  $stmt = $conn->prepare("SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used, u.id AS uid
                          FROM password_resets pr
                          JOIN usuario u ON u.id = pr.user_id
                          WHERE pr.selector = ? LIMIT 1");
  $stmt->bind_param('s', $selector);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row || (int)$row['used'] === 1 || strtotime($row['expires_at']) < time()) {
    render_form('', '', 'El enlace ha expirado o ya fue utilizado.');
  }

  // Verifica token
  $calc = hash('sha256', $token);
  if (!hash_equals($row['token_hash'], $calc)) {
    render_form('', '', 'Enlace inválido.');
  }

  // Actualiza contraseña
  if (defined('PASSWORD_ARGON2ID')) {
    $hash = password_hash($pass1, PASSWORD_ARGON2ID);
  } else {
    $hash = password_hash($pass1, PASSWORD_DEFAULT);
  }

  $uid = (int)$row['user_id'];
  $up = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?");
  $up->bind_param('si', $hash, $uid);
  $up->execute();
  $up->close();

  // Invalida el token
  $upd = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
  $prid = (int)$row['id'];
  $upd->bind_param('i', $prid);
  $upd->execute();
  $upd->close();

  // Confirma
  echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Contraseña actualizada</title>
  <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
</head>
<body class="container" style="max-width:560px; margin-top:40px;">
  <div class="alert alert-success">
    Tu contraseña fue restablecida correctamente. Ya puedes <a href="login.php">iniciar sesión</a>.
  </div>
</body>
</html>
HTML;
  exit;
}

http_response_code(405);
echo 'Método no permitido';

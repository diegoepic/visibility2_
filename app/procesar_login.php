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

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

/* ===== Helpers para sesión única (mismos que en portal) ===== */
function ip_addr(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function user_agent(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255); }
function fpr_salt(): string {
  $k = getenv('APP_KEY');
  return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
}
function session_fingerprint(): string {
  $sid  = session_id();
  $salt = fpr_salt();
  return hash('sha256', $sid . '|' . $salt, true); // 32 bytes binarios
}
function upsert_session(mysqli $conn, int $userId, string $fpr): void {
  $sql = "INSERT INTO user_sessions (user_id, session_fpr, created_at, last_seen_at, ip, user_agent)
          VALUES (?, ?, NOW(), NOW(), INET6_ATON(?), ?)";
  if ($st = $conn->prepare($sql)) {
    $ip = ip_addr();
    $ua = user_agent();
    $st->bind_param("isss", $userId, $fpr, $ip, $ua);
    $st->execute(); $st->close();
  }
}
function revoke_other_sessions(mysqli $conn, int $userId, string $currentFpr): void {
  $sql = "UPDATE user_sessions SET revoked_at = NOW()
          WHERE user_id = ? AND revoked_at IS NULL AND session_fpr <> ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("is", $userId, $currentFpr);
    $st->execute(); $st->close();
  }
}

function fail_and_back(): never {
  $_SESSION['error_login'] = "Usuario o contraseña incorrectos.";
  header("Location: login.php");
  exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: login.php");
  exit();
}

// CSRF
if (
  empty($_POST['csrf_token']) ||
  empty($_SESSION['csrf_token']) ||
  !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
  fail_and_back();
}

$usuario = trim($_POST['usuario'] ?? '');
$clave   = $_POST['clave'] ?? '';
if ($usuario === '' || $clave === '') {
  fail_and_back();
}

// Rate limit básico por IP+usuario: 10 intentos / 15 min
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$conn->query("
  CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(100) NOT NULL,
    ip VARBINARY(16) NOT NULL,
    intento_at DATETIME NOT NULL,
    INDEX (usuario),
    INDEX (intento_at)
  ) ENGINE=InnoDB
");

$stmt = $conn->prepare("SELECT COUNT(*) AS n
                        FROM login_attempts
                        WHERE usuario = ? AND ip = INET6_ATON(?) AND intento_at > (NOW() - INTERVAL 15 MINUTE)");
if (!$stmt) { error_log("Prepare RL: ".$conn->error); fail_and_back(); }
$stmt->bind_param("ss", $usuario, $ip);
$stmt->execute();
$rl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int)($rl['n'] ?? 0) >= 10) {
  fail_and_back();
}

// Buscar usuario activo por RUT o usuario
$sql = "
  SELECT 
      u.id, u.rut, u.nombre, u.apellido, u.email, u.usuario, u.fotoPerfil, u.clave,
      u.id_perfil, u.id_empresa, u.id_division, u.login_count,
      p.nombre AS perfil_nombre, e.nombre AS empresa_nombre
  FROM usuario AS u
  INNER JOIN perfil  AS p ON p.id = u.id_perfil
  INNER JOIN empresa AS e ON e.id = u.id_empresa
  WHERE (u.rut = ? OR u.usuario = ?) AND u.activo = 1
  LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) { error_log("Prepare user: ".$conn->error); fail_and_back(); }
$stmt->bind_param("ss", $usuario, $usuario);
if (!$stmt->execute()) { error_log("Execute user: ".$stmt->error); fail_and_back(); }
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
  $ins = $conn->prepare("INSERT INTO login_attempts (usuario, ip, intento_at) VALUES (?, INET6_ATON(?), NOW())");
  if ($ins) { $ins->bind_param("ss", $usuario, $ip); $ins->execute(); $ins->close(); }
  fail_and_back();
}
$u = $res->fetch_assoc();
$stmt->close();

if (!password_verify($clave, $u['clave'] ?? '')) {
  $ins = $conn->prepare("INSERT INTO login_attempts (usuario, ip, intento_at) VALUES (?, INET6_ATON(?), NOW())");
  if ($ins) { $ins->bind_param("ss", $usuario, $ip); $ins->execute(); $ins->close(); }
  fail_and_back();
}

$dl = $conn->prepare("DELETE FROM login_attempts WHERE usuario = ? AND ip = INET6_ATON(?)");
if ($dl) { $dl->bind_param("ss", $usuario, $ip); $dl->execute(); $dl->close(); }

// Rehash si procede
$needsRehash = defined('PASSWORD_ARGON2ID')
  ? password_needs_rehash($u['clave'], PASSWORD_ARGON2ID)
  : password_needs_rehash($u['clave'], PASSWORD_DEFAULT);
if ($needsRehash) {
  $newHash = defined('PASSWORD_ARGON2ID') ? password_hash($clave, PASSWORD_ARGON2ID) : password_hash($clave, PASSWORD_DEFAULT);
  if ($up = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?")) {
    $up->bind_param("si", $newHash, $u['id']); $up->execute(); $up->close();
  }
}

session_regenerate_id(true);

$_SESSION['usuario_id']         = (int)$u['id'];
$_SESSION['usuario_nombre']     = (string)$u['nombre'];
$_SESSION['usuario_apellido']   = (string)$u['apellido'];
$_SESSION['usuario_fotoPerfil'] = (string)$u['fotoPerfil'];
$_SESSION['usuario_perfil']     = (int)$u['id_perfil'];
$_SESSION['perfil_nombre']      = (string)$u['perfil_nombre'];
$_SESSION['empresa_nombre']     = (string)$u['empresa_nombre'];
$_SESSION['empresa_id']         = (int)$u['id_empresa'];
$_SESSION['division_id']        = isset($u['id_division']) ? (int)$u['id_division'] : 0;


$fpr = session_fingerprint();
revoke_other_sessions($conn, (int)$u['id'], $fpr);
upsert_session($conn, (int)$u['id'], $fpr);

// Métricas de login
if ($update_stmt = $conn->prepare("UPDATE usuario SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")) {
  $update_stmt->bind_param("i", $u['id']);
  $update_stmt->execute();
  $update_stmt->close();
}


$ids_index_pruebas = [2, 70, 268]; 

$usuarioId = (int)$u['id'];

if (in_array($usuarioId, $ids_index_pruebas, true)) {
    header("Location: index_pruebas.php");
} else {
    header("Location: index.php");
}
exit();

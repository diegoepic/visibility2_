<?php
declare(strict_types=1);
require_once __DIR__ . '/../portal/bootstrap.php';
require_once __DIR__ . '/lib/remember.php';
// Regla de ingreso por RUT (se activa sola en la fecha de corte)
require_once __DIR__ . '/../lib_login_rut.php';
// Bloqueo temporal por intentos fallidos (tabla user_security)
require_once __DIR__ . '/lib/login_seguridad.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);

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
function remember_days(): int {
  return (int)(getenv('V2_REMEMBER_DAYS') ?: 30);
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

/* ===================== Buscar usuario =====================
   Hasta la fecha de corte se acepta RUT o nombre de usuario; desde ahí, sólo
   RUT (ver lib_login_rut.php — no hay que subir nada ese día, lo decide la
   fecha). El RUT tecleado se normaliza igual que al crearlo en el portal, así
   que da lo mismo si lo escriben 12.345.678-9 o 123456789. */
$soloRut = login_solo_rut_activo();
$rutIn   = login_normalizar_rut($usuario);

$sqlBase = "
  SELECT
      u.id, u.rut, u.nombre, u.apellido, u.email, u.usuario, u.fotoPerfil, u.clave,
      u.id_perfil, u.id_empresa, u.id_division, u.login_count,
      p.nombre AS perfil_nombre, e.nombre AS empresa_nombre
  FROM usuario AS u
  INNER JOIN perfil  AS p ON p.id = u.id_perfil
  INNER JOIN empresa AS e ON e.id = u.id_empresa
  WHERE %s AND u.activo = 1
  LIMIT 1
";
$sql = sprintf($sqlBase, $soloRut ? 'u.rut = ?' : '(u.rut = ? OR u.usuario = ?)');

$stmt = $conn->prepare($sql);
if (!$stmt) { error_log("Prepare user: ".$conn->error); fail_and_back(); }
if ($soloRut) { $stmt->bind_param("s", $rutIn); }
else          { $stmt->bind_param("ss", $rutIn, $usuario); }
if (!$stmt->execute()) { error_log("Execute user: ".$stmt->error); fail_and_back(); }
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
  $ins = $conn->prepare("INSERT INTO login_attempts (usuario, ip, intento_at) VALUES (?, INET6_ATON(?), NOW())");
  if ($ins) { $ins->bind_param("ss", $usuario, $ip); $ins->execute(); $ins->close(); }
  /* Ya rige el RUT y lo tecleado es un nombre de usuario que EXISTE: sin este
     aviso la persona lee "credenciales incorrectas", cree que le bloquearon la
     cuenta y llama a soporte. Se comprueba el usuario pero NO la clave, así que
     no revela nada que no se supiera al escribirlo. */
  if ($soloRut) {
    $q = $conn->prepare("SELECT 1 FROM usuario WHERE usuario = ? AND activo = 1 LIMIT 1");
    if ($q) {
      $q->bind_param("s", $usuario);
      $q->execute();
      $existe = $q->get_result()->num_rows === 1;
      $q->close();
      if ($existe) {
        $_SESSION['error_login'] = 'Desde el ' . date('d/m/Y', strtotime(login_fecha_corte()))
          . ' se ingresa con el RUT, sin puntos ni guion. Usa tu RUT en vez del nombre de usuario.';
        header("Location: login.php");
        exit();
      }
    }
  }
  fail_and_back();
}
$u = $res->fetch_assoc();
$stmt->close();

/* ===================== Bloqueo por intentos fallidos =====================
   El limite de arriba es por (usuario, IP): quien rote direcciones tiene intentos
   ilimitados contra una cuenta concreta. Este es por CUENTA, venga de donde venga.
   Se comprueba ANTES de mirar la contrasena: a una cuenta bloqueada no se le prueba
   la clave, ni bien ni mal. */
$uid = (int)$u['id'];
$faltanMin = v2_login_minutos_restantes($conn, $uid);
if ($faltanMin > 0) {
  // Se dice el motivo y el tiempo. Un "credenciales incorrectas" generico aqui hace que
  // la persona siga probando, se bloquee mas y termine llamando a soporte.
  $_SESSION['error_login'] = $faltanMin === 1
    ? 'Demasiados intentos fallidos. Vuelve a probar en 1 minuto.'
    : "Demasiados intentos fallidos. Vuelve a probar en $faltanMin minutos.";
  header("Location: login.php");
  exit();
}

if (!password_verify($clave, $u['clave'] ?? '')) {
  $ins = $conn->prepare("INSERT INTO login_attempts (usuario, ip, intento_at) VALUES (?, INET6_ATON(?), NOW())");
  if ($ins) { $ins->bind_param("ss", $usuario, $ip); $ins->execute(); $ins->close(); }

  $bloqueadoMin = v2_login_registrar_fallo($conn, $uid);
  if ($bloqueadoMin > 0) {
    $_SESSION['error_login'] = $bloqueadoMin === 1
      ? 'Demasiados intentos fallidos. La cuenta queda bloqueada 1 minuto.'
      : "Demasiados intentos fallidos. La cuenta queda bloqueada $bloqueadoMin minutos.";
    header("Location: login.php");
    exit();
  }
  fail_and_back();
}

$dl = $conn->prepare("DELETE FROM login_attempts WHERE usuario = ? AND ip = INET6_ATON(?)");
if ($dl) { $dl->bind_param("ss", $usuario, $ip); $dl->execute(); $dl->close(); }

/* Aviso del cambio a RUT: sólo a quien entró con el nombre de usuario, que es
   el único que va a quedar afuera. Quien ya usa su RUT no ve nada.
   No se lleva registro de "ya lo vio" a propósito: el aviso desaparece solo
   cuando la persona cambia de hábito, que es justamente lo que se busca. */
$rutGuardado = login_normalizar_rut((string)($u['rut'] ?? ''));
if (!$soloRut && $rutGuardado !== '' && $rutIn !== $rutGuardado) {
  $_SESSION['aviso_login_rut'] = [
    'rut'   => $rutGuardado,
    'dias'  => login_dias_para_corte(),
    'corte' => date('d/m/Y', strtotime(login_fecha_corte())),
  ];
}

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

// Login correcto: se borra el castigo acumulado y queda constancia de desde donde entro.
v2_login_registrar_exito($conn, (int)$u['id'], ip_addr(), user_agent());

session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

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

// Remember-me persistente (rehidrata sesión al reiniciar navegador)
$pair = generate_remember_pair();
remember_store_token($conn, (int)$u['id'], $pair['selector'], $pair['hash'], ip_addr(), user_agent());
remember_revoke_other_tokens($conn, (int)$u['id'], $pair['selector']);
set_remember_cookie($pair['selector'], $pair['token'], remember_days());

// Métricas de login
if ($update_stmt = $conn->prepare("UPDATE usuario SET login_count = login_count + 1, last_login = NOW() WHERE id = ?")) {
  $update_stmt->bind_param("i", $u['id']);
  $update_stmt->execute();
  $update_stmt->close();
}


// Redirección según división (RedBull = 14)
$divisionId = isset($u['id_division']) ? (int)$u['id_division'] : 0;

if ($divisionId === 14) {
    header("Location: index_pruebas.php");
} else {
    header("Location: index_pruebas.php");
}
exit();
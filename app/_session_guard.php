<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') { return; }

$script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$isPublic = false;
if ($script !== '') {
    $isPublic = (bool) preg_match(
        '#/visibility2/app/(login\.php'
        .'|procesar_login\.php'
        .'|recuperar_clave\.php'
        .'|logout\.php'
        .'|login_pruebas\.php'
        .'|procesar_login_pruebas\.php'
        .'|logout\.php'
        .'|logout\.php'
        .'|ping\.php'              // ⚠ debe poder devolver 401 JSON
        .'|csrf_refresh\.php'      // ⚠ idem
        .')$#i',
        $script
    );
}
if ($isPublic) { return; }

// Sesión segura
if (session_status() !== PHP_SESSION_ACTIVE) {
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
}

// Conexión
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
  if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
  }
}



$envFile = '/home/visibility/.env_visibility2';

if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Ignorar comentarios
        if ($line[0] === '#') {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // quitar comillas simples/dobles del valor
        $value = trim($value, "\"'");

        if ($key === '') {
            continue;
        }

        // Solo setear si no existe aún
        if (getenv($key) === false) {
            putenv("$key=$value");
        }

       
        $_ENV[$key] = $value;
    }
}


// Helpers compartidos
if (!function_exists('fpr_salt')) {
  function fpr_salt(): string {
    $k = getenv('APP_KEY');
    return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
  }
}
if (!function_exists('session_fingerprint')) {
  function session_fingerprint(): string {
    $sid  = session_id();
    $salt = fpr_salt();
    // 32 bytes binarios (BINARY(32) en BD). Si tu columna es CHAR(64), usa hash(..., false).
    return hash('sha256', $sid . '|' . $salt, true);
  }
}

if (!function_exists('assert_session_is_valid')) {
  function assert_session_is_valid(mysqli $conn): void {
    if (empty($_SESSION['usuario_id'])) {
      header('Location: /visibility2/app/login.php'); exit;
    }

    $uid = (int)$_SESSION['usuario_id'];
    $fpr = session_fingerprint();

    // ¿revocada?
    $sql = "SELECT revoked_at
              FROM user_sessions
             WHERE user_id = ? AND session_fpr = ?
             ORDER BY id DESC
             LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
      // Ojo: $fpr es binario, "s" funciona; si prefieres, podrías usar "b" + send_long_data.
      $st->bind_param("is", $uid, $fpr);

      if ($st->execute()) {
        $row = null;
        if (method_exists($st, 'get_result')) {
          $res = $st->get_result();
          $row = $res ? $res->fetch_assoc() : null;
        } else {
          $st->bind_result($revoked_at);
          $row = $st->fetch() ? ['revoked_at' => $revoked_at] : null;
        }
        $st->close();

        if ($row && !is_null($row['revoked_at'])) {
          // Cerrar sesión y volver a login con aviso
          session_unset();
          session_destroy();
          header('Location: /visibility2/app/login.php?session_expired=1'); exit;
        }
      } else {
        $st->close();
        // fail-soft
        return;
      }
    } else {
      // fail-soft
      return;
    }

    // Heartbeat (no crítico)
    $hb = $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = ? AND session_fpr = ?");
    if ($hb) {
      $hb->bind_param("is", $uid, $fpr);
      $hb->execute();
      $hb->close();
    }
  }
}

// Ejecutar guard
assert_session_is_valid($conn);

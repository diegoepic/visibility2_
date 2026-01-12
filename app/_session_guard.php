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
        .'|ping\.php'              // 72 debe poder devolver JSON 401
        .'|csrf_refresh\.php'      // 72 idempotencia
        .')$#i',
        $script
    );
}
if (getenv('V2_TEST_MODE') === '1' && preg_match('#/visibility2/app/api/test_session\.php$#i', $script)) { return; }

if ($isPublic) { return; }

// Sesin segura
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

require_once __DIR__ . '/lib/remember.php';


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

        // Solo setear si no existe an
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

if (!function_exists('upsert_session')) {
  function upsert_session(mysqli $conn, int $userId, string $fpr): void {
    $sql = "INSERT INTO user_sessions (user_id, session_fpr, created_at, last_seen_at, ip, user_agent)
            VALUES (?, ?, NOW(), NOW(), INET6_ATON(?), ?)";
    if ($st = $conn->prepare($sql)) {
      $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
      $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
      $st->bind_param("isss", $userId, $fpr, $ip, $ua);
      $st->execute(); $st->close();
    }
  }
}
if (!function_exists('revoke_other_sessions')) {
  function revoke_other_sessions(mysqli $conn, int $userId, string $currentFpr): void {
    $sql = "UPDATE user_sessions SET revoked_at = NOW()
            WHERE user_id = ? AND revoked_at IS NULL AND session_fpr <> ?";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param("is", $userId, $currentFpr);
      $st->execute(); $st->close();
    }
  }
}
if (!function_exists('hydrate_session_for_user')) {
  function hydrate_session_for_user(mysqli $conn, int $userId): bool {
    $sql = "
      SELECT 
          u.id, u.nombre, u.apellido, u.fotoPerfil,
          u.id_perfil, u.id_empresa, u.id_division,
          p.nombre AS perfil_nombre, e.nombre AS empresa_nombre
      FROM usuario AS u
      INNER JOIN perfil  AS p ON p.id = u.id_perfil
      INNER JOIN empresa AS e ON e.id = u.id_empresa
      WHERE u.id = ? AND u.activo = 1
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $u = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$u) return false;

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

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return true;
  }
}
if (!function_exists('try_rehydrate_session')) {
  function try_rehydrate_session(mysqli $conn): bool {
    $cookie = parse_remember_cookie();
    if (!$cookie) return false;

    $row = remember_find_token($conn, $cookie['selector']);
    if (!$row || !empty($row['revoked_at'])) {
      clear_remember_cookie();
      return false;
    }

    $expected = $row['token_hash'] ?? '';
    $actual = hash('sha256', $cookie['token']);
    if (!$expected || !hash_equals($expected, $actual)) {
      remember_revoke_token($conn, $cookie['selector']);
      clear_remember_cookie();
      return false;
    }

    if (!hydrate_session_for_user($conn, (int)$row['user_id'])) {
      return false;
    }

    $pair = generate_remember_pair();
    remember_update_token($conn, $cookie['selector'], $pair['hash']);
    set_remember_cookie($cookie['selector'], $pair['token'], (int)(getenv('V2_REMEMBER_DAYS') ?: 30));

    $fpr = session_fingerprint();
    revoke_other_sessions($conn, (int)$row['user_id'], $fpr);
    upsert_session($conn, (int)$row['user_id'], $fpr);
    return true;
  }
}

if (!function_exists('assert_session_is_valid')) {
   function assert_session_is_valid(mysqli $conn): void {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $offlineQueue = isset($_SERVER['HTTP_X_OFFLINE_QUEUE']);
    $wantsJson = (stripos($accept, 'application/json') !== false)
      || ($xhr === 'XMLHttpRequest')
      || $offlineQueue
      || isset($_POST['return_json']);
    if (empty($_SESSION['usuario_id'])) {
      if (try_rehydrate_session($conn)) {
        return;
      }
      if ($wantsJson) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'ok' => false,
          'error_code' => 'NO_SESSION',
          'message' => 'Sesin expirada',
          'retryable' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
      header('Location: /visibility2/app/login.php'); exit;
    }

    $uid = (int)$_SESSION['usuario_id'];
    $fpr = session_fingerprint();

    // revocada?
    $sql = "SELECT revoked_at
              FROM user_sessions
             WHERE user_id = ? AND session_fpr = ?
             ORDER BY id DESC
             LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
      //  $fpr es binario, "s" funciona; , podria en un futuro utlilizar  send_long_data.
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
          if ($offlineQueue) {
            return;
          }
          // Cerrar sesin y volver a login con aviso
          session_unset();
          session_destroy();
          if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
              'ok' => false,
              'error_code' => 'SESSION_REVOKED',
              'message' => 'Sesin revocada',
              'retryable' => false
            ], JSON_UNESCAPED_UNICODE);
            exit;
          }
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

    // Heartbeat (no crtico)
    $hb = $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = ? AND session_fpr = ?");
    if ($hb) {
      $hb->bind_param("is", $uid, $fpr);
      $hb->execute();
      $hb->close();
    }
  }
}
assert_session_is_valid($conn);
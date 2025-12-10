<?php
declare(strict_types=1);

// No hacer nada en CLI
if (PHP_SAPI === 'cli') { return; }

/* ------------------------------------------------------------------
   Allowlist de scripts públicos dentro de /portal (sin sesión)
   ------------------------------------------------------------------ */
$script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
if ($script) {
$isPublic = (bool) preg_match(
  '#/visibility2/portal/(index\.php'
  .'|RESTful/.*\.php'  // 
  .'|modulos/(procesar_login|restablecer_contrasena|enviar_solicitud)\.php'
  .'|modulos/logout\.php'
  .')$#i',
  $script
);
    if ($isPublic) { return; }
}

/* ------------------------------------------------------------------
   Sesión segura
   ------------------------------------------------------------------ */
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

/* ------------------------------------------------------------------
   Conexión BD (si no existe)
   ------------------------------------------------------------------ */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
}

/* ------------------------------------------------------------------
   Helpers
   ------------------------------------------------------------------ */
if (!function_exists('is_ajax_request')) {
  function is_ajax_request(): bool {
    return (
      (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
       strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
      ||
      (isset($_SERVER['HTTP_ACCEPT']) &&
       strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
  }
}

if (!function_exists('expel_to_login_top')) {
  function expel_to_login_top(string $qs = 'session_expired=1'): void {
    $loginUrl = '/index.php' . ($qs ? ('?' . $qs) : '');

    // Para AJAX devolvemos 401 y dejamos que el JS redirija el TOP.
    if (is_ajax_request()) {
      http_response_code(401);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(
        ['error' => 'session_revoked', 'redirect' => $loginUrl],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
      );
      exit;
    }

    // Para cargas normales: romper frame y redirigir el TOP.
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><script>
      try {
        if (window.top && window.top !== window.self) {
          window.top.location = ' . json_encode($loginUrl) . ';
        } else {
          window.location = ' . json_encode($loginUrl) . ';
        }
      } catch(e) {
        window.location = ' . json_encode($loginUrl) . ';
      }
    </script>';
    exit;
  }
}

if (!function_exists('fpr_salt')) {
  function fpr_salt(): string {
    $k = getenv('APP_KEY');
    return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
  }
}

if (!function_exists('session_fingerprint')) {
  function session_fingerprint(): string {
    // Debe ser EXACTAMENTE la misma función que en procesar_login.php/logout.php
    $sid  = session_id();
    $salt = fpr_salt();
    return hash('sha256', $sid . '|' . $salt, true); // 32 bytes binarios
  }
}

/* ------------------------------------------------------------------
   Guard principal
   ------------------------------------------------------------------ */
if (!function_exists('assert_session_is_valid')) {
  function assert_session_is_valid(mysqli $conn): void {
    // Sin sesión → fuera
    if (empty($_SESSION['usuario_id'])) {
      expel_to_login_top('session_expired=1');
    }

    $uid = (int)$_SESSION['usuario_id'];
    $fpr = session_fingerprint();

    // Consulta: si falla el prepare/execute, NO bloqueamos la app
    $sql = "SELECT revoked_at
              FROM user_sessions
             WHERE user_id = ? AND session_fpr = ?
          ORDER BY id DESC LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
      $st->bind_param("is", $uid, $fpr);
      if ($st->execute()) {
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        // Si no hay fila (sesión no registrada) o fue revocada → expulsar
        if (!$row || !is_null($row['revoked_at'])) {
          session_unset();
          session_destroy();
          expel_to_login_top('session_expired=1');
        }
      } else {
        $st->close();
        return; // NO-OP en caso de error
      }
    } else {
      return;   // NO-OP si no pudo preparar (p.ej., tabla falta)
    }

    // Heartbeat (ignorar cualquier error)
    $hb = $conn->prepare(
      "UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = ? AND session_fpr = ?"
    );
    if ($hb) {
      $hb->bind_param("is", $uid, $fpr);
      $hb->execute();
      $hb->close();
    }
  }
}

// Ejecutar guard
assert_session_is_valid($conn);

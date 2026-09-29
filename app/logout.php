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

// ----- Limpiar sesi��n y cookie -----
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

// Entregar página HTML mínima que limpia el almacenamiento local y luego redirige.
// No podemos usar header('Location:') aquí porque necesitamos correr JS del lado cliente:
// ni las caches del service worker ni IndexedDB se pueden tocar desde PHP.
//
// Por qué borra IndexedDB (v7.4.0): estos teléfonos los comparten varios ejecutores
// (turnos, reemplazos). Hasta ahora el logout sólo hablaba con el service worker, así que
// la cola de envíos, las fotos pendientes, los borradores de gestión y la agenda del que
// salía quedaban en el equipo, visibles y drenables por el que entraba. El aviso previo
// (offline-queue.js -> instalarAvisoDeSalida) es lo que hace que este borrado sea seguro:
// nadie llega hasta aquí con trabajo sin enviar sin haberlo visto en pantalla.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo <<<'HTML'
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Cerrando sesión…</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Red de seguridad por si el JS se cuelga. Antes eran 2s, que cortaba la limpieza
     a la mitad; ahora el JS redirige solo en cuanto termina. -->
<meta http-equiv="refresh" content="12;url=login.php">
<style>
  body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
       margin:0;display:flex;align-items:center;justify-content:center;height:100vh;
       color:#333;background:#f7f7f7;text-align:center;padding:0 24px}
</style>
</head>
<body>
<script>
// Todas las bases IndexedDB que abre la app. La lista es el plan B: en Chrome y Edge
// (los navegadores del terreno) se usa indexedDB.databases(), que devuelve TODAS las de
// este origen y por lo tanto no se queda obsoleta cuando alguien agregue una base nueva.
// Firefox y Safari viejos no implementan databases(): ahí manda la lista.
//   v2_offline        -> cola de envíos, borradores y blobs de fotos   (assets/js/db.js)
//   visibility2-v2    -> caché de rutas/locales                        (assets/js/v2_cache.js)
//   v2_journal        -> diario de actividad                           (assets/js/journal_db.js)
//   v2_journal_feed   -> feed del diario                               (assets/js/journal_feed.js)
//   v2_mappings       -> client_guid -> visita_id                      (assets/js/offline-queue.js)
//   visibility2-queue -> cola de respaldo si db.js no cargó            (assets/js/offline-queue.js)
//   v2_route_cache    -> respuestas de Routes API                      (assets/js/route_engine.js)
const BASES_CONOCIDAS = [
  'v2_offline', 'visibility2-v2', 'v2_journal', 'v2_journal_feed',
  'v2_mappings', 'visibility2-queue', 'v2_route_cache'
];

function borrarBase(nombre){
  return new Promise(resolve => {
    try {
      const req = indexedDB.deleteDatabase(nombre);
      req.onsuccess = req.onerror = req.onblocked = () => resolve(nombre);
      // Otra pestaña abierta bloquea el borrado indefinidamente: no se espera para siempre.
      setTimeout(() => resolve(nombre), 2000);
    } catch (_) { resolve(nombre); }
  });
}

(async () => {
  // 1) Caches del service worker
  try {
    if ('serviceWorker' in navigator) {
      const reg = await navigator.serviceWorker.ready;
      if (reg && reg.active) {
        reg.active.postMessage({ type: 'CLEAR_USER_CACHES' });
        await new Promise(r => setTimeout(r, 300));
      }
    }
  } catch (_) {}

  // 2) IndexedDB
  try {
    let nombres = BASES_CONOCIDAS;
    if (indexedDB.databases) {
      try {
        const vivas = await indexedDB.databases();
        const detectadas = (vivas || []).map(d => d && d.name).filter(Boolean);
        if (detectadas.length) nombres = Array.from(new Set(detectadas.concat(BASES_CONOCIDAS)));
      } catch (_) {}
    }
    await Promise.all(nombres.map(borrarBase));
  } catch (_) {}

  // 3) localStorage / sessionStorage propios (todo lo de la app va con prefijo v2_)
  try {
    Object.keys(localStorage).filter(k => k.indexOf('v2_') === 0)
      .forEach(k => localStorage.removeItem(k));
  } catch (_) {}
  try {
    Object.keys(sessionStorage).filter(k => k.indexOf('v2_') === 0)
      .forEach(k => sessionStorage.removeItem(k));
  } catch (_) {}

  window.location.replace('login.php');
})();
</script>
<p>Cerrando sesión y limpiando los datos de este teléfono…</p>
</body>
</html>
HTML;
exit();
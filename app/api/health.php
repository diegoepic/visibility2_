<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');

// Sin autenticación — endpoint público para monitoreo externo

$checks  = [];
$overall = 'ok';

/* ── 1. Base de datos ── */
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('connection unavailable');
    }
    $ping = $conn->ping();
    $checks['database'] = ['status' => $ping ? 'ok' : 'down', 'latency_ms' => null];

    // Latencia de una query trivial
    $t0 = microtime(true);
    $conn->query('SELECT 1');
    $checks['database']['latency_ms'] = round((microtime(true) - $t0) * 1000, 2);

    if (!$ping) { $overall = 'down'; }
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'down', 'error' => $e->getMessage()];
    $overall = 'down';
}

/* ── 2. Directorio uploads ── */
$uploadsDir = dirname(__DIR__) . '/uploads/';
$uploadsWritable = is_dir($uploadsDir) && is_writable($uploadsDir);
$freeMB  = $uploadsWritable ? round(disk_free_space($uploadsDir) / 1048576) : null;
$minFreeMB = 500;

$diskStatus = 'ok';
if (!$uploadsWritable) {
    $diskStatus = 'down';
    $overall    = 'down';
} elseif ($freeMB !== null && $freeMB < $minFreeMB) {
    $diskStatus = 'degraded';
    if ($overall === 'ok') { $overall = 'degraded'; }
}

$checks['disk'] = [
    'status'          => $diskStatus,
    'uploads_writable' => $uploadsWritable,
    'free_mb'         => $freeMB,
    'min_free_mb'     => $minFreeMB,
];

/* ── 3. Versión de schema ── */
$checks['schema'] = ['status' => 'unknown', 'version' => null];
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $r = $conn->query("SELECT version FROM schema_version ORDER BY id DESC LIMIT 1");
        if ($r && $r->num_rows) {
            $checks['schema'] = ['status' => 'ok', 'version' => $r->fetch_row()[0]];
        }
        // Tabla no existe → simplemente no hay info de schema (no es error)
    } catch (Throwable $_) { /* tabla aún no creada */ }
}

/* ── 4. Versión de aplicación desde .env ── */
$appVersion = getenv('APP_VERSION') ?: 'unknown';

/* ── Respuesta ── */
$httpCode = match($overall) {
    'ok'       => 200,
    'degraded' => 200,   // degraded sigue siendo "disponible" — alertar por checks
    default    => 503,
};
http_response_code($httpCode);

echo json_encode([
    'status'      => $overall,
    'checks'      => $checks,
    'app_version' => $appVersion,
    'timestamp'   => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }

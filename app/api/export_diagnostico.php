<?php
declare(strict_types=1);
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// -----------------------------------------------------------------------------
// Autenticación
// -----------------------------------------------------------------------------
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'UNAUTHENTICATED',
        'message'    => 'Sesión no válida. Vuelve a iniciar sesión.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// Método permitido
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'METHOD_NOT_ALLOWED',
        'message'    => 'Método no permitido. Usa POST.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';

// -----------------------------------------------------------------------------
// Lectura de payload (JSON o form field payload_json)
// -----------------------------------------------------------------------------
$rawBody   = file_get_contents('php://input');
$body      = null;
$bodyError = null;
if ($rawBody !== false && $rawBody !== '') {
    $json = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $body = $json;
    } else {
        $bodyError = 'INVALID_JSON';
    }
}

if (!$body && isset($_POST['payload_json'])) {
    $json = json_decode((string)$_POST['payload_json'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $body = $json;
        $bodyError = null;
    } else {
        $bodyError = 'INVALID_JSON';
    }
}

if ($bodyError && !$body) {
    http_response_code(400);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'INVALID_JSON',
        'message'    => 'Payload inválido (JSON malformado).'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$body = is_array($body) ? $body : [];

// -----------------------------------------------------------------------------
// CSRF
// -----------------------------------------------------------------------------
$sessionToken = $_SESSION['csrf_token'] ?? null;
if (!$sessionToken) {
    // Aseguramos que exista un token de sesión
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $sessionToken = $_SESSION['csrf_token'];
}

$csrfFromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$csrfFromBody   = $body['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
$csrfProvided   = (string)($csrfFromHeader ?: $csrfFromBody);

if (!hash_equals((string)$sessionToken, $csrfProvided)) {
    http_response_code(419);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'CSRF_INVALID',
        'message'    => 'Token CSRF inválido o faltante.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// Preparar directorios
// -----------------------------------------------------------------------------
$userId  = (int)($_SESSION['usuario_id'] ?? 0);
$now     = new DateTime('now', new DateTimeZone('America/Santiago'));
$ymd     = $now->format('Y-m-d');

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot === '') {
    $docRoot = realpath(__DIR__ . '/..');
}
$baseDir = $docRoot . '/visibility2/app/exports';
$dir     = $baseDir . '/' . $userId . '/' . $ymd;

if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'MKDIR_FAILED',
        'message'    => 'No se pudo crear el directorio de exportación.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Proteger carpeta base
if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
    http_response_code(500);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'MKDIR_FAILED',
        'message'    => 'No se pudo crear la carpeta base de exportaciones.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$htaccessPath = $baseDir . '/.htaccess';
if (!is_file($htaccessPath)) {
    $rules = <<<HTA
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Deny from all
</IfModule>
HTA;
    file_put_contents($htaccessPath, $rules, LOCK_EX);
}

$indexPath = $baseDir . '/index.html';
if (!is_file($indexPath)) {
    file_put_contents($indexPath, "", LOCK_EX);
}

// -----------------------------------------------------------------------------
// Guardar archivo
// -----------------------------------------------------------------------------
$filename = sprintf(
    'diagnostico_%s_%s.json',
    $now->format('Ymd_His'),
    substr(bin2hex(random_bytes(16)), 0, 10)
);

$prettyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($prettyJson === false) {
    http_response_code(400);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'INVALID_JSON',
        'message'    => 'Payload inválido (no JSON).'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tmpPath  = $dir . '/.tmp_' . $filename;
$final    = $dir . '/' . $filename;

if (file_put_contents($tmpPath, $prettyJson, LOCK_EX) === false) {
    @unlink($tmpPath);
    http_response_code(500);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'WRITE_FAILED',
        'message'    => 'No se pudo escribir el archivo temporal.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!rename($tmpPath, $final)) {
    @unlink($tmpPath);
    http_response_code(500);
    echo json_encode([
        'ok'         => false,
        'error_code' => 'RENAME_FAILED',
        'message'    => 'No se pudo guardar el archivo de diagnóstico.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$bytes = filesize($final) ?: 0;
$sha1  = sha1_file($final) ?: '';

$response = [
    'ok'         => true,
    'saved_path' => sprintf('app/exports/%d/%s/%s', $userId, $ymd, $filename),
    'bytes'      => $bytes,
    'sha1'       => $sha1,
    'created_at' => $now->format(DateTime::ATOM)
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
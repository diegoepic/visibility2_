<?php
declare(strict_types=1);
/**
 * route_compute.php — Proxy server-side de Google Routes API (directions/v2:computeRoutes).
 *
 * Por qué: mantener la API key de Routes fuera del frontend. El cliente (route_engine.js) hace POST
 * a este endpoint con { payload, fieldMask, mode }; aquí se valida la sesión y el payload, se inyecta
 * la key desde variable de entorno y se reenvía a Google. Se devuelve el JSON CRUDO de Google para
 * que normalizeRoutesApiResponse() del cliente funcione sin cambios. Ante cualquier error (auth,
 * validación, red), se responde con status != 200 y el cliente cae a su fallback DirectionsService.
 *
 * Seguridad: read-only + gateado por sesión + mismo origen → no requiere CSRF (se hace un chequeo
 * suave de X-Requested-With). La key del frontend (Maps JS) sigue siendo pública por diseño; este
 * proxy solo protege la Routes API key.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$t0 = microtime(true);

function rc_fail(int $http, string $msg, string $code = 'ERROR'): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'status' => $code, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!isset($_SESSION['usuario_id'])) {
    rc_fail(401, 'Sesión expirada', 'no_session');
}
$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);

// Solo POST (read-only pero con cuerpo JSON).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    rc_fail(405, 'Método no permitido', 'method_not_allowed');
}
// Chequeo suave de mismo origen (anti-CSRF ligero; el cliente envía esta cabecera).
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === '') {
    rc_fail(400, 'Solicitud inválida', 'bad_request');
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    rc_fail(400, 'Cuerpo JSON inválido', 'bad_json');
}

$payload   = $body['payload']   ?? null;
$fieldMask = $body['fieldMask'] ?? '';
$mode      = (string)($body['mode'] ?? 'preview');

if (!is_array($payload)) {
    rc_fail(400, 'Falta payload', 'bad_payload');
}
if (!in_array($mode, ['preview', 'traffic', 'nav'], true)) {
    $mode = 'preview';
}

// ---- Validación de coordenadas ----
function rc_latlng($node): ?array {
    if (!is_array($node)) return null;
    $ll = $node['location']['latLng'] ?? null;
    if (!is_array($ll)) return null;
    $lat = $ll['latitude'] ?? null;
    $lng = $ll['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) return null;
    $lat = (float)$lat; $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) return null; // descartar 0,0
    return [$lat, $lng];
}

if (rc_latlng($payload['origin'] ?? null) === null) {
    rc_fail(422, 'Origen inválido', 'bad_origin');
}
if (rc_latlng($payload['destination'] ?? null) === null) {
    rc_fail(422, 'Destino inválido', 'bad_destination');
}

$intermediates = $payload['intermediates'] ?? [];
if (!is_array($intermediates)) {
    rc_fail(422, 'Intermedios inválidos', 'bad_intermediates');
}
// Límite de seguridad: un chunk del planner trae ≤23 intermedios; capamos a 25.
if (count($intermediates) > 25) {
    rc_fail(422, 'Demasiados puntos intermedios (máx 25 por solicitud)', 'too_many_points');
}
foreach ($intermediates as $it) {
    if (rc_latlng($it) === null) {
        rc_fail(422, 'Coordenada intermedia inválida', 'bad_intermediate');
    }
}

// ---- Field mask: aceptar solo una lista corta de campos routes.* ----
$fieldMask = is_string($fieldMask) ? trim($fieldMask) : '';
$DEFAULT_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.steps.navigationInstruction,routes.legs.steps.distanceMeters,routes.legs.steps.staticDuration,routes.legs.steps.startLocation,routes.legs.steps.endLocation,routes.legs.steps.polyline.encodedPolyline,routes.optimizedIntermediateWaypointIndex,routes.travelAdvisory.speedReadingIntervals';
if ($fieldMask === '' || !preg_match('/^[A-Za-z0-9_.,]+$/', $fieldMask) || strpos($fieldMask, 'routes.') !== 0 || strlen($fieldMask) > 600) {
    $fieldMask = $DEFAULT_MASK;
}

// ---- API key server-only (no se expone nunca al cliente) ----
// Cargar app/.env si las variables no están ya en el entorno (mismo mecanismo que con_.php, pero sin
// abrir conexión a la BBDD: este endpoint no la necesita y así evita su modo de fallo HTML).
(static function (): void {
    $envFile = __DIR__ . '/../.env';
    if (!is_readable($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key); $val = trim($val, " \t\"'");
        if ($key !== '' && getenv($key) === false) { putenv("$key=$val"); $_ENV[$key] = $val; }
    }
})();
$apiKey = getenv('GOOGLE_ROUTES_API_KEY') ?: getenv('GOOGLE_MAPS_API_KEY') ?: '';
$apiKey = is_string($apiKey) ? trim($apiKey) : '';
if ($apiKey === '') {
    error_log("route_compute uid=$usuario_id status=no_key");
    rc_fail(500, 'Servicio de rutas no configurado', 'no_key');
}

if (!function_exists('curl_init')) {
    rc_fail(500, 'cURL no disponible', 'no_curl');
}

// ---- Reenviar a Google Routes API ----
$endpoint = 'https://routes.googleapis.com/directions/v2:computeRoutes';
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: ' . $fieldMask,
    ],
]);
$response  = curl_exec($ch);
$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr   = curl_error($ch);
// (sin curl_close: el handle se libera al terminar el script; curl_close está deprecado en PHP 8.5)

$ms = (int)round((microtime(true) - $t0) * 1000);
$nPts = count($intermediates) + 2;

if ($response === false || $curlErr !== '') {
    error_log("route_compute uid=$usuario_id status=curl_error http=$httpCode pts=$nPts ms=$ms err=" . substr($curlErr, 0, 200));
    rc_fail(502, 'No se pudo contactar el servicio de rutas', 'upstream_unreachable');
}

// Log de cuota/errores por usuario (sin tabla DB; queda como follow-up).
error_log("route_compute uid=$usuario_id status=ok http=$httpCode pts=$nPts mode=$mode ms=$ms");

// Reenviar el JSON CRUDO de Google con su status HTTP (el cliente lo normaliza).
http_response_code($httpCode > 0 ? $httpCode : 502);
echo $response;

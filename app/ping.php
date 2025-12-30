<?php
declare(strict_types=1);
/**
 * /visibility2/app/ping.php
 * Verifica sesión viva + conectividad a DB. Devuelve JSON y evita cache.
 * Si no hay sesión => 401 JSON con AUTH_EXPIRED para que el front pause la cola.
 */

require_once __DIR__ . '/lib/api_helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    // Si no hay sesión, 401 para que Queue.drain() se detenga
    if (!isset($_SESSION['usuario_id'])) {
        session_write_close();
        api_auth_expired();
    }

    $uid = (int)$_SESSION['usuario_id'];
    $empresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;

    // Garantiza un CSRF válido para ayudar al heartbeat
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        strlen($_SESSION['csrf_token']) < 32
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /** @var mysqli|null $conn */
    $db_ok = false;
    if (isset($conn) && $conn instanceof mysqli) {
        // Valida y mantiene viva la conexión
        $db_ok = (bool)$conn->ping();
    }

    $resp = [
        'status'      => 'ok',
        'server_time' => gmdate('c'),
        'user_id'     => $uid,
        'empresa_id'  => $empresaId,
        'app_version' => getenv('APP_VERSION') ?: 'v2',
        'db_ok'       => $db_ok,
        'csrf_token'  => $_SESSION['csrf_token'],
        'session_valid' => true,
        'user_session_state' => 'active',
        'trace_id' => api_trace_id(),
        'ts' => api_ts(),
    ];

    session_write_close();

    http_response_code(200);
    echo json_encode(['ok' => true, 'code' => 'OK'] + $resp, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    api_error_response('HTTP_500', 'Ping failed', 500, ['exception' => $e->getMessage()]);
}
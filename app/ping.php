<?php
declare(strict_types=1);
/**
 * /visibility2/app/ping.php
 * Verifica sesión viva + conectividad a DB. Devuelve JSON y evita cache.
 * Si no hay sesión => 401 {status:"no_session"} para que el front pause la cola.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    // Si no hay sesión, 401 para que Queue.drain() se detenga
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'no_session'], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
        'user_id'     => (int)$_SESSION['usuario_id'],
        'empresa_id'  => isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null,
        'app_version' => getenv('APP_VERSION') ?: 'v2',
        'db_ok'       => $db_ok,
        'csrf_token'  => $_SESSION['csrf_token'],
    ];

    http_response_code(200);
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Ping failed',
    ], JSON_UNESCAPED_UNICODE);
    error_log('ping.php error: ' . $e->getMessage());
    exit;
}

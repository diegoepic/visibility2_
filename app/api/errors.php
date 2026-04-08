<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Requiere sesión activa para evitar que actores externos inunden el log
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$level     = substr((string)($data['level']     ?? 'error'),   0, 20);
$message   = substr((string)($data['message']   ?? ''),        0, 1000);
$stack     = substr((string)($data['stack']      ?? ''),       0, 4000);
$url       = substr((string)($data['url']        ?? ''),       0, 500);
$userId    = (int)($_SESSION['usuario_id'] ?? 0);
$appVer    = substr((string)($data['app_version'] ?? ''),      0, 50);
$timestamp = substr((string)($data['timestamp']  ?? ''),       0, 30);

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message required']);
    exit;
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('DB connection not available');
    }
    $conn->set_charset('utf8mb4');

    // Tabla error_log — se crea si no existe (bootstrap automático)
    $conn->query("
        CREATE TABLE IF NOT EXISTS error_log (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL DEFAULT 0,
            level       VARCHAR(20)  NOT NULL DEFAULT 'error',
            message     TEXT         NOT NULL,
            stack       TEXT,
            url         VARCHAR(500),
            app_version VARCHAR(50),
            client_ts   VARCHAR(30),
            server_ts   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user  (user_id),
            INDEX idx_ts    (server_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $conn->prepare(
        'INSERT INTO error_log (user_id, level, message, stack, url, app_version, client_ts)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $userId, $level, $message, $stack, $url, $appVer, $timestamp);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // No revelar detalles internos al cliente
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal']);
    // Registrar en log del servidor
    error_log('[errors.php] ' . $e->getMessage());
}

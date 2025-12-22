<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (getenv('V2_TEST_MODE') !== '1') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error_code' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['usuario_id'] = 1;
$_SESSION['empresa_id'] = 1;
$_SESSION['division_id'] = 1;
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo json_encode([
    'ok' => true,
    'status' => 'ok',
    'csrf_token' => $_SESSION['csrf_token']
], JSON_UNESCAPED_UNICODE);
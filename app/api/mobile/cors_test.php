<?php
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^http:\/\/localhost:\d+$/', $origin) ||
    preg_match('/^http:\/\/127\.0\.0\.1:\d+$/', $origin) ||
    $origin === 'https://visibility.cl'
) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'ok' => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php' => [
        'version' => PHP_VERSION,
        'ok' => true,
    ],
];

$dbOk = false;
$dbError = null;
try {
    require_once __DIR__ . '/../con_.php';
    $dbOk = isset($conn) && $conn instanceof mysqli && @$conn->ping();
    if (!$dbOk) {
        $dbError = 'No fue posible validar la conexión.';
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$checks['db'] = [
    'ok' => $dbOk,
    'error' => $dbError,
];

$extensions = [
    'curl',
    'fileinfo',
    'gd',
    'imagick',
    'intl',
    'mbstring',
    'mysqli',
    'exif',
    'zip',
];

$missing = [];
foreach ($extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
$checks['extensions'] = [
    'ok' => empty($missing),
    'missing' => $missing,
];

http_response_code(($dbOk && empty($missing)) ? 200 : 500);
echo json_encode([
    'ok' => $dbOk && empty($missing),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

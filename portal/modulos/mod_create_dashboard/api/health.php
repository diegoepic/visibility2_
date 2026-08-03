<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'service' => 'perfect-store-php',
    'message' => 'La interfaz PHP está disponible.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

// Misma lógica de sesión que el portal
$secure =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

// Usa el con_ del portal
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok'  => false,
        'msg' => 'No autenticado'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id_division = isset($_GET['id_division']) ? (int)$_GET['id_division'] : 0;

if ($id_division <= 0) {
    echo json_encode([
        'ok' => true,
        'subdivisiones' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "SELECT id, nombre
        FROM subdivision
        WHERE id_division = ?
        ORDER BY nombre";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => 'Error al preparar consulta'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("i", $id_division);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
    $items[] = [
        'id'     => (int)$r['id'],
        'nombre' => $r['nombre']
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'subdivisiones' => $items
], JSON_UNESCAPED_UNICODE);
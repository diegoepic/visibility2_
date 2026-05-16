<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

$id_empresa = (int)($_SESSION['empresa_id'] ?? 0);
$id_region  = isset($_GET['id_region']) ? (int)$_GET['id_region'] : 0;

try {
    $comunas = [];

    if ($id_empresa <= 0) {
        throw new RuntimeException('Empresa no v¨¢lida.');
    }

    $sql = "
        SELECT DISTINCT
            c.id,
            c.comuna AS nombre
        FROM local l
        INNER JOIN comuna c ON c.id = l.id_comuna
        WHERE l.id_empresa = ?
    ";

    $params = [$id_empresa];
    $types  = 'i';

    if ($id_region > 0) {
        $sql .= " AND c.id_region = ? ";
        $params[] = $id_region;
        $types .= 'i';
    }

    $sql .= " ORDER BY c.comuna ASC ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Error preparando consulta: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $comunas[] = [
            'id'     => (int)$row['id'],
            'nombre' => $row['nombre']
        ];
    }

    $stmt->close();

    echo json_encode([
        'ok' => true,
        'comunas' => $comunas
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[ajax_cargar_comunas] ' . $e->getMessage());

    echo json_encode([
        'ok' => false,
        'error' => 'No fue posible cargar comunas.',
        'comunas' => []
    ], JSON_UNESCAPED_UNICODE);
}
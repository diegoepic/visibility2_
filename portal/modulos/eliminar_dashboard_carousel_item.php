<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

$sql = "SELECT image_url FROM dashboard_carousel_items WHERE id = $id LIMIT 1";
$res = $conn->query($sql);

if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'No se encontró el item.']);
    exit;
}

$row = $res->fetch_assoc();
$image_url = $row['image_url'] ?? '';

$del = $conn->query("DELETE FROM dashboard_carousel_items WHERE id = $id LIMIT 1");

if ($del) {
    if (!empty($image_url)) {
        $path = $_SERVER['DOCUMENT_ROOT'] . $image_url;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'No fue posible eliminar el item.']);
exit;
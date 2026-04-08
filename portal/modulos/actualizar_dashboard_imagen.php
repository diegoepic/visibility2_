<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/helpers_image.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido.');
    }

    if (!function_exists('imagewebp')) {
        throw new Exception('El servidor no tiene soporte para WebP en GD.');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        throw new Exception('ID inválido.');
    }

    if (!isset($_FILES['image'])) {
        throw new Exception('No se recibió el archivo image.');
    }

    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir archivo. Código: ' . $_FILES['image']['error']);
    }

    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        throw new Exception('La imagen supera el máximo permitido de 5MB.');
    }

    $stmt = $conn->prepare("SELECT image_url FROM dashboard_items WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error al preparar SELECT: ' . $conn->error);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$res || $res->num_rows === 0) {
        throw new Exception('No se encontró el item.');
    }

    $row = $res->fetch_assoc();
    $oldImage = $row['image_url'] ?? '';

    $destinationDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/dashboard/';
    $resultadoWebp = convertirImagenAWebp($_FILES['image'], $destinationDir, 82);
    $newImageUrl = '/visibility2/portal/uploads/dashboard/' . $resultadoWebp['filename'];

    $stmtUpdate = $conn->prepare("UPDATE dashboard_items SET image_url = ? WHERE id = ?");
    if (!$stmtUpdate) {
        throw new Exception('No se pudo preparar la actualización: ' . $conn->error);
    }

    $stmtUpdate->bind_param("si", $newImageUrl, $id);

    if (!$stmtUpdate->execute()) {
        throw new Exception('Error al actualizar en base de datos: ' . $stmtUpdate->error);
    }

    if (!empty($oldImage) && strpos($oldImage, '/visibility2/portal/uploads/dashboard/') === 0) {
        $oldPath = $_SERVER['DOCUMENT_ROOT'] . $oldImage;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    echo json_encode([
        'success' => true,
        'image_url' => $newImageUrl
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
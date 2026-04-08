<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('M¨¦todo no permitido.');
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        throw new Exception('ID inv¨¢lido.');
    }

    $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 1;
    if ($orden <= 0) {
        $orden = 1;
    }

    $target_url = trim((string)($_POST['target_url'] ?? ''));
    $main_label = trim((string)($_POST['main_label'] ?? ''));
    $sub_label  = trim((string)($_POST['sub_label'] ?? ''));
    $icon_class = trim((string)($_POST['icon_class'] ?? ''));
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $conn->prepare("
        UPDATE dashboard_items
        SET
            target_url = ?,
            main_label = ?,
            sub_label = ?,
            icon_class = ?,
            is_active = ?,
            orden = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('No se pudo preparar la consulta: ' . $conn->error);
    }

    $stmt->bind_param(
        "ssssiii",
        $target_url,
        $main_label,
        $sub_label,
        $icon_class,
        $is_active,
        $orden,
        $id
    );

    if (!$stmt->execute()) {
        throw new Exception('Error al actualizar el item: ' . $stmt->error);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Dashboard item actualizado correctamente.'
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
<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método inválido']);
    exit;
}

$empresa_id  = intval($_SESSION['empresa_id']);
$id          = intval($_POST['id'] ?? 0);
$numero_guia = trim($_POST['numero_guia'] ?? '');

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
    exit;
}

$stmt = $conn->prepare("UPDATE material_recepcion SET numero_guia = ? WHERE id = ? AND id_empresa = ?");
$stmt->bind_param('sii', $numero_guia, $id, $empresa_id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Error al actualizar: ' . $conn->error]);
}

$stmt->close();
$conn->close();

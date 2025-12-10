<?php
// obtener_subcanales_por_canal.php

header('Content-Type: application/json');
require_once '../db.php';

$response = [
    'success' => false,
    'data' => [],
    'message' => ''
];

if (!isset($_GET['canal_id']) || !is_numeric($_GET['canal_id'])) {
    $response['message'] = 'ID de canal inválido o faltante.';
    echo json_encode($response);
    exit();
}

$canal_id = intval($_GET['canal_id']);

try {
    // Función que obtenga subcanales filtrados
    $stmt = $conn->prepare("SELECT id, nombre_subcanal FROM subcanal WHERE id_canal = ? ORDER BY nombre_subcanal ASC");
    $stmt->bind_param('i', $canal_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $subcanales = [];
    while ($row = $result->fetch_assoc()) {
        $subcanales[] = $row;
    }
    $stmt->close();

    $response['success'] = true;
    $response['data'] = $subcanales;
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit();
<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../db.php';

if (!isset($_GET['zona_id'])) {
    echo json_encode(['success' => false, 'message' => 'No zone ID provided']);
    exit();
}
$zona_id = intval($_GET['zona_id']);
$distritos = obtenerDistritosPorZona($zona_id); // Ej. SELECT * FROM distrito WHERE id_zona = ?

if ($distritos !== false) {
    echo json_encode(['success' => true, 'data' => $distritos]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error al obtener distritos']);
}
exit;

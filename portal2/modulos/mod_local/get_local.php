<?php
// mod_local/get_local.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de local inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$local = obtenerDetalleLocal($id);

if ($local !== null) {
    echo json_encode(['success' => true, 'data' => $local], JSON_UNESCAPED_UNICODE);
} else {
    // Nota: si esto persiste, hay que revisar que el id exista en local
    echo json_encode(['success' => false, 'message' => 'No se encontró el local.'], JSON_UNESCAPED_UNICODE);
}
exit;
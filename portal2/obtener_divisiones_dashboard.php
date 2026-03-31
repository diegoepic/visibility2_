<?php
header('Content-Type: application/json; charset=utf-8');

// Incluir la conexión a la BD
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// Verificar si se recibió el id_empresa
if (!isset($_GET['id_empresa'])) {
    echo json_encode([]);
    exit;
}

$id_empresa = (int)$_GET['id_empresa'];

// Ajustar la consulta a tu tabla "division_empresa"
$sql = "SELECT id, nombre
        FROM division_empresa
        WHERE id_empresa = ?
          AND estado = 1
        ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([]);
    exit;
}

$stmt->bind_param("i", $id_empresa);
$stmt->execute();
$res = $stmt->get_result();

$divisiones = [];
while ($row = $res->fetch_assoc()) {
    $divisiones[] = [
        'id'     => $row['id'],
        'nombre' => $row['nombre']
    ];
}
$stmt->close();

// Devolver en formato JSON
echo json_encode($divisiones);
exit;

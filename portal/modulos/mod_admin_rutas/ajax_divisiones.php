<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json');

if (!isset($_GET['id_ejecutor'])) {
    echo json_encode([]);
    exit;
}

$id_ejecutor = intval($_GET['id_ejecutor']);

$sql = "
    SELECT DISTINCT d.id, d.nombre
    FROM formulario f
    JOIN formularioQuestion fq ON fq.id_formulario = f.id
    JOIN division_empresa d ON d.id = f.id_division
    WHERE fq.id_usuario = ?
      AND f.estado = 1
    ORDER BY d.nombre
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_ejecutor);
$stmt->execute();
$res = $stmt->get_result();

$divisiones = [];
while ($row = $res->fetch_assoc()) {
    $divisiones[] = $row;
}

echo json_encode($divisiones);

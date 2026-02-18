<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json');

if (!isset($_GET['division'])) {
    echo json_encode([]);
    exit;
}

$id_division = intval($_GET['division']);

$sql = "SELECT id, nombre 
        FROM subdivision 
        WHERE id_division = ?
        ORDER BY nombre";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_division);
$stmt->execute();
$result = $stmt->get_result();

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode($data);

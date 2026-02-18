<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

header('Content-Type: application/json');

$division = intval($_GET['division'] ?? 0);

if ($division <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT id,
           UPPER(CONCAT(nombre, ' ', apellido)) AS nombre_completo
    FROM usuario
    WHERE activo = 1
      AND id_perfil = 3
      AND id_division = ?
    ORDER BY nombre ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $division);
$stmt->execute();
$res = $stmt->get_result();

$usuarios = [];
while ($row = $res->fetch_assoc()) {
    $usuarios[] = $row;
}

echo json_encode($usuarios);

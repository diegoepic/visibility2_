<?php
session_start();
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';

$cache_key = "compCampanas";
if (function_exists('apcu_fetch') && ($data = apcu_fetch($cache_key)) !== false) {
    echo json_encode($data);
    exit;
}

$sql_comp = "
    SELECT id AS id_campana, nombre AS nombre_campana, estado
    FROM formulario
    WHERE tipo = 2
    ORDER BY nombre ASC
";
$stmt = $conn->prepare($sql_comp);
if ($stmt === false) {
    echo json_encode(['error' => 'Error en la consulta de actividades complementarias: ' . htmlspecialchars($conn->error)]);
    exit;
}
$stmt->execute();
$result = $stmt->get_result();
$compCampanas = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $compCampanas[] = [
            'id_campana'     => (int)$row['id_campana'],
            'nombre_campana' => htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8'),
            'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8')
        ];
    }
}
$stmt->close();
if (function_exists('apcu_store')) {
    apcu_store($cache_key, $compCampanas, 300);
}
echo json_encode($compCampanas);
?>

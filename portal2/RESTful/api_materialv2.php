<?php
header("Content-Type: application/json");

include 'report_functionsv2.php';

// Validar que se reciba el parámetro "nombre"
if (!isset($_GET['nombre']) || empty($_GET['nombre'])) {
    echo json_encode(['error' => 'Parámetro "nombre" no especificado.']);
    exit();
}

$formulario_nombre = $_GET['nombre'];

// Usamos la función pivot para la encuesta basada en nombre:
$materialPivot  = getMaterialPivot($formulario_nombre);

if (empty($materialPivot)) {
    echo json_encode(['error' => 'No se encontraron datos para el nombre especificado.']);
    exit();
}

echo json_encode([
    'materiales' => $materialPivot
]);
?>

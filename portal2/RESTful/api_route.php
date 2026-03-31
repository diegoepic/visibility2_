<?php
include 'report_functionsv2.php';

header('Content-Type: application/json');

// 1) Leer el parámetro desde la URL (ej: ?division=NESCAFE)
$division = isset($_GET['division']) 
    ? trim($_GET['division']) 
    : null;

// 2) Llamar a la función pasándole ese filtro
$data = getLocalMapData($division);

// 3) Devolver JSON
echo json_encode($data);
?>

<?php
include 'report_functions.php';

header('Content-Type: application/json');

$data = getPruebasLotes(1000); // Puedes ajustar el tamaño del lote según sea necesario
echo json_encode($data);
?>
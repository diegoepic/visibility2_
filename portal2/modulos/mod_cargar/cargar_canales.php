<?php
// modulos/cargar_canales.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$query = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
$result = $conn->query($query);
$canales = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $canales[] = $row;
    }
}
header('Content-Type: application/json');
echo json_encode($canales);
?>

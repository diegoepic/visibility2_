<?php
// modulos/cargar_comunas_por_canal_distrito.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id_canal = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : 0;
$id_distrito = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : 0;
$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : 0;

if ($id_canal <= 0 || $id_distrito <= 0 || $id_empresa <= 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$query = "SELECT DISTINCT l.id_comuna AS id, co.comuna 
          FROM local l
          JOIN comuna co ON l.id_comuna = co.id
          WHERE l.id_canal = ? AND l.id_distrito = ? AND l.id_empresa = ?
          ORDER BY co.comuna ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $id_canal, $id_distrito, $id_empresa);
$stmt->execute();
$result = $stmt->get_result();
$comunas = [];
while ($row = $result->fetch_assoc()) {
    $comunas[] = $row;
}
$stmt->close();
header('Content-Type: application/json');
echo json_encode($comunas);
?>

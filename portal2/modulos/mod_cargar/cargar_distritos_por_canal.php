<?php
// modulos/cargar_distritos_por_canal.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id_canal = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : 0;
$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : 0;

if ($id_canal <= 0 || $id_empresa <= 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$query = "SELECT DISTINCT l.id_distrito AS id, dt.nombre_distrito 
          FROM local l 
          JOIN distrito dt ON l.id_distrito = dt.id
          WHERE l.id_canal = ? AND l.id_empresa = ?
          ORDER BY dt.nombre_distrito ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $id_canal, $id_empresa);
$stmt->execute();
$result = $stmt->get_result();
$distritos = [];
while ($row = $result->fetch_assoc()) {
    $distritos[] = $row;
}
$stmt->close();
header('Content-Type: application/json');
echo json_encode($distritos);
?>

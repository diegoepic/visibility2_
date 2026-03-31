<?php
// modulos/cargar_usuarios.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : 0;
$query = "SELECT id, CONCAT(nombre, ' ', apellido) AS nombre_completo 
          FROM usuario 
          WHERE id_empresa = ? and id_perfil = 3
          ORDER BY nombre ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_empresa);
$stmt->execute();
$result = $stmt->get_result();

$usuarios = [];
while ($row = $result->fetch_assoc()) {
    $usuarios[] = $row;
}
$stmt->close();
header('Content-Type: application/json');
echo json_encode($usuarios);
?>
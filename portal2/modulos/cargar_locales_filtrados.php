<?php
// modulos/cargar_locales_filtrados.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Recibir parámetros
$id_empresa = isset($_GET['id_empresa']) ? intval($_GET['id_empresa']) : 0;
$id_distrito = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : 0;
$id_comuna  = isset($_GET['id_comuna'])  ? intval($_GET['id_comuna'])  : 0;
// Agregar al inicio:
$id_canal = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : 0;
// Luego, en la cláusula WHERE:
$where = "WHERE id_empresa = $id_empresa";
if ($id_canal > 0) {
    $where .= " AND id_canal = $id_canal";
}
if ($id_distrito > 0) {
    $where .= " AND id_distrito = $id_distrito";
}
if ($id_comuna > 0) {
    $where .= " AND id_comuna = $id_comuna";
}



$query = "SELECT id, codigo, nombre, direccion, lat, lng FROM local $where ORDER BY nombre ASC";
$result = $conn->query($query);

$locales = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $locales[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'locales' => $locales]);
?>

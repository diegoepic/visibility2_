<?php
// mod_user/obtener_comunas.php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$region_id = isset($_GET['region_id']) && is_numeric($_GET['region_id'])
    ? intval($_GET['region_id'])
    : 0;

if ($region_id <= 0) {
    echo '<option value="">Seleccione una comuna</option>';
    $conn->close();
    exit;
}

$stmt = $conn->prepare("SELECT id, comuna FROM comuna WHERE id_region = ? ORDER BY comuna ASC");
$stmt->bind_param("i", $region_id);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">Seleccione una comuna</option>';
while ($row = $result->fetch_assoc()) {
    echo '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['comuna'], ENT_QUOTES, 'UTF-8') . '</option>';
}

$stmt->close();
$conn->close();
?>

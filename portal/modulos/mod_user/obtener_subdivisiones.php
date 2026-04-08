<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$divisionId = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

echo '<option value="">Seleccione una subdivisión</option>';

if ($divisionId <= 0) {
    exit;
}

$sql = "SELECT id, nombre 
        FROM subdivision 
        WHERE id_division = ? 
        ORDER BY nombre ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $divisionId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo '<option value="' . (int)$row['id'] . '">' . htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8') . '</option>';
}

$stmt->close();
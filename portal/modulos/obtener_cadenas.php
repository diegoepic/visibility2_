<?php
// /home/visibility/public_html/visibility2/portal/modulos/obtener_cadenas.php
header('Content-Type: application/json');

require_once '../con_.php'; // Aseg¨²rate de que este archivo contiene la conexi¨®n a la base de datos

// Obtener el ID de la cuenta desde la petici¨®n GET y validar
$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;

if ($cuenta_id <= 0) {
    echo json_encode(['success' => false, 'data' => []]);
    exit();
}

// Consulta para obtener las cadenas asociadas a la cuenta
$sql = "SELECT id, nombre FROM cadena WHERE id_cuenta = ?";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'data' => []]);
    exit();
}

$stmt->bind_param("i", $cuenta_id);
$stmt->execute();
$result = $stmt->get_result();

$cadenas = array();
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $cadenas[] = array(
            'id' => intval($row['id']),
            'nombre' => htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8')
        );
    }
}

echo json_encode(['success' => true, 'data' => $cadenas]);
$stmt->close();
$conn->close();
?>

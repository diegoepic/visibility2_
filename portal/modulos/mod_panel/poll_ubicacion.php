<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';



$id_ejecutor = intval($_GET['id_ejecutor'] );
if ($id_ejecutor <= 0) {
  http_response_code(400);
  exit("Falta id_ejecutor");
}

// Leer la ubicación actual
$sql = "SELECT lat, lng, fecha_actualizacion FROM ubicacion_ejecutor WHERE id_ejecutor=? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_ejecutor);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
  echo json_encode([
    'lat' => floatval($row['lat']),
    'lng' => floatval($row['lng']),
    'fecha' => $row['fecha_actualizacion']
  ]);
} else {
  // No hay ubicación
  echo json_encode(null);
}
$stmt->close();
$conn->close();
?>

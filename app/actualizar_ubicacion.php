<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Verificar que esté logueado
if (!isset($_SESSION['usuario_id'])) {
  http_response_code(403);
  exit("No autorizado");
}

$id_ejecutor = intval($_SESSION['usuario_id']);
$datos = json_decode(file_get_contents('php://input'), true);
if (!$datos) {
  http_response_code(400);
  exit("Datos inválidos");
}

$lat = floatval($datos['lat'] );
$lng = floatval($datos['lng'] );

// Valida un rango básico
if ($lat == 0 || $lng == 0) {
  http_response_code(400);
  exit("Coordenadas inválidas");
}

// Guardar en la base de datos
// Para dejar *solo* la última ubicación, haz UPSERT
//   si tu motor lo permite, o REPLACE/ON DUPLICATE KEY
$sql = "
  INSERT INTO ubicacion_ejecutor (id_ejecutor, lat, lng, fecha_actualizacion)
  VALUES (?, ?, ?, NOW())
  ON DUPLICATE KEY UPDATE
    lat = VALUES(lat),
    lng = VALUES(lng),
    fecha_actualizacion = NOW()
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  exit("Error en la preparación de la consulta");
}

$stmt->bind_param("idd", $id_ejecutor, $lat, $lng);
if (!$stmt->execute()) {
  http_response_code(500);
  exit("Error ejecutando la consulta");
}

$stmt->close();
$conn->close();

echo "OK"; // Respuesta

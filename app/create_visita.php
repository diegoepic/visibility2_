<?php

$user   = intval($_SESSION['usuario_id']);
$form   = intval($_POST['id_formulario']);
$local  = intval($_POST['id_local']);
$lat    = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
$lng    = isset($_POST['lng']) ? floatval($_POST['lng']) : 0;

$sql = "INSERT INTO visita
          (id_usuario, id_formulario, id_local, fecha_inicio, latitud, longitud)
        VALUES (?,?,?,?,?,?)";
$stmt = $conn->prepare($sql);
$now = date('Y-m-d H:i:s');
$stmt->bind_param("iiisdd", $user, $form, $local, $now, $lat, $lng);
if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(['error'=>$stmt->error]);
  exit;
}
$vid = $stmt->insert_id;
$stmt->close();

// guardamos la sesion para los endpoints necesarios
$_SESSION['current_visita_id'] = $vid;

header('Content-Type: application/json');
echo json_encode(['visita_id'=>$vid]);
exit;

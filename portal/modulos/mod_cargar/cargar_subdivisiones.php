<?php
// /visibility2/portal/modulos/mod_panel/mod_cargar/cargar_subdivisiones.php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
  exit;
}

$id_division = isset($_GET['id_division']) ? intval($_GET['id_division']) : 0;

if ($id_division <= 0) {
  echo json_encode([
    'ok' => true,
    'subdivisiones' => [] 
  ]);
  exit;
}

$sql = "SELECT id, nombre FROM subdivision WHERE id_division = ? ORDER BY nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_division);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
  $items[] = [
    'id' => (int)$r['id'],
    'nombre' => $r['nombre']
  ];
}
$stmt->close();
$conn->close();

echo json_encode([
  'ok' => true,
  'subdivisiones' => $items
], JSON_UNESCAPED_UNICODE);

<?php
// /visibility2/portal/modulos/mod_cargar/cargar_campanas.php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
  exit;
}

$id_division    = isset($_GET['id_division']) ? intval($_GET['id_division']) : 0;
$id_subdivision = isset($_GET['id_subdivision']) ? intval($_GET['id_subdivision']) : 0;
$tipo_gestion   = isset($_GET['tipo_gestion']) ? intval($_GET['tipo_gestion']) : 0;

// Base query
$sql = "SELECT id, nombre FROM formulario WHERE estado='1'";
$params = [];
$types  = "";

// Filtros opcionales
if ($id_division > 0) {
  $sql .= " AND id_division=?";
  $types .= "i";
  $params[] = $id_division;
}

if ($id_subdivision > 0) {
  $sql .= " AND id_subdivision=?";
  $types .= "i";
  $params[] = $id_subdivision;
}

if ($tipo_gestion > 0) {
  $sql .= " AND tipo=?";
  $types .= "i";
  $params[] = $tipo_gestion;
}

$sql .= " ORDER BY nombre";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
  $items[] = [
    'id'     => (int)$r['id'],
    'nombre' => $r['nombre']
  ];
}

$stmt->close();
$conn->close();

echo json_encode([
  'ok' => true,
  'campanas' => $items
], JSON_UNESCAPED_UNICODE);

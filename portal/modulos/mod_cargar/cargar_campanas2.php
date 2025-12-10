<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

header('Content-Type: application/json; charset=utf-8');

$id_empresa   = intval($_GET['id_empresa'] ?? 0);
$id_division  = intval($_GET['id_division'] ?? 0);
$id_subdiv    = intval($_GET['id_subdivision'] ?? 0);
$id_estado    = intval($_GET['estado'] ?? 1);
$tipo_gestion = intval($_GET['tipo_gestion'] ?? 0); // 1=Campaña, 3=Ruta, 0=Todas

if ($id_empresa === 0 || $id_division === 0) {
  echo json_encode(['ok' => false, 'msg' => 'Faltan parámetros']);
  exit;
}

$sql = "
  SELECT f.id, f.nombre
  FROM formulario f
  WHERE f.id_empresa = ?
    AND f.id_division = ?
    AND f.estado = ?
";
$params = [$id_empresa, $id_division, $id_estado];
$types  = "iii";

if ($tipo_gestion > 0) {
  $sql .= " AND f.tipo = ? ";
  $params[] = $tipo_gestion;
  $types   .= "i";
}

if ($id_subdiv === -1) {
  $sql .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0)";
} elseif ($id_subdiv > 0) {
  $sql .= " AND f.id_subdivision = ?";
  $params[] = $id_subdiv;
  $types   .= "i";
}

$sql .= " ORDER BY COALESCE(f.fechaInicio, '1970-01-01') DESC, f.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$campanas = [];
while ($r = $res->fetch_assoc()) $campanas[] = $r;

$stmt->close();
$conn->close();

echo json_encode(['ok' => true, 'campanas' => $campanas]);

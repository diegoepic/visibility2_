<?php

declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
require_once __DIR__ . '/../con_.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_local = isset($_GET['id_local']) ? (int)$_GET['id_local'] : 0;
if ($id_local <= 0){ http_response_code(400); echo json_encode(['error'=>'bad_request']); exit; }

$sql = "SELECT l.id, l.codigo, l.nombre, l.direccion, c.nombre AS comuna, l.lat, l.lng
        FROM local l LEFT JOIN comuna c ON c.id = l.id_comuna
        WHERE l.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id_local);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

echo json_encode([
  'id'        => (int)$res['id'],
  'codigo'    => $res['codigo'] ?? null,
  'nombre'    => $res['nombre'] ?? null,
  'direccion' => $res['direccion'] ?? null,
  'comuna'    => $res['comuna'] ?? null,
  'lat'       => isset($res['lat']) ? (float)$res['lat'] : null,
  'lng'       => isset($res['lng']) ? (float)$res['lng'] : null,
], JSON_UNESCAPED_UNICODE);

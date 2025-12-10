<?php
// File: portal/modulos/mod_mapa/ajax_obtener_locales_mapa.php
header('Content-Type: application/json');
ini_set('display_errors',1);
error_reporting(E_ALL);

include_once __DIR__.'/../db.php';
include_once __DIR__.'/../session_data.php';

// 1) Autenticación
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Acceso denegado.']);
    exit;
}

// 2) Parámetro campaña
$formulario_id = isset($_GET['formulario_id']) ? intval($_GET['formulario_id']) : 0;
if (!$formulario_id) {
    echo json_encode(['success'=>false,'message'=>'formulario_id es obligatorio.']);
    exit;
}

// 3) Query: locales que tienen al menos 1 implementación en esta campaña
$sql = "
SELECT 
  l.id              AS idLocal,
  l.nombre          AS nombreLocal,
  l.direccion       AS direccionLocal,
  l.lat             AS latitud,
  l.lng             AS longitud,
  -- subconsulta para la foto de referencia más reciente
  (
    SELECT fv.url 
    FROM fotoVisita fv
    WHERE fv.id_formulario = ?
      AND fv.id_local       = l.id
    ORDER BY fv.id DESC
    LIMIT 1
  ) AS fotoRef
FROM formularioQuestion fq
INNER JOIN formulario       f ON f.id = fq.id_formulario
INNER JOIN local            l ON l.id = fq.id_local
WHERE fq.id_formulario = ?
  AND f.id_empresa     = ?
GROUP BY l.id, l.nombre, l.direccion, l.lat, l.lng
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii",
  $formulario_id,
  $formulario_id,
  $_SESSION['empresa_id']
);
$stmt->execute();
$res = $stmt->get_result();

$locales = [];
while ($r = $res->fetch_assoc()) {
    $locales[] = [
      'idLocal'        => (int)$r['idLocal'],
      'nombreLocal'    => $r['nombreLocal'],
      'direccionLocal' => $r['direccionLocal'],
      'latitud'        => (float)$r['latitud'],
      'longitud'       => (float)$r['longitud'],
      'fotoRef'        => $r['fotoRef']  // ruta relativa, ej: "uploads/visita/123.jpg"
    ];
}

echo json_encode([
  'success' => true,
  'data'    => $locales
]);

<?php

session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo json_encode([]); exit(); }

$formulario_id = intval($_GET['campana'] ?? 0);
$local_id      = intval($_GET['local']   ?? 0);
$empresa_id    = intval($_SESSION['empresa_id'] ?? 0);

if ($formulario_id <= 0 || $local_id <= 0 || $empresa_id <= 0) {
  http_response_code(400); echo json_encode([]); exit();
}

$sql = "
  SELECT
    gv.id                              AS idGV,
    gv.visita_id                       AS visitaId,
    gv.id_local                        AS localId,
    COALESCE(m.nombre, fq.material)    AS material,
    fq.valor_propuesto                 AS valorPropuesto,
    gv.valor_real                      AS valorImplementado,
    DATE_FORMAT(gv.fecha_visita, '%d/%m/%Y %H:%i') AS fechaVisita,
    COALESCE(gv.latitud, gv.lat_foto, fq.latGestion) AS lat,
    COALESCE(gv.longitud, gv.lng_foto, fq.lngGestion) AS lng,
    gv.estado_gestion                  AS estadoGestion,
    u.usuario                          AS usuario
  FROM gestion_visita gv
  JOIN formulario f   ON f.id = gv.id_formulario AND f.id_empresa = ?
  LEFT JOIN usuario u ON u.id = gv.id_usuario
  LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
  LEFT JOIN material m ON m.id = gv.id_material
  WHERE gv.id_formulario = ?
    AND gv.id_local      = ?
    AND (COALESCE(gv.latitud, gv.lat_foto, fq.latGestion) IS NOT NULL)
    AND (COALESCE(gv.longitud, gv.lng_foto, fq.lngGestion) IS NOT NULL)
  ORDER BY gv.fecha_visita ASC, gv.id ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $empresa_id, $formulario_id, $local_id);
$stmt->execute();
$res = $stmt->get_result();

$gestiones = [];
while ($r = $res->fetch_assoc()) {
  $gestiones[] = [
    'id'                => (int)$r['idGV'],
    'idFQ'              => (int)$r['idGV'], // legacy para front actual
    'visitaId'          => (int)$r['visitaId'],
    'localId'           => (int)$r['localId'],
    'material'          => $r['material'],
    'valorPropuesto'    => $r['valorPropuesto'],
    'valorImplementado' => $r['valorImplementado'],
    'fechaVisita'       => $r['fechaVisita'],
    'lat'               => (float)$r['lat'],
    'lng'               => (float)$r['lng'],
    'estado_gestion'    => $r['estadoGestion'],
    'estado'            => $r['estadoGestion'], // alias legacy
    'usuario'           => $r['usuario'],
  ];
}

$stmt->close();
$conn->close();

echo json_encode($gestiones, JSON_UNESCAPED_UNICODE);
exit();

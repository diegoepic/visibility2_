<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

require_once __DIR__ . '/../con_.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$user_id    = (int)($_SESSION['usuario_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

$today = (new DateTime('today'))->format('Y-m-d');
$from  = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : $today;
$to    = isset($_GET['to'])   ? substr((string)$_GET['to'],0,10)   : $today;
if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

$conn->set_charset('utf8mb4');

// --- 1) Agregados por día+local ---
$sqlAgg = "
  SELECT u.ymd, u.id_local,
         SUM(u.photos_material) AS photos_material,
         SUM(u.photos_encuesta) AS photos_encuesta,
         SUM(u.answers)         AS answers,
         COUNT(DISTINCT u.visita_id) AS visits_created,
         MAX(u.last_updated)    AS last_updated
  FROM (
    -- Visitas creadas
    SELECT DATE(v.created_at) AS ymd, v.id_local, v.id AS visita_id,
           0 AS photos_material, 0 AS photos_encuesta, 0 AS answers,
           COALESCE(v.updated_at, v.created_at) AS last_updated
    FROM visita v
    INNER JOIN formulario f ON f.id = v.id_formulario AND f.id_empresa = ?
    WHERE v.id_usuario = ? AND DATE(v.created_at) BETWEEN ? AND ?

    UNION ALL

    -- Respuestas
    SELECT DATE(v.created_at) AS ymd, v.id_local, v.id AS visita_id,
           0 AS photos_material, 0 AS photos_encuesta,
           COUNT(r.id) AS answers,
           MAX(COALESCE(r.updated_at, r.created_at)) AS last_updated
    FROM form_question_responses r
    INNER JOIN visita v      ON v.id = r.visita_id
    INNER JOIN formulario f  ON f.id = v.id_formulario AND f.id_empresa = ?
    WHERE v.id_usuario = ? AND DATE(v.created_at) BETWEEN ? AND ?
    GROUP BY ymd, v.id_local, v.id

    UNION ALL

    -- Fotos (material vs encuesta)
    SELECT DATE(v.created_at) AS ymd, v.id_local, v.id AS visita_id,
           SUM(CASE WHEN fv.id_material IS NOT NULL THEN 1 ELSE 0 END) AS photos_material,
           SUM(CASE WHEN fv.id_form_question IS NOT NULL THEN 1 ELSE 0 END) AS photos_encuesta,
           0 AS answers,
           MAX(COALESCE(fv.updated_at, fv.created_at)) AS last_updated
    FROM fotoVisita fv
    INNER JOIN visita v     ON v.id = fv.visita_id
    INNER JOIN formulario f ON f.id = v.id_formulario AND f.id_empresa = ?
    WHERE v.id_usuario = ? AND DATE(v.created_at) BETWEEN ? AND ?
    GROUP BY ymd, v.id_local, v.id
  ) u
  GROUP BY u.ymd, u.id_local
  ORDER BY u.ymd, u.id_local
";
$stmt = $conn->prepare($sqlAgg);
$stmt->bind_param(
  'iissiissiiss',
  $empresa_id, $user_id, $from, $to,     // bloque 1
  $empresa_id, $user_id, $from, $to,     // bloque 2
  $empresa_id, $user_id, $from, $to      // bloque 3
);
$stmt->execute();
$agg = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- 2) Locales usados en el rango ---
$ids_local = array_values(array_unique(array_map(fn($r)=> (int)$r['id_local'], $agg)));
$locals = [];
if ($ids_local) {
  $in  = implode(',', array_fill(0, count($ids_local), '?'));
  $typ = str_repeat('i', count($ids_local));
  $sqlL = "
    SELECT l.id, l.codigo, l.nombre, l.direccion, c.nombre AS comuna, l.lat, l.lng
    FROM local l
    LEFT JOIN comuna c ON c.id = l.id_comuna
    WHERE l.id IN ($in)
  ";
  $stmt = $conn->prepare($sqlL);
  $stmt->bind_param($typ, ...$ids_local);
  $stmt->execute();
  $resL = $stmt->get_result();
  while($row = $resL->fetch_assoc()){
    $locals[(int)$row['id']] = [
      'id'        => (int)$row['id'],
      'codigo'    => $row['codigo'] ?? null,
      'nombre'    => $row['nombre'] ?? null,
      'direccion' => $row['direccion'] ?? null,
      'comuna'    => $row['comuna'] ?? null,
      'lat'       => isset($row['lat']) ? (float)$row['lat'] : null,
      'lng'       => isset($row['lng']) ? (float)$row['lng'] : null,
    ];
  }
  $stmt->close();
}

// --- 3) Campañas por día+local (subtítulo) ---
$camp = [];
$sqlC = "
  SELECT DATE(v.created_at) AS ymd, v.id_local,
         GROUP_CONCAT(DISTINCT f.nombre ORDER BY f.nombre SEPARATOR ' · ') AS names
  FROM visita v
  INNER JOIN formulario f ON f.id = v.id_formulario AND f.id_empresa = ?
  WHERE v.id_usuario = ? AND DATE(v.created_at) BETWEEN ? AND ?
  GROUP BY ymd, v.id_local
";
$stmt = $conn->prepare($sqlC);
$stmt->bind_param('iiss', $empresa_id, $user_id, $from, $to);
$stmt->execute();
$rC = $stmt->get_result();
while($row = $rC->fetch_assoc()){
  $camp[$row['ymd'].'|'.$row['id_local']] = $row['names'] ?: '';
}
$stmt->close();

// --- 4) Empaquetado ---
$items = [];
$etag_basis = [$from,$to];
foreach ($agg as $r){
  $ymd      = $r['ymd'];
  $id_local = (int)$r['id_local'];

  $loc = $locals[$id_local] ?? ['id'=>$id_local,'codigo'=>null,'nombre'=>null,'direccion'=>null,'comuna'=>null];
  $campaigns = [];
  $cKey = $ymd.'|'.$id_local;
  if (!empty($camp[$cKey])) $campaigns = explode(' · ', $camp[$cKey]);

  $counts = [
    'photos_material' => (int)$r['photos_material'],
    'photos_encuesta' => (int)$r['photos_encuesta'],
    'answers'         => (int)$r['answers'],
    'visits_created'  => (int)$r['visits_created'],
  ];

  $items[] = [
    'ymd'         => $ymd,
    'local'       => $loc,
    'campaigns'   => $campaigns,
    'counts'      => $counts,
    'status'      => 'success',
    'progress'    => 100,
    'last_updated'=> $r['last_updated'],
  ];

  $etag_basis[] = $ymd.$id_local.implode(',', $counts).($r['last_updated'] ?? '');
}

// ETag condicional
$etag = 'W/"jr-'.sha1(implode('|', $etag_basis)).'"';
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag){
  http_response_code(304);
  exit;
}

echo json_encode([
  'manifest'=> [
    'etag' => $etag,
    'from'=> $from,
    'to'  => $to,
    'server_time'=> (new DateTime())->format('c')
  ],
  'items'=> $items
], JSON_UNESCAPED_UNICODE);

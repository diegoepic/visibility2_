<?php
set_time_limit(300);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

// ===== Config =====
$UPLOAD_DIR = __DIR__ . '/uploads';
$EXPORT_DIR = __DIR__ . '/exports';
@mkdir($UPLOAD_DIR, 0775, true);
@mkdir($EXPORT_DIR, 0775, true);

$max = isset($_POST['max']) ? max(1,(int)$_POST['max']) : 25;

// ===== Helpers =====
function json_out($arr) {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function parse_coord($s, $isLat = true) {
  $s = trim((string)$s);
  if ($s === '') return null;

  // Unificar coma decimal
  $s = str_replace(',', '.', $s);

  // Si tiene más de un punto, asumimos miles: quitarlos
  if (substr_count($s, '.') > 1) $s = str_replace('.', '', $s);

  // Dejar solo signo/dígitos/punto
  $s = preg_replace('/[^0-9\-\+\.]/', '', $s);
  if ($s === '' || $s === '-' || $s === '+') return null;

  $val = floatval($s);

  // Reescalar si quedó fuera de rango
  $lim = $isLat ? 90 : 180;
  $iters = 0;
  while (abs($val) > $lim && $val != 0 && $iters < 10) { $val /= 10.0; $iters++; }

  if ($isLat  && ($val < -90  || $val > 90))   return null;
  if (!$isLat && ($val < -180 || $val > 180))  return null;

  return $val;
}

function remove_accents_lower($s) {
  $s = (string)$s;
  // quita BOM y otros invisibles (ZWSP, ZWNJ, ZWJ)
  $s = str_replace("\xEF\xBB\xBF", '', $s);               // BOM utf-8 (bytes)
  $s = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}]/u', '', $s); // FEFF y 200B..200D

  $s = mb_strtolower(trim($s), 'UTF-8');
  $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'];
  $s = strtr($s, $map);
  return preg_replace('/\s+|_+/', '', $s);
}
function haversine_km($lat1, $lon1, $lat2, $lon2) {
  $R = 6371;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);
  $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
  return 2 * $R * asin(min(1, sqrt($a)));
}
function nearest_neighbor_route($pts) {
  // $pts: array of ['idx'=>int, 'lat'=>float, 'lng'=>float]
  if (count($pts) <= 2) return $pts;
  $used = array_fill(0, count($pts), false);
  $route = [];
  $i = 0; // start at first
  $route[] = $pts[$i]; $used[$i] = true;
  for ($k=1; $k<count($pts); $k++) {
    $best = -1; $bestD = PHP_FLOAT_MAX;
    for ($j=0; $j<count($pts); $j++) {
      if ($used[$j]) continue;
      $d = haversine_km($pts[$i]['lat'], $pts[$i]['lng'], $pts[$j]['lat'], $pts[$j]['lng']);
      if ($d < $bestD) { $bestD = $d; $best = $j; }
    }
    $i = $best; $used[$i] = true; $route[] = $pts[$i];
  }
  return $route;
}
function detect_delimiter($line) {
  $sc = substr_count($line, ';');
  $cm = substr_count($line, ',');
  $tb = substr_count($line, "\t");
  if ($tb >= $sc && $tb >= $cm) return "\t";
  return ($sc > $cm ? ';' : ',');
}

// ===== Validación del archivo =====
if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
  json_out(['ok'=>false, 'msg'=>'No se recibió archivo válido.']);
}
$orig = $_FILES['archivo']['name'];
$tmp  = $_FILES['archivo']['tmp_name'];
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$dest = $UPLOAD_DIR . '/' . uniqid('rutas_', true) . '.' . $ext;
if (!move_uploaded_file($tmp, $dest)) {
  json_out(['ok'=>false, 'msg'=>'No se pudo guardar el archivo.']);
}

// ===== Leer archivo a array $rows (asociativo) =====
$rows = [];
try {
    if (in_array($ext, ['csv','tsv','txt'])) {
    $fh = fopen($dest, 'r');
    if (!$fh) throw new Exception('No se pudo abrir CSV');
    $first = fgets($fh);
    if ($first === false) throw new Exception('Archivo vacío');
    $delim = detect_delimiter($first);
    $headers = str_getcsv($first, $delim);
    if (isset($headers[0])) {
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $headers[0]);
    }
    $norm = array_map('remove_accents_lower', $headers);
    while (($line = fgetcsv($fh, 0, $delim)) !== false) {
      if (!array_filter($line, fn($v)=> trim((string)$v) !== '')) continue; // ← salta vacías
      $row = [];
      foreach ($line as $i=>$v) $row[$norm[$i] ?? ("col$i")] = trim((string)$v);
      $rows[] = $row;
    }
    fclose($fh);
  } else {
    // Requiere PhpSpreadsheet (composer require phpoffice/phpspreadsheet)
    require_once __DIR__ . '/vendor/autoload.php';
    $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($dest);
    $sheet = $ss->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);
    if (count($data) < 2) throw new Exception('XLSX vacío');
    $hdr = array_shift($data);
    $norm = [];
    foreach ($hdr as $k=>$v) $norm[$k] = remove_accents_lower($v);
    foreach ($data as $rowX) {
      $row = [];
      foreach ($rowX as $k=>$v) $row[$norm[$k] ?? $k] = trim((string)$v);
      $rows[] = $row;
    }
  }
} catch (Exception $e) {
  json_out(['ok'=>false, 'msg'=>'Error leyendo archivo: '.$e->getMessage()]);
}

if (isset($_POST['debug']) && $_POST['debug']=='1') {
  $hdr = isset($rows[0]) ? array_keys($rows[0]) : [];
  json_out([
    'ok' => true,
    'stage' => 'read',
    'rows_count' => count($rows),
    'headers_raw' => $hdr,
    'headers_norm' => array_map('remove_accents_lower', $hdr),
    'sample' => array_slice($rows, 0, 3),
    'ext' => $ext
  ]);
}


// ===== Mapear columnas requeridas =====
$required = [
  'codigolocal' => ['codigolocal','codigo local','codigo'],
  'comuna'      => ['comuna'],
  'merchan'     => ['merchan','merch','mercaderista'],
  'latitud'     => ['latitud','lat'],
  'longitud'    => ['longitud','lng','lon','long']
];
function find_key($row, $cands) {
  foreach ($cands as $c) if (array_key_exists($c, $row)) return $c;
  return null;
}

// Normalizar y filtrar válidos
$clean = [];
$invalid = 0;
foreach ($rows as $r) {
  // normaliza claves por si vinieron con acentos/espacios
  $nr = [];
  foreach ($r as $k=>$v) $nr[remove_accents_lower($k)] = $v;

  $k_codigo = find_key($nr, $required['codigolocal']);
  $k_comuna = find_key($nr, $required['comuna']);
  $k_merch  = find_key($nr, $required['merchan']);
  $k_lat    = find_key($nr, $required['latitud']);
  $k_lng    = find_key($nr, $required['longitud']);

  if (!$k_codigo || !$k_comuna || !$k_merch || !$k_lat || !$k_lng) { $invalid++; continue; }

    $lat = parse_coord($nr[$k_lat], true);
    $lng = parse_coord($nr[$k_lng], false);
    if ($lat === null || $lng === null) { $invalid++; continue; }

  $clean[] = [
    'codigo_local' => (string)$nr[$k_codigo],
    'comuna'       => (string)$nr[$k_comuna],
    'merchan'      => (string)$nr[$k_merch],
    'lat'          => $lat,
    'lng'          => $lng
  ];
}

if (isset($_POST['debug']) && $_POST['debug']=='1') {
  json_out([
    'ok' => true,
    'stage' => 'clean',
    'rows_total' => count($rows),
    'valid' => count($clean),
    'invalid' => $invalid,
    'sample_clean' => array_slice($clean, 0, 3)
  ]);
}

if (empty($clean)) {
  json_out(['ok'=>false, 'msg'=>'No hay filas válidas (revisa nombres de columnas y lat/long).']);
}

// ===== Agrupar en lotes de hasta 25 por cercanía a una semilla =====
$remaining = $clean;                // lista mutable
$groups = [];
$group_id = 1;

while (count($remaining) > 0) {
  // semilla: primera
  $seed = $remaining[0];

  // calcular distancias de semilla a todos
  $withD = [];
  foreach ($remaining as $i=>$p) {
    $withD[] = [
      'i'   => $i,
      'p'   => $p,
      'dkm' => haversine_km($seed['lat'], $seed['lng'], $p['lat'], $p['lng'])
    ];
  }
  // ordenar por distancia asc
  usort($withD, fn($a,$b)=> $a['dkm'] <=> $b['dkm']);

  // tomar hasta 25
  
  $chunk = array_slice($withD, 0, $max);
  // extraer los puntos en el mismo orden
  $pts = array_map(fn($x)=> $x['p'], $chunk);

  // ordenar internamente por ruta (nearest neighbor)
  $routeIndexed = [];
  foreach ($pts as $idx=>$p) $routeIndexed[] = ['idx'=>$idx,'lat'=>$p['lat'],'lng'=>$p['lng']];
  $routeOrder = nearest_neighbor_route($routeIndexed); // devuelve array con claves idx

  // construir salida del grupo con orden y distancia al anterior
  $ordered = [];
  $prev = null; $orden = 1;
  foreach ($routeOrder as $node) {
    $p = $pts[$node['idx']];
    $dist_prev = ($prev === null) ? 0.0 : haversine_km($prev['lat'],$prev['lng'],$p['lat'],$p['lng']);
    $ordered[] = [
      'grupo'          => $group_id,
      'orden_en_grupo' => $orden++,
      'codigo_local'   => $p['codigo_local'],
      'comuna'         => $p['comuna'],
      'merchan'        => $p['merchan'],
      'latitud'        => $p['lat'],
      'longitud'       => $p['lng'],
      'dist_prev_km'   => round($dist_prev, 3)
    ];
    $prev = $p;
  }

  $groups = array_merge($groups, $ordered);

  // eliminar del remaining los indices usados en $chunk
  // OJO: $remaining es base 0; eliminamos de mayor a menor índice para no mover offsets
  $idxs = array_map(fn($x)=> $x['i'], $chunk);
  rsort($idxs);
  foreach ($idxs as $i) array_splice($remaining, $i, 1);

  $group_id++;
}

// ===== Escribir CSV de salida =====
$fname = 'rutas_agrupadas_' . date('Ymd_His') . '.csv';
$path  = $EXPORT_DIR . '/' . $fname;
$fh = fopen($path, 'w');
if (!$fh) json_out(['ok'=>false, 'msg'=>'No se pudo crear el archivo de salida.']);

// encabezados
$headers = ['grupo','orden_en_grupo','codigo_local','comuna','merchan','latitud','longitud','dist_prev_km'];
fputcsv($fh, $headers, ';');
foreach ($groups as $row) {
  fputcsv($fh, [
    $row['grupo'],
    $row['orden_en_grupo'],
    $row['codigo_local'],
    $row['comuna'],
    $row['merchan'],
    $row['latitud'],
    $row['longitud'],
    $row['dist_prev_km']
  ], ';');
}
fclose($fh);

// ===== Respuesta JSON =====
$outUrl = '/visibility2/portal/modulos/rutas/exports/' . $fname;
$debug  = [];
if (!empty($_POST['debug'])) $debug = array_slice($groups, 0, 5);

json_out([
  'ok'       => true,
  'total'    => count($groups),
  'grupos'   => $group_id - 1,
  'invalid'  => $invalid,
  'download' => $outUrl,
  'debug'    => $debug
]);

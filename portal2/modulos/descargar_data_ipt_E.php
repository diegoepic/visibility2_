<?php
// =====================================================
//  DESCARGAR DATA IPT - VERSIÓN OPTIMIZADA PARA GRANDES VOLUMENES
//  Mejora: streaming real, control de memoria y flushing continuo
// =====================================================

// ==================== DEBUG ====================
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) {
  ini_set('zlib.output_compression', '0');
  if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: text/plain; charset=UTF-8');
  echo "DEBUG MODE ON\n";
  register_shutdown_function(function () {
    $e = error_get_last();
    echo $e ? ("\n[FATAL ERROR]\n" . print_r($e, true)) : "\n[EOF OK]\n";
  });
}

// ==================== PHP / DB ====================
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

// ==================== PARAMS ====================
$BATCH_SIZE  = isset($_GET['batch']) ? max(1000, intval($_GET['batch'])) : 20000;
$CHUNK_DAYS  = 31; // ventanas de fechas por lotes

$division     = isset($_GET['id_division'])    ? (int)$_GET['id_division']    : 0;
$subdivision  = isset($_GET['id_subdivision']) ? (int)$_GET['id_subdivision'] : 0;
$ejecutor     = isset($_GET['id_usuario'])     ? (int)$_GET['id_usuario']     : 0;
$fecha_inicio = isset($_GET['fecha_inicio'])   ? $_GET['fecha_inicio']        : '';
$fecha_fin    = isset($_GET['fecha_fin'])      ? $_GET['fecha_fin']           : '';

$today = date('Y-m-d');
if (empty($fecha_inicio)) $fecha_inicio = '2000-01-01';
if (empty($fecha_fin))    $fecha_fin    = $today;
if ($fecha_inicio > $fecha_fin) { $t=$fecha_inicio; $fecha_inicio=$fecha_fin; $fecha_fin=$t; }

// División con mucho volumen → reducir tamaño de chunk y batch
if ($division === 13) { // por ejemplo: “Consumo masivo”
  $CHUNK_DAYS = 7;
  $BATCH_SIZE = 5000;
}

if ($DEBUG) {
  echo "PARAMS division=$division, subdivision=$subdivision, ejecutor=$ejecutor\n";
  echo "RANGO $fecha_inicio .. $fecha_fin\n";
}

// ==================== WHERE BASE ====================
$whereBase = " WHERE f.tipo = 3 ";
if ($division)    { $whereBase .= " AND f.id_division = " . (int)$division; }
if ($subdivision) { $whereBase .= " AND f.id_subdivision = " . (int)$subdivision; }
if ($ejecutor)    { $whereBase .= " AND fqr.id_usuario = " . (int)$ejecutor; }

if ($DEBUG) { echo "WHERE_BASE:\n$whereBase\n\n"; }

// ==================== HELPERS ====================
function buildDateWindows($startDate, $endDate, $chunkDays) {
  $windows = [];
  $start = new DateTime($startDate);
  $end   = new DateTime($endDate);
  while ($start <= $end) {
    $chunkEnd = clone $start;
    $chunkEnd->modify('+'.($chunkDays-1).' days');
    if ($chunkEnd > $end) { $chunkEnd = clone $end; }
    $windows[] = [$start->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
    $start = clone $chunkEnd;
    $start->modify('+1 day');
  }
  return $windows;
}
function to_upper($v) {
  if ($v === null) return '';
  $s = (string)$v;
  if ($s === '') return '';
  return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

// ======================================================================
// 1) DESCUBRIR PREGUNTAS (para columnas dinámicas)
// ======================================================================
$whereCols = $whereBase
  . " AND fqr.created_at >= '" . mysqli_real_escape_string($conn, $fecha_inicio) . " 00:00:00'"
  . " AND fqr.created_at <= '" . mysqli_real_escape_string($conn, $fecha_fin)    . " 23:59:59'";

$sqlCols = "
  SELECT
    fp.question_text                    AS question_text,
    MIN(fp.sort_order)                  AS sorder,
    MAX(CASE WHEN fqr.valor IS NOT NULL AND fqr.valor <> '0.00' AND fqr.valor <> 0 THEN 1 ELSE 0 END) AS has_valor
  FROM formulario f
  JOIN form_questions fp            ON fp.id_formulario      = f.id
  JOIN form_question_responses fqr  ON fqr.id_form_question  = fp.id
  JOIN usuario u                    ON u.id                  = fqr.id_usuario
  JOIN local l                      ON l.id                  = fqr.id_local
  JOIN canal ca                     ON ca.id                 = l.id_canal
  JOIN cuenta cu                    ON cu.id                 = l.id_cuenta
  JOIN distrito di                  ON di.id                 = l.id_distrito
  JOIN comuna c                     ON c.id                  = l.id_comuna
  JOIN zona z                       ON z.id                  = l.id_zona
  JOIN region r                     ON r.id                  = c.id_region
  $whereCols
  GROUP BY question_text
  ORDER BY sorder ASC, question_text ASC
";

if ($DEBUG) { echo "SQLCols:\n$sqlCols\n"; }

$colsRes = mysqli_query($conn, $sqlCols);
if (!$colsRes) { http_response_code(500); echo "ERROR SQLCols: " . mysqli_error($conn) . "\n"; exit; }

$questions = [];
$hasValor  = [];
while ($r = mysqli_fetch_assoc($colsRes)) {
  $qt = $r['question_text'];
  $questions[] = $qt;
  $hasValor[$qt] = ((int)$r['has_valor'] === 1);
}
mysqli_free_result($colsRes);

if (empty($questions)) {
  if ($DEBUG) { echo "Sin preguntas/datos en el rango.\n"; exit; }
  header("Cache-Control: no-cache, must-revalidate");
  header("Pragma: no-cache");
  header("Content-Type: text/csv; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"Encuesta_Pivot_" . date("Ymd_His") . ".csv\"");
  echo "\xEF\xBB\xBF";
  echo "Sin datos\n";
  exit;
}

if ($DEBUG) {
  echo "PREGUNTAS (" . count($questions) . "): " . implode(' | ', $questions) . "\n\n";
}

// ==================== CABECERA Y STREAMING ====================
while (ob_get_level() > 0) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
header('X-Accel-Buffering: no'); // para nginx
set_time_limit(0);
ini_set('memory_limit', '1024M');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Encuesta_PIVOT_" . date("Ymd_His") . ".csv\"");
echo "\xEF\xBB\xBF"; // BOM UTF-8

$DELIMITER = ';';
$ENCLOSURE = '"';

$staticHeaders = [
  'nombreCampana',
  'nombreCanal',
  'nombreDistrito',
  'codigo_local',
  'cuenta',
  'nombre_local',
  'direccion_local',
  'comuna',
  'region',
  'nombreZona',
  'usuario',
  'fecha_respuesta'
];

$dynHeaders = [];
foreach ($questions as $qtxt) {
  $dynHeaders[] = $qtxt;
  if (!empty($hasValor[$qtxt])) $dynHeaders[] = $qtxt . '_VALOR';
}

$out = fopen('php://output', 'w');
fputcsv($out, array_merge($staticHeaders, $dynHeaders), $DELIMITER, $ENCLOSURE);


// ==================== BUFFER / FLUSH ====================
$currentKey = null;
$currentRow = null;

$flushCurrent = function() use (&$currentRow, &$questions, &$hasValor, $out, $DELIMITER, $ENCLOSURE) {
  if (!$currentRow) return;

  $maxRows = 1;
  foreach ($questions as $qt) {
    if (isset($currentRow['Q'][$qt])) {
      $nA = !empty($currentRow['Q'][$qt]['answers']) ? count($currentRow['Q'][$qt]['answers']) : 0;
      $nV = !empty($currentRow['Q'][$qt]['valores']) ? count($currentRow['Q'][$qt]['valores']) : 0;
      $n  = max($nA, $nV);
      if ($n > $maxRows) $maxRows = $n;
    }
  }

  for ($i = 0; $i < $maxRows; $i++) {
    $rowOut = [
      to_upper($currentRow['nombreCampana']),
      to_upper($currentRow['nombreCanal']),
      to_upper($currentRow['nombreDistrito']),
      to_upper($currentRow['codigo_local']),
      to_upper($currentRow['cuenta']),
      to_upper($currentRow['nombre_local']),
      to_upper($currentRow['direccion_local']),
      to_upper($currentRow['comuna']),
      to_upper($currentRow['region']),
      to_upper($currentRow['nombreZona']),
      to_upper($currentRow['usuario']),
      to_upper($currentRow['fecha_respuesta'])
    ];

    foreach ($questions as $qt) {
      $ans = (isset($currentRow['Q'][$qt]['answers'][$i])) ? $currentRow['Q'][$qt]['answers'][$i] : '';
      $rowOut[] = to_upper($ans);
      if (!empty($hasValor[$qt])) {
        $val = (isset($currentRow['Q'][$qt]['valores'][$i])) ? $currentRow['Q'][$qt]['valores'][$i] : '';
        $rowOut[] = to_upper($val);
      }
    }
    fputcsv($out, $rowOut, $DELIMITER, $ENCLOSURE);
  }

  $currentRow = null;
};

// ==================== DATA LOOP ====================
$dateWindows = buildDateWindows($fecha_inicio, $fecha_fin, $CHUNK_DAYS);

foreach ($dateWindows as [$chunkStart, $chunkEnd]) {
  $flushCurrent();
  $last_id = 0; $more = true;

  $where = $whereBase
    . " AND fqr.created_at >= '" . mysqli_real_escape_string($conn, $chunkStart) . " 00:00:00'"
    . " AND fqr.created_at <= '" . mysqli_real_escape_string($conn, $chunkEnd)   . " 23:59:59'";

  while ($more) {
    $sql = "
      SELECT
        fqr.id                 AS k_id,
        f.id                   AS idCampana,
        f.nombre               AS nombreCampana,
        ca.nombre_canal        AS nombreCanal,
        di.nombre_distrito     AS nombreDistrito,
        l.codigo               AS codigo_local,
        cu.nombre              AS cuenta,
        l.nombre               AS nombre_local,
        l.direccion            AS direccion_local,
        c.comuna               AS comuna,
        r.region               AS region,
        z.nombre_zona          AS nombreZona,
        CONCAT(u.nombre,' ',u.apellido) AS usuario,
        DATE(fqr.created_at)   AS fecha_respuesta,
        fp.question_text       AS pregunta,
        fqr.answer_text        AS respuesta,
        CASE
          WHEN fqr.valor IS NOT NULL AND fqr.valor <> '0.00' AND fqr.valor <> 0
          THEN CAST(fqr.valor AS CHAR)
          ELSE ''
        END                    AS precio
      FROM formulario f
      JOIN form_questions fp            ON fp.id_formulario      = f.id
      JOIN form_question_responses fqr  ON fqr.id_form_question  = fp.id
      JOIN usuario u                    ON u.id                  = fqr.id_usuario
      JOIN local l                      ON l.id                  = fqr.id_local
      JOIN canal ca                     ON ca.id                 = l.id_canal
      JOIN cuenta cu                    ON cu.id                 = l.id_cuenta
      JOIN distrito di                  ON di.id                 = l.id_distrito
      JOIN comuna c                     ON c.id                  = l.id_comuna
      JOIN zona z                       ON z.id                  = l.id_zona
      JOIN region r                     ON r.id                  = c.id_region
      $where
        AND fqr.id > $last_id
      ORDER BY fqr.id ASC, l.codigo ASC, fp.sort_order ASC
      LIMIT $BATCH_SIZE
    ";

    $res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
    if (!$res) { http_response_code(500); echo "ERROR SQL DATA: " . mysqli_error($conn) . "\n"; exit; }

    $rowCount = 0;
    while ($row = mysqli_fetch_assoc($res)) {
      $rowCount++;
      $last_id = (int)$row['k_id'];

      $groupKey = $row['idCampana'] . '|' . $row['codigo_local'] . '|' . $row['fecha_respuesta'];
      if (!isset($currentKey) || $groupKey !== $currentKey) {
        $flushCurrent();
        $currentKey = $groupKey;
        $currentRow = [
          'nombreCampana'   => $row['nombreCampana'],
          'nombreCanal'     => $row['nombreCanal'],
          'nombreDistrito'  => $row['nombreDistrito'],
          'codigo_local'    => $row['codigo_local'],
          'cuenta'          => $row['cuenta'],
          'nombre_local'    => $row['nombre_local'],
          'direccion_local' => $row['direccion_local'],
          'comuna'          => $row['comuna'],
          'region'          => $row['region'],
          'nombreZona'      => $row['nombreZona'],
          'usuario'         => $row['usuario'],
          'fecha_respuesta' => $row['fecha_respuesta'],
          'Q'               => []
        ];
      }

      $qt  = $row['pregunta'];
      $ans = (string)($row['respuesta'] ?? '');
      $val = (string)($row['precio'] ?? '');

      if (!isset($currentRow['Q'][$qt])) {
        $currentRow['Q'][$qt] = ['answers' => [], 'valores' => []];
      }
      if ($ans !== '') $currentRow['Q'][$qt]['answers'][] = $ans;
      if ($val !== '') $currentRow['Q'][$qt]['valores'][] = $val;

      if (($rowCount % 5000) === 0) {
      }
    }

    mysqli_free_result($res);
    unset($row);
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();

    if ($rowCount < $BATCH_SIZE) { $more = false; }
  }
}

// flush final
$flushCurrent();

fclose($out);
exit;

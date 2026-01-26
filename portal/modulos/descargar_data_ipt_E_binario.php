<?php
// =====================================================
//  DESCARGAR DATA IPT - PIVOT OPTIMIZADO (STREAMING)
//  Soporta preguntas normales + selección múltiple (tipo 3)
// =====================================================

// ==================== DEBUG ====================
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '2048M');
ignore_user_abort(true);

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
error_reporting(0);

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

// ==================== PARAMS ====================
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

mysqli_query($conn, "SET SESSION tmp_table_size = 1024*1024*1024");
mysqli_query($conn, "SET SESSION max_heap_table_size = 1024*1024*1024");

$BATCH_SIZE  = isset($_GET['batch']) ? max(1000, intval($_GET['batch'])) : 20000;
$CHUNK_DAYS  = 31;

$division     = (int)($_GET['id_division']    ?? 0);
$subdivision  = (int)($_GET['id_subdivision'] ?? 0);
$ejecutor     = (int)($_GET['id_usuario']     ?? 0);
$fecha_inicio = $_GET['fecha_inicio'] ?? '2000-01-01';
$fecha_fin    = $_GET['fecha_fin']    ?? date('Y-m-d');

if ($fecha_inicio > $fecha_fin) {
  [$fecha_inicio, $fecha_fin] = [$fecha_fin, $fecha_inicio];
}

// ==================== WHERE BASE ====================
$whereBase = " WHERE f.tipo = 3 ";
if ($division)    $whereBase .= " AND f.id_division = $division";
if ($subdivision) $whereBase .= " AND f.id_subdivision = $subdivision";
if ($ejecutor)    $whereBase .= " AND fqr.id_usuario = $ejecutor";

// ==================== HELPERS ====================
function buildDateWindows($start, $end, $chunk) {
  $out = [];
  $s = new DateTime($start);
  $e = new DateTime($end);
  while ($s <= $e) {
    $c = clone $s;
    $c->modify("+".($chunk-1)." days");
    if ($c > $e) $c = clone $e;
    $out[] = [$s->format('Y-m-d'), $c->format('Y-m-d')];
    $s = clone $c;
    $s->modify('+1 day');
  }
  return $out;
}
function to_upper($v) {
  if ($v === null || $v === '') return '';
  return mb_strtoupper(trim((string)$v), 'UTF-8');
}

// ======================================================================
// 1) DESCUBRIR COLUMNAS (PREGUNTAS + MULTI)
// ======================================================================
$whereCols = $whereBase
  . " AND fqr.created_at BETWEEN '$fecha_inicio 00:00:00' AND '$fecha_fin 23:59:59'";

$sqlCols = "
SELECT
  fp.question_text,
  fp.id_question_type,
  UPPER(TRIM(fqr.answer_text)) AS answer_text,
  MIN(fp.sort_order) AS sorder,
  COUNT(
    CASE WHEN fp.id_question_type = 7 AND fqr.answer_text <> '' THEN 1 END
  ) AS total_fotos,  
  MAX(CASE WHEN fqr.valor IS NOT NULL AND fqr.valor <> '0.00' AND fqr.valor <> 0 THEN 1 ELSE 0 END) AS has_valor
FROM formulario f
JOIN form_questions fp ON fp.id_formulario = f.id
JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
$whereCols
GROUP BY fp.question_text, fp.id_question_type, answer_text
ORDER BY sorder, fp.question_text, answer_text
";

$res = mysqli_query($conn, $sqlCols);
if (!$res) die(mysqli_error($conn));

$questions = [];
$hasValor  = [];
$multiCols = [];
$photoCols = [];

while ($r = mysqli_fetch_assoc($res)) {
  $qt   = $r['question_text'];
  $type = (int)$r['id_question_type'];
  $ans  = $r['answer_text'];

  if ($type === 3 && $ans !== '') {
    $multiCols[$qt][$ans] = [
      'has_price' => ((int)$r['has_valor'] === 1)
    ];
    continue;
  }

  if ($type === 7 && (int)$r['total_fotos'] > 0) {
    $photoCols[$qt] = max(
      $photoCols[$qt] ?? 0,
      (int)$r['total_fotos']
    );
    continue;
  }

  $questions[$qt] = true;
  $hasValor[$qt]  = ((int)$r['has_valor'] === 1);
}
mysqli_free_result($res);

$questions = array_keys($questions);

// ======================================================================
// 2) CABECERAS CSV
// ======================================================================
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"Encuesta_PIVOT_PRUEBA_" . date("Ymd_His") . ".csv\"");
echo "\xEF\xBB\xBF";

$staticHeaders = [
  'nombreCampana','nombreCanal','nombreDistrito','codigo_local','cuenta',
  'nombre_local','direccion_local','comuna','region','nombreZona',
  'usuario','fecha_respuesta'
];

$dynHeaders = [];
foreach ($questions as $q) {
  $dynHeaders[] = $q;
  if (!empty($hasValor[$q])) $dynHeaders[] = $q.'_VALOR';
}
foreach ($multiCols as $q => $ansList) {
  foreach ($ansList as $ans => $meta) {
    $dynHeaders[] = $ans;
    if (!empty($meta['has_price'])) {
      $dynHeaders[] = $ans . '_precio';
    }
  }
}
foreach ($photoCols as $q => $maxFotos) {
  for ($i = 1; $i <= $maxFotos; $i++) {
    $dynHeaders[] = $q . '_FOTO_' . $i;
  }
}


$out = fopen('php://output', 'w');
fputcsv($out, array_merge($staticHeaders, $dynHeaders), ';');

// ======================================================================
// 3) STREAMING DATA
// ======================================================================
$currentKey = null;
$currentRow = null;

$flush = function() use (&$currentRow, &$questions, &$hasValor, &$multiCols, &$photoCols, $out) {
  if (!$currentRow) return;

  $row = [
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
    $currentRow['fecha_respuesta']
  ];

  // ---------- Preguntas normales ----------
  foreach ($questions as $q) {
    $row[] = to_upper($currentRow['Q'][$q]['answers'][0] ?? '');
    if (!empty($hasValor[$q])) {
      $row[] = $currentRow['Q'][$q]['valores'][0] ?? '';
    }
  }

  // ---------- Selección múltiple ----------
  foreach ($multiCols as $q => $ansList) {
    foreach ($ansList as $ans => $meta) {

      // Binario
      $row[] = isset($currentRow['Q'][$q]['multi'][$ans]) ? '1' : '0';

      // Precio real
      if (!empty($meta['has_price'])) {
        $row[] = $currentRow['Q'][$q]['multi_precio'][$ans] ?? '';
      }
    }
  }

  // ---------- Fotos (tipo 7) ----------
  foreach ($photoCols as $q => $maxFotos) {
    $fotos = $currentRow['Q'][$q]['fotos'] ?? [];

    for ($i = 0; $i < $maxFotos; $i++) {
      $row[] = $fotos[$i] ?? ''; // rellena vacío si no existe
    }
  }

  fputcsv($out, $row, ';');
  $currentRow = null;
};

// ======================================================================
// 4) LOOP PRINCIPAL
// ======================================================================
foreach (buildDateWindows($fecha_inicio, $fecha_fin, $CHUNK_DAYS) as [$d1, $d2]) {

  $last_id = 0;
  $more = true;

  while ($more) {

    $sql = "
      SELECT
        fqr.id AS k_id,
        fqr.visita_id AS k_visita_id,          
        f.nombre AS nombreCampana,
        ca.nombre_canal AS nombreCanal,
        di.nombre_distrito AS nombreDistrito,
        l.codigo AS codigo_local,
        cu.nombre AS cuenta,
        l.nombre AS nombre_local,
        l.direccion AS direccion_local,
        c.comuna,
        r.region,
        z.nombre_zona AS nombreZona,
        CONCAT(u.nombre,' ',u.apellido) AS usuario,
        DATE(fqr.created_at) AS fecha_respuesta,
        fp.id_question_type AS preguntaTipo,
        fp.question_text AS pregunta,
        UPPER(TRIM(fqr.answer_text)) AS respuesta,
        CASE
          WHEN fqr.valor IS NOT NULL
           AND fqr.valor <> '0.00'
           AND fqr.valor <> 0
          THEN fqr.valor
          ELSE ''
        END AS precio
      FROM formulario f
      JOIN form_questions fp            ON fp.id_formulario = f.id
      JOIN form_question_responses fqr  ON fqr.id_form_question = fp.id
      JOIN usuario u                    ON u.id = fqr.id_usuario
      JOIN local l                      ON l.id = fqr.id_local
      JOIN canal ca                     ON ca.id = l.id_canal
      JOIN cuenta cu                    ON cu.id = l.id_cuenta
      JOIN distrito di                  ON di.id = l.id_distrito
      JOIN comuna c                     ON c.id = l.id_comuna
      JOIN zona z                       ON z.id = l.id_zona
      JOIN region r                     ON r.id = c.id_region
      $whereBase
        AND fqr.created_at BETWEEN '$d1 00:00:00' AND '$d2 23:59:59'
        AND fqr.id > $last_id
      ORDER BY fqr.visita_id, fqr.id
      LIMIT $BATCH_SIZE
    ";

    $res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
    if (!$res) {
      http_response_code(500);
      echo "ERROR SQL: " . mysqli_error($conn);
      exit;
    }

    $count = 0;

    while ($r = mysqli_fetch_assoc($res)) {
      $count++;
      $last_id = (int)$r['k_id'];

     //$key = $r['codigo_local'] . '|' . $r['fecha_respuesta']; agrupacion unica
     $key = $r['k_visita_id'];
     
      if ($key !== $currentKey) {
        $flush();
        $currentKey = $key;

        $currentRow = [
          'nombreCampana'   => $r['nombreCampana'],
          'nombreCanal'     => $r['nombreCanal'],
          'nombreDistrito'  => $r['nombreDistrito'],
          'codigo_local'    => $r['codigo_local'],
          'cuenta'          => $r['cuenta'],
          'nombre_local'    => $r['nombre_local'],
          'direccion_local' => $r['direccion_local'],
          'comuna'          => $r['comuna'],
          'region'          => $r['region'],
          'nombreZona'      => $r['nombreZona'],
          'usuario'         => $r['usuario'],
          'fecha_respuesta' => $r['fecha_respuesta'],
          'Q'               => []
        ];
      }

      $q = $r['pregunta'];

      if (!isset($currentRow['Q'][$q])) {
        $currentRow['Q'][$q] = [
          'answers'      => [],
          'valores'      => [],
          'multi'        => [],
          'multi_precio' => [],
          'fotos'        => []
        ];
      }

      if ((int)$r['preguntaTipo'] === 3) {

        if ($r['respuesta'] !== '') {
          $currentRow['Q'][$q]['multi'][$r['respuesta']] = true;

          if ($r['precio'] !== '' && (float)$r['precio'] > 1) {
            $currentRow['Q'][$q]['multi_precio'][$r['respuesta']] = $r['precio'];
          }
        }

      } elseif ((int)$r['preguntaTipo'] === 7) {

        if ($r['respuesta'] !== '') {
          $currentRow['Q'][$q]['fotos'][] = $r['respuesta'];
        }

      } else {

        if ($r['respuesta'] !== '') {
          $currentRow['Q'][$q]['answers'][] = $r['respuesta'];
        }

        if ($r['precio'] !== '') {
          $currentRow['Q'][$q]['valores'][] = $r['precio'];
        }
      }
    }

    mysqli_free_result($res);

    if ($count < $BATCH_SIZE) {
      $more = false;
    }
  }
}

// Flush final
$flush();
fclose($out);
exit;
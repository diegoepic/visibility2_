<?php
// Asegurar sesión activa antes de usar $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Sesión expirada");
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Conexión + helpers
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_panel_encuesta/panel_encuesta_helpers.php';

$debugId = panel_encuesta_request_id();
header('X-Request-Id: '.$debugId);

$user_div   = (int)($_SESSION['division_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

// ==== parámetros (POST preferido, fallback GET) ====
$SRC = $_POST ?: $_GET;
$csrf_token = $SRC['csrf_token'] ?? '';
if (!panel_encuesta_validate_csrf(is_string($csrf_token) ? $csrf_token : '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Token CSRF inválido.');
}

// WHERE + params centralizados (incluye qfilters, rango por fecha_fin, etc.)
list($whereSql, $types, $params, $metaFilters) =
    build_panel_encuesta_filters($empresa_id, $user_div, $SRC, [
        'foto_only'             => false,
        'enforce_date_fallback' => true,   // últimos 30 días si no hay fechas ni campaña
    ]);

if (!empty($metaFilters['range_risky_no_scope'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Rango demasiado amplio sin filtros adicionales. Acota fechas o selecciona campaña.');
}

// ====== stream CSV ======
$fname = 'panel_encuesta_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fwrite($out, "\xEF\xBB\xBF");

// Helper URL absoluta (dinámico según host)
function make_abs_url($path)
{
    if (!$path) {
        return $path;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    $p      = ($path[0] ?? '') === '/' ? $path : ('/' . $path);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'www.visibility.cl';
    return $scheme . '://' . $host . $p;
}

function csv_safe($value): string
{
    $v = (string)$value;
    if (preg_match('/^[=+\\-@]/', $v)) {
        return "'" . $v;
    }
    return $v;
}

// Límite de filas (respuestas) a exportar
$exportLimit = 50000;

// Query principal (mismo FROM/joins que panel_encuesta_data.php, pero trayendo claves para agrupar)
$sql = "
  SELECT
    v.id AS visita_id,
    fqr.id_local        AS id_local,
    fq.id               AS pregunta_id,

    -- fecha principal: fin de visita; si no hay, created_at
    CASE
      WHEN v.fecha_fin IS NOT NULL
        THEN DATE_FORMAT(v.fecha_fin, '%d/%m/%Y %H:%i:%s')
      ELSE DATE_FORMAT(fqr.created_at, '%d/%m/%Y %H:%i:%s')
    END AS fecha,

    f.nombre             AS campana,
    fq.question_text     AS pregunta,
    fq.id_question_type  AS tipo,
    COALESCE(o.option_text, fqr.answer_text) AS respuesta,
    fqr.valor,
    l.codigo             AS local_codigo,
    l.nombre             AS local_nombre,
    l.direccion          AS direccion,
    c.nombre             AS cadena,
    d.nombre_distrito    AS distrito,
    jv.nombre            AS jefe_venta,
    u.usuario            AS usuario
  FROM form_question_responses fqr
  JOIN form_questions fq  ON fq.id = fqr.id_form_question
  JOIN formulario f       ON f.id  = fq.id_formulario
  JOIN local l            ON l.id  = fqr.id_local
  LEFT JOIN cadena c      ON c.id  = l.id_cadena
  LEFT JOIN distrito d    ON d.id  = l.id_distrito
  LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
  JOIN usuario u          ON u.id  = fqr.id_usuario
  LEFT JOIN form_question_options o ON o.id = fqr.id_option
  JOIN visita v           ON v.id  = fqr.visita_id
  $whereSql
  ORDER BY
    v.fecha_fin DESC,
    fqr.visita_id DESC,
    CASE WHEN fq.id_question_type = 7 THEN 2 ELSE 1 END,
    fq.id,
    fqr.created_at DESC,
    fqr.id DESC
  LIMIT $exportLimit
";

$st = $conn->prepare($sql);
if ($types) {
    $st->bind_param($types, ...$params);
}

$t0 = microtime(true);
$st->execute();
$rs = $st->get_result();

$rowsCount  = 0; // respuestas leídas desde la BD
$visitCount = 0; // visitas únicas exportadas

// ==== helpers internos ====
function norm_text_key($text, $tipoInt)
{
    $txt = trim((string)$text);
    if (function_exists('mb_strtolower')) {
        $txt = mb_strtolower($txt, 'UTF-8');
    } else {
        $txt = strtolower($txt);
    }
    // agrupamos por texto normalizado + tipo
    return $txt . '|' . (int)$tipoInt;
}

// ==== Agrupación por visita ====
// $questions: catálogo de preguntas lógicas presentes en el export
//   qKey => ['key'=>qKey, 'text'=>..., 'tipo'=>int]
$questions = [];

// $visits: visita_id => [
//   'fecha','campana','local_codigo','local_nombre','direccion','cadena',
//   'distrito','jefe_venta','usuario','answers' => [ qKey => [val1,val2,...] ]
// ]
$visits = [];

while ($r = $rs->fetch_assoc()) {
    $rowsCount++;

    $visitaId = (int)($r['visita_id'] ?? 0);
    if ($visitaId <= 0) {
        // Sin visita no tiene sentido en este modo agrupado
        continue;
    }

    // Inicializar grupo de visita
    if (!isset($visits[$visitaId])) {
        $visits[$visitaId] = [
            'fecha'        => $r['fecha'],
            'campana'      => $r['campana'],
            'local_codigo' => $r['local_codigo'],
            'local_nombre' => $r['local_nombre'],
            'direccion'    => $r['direccion'],
            'cadena'       => $r['cadena'],
            'distrito'     => $r['distrito'],
            'jefe_venta'   => $r['jefe_venta'],
            'usuario'      => $r['usuario'],
            'answers'      => [],
        ];
    }

    $pregId   = (int)($r['pregunta_id'] ?? 0);
    $tipoInt  = (int)($r['tipo'] ?? 0);
    $pregText = (string)($r['pregunta'] ?? '');

    if ($pregId <= 0) {
        continue;
    }

    // ==== clave lógica de pregunta (unifica clones por texto + tipo) ====
    $qKey = norm_text_key($pregText, $tipoInt);

    if (!isset($questions[$qKey])) {
        $questions[$qKey] = [
            'key'  => $qKey,
            'text' => $pregText,
            'tipo' => $tipoInt,
        ];
    }

    // Determinar valor que irá a la celda
    $val = '';
    if ($tipoInt === 5 && $r['valor'] !== null && $r['valor'] !== '') {
        // Numérico: usamos valor numérico
        $val = (string)$r['valor'];
    } else {
        $val = (string)($r['respuesta'] ?? '');
        if ($tipoInt === 7 && $val !== '') {
            // Foto: URL absoluta
            $val = make_abs_url($val);
        }
    }

    if ($val === '') {
        continue;
    }

    if (!isset($visits[$visitaId]['answers'][$qKey])) {
        $visits[$visitaId]['answers'][$qKey] = [];
    }
    $visits[$visitaId]['answers'][$qKey][] = $val;
}

$st->close();

// ==== Construir cabecera dinámica ====
// Columnas fijas por visita
$headers = [
    'Fecha',
    'Campaña',
    'Cód. Local',
    'Local',
    'Dirección',
    'Cadena',
    'Distrito',
    'Jefe de venta',
    'Usuario',
];

// Ordenamos preguntas por texto (y luego por tipo) para tener columnas estables
if (!empty($questions)) {
    uasort($questions, function ($a, $b) {
        $t1 = trim((string)$a['text']);
        $t2 = trim((string)$b['text']);

        if (function_exists('mb_strtolower')) {
            $t1l = mb_strtolower($t1, 'UTF-8');
            $t2l = mb_strtolower($t2, 'UTF-8');
        } else {
            $t1l = strtolower($t1);
            $t2l = strtolower($t2);
        }

        if ($t1l === $t2l) {
            // Si el texto es igual, ordenamos por tipo para estabilidad
            return ($a['tipo'] <=> $b['tipo']);
        }
        return $t1l <=> $t2l;
    });

    foreach ($questions as $q) {
        $label = trim((string)$q['text']);
        if ($label === '') {
            $label = 'Pregunta';
        }
        $headers[] = $label;
    }
}

// Escribimos cabecera (aunque no haya datos, para que el CSV tenga estructura)
fputcsv($out, array_map('csv_safe', $headers));

// ==== Escribir filas por visita ====
foreach ($visits as $visit) {
    $row = [
        $visit['fecha'],
        $visit['campana'],
        $visit['local_codigo'],
        $visit['local_nombre'],
        $visit['direccion'],
        $visit['cadena'],
        $visit['distrito'],
        $visit['jefe_venta'],
        $visit['usuario'],
    ];

    // Por cada pregunta “lógica” agregamos la celda correspondiente
    foreach ($questions as $qKey => $q) {
        if (!empty($visit['answers'][$qKey])) {
            // Unificamos valores por pregunta (multi-respuesta, varias fotos, etc.)
            $vals = $visit['answers'][$qKey];

            // Limpiar duplicados y vacíos
            $vals = array_filter(
                array_unique(array_map('strval', $vals)),
                function ($s) { return $s !== ''; }
            );

            $row[] = implode(' | ', $vals);
        } else {
            $row[] = '';
        }
    }

    fputcsv($out, array_map('csv_safe', $row));
    $visitCount++;
}

fclose($out);

$duration = microtime(true) - $t0;
$metaFilters['duration_sec']  = $duration;
$metaFilters['export_limit']  = $exportLimit;
$metaFilters['rows']          = $rowsCount;    // respuestas leídas
$metaFilters['visits']        = $visitCount;   // visitas exportadas
$metaFilters['truncated']     = ($rowsCount >= $exportLimit);
$metaFilters['csv_mode']      = 'grouped_by_visit_dedup_by_text';

if (function_exists('log_panel_encuesta_query')) {
    log_panel_encuesta_query($conn, 'csv', $rowsCount, $metaFilters);
}

exit;

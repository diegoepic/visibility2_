<?php
// Asegurar sesión activa antes de usar $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Sesión expirada',
        'error_code' => 'session_expired',
        'debug_id' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Conexión (por si este script se llama directo)
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once __DIR__ . '/panel_encuesta_helpers.php';
header('Content-Type: application/json; charset=UTF-8');
$debugId = panel_encuesta_request_id();
header('X-Request-Id: '.$debugId);

$t0 = microtime(true);

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

$DEFAULT_RANGE_DAYS = 7; // mismo criterio que panel_encuesta_data.php

$mode_raw = $_GET['mode'] ?? '';
$mode     = in_array($mode_raw, ['exact', 'set', 'vset'], true) ? $mode_raw : 'exact';
$qid_raw  = $_GET['id'] ?? '';
$tipo     = (int)($_GET['tipo'] ?? 0);

$division     = (int)($_GET['division'] ?? 0);
$subdivision  = (int)($_GET['subdivision'] ?? 0);
$form_id      = (int)($_GET['form_id'] ?? 0);
$clase_tipo   = (int)($_GET['clase_tipo'] ?? 0);     // 0 => (1,3)
$desde        = trim($_GET['desde'] ?? '');
$hasta        = trim($_GET['hasta'] ?? '');
$distrito     = (int)($_GET['distrito'] ?? 0);
$jv           = (int)($_GET['jv'] ?? 0);
$usuario      = (int)($_GET['usuario'] ?? 0);
$codigo       = trim($_GET['codigo'] ?? '');
$csrf_token   = $_GET['csrf_token'] ?? '';

if (!panel_encuesta_validate_csrf(is_string($csrf_token) ? $csrf_token : '')) {
    http_response_code(403);
    panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId);
    exit;
}

// Validaciones básicas de ID
if ($mode !== 'vset' && (int)$qid_raw <= 0) {
    http_response_code(400);
    panel_encuesta_json_response('error', [], 'id inválido', 'invalid_id', $debugId);
    exit;
}
if ($mode === 'vset') {
    $qid_raw = strtolower(trim((string)$qid_raw));
    if (!preg_match('/^[a-f0-9]{32}$/', $qid_raw)) {
        http_response_code(400);
        panel_encuesta_json_response('error', [], 'hash inválido', 'invalid_hash', $debugId);
        exit;
    }
}

// Rango por defecto últimos N días si no hay fechas ni campaña
$appliedDefaultRange = false;
if ($desde === '' && $hasta === '' && $form_id === 0) {
    $hasta = date('Y-m-d');
    $desde = date('Y-m-d', strtotime('-'.($DEFAULT_RANGE_DAYS - 1).' days'));
    $appliedDefaultRange = true;
}
$desdeFull = $desde ? ($desde . ' 00:00:00') : null;
$hastaFull = $hasta ? (date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00') : null;

// ===== WHERE común (idéntico criterio de ámbito que panel_encuesta_data.php) =====
$where  = [];
$types  = '';
$params = [];

$where[]  = 'f.id_empresa=?';
$types   .= 'i';
$params[] = $empresa_id;

// División
if ($is_mc) {
    if ($division > 0) {
        $where[]  = 'f.id_division=?';
        $types   .= 'i';
        $params[] = $division;
    }
} else {
    $where[]  = 'f.id_division=?';
    $types   .= 'i';
    $params[] = $user_div;
}

// Subdivisión
if ($subdivision > 0) {
    $where[]  = 'f.id_subdivision=?';
    $types   .= 'i';
    $params[] = $subdivision;
}

// Campaña
if ($form_id > 0) {
    $where[]  = 'f.id=?';
    $types   .= 'i';
    $params[] = $form_id;
}

// Tipo de formulario (programadas vs ruta IPT)
if (in_array($clase_tipo, [1, 3], true)) {
    $where[]  = 'f.tipo=?';
    $types   .= 'i';
    $params[] = $clase_tipo;
}

// Solo visitas finalizadas (mismo criterio que el listado principal)
$where[] = 'v.fecha_fin IS NOT NULL';

// Fechas sobre el FIN de visita (no sobre created_at)
if ($desdeFull) {
    $where[]  = 'v.fecha_fin >= ?';
    $types   .= 's';
    $params[] = $desdeFull;
}
if ($hastaFull) {
    $where[]  = 'v.fecha_fin < ?';
    $types   .= 's';
    $params[] = $hastaFull;
}

// Filtros de local/usuario
if ($distrito > 0) {
    $where[]  = 'l.id_distrito=?';
    $types   .= 'i';
    $params[] = $distrito;
}
if ($jv > 0) {
    $where[]  = 'l.id_jefe_venta=?';
    $types   .= 'i';
    $params[] = $jv;
}
if ($usuario > 0) {
    $where[]  = 'u.id=?';
    $types   .= 'i';
    $params[] = $usuario;
}
if ($codigo !== '') {
    $where[]  = 'l.codigo=?';
    $types   .= 's';
    $params[] = $codigo;
}

// Alcance por pregunta (exact/set/vset)
if ($mode === 'exact') {
    $where[]  = 'fq.id=?';
    $types   .= 'i';
    $params[] = (int)$qid_raw;
} elseif ($mode === 'set') {
    $where[]  = 'fq.id_question_set_question=?';
    $types   .= 'i';
    $params[] = (int)$qid_raw;
} else {
    // vset: usar v_signature (mismo criterio que ajax_preguntas_lookup y ajax_pregunta_meta)
    $where[]  = "((fq.id_question_set_question IS NULL OR fq.id_question_set_question = 0) AND fq.v_signature = ?)";
    $types   .= 's';
    $params[] = $qid_raw;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$out = [
    'total'   => 0,
    'buckets' => [],
    'numeric' => null,
];

// =====================================================================
// Tipos
// =====================================================================
if ($tipo === 1) {
    // Sí/No robusto: case-insensitive + accent-insensitive, y/o valor binario
    $label_ci = "LOWER(TRIM(COALESCE(o.option_text, fqr.answer_text))) COLLATE utf8_general_ci";

    $sql = "
      SELECT
        SUM(CASE WHEN ($label_ci = 'si' OR fqr.valor = 1) THEN 1 ELSE 0 END) AS si_cnt,
        SUM(CASE WHEN ($label_ci = 'no' OR fqr.valor = 0) THEN 1 ELSE 0 END) AS no_cnt,
        COUNT(*) AS total
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN formulario f      ON f.id  = fq.id_formulario
      JOIN local l           ON l.id  = fqr.id_local
      JOIN usuario u         ON u.id  = fqr.id_usuario
      JOIN visita v          ON v.id  = fqr.visita_id
 LEFT JOIN form_question_options o ON o.id  = fqr.id_option
      $whereSql
    ";

    $st = $conn->prepare($sql);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $st->bind_result($si, $no, $tot);
    $st->fetch();
    $st->close();

    $out['total']   = (int)$tot;
    $out['buckets'] = [
        ['key' => '1', 'label' => 'Sí',  'count' => (int)$si],
        ['key' => '0', 'label' => 'No',  'count' => (int)$no],
    ];

} elseif ($tipo === 2 || $tipo === 3) {
    // Única / Múltiple: agrupar por TEXTO normalizado (colación accent-insensitive)
    // Evita duplicados cuando la misma etiqueta aparece con distintos IDs u ortografías con tildes.
    $label_raw = "TRIM(COALESCE(o.option_text, fqr.answer_text))";
    $key_norm  = "LOWER($label_raw) COLLATE utf8_general_ci";

    $sql = "
      SELECT label, cnt
        FROM (
          SELECT MIN(lbl) AS label, COUNT(*) AS cnt
            FROM (
              SELECT $key_norm AS k, $label_raw AS lbl
                FROM form_question_responses fqr
                JOIN form_questions fq ON fq.id = fqr.id_form_question
                JOIN formulario f      ON f.id  = fq.id_formulario
                JOIN local l           ON l.id  = fqr.id_local
                JOIN usuario u         ON u.id  = fqr.id_usuario
                JOIN visita v          ON v.id  = fqr.visita_id
           LEFT JOIN form_question_options o ON o.id  = fqr.id_option
                $whereSql
                  AND $label_raw IS NOT NULL
                  AND $label_raw <> ''
            ) t
           GROUP BY k
        ) agg
       ORDER BY cnt DESC, label
       LIMIT 100
    ";

    $st = $conn->prepare($sql);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rs  = $st->get_result();
    $sum = 0;
    $b   = [];

    while ($r = $rs->fetch_assoc()) {
        $cnt = (int)$r['cnt'];
        $b[] = [
            'key'   => $r['label'],
            'label' => $r['label'],
            'count' => $cnt,
        ];
        $sum += $cnt;
    }
    $st->close();

    $out['total']   = $sum;
    $out['buckets'] = $b;

} elseif ($tipo === 5) {
    // Numérico: estadísticos básicos
    $sql = "
      SELECT COUNT(*) AS cnt,
             MIN(valor) AS vmin,
             MAX(valor) AS vmax,
             AVG(valor) AS vavg
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN formulario f      ON f.id  = fq.id_formulario
        JOIN local l           ON l.id  = fqr.id_local
        JOIN usuario u         ON u.id  = fqr.id_usuario
        JOIN visita v          ON v.id  = fqr.visita_id
       $whereSql
         AND fqr.valor IS NOT NULL
    ";

    $st = $conn->prepare($sql);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $st->bind_result($cnt, $vmin, $vmax, $vavg);
    $st->fetch();
    $st->close();

    $out['total']  = (int)$cnt;
    $out['numeric'] = [
        'count' => (int)$cnt,
        'min'   => $vmin !== null ? (float)$vmin : null,
        'max'   => $vmax !== null ? (float)$vmax : null,
        'avg'   => $vavg !== null ? (float)$vavg : null,
    ];

} elseif ($tipo === 7) {
    // Foto: con/sin foto (usa answer_text no vacío)
    $sql = "
      SELECT
        SUM(CASE WHEN (fqr.answer_text IS NOT NULL AND fqr.answer_text <> '') THEN 1 ELSE 0 END) AS con_foto,
        SUM(CASE WHEN (fqr.answer_text IS NULL OR fqr.answer_text = '') THEN 1 ELSE 0 END)      AS sin_foto,
        COUNT(*) AS total
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN formulario f      ON f.id  = fq.id_formulario
        JOIN local l           ON l.id  = fqr.id_local
        JOIN usuario u         ON u.id  = fqr.id_usuario
        JOIN visita v          ON v.id  = fqr.visita_id
       $whereSql
    ";

    $st = $conn->prepare($sql);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $st->bind_result($cf, $sf, $tot);
    $st->fetch();
    $st->close();

    $out['total']   = (int)$tot;
    $out['buckets'] = [
        ['key' => '1', 'label' => 'Con foto', 'count' => (int)$cf],
        ['key' => '0', 'label' => 'Sin foto', 'count' => (int)$sf],
    ];

} else {
    // Fallback: total simple
    $sql = "
      SELECT COUNT(*) AS cnt
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN formulario f      ON f.id  = fq.id_formulario
        JOIN local l           ON l.id  = fqr.id_local
        JOIN usuario u         ON u.id  = fqr.id_usuario
        JOIN visita v          ON v.id  = fqr.visita_id
   LEFT JOIN form_question_options o ON o.id  = fqr.id_option
       $whereSql
    ";

    $st = $conn->prepare($sql);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $st->bind_result($cnt);
    $st->fetch();
    $st->close();

    $out['total'] = (int)$cnt;
}

// Meta de rango por defecto (para debug/diagnóstico)
$out['meta'] = [
    'default_range' => [
        'applied' => $appliedDefaultRange ? 1 : 0,
        'days'    => $DEFAULT_RANGE_DAYS
    ]
];

// Tiempo de consulta
$ms = (microtime(true) - $t0) * 1000;
header('X-QueryTime-ms: '.number_format($ms, 1, '.', ''));

echo json_encode([
    'status' => 'ok',
    'data' => $out,
    'message' => '',
    'error_code' => null,
    'debug_id' => $debugId
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

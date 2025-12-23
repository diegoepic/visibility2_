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

// Conexión
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once __DIR__ . '/panel_encuesta_helpers.php';
header('Content-Type: application/json; charset=UTF-8');
$debugId = panel_encuesta_request_id();
header('X-Request-Id: '.$debugId);

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

$q           = trim($_GET['q'] ?? '');
$division    = (int)($_GET['division'] ?? 0);
$subdivision = (int)($_GET['subdivision'] ?? 0);
$tipo        = (int)($_GET['tipo'] ?? 0);      // 0 => (1,3)
$form_id     = (int)($_GET['form_id'] ?? 0);
$global      = (int)($_GET['global'] ?? 0);
$csrf_token  = $_GET['csrf_token'] ?? '';

if (!panel_encuesta_validate_csrf(is_string($csrf_token) ? $csrf_token : '')) {
    http_response_code(403);
    panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId);
    exit;
}

// Si form_id = 0, forzamos modo global (por set / vset)
if ($form_id === 0) {
    $global = 1;
}

// ===== Filtros base =====
$where  = [];
$types  = '';
$params = [];

$where[]  = 'f.id_empresa=?';
$types   .= 'i';
$params[] = $empresa_id;

// Excluir campañas/preguntas eliminadas lógicamente
$where[] = 'f.deleted_at IS NULL';
$where[] = 'fq.deleted_at IS NULL';

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

if ($subdivision > 0) {
    $where[]  = 'f.id_subdivision=?';
    $types   .= 'i';
    $params[] = $subdivision;
}

if (in_array($tipo, [1, 3], true)) {
    $where[]  = 'f.tipo=?';
    $types   .= 'i';
    $params[] = $tipo;
} else {
    $where[] = 'f.tipo IN (1,3)';
}

// En modo NO global, acotamos por campaña concreta
if (!$global && $form_id > 0) {
    $where[]  = 'f.id=?';
    $types   .= 'i';
    $params[] = $form_id;
}

// ===== Filtro por tipo (en texto de búsqueda): "type:foto|num|texto|single|multi|bool" =====
$typeFilter = null;
if ($q !== '') {
    if (preg_match('~(?:type|tipo):(foto|num|texto|single|multi|bool)~i', $q, $m)) {
        $map        = [
            'foto'   => 7,
            'num'    => 5,
            'texto'  => 4,
            'single' => 2,
            'multi'  => 3,
            'bool'   => 1,
        ];
        $typeFilter = $map[strtolower($m[1])] ?? null;
        // Quitamos el token del query libre
        $q = trim(str_replace($m[0], '', $q));
    }
}

// Búsqueda por texto utilizando la columna normalizada (question_text_norm)
// Esto hace la búsqueda más robusta (case-insensitive/control de espacios).
if ($q !== '') {
    $qNorm    = mb_strtolower($q, 'UTF-8');
    $where[]  = 'fq.question_text_norm LIKE ?';
    $types   .= 's';
    $params[] = '%' . $qNorm . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ===== Query =====
if ($global) {
    // GLOBAL: devuelve sets y vsets (huérfanas) con conteo de respuestas y tipo mayoritario.
    // Usamos fq.v_signature para vset; los "id" devueltos son:
    //   - set : "123"          (id de question_set_questions)
    //   - vset: "v:<hash_md5>" (firma de texto+tipo)
    $sql = "
      -- SET (preguntas con set)
      SELECT 
        CAST(qsq.id AS CHAR) AS id,
        COALESCE(qsq.question_text, MIN(fq.question_text)) AS text,
        NULL AS campana,
        COUNT(fqr.id) AS cnt,
        (
          SELECT fq2.id_question_type
            FROM form_questions fq2
           WHERE fq2.id_question_set_question = qsq.id
             AND fq2.deleted_at IS NULL
           GROUP BY fq2.id_question_type
           ORDER BY COUNT(*) DESC
           LIMIT 1
        ) AS tipo,
        'set' AS mode
      FROM form_questions fq
      JOIN formulario f       ON f.id = fq.id_formulario
      JOIN question_set_questions qsq
           ON qsq.id = fq.id_question_set_question
      LEFT JOIN form_question_responses fqr
           ON fqr.id_form_question = fq.id
      $whereSql
        AND fq.id_question_set_question IS NOT NULL
      GROUP BY qsq.id

      UNION ALL

      -- VSET (preguntas sin set => agrupadas por v_signature)
      SELECT 
        CONCAT('v:', fq.v_signature) AS id,
        MIN(fq.question_text)        AS text,
        NULL                         AS campana,
        COUNT(fqr.id)                AS cnt,
        (
          SELECT fq2.id_question_type
            FROM form_questions fq2
           WHERE fq2.v_signature = fq.v_signature
             AND fq2.id_question_set_question IS NULL
             AND fq2.deleted_at IS NULL
           GROUP BY fq2.id_question_type
           ORDER BY COUNT(*) DESC
           LIMIT 1
        ) AS tipo,
        'vset' AS mode
      FROM form_questions fq
      JOIN formulario f       ON f.id = fq.id_formulario
      LEFT JOIN form_question_responses fqr
           ON fqr.id_form_question = fq.id
      $whereSql
        AND fq.id_question_set_question IS NULL
      GROUP BY fq.v_signature

      ORDER BY cnt DESC, text
      LIMIT 50
    ";

    $typesBind  = $types;
    $paramsBind = $params;
} else {
    // NO GLOBAL: devolvemos las preguntas concretas de una campaña (form_id),
    // agrupadas igual que en el global, pero además informando la campaña.
    $sql = "
      -- SET de la campaña específica
      SELECT 
        CAST(qsq.id AS CHAR) AS id,
        COALESCE(qsq.question_text, MIN(fq.question_text)) AS text,
        f.nombre AS campana,
        COUNT(fqr.id) AS cnt,
        (
          SELECT fq2.id_question_type
            FROM form_questions fq2
           WHERE fq2.id_question_set_question = qsq.id
             AND fq2.deleted_at IS NULL
           GROUP BY fq2.id_question_type
           ORDER BY COUNT(*) DESC
           LIMIT 1
        ) AS tipo,
        'set' AS mode
      FROM form_questions fq
      JOIN formulario f       ON f.id = fq.id_formulario
      JOIN question_set_questions qsq
           ON qsq.id = fq.id_question_set_question
      LEFT JOIN form_question_responses fqr
           ON fqr.id_form_question = fq.id
      $whereSql
        AND fq.id_question_set_question IS NOT NULL
      GROUP BY qsq.id, f.id

      UNION ALL

      -- VSET de la campaña específica
      SELECT 
        CONCAT('v:', fq.v_signature) AS id,
        MIN(fq.question_text)        AS text,
        f.nombre                     AS campana,
        COUNT(fqr.id)                AS cnt,
        (
          SELECT fq2.id_question_type
            FROM form_questions fq2
           WHERE fq2.v_signature = fq.v_signature
             AND fq2.id_question_set_question IS NULL
             AND fq2.deleted_at IS NULL
           GROUP BY fq2.id_question_type
           ORDER BY COUNT(*) DESC
           LIMIT 1
        ) AS tipo,
        'vset' AS mode
      FROM form_questions fq
      JOIN formulario f       ON f.id = fq.id_formulario
      LEFT JOIN form_question_responses fqr
           ON fqr.id_form_question = fq.id
      $whereSql
        AND fq.id_question_set_question IS NULL
      GROUP BY fq.v_signature, f.id

      ORDER BY cnt DESC, text
      LIMIT 50
    ";

    $typesBind  = $types;
    $paramsBind = $params;
}

// ===== Ejecutar =====
$st = $conn->prepare($sql);
if ($typesBind) {
    $st->bind_param($typesBind, ...$paramsBind);
}
$st->execute();
$rs = $st->get_result();

$out = [];
while ($r = $rs->fetch_assoc()) {
    $out[] = [
        'id'      => $r['id'],                       // 'v:<hash>' o num (string)
        'text'    => $r['text'],
        'campana' => $r['campana'] ?? null,
        'count'   => (int)($r['cnt'] ?? 0),
        'tipo'    => isset($r['tipo']) ? (int)$r['tipo'] : null,
        'mode'    => $r['mode'],
    ];
}
$st->close();

echo json_encode([
    'status' => 'ok',
    'data' => $out,
    'message' => '',
    'error_code' => null,
    'debug_id' => $debugId
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
<?php
// Asegurar sesión activa antes de usar $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Sesión expirada']);
    exit;
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=UTF-8');

// Conexión (por si este script se invoca directo)
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

/**
 * Parámetros:
 * - mode: 'exact' | 'set' | 'vset'
 * - id:   (int) fq.id        si mode=exact
 *         (int) qset.id      si mode=set  (question_set_questions.id)
 *         (md5) firma string si mode=vset (huérfanas; coincide con v_signature)
 */
$mode        = strtolower(trim($_GET['mode'] ?? 'exact'));
$idParam     = $_GET['id'] ?? null;
$division    = (int)($_GET['division'] ?? 0);
$subdivision = (int)($_GET['subdivision'] ?? 0);
$tipo_scope  = (int)($_GET['tipo'] ?? 0);   // 0 => (1,3)
$form_id     = (int)($_GET['form_id'] ?? 0);

if (!in_array($mode, ['exact', 'set', 'vset'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'mode inválido']);
    exit;
}
if ($mode !== 'vset' && (int)$idParam <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id inválido']);
    exit;
}

// WHERE común de seguridad/ámbito
$where  = [];
$types  = '';
$params = [];

$where[]  = 'f.id_empresa=?';
$types   .= 'i';
$params[] = $empresa_id;

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
if (in_array($tipo_scope, [1, 3], true)) {
    $where[]  = 'f.tipo=?';
    $types   .= 'i';
    $params[] = $tipo_scope;
} else {
    $where[] = 'f.tipo IN (1,3)';
}

// Solo acotamos por formulario cuando es modo exact (pregunta concreta)
if ($form_id > 0 && $mode === 'exact') {
    $where[]  = 'f.id=?';
    $types   .= 'i';
    $params[] = $form_id;
}

$whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

$out = [
    'mode'        => $mode,
    'id'          => null,
    'tipo'        => null,
    'tipo_texto'  => null,
    'has_options' => false,
    'options'     => [],
    'supports'    => ['text' => false, 'numeric' => false, 'photo' => false],
];

function tipoTexto($t) {
    $t = (int)$t;
    return [
        1 => 'Sí/No',
        2 => 'Selección única',
        3 => 'Selección múltiple',
        4 => 'Texto',
        5 => 'Numérico',
        6 => 'Fecha',
        7 => 'Foto',
    ][$t] ?? 'Otro';
}

// ---------------------------------------------------------------------
// vset: huérfanas agrupadas por firma (v_signature)
// ---------------------------------------------------------------------
if ($mode === 'vset') {
    $hash = strtolower(trim((string)$idParam));
    if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
        http_response_code(400);
        echo json_encode(['error' => 'hash inválido']);
        exit;
    }

    // tipo mayoritario para la firma (usa v_signature, que corresponde al md5 texto+tipo)
    $sql = "
      SELECT fq.id_question_type AS tipo, COUNT(*) c
        FROM form_questions fq
        JOIN formulario f ON f.id = fq.id_formulario
       WHERE (fq.id_question_set_question IS NULL OR fq.id_question_set_question = 0)
         AND fq.v_signature = ?
         $whereSql
       GROUP BY fq.id_question_type
       ORDER BY c DESC
       LIMIT 1
    ";
    $st      = $conn->prepare($sql);
    $typesT  = 's' . $types;
    $paramsT = array_merge([$hash], $params);
    $st->bind_param($typesT, ...$paramsT);
    $st->execute();
    $rs  = $st->get_result();
    $row = $rs->fetch_assoc();
    $st->close();

    $tipo = (int)($row['tipo'] ?? 4);

    // Opciones distintas declaradas en las preguntas huérfanas (form_question_options)
    $opts = [];
    $sql  = "
      SELECT DISTINCT fo.option_text AS text
        FROM form_questions fq
        JOIN formulario f ON f.id = fq.id_formulario
        JOIN form_question_options fo ON fo.id_form_question = fq.id
       WHERE (fq.id_question_set_question IS NULL OR fq.id_question_set_question = 0)
         AND fq.v_signature = ?
         $whereSql
       ORDER BY text
    ";
    $st = $conn->prepare($sql);
    $st->bind_param($typesT, ...$paramsT);
    $st->execute();
    $rs = $st->get_result();
    while ($o = $rs->fetch_assoc()) {
        if ($o['text'] !== '') {
            $opts[] = ['id' => null, 'text' => $o['text']];
        }
    }
    $st->close();

    // Si no hay opciones y es Sí/No, inyectamos default
    if (empty($opts) && $tipo === 1) {
        $opts = [
            ['id' => 1, 'text' => 'Sí'],
            ['id' => 0, 'text' => 'No'],
        ];
    }

    $out['id']                 = $hash;
    $out['tipo']               = $tipo;
    $out['tipo_texto']         = tipoTexto($tipo);
    $out['supports']['text']   = ($tipo === 4);
    $out['supports']['numeric']= ($tipo === 5);
    $out['supports']['photo']  = ($tipo === 7);
    $out['has_options']        = count($opts) > 0;
    $out['options']            = $opts;

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// exact: pregunta concreta (form_questions.id)
// ---------------------------------------------------------------------
if ($mode === 'exact') {
    $id  = (int)$idParam;
    $sql = "
      SELECT fq.id,
             fq.id_question_type       AS tipo,
             fq.id_question_set_question AS qset_id
        FROM form_questions fq
        JOIN formulario f ON f.id = fq.id_formulario
       WHERE fq.id = ?
         $whereSql
       LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $t  = 'i' . $types;
    $p  = array_merge([$id], $params);
    $st->bind_param($t, ...$p);
    $st->execute();
    $rs   = $st->get_result();
    $qrow = $rs->fetch_assoc();
    $st->close();

    if (!$qrow) {
        http_response_code(404);
        echo json_encode(['error' => 'Pregunta no encontrada']);
        exit;
    }

    $out['id']                = (int)$id;
    $out['tipo']              = (int)$qrow['tipo'];
    $out['tipo_texto']        = tipoTexto($out['tipo']);
    $out['supports']['text']  = ($out['tipo'] === 4);
    $out['supports']['numeric'] = ($out['tipo'] === 5);
    $out['supports']['photo'] = ($out['tipo'] === 7);

    // 1) Opciones desde set si hay (question_set_options)
    $gotOptions = false;
    $qset_id    = (int)($qrow['qset_id'] ?? 0);

    if ($qset_id > 0) {
        $sql = "
          SELECT id, option_text AS text
            FROM question_set_options
           WHERE id_question_set_question = ?
           ORDER BY sort_order, id
        ";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $qset_id);
        $st->execute();
        $rs = $st->get_result();
        while ($o = $rs->fetch_assoc()) {
            $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
        }
        $st->close();
        $gotOptions = count($out['options']) > 0;
    }

    // 2) Si no hay opciones de set, usamos form_question_options
    if (!$gotOptions) {
        $sql = "
          SELECT id, option_text AS text
            FROM form_question_options
           WHERE id_form_question = ?
           ORDER BY sort_order, id
        ";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $id);
        $st->execute();
        $rs = $st->get_result();
        while ($o = $rs->fetch_assoc()) {
            $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
        }
        $st->close();
    }

    // 3) Default para Sí/No si no hay opciones
    if (empty($out['options']) && $out['tipo'] === 1) {
        $out['options'] = [
            ['id' => 1, 'text' => 'Sí'],
            ['id' => 0, 'text' => 'No'],
        ];
    }

    $out['has_options'] = count($out['options']) > 0;

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// set: pregunta por set (question_set_questions.id, modo global)
// ---------------------------------------------------------------------
if ($mode === 'set') {
    $id = (int)$idParam;

    // ¿existe el set en el ámbito?
    $sql = "
      SELECT fq.id_question_set_question AS qset_id,
             fq.id_question_type        AS tipo
        FROM form_questions fq
        JOIN formulario f ON f.id = fq.id_formulario
       WHERE fq.id_question_set_question = ?
         $whereSql
       LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $t  = 'i' . $types;
    $p  = array_merge([$id], $params);
    $st->bind_param($t, ...$p);
    $st->execute();
    $rs  = $st->get_result();
    $any = $rs->fetch_assoc();
    $st->close();

    if (!$any) {
        http_response_code(404);
        echo json_encode(['error' => 'Set de pregunta no encontrado en el ámbito']);
        exit;
    }

    // Tipo mayoritario dentro del set (por si el mismo set se usa con distintos tipos en campañas antiguas)
    $sql = "
      SELECT fq.id_question_type AS tipo, COUNT(*) c
        FROM form_questions fq
        JOIN formulario f ON f.id = fq.id_formulario
       WHERE fq.id_question_set_question = ?
         $whereSql
       GROUP BY fq.id_question_type
       ORDER BY c DESC
       LIMIT 1
    ";
    $st = $conn->prepare($sql);
    $st->bind_param($t, ...$p);
    $st->execute();
    $rs      = $st->get_result();
    $rowTipo = $rs->fetch_assoc();
    $st->close();

    $out['id']                 = (int)$id;
    $out['tipo']               = (int)($rowTipo['tipo'] ?? $any['tipo'] ?? 4);
    $out['tipo_texto']         = tipoTexto($out['tipo']);
    $out['supports']['text']   = ($out['tipo'] === 4);
    $out['supports']['numeric']= ($out['tipo'] === 5);
    $out['supports']['photo']  = ($out['tipo'] === 7);

    // Opciones desde question_set_options
    $sql = "
      SELECT id, option_text AS text
        FROM question_set_options
       WHERE id_question_set_question = ?
       ORDER BY sort_order, id
    ";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $rs = $st->get_result();
    while ($o = $rs->fetch_assoc()) {
        $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
    }
    $st->close();

    // Default Sí/No si no se definieron opciones
    if (empty($out['options']) && $out['tipo'] === 1) {
        $out['options'] = [
            ['id' => 1, 'text' => 'Sí'],
            ['id' => 0, 'text' => 'No'],
        ];
    }

    $out['has_options'] = count($out['options']) > 0;

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

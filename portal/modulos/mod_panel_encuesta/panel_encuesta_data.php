<?php
// Asegurar sesión antes de leer $_SESSION
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

// Helper común del panel
require_once __DIR__ . '/panel_encuesta_helpers.php';
header('Content-Type: application/json; charset=UTF-8');
if (!function_exists('panel_encuesta_tipo_texto')) {
    function panel_encuesta_tipo_texto($t){
        $t = (int)$t;
        $map = [
            1 => 'Sí/No',
            2 => 'Selección única',
            3 => 'Selección múltiple',
            4 => 'Texto',
            5 => 'Numérico',
            6 => 'Fecha',
            7 => 'Foto'
        ];
        return $map[$t] ?? 'Otro';
    }
}

$t0 = microtime(true);
$debugId = panel_encuesta_request_id();
header('X-Request-Id: '.$debugId);

$user_div   = (int)($_SESSION['division_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$is_mc      = ($user_div === 1);

// --------- Constantes de paginación / carga ----------

$MAX_LIMIT              = 200;    // máximo registros por página
$MAX_TOTAL_ROWS         = 30000;  // corte duro de "total" que reportamos
$COUNT_LIMIT_ROWS       = $MAX_TOTAL_ROWS + 1; // para el sub-SELECT del COUNT
$FACETS_MAX_TOTAL       = 15000;  // si hay más que esto, no calculamos facets (aumentado para mejor UX)
$DEFAULT_RANGE_DAYS     = 7;      // fallback: últimos 7 días (incluyendo hoy)
$MAX_RANGE_DAYS_NO_SCOPE= 31;     // si no hay campaña/usuario/etc, rango > 31 días se marca como "riesgoso"
$MAX_QFILTERS           = 5;      // máximo de filtros avanzados
$MAX_QFILTER_VALUES     = 50;     // máximo de valores por filtro (ids/textos/bool)

// --------- Parámetros ----------

$division     = (int)($_GET['division'] ?? 0);
$subdivision  = (int)($_GET['subdivision'] ?? 0);
$form_id      = (int)($_GET['form_id'] ?? 0);
$tipo         = (int)($_GET['tipo'] ?? 0);
$desde        = trim($_GET['desde'] ?? '');
$hasta        = trim($_GET['hasta'] ?? '');
$distrito     = (int)($_GET['distrito'] ?? 0);
$jv           = (int)($_GET['jv'] ?? 0);
$usuario      = (int)($_GET['usuario'] ?? 0);
$codigo       = trim($_GET['codigo'] ?? '');
$page         = max(1, (int)($_GET['page']  ?? 1));
$limit        = max(1, min($MAX_LIMIT, (int)($_GET['limit'] ?? 50)));
$offset       = ($page-1)*$limit;
$want_facets  = (int)($_GET['facets'] ?? 0) === 1;
$csrf_token   = $_GET['csrf_token'] ?? '';

if (!panel_encuesta_validate_csrf(is_string($csrf_token) ? $csrf_token : '')) {
    http_response_code(403);
    panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Arrays de preguntas
$qset_ids = $_GET['qset_ids'] ?? [];
$qids     = $_GET['qids']     ?? [];
$vset_ids = $_GET['vset_ids'] ?? [];

$qset_ids = array_values(array_unique(array_filter(array_map('intval', (array)$qset_ids))));
$qids     = array_values(array_unique(array_filter(array_map('intval', (array)$qids))));
$vset_ids = array_values(array_unique(array_filter(array_map(function($s){
    $s = strtolower(trim((string)$s));
    return preg_match('/^[a-f0-9]{32}$/',$s) ? $s : null;
}, (array)$vset_ids))));

// Filtros avanzados (JSON)
$qfilters_raw = $_GET['qfilters'] ?? '[]';
$qfilters = json_decode($qfilters_raw, true);
if (!is_array($qfilters)) {
    $qfilters = [];
}
$qfilters_match = strtolower(trim((string)($_GET['qfilters_match'] ?? 'all')));
if (!in_array($qfilters_match, ['all', 'any'], true)) {
    $qfilters_match = 'all';
}

// Limitar cantidad de filtros avanzados
if ($MAX_QFILTERS > 0 && count($qfilters) > $MAX_QFILTERS) {
    $qfilters = array_slice($qfilters, 0, $MAX_QFILTERS);
}

// Normalizar/limitar valores por filtro y validar IDs
$cleanFilters = [];
foreach ($qfilters as $f) {
    $mode = $f['mode'] ?? 'exact';
    $fid  = $f['id'] ?? null;

    $valid = false;
    if ($mode === 'vset') {
        $fidStr = strtolower(trim((string)$fid));
        if (preg_match('/^[a-f0-9]{32}$/', $fidStr)) {
            $fid = $fidStr;
            $valid = true;
        }
    } else {
        if (is_numeric($fid) && (int)$fid > 0) {
            $fid = (int)$fid;
            $valid = true;
        }
    }
    if (!$valid) {
        continue;
    }

    $vals = is_array($f['values'] ?? null) ? $f['values'] : [];

    if ($MAX_QFILTER_VALUES > 0) {
        foreach (['bool','opts_ids','opts_texts'] as $key) {
            if (isset($vals[$key]) && is_array($vals[$key]) && count($vals[$key]) > $MAX_QFILTER_VALUES) {
                $vals[$key] = array_slice($vals[$key], 0, $MAX_QFILTER_VALUES);
            }
        }
    }

    $cleanFilters[] = [
        'mode'   => $mode,
        'id'     => $fid,
        'tipo'   => isset($f['tipo']) ? (int)$f['tipo'] : 0,
        'values' => $vals
    ];
}
$qfilters = $cleanFilters;
unset($cleanFilters);

// ---- Rango por defecto si no hay fechas NI campaña ----
// (últimos DEFAULT_RANGE_DAYS días, incluyendo hoy)
$appliedDefaultRange = false;
if ($desde === '' && $hasta === '' && $form_id === 0) {
    $hasta = date('Y-m-d');
    $desde = date('Y-m-d', strtotime('-'.($DEFAULT_RANGE_DAYS - 1).' days'));
    $appliedDefaultRange = true;
}

// Calcular días de rango y "scope" adicional
$rangeDays = null;
if ($desde !== '' && $hasta !== '') {
    $d1 = \DateTime::createFromFormat('Y-m-d', $desde);
    $d2 = \DateTime::createFromFormat('Y-m-d', $hasta);
    if ($d1 && $d2) {
        $diff      = $d1->diff($d2);
        $rangeDays = (int)$diff->days + 1; // inclusivo
    }
}

$hasScope = (
    $form_id > 0 ||
    $usuario > 0 ||
    $distrito > 0 ||
    $jv > 0 ||
    $codigo !== '' ||
    !empty($qfilters) ||
    !empty($qids) ||
    !empty($qset_ids) ||
    !empty($vset_ids)
);

$rangeRiskyNoScope = false;
if ($rangeDays !== null && !$hasScope && $rangeDays > $MAX_RANGE_DAYS_NO_SCOPE) {
    $rangeRiskyNoScope = true;
}

$desdeFull = $desde ? ($desde.' 00:00:00') : null;
$hastaFull = $hasta ? (date('Y-m-d', strtotime($hasta.' +1 day')).' 00:00:00') : null;

// --------- WHERE ámbito ----------

$where  = [];
$types  = '';
$params = [];

$where[] = 'f.id_empresa=?';
$types  .= 'i';
$params[] = $empresa_id;

// Excluir campañas / preguntas soft-borradas
$where[] = 'f.deleted_at IS NULL';
$where[] = 'fq.deleted_at IS NULL';

// División (MC puede elegir, otros fijos)
if ($is_mc) {
    if ($division>0){
        $where[] = 'f.id_division=?';
        $types  .= 'i';
        $params[] = $division;
    }
} else {
    $where[] = 'f.id_division=?';
    $types  .= 'i';
    $params[] = $user_div;
}

if ($subdivision>0){
    $where[] = 'f.id_subdivision=?';
    $types  .= 'i';
    $params[] = $subdivision;
}

if ($form_id>0){
    $where[] = 'f.id=?';
    $types  .= 'i';
    $params[] = $form_id;
}

if (in_array($tipo,[1,3],true)){
    $where[] = 'f.tipo=?';
    $types  .= 'i';
    $params[] = $tipo;
}

// Solo visitas finalizadas
$where[] = 'v.fecha_fin IS NOT NULL';

// Fechas sobre el fin de visita
if ($desdeFull){
    $where[] = 'v.fecha_fin >= ?';
    $types  .= 's';
    $params[] = $desdeFull;
}
if ($hastaFull){
    $where[] = 'v.fecha_fin < ?';
    $types  .= 's';
    $params[] = $hastaFull;
}

if ($distrito>0){
    $where[] = 'l.id_distrito=?';
    $types  .= 'i';
    $params[] = $distrito;
}
if ($jv>0){
    $where[] = 'l.id_jefe_venta=?';
    $types  .= 'i';
    $params[] = $jv;
}
if ($usuario>0){
    $where[] = 'u.id=?';
    $types  .= 'i';
    $params[] = $usuario;
}
if ($codigo!==''){
    $where[] = 'l.codigo=?';
    $types  .= 's';
    $params[] = $codigo;
}

// ---- Preguntas: si NO hay qfilters, selección simple ----
if (empty($qfilters)) {
    if ($qids) {
        $in = implode(',', array_fill(0,count($qids),'?'));
        $where[] = "fq.id IN ($in)";
        $types  .= str_repeat('i', count($qids));
        $params  = array_merge($params, $qids);

    } elseif ($qset_ids || $vset_ids) {
        $ors = [];
        if ($qset_ids){
            $in = implode(',', array_fill(0,count($qset_ids),'?'));
            $ors[] = "fq.id_question_set_question IN ($in)";
            $types .= str_repeat('i', count($qset_ids));
            $params = array_merge($params, $qset_ids);
        }
        if ($vset_ids){
            $in = implode(',', array_fill(0,count($vset_ids),'?'));
            // hash por texto+tipo
            $ors[] = "( (fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
                        AND MD5(CONCAT(LOWER(TRIM(fq.question_text)),'|',fq.id_question_type)) IN ($in) )";
            $types .= str_repeat('s', count($vset_ids));
            $params = array_merge($params, $vset_ids);
        }
        if ($ors){
            $where[] = '('.implode(' OR ',$ors).')';
        }
    }
}

// ---- qfilters avanzados: AND entre preguntas (misma visita/local) ----
if ($qfilters) {
    // Foco (limitar listado a las preguntas seleccionadas)
    $focusOr     = [];
    $focusTypes  = '';
    $focusParams = [];

    foreach ($qfilters as $f) {
        $mode = $f['mode'] ?? 'exact';
        $fid  = $f['id'] ?? null;

        if ($mode === 'set') {
            $focusOr[]    = '(fq.id_question_set_question=?)';
            $focusTypes  .= 'i';
            $focusParams[] = (int)$fid;

        } elseif ($mode === 'vset') {
            $focusOr[] = "((fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
                          AND MD5(CONCAT(LOWER(TRIM(fq.question_text)),'|',fq.id_question_type))=?)";
            $focusTypes  .= 's';
            $focusParams[] = strtolower((string)$fid);

        } else {
            $focusOr[]    = '(fq.id=?)';
            $focusTypes  .= 'i';
            $focusParams[] = (int)$fid;
        }
    }

    if ($focusOr){
        $where[] = '('.implode(' OR ',$focusOr).')';
        $types  .= $focusTypes;
        $params  = array_merge($params,$focusParams);
    }

    // EXISTS por cada filtro
    $qtypes  = '';
    $qparams = [];
    $qexists = [];

    foreach ($qfilters as $f) {
        $mode  = $f['mode'] ?? 'exact';
        $fid   = $f['id'] ?? null;
        $ftipo = (int)($f['tipo'] ?? 0);
        $vals  = is_array($f['values'] ?? null) ? $f['values'] : [];

        if ($mode === 'set') {
            $base   = 'fq2.id_question_set_question=?';
            $qtypes .= 'i';
            $qparams[] = (int)$fid;

        } elseif ($mode === 'vset') {
            $base = "((fq2.id_question_set_question IS NULL OR fq2.id_question_set_question=0)
                     AND MD5(CONCAT(LOWER(TRIM(fq2.question_text)),'|',fq2.id_question_type))=?)";
            $qtypes  .= 's';
            $qparams[] = strtolower((string)$fid);

        } else {
            $base   = 'fq2.id=?';
            $qtypes .= 'i';
            $qparams[] = (int)$fid;
        }

        $cond   = '1=1';
        $label2 = "LOWER(TRIM(COALESCE(o2.option_text, r2.answer_text)))";

        // Tipo 1: Sí/No
        if ($ftipo===1 && !empty($vals['bool']) && is_array($vals['bool'])) {
            $hasSi = in_array(1,$vals['bool'],true);
            $hasNo = in_array(0,$vals['bool'],true);

            // normalización básica de acentos
            $label2n = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($label2,
                              CHAR(0xCC,0x81), ''), CHAR(0xC3,0xA1),'a'),
                              CHAR(0xC3,0xA9),'e'), CHAR(0xC3,0xAD),'i'),
                              CHAR(0xC3,0xB3),'o'), CHAR(0xC3,0xBA),'u')";
            $yes  = "($label2n='si')";
            $no   = "($label2n='no')";
            $parts = [];
            if ($hasSi) $parts[] = $yes;
            if ($hasNo) $parts[] = $no;
            $cond = $parts ? ('('.implode(' OR ',$parts).')') : '0=1';
        }

        // Tipo 2/3: selección con opciones
        if (($ftipo===2 || $ftipo===3) && (isset($vals['opts_ids']) || isset($vals['opts_texts']))) {
            $ids = is_array($vals['opts_ids']??null)
                ? array_values(array_filter($vals['opts_ids'],'is_numeric'))
                : [];
            $txs = is_array($vals['opts_texts']??null)
                ? array_values(array_filter($vals['opts_texts'],'strlen'))
                : [];
            $matchAll = ($ftipo===3 && (($vals['match']??'any')==='all'));

            if ($matchAll && (count($ids)+count($txs)>0)) {
                // ALL: todas las opciones seleccionadas deben aparecer (otra subquery)
                $subParts = [];
                if ($ids) {
                    if ($mode==='exact'){
                        $subParts[] = "r3.id_option IN (".implode(',', array_fill(0,count($ids),'?')).")";
                        $qtypes    .= str_repeat('i',count($ids));
                        $qparams    = array_merge($qparams,$ids);
                    } else {
                        $subParts[] = "LOWER(TRIM(COALESCE(o3.option_text, r3.answer_text))) IN (
                                          SELECT LOWER(TRIM(qso.option_text))
                                            FROM question_set_options qso
                                           WHERE qso.id IN (".implode(',', array_fill(0,count($ids),'?')).")
                                       )";
                        $qtypes    .= str_repeat('i',count($ids));
                        $qparams    = array_merge($qparams,$ids);
                    }
                }
                if ($txs) {
                    $subParts[] = "LOWER(TRIM(COALESCE(o3.option_text, r3.answer_text))) IN (". 
                                  implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                    $qtypes    .= str_repeat('s',count($txs));
                    $qparams    = array_merge($qparams,$txs);
                }
                $need = count($ids)+count($txs);

                $cond = "EXISTS (
                           SELECT 1
                             FROM form_question_responses r3
                        LEFT JOIN form_question_options o3 ON o3.id=r3.id_option
                            WHERE r3.id_local=fqr.id_local
                              AND r3.visita_id=fqr.visita_id
                              AND r3.id_form_question=fq2.id
                              AND (".implode(' OR ',$subParts).")
                        GROUP BY r3.visita_id, r3.id_local
                           HAVING COUNT(DISTINCT COALESCE(CONCAT('id:',r3.id_option),
                                                          CONCAT('tx:',LOWER(TRIM(COALESCE(o3.option_text,r3.answer_text)))))) >= $need
                         )";
            } else {
                // ANY: basta con que cumpla uno de los valores elegidos
                $sub = [];
                if ($ids) {
                    if ($mode==='exact'){
                        $sub[]   = "r2.id_option IN (".implode(',', array_fill(0,count($ids),'?')).")";
                        $qtypes .= str_repeat('i',count($ids));
                        $qparams = array_merge($qparams,$ids);
                    } else {
                        $sub[]   = "$label2 IN (
                                      SELECT LOWER(TRIM(qso.option_text))
                                        FROM question_set_options qso
                                       WHERE qso.id IN (".implode(',', array_fill(0,count($ids),'?')).")
                                    )";
                        $qtypes .= str_repeat('i',count($ids));
                        $qparams = array_merge($qparams,$ids);
                    }
                }
                if ($txs) {
                    $sub[]   = "$label2 IN (".implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                    $qtypes .= str_repeat('s',count($txs));
                    $qparams = array_merge($qparams,$txs);
                }
                $cond = $sub ? ('('.implode(' OR ',$sub).')') : '0=1';
            }
        }

        // Tipo 4: texto libre
        if ($ftipo===4 && !empty($vals['text'])) {
            $op = $vals['op'] ?? 'contains';
            if ($op==='equals'){
                $cond   = "$label2 = LOWER(TRIM(?))";
                $qtypes .= 's';
                $qparams[] = $vals['text'];
            } elseif ($op==='prefix'){
                $cond   = "$label2 LIKE LOWER(CONCAT(?, '%'))";
                $qtypes .= 's';
                $qparams[] = $vals['text'];
            } elseif ($op==='suffix'){
                $cond   = "$label2 LIKE LOWER(CONCAT('%', ?))";
                $qtypes .= 's';
                $qparams[] = $vals['text'];
            } else {
                $cond   = "$label2 LIKE LOWER(CONCAT('%', ?, '%'))";
                $qtypes .= 's';
                $qparams[] = $vals['text'];
            }
        }

        // Tipo 5: numérico
        if ($ftipo===5) {
            $num = [];
            if (isset($vals['min']) && $vals['min']!==''){
                $num[]   = 'r2.valor >= ?';
                $qtypes .= 'd';
                $qparams[] = (float)$vals['min'];
            }
            if (isset($vals['max']) && $vals['max']!==''){
                $num[]   = 'r2.valor <= ?';
                $qtypes .= 'd';
                $qparams[] = (float)$vals['max'];
            }
            if ($num) {
                $cond = '('.implode(' AND ',$num).')';
            }
        }

        $qexists[] = "EXISTS (
                      SELECT 1
                        FROM form_question_responses r2
                        JOIN form_questions fq2       ON fq2.id = r2.id_form_question
                   LEFT JOIN form_question_options o2 ON o2.id = r2.id_option
                       WHERE r2.id_local  = fqr.id_local
                         AND r2.visita_id = fqr.visita_id
                         AND ($base)
                         AND $cond
                    )";
    }

    $types  .= $qtypes;
    $params  = array_merge($params, $qparams);
    if (!empty($qexists)) {
        if ($qfilters_match === 'any') {
            $where[] = '(' . implode(' OR ', $qexists) . ')';
        } else {
            foreach ($qexists as $expr) {
                $where[] = $expr;
            }
        }
    }
}

$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// --------- FROM común (para SELECT y COUNT) ----------

$sqlFrom = "
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
";

// --------- Query principal (paginada) ----------

$sql = "
  SELECT
    fqr.id, fqr.visita_id, fqr.created_at, fqr.valor,
    f.id AS form_id, f.nombre AS campana,
    fq.id AS pregunta_id, fq.question_text AS pregunta, fq.id_question_type AS tipo,
    COALESCE(o.option_text, fqr.answer_text) AS respuesta,
    l.id AS local_id, l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
    l.id_distrito AS distrito_id, l.id_jefe_venta AS jefe_venta_id,
    d.nombre_distrito AS distrito, jv.nombre AS jefe_venta,
    u.id AS usuario_id, u.usuario AS usuario,
    v.fecha_fin AS visita_fin,
    c.nombre AS cadena
  $sqlFrom
  $whereSql
  ORDER BY
    v.fecha_fin DESC,
    fqr.visita_id DESC,
    CASE WHEN fq.id_question_type = 7 THEN 2 ELSE 1 END,
    fq.id,
    fqr.created_at DESC, fqr.id DESC
  LIMIT ? OFFSET ?
";

$typesL  = $types.'ii';
$paramsL = array_merge($params, [$limit,$offset]);

$st = $conn->prepare($sql);
$st->bind_param($typesL, ...$paramsL);
$st->execute();
$rs = $st->get_result();

$rows = [];

while ($r = $rs->fetch_assoc()){
    // Mostrar la fecha de fin de visita si existe (formateada), si no caer a created_at
    $fechaBase = $r['visita_fin'] ? $r['visita_fin'] : $r['created_at'];

    $rows[] = [
        'id'            => (int)$r['id'],
        'visita_id'     => $r['visita_id']!==null?(int)$r['visita_id']:null,
        'fecha'         => $fechaBase ? date('d/m/Y H:i:s', strtotime($fechaBase)) : '',
        'campana'       => $r['campana'],
        'form_id'       => (int)$r['form_id'],
        'pregunta_id'   => (int)$r['pregunta_id'],
        'pregunta'      => $r['pregunta'],
        'tipo'          => (int)$r['tipo'],
        'tipo_texto'    => panel_encuesta_tipo_texto($r['tipo']),
        'respuesta'     => $r['respuesta'],
        'valor'         => $r['valor']!==null?(float)$r['valor']:null,
        'local_id'      => (int)$r['local_id'],
        'local_codigo'  => $r['local_codigo'],
        'local_nombre'  => $r['local_nombre'],
        'direccion'     => $r['direccion'],
        'cadena'        => $r['cadena'],
        'distrito_id'   => $r['distrito_id']!==null?(int)$r['distrito_id']:null,
        'distrito'      => $r['distrito'],
        'jefe_venta_id' => $r['jefe_venta_id']!==null?(int)$r['jefe_venta_id']:null,
        'jefe_venta'    => $r['jefe_venta'],
        'usuario_id'    => (int)$r['usuario_id'],
        'usuario'       => $r['usuario']
    ];
}
$st->close();

// --------- Total con COUNT limitado (hard cap) ----------
//
// En vez de hacer COUNT(*) sobre TODO (que puede ser gigante),
// contamos sobre un sub-SELECT con LIMIT, para leer a lo más
// $COUNT_LIMIT_ROWS filas.

$sqlC = "
  SELECT COUNT(*) AS c
  FROM (
    SELECT 1
    $sqlFrom
    $whereSql
    LIMIT ?
  ) AS sub
";

$typesC  = $types.'i';
$paramsC = array_merge($params, [$COUNT_LIMIT_ROWS]);

$st = $conn->prepare($sqlC);
$st->bind_param($typesC, ...$paramsC);
$st->execute();
$st->bind_result($countLimited);
$st->fetch();
$st->close();

// $countLimited será <= $COUNT_LIMIT_ROWS.
// Reportamos el total con hard cap.
$total     = (int)$countLimited;
$truncated = false;
if ($total > $MAX_TOTAL_ROWS) {
    $total     = $MAX_TOTAL_ROWS;
    $truncated = true;
}

// --------- Facets ----------
// SIEMPRE calculamos facets de usuarios/JV/distritos porque son esenciales para
// la analítica y tienen baja cardinalidad (típicamente < 500 elementos cada uno).
// Esto garantiza que el frontend tenga TODOS los filtros disponibles, no solo
// los de la página actual de resultados.

$facets = null;
$FACETS_LIMIT = 1000; // límite de seguridad por facet

if ($want_facets && $total > 0) {
    $facets = ['usuarios'=>[],'jefes'=>[],'distritos'=>[]];

    // Usuarios - SIEMPRE calcular
    $sqlU = "
      SELECT DISTINCT u.id, u.usuario
      $sqlFrom
      $whereSql
      ORDER BY u.usuario
      LIMIT $FACETS_LIMIT
    ";
    $st = $conn->prepare($sqlU);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $ru = $st->get_result();
    while($u = $ru->fetch_assoc()){
        $facets['usuarios'][] = [
            'id'     => (int)$u['id'],
            'nombre' => $u['usuario']
        ];
    }
    $st->close();

    // Jefes - SIEMPRE calcular
    $sqlJ = "
      SELECT DISTINCT jv.id, jv.nombre
      $sqlFrom
      $whereSql
      ORDER BY jv.nombre
      LIMIT $FACETS_LIMIT
    ";
    $st = $conn->prepare($sqlJ);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rj = $st->get_result();
    while($j = $rj->fetch_assoc()){
        if ($j['id']) {
            $facets['jefes'][] = [
                'id'     => (int)$j['id'],
                'nombre' => $j['nombre']
            ];
        }
    }
    $st->close();

    // Distritos - SIEMPRE calcular
    $sqlD = "
      SELECT DISTINCT d.id, d.nombre_distrito
      $sqlFrom
      $whereSql
      ORDER BY d.nombre_distrito
      LIMIT $FACETS_LIMIT
    ";
    $st = $conn->prepare($sqlD);
    if ($types) {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $rd = $st->get_result();
    while($d = $rd->fetch_assoc()){
        if ($d['id']) {
            $facets['distritos'][] = [
                'id'     => (int)$d['id'],
                'nombre' => $d['nombre_distrito']
            ];
        }
    }
    $st->close();
}

// Tiempo de consulta
$elapsed = microtime(true) - $t0;
$ms      = $elapsed * 1000;
header('X-QueryTime-ms: '.number_format($ms, 1, '.', ''));

// Logging ligero (si la función existe y $conn está disponible)
if (function_exists('log_panel_encuesta_query') && isset($conn) && ($conn instanceof mysqli)) {
    try {
        log_panel_encuesta_query($conn, 'panel', $total, [
            'duration_sec'        => $elapsed,
            'has_qfilters'        => !empty($qfilters),
            'applied_30d_default' => $appliedDefaultRange ? 1 : 0,
            'from'                => $desdeFull,
            'to'                  => $hastaFull,
        ]);
    } catch (\Throwable $e) {
        // Ignorar errores de logging
    }
}

echo json_encode([
    'status'     => 'ok',
    'data'       => $rows,
    'total'      => $total,
    'page'       => $page,
    'per_page'   => $limit,
    'facets'     => $facets,
    'message'    => '',
    'error_code' => null,
    'debug_id'   => $debugId,
    'meta'       => [
        'count_limit_rows' => $COUNT_LIMIT_ROWS,
        'max_total_rows'   => $MAX_TOTAL_ROWS,
        'truncated_total'  => $truncated ? 1 : 0,
        'default_range'    => [
            'applied' => $appliedDefaultRange ? 1 : 0,
            'days'    => $DEFAULT_RANGE_DAYS
        ],
        'range' => [
            'days'              => $rangeDays,
            'has_scope'         => $hasScope ? 1 : 0,
            'risky_no_scope'    => $rangeRiskyNoScope ? 1 : 0,
            'max_days_no_scope' => $MAX_RANGE_DAYS_NO_SCOPE
        ],
        'qfilters_match' => $qfilters_match
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
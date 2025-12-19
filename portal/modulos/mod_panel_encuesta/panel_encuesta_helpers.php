<?php
declare(strict_types=1);

function panel_encuesta_get_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function panel_encuesta_validate_csrf(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || $token === null || $token === '') {
        return false;
    }
    return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
}

function panel_encuesta_request_id(): string {
    $hdr = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    if (is_string($hdr) && preg_match('/^[a-f0-9\\-]{8,64}$/i', $hdr)) {
        return $hdr;
    }
    return bin2hex(random_bytes(8));
}

function panel_encuesta_json_response(
    string $status,
    array $data = [],
    string $message = '',
    ?string $errorCode = null,
    ?string $debugId = null,
    array $meta = []
): void {
    header('Content-Type: application/json; charset=UTF-8');
    if ($debugId) {
        header('X-Request-Id: '.$debugId);
    }
    echo json_encode([
        'status' => $status,
        'data' => $data,
        'message' => $message,
        'error_code' => $errorCode,
        'debug_id' => $debugId,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE);
}

function panel_encuesta_abs_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'www.visibility.cl';
    return $scheme . '://' . $host;
}

function panel_encuesta_photo_candidates(?string $path): array
{
    $raw = trim((string)($path ?? ''));
    if ($raw === '') {
        return [];
    }

    if (preg_match('~^https?://~i', $raw)) {
        return [$raw];
    }

    $noSlash = ltrim($raw, '/');
    $withSlash = '/' . $noSlash;
    $base = panel_encuesta_abs_base();
    $out = [];
    $add = static function (?string $url) use (&$out): void {
        if ($url && !in_array($url, $out, true)) {
            $out[] = $url;
        }
    };

    if (str_starts_with($noSlash, 'uploads/')) {
        $add($base . '/visibility2/app/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    if (str_starts_with($noSlash, 'app/')) {
        $add($base . '/visibility2/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    if (str_starts_with($noSlash, 'portal/')) {
        $add($base . '/visibility2/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    if (str_starts_with($noSlash, 'visibility2/')) {
        $add($base . '/' . $noSlash);
        return $out;
    }

    $add($base . $withSlash);
    return $out;
}

function panel_encuesta_photo_fs_path(?string $url): ?string
{
    $raw = trim((string)($url ?? ''));
    if ($raw === '') {
        return null;
    }

    $path = $raw;
    if (preg_match('~^https?://~i', $path)) {
        $parts = @parse_url($path);
        $path = $parts['path'] ?? '';
        if ($path === '') {
            return null;
        }
    }

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docroot === '') {
        return null;
    }

    $path = ($path[0] ?? '') === '/' ? $path : ('/' . $path);
    $fs = realpath($docroot . $path);
    if (!$fs || !is_file($fs)) {
        return null;
    }

    return $fs;
}

function panel_encuesta_resolve_photo_url(?string $path): ?string
{
    $candidates = panel_encuesta_photo_candidates($path);
    foreach ($candidates as $candidate) {
        if (panel_encuesta_photo_fs_path($candidate)) {
            return $candidate;
        }
    }
    return $candidates[0] ?? null;
}

function build_panel_encuesta_filters(
    int $empresa_id,
    int $user_div,
    array $src,
    array $opts = []
): array {
    $defaults = [
        'foto_only'              => false,
        'enforce_date_fallback'  => true,
        'max_qfilters'           => 5,   // Máximo de condiciones avanzadas por pregunta
        'max_qfilter_values'     => 50,  // Máximo de valores (ids/textos) por filtro
        // Nuevo: rango por defecto y guardrail de rango "sin scope"
        'default_range_days'     => 7,   // fallback: últimos 7 días si no hay fechas ni campaña
        'max_range_days_no_scope'=> 31,  // si no hay campaña/usuario/preguntas, rango > 31 días se marca como "riesgoso"
    ];
    $opts   = array_merge($defaults, $opts);
    $is_mc  = ($user_div === 1);

    // -------- parámetros base --------
    $division     = isset($src['division'])     ? (int)$src['division']     : 0;
    $subdivision  = isset($src['subdivision'])  ? (int)$src['subdivision']  : 0;
    $form_id      = isset($src['form_id'])      ? (int)$src['form_id']      : 0;
    $tipo         = isset($src['tipo'])         ? (int)$src['tipo']         : 0; // 0 => (1,3)
    $desde        = trim($src['desde'] ?? '');
    $hasta        = trim($src['hasta'] ?? '');
    $distrito     = isset($src['distrito'])     ? (int)$src['distrito']     : 0;
    $jv           = isset($src['jv'])           ? (int)$src['jv']           : 0;
    $usuario      = isset($src['usuario'])      ? (int)$src['usuario']      : 0;
    $codigo       = trim($src['codigo'] ?? '');

    // Preguntas seleccionadas
    $qset_ids = $src['qset_ids'] ?? [];
    $qset_ids = array_values(array_unique(array_filter(array_map('intval', (array)$qset_ids))));

    $qids = $src['qids'] ?? [];
    $qids = array_values(array_unique(array_filter(array_map('intval', (array)$qids))));

    $vset_ids = $src['vset_ids'] ?? [];
    $vset_ids = array_values(array_unique(array_filter(array_map(function($s){
        $s = strtolower(trim((string)$s));
        return preg_match('/^[a-f0-9]{32}$/', $s) ? $s : null;
    }, (array)$vset_ids))));

    // Filtros avanzados (JSON)
    $qfilters_raw = $src['qfilters'] ?? '[]';
    $qfilters = json_decode($qfilters_raw, true);
    if (!is_array($qfilters)) {
        $qfilters = [];
    }
    $qfiltersMatch = strtolower(trim((string)($src['qfilters_match'] ?? 'all')));
    if (!in_array($qfiltersMatch, ['all', 'any'], true)) {
        $qfiltersMatch = 'all';
    }

    // Limitar cantidad de filtros (guardrail)
    if ($opts['max_qfilters'] > 0 && count($qfilters) > $opts['max_qfilters']) {
        $qfilters = array_slice($qfilters, 0, $opts['max_qfilters']);
    }

    // -------- Fallback de fechas (últimos N días) --------
    // Mantiene la semántica anterior (solo aplica cuando NO hay campaña seleccionada),
    // pero ahora usa 'default_range_days' en vez de estar fijo a 30 días.
    $appliedDefaultRange = false;
    if (
        $opts['enforce_date_fallback']
        && $desde === ''
        && $hasta === ''
        && $form_id === 0
    ) {
        $hasta = date('Y-m-d');
        $n     = max(1, (int)$opts['default_range_days']); // ej. 7
        // rango inclusivo: hoy y los (n-1) días anteriores
        $desde = date('Y-m-d', strtotime('-'.($n - 1).' days'));
        $appliedDefaultRange = true;
    }

    // Normalización a timestamp (se usan en el WHERE principal)
    $desdeFull = $desde ? ($desde.'  00:00:00') : null;
    $hastaFull = $hasta ? (date('Y-m-d', strtotime($hasta.' +1 day')).' 00:00:00') : null;

    // -------- Análisis de rango y "scope" (no corta aún, solo marca) --------
    $rangeDays = null;
    if ($desde !== '' && $hasta !== '') {
        $d1 = \DateTime::createFromFormat('Y-m-d', $desde);
        $d2 = \DateTime::createFromFormat('Y-m-d', $hasta);
        if ($d1 && $d2) {
            $diff      = $d1->diff($d2);
            $rangeDays = (int)$diff->days + 1; // inclusivo
        }
    }

    // "Scope" adicional que hace la query más acotada que solo fechas:
    $hasScope =
        ($form_id > 0) ||
        ($usuario > 0) ||
        ($distrito > 0) ||
        ($jv > 0) ||
        ($codigo !== '') ||
        !empty($qfilters) ||
        !empty($qids) ||
        !empty($qset_ids) ||
        !empty($vset_ids);

    $rangeRiskyNoScope = false;
    if ($rangeDays !== null && !$hasScope) {
        $maxNoScope = max(1, (int)$opts['max_range_days_no_scope']); // ej. 31
        if ($rangeDays > $maxNoScope) {
            // No lanzamos excepción aquí (para no romper endpoints actuales),
            // solo marcamos en meta. Los endpoints decidirán si bloquean o no.
            $rangeRiskyNoScope = true;
        }
    }

    // -------- WHERE de ámbito --------
    $where  = [];
    $types  = '';
    $params = [];

    $where[] = 'f.id_empresa=?'; $types .= 'i'; $params[] = $empresa_id;

    // Excluir campañas / preguntas soft-borradas
    $where[] = 'f.deleted_at IS NULL';
    $where[] = 'fq.deleted_at IS NULL';

    if ($is_mc) {
        if ($division > 0) { $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $division; }
    } else {
        $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $user_div;
    }

    if ($subdivision > 0) { $where[] = 'f.id_subdivision=?'; $types .= 'i'; $params[] = $subdivision; }
    if ($form_id > 0)     { $where[] = 'f.id=?';            $types .= 'i'; $params[] = $form_id; }
    if (in_array($tipo, [1,3], true)) { $where[]='f.tipo=?'; $types.='i'; $params[]=$tipo; }

    if ($desdeFull) { $where[] = 'fqr.created_at >= ?'; $types .= 's'; $params[] = $desdeFull; }
    if ($hastaFull) { $where[] = 'fqr.created_at < ?';  $types .= 's'; $params[] = $hastaFull; }

    if ($distrito > 0) { $where[] = 'l.id_distrito=?';   $types .= 'i'; $params[] = $distrito; }
    if ($jv > 0)       { $where[] = 'l.id_jefe_venta=?'; $types .= 'i'; $params[] = $jv; }
    if ($usuario > 0)  { $where[] = 'u.id=?';            $types .= 'i'; $params[] = $usuario; }
    if ($codigo !== ''){ $where[] = 'l.codigo=?';        $types .= 's'; $params[] = $codigo; }

    // -------- Selección simple (sin qfilters) --------
    if (empty($qfilters)) {
        if ($qids) {
            $in = implode(',', array_fill(0, count($qids), '?'));
            $where[] = "fq.id IN ($in)";
            $types  .= str_repeat('i', count($qids));
            $params  = array_merge($params, $qids);
        } elseif ($qset_ids || $vset_ids) {
            $ors = [];
            if ($qset_ids) {
                $in    = implode(',', array_fill(0, count($qset_ids), '?'));
                $ors[] = "fq.id_question_set_question IN ($in)";
                $types .= str_repeat('i', count($qset_ids));
                $params= array_merge($params, $qset_ids);
            }
            if ($vset_ids) {
                $in    = implode(',', array_fill(0, count($vset_ids), '?'));
                $ors[] = "( (fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
                            AND MD5(CONCAT(LOWER(TRIM(fq.question_text)),'|',fq.id_question_type)) IN ($in) )";
                $types .= str_repeat('s', count($vset_ids));
                $params= array_merge($params, $vset_ids);
            }
            if ($ors) {
                $where[] = '('.implode(' OR ', $ors).')';
            }
        }
    }

    // -------- qfilters avanzados: foco + EXISTS --------
    if ($qfilters) {
        $qfilterExists = [];
        // 1) Foco (las mismas preguntas que estás filtrando)
        $focusOr     = [];
        $focusTypes  = '';
        $focusParams = [];

        foreach ($qfilters as $f) {
            $mode = $f['mode'] ?? 'exact';
            $fid  = $f['id'] ?? null;

            // Validación básica de ID
            $valid = false;
            if ($mode === 'vset') {
                $valid = is_string($fid) && preg_match('/^[a-f0-9]{32}$/', strtolower((string)$fid));
            } else {
                $valid = is_numeric($fid) && (int)$fid > 0;
            }
            if (!$valid) { continue; }

            if ($mode === 'set') {
                $focusOr[]     = '(fq.id_question_set_question = ?)';
                $focusTypes   .= 'i';
                $focusParams[] = (int)$fid;
            } elseif ($mode === 'vset') {
                $focusOr[]     = "((fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
                                  AND MD5(CONCAT(LOWER(TRIM(fq.question_text)),'|',fq.id_question_type)) = ?)";
                $focusTypes   .= 's';
                $focusParams[] = strtolower((string)$fid);
            } else {
                $focusOr[]     = '(fq.id = ?)';
                $focusTypes   .= 'i';
                $focusParams[] = (int)$fid;
            }
        }

        $focusOrStr = implode(' OR ', $focusOr);
        if (trim($focusOrStr) !== '') {
            $where[] = '(' . $focusOrStr . ')';
            $types  .= $focusTypes;
            $params  = array_merge($params, $focusParams);
        }

        // 2) EXISTS por cada filtro (AND entre preguntas)
        $i = 0; $qtypes=''; $qparams=[];
        foreach ($qfilters as $f) {
            $mode  = $f['mode'] ?? 'exact';
            $fid   = $f['id'] ?? null;
            $ftipo = (int)($f['tipo'] ?? 0);
            $vals  = is_array($f['values'] ?? null) ? $f['values'] : [];

            // Validación de ID
            $valid = false;
            if ($mode === 'vset') {
                $valid = is_string($fid) && preg_match('/^[a-f0-9]{32}$/', strtolower((string)$fid));
            } else {
                $valid = is_numeric($fid) && (int)$fid > 0;
            }
            if (!$valid) { continue; }

            // Limitar cantidad de valores por filtro
            if ($opts['max_qfilter_values'] > 0 && is_array($vals)) {
                foreach (['bool','opts_ids','opts_texts'] as $key) {
                    if (isset($vals[$key]) && is_array($vals[$key]) && count($vals[$key]) > $opts['max_qfilter_values']) {
                        $vals[$key] = array_slice($vals[$key], 0, $opts['max_qfilter_values']);
                    }
                }
            }

            $fqri = "fqr_af_$i";
            $fqi  = "fq_af_$i";
            $oi   = "o_af_$i";

            if ($mode==='set') {
                $base = "$fqi.id_question_set_question = ?";
                $qtypes.='i'; $qparams[]=(int)$fid;
            } elseif ($mode==='vset') {
                $base = "( ($fqi.id_question_set_question IS NULL OR $fqi.id_question_set_question=0)
                           AND MD5(CONCAT(LOWER(TRIM($fqi.question_text)),'|',$fqi.id_question_type)) = ? )";
                $qtypes.='s'; $qparams[]=strtolower((string)$fid);
            } else {
                $base = "$fqi.id = ?";
                $qtypes.='i'; $qparams[]=(int)$fid;
            }

            $cond  = '1=1';
            $label = "LOWER(TRIM(COALESCE($oi.option_text, $fqri.answer_text)))";

            // tipo 1: Sí/No
            if ($ftipo===1 && !empty($vals['bool']) && is_array($vals['bool'])) {
                $hasSi=in_array(1,$vals['bool'],true); $hasNo=in_array(0,$vals['bool'],true);
                $yes="($fqri.valor=1 OR $label REGEXP '^(s(i|í))$')";
                $no ="($fqri.valor=0 OR $label REGEXP '^(n(o|ó))$')";
                $parts=[]; if($hasSi) $parts[]=$yes; if($hasNo) $parts[]=$no;
                $cond=$parts?('('.implode(' OR ',$parts).')'):'0=1';
            }

            // tipo 2/3: única / múltiple
            if (($ftipo===2 || $ftipo===3) && (isset($vals['opts_ids']) || isset($vals['opts_texts']))) {
                $ids = is_array($vals['opts_ids'] ?? null) ? array_values(array_filter($vals['opts_ids'], 'is_numeric')) : [];
                $txs = is_array($vals['opts_texts'] ?? null) ? array_values(array_filter($vals['opts_texts'], 'strlen')) : [];
                $matchAll = ($ftipo===3 && (($vals['match'] ?? 'any')==='all'));

                if ($matchAll && (count($ids)+count($txs)>0)) {
                    $joinCond=[];
                    if ($ids){
                        $joinCond[]="r3.id_option IN (".implode(',', array_fill(0,count($ids),'?')).")";
                        $qtypes.=str_repeat('i', count($ids)); $qparams=array_merge($qparams,$ids);
                    }
                    if ($txs){
                        $joinCond[]="LOWER(TRIM(COALESCE(o3.option_text, r3.answer_text))) IN (".
                                    implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                        $qtypes.=str_repeat('s', count($txs)); $qparams=array_merge($qparams,$txs);
                    }
                    $need=count($ids)+count($txs);
                    $cond="EXISTS (
                      SELECT 1 FROM form_question_responses r3
                      LEFT JOIN form_question_options o3 ON o3.id=r3.id_option
                      WHERE r3.id_local=fqr.id_local AND r3.visita_id=fqr.visita_id AND r3.id_form_question=$fqi.id
                        AND (".implode(' OR ',$joinCond).")
                      GROUP BY r3.visita_id, r3.id_local
                      HAVING COUNT(DISTINCT COALESCE(CONCAT('id:', r3.id_option),
                                                     CONCAT('tx:', LOWER(TRIM(COALESCE(o3.option_text, r3.answer_text)))))) >= $need
                    )";
                } else {
                    $sub=[];
                    if ($ids){
                        $sub[]="$fqri.id_option IN (".implode(',', array_fill(0,count($ids),'?')).")";
                        $qtypes.=str_repeat('i', count($ids)); $qparams=array_merge($qparams,$ids);
                    }
                    if ($txs){
                        $sub[]="$label IN (".implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                        $qtypes.=str_repeat('s', count($txs)); $qparams=array_merge($qparams,$txs);
                    }
                    $cond=$sub?('('.implode(' OR ',$sub).')'):'0=1';
                }
            }

            // tipo 4: texto
            if ($ftipo===4 && !empty($vals['text'])) {
                $op=$vals['op'] ?? 'contains';
                if     ($op==='equals'){ $cond = "$label = LOWER(TRIM(?))"; }
                elseif ($op==='prefix'){ $cond = "$label LIKE LOWER(CONCAT(?, '%'))"; }
                elseif ($op==='suffix'){ $cond = "$label LIKE LOWER(CONCAT('%', ?))"; }
                else                    { $cond = "$label LIKE LOWER(CONCAT('%', ?, '%'))"; }
                $qtypes.='s'; $qparams[]=$vals['text'];
            }

            // tipo 5: numérico
            if ($ftipo===5) {
                $num=[];
                if(isset($vals['min']) && $vals['min']!==''){ $num[]="$fqri.valor >= ?"; $qtypes.='d'; $qparams[]=(float)$vals['min']; }
                if(isset($vals['max']) && $vals['max']!==''){ $num[]="$fqri.valor <= ?"; $qtypes.='d'; $qparams[]=(float)$vals['max']; }
                if ($num) $cond='('.implode(' AND ',$num).')';
            }

            $qfilterExists[]="EXISTS (
              SELECT 1 FROM form_question_responses $fqri
              JOIN form_questions $fqi ON $fqi.id=$fqri.id_form_question
              LEFT JOIN form_question_options $oi ON $oi.id=$fqri.id_option
              WHERE $fqri.id_local=fqr.id_local AND $fqri.visita_id=fqr.visita_id
                AND ($base) AND $cond
            )";

            $i++;
        }
        $types .= $qtypes;
        $params= array_merge($params,$qparams);
        if (!empty($qfilterExists)) {
            if ($qfiltersMatch === 'any') {
                $where[] = '(' . implode(' OR ', $qfilterExists) . ')';
            } else {
                foreach ($qfilterExists as $expr) {
                    $where[] = $expr;
                }
            }
        }
    }

    // Solo fotos (para export PDF de fotos)
    if (!empty($opts['foto_only'])) {
        $where[] = 'fq.id_question_type = 7';
    }

    // Sanitizar
    $where = array_values(array_filter($where, function($w){
        $w = trim((string)$w);
        return $w !== '' && $w !== '()';
    }));
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $meta = [
        'has_qfilters'           => !empty($qfilters),
        'applied_30d_default'    => $appliedDefaultRange, // nombre heredado, ahora significa "aplicó fallback de rango"
        'from'                   => $desdeFull,
        'to'                     => $hastaFull,
        // Nuevos metadatos para control de carga
        'range_days'             => $rangeDays,
        'has_scope'              => $hasScope,
        'range_risky_no_scope'   => $rangeRiskyNoScope,
        'default_range_days'     => (int)$opts['default_range_days'],
        'max_range_days_no_scope'=> (int)$opts['max_range_days_no_scope'],
        'qfilters_match'         => $qfiltersMatch,
    ];

    return [$whereSql, $types, $params, $meta];
}

function log_panel_encuesta_query(mysqli $conn, string $accion, int $filas, array $meta = []): void {
    $userId    = (int)($_SESSION['usuario_id'] ?? 0);
    $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
    $durSec    = isset($meta['duration_sec']) ? (float)$meta['duration_sec'] : 0.0;
    $durMs     = (int)round($durSec * 1000);

    $hasQ      = !empty($meta['has_qfilters']) ? 1 : 0;
    $applied30 = !empty($meta['applied_30d_default']) ? 1 : 0;
    $from      = $meta['from'] ?? null;
    $to        = $meta['to']   ?? null;

    try {
        $sql = "INSERT INTO panel_encuesta_log
                (usuario_id, empresa_id, accion, duracion_ms, filas, has_qfilters, applied_30d_default, fecha_desde, fecha_hasta, creado_en)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())";
        $st = $conn->prepare($sql);
        $st->bind_param(
            'iisiiisss',
            $userId,
            $empresaId,
            $accion,
            $durMs,
            $filas,
            $hasQ,
            $applied30,
            $from,
            $to
        );
        $st->execute();
        $st->close();
    } catch (\Throwable $e) {
        // Si la tabla no existe o hay error, lo ignoramos silenciosamente
    }
}

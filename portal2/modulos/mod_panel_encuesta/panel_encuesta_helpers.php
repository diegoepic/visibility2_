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

    // Caso: uploads/ -> múltiples ubicaciones posibles
    if (str_starts_with($noSlash, 'uploads/')) {
        $add($base . '/visibility2/app/' . $noSlash);
        $add($base . '/visibility2/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    // Caso: uploads_fotos_pregunta/ -> ruta específica de fotos de preguntas
    if (str_starts_with($noSlash, 'uploads_fotos_pregunta/')) {
        $add($base . '/visibility2/app/uploads/' . $noSlash);
        $add($base . '/visibility2/app/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    // Caso: app/ -> dentro de visibility2
    if (str_starts_with($noSlash, 'app/')) {
        $add($base . '/visibility2/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    // Caso: portal/ -> dentro de visibility2
    if (str_starts_with($noSlash, 'portal/')) {
        $add($base . '/visibility2/' . $noSlash);
        $add($base . '/' . $noSlash);
        return $out;
    }

    // Caso: visibility2/ -> ruta directa
    if (str_starts_with($noSlash, 'visibility2/')) {
        $add($base . '/' . $noSlash);
        return $out;
    }

    // Caso: nombre de archivo directo (sin ruta) -> probar varias ubicaciones
    if (!str_contains($noSlash, '/')) {
        $add($base . '/visibility2/app/uploads/uploads_fotos_pregunta/' . $noSlash);
        $add($base . '/visibility2/app/uploads/' . $noSlash);
        $add($base . '/visibility2/app/' . $noSlash);
    }

    // Fallback genérico
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

    // Intentar ruta directa
    $fs = realpath($docroot . $path);
    if ($fs && is_file($fs)) {
        return $fs;
    }

    // Intentar rutas alternativas si la directa falla
    $pathNoSlash = ltrim($path, '/');
    $alternativePaths = [
        $docroot . '/visibility2/app/uploads/' . $pathNoSlash,
        $docroot . '/visibility2/app/' . $pathNoSlash,
        $docroot . '/visibility2/' . $pathNoSlash,
        $docroot . '/' . $pathNoSlash,
    ];

    // Si la ruta empieza con visibility2/, probar sin ese prefijo
    if (str_starts_with($pathNoSlash, 'visibility2/')) {
        $withoutPrefix = substr($pathNoSlash, strlen('visibility2/'));
        $alternativePaths[] = $docroot . '/visibility2/app/uploads/' . $withoutPrefix;
        $alternativePaths[] = $docroot . '/visibility2/app/' . $withoutPrefix;
    }

    foreach ($alternativePaths as $altPath) {
        $fs = realpath($altPath);
        if ($fs && is_file($fs)) {
            return $fs;
        }
    }

    return null;
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
        // Importante: los params de cada EXISTS se bufferean localmente y solo
        // se acumulan en $qtypes/$qparams si el EXISTS efectivamente se añade al SQL.
        // Esto evita que el bind_param reciba más params que placeholders '?' en la query.
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

            // Buffer local: acumular tipos/params de este EXISTS sin commitarlos aún.
            $lt = ''; $lp = [];

            if ($mode==='set') {
                $base = "$fqi.id_question_set_question = ?";
                $lt.='i'; $lp[]=(int)$fid;
            } elseif ($mode==='vset') {
                $base = "( ($fqi.id_question_set_question IS NULL OR $fqi.id_question_set_question=0)
                           AND MD5(CONCAT(LOWER(TRIM($fqi.question_text)),'|',$fqi.id_question_type)) = ? )";
                $lt.='s'; $lp[]=strtolower((string)$fid);
            } else {
                $base = "$fqi.id = ?";
                $lt.='i'; $lp[]=(int)$fid;
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
                        $lt.=str_repeat('i', count($ids)); $lp=array_merge($lp,$ids);
                    }
                    if ($txs){
                        $joinCond[]="LOWER(TRIM(COALESCE(o3.option_text, r3.answer_text))) IN (".
                                    implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                        $lt.=str_repeat('s', count($txs)); $lp=array_merge($lp,$txs);
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
                        $lt.=str_repeat('i', count($ids)); $lp=array_merge($lp,$ids);
                    }
                    if ($txs){
                        $sub[]="$label IN (".implode(',', array_fill(0,count($txs),'LOWER(TRIM(?))')).")";
                        $lt.=str_repeat('s', count($txs)); $lp=array_merge($lp,$txs);
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
                $lt.='s'; $lp[]=$vals['text'];
            }

            // tipo 5: numérico
            if ($ftipo===5) {
                $num=[];
                if(isset($vals['min']) && $vals['min']!==''){ $num[]="$fqri.valor >= ?"; $lt.='d'; $lp[]=(float)$vals['min']; }
                if(isset($vals['max']) && $vals['max']!==''){ $num[]="$fqri.valor <= ?"; $lt.='d'; $lp[]=(float)$vals['max']; }
                if ($num) $cond='('.implode(' AND ',$num).')';
            }

            // Solo añadir EXISTS si hay condición real de valor.
            // Con $cond='1=1' (sin valores seleccionados), el foco (WHERE OR) ya restringe
            // las preguntas mostradas; agregar EXISTS solo añadiría una restricción cruzada.
            // CRÍTICO: solo acumular $lt/$lp en $qtypes/$qparams si el EXISTS se usa,
            // de lo contrario bind_param() lanza ArgumentCountError por params huérfanos.
            if ($cond !== '1=1') {
                $qfilterExists[]="EXISTS (
                  SELECT 1 FROM form_question_responses $fqri
                  JOIN form_questions $fqi ON $fqi.id=$fqri.id_form_question
                  LEFT JOIN form_question_options $oi ON $oi.id=$fqri.id_option
                  WHERE $fqri.id_local=fqr.id_local AND $fqri.visita_id=fqr.visita_id
                    AND ($base) AND $cond
                )";
                $qtypes .= $lt;
                $qparams  = array_merge($qparams, $lp);
            }

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

    // Solo visitas finalizadas (para endpoints que lo requieren)
    if (!empty($opts['require_visita_fin'])) {
        $where[] = 'v.fecha_fin IS NOT NULL';
    }

    // Solo fotos (para export PDF de fotos)
    if (!empty($opts['foto_only'])) {
        $where[] = 'fq.id_question_type = 7';
    }

    // -------- Filtros geoespaciales --------
    // Requiere que el FROM del endpoint incluya: JOIN local l ON l.id = fqr.id_local
    // (todos los endpoints del módulo ya lo hacen)

    // Bounding box: lat_min, lat_max, lng_min, lng_max
    $latMin = isset($src['lat_min']) && $src['lat_min'] !== '' ? (float)$src['lat_min'] : null;
    $latMax = isset($src['lat_max']) && $src['lat_max'] !== '' ? (float)$src['lat_max'] : null;
    $lngMin = isset($src['lng_min']) && $src['lng_min'] !== '' ? (float)$src['lng_min'] : null;
    $lngMax = isset($src['lng_max']) && $src['lng_max'] !== '' ? (float)$src['lng_max'] : null;

    if ($latMin !== null && $latMax !== null) {
        $where[] = 'l.lat BETWEEN ? AND ?';
        $types  .= 'dd';
        $params[] = $latMin;
        $params[] = $latMax;
    }
    if ($lngMin !== null && $lngMax !== null) {
        $where[] = 'l.lng BETWEEN ? AND ?';
        $types  .= 'dd';
        $params[] = $lngMin;
        $params[] = $lngMax;
    }

    // Radio Haversine: lat + lng + radius_km
    $geoLat    = isset($src['geo_lat'])    && $src['geo_lat']    !== '' ? (float)$src['geo_lat']    : null;
    $geoLng    = isset($src['geo_lng'])    && $src['geo_lng']    !== '' ? (float)$src['geo_lng']    : null;
    $radiusKm  = isset($src['radius_km'])  && $src['radius_km']  !== '' ? (float)$src['radius_km']  : null;

    if ($geoLat !== null && $geoLng !== null && $radiusKm !== null && $radiusKm > 0) {
        // Haversine en SQL (usa LEAST para evitar errores de dominio de acos por float)
        $where[] = '(6371 * acos(LEAST(1.0, cos(radians(?)) * cos(radians(l.lat)) * cos(radians(l.lng) - radians(?)) + sin(radians(?)) * sin(radians(l.lat))))) <= ?';
        $types  .= 'dddd';
        $params[] = $geoLat;
        $params[] = $geoLng;
        $params[] = $geoLat;
        $params[] = $radiusKm;
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

/**
 * Busca y carga el autoload de Dompdf. Retorna true si se encontró y la clase existe.
 */
function panel_encuesta_load_dompdf(): bool {
    $paths = [
        __DIR__ . '/vendor/autoload.php',
        $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
        $_SERVER['DOCUMENT_ROOT'] . '/visibility2/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            require_once $p;
            return class_exists('\\Dompdf\\Dompdf');
        }
    }
    return false;
}

/**
 * Rate-limit para endpoints de exportación.
 * Permite máximo $maxPerWindow solicitudes en $windowSecs segundos por usuario/IP.
 * Almacena el estado en $_SESSION para no depender de Redis/APCu.
 *
 * @return bool  true = permitido, false = bloqueado (debe devolver 429)
 */
function panel_encuesta_check_export_rate(
    string $action = 'export',
    int    $maxPerWindow = 3,
    int    $windowSecs  = 15
): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = '_pe_rate_' . preg_replace('/[^a-z0-9_]/', '_', $action);
    $now = time();

    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }

    // Purgar entradas fuera de la ventana
    $_SESSION[$key] = array_values(array_filter(
        $_SESSION[$key],
        fn($ts) => ($now - $ts) < $windowSecs
    ));

    if (count($_SESSION[$key]) >= $maxPerWindow) {
        return false;
    }

    $_SESSION[$key][] = $now;
    return true;
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
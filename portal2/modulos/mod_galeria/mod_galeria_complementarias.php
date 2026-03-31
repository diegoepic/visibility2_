<?php
session_start();

/* =========================================
 * Utilidades
 * ======================================= */
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $k => $v) $refs[$k] = &$arr[$k];
        return $refs;
    }
    return $arr;
}
function fixUrl($url, $base_url) {
    if (preg_match('#^https?://#i', $url)) return $url;
    $prefixes = ['/visibility2/app/','../app/'];
    foreach ($prefixes as $p) {
        if (strncmp($url,$p,strlen($p))===0) { $url = substr($url, strlen($p)); break; }
    }
    $url = ltrim($url,'/');
    return rtrim($base_url,'/').'/'.$url;
}
function formatearFecha($f) { return $f ? date('d/m/Y H:i:s', strtotime($f)) : ''; }

/* =========================================
 * Includes / seguridad
 * ======================================= */
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("<div class='alert alert-danger'>ID de campaña inválido.</div>");
}
$formulario_id = (int)$_GET['id'];

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) die("<div class='alert alert-danger'>Acceso inválido (empresa).</div>");

$stmt = $conn->prepare("
  SELECT tipo,
         COALESCE(nombre, CONCAT('Campaña #',id)) AS campanaNombre,
         COALESCE(iw_requiere_local,0) AS requiereLocal
  FROM formulario
  WHERE id=? AND id_empresa=? LIMIT 1
");
$stmt->bind_param("ii", $formulario_id, $empresa_id);
$stmt->execute();
$stmt->bind_result($tipoForm, $campanaNombre, $requiereLocal);
if (!$stmt->fetch()) die("<div class='alert alert-danger'>Formulario no encontrado o no pertenece a tu empresa.</div>");
$stmt->close();

$requiereLocal = (int)$requiereLocal === 1;

if ((int)$tipoForm !== 2) {
    die("<div class='alert alert-warning'>Este módulo es sólo para campañas complementarias (tipo 2).</div>");
}

/* =========================================
 * Filtros / paginación
 * ======================================= */
$start_date  = $_GET['start_date']   ?? '';
$end_date    = $_GET['end_date']     ?? '';
$user_id     = intval($_GET['user_id'] ?? 0);
$id_question = $_GET['id_question']  ?? '';
$limit       = max(1, intval($_GET['limit'] ?? 25));
$page        = max(1, intval($_GET['page']  ?? 1));
$offset      = ($page - 1) * $limit;

/* Gap de sesión en minutos (default 2) */
$gap = max(1, intval($_GET['gap'] ?? 2));

/* Modo de vista: galería normal vs duplicados (incidentes) */
$view_mode = $_GET['view_mode'] ?? 'galeria';
if (!in_array($view_mode, ['galeria','duplicados'], true)) {
    $view_mode = 'galeria';
}

$base_url = "https://visibility.cl/visibility2/app/";

function buildPaginationUrl(int $page): string {
    $params = $_GET;
    $params['page']=$page;
    return '?'.http_build_query($params);
}
function buildViewModeUrl(string $mode): string {
    $params = $_GET;
    $params['view_mode'] = $mode;
    $params['page'] = 1; // al cambiar de vista, vuelve a página 1
    return '?'.http_build_query($params);
}

/* =========================================
 * Listas para filtros (usuarios / preguntas)
 *  (Eliminado el filtro rígido id_local = 0)
 * ======================================= */
$usuarios = [];
$stmtU = $conn->prepare("
    SELECT DISTINCT u.id, u.usuario
    FROM form_question_responses fqr
    JOIN form_questions fq ON fq.id = fqr.id_form_question
    JOIN usuario u        ON u.id = fqr.id_usuario
   WHERE fq.id_formulario = ?
     AND fq.id_question_type = 7
   ORDER BY u.usuario
");
$stmtU->bind_param("i", $formulario_id);
$stmtU->execute();
$resU = $stmtU->get_result();
while ($r = $resU->fetch_assoc()) $usuarios[] = $r;
$stmtU->close();

$preguntasDisponibles = [];
$stmtP = $conn->prepare("
  SELECT id, question_text
  FROM form_questions
  WHERE id_formulario = ?
    AND id_question_type = 7
  ORDER BY sort_order
");
$stmtP->bind_param("i", $formulario_id);
$stmtP->execute();
$rsP = $stmtP->get_result();
while ($r = $rsP->fetch_assoc()) $preguntasDisponibles[] = $r;
$stmtP->close();

/* =========================================
 * Export CSV de fotos duplicadas (solo modo duplicados)
 * ======================================= */
if ($view_mode === 'duplicados' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvParams = [$formulario_id];
    $csvTypes  = "i";

    $csvSql = "
      SELECT
        v.id_formulario,
        r.id_usuario,
        u.usuario AS usuario,
        COALESCE(v.id_local, m.id_local, r.id_local) AS local_id_effective,
        l.codigo AS local_codigo,
        l.nombre AS local_nombre,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')) AS sha1,
        COUNT(*) AS total_subidas,
        COUNT(DISTINCT DATE(m.created_at)) AS dias_distintos,
        GROUP_CONCAT(DISTINCT DATE(m.created_at) ORDER BY DATE(m.created_at) SEPARATOR ', ') AS fechas,
        MIN(m.created_at) AS primera_subida,
        MAX(m.created_at) AS ultima_subida
      FROM form_question_photo_meta m
      JOIN form_question_responses r ON r.id = m.resp_id
      JOIN visita v                  ON v.id = r.visita_id
      JOIN form_questions fq         ON fq.id = r.id_form_question
      LEFT JOIN usuario u            ON u.id = r.id_usuario
      LEFT JOIN local   l            ON l.id = COALESCE(v.id_local, m.id_local, r.id_local)
      WHERE v.id_formulario = ?
        AND fq.id_question_type = 7
        AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
    ";

    if ($start_date !== '') { $csvSql .= " AND DATE(m.created_at) >= ?"; $csvTypes.="s"; $csvParams[]=$start_date; }
    if ($end_date   !== '') { $csvSql .= " AND DATE(m.created_at) <= ?"; $csvTypes.="s"; $csvParams[]=$end_date; }
    if ($user_id > 0)       { $csvSql .= " AND r.id_usuario = ?";       $csvTypes.="i"; $csvParams[]=$user_id; }
    if ($id_question !== ''){ $csvSql .= " AND fq.id = ?";              $csvTypes.="i"; $csvParams[]=(int)$id_question; }

    $csvSql .= "
      GROUP BY
        v.id_formulario,
        r.id_usuario,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')),
        COALESCE(v.id_local, m.id_local, r.id_local)
      HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
      ORDER BY ultima_subida DESC
    ";

    $stmtCsv = $conn->prepare($csvSql);
    if (!$stmtCsv) {
        die("<div class='alert alert-danger'>Error preparación CSV (duplicados): ".htmlspecialchars($conn->error)."</div>");
    }
    $bindCsv = array_merge([$csvTypes], $csvParams);
    $bindCsv = refValues($bindCsv);
    call_user_func_array([$stmtCsv,'bind_param'],$bindCsv);
    $stmtCsv->execute();
    $resCsv = $stmtCsv->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="duplicados_campana_'.$formulario_id.'.csv"');

    // BOM para Excel
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');

    // Encabezados CSV
    fputcsv($fh, [
        'ID Formulario',
        'ID Usuario',
        'Usuario',
        'ID Local',
        'Local Código',
        'Local Nombre',
        'Total subidas',
        'Días distintos',
        'Fechas',
        'Primera subida (fecha/hora)',
        'Última subida (fecha/hora)'
    ]);

    while ($row = $resCsv->fetch_assoc()) {
        $local_id   = (int)($row['local_id_effective'] ?? 0);
        $local_cod  = $row['local_codigo'] ?? '';
        $local_nom  = $row['local_nombre'] ?? '';

        fputcsv($fh, [
            $row['id_formulario'],
            $row['id_usuario'],
            $row['usuario'],
            $local_id,
            $local_cod,
            $local_nom,
            $row['total_subidas'],
            $row['dias_distintos'],
            $row['fechas'],
            $row['primera_subida'],
            $row['ultima_subida']
        ]);
    }

    fclose($fh);
    $stmtCsv->close();
    $conn->close();
    exit;
}

/* =========================================
 * Consulta principal:
 *   - Modo "galeria": lógica original por sesiones (visita/gap)
 *   - Modo "duplicados": grupos por sha1 (incidentes)
 * ======================================= */

$data      = [];
$localIds  = [];
$totalRows = 0;

if ($view_mode === 'duplicados') {
    /* --------- VISTA DUPLICADOS (incidentes por SHA1) --------- */
    $params = [$formulario_id];
    $types  = "i";

    $sql = "
      SELECT
        v.id_formulario,
        r.id_usuario,
        u.usuario AS usuario,
        COALESCE(v.id_local, m.id_local, r.id_local) AS local_id_effective,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')) AS sha1,
        COUNT(*) AS total_subidas,
        COUNT(DISTINCT DATE(m.created_at)) AS dias_distintos,
        GROUP_CONCAT(DISTINCT DATE(m.created_at) ORDER BY DATE(m.created_at) SEPARATOR ', ') AS fechas,
        MIN(m.created_at) AS primera_subida,
        MAX(m.created_at) AS ultima_subida,
        GROUP_CONCAT(DISTINCT m.foto_url ORDER BY m.created_at SEPARATOR '||') AS urls
      FROM form_question_photo_meta m
      JOIN form_question_responses r ON r.id = m.resp_id
      JOIN visita v                  ON v.id = r.visita_id
      JOIN form_questions fq         ON fq.id = r.id_form_question
      LEFT JOIN usuario u            ON u.id = r.id_usuario
      WHERE v.id_formulario = ?
        AND fq.id_question_type = 7
        AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
    ";

    if ($start_date !== '') { $sql.=" AND DATE(m.created_at) >= ?"; $types.="s"; $params[]=$start_date; }
    if ($end_date   !== '') { $sql.=" AND DATE(m.created_at) <= ?"; $types.="s"; $params[]=$end_date; }
    if ($user_id > 0)       { $sql.=" AND r.id_usuario = ?";       $types.="i"; $params[]=$user_id; }
    if ($id_question !== ''){ $sql.=" AND fq.id = ?";              $types.="i"; $params[]=(int)$id_question; }

    $sql .= "
      GROUP BY
        v.id_formulario,
        r.id_usuario,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')),
        COALESCE(v.id_local, m.id_local, r.id_local)
      HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
      ORDER BY ultima_subida DESC
      LIMIT ? OFFSET ?
    ";

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmtMain = $conn->prepare($sql);
    if (!$stmtMain) die("<div class='alert alert-danger'>Error preparación (duplicados): ".htmlspecialchars($conn->error)."</div>");
    $bindParams = array_merge([$types], $params);
    $bindParams = refValues($bindParams);
    call_user_func_array([$stmtMain,'bind_param'],$bindParams);
    $stmtMain->execute();
    $result = $stmtMain->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = explode('||',$row['urls'] ?? '');
        $fixed=[];
        foreach ($raw as $u) {
            if ($u==='') continue;
            $fixed[] = fixUrl($u,$base_url);
        }
        $row['urls']         = implode('||',$fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0] ?? $base_url.'assets/images/placeholder.png';

        $row['local_id_effective'] = (int)($row['local_id_effective'] ?? 0);
        if ($requiereLocal && $row['local_id_effective'] > 0) {
            $localIds[$row['local_id_effective']] = true;
        }

        $data[] = $row;
    }
    $stmtMain->close();

    // Conteo total de grupos de duplicados (para paginación)
    $countParams = [$formulario_id];
    $countTypes  = "i";

    $countSql = "
      SELECT COUNT(*) AS total
      FROM (
        SELECT
          v.id_formulario,
          r.id_usuario,
          JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')) AS sha1,
          COALESCE(v.id_local, m.id_local, r.id_local) AS local_id_effective,
          COUNT(DISTINCT DATE(m.created_at)) AS dias_distintos
        FROM form_question_photo_meta m
        JOIN form_question_responses r ON r.id = m.resp_id
        JOIN visita v                  ON v.id = r.visita_id
        JOIN form_questions fq         ON fq.id = r.id_form_question
        WHERE v.id_formulario = ?
          AND fq.id_question_type = 7
          AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
    ";

    if ($start_date !== '') { $countSql.=" AND DATE(m.created_at) >= ?"; $countTypes.="s"; $countParams[]=$start_date; }
    if ($end_date   !== '') { $countSql.=" AND DATE(m.created_at) <= ?"; $countTypes.="s"; $countParams[]=$end_date; }
    if ($user_id > 0)       { $countSql.=" AND r.id_usuario = ?";       $countTypes.="i"; $countParams[]=$user_id; }
    if ($id_question !== ''){ $countSql.=" AND fq.id = ?";              $countTypes.="i"; $countParams[]=(int)$id_question; }

    $countSql .= "
        GROUP BY
          v.id_formulario,
          r.id_usuario,
          JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1')),
          COALESCE(v.id_local, m.id_local, r.id_local)
        HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
      ) AS x
    ";

    $stmtCount = $conn->prepare($countSql);
    if (!$stmtCount) die("<div class='alert alert-danger'>Error conteo (duplicados): ".htmlspecialchars($conn->error)."</div>");
    $bindCount = array_merge([$countTypes], $countParams);
    $bindCount = refValues($bindCount);
    call_user_func_array([$stmtCount,'bind_param'],$bindCount);
    $stmtCount->execute();
    $stmtCount->bind_result($totalRows);
    $stmtCount->fetch();
    $stmtCount->close();

} else {
    /* --------- VISTA NORMAL (galería por sesiones, código original) --------- */
    $params = [$gap, $empresa_id, $formulario_id];
    $types  = "iii";

    $sql = "
      SELECT
        MIN(s.id) AS foto_id,
        GROUP_CONCAT(s.answer_text SEPARATOR '||') AS urls,
        MAX(s.created_at) AS fechaSubida,
        fq.question_text AS pregunta,
        u.usuario AS usuario,
        MAX(s.visita_id) AS visita_id,
        MAX(s.local_id_effective) AS local_id_effective
      FROM (
        SELECT t.*,
          @grp := (
            CASE
              WHEN (t.visita_id IS NOT NULL AND t.visita_id > 0) THEN
                IF(@prev_visita = t.visita_id AND @prev_q = t.id_form_question, @grp, @grp + 1)
              ELSE
                IF(
                  @prev_user = t.id_usuario
                  AND @prev_q = t.id_form_question
                  AND TIMESTAMPDIFF(MINUTE, @prev_time, t.created_at) <= ?
                  AND DATE(@prev_time) = DATE(t.created_at),
                  @grp, @grp + 1
                )
            END
          ) AS session_id,
          @prev_visita := t.visita_id,
          @prev_user := t.id_usuario,
          @prev_q := t.id_form_question,
          @prev_time := t.created_at
        FROM (
          SELECT
            fqr.id,
            fqr.answer_text,
            fqr.created_at,
            fqr.id_usuario,
            fqr.id_form_question,
            fqr.visita_id,
            COALESCE(fqr.id_local, v.id_local, 0) AS local_id_effective
          FROM form_question_responses fqr
          JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f      ON f.id  = fq.id_formulario AND f.id_empresa = ?
          LEFT JOIN visita v     ON v.id  = fqr.visita_id
          WHERE fq.id_formulario = ?
            AND fq.id_question_type = 7
            AND fqr.answer_text REGEXP '\\\\.(jpe?g|png|gif|webp)(\\\\?.*)?$'
    ";

    if ($start_date !== '') { $sql.=" AND DATE(fqr.created_at) >= ?"; $types.="s"; $params[]=$start_date; }
    if ($end_date   !== '') { $sql.=" AND DATE(fqr.created_at) <= ?"; $types.="s"; $params[]=$end_date; }
    if ($user_id > 0)       { $sql.=" AND fqr.id_usuario = ?";       $types.="i"; $params[]=$user_id; }
    if ($id_question !== ''){ $sql.=" AND fq.id = ?";                 $types.="i"; $params[]=(int)$id_question; }

    $sql .= "
          ORDER BY COALESCE(fqr.visita_id,0), fqr.id_usuario, fqr.id_form_question, fqr.created_at
        ) AS t
        JOIN (SELECT @grp := 0, @prev_visita := NULL, @prev_user := NULL, @prev_q := NULL, @prev_time := NULL) vars
      ) AS s
      JOIN form_questions fq ON fq.id = s.id_form_question
      JOIN usuario u         ON u.id = s.id_usuario
      GROUP BY s.id_usuario, s.id_form_question, s.session_id
      ORDER BY MAX(s.created_at) DESC
      LIMIT ? OFFSET ?
    ";
    $types.="ii"; $params[]=$limit; $params[]=$offset;

    $stmtMain = $conn->prepare($sql);
    if (!$stmtMain) die("<div class='alert alert-danger'>Error preparación: ".htmlspecialchars($conn->error)."</div>");
    $bindParams = array_merge([$types], $params);
    $bindParams = refValues($bindParams);
    call_user_func_array([$stmtMain,'bind_param'],$bindParams);
    $stmtMain->execute();
    $result = $stmtMain->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = explode('||',$row['urls'] ?? '');
        $fixed=[];
        foreach ($raw as $u) { if ($u==='') continue; $fixed[] = fixUrl($u,$base_url); }
        $row['urls']         = implode('||',$fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0] ?? $base_url.'assets/images/placeholder.png';

        $row['local_id_effective'] = (int)($row['local_id_effective'] ?? 0);
        if ($requiereLocal && $row['local_id_effective'] > 0) {
            $localIds[$row['local_id_effective']] = true;
        }

        $data[] = $row;
    }
    $stmtMain->close();

    /* =========================================
     * Conteo total para paginación (misma lógica visita/gap)
     * ======================================= */
    $countParams = [$gap, $empresa_id, $formulario_id];
    $countTypes  = "iii";

    $countSql = "
      SELECT COUNT(*) AS total
      FROM (
        SELECT s.id_usuario, s.id_form_question, s.session_id
        FROM (
          SELECT t.*,
            @grp := (
              CASE
                WHEN (t.visita_id IS NOT NULL AND t.visita_id > 0) THEN
                  IF(@prev_visita = t.visita_id AND @prev_q = t.id_form_question, @grp, @grp + 1)
                ELSE
                  IF(
                    @prev_user = t.id_usuario
                    AND @prev_q = t.id_form_question
                    AND TIMESTAMPDIFF(MINUTE, @prev_time, t.created_at) <= ?
                    AND DATE(@prev_time) = DATE(t.created_at),
                    @grp, @grp + 1
                  )
              END
            ) AS session_id,
            @prev_visita := t.visita_id,
            @prev_user := t.id_usuario,
            @prev_q := t.id_form_question,
            @prev_time := t.created_at
          FROM (
            SELECT
              fqr.id,
              fqr.created_at,
              fqr.id_usuario,
              fqr.id_form_question,
              fqr.visita_id
            FROM form_question_responses fqr
            JOIN form_questions fq ON fq.id = fqr.id_form_question
            JOIN formulario f      ON f.id  = fq.id_formulario AND f.id_empresa = ?
            WHERE fq.id_formulario = ?
              AND fq.id_question_type = 7
              AND fqr.answer_text REGEXP '\\\\.(jpe?g|png|gif|webp)(\\\\?.*)?$'
    ";

    if ($start_date !== '') { $countSql.=" AND DATE(fqr.created_at) >= ?"; $countTypes.="s"; $countParams[]=$start_date; }
    if ($end_date   !== '') { $countSql.=" AND DATE(fqr.created_at) <= ?"; $countTypes.="s"; $countParams[]=$end_date; }
    if ($user_id > 0)       { $countSql.=" AND fqr.id_usuario = ?";       $countTypes.="i"; $countParams[]=$user_id; }
    if ($id_question !== ''){ $countSql.=" AND fq.id = ?";                 $countTypes.="i"; $countParams[]=(int)$id_question; }

    $countSql .= "
            ORDER BY COALESCE(fqr.visita_id,0), fqr.id_usuario, fqr.id_form_question, fqr.created_at
          ) AS t
          JOIN (SELECT @grp := 0, @prev_visita := NULL, @prev_user := NULL, @prev_q := NULL, @prev_time := NULL) vars
        ) AS s
        GROUP BY s.id_usuario, s.id_form_question, s.session_id
      ) z
    ";

    $stmtCount = $conn->prepare($countSql);
    if (!$stmtCount) die("<div class='alert alert-danger'>Error conteo: ".htmlspecialchars($conn->error)."</div>");
    $bindCount = array_merge([$countTypes], $countParams);
    $bindCount = refValues($bindCount);
    call_user_func_array([$stmtCount,'bind_param'],$bindCount);
    $stmtCount->execute();
    $stmtCount->bind_result($totalRows);
    $stmtCount->fetch();
    $stmtCount->close();
}

$totalPages = max(1, (int)ceil($totalRows / $limit));

/* =========================================
 * Mapa de locales (solo si la campaña requiere local)
 * ======================================= */
$localMap = [];
if ($requiereLocal && !empty($localIds)) {
    $ids = array_keys($localIds);
    $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $typesLoc = str_repeat('i', count($ids));
    $sqlLoc = "SELECT id, codigo, nombre, direccion FROM local WHERE id IN ($inPlaceholders)";
    $stmtLoc = $conn->prepare($sqlLoc);
    $bind = array_merge([$typesLoc], $ids);
    $bind = refValues($bind);
    call_user_func_array([$stmtLoc,'bind_param'], $bind);
    $stmtLoc->execute();
    $resLoc = $stmtLoc->get_result();
    while ($l = $resLoc->fetch_assoc()) {
        $localMap[(int)$l['id']] = [
            'codigo'    => (string)($l['codigo'] ?? ''),
            'nombre'    => (string)($l['nombre'] ?? ''),
            'direccion' => (string)($l['direccion'] ?? '')
        ];
    }
    $stmtLoc->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Galería Complementaria — <?= htmlspecialchars($campanaNombre,ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .thumbnail { width:100px; height:100px; object-fit:cover; border-radius:5px; }
    .custom-img-cell { width:130px; position:relative; }
    .badge-count { position:absolute; top:5px; right:5px; background:rgba(0,0,0,.6); color:#fff; font-size:.8rem; padding:.2rem .4rem; border-radius:50%; }
    .pagination { flex-wrap:wrap; justify-content:center; gap:5px; }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>Galería Complementaria</h2>
  <p class="text-muted mb-3"><?= htmlspecialchars($campanaNombre,ENT_QUOTES) ?> (ID <?= (int)$formulario_id ?>)</p>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $view_mode === 'galeria' ? 'active' : '' ?>"
         href="<?= htmlspecialchars(buildViewModeUrl('galeria'), ENT_QUOTES) ?>">
        Todas las fotos
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view_mode === 'duplicados' ? 'active' : '' ?>"
         href="<?= htmlspecialchars(buildViewModeUrl('duplicados'), ENT_QUOTES) ?>">
        Casos duplicados (fotos repetidas)
      </a>
    </li>
  </ul>

  <form id="filterForm" method="GET" class="form-inline mb-3">
    <input type="hidden" name="id" value="<?= $formulario_id ?>">
    <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode, ENT_QUOTES) ?>">

    <label class="mr-2">Desde:</label>
    <input type="date" name="start_date" class="form-control mr-2" value="<?= htmlspecialchars($start_date) ?>">
    <label class="mr-2">Hasta:</label>
    <input type="date" name="end_date" class="form-control mr-2" value="<?= htmlspecialchars($end_date) ?>">

    <label class="mr-2">Usuario:</label>
    <select name="user_id" class="form-control mr-2">
      <option value="0">-- Todos --</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= $u['id']?>" <?= $u['id']==$user_id?'selected':'' ?>>
          <?= htmlspecialchars($u['usuario']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="mr-2">Pregunta:</label>
    <select name="id_question" class="form-control mr-2">
      <option value="">-- Todas --</option>
      <?php foreach ($preguntasDisponibles as $p): ?>
        <option value="<?= $p['id']?>" <?= $p['id']==$id_question?'selected':'' ?>>
          <?= htmlspecialchars($p['question_text']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- Opcional: ajustar lapso (min) sin tocar código (solo afecta modo galería) -->
    <label class="mr-2">Lapso visita (min):</label>
    <input type="number" min="1" name="gap" class="form-control mr-2" style="width:90px"
           value="<?= htmlspecialchars($gap) ?>">

    <button type="submit" class="btn btn-primary d-none">Filtrar</button>
  </form>

  <div class="d-flex align-items-center mb-2">
    <label class="mr-2">Mostrar:</label>
    <select id="limitSelect" class="form-control" style="width:auto">
      <?php foreach ([10,25,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= $n==$limit?'selected':'' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
    <span class="ml-2">registros</span>
  </div>

  <button id="btnDownloadSelected" class="btn btn-success mb-3">Descargar seleccionadas</button>
  <button id="btnDownloadAll" class="btn btn-warning mb-3 ml-2">Descargar todas las fotos</button>
  <?php if ($view_mode === 'duplicados'): ?>
    <button id="btnExportCsv" class="btn btn-info mb-3 ml-2">Exportar CSV duplicados</button>
  <?php endif; ?>

  <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
  </form>

  <table class="table table-bordered table-hover">
    <thead class="thead-light">
      <tr>
        <th><input type="checkbox" id="selectAll"></th>
        <th>#</th>
        <th>Imagen</th>
        <th>Pregunta / Nota</th>
        <th>Usuario</th>
        <?php if ($requiereLocal): ?>
          <th>Local</th>
          <th>Dirección</th>
        <?php endif; ?>

        <?php if ($view_mode === 'duplicados'): ?>
          <th>Veces</th>
          <th>Días distintos</th>
          <th>Fechas</th>
          <th>Primera / Última subida</th>
        <?php else: ?>
          <th>Fecha Subida</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($data)): ?>
      <tr><td colspan="<?= $requiereLocal ? ($view_mode === 'duplicados' ? 11 : 8) : ($view_mode === 'duplicados' ? 9 : 6) ?>" class="text-center">Sin fotos</td></tr>
    <?php else: ?>
      <?php $i=$offset+1; foreach ($data as $row): ?>
        <?php
          $safeUsuario  = preg_replace('/[^a-zA-Z0-9]/','_', $row['usuario']);
          $safePregunta = preg_replace('/[^a-zA-Z0-9]/','_', $row['pregunta'] ?? 'encuesta');
          $phpPrefix    = "{$safeUsuario}_{$safePregunta}";
          $thumb        = htmlspecialchars($row['thumbnail'], ENT_QUOTES);
          $badge        = (int)$row['photos_count'];
          $fecha        = isset($row['fechaSubida']) ? formatearFecha($row['fechaSubida']) : '';

          // Local (si aplica)
          $localLabel = 'N/A';
          $localDir   = 'N/A';
          if ($requiereLocal) {
              $lid = (int)($row['local_id_effective'] ?? 0);
              if ($lid > 0 && isset($localMap[$lid])) {
                  $codigo = trim($localMap[$lid]['codigo']);
                  $nombre = trim($localMap[$lid]['nombre']);
                  $direccion = trim($localMap[$lid]['direccion']);
                  $localLabel = $codigo !== '' ? ($codigo.' - '.$nombre) : $nombre;
                  $localDir   = $direccion !== '' ? $direccion : '—';
              }
          }
        ?>
        <tr>
          <td>
            <input type="checkbox" class="imgCheckbox"
                   data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>"
                   data-prefix="<?= $phpPrefix ?>">
          </td>
          <td><?= $i ?></td>
          <td class="custom-img-cell">
            <span class="badge-count"><?= $badge ?></span>
            <img src="<?= $thumb ?>" class="thumbnail img-click" loading="lazy" decoding="async"
                 data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>">
          </td>
          <td>
            <?php if ($view_mode === 'duplicados'): ?>
              <strong>SHA1:</strong> <?= htmlspecialchars($row['sha1'] ?? '', ENT_QUOTES) ?><br>
              <small>Imagen usada múltiples días en esta campaña.</small>
            <?php else: ?>
              <?= htmlspecialchars($row['pregunta'], ENT_QUOTES) ?>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($row['usuario'], ENT_QUOTES) ?></td>
          <?php if ($requiereLocal): ?>
            <td><?= htmlspecialchars($localLabel, ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($localDir,   ENT_QUOTES) ?></td>
          <?php endif; ?>

          <?php if ($view_mode === 'duplicados'): ?>
            <td><?= (int)($row['total_subidas'] ?? 0) ?></td>
            <td><?= (int)($row['dias_distintos'] ?? 0) ?></td>
            <td><?= htmlspecialchars($row['fechas'] ?? '', ENT_QUOTES) ?></td>
            <td>
              <?= formatearFecha($row['primera_subida'] ?? null) ?>
              <br>
              <?= formatearFecha($row['ultima_subida'] ?? null) ?>
            </td>
          <?php else: ?>
            <td><?= $fecha ?></td>
          <?php endif; ?>
        </tr>
      <?php $i++; endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages>1): ?>
    <nav><ul class="pagination">
      <?php if ($page>1): ?>
        <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($page-1) ?>">Anterior</a></li>
      <?php else: ?>
        <li class="page-item disabled"><span class="page-link">Anterior</span></li>
      <?php endif; ?>
      <?php for($p=1;$p<=$totalPages;$p++): ?>
        <?php if ($p==$page): ?>
          <li class="page-item active"><span class="page-link"><?= $p ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($p) ?>"><?= $p ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page<$totalPages): ?>
        <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($page+1) ?>">Siguiente</a></li>
      <?php else: ?>
        <li class="page-item disabled"><span class="page-link">Siguiente</span></li>
      <?php endif; ?>
    </ul></nav>
  <?php endif; ?>
</div>

<!-- Modal imágenes -->
<div class="modal fade" id="fullSizeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0 text-center" id="modalBodyImgs"></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Modal con todas las fotos de la fila
  $(document).on('click', '.thumbnail.img-click', function(){
    var base = '<?= $base_url ?>';
    var urls = $(this).data('urls').split('||');
    var $body = $('#modalBodyImgs').empty();
    urls.forEach(function(u){
      if(!u) return;
      var src = /^https?:\/\//.test(u) ? u : base + u.replace(/^\/+/, '');
      $body.append('<img src="'+src+'" class="img-fluid mb-2" style="max-height:80vh" loading="lazy" decoding="async">');
    });
    $('#fullSizeModal').modal('show');
  });

  // Select all
  $('#selectAll').on('change', function(){ $('.imgCheckbox').prop('checked', $(this).prop('checked')); });

  // Descargar seleccionadas (usa download_zip.php existente)
  $('#btnDownloadSelected').click(function(){
    var toZip = [];
    $('.imgCheckbox:checked').each(function(){
      var urls=$(this).data('urls').split('||'); var prefix=$(this).data('prefix');
      urls.forEach(function(u){ if(!u) return; var name = prefix + '_' + u.split('/').pop(); toZip.push({url:u, filename:name}); });
    });
    if (!toZip.length) return alert('Selecciona al menos una fila.');
    $.ajax({
      url: 'download_zip.php', method: 'POST',
      data: { jsonFotos: JSON.stringify(toZip) }, xhrFields:{responseType:'blob'},
      success: function(data,status,xhr){
        var disp = xhr.getResponseHeader('Content-Disposition')||'';
        var fname = (disp.match(/filename[^;=\n]*=\s*([\'\"]?)([^\'\"\n]*)/)||[])[2] || 'fotos.zip';
        var blob = new Blob([data],{type:'application/zip'}), link=document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download=fname; document.body.appendChild(link);
        link.click(); link.remove();
      },
      error: function(_,__,e){ alert('Error al crear ZIP: '+e); }
    });
  });

  // Descargar todas con filtros actuales (GET ?action=all&view=complementaria)
  $('#btnDownloadAll').click(function(){
    const params = new URLSearchParams(window.location.search);
    params.set('action','all');
    params.set('view','complementaria');
    const url = 'download_zip.php?' + params.toString();
    $.ajax({
      url, method:'GET', xhrFields:{responseType:'blob'},
      success(data,status,xhr){
        let fname='fotos_todas.zip';
        const disp = xhr.getResponseHeader('Content-Disposition')||'';
        const m = disp.match(/filename[^;=\n]*=\s*([\'\"]?)([^\'\"\n]*)/); if (m && m[2]) fname=m[2];
        const blob=new Blob([data],{type:'application/zip'}); const link=document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download=fname; document.body.appendChild(link);
        link.click(); link.remove();
      },
      error(_,__,e){ alert('Error al crear ZIP completo: '+e); }
    });
  });

  // Exportar CSV de duplicados
  $('#btnExportCsv').click(function(){
    const url = new URL(window.location.href);
    url.searchParams.set('export','csv');
    window.location.href = url.toString();
  });

  // Selector de límite + auto-submit filtros
  (function(){
    $('#limitSelect').val('<?= $limit ?>').on('change', function(){
      var url = new URL(window.location.href);
      url.searchParams.set('limit', $(this).val());
      url.searchParams.set('page', 1);
      window.location.href = url.toString();
    });
    $('#filterForm').on('change','input,select', function(){ $('#filterForm').submit(); });
  })();
</script>
</body>
</html>
<?php $conn->close(); ?>

<?php
session_start();

/* =========================================
 * Headers AJAX / no cache
 * ======================================= */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* =========================================
 * Utilidades
 * ======================================= */
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $k => $v) {
            $refs[$k] = &$arr[$k];
        }
        return $refs;
    }
    return $arr;
}

function fixUrl($url, $base_url) {
    if (!$url) return '';
    if (preg_match('#^https?://#i', $url)) return $url;

    $prefixes = ['/visibility2/app/', '../app/'];
    foreach ($prefixes as $p) {
        if (strncmp($url, $p, strlen($p)) === 0) {
            $url = substr($url, strlen($p));
            break;
        }
    }

    $url = ltrim($url, '/');
    return rtrim($base_url, '/') . '/' . $url;
}

function formatearFecha($f) {
    if (!$f || $f === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($f);
    return $ts ? date('d/m/Y H:i:s', $ts) : '—';
}

/* =========================================
 * Includes / seguridad
 * ======================================= */
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    echo '<div class="empty-state">ID de campaña inválido.</div>';
    exit;
}

$formulario_id = (int) $_GET['id'];
$empresa_id    = (int) ($_SESSION['empresa_id'] ?? 0);

if ($empresa_id <= 0) {
    echo '<div class="empty-state">Acceso inválido.</div>';
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        tipo,
        COALESCE(nombre, CONCAT('Campaña #', id)) AS campanaNombre,
        COALESCE(iw_requiere_local, 0) AS requiereLocal
    FROM formulario
    WHERE id = ? AND id_empresa = ?
    LIMIT 1
");

if (!$stmt) {
    echo '<div class="empty-state">Error al validar campaña.</div>';
    exit;
}

$stmt->bind_param("ii", $formulario_id, $empresa_id);
$stmt->execute();
$stmt->bind_result($tipoForm, $campanaNombre, $requiereLocal);

if (!$stmt->fetch()) {
    $stmt->close();
    echo '<div class="empty-state">Formulario no encontrado o no pertenece a tu empresa.</div>';
    exit;
}
$stmt->close();

$requiereLocal = ((int)$requiereLocal === 1);

if ((int)$tipoForm !== 2) {
    echo '<div class="empty-state">Este módulo es solo para campañas complementarias.</div>';
    exit;
}

/* =========================================
 * Filtros / paginación
 * ======================================= */
$start_date  = trim($_GET['start_date'] ?? '');
$end_date    = trim($_GET['end_date'] ?? '');
$user_id     = (int) ($_GET['user_id'] ?? 0);
$id_question = trim($_GET['id_question'] ?? '');
$limit       = max(1, (int) ($_GET['limit'] ?? 25));
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $limit;
$local_codigo = trim($_GET['local_codigo'] ?? '');
$gap         = max(1, (int) ($_GET['gap'] ?? 2));

$view_mode = trim($_GET['view_mode'] ?? 'galeria');
if (!in_array($view_mode, ['galeria', 'duplicados'], true)) {
    $view_mode = 'galeria';
}

$base_url = "https://visibility.cl/visibility2/app/";

/* =========================================
 * Solo cargar si hay filtros reales
 * ======================================= */
$hasFilters = (
    $user_id > 0 ||
    $id_question !== '' ||
    $local_codigo !== '' ||
    $start_date !== '' ||
    $end_date !== ''
);

if (!$hasFilters) {
    echo '<div class="empty-state">Aplica filtros para visualizar fotos y datos.</div>';
    exit;
}

/* =========================================
 * Consulta principal
 * ======================================= */
$data      = [];
$localIds  = [];
$totalRows = 0;

if ($view_mode === 'duplicados') {

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
        JOIN visita v ON v.id = r.visita_id
        JOIN form_questions fq ON fq.id = r.id_form_question
        LEFT JOIN usuario u ON u.id = r.id_usuario
        LEFT JOIN local l ON l.id = COALESCE(v.id_local, m.id_local, r.id_local)
        WHERE v.id_formulario = ?
          AND fq.id_question_type = 7
          AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
    ";

    if ($start_date !== '') {
        $sql .= " AND DATE(m.created_at) >= ?";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(m.created_at) <= ?";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($user_id > 0) {
        $sql .= " AND r.id_usuario = ?";
        $types .= "i";
        $params[] = $user_id;
    }
    if ($id_question !== '' && ctype_digit($id_question)) {
        $sql .= " AND fq.id = ?";
        $types .= "i";
        $params[] = (int) $id_question;
    }
    if ($requiereLocal && $local_codigo !== '') {
        $sql .= " AND l.codigo = ?";
        $types .= "s";
        $params[] = $local_codigo;
    }

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
    if (!$stmtMain) {
        echo '<div class="empty-state">Error preparando galería de duplicados.</div>';
        exit;
    }

    $bindParams = array_merge([$types], $params);
    $bindParams = refValues($bindParams);
    call_user_func_array([$stmtMain, 'bind_param'], $bindParams);
    $stmtMain->execute();
    $result = $stmtMain->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = explode('||', $row['urls'] ?? '');
        $fixed = [];

        foreach ($raw as $u) {
            if ($u === '') continue;
            $fixed[] = fixUrl($u, $base_url);
        }

        $row['urls']         = implode('||', $fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0] ?? ($base_url . 'assets/images/placeholder.png');
        $row['local_id_effective'] = (int)($row['local_id_effective'] ?? 0);

        if ($requiereLocal && $row['local_id_effective'] > 0) {
            $localIds[$row['local_id_effective']] = true;
        }

        $data[] = $row;
    }
    $stmtMain->close();

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
            JOIN visita v ON v.id = r.visita_id
            JOIN form_questions fq ON fq.id = r.id_form_question
            LEFT JOIN local l ON l.id = COALESCE(v.id_local, m.id_local, r.id_local)
            WHERE v.id_formulario = ?
              AND fq.id_question_type = 7
              AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
    ";

    if ($start_date !== '') {
        $countSql .= " AND DATE(m.created_at) >= ?";
        $countTypes .= "s";
        $countParams[] = $start_date;
    }
    if ($end_date !== '') {
        $countSql .= " AND DATE(m.created_at) <= ?";
        $countTypes .= "s";
        $countParams[] = $end_date;
    }
    if ($user_id > 0) {
        $countSql .= " AND r.id_usuario = ?";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
    if ($id_question !== '' && ctype_digit($id_question)) {
        $countSql .= " AND fq.id = ?";
        $countTypes .= "i";
        $countParams[] = (int) $id_question;
    }
    if ($requiereLocal && $local_codigo !== '') {
        $countSql .= " AND l.codigo = ?";
        $countTypes .= "s";
        $countParams[] = $local_codigo;
    }

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
    if (!$stmtCount) {
        echo '<div class="empty-state">Error contando duplicados.</div>';
        exit;
    }

    $bindCount = array_merge([$countTypes], $countParams);
    $bindCount = refValues($bindCount);
    call_user_func_array([$stmtCount, 'bind_param'], $bindCount);
    $stmtCount->execute();
    $stmtCount->bind_result($totalRows);
    $stmtCount->fetch();
    $stmtCount->close();

} else {

    $params = [$gap, $empresa_id, $formulario_id];
    $types  = "iii";

    $sql = "
        SELECT
            MIN(s.id) AS foto_id,
            GROUP_CONCAT(s.answer_text ORDER BY s.created_at SEPARATOR '||') AS urls,
            GROUP_CONCAT(s.id          ORDER BY s.created_at SEPARATOR '||') AS resp_ids,
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
                JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
                LEFT JOIN visita v ON v.id = fqr.visita_id
                LEFT JOIN local l ON l.id = COALESCE(fqr.id_local, v.id_local)
                WHERE fq.id_formulario = ?
                  AND fq.id_question_type = 7
                  AND fqr.answer_text REGEXP '\\\\.(jpe?g|png|gif|webp)(\\\\?.*)?$'
    ";

    if ($start_date !== '') {
        $sql .= " AND DATE(fqr.created_at) >= ?";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fqr.created_at) <= ?";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($user_id > 0) {
        $sql .= " AND fqr.id_usuario = ?";
        $types .= "i";
        $params[] = $user_id;
    }
    if ($id_question !== '' && ctype_digit($id_question)) {
        $sql .= " AND fq.id = ?";
        $types .= "i";
        $params[] = (int) $id_question;
    }
    if ($requiereLocal && $local_codigo !== '') {
        $sql .= " AND l.codigo = ?";
        $types .= "s";
        $params[] = $local_codigo;
    }

    $sql .= "
                ORDER BY COALESCE(fqr.visita_id,0), fqr.id_usuario, fqr.id_form_question, fqr.created_at
            ) AS t
            JOIN (SELECT @grp := 0, @prev_visita := NULL, @prev_user := NULL, @prev_q := NULL, @prev_time := NULL) vars
        ) AS s
        JOIN form_questions fq ON fq.id = s.id_form_question
        JOIN usuario u ON u.id = s.id_usuario
        GROUP BY s.id_usuario, s.id_form_question, s.session_id
        ORDER BY MAX(s.created_at) DESC
        LIMIT ? OFFSET ?
    ";

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmtMain = $conn->prepare($sql);
    if (!$stmtMain) {
        echo '<div class="empty-state">Error preparando galería.</div>';
        exit;
    }

    $bindParams = array_merge([$types], $params);
    $bindParams = refValues($bindParams);
    call_user_func_array([$stmtMain, 'bind_param'], $bindParams);
    $stmtMain->execute();
    $result = $stmtMain->get_result();

    while ($row = $result->fetch_assoc()) {
        $raw = explode('||', $row['urls'] ?? '');
        $fixed = [];

        foreach ($raw as $u) {
            if ($u === '') continue;
            $fixed[] = fixUrl($u, $base_url);
        }

        $row['urls']         = implode('||', $fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0] ?? ($base_url . 'assets/images/placeholder.png');
        $row['local_id_effective'] = (int)($row['local_id_effective'] ?? 0);

        if ($requiereLocal && $row['local_id_effective'] > 0) {
            $localIds[$row['local_id_effective']] = true;
        }

        $data[] = $row;
    }
    $stmtMain->close();

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
                    JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
                    LEFT JOIN visita v ON v.id = fqr.visita_id
                    LEFT JOIN local l ON l.id = COALESCE(fqr.id_local, v.id_local)
                    WHERE fq.id_formulario = ?
                      AND fq.id_question_type = 7
                      AND fqr.answer_text REGEXP '\\\\.(jpe?g|png|gif|webp)(\\\\?.*)?$'
    ";

    if ($start_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) >= ?";
        $countTypes .= "s";
        $countParams[] = $start_date;
    }
    if ($end_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) <= ?";
        $countTypes .= "s";
        $countParams[] = $end_date;
    }
    if ($user_id > 0) {
        $countSql .= " AND fqr.id_usuario = ?";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
    if ($id_question !== '' && ctype_digit($id_question)) {
        $countSql .= " AND fq.id = ?";
        $countTypes .= "i";
        $countParams[] = (int) $id_question;
    }
    if ($requiereLocal && $local_codigo !== '') {
        $countSql .= " AND l.codigo = ?";
        $countTypes .= "s";
        $countParams[] = $local_codigo;
    }

    $countSql .= "
                    ORDER BY COALESCE(fqr.visita_id,0), fqr.id_usuario, fqr.id_form_question, fqr.created_at
                ) AS t
                JOIN (SELECT @grp := 0, @prev_visita := NULL, @prev_user := NULL, @prev_q := NULL, @prev_time := NULL) vars
            ) AS s
            GROUP BY s.id_usuario, s.id_form_question, s.session_id
        ) z
    ";

    $stmtCount = $conn->prepare($countSql);
    if (!$stmtCount) {
        echo '<div class="empty-state">Error contando resultados.</div>';
        exit;
    }

    $bindCount = array_merge([$countTypes], $countParams);
    $bindCount = refValues($bindCount);
    call_user_func_array([$stmtCount, 'bind_param'], $bindCount);
    $stmtCount->execute();
    $stmtCount->bind_result($totalRows);
    $stmtCount->fetch();
    $stmtCount->close();
}

$totalPages = max(1, (int) ceil(($totalRows ?: 0) / $limit));

/* =========================================
 * Mapa de locales
 * ======================================= */
$localMap = [];

if ($requiereLocal && !empty($localIds)) {
    $ids = array_keys($localIds);
    $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $typesLoc = str_repeat('i', count($ids));

    $sqlLoc = "SELECT id, codigo, nombre, direccion FROM local WHERE id IN ($inPlaceholders)";
    $stmtLoc = $conn->prepare($sqlLoc);

    if ($stmtLoc) {
        $bind = array_merge([$typesLoc], $ids);
        $bind = refValues($bind);
        call_user_func_array([$stmtLoc, 'bind_param'], $bind);
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
}
?>

<div class="table-responsive">
    <table class="table table-hover">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>#</th>
                <th>Imagen</th>
                <th><?= $view_mode === 'duplicados' ? 'Nota' : 'Pregunta' ?></th>
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
                    <th>Fecha subida</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="<?= $requiereLocal ? ($view_mode === 'duplicados' ? 11 : 8) : ($view_mode === 'duplicados' ? 9 : 6) ?>" class="empty-state">
                        Sin fotos disponibles con los filtros aplicados
                    </td>
                </tr>
            <?php else: ?>
                <?php $i = $offset + 1; ?>
                <?php foreach ($data as $row): ?>
                    <?php
                        $safeUsuario  = preg_replace('/[^a-zA-Z0-9]/', '_', $row['usuario'] ?? 'usuario');
                        $safePregunta = preg_replace('/[^a-zA-Z0-9]/', '_', $row['pregunta'] ?? 'encuesta');
                        $phpPrefix    = "{$safeUsuario}_{$safePregunta}";
                        $thumb        = htmlspecialchars($row['thumbnail'] ?? '', ENT_QUOTES);
                        $badge        = (int)($row['photos_count'] ?? 0);
                        $fecha        = isset($row['fechaSubida']) ? formatearFecha($row['fechaSubida']) : '';

                        $localLabel = 'N/A';
                        $localDir   = 'N/A';

                        if ($requiereLocal) {
                            $lid = (int)($row['local_id_effective'] ?? 0);
                            if ($lid > 0 && isset($localMap[$lid])) {
                                $codigo    = trim($localMap[$lid]['codigo']);
                                $nombre    = trim($localMap[$lid]['nombre']);
                                $direccion = trim($localMap[$lid]['direccion']);

                                $localLabel = $codigo !== '' ? ($codigo . ' - ' . $nombre) : $nombre;
                                $localDir   = $direccion !== '' ? $direccion : '—';
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="imgCheckbox"
                                   data-urls="<?= htmlspecialchars($row['urls'] ?? '', ENT_QUOTES) ?>"
                                   data-prefix="<?= htmlspecialchars($phpPrefix, ENT_QUOTES) ?>">
                        </td>

                        <td><strong><?= $i ?></strong></td>

                        <td class="custom-img-cell">
                            <span class="badge-count"><?= $badge ?></span>
                            <img src="<?= $thumb ?>"
                                 class="thumbnail img-click"
                                 loading="lazy"
                                 decoding="async"
                                 data-urls="<?= htmlspecialchars($row['urls'] ?? '', ENT_QUOTES) ?>"
                                 data-resp-ids="<?= htmlspecialchars($row['resp_ids'] ?? '', ENT_QUOTES) ?>">
                        </td>

                        <td>
                            <?php if ($view_mode === 'duplicados'): ?>
                                <div><strong>SHA1:</strong> <?= htmlspecialchars($row['sha1'] ?? '', ENT_QUOTES) ?></div>
                                <div class="meta-note">Imagen usada múltiples días en esta campaña.</div>
                            <?php else: ?>
                                <?= htmlspecialchars($row['pregunta'] ?? '', ENT_QUOTES) ?>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($row['usuario'] ?? '', ENT_QUOTES) ?></td>

                        <?php if ($requiereLocal): ?>
                            <td><?= htmlspecialchars($localLabel, ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($localDir, ENT_QUOTES) ?></td>
                        <?php endif; ?>

                        <?php if ($view_mode === 'duplicados'): ?>
                            <td><?= (int)($row['total_subidas'] ?? 0) ?></td>
                            <td><?= (int)($row['dias_distintos'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($row['fechas'] ?? '', ENT_QUOTES) ?></td>
                            <td>
                                <?= formatearFecha($row['primera_subida'] ?? null) ?><br>
                                <?= formatearFecha($row['ultima_subida'] ?? null) ?>
                            </td>
                        <?php else: ?>
                            <td><?= $fecha ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php $i++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination-wrap">
        <nav>
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link js-gallery-page" href="#" data-page="<?= $page - 1 ?>">Anterior</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link">Anterior</span>
                    </li>
                <?php endif; ?>

                <?php
                $startPag = max(1, $page - 2);
                $endPag   = min($totalPages, $page + 2);

                if ($startPag > 1): ?>
                    <li class="page-item">
                        <a class="page-link js-gallery-page" href="#" data-page="1">1</a>
                    </li>
                    <?php if ($startPag > 2): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startPag; $p <= $endPag; $p++): ?>
                    <?php if ($p == $page): ?>
                        <li class="page-item active">
                            <span class="page-link"><?= $p ?></span>
                        </li>
                    <?php else: ?>
                        <li class="page-item">
                            <a class="page-link js-gallery-page" href="#" data-page="<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPag < $totalPages): ?>
                    <?php if ($endPag < ($totalPages - 1)): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link js-gallery-page" href="#" data-page="<?= $totalPages ?>"><?= $totalPages ?></a>
                    </li>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link js-gallery-page" href="#" data-page="<?= $page + 1 ?>">Siguiente</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link">Siguiente</span>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
<?php endif; ?>

<?php $conn->close(); ?>
<?php
session_start();

// -------------------------------------------------------------
// 1) Funciones auxiliares
// -------------------------------------------------------------
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    return $arr;
}

function fixUrl($url, $base_url) {
    if (!$url) {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $prefixes = ['/visibility2/app/', '../app/'];
    foreach ($prefixes as $p) {
        if (substr($url, 0, strlen($p)) === $p) {
            $url = substr($url, strlen($p));
            break;
        }
    }

    $url = ltrim($url, '/');
    return rtrim($base_url, '/') . '/' . $url;
}

function formatearFecha($f) {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

// --- Parser robusto para "No gestionados" (LN) ---
function ln_extract_urls(string $txt): array {
    if ($txt === '' || $txt === null) {
        return [];
    }

    $urls = [];

    // A) Absolutas http/https
    if (preg_match_all('#https?://[^\s<>"\'()]+#i', $txt, $m1)) {
        $urls = array_merge($urls, $m1[0]);
    }

    // B) Relativas típicas bajo /app/uploads (con o sin 'visibility2')
    if (preg_match_all('#(?:^|[\s\|\(])(/(?:visibility2/)?app/uploads[^\s<>"\'()]+?\.(?:webp|jpe?g|png|gif))#i', $txt, $m2)) {
        $urls = array_merge($urls, $m2[1]);
    }

    // C) Prefijos /visibility2/app/ o ../app/
    if (preg_match_all('#(?:^|[\s\|])((?:/visibility2/app/|(?:\.\./)+app/)[^\s<>"\'()]+)#i', $txt, $m3)) {
        foreach ($m3[1] as $hit) {
            $urls[] = $hit;
        }
    }

    // D) Tokens "Foto:" / "Foto Mueble:" etc → capturar el siguiente token como ruta
    if (preg_match_all('/\bfoto[^:]*:\s*([^\s\|,;]+)/i', $txt, $m4)) {
        $urls = array_merge($urls, $m4[1]);
    }

    $urls = array_map(function ($u) {
        return rtrim($u, ".,;)]");
    }, $urls);

    return array_values(array_unique($urls));
}

function ln_clean_observacion(string $txt): string {
    $txt = preg_replace('#https?://[^\s<>"\'()]+#i', '', $txt);
    $txt = preg_replace('#/(?:visibility2/)?app/uploads[^\s<>"\'()]+#i', '', $txt);
    $txt = preg_replace('/\bfoto[^:]*:\s*/i', '', $txt);
    $txt = trim(preg_replace('/\s+/', ' ', $txt));
    return $txt;
}

function ln_detect_motivos(string $txt): array {
    $t = mb_strtolower($txt, 'UTF-8');
    $out = [];

    foreach ([
        'local_cerrado'   => 'Cerrado',
        'local no existe' => 'No existe',
        'local_no_existe' => 'No existe',
        'cancelado'       => 'Cancelado',
        'pendiente'       => 'Pendiente',
        'sin stock'       => 'Sin stock',
        'no autorizado'   => 'No autorizado',
    ] as $k => $label) {
        if (strpos($t, $k) !== false) {
            $out[] = $label;
        }
    }

    return array_values(array_unique($out));
}

// -------------------------------------------------------------
// 2) Includes y validaciones iniciales
// -------------------------------------------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("<div class='alert alert-danger'>ID de campaña inválido.</div>");
}
$formulario_id = (int)$_GET['id'];

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
    die("<div class='alert alert-danger'>Acceso inválido (empresa).</div>");
}

$stmtTipo = $conn->prepare("SELECT tipo FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1");
$stmtTipo->bind_param("ii", $formulario_id, $empresa_id);
$stmtTipo->execute();
$stmtTipo->bind_result($tipoForm);
if (!$stmtTipo->fetch()) {
    $stmtTipo->close();
    die("<div class='alert alert-danger'>Formulario no encontrado o no pertenece a tu empresa.</div>");
}
$stmtTipo->close();

// -------------------------------------------------------------
// 3) Parámetros de filtrado y paginación
// -------------------------------------------------------------
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$user_id      = intval($_GET['user_id'] ?? 0);
$material_id  = intval($_GET['material_id'] ?? 0);
$local_code   = trim($_GET['local_code'] ?? '');
$id_question  = $_GET['id_question'] ?? '';
$limit        = max(1, intval($_GET['limit'] ?? 25));
$page         = max(1, intval($_GET['page'] ?? 1));
$offset       = ($page - 1) * $limit;
$view         = $_GET['view'] ?? 'implementacion';

if ($tipoForm == 2) {
    $view = 'encuesta';
}

$base_url = "https://visibility.cl/visibility2/app/";

$start_dt = $start_date !== '' ? $start_date . ' 00:00:00' : null;
$end_dt   = $end_date !== '' ? $end_date . ' 23:59:59' : null;

function buildPaginationUrl(int $page): string {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// -------------------------------------------------------------
// 3.1) Detección de modo: 'gv' | 'legacy' | 'hybrid' (solo Implementación)
// -------------------------------------------------------------
$mode = 'gv';

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    $gvCount = 0;
    $sqlGvCnt = "
        SELECT COUNT(*)
        FROM gestion_visita gv
        JOIN formulario f
          ON f.id = gv.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        WHERE gv.id_formulario = ?
    ";

    if ($stmt = $conn->prepare($sqlGvCnt)) {
        $stmt->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
        $stmt->execute();
        $stmt->bind_result($gvCount);
        $stmt->fetch();
        $stmt->close();
    }

    $legacyOnlyCount = 0;
    $sqlLegacyOnlyCnt = "
        SELECT COUNT(*) FROM (
            SELECT fq.id
            FROM formularioQuestion fq
            JOIN formulario f
              ON f.id = fq.id_formulario
             AND f.id = ?
             AND f.id_empresa = ?
            JOIN fotoVisita fv
              ON fv.id_formularioQuestion = fq.id
            WHERE fq.id_formulario = ?
              AND NOT EXISTS (
                SELECT 1
                FROM gestion_visita gv2
                WHERE gv2.id_formulario = fq.id_formulario
                  AND gv2.id_formularioQuestion = fq.id
              )
            GROUP BY fq.id
        ) t
    ";

    if ($stmt = $conn->prepare($sqlLegacyOnlyCnt)) {
        $stmt->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
        $stmt->execute();
        $stmt->bind_result($legacyOnlyCount);
        $stmt->fetch();
        $stmt->close();
    }

    if ($gvCount > 0 && $legacyOnlyCount > 0) {
        $mode = 'hybrid';
    } elseif ($gvCount > 0) {
        $mode = 'gv';
    } else {
        $mode = 'legacy';
    }
}

// -------------------------------------------------------------
// 4) Listas para filtros
// -------------------------------------------------------------
$usuarios = [];

if ($tipoForm == 1 || $tipoForm == 3) {
    if ($view === 'implementacion') {
        $sqlUsers = "
            SELECT DISTINCT u.id, u.usuario
            FROM fotoVisita fv
            JOIN usuario u ON u.id = fv.id_usuario
            JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
            WHERE fq.id_formulario = ?
            ORDER BY u.usuario
        ";
        $stmtU = $conn->prepare($sqlUsers);
        $stmtU->bind_param("i", $formulario_id);

    } elseif ($view === 'locales_no_visitados') {
        $sqlUsers = "
            SELECT id, usuario
            FROM (
                SELECT DISTINCT u.id AS id, u.usuario AS usuario
                FROM gestion_visita gv
                JOIN formulario f
                  ON f.id = gv.id_formulario
                 AND f.id = ?
                 AND f.id_empresa = ?
                JOIN usuario u ON u.id = gv.id_usuario
                JOIN local l ON l.id = gv.id_local
                WHERE gv.id_formulario = ?
                  AND (
                    gv.estado_gestion IN ('cancelado','pendiente')
                    OR gv.observacion LIKE '%local_cerrado%'
                    OR gv.observacion LIKE '%local_no_existe%'
                    OR gv.observacion LIKE '%http%'
                    OR gv.observacion LIKE '%Foto:%'
                    OR gv.foto_url IS NOT NULL
                  )

                UNION

                SELECT DISTINCT u.id, u.usuario
                FROM formularioQuestion fq
                JOIN formulario f
                  ON f.id = fq.id_formulario
                 AND f.id = ?
                 AND f.id_empresa = ?
                JOIN usuario u ON u.id = fq.id_usuario
                JOIN local l ON l.id = fq.id_local
                WHERE fq.id_formulario = ?
                  AND (
                    fq.observacion LIKE '%local_cerrado%'
                    OR fq.observacion LIKE '%local_no_existe%'
                    OR fq.observacion LIKE '%mueble_no_esta_en_sala%'
                    OR fq.observacion LIKE '%mueble no esta en sala%'
                    OR fq.observacion LIKE '%mueble_no_existe%'
                    OR fq.observacion LIKE '%no existe%'
                    OR fq.observacion LIKE '%no_existe%'
                    OR fq.observacion LIKE '%no esta%'
                    OR fq.observacion LIKE '%no está%'
                    OR fq.observacion LIKE '%no se encuentra%'
                    OR fq.observacion LIKE '%pendiente%'
                    OR fq.observacion LIKE '%cancelado%'
                    OR fq.observacion LIKE '%http%'
                    OR fq.observacion LIKE '%Foto:%'
                  )
            ) t
            ORDER BY usuario
        ";
        $stmtU = $conn->prepare($sqlUsers);
        $stmtU->bind_param(
            "iiiiii",
            $formulario_id,
            $empresa_id,
            $formulario_id,
            $formulario_id,
            $empresa_id,
            $formulario_id
        );

    } else {
        $sqlUsers = "
            SELECT DISTINCT u.id, u.usuario
            FROM form_question_responses fqr
            JOIN usuario u ON u.id = fqr.id_usuario
            JOIN form_questions fq ON fq.id = fqr.id_form_question
            WHERE fq.id_formulario = ?
              AND fq.id_question_type = 7
              AND fqr.id_local <> 0
            ORDER BY u.usuario
        ";
        $stmtU = $conn->prepare($sqlUsers);
        $stmtU->bind_param("i", $formulario_id);
    }
} else {
    $sqlUsers = "
        SELECT DISTINCT u.id, u.usuario
        FROM form_question_responses fqr
        JOIN usuario u ON u.id = fqr.id_usuario
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        WHERE fq.id_formulario = ?
          AND fq.id_question_type = 7
          AND fqr.id_local = 0
        ORDER BY u.usuario
    ";
    $stmtU = $conn->prepare($sqlUsers);
    $stmtU->bind_param("i", $formulario_id);
}

$stmtU->execute();
$resU = $stmtU->get_result();
while ($r = $resU->fetch_assoc()) {
    $usuarios[] = $r;
}
$stmtU->close();

// Materiales (solo Implementación)
$materials = [];
if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    if ($mode === 'gv') {
        $stmtM = $conn->prepare("
            SELECT DISTINCT m.id, m.nombre
            FROM gestion_visita gv
            JOIN formulario f
              ON f.id = gv.id_formulario
             AND f.id = ?
             AND f.id_empresa = ?
            JOIN material m ON m.id = gv.id_material
            WHERE gv.id_formulario = ?
            ORDER BY m.nombre ASC
        ");
        $stmtM->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);

    } elseif ($mode === 'legacy') {
        $stmtM = $conn->prepare("
            SELECT DISTINCT m.id, m.nombre
            FROM formularioQuestion fq
            JOIN formulario f
              ON f.id = fq.id_formulario
             AND f.id = ?
             AND f.id_empresa = ?
            JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
            JOIN material m ON m.id = fv.id_material
            WHERE fq.id_formulario = ?
            ORDER BY m.nombre ASC
        ");
        $stmtM->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);

    } else {
        $stmtM = $conn->prepare("
            (SELECT DISTINCT m.id, m.nombre
             FROM gestion_visita gv
             JOIN formulario f
               ON f.id = gv.id_formulario
              AND f.id = ?
              AND f.id_empresa = ?
             JOIN material m ON m.id = gv.id_material
             WHERE gv.id_formulario = ?)

            UNION

            (SELECT DISTINCT m.id, m.nombre
             FROM formularioQuestion fq
             JOIN formulario f
               ON f.id = fq.id_formulario
              AND f.id = ?
              AND f.id_empresa = ?
             JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
             JOIN material m ON m.id = fv.id_material
             WHERE fq.id_formulario = ?)

            ORDER BY nombre ASC
        ");
        $stmtM->bind_param(
            "iiiiii",
            $formulario_id,
            $empresa_id,
            $formulario_id,
            $formulario_id,
            $empresa_id,
            $formulario_id
        );
    }

    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($rowM = $resM->fetch_assoc()) {
        $materials[] = $rowM;
    }
    $stmtM->close();
}

// Preguntas para Encuesta
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
while ($r = $rsP->fetch_assoc()) {
    $preguntasDisponibles[] = $r;
}
$stmtP->close();

// -------------------------------------------------------------
// 5) Consulta principal
// -------------------------------------------------------------
$params = [];
$types  = "";
$sql    = "";

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {

    // ---------- Bloque GV ----------
    $sqlGV = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(fv.url ORDER BY fv.id DESC SEPARATOR '||') AS urls,
            ANY_VALUE(COALESCE(m.nombre, fq.material, '—')) AS material,
            COALESCE(fv.id_material, gv.id_material, 0) AS material_id,
            ANY_VALUE(gv.fecha_visita) AS fechaVisita,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario AS usuario
        FROM gestion_visita gv
        JOIN formulario f
          ON f.id = gv.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN local l ON l.id = gv.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        JOIN cadena c ON c.id = l.id_cadena
        JOIN cuenta ct ON ct.id = l.id_cuenta
        JOIN usuario u ON u.id = gv.id_usuario
        LEFT JOIN fotoVisita fv
          ON fv.visita_id = gv.visita_id
         AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
        LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
        LEFT JOIN material m ON m.id = COALESCE(fv.id_material, gv.id_material)
        WHERE gv.id_formulario = ?
    ";
    $typesGV  = "iii";
    $paramsGV = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $sqlGV .= " AND gv.fecha_visita >= ?";
        $typesGV .= "s";
        $paramsGV[] = $start_dt;
    }
    if ($end_dt !== null) {
        $sqlGV .= " AND gv.fecha_visita <= ?";
        $typesGV .= "s";
        $paramsGV[] = $end_dt;
    }
    if ($user_id > 0) {
        $sqlGV .= " AND gv.id_usuario = ?";
        $typesGV .= "i";
        $paramsGV[] = $user_id;
    }
    if ($local_code !== '') {
        $sqlGV .= " AND l.codigo = ?";
        $typesGV .= "s";
        $paramsGV[] = $local_code;
    }
    if ($material_id > 0) {
        $sqlGV .= " AND COALESCE(fv.id_material, gv.id_material) = ?";
        $typesGV .= "i";
        $paramsGV[] = $material_id;
    }

    $sqlGV .= "
        GROUP BY gv.visita_id, COALESCE(fv.id_material, gv.id_material, 0), l.id, u.id
        HAVING COUNT(fv.id) > 0
    ";

    // ---------- Bloque LEGACY ----------
    $sqlLegacy = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(fv.url ORDER BY fv.id DESC SEPARATOR '||') AS urls,
            ANY_VALUE(COALESCE(m.nombre, fq.material, '—')) AS material,
            COALESCE(fv.id_material, 0) AS material_id,
            fq.fechaVisita AS fechaVisita,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario AS usuario
        FROM formularioQuestion fq
        JOIN formulario f
          ON f.id = fq.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN local l ON l.id = fq.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        JOIN cadena c ON c.id = l.id_cadena
        JOIN cuenta ct ON ct.id = l.id_cuenta
        JOIN usuario u ON u.id = fq.id_usuario
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        LEFT JOIN material m ON m.id = fv.id_material
        WHERE fq.id_formulario = ?
          AND NOT EXISTS (
            SELECT 1
            FROM gestion_visita gv2
            WHERE gv2.id_formulario = fq.id_formulario
              AND gv2.id_formularioQuestion = fq.id
          )
    ";
    $typesLg  = "iii";
    $paramsLg = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $sqlLegacy .= " AND fq.fechaVisita >= ?";
        $typesLg .= "s";
        $paramsLg[] = $start_dt;
    }
    if ($end_dt !== null) {
        $sqlLegacy .= " AND fq.fechaVisita <= ?";
        $typesLg .= "s";
        $paramsLg[] = $end_dt;
    }
    if ($user_id > 0) {
        $sqlLegacy .= " AND fq.id_usuario = ?";
        $typesLg .= "i";
        $paramsLg[] = $user_id;
    }
    if ($local_code !== '') {
        $sqlLegacy .= " AND l.codigo = ?";
        $typesLg .= "s";
        $paramsLg[] = $local_code;
    }
    if ($material_id > 0) {
        $sqlLegacy .= " AND COALESCE(fv.id_material, 0) = ?";
        $typesLg .= "i";
        $paramsLg[] = $material_id;
    }

    $sqlLegacy .= "
        GROUP BY fq.id, COALESCE(fv.id_material, 0), l.id, u.id
        HAVING COUNT(fv.id) > 0
    ";

    if ($mode === 'gv') {
        $sql = $sqlGV . " ORDER BY fechaVisita DESC, foto_id DESC LIMIT ? OFFSET ? ";
        $types = $typesGV . "ii";
        $params = array_merge($paramsGV, [$limit, $offset]);

    } elseif ($mode === 'legacy') {
        $sql = $sqlLegacy . " ORDER BY fechaVisita DESC, foto_id DESC LIMIT ? OFFSET ? ";
        $types = $typesLg . "ii";
        $params = array_merge($paramsLg, [$limit, $offset]);

    } else {
        $sql = "
            SELECT *
            FROM (
                $sqlGV
                UNION ALL
                $sqlLegacy
            ) X
            ORDER BY X.fechaVisita DESC, X.foto_id DESC
            LIMIT ? OFFSET ?
        ";
        $types = $typesGV . $typesLg . "ii";
        $params = array_merge($paramsGV, $paramsLg, [$limit, $offset]);
    }

} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {

    // MySQL 8 compatible: columnas no agrupadas con MAX/ANY_VALUE
    $sql = "
        SELECT
            MIN(fqr.id) AS foto_id,
            GROUP_CONCAT(fqr.answer_text ORDER BY fqr.id DESC SEPARATOR '||') AS urls,
            MAX(fqr.created_at) AS fechaSubida,
            ANY_VALUE(fq.question_text) AS pregunta,
            ANY_VALUE(l.codigo) AS local_codigo,
            ANY_VALUE(l.nombre) AS local_nombre,
            ANY_VALUE(l.direccion) AS local_direccion,
            ANY_VALUE(co.comuna) AS comuna_nombre,
            ANY_VALUE(c.nombre) AS cadena_nombre,
            ANY_VALUE(ct.nombre) AS cuenta_nombre,
            ANY_VALUE(u.usuario) AS usuario
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN local l ON l.id = fqr.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        JOIN cadena c ON c.id = l.id_cadena
        JOIN cuenta ct ON ct.id = l.id_cuenta
        JOIN usuario u ON u.id = fqr.id_usuario
        WHERE fq.id_formulario = ?
          AND fq.id_question_type = 7
          AND fqr.id_local <> 0
    ";
    $types  = "i";
    $params = [$formulario_id];

    if ($start_dt !== null) {
        $sql .= " AND fqr.created_at >= ?";
        $types .= "s";
        $params[] = $start_dt;
    }
    if ($end_dt !== null) {
        $sql .= " AND fqr.created_at <= ?";
        $types .= "s";
        $params[] = $end_dt;
    }
    if ($local_code !== '') {
        $sql .= " AND l.codigo = ?";
        $types .= "s";
        $params[] = $local_code;
    }
    if ($user_id > 0) {
        $sql .= " AND fqr.id_usuario = ?";
        $types .= "i";
        $params[] = $user_id;
    }
    if ($id_question !== '') {
        $sql .= " AND fq.id = ?";
        $types .= "i";
        $params[] = (int)$id_question;
    }

    $sql .= "
        GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
        ORDER BY MAX(fqr.created_at) DESC
        LIMIT ? OFFSET ?
    ";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'locales_no_visitados') {

    $sqlGV = "
        SELECT
            MIN(gv.id) AS row_id,
            MAX(gv.fecha_visita) AS fechaRef,
            u.usuario AS usuario,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            GROUP_CONCAT(DISTINCT gv.foto_url SEPARATOR ' || ') AS fotos_gv,
            GROUP_CONCAT(DISTINCT gv.observacion SEPARATOR ' || ') AS observaciones
        FROM gestion_visita gv
        JOIN formulario f
          ON f.id = gv.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN usuario u ON u.id = gv.id_usuario
        JOIN local l ON l.id = gv.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        WHERE gv.id_formulario = ?
          AND (
            gv.estado_gestion IN ('cancelado','pendiente')
            OR gv.observacion LIKE '%local_cerrado%'
            OR gv.observacion LIKE '%local_no_existe%'
            OR gv.observacion LIKE '%http%'
            OR gv.observacion LIKE '%Foto:%'
            OR gv.foto_url IS NOT NULL
          )
    ";
    $typesGV  = "iii";
    $paramsGV = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $sqlGV .= " AND gv.fecha_visita >= ?";
        $typesGV .= "s";
        $paramsGV[] = $start_dt;
    }
    if ($end_dt !== null) {
        $sqlGV .= " AND gv.fecha_visita <= ?";
        $typesGV .= "s";
        $paramsGV[] = $end_dt;
    }
    if ($user_id > 0) {
        $sqlGV .= " AND gv.id_usuario = ?";
        $typesGV .= "i";
        $paramsGV[] = $user_id;
    }
    if ($local_code !== '') {
        $sqlGV .= " AND l.codigo = ?";
        $typesGV .= "s";
        $paramsGV[] = $local_code;
    }

    $sqlGV .= " GROUP BY DATE(gv.fecha_visita), u.id, l.id ";

    $sqlFQ = "
        SELECT
            MIN(fq.id) AS row_id,
            MAX(fq.fechaVisita) AS fechaRef,
            u.usuario AS usuario,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            NULL AS fotos_gv,
            GROUP_CONCAT(DISTINCT fq.observacion SEPARATOR ' || ') AS observaciones
        FROM formularioQuestion fq
        JOIN formulario f
          ON f.id = fq.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN usuario u ON u.id = fq.id_usuario
        JOIN local l ON l.id = fq.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        WHERE fq.id_formulario = ?
          AND (
            fq.observacion LIKE '%local_cerrado%'
            OR fq.observacion LIKE '%local_no_existe%'
            OR fq.observacion LIKE '%mueble_no_esta_en_sala%'
            OR fq.observacion LIKE '%mueble no esta en sala%'
            OR fq.observacion LIKE '%mueble_no_existe%'
            OR fq.observacion LIKE '%no existe%'
            OR fq.observacion LIKE '%no_existe%'
            OR fq.observacion LIKE '%no esta%'
            OR fq.observacion LIKE '%no está%'
            OR fq.observacion LIKE '%no se encuentra%'
            OR fq.observacion LIKE '%pendiente%'
            OR fq.observacion LIKE '%cancelado%'
            OR fq.observacion LIKE '%http%'
            OR fq.observacion LIKE '%Foto:%'
          )
    ";
    $typesFQ  = "iii";
    $paramsFQ = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $sqlFQ .= " AND fq.fechaVisita >= ?";
        $typesFQ .= "s";
        $paramsFQ[] = $start_dt;
    }
    if ($end_dt !== null) {
        $sqlFQ .= " AND fq.fechaVisita <= ?";
        $typesFQ .= "s";
        $paramsFQ[] = $end_dt;
    }
    if ($user_id > 0) {
        $sqlFQ .= " AND fq.id_usuario = ?";
        $typesFQ .= "i";
        $paramsFQ[] = $user_id;
    }
    if ($local_code !== '') {
        $sqlFQ .= " AND l.codigo = ?";
        $typesFQ .= "s";
        $paramsFQ[] = $local_code;
    }

    $sqlFQ .= " GROUP BY DATE(fq.fechaVisita), u.id, l.id ";

    $sql = "
        SELECT *
        FROM (
            $sqlGV
            UNION ALL
            $sqlFQ
        ) X
        ORDER BY X.fechaRef DESC, X.row_id DESC
        LIMIT ? OFFSET ?
    ";
    $types = $typesGV . $typesFQ . "ii";
    $params = array_merge($paramsGV, $paramsFQ, [$limit, $offset]);

} else {

    $sql = "
        SELECT
            fqr.id AS foto_id,
            fqr.answer_text AS url,
            fqr.created_at AS fechaSubida,
            fq.question_text AS pregunta,
            u.usuario AS usuario
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN usuario u ON u.id = fqr.id_usuario
        WHERE fq.id_formulario = ?
          AND fq.id_question_type = 7
          AND fqr.id_local = 0
        ORDER BY fqr.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $types = "iii";
    $params = [$formulario_id, $limit, $offset];
}

$stmtMain = $conn->prepare($sql);
if (!$stmtMain) {
    die("<div class='alert alert-danger'>Error preparación: " . htmlspecialchars($conn->error) . "</div>");
}

$bindParams = refValues($params);
array_unshift($bindParams, $types);
call_user_func_array([$stmtMain, 'bind_param'], $bindParams);

$stmtMain->execute();
$result = $stmtMain->get_result();

// -------------------------------------------------------------
// 6) Construcción de $data
// -------------------------------------------------------------
$data = [];

while ($row = $result->fetch_assoc()) {

    if ($view === 'locales_no_visitados') {
        $candidates = [];

        if (!empty($row['fotos_gv'])) {
            foreach (preg_split('/\s*\|\|\s*/', (string)$row['fotos_gv']) as $u) {
                $u = trim($u);
                if ($u !== '') {
                    $candidates[] = $u;
                }
            }
        }

        if (!empty($row['observaciones'])) {
            foreach (preg_split('/\s*\|\|\s*/', (string)$row['observaciones']) as $obs) {
                $obs = trim($obs);
                if ($obs === '') {
                    continue;
                }

                $candidates = array_merge($candidates, ln_extract_urls($obs));

                if (empty($row['observacion_snippet'])) {
                    $row['observacion_snippet'] = ln_clean_observacion($obs);
                    $row['motivos'] = ln_detect_motivos($obs);
                }
            }
        }

        $fixed = [];
        foreach (array_unique($candidates) as $u) {
            $fu = fixUrl($u, $base_url);
            if ($fu !== '') {
                $fixed[] = $fu;
            }
        }

        if (!count($fixed)) {
            continue;
        }

        $row['urls']         = implode('||', $fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0];
        $row['fechaSubida']  = $row['fechaRef'] ?? null;
    }

    if (isset($row['urls'])) {
        $rawUrls = explode('||', $row['urls']);
        $fixed = [];

        foreach ($rawUrls as $u) {
            $u = trim($u);
            if ($u === '') {
                continue;
            }

            $fu = fixUrl($u, $base_url);
            if ($fu !== '') {
                $fixed[] = $fu;
            }
        }

        $fixed = array_values(array_unique($fixed));

        if (!empty($fixed)) {
            $row['urls']         = implode('||', $fixed);
            $row['photos']       = $fixed;
            $row['photos_count'] = count($fixed);
            $row['thumbnail']    = $fixed[0];
        }

    } else {
        if (!isset($row['thumbnail']) && isset($row['url'])) {
            $fixedUrl = fixUrl($row['url'], $base_url);
            if ($fixedUrl !== '') {
                $row['urls']         = $fixedUrl;
                $row['photos']       = [$fixedUrl];
                $row['photos_count'] = 1;
                $row['thumbnail']    = $fixedUrl;
            }
        }
    }

    $data[] = $row;
}

$stmtMain->close();

// -------------------------------------------------------------
// 7) Conteo para paginación
// -------------------------------------------------------------
$countSql    = "";
$countTypes  = "";
$countParams = [];

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {

    $cntGV = "
        SELECT
            gv.visita_id AS g1,
            COALESCE(fv.id_material, gv.id_material, 0) AS g2,
            l.id AS g3,
            u.id AS g4
        FROM gestion_visita gv
        JOIN formulario f
          ON f.id = gv.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN local l ON l.id = gv.id_local
        JOIN usuario u ON u.id = gv.id_usuario
        LEFT JOIN fotoVisita fv
          ON fv.visita_id = gv.visita_id
         AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
        WHERE gv.id_formulario = ?
    ";
    $typesGVc  = "iii";
    $paramsGVc = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $cntGV .= " AND gv.fecha_visita >= ?";
        $typesGVc .= "s";
        $paramsGVc[] = $start_dt;
    }
    if ($end_dt !== null) {
        $cntGV .= " AND gv.fecha_visita <= ?";
        $typesGVc .= "s";
        $paramsGVc[] = $end_dt;
    }
    if ($user_id > 0) {
        $cntGV .= " AND gv.id_usuario = ?";
        $typesGVc .= "i";
        $paramsGVc[] = $user_id;
    }
    if ($local_code !== '') {
        $cntGV .= " AND l.codigo = ?";
        $typesGVc .= "s";
        $paramsGVc[] = $local_code;
    }
    if ($material_id > 0) {
        $cntGV .= " AND COALESCE(fv.id_material, gv.id_material) = ?";
        $typesGVc .= "i";
        $paramsGVc[] = $material_id;
    }

    $cntGV .= "
        GROUP BY gv.visita_id, COALESCE(fv.id_material, gv.id_material, 0), l.id, u.id
        HAVING COUNT(fv.id) > 0
    ";

    $cntLG = "
        SELECT
            fq.id AS g1,
            COALESCE(fv.id_material, 0) AS g2,
            l.id AS g3,
            u.id AS g4
        FROM formularioQuestion fq
        JOIN formulario f
          ON f.id = fq.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN local l ON l.id = fq.id_local
        JOIN usuario u ON u.id = fq.id_usuario
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_formulario = ?
          AND NOT EXISTS (
            SELECT 1
            FROM gestion_visita gv2
            WHERE gv2.id_formulario = fq.id_formulario
              AND gv2.id_formularioQuestion = fq.id
          )
    ";
    $typesLGc  = "iii";
    $paramsLGc = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $cntLG .= " AND fq.fechaVisita >= ?";
        $typesLGc .= "s";
        $paramsLGc[] = $start_dt;
    }
    if ($end_dt !== null) {
        $cntLG .= " AND fq.fechaVisita <= ?";
        $typesLGc .= "s";
        $paramsLGc[] = $end_dt;
    }
    if ($user_id > 0) {
        $cntLG .= " AND fq.id_usuario = ?";
        $typesLGc .= "i";
        $paramsLGc[] = $user_id;
    }
    if ($local_code !== '') {
        $cntLG .= " AND l.codigo = ?";
        $typesLGc .= "s";
        $paramsLGc[] = $local_code;
    }
    if ($material_id > 0) {
        $cntLG .= " AND COALESCE(fv.id_material, 0) = ?";
        $typesLGc .= "i";
        $paramsLGc[] = $material_id;
    }

    $cntLG .= "
        GROUP BY fq.id, COALESCE(fv.id_material, 0), l.id, u.id
        HAVING COUNT(fv.id) > 0
    ";

    if ($mode === 'gv') {
        $countSql = "SELECT COUNT(*) FROM ( $cntGV ) t";
        $countTypes = $typesGVc;
        $countParams = $paramsGVc;

    } elseif ($mode === 'legacy') {
        $countSql = "SELECT COUNT(*) FROM ( $cntLG ) t";
        $countTypes = $typesLGc;
        $countParams = $paramsLGc;

    } else {
        $countSql = "SELECT COUNT(*) FROM ( $cntGV UNION ALL $cntLG ) t";
        $countTypes = $typesGVc . $typesLGc;
        $countParams = array_merge($paramsGVc, $paramsLGc);
    }

} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {

    // Conteo consistente con el GROUP BY principal
    $countSql = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT 1
            FROM form_question_responses fqr
            JOIN form_questions fq ON fq.id = fqr.id_form_question
            JOIN local l ON l.id = fqr.id_local
            WHERE fq.id_formulario = ?
              AND fq.id_question_type = 7
              AND fqr.id_local <> 0
    ";
    $countTypes  = "i";
    $countParams = [$formulario_id];

    if ($start_dt !== null) {
        $countSql .= " AND fqr.created_at >= ?";
        $countTypes .= "s";
        $countParams[] = $start_dt;
    }
    if ($end_dt !== null) {
        $countSql .= " AND fqr.created_at <= ?";
        $countTypes .= "s";
        $countParams[] = $end_dt;
    }
    if ($local_code !== '') {
        $countSql .= " AND l.codigo = ?";
        $countTypes .= "s";
        $countParams[] = $local_code;
    }
    if ($user_id > 0) {
        $countSql .= " AND fqr.id_usuario = ?";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
    if ($id_question !== '') {
        $countSql .= " AND fq.id = ?";
        $countTypes .= "i";
        $countParams[] = (int)$id_question;
    }

    $countSql .= "
            GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
        ) t
    ";

} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'locales_no_visitados') {

    $cntGV = "
        SELECT DATE(gv.fecha_visita) AS d, u.id AS uid, l.id AS lid
        FROM gestion_visita gv
        JOIN formulario f
          ON f.id = gv.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN usuario u ON u.id = gv.id_usuario
        JOIN local l ON l.id = gv.id_local
        WHERE gv.id_formulario = ?
          AND (
            gv.estado_gestion IN ('cancelado','pendiente')
            OR gv.observacion LIKE '%local_cerrado%'
            OR gv.observacion LIKE '%local_no_existe%'
            OR gv.observacion LIKE '%http%'
            OR gv.observacion LIKE '%Foto:%'
            OR gv.foto_url IS NOT NULL
          )
    ";
    $typesGVc  = "iii";
    $paramsGVc = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $cntGV .= " AND gv.fecha_visita >= ?";
        $typesGVc .= "s";
        $paramsGVc[] = $start_dt;
    }
    if ($end_dt !== null) {
        $cntGV .= " AND gv.fecha_visita <= ?";
        $typesGVc .= "s";
        $paramsGVc[] = $end_dt;
    }
    if ($user_id > 0) {
        $cntGV .= " AND gv.id_usuario = ?";
        $typesGVc .= "i";
        $paramsGVc[] = $user_id;
    }
    if ($local_code !== '') {
        $cntGV .= " AND l.codigo = ?";
        $typesGVc .= "s";
        $paramsGVc[] = $local_code;
    }

    $cntGV .= " GROUP BY d, uid, lid ";

    $cntFQ = "
        SELECT DATE(fq.fechaVisita) AS d, u.id AS uid, l.id AS lid
        FROM formularioQuestion fq
        JOIN formulario f
          ON f.id = fq.id_formulario
         AND f.id = ?
         AND f.id_empresa = ?
        JOIN usuario u ON u.id = fq.id_usuario
        JOIN local l ON l.id = fq.id_local
        WHERE fq.id_formulario = ?
          AND (
            fq.observacion LIKE '%local_cerrado%'
            OR fq.observacion LIKE '%local_no_existe%'
            OR fq.observacion LIKE '%mueble_no_esta_en_sala%'
            OR fq.observacion LIKE '%mueble no esta en sala%'
            OR fq.observacion LIKE '%mueble_no_existe%'
            OR fq.observacion LIKE '%no existe%'
            OR fq.observacion LIKE '%no_existe%'
            OR fq.observacion LIKE '%no esta%'
            OR fq.observacion LIKE '%no está%'
            OR fq.observacion LIKE '%no se encuentra%'
            OR fq.observacion LIKE '%pendiente%'
            OR fq.observacion LIKE '%cancelado%'
            OR fq.observacion LIKE '%http%'
            OR fq.observacion LIKE '%Foto:%'
          )
    ";
    $typesLGc  = "iii";
    $paramsLGc = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) {
        $cntFQ .= " AND fq.fechaVisita >= ?";
        $typesLGc .= "s";
        $paramsLGc[] = $start_dt;
    }
    if ($end_dt !== null) {
        $cntFQ .= " AND fq.fechaVisita <= ?";
        $typesLGc .= "s";
        $paramsLGc[] = $end_dt;
    }
    if ($user_id > 0) {
        $cntFQ .= " AND fq.id_usuario = ?";
        $typesLGc .= "i";
        $paramsLGc[] = $user_id;
    }
    if ($local_code !== '') {
        $cntFQ .= " AND l.codigo = ?";
        $typesLGc .= "s";
        $paramsLGc[] = $local_code;
    }

    $cntFQ .= " GROUP BY d, uid, lid ";

    $countSql = "SELECT COUNT(*) FROM ( $cntGV UNION ALL $cntFQ ) t";
    $countTypes = $typesGVc . $typesLGc;
    $countParams = array_merge($paramsGVc, $paramsLGc);

} else {

    $countSql = "
        SELECT COUNT(*) AS total
        FROM form_question_responses fqr
        WHERE fqr.id_form_question IN (
            SELECT id
            FROM form_questions
            WHERE id_formulario = ?
              AND id_question_type = 7
        )
          AND fqr.id_local = 0
    ";
    $countTypes  = "i";
    $countParams = [$formulario_id];

    if ($start_dt !== null) {
        $countSql .= " AND fqr.created_at >= ?";
        $countTypes .= "s";
        $countParams[] = $start_dt;
    }
    if ($end_dt !== null) {
        $countSql .= " AND fqr.created_at <= ?";
        $countTypes .= "s";
        $countParams[] = $end_dt;
    }
    if ($user_id > 0) {
        $countSql .= " AND fqr.id_usuario = ?";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
}

$stmtCount = $conn->prepare($countSql);
if (!$stmtCount) {
    die("<div class='alert alert-danger'>Error conteo: " . htmlspecialchars($conn->error) . "</div>");
}

$bindCount = refValues($countParams);
array_unshift($bindCount, $countTypes);
call_user_func_array([$stmtCount, 'bind_param'], $bindCount);

$stmtCount->execute();
$stmtCount->bind_result($totalRows);
$stmtCount->fetch();
$stmtCount->close();

$totalRows  = $totalRows ?? 0;
$totalPages = (int)ceil($totalRows / max(1, $limit));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Galería de Campaña</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .thumbnail { width:100px; height:100px; object-fit:cover; border-radius:5px; }
        .custom-img-cell { width:130px; position:relative; }
        .badge-count {
            position:absolute;
            top:5px;
            right:5px;
            background:rgba(0,0,0,0.6);
            color:#fff;
            font-size:.8rem;
            padding:.2rem .4rem;
            border-radius:50%;
        }
        .pagination {
            flex-wrap:wrap;
            justify-content:center;
            gap:5px;
        }
/* =========================================================
   GALERÍA MODERNA VISIBILITY
========================================================= */

body.gallery-modern-body {
    background:
        radial-gradient(circle at 12% 8%, rgba(95, 160, 255, .12), transparent 34%),
        linear-gradient(180deg, #f6f9fd 0%, #eef3f9 100%) !important;
    color: #183153;
}

.modern-gallery-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 26px 22px 40px;
}

/* Header */

.modern-gallery-hero {
    border-radius: 30px;
    padding: 26px 30px;
    background:
        radial-gradient(circle at 10% 0%, rgba(95,160,255,.14), transparent 34%),
        linear-gradient(180deg, rgba(255,255,255,.88), rgba(245,249,255,.78));
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 24px 55px rgba(70, 95, 140, .12),
        inset 0 1px 0 rgba(255,255,255,.88);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    margin-bottom: 20px;
}

.modern-gallery-hero-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
}

.modern-gallery-title-wrap {
    display: flex;
    align-items: center;
    gap: 16px;
}

.modern-gallery-icon {
    width: 62px;
    height: 62px;
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    font-size: 24px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.modern-gallery-title {
    margin: 0;
    font-size: 30px;
    font-weight: 950;
    color: #15315d;
    letter-spacing: .2px;
}

.modern-gallery-subtitle {
    margin: 5px 0 0;
    color: #7a8ba7;
    font-size: 13px;
    font-weight: 650;
}

.modern-gallery-kpis {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.modern-gallery-kpi {
    min-width: 130px;
    padding: 14px 16px;
    border-radius: 20px;
    background: rgba(255,255,255,.82);
    border: 1px solid rgba(218,228,243,.9);
    box-shadow:
        0 12px 26px rgba(70,95,140,.07),
        inset 0 1px 0 rgba(255,255,255,.85);
}

.modern-gallery-kpi span {
    display: block;
    font-size: 11px;
    font-weight: 850;
    color: #7c8da8;
    text-transform: uppercase;
    letter-spacing: .6px;
}

.modern-gallery-kpi strong {
    display: block;
    margin-top: 5px;
    font-size: 22px;
    font-weight: 950;
    color: #15315d;
}

/* Tabs */

.modern-gallery-tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin: 18px 0 0;
    padding: 0;
    border: 0;
}

.modern-gallery-tabs .nav-item {
    margin: 0;
}

.modern-gallery-tabs .nav-link {
    border: 1px solid rgba(200,215,238,.85) !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.72);
    color: #315071;
    font-size: 13px;
    font-weight: 850;
    padding: 11px 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow:
        0 10px 22px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.8);
}

.modern-gallery-tabs .nav-link.active {
    background: linear-gradient(135deg, #75a5ff, #4d7eff);
    color: #fff !important;
    border-color: transparent !important;
    box-shadow:
        0 14px 28px rgba(77,126,255,.30),
        inset 0 1px 0 rgba(255,255,255,.35);
}

/* Filtros */

.modern-gallery-filter-card {
    border-radius: 26px;
    padding: 22px;
    background: rgba(255,255,255,.78);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 22px 50px rgba(70,95,140,.10),
        inset 0 1px 0 rgba(255,255,255,.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    margin-bottom: 18px;
}

.modern-gallery-filter-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(160px, 1fr));
    gap: 16px;
}

.modern-gallery-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.modern-gallery-field label {
    margin: 0;
    font-size: 12px;
    font-weight: 850;
    color: #294469;
}

.modern-gallery-field input,
.modern-gallery-field select {
    width: 100%;
    height: 44px;
    border: 1px solid rgba(185,202,230,.78);
    border-radius: 15px;
    background: rgba(255,255,255,.86);
    color: #28446f;
    padding: 0 13px;
    font-size: 13px;
    font-weight: 650;
    box-shadow:
        0 8px 18px rgba(70,95,140,.06),
        inset 0 1px 0 rgba(255,255,255,.8);
}

.modern-gallery-field input:focus,
.modern-gallery-field select:focus {
    outline: none;
    border-color: #8eb7ff;
    box-shadow:
        0 0 0 4px rgba(90,142,255,.12),
        0 8px 18px rgba(76,108,163,.08);
}

/* Toolbar */

.modern-gallery-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.modern-gallery-limit {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #294469;
    font-size: 13px;
    font-weight: 750;
}

.modern-gallery-limit select {
    height: 42px;
    border-radius: 14px;
    border: 1px solid rgba(185,202,230,.78);
    background: rgba(255,255,255,.88);
    color: #28446f;
    padding: 0 12px;
    font-size: 13px;
    font-weight: 700;
}

.modern-gallery-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.modern-gallery-btn {
    height: 44px;
    border: 0;
    border-radius: 15px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    color: #fff;
    font-size: 13px;
    font-weight: 850;
    box-shadow:
        0 14px 28px rgba(70,95,140,.15),
        inset 0 1px 0 rgba(255,255,255,.32);
    transition: transform .18s ease, box-shadow .18s ease;
}

.modern-gallery-btn:hover {
    transform: translateY(-2px);
    color: #fff;
}

.modern-gallery-btn.success {
    background: linear-gradient(135deg, #50c878, #22a85a);
}

.modern-gallery-btn.primary {
    background: linear-gradient(135deg, #75a5ff, #4d7eff);
}

/* Tabla */

.modern-gallery-table-shell {
    border-radius: 26px;
    overflow: hidden;
    background: rgba(255,255,255,.82);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 24px 55px rgba(70,95,140,.11),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.modern-gallery-table {
    margin: 0;
    background: transparent;
}

.modern-gallery-table thead th {
    border: 0 !important;
    background: rgba(242,247,255,.96);
    color: #324a6d;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 16px 14px;
    vertical-align: middle;
}

.modern-gallery-table tbody td {
    border-color: rgba(220,230,245,.85) !important;
    padding: 14px;
    vertical-align: middle;
    color: #253b59;
    font-size: 14px;
    font-weight: 600;
}

.modern-gallery-table tbody tr {
    transition: background .16s ease;
}

.modern-gallery-table tbody tr:hover {
    background: rgba(245,249,255,.88);
}

.thumbnail {
    width: 112px;
    height: 94px;
    object-fit: cover;
    border-radius: 18px;
    cursor: pointer;
    box-shadow:
        0 12px 24px rgba(35,65,115,.14),
        inset 0 1px 0 rgba(255,255,255,.82);
    transition: transform .18s ease, box-shadow .18s ease;
}

.thumbnail:hover {
    transform: scale(1.04);
    box-shadow: 0 16px 30px rgba(35,65,115,.20);
}

.custom-img-cell {
    width: 140px;
    position: relative;
}

.badge-count {
    position: absolute;
    top: 8px;
    right: 12px;
    min-width: 26px;
    height: 26px;
    padding: 0 8px;
    border-radius: 999px;
    background: rgba(21,49,93,.86);
    color: #fff;
    font-size: 11px;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
    box-shadow: 0 8px 16px rgba(21,49,93,.22);
}

.modern-gallery-empty {
    padding: 34px;
    text-align: center;
    color: #7a8ba7;
    font-weight: 750;
}

/* Paginación */

.pagination {
    flex-wrap: wrap;
    justify-content: center;
    gap: 7px;
    margin-top: 22px;
}

.pagination .page-link {
    border: 0;
    border-radius: 13px;
    color: #315071;
    font-weight: 800;
    box-shadow: 0 8px 18px rgba(70,95,140,.08);
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #75a5ff, #4d7eff);
}

/* Modal imagen */

.modern-photo-modal .modal-dialog {
    max-width: 92vw;
}

.modern-photo-modal .modal-content {
    border: 0;
    border-radius: 26px;
    overflow: hidden;
    background: rgba(255,255,255,.98);
    box-shadow: 0 34px 95px rgba(20,45,90,.30);
}

.modern-photo-modal .modal-body {
    padding: 18px !important;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.10), transparent 36%),
        #fff;
}

.modern-photo-modal #modalBodyImgs img {
    max-height: 82vh;
    border-radius: 18px;
    box-shadow: 0 18px 45px rgba(40,70,120,.18);
}

.modern-photo-modal .modal-footer {
    border-top: 1px solid rgba(205,218,238,.75);
    background: rgba(248,251,255,.96);
}

@media (max-width: 1200px) {
    .modern-gallery-filter-grid {
        grid-template-columns: repeat(3, minmax(160px, 1fr));
    }
}

@media (max-width: 768px) {
    .modern-gallery-container {
        padding: 18px 14px 30px;
    }

    .modern-gallery-title {
        font-size: 24px;
    }

    .modern-gallery-filter-grid {
        grid-template-columns: 1fr;
    }

    .modern-gallery-toolbar {
        align-items: stretch;
    }

    .modern-gallery-actions,
    .modern-gallery-btn {
        width: 100%;
        justify-content: center;
    }

    .modern-gallery-table-shell {
        overflow-x: auto;
    }
}
    </style>
</head>
<body class="gallery-modern-body">
<div class="modern-gallery-container">

  <div class="modern-gallery-hero">
    <div class="modern-gallery-hero-main">

      <div class="modern-gallery-title-wrap">
        <div class="modern-gallery-icon">
          <i class="fas fa-images"></i>
        </div>

        <div>
          <h2 class="modern-gallery-title">Galería de Campaña</h2>
          <p class="modern-gallery-subtitle">
            Visualización, filtro y descarga de registros fotográficos
          </p>
        </div>
      </div>

      <div class="modern-gallery-kpis">
        <div class="modern-gallery-kpi">
          <span>Total registros</span>
          <strong><?= number_format((int)$totalRows, 0, ',', '.'); ?></strong>
        </div>

        <div class="modern-gallery-kpi">
          <span>Vista actual</span>
          <strong>
            <?php
              echo $view === 'implementacion'
                ? 'Implementación'
                : ($view === 'encuesta' ? 'Encuesta' : 'No gestionados');
            ?>
          </strong>
        </div>

        <div class="modern-gallery-kpi">
          <span>Página</span>
          <strong><?= (int)$page; ?> / <?= max(1, (int)$totalPages); ?></strong>
        </div>
      </div>

    </div>

    <?php if ($tipoForm == 1 || $tipoForm == 3): ?>
        <ul class="nav modern-gallery-tabs">
            <li class="nav-item">
                <a class="nav-link <?= $view === 'implementacion' ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['view' => 'implementacion', 'page' => 1])) ?>">
                    <i class="fas fa-image"></i>
                    Fotos Implementación
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $view === 'encuesta' ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['view' => 'encuesta', 'page' => 1])) ?>">
                    <i class="fas fa-clipboard-list"></i>
                    Fotos Encuesta
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $view === 'locales_no_visitados' ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['view' => 'locales_no_visitados', 'page' => 1])) ?>">
                    <i class="fas fa-store-slash"></i>
                    Locales No Gestionados
                </a>
            </li>
        </ul>
    <?php endif; ?>
  </div>

<form id="filterForm" method="GET" class="modern-gallery-filter-card">
  <input type="hidden" name="id" value="<?= $formulario_id ?>">
  <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

  <div class="modern-gallery-filter-grid">

    <div class="modern-gallery-field">
      <label>Desde</label>
      <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
    </div>

    <div class="modern-gallery-field">
      <label>Hasta</label>
      <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
    </div>

    <div class="modern-gallery-field">
      <label>Usuario</label>
      <select name="user_id">
        <option value="0">Todos</option>
        <?php foreach ($usuarios as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $u['id'] == $user_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($u['usuario']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion'): ?>
      <div class="modern-gallery-field">
        <label>Material</label>
        <select name="material_id">
          <option value="0">Todos</option>
          <?php foreach ($materials as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $m['id'] == $material_id ? 'selected' : '' ?>>
              <?= htmlspecialchars($m['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta'): ?>
      <div class="modern-gallery-field">
        <label>Pregunta</label>
        <select name="id_question">
          <option value="">Todas</option>
          <?php foreach ($preguntasDisponibles as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id'] == $id_question ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['question_text']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($tipoForm == 1 || $tipoForm == 3): ?>
      <div class="modern-gallery-field">
        <label>Cód. Local</label>
        <input type="text" name="local_code" value="<?= htmlspecialchars($local_code) ?>">
      </div>
    <?php endif; ?>

  </div>

  <button type="submit" class="btn btn-primary d-none">Filtrar</button>
</form>

    <div class="modern-gallery-toolbar">

  <div class="modern-gallery-limit">
    <span>Mostrar</span>

    <select id="limitSelect">
      <?php foreach ([10, 25, 50, 100] as $n): ?>
        <option value="<?= $n ?>" <?= $n == $limit ? 'selected' : '' ?>>
          <?= $n ?>
        </option>
      <?php endforeach; ?>
    </select>

    <span>registros</span>
  </div>

  <div class="modern-gallery-actions">
    <button id="btnDownloadSelected" class="modern-gallery-btn success" type="button">
      <i class="fas fa-download"></i>
      <span>Descargar seleccionadas</span>
    </button>

    <button id="btnDownloadAll" class="modern-gallery-btn primary" type="button">
      <i class="fas fa-cloud-download-alt"></i>
      <span>Descargar todas las fotos</span>
    </button>
  </div>

</div>

    <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
        <input type="hidden" name="jsonFotos" id="jsonFotos">
    </form>

    <?php if ($view === 'implementacion' || $view === 'locales_no_visitados'): ?>
<div class="modern-gallery-table-shell">
  <table class="table modern-gallery-table">
            <thead class="thead-light">
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>#</th>
                <th>Imagen</th>
                <th>Cód. Local</th>
                <th>Local</th>
                <th>Dirección</th>
                <?php if ($view === 'implementacion'): ?>
                    <th>Material</th>
                    <th>Cadena</th>
                    <th>Cuenta</th>
                <?php else: ?>
                    <th>Observación</th>
                <?php endif; ?>
                <th>Usuario</th>
                <th><?= $view === 'implementacion' ? 'Fecha' : 'Fecha Subida' ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($data)): ?>
            <tr>
              <td colspan="11">
                <div class="modern-gallery-empty">
                  <i class="fas fa-images mb-2 d-block"></i>
                  Sin fotos disponibles para los filtros seleccionados.
                </div>
              </td>
            </tr>
            <?php else: ?>
                <?php $i = $offset + 1; ?>
                <?php foreach ($data as $row): ?>
                    <?php
                    $safeUsuario   = preg_replace('/[^a-zA-Z0-9]/', '_', $row['usuario']);
                    $safeMaterial  = isset($row['material']) ? preg_replace('/[^a-zA-Z0-9]/', '_', $row['material']) : 'sematerial';
                    $safeCodigo    = preg_replace('/[^a-zA-Z0-9]/', '_', $row['local_codigo']);
                    $thumb         = htmlspecialchars($row['thumbnail'], ENT_QUOTES);
                    $badge         = $row['photos_count'];
                    $fieldFecha    = $view === 'implementacion' ? 'fechaVisita' : 'fechaSubida';
                    $fecha         = formatearFecha($row[$fieldFecha] ?? null);
                    $phpPrefix     = "{$safeUsuario}_{$safeMaterial}_{$safeCodigo}";
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="imgCheckbox"
                                   data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>"
                                   data-prefix="<?= htmlspecialchars($phpPrefix, ENT_QUOTES) ?>">
                        </td>
                        <td><?= $i ?></td>
                        <td class="custom-img-cell">
                            <span class="badge-count"><?= $badge ?></span>
                            <img src="<?= $thumb ?>"
                                 class="thumbnail img-click"
                                 loading="lazy"
                                 decoding="async"
                                 data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>">
                        </td>
                        <td><?= htmlspecialchars($row['local_codigo'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['local_nombre'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['local_direccion'], ENT_QUOTES) ?></td>

                        <?php if ($view === 'implementacion'): ?>
                            <td><?= htmlspecialchars($row['material'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['cadena_nombre'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['cuenta_nombre'], ENT_QUOTES) ?></td>
                        <?php else: ?>
                            <td>
                                <?php if (!empty($row['observacion_snippet'])): ?>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($row['observacion_snippet'], ENT_QUOTES) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($row['motivos'])): ?>
                                    <div class="mt-1">
                                        <?php foreach ($row['motivos'] as $mot): ?>
                                            <span class="badge badge-info mr-1"><?= htmlspecialchars($mot, ENT_QUOTES) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td><?= htmlspecialchars($row['usuario'], ENT_QUOTES) ?></td>
                        <td><?= $fecha ?></td>
                    </tr>
                    <?php $i++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
<div class="modern-gallery-table-shell">
  <table class="table modern-gallery-table">
            <thead class="thead-light">
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>#</th>
                <th>Imagen</th>
                <th>Pregunta</th>
                <th>Cód. Local</th>
                <th>Local</th>
                <th>Dirección</th>
                <th>Cadena</th>
                <th>Cuenta</th>
                <th>Usuario</th>
                <th>Fecha Subida</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($data)): ?>
            <tr>
              <td colspan="11">
                <div class="modern-gallery-empty">
                  <i class="fas fa-clipboard-list mb-2 d-block"></i>
                  Sin fotos de encuesta para los filtros seleccionados.
                </div>
              </td>
            </tr>
            <?php else: ?>
                <?php $i = $offset + 1; ?>
                <?php foreach ($data as $row): ?>
                    <?php
                    $safeUsuario   = preg_replace('/[^a-zA-Z0-9]/', '_', $row['usuario']);
                    $safePregunta  = preg_replace('/[^a-zA-Z0-9]/', '_', $row['pregunta'] ?? 'encuesta');
                    $safeCodigo    = preg_replace('/[^a-zA-Z0-9]/', '_', $row['local_codigo']);
                    $phpPrefix     = "{$safeUsuario}_{$safePregunta}_{$safeCodigo}";
                    $thumb         = htmlspecialchars($row['thumbnail'], ENT_QUOTES);
                    $badge         = $row['photos_count'];
                    $fecha         = formatearFecha($row['fechaSubida'] ?? null);
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="imgCheckbox"
                                   data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>"
                                   data-prefix="<?= htmlspecialchars($phpPrefix, ENT_QUOTES) ?>">
                        </td>
                        <td><?= $i ?></td>
                        <td class="custom-img-cell">
                            <span class="badge-count"><?= $badge ?></span>
                            <img src="<?= $thumb ?>"
                                 class="thumbnail img-click"
                                 loading="lazy"
                                 decoding="async"
                                 data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>">
                        </td>
                        <td><?= htmlspecialchars($row['pregunta'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['local_codigo'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['local_nombre'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['local_direccion'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['cadena_nombre'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['cuenta_nombre'], ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['usuario'], ENT_QUOTES) ?></td>
                        <td><?= $fecha ?></td>
                    </tr>
                    <?php $i++; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
</div>        
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>">Anterior</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Anterior</span></li>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p == $page): ?>
                        <li class="page-item active"><span class="page-link"><?= $p ?></span></li>
                    <?php else: ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= buildPaginationUrl($p) ?>"><?= $p ?></a>
                        </li>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">Siguiente</a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Siguiente</span></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<div class="modal fade modern-photo-modal" id="fullSizeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-body text-center" id="modalBodyImgs"></div>

      <div class="modal-footer justify-content-between">
        <div class="small text-muted font-weight-bold">
          Vista ampliada de imágenes
        </div>

        <button class="btn btn-light font-weight-bold" data-dismiss="modal">
          Cerrar
        </button>
      </div>

    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).on('click', '.thumbnail.img-click', function () {
    var urls = ($(this).data('urls') || '').toString().split('||');
    var $body = $('#modalBodyImgs').empty();

    urls.forEach(function (src) {
        if (!src) return;
        $body.append('<img src="' + src + '" class="img-fluid mb-2" style="max-height:80vh" loading="lazy" decoding="async">');
    });

    $('#fullSizeModal').modal('show');
});

$('#selectAll').on('change', function () {
    $('.imgCheckbox').prop('checked', $(this).prop('checked'));
});

$('#btnDownloadSelected').click(function () {
    var toZip = [];

    $('.imgCheckbox:checked').each(function () {
        var urls = ($(this).data('urls') || '').toString().split('||');
        var prefix = ($(this).data('prefix') || '').toString();

        urls.forEach(function (u) {
            if (!u) return;
            var name = prefix + '_' + u.split('/').pop();
            toZip.push({ url: u, filename: name });
        });
    });

    if (!toZip.length) {
        return alert('Selecciona al menos una fila.');
    }

    $.ajax({
        url: 'download_zip.php',
        method: 'POST',
        data: { jsonFotos: JSON.stringify(toZip) },
        xhrFields: { responseType: 'blob' },
        success: function (data, status, xhr) {
            var disp = xhr.getResponseHeader('Content-Disposition') || '';
            var fname = 'fotos.zip';
            var m = disp.match(/filename[^;=\n]*=\s*(['"]?)([^'"\n]*)/);

            if (m && m[2]) {
                fname = m[2];
            }

            var blob = new Blob([data], { type: 'application/zip' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = fname;
            document.body.appendChild(link);
            link.click();
            link.remove();
        },
        error: function (_, __, e) {
            alert('Error al crear ZIP: ' + e);
        }
    });
});

$('#btnDownloadAll').click(function () {
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'all');
    params.set('view', '<?= $view ?>');

    <?php if ($view === 'implementacion'): ?>
    params.set('mode', '<?= $mode ?>');
    <?php endif; ?>

    const url = 'download_zip.php?' + params.toString();

    $.ajax({
        url: url,
        method: 'GET',
        xhrFields: { responseType: 'blob' },
        success(data, status, xhr) {
            let fname = 'fotos_todas.zip';
            const disp = xhr.getResponseHeader('Content-Disposition') || '';
            const m = disp.match(/filename[^;=\n]*=\s*(['"]?)([^'"\n]*)/);

            if (m && m[2]) {
                fname = m[2];
            }

            const blob = new Blob([data], { type: 'application/zip' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = fname;
            document.body.appendChild(link);
            link.click();
            link.remove();
        },
        error(_, __, e) {
            alert('Error al crear ZIP completo: ' + e);
        }
    });
});

$(function () {
    $('#limitSelect').val('<?= $limit ?>').on('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('limit', $(this).val());
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    });
});

$(function () {
    $('#filterForm').on('change', 'input, select', function () {
        $('#filterForm').submit();
    });
});
</script>
</body>
</html>
<?php
$conn->close();
?>
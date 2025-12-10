<?php
session_start();

/**
 * mod_galeria.php
 * Galería por campaña con:
 * - Implementación: modo gv/legacy/hybrid
 * - Encuesta
 * - Locales no visitados (No gestionados): unión GV+FQ, deduplicación por día+usuario+local,
 *   parser robusto de rutas de imagen en 'observacion' y uso de 'gestion_visita.foto_url'
 */

// -------------------------------------------------------------
// 1) Funciones auxiliares
// -------------------------------------------------------------
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $key => $value) { $refs[$key] = &$arr[$key]; }
        return $refs;
    }
    return $arr;
}
function fixUrl($url, $base_url) {
    if (preg_match('#^https?://#i', $url)) return $url;
    $prefixes = ['/visibility2/app/', '../app/'];
    foreach ($prefixes as $p) {
        if (substr($url, 0, strlen($p)) === $p) { $url = substr($url, strlen($p)); break; }
    }
    $url = ltrim($url, '/');
    return rtrim($base_url, '/') . '/' . $url;
}
function formatearFecha($f) { return $f ? date('d/m/Y H:i:s', strtotime($f)) : ''; }

// --- Parser robusto para "No gestionados" (LN) ---
function ln_extract_urls(string $txt): array {
    if ($txt === '' || $txt === null) return [];
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
        foreach ($m3[1] as $hit) $urls[] = $hit;
    }
    // D) Tokens "Foto:" / "Foto Mueble:" etc → capturar el siguiente token como ruta
    if (preg_match_all('/\bfoto[^:]*:\s*([^\s\|,;]+)/i', $txt, $m4)) {
        $urls = array_merge($urls, $m4[1]);
    }

    // Limpieza y únicos
    $urls = array_map(function($u){ return rtrim($u, ".,;)]"); }, $urls);
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
        if (strpos($t, $k) !== false) $out[] = $label;
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
    die("<div class='alert alert-danger'>Formulario no encontrado o no pertenece a tu empresa.</div>");
}
$stmtTipo->close();

// -------------------------------------------------------------
// 3) Parámetros de filtrado y paginación
// -------------------------------------------------------------
$start_date   = $_GET['start_date']   ?? '';
$end_date     = $_GET['end_date']     ?? '';
$user_id      = intval($_GET['user_id']   ?? 0);
$material_id  = intval($_GET['material_id'] ?? 0);
$local_code   = $_GET['local_code']   ?? '';
$id_question  = $_GET['id_question']  ?? '';
$limit        = max(1, intval($_GET['limit']  ?? 25));
$page         = max(1, intval($_GET['page']   ?? 1));
$offset       = ($page - 1) * $limit;
$view         = $_GET['view'] ?? 'implementacion';
if ($tipoForm == 2) { $view = 'encuesta'; } // tipo 2 es encuesta
$base_url = "https://visibility.cl/visibility2/app/";

$start_dt = $start_date !== '' ? $start_date . ' 00:00:00' : null;
$end_dt   = $end_date   !== '' ? $end_date   . ' 23:59:59' : null;

function buildPaginationUrl(int $page): string {
    $params = $_GET; $params['page'] = $page; return '?' . http_build_query($params);
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
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      WHERE gv.id_formulario = ?
    ";
    if ($stmt = $conn->prepare($sqlGvCnt)) {
        $stmt->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
        $stmt->execute(); $stmt->bind_result($gvCount); $stmt->fetch(); $stmt->close();
    }

    $legacyOnlyCount = 0;
    $sqlLegacyOnlyCnt = "
      SELECT COUNT(*) FROM (
        SELECT fq.id
        FROM formularioQuestion fq
        JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_formulario = ?
          AND NOT EXISTS (
            SELECT 1 FROM gestion_visita gv2
            WHERE gv2.id_formulario = fq.id_formulario
              AND gv2.id_formularioQuestion = fq.id
          )
        GROUP BY fq.id
      ) t
    ";
    if ($stmt = $conn->prepare($sqlLegacyOnlyCnt)) {
        $stmt->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
        $stmt->execute(); $stmt->bind_result($legacyOnlyCount); $stmt->fetch(); $stmt->close();
    }

    if ($gvCount > 0 && $legacyOnlyCount > 0) $mode = 'hybrid';
    elseif ($gvCount > 0) $mode = 'gv';
    else $mode = 'legacy';
}

// -------------------------------------------------------------
// 4) Listas para filtros
// -------------------------------------------------------------
$usuarios = [];
if ($tipoForm == 1 || $tipoForm == 3) {
    if ($view === 'implementacion') {
        $sqlUsers = "SELECT DISTINCT u.id, u.usuario
                     FROM fotoVisita fv
                     JOIN usuario u ON u.id = fv.id_usuario
                     JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
                     WHERE fq.id_formulario = ?
                     ORDER BY u.usuario";
        $stmtU = $conn->prepare($sqlUsers);
        $stmtU->bind_param("i", $formulario_id);
    } elseif ($view === 'locales_no_visitados') {
        // Usuarios desde GV y FQ (ambas fuentes)
        $sqlUsers = "
          SELECT id, usuario FROM (
            SELECT DISTINCT u.id AS id, u.usuario AS usuario
            FROM gestion_visita gv
            JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
            JOIN usuario u ON u.id = gv.id_usuario
            JOIN local   l ON l.id = gv.id_local
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
            JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
            JOIN usuario u ON u.id = fq.id_usuario
            JOIN local   l ON l.id = fq.id_local
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
        $stmtU->bind_param("iiiiii", $formulario_id, $empresa_id, $formulario_id, $formulario_id, $empresa_id, $formulario_id);
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
while ($r = $resU->fetch_assoc()) { $usuarios[] = $r; }
$stmtU->close();

// Materiales (solo Implementación)
$materials = [];
if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    if ($mode === 'gv') {
        $stmtM = $conn->prepare("
            SELECT DISTINCT m.id, m.nombre
            FROM gestion_visita gv
            JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
            JOIN material m   ON m.id = gv.id_material
            WHERE gv.id_formulario = ?
            ORDER BY m.nombre ASC
        ");
        $stmtM->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
    } elseif ($mode === 'legacy') {
        $stmtM = $conn->prepare("
            SELECT DISTINCT m.id, m.nombre
            FROM formularioQuestion fq
            JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
            JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
            JOIN material m ON m.id = fv.id_material
            WHERE fq.id_formulario = ?
            ORDER BY m.nombre ASC
        ");
        $stmtM->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
    } else { // hybrid
        $stmtM = $conn->prepare("
            (SELECT DISTINCT m.id, m.nombre
             FROM gestion_visita gv
             JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
             JOIN material m   ON m.id = gv.id_material
             WHERE gv.id_formulario = ?)
            UNION
            (SELECT DISTINCT m.id, m.nombre
             FROM formularioQuestion fq
             JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
             JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
             JOIN material m ON m.id = fv.id_material
             WHERE fq.id_formulario = ?)
            ORDER BY nombre ASC
        ");
        $stmtM->bind_param("iiiiii", $formulario_id, $empresa_id, $formulario_id, $formulario_id, $empresa_id, $formulario_id);
    }
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($rowM = $resM->fetch_assoc()) { $materials[] = $rowM; }
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
while ($r = $rsP->fetch_assoc()) { $preguntasDisponibles[] = $r; }
$stmtP->close();

// -------------------------------------------------------------
// 5) Consulta principal (Implementación: gv/legacy/hybrid; Encuesta; LN)
// -------------------------------------------------------------
$params = [$formulario_id];
$types  = "i";
$sql = "";

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {

    // ---------- Bloque GV ----------
    $sqlGV = "
      SELECT
        MIN(fv.id) AS foto_id,
        GROUP_CONCAT(fv.url ORDER BY fv.id DESC SEPARATOR '||') AS urls,
        COALESCE(m.nombre, fq.material, '—')               AS material,
        COALESCE(fv.id_material, gv.id_material, 0)        AS material_id,
        gv.fecha_visita                                    AS fechaVisita,
        l.codigo            AS local_codigo,
        l.nombre            AS local_nombre,
        l.direccion         AS local_direccion,
        co.comuna           AS comuna_nombre,
        c.nombre            AS cadena_nombre,
        ct.nombre           AS cuenta_nombre,
        u.usuario           AS usuario
      FROM gestion_visita gv
      JOIN formulario f
        ON f.id = gv.id_formulario
       AND f.id = ?
       AND f.id_empresa = ?
      JOIN local l               ON l.id = gv.id_local
      LEFT JOIN comuna co        ON co.id = l.id_comuna
      JOIN cadena c              ON c.id  = l.id_cadena
      JOIN cuenta ct             ON ct.id = l.id_cuenta
      JOIN usuario u             ON u.id  = gv.id_usuario
      LEFT JOIN fotoVisita fv
        ON fv.visita_id = gv.visita_id
       AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
      LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
      LEFT JOIN material m            ON m.id = COALESCE(fv.id_material, gv.id_material)
      WHERE gv.id_formulario = ?
    ";
    $typesGV  = "iii";
    $paramsGV = [$formulario_id, $empresa_id, $formulario_id];

    if ($start_dt !== null) { $sqlGV .= " AND gv.fecha_visita >= ?"; $typesGV .= "s"; $paramsGV[] = $start_dt; }
    if ($end_dt   !== null) { $sqlGV .= " AND gv.fecha_visita <= ?"; $typesGV .= "s"; $paramsGV[] = $end_dt; }
    if ($user_id > 0)       { $sqlGV .= " AND gv.id_usuario = ?";    $typesGV .= "i"; $paramsGV[] = $user_id; }
    if ($local_code !== '') { $sqlGV .= " AND l.codigo = ?";         $typesGV .= "s"; $paramsGV[] = $local_code; }
    if ($material_id > 0)   { $sqlGV .= " AND COALESCE(fv.id_material, gv.id_material) = ?"; $typesGV .= "i"; $paramsGV[] = $material_id; }

    $sqlGV .= "
      GROUP BY gv.visita_id, COALESCE(fv.id_material, gv.id_material, 0), l.id, u.id
      HAVING COUNT(fv.id) > 0
    ";

    // ---------- Bloque LEGACY ----------
    $sqlLegacy = "
      SELECT
        MIN(fv.id) AS foto_id,
        GROUP_CONCAT(fv.url ORDER BY fv.id DESC SEPARATOR '||') AS urls,
        COALESCE(m.nombre, fq.material, '—')               AS material,
        COALESCE(fv.id_material, 0)                        AS material_id,
        fq.fechaVisita                                     AS fechaVisita,
        l.codigo            AS local_codigo,
        l.nombre            AS local_nombre,
        l.direccion         AS local_direccion,
        co.comuna           AS comuna_nombre,
        c.nombre            AS cadena_nombre,
        ct.nombre           AS cuenta_nombre,
        u.usuario           AS usuario
      FROM formularioQuestion fq
      JOIN formulario f
        ON f.id = fq.id_formulario
       AND f.id = ?
       AND f.id_empresa = ?
      JOIN local l               ON l.id = fq.id_local
      LEFT JOIN comuna co        ON co.id = l.id_comuna
      JOIN cadena c              ON c.id  = l.id_cadena
      JOIN cuenta ct             ON ct.id = l.id_cuenta
      JOIN usuario u             ON u.id  = fq.id_usuario
      JOIN fotoVisita fv         ON fv.id_formularioQuestion = fq.id
      LEFT JOIN material m       ON m.id = fv.id_material
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

    if ($start_dt !== null) { $sqlLegacy .= " AND fq.fechaVisita >= ?"; $typesLg .= "s"; $paramsLg[] = $start_dt; }
    if ($end_dt   !== null) { $sqlLegacy .= " AND fq.fechaVisita <= ?"; $typesLg .= "s"; $paramsLg[] = $end_dt; }
    if ($user_id > 0)       { $sqlLegacy .= " AND fq.id_usuario = ?";   $typesLg .= "i"; $paramsLg[] = $user_id; }
    if ($local_code !== '') { $sqlLegacy .= " AND l.codigo = ?";        $typesLg .= "s"; $paramsLg[] = $local_code; }
    if ($material_id > 0)   { $sqlLegacy .= " AND COALESCE(fv.id_material, 0) = ?"; $typesLg .= "i"; $paramsLg[] = $material_id; }

    $sqlLegacy .= "
      GROUP BY fq.id, COALESCE(fv.id_material, 0), l.id, u.id
      HAVING COUNT(fv.id) > 0
    ";

    if ($mode === 'gv') {
        $sql   = $sqlGV . " ORDER BY fechaVisita DESC, foto_id DESC LIMIT ? OFFSET ? ";
        $types = $typesGV . "ii";
        $params = array_merge($paramsGV, [$limit, $offset]);
    } elseif ($mode === 'legacy') {
        $sql   = $sqlLegacy . " ORDER BY fechaVisita DESC, foto_id DESC LIMIT ? OFFSET ? ";
        $types = $typesLg . "ii";
        $params = array_merge($paramsLg, [$limit, $offset]);
    } else { // hybrid
        $sql = "
          SELECT * FROM (
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

}
elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {
    $sql = "
      SELECT
        MIN(fqr.id) AS foto_id,
        GROUP_CONCAT(fqr.answer_text ORDER BY fqr.id DESC SEPARATOR '||') AS urls,
        fqr.created_at   AS fechaSubida,
        fq.question_text AS pregunta,
        l.codigo         AS local_codigo,
        l.nombre         AS local_nombre,
        l.direccion      AS local_direccion,
        co.comuna        AS comuna_nombre,
        c.nombre         AS cadena_nombre,
        ct.nombre        AS cuenta_nombre,
        u.usuario        AS usuario
      FROM form_question_responses fqr
      JOIN form_questions fq    ON fq.id = fqr.id_form_question
      JOIN local l              ON l.id = fqr.id_local
      LEFT JOIN comuna co       ON co.id = l.id_comuna
      JOIN cadena c             ON c.id = l.id_cadena
      JOIN cuenta ct            ON ct.id = l.id_cuenta
      JOIN usuario u            ON u.id = fqr.id_usuario
      WHERE fq.id_formulario = ?
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
    if ($start_dt !== null) { $sql .= " AND fqr.created_at >= ?"; $types .= "s"; $params[] = $start_dt; }
    if ($end_dt   !== null) { $sql .= " AND fqr.created_at <= ?"; $types .= "s"; $params[] = $end_dt; }
    if ($local_code !== '') { $sql .= " AND l.codigo = ?";        $types .= "s"; $params[] = $local_code; }
    if ($user_id > 0)       { $sql .= " AND fqr.id_usuario = ?";  $types .= "i"; $params[] = $user_id; }
    if ($id_question !== ''){ $sql .= " AND fq.id = ?";           $types .= "i"; $params[] = (int)$id_question; }

    $sql .= "
      GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
      ORDER BY fqr.created_at DESC
      LIMIT ? OFFSET ?
    ";
    $types .= "ii"; $params[] = $limit; $params[] = $offset;
}
elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'locales_no_visitados') {
    // No gestionados: GV + FQ colapsado por DIA + USUARIO + LOCAL
    $sqlGV = "
      SELECT
        MIN(gv.id) AS row_id,
        MAX(gv.fecha_visita) AS fechaRef,
        u.usuario AS usuario,
        l.codigo  AS local_codigo,
        l.nombre  AS local_nombre,
        l.direccion AS local_direccion,
        co.comuna AS comuna_nombre,
        GROUP_CONCAT(DISTINCT gv.foto_url SEPARATOR ' || ') AS fotos_gv,
        GROUP_CONCAT(DISTINCT gv.observacion SEPARATOR ' || ') AS observaciones
      FROM gestion_visita gv
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario   u  ON u.id = gv.id_usuario
      JOIN local     l  ON l.id = gv.id_local
      LEFT JOIN comuna  co ON co.id = l.id_comuna
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
    $typesGV = "iii";
    $paramsGV = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $sqlGV .= " AND gv.fecha_visita >= ?"; $typesGV .= "s"; $paramsGV[] = $start_dt; }
    if ($end_dt   !== null) { $sqlGV .= " AND gv.fecha_visita <= ?"; $typesGV .= "s"; $paramsGV[] = $end_dt; }
    if ($user_id > 0)       { $sqlGV .= " AND gv.id_usuario = ?";    $typesGV .= "i"; $paramsGV[] = $user_id; }
    if ($local_code !== '') { $sqlGV .= " AND l.codigo = ?";         $typesGV .= "s"; $paramsGV[] = $local_code; }
    $sqlGV .= " GROUP BY DATE(gv.fecha_visita), u.id, l.id ";

    $sqlFQ = "
      SELECT
        MIN(fq.id) AS row_id,
        MAX(fq.fechaVisita) AS fechaRef,
        u.usuario AS usuario,
        l.codigo  AS local_codigo,
        l.nombre  AS local_nombre,
        l.direccion AS local_direccion,
        co.comuna AS comuna_nombre,
        NULL AS fotos_gv,
        GROUP_CONCAT(DISTINCT fq.observacion SEPARATOR ' || ') AS observaciones
      FROM formularioQuestion fq
      JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario   u  ON u.id = fq.id_usuario
      JOIN local     l  ON l.id = fq.id_local
      LEFT JOIN comuna  co ON co.id = l.id_comuna
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
    $typesFQ = "iii";
    $paramsFQ = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $sqlFQ .= " AND fq.fechaVisita >= ?"; $typesFQ .= "s"; $paramsFQ[] = $start_dt; }
    if ($end_dt   !== null) { $sqlFQ .= " AND fq.fechaVisita <= ?"; $typesFQ .= "s"; $paramsFQ[] = $end_dt; }
    if ($user_id > 0)       { $sqlFQ .= " AND fq.id_usuario = ?";   $typesFQ .= "i"; $paramsFQ[] = $user_id; }
    if ($local_code !== '') { $sqlFQ .= " AND l.codigo = ?";        $typesFQ .= "s"; $paramsFQ[] = $local_code; }
    $sqlFQ .= " GROUP BY DATE(fq.fechaVisita), u.id, l.id ";

    $sql = "
      SELECT * FROM (
        $sqlGV
        UNION ALL
        $sqlFQ
      ) X
      ORDER BY X.fechaRef DESC, X.row_id DESC
      LIMIT ? OFFSET ?
    ";
    $types = $typesGV . $typesFQ . "ii";
    $params = array_merge($paramsGV, $paramsFQ, [$limit, $offset]);
}
else { // Vista genérica (tipoForm != 1,3)
    $sql = "
      SELECT
        fqr.id           AS foto_id,
        fqr.answer_text  AS url,
        fqr.created_at   AS fechaSubida,
        fq.question_text AS pregunta,
        u.usuario        AS usuario
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN usuario u         ON u.id = fqr.id_usuario
      WHERE fq.id_formulario = ?
        AND fq.id_question_type = 7
        AND fqr.id_local = 0
      ORDER BY fqr.created_at DESC
      LIMIT ? OFFSET ?
    ";
    $types .= "ii"; $params[] = $limit; $params[] = $offset;
}

$stmtMain = $conn->prepare($sql);
if (!$stmtMain) { die("<div class='alert alert-danger'>Error preparación: " . htmlspecialchars($conn->error) . "</div>"); }
$bindParams = refValues($params); array_unshift($bindParams, $types);
call_user_func_array([$stmtMain, 'bind_param'], $bindParams);
$stmtMain->execute();
$result = $stmtMain->get_result();

// -------------------------------------------------------------
// 6) Construcción de $data (+ manejo especial LN)
// -------------------------------------------------------------
$data = [];
while ($row = $result->fetch_assoc()) {

    // LN: unir foto_url (GV) + URLs extraídas de observacion (GV/FQ), dedupe, snippet y motivos
    if ($view === 'locales_no_visitados') {
        $candidates = [];

        if (!empty($row['fotos_gv'])) {
            foreach (preg_split('/\s*\|\|\s*/', (string)$row['fotos_gv']) as $u) {
                $u = trim($u);
                if ($u !== '') $candidates[] = $u;
            }
        }
        if (!empty($row['observaciones'])) {
            foreach (preg_split('/\s*\|\|\s*/', (string)$row['observaciones']) as $obs) {
                $obs = trim($obs);
                if ($obs === '') continue;
                $candidates = array_merge($candidates, ln_extract_urls($obs));
                if (empty($row['observacion_snippet'])) {
                    $row['observacion_snippet'] = ln_clean_observacion($obs);
                    $row['motivos'] = ln_detect_motivos($obs);
                }
            }
        }

        $fixed = [];
        foreach (array_unique($candidates) as $u) { $fixed[] = fixUrl($u, $base_url); }
        if (!count($fixed)) continue;

        $row['urls']         = implode('||', $fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0];
        $row['fechaSubida']  = $row['fechaRef'] ?? null;
    }

    // Resto de vistas: normalización estándar
    if (isset($row['urls'])) {
        $rawUrls = explode('||', $row['urls']); $fixed = [];
        foreach ($rawUrls as $u) { $fixed[] = fixUrl($u, $base_url); }
        $row['urls']         = implode('||', $fixed);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0];
    } else {
        if (!isset($row['thumbnail']) && isset($row['url'])) {
            $fixedUrl = fixUrl($row['url'], $base_url);
            $row['urls']         = $fixedUrl;
            $row['photos']       = [$fixedUrl];
            $row['photos_count'] = 1;
            $row['thumbnail']    = $fixedUrl;
        }
    }
    $data[] = $row;
}
$stmtMain->close();

// -------------------------------------------------------------
// 7) Conteo para paginación (Implementación: gv/legacy/hybrid; Encuesta; LN)
// -------------------------------------------------------------
$countSql    = "";
$countTypes  = "i";
$countParams = [$formulario_id];

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {

    $cntGV = "
      SELECT gv.visita_id AS g1, COALESCE(fv.id_material, gv.id_material, 0) AS g2, l.id AS g3, u.id AS g4
      FROM gestion_visita gv
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN local l ON l.id = gv.id_local
      JOIN usuario u ON u.id = gv.id_usuario
      LEFT JOIN fotoVisita fv
        ON fv.visita_id = gv.visita_id
       AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
      WHERE gv.id_formulario = ?
    ";
    $typesGVc  = "iii";
    $paramsGVc = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $cntGV .= " AND gv.fecha_visita >= ?"; $typesGVc .= "s"; $paramsGVc[] = $start_dt; }
    if ($end_dt   !== null) { $cntGV .= " AND gv.fecha_visita <= ?"; $typesGVc .= "s"; $paramsGVc[] = $end_dt; }
    if ($user_id > 0)       { $cntGV .= " AND gv.id_usuario = ?";    $typesGVc .= "i"; $paramsGVc[] = $user_id; }
    if ($local_code !== '') { $cntGV .= " AND l.codigo = ?";         $typesGVc .= "s"; $paramsGVc[] = $local_code; }
    if ($material_id > 0)   { $cntGV .= " AND COALESCE(fv.id_material, gv.id_material) = ?"; $typesGVc .= "i"; $paramsGVc[] = $material_id; }
    $cntGV .= " GROUP BY gv.visita_id, COALESCE(fv.id_material, gv.id_material, 0), l.id, u.id HAVING COUNT(fv.id) > 0 ";

    $cntLG = "
      SELECT fq.id AS g1, COALESCE(fv.id_material, 0) AS g2, l.id AS g3, u.id AS g4
      FROM formularioQuestion fq
      JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN local l ON l.id = fq.id_local
      JOIN usuario u ON u.id = fq.id_usuario
      JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
      WHERE fq.id_formulario = ?
        AND NOT EXISTS (
          SELECT 1 FROM gestion_visita gv2
          WHERE gv2.id_formulario = fq.id_formulario
            AND gv2.id_formularioQuestion = fq.id
        )
    ";
    $typesLGc  = "iii";
    $paramsLGc = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $cntLG .= " AND fq.fechaVisita >= ?"; $typesLGc .= "s"; $paramsLGc[] = $start_dt; }
    if ($end_dt   !== null) { $cntLG .= " AND fq.fechaVisita <= ?"; $typesLGc .= "s"; $paramsLGc[] = $end_dt; }
    if ($user_id > 0)       { $cntLG .= " AND fq.id_usuario = ?";   $typesLGc .= "i"; $paramsLGc[] = $user_id; }
    if ($local_code !== '') { $cntLG .= " AND l.codigo = ?";        $typesLGc .= "s"; $paramsLGc[] = $local_code; }
    if ($material_id > 0)   { $cntLG .= " AND COALESCE(fv.id_material, 0) = ?"; $typesLGc .= "i"; $paramsLGc[] = $material_id; }
    $cntLG .= " GROUP BY fq.id, COALESCE(fv.id_material, 0), l.id, u.id HAVING COUNT(fv.id) > 0 ";

    if ($mode === 'gv') {
        $countSql   = "SELECT COUNT(*) FROM ( $cntGV ) t";
        $countTypes = $typesGVc;
        $countParams = $paramsGVc;
    } elseif ($mode === 'legacy') {
        $countSql   = "SELECT COUNT(*) FROM ( $cntLG ) t";
        $countTypes = $typesLGc;
        $countParams = $paramsLGc;
    } else {
        $countSql   = "SELECT COUNT(*) FROM ( $cntGV UNION ALL $cntLG ) t";
        $countTypes = $typesGVc . $typesLGc;
        $countParams = array_merge($paramsGVc, $paramsLGc);
    }

}
elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {
    $countSql = "
      SELECT COUNT(DISTINCT fqr.id_usuario, fqr.id_local) AS total
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      WHERE fq.id_formulario = ?
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
    if ($start_dt !== null) { $countSql .= " AND fqr.created_at >= ?"; $countTypes .= "s"; $countParams[] = $start_dt; }
    if ($end_dt   !== null) { $countSql .= " AND fqr.created_at <= ?"; $countTypes .= "s"; $countParams[] = $end_dt; }
    if ($local_code !== '') { $countSql .= " AND (SELECT l.codigo FROM local l WHERE l.id = fqr.id_local) = ?"; $countTypes .= "s"; $countParams[] = $local_code; }
    if ($user_id > 0)       { $countSql .= " AND fqr.id_usuario = ?";       $countTypes .= "i"; $countParams[] = $user_id; }
}
elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'locales_no_visitados') {
    // Conteo por grupos DIA + USUARIO + LOCAL (GV + FQ)
    $cntGV = "
      SELECT DATE(gv.fecha_visita) AS d, u.id AS uid, l.id AS lid
      FROM gestion_visita gv
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario u ON u.id = gv.id_usuario
      JOIN local   l ON l.id = gv.id_local
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
    $typesGVc = "iii"; $paramsGVc = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $cntGV .= " AND gv.fecha_visita >= ?"; $typesGVc .= "s"; $paramsGVc[] = $start_dt; }
    if ($end_dt   !== null) { $cntGV .= " AND gv.fecha_visita <= ?"; $typesGVc .= "s"; $paramsGVc[] = $end_dt; }
    if ($user_id > 0)       { $cntGV .= " AND gv.id_usuario = ?";    $typesGVc .= "i"; $paramsGVc[] = $user_id; }
    if ($local_code !== '') { $cntGV .= " AND l.codigo = ?";         $typesGVc .= "s"; $paramsGVc[] = $local_code; }
    $cntGV .= " GROUP BY d, uid, lid ";

    $cntFQ = "
      SELECT DATE(fq.fechaVisita) AS d, u.id AS uid, l.id AS lid
      FROM formularioQuestion fq
      JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario u ON u.id = fq.id_usuario
      JOIN local   l ON l.id = fq.id_local
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
    $typesLGc = "iii"; $paramsLGc = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_dt !== null) { $cntFQ .= " AND fq.fechaVisita >= ?"; $typesLGc .= "s"; $paramsLGc[] = $start_dt; }
    if ($end_dt   !== null) { $cntFQ .= " AND fq.fechaVisita <= ?"; $typesLGc .= "s"; $paramsLGc[] = $end_dt; }
    if ($user_id > 0)       { $cntFQ .= " AND fq.id_usuario = ?";   $typesLGc .= "i"; $paramsLGc[] = $user_id; }
    if ($local_code !== '') { $cntFQ .= " AND l.codigo = ?";        $typesLGc .= "s"; $paramsLGc[] = $local_code; }
    $cntFQ .= " GROUP BY d, uid, lid ";

    $countSql   = "SELECT COUNT(*) FROM ( $cntGV UNION ALL $cntFQ ) t";
    $countTypes = $typesGVc . $typesLGc;
    $countParams = array_merge($paramsGVc, $paramsLGc);
}
else {
    $countSql = "
      SELECT COUNT(*) AS total
      FROM form_question_responses fqr
      WHERE fqr.id_form_question IN (
        SELECT id FROM form_questions
        WHERE id_formulario = ? AND id_question_type = 7
      )
        AND fqr.id_local = 0
    ";
    if ($start_dt !== null) { $countSql .= " AND fqr.created_at >= ?"; $countTypes .= "s"; $countParams[] = $start_dt; }
    if ($end_dt   !== null) { $countSql .= " AND fqr.created_at <= ?"; $countTypes .= "s"; $countParams[] = $end_dt; }
    if ($user_id > 0)       { $countSql .= " AND fqr.id_usuario = ?";  $countTypes .= "i"; $countParams[] = $user_id; }
}

$stmtCount = $conn->prepare($countSql);
if (!$stmtCount) { die("<div class='alert alert-danger'>Error conteo: ".htmlspecialchars($conn->error)."</div>"); }
$bindCount = refValues($countParams); array_unshift($bindCount, $countTypes);
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
    .badge-count { position:absolute; top:5px; right:5px; background:rgba(0,0,0,0.6); color:#fff; font-size:.8rem; padding:.2rem .4rem; border-radius:50%; }
    .pagination { flex-wrap:wrap; justify-content:center; gap:5px; }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>Galería de Campaña</h2>

  <?php if ($tipoForm == 1 || $tipoForm == 3): ?>
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link <?= $view==='implementacion'?'active':'' ?>"
           href="?<?= http_build_query(array_merge($_GET,['view'=>'implementacion','page'=>1])) ?>">
          Fotos Implementación
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $view==='encuesta'?'active':'' ?>"
           href="?<?= http_build_query(array_merge($_GET,['view'=>'encuesta','page'=>1])) ?>">
          Fotos Encuesta
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $view==='locales_no_visitados'?'active':'' ?>"
           href="?<?= http_build_query(array_merge($_GET,['view'=>'locales_no_visitados','page'=>1])) ?>">
          Locales No Gestionados
        </a>
      </li>
    </ul>
  <?php endif; ?>

  <form id="filterForm" method="GET" class="form-inline mb-3">
    <input type="hidden" name="id" value="<?= $formulario_id ?>">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <label class="mr-2">Desde:</label>
    <input type="date" name="start_date" class="form-control mr-2" value="<?= htmlspecialchars($start_date) ?>">
    <label class="mr-2">Hasta:</label>
    <input type="date" name="end_date" class="form-control mr-2" value="<?= htmlspecialchars($end_date) ?>">
    <label class="mr-2">Usuario:</label>
    <select name="user_id" class="form-control mr-2">
      <option value="0">-- Todos --</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $u['id']==$user_id?'selected':'' ?>><?= htmlspecialchars($u['usuario']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if (($tipoForm==1||$tipoForm==3) && $view==='implementacion'): ?>
      <label class="mr-2">Material:</label>
      <select name="material_id" class="form-control mr-2">
        <option value="0">-- Todos --</option>
        <?php foreach ($materials as $m): ?>
          <option value="<?= $m['id']?>" <?= $m['id']==$material_id?'selected':'' ?>><?= htmlspecialchars($m['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <?php if (($tipoForm==1||$tipoForm==3) && $view==='encuesta'): ?>
      <label class="mr-2">Pregunta:</label>
      <select name="id_question" class="form-control mr-2">
        <option value="">-- Todas --</option>
        <?php foreach ($preguntasDisponibles as $p): ?>
          <option value="<?= $p['id']?>" <?= $p['id']==$id_question?'selected':'' ?>><?= htmlspecialchars($p['question_text']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <?php if ($tipoForm==1||$tipoForm==3): ?>
      <label class="mr-2">Cód. Local:</label>
      <input type="text" name="local_code" class="form-control mr-2" value="<?= htmlspecialchars($local_code) ?>">
    <?php endif; ?>

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
  <button id="btnDownloadAll" class="btn btn-warning mb-3 ml-2">Descargar Todas las fotos</button>
  <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
  </form>

  <?php if ($view==='implementacion' || $view==='locales_no_visitados'): ?>
    <table class="table table-bordered table-hover">
      <thead class="thead-light">
      <tr>
        <th><input type="checkbox" id="selectAll"></th>
        <th>#</th>
        <th>Imagen</th>
        <th>Cód. Local</th>
        <th>Local</th>
        <th>Dirección</th>
        <?php if ($view==='implementacion'): ?>
          <th>Material</th>
          <th>Cadena</th>
          <th>Cuenta</th>
        <?php else: ?>
          <th>Observación</th>
        <?php endif; ?>
        <th>Usuario</th>
        <th><?= $view==='implementacion'?'Fecha':'Fecha Subida' ?></th>
      </tr>
      </thead>
      <tbody>
      <?php if (empty($data)): ?>
        <tr><td colspan="11" class="text-center">Sin fotos</td></tr>
      <?php else: ?>
        <?php $i = $offset + 1; ?>
        <?php foreach ($data as $row): ?>
          <?php
            $safeUsuario   = preg_replace('/[^a-zA-Z0-9]/', '_', $row['usuario']);
            $safeMaterial  = isset($row['material']) ? preg_replace('/[^a-zA-Z0-9]/', '_', $row['material']) : 'sematerial';
            $safeCodigo    = preg_replace('/[^a-zA-Z0-9]/', '_', $row['local_codigo']);
            $safeDireccion = preg_replace('/[^a-zA-Z0-9]/', '_', $row['local_direccion'] ?? '');
            $safeComuna    = isset($row['comuna_nombre']) ? preg_replace('/[^a-zA-Z0-9]/', '_', $row['comuna_nombre']) : 'sincomuna';

            $thumb         = htmlspecialchars($row['thumbnail'], ENT_QUOTES);
            $badge         = $row['photos_count'];
            $fieldFecha    = $view==='implementacion' ? 'fechaVisita' : 'fechaSubida';
            $fecha         = formatearFecha($row[$fieldFecha] ?? null);
            $phpPrefix     = "{$safeUsuario}_{$safeMaterial}_{$safeCodigo}";
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
              <img src="<?= $thumb ?>"
                   class="thumbnail img-click"
                   loading="lazy" decoding="async"
                   data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>">
            </td>
            <td><?= htmlspecialchars($row['local_codigo'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['local_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($row['local_direccion'], ENT_QUOTES) ?></td>
            <?php if ($view==='implementacion'): ?>
              <td><?= htmlspecialchars($row['material'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['cadena_nombre'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['cuenta_nombre'], ENT_QUOTES) ?></td>
            <?php else: ?>
              <td>
                <?php if (!empty($row['observacion_snippet'])): ?>
                  <div class="small text-muted"><?= htmlspecialchars($row['observacion_snippet'], ENT_QUOTES) ?></div>
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
  <?php else: ?>
    <table class="table table-bordered table-hover">
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
        <tr><td colspan="11" class="text-center">Sin fotos de encuesta</td></tr>
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
              <input type="checkbox" class="imgCheckbox"
                     data-urls="<?= htmlspecialchars($row['urls'], ENT_QUOTES) ?>"
                     data-prefix="<?= $phpPrefix ?>">
            </td>
            <td><?= $i ?></td>
            <td class="custom-img-cell">
              <span class="badge-count"><?= $badge ?></span>
              <img src="<?= $thumb ?>"
                   class="thumbnail img-click"
                   loading="lazy" decoding="async"
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
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav><ul class="pagination">
      <?php if ($page > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($page-1) ?>">Anterior</a></li>
      <?php else: ?>
        <li class="page-item disabled"><span class="page-link">Anterior</span></li>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p == $page): ?>
          <li class="page-item active"><span class="page-link"><?= $p ?></span></li>
        <?php else: ?>
          <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($p) ?>"><?= $p ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <li class="page-item"><a class="page-link" href="<?= buildPaginationUrl($page+1) ?>">Siguiente</a></li>
      <?php else: ?>
        <li class="page-item disabled"><span class="page-link">Siguiente</span></li>
      <?php endif; ?>
    </ul></nav>
  <?php endif; ?>

</div>

<div class="modal fade" id="fullSizeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0 text-center" id="modalBodyImgs"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Modal con todas las imágenes del grupo
  $(document).on('click', '.thumbnail.img-click', function(){
    var urls = $(this).data('urls').split('||');
    var $body = $('#modalBodyImgs').empty();
    urls.forEach(function(src){
      $body.append('<img src="'+ src +'" class="img-fluid mb-2" style="max-height:80vh" loading="lazy" decoding="async">');
    });
    $('#fullSizeModal').modal('show');
  });

  // Select all
  $('#selectAll').on('change', function(){ $('.imgCheckbox').prop('checked', $(this).prop('checked')); });

  // Descarga ZIP (seleccionadas)
  $('#btnDownloadSelected').click(function(){
    var toZip = [];
    $('.imgCheckbox:checked').each(function(){
      var urls   = $(this).data('urls').split('||');
      var prefix = $(this).data('prefix');
      urls.forEach(function(u){
        var name = prefix + '_' + u.split('/').pop();
        toZip.push({url: u, filename: name});
      });
    });
    if (!toZip.length) return alert('Selecciona al menos una fila.');
    $.ajax({
      url: 'download_zip.php',
      method: 'POST',
      data: { jsonFotos: JSON.stringify(toZip) },
      xhrFields: { responseType: 'blob' },
      success: function(data, status, xhr) {
        var disp = xhr.getResponseHeader('Content-Disposition') || '';
        var fname = 'fotos.zip';
        var m = disp.match(/filename[^;=\n]*=\s*(['"]?)([^'"\n]*)/);
        if (m && m[2]) fname = m[2];
        var blob = new Blob([data], {type: 'application/zip'});
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fname;
        document.body.appendChild(link); link.click(); link.remove();
      },
      error: function(_, __, e) { alert('Error al crear ZIP: ' + e); }
    });
  });

  // Descarga ZIP (todas)
  $('#btnDownloadAll').click(function(){
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'all');
    params.set('view', '<?= $view ?>');
    <?php if ($view==='implementacion'): ?>
      params.set('mode', '<?= $mode ?>');
    <?php endif; ?>
    const url = 'download_zip.php?' + params.toString();
    $.ajax({
      url: url, method: 'GET', xhrFields: { responseType: 'blob' },
      success(data, status, xhr) {
        let fname = 'fotos_todas.zip';
        const disp = xhr.getResponseHeader('Content-Disposition') || '';
        const m = disp.match(/filename[^;=\n]*=\s*(['"]?)([^'"\n]*)/);
        if (m && m[2]) fname = m[2];
        const blob = new Blob([data], { type: 'application/zip' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob); link.download = fname;
        document.body.appendChild(link); link.click(); link.remove();
      },
      error(_, __, e) { alert('Error al crear ZIP completo: ' + e); }
    });
  });

  // Selector de limit
  $(function(){
    $('#limitSelect').val('<?= $limit ?>').on('change', function(){
      var url = new URL(window.location.href);
      url.searchParams.set('limit', $(this).val());
      url.searchParams.set('page', 1);
      window.location.href = url.toString();
    });
  });

  // Auto-submit filtros
  $(function(){
    $('#filterForm').on('change', 'input, select', function(){ $('#filterForm').submit(); });
  });
</script>
</body>
</html>
<?php $conn->close(); ?>

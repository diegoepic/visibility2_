<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($usuario_id)) {
    echo json_encode([]);
    exit;
}

// Mismos parámetros que ajax_galeria_table.php (path encuesta)
$division          = intval($_GET['division']    ?? 0);
$subdivision       = intval($_GET['subdivision'] ?? 0);
$region            = intval($_GET['region']      ?? 0);
$zona              = intval($_GET['zona']        ?? 0);
$distrito          = intval($_GET['distrito']    ?? 0);
$comuna            = intval($_GET['comuna']      ?? 0);
$usuarioFiltro     = intval($_GET['usuario']     ?? 0);
$jefeVentaFiltro   = intval($_GET['jefe_venta']  ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local']  ?? '');
$start_date        = trim($_GET['start_date']    ?? '');
$end_date          = trim($_GET['end_date']      ?? '');

// WHERE idéntico al path encuesta de ajax_galeria_table.php (líneas 232-307)
$where  = "1=1";
$params = [];
$types  = "";

if ($division        > 0) { $where .= " AND f.id_division    = ?"; $types .= "i"; $params[] = $division; }
if ($subdivision     > 0) { $where .= " AND f.id_subdivision = ?"; $types .= "i"; $params[] = $subdivision; }
if ($region          > 0) { $where .= " AND r.id             = ?"; $types .= "i"; $params[] = $region; }
if ($zona            > 0) { $where .= " AND z.id             = ?"; $types .= "i"; $params[] = $zona; }
if ($distrito        > 0) { $where .= " AND d.id             = ?"; $types .= "i"; $params[] = $distrito; }
if ($comuna          > 0) { $where .= " AND co.id            = ?"; $types .= "i"; $params[] = $comuna; }
if ($usuarioFiltro   > 0) { $where .= " AND fqr.id_usuario   = ?"; $types .= "i"; $params[] = $usuarioFiltro; }
if ($jefeVentaFiltro > 0) { $where .= " AND l.id_jefe_venta  = ?"; $types .= "i"; $params[] = $jefeVentaFiltro; }

if ($codigoLocalFiltro !== '') {
    $where  .= " AND l.codigo LIKE ?";
    $types  .= "s";
    $params[] = '%' . $codigoLocalFiltro . '%';
}

if ($start_date !== '') {
    $where  .= " AND fqr.created_at >= ?";
    $types  .= "s";
    $params[] = $start_date . ' 00:00:00';
}

if ($end_date !== '') {
    $where  .= " AND fqr.created_at <= ?";
    $types  .= "s";
    $params[] = $end_date . ' 23:59:59';
}

// JOINs idénticos al path encuesta de ajax_galeria_table.php
$sql = "
    SELECT DISTINCT UPPER(TRIM(fq.question_text)) AS question_text
    FROM form_question_responses fqr
    INNER JOIN form_questions fq ON fq.id  = fqr.id_form_question
    INNER JOIN formulario f      ON f.id   = fq.id_formulario
    INNER JOIN local l           ON l.id   = fqr.id_local
    LEFT  JOIN comuna co         ON co.id  = l.id_comuna
    LEFT  JOIN region r          ON r.id   = co.id_region
    LEFT  JOIN distrito d        ON d.id   = l.id_distrito
    LEFT  JOIN zona z            ON z.id   = d.id_zona
    LEFT  JOIN jefe_venta jv     ON jv.id  = l.id_jefe_venta
    INNER JOIN cadena c          ON c.id   = l.id_cadena
    INNER JOIN cuenta ct         ON ct.id  = l.id_cuenta
    INNER JOIN usuario u         ON u.id   = fqr.id_usuario
    WHERE {$where}
      AND fq.id_question_type = 7
      AND COALESCE(TRIM(fqr.answer_text), '') <> ''
      AND fq.deleted_at IS NULL
      AND f.deleted_at  IS NULL
    ORDER BY question_text ASC
";

$out  = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = $r['question_text'];
    }
    $stmt->close();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

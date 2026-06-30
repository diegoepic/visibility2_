<?php
declare(strict_types=1);
// ajax_campanas.php — lista de campañas modalidad "implementacion_por_etapas" de la empresa/división.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesión expirada']);
    exit;
}

$conn->set_charset('utf8mb4');
$empresa_id  = (int)($_SESSION['empresa_id'] ?? 0);
$division_id = (int)($_SESSION['division_id'] ?? 0);
// MC (division 1) ve TODAS las campañas (todas las empresas/divisiones).
$isMC = ($division_id === 1);

// Solo campañas por etapas. MC sin filtro; el resto, su empresa/división.
$sql = "
    SELECT
        f.id,
        f.nombre,
        f.id_division,
        de.nombre AS division_nombre,
        e.nombre AS empresa,
        DATE(f.fechaInicio)  AS fecha_inicio,
        DATE(f.fechaTermino) AS fecha_termino,
        COUNT(fq.id)                                                              AS total_materiales,
        COUNT(DISTINCT fq.id_local)                                              AS total_locales,
        SUM(CASE
              WHEN fq.etapa_material = 'retirado'
                OR (fq.etapa_material = 'implementado'
                    AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED)
                        >= CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))
              THEN 1 ELSE 0 END)                                                  AS materiales_completos
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    LEFT  JOIN empresa e          ON e.id = f.id_empresa
    LEFT  JOIN division_empresa de ON de.id = f.id_division
    WHERE f.modalidad = 'implementacion_por_etapas'
      " . ($isMC ? "" : "AND f.id_empresa = ? AND (? = 0 OR f.id_division = ?)") . "
    GROUP BY f.id, f.nombre, f.id_division, de.nombre, e.nombre, f.fechaInicio, f.fechaTermino
    ORDER BY f.fechaInicio DESC, f.nombre ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error SQL: ' . $conn->error]);
    exit;
}
if (!$isMC) {
    $stmt->bind_param('iii', $empresa_id, $division_id, $division_id);
}
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $total = (int)$row['total_materiales'];
    $comp  = (int)$row['materiales_completos'];
    $row['pct_completado'] = $total > 0 ? round($comp * 100 / $total) : 0;
    $data[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'ok' => false,
        'message' => 'Sesión no válida'
    ]);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$id_empresa = (int)($_SESSION['empresa_id'] ?? 0);

$id_ejecutor = isset($_GET['id_ejecutor'])
    ? (int)$_GET['id_ejecutor']
    : 0;

$formulario_estado = $_GET['formulario_estado'] ?? 'activos';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

if ($id_ejecutor <= 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'Ejecutor inválido'
    ]);
    exit;
}

/* ======================================================
   MISMA LÓGICA DE FECHA VÁLIDA Y GESTIÓN ACTIVA
====================================================== */
$fechaValida = "
    fq.fechaPropuesta IS NOT NULL
    AND CAST(fq.fechaPropuesta AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaPropuesta AS CHAR(10)) <> '0000-00-00'
";

$gestionFinalizada = "
    (
        COALESCE(fq.countVisita, 0) > 0
        OR fq.pregunta IN (
            'solo_auditoria',
            'solo_implementado',
            'implementado_auditado',
            'completado'
        )
    )
";

$gestionActiva = "
    (
        fq.id IS NOT NULL
        AND NOT $gestionFinalizada
    )
";

/* ======================================================
   RESUMEN POR FORMULARIO ACTIVO DEL TRABAJADOR
====================================================== */
$sql = "
    SELECT
        f.id AS id_formulario,
        COALESCE(f.nombre, 'SIN FORMULARIO') AS formulario,
        COALESCE(df.nombre, 'SIN DIVISIÓN') AS division_formulario,
        COALESCE(sf.nombre, '') AS subdivision_formulario,

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_pendiente,

        COUNT(DISTINCT CASE
            WHEN DATE(fq.fechaPropuesta) < CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_vencido,

        COUNT(DISTINCT CASE
            WHEN DATE(fq.fechaPropuesta) = CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_hoy,

        COUNT(DISTINCT CASE
            WHEN DATE(fq.fechaPropuesta) > CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_futuro,

        MIN(DATE(fq.fechaPropuesta)) AS primera_pendiente,
        MAX(DATE(fq.fechaPropuesta)) AS ultima_planificacion

    FROM formularioQuestion fq

    INNER JOIN usuario u
        ON u.id = fq.id_usuario
       AND u.id_empresa = ?
       AND u.activo = 1

    INNER JOIN formulario f
        ON f.id = fq.id_formulario

    LEFT JOIN division_empresa df
        ON df.id = f.id_division

    LEFT JOIN subdivision sf
        ON sf.id = f.id_subdivision

    WHERE fq.id_usuario = ?
      AND $fechaValida
      AND $gestionActiva
";

$types = "ii";
$params = [$id_empresa, $id_ejecutor];

if (!empty($fecha_desde)) {
    $sql .= " AND DATE(fq.fechaPropuesta) >= ? ";
    $types .= "s";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $sql .= " AND DATE(fq.fechaPropuesta) <= ? ";
    $types .= "s";
    $params[] = $fecha_hasta;
}

if ($formulario_estado === 'activos') {
    $sql .= " AND f.estado = 1 ";
} elseif ($formulario_estado === 'inactivos') {
    $sql .= " AND f.estado <> 1 ";
}

$sql .= "
    GROUP BY
        f.id,
        f.nombre,
        df.nombre,
        sf.nombre

    ORDER BY
        primera_pendiente ASC,
        ultima_planificacion DESC,
        f.nombre ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

$totales = [
    'total_formularios' => 0,
    'total_pendiente' => 0,
    'primera_pendiente' => null,
    'ultima_planificacion' => null
];

while ($row = $res->fetch_assoc()) {
    $row['id_formulario'] = (int)$row['id_formulario'];
    $row['total_pendiente'] = (int)$row['total_pendiente'];
    $row['total_vencido'] = (int)$row['total_vencido'];
    $row['total_hoy'] = (int)$row['total_hoy'];
    $row['total_futuro'] = (int)$row['total_futuro'];

    $data[] = $row;

    $totales['total_formularios']++;
    $totales['total_pendiente'] += $row['total_pendiente'];

    if (!empty($row['primera_pendiente'])) {
        if ($totales['primera_pendiente'] === null || $row['primera_pendiente'] < $totales['primera_pendiente']) {
            $totales['primera_pendiente'] = $row['primera_pendiente'];
        }
    }

    if (!empty($row['ultima_planificacion'])) {
        if ($totales['ultima_planificacion'] === null || $row['ultima_planificacion'] > $totales['ultima_planificacion']) {
            $totales['ultima_planificacion'] = $row['ultima_planificacion'];
        }
    }
}

$stmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'totales' => $totales,
    'data' => $data
], JSON_UNESCAPED_UNICODE);
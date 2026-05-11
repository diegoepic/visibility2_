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

$division_usuario = isset($_GET['division_usuario'])
    ? (int)$_GET['division_usuario']
    : 0;

$subdivision_usuario = isset($_GET['subdivision_usuario'])
    ? (int)$_GET['subdivision_usuario']
    : 0;

$clasificacion_usuario = $_GET['clasificacion_usuario'] ?? 'todos';
$estado_gestion = $_GET['estado_gestion'] ?? 'todos';
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
   MISMA LÓGICA DEL PANEL
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

$sql = "
    SELECT
        f.id AS id_formulario,
        COALESCE(f.nombre, 'SIN FORMULARIO') AS formulario,
        COALESCE(df.nombre, 'SIN DIVISIÓN') AS division_formulario,
        COALESCE(sf.nombre, '') AS subdivision_formulario,
        DATE(fq.fechaPropuesta) AS fecha_planificada,

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total

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
";

$types = "ii";
$params = [$id_empresa, $id_ejecutor];

if ($division_usuario > 0) {
    $sql .= " AND u.id_division = ? ";
    $types .= "i";
    $params[] = $division_usuario;
}

if ($subdivision_usuario > 0) {
    $sql .= " AND u.id_subdivision = ? ";
    $types .= "i";
    $params[] = $subdivision_usuario;
}

if ($clasificacion_usuario === 'interno' || $clasificacion_usuario === 'externo') {
    $sql .= " AND u.clasificacion_usuario = ? ";
    $types .= "s";
    $params[] = $clasificacion_usuario;
}

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

if ($estado_gestion === 'activa') {
    $sql .= " AND $gestionActiva ";
} elseif ($estado_gestion === 'finalizada') {
    $sql .= " AND $gestionFinalizada ";
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
        sf.nombre,
        DATE(fq.fechaPropuesta)

    ORDER BY
        f.nombre ASC,
        fecha_planificada ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$map = [];
$fechasSet = [];
$totalesPorFecha = [];

$totalPlanificado = 0;

while ($row = $res->fetch_assoc()) {
    $idFormulario = (int)$row['id_formulario'];
    $fecha = $row['fecha_planificada'];
    $total = (int)$row['total'];

    if (!isset($map[$idFormulario])) {
        $map[$idFormulario] = [
            'id_formulario' => $idFormulario,
            'formulario' => $row['formulario'],
            'division_formulario' => $row['division_formulario'],
            'subdivision_formulario' => $row['subdivision_formulario'],
            'total' => 0,
            'fechas' => []
        ];
    }

    $map[$idFormulario]['fechas'][$fecha] = $total;
    $map[$idFormulario]['total'] += $total;

    $fechasSet[$fecha] = true;

    if (!isset($totalesPorFecha[$fecha])) {
        $totalesPorFecha[$fecha] = 0;
    }

    $totalesPorFecha[$fecha] += $total;
    $totalPlanificado += $total;
}

$stmt->close();

$fechas = array_keys($fechasSet);
sort($fechas);

$data = array_values($map);

usort($data, function ($a, $b) {
    return strcmp($a['formulario'], $b['formulario']);
});

/* ======================================================
   PORCENTAJE FINALIZADO POR FORMULARIO Y POR FECHA
====================================================== */
$sqlPct = "
    SELECT
        f.id AS id_formulario,
        DATE(fq.fechaPropuesta) AS fecha_planificada,
        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_general,
        COUNT(DISTINCT CASE
            WHEN $gestionFinalizada
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_finalizado
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
";

$typesPct = "ii";
$paramsPct = [$id_empresa, $id_ejecutor];

if ($division_usuario > 0) {
    $sqlPct .= " AND u.id_division = ? ";
    $typesPct .= "i";
    $paramsPct[] = $division_usuario;
}

if ($subdivision_usuario > 0) {
    $sqlPct .= " AND u.id_subdivision = ? ";
    $typesPct .= "i";
    $paramsPct[] = $subdivision_usuario;
}

if ($clasificacion_usuario === 'interno' || $clasificacion_usuario === 'externo') {
    $sqlPct .= " AND u.clasificacion_usuario = ? ";
    $typesPct .= "s";
    $paramsPct[] = $clasificacion_usuario;
}

if (!empty($fecha_desde)) {
    $sqlPct .= " AND DATE(fq.fechaPropuesta) >= ? ";
    $typesPct .= "s";
    $paramsPct[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $sqlPct .= " AND DATE(fq.fechaPropuesta) <= ? ";
    $typesPct .= "s";
    $paramsPct[] = $fecha_hasta;
}

if ($formulario_estado === 'activos') {
    $sqlPct .= " AND f.estado = 1 ";
} elseif ($formulario_estado === 'inactivos') {
    $sqlPct .= " AND f.estado <> 1 ";
}

$sqlPct .= "
    GROUP BY f.id, DATE(fq.fechaPropuesta)
";

$stmtPct = $conn->prepare($sqlPct);
$stmtPct->bind_param($typesPct, ...$paramsPct);
$stmtPct->execute();
$resPct = $stmtPct->get_result();

$metaPct = [];
$totalGeneralGlobal = 0;
$totalFinalizadoGlobal = 0;
$porcentajeGlobalPorFechaBase = [];

while ($rowPct = $resPct->fetch_assoc()) {
    $idFormulario = (int)$rowPct['id_formulario'];
    $fecha = $rowPct['fecha_planificada'];
    $totalGeneral = (int)$rowPct['total_general'];
    $totalFinalizadoFormulario = (int)$rowPct['total_finalizado'];

    if (!isset($metaPct[$idFormulario])) {
        $metaPct[$idFormulario] = [
            'total_general' => 0,
            'total_finalizado' => 0,
            'porcentaje_finalizado' => 0,
            'porcentaje_por_fecha' => []
        ];
    }

    $metaPct[$idFormulario]['total_general'] += $totalGeneral;
    $metaPct[$idFormulario]['total_finalizado'] += $totalFinalizadoFormulario;

    $metaPct[$idFormulario]['porcentaje_por_fecha'][$fecha] = $totalGeneral > 0
        ? round(($totalFinalizadoFormulario / $totalGeneral) * 100)
        : 0;

    if (!isset($porcentajeGlobalPorFechaBase[$fecha])) {
        $porcentajeGlobalPorFechaBase[$fecha] = [
            'total_general' => 0,
            'total_finalizado' => 0
        ];
    }

    $porcentajeGlobalPorFechaBase[$fecha]['total_general'] += $totalGeneral;
    $porcentajeGlobalPorFechaBase[$fecha]['total_finalizado'] += $totalFinalizadoFormulario;

    $totalGeneralGlobal += $totalGeneral;
    $totalFinalizadoGlobal += $totalFinalizadoFormulario;
}

$stmtPct->close();
$conn->close();

$porcentajeGlobalPorFecha = [];

foreach ($porcentajeGlobalPorFechaBase as $fecha => $vals) {
    $porcentajeGlobalPorFecha[$fecha] = $vals['total_general'] > 0
        ? round(($vals['total_finalizado'] / $vals['total_general']) * 100)
        : 0;
}

foreach ($metaPct as $idFormulario => $vals) {
    $metaPct[$idFormulario]['porcentaje_finalizado'] = $vals['total_general'] > 0
        ? round(($vals['total_finalizado'] / $vals['total_general']) * 100)
        : 0;
}

/* Inyectar % en cada formulario del detalle */
foreach ($data as &$item) {
    $idFormulario = (int)$item['id_formulario'];

    $item['porcentaje_finalizado'] = isset($metaPct[$idFormulario])
        ? (int)$metaPct[$idFormulario]['porcentaje_finalizado']
        : 0;

    $item['porcentaje_por_fecha'] = isset($metaPct[$idFormulario]['porcentaje_por_fecha'])
        ? $metaPct[$idFormulario]['porcentaje_por_fecha']
        : [];
}
unset($item);

$porcentajeFinalizadoGlobal = $totalGeneralGlobal > 0
    ? round(($totalFinalizadoGlobal / $totalGeneralGlobal) * 100)
    : 0;

echo json_encode([
    'ok' => true,
    'fechas' => $fechas,
    'totales_por_fecha' => $totalesPorFecha,
    'porcentaje_global_por_fecha' => $porcentajeGlobalPorFecha,
    'totales' => [
        'total_formularios' => count($data),
        'total_planificado' => $totalPlanificado,
        'total_fechas' => count($fechas),
        'porcentaje_finalizado' => $porcentajeFinalizadoGlobal
    ],
    'data' => $data
], JSON_UNESCAPED_UNICODE);
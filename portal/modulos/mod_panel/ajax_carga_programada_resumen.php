<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'ok' => false,
        'message' => 'Sesion no valida'
    ]);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$id_empresa = (int)($_SESSION['empresa_id'] ?? 0);

$id_ejecutor = isset($_GET['id_ejecutor'])
    ? (int)$_GET['id_ejecutor']
    : 0;

$division_usuario = isset($_GET['division_usuario']) ? (int)$_GET['division_usuario'] : 0;
$subdivision_usuario = isset($_GET['subdivision_usuario']) ? (int)$_GET['subdivision_usuario'] : 0;
$clasificacion_usuario = strtolower(trim((string)($_GET['clasificacion_usuario'] ?? 'todos')));

$formulario_estado = $_GET['formulario_estado'] ?? 'activos';
$estado_gestion = $_GET['estado_gestion'] ?? 'todos';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
// Mismo alcance del panel: en curso/todos es global; sólo finalizados admite fechas.
$permitirFiltroFecha = $formulario_estado === 'inactivos' && $estado_gestion !== 'activa';
if (!$permitirFiltroFecha) {
    $fecha_desde = '';
    $fecha_hasta = '';
}
$usarFechaVisita = !empty($fecha_desde) || !empty($fecha_hasta);
$fechaMedicionSql = $usarFechaVisita ? 'fq.fechaVisita' : 'fq.fechaPropuesta';

if ($id_ejecutor <= 0) {
    echo json_encode([
        'ok' => false,
        'message' => 'Ejecutor invalido'
    ]);
    exit;
}

/* ======================================================
   MISMA LOGICA DE FECHA VALIDA Y GESTION
====================================================== */
$fechaValida = "
    fq.fechaPropuesta IS NOT NULL
    AND CAST(fq.fechaPropuesta AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaPropuesta AS CHAR(10)) <> '0000-00-00'
";

$fechaVisitaValida = "
    fq.fechaVisita IS NOT NULL
    AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaVisita AS CHAR(10)) <> '0000-00-00'
";

$fechaMedicionValida = $usarFechaVisita ? $fechaVisitaValida : $fechaValida;

$gestionFinalizada = "
    (
        COALESCE(fq.countVisita, 0) > 0
        OR COALESCE(fq.pregunta, '') IN (
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
   RESUMEN POR FORMULARIO DEL TRABAJADOR
====================================================== */
$sql = "
    SELECT
        f.id AS id_formulario,
        COALESCE(f.nombre, 'SIN FORMULARIO') AS formulario,
        COALESCE(df.nombre, 'SIN DIVISION') AS division_formulario,
        COALESCE(sf.nombre, '') AS subdivision_formulario,

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_asignado,

        COUNT(DISTINCT CASE
            WHEN $fechaVisitaValida
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_visitado,

        COUNT(DISTINCT CASE
            WHEN $gestionFinalizada
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_gestionado,

        COUNT(DISTINCT CASE
            WHEN $gestionActiva
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_pendiente,

        COUNT(DISTINCT CASE
            WHEN $gestionActiva AND DATE(fq.fechaPropuesta) < CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_vencido,

        COUNT(DISTINCT CASE
            WHEN $gestionActiva AND DATE(fq.fechaPropuesta) = CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_hoy,

        COUNT(DISTINCT CASE
            WHEN $gestionActiva AND DATE(fq.fechaPropuesta) > CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_futuro,

        MIN(CASE WHEN $gestionActiva THEN DATE(fq.fechaPropuesta) END) AS primera_pendiente,
        MAX(DATE($fechaMedicionSql)) AS ultima_planificacion

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
      AND $fechaMedicionValida
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
    $sql .= " AND DATE(fq.fechaVisita) >= ? ";
    $types .= "s";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $sql .= " AND DATE(fq.fechaVisita) <= ? ";
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
    $row['total_asignado'] = (int)$row['total_asignado'];
    $row['total_visitado'] = (int)$row['total_visitado'];
    $row['total_gestionado'] = (int)$row['total_gestionado'];
    $row['ratio_avance'] = $row['total_asignado'] > 0
        ? round(($row['total_gestionado'] / $row['total_asignado']) * 100)
        : 0;
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

if ($estado_gestion !== 'activa') {
    $sqlIw = "
        SELECT
            f.id AS id_formulario,
            CONCAT(COALESCE(f.nombre, 'SIN FORMULARIO'), ' (IW)') AS formulario,
            COALESCE(df.nombre, 'SIN DIVISION') AS division_formulario,
            COALESCE(sf.nombre, '') AS subdivision_formulario,

            COUNT(DISTINCT CONCAT(
                f.id,
                '|',
                COALESCE(r.id_local, v.id_local, 0)
            )) AS total_asignado,

            COUNT(DISTINCT CONCAT(
                f.id,
                '|',
                COALESCE(r.id_local, v.id_local, 0)
            )) AS total_visitado,

            COUNT(DISTINCT CONCAT(
                f.id,
                '|',
                COALESCE(r.id_local, v.id_local, 0)
            )) AS total_gestionado,

            0 AS total_pendiente,

            0 AS total_vencido,

            0 AS total_hoy,

            0 AS total_futuro,
            NULL AS primera_pendiente,
            MAX(DATE(r.created_at)) AS ultima_planificacion

        FROM form_question_responses r

        INNER JOIN form_questions q
            ON q.id = r.id_form_question
           AND q.id_question_type <> 7

        INNER JOIN formulario f
            ON f.id = q.id_formulario
           AND f.tipo = 2
           AND f.id NOT IN (138, 2187)

        INNER JOIN usuario u
            ON u.id = r.id_usuario
           AND u.id_empresa = ?
           AND u.activo = 1

        LEFT JOIN visita v
            ON v.id = r.visita_id

        LEFT JOIN division_empresa df
            ON df.id = f.id_division

        LEFT JOIN subdivision sf
            ON sf.id = f.id_subdivision

        WHERE r.id_usuario = ?
    ";

    $typesIw = "ii";
    $paramsIw = [$id_empresa, $id_ejecutor];

    if ($division_usuario > 0) {
        $sqlIw .= " AND u.id_division = ? ";
        $typesIw .= "i";
        $paramsIw[] = $division_usuario;
    }

    if ($subdivision_usuario > 0) {
        $sqlIw .= " AND u.id_subdivision = ? ";
        $typesIw .= "i";
        $paramsIw[] = $subdivision_usuario;
    }

    if ($clasificacion_usuario === 'interno' || $clasificacion_usuario === 'externo') {
        $sqlIw .= " AND u.clasificacion_usuario = ? ";
        $typesIw .= "s";
        $paramsIw[] = $clasificacion_usuario;
    }

    if (!empty($fecha_desde)) {
        $sqlIw .= " AND DATE(r.created_at) >= ? ";
        $typesIw .= "s";
        $paramsIw[] = $fecha_desde;
    }

    if (!empty($fecha_hasta)) {
        $sqlIw .= " AND DATE(r.created_at) <= ? ";
        $typesIw .= "s";
        $paramsIw[] = $fecha_hasta;
    }

    if ($formulario_estado === 'activos') {
        $sqlIw .= " AND f.estado = 1 ";
    } elseif ($formulario_estado === 'inactivos') {
        $sqlIw .= " AND f.estado <> 1 ";
    }

    $sqlIw .= "
        GROUP BY
            f.id,
            f.nombre,
            df.nombre,
            sf.nombre

        HAVING total_asignado > 0

        ORDER BY
            primera_pendiente ASC,
            ultima_planificacion DESC,
            f.nombre ASC
    ";

    $stmtIw = $conn->prepare($sqlIw);
    $stmtIw->bind_param($typesIw, ...$paramsIw);
    $stmtIw->execute();
    $resIw = $stmtIw->get_result();

    while ($rowIw = $resIw->fetch_assoc()) {
        $rowIw['id_formulario'] = (int)$rowIw['id_formulario'];
        $rowIw['total_asignado'] = (int)$rowIw['total_asignado'];
        $rowIw['total_visitado'] = (int)$rowIw['total_visitado'];
        $rowIw['total_gestionado'] = (int)$rowIw['total_gestionado'];
        $rowIw['ratio_avance'] = 100;
        $rowIw['total_pendiente'] = (int)$rowIw['total_pendiente'];
        $rowIw['total_vencido'] = (int)$rowIw['total_vencido'];
        $rowIw['total_hoy'] = (int)$rowIw['total_hoy'];
        $rowIw['total_futuro'] = (int)$rowIw['total_futuro'];

        $data[] = $rowIw;

        $totales['total_formularios']++;
        $totales['total_pendiente'] += $rowIw['total_pendiente'];

        if (!empty($rowIw['primera_pendiente'])) {
            if ($totales['primera_pendiente'] === null || $rowIw['primera_pendiente'] < $totales['primera_pendiente']) {
                $totales['primera_pendiente'] = $rowIw['primera_pendiente'];
            }
        }

        if (!empty($rowIw['ultima_planificacion'])) {
            if ($totales['ultima_planificacion'] === null || $rowIw['ultima_planificacion'] > $totales['ultima_planificacion']) {
                $totales['ultima_planificacion'] = $rowIw['ultima_planificacion'];
            }
        }
    }

    $stmtIw->close();
}

usort($data, function ($a, $b) {
    $fechaA = $a['primera_pendiente'] ?? '9999-12-31';
    $fechaB = $b['primera_pendiente'] ?? '9999-12-31';

    if ($fechaA === $fechaB) {
        return strcmp($a['formulario'], $b['formulario']);
    }

    return strcmp($fechaA, $fechaB);
});

$departamentosMap = [];
$campanasUnicas = [];
$totalAsignado = 0;
$totalVisitado = 0;
$totalGestionado = 0;

foreach ($data as $item) {
    $departamento = trim((string)($item['division_formulario'] ?? ''));
    if ($departamento === '') {
        $departamento = 'SIN DEPARTAMENTO';
    }
    if (!isset($departamentosMap[$departamento])) {
        $departamentosMap[$departamento] = [
            'departamento' => $departamento,
            'total_asignado' => 0,
            'total_visitado' => 0,
            'total_gestionado' => 0,
            'ratio_avance' => 0,
            'campanas' => [],
        ];
    }

    $asignado = (int)($item['total_asignado'] ?? 0);
    $visitado = (int)($item['total_visitado'] ?? 0);
    $gestionado = (int)($item['total_gestionado'] ?? 0);
    $campaignKey = (string)($item['id_formulario'] ?? '') . '|' . (string)($item['formulario'] ?? '');
    $campanasUnicas[$departamento . '|' . $campaignKey] = true;

    $departamentosMap[$departamento]['total_asignado'] += $asignado;
    $departamentosMap[$departamento]['total_visitado'] += $visitado;
    $departamentosMap[$departamento]['total_gestionado'] += $gestionado;
    $departamentosMap[$departamento]['campanas'][] = [
        'id_formulario' => $item['id_formulario'],
        'formulario' => $item['formulario'],
        'total_asignado' => $asignado,
        'total_visitado' => $visitado,
        'total_gestionado' => $gestionado,
        'ratio_avance' => (int)($item['ratio_avance'] ?? 0),
    ];

    $totalAsignado += $asignado;
    $totalVisitado += $visitado;
    $totalGestionado += $gestionado;
}

$departamentos = array_values($departamentosMap);
foreach ($departamentos as &$departamento) {
    $departamento['ratio_avance'] = $departamento['total_asignado'] > 0
        ? round(($departamento['total_gestionado'] / $departamento['total_asignado']) * 100)
        : 0;
    usort($departamento['campanas'], static function (array $left, array $right): int {
        return strcasecmp((string)$left['formulario'], (string)$right['formulario']);
    });
}
unset($departamento);
usort($departamentos, static function (array $left, array $right): int {
    return strcasecmp((string)$left['departamento'], (string)$right['departamento']);
});

$totales['total_departamentos'] = count($departamentos);
$totales['total_campanas'] = count($campanasUnicas);
$totales['total_asignado'] = $totalAsignado;
$totales['total_visitado'] = $totalVisitado;
$totales['total_gestionado'] = $totalGestionado;
$totales['ratio_avance'] = $totalAsignado > 0
    ? round(($totalGestionado / $totalAsignado) * 100)
    : 0;
$conn->close();

echo json_encode([
    'ok' => true,
    'totales' => $totales,
    'departamentos' => $departamentos,
    'data' => $data
], JSON_UNESCAPED_UNICODE);

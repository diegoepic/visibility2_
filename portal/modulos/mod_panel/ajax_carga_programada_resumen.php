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

$formulario_estado = $_GET['formulario_estado'] ?? 'activos';
$estado_gestion = $_GET['estado_gestion'] ?? 'todos';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
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
   RESUMEN POR FORMULARIO DEL TRABAJADOR
====================================================== */
$sql = "
    SELECT
        f.id AS id_formulario,
        COALESCE(f.nombre, 'SIN FORMULARIO') AS formulario,
        COALESCE(df.nombre, 'SIN DIVISION') AS division_formulario,
        COALESCE(sf.nombre, '') AS subdivision_formulario,

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_pendiente,

        COUNT(DISTINCT CASE
            WHEN DATE($fechaMedicionSql) < CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_vencido,

        COUNT(DISTINCT CASE
            WHEN DATE($fechaMedicionSql) = CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_hoy,

        COUNT(DISTINCT CASE
            WHEN DATE($fechaMedicionSql) > CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_futuro,

        MIN(DATE($fechaMedicionSql)) AS primera_pendiente,
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
      AND $fechaValida
";

$types = "ii";
$params = [$id_empresa, $id_ejecutor];

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
                COALESCE(r.id_local, v.id_local, 0),
                '|',
                COALESCE(r.visita_id, 0),
                '|',
                DATE(r.created_at),
                '|',
                TIME(r.created_at)
            )) AS total_pendiente,

            0 AS total_vencido,

            COUNT(DISTINCT CASE
                WHEN DATE(r.created_at) = CURDATE()
                THEN CONCAT(
                    f.id,
                    '|',
                    COALESCE(r.id_local, v.id_local, 0),
                    '|',
                    COALESCE(r.visita_id, 0),
                    '|',
                    DATE(r.created_at),
                    '|',
                    TIME(r.created_at)
                )
            END) AS total_hoy,

            0 AS total_futuro,
            MIN(DATE(r.created_at)) AS primera_pendiente,
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

        HAVING total_pendiente > 0

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
$conn->close();

echo json_encode([
    'ok' => true,
    'totales' => $totales,
    'data' => $data
], JSON_UNESCAPED_UNICODE);

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
require_once __DIR__ . '/_auth_mobile.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    mobile_json_response(500, [
        'ok' => false,
        'message' => 'No hay conexión válida a la base de datos'
    ]);
}
$conn->set_charset('utf8mb4');

$user = mobile_require_auth($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobile_json_response(405, [
        'ok' => false,
        'message' => 'Método no permitido'
    ]);
}

$usuarioId = (int)$user['id'];
$empresaId = (int)$user['id_empresa'];
$hoy       = date('Y-m-d');

function dbFetchAllAssocHome(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        mobile_json_response(500, [
            'ok' => false,
            'message' => 'Error al preparar consulta',
            'db_error' => $conn->error
        ]);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        mobile_json_response(500, [
            'ok' => false,
            'message' => 'Error al ejecutar consulta',
            'db_error' => $err
        ]);
    }

    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function dbFetchOneAssocHome(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = dbFetchAllAssocHome($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

/*
|--------------------------------------------------------------------------
| 1) Campañas programadas
|--------------------------------------------------------------------------
| Asume que fq.fechaPropuesta existe. Si en tu instalación el campo real
| de agenda es otro, ajustamos solo este WHERE.
*/
$sqlCampanas = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        COUNT(DISTINCT fq.id_local) AS total_locales
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND DATE(fq.fechaPropuesta) = ?
    GROUP BY f.id, f.nombre
    ORDER BY f.nombre ASC
";
$campanas = dbFetchAllAssocHome($conn, $sqlCampanas, "iis", [$usuarioId, $empresaId, $hoy]);

/*
|--------------------------------------------------------------------------
| 2) Locales programados
|--------------------------------------------------------------------------
*/
$sqlProgramados = "
    SELECT
        fq.id_local,
        l.codigo AS codigo_local,
        l.nombre AS nombre_local,
        l.direccion AS direccion_local,
        COALESCE(co.comuna, '') AS comuna,
        COALESCE(c.nombre, '') AS cadena,
        l.lat AS latitud,
        l.lng AS longitud,
        COUNT(DISTINCT fq.id_formulario) AS total_campanas,
        GROUP_CONCAT(DISTINCT fq.id_formulario ORDER BY fq.id_formulario SEPARATOR ',') AS campanas_ids
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN comuna co ON co.id = l.id_comuna
    LEFT JOIN cadena c ON c.id = l.id_cadena
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND DATE(fq.fechaPropuesta) = ?
    GROUP BY fq.id_local, l.codigo, l.nombre, l.direccion, co.comuna, c.nombre, l.lat, l.lng
    ORDER BY l.codigo ASC
";
$programadosRaw = dbFetchAllAssocHome($conn, $sqlProgramados, "iis", [$usuarioId, $empresaId, $hoy]);

$programados = [];
foreach ($programadosRaw as $row) {
    $programados[] = [
        'id_local' => (int)$row['id_local'],
        'codigo_local' => (string)($row['codigo_local'] ?? ''),
        'nombre_local' => (string)($row['nombre_local'] ?? ''),
        'direccion_local' => (string)($row['direccion_local'] ?? ''),
        'comuna' => (string)($row['comuna'] ?? ''),
        'cadena' => (string)($row['cadena'] ?? ''),
        'latitud' => (float)($row['latitud'] ?? 0),
        'longitud' => (float)($row['longitud'] ?? 0),
        'total_campanas' => (int)($row['total_campanas'] ?? 0),
        'campanas_ids' => array_values(array_filter(explode(',', (string)($row['campanas_ids'] ?? '')))),
    ];
}

/*
|--------------------------------------------------------------------------
| 3) Reagendados
|--------------------------------------------------------------------------
| V1: usa fechaPropuesta < hoy y sin fechaVisita cerrada.
| Si tu lógica real de reagendado usa otra condición, la afinamos luego.
*/
$sqlReag = "
    SELECT
        fq.id_local,
        l.codigo AS codigo_local,
        l.nombre AS nombre_local,
        l.direccion AS direccion_local,
        COALESCE(c.nombre, '') AS cadena,
        l.lat AS latitud,
        l.lng AS longitud,
        COUNT(DISTINCT fq.id_formulario) AS total_campanas,
        GROUP_CONCAT(DISTINCT fq.id_formulario ORDER BY fq.id_formulario SEPARATOR ',') AS campanas_ids
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN cadena c ON c.id = l.id_cadena
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND fq.fechaPropuesta IS NOT NULL
      AND DATE(fq.fechaPropuesta) < ?
      AND (fq.fechaVisita IS NULL OR fq.fechaVisita = '0000-00-00 00:00:00')
    GROUP BY fq.id_local, l.codigo, l.nombre, l.direccion, c.nombre, l.lat, l.lng
    ORDER BY l.codigo ASC
";
$reagRaw = dbFetchAllAssocHome($conn, $sqlReag, "iis", [$usuarioId, $empresaId, $hoy]);

$reagendados = [];
foreach ($reagRaw as $row) {
    $reagendados[] = [
        'id_local' => (int)$row['id_local'],
        'codigo_local' => (string)($row['codigo_local'] ?? ''),
        'nombre_local' => (string)($row['nombre_local'] ?? ''),
        'direccion_local' => (string)($row['direccion_local'] ?? ''),
        'cadena' => (string)($row['cadena'] ?? ''),
        'latitud' => (float)($row['latitud'] ?? 0),
        'longitud' => (float)($row['longitud'] ?? 0),
        'total_campanas' => (int)($row['total_campanas'] ?? 0),
        'campanas_ids' => array_values(array_filter(explode(',', (string)($row['campanas_ids'] ?? '')))),
    ];
}

/*
|--------------------------------------------------------------------------
| 4) Actividades complementarias
|--------------------------------------------------------------------------
| V1: campañas del usuario para hoy sin local específico.
| Si tu lógica actual usa otra marca, la afinamos después.
*/
$sqlComplementarias = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.modalidad
    FROM formulario f
    WHERE f.id_empresa = ?
      AND f.id IN (
          SELECT DISTINCT fq.id_formulario
          FROM formularioQuestion fq
          WHERE fq.id_usuario = ?
            AND DATE(fq.fechaPropuesta) = ?
      )
    ORDER BY f.nombre ASC
";
$compRaw = dbFetchAllAssocHome($conn, $sqlComplementarias, "iis", [$empresaId, $usuarioId, $hoy]);

$complementarias = [];
foreach ($compRaw as $row) {
    $complementarias[] = [
        'id_campana' => (int)$row['id_campana'],
        'nombre_campana' => (string)($row['nombre_campana'] ?? ''),
        'modalidad' => (string)($row['modalidad'] ?? ''),
    ];
}

$summary = [
    'campanas_programadas' => count($campanas),
    'locales_programados' => count($programados),
    'locales_reagendados' => count($reagendados),
    'actividades_complementarias' => count($complementarias),
];

mobile_json_response(200, [
    'ok' => true,
    'message' => 'Home cargado',
    'data' => [
        'fecha' => $hoy,
        'user' => [
            'id' => (int)$user['id'],
            'nombre_completo' => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
            'empresa_id' => (int)$user['id_empresa'],
            'empresa_nombre' => (string)$user['empresa_nombre'],
            'division_id' => (int)$user['id_division'],
        ],
        'summary' => $summary,
        'campanas' => $campanas,
        'programados' => $programados,
        'reagendados' => $reagendados,
        'complementarias' => $complementarias,
    ]
]);
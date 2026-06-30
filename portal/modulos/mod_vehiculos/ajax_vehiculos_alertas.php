<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexi¨®n.']);
    exit;
}

$sql = "
    SELECT
        vd.id,
        vd.id_vehiculo,
        vd.tipo,
        vd.fecha_vencimiento,
        v.patente,
        u.nombre,
        u.apellido,
        DATEDIFF(vd.fecha_vencimiento, CURDATE()) AS dias_restantes
    FROM vehiculo_documento vd
    JOIN vehiculo v ON v.id = vd.id_vehiculo AND v.deleted_at IS NULL AND v.estado = 1
    LEFT JOIN usuario u ON u.id = v.id_merchan
    WHERE vd.deleted_at IS NULL
      AND vd.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY vd.fecha_vencimiento ASC
";

$res = $mysqli->query($sql);

if (!$res) {
    echo json_encode(['ok' => false, 'msg' => 'Error en consulta: ' . $mysqli->error]);
    exit;
}

$vencidos    = [];
$proximos15  = [];
$proximos30  = [];

while ($row = $res->fetch_assoc()) {
    $dias = (int)$row['dias_restantes'];
    if ($dias < 0) {
        $vencidos[] = $row;
    } elseif ($dias <= 15) {
        $proximos15[] = $row;
    } else {
        $proximos30[] = $row;
    }
}

echo json_encode([
    'ok'   => true,
    'data' => [
        'vencidos'    => $vencidos,
        'proximos_15' => $proximos15,
        'proximos_30' => $proximos30,
    ],
]);

<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: application/json; charset=utf-8');

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode([
        'ok' => false,
        'msg' => 'No existe conexión a base de datos.'
    ]);
    exit;
}

$id_vehiculo = isset($_GET['id_vehiculo']) ? (int)$_GET['id_vehiculo'] : 0;

if ($id_vehiculo <= 0) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Vehículo no válido.'
    ]);
    exit;
}

try {
    $stmt = $mysqli->prepare("
        SELECT 
            h.id,
            h.fecha_inicio,
            h.fecha_termino,
            h.observacion,

            e.nombre AS empresa,
            d.nombre AS division,
            s.nombre AS subdivision,
            CONCAT(u.nombre, ' ', u.apellido) AS merchan,
            u.usuario AS usuario_merchan

        FROM vehiculo_asignacion_historial h

        LEFT JOIN empresa e
            ON e.id = h.id_empresa

        LEFT JOIN division_empresa d
            ON d.id = h.id_division

        LEFT JOIN subdivision s
            ON s.id = h.id_subdivision

        LEFT JOIN usuario u
            ON u.id = h.id_merchan

        WHERE h.id_vehiculo = ?
        ORDER BY h.fecha_inicio DESC, h.id DESC
    ");

    $stmt->bind_param("i", $id_vehiculo);
    $stmt->execute();

    $res = $stmt->get_result();

    $data = [];

    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode([
        'ok' => true,
        'data' => $data
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}
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

try {
    $sql = "
        SELECT 
            v.id,
            v.patente,
            v.modelo,
            v.tipo_combustible,
            v.direccion_origen,
            v.lat_origen,
            v.lng_origen,
            v.estado,
        
            h.id_empresa,
            h.id_division,
            h.id_subdivision,
            h.id_merchan,
            h.fecha_inicio,

            e.nombre AS empresa,
            d.nombre AS division,
            s.nombre AS subdivision,
            CONCAT(u.nombre, ' ', u.apellido) AS merchan,
            u.usuario AS usuario_merchan

        FROM vehiculo v

        LEFT JOIN vehiculo_asignacion_historial h
            ON h.id_vehiculo = v.id
            AND h.fecha_termino IS NULL

        LEFT JOIN empresa e
            ON e.id = h.id_empresa

        LEFT JOIN division_empresa d
            ON d.id = h.id_division

        LEFT JOIN subdivision s
            ON s.id = h.id_subdivision

        LEFT JOIN usuario u
            ON u.id = h.id_merchan

        WHERE v.deleted_at IS NULL
        ORDER BY v.id DESC
    ";

    $res = $mysqli->query($sql);

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
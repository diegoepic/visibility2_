<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/includes/rutas_programadas_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

rp_require_login_json();

try {
    $idEmpresa = rp_session_int('empresa_id');
    $idRutaSet = rp_get_int('id_ruta_set');

    if ($idRutaSet <= 0) {
        throw new InvalidArgumentException('No se recibió la ruta solicitada.');
    }

    $detalle = rp_detalle_ruta_programada($conn, $idEmpresa, $idRutaSet);

    rp_json([
        'ok' => true,
        'ruta' => $detalle['ruta'],
        'detalle' => $detalle['detalle'],
        'total' => count($detalle['detalle']),
    ]);
} catch (Throwable $e) {
    rp_json([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}

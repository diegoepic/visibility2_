<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/includes/rutas_programadas_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

rp_require_login_json();

try {
    $idEmpresa = rp_session_int('empresa_id');
    $idUsuario = rp_get_int('id_usuario', rp_get_int('id_ejecutor', 0));
    $idDivision = rp_get_int('id_division', 0);
    $estado = rp_get_str('estado', '');

    $rutas = rp_listar_rutas_programadas($conn, $idEmpresa, [
        'id_usuario' => $idUsuario,
        'id_division' => $idDivision,
        'estado' => $estado,
        'limit' => 100,
    ]);

    rp_json([
        'ok' => true,
        'data' => $rutas,
        'total' => count($rutas),
    ]);
} catch (Throwable $e) {
    rp_json([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}

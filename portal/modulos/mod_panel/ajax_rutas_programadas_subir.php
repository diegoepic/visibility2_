<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/includes/rutas_programadas_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

rp_require_login_json();

try {
    $idEmpresa = rp_session_int('empresa_id');
    $createdBy = rp_session_int('usuario_id');

    $idUsuario = rp_post_int('id_usuario', rp_post_int('id_ejecutor', 0));
    $idDivision = rp_post_int('id_division');
    $idSubdivision = rp_post_int('id_subdivision', 0);
    $nombre = rp_post_str('nombre_ruta');
    $fechaInicio = rp_post_str('fecha_inicio');
    $localesPorDia = rp_post_int('locales_por_dia', 0);

    if (!isset($_FILES['archivo_ruta']) || $_FILES['archivo_ruta']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Debes subir un archivo CSV válido.');
    }

    $archivo = $_FILES['archivo_ruta'];
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, ['csv', 'txt'], true)) {
        throw new RuntimeException('Por ahora la carga acepta archivos CSV o TXT delimitados por coma o punto y coma.');
    }

    $csv = rp_read_csv_assoc($archivo['tmp_name']);

    $resultado = rp_crear_ruta_programada($conn, [
        'id_empresa' => $idEmpresa,
        'id_division' => $idDivision,
        'id_subdivision' => $idSubdivision,
        'id_usuario' => $idUsuario,
        'tipo_scope' => 'individual',
        'origen' => 'archivo',
        'nombre' => $nombre,
        'fecha_inicio' => $fechaInicio,
        'locales_por_dia' => $localesPorDia,
        'created_by' => $createdBy,
    ], $csv['rows']);

    rp_json([
        'ok' => true,
        'message' => 'Ruta guardada correctamente.',
        'resultado' => $resultado,
    ]);
} catch (Throwable $e) {
    rp_json([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}

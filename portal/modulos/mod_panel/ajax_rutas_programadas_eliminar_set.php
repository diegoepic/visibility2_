<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/includes/rutas_programadas_set_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'message' => 'Sesión no válida.'
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $conn->set_charset('utf8mb4');

    $idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);
    $idUsuarioSesion = (int)($_SESSION['usuario_id'] ?? 0);

    if ($idEmpresa <= 0 || $idUsuarioSesion <= 0) {
        throw new RuntimeException('No fue posible identificar la empresa o el usuario de sesión.');
    }

    $idRutaSet = (int)($_POST['id_ruta_set'] ?? 0);
    $modoSet = strtolower(trim((string)($_POST['modo_set'] ?? '')));
    $idUsuarioFijo = (int)($_POST['id_usuario_fijo'] ?? 0);

    if ($modoSet === 'individual') {
        $tipoScope = 'individual';
    } elseif ($modoSet === 'masivo' || $modoSet === 'masiva') {
        $tipoScope = 'masiva';
    } else {
        $tipoScope = '';
    }

    $service = new RutasProgramadasSetService($conn, $idEmpresa, $idUsuarioSesion);
    $resultado = $service->eliminarSet($idRutaSet, $tipoScope, $idUsuarioFijo);

    echo json_encode([
        'ok' => true,
        'message' => 'Set eliminado correctamente.',
        'resultado' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
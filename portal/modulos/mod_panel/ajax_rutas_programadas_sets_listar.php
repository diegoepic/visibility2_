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
            'message' => 'Sesi¨®n no v¨¢lida.'
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $conn->set_charset('utf8mb4');

    $idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);
    $idUsuarioSesion = (int)($_SESSION['usuario_id'] ?? 0);

    if ($idEmpresa <= 0 || $idUsuarioSesion <= 0) {
        throw new RuntimeException('No fue posible identificar la empresa o el usuario de sesi¨®n.');
    }

    $idDivision = isset($_GET['id_division']) && $_GET['id_division'] !== ''
        ? (int)$_GET['id_division']
        : null;

    $modoSet = strtolower(trim((string)($_GET['modo_set'] ?? 'masivo')));
    $tipoScope = $modoSet === 'individual' ? 'individual' : 'masiva';

    $idUsuarioFijo = (int)($_GET['id_usuario_fijo'] ?? 0);

    $service = new RutasProgramadasSetService($conn, $idEmpresa, $idUsuarioSesion);
    $data = $service->listarSets($idDivision, $tipoScope, $idUsuarioFijo);

    echo json_encode([
        'ok' => true,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

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
    $idDivision = (int)($_GET['id_division'] ?? 0);

    if ($idEmpresa <= 0) {
        throw new RuntimeException('No fue posible identificar la empresa de sesión.');
    }

    if ($idDivision <= 0) {
        throw new RuntimeException('Debes seleccionar una división.');
    }

    $sql = "
        SELECT
            id,
            nombre,
            descripcion
        FROM ruta_set_categoria
        WHERE id_empresa = ?
          AND id_division = ?
          AND estado = 'activa'
        ORDER BY nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idEmpresa, $idDivision);
    $stmt->execute();

    $res = $stmt->get_result();

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();

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
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
    $idUsuarioSesion = (int)($_SESSION['usuario_id'] ?? 0);

    $idDivision = (int)($_POST['id_division'] ?? 0);
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));

    if ($idEmpresa <= 0 || $idUsuarioSesion <= 0) {
        throw new RuntimeException('No fue posible identificar la empresa o el usuario de sesión.');
    }

    if ($idDivision <= 0) {
        throw new RuntimeException('Debes seleccionar una división.');
    }

    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre de la categoría.');
    }

    $stmtDiv = $conn->prepare("
        SELECT id
        FROM division_empresa
        WHERE id = ?
          AND id_empresa = ?
          AND estado = 1
        LIMIT 1
    ");
    $stmtDiv->bind_param('ii', $idDivision, $idEmpresa);
    $stmtDiv->execute();
    $div = $stmtDiv->get_result()->fetch_assoc();
    $stmtDiv->close();

    if (!$div) {
        throw new RuntimeException('La división seleccionada no existe o no está activa.');
    }

    /*
        Si la categoría ya existe para esa división, la reactivamos.
        Esto ayuda si antes fue marcada inactiva.
    */
    $sql = "
        INSERT INTO ruta_set_categoria (
            nombre,
            id_empresa,
            id_division,
            estado,
            descripcion,
            created_by
        ) VALUES (?, ?, ?, 'activa', ?, ?)
        ON DUPLICATE KEY UPDATE
            estado = 'activa',
            descripcion = VALUES(descripcion),
            updated_at = NOW()
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('siisi', $nombre, $idEmpresa, $idDivision, $descripcion, $idUsuarioSesion);
    $stmt->execute();
    $stmt->close();

    $stmtFind = $conn->prepare("
        SELECT id, nombre, descripcion
        FROM ruta_set_categoria
        WHERE id_empresa = ?
          AND id_division = ?
          AND nombre = ?
        LIMIT 1
    ");
    $stmtFind->bind_param('iis', $idEmpresa, $idDivision, $nombre);
    $stmtFind->execute();
    $categoria = $stmtFind->get_result()->fetch_assoc();
    $stmtFind->close();

    if (!$categoria) {
        throw new RuntimeException('No fue posible obtener la categoría creada.');
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Categoría creada correctamente.',
        'data' => $categoria,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
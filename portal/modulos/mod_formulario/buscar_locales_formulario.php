<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['results' => [], 'error' => 'Sesión expirada.']);
    exit;
}

$formularioId = isset($_GET['formulario_id']) ? (int)$_GET['formulario_id'] : 0;
$termino = trim((string)($_GET['q'] ?? ''));

if ($formularioId <= 0 || mb_strlen($termino, 'UTF-8') < 1) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmtUsuario = $conn->prepare(
        "SELECT e.nombre
         FROM empresa e
         WHERE e.id = ?
         LIMIT 1"
    );
    $empresaSesion = (int)($_SESSION['empresa_id'] ?? 0);
    $stmtUsuario->bind_param('i', $empresaSesion);
    $stmtUsuario->execute();
    $empresaActual = $stmtUsuario->get_result()->fetch_assoc();
    $stmtUsuario->close();

    $esMentecreativa = strtolower(trim((string)($empresaActual['nombre'] ?? ''))) === 'mentecreativa';

    $stmtFormulario = $conn->prepare(
        "SELECT id_empresa, COALESCE(id_division, 0) AS id_division
         FROM formulario
         WHERE id = ?
         LIMIT 1"
    );
    $stmtFormulario->bind_param('i', $formularioId);
    $stmtFormulario->execute();
    $formulario = $stmtFormulario->get_result()->fetch_assoc();
    $stmtFormulario->close();

    if (!$formulario || (!$esMentecreativa && (int)$formulario['id_empresa'] !== $empresaSesion)) {
        http_response_code(403);
        echo json_encode(['results' => [], 'error' => 'Formulario no disponible.']);
        exit;
    }

    $empresaFormulario = (int)$formulario['id_empresa'];
    $divisionFormulario = (int)$formulario['id_division'];
    $like = '%' . $termino . '%';

    $sql =
        "SELECT l.id, l.codigo, l.nombre, l.direccion
         FROM local l
         WHERE l.id_empresa = ?
           AND l.deleted_at IS NULL
           AND (l.codigo LIKE ? OR l.nombre LIKE ? OR l.direccion LIKE ?)";

    if ($divisionFormulario > 0) {
        $sql .= " AND l.id_division = ?";
    }

    $sql .=
        " ORDER BY
            CASE WHEN l.codigo = ? THEN 0 WHEN l.codigo LIKE ? THEN 1 ELSE 2 END,
            l.nombre ASC
          LIMIT 30";

    $stmt = $conn->prepare($sql);
    $codigoEmpieza = $termino . '%';

    if ($divisionFormulario > 0) {
        $stmt->bind_param(
            'isssiss',
            $empresaFormulario,
            $like,
            $like,
            $like,
            $divisionFormulario,
            $termino,
            $codigoEmpieza
        );
    } else {
        $stmt->bind_param(
            'isssss',
            $empresaFormulario,
            $like,
            $like,
            $like,
            $termino,
            $codigoEmpieza
        );
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $locales = [];

    while ($local = $result->fetch_assoc()) {
        $codigo = trim((string)$local['codigo']);
        $nombre = trim((string)$local['nombre']);
        $direccion = trim((string)$local['direccion']);
        $detalle = array_filter([$nombre, $direccion], static fn($valor) => $valor !== '');

        $locales[] = [
            'id' => (int)$local['id'],
            'text' => $codigo . (empty($detalle) ? '' : ' — ' . implode(' — ', $detalle)),
            'codigo' => $codigo,
            'nombre' => $nombre,
            'direccion' => $direccion,
        ];
    }

    $stmt->close();

    echo json_encode(['results' => $locales], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'error' => 'No fue posible buscar locales.'], JSON_UNESCAPED_UNICODE);
}


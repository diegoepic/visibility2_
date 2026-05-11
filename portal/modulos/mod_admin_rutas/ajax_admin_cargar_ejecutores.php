<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

try {
    $idEmpresa     = (int)($_GET['id_empresa'] ?? 0);
    $idDivision    = (int)($_GET['id_division'] ?? 0);
    $idSubdivision = (int)($_GET['id_subdivision'] ?? 0);

    if ($idEmpresa <= 0) {
        $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    }

    $where = [];
    $types = '';
    $params = [];

    $where[] = "u.activo = 1";

    if ($idEmpresa > 0) {
        $where[] = "u.id_empresa = ?";
        $types .= "i";
        $params[] = $idEmpresa;
    }

    if ($idDivision > 0) {
        $where[] = "u.id_division = ?";
        $types .= "i";
        $params[] = $idDivision;
    }

    if ($idSubdivision > 0) {
        $where[] = "u.id_subdivision = ?";
        $types .= "i";
        $params[] = $idSubdivision;
    }

    if ($idSubdivision === -1) {
        $where[] = "(u.id_subdivision IS NULL OR u.id_subdivision = 0)";
    }

    /*
      Ajusta esto si quieres limitar solo a perfiles ejecutores/merchan.
      Por ahora trae usuarios activos según empresa/división/subdivisión.
    */
    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.usuario,
            u.id_division,
            u.id_subdivision
        FROM usuario u
        WHERE " . implode(" AND ", $where) . "
        ORDER BY u.nombre ASC, u.apellido ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $ejecutores = [];

    while ($row = $res->fetch_assoc()) {
        $ejecutores[] = [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre'],
            'apellido' => $row['apellido'],
            'usuario' => $row['usuario'],
            'id_division' => (int)$row['id_division'],
            'id_subdivision' => (int)$row['id_subdivision'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'total' => count($ejecutores),
        'ejecutores' => $ejecutores
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
        'ejecutores' => []
    ], JSON_UNESCAPED_UNICODE);
}
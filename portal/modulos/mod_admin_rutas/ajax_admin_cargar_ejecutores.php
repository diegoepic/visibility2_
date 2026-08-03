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
    $idDistrito    = (int)($_GET['id_distrito'] ?? 0);
    $tipoGestion   = (int)($_GET['tipo_gestion'] ?? 0);
    $idCampana     = (int)($_GET['id_campana'] ?? 0);
    $estado        = (int)($_GET['estado'] ?? 0);
    $idRegion      = (int)($_GET['id_region'] ?? 0);
    $idComuna      = (int)($_GET['id_comuna'] ?? 0);
    $desde         = trim((string)($_GET['desde'] ?? ''));
    $hasta         = trim((string)($_GET['hasta'] ?? ''));

    if ($idEmpresa <= 0) {
        $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
    }

    $where = [];
    $types = '';
    $params = [];

    $where[] = "u.activo = 1";
    $where[] = "l.id_division = f.id_division";

    if ($idEmpresa > 0) {
        $where[] = "f.id_empresa = ?";
        $types .= "i";
        $params[] = $idEmpresa;
    }

    if ($idDivision > 0) {
        // La división corresponde a la gestión/campaña, no a la ficha del usuario.
        $where[] = "f.id_division = ?";
        $types .= "i";
        $params[] = $idDivision;
    }

    if ($idSubdivision > 0) {
        $where[] = "f.id_subdivision = ?";
        $types .= "i";
        $params[] = $idSubdivision;
    }

    if ($idSubdivision === -1) {
        $where[] = "(f.id_subdivision IS NULL OR f.id_subdivision = 0)";
    }

    if (in_array($tipoGestion, [1, 3], true)) {
        $where[] = "f.tipo = ?";
        $types .= "i";
        $params[] = $tipoGestion;
    }

    if ($idCampana > 0) {
        $where[] = "f.id = ?";
        $types .= "i";
        $params[] = $idCampana;
    }

    if (in_array($estado, [1, 3], true)) {
        $where[] = "f.estado = ?";
        $types .= "i";
        $params[] = $estado;
    }

    if ($idDistrito > 0) {
        $where[] = "l.id_distrito = ?";
        $types .= "i";
        $params[] = $idDistrito;
    }

    if ($idRegion > 0) {
        $where[] = "c.id_region = ?";
        $types .= "i";
        $params[] = $idRegion;
    }

    if ($idComuna > 0) {
        $where[] = "l.id_comuna = ?";
        $types .= "i";
        $params[] = $idComuna;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $where[] = "fq.fechaPropuesta >= ?";
        $types .= "s";
        $params[] = $desde . ' 00:00:00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $where[] = "fq.fechaPropuesta <= ?";
        $types .= "s";
        $params[] = $hasta . ' 23:59:59';
    }

    /*
      Se listan usuarios activos que tengan al menos una gestión coincidente.
      No se usa u.id_division: un usuario puede ejecutar campañas de otra división.
    */
    $sql = "
        SELECT DISTINCT
            u.id,
            u.nombre,
            u.apellido,
            u.usuario,
            u.id_division,
            u.id_subdivision
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN usuario u ON u.id = fq.id_usuario
        INNER JOIN local l ON l.id = fq.id_local
        INNER JOIN comuna c ON c.id = l.id_comuna
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

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
header('Content-Type: application/json; charset=utf-8');

$id_empresa     = (int)($_GET['id_empresa'] ?? 0);
$id_campana     = (int)($_GET['id_campana'] ?? 0);
$id_division    = (int)($_GET['id_division'] ?? 0);
$id_subdivision = (int)($_GET['id_subdivision'] ?? 0);
$id_distrito    = (int)($_GET['id_distrito'] ?? 0);
$tipo           = (int)($_GET['tipo_gestion'] ?? 0);
$estado         = (int)($_GET['estado'] ?? 0);

if ($id_empresa === 0) {
    echo json_encode(['ok' => false, 'msg' => 'Falta id_empresa']);
    exit;
}

try {
    $joinLocal    = $id_distrito > 0 ? " JOIN local l ON l.id = fq.id_local " : "";
    $condDistrito = $id_distrito > 0 ? " AND l.id_distrito = ? " : "";

    $sql = "
        SELECT DISTINCT
            u.id,
            UPPER(u.nombre)   AS nombre,
            UPPER(u.apellido) AS apellido
        FROM formularioQuestion fq
        JOIN formulario f ON f.id = fq.id_formulario
        JOIN usuario u    ON u.id = fq.id_usuario
        $joinLocal
        WHERE f.id_empresa = ?
    ";

    $params = [$id_empresa];
    $types  = "i";

    if ($id_campana > 0) {
        $sql .= " AND f.id = ? ";
        $params[] = $id_campana;
        $types .= "i";
    }

    if ($tipo > 0) {
        $sql .= " AND f.tipo = ? ";
        $params[] = $tipo;
        $types .= "i";
    }

    if ($estado > 0) {
        $sql .= " AND f.estado = ? ";
        $params[] = $estado;
        $types .= "i";
    }

    if ($id_division > 0) {
        $sql .= " AND f.id_division = ? ";
        $params[] = $id_division;
        $types .= "i";
    }

    if ($id_subdivision > 0) {
        $sql .= " AND f.id_subdivision = ? ";
        $params[] = $id_subdivision;
        $types .= "i";
    }

    if ($id_distrito > 0) {
        $sql .= $condDistrito;
        $params[] = $id_distrito;
        $types .= "i";
    }

    $sql .= " ORDER BY UPPER(u.nombre), UPPER(u.apellido) ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $ejecutores = [];
    while ($r = $res->fetch_assoc()) {
        $ejecutores[] = $r;
    }

    $stmt->close();
    $conn->close();

    echo json_encode(['ok' => true, 'ejecutores' => $ejecutores]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}
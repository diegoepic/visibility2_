<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

try {
    if (!isset($conn) || !$conn) {
        throw new Exception('Conexión no disponible.');
    }

    $id_empresa     = (int)($_GET['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 0));
    $id_division    = (int)($_GET['id_division'] ?? 0);
    $id_subdivision = (int)($_GET['id_subdivision'] ?? 0);

    if ($id_empresa <= 0) {
        throw new Exception('Empresa no válida.');
    }

    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.apellido,
            u.usuario
        FROM usuario u
        WHERE u.activo = 1
          AND u.id_empresa = ?
    ";

    $params = [$id_empresa];
    $types  = "i";

    if ($id_division > 0) {
        // Permite reasignar tanto a usuarios de la división seleccionada
        // como a cualquier usuario de la división MC (id_division = 1).
        $sql .= " AND (u.id_division = ? OR u.id_division = 1)";
        $params[] = $id_division;
        $types .= "i";
    }

    if ($id_subdivision > 0) {
        // La subdivisión aplica a la división seleccionada; MC queda disponible completo.
        $sql .= " AND (u.id_division = 1 OR u.id_subdivision = ?)";
        $params[] = $id_subdivision;
        $types .= "i";
    }

    if ($id_subdivision === -1) {
        $sql .= " AND (u.id_division = 1 OR u.id_subdivision IS NULL OR u.id_subdivision = 0)";
    }

    /*
      Si necesitas listar solo merchandisers/ejecutores,
      puedes activar una condición por perfil, ejemplo:

      AND u.id_perfil = 4

      Pero primero confirma qué id_perfil corresponde en tu BD.
    */

    $sql .= "
        ORDER BY 
            u.nombre ASC,
            u.apellido ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $result = $stmt->get_result();

    $usuarios = [];

    while ($row = $result->fetch_assoc()) {
        $usuarios[] = [
            'id'       => (int)$row['id'],
            'nombre'   => $row['nombre'] ?? '',
            'apellido' => $row['apellido'] ?? '',
            'usuario'  => $row['usuario'] ?? ''
        ];
    }

    echo json_encode([
        'ok' => true,
        'usuarios' => $usuarios
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
        'usuarios' => []
    ], JSON_UNESCAPED_UNICODE);
}

<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

try {
    if (empty($_SESSION['id_usuario'])) {
        throw new Exception('Sesión no válida.');
    }

    // Ajusta este control según tus perfiles reales.
    $idPerfil = (int)($_SESSION['id_perfil'] ?? 0);

    if (!in_array($idPerfil, [1], true)) {
        throw new Exception('No tienes permisos para usar este módulo.');
    }

    $sql = "
        SELECT 
            id,
            nombre,
            apellido,
            usuario
        FROM usuario
        WHERE activo = 1
        ORDER BY nombre ASC, apellido ASC
    ";

    $res = $conn->query($sql);

    if (!$res) {
        throw new Exception('Error consultando usuarios: ' . $conn->error);
    }

    $usuarios = [];

    while ($row = $res->fetch_assoc()) {
        $usuarios[] = [
            'id'       => (int)$row['id'],
            'nombre'   => $row['nombre'],
            'apellido' => $row['apellido'],
            'usuario'  => $row['usuario'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'usuarios' => $usuarios
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
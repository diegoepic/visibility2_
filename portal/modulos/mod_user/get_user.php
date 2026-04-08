<?php
// mod_user/get_user.php

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'ID de usuario no proporcionado o inválido'
    ]);
    exit;
}

$usuario_id = (int) $_GET['id'];

$sql = "SELECT 
            id,
            rut,
            nombre,
            apellido,
            telefono,
            email,
            usuario,
            id_perfil,
            id_empresa,
            id_division,
            id_subdivision,
            clasificacion_usuario,
            fotoPerfil
        FROM usuario
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al preparar la consulta'
    ]);
    exit;
}

$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $usuario = $result->fetch_assoc();

    $data = [
        'id' => $usuario['id'],
        'rut' => $usuario['rut'],
        'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'],
        'telefono' => $usuario['telefono'],
        'email' => $usuario['email'],
        'usuario' => $usuario['usuario'],
        'id_perfil' => $usuario['id_perfil'],
        'id_empresa' => $usuario['id_empresa'],
        'id_division' => $usuario['id_division'],
        'id_subdivision' => $usuario['id_subdivision'],
        'clasificacion_usuario' => $usuario['clasificacion_usuario'],
        'fotoPerfil' => $usuario['fotoPerfil']
    ];

    echo json_encode([
        'status' => 'success',
        'data' => $data
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Usuario no encontrado'
    ]);
}

$stmt->close();
$conn->close();
?>
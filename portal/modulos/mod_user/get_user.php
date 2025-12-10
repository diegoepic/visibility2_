<?php
// mod_user/get_user.php

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $usuario_id = intval($_GET['id']);

    // Obtener los datos del usuario
    $stmt = $conn->prepare("SELECT * FROM usuario WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $usuario = $result->fetch_assoc();

        // Preparar los datos para el JSON
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
            'fotoPerfil' => $usuario['fotoPerfil'] // Ajusta esto según cómo almacenes la ruta de la foto
        ];

        // Devolver los datos en formato JSON
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
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'ID de usuario no proporcionado o inválido'
    ]);
}

$conn->close();
?>


<?php
// mod_user/eliminar_usuario.php

session_start();

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Función para validar el token CSRF
function validar_token_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar CSRF Token
    if (!validar_token_csrf($_POST['csrf_token'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Token CSRF inválido.'
        ]);
        exit();
    }

    // Obtener y sanitizar el ID del usuario
    if (isset($_POST['id']) && is_numeric($_POST['id'])) {
        $usuario_id = intval($_POST['id']);

        // Verificar si el usuario existe y está activo
        $stmt = $conn->prepare("SELECT activo FROM usuario WHERE id = ?");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $usuario = $result->fetch_assoc();
            if ($usuario['activo'] == 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'El usuario ya está desactivado.'
                ]);
                exit();
            }

            // Actualizar el campo 'activo' a 0
            $stmt_update = $conn->prepare("UPDATE usuario SET activo = 0 WHERE id = ?");
            $stmt_update->bind_param("i", $usuario_id);
            if ($stmt_update->execute()) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Usuario desactivado exitosamente.'
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Error al desactivar el usuario.'
                ]);
            }
            $stmt_update->close();
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Usuario no encontrado.'
            ]);
        }
        $stmt->close();
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'ID de usuario inválido.'
        ]);
    }

    $conn->close();
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Método de solicitud inválido.'
    ]);
}
?>

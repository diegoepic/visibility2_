<?php
// mod_user/procesar_modificacion.php

// 0) Arrancar sesión y mostrar errores (quitar en producción)
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1) Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_user/generate_token.php';

// 2) Verificar que el usuario esté autenticado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit();
}

// 3) Procesar solo POST con los campos mínimos
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset(
        $_POST['usuario_id'],
        $_POST['rut'],
        $_POST['nombre'],
        $_POST['apellido'],
        $_POST['telefono'],
        $_POST['email'],
        $_POST['usuario'],
        $_POST['id_perfil'],
        $_POST['id_empresa']
    )
) {
    // 4) CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_formulario'] = "Token CSRF inválido.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // 5) Recoger y sanear entradas
    $usuario_id     = intval($_POST['usuario_id']);
    $rut_input      = trim($_POST['rut']);
    $nombre         = trim($_POST['nombre']);
    $apellido       = trim($_POST['apellido']);
    $telefono       = trim($_POST['telefono']);
    $email          = trim($_POST['email']);
    $usuario_nombre = trim($_POST['usuario']);
    $id_perfil      = intval($_POST['id_perfil']);
    $id_empresa     = intval($_POST['id_empresa']);
    $id_division    = isset($_POST['id_division']) && $_POST['id_division'] !== ''
                      ? intval($_POST['id_division'])
                      : null;
    if ($id_perfil === 1) { // forzar división 1 si es editor
        $id_division = 1;
    }

    // 6) Validaciones
    $errors = [];

    // 6a) RUT
    if (!validarRut($rut_input)) {
        $errors[] = "RUT inválido.";
    } else {
        $rut_estandarizado = strtoupper(preg_replace('/[^0-9kK]/', '', $rut_input));
        $stmt_rut = $conn->prepare("SELECT COUNT(*) FROM usuario WHERE rut = ? AND id != ?");
        $stmt_rut->bind_param("si", $rut_estandarizado, $usuario_id);
        $stmt_rut->execute();
        $stmt_rut->bind_result($count_rut);
        $stmt_rut->fetch();
        $stmt_rut->close();
        if ($count_rut > 0) {
            $errors[] = "El RUT ya está registrado por otro usuario.";
        }
    }

    // 6b) Email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Correo electrónico inválido.";
    } else {
        $stmt_email = $conn->prepare("SELECT COUNT(*) FROM usuario WHERE email = ? AND id != ?");
        if ($stmt_email === false) {
            $errors[] = "Error al preparar verificación de email.";
        } else {
            $stmt_email->bind_param("si", $email, $usuario_id);
            $stmt_email->execute();
            $stmt_email->bind_result($count_email);
            $stmt_email->fetch();
            $stmt_email->close();
            if ($count_email > 0) {
                $errors[] = "El correo ya está en uso.";
            }
        }
    }

    // 6c) Nombre de usuario
    $stmt_user = $conn->prepare("SELECT COUNT(*) FROM usuario WHERE usuario = ? AND id != ?");
    if ($stmt_user === false) {
        $errors[] = "Error al preparar verificación de usuario.";
    } else {
        $stmt_user->bind_param("si", $usuario_nombre, $usuario_id);
        $stmt_user->execute();
        $stmt_user->bind_result($count_user);
        $stmt_user->fetch();
        $stmt_user->close();
        if ($count_user > 0) {
            $errors[] = "El nombre de usuario ya está en uso.";
        }
    }

    // 6d) Campos obligatorios
    if (empty($nombre) || empty($apellido) || empty($telefono) || empty($usuario_nombre)) {
        $errors[] = "Completa todos los campos obligatorios.";
    }

    // 6e) Foto de perfil (opcional)
    $fotoPerfil = null;
    if (isset($_FILES['fotoPerfil']) && $_FILES['fotoPerfil']['error'] === UPLOAD_ERR_OK) {
        $tmp  = $_FILES['fotoPerfil']['tmp_name'];
        $name = $_FILES['fotoPerfil']['name'];
        $size = $_FILES['fotoPerfil']['size'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) {
            $errors[] = "Sólo JPG, JPEG y PNG para la foto.";
        } elseif ($size > 2*1024*1024) {
            $errors[] = "Foto supera 2MB.";
        } else {
            $newName = md5(time().$name).'.'.$ext;
            $dir     = $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/uploads/fotos_perfil/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($tmp, $dir.$newName)) {
                $fotoPerfil = '/visibility2/portal/uploads/fotos_perfil/'.$newName;
            } else {
                $errors[] = "Error al subir la foto.";
            }
        }
    }

    // 6f) Cambio de contraseña (sólo si el bloque se abrió)
    $clave = null;
    if (!empty($_POST['new_password']) || !empty($_POST['confirm_password'])) {
        if ($_POST['new_password'] !== $_POST['confirm_password']) {
            $errors[] = "Las contraseñas no coinciden.";
        } else {
            $clave = $_POST['new_password'];
        }
    }

    // 7) Si hay errores, volvemos con mensaje
    if (!empty($errors)) {
        $_SESSION['error_formulario'] = implode("<br>", $errors);
        header("Location: ../mod_create_user.php");
        exit();
    }

    // 8) Armar UPDATE dinámico
    $campos = "rut = ?, nombre = ?, apellido = ?, telefono = ?, email = ?, usuario = ?, id_perfil = ?, id_empresa = ?";
    $params = [
        $rut_estandarizado,
        $nombre,
        $apellido,
        $telefono,
        $email,
        $usuario_nombre,
        $id_perfil,
        $id_empresa
    ];
    $tipos  = "ssssssii";

    if ($id_division !== null && $id_division > 0) {
        $campos   .= ", id_division = ?";
        $params[]  = $id_division;
        $tipos    .= "i";
    } else {
        $campos   .= ", id_division = NULL";
    }

    if ($clave !== null) {
        $hash     = password_hash($clave, PASSWORD_DEFAULT);
        $campos   .= ", clave = ?";
        $params[]  = $hash;
        $tipos    .= "s";
    }

    if ($fotoPerfil !== null) {
        $campos   .= ", fotoPerfil = ?";
        $params[]  = $fotoPerfil;
        $tipos    .= "s";
    }

    // Añadir WHERE
    $campos   .= " WHERE id = ?";
    $params[]  = $usuario_id;
    $tipos    .= "i";

    // 9) Ejecutar UPDATE
    $sql  = "UPDATE usuario SET $campos";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("Error preparar UPDATE: " . $conn->error);
        $_SESSION['error_formulario'] = "Error interno al preparar actualización.";
        header("Location: ../mod_create_user.php");
        exit();
    }
    $stmt->bind_param($tipos, ...$params);

    if ($stmt->execute()) {
        $_SESSION['success_formulario'] = "Usuario actualizado exitosamente.";
        // Renovar CSRF
        $_SESSION['csrf_token'] = generate_csrf_token(32);
        header("Location: ../mod_create_user.php");
        exit();
    } else {
        error_log("Error ejecutar UPDATE: " . $stmt->error);
        $_SESSION['error_formulario'] = "Error al actualizar el usuario. Inténtalo de nuevo.";
        header("Location: ../mod_create_user.php");
        exit();
    }

} else {
    // Llega sin los campos mínimos
    $_SESSION['error_formulario'] = "Solicitud inválida.";
    header("Location: ../mod_create_user.php");
    exit();
}
?>

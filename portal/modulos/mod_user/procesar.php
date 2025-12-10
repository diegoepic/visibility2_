<?php
// Activar la visualización de errores para depuración (desactivar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir el archivo de base de datos y funciones
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Iniciar la sesión para manejar mensajes de éxito y error
session_start();



// 1) Asegurar que llegue por POST y tenga token
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['csrf_token'])
    || $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
    $_SESSION['error_formulario'] = "Solicitud inválida (CSRF).";
    header("Location: ../mod_create_user.php");
    exit();
}
// ––– FIN BLOQUE CSRF –––

// Verificar que la solicitud se realiza mediante POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Sanitizar y obtener las entradas del formulario
    $rut_input = trim($_POST['rut']);
    $nombre = trim($_POST['nombre']);
    $apellido = trim($_POST['apellido']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $usuario = trim($_POST['usuario']);
    $id_empresa = isset($_POST['id_empresa']) ? intval($_POST['id_empresa']) : 0;
    $id_perfil = isset($_POST['id_perfil']) ? intval($_POST['id_perfil']) : 0;
    $clave = $_POST['password'];

    // Validar el RUT utilizando la función validarRut
    if (!validarRut($rut_input)) {
        // RUT inválido
        $_SESSION['error_formulario'] = "El RUT ingresado es inválido.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // Estandarizar el RUT para almacenamiento (sin puntos ni guión y en mayúscula)
    $rut_estandarizado = preg_replace('/[^0-9kK]/', '', $rut_input);
    $rut_estandarizado = strtoupper($rut_estandarizado);

    // Hashear la contraseña utilizando un algoritmo seguro
    $hashed_password = password_hash($clave, PASSWORD_DEFAULT);

    // Manejar la carga de la foto de perfil (si se ha subido)
    $fotoPerfil = null;
    if (isset($_FILES['fotoPerfil']) && $_FILES['fotoPerfil']['error'] == UPLOAD_ERR_OK) {
        // Directorio donde se almacenarán las imágenes de perfil
        $upload_dir = '/home/visibility/public_html/visibility2/portal/images/uploads/perfil/';
        $web_upload_dir = 'https://www.visibility.cl/visibility2/portal/images/uploads/perfil/';
        $filename = basename($_FILES['fotoPerfil']['name']);

        // Renombrar el archivo para evitar colisiones
        $filename = uniqid() . '_' . $filename;

        $target_file = $upload_dir . $filename;
        $web_target_file = $web_upload_dir . $filename;

        // Validar el tipo de archivo
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $detected_type = mime_content_type($_FILES['fotoPerfil']['tmp_name']);

        if (in_array($detected_type, $allowed_types)) {
            if (move_uploaded_file($_FILES['fotoPerfil']['tmp_name'], $target_file)) {
                $fotoPerfil = $web_target_file;
            } else {
                $_SESSION['error_formulario'] = "Error al subir la imagen de perfil.";
                header("Location: ../mod_create_user.php");
                exit();
            }
        } else {
            $_SESSION['error_formulario'] = "Tipo de archivo no permitido para la foto de perfil.";
            header("Location: ../mod_create_user.php");
            exit();
        }
    }

    // Verificar si el RUT ya existe en la base de datos
    if (existeRut($rut_estandarizado)) {
        $_SESSION['error_formulario'] = "El RUT ingresado ya existe en el sistema.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // Verificar si el email o nombre de usuario ya existen
    if (existeUsuario($email, $usuario)) {
        $_SESSION['error_formulario'] = "El email o nombre de usuario ya están registrados.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // Obtener el nombre del perfil seleccionado
    $perfil_seleccionado = obtenerNombrePerfil($id_perfil);

    // Contar el número de divisiones que tiene la empresa
    $total_divisiones = contarDivisionesPorEmpresa($id_empresa);

    // Determinar si la división es requerida y asignar el id_division apropiadamente
    if (strtolower($perfil_seleccionado) === 'visor' || strtolower($perfil_seleccionado) === 'ejecutor') {
        if ($total_divisiones > 0) {
            // La empresa tiene divisiones; la división es obligatoria
            if (isset($_POST['id_division']) && !empty($_POST['id_division'])) {
                $id_division = intval($_POST['id_division']);
            } else {
                // Redirigir al formulario con un mensaje de error
                $_SESSION['error_formulario'] = "La división es obligatoria para el perfil seleccionado.";
                header("Location: ../mod_create_user.php");
                exit();
            }
        } else {
            // La empresa no tiene divisiones; asignar 0
            $id_division = 0;
        }
    } else {
        // Para otros perfiles, la división es NULL
        $id_division = null;
    }

    // Insertar el usuario en la base de datos
    if (insertarUsuario($rut_estandarizado, $nombre, $apellido, $telefono, $email, $usuario, $fotoPerfil, $hashed_password, $id_perfil, $id_empresa, $id_division)) {
        $_SESSION['success_formulario'] = "Usuario creado exitosamente.";
        header("Location: ../mod_create_user.php");
        exit();        
    } else {
        $_SESSION['error_formulario'] = "Error al crear el usuario.";
        header("Location: ../mod_create_user.php");
        exit();         
    }
} else {
    // Si no se envió el formulario correctamente
    $_SESSION['error_formulario'] = "Método de solicitud inválido.";
    header("Location: ../mod_create_user.php");
    exit();
}
?>



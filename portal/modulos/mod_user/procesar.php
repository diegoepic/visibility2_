<?php
// Activar el reporte de errores para depuración (sin enviar salida al cliente)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Incluir el archivo de base de datos y funciones
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Iniciar la sesión para manejar mensajes de éxito y error
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * Determina si un perfil requiere división.
 */
function perfilRequiereDivision($perfilNombre): bool
{
    $perfil = mb_strtolower(trim((string)$perfilNombre), 'UTF-8');
    return in_array($perfil, ['visor', 'ejecutor', 'editor', 'coordinador'], true);
}



/**
 * Inserta el usuario.
 */
function insertarUsuarioNuevo(
    mysqli $conn,
    string $rut,
    string $nombre,
    string $apellido,
    string $telefono,
    string $email,
    string $usuario,
    ?string $fotoPerfil,
    string $hashed_password,
    int $id_perfil,
    int $id_empresa,
    ?int $id_division,
    ?int $id_subdivision,
    string $clasificacion_usuario
): bool {
    $sql = "INSERT INTO usuario (
                rut,
                nombre,
                apellido,
                telefono,
                email,
                usuario,
                fotoPerfil,
                clave,
                fechaCreacion,
                activo,
                id_empresa,
                id_division,
                id_subdivision,
                login_count,
                last_login,
                id_perfil,
                tipo_usuario,
                clasificacion_usuario
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, ?, ?, ?, 0, NULL, ?, NULL, ?
            )";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ssssssssiiiss",
        $rut,
        $nombre,
        $apellido,
        $telefono,
        $email,
        $usuario,
        $fotoPerfil,
        $hashed_password,
        $id_empresa,
        $id_division,
        $id_subdivision,
        $id_perfil,
        $clasificacion_usuario
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

// 1) Asegurar que llegue por POST y tenga token
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
    $_SESSION['error_formulario'] = "Solicitud inválida (CSRF).";
    header("Location: ../mod_create_user.php");
    exit();
}

// Verificar que la solicitud se realiza mediante POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitizar y obtener las entradas del formulario
    $rut_input = trim($_POST['rut'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $id_empresa = isset($_POST['id_empresa']) ? (int) $_POST['id_empresa'] : 0;
    $id_perfil = isset($_POST['id_perfil']) ? (int) $_POST['id_perfil'] : 0;
    $clave = $_POST['password'] ?? '';

    $id_division = !empty($_POST['id_division']) ? (int) $_POST['id_division'] : null;
    $id_subdivision = !empty($_POST['id_subdivision']) ? (int) $_POST['id_subdivision'] : null;
    $clasificacion_usuario = trim($_POST['clasificacion_usuario'] ?? '');

    // Validaciones básicas
    if (
        $rut_input === '' ||
        $nombre === '' ||
        $apellido === '' ||
        $telefono === '' ||
        $email === '' ||
        $usuario === '' ||
        $id_empresa <= 0 ||
        $id_perfil <= 0 ||
        $clave === ''
    ) {
        $_SESSION['error_formulario'] = "Debes completar todos los campos obligatorios.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    if (!in_array($clasificacion_usuario, ['interno', 'externo'], true)) {
        $_SESSION['error_formulario'] = "Debes seleccionar si el usuario es interno o externo.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // Validar el RUT utilizando la función validarRut
    if (!validarRut($rut_input)) {
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
    if (isset($_FILES['fotoPerfil']) && $_FILES['fotoPerfil']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '/home/visibility/public_html/visibility2/portal/images/uploads/perfil/';
        $web_upload_dir = 'https://www.visibility.cl/visibility2/portal/images/uploads/perfil/';

        $filename = basename($_FILES['fotoPerfil']['name']);
        $filename = uniqid() . '_' . $filename;

        $target_file = $upload_dir . $filename;
        $web_target_file = $web_upload_dir . $filename;

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $detected_type = mime_content_type($_FILES['fotoPerfil']['tmp_name']);

        if (in_array($detected_type, $allowed_types, true)) {
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

    // Determinar si la división es requerida
    if (perfilRequiereDivision($perfil_seleccionado)) {
        if ($total_divisiones > 0) {
            if ($id_division === null || $id_division <= 0) {
                $_SESSION['error_formulario'] = "La división es obligatoria para el perfil seleccionado.";
                header("Location: ../mod_create_user.php");
                exit();
            }
        } else {
            $id_division = null;
        }
    } else {
        $id_division = null;
        $id_subdivision = null;
    }

    // Si viene subdivisión, debe venir división también
    if ($id_subdivision !== null && ($id_division === null || $id_division <= 0)) {
        $_SESSION['error_formulario'] = "No puedes asignar una subdivisión sin seleccionar una división.";
        header("Location: ../mod_create_user.php");
        exit();
    }

    // Validar que la subdivisión pertenezca a la división
    if ($id_subdivision !== null && $id_division !== null) {
        if (!subdivisionPerteneceADivision($id_subdivision, $id_division)) {
            $_SESSION['error_formulario'] = "La subdivisión seleccionada no pertenece a la división indicada.";
            header("Location: ../mod_create_user.php");
            exit();
        }
    }

    // Insertar el usuario en la base de datos
    $insertado = insertarUsuarioNuevo(
        $conn,
        $rut_estandarizado,
        $nombre,
        $apellido,
        $telefono,
        $email,
        $usuario,
        $fotoPerfil,
        $hashed_password,
        $id_perfil,
        $id_empresa,
        $id_division,
        $id_subdivision,
        $clasificacion_usuario
    );

    if ($insertado) {
        $_SESSION['success_formulario'] = "Usuario creado exitosamente.";
        header("Location: ../mod_create_user.php");
        exit();
    } else {
        $_SESSION['error_formulario'] = "Error al crear el usuario.";
        header("Location: ../mod_create_user.php");
        exit();
    }
} else {
    $_SESSION['error_formulario'] = "Método de solicitud inválido.";
    header("Location: ../mod_create_user.php");
    exit();
}
?>
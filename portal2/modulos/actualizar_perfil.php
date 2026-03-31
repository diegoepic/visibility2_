<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json'); // Importante para AJAX

// Incluir archivo de conexi¨®n
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Verificar autenticaci¨®n
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(["success" => false, "error" => "Usuario no autenticado."]);
    exit();
}

$id_usuario = $_SESSION['usuario_id'];
$nombre = trim($_POST['nombre']);
$apellido = trim($_POST['apellido']);
$telefono = trim($_POST['telefono']);
$email = trim($_POST['email']);

// Obtener la foto actual del usuario si no se sube una nueva
$query = "SELECT fotoPerfil FROM usuario WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$stmt->bind_result($fotoActual);
$stmt->fetch();
$stmt->close();

$fotoPerfil = $fotoActual; // Mantener la imagen actual por defecto

// Manejo de la foto de perfil
if (isset($_FILES['fotoPerfil']) && $_FILES['fotoPerfil']['error'] === UPLOAD_ERR_OK) {
    $directorio = realpath($_SERVER['DOCUMENT_ROOT']) . "/visibility2/portal/images/uploads/perfil/";

    // Crear el directorio si no existe
    if (!is_dir($directorio) && !mkdir($directorio, 0755, true) && !is_dir($directorio)) {
        echo json_encode(["success" => false, "error" => "No se pudo crear el directorio de im¨¢genes."]);
        exit();
    }

    $extension = strtolower(pathinfo($_FILES['fotoPerfil']['name'], PATHINFO_EXTENSION));
    $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($extension, $extensionesPermitidas)) {
        echo json_encode(["success" => false, "error" => "Formato de imagen no permitido."]);
        exit();
    }

    $nombreArchivo = "perfil_" . $id_usuario . "." . $extension;
    $rutaDestino = $directorio . "/" . $nombreArchivo;

    if (move_uploaded_file($_FILES['fotoPerfil']['tmp_name'], $rutaDestino)) {
        // Guardar la URL accesible p¨²blicamente
        $fotoPerfil = "/visibility2/portal/images/uploads/perfil/" . $nombreArchivo;
        // Reemplazar las barras invertidas por barras normales
        $fotoPerfil = str_replace("\\", "/", $fotoPerfil);
    } else {
        echo json_encode(["success" => false, "error" => "Error al subir la imagen."]);
        exit();
    }
}

// Actualizar los datos en la base de datos
$sql = "UPDATE usuario SET nombre = ?, apellido = ?, telefono = ?, email = ?, fotoPerfil = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssssi", $nombre, $apellido, $telefono, $email, $fotoPerfil, $id_usuario);

$response = [];

if ($stmt->execute()) {
    $_SESSION['usuario_nombre'] = $nombre;
    $_SESSION['usuario_apellido'] = $apellido;
    $_SESSION['telefono'] = $telefono;
    $_SESSION['email'] = $email;
    $_SESSION['usuario_fotoPerfil'] = $fotoPerfil;

    $response['success'] = true;
    $response['nuevaImagen'] = $fotoPerfil;
} else {
    $response['success'] = false;
    $response['error'] = "Error al actualizar el perfil.";
}

$stmt->close();
$conn->close();

echo json_encode($response);
exit();
?>
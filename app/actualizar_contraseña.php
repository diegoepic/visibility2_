<?php
session_start();
$servername = "localhost";
$username = "visibility";
$password = "xyPz8e/rgaC2";
$dbname = "visibility_visibility2";
$conn = new mysqli($servername, $username, $password, $dbname);


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $nueva_clave = $_POST['clave'];

    // Verificar el token nuevamente
    $stmt = $conn->prepare("SELECT usuario_id FROM password_resets WHERE token = ? AND expira > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($usuario_id);
    $stmt->fetch();
    $stmt->close();

    if ($usuario_id) {
        // Hashear la nueva contraseña
        $clave_hash = password_hash($nueva_clave, PASSWORD_DEFAULT);

        // Actualizar la contraseña en la base de datos
        $stmt = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?");
        $stmt->bind_param("si", $clave_hash, $usuario_id);
        $stmt->execute();
        $stmt->close();

        // Eliminar el token usado
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE usuario_id = ?");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $stmt->close();

        echo "Tu contraseña ha sido actualizada exitosamente.";
        // Redireccionar al inicio de sesión
        header("Location: index.php");
        exit();
    } else {
        echo "El enlace es inválido o ha expirado.";
    }
}
?>
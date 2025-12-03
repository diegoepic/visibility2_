<?php
session_start();
$servername = "localhost";
$username = "visibility";
$password = "xyPz8e/rgaC2";
$dbname = "visibility_visibility2";
$conn = new mysqli($servername, $username, $password, $dbname);


if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Verificar si el token es válido y no ha expirado
    $stmt = $conn->prepare("SELECT usuario_id FROM password_resets WHERE token = ? AND expira > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($usuario_id);
    $stmt->fetch();
    $stmt->close();

    if ($usuario_id) {
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Cambiar Contraseña</title>
        </head>
        <body>
            <h2>Cambiar Contraseña</h2>
            <form action="actualizar_contraseña.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <label for="clave">Nueva Contraseña:</label>
                <input type="password" name="clave" required>
                <button type="submit">Actualizar Contraseña</button>
            </form>
        </body>
        </html>
        <?php
    } else {
        echo "El enlace es inválido o ha expirado.";
    }
} else {
    echo "No se proporcionó un token válido.";
}
?>
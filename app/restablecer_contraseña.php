<!-- restablecer_contraseña.php -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Restablecer Contraseña</title>
</head>
<body>
    <h2>Restablecer Contraseña</h2>
    <form action="procesar_restablecimiento.php" method="POST">
        <label for="email">Correo Electrónico:</label>
        <input type="email" name="email" required>
        <button type="submit">Enviar Enlace de Restablecimiento</button>
    </form>
</body>
</html>
<?php 
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';


?>

<!DOCTYPE html>
<html>
<head>
    <title>Crear Encuesta</title>
</head>
<body>
    <h1>Crear Nueva Encuesta</h1>
    <form action="mod_encuesta/procesar_encuesta.php" method="POST">
        <label>Nombre de la Encuesta:</label><br>
        <input type="text" name="nombre_encuesta" required><br><br>

        <label>Fecha de Término:</label><br>
        <input type="date" name="fecha_termino" required><br><br>

        <label>Local:</label><br>
        <select name="codigo_local" required>
            <?php
            $locales = consulta("SELECT id, nombre FROM local");
            foreach ($locales as $local) {
                echo "<option value='{$local['id']}'>{$local['nombre']}</option>";
            }
            ?>
        </select><br><br>

        <label>Usuario:</label><br>
        <select name="id_usuario" required>
            <?php
    $usuarios = consulta("SELECT id, nombre, apellido FROM usuario");
    
    if ($usuarios && count($usuarios) > 0) {
        foreach ($usuarios as $usuario) {
            // Combinar nombre y apellido, y escapar caracteres especiales
            $nombreCompleto = htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido'], ENT_QUOTES, 'UTF-8');
            $idUsuario = htmlspecialchars($usuario['id'], ENT_QUOTES, 'UTF-8');
            
            // Mostrar la opción en el menú desplegable
            echo "<option value='{$idUsuario}'>{$nombreCompleto}</option>";
        }
    } else {
        echo "<option value=''>No hay usuarios disponibles</option>";
    }
?>
        </select><br><br>

        <input type="submit" value="Crear Encuesta">
    </form>
</body>
</html>
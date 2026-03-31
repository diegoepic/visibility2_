<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
$id_encuesta = $_GET['id_encuesta'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Agregar Preguntas</title>
    <script>
        function mostrarOpciones() {
            var tipo = document.getElementById('tipo_pregunta').value;
            if (tipo == 'seleccion_unica' || tipo == 'seleccion_multiple') {
                document.getElementById('opciones').style.display = 'block';
            } else {
                document.getElementById('opciones').style.display = 'none';
            }
        }

        function agregarOpcion() {
            var contenedor = document.getElementById('lista_opciones');
            var input = document.createElement('input');
            input.type = 'text';
            input.name = 'opciones[]';
            input.required = true;
            contenedor.appendChild(input);
            contenedor.appendChild(document.createElement('br'));
        }
    </script>
</head>
<body>
    <h1>Agregar Preguntas a la Encuesta</h1>
    <form action="procesar_preguntas.php" method="POST">
        <input type="hidden" name="id_encuesta" value="<?php echo $id_encuesta; ?>">

        <label>Texto de la Pregunta:</label><br>
        <input type="text" name="texto_pregunta" required><br><br>

        <label>Tipo de Pregunta:</label><br>
        <select name="tipo_pregunta" id="tipo_pregunta" onchange="mostrarOpciones()" required>
            <option value="">Seleccione un tipo</option>
            <option value="texto">Texto</option>
            <option value="numerico">Numérico</option>
            <option value="seleccion_unica">Selección Única</option>
            <option value="seleccion_multiple">Selección Múltiple</option>
        </select><br><br>

        <div id="opciones" style="display:none;">
            <label>Opciones:</label><br>
            <div id="lista_opciones">
                <input type="text" name="opciones[]" required><br>
            </div>
            <button type="button" onclick="agregarOpcion()">Agregar Otra Opción</button><br><br>
        </div>

        <input type="submit" value="Agregar Pregunta">
    </form>
    <br>
    <a href="agregar_preguntas.php?id_encuesta=<?php echo $id_encuesta; ?>">Agregar Otra Pregunta</a><br>
    <a href="crear_encuesta.php">Crear Nueva Encuesta</a>
</body>
</html>
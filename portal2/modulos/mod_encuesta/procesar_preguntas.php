<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$id_encuesta = $_POST['id_encuesta'];
$texto_pregunta = $_POST['texto_pregunta'];
$tipo_pregunta = $_POST['tipo_pregunta'];

// Insertar pregunta
$insertar_pregunta = "INSERT INTO pregunta (id_encuesta, texto_pregunta, tipo_pregunta)
VALUES ('$id_encuesta', '$texto_pregunta', '$tipo_pregunta')";

$id_pregunta = ejecutar($insertar_pregunta);

// Si la pregunta es de selección, insertar opciones
if ($tipo_pregunta == 'seleccion_unica' || $tipo_pregunta == 'seleccion_multiple') {
    $opciones = $_POST['opciones'];
    foreach ($opciones as $texto_opcion) {
        $insertar_opcion = "INSERT INTO opcion_pregunta (id_pregunta, texto_opcion)
        VALUES ('$id_pregunta', '$texto_opcion')";
        ejecutar($insertar_opcion);
    }
}

// Redirigir para agregar más preguntas o finalizar
header("Location: agregar_preguntas.php?id_encuesta=$id_encuesta");
exit();
?>
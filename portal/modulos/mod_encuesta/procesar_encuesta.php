<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$nombre_encuesta = $_POST['nombre_encuesta'];
$fecha_termino = $_POST['fecha_termino'];
$codigo_local = $_POST['codigo_local'];
$id_usuario = $_POST['id_usuario'];


// Insertar la encuesta
$insertar_encuesta = "INSERT INTO encuesta (nombre, fecha_creacion, fecha_termino, codigo_local, id_usuario)
VALUES ('$nombre_encuesta', NOW(), '$fecha_termino', '$codigo_local', '$id_usuario')";

$id_encuesta = ejecutar($insertar_encuesta);

// Redirigir a agregar preguntas
header("Location: agregar_preguntas.php?id_encuesta=$id_encuesta");
exit();
?>
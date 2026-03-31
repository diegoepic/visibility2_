<?php
session_start();
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Verificar que se reciba el parámetro 'id'
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div>Error: ID de formulario no proporcionado.</div>";
    exit();
}

$formulario_id = intval($_GET['id']);
if ($formulario_id <= 0) {
    echo "<div>Error: ID de formulario inválido.</div>";
    exit();
}

// Consulta para obtener el campo url_bi y el nombre del formulario
$stmt = $conn->prepare("SELECT url_bi, nombre FROM formulario WHERE id = ?");
if (!$stmt) {
    echo "<div>Error en la preparación de la consulta.</div>";
    exit();
}
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$stmt->bind_result($url_bi, $nombre);
if ($stmt->fetch()) {
    // Extraer el valor del atributo src usando una expresión regular
    $url = '';
    if (preg_match('/src\s*=\s*"(.*?)"/i', $url_bi, $matches)) {
        $url = $matches[1];
    }
    if (empty($url)) {
        echo "<div>Error: No se encontró la URL en el campo url_bi.</div>";
        exit();
    }
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Informe BI - <?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?></title>
    </head>
    <body>
        <iframe 
            title="<?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?>"
            width="100%"
            height="1450px"
            src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
            frameborder="0"
            allowFullScreen="true">
        </iframe>
    </body>
    </html>
    <?php
} else {
    echo "<div>Error: Formulario no encontrado.</div>";
}
$stmt->close();
?>
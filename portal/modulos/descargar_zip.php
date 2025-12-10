<?php
session_start(); 
// Si necesitas validar aquí que el usuario esté logueado o tenga permisos, hazlo.

if (!isset($_POST['jsonFotos'])) {
    die("No se recibieron fotos para comprimir.");
}

// Decodificar JSON
$fotos = json_decode($_POST['jsonFotos'], true);
if (!is_array($fotos) || count($fotos) === 0) {
    die("Lista de fotos inválida.");
}

// Usaremos ZipArchive para crear el ZIP
$zipFile = tempnam(sys_get_temp_dir(), 'fotos_');
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    die("No se pudo crear el archivo ZIP.");
}

// Ajusta si tienes otra ruta absoluta local donde están tus imágenes
// Por ejemplo, si la data-url ya es la ruta completa con "https://...",
// necesitaremos quitar el "https://visibility.cl/visibility2/app/" de la URL
// para formar la ruta física local. 
// Suponiendo que tus archivos físicos están en:
$docRoot = $_SERVER['DOCUMENT_ROOT'] . "/visibility2/app/";

// Recorremos las fotos seleccionadas
foreach ($fotos as $f) {
    $fotoUrl = $f['url'];       // p.ej "https://visibility.cl/visibility2/app/uploads/imagen.jpg"
    $fileName = $f['filename']; // Ej: "foto_123.jpg"

    // Quitar base URL para obtener la parte "uploads/imagen.jpg"
    // Ajusta la base real si difiere
    $buscaBase = "https://visibility.cl/visibility2/app/";
    $rutaRelativa = str_replace($buscaBase, "", $fotoUrl);

    // Ruta local absoluta
    $rutaLocal = $docRoot . $rutaRelativa;

    if (file_exists($rutaLocal)) {
        // Añadir al ZIP con el nombre que queramos
        $zip->addFile($rutaLocal, $fileName);
    }
}

$zip->close();

// Enviamos el ZIP al navegador
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="fotos_seleccionadas.zip"');
header('Content-Length: ' . filesize($zipFile));
readfile($zipFile);
unlink($zipFile);
exit;

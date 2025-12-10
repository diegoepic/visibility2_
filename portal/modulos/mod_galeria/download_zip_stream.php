<?php
session_start();

// 1) Verificar que recibimos un JSON con las fotos
if (!isset($_POST['jsonFotos'])) {
    die("No se recibieron fotos.");
}

$jsonFotos = $_POST['jsonFotos'];
$fotos = json_decode($jsonFotos, true);
if (!is_array($fotos) || count($fotos) === 0) {
    die("Lista de fotos inválida.");
}

// 2) Cargar la librería de ZipStream (vía Composer autoload)
require_once __DIR__ . '/vendor/autoload.php';

use ZipStream\Option\Archive;
use ZipStream\ZipStream;

// 3) Configurar opciones (ej. no guardar en disco, usar streaming)
$options = new Archive();
$options->setSendHttpHeaders(true); // ZipStream enviará cabeceras HTTP

// 4) Crear el objeto ZipStream
$zip = new ZipStream('fotos_seleccionadas.zip', $options);

// 5) Definir tu ruta absoluta a las imágenes
//    OJO: no uses $_SERVER['DOCUMENT_ROOT'] si no está disponible.
//    Ajusta la ruta real a tu hosting.
$docRoot = '/home/usuario/public_html/visibility2/app/';
$baseURL = 'https://visibility.cl/visibility2/app/';

foreach ($fotos as $f) {
    $fotoUrl  = $f['url'];       // p.ej. "https://visibility.cl/visibility2/app/uploads/imagen.jpg"
    $fileName = $f['filename'];  // "foto_123.jpg"

    // Convertir URL a ruta local
    $rutaRelativa = str_replace($baseURL, '', $fotoUrl);
    $rutaLocal = $docRoot . $rutaRelativa;

    if (file_exists($rutaLocal)) {
        // Añadir al ZIP. 
        // Con ZipStream, puedes usar addFileFromPath para leer el archivo del disco
        $zip->addFileFromPath($fileName, $rutaLocal);
    }
}

// 6) Finalizar el ZIP (cierra el stream)
$zip->finish();
exit;

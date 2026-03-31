<?php
// generar_zip.php
if ($argc < 2) {
    exit("No se proporcionó un job ID.\n");
}
$jobId = $argv[1];
$jobDir = __DIR__ . '/jobs/';
$jobFile = $jobDir . $jobId . '.json';

if (!file_exists($jobFile)) {
    exit("Job no encontrado.\n");
}

$jobData = json_decode(file_get_contents($jobFile), true);
if (!$jobData || !isset($jobData['jsonFotos'])) {
    exit("Datos de job inválidos.\n");
}

$jsonFotos = $jobData['jsonFotos'];
$fotos = json_decode($jsonFotos, true);
if (!is_array($fotos) || count($fotos) === 0) {
    $jobData['status'] = 'failed';
    file_put_contents($jobFile, json_encode($jobData));
    exit("No hay fotos para procesar.\n");
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] . "/visibility2/app/";
$baseURL = "https://visibility.cl/visibility2/app/";

// Crear archivo ZIP temporal
$zipFile = sys_get_temp_dir() . '/' . $jobId . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    $jobData['status'] = 'failed';
    file_put_contents($jobFile, json_encode($jobData));
    exit("No se pudo crear el ZIP.\n");
}

foreach ($fotos as $f) {
    $fotoUrl = $f['url'];       // Ej: "https://visibility.cl/visibility2/app/uploads/imagen.jpg"
    $fileName = $f['filename']; // Ej: "foto_123.jpg"
    // Convertir URL a ruta relativa
    $rutaRelativa = str_replace($baseURL, "", $fotoUrl);
    $rutaLocal = $docRoot . $rutaRelativa;
    if (file_exists($rutaLocal)) {
        $zip->addFile($rutaLocal, $fileName);
    }
}

$zip->close();

// Actualizar el job con status "ready" y guardar la ruta del ZIP
$jobData['status'] = 'ready';
$jobData['zipFile'] = $zipFile;
file_put_contents($jobFile, json_encode($jobData));
exit;

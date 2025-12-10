<?php
// cron_zip_generator.php
// Se ejecuta cada X minutos vía cron
$jobDir = __DIR__ . '/jobs/';
$files = glob($jobDir . 'zip_*.json');

$docRoot = '/home/tu_usuario/public_html/visibility2/app/'; // Ruta absoluta real
$baseURL = "https://visibility.cl/visibility2/app/";

foreach ($files as $jobFile) {
    $jobData = json_decode(file_get_contents($jobFile), true);
    if (!$jobData) {
        continue; // Archivo corrupto
    }
    if ($jobData['status'] !== 'pending') {
        continue; // No hay que procesar
    }

    // Cambiar a "in_progress" si deseas evitar procesarlo dos veces
    $jobData['status'] = 'in_progress';
    file_put_contents($jobFile, json_encode($jobData));

    // Generar el ZIP
    $jsonFotos = $jobData['jsonFotos'];
    $fotos = json_decode($jsonFotos, true);
    if (!is_array($fotos) || count($fotos) === 0) {
        $jobData['status'] = 'failed';
        file_put_contents($jobFile, json_encode($jobData));
        continue;
    }

    // Ruta del ZIP en /tmp o en tu propia carpeta
    $zipFile = sys_get_temp_dir() . '/' . basename($jobFile) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
        $jobData['status'] = 'failed';
        file_put_contents($jobFile, json_encode($jobData));
        continue;
    }

    foreach ($fotos as $f) {
        $fotoUrl = $f['url'];
        $fileName = $f['filename'];
        $rutaRelativa = str_replace($baseURL, "", $fotoUrl);
        $rutaLocal = $docRoot . $rutaRelativa;
        if (file_exists($rutaLocal)) {
            $zip->addFile($rutaLocal, $fileName);
        }
    }

    $zip->close();

    // Actualizar status
    $jobData['status'] = 'ready';
    $jobData['zipFile'] = $zipFile;
    file_put_contents($jobFile, json_encode($jobData));
}


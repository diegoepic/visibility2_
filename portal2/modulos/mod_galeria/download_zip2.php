<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Para depuración, acumulamos mensajes en un arreglo.
$log = [];

// 1) Verificar que se recibió un JSON con las fotos
if (!isset($_POST['jsonFotos'])) {
    die("No se recibieron fotos.");
}
$jsonFotos = $_POST['jsonFotos'];
$fotos = json_decode($jsonFotos, true);
if (!is_array($fotos) || count($fotos) === 0) {
    die("Lista de fotos inválida.");
}

// 1.1) Obtener la vista enviada (implementacion o encuesta)
$view = isset($_POST['view']) ? trim($_POST['view']) : 'implementacion';
$log[] = "Vista recibida: " . $view;

// 1.2) Capturar las fechas (si se filtró por ellas) desde POST
$start_date = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
$end_date   = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';
if ($view === 'encuesta') {
    $log[] = "Fechas recibidas: " . $start_date . " a " . $end_date;
}

// 2) Agrupar los locales seleccionados a partir de los datos recibidos  
// Se asume que cada objeto JSON incluye una clave "local" con el ID del local.
$locales = [];
foreach ($fotos as $f) {
    if (isset($f['local'])) {
        $localId = intval($f['local']);
        // Usamos el ID como clave para evitar duplicados.
        $locales[$localId] = $localId;
    }
}
$log[] = "Locales agrupados: " . implode(", ", array_keys($locales));

// 3) Definir la ruta absoluta a las imágenes y la URL base
$docRoot = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/';
$baseURL = 'https://visibility.cl/visibility2/app/';

// 4) Crear un archivo ZIP temporal usando ZipArchive
$tmpFile = tempnam(sys_get_temp_dir(), 'zip');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    die("No se pudo crear el archivo ZIP.");
}

// 5) Incluir la conexión a la base de datos para obtener las fotos de cada local
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// 6) Para cada local seleccionado, obtener todas las imágenes asociadas y agregarlas al ZIP
foreach ($locales as $localId) {
    if ($view === 'encuesta') {
        // Consulta para la vista de encuesta con filtro de fechas
        $stmt = $conn->prepare("
            SELECT 
               fqr.answer_text AS url,
               CONCAT('local_', fqr.id_local, '_', fqr.id, '.jpg') AS filename
            FROM form_question_responses fqr
            JOIN form_questions fq ON fq.id = fqr.id_form_question
            WHERE fqr.id_local = ?
              AND fq.id_question_type = 7
              AND DATE(fqr.created_at) >= ?
              AND DATE(fqr.created_at) <= ?
            ORDER BY fqr.created_at DESC
        ");
        if (!$stmt) {
            $log[] = "Error en la preparación para local $localId (encuesta): " . $conn->error;
            continue;
        }
        // Bind: "iss" (local: int, start_date: string, end_date: string)
        $stmt->bind_param("iss", $localId, $start_date, $end_date);
    } else {
        // Consulta para la vista de implementación
        $stmt = $conn->prepare("
            SELECT 
               fv.url,
               CONCAT('local_', fq.id_local, '_', fv.id, '.jpg') AS filename
            FROM formularioQuestion fq
            JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
            WHERE fq.id_local = ?
            ORDER BY fq.fechaVisita DESC
        ");
        if (!$stmt) {
            $log[] = "Error en la preparación para local $localId (implementación): " . $conn->error;
            continue;
        }
        $stmt->bind_param("i", $localId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $localCount = 0;
    while ($row = $result->fetch_assoc()) {
         $fotoUrl  = $row['url'];
         $fileName = $row['filename'];

         // Convertir la URL a una ruta local
         $rutaRelativa = str_replace($baseURL, '', $fotoUrl);
         $rutaLocal = $docRoot . $rutaRelativa;

         if (file_exists($rutaLocal)) {
             $zip->addFile($rutaLocal, $fileName);
             $localCount++;
         } else {
             $log[] = "Archivo no encontrado para local $localId: $rutaLocal";
         }
    }
    $log[] = "Local $localId: Se agregaron $localCount imágenes.";
    $stmt->close();
}

$zip->close();
$log[] = "ZIP finalizado. Tamaño: " . filesize($tmpFile) . " bytes.";

// 7) Modo Debug: si se pasa ?debug=1 en la URL, mostramos el log en lugar de enviar el ZIP.
$debug = (isset($_GET['debug']) && $_GET['debug'] == 1);
if ($debug) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h2>Modo Debug - Log del script de descarga</h2>";
    echo "<pre>" . print_r($log, true) . "</pre>";
    echo "<p>El archivo ZIP se ha creado en: $tmpFile</p>";
    // No eliminamos el archivo temporal para que puedas revisarlo.
    exit;
}

// 8) Enviar el ZIP al navegador
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="fotos_seleccionadas.zip"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);

// 9) Eliminar el archivo ZIP temporal
unlink($tmpFile);
exit;
?>

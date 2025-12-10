<?php
session_start();

if (!isset($_POST['jsonFotos'])) {
    echo json_encode(['success' => false, 'message' => 'No se proporcionó la lista de fotos.']);
    exit;
}

$jsonFotos = $_POST['jsonFotos'];
// Generar un ID único para el job
$jobId = uniqid('zip_', true);

// Aquí puedes guardar en una base de datos o en un archivo. 
// Supongamos que usas un archivo jobs/zip_XXXX.json:
$jobDir = __DIR__ . '/jobs/';
if (!file_exists($jobDir)) {
    mkdir($jobDir, 0777, true);
}
$jobFile = $jobDir . $jobId . '.json';

// Creamos un JSON con estado "pending"
$jobData = [
    'status' => 'pending',
    'jsonFotos' => $jsonFotos,
    'zipFile' => ''
];
file_put_contents($jobFile, json_encode($jobData));

// Devolvemos success, el job se procesará luego por un cron
echo json_encode(['success' => true, 'job_id' => $jobId]);
exit;

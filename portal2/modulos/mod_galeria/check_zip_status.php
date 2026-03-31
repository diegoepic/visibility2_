<?php
session_start();
if (!isset($_GET['job_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Job ID no proporcionado']);
    exit;
}
$jobId = $_GET['job_id'];
$jobFile = __DIR__ . '/jobs/' . $jobId . '.json';
if (!file_exists($jobFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Job no encontrado']);
    exit;
}

$jobData = json_decode(file_get_contents($jobFile), true);
if (!$jobData) {
    echo json_encode(['status' => 'error', 'message' => 'Datos de job inválidos']);
    exit;
}

echo json_encode(['status' => $jobData['status']]);
exit;

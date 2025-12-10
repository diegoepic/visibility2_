<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: application/json; charset=utf-8');

// Validar archivo
if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([]);
    exit;
}

$file = $_FILES['csvFile']['tmp_name'];
$handle = fopen($file, 'r');
if (!$handle) {
    echo json_encode([]);
    exit;
}

$codigos = [];
$lineNumber = 0;

while (($data = fgetcsv($handle, 1000, ',')) !== false) {
    $lineNumber++;
    $codigo = trim($data[0] ?? '');

    // Saltar la primera línea si parece un encabezado
    if ($lineNumber === 1 && preg_match('/^codigo$/i', $codigo)) {
        continue;
    }

    if ($codigo !== '') {
        $codigos[] = $codigo;
    }
}
fclose($handle);

if (empty($codigos)) {
    echo json_encode([]);
    exit;
}

// Generar placeholders para consulta segura
$placeholders = implode(',', array_fill(0, count($codigos), '?'));
$types = str_repeat('s', count($codigos));

$sql = "SELECT codigo, nombre, direccion, lat, lng 
        FROM local 
        WHERE codigo IN ($placeholders) 
        AND lat IS NOT NULL AND lng IS NOT NULL";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$codigos);
$stmt->execute();
$result = $stmt->get_result();

$locales = [];
while ($row = $result->fetch_assoc()) {
    $locales[] = $row;
}

echo json_encode($locales, JSON_UNESCAPED_UNICODE);

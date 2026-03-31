<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

function jsonResponse(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse([
        'success' => false,
        'message' => 'No se recibió un archivo CSV válido.'
    ]);
}

$file = $_FILES['csvFile']['tmp_name'];

if (!is_uploaded_file($file)) {
    jsonResponse([
        'success' => false,
        'message' => 'El archivo cargado no es válido.'
    ]);
}

$handle = fopen($file, 'r');
if (!$handle) {
    jsonResponse([
        'success' => false,
        'message' => 'No fue posible abrir el archivo CSV.'
    ]);
}

$codigos = [];
$lineNumber = 0;

while (($line = fgets($handle)) !== false) {
    $lineNumber++;

    if ($lineNumber === 1) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $delimiter = (substr_count($line, ';') > substr_count($line, ',')) ? ';' : ',';
    $data = str_getcsv($line, $delimiter);

    $codigo = trim($data[0] ?? '');

    // Saltar encabezado típico
    if ($lineNumber === 1 && preg_match('/^(codigo|cod_local|local|cod)$/i', $codigo)) {
        continue;
    }

    if ($codigo !== '') {
        $codigos[] = $codigo;
    }
}
fclose($handle);

$codigos = array_values(array_unique($codigos));

if (empty($codigos)) {
    jsonResponse([
        'success' => false,
        'message' => 'El CSV no contiene códigos válidos.'
    ]);
}

$placeholders = implode(',', array_fill(0, count($codigos), '?'));
$types = str_repeat('s', count($codigos));

$sql = "
    SELECT
        l.codigo,
        l.nombre,
        l.direccion,
        c.comuna,
        l.lat,
        l.lng
    FROM local l
    LEFT JOIN comuna c
        ON c.id = l.id_comuna
    WHERE l.codigo IN ($placeholders)
      AND l.deleted_at IS NULL
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    jsonResponse([
        'success' => false,
        'message' => 'Error al preparar la consulta SQL.',
        'sql_error' => $conn->error
    ]);
}

$stmt->bind_param($types, ...$codigos);

if (!$stmt->execute()) {
    jsonResponse([
        'success' => false,
        'message' => 'Error al ejecutar la consulta SQL.',
        'sql_error' => $stmt->error
    ]);
}

$result = $stmt->get_result();

$encontrados = [];
$codigosEncontrados = [];

while ($row = $result->fetch_assoc()) {
    $codigo = trim((string)$row['codigo']);
    $codigosEncontrados[] = $codigo;

    $encontrados[] = [
        'codigo'    => $codigo,
        'nombre'    => $row['nombre'] ?? '',
        'direccion' => $row['direccion'] ?? '',
        'comuna'    => $row['comuna'] ?? '',
        'lat'       => $row['lat'],
        'lng'       => $row['lng']
    ];
}

$stmt->close();

$codigosEncontrados = array_unique($codigosEncontrados);
$noEncontrados = array_values(array_diff($codigos, $codigosEncontrados));

usort($encontrados, function($a, $b) {
    return strcmp((string)$a['codigo'], (string)$b['codigo']);
});

sort($noEncontrados);

jsonResponse([
    'success'        => true,
    'message'        => 'Archivo procesado correctamente.',
    'total_csv'      => count($codigos),
    'encontrados'    => $encontrados,
    'no_encontrados' => $noEncontrados
]);
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$response = [
    'success' => false,
    'error'   => 'Solicitud inválida.'
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode($response);
    exit;
}

$id         = intval($_POST['id'] ?? 0);
$nombre     = trim($_POST['nombre'] ?? '');
$id_empresa = intval($_POST['id_empresa'] ?? 0);
$estado     = isset($_POST['estado']) ? 1 : 0;

if ($id <= 0) {
    $response['error'] = 'ID de división inválido.';
    echo json_encode($response);
    exit;
}

if ($nombre === '') {
    $response['error'] = 'Debes ingresar el nombre de la división.';
    echo json_encode($response);
    exit;
}

if ($id_empresa <= 0) {
    $response['error'] = 'Debes seleccionar una empresa.';
    echo json_encode($response);
    exit;
}

$nombreEsc = $conn->real_escape_string($nombre);

/* Obtener imagen actual */
$sqlActual = "SELECT image_url FROM division_empresa WHERE id = $id LIMIT 1";
$resActual = $conn->query($sqlActual);

if (!$resActual || $resActual->num_rows === 0) {
    $response['error'] = 'No se encontró la división.';
    echo json_encode($response);
    exit;
}

$rowActual = $resActual->fetch_assoc();
$image_url = $rowActual['image_url'] ?? '';

/* Validar duplicado opcional */
$sqlDup = "
    SELECT id
    FROM division_empresa
    WHERE nombre = '$nombreEsc'
      AND id_empresa = $id_empresa
      AND id <> $id
    LIMIT 1
";
$resDup = $conn->query($sqlDup);

if ($resDup && $resDup->num_rows > 0) {
    $response['error'] = 'Ya existe otra división con ese nombre para la empresa seleccionada.';
    echo json_encode($response);
    exit;
}

/* Procesar nueva imagen si viene */
if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    $allowed = [
        "jpg"  => "image/jpeg",
        "jpeg" => "image/jpeg",
        "png"  => "image/png",
        "gif"  => "image/gif",
        "webp" => "image/webp"
    ];

    $filename = $_FILES['image']['name'];
    $filetype = $_FILES['image']['type'];
    $filesize = $_FILES['image']['size'];
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!array_key_exists($ext, $allowed)) {
        $response['error'] = 'Formato de imagen no válido.';
        echo json_encode($response);
        exit;
    }

    if ($filesize > 5 * 1024 * 1024) {
        $response['error'] = 'La imagen supera el máximo permitido de 5 MB.';
        echo json_encode($response);
        exit;
    }

    if (!in_array($filetype, $allowed)) {
        $response['error'] = 'El tipo MIME del archivo no es válido.';
        echo json_encode($response);
        exit;
    }

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/divisiones/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $newFilename = uniqid('division_', true) . '.' . $ext;
    $destination = $uploadDir . $newFilename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
        $response['error'] = 'No fue posible subir la nueva imagen.';
        echo json_encode($response);
        exit;
    }

    $newImageUrl = '/visibility2/portal/uploads/divisiones/' . $newFilename;

    // eliminar imagen anterior si existe físicamente
    if (!empty($image_url)) {
        $oldPath = $_SERVER['DOCUMENT_ROOT'] . $image_url;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $image_url = $newImageUrl;
}

$imageEsc = $conn->real_escape_string($image_url);

$sqlUpdate = "
    UPDATE division_empresa
    SET
        nombre = '$nombreEsc',
        id_empresa = $id_empresa,
        image_url = '$imageEsc',
        estado = $estado
    WHERE id = $id
    LIMIT 1
";

if ($conn->query($sqlUpdate)) {
    echo json_encode([
        'success' => true,
        'message' => 'División actualizada correctamente.'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'error'   => 'Error al actualizar: ' . $conn->error
]);
exit;
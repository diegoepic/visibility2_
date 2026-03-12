<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$id          = intval($_POST['id'] ?? 0);
$id_empresa  = intval($_POST['empresa'] ?? 0);
$id_division = intval($_POST['division'] ?? 0);
$titulo      = trim($_POST['titulo'] ?? '');
$subtitulo   = trim($_POST['subtitulo'] ?? '');
$target_url  = trim($_POST['target_url'] ?? '');
$orden       = intval($_POST['orden'] ?? 1);
$is_active   = isset($_POST['is_active']) ? 1 : 0;

if ($id <= 0 || $id_empresa <= 0 || $id_division <= 0 || $titulo === '' || $target_url === '') {
    echo json_encode(['success' => false, 'error' => 'Faltan datos obligatorios.']);
    exit;
}

$sqlActual = "SELECT image_url FROM dashboard_carousel_items WHERE id = $id LIMIT 1";
$resActual = $conn->query($sqlActual);

if (!$resActual || $resActual->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'No se encontró el item.']);
    exit;
}

$rowActual = $resActual->fetch_assoc();
$image_url = $rowActual['image_url'] ?? '';

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

    if (!array_key_exists($ext, $allowed) || !in_array($filetype, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Formato de imagen no válido.']);
        exit;
    }

    if ($filesize > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'La imagen supera 5 MB.']);
        exit;
    }

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/carousel/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $newFilename = uniqid('carousel_', true) . '.' . $ext;
    $destination = $uploadDir . $newFilename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
        echo json_encode(['success' => false, 'error' => 'No se pudo subir la nueva imagen.']);
        exit;
    }

    $newImageUrl = '/visibility2/portal/uploads/carousel/' . $newFilename;

    if (!empty($image_url)) {
        $oldPath = $_SERVER['DOCUMENT_ROOT'] . $image_url;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $image_url = $newImageUrl;
}

$tituloEsc    = $conn->real_escape_string($titulo);
$subtituloEsc = $conn->real_escape_string($subtitulo);
$targetEsc    = $conn->real_escape_string($target_url);
$imageEsc     = $conn->real_escape_string($image_url);

$sql = "
    UPDATE dashboard_carousel_items
    SET
        id_empresa = $id_empresa,
        id_division = $id_division,
        titulo = '$tituloEsc',
        subtitulo = '$subtituloEsc',
        image_url = '$imageEsc',
        target_url = '$targetEsc',
        orden = $orden,
        is_active = $is_active
    WHERE id = $id
    LIMIT 1
";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Error al actualizar: ' . $conn->error]);
}
exit;
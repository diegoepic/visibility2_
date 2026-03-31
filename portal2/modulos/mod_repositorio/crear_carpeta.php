<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'Usuario no autenticado']);
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$nombre = trim($_POST['nombre_carpeta'] ?? '');

if ($nombre === '') {
    echo json_encode(['error' => 'Debe indicar un nombre de carpeta']);
    exit;
}

// sanitizar
$nombre = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nombre);

// validar BD: carpeta ya existe
$check = $conn->prepare("SELECT id FROM repo_carpeta WHERE nombre = ?");
$check->bind_param("s", $nombre);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    echo json_encode(['error' => 'La carpeta ya existe']);
    exit;
}

// crear carpeta física
$folderRoot = $_SERVER['DOCUMENT_ROOT'] . "/visibility2/portal/repositorio";
$path = $folderRoot . '/' . $nombre;

if (!is_dir($path)) {
    mkdir($path, 0777, true);
}

// guardar en BD
$stmt = $conn->prepare("
    INSERT INTO repo_carpeta (nombre, usuario_creador)
    VALUES (?, ?)
");
$stmt->bind_param("si", $nombre, $_SESSION['usuario_id']);
$stmt->execute();

echo json_encode(['ok'=>true, 'carpeta'=>$nombre]);
exit;

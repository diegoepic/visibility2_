<?php
session_start();

// HEADER JSON + BUFFER
header('Content-Type: application/json; charset=utf-8');
ob_start();

// DEBUG TEMPORAL
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- 1) Usuario ---
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'Usuario no autenticado']);
    exit;
}
$idUsuario = intval($_SESSION['usuario_id']);

// --- 2) Conexion BD ---
require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// --- 3) Validar archivo ---
if (!isset($_FILES['mi_archivo'])) {
    echo json_encode(['error' => 'No se recibió archivo']);
    exit;
}
$f = $_FILES['mi_archivo'];

// error nativo PHP
if ($f['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Error al subir archivo']);
    exit;
}

// --- 4) Validar extension ---
$extPermitidas = ['csv','xlsx'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $extPermitidas)) {
    echo json_encode(['error' => 'Extensión no permitida (.csv o .xlsx)']);
    exit;
}

// --- 5) Carpeta ---
$carpeta = trim($_POST['carpeta'] ?? '');

if ($carpeta === '') {
    echo json_encode(['error' => 'Debe seleccionar carpeta']);
    exit;
}

// sanitizar
$carpeta = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $carpeta);

// --- 6) Ruta física ---
$baseDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/repositorio';
$rutaCarpeta = $baseDir . '/' . $carpeta;

if (!is_dir($rutaCarpeta)) {
    mkdir($rutaCarpeta, 0777, true);
}

// --- 7) Nombre seguro ---
$nombreSeguro = uniqid('IPT_', true) . '.' . $ext;
$rutaFinal = $rutaCarpeta . '/' . $nombreSeguro;

// --- 8) Mover archivo ---
if (!move_uploaded_file($f['tmp_name'], $rutaFinal)) {
    echo json_encode(['error' => 'No se pudo guardar el archivo']);
    exit;
}

// --- 9) Metadata ---
$tamanoBytes = filesize($rutaFinal);
$mime = mime_content_type($rutaFinal);
$hash = hash_file("sha256", $rutaFinal);

// --- 10) Rutas públicas ---
$urlPublica = "https://visibility.cl/visibility2/portal/repositorio/$carpeta/$nombreSeguro";
$rutaRelativa = "/repositorio/$carpeta/$nombreSeguro";

// --- 11) Guardar en BD ---
$stmt = $conn->prepare("
INSERT INTO repo_archivo (
    id_usuario,
    nombre_archivo,
    carpeta,
    ruta_relativa,
    ruta_url,
    tipo_archivo,
    tamano_bytes,
    estado,
    observacion,
    fecha_creacion,
    fecha_actualizado
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

$estado = 0;
$obs = "";

$stmt->bind_param(
    "isssssiss",
    $idUsuario,
    $f['name'],
    $carpeta,
    $rutaRelativa,
    $urlPublica,
    $ext,
    $tamanoBytes,
    $estado,
    $obs
);

if (!$stmt->execute()) {
    echo json_encode([
        'error' => 'Error BD',
        'sql' => $stmt->error
    ]);
    exit;
}

// --- 12) Capturar errores invisibles ---
$buffer = ob_get_clean();
if ($buffer !== "") {
    echo json_encode([
        "error" => "PHP warnings detectados",
        "debug" => $buffer
    ]);
    exit;
}

// --- 13) Respuesta exitosa ---
echo json_encode([
    "exito" => true,
    "url" => $urlPublica,
    "nombre_original" => $f['name'],
    "nombre_servidor" => $nombreSeguro,
    "peso" => $tamanoBytes,
    "mime" => $mime
]);
exit;

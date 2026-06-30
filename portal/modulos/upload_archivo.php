<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function uploadErrorMessage(int $error): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'El archivo supera upload_max_filesize del servidor.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el formulario.',
        UPLOAD_ERR_PARTIAL => 'El archivo se recibió parcialmente.',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal configurada.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida.',
    ];

    return $messages[$error] ?? 'Ocurrió un error desconocido durante la subida.';
}

function safeFolderName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[^\pL\pN _-]+/u', '', $name) ?? '';
    $name = preg_replace('/\s+/', '_', $name) ?? '';

    return trim($name, '._- ');
}

function safeFileName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $baseName = pathinfo($name, PATHINFO_FILENAME);
    $baseName = preg_replace('/[^\pL\pN _.-]+/u', '_', $baseName) ?? '';
    $baseName = preg_replace('/\s+/', '_', trim($baseName)) ?? '';
    $baseName = trim($baseName, '.-_ ');

    if ($baseName === '') {
        $baseName = 'archivo';
    }

    return $extension !== '' ? $baseName . '.' . $extension : $baseName;
}

$baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'repositorio';

if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
    respond(500, ['error' => 'No se pudo crear el directorio repositorio.']);
}

if (!is_writable($baseDir)) {
    respond(500, ['error' => 'El directorio repositorio no tiene permisos de escritura.']);
}

$existingFolder = trim((string)($_POST['carpeta_existente'] ?? ''));
$newFolder = trim((string)($_POST['carpeta_nueva'] ?? ''));
$folderName = safeFolderName($newFolder !== '' ? $newFolder : $existingFolder);

if ($folderName === '') {
    respond(400, ['error' => 'Debes seleccionar una carpeta o indicar una carpeta nueva válida.']);
}

$targetFolder = $baseDir . DIRECTORY_SEPARATOR . $folderName;

if (!is_dir($targetFolder) && !mkdir($targetFolder, 0755, true)) {
    respond(500, ['error' => 'No se pudo crear la carpeta de destino.']);
}

if (!is_writable($targetFolder)) {
    respond(500, ['error' => 'La carpeta de destino no tiene permisos de escritura.']);
}

if (!isset($_FILES['mi_archivo']) || !is_array($_FILES['mi_archivo'])) {
    respond(400, ['error' => 'No se recibió ningún archivo.']);
}

$upload = $_FILES['mi_archivo'];
$uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError !== UPLOAD_ERR_OK) {
    respond(400, ['error' => uploadErrorMessage($uploadError)]);
}

$originalName = (string)($upload['name'] ?? '');
$temporaryPath = (string)($upload['tmp_name'] ?? '');
$size = (int)($upload['size'] ?? 0);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExtensions = ['ppt', 'pptx', 'csv', 'xls', 'xlsx', 'zip', 'rar', 'pdf'];

if (!in_array($extension, $allowedExtensions, true)) {
    respond(400, ['error' => 'Tipo de archivo no permitido.']);
}

$maxSize = 100 * 1024 * 1024;
if ($size <= 0 || $size > $maxSize) {
    respond(400, ['error' => 'El archivo está vacío o supera el máximo permitido de 100 MB.']);
}

if (!is_uploaded_file($temporaryPath)) {
    respond(400, ['error' => 'El archivo temporal de subida no es válido.']);
}

$safeName = safeFileName($originalName);
$targetPath = $targetFolder . DIRECTORY_SEPARATOR . $safeName;
$replaceExisting = isset($_POST['reemplazar_existente']) && $_POST['reemplazar_existente'] === '1';
$wasReplaced = file_exists($targetPath);

if ($wasReplaced && !$replaceExisting) {
    respond(409, [
        'error' => 'Ya existe un archivo con el mismo nombre. Activa la opción de reemplazo para continuar.',
    ]);
}

$backupPath = null;
if ($wasReplaced) {
    $backupPath = $targetPath . '.upload-backup-' . bin2hex(random_bytes(5));

    if (!rename($targetPath, $backupPath)) {
        respond(500, ['error' => 'No fue posible preparar el reemplazo del archivo existente. Revisa sus permisos.']);
    }
}

if (!move_uploaded_file($temporaryPath, $targetPath)) {
    if ($backupPath !== null && file_exists($backupPath)) {
        @rename($backupPath, $targetPath);
    }

    respond(500, ['error' => 'No se pudo guardar el archivo en la carpeta de destino.']);
}

@chmod($targetPath, 0644);

if ($backupPath !== null && file_exists($backupPath)) {
    @unlink($backupPath);
}

$domain = 'https://visibility.cl/visibility2/portal';
$url = $domain . '/repositorio/' . rawurlencode($folderName) . '/' . rawurlencode($safeName);

respond(200, [
    'exito' => true,
    'reemplazado' => $wasReplaced,
    'nombre' => $safeName,
    'carpeta' => $folderName,
    'url' => $url,
]);

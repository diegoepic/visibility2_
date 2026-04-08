<?php
function convertirImagenAWebp(array $file, string $destinoDirectorio, int $calidad = 82): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Archivo temporal inválido.');
    }

    $info = getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new Exception('El archivo no es una imagen válida.');
    }

    $mime = $info['mime'] ?? '';

    switch ($mime) {
        case 'image/jpeg':
            $imagen = imagecreatefromjpeg($file['tmp_name']);
            break;

        case 'image/png':
            $imagen = imagecreatefrompng($file['tmp_name']);
            imagepalettetotruecolor($imagen);
            imagealphablending($imagen, true);
            imagesavealpha($imagen, true);
            break;

        case 'image/gif':
            $imagen = imagecreatefromgif($file['tmp_name']);
            imagepalettetotruecolor($imagen);
            imagealphablending($imagen, true);
            imagesavealpha($imagen, true);
            break;

        case 'image/webp':
            $imagen = imagecreatefromwebp($file['tmp_name']);
            break;

        default:
            throw new Exception('Formato no soportado para conversión a WebP.');
    }

    if (!$imagen) {
        throw new Exception('No se pudo procesar la imagen.');
    }

    if (!is_dir($destinoDirectorio) && !mkdir($destinoDirectorio, 0755, true)) {
        imagedestroy($imagen);
        throw new Exception('No se pudo crear el directorio destino.');
    }

    $nombreArchivo = uniqid('dash_', true) . '.webp';
    $rutaFisica = rtrim($destinoDirectorio, '/') . '/' . $nombreArchivo;

    if (!imagewebp($imagen, $rutaFisica, $calidad)) {
        imagedestroy($imagen);
        throw new Exception('No se pudo convertir la imagen a WebP.');
    }

    imagedestroy($imagen);

    return [
        'filename' => $nombreArchivo,
        'full_path' => $rutaFisica
    ];
}
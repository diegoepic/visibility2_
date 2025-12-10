<?php
// upload_archivo.php
header("Content-Type: application/json; charset=UTF-8");

// 1) Defino base y compruebo que exista y sea escribible:
$baseDir = __DIR__ . '/../repositorio';
if (!is_dir($baseDir) || !is_writable($baseDir)) {
    http_response_code(500);
    echo json_encode([
        "error" => "El directorio repositorio no existe o no tiene permisos de escritura."
    ]);
    exit;
}

// 2) Tomo datos del formulario:
$carpetaExistente = isset($_POST['carpeta_existente']) ? trim($_POST['carpeta_existente']) : '';
$carpetaNueva     = isset($_POST['carpeta_nueva'])     ? trim($_POST['carpeta_nueva'])     : '';

// 3) Decidir carpeta destino (igual que antes)…
if ($carpetaNueva !== "") {
    $nombreCarpeta = preg_replace('/[^A-Za-z0-9_-]/', '', $carpetaNueva);
    if ($nombreCarpeta === "") {
        http_response_code(400);
        echo json_encode(["error" => "El nombre de carpeta nueva no es válido."]);
        exit;
    }
    $rutaCarpeta = "$baseDir/$nombreCarpeta";
    if (!is_dir($rutaCarpeta)) {
        if (!mkdir($rutaCarpeta, 0755, true)) {
            http_response_code(500);
            echo json_encode(["error" => "No se pudo crear la carpeta $nombreCarpeta."]);
            exit;
        }
    }
}
elseif ($carpetaExistente !== "") {
    $nombreCarpeta = preg_replace('/[^A-Za-z0-9_-]/', '', $carpetaExistente);
    $rutaCarpeta   = "$baseDir/$nombreCarpeta";
    if (!is_dir($rutaCarpeta)) {
        http_response_code(400);
        echo json_encode(["error" => "La carpeta seleccionada no existe."]);
        exit;
    }
}
else {
    http_response_code(400);
    echo json_encode(["error" => "Debes elegir carpeta existente o indicar una nueva."]);
    exit;
}

// 4) Validar y procesar el archivo subido:
if (!isset($_FILES['mi_archivo']) || $_FILES['mi_archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "No se envió ningún archivo o hubo un error al subir."]);
    exit;
}

$archivo    = $_FILES['mi_archivo'];
$nombreTemp = $archivo['tmp_name'];
$nombreOri  = $archivo['name'];
$tamanio    = $archivo['size'];

// 4a) Validar extensión permitida:
$ext = strtolower(pathinfo($nombreOri, PATHINFO_EXTENSION));
$extPermit = ['ppt', 'pptx', 'csv', 'xlsx', 'zip', 'rar'];
if (!in_array($ext, $extPermit)) {
    http_response_code(400);
    echo json_encode(["error" => "Tipo de archivo no permitido. Solo .ppt, .pptx, .csv, .xlsx, .zip o .rar."]);
    exit;
}

// 4b) (Opcional) Validar tamaño máximo (por ejemplo 50MB):
$maxSizeBytes = 50 * 1024 * 1024; // 50 MB
if ($tamanio > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode(["error" => "El archivo excede el tamaño máximo permitido (50MB)."]);
    exit;
}

// — Aquí viene el cambio importante —
// 4c) Ahora usaremos el nombre ORIGINAL (limpiado de caracteres extraños) 
// para que, si ya había un “informe.zip”, al mover, lo pise:

// Sanitizo el nombre original (solo letras, números, guiones, puntos y guiones bajos)
$nombreSeguro = preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombreOri);

// 5) Ruta destino completa:
$rutaDestino = "$rutaCarpeta/$nombreSeguro";

// 5.1) (Opcional) Si quieres borrar el viejo antes de pisar:
// if (file_exists($rutaDestino)) {
//     unlink($rutaDestino);
// }

// 6) Mover el archivo (si el destino ya existe, lo sobrescribe por defecto):
if (!move_uploaded_file($nombreTemp, $rutaDestino)) {
    http_response_code(500);
    echo json_encode(["error" => "Error al mover el archivo a la carpeta destino."]);
    exit;
}

// 7) Generar la URL pública:
$dominio = "https://visibility.cl/visibility2/portal";
$subpath = "repositorio/$nombreCarpeta/$nombreSeguro";
$urlFinal = "$dominio/$subpath";

// 8) Responder con éxito:
http_response_code(200);
echo json_encode([
    "exito" => true,
    "url"   => $urlFinal
]);
exit;

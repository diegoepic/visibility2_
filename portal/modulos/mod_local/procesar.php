<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Verificar autenticación y CSRF
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
    exit();
}

// 2. Obtener datos
$codigoLocal = trim($_POST['inputCodigoLocal']);
$nombreLocal = trim($_POST['inputLocal']);
$direccion   = trim($_POST['inputDireccion']);
$cuenta_id   = intval($_POST['cuenta_id']);
$cadena_id   = intval($_POST['cadena_id']);
$comuna_id   = intval($_POST['comuna_id']);
$empresa_id  = intval($_POST['empresa_id']);
$division_id = isset($_POST['division_id']) ? intval($_POST['division_id']) : 1;

// LEER canal_id y subcanal_id
$canal_id    = intval($_POST['canal_id']);
$subcanal_id = intval($_POST['subcanal_id']);

// NUEVO: relevancia, zona, distrito, jefe_venta, vendedor
$relevancia     = isset($_POST['relevancia']) ? intval($_POST['relevancia']) : 0;
$zona_id        = isset($_POST['zona_id']) ? intval($_POST['zona_id']) : 0;
$distrito_id    = isset($_POST['distrito_id']) ? intval($_POST['distrito_id']) : 0;
$jefe_venta_id  = isset($_POST['jefe_venta_id']) ? intval($_POST['jefe_venta_id']) : 0;
$vendedor_id    = isset($_POST['vendedor_id']) ? intval($_POST['vendedor_id']) : 0;

$lat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
$lng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;

// 3. Validaciones
if (
    empty($codigoLocal) ||
    empty($nombreLocal)  ||
    empty($direccion)    ||
    $empresa_id  <= 0    ||
    $division_id <= 0    ||
    $cuenta_id   <= 0    ||
    $cadena_id   <= 0    ||
    $comuna_id   <= 0    ||
    $canal_id    <= 0    ||
    $subcanal_id <= 0
) {
    echo json_encode([
        'success' => false,
        'message' => 'Por favor, completa todos los campos requeridos.'
    ]);
    exit();
}

// 4. Verificar código único
$stmt_check = $conn->prepare("
    SELECT e.nombre
    FROM local l
    JOIN empresa e ON l.id_empresa = e.id
    WHERE l.codigo = ?
    LIMIT 1
");
if (!$stmt_check) {
    echo json_encode([
        'success' => false,
        'message' => 'Error preparando verificación: ' . $conn->error
    ]);
    exit();
}
$stmt_check->bind_param("s", $codigoLocal);
$stmt_check->execute();
$stmt_check->bind_result($nombreEmpresaExistente);
$existe = $stmt_check->fetch();
$stmt_check->close();
if ($existe) {
    echo json_encode([
        'success' => false,
        'message' => "El local ya existe en la Empresa '$nombreEmpresaExistente' con código '$codigoLocal'."
    ]);
    exit();
}

// 5. Geocodificar si lat/lng no llegan
if (is_null($lat) || is_null($lng)) {
    $direccionEncoded = urlencode($direccion);
    // Sustituye TU_API_KEY por tu key real de Google
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$direccionEncoded}&key=TU_API_KEY";
    $resp_json = file_get_contents($url);
    if ($resp_json === false) {
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo conectar con geocodificación.'
        ]);
        exit();
    }
    $resp = json_decode($resp_json, true);
    if ($resp['status'] === 'OK') {
        $lat = $resp['results'][0]['geometry']['location']['lat'];
        $lng = $resp['results'][0]['geometry']['location']['lng'];
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error geocodificando: ' . htmlspecialchars($resp['status'])
        ]);
        exit();
    }
}

// 6. Insertar con todos los campos nuevos
$stmt_insert = $conn->prepare("
    INSERT INTO local (
        codigo,
        nombre,
        direccion,
        id_cuenta,
        id_cadena,
        id_comuna,
        id_empresa,
        id_division,   
        id_canal,
        id_subcanal,
        lat,
        lng,
        relevancia,
        id_zona,
        id_distrito,
        id_jefe_venta,
        id_vendedor
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)
");
if (!$stmt_insert) {
    echo json_encode([
        'success' => false,
        'message' => 'Error preparando inserción: ' . $conn->error
    ]);
    exit();
}

// 16 parámetros => "sssiiiiiiddiiii"
$stmt_insert->bind_param(
    "sssiiiiiiiddiiiii",
    $codigoLocal,
    $nombreLocal,
    $direccion,
    $cuenta_id,
    $cadena_id,
    $comuna_id,
    $empresa_id,
    $division_id, 
    $canal_id,
    $subcanal_id,
    $lat,
    $lng,
    $relevancia,
    $zona_id,
    $distrito_id,
    $jefe_venta_id,
    $vendedor_id
);

if ($stmt_insert->execute()) {
    echo json_encode([
        'success' => true,
        'message' => "Local '$nombreLocal' creado con coords ($lat, $lng)."
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error al insertar local: ' . $stmt_insert->error
    ]);
}
$stmt_insert->close();
$conn->close();

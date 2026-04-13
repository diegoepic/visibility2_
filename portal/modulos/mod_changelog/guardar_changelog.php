<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'ok' => false,
    'message' => 'No se pudo guardar el registro.'
];

$modulo = trim($_POST['modulo'] ?? '');
$id_tipo_gestion = (int)($_POST['id_tipo_gestion'] ?? 0);
$titulo = trim($_POST['titulo'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$usuario_registro = trim($_POST['usuario_registro'] ?? '');
$criticidad = trim($_POST['criticidad'] ?? 'media');
$estado = trim($_POST['estado'] ?? 'publicado');

$criticidades_validas = ['baja', 'media', 'alta'];
$estados_validos = ['publicado', 'borrador'];

if ($modulo === '' || $id_tipo_gestion <= 0 || $titulo === '' || $descripcion === '') {
    $response['message'] = 'Completa los campos obligatorios.';
    echo json_encode($response);
    exit;
}

if (!in_array($criticidad, $criticidades_validas, true)) {
    $criticidad = 'media';
}

if (!in_array($estado, $estados_validos, true)) {
    $estado = 'publicado';
}

/* Buscar nombre del tipo */
$stmtTipo = $conn->prepare("
    SELECT nombre
    FROM system_changelog_types
    WHERE id = ?
    LIMIT 1
");

if (!$stmtTipo) {
    $response['message'] = 'Error al preparar consulta de tipo: ' . $conn->error;
    echo json_encode($response);
    exit;
}

$stmtTipo->bind_param("i", $id_tipo_gestion);
$stmtTipo->execute();
$resultTipo = $stmtTipo->get_result();
$tipoRow = $resultTipo->fetch_assoc();
$stmtTipo->close();

if (!$tipoRow) {
    $response['message'] = 'No se encontró el tipo de gestión seleccionado.';
    echo json_encode($response);
    exit;
}

$tipo_gestion = trim($tipoRow['nombre']);

/*
    IMPORTANTE:
    Aquí asumo que tu tabla system_changelog tiene la columna tipo_gestion
    en vez de id_tipo_gestion
*/
$stmt = $conn->prepare("
    INSERT INTO system_changelog (
        modulo,
        tipo_gestion,
        titulo,
        descripcion,
        fecha_cambio,
        usuario_registro,
        criticidad,
        estado,
        created_at
    ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, NOW())
");

if (!$stmt) {
    $response['message'] = 'Error al preparar la consulta: ' . $conn->error;
    echo json_encode($response);
    exit;
}

$stmt->bind_param(
    "sssssss",
    $modulo,
    $tipo_gestion,
    $titulo,
    $descripcion,
    $usuario_registro,
    $criticidad,
    $estado
);

if ($stmt->execute()) {
    $response['ok'] = true;
    $response['message'] = 'Cambio registrado correctamente.';
} else {
    $response['message'] = 'Error al guardar en base de datos: ' . $stmt->error;
}

$stmt->close();

echo json_encode($response);
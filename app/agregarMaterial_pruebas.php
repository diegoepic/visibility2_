<?php
declare(strict_types=1);

// agregarMaterial_pruebas.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/con_.php';
// OJO: la sesión ya la abre _session_guard.php vía .user.ini (auto_prepend_file)
// NO llamar session_start() aquí para evitar warnings.

/*
 * Helper para responder JSON y cortar ejecución
 */
function json_error(string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'E_ADD_MATERIAL',
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success(array $data = []): void {
    http_response_code(200);
    echo json_encode(array_merge([
        'status' => 'success',
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------- Validaciones básicas --------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
    json_error('Sesión expirada. Vuelve a iniciar sesión.', 401);
}

$sessionCsrf = $_SESSION['csrf_token'] ?? '';
$postCsrf    = $_POST['csrf_token'] ?? '';

if (!$sessionCsrf || !$postCsrf || !hash_equals($sessionCsrf, $postCsrf)) {
    json_error('Token CSRF inválido.', 403);
}

$usuario_id  = (int) $_SESSION['usuario_id'];
$empresa_id  = (int) $_SESSION['empresa_id'];

$nombreMaterial = trim((string)($_POST['nombreMaterial'] ?? ''));
$valorPropuesto = trim((string)($_POST['valorImplementado'] ?? ''));
$idCampana      = (int) ($_POST['idCampana'] ?? 0);
$idLocal        = (int) ($_POST['idLocal'] ?? 0);
$division_id    = (int) ($_POST['division_id'] ?? 0);

if ($idCampana <= 0 || $idLocal <= 0 || $division_id <= 0) {
    json_error('Parámetros incompletos (campaña/local/división).');
}

if ($nombreMaterial === '') {
    json_error('Debes seleccionar un material.');
}

if ($valorPropuesto === '' || !preg_match('/^\d$/', $valorPropuesto)) {
    json_error('El valor debe ser un número entre 0 y 9.');
}

// ------- Validar que el ejecutor tiene permisos sobre la campaña/local -------
$sqlPerm = "
    SELECT 1
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local      l ON l.id = fq.id_local
    WHERE fq.id_formulario = ?
      AND fq.id_local      = ?
      AND fq.id_usuario    = ?
      AND f.id_empresa     = ?
    LIMIT 1
";
$stmtPerm = $conn->prepare($sqlPerm);
if (!$stmtPerm) {
    json_error('Error preparando validación de permisos: ' . $conn->error);
}
$stmtPerm->bind_param('iiii', $idCampana, $idLocal, $usuario_id, $empresa_id);
$stmtPerm->execute();
$resPerm = $stmtPerm->get_result();
if ($resPerm->num_rows === 0) {
    $stmtPerm->close();
    json_error('Sin permiso sobre este material/local o no existe.', 403);
}
$stmtPerm->close();

// ------- Resolver material (tabla material) -------
$sqlMat = "
    SELECT id, nombre, ref_image
    FROM material
    WHERE nombre = ?
      AND id_division = ?
    LIMIT 1
";
$stmtMat = $conn->prepare($sqlMat);
if (!$stmtMat) {
    json_error('Error preparando consulta de material: ' . $conn->error);
}
$stmtMat->bind_param('si', $nombreMaterial, $division_id);
$stmtMat->execute();
$resMat = $stmtMat->get_result();

$materialId = null;
$refImage   = '';

if ($rowMat = $resMat->fetch_assoc()) {
    $materialId = (int)$rowMat['id'];
    $refImage   = (string)($rowMat['ref_image'] ?? '');
}
$stmtMat->close();

// Si el material no existe en catálogo, NO lo creamos automáticamente
// (si quisieras crearlo, aquí haces el INSERT a `material` y actualizas $materialId/$refImage)

// ------- Buscar si ya existe un formularioQuestion para ese material --------
// Esto hace que la operación sea idempotente: si ya existe, devolvemos el mismo ID.
$sqlExist = "
    SELECT id, valor_propuesto, valor, observacion
    FROM formularioQuestion
    WHERE id_formulario = ?
      AND id_local      = ?
      AND id_usuario    = ?
      AND material      = ?
    LIMIT 1
";
$stmtExist = $conn->prepare($sqlExist);
if (!$stmtExist) {
    json_error('Error preparando búsqueda de material existente: ' . $conn->error);
}
$stmtExist->bind_param('iiis', $idCampana, $idLocal, $usuario_id, $nombreMaterial);
$stmtExist->execute();
$resExist = $stmtExist->get_result();

if ($rowFQ = $resExist->fetch_assoc()) {
    // Ya existe: devolvemos el existente
    $idNuevo = (int)$rowFQ['id'];
    $valorPropuestoExist = (string)$rowFQ['valor_propuesto'];

    $stmtExist->close();

    json_success([
        'idNuevo'    => $idNuevo,
        'nombre'     => $nombreMaterial,
        'valor_prop' => $valorPropuestoExist !== '' ? $valorPropuestoExist : $valorPropuesto,
        'ref_image'  => $refImage,
        'message'    => 'Material ya existía, se reutiliza el registro.',
    ]);
}
$stmtExist->close();

// ------- Insertar NUEVO registro en formularioQuestion -------
$sqlIns = "
    INSERT INTO formularioQuestion
        (id_formulario, id_usuario, id_local, material, valor_propuesto, valor, observacion, fechaVisita)
    VALUES
        (?, ?, ?, ?, ?, 0, '', NULL)
";
$stmtIns = $conn->prepare($sqlIns);
if (!$stmtIns) {
    json_error('Error preparando inserción de material: ' . $conn->error);
}
$stmtIns->bind_param('iiiss', $idCampana, $usuario_id, $idLocal, $nombreMaterial, $valorPropuesto);

if (!$stmtIns->execute()) {
    $stmtIns->close();
    json_error('No se pudo insertar el material: ' . $conn->error);
}

$idNuevo = $stmtIns->insert_id;
$stmtIns->close();

// ------- Respuesta OK --------
json_success([
    'idNuevo'    => $idNuevo,
    'nombre'     => $nombreMaterial,
    'valor_prop' => $valorPropuesto,
    'ref_image'  => $refImage,
    'message'    => 'Material resuelto correctamente',
]);



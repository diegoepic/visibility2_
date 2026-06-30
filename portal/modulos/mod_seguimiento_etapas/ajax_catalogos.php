<?php
declare(strict_types=1);
// ajax_catalogos.php — catálogos para los modales (ejecutores, materiales, locales) por campaña.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesión expirada']);
    exit;
}

$conn->set_charset('utf8mb4');
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$idForm     = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;

if ($idForm <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Falta id_formulario']);
    exit;
}

$stmtF = $conn->prepare("SELECT id_division FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1");
$stmtF->bind_param('ii', $idForm, $empresa_id);
$stmtF->execute();
$rowF = $stmtF->get_result()->fetch_assoc();
$stmtF->close();
if (!$rowF) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Campaña no encontrada']);
    exit;
}
$id_division = (int)$rowF['id_division'];

// Ejecutores (perfil 3) activos de la empresa.
$ejecutores = [];
$stmtE = $conn->prepare("
    SELECT id, TRIM(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellido,''))) AS nombre, usuario
    FROM usuario
    WHERE id_empresa = ? AND activo = 1 AND id_perfil = 3
    ORDER BY nombre, usuario
");
$stmtE->bind_param('i', $empresa_id);
$stmtE->execute();
$resE = $stmtE->get_result();
while ($e = $resE->fetch_assoc()) {
    $label = trim($e['nombre']) !== '' ? trim($e['nombre']) : (string)$e['usuario'];
    $ejecutores[] = ['id' => (int)$e['id'], 'nombre' => $label];
}
$stmtE->close();

// Materiales del catálogo de la división de la campaña.
$materiales = [];
if ($id_division > 0) {
    $stmtM = $conn->prepare("SELECT id, nombre FROM material WHERE id_division = ? ORDER BY nombre");
    $stmtM->bind_param('i', $id_division);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($m = $resM->fetch_assoc()) {
        $materiales[] = ['id' => (int)$m['id'], 'nombre' => (string)$m['nombre']];
    }
    $stmtM->close();
}

// Locales del catálogo de la empresa/división (para "agregar local").
$locales = [];
$stmtL = $conn->prepare("
    SELECT id, codigo, nombre
    FROM local
    WHERE id_empresa = ? AND (? = 0 OR id_division = ? OR id_division = 0) AND deleted_at IS NULL
    ORDER BY codigo
    LIMIT 5000
");
$stmtL->bind_param('iii', $empresa_id, $id_division, $id_division);
$stmtL->execute();
$resL = $stmtL->get_result();
while ($l = $resL->fetch_assoc()) {
    $locales[] = [
        'id'     => (int)$l['id'],
        'codigo' => (string)$l['codigo'],
        'nombre' => (string)$l['nombre'],
    ];
}
$stmtL->close();

echo json_encode([
    'ok'         => true,
    'ejecutores' => $ejecutores,
    'materiales' => $materiales,
    'locales'    => $locales,
], JSON_UNESCAPED_UNICODE);

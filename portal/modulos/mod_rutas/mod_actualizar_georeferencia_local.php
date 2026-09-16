<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

function geoFail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    geoFail('Método no permitido.', 405);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    geoFail('Solicitud inválida.');
}

$csrfSession = (string)($_SESSION['route_optimizer_csrf'] ?? '');
$csrfRequest = (string)($payload['csrf_token'] ?? '');
if ($csrfSession === '' || $csrfRequest === '' || !hash_equals($csrfSession, $csrfRequest)) {
    geoFail('La sesión expiró. Recarga la página e inténtalo nuevamente.', 403);
}

$idUsuario = (int)($_SESSION['usuario_id'] ?? 0);
$idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);
$divisionSesion = (int)($_SESSION['division_id'] ?? 0);
$idLocal = (int)($payload['id_local'] ?? 0);
$direccion = trim((string)($payload['direccion'] ?? ''));
$lat = filter_var($payload['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($payload['lng'] ?? null, FILTER_VALIDATE_FLOAT);

if ($idUsuario <= 0 || $idEmpresa <= 0) geoFail('Sesión inválida.', 403);
if ($idLocal <= 0) geoFail('El local no es válido.');
if ($direccion === '' || mb_strlen($direccion) > 500) geoFail('La dirección corregida no es válida.');
if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    geoFail('Las coordenadas obtenidas no son válidas.');
}

$stmt = $conn->prepare('SELECT UPPER(TRIM(nombre)) FROM division_empresa WHERE id=? AND id_empresa=? AND estado=1 LIMIT 1');
$stmt->bind_param('ii', $divisionSesion, $idEmpresa);
$stmt->execute();
$stmt->bind_result($divisionSesionNombre);
$stmt->fetch();
$stmt->close();
$esMc = strtoupper(trim((string)$divisionSesionNombre)) === 'MC';

$sql = "
    UPDATE local
    SET direccion_google = ?,
        lat = ?,
        lng = ?,
        estado_address_validation = 'VALIDADO_MANUAL',
        direccion_validada_at = NOW()
    WHERE id = ?
      AND id_empresa = ?
";
$types = 'sddii';
$params = [$direccion, $lat, $lng, $idLocal, $idEmpresa];

if (!$esMc) {
    $sql .= ' AND id_division = ?';
    $types .= 'i';
    $params[] = $divisionSesion;
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    $checkSql = 'SELECT id FROM local WHERE id=? AND id_empresa=?';
    $checkTypes = 'ii';
    $checkParams = [$idLocal, $idEmpresa];
    if (!$esMc) {
        $checkSql .= ' AND id_division=?';
        $checkTypes .= 'i';
        $checkParams[] = $divisionSesion;
    }
    $checkSql .= ' LIMIT 1';
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param($checkTypes, ...$checkParams);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) geoFail('El local no existe o no pertenece a tu empresa.', 404);
}

echo json_encode([
    'success' => true,
    'message' => 'Georreferencia actualizada correctamente.',
    'local' => [
        'id_local' => $idLocal,
        'direccion' => $direccion,
        'lat' => (float)$lat,
        'lng' => (float)$lng,
    ],
], JSON_UNESCAPED_UNICODE);

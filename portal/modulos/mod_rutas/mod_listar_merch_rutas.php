<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

function merchFail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$idUsuario = (int)($_SESSION['usuario_id'] ?? 0);
$idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);
$divisionSesion = (int)($_SESSION['division_id'] ?? 0);
$divisionSolicitada = (int)($_GET['id_division'] ?? 0);

if ($idUsuario <= 0 || $idEmpresa <= 0 || $divisionSolicitada <= 0) {
    merchFail('Sesión o división inválida.', 403);
}

$stmt = $conn->prepare('SELECT UPPER(TRIM(nombre)) FROM division_empresa WHERE id=? AND id_empresa=? AND estado=1 LIMIT 1');
$stmt->bind_param('ii', $divisionSesion, $idEmpresa);
$stmt->execute();
$stmt->bind_result($divisionSesionNombre);
$stmt->fetch();
$stmt->close();

$esMc = strtoupper(trim((string)$divisionSesionNombre)) === 'MC';
if (!$esMc && $divisionSolicitada !== $divisionSesion) {
    merchFail('No tienes acceso a los trabajadores de esa división.', 403);
}

$stmt = $conn->prepare("
    SELECT
        u.id,
        COALESCE(u.usuario, '') AS usuario,
        TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS nombre,
        COALESCE(u.clasificacion_usuario, '') AS clasificacion
    FROM usuario u
    INNER JOIN division_empresa d ON d.id = u.id_division AND d.id_empresa = ?
    WHERE u.activo = 1
      AND u.id_division = ?
    ORDER BY nombre ASC, u.usuario ASC
");
$stmt->bind_param('ii', $idEmpresa, $divisionSolicitada);
$stmt->execute();
$result = $stmt->get_result();
$trabajadores = [];

while ($row = $result->fetch_assoc()) {
    $trabajadores[] = [
        'id' => (int)$row['id'],
        'usuario' => (string)$row['usuario'],
        'nombre' => trim((string)$row['nombre']) ?: (string)$row['usuario'],
        'clasificacion' => (string)$row['clasificacion'],
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'trabajadores' => $trabajadores], JSON_UNESCAPED_UNICODE);


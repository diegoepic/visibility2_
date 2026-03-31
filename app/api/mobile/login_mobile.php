<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (
    preg_match('/^http:\/\/localhost:\d+$/', $origin) ||
    preg_match('/^http:\/\/127\.0\.0\.1:\d+$/', $origin) ||
    $origin === 'https://visibility.cl'
) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
$conn->set_charset('utf8mb4');

function respond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Método no permitido']);
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '{}', true);

if (!is_array($data)) {
    respond(400, ['ok' => false, 'message' => 'JSON inválido']);
}

$usuario = trim((string)($data['usuario'] ?? ''));
$clave   = (string)($data['clave'] ?? '');

if ($usuario === '' || $clave === '') {
    respond(422, ['ok' => false, 'message' => 'Debes ingresar usuario y contraseña']);
}

$sql = "
    SELECT 
        u.id,
        u.rut,
        u.nombre,
        u.apellido,
        u.email,
        u.usuario,
        u.fotoPerfil,
        u.clave,
        u.id_perfil,
        u.id_empresa,
        u.id_division,
        p.nombre AS perfil_nombre,
        e.nombre AS empresa_nombre
    FROM usuario u
    INNER JOIN perfil p ON p.id = u.id_perfil
    INNER JOIN empresa e ON e.id = u.id_empresa
    WHERE (u.rut = ? OR u.usuario = ?)
      AND u.activo = 1
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    respond(500, ['ok' => false, 'message' => 'Error preparando consulta']);
}

$stmt->bind_param("ss", $usuario, $usuario);

if (!$stmt->execute()) {
    $stmt->close();
    respond(500, ['ok' => false, 'message' => 'Error ejecutando consulta']);
}

$res = $stmt->get_result();

if ($res->num_rows !== 1) {
    $stmt->close();
    respond(401, ['ok' => false, 'message' => 'Usuario o contraseña incorrectos']);
}

$user = $res->fetch_assoc();
$stmt->close();

if (!password_verify($clave, $user['clave'] ?? '')) {
    respond(401, ['ok' => false, 'message' => 'Usuario o contraseña incorrectos']);
}

$userId = (int)$user['id'];

$updateStmt = $conn->prepare("
    UPDATE usuario
    SET login_count = COALESCE(login_count, 0) + 1,
        last_login = NOW()
    WHERE id = ?
");
if ($updateStmt) {
    $updateStmt->bind_param("i", $userId);
    $updateStmt->execute();
    $updateStmt->close();
}

$plainToken = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $plainToken);
$expiresAt  = date('Y-m-d H:i:s', strtotime('+30 days'));
$deviceName = trim((string)($data['device_name'] ?? 'flutter_app'));

$insToken = $conn->prepare("
    INSERT INTO api_mobile_tokens (
        user_id, token_hash, device_name, created_at, last_used_at, expires_at
    ) VALUES (?, ?, ?, NOW(), NOW(), ?)
");
if (!$insToken) {
    respond(500, ['ok' => false, 'message' => 'No se pudo generar token']);
}

$insToken->bind_param("isss", $userId, $tokenHash, $deviceName, $expiresAt);

if (!$insToken->execute()) {
    $insToken->close();
    respond(500, ['ok' => false, 'message' => 'No se pudo guardar el token']);
}

$insToken->close();

respond(200, [
    'ok' => true,
    'message' => 'Login correcto',
    'data' => [
        'token' => $plainToken,
        'expires_at' => $expiresAt,
        'user' => [
            'id' => (int)$user['id'],
            'rut' => (string)$user['rut'],
            'nombre' => (string)$user['nombre'],
            'apellido' => (string)$user['apellido'],
            'nombre_completo' => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
            'email' => (string)$user['email'],
            'usuario' => (string)$user['usuario'],
            'foto_perfil' => (string)$user['fotoPerfil'],
            'perfil_id' => (int)$user['id_perfil'],
            'perfil_nombre' => (string)$user['perfil_nombre'],
            'empresa_id' => (int)$user['id_empresa'],
            'empresa_nombre' => (string)$user['empresa_nombre'],
            'division_id' => isset($user['id_division']) ? (int)$user['id_division'] : 0
        ]
    ]
]);
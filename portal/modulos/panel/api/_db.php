<?php
// panel/api/_db.php
declare(strict_types=1);
session_start();
header('X-Content-Type-Options: nosniff');

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'visibility_visibility2';

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// *** En producción, habilita este bloqueo ***
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['empresa_id'])) {
    // http_response_code(401);
    // echo json_encode(['error' => 'No autorizado']);
    // exit;
}

function jraw(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    if ($raw && ($j = json_decode($raw, true)) && json_last_error() === JSON_ERROR_NONE) {
        $cache = $j;
        return $j;
    }
    $cache = [];
    return $cache;
}
function jread(string $key, $default = null) {
    $j = jraw();
    if (array_key_exists($key, $j)) return $j[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}
function ints(array $arr): array {
    $out = [];
    foreach ($arr as $v) {
        $i = intval($v);
        if ($i > 0) $out[] = $i;
    }
    return array_values(array_unique($out));
}
function inClause(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}
function bindMany(mysqli_stmt $stmt, string $types, array $values) {
    if ($types === '' || !$values) return;
    $bind = [];
    $bind[] = &$types;
    foreach ($values as $k => $v) $bind[] = &$values[$k];
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
function ok($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code=400) {
    http_response_code($code);
    ok(['error'=>$msg]);
}

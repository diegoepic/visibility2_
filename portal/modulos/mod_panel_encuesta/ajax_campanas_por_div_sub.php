<?php
// Asegurar sesión activa antes de usar $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Sesión expirada'], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=UTF-8');

// Conexi��n
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

// Par��metros
$division    = (int)($_GET['division'] ?? 0);
$subdivision = (int)($_GET['subdivision'] ?? 0);
$tipo        = (int)($_GET['tipo'] ?? 0); // 0=1+3; 1 �� 3 = filtro exacto

// En no-MC, el usuario s��lo ve su divisi��n (ignoramos la que venga por GET)
if (!$is_mc) {
  $division = $user_div;
}

// Base query - excluir campañas eliminadas
$sql   = "SELECT id, nombre FROM formulario WHERE id_empresa = ? AND deleted_at IS NULL";
$types = 'i';
$params = [$empresa_id];

// Filtros de ��mbito
if ($division > 0)    { $sql .= " AND id_division=?";    $types .= 'i'; $params[] = $division; }
if ($subdivision > 0) { $sql .= " AND id_subdivision=?"; $types .= 'i'; $params[] = $subdivision; }

// Filtro por clase de formulario (solo 1 y 3 por requerimiento)
if (in_array($tipo, [1,3], true)) {
  $sql .= " AND tipo=?";
  $types .= 'i'; $params[] = $tipo;
} else {
  $sql .= " AND tipo IN (1,3)";
}

// Orden: m��s recientes primero (usa fechaInicio si existe)
$sql .= " ORDER BY fechaInicio DESC, id DESC";

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$rs = $st->get_result();

$out = [];
while ($r = $rs->fetch_assoc()) {
  // salida directa, tal como espera el front (id, nombre)
  $out[] = $r;
}
$st->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

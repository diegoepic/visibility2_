<?php
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); header('Content-Type: text/plain; charset=UTF-8'); exit("Sesión expirada"); }

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=UTF-8');

// Conexión
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

// Parámetros
$division = (int)($_GET['division'] ?? 0);

// En no-MC, el usuario solo puede consultar su propia división
if (!$is_mc) {
  $division = $user_div;
}

if ($division <= 0) {
  echo json_encode([]);
  exit;
}

/*
  Endurecemos el ámbito: la división debe pertenecer a la empresa del usuario.
  Esto evita que pasando un id de división de otra empresa se filtren datos.
*/
$sql = "
  SELECT s.id, s.nombre
    FROM subdivision s
    JOIN division_empresa de ON de.id = s.id_division
   WHERE s.id_division = ?
     AND de.id_empresa = ?
   ORDER BY s.nombre
";
$st = $conn->prepare($sql);
$st->bind_param('ii', $division, $empresa_id);
$st->execute();
$rs = $st->get_result();

$out = [];
while ($r = $rs->fetch_assoc()) { $out[] = $r; }
$st->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); header('Content-Type: text/plain; charset=UTF-8'); exit("Sesi¨®n expirada"); }

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=UTF-8');

// Conexi¨®n
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$user_div   = (int)($_SESSION['division_id'] ?? 0);
$is_mc      = ($user_div === 1);

// Par¨¢metro
$div = (int)($_GET['division'] ?? 0);

// Si NO es MC, solo puede consultar su divisi¨®n
if (!$is_mc) {
  $div = $user_div;
}

if ($div <= 0) { echo json_encode([]); exit; }

$sql = "
  SELECT DISTINCT jv.id, jv.nombre
    FROM local l
    JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
   WHERE l.id_division = ?
     AND l.id_empresa  = ?
     AND jv.id IS NOT NULL
   ORDER BY jv.nombre
";
$st = $conn->prepare($sql);
$st->bind_param("ii", $div, $empresa_id);
$st->execute();
$rs = $st->get_result();

$out = [];
while ($r = $rs->fetch_assoc()) { $out[] = $r; }
$st->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

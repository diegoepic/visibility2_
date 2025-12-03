<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---- Seguridad básica ----
if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
  http_response_code(401);
  echo json_encode(['status' => 'error', 'message' => 'Sesión no iniciada']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['status' => 'error', 'message' => 'Método inválido']);
  exit;
}

// ---- Entrada ----
$idCampana = isset($_GET['idCampana']) ? (int)$_GET['idCampana'] : 0;
$qRaw      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

if ($idCampana <= 0) {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido']);
  exit;
}

// Evita consultas vacías o de 1 carácter (ruidosas)
if (mb_strlen($qRaw, 'UTF-8') < 2) {
  echo json_encode(['status' => 'success', 'locales' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

$empresaId = (int)$_SESSION['empresa_id'];

// ---- Validar campaña IW (solo empresa; ya NO filtramos por división) ----
$stmt = $conn->prepare("
  SELECT id_empresa
  FROM formulario
  WHERE id = ? AND tipo = 2
  LIMIT 1
");
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$stmt->bind_result($empresaCamp);
$ok = $stmt->fetch();
$stmt->close();

if (!$ok) {
  http_response_code(404);
  echo json_encode(['status' => 'error', 'message' => 'Campaña IW no encontrada']);
  exit;
}

if ((int)$empresaCamp !== $empresaId) {
  http_response_code(403);
  echo json_encode(['status' => 'error', 'message' => 'Sin permiso para esta campaña']);
  exit;
}

// ---- Normalización de búsqueda: tokens + escape LIKE ----
function like_escape(string $s): string {
  return strtr($s, ['%' => '\%', '_' => '\_']);
}

$tokens = preg_split('/\s+/u', $qRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$tokens = array_values(array_filter($tokens, function($t){ return mb_strlen($t,'UTF-8') >= 2; }));
if (empty($tokens)) {
  echo json_encode(['status' => 'success', 'locales' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- Consulta: todos los locales de la empresa (sin filtrar por división de campaña) ----
$sql = "
  SELECT
    l.id,
    l.codigo,
    l.nombre,
    l.direccion,
    l.id_division,
    COALESCE(d.nombre, CONCAT('División #', l.id_division)) AS division_nombre
  FROM local l
  LEFT JOIN division_empresa d
    ON d.id = l.id_division AND d.id_empresa = l.id_empresa
  WHERE l.id_empresa = ?
";
$types  = "i";
$params = [$empresaId];

// AND entre tokens; OR entre (codigo, nombre, direccion)
foreach ($tokens as $tok) {
  $like = '%' . like_escape($tok) . '%';
  $sql .= "
    AND (
           l.codigo    LIKE ? ESCAPE '\\\\'
        OR l.nombre    LIKE ? ESCAPE '\\\\'
        OR l.direccion LIKE ? ESCAPE '\\\\'
    )
  ";
  $types  .= "sss";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= "
  ORDER BY l.relevancia DESC, l.nombre ASC
  LIMIT 25
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'Error interno (prepare)']);
  exit;
}

// bind_param dinámico
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$locales = [];
while ($row = $res->fetch_assoc()) {
  $codigo    = (string)($row['codigo'] ?? '');
  $nombre    = (string)($row['nombre'] ?? '');
  $direccion = (string)($row['direccion'] ?? '');
  $divNom    = (string)($row['division_nombre'] ?? 'Sin división');

  // etiqueta amigable + label de división (sin tocar el JS actual)
  // Ej: "615107 - ALVI · AVENIDA ... · [División: Mayorista]"
  $etiqueta = $codigo !== '' ? ($codigo . ' - ' . $nombre) : $nombre;
  if ($direccion !== '') {
    $etiqueta .= ' · ' . $direccion;
  }
  $etiqueta .= ' · [División: ' . $divNom . ']';

  $locales[] = [
    'id'               => (int)$row['id'],
    'codigo'           => $codigo,
    'nombre'           => $nombre,
    'direccion'        => $direccion,
    'division_id'      => isset($row['id_division']) ? (int)$row['id_division'] : null,
    'division_nombre'  => $divNom,
    'etiqueta'         => $etiqueta,
  ];
}
$stmt->close();

echo json_encode(['status' => 'success', 'locales' => $locales], JSON_UNESCAPED_UNICODE);

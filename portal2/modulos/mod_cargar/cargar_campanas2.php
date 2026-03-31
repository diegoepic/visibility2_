<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

header('Content-Type: application/json; charset=utf-8');

$id_empresa   = intval($_GET['id_empresa'] ?? 0);
$id_division  = intval($_GET['id_division'] ?? 0);
$id_subdiv    = intval($_GET['id_subdivision'] ?? 0);
$id_estado    = intval($_GET['estado'] ?? 0);        // 0 = ambos
$tipo_gestion = intval($_GET['tipo_gestion'] ?? 0);  // 1=Campaña, 3=Ruta, 0=Todas

if ($id_empresa === 0 || $id_division === 0) {
    echo json_encode(['ok' => false, 'msg' => 'Faltan parámetros']);
    exit;
}

$sql = "
    SELECT f.id, f.nombre
    FROM formulario f
    WHERE f.id_empresa = ?
      AND f.id_division = ?
";

$params = [$id_empresa, $id_division];
$types  = "ii";

/*
|--------------------------------------------------------------------------
| ESTADO
| 0 = AMBOS => NO filtra por estado
| 1 = EN CURSO
| 3 = FINALIZADAS
|--------------------------------------------------------------------------
*/
if ($id_estado > 0) {
    $sql .= " AND f.estado = ? ";
    $params[] = $id_estado;
    $types   .= "i";
}

/*
|--------------------------------------------------------------------------
| TIPO DE GESTIÓN
| 0 = TODAS => NO filtra por tipo
| 1 = CAMPAÑA
| 3 = RUTA
|--------------------------------------------------------------------------
*/
if ($tipo_gestion > 0) {
    $sql .= " AND f.tipo = ? ";
    $params[] = $tipo_gestion;
    $types   .= "i";
}

/*
|--------------------------------------------------------------------------
| SUBDIVISIÓN
|--------------------------------------------------------------------------
*/
if ($id_subdiv === -1) {
    $sql .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0)";
} elseif ($id_subdiv > 0) {
    $sql .= " AND f.id_subdivision = ?";
    $params[] = $id_subdiv;
    $types   .= "i";
}

$sql .= " ORDER BY COALESCE(f.fechaInicio, '1970-01-01') DESC, f.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al preparar consulta',
        'error' => $conn->error
    ]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$campanas = [];
while ($r = $res->fetch_assoc()) {
    $campanas[] = $r;
}

$stmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'campanas' => $campanas
]);
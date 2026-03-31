<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Validar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([]);
    exit;
}

$id_empresa = intval($_SESSION['empresa_id']);

if (!isset($_GET['id_ejecutor'])) {
    echo json_encode([]);
    exit;
}
$id_ejecutor = intval($_GET['id_ejecutor']);

// Capturar filtros
$filter_fecha = isset($_GET['fechaPropuesta']) ? trim($_GET['fechaPropuesta']) : ''; // formato "YYYY-MM-DD"
$filter_mes   = isset($_GET['mesPropuesta']) ? trim($_GET['mesPropuesta']) : '';       // formato "YYYY-MM"
$filter_anio  = isset($_GET['anioPropuesta']) ? trim($_GET['anioPropuesta']) : '';     // formato "YYYY"

// Si se selecciona un mes o un año, forzamos que filter_fecha quede vacío
if (!empty($filter_mes) || !empty($filter_anio)) {
    $filter_fecha = '';
}

// Extraer años disponibles para el usuario
$anios = [];
$sql_anios = "SELECT DISTINCT DATE_FORMAT(fq.fechaPropuesta, '%Y') AS anio
              FROM formularioQuestion fq
              INNER JOIN formulario f ON f.id = fq.id_formulario
              WHERE f.id_empresa = ? AND fq.id_usuario = ?
              AND fq.fechaPropuesta IS NOT NULL
              ORDER BY anio DESC";
$stmt_anios = $conn->prepare($sql_anios);
$stmt_anios->bind_param("ii", $id_empresa, $id_ejecutor);
$stmt_anios->execute();
$res_anios = $stmt_anios->get_result();
while ($row = $res_anios->fetch_assoc()) {
    $anios[] = $row['anio'];
}
$stmt_anios->close();

// Extraer meses disponibles (formato YYYY-MM) para el usuario
$meses = [];
// Opcionalmente, si se selecciona un año, filtrar por ese año
$sql_meses = "SELECT DISTINCT DATE_FORMAT(fq.fechaPropuesta, '%Y-%m') AS mes
              FROM formularioQuestion fq
              INNER JOIN formulario f ON f.id = fq.id_formulario
              WHERE f.id_empresa = ? 
                AND fq.id_usuario = ? 
                AND fq.fechaPropuesta IS NOT NULL";
$params = [$id_empresa, $id_ejecutor];
$types = "ii";
if (!empty($filter_anio)) {
    $sql_meses .= " AND DATE_FORMAT(fq.fechaPropuesta, '%Y') = ?";
    $params[] = $filter_anio;
    $types .= "s";
}
$sql_meses .= " ORDER BY mes DESC";
$stmt_meses = $conn->prepare($sql_meses);
$stmt_meses->bind_param($types, ...$params);
$stmt_meses->execute();
$res_meses = $stmt_meses->get_result();
while ($row = $res_meses->fetch_assoc()) {
    $meses[] = $row['mes'];
}
$stmt_meses->close();

// Extraer fechas exactas (formato YYYY-MM-DD) para el usuario
$fechas = [];
$sql_fechas = "SELECT DISTINCT DATE(fq.fechaPropuesta) AS fecha
               FROM formularioQuestion fq
               INNER JOIN formulario f ON f.id = fq.id_formulario
               AND fq.fechaPropuesta IS NOT NULL
               WHERE f.id_empresa = ? AND fq.id_usuario = ?";
$params = [$id_empresa, $id_ejecutor];
$types = "ii";
// Si se selecciona un mes, limitar las fechas a ese mes
if (!empty($filter_mes)) {
    $sql_fechas .= " AND DATE_FORMAT(fq.fechaPropuesta, '%Y-%m') = ?";
    $params[] = $filter_mes;
    $types .= "s";
} elseif (!empty($filter_anio)) {
    $sql_fechas .= " AND DATE_FORMAT(fq.fechaPropuesta, '%Y') = ?";
    $params[] = $filter_anio;
    $types .= "s";
}
$sql_fechas .= " ORDER BY fecha DESC";
$stmt_fechas = $conn->prepare($sql_fechas);
$stmt_fechas->bind_param($types, ...$params);
$stmt_fechas->execute();
$res_fechas = $stmt_fechas->get_result();
while ($row = $res_fechas->fetch_assoc()) {
    $fechas[] = $row['fecha'];
}
$stmt_fechas->close();

$conn->close();

echo json_encode([
    'anios'  => $anios,
    'meses'  => $meses,
    'fechas' => $fechas
]);
?>
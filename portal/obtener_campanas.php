<?php
// obtener_campanas.php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Asegurarse de que la variable $es_mentecreativa esté definida
if (!isset($es_mentecreativa)) {
    $es_mentecreativa = false;
}

// Recibir parámetros de filtrado
$empresa  = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$division = isset($_GET['division']) ? intval($_GET['division']) : 0;

// Construir filtros y parámetros según la lógica de usuarios
$filtros = " WHERE f.tipo = 1";
$params = [];
$tipos  = "";

if ($empresa > 0) {
    $filtros .= " AND f.id_empresa = ?";
    $params[] = $empresa;
    $tipos  .= "i";
} elseif (!$es_mentecreativa) {
    // Para usuarios no "Mentecreativa", se filtra siempre por su empresa
    $filtros .= " AND f.id_empresa = ?";
    $params[] = $_SESSION['empresa_id'];
    $tipos  .= "i";
}

if ($division > 0) {
    $filtros .= " AND f.id_division = ?";
    $params[] = $division;
    $tipos  .= "i";
}

// Consulta SQL (ajusta los campos y agregaciones según tu estructura real)
$query = "
    SELECT 
        f.id,
        f.nombre,
        f.fechaInicio,
        f.fechaTermino,
        e.nombre AS nombre_empresa,
        COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita))) AS locales_programados,
        COUNT(DISTINCT CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria') THEN CONCAT(l.codigo, fq.fechaVisita) END) AS locales_visitados,
        COUNT(DISTINCT CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria') THEN CONCAT(l.codigo, fq.fechaVisita) END) AS locales_implementados,
        ROUND((COUNT(DISTINCT CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','cancelado') THEN CONCAT(l.codigo, fq.fechaVisita) END) / COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita)))) * 100) AS porcentaje_visitado,
        ROUND((COUNT(DISTINCT CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria') THEN CONCAT(l.codigo, fq.fechaVisita) END) / COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita)))) * 100) AS porcentaje_completado,
        f.estado
    FROM formulario f
    INNER JOIN empresa AS e ON e.id = f.id_empresa
    INNER JOIN formularioQuestion AS fq ON fq.id_formulario = f.id
    INNER JOIN local AS l ON l.id = fq.id_local
    $filtros
    GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre, f.estado
";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => 'Error en la preparación de la consulta.']);
    exit();
}

if (!empty($params)) {
    $stmt->bind_param($tipos, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($data);
exit();
?>

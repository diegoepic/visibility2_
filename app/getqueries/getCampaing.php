<?php
session_start();
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);
$cache_key = 'campanas_' . $usuario_id . '_' . $empresa_id;

// Si existe cache, la retornamos
if (function_exists('apcu_fetch') && $cached = apcu_fetch($cache_key)) {
    echo json_encode($cached);
    exit;
}

$sql_campaigns = "
    SELECT DISTINCT 
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado,
        f.fechaInicio,
        f.fechaTermino
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND fq.estado = 0
      AND f.tipo = 1
    ORDER BY f.fechaInicio DESC
";
$stmt = $conn->prepare($sql_campaigns);
if ($stmt === false) {
    echo json_encode(['error' => 'Error en la preparación de la sentencia de campañas.']);
    exit;
}
$stmt->bind_param('ii', $usuario_id, $empresa_id);
$stmt->execute();
$result = $stmt->get_result();

$campanas = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $campanas[] = [
            'id_campana'     => (int)$row['id_campana'],
            'nombre_campana' => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
            'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'),
            'fechaInicio'    => date('d-m-Y', strtotime($row['fechaInicio'])),
            'fechaTermino'   => date('d-m-Y', strtotime($row['fechaTermino']))
        ];
    }
}
$stmt->close();

// Almacenar en cache durante 300 segundos (5 minutos)
if (function_exists('apcu_store')) {
    apcu_store($cache_key, $campanas, 300);
}
echo json_encode($campanas);
?>

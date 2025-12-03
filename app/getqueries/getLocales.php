<?php
session_start();
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';

$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);
$cache_key = "locales_{$usuario_id}_{$empresa_id}";

if (function_exists('apcu_fetch') && ($data = apcu_fetch($cache_key)) !== false) {
    echo json_encode($data);
    exit;
}

$sql = "
    SELECT 
        l.codigo AS codigoLocal,
        c.nombre AS cadena,
        l.direccion AS direccionLocal,
        l.nombre AS nombreLocal,
        IFNULL(v.nombre_vendedor, '') AS vendedor,
        l.id AS idLocal,
        l.lat AS latitud,
        l.lng AS longitud,
        COUNT(DISTINCT f.id) AS totalCampanas,
        GROUP_CONCAT(DISTINCT f.id) AS campanasIds,
        SUM(CASE WHEN fq.estado = 0 THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN fq.estado = 1 THEN 1 ELSE 0 END) AS completados,
        SUM(CASE WHEN fq.estado = 2 THEN 1 ELSE 0 END) AS cancelados,
        MAX(fq.is_priority) AS is_priority
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local l ON l.id = fq.id_local
    INNER JOIN cadena c ON c.id = l.id_cadena
    INNER JOIN vendedor v ON v.id = l.id_vendedor
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND f.tipo = 1
    GROUP BY l.codigo, c.nombre, l.direccion, l.nombre, l.id, l.lat, l.lng, v.nombre_vendedor
    HAVING MAX(fq.countVisita) = 0
    ORDER BY c.nombre, l.direccion
";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['error' => 'Error en la consulta de locales: ' . htmlspecialchars($conn->error)]);
    exit;
}
$stmt->bind_param('ii', $usuario_id, $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
$locales = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $pend = (int)$row['pendientes'];
        $comp = (int)$row['completados'];
        $canc = (int)$row['cancelados'];
        $total = $pend + $comp + $canc;
        $priority = (int)$row['is_priority'];
        $markerColor = 'red';
        if ($priority === 1) {
            $markerColor = 'blue';
        } else {
            if ($pend == $total) {
                $markerColor = 'red';
            } elseif ($pend > 0) {
                $markerColor = 'orange';
            } else {
                if ($comp > 0 && $canc == 0) {
                    $markerColor = 'green';
                } else {
                    $markerColor = 'grey';
                }
            }
        }
        $row['campanasIds'] = explode(',', $row['campanasIds']);
        $locales[] = [
            'codigoLocal'    => htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'),
            'cadena'         => htmlspecialchars($row['cadena'], ENT_QUOTES, 'UTF-8'),
            'direccionLocal' => htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8'),
            'nombreLocal'    => htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8'),
            'vendedor'       => htmlspecialchars($row['vendedor'], ENT_QUOTES, 'UTF-8'),
            'idLocal'        => (int)$row['idLocal'],
            'latitud'        => (float)$row['latitud'],
            'longitud'       => (float)$row['longitud'],
            'totalCampanas'  => (int)$row['totalCampanas'],
            'campanasIds'    => $row['campanasIds'],
            'visitado'       => false,
            'markerColor'    => $markerColor,
            'is_priority'    => $priority
        ];
    }
}
$stmt->close();
if (function_exists('apcu_store')) {
    apcu_store($cache_key, $locales, 300);
}
echo json_encode($locales);
?>

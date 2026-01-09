<?php

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session_data.php';
require_once __DIR__ . '/visitas_helpers.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "Acceso denegado.";
    exit;
}

$formulario_id = isset($_GET['formulario_id']) ? intval($_GET['formulario_id']) : 0;
if ($formulario_id <= 0) {
    http_response_code(400);
    echo "Parámetros inválidos.";
    exit;
}

$filters = [
    'formulario_id' => $formulario_id,
    'usuario_id' => isset($_GET['visita_usuario']) ? intval($_GET['visita_usuario']) : 0,
    'fecha_desde' => isset($_GET['visita_desde']) ? trim($_GET['visita_desde']) : '',
    'fecha_hasta' => isset($_GET['visita_hasta']) ? trim($_GET['visita_hasta']) : '',
];

if (!empty($filters['fecha_desde'])) {
    $filters['fecha_desde'] = $filters['fecha_desde'] . ' 00:00:00';
}
if (!empty($filters['fecha_hasta'])) {
    $filters['fecha_hasta'] = $filters['fecha_hasta'] . ' 23:59:59';
}

$visitas = obtenerVisitasExportar($conn, $filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=visitas_formulario_' . $formulario_id . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, [
    'ID Visita',
    'Fecha Inicio',
    'Fecha Fin',
    'Código Local',
    'Cadena',
    'Local',
    'Dirección',
    'Usuario',
    'Estado',
]);

foreach ($visitas as $v) {
    fputcsv($output, [
        $v['id'],
        $v['fecha_inicio'],
        $v['fecha_fin'],
        $v['codigo'],
        $v['cadena'],
        $v['local_nombre'],
        $v['direccion'],
        $v['usuario'],
        $v['estado_visita'] ?: $v['estado'],
    ]);
}

fclose($output);
exit;

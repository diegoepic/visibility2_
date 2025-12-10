<?php
// mod_local/obtener_comunas_por_region.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// No mostrar warnings/notices en JSON
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../db.php';

$region_id = isset($_GET['region_id']) && is_numeric($_GET['region_id'])
    ? (int) $_GET['region_id']
    : 0;

if ($region_id <= 0) {
    echo json_encode([
        'success' => false,
        'data'    => [],
        'message' => 'Parámetro region_id inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$comunas = obtenerComunasPorRegion($region_id);

// La función puede devolver false en error; normaliza a array vacío y marca error
if ($comunas === false) {
    echo json_encode([
        'success' => false,
        'data'    => [],
        'message' => 'Error al obtener las comunas.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Éxito (aunque venga vacío)
echo json_encode([
    'success' => true,
    'data'    => $comunas
], JSON_UNESCAPED_UNICODE);
exit;

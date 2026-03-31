<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'report_functions.php';

// Leer parámetros base
$division = isset($_GET['id_division']) ? (int)$_GET['id_division'] : 0;
$year     = (isset($_GET['year']) && $_GET['year'] !== '') ? (int)$_GET['year'] : null;

// Normalizar id_subdivision: soporta id_subdivision[] (array), CSV o único número
function normalizeSubdivisionParamLocal(): array {
    if (!isset($_GET['id_subdivision'])) {
        return []; // sin parámetro => TODAS
    }

    $raw = $_GET['id_subdivision'];
    $vals = is_array($raw)
        ? $raw
        : (strpos((string)$raw, ',') !== false ? explode(',', (string)$raw) : [$raw]);

    // Sanitizar: int > 0 y únicos
    return array_values(
        array_unique(
            array_filter(array_map('intval', $vals), fn($n) => $n > 0)
        )
    );
}

$subs = normalizeSubdivisionParamLocal();

// Validación mínima
if ($division <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de división inválido.']);
    exit();
}

// Ejecutar (si $subs queda vacío => TODAS las subdivisiones)
getMaterialPivot($division, $subs, $year);

<?php
// descargar_plantilla_locales.php

// Definir los encabezados del CSV
$coordinateMode = strtolower(trim((string)($_GET['modo_coordenadas'] ?? 'google')));
$useClientCoordinates = $coordinateMode === 'cliente';
$encabezados = [
    "codigo", "canal", "subcanal", "cuenta", "cadena",
    "nombre local", "direccion", "comuna", "nombre vendedor", "jefe de venta"
];
if ($useClientCoordinates) {
    $encabezados[] = 'latitud';
    $encabezados[] = 'longitud';
}

// Configurar las cabeceras para la descarga
header('Content-Type: text/csv; charset=utf-8');
$filename = $useClientCoordinates
    ? 'plantilla_carga_masiva_locales_con_coordenadas.csv'
    : 'plantilla_carga_masiva_locales.csv';
header('Content-Disposition: attachment; filename=' . $filename);

// Abrir la salida estándar como archivo
$output = fopen('php://output', 'w');

// Escribir los encabezados en el CSV
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $encabezados, ';', '"', '');

// Cerrar el recurso de salida
fclose($output);
exit();
?>

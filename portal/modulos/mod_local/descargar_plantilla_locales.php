<?php
// descargar_plantilla_locales.php

// Definir los encabezados del CSV
$encabezados = [
    "codigo", "canal", "subcanal", "cuenta", "cadena",
    "nombre local", "direccion", "comuna", "nombre vendedor", "jefe de venta"
];

// Configurar las cabeceras para la descarga
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=plantilla_carga_masiva_locales.csv');

// Abrir la salida estándar como archivo
$output = fopen('php://output', 'w');

// Escribir los encabezados en el CSV
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $encabezados, ';');

// Cerrar el recurso de salida
fclose($output);
exit();
?>

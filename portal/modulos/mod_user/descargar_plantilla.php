<?php
// descargar_ejemplo_csv.php

// Establecer las cabeceras para la descarga del archivo CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=usuarios_ejemplo.csv');

// Crear un "archivo" de salida
$output = fopen('php://output', 'w');

// Agregar los encabezados
fputcsv($output, ['rut', 'nombre', 'apellido', 'telefono', 'email', 'usuario', 'password']);

// Agregar filas de ejemplo
fputcsv($output, ['12345678-5', 'Juan', 'Perez', '912345678', 'jperez@example.com', 'jperez', '2435']);
fputcsv($output, ['87654321-K', 'Ana', 'Gomez', '987654321', 'agomez@example.com', 'agomez', '2435']);

// Cerrar el "archivo"
fclose($output);
exit();
?>

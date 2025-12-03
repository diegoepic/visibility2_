<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Precargando scripts vía web con opcache_compile_file...</h3>";

$archivos = [
    'app/gestionar.php',
    'app/login.php',
    'portal/index.php',
    'portal/modulos/mod_galeria.php',
    'app/getqueries/getLocales.php',
    'app/procesar_gestion.php',
    'app/eliminarMaterial.php',
];

$root = __DIR__ . '/';
$precargados = 0;

foreach ($archivos as $ruta) {
    $fullPath = $root . $ruta;
    if (file_exists($fullPath)) {
        if (opcache_compile_file($fullPath)) {
            echo "recargado: <code>$ruta</code><br>";
            $precargados++;
        } else {
            echo "Falló al precargar: <code>$ruta</code><br>";
        }
    } else {
        echo " encontrado: <code>$ruta</code><br>";
    }
}

echo "<br><strong>Total precargados:</strong> $precargados";

<?php
header('Content-Type: text/html; charset=utf-8');

if (!function_exists('opcache_get_status')) {
    echo "<b>❌ OPcache no está habilitado o no tienes permisos para ver el estado.</b>";
    exit;
}

$status = opcache_get_status(true);
$config = opcache_get_configuration();


echo "<pre>";
echo "Total cacheados reportados: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";

echo "Scripts visibles: " . count($status['scripts']) . "\n";
print_r(array_slice($status['scripts'], 0, 5)); // Intenta mostrar algunos scripts
echo "</pre>";



echo "<h2>📦 OPcache Status</h2>";

echo "<pre>";
echo "OPcache Enabled: " . ($status['opcache_enabled'] ? '✅ Yes' : '❌ No') . "\n";

$totalMem = $status['memory_usage']['total_memory'] ?? 0;
$usedMem  = $status['memory_usage']['used_memory'] ?? 0;

echo "Memory Usage: " . number_format($usedMem / 1024 / 1024, 2) . " MB / " .
                     number_format($totalMem / 1024 / 1024, 2) . " MB\n";

echo "Cached Scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
echo "Cache Hits: " . ($status['opcache_statistics']['hits'] ?? 0) . "\n";
echo "Cache Misses: " . ($status['opcache_statistics']['misses'] ?? 0) . "\n";
echo "Blacklist Filename: " . ($config['blacklist_filename'] ?? 'none') . "\n";
echo "</pre>";

echo "<h3>📄 Cached Files</h3>";

if (!empty($status['scripts'])) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>#</th><th>Script Filename</th><th>Hits</th><th>Last Used</th></tr>";
    $i = 1;
    foreach ($status['scripts'] as $script) {
        $path  = htmlspecialchars($script['full_path']);
        $hits  = $script['hits'];
        $last  = date('Y-m-d H:i:s', $script['last_used_timestamp']);
        echo "<tr><td>{$i}</td><td>{$path}</td><td>{$hits}</td><td>{$last}</td></tr>";
        $i++;
    }
    echo "</table>";
} else {
    echo "<p>No hay scripts cacheados todavía. Accede a las páginas PHP desde el navegador para que se cacheen.</p>";
}


echo "<h3>🔎 Verificación directa con opcache_is_script_cached()</h3>";
$verificar = [
    'app/gestionar.php',
    'app/login.php',
    'portal/index.php',
    'app/procesar_gestion.php',
];

foreach ($verificar as $rel) {
    $full = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/' . $rel;
    $ok = opcache_is_script_cached($full) ? '✅ Cacheado' : '❌ NO';
    echo "$rel → $ok<br>";
}
?>

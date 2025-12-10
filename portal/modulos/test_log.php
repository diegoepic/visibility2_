<?php
$logfile = __DIR__ . '/debug_reset.log';
$bytes = file_put_contents($logfile, "[" . date('Y-m-d H:i:s') . "] Test de escritura\n", FILE_APPEND);

if ($bytes === false) {
    echo "❌ No se pudo escribir en el log ($logfile)";
} else {
    echo "✅ Escribió $bytes bytes en $logfile";
}
?>
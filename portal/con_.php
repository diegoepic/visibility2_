<?php
declare(strict_types=1);
// Crear conexión solo si no existe
if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);

    $rootDir = dirname(__DIR__);
    $config = [];
    $localConfig = $rootDir . '/config/local.php';
    if (is_file($localConfig)) {
        $loaded = require $localConfig;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }
    $db = $config['db'] ?? [];

    $dbHost = $db['host'] ?? (getenv('VISIBILITY_DB_HOST') ?: 'localhost');
    $dbUser = $db['user'] ?? (getenv('VISIBILITY_DB_USER') ?: 'visibility');
    $dbPass = $db['pass'] ?? (getenv('VISIBILITY_DB_PASS') ?: 'xyPz8e/rgaC2');
    $dbName = $db['name'] ?? (getenv('VISIBILITY_DB_NAME') ?: 'visibility_visibility2');

    $conn = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
    if (!$conn) {
        die("Error de conexión: " . mysqli_connect_error());
    }

    // Establecer conjunto de caracteres
    @mysqli_set_charset($conn, "utf8mb4");
}
if (!function_exists('consulta')) {
    function consulta(string $consulta): array {
        global $conn;
        $result = mysqli_query($conn, $consulta);
        if ($result === false) {
            die(mysqli_error($conn));
        }
        $arr = [];
        while ($a = mysqli_fetch_assoc($result)) {
            $arr[] = $a;
        }
        mysqli_free_result($result);
        return $arr;
    }
}
if (!function_exists('ejecutar')) {
    function ejecutar(string $consulta): int {
        global $conn;
        $ok = mysqli_query($conn, $consulta);
        if ($ok === false) {
            die(mysqli_error($conn));
        }
        return (int) mysqli_insert_id($conn);
    }
}

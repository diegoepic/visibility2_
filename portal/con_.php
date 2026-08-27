<?php
declare(strict_types=1);

/**
 * Conexión del portal.
 *
 * Las credenciales estaban escritas dentro de este archivo, que además está
 * versionado. Ahora salen del .env de la raíz, el mismo que lee la app.
 *
 * Lo que NO cambia, porque lo incluyen 129 archivos: la variable $conn y las
 * funciones consulta() y ejecutar() se comportan igual que antes.
 *
 * Si faltan las credenciales el portal responde 503 y no arranca. Es
 * deliberado: dejar las viejas como respaldo mantendría el problema que este
 * cambio viene a resolver.
 */

require_once __DIR__ . '/../lib_env.php';
v2_load_env();

// Crear conexión solo si no existe
if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);

    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbUser = getenv('DB_USER') ?: '';
    $dbPass = getenv('DB_PASSWORD');
    $dbName = getenv('DB_NAME') ?: '';

    if ($dbUser === '' || $dbName === '' || $dbPass === false) {
        error_log('[portal/con_] Faltan credenciales de base de datos: revisar el .env de la raiz.');
        http_response_code(503);
        die('Error de conexión con la base de datos.');
    }

    $conn = @mysqli_connect($dbHost, $dbUser, (string) $dbPass, $dbName);
    if (!$conn) {
        // El detalle va al log, no a la pantalla: mysqli_connect_error() revela
        // usuario y host.
        error_log('[portal/con_] Fallo la conexion: ' . mysqli_connect_error());
        http_response_code(503);
        die('Error de conexión con la base de datos.');
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

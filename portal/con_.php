<?php
declare(strict_types=1);
// Crear conexión solo si no existe
if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = @mysqli_connect("localhost", "visibility", "xyPz8e/rgaC2", "visibility_visibility2");
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

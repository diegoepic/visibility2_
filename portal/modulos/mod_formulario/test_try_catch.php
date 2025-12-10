<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // Simular una excepción
    throw new Exception("Prueba de excepción");
} catch (Exception $e) {
    echo "Excepción capturada: " . $e->getMessage();
}
?>
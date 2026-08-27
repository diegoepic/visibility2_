<?php

    error_reporting(E_ALL & ~E_NOTICE);

    // El cargador vive ahora en la raiz para que el portal lea el mismo .env.
    // Es el mismo codigo que estaba aqui: misma precedencia (el entorno real
    // gana sobre el archivo) y sigue encontrando app/.env si el de la raiz aun
    // no esta subido.
    require_once __DIR__ . '/../lib_env.php';
    v2_load_env();

    $servername = getenv('DB_HOST')     ?: 'localhost';
    $username   = getenv('DB_USER')     ?: '';
    $password   = getenv('DB_PASSWORD') ?: '';
    $dbname     = getenv('DB_NAME')     ?: '';

    $conn = new mysqli($servername, $username, $password, $dbname);
    date_default_timezone_set('America/Santiago');

    if ($conn->connect_error) {
        error_log("DB connection failed: " . $conn->connect_error);
        http_response_code(503);
        die("Error de conexi��n con la base de datos.");
    }

    if (!$conn->set_charset("utf8")) {
        error_log("Error loading charset utf8: " . $conn->error);
        die("Error cargando el conjunto de caracteres utf8: " . $conn->error);
    }

?>
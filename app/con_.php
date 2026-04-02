<?php

    error_reporting(E_ALL & ~E_NOTICE);

    // Cargar .env si existe y aи▓n no se han cargado las variables de entorno
    (static function (): void {
        $envFile = __DIR__ . '/.env';
        if (!is_readable($envFile)) return;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
    })();

    $servername = getenv('DB_HOST')     ?: 'localhost';
    $username   = getenv('DB_USER')     ?: '';
    $password   = getenv('DB_PASSWORD') ?: '';
    $dbname     = getenv('DB_NAME')     ?: '';

    $conn = new mysqli($servername, $username, $password, $dbname);
    date_default_timezone_set('America/Santiago');

    if ($conn->connect_error) {
        error_log("DB connection failed: " . $conn->connect_error);
        http_response_code(503);
        die("Error de conexiиоn con la base de datos.");
    }

    if (!$conn->set_charset("utf8")) {
        error_log("Error loading charset utf8: " . $conn->error);
        die("Error cargando el conjunto de caracteres utf8: " . $conn->error);
    }

?>
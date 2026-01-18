<?php
    
    error_reporting(E_ALL & ~E_NOTICE);


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

    $servername = $db['host'] ?? (getenv('VISIBILITY_DB_HOST') ?: 'localhost');
    $username   = $db['user'] ?? (getenv('VISIBILITY_DB_USER') ?: 'visibility');
    $password   = $db['pass'] ?? (getenv('VISIBILITY_DB_PASS') ?: 'xyPz8e/rgaC2');
    $dbname     = $db['name'] ?? (getenv('VISIBILITY_DB_NAME') ?: 'visibility_visibility2');


    $conn = new mysqli($servername, $username, $password, $dbname);
    date_default_timezone_set('America/Santiago');

    if ($conn->connect_error) {
        die("Conexión fallida: " . $conn->connect_error);
    }


    if (!$conn->set_charset("utf8")) {
        die("Error cargando el conjunto de caracteres utf8: " . $conn->error);
    }

?>

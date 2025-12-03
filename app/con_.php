<?php
    
    error_reporting(E_ALL & ~E_NOTICE);


    $servername = "localhost";
    $username   = "visibility";
    $password   = "xyPz8e/rgaC2";
    $dbname     = "visibility_visibility2";


    $conn = new mysqli($servername, $username, $password, $dbname);
    date_default_timezone_set('America/Santiago');

    if ($conn->connect_error) {
        die("Conexión fallida: " . $conn->connect_error);
    }


    if (!$conn->set_charset("utf8")) {
        die("Error cargando el conjunto de caracteres utf8: " . $conn->error);
    }

?>
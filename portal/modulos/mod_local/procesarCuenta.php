<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Recibir el valor del formulario
    $nombre_cuenta = isset($_POST['inputNombreCuenta']) ? trim($_POST['inputNombreCuenta']) : '';
    
    
    if (!empty($nombre_cuenta)) {
        // Insertar empresa en la base de datos
        if (insertarCuenta($nombre_cuenta)) {
            // Redirigir a la página de éxito o mostrar un mensaje de éxito
            header("Location: ../mod_local.php?mensaje=exito");
        } else {
            // Redirigir a la página de error o mostrar un mensaje de error
            header("Location: ../mod_local.php?mensaje=error");
        }
        
    } else {
        // Manejar el caso en que el campo esté vacío
        echo "El nombre de la empresa no puede estar vacío.";
    }
}
?>
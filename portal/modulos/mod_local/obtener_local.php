<?php
// mod_local/obtener_local.php

// Habilitar la visualizaci車n de errores para depuraci車n (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

include '../db.php'; // Aseg迆rate de que este archivo contiene la conexi車n a la base de datos

$response = [];

if (isset($_GET['id'])) {
    $local_id = intval($_GET['id']);
    
    // Verificar que el ID sea v芍lido
    if ($local_id <= 0) {
        $response['success'] = false;
        $response['message'] = 'ID de local inv芍lido.';
        echo json_encode($response);
        exit;
    }

    $local = obtenerLocalPorId($local_id); // Aseg迆rate de que esta funci車n existe y est芍 correctamente definida en 'db.php'
    
    if ($local !== null) {
        $response['success'] = true;
        $response['data'] = $local;
    } else {
        $response['success'] = false;
        $response['message'] = 'Local no encontrado.';
    }
} else {
    $response['success'] = false;
    $response['message'] = 'No se proporcion車 el ID del local.';
}

echo json_encode($response);
?>


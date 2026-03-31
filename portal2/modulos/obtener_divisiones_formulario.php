<?php
// obtener_divisiones_formulario.php

// Incluir archivos necesarios
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Verificar si se ha proporcionado el ID de la empresa
if (isset($_GET['id_empresa'])) {
    $id_empresa = intval($_GET['id_empresa']);

    try {
        // Obtener divisiones de la empresa
        $divisiones = obtenerDivisionesPorEmpresa($id_empresa);

        // Retornar las divisiones en formato JSON
        header('Content-Type: application/json');
        echo json_encode($divisiones);
        exit();
    } catch (Exception $e) {
        // En caso de error, retornar un arreglo vacío
        header('Content-Type: application/json');
        echo json_encode([]);
        exit();
    }
} else {
    // No se proporcionó id_empresa, retornar un arreglo vacío
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}
?>

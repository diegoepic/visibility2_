<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre_empresa = isset($_POST['inputEmpresa']) ? trim($_POST['inputEmpresa']) : '';
    $nombre_division = isset($_POST['inputDivision']) ? trim($_POST['inputDivision']) : '';

    if (!empty($nombre_empresa)) {
        // Verificar si la empresa ya existe
        if (existeEmpresa($nombre_empresa)) {
            header("Location: ../mod_elementos.php?mensaje=empresa_duplicada");
            exit();
        }

        // Insertar empresa si no existe
        if ($empresaId = insertarEmpresa($nombre_empresa)) {
            if (!empty($nombre_division)) {
                // Verificar si la división ya existe para esta empresa
                if (existeDivision($nombre_division, $empresaId)) {
                    header("Location: ../mod_elementos.php?mensaje=division_duplicada");
                    exit();
                }

                insertarDivision($nombre_division, $empresaId);
            }
            header("Location: ../mod_elementos.php?mensaje=exito");
            exit();
        } else {
            header("Location: ../mod_elementos.php?mensaje=error");
            exit();
        }
    } else {
        echo "El nombre de la empresa no puede estar vacío.";
    }
}
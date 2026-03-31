<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId = $_POST['empresa_id'];
    $nombre_division = trim($_POST['inputDivision']);

    if (!empty($nombre_division)) {
        // Verificar si la divisiиоn ya existe para esta empresa
        if (existeDivision($nombre_division, $empresaId)) {
            header("Location: ../mod_elementos.php?mensaje=division_duplicada");
            exit();
        }

        if (insertarDivision($nombre_division, $empresaId)) {
            header("Location: ../mod_elementos.php?mensaje=division_exito");
            exit();
        } else {
            header("Location: ../mod_elementos.php?mensaje=division_error");
            exit();
        }
    } else {
        echo "El nombre de la divisiиоn no puede estar vacикo.";
    }
}
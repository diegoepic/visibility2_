<?php
// mod_user/obtener_divisiones.php

session_start();

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['empresa_id']) && is_numeric($_GET['empresa_id'])) {
    $empresa_id = intval($_GET['empresa_id']);

    // Obtener las divisiones de la empresa
    $stmt = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE id_empresa = ? ORDER BY nombre ASC");
    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Generar las opciones del select
    echo '<option value="">Seleccione una división</option>';
    while($row = $result->fetch_assoc()){
        echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['nombre']) . '</option>';
    }

    $stmt->close();
} else {
    // Si no se proporciona una empresa válida, devolver una opción por defecto
    echo '<option value="">Seleccione una división</option>';
}

$conn->close();
?>

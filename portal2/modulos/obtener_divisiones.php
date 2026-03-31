<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['empresa_id'])) {
    $id_empresa = intval($_GET['empresa_id']);
    $divisiones = obtenerDivisionesPorEmpresa($id_empresa);

    if (!empty($divisiones)) {
        foreach ($divisiones as $division) {
            echo '<option value="' . htmlspecialchars($division['id']) . '">' . htmlspecialchars($division['nombre']) . '</option>';
        }
    } else {
        echo '<option value="">No hay divisiones disponibles</option>';
    }
} else {
    echo '<option value="">Error al cargar las divisiones</option>';
}
?>

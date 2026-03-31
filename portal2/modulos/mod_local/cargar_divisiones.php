<?php
// Incluir conexión a la base de datos
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['empresa_id'])) {
    $empresa_id = intval($_GET['empresa_id']);
    
    // Consulta para obtener las divisiones asociadas a la empresa 
    $query = "SELECT id, nombre FROM division_empresa WHERE id_empresa = ? AND estado = 1 ORDER BY nombre ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Construir las opciones para el select
    $options = '<option value="">-- Seleccione una División --</option>';
    while ($row = $result->fetch_assoc()) {
        $options .= '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['nombre']) . '</option>';
    }
    
    echo $options;
} else {
    echo '<option value="">-- Seleccione una División --</option>';
}
?>
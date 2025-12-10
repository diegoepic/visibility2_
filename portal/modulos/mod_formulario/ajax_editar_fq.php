<?php
// ajax_editar_fq.php

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Cargar listas de usuarios y materiales (ajusta las consultas si es necesario)
$usuarios = [];
$sqlUsuarios = "SELECT id, usuario FROM usuario ORDER BY usuario ASC";
$resUsuarios = $conn->query($sqlUsuarios);
if($resUsuarios){
    while($rowUsuario = $resUsuarios->fetch_assoc()){
        $usuarios[] = $rowUsuario;
    }
}

$materiales = [];
$sqlMateriales = "SELECT id, nombre FROM material ORDER BY nombre ASC";
$resMateriales = $conn->query($sqlMateriales);
if($resMateriales){
    while($rowMaterial = $resMateriales->fetch_assoc()){
        $materiales[] = $rowMaterial;
    }
}

if (isset($_GET['id'], $_GET['formulario_id']) && is_numeric($_GET['id'])) {
    $fq_id = intval($_GET['id']);
    $formulario_id = intval($_GET['formulario_id']);
    
    // Consulta para obtener la entrada
    $stmt = $conn->prepare("SELECT fq.id, fq.id_usuario, u.usuario AS nombre_usuario, fq.id_local, l.nombre AS nombre_local, fq.material, fq.valor_propuesto, fq.valor, fq.fechaVisita, fq.observacion, fq.estado, fq.pregunta
                            FROM formularioQuestion fq
                            LEFT JOIN usuario u ON fq.id_usuario = u.id
                            LEFT JOIN local l ON fq.id_local = l.id
                            WHERE fq.id = ?");
    $stmt->bind_param("i", $fq_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Genera el contenido del modal (sin etiquetas <html> ni <body>)
        ?>
        <div class="modal-header">
          <h5 class="modal-title" id="editarFQModalLabel">Editar Entrada</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post">
            <input type="hidden" name="update_fq" value="1">
            <input type="hidden" name="fq_id" value="<?php echo $row['id']; ?>">
            <input type="hidden" name="active_tab" value="agregar-entradas">
            
            <!-- Campo: Usuario Asignado -->
            <div class="form-group">
              <label for="id_usuario">Usuario Asignado:</label>
              <select id="id_usuario" name="id_usuario" class="form-control" required>
                <option value="">Seleccione un usuario</option>
                <?php foreach ($usuarios as $usuario): ?>
                  <option value="<?php echo htmlspecialchars($usuario['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php if ($row['id_usuario'] == $usuario['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <!-- Campo: Material -->
            <div class="form-group">
              <label for="material_id">Material:</label>
              <div class="input-group">
                <select id="material_id" name="material_id" class="form-control" required>
                  <option value="">Seleccione un material</option>
                  <?php foreach ($materiales as $mat): ?>
                    <option value="<?php echo htmlspecialchars($mat['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php if ($row['material'] == $mat['nombre']) echo 'selected'; ?>>
                      <?php echo htmlspecialchars($mat['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="input-group-append">
                  <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">Agregar Material</button>
                </div>
              </div>
            </div>
            
            <!-- Otros campos: Valor Propuesto, Valor, Fecha, Observación, etc. -->
            <div class="form-group">
              <label for="valor_propuesto">Valor Propuesto:</label>
              <input type="text" id="valor_propuesto" name="valor_propuesto" class="form-control" value="<?php echo htmlspecialchars($row['valor_propuesto'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            
            <div class="form-group">
              <label for="valor">Valor:</label>
              <input type="text" id="valor" name="valor" class="form-control" value="<?php echo htmlspecialchars($row['valor'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            
            <div class="form-group">
              <label for="fechaVisita">Fecha de Visita:</label>
              <input type="datetime-local" id="fechaVisita" name="fechaVisita" class="form-control" value="<?php echo !empty($row['fechaVisita']) ? date('Y-m-d\TH:i', strtotime($row['fechaVisita'])) : ''; ?>">
            </div>
            
            <div class="form-group">
              <label for="observacion">Observación:</label>
              <textarea id="observacion" name="observacion" class="form-control"><?php echo htmlspecialchars($row['observacion'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            
            <div class="form-group">
              <label for="estado">Estado:</label>
              <select id="estado" name="estado" class="form-control" required>
                <option value="0" <?php if ($row['estado'] == 0) echo 'selected'; ?>>En Proceso</option>
                <option value="1" <?php if ($row['estado'] == 1) echo 'selected'; ?>>Completado</option>
                <option value="2" <?php if ($row['estado'] == 2) echo 'selected'; ?>>Cancelado</option>
              </select>
            </div>
            
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
          </form>
        </div>
        <?php
    } else {
        echo "<div class='modal-body'><p>Error: Entrada no encontrada.</p></div>";
    }
    
    $stmt->close();
} else {
    echo "<div class='modal-body'><p>Error: Parámetros inválidos.</p></div>";
}
?>

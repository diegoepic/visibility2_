<?php
// ajax_editar_gestion.php

// Incluir la conexión a BDDD y datos de sesión 
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_SESSION['usuario_id'])) {
  http_response_code(403);
  echo "<div class='alert alert-danger'>Acceso denegado.</div>";
  exit;
}


// Parámetros
$formulario_id = isset($_GET['formulario_id']) ? intval($_GET['formulario_id']) : 0;
$local_id      = isset($_GET['local_id'])      ? intval($_GET['local_id'])      : 0;

if ($formulario_id <= 0 || $local_id <= 0) {
    echo "<div class='alert alert-danger'>Parámetros inválidos.</div>";
    exit();
}

// ----------------------------------------------------------------------
// 0. Obtener id_division del formulario para cargar materiales
// ----------------------------------------------------------------------
$stmt_div = $conn->prepare("SELECT id_division FROM formulario WHERE id = ?");
$stmt_div->bind_param("i", $formulario_id);
$stmt_div->execute();
$stmt_div->bind_result($id_division);
$stmt_div->fetch();
$stmt_div->close();

// Traer lista de materiales de esa división
$materiales = [];
if ($id_division > 0) {
    $stmt_mat = $conn->prepare("SELECT id, nombre FROM material WHERE id_division = ? ORDER BY nombre ASC");
    $stmt_mat->bind_param("i", $id_division);
    $stmt_mat->execute();
    $res_mat = $stmt_mat->get_result();
    $materiales = $res_mat->fetch_all(MYSQLI_ASSOC);
    $stmt_mat->close();
}

// ----------------------------------------------------------------------
// 1. Procesar la reasignación de local (action = 'reassign_local')
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reassign_local') {
    $nuevo_ejecutor_id = isset($_POST['nuevo_ejecutor_id']) ? intval($_POST['nuevo_ejecutor_id']) : 0;
    if ($nuevo_ejecutor_id > 0) {
        $stmt_reassign = $conn->prepare("
            UPDATE formularioQuestion
               SET id_usuario = ?
             WHERE id_formulario = ? AND id_local = ?
        ");
        $stmt_reassign->bind_param("iii", $nuevo_ejecutor_id, $formulario_id, $local_id);
        if ($stmt_reassign->execute()) {
            echo "<div class='alert alert-success'>Local reasignado correctamente al usuario con ID $nuevo_ejecutor_id.</div>";
        } else {
            echo "<div class='alert alert-danger'>Error al reasignar el local: " . htmlspecialchars($stmt_reassign->error) . "</div>";
        }
        $stmt_reassign->close();
    } else {
        echo "<div class='alert alert-danger'>No seleccionaste un ejecutor válido.</div>";
    }
    exit();
}

// ----------------------------------------------------------------------
// 2. Procesar la actualización de la gestión (action = 'update_gestion')
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_gestion') {
    if (isset($_POST['gestion']) && is_array($_POST['gestion'])) {
        $successCount = 0;
        
$sql_update = "
  UPDATE formularioQuestion
     SET material        = ?,
         valor_propuesto = NULLIF(?, ''),   
         valor           = NULLIF(?, ''),   
         fechaVisita     = NULLIF(?, ''),
         fechaPropuesta  = NULLIF(?, ''),
         estado          = ?,
         pregunta        = ?,            
         motivo          = ?,
         is_priority     = ?,
         latGestion      = NULLIF(?, ''),
         lngGestion      = NULLIF(?, ''),
         id_usuario      = ?
   WHERE id = ?
";
        $stmt_update = $conn->prepare($sql_update);

        foreach ($_POST['gestion'] as $id => $data) {
            $userId      = intval($data['usuario_id']);
            $material    = $data['material'];
            $valor_prop  = $data['valor_propuesto'];
            $valor_impl  = $data['valor'];
            $fv          = str_replace('T',' ',$data['fechaVisita']);
            $fp          = $data['fechaPropuesta'] ?: null;
            $estado      = intval($data['estado']);
            if ($estado === 3) {
                $estado_db = 0;
                $pregunta_val = '';
            } elseif ($estado === 1) {
                $estado_db = 1;
                $pregunta_val = 'completado';
            } elseif ($estado === 2) {
                $estado_db = 2;
                $pregunta_val = 'cancelado';
            } else {
                $estado_db = 0;
                $pregunta_val = 'en proceso';
            }
            // motivo llega directamente como '-' si está marcado, o no existe
            $motivo      = isset($data['motivo']) && $data['motivo']==='-' ? '-' : '';
            $is_priority = isset($data['is_priority']) ? 1 : 0;
            $lat         = $data['latGestion'];
            $lng         = $data['lngGestion'];

                $stmt_update->bind_param(
                  "sssssisisssii",
                  $material,
                  $valor_prop,  
                  $valor_impl,  
                  $fv,
                  $fp,
                  $estado_db,
                  $pregunta_val, 
                  $motivo,
                  $is_priority,
                  $lat,          
                  $lng,          
                  $userId,
                  $id
                );
            if ($stmt_update->execute()) {
                $successCount++;
            }
        }
        $stmt_update->close();
        echo "<div class='alert alert-success'>Se actualizaron $successCount registros correctamente.</div>";
    } else {
        echo "<div class='alert alert-danger'>No se recibieron datos para actualizar.</div>";
    }
    exit();
}

// ----------------------------------------------------------------------
// 3. Mostrar contenido del modal para editar (GET)
// ----------------------------------------------------------------------
$sql = "
    SELECT fq.*, u.usuario AS nombre_usuario
      FROM formularioQuestion fq
      LEFT JOIN usuario u ON u.id = fq.id_usuario
     WHERE fq.id_formulario = ? AND fq.id_local = ?
     ORDER BY fq.fechaVisita ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $formulario_id, $local_id);
$stmt->execute();
$result = $stmt->get_result();
$gestiones = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();



// ----------------------------------------------------------------------
// 4. Obtener lista de ejecutores para reasignación
// ----------------------------------------------------------------------
$ejecutores = [];
$perfil_ejecutor_id = 3;
$sql_ejec = "SELECT id, usuario
               FROM usuario
              WHERE id_perfil   = ?
                AND activo      = 1
           ORDER BY usuario ASC";
$stmt_ejec = $conn->prepare($sql_ejec);
$stmt_ejec->bind_param("i", $perfil_ejecutor_id );
$stmt_ejec->execute();
$res_ejec = $stmt_ejec->get_result();
$ejecutores = $res_ejec->fetch_all(MYSQLI_ASSOC);
$stmt_ejec->close();
?>
<div class="modal-header">
  <h5 class="modal-title">Editar Gestión — Local ID: <?= $local_id ?></h5>
  <button type="button" class="close" data-dismiss="modal">&times;</button>
</div>
<div class="modal-body">

  <!-- BLOQUE A: Editar gestiones -->
  <?php if (count($gestiones) > 0): ?>
    <form method="post" id="editarGestionForm">
      <input type="hidden" name="action" value="update_gestion">
      <input type="hidden" name="formulario_id" value="<?= $formulario_id ?>">
      <input type="hidden" name="local_id"      value="<?= $local_id ?>">

      <div class="table-responsive">
        <table class="table table-sm table-bordered">
          <thead>
            <tr>
              <th>ID</th>
              <th>Usuario</th>
              <th>Material</th>
              <th>Valor Propuesto</th>
              <th>Valor Implementado</th>
              <th>Fecha Visita</th>
              <th>Fecha Propuesta</th>
              <th style="width:140px">Estado</th>
              <th>Implementado</th>
              <th>Prioridad</th>
              <th>Latitud</th>
              <th>Longitud</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($gestiones as $g): ?>
            <tr>
              <td><?= $g['id'] ?></td>
<td>
  <select name="gestion[<?= $g['id'] ?>][usuario_id]" 
          class="form-control form-control-sm" required>
    <?php foreach ($ejecutores as $ej): ?>
      <option value="<?= $ej['id'] ?>"
        <?= $ej['id'] === $g['id_usuario'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($ej['usuario'], ENT_QUOTES) ?>
      </option>
    <?php endforeach; ?>
  </select>
</td>
              <td>
                <select name="gestion[<?= $g['id'] ?>][material]" class="form-control form-control-sm" required>
                  <?php foreach ($materiales as $m): ?>
                    <option value="<?= $m['nombre'] ?>"
                      <?= $m['nombre'] === $g['material'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($m['nombre'], ENT_QUOTES) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <input type="number" step="any"
                       name="gestion[<?= $g['id'] ?>][valor_propuesto]"
                       value="<?= $g['valor_propuesto'] ?>"
                       class="form-control form-control-sm">
              </td>
              <td>
                <input type="number" step="any"
                       name="gestion[<?= $g['id'] ?>][valor]"
                       value="<?= $g['valor'] ?>"
                       class="form-control form-control-sm">
              </td>
              <td>
                <input type="datetime-local"
                       name="gestion[<?= $g['id'] ?>][fechaVisita]"
                       value="<?= date('Y-m-d\TH:i', strtotime($g['fechaVisita'])) ?>"
                       class="form-control form-control-sm">
              </td>
              <td>
                <input type="date"
                       name="gestion[<?= $g['id'] ?>][fechaPropuesta]"
                       value="<?= $g['fechaPropuesta'] ?>"
                       class="form-control form-control-sm">
              </td>
             <td style="width:140px">
                <select name="gestion[<?= $g['id'] ?>][estado]" class="form-control form-control-sm">
                  <option value="0" <?= $g['estado']==0?'selected':'' ?>>En proceso</option>
                  <option value="1" <?= $g['estado']==1?'selected':'' ?>>Completado</option>
                  <option value="2" <?= $g['estado']==2?'selected':'' ?>>Cancelado</option>
                  <option value="3" <?= $g['estado']==3?'selected':'' ?>>No gestionado</option>
                </select>
              </td>
              <td class="text-center">
                <input type="checkbox"
                       name="gestion[<?= $g['id'] ?>][motivo]"
                       value="-"
                       <?= $g['motivo']==='-' ? 'checked' : '' ?>>
              </td>
              <td class="text-center">
                <input type="checkbox"
                       name="gestion[<?= $g['id'] ?>][is_priority]"
                       <?= $g['is_priority'] ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="text"
                       name="gestion[<?= $g['id'] ?>][latGestion]"
                       value="<?= $g['latGestion'] ?>"
                       class="form-control form-control-sm">
              </td>
              <td>
                <input type="text"
                       name="gestion[<?= $g['id'] ?>][lngGestion]"
                       value="<?= $g['lngGestion'] ?>"
                       class="form-control form-control-sm">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <button type="submit" class="btn btn-primary btn-sm">Guardar Cambios</button>
    </form>
  <?php else: ?>
    <p>No se encontraron registros de gestión para este local.</p>
  <?php endif; ?>

  <hr>

  <!-- BLOQUE B: Reasignar local -->
  <h5>Reasignar Local a Otro Ejecutor</h5>
  <form method="post" id="reasignarLocalForm" class="form-inline mt-3">
    <input type="hidden" name="action" value="reassign_local">
    <input type="hidden" name="formulario_id" value="<?= $formulario_id ?>">
    <input type="hidden" name="local_id"      value="<?= $local_id ?>">

    <div class="form-group mb-2 mr-2">
      <label for="nuevo_ejecutor_id" class="mr-2">Ejecutor:</label>
      <select name="nuevo_ejecutor_id" id="nuevo_ejecutor_id" class="form-control form-control-sm" required>
        <option value="">-- Seleccione --</option>
        <?php foreach ($ejecutores as $ej): ?>
          <option value="<?= $ej['id'] ?>"><?= htmlspecialchars($ej['usuario'], ENT_QUOTES) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-warning btn-sm mb-2">Reasignar</button>
  </form>
</div>
<div class="modal-footer">
  <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cerrar</button>
</div>
<?php
$conn->close();
?>

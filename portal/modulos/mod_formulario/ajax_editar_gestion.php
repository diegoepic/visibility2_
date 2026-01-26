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
// 2. Procesar la actualización INDIVIDUAL de un registro (action = 'update_single_gestion')
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_single_gestion') {
    header('Content-Type: application/json');

    $fq_id = isset($_POST['fq_id']) ? intval($_POST['fq_id']) : 0;

    if ($fq_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de registro inválido.']);
        exit();
    }

    // Verificar que el registro pertenece al formulario y local indicados
    $stmt_check = $conn->prepare("SELECT id FROM formularioQuestion WHERE id = ? AND id_formulario = ? AND id_local = ?");
    $stmt_check->bind_param("iii", $fq_id, $formulario_id, $local_id);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows === 0) {
        $stmt_check->close();
        echo json_encode(['success' => false, 'message' => 'Registro no encontrado o no pertenece a este local/formulario.']);
        exit();
    }
    $stmt_check->close();

    $userId      = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
    $material    = isset($_POST['material']) ? $_POST['material'] : '';
    $valor_prop  = isset($_POST['valor_propuesto']) ? $_POST['valor_propuesto'] : '';
    $valor_impl  = isset($_POST['valor']) ? $_POST['valor'] : '';
    $fv          = isset($_POST['fechaVisita']) ? str_replace('T', ' ', $_POST['fechaVisita']) : '';
    $fp          = isset($_POST['fechaPropuesta']) && $_POST['fechaPropuesta'] !== '' ? $_POST['fechaPropuesta'] : null;
    $estado      = isset($_POST['estado']) ? intval($_POST['estado']) : 0;

    if ($estado === 3) {
        $pregunta_val = '';
    } elseif ($estado === 1) {
        $pregunta_val = 'completado';
    } elseif ($estado === 2) {
        $pregunta_val = 'cancelado';
    } else {
        $pregunta_val = 'en proceso';
    }

    $motivo      = isset($_POST['motivo']) && $_POST['motivo'] === '-' ? '-' : '';
    $is_priority = isset($_POST['is_priority']) ? 1 : 0;
    $lat         = isset($_POST['latGestion']) ? $_POST['latGestion'] : '';
    $lng         = isset($_POST['lngGestion']) ? $_POST['lngGestion'] : '';

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
    $stmt_update->bind_param(
        "sssssisisssii",
        $material,
        $valor_prop,
        $valor_impl,
        $fv,
        $fp,
        $estado,
        $pregunta_val,
        $motivo,
        $is_priority,
        $lat,
        $lng,
        $userId,
        $fq_id
    );

    if ($stmt_update->execute()) {
        $stmt_update->close();
        echo json_encode(['success' => true, 'message' => 'Registro actualizado correctamente.', 'id' => $fq_id]);
    } else {
        $error = $stmt_update->error;
        $stmt_update->close();
        echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $error]);
    }
    exit();
}

// ----------------------------------------------------------------------
// 2b. Procesar la actualización MASIVA de la gestión (action = 'update_gestion') - MANTENIDO PARA COMPATIBILIDAD
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
                $pregunta_val = '';
            } elseif ($estado === 1) {
                $pregunta_val = 'completado';
            } elseif ($estado === 2) {
                $pregunta_val = 'cancelado';
            } else {
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
                  $estado,
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

  <!-- BLOQUE A: Editar gestiones (individual por fila) -->
  <?php if (count($gestiones) > 0): ?>
    <div id="gestion-alert-container"></div>

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
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($gestiones as $g): ?>
          <tr data-fq-id="<?= $g['id'] ?>">
            <td><?= $g['id'] ?></td>
            <td>
              <select name="usuario_id" class="form-control form-control-sm" required>
                <?php foreach ($ejecutores as $ej): ?>
                  <option value="<?= $ej['id'] ?>"
                    <?= $ej['id'] == $g['id_usuario'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ej['usuario'], ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="material" class="form-control form-control-sm" required>
                <?php foreach ($materiales as $m): ?>
                  <option value="<?= htmlspecialchars($m['nombre'], ENT_QUOTES) ?>"
                    <?= $m['nombre'] === $g['material'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['nombre'], ENT_QUOTES) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="number" step="any"
                     name="valor_propuesto"
                     value="<?= htmlspecialchars($g['valor_propuesto'] ?? '', ENT_QUOTES) ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <input type="number" step="any"
                     name="valor"
                     value="<?= htmlspecialchars($g['valor'] ?? '', ENT_QUOTES) ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <input type="datetime-local"
                     name="fechaVisita"
                     value="<?= $g['fechaVisita'] ? date('Y-m-d\TH:i', strtotime($g['fechaVisita'])) : '' ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <input type="date"
                     name="fechaPropuesta"
                     value="<?= htmlspecialchars($g['fechaPropuesta'] ?? '', ENT_QUOTES) ?>"
                     class="form-control form-control-sm">
            </td>
            <td style="width:140px">
              <select name="estado" class="form-control form-control-sm">
                <option value="0" <?= $g['estado']==0?'selected':'' ?>>En proceso</option>
                <option value="1" <?= $g['estado']==1?'selected':'' ?>>Completado</option>
                <option value="2" <?= $g['estado']==2?'selected':'' ?>>Cancelado</option>
                <option value="3" <?= $g['estado']==3?'selected':'' ?>>No gestionado</option>
              </select>
            </td>
            <td class="text-center">
              <input type="checkbox"
                     name="motivo"
                     value="-"
                     <?= ($g['motivo'] ?? '')==='-' ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
              <input type="checkbox"
                     name="is_priority"
                     <?= ($g['is_priority'] ?? 0) ? 'checked' : '' ?>>
            </td>
            <td>
              <input type="text"
                     name="latGestion"
                     value="<?= htmlspecialchars($g['latGestion'] ?? '', ENT_QUOTES) ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <input type="text"
                     name="lngGestion"
                     value="<?= htmlspecialchars($g['lngGestion'] ?? '', ENT_QUOTES) ?>"
                     class="form-control form-control-sm">
            </td>
            <td>
              <button type="button" class="btn btn-success btn-sm btn-guardar-fila" data-fq-id="<?= $g['id'] ?>">
                <i class="fas fa-save"></i> Guardar
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <script>
    (function() {
      const formularioId = <?= json_encode($formulario_id) ?>;
      const localId = <?= json_encode($local_id) ?>;

      $(document).off('click', '.btn-guardar-fila').on('click', '.btn-guardar-fila', function() {
        const $btn = $(this);
        const fqId = $btn.data('fq-id');
        const $row = $btn.closest('tr');

        // Recopilar datos de la fila
        const data = {
          action: 'update_single_gestion',
          fq_id: fqId,
          usuario_id: $row.find('[name="usuario_id"]').val(),
          material: $row.find('[name="material"]').val(),
          valor_propuesto: $row.find('[name="valor_propuesto"]').val(),
          valor: $row.find('[name="valor"]').val(),
          fechaVisita: $row.find('[name="fechaVisita"]').val(),
          fechaPropuesta: $row.find('[name="fechaPropuesta"]').val(),
          estado: $row.find('[name="estado"]').val(),
          motivo: $row.find('[name="motivo"]').is(':checked') ? '-' : '',
          is_priority: $row.find('[name="is_priority"]').is(':checked') ? '1' : '0',
          latGestion: $row.find('[name="latGestion"]').val(),
          lngGestion: $row.find('[name="lngGestion"]').val()
        };

        // Deshabilitar botón durante la petición
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
          url: 'ajax_editar_gestion.php?formulario_id=' + formularioId + '&local_id=' + localId,
          method: 'POST',
          data: data,
          dataType: 'json',
          success: function(response) {
            const $alertContainer = $('#gestion-alert-container');
            $alertContainer.empty();

            if (response.success) {
              $alertContainer.html('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                response.message +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
                '</div>');
              $row.css('background-color', '#d4edda');
              setTimeout(() => $row.css('background-color', ''), 1500);
            } else {
              $alertContainer.html('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                response.message +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
                '</div>');
              $row.css('background-color', '#f8d7da');
              setTimeout(() => $row.css('background-color', ''), 1500);
            }
          },
          error: function(xhr, status, error) {
            $('#gestion-alert-container').html('<div class="alert alert-danger">Error de conexión: ' + error + '</div>');
          },
          complete: function() {
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar');
          }
        });
      });
    })();
    </script>
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
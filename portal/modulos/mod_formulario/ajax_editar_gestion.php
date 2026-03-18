<?php
// ajax_editar_gestion.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Acceso denegado.</div>";
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeDatetimeLocal(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function normalizeDateOnly(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

$formulario_id = isset($_GET['formulario_id']) ? (int)$_GET['formulario_id'] : 0;
$local_id      = isset($_GET['local_id']) ? (int)$_GET['local_id'] : 0;

if ($formulario_id <= 0 || $local_id <= 0) {
    http_response_code(400);
    echo "<div class='alert alert-danger'>Parámetros inválidos.</div>";
    exit;
}

// ----------------------------------------------------------------------
// 0. Obtener datos del formulario
// ----------------------------------------------------------------------
$stmt_form = $conn->prepare("
    SELECT id_division, id_empresa
    FROM formulario
    WHERE id = ?
    LIMIT 1
");
$stmt_form->bind_param("i", $formulario_id);
$stmt_form->execute();
$res_form = $stmt_form->get_result();
$formulario = $res_form->fetch_assoc();
$stmt_form->close();

if (!$formulario) {
    http_response_code(404);
    echo "<div class='alert alert-danger'>Formulario no encontrado.</div>";
    exit;
}

$id_division = (int)($formulario['id_division'] ?? 0);
$id_empresa  = (int)($formulario['id_empresa'] ?? 0);

// ----------------------------------------------------------------------
// Materiales de la división del formulario
// ----------------------------------------------------------------------
$materiales = [];

if ($id_division > 0) {
    $stmt_mat = $conn->prepare("
        SELECT id, nombre
        FROM material
        WHERE id_division = ?
        ORDER BY nombre ASC
    ");
    $stmt_mat->bind_param("i", $id_division);
    $stmt_mat->execute();
    $res_mat = $stmt_mat->get_result();
    $materiales = $res_mat->fetch_all(MYSQLI_ASSOC);
    $stmt_mat->close();
}

// ----------------------------------------------------------------------
// Ejecutores válidos del mismo contexto empresa/división
// ----------------------------------------------------------------------
$ejecutores = [];
$perfil_ejecutor_id = 3;

$stmt_ejec = $conn->prepare("
    SELECT id, usuario
    FROM usuario
    WHERE id_perfil = ?
      AND activo = 1
    ORDER BY usuario ASC
");
$stmt_ejec->bind_param("i", $perfil_ejecutor_id);
$stmt_ejec->execute();
$res_ejec = $stmt_ejec->get_result();
$ejecutores = $res_ejec->fetch_all(MYSQLI_ASSOC);
$stmt_ejec->close();

// ----------------------------------------------------------------------
// 1. Reasignación de local
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_local') {
    $nuevo_ejecutor_id = isset($_POST['nuevo_ejecutor_id']) ? (int)$_POST['nuevo_ejecutor_id'] : 0;

    if ($nuevo_ejecutor_id <= 0) {
        echo "<div class='alert alert-danger'>No seleccionaste un ejecutor válido.</div>";
        exit;
    }

    $stmt_check_user = $conn->prepare("
        SELECT id
        FROM usuario
        WHERE id = ?
          AND id_perfil = 3
          AND activo = 1
        LIMIT 1
    ");
    $stmt_check_user->bind_param("i", $nuevo_ejecutor_id);
    $stmt_check_user->execute();
    $stmt_check_user->store_result();

    if ($stmt_check_user->num_rows === 0) {
        $stmt_check_user->close();
        echo "<div class='alert alert-danger'>El ejecutor seleccionado no es válido.</div>";
        exit;
    }
    $stmt_check_user->close();

    $conn->begin_transaction();

    try {
        // Implementaciones
        $stmt_reassign_fq = $conn->prepare("
            UPDATE formularioQuestion
            SET id_usuario = ?
            WHERE id_formulario = ?
              AND id_local = ?
        ");
        $stmt_reassign_fq->bind_param("iii", $nuevo_ejecutor_id, $formulario_id, $local_id);
        $stmt_reassign_fq->execute();
        $stmt_reassign_fq->close();

        // Encuestas
        $stmt_reassign_fqr = $conn->prepare("
            UPDATE form_question_responses fqr
            INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
            SET fqr.id_usuario = ?
            WHERE fq.id_formulario = ?
              AND fqr.id_local = ?
        ");
        $stmt_reassign_fqr->bind_param("iii", $nuevo_ejecutor_id, $formulario_id, $local_id);
        $stmt_reassign_fqr->execute();
        $stmt_reassign_fqr->close();

        // Fotos
        $stmt_reassign_fv = $conn->prepare("
            UPDATE fotoVisita
            SET id_usuario = ?
            WHERE id_formulario = ?
              AND id_local = ?
        ");
        $stmt_reassign_fv->bind_param("iii", $nuevo_ejecutor_id, $formulario_id, $local_id);
        $stmt_reassign_fv->execute();
        $stmt_reassign_fv->close();

        $conn->commit();

        echo "<div class='alert alert-success'>Local reasignado correctamente.</div>";
    } catch (Throwable $e) {
        $conn->rollback();
        echo "<div class='alert alert-danger'>Error al reasignar el local: " . h($e->getMessage()) . "</div>";
    }

    exit;
}

// ----------------------------------------------------------------------
// 2. Actualización individual
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_single_gestion') {
    header('Content-Type: application/json; charset=utf-8');

    $fq_id = isset($_POST['fq_id']) ? (int)$_POST['fq_id'] : 0;

    if ($fq_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de registro inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt_check = $conn->prepare("
        SELECT id
        FROM formularioQuestion
        WHERE id = ?
          AND id_formulario = ?
          AND id_local = ?
        LIMIT 1
    ");
    $stmt_check->bind_param("iii", $fq_id, $formulario_id, $local_id);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows === 0) {
        $stmt_check->close();
        echo json_encode([
            'success' => false,
            'message' => 'Registro no encontrado o no pertenece a este local/formulario.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt_check->close();

    $userId      = isset($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : 0;
    $material    = trim((string)($_POST['material'] ?? ''));
    $valor_prop  = trim((string)($_POST['valor_propuesto'] ?? ''));
    $valor_impl  = trim((string)($_POST['valor'] ?? ''));
    $fv          = normalizeDatetimeLocal($_POST['fechaVisita'] ?? null);
    $fp          = normalizeDateOnly($_POST['fechaPropuesta'] ?? null);
    $estado      = isset($_POST['estado']) ? (int)$_POST['estado'] : 0;
    $motivo      = (isset($_POST['motivo']) && $_POST['motivo'] === '-') ? '-' : '';
    $is_priority = isset($_POST['is_priority']) && (string)$_POST['is_priority'] === '1' ? 1 : 0;
    $lat         = trim((string)($_POST['latGestion'] ?? ''));
    $lng         = trim((string)($_POST['lngGestion'] ?? ''));

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Usuario inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($material === '') {
        echo json_encode(['success' => false, 'message' => 'Material inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($estado === 3) {
        $pregunta_val = '';
    } elseif ($estado === 1) {
        $pregunta_val = 'completado';
    } elseif ($estado === 2) {
        $pregunta_val = 'cancelado';
    } else {
        $pregunta_val = 'en proceso';
    }

    $sql_update = "
        UPDATE formularioQuestion
        SET material        = ?,
            valor_propuesto = ?,
            valor           = ?,
            fechaVisita     = ?,
            fechaPropuesta  = ?,
            estado          = ?,
            pregunta        = ?,
            motivo          = ?,
            is_priority     = ?,
            latGestion      = ?,
            lngGestion      = ?,
            id_usuario      = ?
        WHERE id = ?
    ";

    $stmt_update = $conn->prepare($sql_update);

    $valor_prop_db = ($valor_prop === '') ? null : $valor_prop;
    $valor_impl_db = ($valor_impl === '') ? null : $valor_impl;
    $lat_db        = ($lat === '') ? null : $lat;
    $lng_db        = ($lng === '') ? null : $lng;

    $stmt_update->bind_param(
        "sssssississii",
        $material,
        $valor_prop_db,
        $valor_impl_db,
        $fv,
        $fp,
        $estado,
        $pregunta_val,
        $motivo,
        $is_priority,
        $lat_db,
        $lng_db,
        $userId,
        $fq_id
    );

    if ($stmt_update->execute()) {
        $stmt_update->close();
        echo json_encode([
            'success' => true,
            'message' => 'Registro actualizado correctamente.',
            'id'      => $fq_id
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $error = $stmt_update->error;
        $stmt_update->close();
        echo json_encode([
            'success' => false,
            'message' => 'Error al actualizar: ' . $error
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ----------------------------------------------------------------------
// 2b. Actualización masiva
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_gestion') {
    if (!isset($_POST['gestion']) || !is_array($_POST['gestion'])) {
        echo "<div class='alert alert-danger'>No se recibieron datos para actualizar.</div>";
        exit;
    }

    $successCount = 0;

    $sql_update = "
        UPDATE formularioQuestion
        SET material        = ?,
            valor_propuesto = ?,
            valor           = ?,
            fechaVisita     = ?,
            fechaPropuesta  = ?,
            estado          = ?,
            pregunta        = ?,
            motivo          = ?,
            is_priority     = ?,
            latGestion      = ?,
            lngGestion      = ?,
            id_usuario      = ?
        WHERE id = ?
          AND id_formulario = ?
          AND id_local = ?
    ";
    $stmt_update = $conn->prepare($sql_update);

    foreach ($_POST['gestion'] as $id => $data) {
        $id = (int)$id;
        $userId      = (int)($data['usuario_id'] ?? 0);
        $material    = trim((string)($data['material'] ?? ''));
        $valor_prop  = trim((string)($data['valor_propuesto'] ?? ''));
        $valor_impl  = trim((string)($data['valor'] ?? ''));
        $fv          = normalizeDatetimeLocal($data['fechaVisita'] ?? null);
        $fp          = normalizeDateOnly($data['fechaPropuesta'] ?? null);
        $estado      = (int)($data['estado'] ?? 0);
        $motivo      = (isset($data['motivo']) && $data['motivo'] === '-') ? '-' : '';
        $is_priority = isset($data['is_priority']) ? 1 : 0;
        $lat         = trim((string)($data['latGestion'] ?? ''));
        $lng         = trim((string)($data['lngGestion'] ?? ''));

        if ($estado === 3) {
            $pregunta_val = '';
        } elseif ($estado === 1) {
            $pregunta_val = 'completado';
        } elseif ($estado === 2) {
            $pregunta_val = 'cancelado';
        } else {
            $pregunta_val = 'en proceso';
        }

        $valor_prop_db = ($valor_prop === '') ? null : $valor_prop;
        $valor_impl_db = ($valor_impl === '') ? null : $valor_impl;
        $lat_db        = ($lat === '') ? null : $lat;
        $lng_db        = ($lng === '') ? null : $lng;

        $stmt_update->bind_param(
            "sssssississiii",
            $material,
            $valor_prop_db,
            $valor_impl_db,
            $fv,
            $fp,
            $estado,
            $pregunta_val,
            $motivo,
            $is_priority,
            $lat_db,
            $lng_db,
            $userId,
            $id,
            $formulario_id,
            $local_id
        );

        if ($stmt_update->execute()) {
            $successCount++;
        }
    }

    $stmt_update->close();
    echo "<div class='alert alert-success'>Se actualizaron {$successCount} registros correctamente.</div>";
    exit;
}

// ----------------------------------------------------------------------
// 3. Mostrar contenido del modal
// ----------------------------------------------------------------------
$sql = "
    SELECT
        fq.*,
        u.usuario AS nombre_usuario
    FROM formularioQuestion fq
    LEFT JOIN usuario u ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
      AND fq.id_local = ?
    ORDER BY fq.fechaVisita ASC, fq.id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $formulario_id, $local_id);
$stmt->execute();
$result = $stmt->get_result();
$gestiones = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="modal-header">
  <h5 class="modal-title">Editar gestión — Local ID: <?= (int)$local_id ?></h5>
  <button type="button" class="close" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">

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
          <tr data-fq-id="<?= (int)$g['id'] ?>">
            <td><?= (int)$g['id'] ?></td>

            <td>
              <select name="usuario_id" class="form-control form-control-sm" required>
                <?php foreach ($ejecutores as $ej): ?>
                  <option value="<?= (int)$ej['id'] ?>" <?= ((int)$ej['id'] === (int)$g['id_usuario']) ? 'selected' : '' ?>>
                    <?= h($ej['usuario']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <select name="material" class="form-control form-control-sm" required>
                <?php foreach ($materiales as $m): ?>
                  <option value="<?= h($m['nombre']) ?>" <?= ($m['nombre'] === $g['material']) ? 'selected' : '' ?>>
                    <?= h($m['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <input type="number" step="any" name="valor_propuesto" value="<?= h($g['valor_propuesto'] ?? '') ?>" class="form-control form-control-sm">
            </td>

            <td>
              <input type="number" step="any" name="valor" value="<?= h($g['valor'] ?? '') ?>" class="form-control form-control-sm">
            </td>

            <td>
              <input
                type="datetime-local"
                name="fechaVisita"
                value="<?= !empty($g['fechaVisita']) ? h(date('Y-m-d\TH:i', strtotime((string)$g['fechaVisita']))) : '' ?>"
                class="form-control form-control-sm"
              >
            </td>

            <td>
              <input
                type="date"
                name="fechaPropuesta"
                value="<?= h($g['fechaPropuesta'] ?? '') ?>"
                class="form-control form-control-sm"
              >
            </td>

            <td style="width:140px">
              <select name="estado" class="form-control form-control-sm">
                <option value="0" <?= ((int)$g['estado'] === 0) ? 'selected' : '' ?>>En proceso</option>
                <option value="1" <?= ((int)$g['estado'] === 1) ? 'selected' : '' ?>>Completado</option>
                <option value="2" <?= ((int)$g['estado'] === 2) ? 'selected' : '' ?>>Cancelado</option>
                <option value="3" <?= ((int)$g['estado'] === 3) ? 'selected' : '' ?>>No gestionado</option>
              </select>
            </td>

            <td class="text-center">
              <input type="checkbox" name="motivo" value="-" <?= (($g['motivo'] ?? '') === '-') ? 'checked' : '' ?>>
            </td>

            <td class="text-center">
              <input type="checkbox" name="is_priority" <?= !empty($g['is_priority']) ? 'checked' : '' ?>>
            </td>

            <td>
              <input type="text" name="latGestion" value="<?= h($g['latGestion'] ?? '') ?>" class="form-control form-control-sm">
            </td>

            <td>
              <input type="text" name="lngGestion" value="<?= h($g['lngGestion'] ?? '') ?>" class="form-control form-control-sm">
            </td>

            <td>
              <button type="button" class="btn btn-success btn-sm btn-guardar-fila" data-fq-id="<?= (int)$g['id'] ?>">
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

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
          url: 'ajax_editar_gestion.php?formulario_id=' + encodeURIComponent(formularioId) + '&local_id=' + encodeURIComponent(localId),
          method: 'POST',
          data: data,
          dataType: 'json',
          success: function(response) {
            const $alertContainer = $('#gestion-alert-container');
            $alertContainer.empty();

            if (response.success) {
              $alertContainer.html(
                '<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                  response.message +
                  '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                '</div>'
              );
              $row.css('background-color', '#d4edda');
              setTimeout(function() { $row.css('background-color', ''); }, 1200);
            } else {
              $alertContainer.html(
                '<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                  response.message +
                  '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                '</div>'
              );
              $row.css('background-color', '#f8d7da');
              setTimeout(function() { $row.css('background-color', ''); }, 1200);
            }
          },
          error: function(xhr, status, error) {
            $('#gestion-alert-container').html(
              '<div class="alert alert-danger">Error de conexión: ' + error + '</div>'
            );
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

  <h5>Reasignar local a otro ejecutor</h5>
  <form method="post" id="reasignarLocalForm" class="form-inline mt-3">
    <input type="hidden" name="action" value="reassign_local">
    <input type="hidden" name="formulario_id" value="<?= (int)$formulario_id ?>">
    <input type="hidden" name="local_id" value="<?= (int)$local_id ?>">

    <div class="form-group mb-2 mr-2">
      <label for="nuevo_ejecutor_id" class="mr-2">Ejecutor:</label>
      <select name="nuevo_ejecutor_id" id="nuevo_ejecutor_id" class="form-control form-control-sm" required>
        <option value="">-- Seleccione --</option>
        <?php foreach ($ejecutores as $ej): ?>
          <option value="<?= (int)$ej['id'] ?>"><?= h($ej['usuario']) ?></option>
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
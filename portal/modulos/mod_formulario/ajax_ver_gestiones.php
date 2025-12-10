<?php
// ————————————————————————
// 1) EVITAR CUALQUIER SALIDA ANTES DE HTML
// ————————————————————————
if (ob_get_length()) {
    ob_clean();
}
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);


// ————————————————————————
// 2) SESIÓN Y CONEXIONES
// ————————————————————————
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session_data.php';

// Seguridad
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Acceso denegado.</div>";
    exit;
}

// Parámetros
$formulario_id = isset($_REQUEST['formulario_id']) ? intval($_REQUEST['formulario_id']) : 0;
$local_id      = isset($_REQUEST['local_id'])      ? intval($_REQUEST['local_id'])      : 0;
if (!$formulario_id || !$local_id) {
    echo "<div class='alert alert-danger'>Parámetros inválidos.</div>";
    exit;
}

// ————————————————————————
// 3) ACCIONES MUTATIVAS (POST → RESPUESTA FLASH)
// ————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action      = $_POST['action'];
    $target_user = intval($_POST['user_id'] ?? 0);

    switch ($action) {

      // — Borrar respuestas de encuesta
      case 'clear_responses':
        if (!$target_user) {
          echo "<div class='alert alert-danger'>Selecciona un ejecutor.</div>";
          exit;
        }
        // 1) Borrar respuestas de encuesta
        $stmt = $conn->prepare("
          DELETE fqr
            FROM form_question_responses fqr
       INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
           WHERE fq.id_formulario=? AND fqr.id_local=? AND fqr.id_usuario=?
        ");
        $stmt->bind_param("iii", $formulario_id, $local_id, $target_user);
        $ok1 = $stmt->execute();
        $stmt->close();
        // 2) Resetear countVisita en formularioQuestion
        $stmt2 = $conn->prepare("
          UPDATE formularioQuestion
             SET countVisita = 0
           WHERE id_formulario = ?
             AND id_local      = ?
             AND id_usuario    = ?
        ");
        $stmt2->bind_param("iii", $formulario_id, $local_id, $target_user);
        $ok2 = $stmt2->execute();
        $stmt2->close();
        if ($ok1 && $ok2) {
          echo "<div class='alert alert-success'>Encuesta recargada y visitas reseteadas.</div>";
        } else {
          echo "<div class='alert alert-danger'>Error al recargar encuesta.</div>";
        }
        exit;


      // — Recargar toda la gestión del ejecutor
      case 'reset_local':
        if (!$target_user) {
          echo "<div class='alert alert-danger'>Selecciona un ejecutor.</div>";
          exit;
        }
        // 1) Reset implementaciones
        $stmt1 = $conn->prepare("
          UPDATE formularioQuestion
             SET countVisita=0,
                 valor=NULL,
                 motivo=NULL,
                 observacion=NULL,
                 pregunta=NULL,
                 fechaVisita=NULL
           WHERE id_formulario=? AND id_local=? AND id_usuario=?
        ");
        $stmt1->bind_param("iii", $formulario_id, $local_id, $target_user);
        $ok1 = $stmt1->execute();
        $stmt1->close();
        // 2) Borrar respuestas
        $stmt2 = $conn->prepare("
          DELETE fqr
            FROM form_question_responses fqr
         INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
           WHERE fq.id_formulario=? AND fqr.id_local=? AND fqr.id_usuario=?
        ");
        $stmt2->bind_param("iii", $formulario_id, $local_id, $target_user);
        $ok2 = $stmt2->execute();
        $stmt2->close();
        echo ($ok1 && $ok2)
          ? "<div class='alert alert-success'>Gestión recargada.</div>"
          : "<div class='alert alert-danger'>Error al recargar gestión.</div>";
        exit;

      // — Eliminar una implementación completa
      case 'delete_impl':
        $impl_id = intval($_POST['id'] ?? 0);
        if ($impl_id) {
          // 1) Borrar fotos de disco
          $stmtF = $conn->prepare("SELECT url FROM fotoVisita WHERE id_formularioQuestion=?");
          $stmtF->bind_param("i", $impl_id);
          $stmtF->execute();
          $resF = $stmtF->get_result();
          while ($row = $resF->fetch_assoc()) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/' . ltrim($row['url'], '/');
            @unlink($path);
          }
          $stmtF->close();
          // 2) Borrar filas de fotoVisita
          $stmtD = $conn->prepare("DELETE FROM fotoVisita WHERE id_formularioQuestion=?");
          $stmtD->bind_param("i", $impl_id);
          $stmtD->execute();
          $stmtD->close();
          // 3) Borrar fila de formularioQuestion
          $stmtQ = $conn->prepare("
            DELETE FROM formularioQuestion
             WHERE id=? AND id_formulario=? AND id_local=?
          ");
          $stmtQ->bind_param("iii", $impl_id, $formulario_id, $local_id);
          $ok = $stmtQ->execute();
          $stmtQ->close();
          echo $ok
            ? "<div class='alert alert-success'>Implementación eliminada.</div>"
            : "<div class='alert alert-danger'>Error al eliminar implementación.</div>";
        } else {
          echo "<div class='alert alert-danger'>ID inválido.</div>";
        }
        exit;

      // — Eliminar una respuesta de encuesta individual
      case 'delete_resp':
        $resp_id = intval($_POST['id'] ?? 0);
        if ($resp_id) {
          $stmtR = $conn->prepare("DELETE FROM form_question_responses WHERE id=?");
          $stmtR->bind_param("i", $resp_id);
          $ok = $stmtR->execute();
          $stmtR->close();
          echo $ok
            ? "<div class='alert alert-success'>Respuesta eliminada.</div>"
            : "<div class='alert alert-danger'>Error al eliminar respuesta.</div>";
        } else {
          echo "<div class='alert alert-danger'>ID inválido.</div>";
        }
        exit;

      // — Limpiar un material conservando fotos
      case 'clear_material_keep_photos':
        $impl_id = intval($_POST['id'] ?? 0);
        if ($impl_id) {
          $stmtU = $conn->prepare("
            UPDATE formularioQuestion
               SET countVisita=0,
                   valor=NULL,
                   motivo=NULL,
                   observacion=NULL,
                   pregunta=NULL,
                   fechaVisita=NULL
             WHERE id=? AND id_formulario=? AND id_local=?
          ");
          $stmtU->bind_param("iii", $impl_id, $formulario_id, $local_id);
          $ok = $stmtU->execute();
          $stmtU->close();
          echo $ok
            ? "<div class='alert alert-success'>Material recargado (fotos conservadas).</div>"
            : "<div class='alert alert-danger'>Error al recargar material.</div>";
        } else {
          echo "<div class='alert alert-danger'>ID inválido.</div>";
        }
        exit;

      // — Limpiar un material (resetea y borra fotos)
      case 'clear_material':
        $impl_id = intval($_POST['id'] ?? 0);
        if ($impl_id) {
          $stmtU = $conn->prepare("
            UPDATE formularioQuestion
               SET countVisita=0,
                   valor=NULL,
                   motivo=NULL,
                   observacion=NULL,
                   pregunta=NULL,
                   fechaVisita=NULL
             WHERE id=? AND id_formulario=? AND id_local=?
          ");
          $stmtU->bind_param("iii", $impl_id, $formulario_id, $local_id);
          $ok = $stmtU->execute();
          $stmtU->close();
          $stmtF = $conn->prepare("SELECT url FROM fotoVisita WHERE id_formularioQuestion=?");
          $stmtF->bind_param("i", $impl_id);
          $stmtF->execute();
          $resF = $stmtF->get_result();
          while ($row = $resF->fetch_assoc()) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/' . ltrim($row['url'], '/');
            @unlink($path);
          }
          $stmtF->close();
          $stmtD = $conn->prepare("DELETE FROM fotoVisita WHERE id_formularioQuestion=?");
          $stmtD->bind_param("i", $impl_id);
          $stmtD->execute();
          $stmtD->close();
          echo $ok
            ? "<div class='alert alert-success'>Material limpiado.</div>"
            : "<div class='alert alert-danger'>Error al limpiar material.</div>";
        } else {
          echo "<div class='alert alert-danger'>ID inválido.</div>";
        }
        exit;

      // — Editar inline el nombre del material
      case 'update_material':
        $impl_id  = intval($_POST['id'] ?? 0);
        $material = trim($_POST['material'] ?? '');
        if ($impl_id && $material !== '') {
          $stmtM = $conn->prepare("
            UPDATE formularioQuestion
               SET material=?
             WHERE id=? AND id_formulario=? AND id_local=?
          ");
          $stmtM->bind_param("siii", $material, $impl_id, $formulario_id, $local_id);
          $ok = $stmtM->execute();
          $stmtM->close();
          echo $ok
            ? "<div class='alert alert-success'>Material actualizado.</div>"
            : "<div class='alert alert-danger'>Error al actualizar material.</div>";
        } else {
          echo "<div class='alert alert-danger'>Datos inválidos.</div>";
        }
        exit;

      // — Recargar solo implementaciones
      case 'reset_impl_all':
        if (!$target_user) {
          echo "<div class='alert alert-danger'>Selecciona un ejecutor.</div>";
          exit;
        }
        $stmt = $conn->prepare("
          UPDATE formularioQuestion
             SET countVisita=0,
                 valor=NULL,
                 motivo=NULL,
                 observacion=NULL,
                 pregunta=NULL,
                 fechaVisita=NULL
           WHERE id_formulario=? AND id_local=? AND id_usuario=?
        ");
        $stmt->bind_param("iii", $formulario_id, $local_id, $target_user);
        $ok1 = $stmt->execute();
        $stmt->close();
        

        echo $ok1
          ? "<div class='alert alert-success'>Implementaciones recargadas.</div>"
          : "<div class='alert alert-danger'>Error al recargar implementaciones.</div>";
        exit;

      // — Eliminar una foto individual
      case 'delete_photo':
        $photo_id = intval($_POST['id'] ?? 0);
        if ($photo_id) {
          $stmtF = $conn->prepare("SELECT url FROM fotoVisita WHERE id = ?");
          $stmtF->bind_param("i", $photo_id);
          $stmtF->execute();
          $resF = $stmtF->get_result()->fetch_assoc();
          $stmtF->close();
          if ($resF) {
            @unlink($_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/' . ltrim($resF['url'], '/'));
          }
          $stmtD = $conn->prepare("DELETE FROM fotoVisita WHERE id = ?");
          $stmtD->bind_param("i", $photo_id);
          $ok = $stmtD->execute();
          $stmtD->close();
          echo $ok
            ? "<div class='alert alert-success'>Foto eliminada.</div>"
            : "<div class='alert alert-danger'>Error al eliminar foto.</div>";
        } else {
          echo "<div class='alert alert-danger'>ID de foto inválido.</div>";
        }
        exit;
        
        case 'update_photo':
        $photo_id = intval($_POST['id'] ?? 0);
        if ($photo_id && isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK) {
          // 1) Obtener URL antigua
          $stmtF = $conn->prepare("SELECT url FROM fotoVisita WHERE id = ?");
          $stmtF->bind_param("i", $photo_id);
          $stmtF->execute();
          $old = $stmtF->get_result()->fetch_assoc();
          $stmtF->close();
          if ($old && $old['url']) {
            $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/' . ltrim($old['url'],'/');
            if (file_exists($oldPath)) @unlink($oldPath);
          }
          // 2) Guardar el nuevo archivo en la misma ruta
          $tmp     = $_FILES['file']['tmp_name'];
          $dest    = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/' . ltrim($old['url'],'/');
          if (move_uploaded_file($tmp, $dest)) {
            echo "<div class='alert alert-success'>Foto reemplazada correctamente.</div>";
          } else {
            echo "<div class='alert alert-danger'>Error al mover el archivo.</div>";
          }
        } else {
          echo "<div class='alert alert-danger'>No se recibió archivo válido.</div>";
        }
        exit;

    case 'add_photo':
        $impl_id = intval($_POST['id'] ?? 0);
        if ($impl_id && isset($_FILES['file']) && $_FILES['file']['error']===UPLOAD_ERR_OK) {
          // 1) Obtener el usuario (ejecutor) asignado a esta implementación
          $stmtU = $conn->prepare("
            SELECT id_usuario
              FROM formularioQuestion
             WHERE id = ?
             LIMIT 1
          ");
          $stmtU->bind_param("i", $impl_id);
          $stmtU->execute();
          $ejecutor = $stmtU->get_result()->fetch_assoc()['id_usuario'] ?? 0;
          $stmtU->close();

          // 2) Sacar el nombre de material y de ahí su ID (igual que antes)
          $stmtM = $conn->prepare("
            SELECT material
              FROM formularioQuestion
             WHERE id = ?
          ");
          $stmtM->bind_param("i", $impl_id);
          $stmtM->execute();
          $matName = $stmtM->get_result()->fetch_assoc()['material'] ?? '';
          $stmtM->close();

          $stmtMid = $conn->prepare("
            SELECT id
              FROM material
             WHERE nombre = ?
             LIMIT 1
          ");
          $stmtMid->bind_param("s", $matName);
          $stmtMid->execute();
          $material_id = $stmtMid->get_result()->fetch_assoc()['id'] ?? 0;
          $stmtMid->close();

          // 3) Crear carpeta uploads/fecha/material_ID
          $fecha   = date('Y-m-d');
          $relDir  = "uploads/$fecha/material_$material_id";
          $fullDir = $_SERVER['DOCUMENT_ROOT'] . "/visibility2/app/$relDir";
          if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

          // 4) Guardar archivo
          $ext  = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
          $name = "mat_" . uniqid("", true) . ".$ext";
          $dest = "$fullDir/$name";

          if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            // 5) Insertar registro con el EJECUTOR correcto
            $url = "$relDir/$name";
            $stmtI = $conn->prepare("
              INSERT INTO fotoVisita
                (url, id_formularioQuestion, id_usuario, id_material, id_formulario, id_local)
              VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtI->bind_param(
              "siiiii",
              $url,
              $impl_id,
              $ejecutor,        // aquí el usuario del ejecutor
              $material_id,
              $formulario_id,
              $local_id
            );
            $ok = $stmtI->execute();
            $stmtI->close();

            echo $ok
              ? "<div class='alert alert-success'>Foto añadida correctamente.</div>"
              : "<div class='alert alert-danger'>Error al registrar la foto en BD.</div>";
          } else {
            echo "<div class='alert alert-danger'>Error al mover el archivo.</div>";
          }
        } else {
          echo "<div class='alert alert-danger'>No se recibió un archivo válido.</div>";
        }
        exit;
        
      default:
        echo "<div class='alert alert-danger'>Acción desconocida.</div>";
        exit;
    }
}

// ————————————————————————
// 4) RECOGIDA DE DATOS PARA RENDERIZAR HTML
// ————————————————————————

// 4.1) Lista de usuarios que han gestionado (implementaciones o encuestas)
$stmt = $conn->prepare("
  SELECT uid, usuario FROM (
    SELECT fq.id_usuario AS uid, u.usuario
      FROM formularioQuestion fq
      JOIN usuario u ON u.id = fq.id_usuario
     WHERE fq.id_formulario=? AND fq.id_local=?
    UNION
    SELECT fqr.id_usuario AS uid, u2.usuario
      FROM form_question_responses fqr
      JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
      JOIN usuario u2 ON u2.id = fqr.id_usuario
     WHERE fq2.id_formulario=? AND fqr.id_local=?  
  ) tmp
  ORDER BY usuario ASC
");
$stmt->bind_param("iiii", $formulario_id, $local_id, $formulario_id, $local_id);
$stmt->execute();
$distinct_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selected_user = intval($_REQUEST['user_id'] ?? ($distinct_users[0]['uid'] ?? 0));

// 4.2) Conteos totales para paginación
$stmt = $conn->prepare("
  SELECT COUNT(*) FROM formularioQuestion
   WHERE id_formulario=? AND id_local=? AND id_usuario=?
");
$stmt->bind_param("iii", $formulario_id, $local_id, $selected_user);
$stmt->execute();
$stmt->bind_result($total_impl);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*)
    FROM form_question_responses fqr
    JOIN form_questions fq ON fq.id = fqr.id_form_question
   WHERE fq.id_formulario=? AND fqr.id_local=? AND fqr.id_usuario=?
");
$stmt->bind_param("iii", $formulario_id, $local_id, $selected_user);
$stmt->execute();
$stmt->bind_result($total_resp);
$stmt->fetch();
$stmt->close();

$page_impl     = max(1, intval($_GET['page_impl']     ?? 1));
$per_page_impl = max(5, intval($_GET['per_page_impl'] ?? 10));
$offset_impl   = ($page_impl - 1) * $per_page_impl;

$page_resp     = max(1, intval($_GET['page_resp']     ?? 1));
$per_page_resp = max(5, intval($_GET['per_page_resp'] ?? 10));
$offset_resp   = ($page_resp - 1) * $per_page_resp;

$tab = (isset($_GET['tab']) && $_GET['tab']==='encuesta') ? 'encuesta' : 'impl';

// 4.3) Consulta implementaciones sin agrupar fotos
$stmt = $conn->prepare("
  SELECT
    fq.id,
    u.usuario        AS nombre_usuario,
    fq.material,
    fq.valor_propuesto,
    fq.valor,
    fq.fechaVisita,
    fq.observacion
  FROM formularioQuestion fq
  LEFT JOIN usuario u    ON u.id = fq.id_usuario
 WHERE fq.id_formulario = ? AND fq.id_local = ? AND fq.id_usuario = ?
 ORDER BY fq.fechaVisita ASC
 LIMIT ? OFFSET ?
");
$stmt->bind_param("iiiii", $formulario_id, $local_id, $selected_user, $per_page_impl, $offset_impl);
$stmt->execute();
$rawImpl = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4.3.b) Para cada implementación, obtenemos sus fotos con ID y URL
$implementaciones = [];
foreach ($rawImpl as $impl) {
  $stmtF = $conn->prepare("SELECT id, url FROM fotoVisita WHERE id_formularioQuestion=?");
  $stmtF->bind_param("i", $impl['id']);
  $stmtF->execute();
  $resF = $stmtF->get_result();
  $fotos = [];
  while ($f = $resF->fetch_assoc()) {
    $fotos[] = $f;
  }
  $stmtF->close();
  $impl['fotos'] = $fotos;
  $implementaciones[] = $impl;
}

// 4.4) Consulta respuestas de encuesta
$stmt = $conn->prepare("
  SELECT
    fqr.id,
    u.usuario       AS nombre_usuario,
    fq.question_text,
    fqr.answer_text,
    fqr.created_at
  FROM form_question_responses fqr
  JOIN form_questions fq ON fq.id = fqr.id_form_question
  LEFT JOIN usuario u   ON u.id = fqr.id_usuario
 WHERE fq.id_formulario = ? AND fqr.id_local = ? AND fqr.id_usuario = ?
 ORDER BY fqr.created_at ASC
 LIMIT ? OFFSET ?
");
$stmt->bind_param("iiiii", $formulario_id, $local_id, $selected_user, $per_page_resp, $offset_resp);
$stmt->execute();
$respuestas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$uploadBaseUrl = '/visibility2/app/';
?>
<!-- Modal header -->
<div class="modal-header bg-primary text-white">
  <h5 class="modal-title">
    Gestión PDV <small class="text-light">Local #<?= htmlspecialchars($local_id, ENT_QUOTES) ?></small>
  </h5>
  <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">
  <div id="actionAlerts"></div>

  <div class="form-inline mb-3">
    <label for="select_ejecutor" class="mr-2">Ejecutor:</label>
    <select id="select_ejecutor" class="form-control">
      <?php foreach ($distinct_users as $u): ?>
        <option value="<?= intval($u['uid']) ?>"
          <?= $u['uid'] === $selected_user ? 'selected' : '' ?>>
          <?= htmlspecialchars($u['usuario'], ENT_QUOTES) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='impl' ? 'active' : ''?>" data-toggle="tab" href="#tab_impl">
        <i class="fas fa-tools"></i> Implementaciones
        <span class="badge badge-light"><?= $total_impl ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='encuesta' ? 'active' : ''?>" data-toggle="tab" href="#tab_encuesta">
        <i class="fas fa-question-circle"></i> Encuesta
        <span class="badge badge-light"><?= $total_resp ?></span>
      </a>
    </li>
  </ul>

  <div class="tab-content mt-3">

    <div id="tab_impl" class="tab-pane fade <?= $tab==='impl' ? 'show active' : ''?>">

      <?php if (count($implementaciones) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="thead-dark">
              <tr>
                <th>ID</th>
                <th>Material</th>
                <th>Propuesto</th>
                <th>Implementado</th>
                <th>Fecha Visita</th>
                <th>Fotos</th>
                <th>Obs.</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($implementaciones as $impl): ?>
              <tr data-id="<?= htmlspecialchars($impl['id'], ENT_QUOTES) ?>">
                <td><?= htmlspecialchars($impl['id'], ENT_QUOTES) ?></td>
                <td>
                  <div class="d-flex align-items-center">
                    <span class="material-text"><?= htmlspecialchars($impl['material'], ENT_QUOTES) ?></span>
                    <input type="text" class="form-control form-control-sm ml-2 material-input"
                           value="<?= htmlspecialchars($impl['material'], ENT_QUOTES) ?>"
                           style="display:none; width:120px;">
                    <button class="btn btn-sm btn-success ml-1 save-material" style="display:none;">
                      <i class="fas fa-save"></i>
                    </button>
                  </div>
                </td>
                <td><?= htmlspecialchars($impl['valor_propuesto'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($impl['valor'] ?? '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($impl['fechaVisita'] ?? '—', ENT_QUOTES) ?></td>
                <td>
                  <div class="d-flex flex-wrap">
                    <?php foreach ($impl['fotos'] as $foto):
                      $full = $uploadBaseUrl . ltrim($foto['url'], '/');
                      $photoId = (int)$foto['id'];
                    ?>
                    
                      <div class="photo-thumb-wrapper position-relative mr-1 mb-1"
                           data-photo-id="<?= $photoId ?>"
                           style="width:48px;height:48px;">
                        <img src="<?= htmlspecialchars($full, ENT_QUOTES) ?>"
                             class="photo-thumb rounded"
                             style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                             title="Ver foto">
                        <button class="btn btn-sm btn-danger delete-photo position-absolute"
                                style="top:2px; right:2px; padding:0 4px; line-height:1;"
                                title="Elminar foto">
                          ×
                        </button>
                          <button class="btn btn-sm btn-primary replace-photo position-absolute"
                                  style="top:2px; left:2px; padding:0 4px; line-height:1;"
                                  title="Reemplazar foto">
                            <i class="fa-solid fa-rotate-right"></i>
                          </button>
                          
                          
                      </div>
                    <?php endforeach; ?>
                    
                    <div class="d-flex flex-wrap">
                      <?php foreach ($impl['fotos'] as $foto): ?>
                        <!-- miniaturas existentes… -->
                      <?php endforeach; ?>
                    
                      <!-- este es el nuevo botón -->
                      <div class="photo-thumb-wrapper position-relative mr-1 mb-1"
                           data-impl-id="<?= htmlspecialchars($impl['id'], ENT_QUOTES) ?>"
                           style="width:48px;height:48px;">
                        <button class="btn btn-sm btn-success add-photo position-absolute"
                                style="top:2px; left:2px; padding:0 4px; line-height:1;"
                                title="Añadir foto para este material">
                          <i class="fas fa-plus"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </td>
                <td><?= nl2br(htmlspecialchars($impl['observacion'], ENT_QUOTES)) ?></td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-danger delete-impl" title="Eliminar">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="btn btn-warning clear-material" title="Limpiar">
                      <i class="fas fa-eraser"></i>
                    </button>
                    <button class="btn btn-info edit-material" title="Editar">
                      <i class="fas fa-edit"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Impl paginación">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $page_impl<=1?'disabled':'' ?>">
              <a class="page-link prev-impl" href="#" data-page="<?= $page_impl-1 ?>" data-resp="<?= $page_resp ?>">
                &laquo; Anterior
              </a>
            </li>
            <li class="page-item disabled">
              <span class="page-link">
                Página <?= $page_impl ?> de <?= ceil($total_impl/$per_page_impl) ?>
              </span>
            </li>
            <li class="page-item <?= $page_impl>=ceil($total_impl/$per_page_impl)?'disabled':'' ?>">
              <a class="page-link next-impl" href="#" data-page="<?= $page_impl+1 ?>" data-resp="<?= $page_resp ?>">
                Siguiente &raquo;
              </a>
            </li>
          </ul>
        </nav>

      <?php else: ?>
        <p class="text-center text-muted">No hay implementaciones registradas para este ejecutor.</p>
      <?php endif; ?>
    </div>

    <div id="tab_encuesta" class="tab-pane fade <?= $tab==='encuesta' ? 'show active' : ''?>">

      <?php if (count($respuestas) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="thead-dark">
              <tr>
                <th>ID</th>
                <th>Pregunta</th>
                <th>Respuesta</th>
                <th>Fecha</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($respuestas as $r): ?>
              <tr data-id="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>">
                <td><?= htmlspecialchars($r['id'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($r['question_text'], ENT_QUOTES) ?></td>
                <td>
                  <?php
                    $ans = $r['answer_text'];
                    $isImg = preg_match('/\.(jpe?g|png|gif|webp)$/i', $ans ?? '');
                    if ($isImg) {
                      $url = (strpos($ans, '/') === 0)
                             ? $ans
                             : '/visibility2/app/' . ltrim($ans, './');
                      echo '<img src="'.htmlspecialchars($url,ENT_QUOTES).'" class="photo-thumb rounded" style="width:48px;height:48px;cursor:pointer;">';
                    } else {
                      echo nl2br(htmlspecialchars($ans ?? '', ENT_QUOTES));
                    }
                  ?>
                </td>
                <td><?= htmlspecialchars($r['created_at'], ENT_QUOTES) ?></td>
                <td class="text-center">
                  <button class="btn btn-sm btn-danger delete-resp" title="Eliminar">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Encuesta paginación">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $page_resp<=1?'disabled':'' ?>">
              <a class="page-link prev-resp" href="#" data-page="<?= $page_resp-1 ?>" data-impl="<?= $page_impl ?>">
                &laquo; Anterior
              </a>
            </li>
            <li class="page-item disabled">
              <span class="page-link">
                Página <?= $page_resp ?> de <?= ceil($total_resp/$per_page_resp) ?>
              </span>
            </li>
            <li class="page-item <?= $page_resp>=ceil($total_resp/$per_page_resp)?'disabled':'' ?>">
              <a class="page-link next-resp" href="#" data-page="<?= $page_resp+1 ?>" data-impl="<?= $page_impl ?>">
                Siguiente &raquo;
              </a>
            </li>
          </ul>
        </nav>

        <div class="text-center mt-3">
          <button class="btn btn-outline-danger" id="clear-resps">
            <i class="fas fa-trash"></i> Borrar todas las respuestas
          </button>
        </div>
      <?php else: ?>
        <p class="text-center text-muted">No hay respuestas de encuesta para este ejecutor.</p>
      <?php endif; ?>

    </div>
  </div>
</div>

<div class="modal-footer">
  <button class="btn btn-warning" id="reset-local">
    <i class="fas fa-sync"></i> Recargar toda la gestión
  </button>
  <button class="btn btn-warning ml-2" id="reset-materials">
    <i class="fas fa-eraser"></i> Recargar solo implementaciones
  </button>
  <button class="btn btn-warning ml-2" id="reset-encuesta">
    <i class="fas fa-trash"></i> Recargar solo encuesta
  </button>
  <button class="btn btn-secondary ml-auto" data-dismiss="modal">
    <i class="fas fa-times"></i> Cerrar
  </button>
</div>

<!-- Confirmation Modal Reutilizable -->
<div class="modal fade" id="confirmActionModal" tabindex="-1" role="dialog" aria-labelledby="confirmActionLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title" id="confirmActionLabel">Confirmar acción</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="confirmActionCancel">Cancelar</button>
        <button type="button" class="btn btn-danger" id="confirmActionOk">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="modal fade" id="photoModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content p-2 bg-dark">
      <img id="photoModalImg" src="" class="img-fluid rounded" />
    </div>
  </div>
</div>

<style>
.photo-thumb-wrapper {
  position: relative;
  overflow: hidden;
}
.photo-thumb-wrapper .loader {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 100;
}    
    
</style>


<script>
// — ACCESSIBLE FUNCTIONS PARA INTERACCIÓN —

var currentPageImpl = <?= $page_impl ?>;
var currentPageResp = <?= $page_resp ?>;
var currentTab      = '<?= $tab ?>';

function showConfirm(msg, callback) {
  $('#confirmActionModal .modal-body').text(msg);
  $('#confirmActionOk, #confirmActionCancel').off('click');
  $('#confirmActionOk').on('click', function() {
    $('#confirmActionModal').modal('hide');
    callback(true);
  });
  $('#confirmActionCancel').on('click', function() {
    $('#confirmActionModal').modal('hide');
    callback(false);
  });
  $('#confirmActionModal').modal('show');
}

function flashAlert(html) {
  // 1) Añadimos la alerta oculta
  var $alert = $(html)
    .appendTo('#actionAlerts')
    .hide()
    .fadeIn(200);

  // 2) Tras 3 segundos, la desvanecemos y eliminamos
  setTimeout(function(){
    $alert
      .fadeOut(500, function(){
        $(this).remove();
      });
  }, 3000);
}
function loadGestiones(tab, pageImpl, pageResp) {
  $.get('ajax_ver_gestiones.php', {
    formulario_id: <?= $formulario_id ?>,
    local_id:      <?= $local_id ?>,
    user_id:       $('#select_ejecutor').val(),
    page_impl:     pageImpl,
    page_resp:     pageResp,
    tab:           tab
  }, function(html) {
    $('#gestionesModalContent').html(html);
    $('.nav-tabs a[href="#tab_' + tab + '"]').tab('show');
  });
}

$(document).on('change','#select_ejecutor',function(){ loadGestiones('impl',1,1); });
$(document).on('click','.prev-impl, .next-impl',function(e){ e.preventDefault(); loadGestiones('impl', $(this).data('page'), $(this).data('resp')); });
$(document).on('click','.prev-resp, .next-resp',function(e){ e.preventDefault(); loadGestiones('encuesta', $(this).data('impl'), $(this).data('page')); });

$(document).on('click','#clear-resps',function(){
  showConfirm('¿Borrar TODAS las respuestas de este ejecutor?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'clear_responses',
      user_id:       $('#select_ejecutor').val()
    }, function(html){
      flashAlert(html);
      loadGestiones('encuesta',1,1);
    });
  });
});

$(document).on('click','#reset-local',function(){
  showConfirm('¿Recargar TODA la gestión de este ejecutor?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'reset_local',
      user_id:       $('#select_ejecutor').val()
    }, function(html){
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        // después del reload queremos quedar en la pestaña de implementaciones
        setTimeout(()=>reloadAndReopenGestiones('impl'), 400);
      }
    });
  });
});

$(document).on('click','#reset-materials',function(){
  showConfirm('¿Recargar SOLO las implementaciones de este ejecutor?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'reset_impl_all',
      user_id:       $('#select_ejecutor').val()
    }, function(html){
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        setTimeout(()=>reloadAndReopenGestiones('impl'), 400);
      }
    });
  });
});

$(document).on('click','#reset-encuesta',function(){
  showConfirm('¿Recargar SOLO las respuestas de encuesta de este ejecutor?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'clear_responses',
      user_id:       $('#select_ejecutor').val()
    }, function(html){
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        setTimeout(()=>reloadAndReopenGestiones('encuesta'), 400);
      }
    });
  });
});
$(document).on('click','.delete-impl',function(){
  var id = $(this).closest('tr').data('id');
  showConfirm('¿Eliminar esta implementación?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'delete_impl',
      id:            id
    }, function(html){
      flashAlert(html);
      loadGestiones(currentTab, currentPageImpl, currentPageResp);
    });
  });
});

$(document).on('click','.clear-material', function(){
  var id = $(this).closest('tr').data('id');
  showConfirm(
    '¿Borrar también las fotos?\n OK: recargar material y fotos Cancelar: recargar solo material (fotos conservadas)',
    function(ok){
      var action = ok ? 'clear_material' : 'clear_material_keep_photos';
      $.post('ajax_ver_gestiones.php',{
        formulario_id: <?= $formulario_id ?>,
        local_id:      <?= $local_id ?>,
        action:        action,
        id:            id
      }, function(html){
        flashAlert(html);
        loadGestiones(currentTab, currentPageImpl, currentPageResp);
      });
    }
  );
});

$(document).on('click','.edit-material',function(){
  var $row = $(this).closest('tr');
  $row.find('.material-text').hide();
  $row.find('.material-input, .save-material').show();
});

$(document).on('click','.save-material',function(){
  var $row = $(this).closest('tr'),
      id   = $row.data('id'),
      mat  = $row.find('.material-input').val();
  $.post('ajax_ver_gestiones.php',{
    formulario_id: <?= $formulario_id ?>,
    local_id:      <?= $local_id ?>,
    action:        'update_material',
    id:            id,
    material:      mat
  }, function(html){
    flashAlert(html);
    loadGestiones('impl',<?= $page_impl ?>,<?= $page_resp ?>);
  });
});

$(document).on('click','.delete-resp',function(){
  var id = $(this).closest('tr').data('id');
  showConfirm('¿Eliminar esta respuesta?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'delete_resp',
      id:            id
    }, function(html){
      flashAlert(html);
      loadGestiones('encuesta',<?= $page_impl ?>,<?= $page_resp ?>);
    });
  });
});

$(document).on('click','.photo-thumb',function(){
  $('#photoModalImg').attr('src', $(this).attr('src'));
  $('#photoModal').modal('show');
});

$(document).on('click','.delete-photo',function(e){
  e.stopPropagation();
  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      photoId  = $wrapper.data('photo-id');
  showConfirm('¿Eliminar esta foto?', function(ok){
    if (!ok) return;
    $.post('ajax_ver_gestiones.php',{
      formulario_id: <?= $formulario_id ?>,
      local_id:      <?= $local_id ?>,
      action:        'delete_photo',
      id:            photoId
    }, function(html){
      flashAlert(html);
      $wrapper.remove();
    });
  });
});


$(document).off('click','.replace-photo');

// hadnler para reemplazar foto

$(document).on('click','.replace-photo', function(e){
  e.stopPropagation();
  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      photoId  = $wrapper.data('photo-id');

  // Creo el input y bind one-time al cambio
  var $input = $('<input type="file" accept="image/*" style="display:none">')
    .one('change', function(){
      var file = this.files[0];
      if (!file) return;
      // creo el loader y lo inserto
      var $loader = $(
        '<div class="loader">' +
          '<div class="spinner-border spinner-border-sm text-primary" role="status">' +
            '<span class="sr-only">Cargando...</span>' +
          '</div>' +
        '</div>'
      ).appendTo($wrapper);

      var fd = new FormData();
      fd.append('action', 'update_photo');
      fd.append('id', photoId);
      fd.append('formulario_id', <?= $formulario_id ?>);
      fd.append('local_id',      <?= $local_id      ?>);
      fd.append('file', file);

      $.ajax({
        url: 'ajax_ver_gestiones.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        beforeSend: function(){
          // por si acaso; loader ya insertado
        },
        success: function(html){
          flashAlert(html);
          // actualizo miniatura con FileReader
          var reader = new FileReader();
          reader.onload = function(ev){
            $wrapper.find('img.photo-thumb').attr('src', ev.target.result);
          };
          reader.readAsDataURL(file);
        },
        error: function(){
          flashAlert('<div class="alert alert-danger">Error al reemplazar foto.</div>');
        },
        complete: function(){
          $loader.remove();    // quito el spinner
        }
      });

      // elimino el input del DOM
      $input.remove();
    })
    .appendTo('body')
    .click();
});

$(document).on('click', '.add-photo', function(e){
  e.stopPropagation();
  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      implId   = $wrapper.data('impl-id');

  // 1) Creamos un input oculto que sólo escucha UN cambio
  var $input = $('<input type="file" accept="image/*" style="display:none">')
    .one('change', function(){
      var file = this.files[0];
      if (!file) {
        $input.remove();
        return;
      }

      // 2) Indicador de carga
      var $spinner = $('<div class="spinner-border spinner-border-sm text-primary loader" role="status"></div>');
      $wrapper.append($spinner);

      // 3) Montamos FormData y AJAX
      var fd = new FormData();
      fd.append('action',        'add_photo');
      fd.append('id',            implId);
      fd.append('formulario_id', <?= $formulario_id ?>);
      fd.append('local_id',      <?= $local_id ?>);
      fd.append('file',          file);

      $.ajax({
        url: 'ajax_ver_gestiones.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false
      }).always(function(){
        $spinner.remove();
      })
      .done(function(html){
        flashAlert(html);
        // 4) Previsualizamos la miniatura
        var reader = new FileReader();
        reader.onload = function(ev){
          $wrapper.before(
            '<img src="'+ev.target.result+'" class="rounded mr-1 mb-1" '+
            'style="width:48px;height:48px;object-fit:cover;cursor:pointer;" '+
            'onclick="$(\'#photoModalImg\').attr(\'src\',\''+ev.target.result+'\');'+
                     '$(\'#photoModal\').modal(\'show\');">'
          );
        };
        reader.readAsDataURL(file);
      }).fail(function(){
        flashAlert('<div class="alert alert-danger">Error al subir la foto.</div>');
      });

      $input.remove();
    })
    .appendTo('body')
    .click();
});

function reloadAndReopenGestiones(tabToShow){
  const url = new URL(window.location.href);

  // dejamos al usuario en la pestaña "agregar-entradas"
  url.searchParams.set('active_tab', 'agregar-entradas');

  // banderas para reabrir el modal al volver
  url.searchParams.set('open_modal', 'gestiones');
  url.searchParams.set('local_id',      String(<?= $local_id ?>));
  url.searchParams.set('user_id',       $('#select_ejecutor').val() || '');
  url.searchParams.set('tab',           tabToShow || 'impl');
  url.searchParams.set('page_impl',     String(currentPageImpl || 1));
  url.searchParams.set('page_resp',     String(currentPageResp || 1));

  window.location.href = url.toString();
}

</script>

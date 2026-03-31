<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit("Sesión expirada"); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$method = $_SERVER['REQUEST_METHOD'];
$idForm = isset($_REQUEST['idForm']) ? (int)$_REQUEST['idForm'] : 0;
if ($idForm <= 0) { http_response_code(400); exit("Formulario inválido"); }

// ---------- Helpers ----------
function getQuestion($conn, $idQ, $idForm) {
  $st = $conn->prepare("SELECT * FROM form_questions WHERE id=? AND id_formulario=? LIMIT 1");
  $st->bind_param("ii", $idQ, $idForm);
  $st->execute();
  $res = $st->get_result()->fetch_assoc();
  $st->close();
  return $res ?: null;
}
function getOptions($conn, $idQ) {
  $st = $conn->prepare("SELECT * FROM form_question_options WHERE id_form_question=? ORDER BY sort_order ASC, id ASC");
  $st->bind_param("i", $idQ);
  $st->execute();
  $rs = $st->get_result(); $out=[];
  while($r=$rs->fetch_assoc()) $out[]=$r;
  $st->close();
  return $out;
}
function renderDependencySelect($conn, $idForm, $selectedOptId) {
  $ht = '<option value="">-- Sin dependencia --</option>';
  $st = $conn->prepare("
    SELECT fq.id, fq.question_text, fqo.id AS opt_id, fqo.option_text
    FROM form_questions fq
    JOIN form_question_options fqo ON fqo.id_form_question = fq.id
    WHERE fq.id_formulario = ? AND fq.id_question_type IN (1,2,3)
    ORDER BY fq.sort_order, fqo.sort_order
  ");
  $st->bind_param("i", $idForm);
  $st->execute();
  $st->bind_result($qid, $qtxt, $oid, $otxt);
  $qgroup = -1; $og = '';
  while($st->fetch()){
    if ($qgroup !== $qid) {
      if ($og) $ht .= '</optgroup>';
      $og = '<optgroup label="P: '.htmlspecialchars($qtxt,ENT_QUOTES).'">';
      $ht .= $og; $qgroup = $qid;
    }
    $sel = ($selectedOptId && (int)$selectedOptId===(int)$oid) ? ' selected' : '';
    $ht .= '<option value="'.$oid.'"'.$sel.'>Opción: '.htmlspecialchars($otxt,ENT_QUOTES).'</option>';
  }
  if ($og) $ht .= '</optgroup>';
  $st->close();
  return $ht;
}

// ---------- POST: guardar ----------
if ($method === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  $idQ = isset($_POST['question_id']) ? (int)$_POST['question_id'] : 0;
  if ($idQ <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Pregunta inválida']); exit(); }
  $q = getQuestion($conn, $idQ, $idForm);
  if (!$q) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Pregunta no encontrada']); exit(); }

  $question_text     = trim($_POST['question_text'] ?? '');
  $id_question_type  = (int)($_POST['id_question_type'] ?? 0);
  $is_required       = isset($_POST['is_required']) ? 1 : 0;
  $is_valued         = isset($_POST['is_valued']) ? 1 : 0;
  $dependency_option = (isset($_POST['dependency_option']) && $_POST['dependency_option']!=='') ? (int)$_POST['dependency_option'] : null;

  if ($question_text==='' || $id_question_type<=0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Datos incompletos']); exit(); }

  // Validar dependencia
  if (!is_null($dependency_option)) {
    $st = $conn->prepare("
      SELECT 1
      FROM form_question_options o
      JOIN form_questions fq ON fq.id = o.id_form_question
      WHERE o.id=? AND fq.id_formulario=? LIMIT 1
    ");
    $st->bind_param("ii", $dependency_option, $idForm);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    if (!$ok) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Dependencia inválida']); exit(); }
  }

  // Directorio de imágenes
  $uploadDirServer = $_SERVER['DOCUMENT_ROOT'] . '/uploads/opciones/';
  $uploadDirDB     = '/uploads/opciones/';
  if (!is_dir($uploadDirServer)) { @mkdir($uploadDirServer, 0755, true); }

  // Capturar payload de orden
  $sort_existing = isset($_POST['sort_existing']) && is_array($_POST['sort_existing']) ? $_POST['sort_existing'] : [];
  $sort_new      = isset($_POST['sort_new']) && is_array($_POST['sort_new']) ? $_POST['sort_new'] : [];

  $conn->begin_transaction();
  try {
    // 1) Actualizar pregunta (desvincular del set)
    $st = $conn->prepare("
      UPDATE form_questions
      SET question_text=?, id_question_type=?, is_required=?, id_dependency_option=?, is_valued=?, id_question_set_question=NULL
      WHERE id=? AND id_formulario=?
    ");
    $st->bind_param("siiiiii", $question_text, $id_question_type, $is_required, $dependency_option, $is_valued, $idQ, $idForm);
    $st->execute();
    $st->close();

    // 2) Sincronizar opciones
    $existing = getOptions($conn, $idQ);
    $existingById = [];
    foreach ($existing as $op) $existingById[(int)$op['id']] = $op;

    // a) Borrado (opciones existentes que no vienen en options_existing)
    $postedExisting = isset($_POST['options_existing']) && is_array($_POST['options_existing']) ? $_POST['options_existing'] : [];
    $postedExistingIds = array_map('intval', array_keys($postedExisting));
    $toDelete = array_diff(array_keys($existingById), $postedExistingIds);

    if ($id_question_type === 1) {
      // Para Sí/No no borrar (las primeras 2 son fijas)
      $toDelete = [];
    }

    if (!empty($toDelete)) {
      $in = implode(',', array_fill(0, count($toDelete), '?'));
      $types = str_repeat('i', count($toDelete));
      // Limpiar dependencias de otras preguntas que apunten a estas opciones
      $sqlDep = "UPDATE form_questions SET id_dependency_option=NULL WHERE id_dependency_option IN ($in)";
      $st = $conn->prepare($sqlDep);
      $st->bind_param($types, ...$toDelete);
      $st->execute();
      $st->close();

      $sql = "DELETE FROM form_question_options WHERE id IN ($in) AND id_form_question=?";
      $types2 = $types.'i';
      $params = array_merge($toDelete, [$idQ]);
      $st = $conn->prepare($sql);
      $st->bind_param($types2, ...$params);
      $st->execute();
      $st->close();
      foreach ($toDelete as $did) unset($existingById[(int)$did]);
    }

    // b) Actualizar existentes
    if (!empty($postedExisting)) {
      foreach ($postedExisting as $optIdStr => $newText) {
        $optId = (int)$optIdStr;
        if (!isset($existingById[$optId])) continue;

        $newText = trim((string)$newText);
        if ($newText === '') $newText = $existingById[$optId]['option_text'];

        $desiredSort = isset($sort_existing[$optIdStr]) ? (int)$sort_existing[$optIdStr] : (int)$existingById[$optId]['sort_order'];

        // Imagen nueva?
        $ref = $existingById[$optId]['reference_image'] ?? '';
        if (isset($_FILES['image_existing']['name'][$optId]) && $_FILES['image_existing']['error'][$optId] === UPLOAD_ERR_OK) {
          $tmp  = $_FILES['image_existing']['tmp_name'][$optId];
          $name = basename($_FILES['image_existing']['name'][$optId]);
          $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          $safe = uniqid('opt_', true).'.'.$ext;
          if (move_uploaded_file($tmp, $uploadDirServer.$safe)) $ref = $uploadDirDB.$safe;
        }

        $changedContent = ($newText !== $existingById[$optId]['option_text']) ||
                          ($ref !== ($existingById[$optId]['reference_image'] ?? ''));

        if ($changedContent) {
          $st = $conn->prepare("
            UPDATE form_question_options
            SET option_text=?, reference_image=?, sort_order=?, id_question_set_option=NULL
            WHERE id=? AND id_form_question=?
          ");
          $st->bind_param("ssiii", $newText, $ref, $desiredSort, $optId, $idQ);
        } else {
          $st = $conn->prepare("
            UPDATE form_question_options
            SET option_text=?, reference_image=?, sort_order=?
            WHERE id=? AND id_form_question=?
          ");
          $st->bind_param("ssiii", $newText, $ref, $desiredSort, $optId, $idQ);
        }
        $st->execute();
        $st->close();
      }
    }

    // c) Insertar nuevas
    $newTexts = isset($_POST['options_new']) && is_array($_POST['options_new']) ? $_POST['options_new'] : [];
    if (!empty($newTexts)) {
      foreach ($newTexts as $tmpIdx => $txt) {
        $txt = trim((string)$txt); if ($txt==='') continue;
        $desiredSort = isset($sort_new[$tmpIdx]) ? (int)$sort_new[$tmpIdx] : null;
        $ref = '';
        if (isset($_FILES['image_new']['name'][$tmpIdx]) && $_FILES['image_new']['error'][$tmpIdx] === UPLOAD_ERR_OK) {
          $tmp  = $_FILES['image_new']['tmp_name'][$tmpIdx];
          $name = basename($_FILES['image_new']['name'][$tmpIdx]);
          $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
          $safe = uniqid('opt_', true).'.'.$ext;
          if (move_uploaded_file($tmp, $uploadDirServer.$safe)) $ref = $uploadDirDB.$safe;
        }
        if ($desiredSort === null) {
          $st = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM form_question_options WHERE id_form_question=?");
          $st->bind_param("i", $idQ);
          $st->execute();
          $st->bind_result($desiredSort);
          $st->fetch();
          $st->close();
        }

        $st = $conn->prepare("
          INSERT INTO form_question_options (id_form_question, option_text, sort_order, reference_image)
          VALUES (?,?,?,?)
        ");
        $st->bind_param("isis", $idQ, $txt, $desiredSort, $ref);
        $st->execute();
        $st->close();
      }
    }

    // d) Normalizar sort_order (1..N)
    $opsNow = getOptions($conn, $idQ);
    $i = 1;
    $st = $conn->prepare("UPDATE form_question_options SET sort_order=? WHERE id=? AND id_form_question=?");
    foreach ($opsNow as $op) {
      $st->bind_param("iii", $i, $op['id'], $idQ);
      $st->execute();
      $i++;
    }
    $st->close();

    $conn->commit();
    echo json_encode(['ok'=>true,'message'=>'Pregunta actualizada correctamente.']);
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit();
}

// ---------- GET: render modal ----------
$idQ = isset($_GET['idFormQuestion']) ? (int)$_GET['idFormQuestion'] : 0;
if ($idQ <= 0) { http_response_code(400); exit("Pregunta inválida"); }
$q = getQuestion($conn, $idQ, $idForm);
if (!$q) { http_response_code(404); exit("Pregunta no encontrada"); }
$ops = in_array((int)$q['id_question_type'], [1,2,3], true) ? getOptions($conn, $q['id']) : [];
$depHtml = renderDependencySelect($conn, $idForm, $q['id_dependency_option']);
?>
<form method="post" action="ajax_editar_form_question.php" enctype="multipart/form-data" id="editFormQuestionForm">
  <input type="hidden" name="idForm" value="<?= (int)$idForm ?>">
  <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">

  <div class="modal-header py-2">
    <h6 class="modal-title">Editar pregunta</h6>
    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
  </div>

  <div class="modal-body">
    <div class="form-group">
      <label>Texto de la Pregunta</label>
      <input type="text" name="question_text" class="form-control" value="<?= htmlspecialchars($q['question_text'],ENT_QUOTES) ?>" required>
    </div>

    <div class="form-row">
      <div class="form-group col-md-4">
        <label>Tipo</label>
        <select name="id_question_type" class="form-control" required id="editTypeSel">
          <?php
            $tipos=[1=>"Sí/No",2=>"Selección única",3=>"Selección múltiple",4=>"Texto",5=>"Numérico",6=>"Fecha",7=>"Foto"];
            foreach($tipos as $tid=>$tname){
              $sel = ((int)$q['id_question_type']===$tid)?' selected':'';
              echo '<option value="'.$tid.'"'.$sel.'>'.htmlspecialchars($tname,ENT_QUOTES).'</option>';
            }
          ?>
        </select>
      </div>
      <div class="form-group col-md-4">
        <label>Depende de (opcional)</label>
        <select name="dependency_option" class="form-control"><?= $depHtml ?></select>
      </div>
      <div class="form-group col-md-4">
        <div class="custom-control custom-checkbox mt-4">
          <input type="checkbox" class="custom-control-input" id="editReq" name="is_required" value="1" <?= $q['is_required']?'checked':'' ?>>
          <label class="custom-control-label" for="editReq">¿Requerida?</label>
        </div>
        <div class="custom-control custom-checkbox mt-2" id="editValWrap" style="display:<?= in_array((int)$q['id_question_type'],[2,3],true)?'block':'none' ?>;">
          <input type="checkbox" class="custom-control-input" id="editVal" name="is_valued" value="1" <?= $q['is_valued']?'checked':'' ?>>
          <label class="custom-control-label" for="editVal">¿Valorizada?</label>
        </div>
      </div>
    </div>

    <div id="editOptionsBlock" style="display:<?= in_array((int)$q['id_question_type'],[1,2,3],true)?'block':'none' ?>;">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="mb-0">Opciones</h6>
        <small class="text-muted"><i class="fa fa-arrows-alt-v"></i> Arrastra para reordenar</small>
      </div>
      <div id="editOptionsContainer" class="mt-2">
        <?php if (in_array((int)$q['id_question_type'], [1,2,3], true) && $ops): ?>
          <?php foreach ($ops as $i=>$op): ?>
            <div class="option-block mb-3 p-2 border rounded d-flex align-items-start"
                 data-kind="existing" data-id="<?= (int)$op['id'] ?>"
                 <?= ((int)$q['id_question_type']===1 && $i<2)?'data-fixed="1"':'' ?>>
              <span class="drag-handle mr-2" style="cursor:grab; font-size:18px; line-height:36px;"><i class="fa fa-grip-vertical"></i></span>
              <div class="flex-grow-1">
                <div class="form-group mb-2">
                  <label class="mb-1">Texto de la opción</label>
                  <input type="text" class="form-control" name="options_existing[<?= (int)$op['id'] ?>]"
                         value="<?= htmlspecialchars($op['option_text'],ENT_QUOTES) ?>"
                         <?= ((int)$q['id_question_type']===1 && $i<2)?'readonly':'' ?>>
                </div>
                <div class="form-group mb-0">
                  <label class="mb-1">Imagen (opcional)</label>
                  <input type="file" class="form-control-file opt-image-input" name="image_existing[<?= (int)$op['id'] ?>]" accept="image/*">
                  <?php if (!empty($op['reference_image'])): ?>
                    <div class="mt-1">
                      <img src="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>" alt="actual"
                           class="img-thumbnail opt-thumb" style="max-width: 120px; cursor:zoom-in"
                           onclick="window.open(this.src,'_blank')">
                    </div>
                  <?php else: ?>
                    <img src="" class="img-thumbnail opt-thumb mt-1" style="display:none; max-width:120px">
                  <?php endif; ?>
                </div>
              </div>
              <?php if (!((int)$q['id_question_type']===1 && $i<2)): ?>
                <button type="button" class="btn btn-sm btn-danger ml-2" onclick="this.closest('.option-block').remove()">Eliminar</button>
              <?php endif; ?>
              <input type="hidden" name="sort_existing[<?= (int)$op['id'] ?>]" value="<?= (int)$op['sort_order'] ?>" class="sort-holder">
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <button class="btn btn-sm btn-secondary" type="button" id="btnAddEditOpt">+ Agregar opción</button>
      <small class="text-muted d-block mt-1">Para Sí/No, las dos primeras opciones son fijas.</small>
    </div>
  </div>

  <div class="modal-footer py-2">
    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
    <button type="submit" class="btn btn-primary">Guardar</button>
  </div>
</form>

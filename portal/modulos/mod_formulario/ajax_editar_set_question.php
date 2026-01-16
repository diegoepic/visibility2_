<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { echo "No autorizado."; exit(); }

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// DEBUG (apaga en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ============================================================
   Helpers SQL
   ============================================================ */

function fetchQuestion(mysqli $conn, int $idQ, int $idSet){
  $stmt = $conn->prepare("SELECT * FROM question_set_questions WHERE id=? AND id_question_set=?");
  $stmt->bind_param("ii", $idQ, $idSet);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $res ?: null;
}

function fetchOptionsQ(mysqli $conn, int $idQ){
  $stmt = $conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question=? ORDER BY sort_order ASC, id ASC");
  $stmt->bind_param("i", $idQ);
  $stmt->execute();
  $rows=[]; $res = $stmt->get_result();
  while($r=$res->fetch_assoc()) $rows[]=$r;
  $stmt->close();
  return $rows;
}

function fetchSetQuestions(mysqli $conn, int $idSet){
  $stmt = $conn->prepare("SELECT * FROM question_set_questions WHERE id_question_set=?");
  $stmt->bind_param("i", $idSet);
  $stmt->execute();
  $rows=[]; $res = $stmt->get_result();
  while($r=$res->fetch_assoc()) $rows[]=$r;
  $stmt->close();
  return $rows;
}

function optionsMapByQuestion(mysqli $conn, int $idSet){
  $stmt = $conn->prepare("
    SELECT o.id, o.id_question_set_question
    FROM question_set_options o
    JOIN question_set_questions q ON q.id = o.id_question_set_question
    WHERE q.id_question_set = ?
  ");
  $stmt->bind_param("i", $idSet);
  $stmt->execute();
  $res=$stmt->get_result();
  $map=[]; while($r=$res->fetch_assoc()) $map[(int)$r['id']] = (int)$r['id_question_set_question'];
  $stmt->close();
  return $map; // id_opcion => id_pregunta_padre
}

/** Descendientes recursivos de una pregunta (ids de preguntas) */
function getDescendants(mysqli $conn, int $idSet, int $qid){
  $desc = [];

  // opciones de esta pregunta
  $ops = [];
  $st = $conn->prepare("SELECT id FROM question_set_options WHERE id_question_set_question=?");
  $st->bind_param("i", $qid);
  $st->execute(); $r = $st->get_result(); while($row=$r->fetch_assoc()) $ops[] = (int)$row['id'];
  $st->close();

  if (empty($ops)) return $desc;

  $in = implode(',', array_fill(0, count($ops), '?'));
  $types = str_repeat('i', count($ops));
  $sql = "SELECT id FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st = $conn->prepare($sql);
  $st->bind_param('i'.$types, $idSet, ...$ops);
  $st->execute(); $r=$st->get_result();
  $children=[]; while($row=$r->fetch_assoc()){ $cid=(int)$row['id']; $children[]=$cid; $desc[]=$cid; }
  $st->close();

  foreach($children as $c){
    $desc = array_merge($desc, getDescendants($conn, $idSet, $c));
  }
  return array_values(array_unique($desc));
}

/** Hijos directos de una pregunta (dependen de cualquiera de sus opciones) */
function getImmediateChildren(mysqli $conn, int $idSet, int $qid){
  $ops = [];
  $st = $conn->prepare("SELECT id FROM question_set_options WHERE id_question_set_question=?");
  $st->bind_param("i", $qid);
  $st->execute(); $r=$st->get_result(); while($row=$r->fetch_assoc()) $ops[]=(int)$row['id'];
  $st->close();
  if (empty($ops)) return [];

  $in = implode(',', array_fill(0, count($ops), '?'));
  $types = str_repeat('i', count($ops));
  $sql = "SELECT id FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st = $conn->prepare($sql);
  $st->bind_param('i'.$types, $idSet, ...$ops);
  $st->execute(); $r=$st->get_result();
  $children=[]; while($row=$r->fetch_assoc()) $children[]=(int)$row['id'];
  $st->close();
  return $children;
}

/** Mapa dependientes por opción: opt_id => [question_text, ...] */
function dependentsByOption(mysqli $conn, int $idSet, array $optionIds): array{
  if (empty($optionIds)) return [];
  $in = implode(',', array_fill(0, count($optionIds), '?'));
  $types = str_repeat('i', count($optionIds));
  $sql = "SELECT question_text, id_dependency_option FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st = $conn->prepare($sql);
  $st->bind_param('i'.$types, $idSet, ...$optionIds);
  $st->execute(); $r=$st->get_result();
  $map=[]; while($row=$r->fetch_assoc()){
    $k=(int)$row['id_dependency_option'];
    $map[$k] = $map[$k] ?? [];
    $map[$k][] = $row['question_text'];
  }
  $st->close();
  return $map;
}

/** Normaliza sort_order 1..N (por si acaso) */
function normalizeOptionsOrder(mysqli $conn, int $qid){
  $opts = fetchOptionsQ($conn, $qid);
  $order = 1;
  $st = $conn->prepare("UPDATE question_set_options SET sort_order=? WHERE id=?");
  foreach($opts as $op){
    $id = (int)$op['id'];
    $st->bind_param("ii", $order, $id);
    $st->execute();
    $order++;
  }
  $st->close();
}

/* ============================================================
   Imagen (WebP + resize + EXIF)
   ============================================================ */

function ensureDir(string $dir){
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
}

function getImageInfo(string $path){
  $info = @getimagesize($path);
  if (!$info) return [null, null, null];
  return [$info[0], $info[1], $info['mime'] ?? null];
}

function imageCreateFromPath(string $path, ?string $mime){
  switch ($mime){
    case 'image/jpeg':
    case 'image/jpg': return @imagecreatefromjpeg($path);
    case 'image/png':
      $im = @imagecreatefrompng($path);
      if ($im){ imagepalettetotruecolor($im); imagesavealpha($im, true); }
      return $im;
    case 'image/gif':
      $im = @imagecreatefromgif($path);
      if ($im){ imagepalettetotruecolor($im); imagesavealpha($im, true); }
      return $im;
    case 'image/webp':
      return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
    default: return null;
  }
}

/** Aplica orientación EXIF a JPEG */
function fixOrientationIfNeeded($img, string $path, ?string $mime){
  if ($mime !== 'image/jpeg' && $mime !== 'image/jpg') return $img;
  if (!function_exists('exif_read_data')) return $img;
  $exif = @exif_read_data($path);
  if (!$exif || empty($exif['Orientation'])) return $img;
  $ori = (int)$exif['Orientation'];
  switch ($ori) {
    case 3: $img = imagerotate($img, 180, 0); break;
    case 6: $img = imagerotate($img, -90, 0); break;
    case 8: $img = imagerotate($img, 90, 0); break;
  }
  return $img;
}

/** Redimensiona manteniendo relación */
function resizeToMax($srcImg, int $w, int $h, int $maxDim=1600){
  if ($w <= $maxDim && $h <= $maxDim) return $srcImg;
  $ratio = $w / $h;
  if ($ratio >= 1){ $newW = $maxDim; $newH = (int)round($maxDim / $ratio); }
  else { $newH = $maxDim; $newW = (int)round($maxDim * $ratio); }
  $dst = imagecreatetruecolor($newW, $newH);
  imagealphablending($dst, false);
  imagesavealpha($dst, true);
  imagecopyresampled($dst, $srcImg, 0,0,0,0, $newW, $newH, $w, $h);
  imagedestroy($srcImg);
  return $dst;
}

/** Convierte/optimiza a WebP (o JPEG fallback) y retorna ruta web */
function saveOptimizedImage(string $tmpPath, string $destDirFs, string $destDirWeb, string $prefix='opt_', int $maxDim=1600, int $quality=80){
  ensureDir($destDirFs);

  [$w, $h, $mime] = getImageInfo($tmpPath);
  if (!$mime || !$w || !$h) return '';

  $img = imageCreateFromPath($tmpPath, $mime);
  if (!$img) return '';

  $img = fixOrientationIfNeeded($img, $tmpPath, $mime);
  $img = resizeToMax($img, $w, $h, $maxDim);

  $id = uniqid($prefix, true);
  if (function_exists('imagewebp')){
    $path = $destDirFs . $id . '.webp';
    $ok = @imagewebp($img, $path, $quality);
    imagedestroy($img);
    if ($ok) return $destDirWeb . $id . '.webp';
  }
  $path = $destDirFs . $id . '.jpg';
  @imagejpeg($img, $path, 82);
  imagedestroy($img);
  return $destDirWeb . $id . '.jpg';
}

/* ============================================================
   POST (guardar cambios)
   ============================================================ */

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='edit_question_set'){
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo "Token CSRF inválido.";
    exit();
  }
  $idSet        = (int)($_POST['id_set'] ?? 0);
  $idSetQuestion= (int)($_POST['idSetQuestion'] ?? 0);
  $question_text= trim($_POST['question_text'] ?? '');
  $id_type      = (int)($_POST['id_question_type'] ?? 0);
  $is_required  = isset($_POST['is_required']) ? 1 : 0;
  $is_valued    = isset($_POST['is_valued']) ? 1 : 0;
  $dependency_option = (isset($_POST['dependency_option']) && $_POST['dependency_option'] !== '') ? (int)$_POST['dependency_option'] : null;

  if ($idSet<=0 || $idSetQuestion<=0 || $question_text==='' || $id_type<=0){ echo "Datos inválidos."; exit(); }

  // Pregunta original
  $old = fetchQuestion($conn, $idSetQuestion, $idSet);
  if (!$old){ echo "Pregunta no encontrada."; exit(); }
  $oldType = (int)$old['id_question_type'];

  // Dependencia original vs nueva (para saber si hay que reordenar)
  $oldDepOpt  = ($old['id_dependency_option'] !== null) ? (int)$old['id_dependency_option'] : null;
  $depChanged = ($oldDepOpt !== $dependency_option);

  // Validación anti-ciclo si cambia dependencia
  if (!is_null($dependency_option)){
    $optMap = optionsMapByQuestion($conn, $idSet);
    if (!isset($optMap[$dependency_option])){ echo "Opción disparadora inválida."; exit(); }
    $padreNuevo = (int)$optMap[$dependency_option];

    if ($padreNuevo === $idSetQuestion){ echo "No puedes depender de una opción de la MISMA pregunta."; exit(); }
    $desc = getDescendants($conn, $idSet, $idSetQuestion);
    if (in_array($padreNuevo, $desc, true)){ echo "Dependencia inválida: generaría un ciclo (dependes de tu descendiente)."; exit(); }
  }

  $isOptionType = fn($t) => in_array((int)$t, [1,2,3], true);

  // No permitir pasar a tipo sin opciones si hay dependientes actuales
  if ($isOptionType($oldType) && !$isOptionType($id_type)){
    $children = getImmediateChildren($conn, $idSet, $idSetQuestion);
    if (!empty($children)){
      echo "No puedes cambiar esta pregunta a un tipo sin opciones mientras existan dependientes de sus opciones. Reasigna/elimina esos dependientes primero.";
      exit();
    }
  }

  // Evitar cambio a Sí/No si hay dependientes actuales
  if (in_array($oldType, [2,3], true) && $id_type===1){
    $children = getImmediateChildren($conn, $idSet, $idSetQuestion);
    if (!empty($children)){
      echo "Cambio a Sí/No bloqueado: la pregunta tiene dependientes de opciones actuales. Reasigna/elimina esos dependientes y luego cambia el tipo.";
      exit();
    }
  }

  $destFs  = $_SERVER['DOCUMENT_ROOT'].'/uploads/opciones/';
  $destWeb = '/uploads/opciones/';

  $conn->begin_transaction();
  try{
    // UPDATE pregunta (texto, tipo, flags, dependencia)
    if (is_null($dependency_option)){
      $sql = "UPDATE question_set_questions 
              SET question_text=?, id_question_type=?, is_required=?, is_valued=?, id_dependency_option=NULL
              WHERE id=? AND id_question_set=?";
      $st = $conn->prepare($sql);
      $st->bind_param("siiiii", $question_text, $id_type, $is_required, $is_valued, $idSetQuestion, $idSet);
    } else {
      $sql = "UPDATE question_set_questions 
              SET question_text=?, id_question_type=?, is_required=?, is_valued=?, id_dependency_option=?
              WHERE id=? AND id_question_set=?";
      $st = $conn->prepare($sql);
      $st->bind_param("siiiiii", $question_text, $id_type, $is_required, $is_valued, $dependency_option, $idSetQuestion, $idSet);
    }
    $st->execute(); 
    $st->close();

    // Reordenar cuando la dependencia cambia y ahora es condicional
    if ($depChanged && $dependency_option !== null) {
      $optMap = optionsMapByQuestion($conn, $idSet);
      if (!isset($optMap[$dependency_option])) {
        throw new Exception("La opción seleccionada no pertenece a este set.");
      }
      $parentQ = (int)$optMap[$dependency_option];

      // sort_order del padre
      $stP = $conn->prepare("SELECT sort_order FROM question_set_questions WHERE id=? AND id_question_set=?");
      $stP->bind_param("ii", $parentQ, $idSet);
      $stP->execute();
      $stP->bind_result($parentSort);
      if (!$stP->fetch()) {
        $stP->close();
        throw new Exception("No se encontró la pregunta padre al reordenar.");
      }
      $stP->close();

      $newSort = (int)$parentSort + 1;

      // Desplazar hacia abajo todas las preguntas con sort_order >= newSort, excepto esta
      $stShift = $conn->prepare("
        UPDATE question_set_questions
           SET sort_order = sort_order + 1
         WHERE id_question_set = ?
           AND id <> ?
           AND sort_order >= ?
      ");
      $stShift->bind_param("iii", $idSet, $idSetQuestion, $newSort);
      $stShift->execute();
      $stShift->close();

      // Asignar nuevo sort_order a la pregunta actual
      $stSort = $conn->prepare("
        UPDATE question_set_questions
           SET sort_order = ?
         WHERE id = ? AND id_question_set = ?
      ");
      $stSort->bind_param("iii", $newSort, $idSetQuestion, $idSet);
      $stSort->execute();
      $stSort->close();
    }

    // Opciones según tipo
    if ($isOptionType($id_type)){
      // Eliminar opciones removidas (comparando ids existentes enviados vs actuales)
      $current = fetchOptionsQ($conn, $idSetQuestion);
      $curIds = array_map(fn($r)=>(int)$r['id'], $current);
      $submitted = [];
      if (!empty($_POST['existing_option_id'])){
        foreach($_POST['existing_option_id'] as $id){ if($id!=='') $submitted[] = (int)$id; }
      }
      $toDel = array_diff($curIds, $submitted);
      if (!empty($toDel)){
        $depsToDel = dependentsByOption($conn, $idSet, $toDel);
        if (!empty($depsToDel)){
          $list = [];
          foreach ($depsToDel as $optId => $texts){
            foreach ($texts as $t){ $list[] = $t; }
          }
          $preview = implode(', ', array_slice($list, 0, 5));
          $more = count($list) > 5 ? '…' : '';
          throw new Exception("No puedes eliminar opciones con dependientes. Reasigna o elimina sus preguntas primero. Ejemplos: ".$preview.$more);
        }
        $in=implode(',', array_map('intval', $toDel));
        $conn->query("DELETE FROM question_set_options WHERE id_question_set_question={$idSetQuestion} AND id IN ($in)");
      }

      // Actualizar EXISTENTES en el ORDEN recibido
      if (!empty($_POST['existing_option_id'])){
        $order = 1;
        foreach($_POST['existing_option_id'] as $idx=>$optIdRaw){
          if($optIdRaw==='') continue;
          $optId = (int)$optIdRaw;
          $text  = trim($_POST['options'][$idx] ?? '');
          if ($text === '') $text = 'Opción';

          // Mantener/limpiar/actualizar imagen
          $ref   = $_POST['existing_reference_image'][$idx] ?? '';
          $clear = isset($_POST['clear_image'][$idx]) && $_POST['clear_image'][$idx]=='1';

          // Si el usuario sube una imagen nueva, tiene prioridad sobre "clear"
          if (isset($_FILES['option_images']['name'][$idx]) && $_FILES['option_images']['error'][$idx]===UPLOAD_ERR_OK){
            if ($_FILES['option_images']['size'][$idx] > 10*1024*1024) { throw new Exception("Archivo demasiado grande (>10MB)."); }
            $refOpt = saveOptimizedImage($_FILES['option_images']['tmp_name'][$idx], $destFs, $destWeb, 'opt_', 1600, 80);
            if ($refOpt) $ref = $refOpt;
          } else {
            if ($clear) $ref = '';
          }

          $st=$conn->prepare("UPDATE question_set_options SET option_text=?, reference_image=?, sort_order=? WHERE id=? AND id_question_set_question=?");
          $st->bind_param("ssiii", $text, $ref, $order, $optId, $idSetQuestion);
          $st->execute(); $st->close();
          $order++;
        }
      }

      // Insertar NUEVAS (al final)
      if (!empty($_POST['options_new'])){
        $optsNow = fetchOptionsQ($conn, $idSetQuestion);
        $order = count($optsNow) + 1;

        foreach($_POST['options_new'] as $idx=>$text){
          $text=trim($text); if($text==='') continue;
          $ref='';

          if (isset($_FILES['option_images_new']['name'][$idx]) && $_FILES['option_images_new']['error'][$idx]===UPLOAD_ERR_OK){
            if ($_FILES['option_images_new']['size'][$idx] > 10*1024*1024) { throw new Exception("Archivo demasiado grande (>10MB)."); }
            $refOpt = saveOptimizedImage($_FILES['option_images_new']['tmp_name'][$idx], $destFs, $destWeb, 'opt_', 1600, 80);
            if ($refOpt) $ref = $refOpt;
          }

          $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
          $st->bind_param("issi", $idSetQuestion, $text, $ref, $order);
          $st->execute(); $st->close();
          $order++;
        }
      }

      // Ajuste Sí/No
      if ($id_type===1){
        $opts = fetchOptionsQ($conn, $idSetQuestion);
        if (!empty($opts)){
          $txt1 = 'Sí';
          $st=$conn->prepare("UPDATE question_set_options SET option_text=?, sort_order=1 WHERE id=?");
          $st->bind_param("si", $txt1, $opts[0]['id']);
          $st->execute(); $st->close();
        }
        if (count($opts) >= 2){
          $txt2 = 'No';
          $st=$conn->prepare("UPDATE question_set_options SET option_text=?, sort_order=2 WHERE id=?");
          $st->bind_param("si", $txt2, $opts[1]['id']);
          $st->execute(); $st->close();
        } else {
          $txt2 = 'No'; $sort2 = 2; $empty='';
          $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
          $st->bind_param("issi", $idSetQuestion, $txt2, $empty, $sort2);
          $st->execute(); $st->close();
        }
        if (count($opts) > 2){
          $ids = array_slice(array_column($opts,'id'), 2);
          if (!empty($ids)){
            $in = implode(',', array_map('intval',$ids));
            $conn->query("DELETE FROM question_set_options WHERE id_question_set_question={$idSetQuestion} AND id IN ($in)");
          }
        }
      }

      normalizeOptionsOrder($conn, $idSetQuestion);

    } else {
      // Tipos sin opciones: limpiar si quedara algo
      $conn->query("DELETE FROM question_set_options WHERE id_question_set_question={$idSetQuestion}");
    }

    $conn->commit();
    echo "OK";
  } catch(Exception $e){
    $conn->rollback();
    echo "Error: ".$e->getMessage();
  }
  exit();
}

/* ============================================================
   GET (cargar modal)
   ============================================================ */

$idSet = (int)($_GET['idSet'] ?? 0);
$idSetQuestion = (int)($_GET['idSetQuestion'] ?? 0);

$q = fetchQuestion($conn, $idSetQuestion, $idSet);
if (!$q){ echo "Pregunta no encontrada."; exit(); }

$opts = fetchOptionsQ($conn, $q['id']);
$optIds = array_map(fn($r)=>(int)$r['id'], $opts);
$depsMap = dependentsByOption($conn, $idSet, $optIds);

$allQs = fetchSetQuestions($conn, $idSet);
$descOfThis = getDescendants($conn, $idSet, $idSetQuestion);
$descSet = array_flip($descOfThis);

$depOptionsHtml = '<option value="">-- Sin dependencia --</option>';
foreach($allQs as $pq){
  if (in_array((int)$pq['id_question_type'], [1,2,3], true)){
    $rows = fetchOptionsQ($conn, $pq['id']);
    foreach($rows as $op){
      $sel = ((int)$q['id_dependency_option'] === (int)$op['id']) ? 'selected' : '';
      $parentId = (int)$pq['id'];
      $disabled = isset($descSet[$parentId]) || $parentId===$idSetQuestion ? 'disabled' : '';
      $label = 'P: '.htmlspecialchars($pq['question_text'],ENT_QUOTES).' — Opción: '.htmlspecialchars($op['option_text'],ENT_QUOTES);
      $depOptionsHtml .= '<option value="'.$op['id'].'" '.$sel.' '.$disabled.'>'.$label.'</option>';
    }
  }
}

function isOptionTypeInt($t){ return in_array((int)$t, [1,2,3], true); }

?>
<form id="editSetQuestionForm" method="post" enctype="multipart/form-data" onsubmit="return submitEditSetQ(this);">
  <input type="hidden" name="action" value="edit_question_set">
  <input type="hidden" name="id_set" value="<?= $idSet ?>">
  <input type="hidden" name="idSetQuestion" value="<?= (int)$q['id'] ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">

  <div class="modal-header py-2">
    <h5 class="modal-title">Editar pregunta</h5>
    <button type="button" class="close js-maybe-close" data-dismiss="modal"><span>&times;</span></button>
  </div>

  <div class="modal-body">

    <style>
      .ref-thumb { max-height: 64px; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,.08); }
      .ref-thumb-btn { border:none; background:transparent; padding:0; }
      .opt-drag-handle{cursor:move; color:#adb5bd;}
      .opt-sortable-placeholder{border:2px dashed #91b0ff;background:#eef4ff;height:58px;border-radius:8px;margin-bottom:8px;}
      .opt-toolbar .btn{padding:.15rem .35rem; font-size:.8rem; margin-left:.25rem;}
      .badge-deps{cursor:default;}
    </style>

    <div class="form-group">
      <label>Texto</label>
      <input type="text" class="form-control js-dirty" name="question_text" value="<?= htmlspecialchars($q['question_text'],ENT_QUOTES) ?>" required>
    </div>

    <div class="form-row">
      <div class="form-group col-md-5">
        <label>Tipo</label>
        <select name="id_question_type" id="edit_type" class="form-control js-dirty" required>
          <?php foreach ([1=>"Sí/No",2=>"Selección única",3=>"Selección múltiple",4=>"Texto",5=>"Numérico",6=>"Fecha",7=>"Foto"] as $tid=>$label): ?>
            <option value="<?= $tid ?>" <?= ((int)$q['id_question_type']===$tid?'selected':'') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-7">
        <label>Depende de (opcional)</label>
        <select name="dependency_option" class="form-control js-dirty"><?= $depOptionsHtml ?></select>
        <small class="text-muted">Si cambias el tipo a uno sin opciones y esta pregunta tiene dependientes, el guardado será bloqueado.</small>
      </div>
    </div>

    <div class="form-row">
      <div class="col">
        <div class="custom-control custom-checkbox">
          <input type="checkbox" class="custom-control-input js-dirty" id="edit_req" name="is_required" value="1" <?= ($q['is_required']?'checked':'') ?>>
          <label class="custom-control-label" for="edit_req">¿Requerida?</label>
        </div>
      </div>
      <div class="col">
        <div class="custom-control custom-checkbox" id="edit_val_wrap" style="display: <?= isOptionTypeInt($q['id_question_type']) && (int)$q['id_question_type']!==1 ? 'block':'none' ?>;">
          <input type="checkbox" class="custom-control-input js-dirty" id="edit_val" name="is_valued" value="1" <?= ($q['is_valued']?'checked':'') ?>>
          <label class="custom-control-label" for="edit_val">¿Valorizada?</label>
        </div>
      </div>
    </div>

    <div id="edit_opts_section" style="display: <?= isOptionTypeInt($q['id_question_type']) ? 'block':'none' ?>;">
      <hr>
      <div class="d-flex align-items-center mb-1">
        <h6 class="mb-0">Opciones</h6>
        <small class="text-muted ml-2">Máx 10 MB por imagen. </small>
      </div>

      <div id="edit_opts_container">
        <?php if ((int)$q['id_question_type'] === 1): ?>
          <?php
            $o0 = $opts[0] ?? ['id'=>'','option_text'=>'Sí','reference_image'=>''];
            $o1 = $opts[1] ?? ['id'=>'','option_text'=>'No','reference_image'=>''];
            $pair = [$o0,$o1];
            foreach($pair as $i=>$op):
              $deps = $depsMap[ (int)($op['id'] ?? 0) ] ?? [];
              $cnt  = count($deps);
              $tooltip = '';
              if ($cnt>0){
                $tip = '';
                foreach($deps as $t){ $tip .= htmlspecialchars($t,ENT_QUOTES).'<br>'; }
                $tooltip = ' data-toggle="tooltip" data-html="true" title="'.$tip.'"';
              }
          ?>
            <div class="border rounded p-2 mb-2 option-yn existing-option">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <span class="badge badge-light"><?= ($i===0?'Sí':'No') ?></span>
                  <span class="badge badge-<?= $cnt>0?'info':'light' ?> badge-deps"<?= $tooltip ?>><?= $cnt ?> dependientes</span>
                </div>
                <div class="opt-toolbar">
                  <?php if(!empty($op['reference_image'])): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm js-ref-thumb" title="Ver imagen" data-src="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>" data-caption="Opción <?= ($i===0?'Sí':'No') ?>"><i class="fa fa-image"></i></button>
                    <button type="button" class="btn btn-outline-warning btn-sm js-clear-img" title="Quitar imagen"><i class="fa fa-eraser"></i></button>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Sin imagen"><i class="fa fa-image"></i></button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="form-group mb-2">
                <label>Texto</label>
                <input type="text" class="form-control" name="options[]" value="<?= htmlspecialchars($op['option_text'],ENT_QUOTES) ?>" readonly>
              </div>

              <?php if(!empty($op['reference_image'])): ?>
                <div class="mb-2 js-current-img">
                  <img class="ref-thumb" src="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>" alt="Imagen de referencia">
                  <small class="text-muted ml-1">Actual</small>
                </div>
              <?php endif; ?>

              <div class="custom-file mb-2">
                <input type="file" class="custom-file-input js-live-file" name="option_images[]" accept="image/*">
                <label class="custom-file-label">Imagen (opcional)</label>
              </div>
              <div class="mt-1 d-none js-live-preview-wrap">
                <img class="ref-thumb js-live-preview" alt="Vista previa">
                <small class="text-muted ml-1">Nueva (sin guardar)</small>
              </div>

              <input type="hidden" name="existing_option_id[]" value="<?= htmlspecialchars($op['id'],ENT_QUOTES) ?>">
              <input type="hidden" name="existing_reference_image[]" value="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>">
              <input type="hidden" name="clear_image[]" value="0">
            </div>
          <?php endforeach; ?>

        <?php elseif (in_array((int)$q['id_question_type'], [2,3], true)): ?>
          <?php foreach($opts as $op):
            $deps = $depsMap[(int)$op['id']] ?? [];
            $cnt  = count($deps);
            $tooltip='';
            if ($cnt>0){
              $tip=''; foreach($deps as $t){ $tip .= htmlspecialchars($t,ENT_QUOTES).'<br>'; }
              $tooltip = ' data-toggle="tooltip" data-html="true" title="'.$tip.'"';
            }
          ?>
            <div class="option-block existing-option border rounded p-2 mb-2">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center">
                  <span class="opt-drag-handle mr-2" title="Arrastrar para reordenar"><i class="fa fa-grip-vertical"></i></span>
                  <small class="text-muted mr-2">Arrastrar para cambiar orden</small>
                  <span class="badge badge-<?= $cnt>0?'info':'light' ?> badge-deps"<?= $tooltip ?>><?= $cnt ?> dependientes</span>
                </div>
                <div class="opt-toolbar">
                  <button type="button" class="btn btn-outline-primary btn-sm js-dup-opt" title="Duplicar opción"><i class="fa fa-clone"></i></button>
                  <?php if(!empty($op['reference_image'])): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm js-ref-thumb" title="Ver imagen" data-src="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>" data-caption="<?= htmlspecialchars($op['option_text'],ENT_QUOTES) ?>"><i class="fa fa-image"></i></button>
                    <button type="button" class="btn btn-outline-warning btn-sm js-clear-img" title="Quitar imagen"><i class="fa fa-eraser"></i></button>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Sin imagen"><i class="fa fa-image"></i></button>
                  <?php endif; ?>
                  <button type="button" class="btn btn-outline-danger btn-sm js-del-opt" title="Eliminar opción"><i class="fa fa-trash"></i></button>
                </div>
              </div>

              <div class="form-group mb-2">
                <label>Texto</label>
                <input type="text" class="form-control js-dirty" name="options[]" value="<?= htmlspecialchars($op['option_text'],ENT_QUOTES) ?>" required>
              </div>

              <?php if(!empty($op['reference_image'])): ?>
                <div class="mb-2 js-current-img">
                  <img class="ref-thumb" src="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>" alt="Imagen de referencia">
                  <small class="text-muted ml-1">Actual</small>
                </div>
              <?php endif; ?>

              <div class="custom-file mb-2">
                <input type="file" class="custom-file-input js-live-file" name="option_images[]" accept="image/*">
                <label class="custom-file-label">Imagen (opcional)</label>
              </div>
              <div class="mt-1 d-none js-live-preview-wrap">
                <img class="ref-thumb js-live-preview" alt="Vista previa">
                <small class="text-muted ml-1">Nueva (sin guardar)</small>
              </div>

              <input type="hidden" name="existing_option_id[]" value="<?= (int)$op['id'] ?>">
              <input type="hidden" name="existing_reference_image[]" value="<?= htmlspecialchars($op['reference_image'],ENT_QUOTES) ?>">
              <input type="hidden" name="clear_image[]" value="0">
            </div>
          <?php endforeach; ?>
          <button type="button" class="btn btn-sm btn-secondary" onclick="addEditOption()">+ Agregar opción</button>
        <?php endif; ?>
      </div>

      <small class="text-muted d-block mt-2">
        Para tipo Sí/No, las opciones son fijas. Para Única/Múltiple puedes agregar/eliminar, reordenar y subir imagen de referencia.
      </small>
    </div>

  </div>

  <div class="modal-footer py-2">
    <button type="button" class="btn btn-secondary js-maybe-close" data-dismiss="modal">Cancelar</button>
    <button type="submit" class="btn btn-outline-primary js-keep-open">Guardar y seguir</button>
    <button type="submit" class="btn btn-primary">Guardar y cerrar</button>
  </div>
</form>

<script>
// ===== Modal preview global (si no existe) =====
(function ensurePreviewModal(){
  if (document.getElementById('refImageModal')) return;
  var html = `
  <div class="modal fade" id="refImageModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header py-2">
          <h6 class="modal-title" id="refImageCaption">Imagen de referencia</h6>
          <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        </div>
        <div class="modal-body p-0 text-center">
          <img id="refImageModalImg" src="" alt="Imagen de referencia" style="max-width:100%;height:auto;">
        </div>
      </div>
    </div>
  </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
})();

// Abrir modal con imagen actual o nueva
$(document).on('click', '.js-ref-thumb', function(e){
  e.preventDefault();
  var src = $(this).data('src');
  var cap = $(this).data('caption') || 'Imagen de referencia';
  $('#refImageModalImg').attr('src', src);
  $('#refImageCaption').text(cap);
  $('#refImageModal').modal('show');
});
$('#refImageModal').on('hidden.bs.modal', function () {
  $('#refImageModalImg').attr('src', '');
});

// ===== Dirty guard (evitar perder cambios) =====
let __dirty = false, __keepOpen = false;
$(document).on('change keyup', '.js-dirty, input[type=file]', function(){ __dirty = true; });
$('.js-keep-open').on('click', function(){ __keepOpen = true; });
$('.js-maybe-close').on('click', function(){
  if (__dirty && !confirm('Tienes cambios sin guardar. ¿Cerrar de todos modos?')) {
    // impedir cierre
    return false;
  }
});

// ===== Sortable de alternativas EXISTENTES (única/múltiple) =====
function initOptionsSortable(){
  if (typeof $.fn.sortable !== 'function') return;
  var $c = $('#edit_opts_container');
  if(!$c.length) return;
  try { $c.sortable('destroy'); } catch(e){}
  $c.sortable({
    items: '> .option-block.existing-option', // solo existentes
    handle: '.opt-drag-handle',
    placeholder: 'opt-sortable-placeholder',
    forcePlaceholderSize: true,
    axis: 'y',
    cancel: 'input, textarea, select, .custom-file, button, .js-ref-thumb'
  });
}
$(function(){ initOptionsSortable(); $('[data-toggle="tooltip"]').tooltip({container:'body'}); });

// ===== File label + preview live =====
(function(){
  function toDataURL(file, cb){
    var rd = new FileReader();
    rd.onload = function(e){ cb(e.target.result); };
    rd.readAsDataURL(file);
  }
  document.querySelectorAll('.custom-file-input.js-live-file').forEach(function(inp){
    inp.addEventListener('change', function(e){
      var file = e.target.files && e.target.files[0];
      var label = e.target.nextElementSibling;
      if (label) label.innerText = file ? file.name : 'Imagen (opcional)';
      var wrap = e.target.closest('.option-yn, .option-block')?.querySelector('.js-live-preview-wrap');
      var img  = wrap ? wrap.querySelector('.js-live-preview') : null;
      if (file && /^image\//.test(file.type) && img && wrap){
        toDataURL(file, function(src){
          img.src = src;
          wrap.classList.remove('d-none');
          img.onclick = function(){
            $('#refImageModalImg').attr('src', src);
            $('#refImageCaption').text('Vista previa');
            $('#refImageModal').modal('show');
          };
        });
      } else if (wrap){
        wrap.classList.add('d-none');
      }
      __dirty = true;
    });
  });
})();

// ===== Acciones rápidas por opción =====
$(document).on('click', '.js-del-opt', function(){
  if (confirm('¿Eliminar esta opción?')) {
    $(this).closest('.option-block').remove();
    __dirty = true;
  }
});
$(document).on('click', '.js-clear-img', function(){
  const block = $(this).closest('.option-yn, .option-block');
  const idxClear = block.find('input[name^="clear_image"]').first();
  // marca limpiar imagen (solo afecta a EXISTENTES si no suben una nueva)
  if (idxClear.length){ idxClear.val('1'); }
  block.find('.js-current-img').remove();
  __dirty = true;
});
$(document).on('click', '.js-dup-opt', function(){
  const src = $(this).closest('.option-block');
  // clonar como NUEVA opción (no sortable, no ids existentes)
  const clone = src.clone(true, true);
  clone.removeClass('existing-option').addClass('new-option');
  clone.find('.opt-drag-handle').remove(); // nuevas no son arrastrables
  clone.find('.badge-deps').remove(); // dependientes no aplican a nuevas
  // limpiar inputs y renombrar a *_new[]
  clone.find('input[name="options[]"]').attr('name','options_new[]');
  clone.find('input[name="existing_option_id[]"], input[name="existing_reference_image[]"], input[name="clear_image[]"]').remove();
  // limpiar file input
  clone.find('input[name="option_images[]"]').attr('name','option_images_new[]').val('');
  clone.find('.custom-file-label').text('Imagen (opcional)');
  // mantener texto actual, quitar imagen actual
  clone.find('.js-current-img').remove();
  // insertar antes del botón de agregar
  const btn = $('#edit_opts_container').find('> .btn.btn-sm.btn-secondary').last();
  if (btn.length) clone.insertBefore(btn); else $('#edit_opts_container').append(clone);
  __dirty = true;
});

// ===== Reconstrucción de opciones al cambiar tipo (conserva snapshot) =====
(function(){
  function snapshotOptions(){
    const c = document.getElementById('edit_opts_container');
    const data = [];
    c.querySelectorAll('.option-block, .option-yn').forEach(function(block){
      const inp = block.querySelector('input[name^="options"]');
      const txt = inp ? inp.value : '';
      const idEl = block.querySelector('input[name^="existing_option_id"]');
      const refEl= block.querySelector('input[name^="existing_reference_image"]');
      data.push({ id: idEl ? idEl.value : '', text: txt, ref:  refEl ? refEl.value : '' });
    });
    return data;
  }

  function renderYesNoFromSnapshot(snap){
    const c = document.getElementById('edit_opts_container');
    c.innerHTML = '';
    ['Sí','No'].forEach(function(label){
      const prev = snap.find(s => (s.text||'').trim().toLowerCase() === label.toLowerCase()) || {id:'', ref:''};
      const node = document.createElement('div');
      node.className = 'border rounded p-2 mb-2 option-yn existing-option';
      node.innerHTML = `
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div>
            <span class="badge badge-light">${label}</span>
          </div>
          <div class="opt-toolbar">
            ${prev.ref ? `<button type="button" class="btn btn-outline-secondary btn-sm js-ref-thumb" data-src="${prev.ref}" data-caption="Opción ${label}" title="Ver imagen"><i class="fa fa-image"></i></button>
            <button type="button" class="btn btn-outline-warning btn-sm js-clear-img" title="Quitar imagen"><i class="fa fa-eraser"></i></button>` : `<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Sin imagen"><i class="fa fa-image"></i></button>`}
          </div>
        </div>
        <div class="form-group mb-2">
          <label>Texto</label>
          <input type="text" class="form-control" name="options[]" value="${label}" readonly>
        </div>
        ${prev.ref ? `
          <div class="mb-2 js-current-img">
            <img class="ref-thumb" src="${prev.ref}" alt="Imagen de referencia">
            <small class="text-muted ml-1">Actual</small>
          </div>`:``}
        <div class="custom-file mb-2">
          <input type="file" class="custom-file-input js-live-file" name="option_images[]" accept="image/*">
          <label class="custom-file-label">Imagen (opcional)</label>
        </div>
        <div class="mt-1 d-none js-live-preview-wrap">
          <img class="ref-thumb js-live-preview" alt="Vista previa">
          <small class="text-muted ml-1">Nueva (sin guardar)</small>
        </div>
        <input type="hidden" name="existing_option_id[]" value="${prev.id||''}">
        <input type="hidden" name="existing_reference_image[]" value="${prev.ref||''}">
        <input type="hidden" name="clear_image[]" value="0">
      `;
      c.appendChild(node);
    });
    $('[data-toggle="tooltip"]').tooltip({container:'body'});
  }

  function renderGenericOptionsFromSnapshot(snap){
    const c = document.getElementById('edit_opts_container');
    c.innerHTML = '';
    (snap.length ? snap : [{id:'',text:'Opción 1',ref:''},{id:'',text:'Opción 2',ref:''}]).forEach(function(s){
      const isExisting = (s.id && String(s.id).trim() !== '');
      const node = document.createElement('div');
      node.className = 'option-block border rounded p-2 mb-2 ' + (isExisting ? 'existing-option' : 'new-option');
      node.innerHTML = `
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="d-flex align-items-center">
            ${isExisting ? `<span class="opt-drag-handle mr-2" title="Arrastrar para reordenar"><i class="fa fa-grip-vertical"></i></span>
            <small class="text-muted mr-2">Arrastrar para cambiar orden</small>` : ``}
          </div>
          <div class="opt-toolbar">
            ${isExisting ? `<button type="button" class="btn btn-outline-primary btn-sm js-dup-opt" title="Duplicar opción"><i class="fa fa-clone"></i></button>` : ``}
            ${s.ref ? `<button type="button" class="btn btn-outline-secondary btn-sm js-ref-thumb" data-src="${s.ref}" data-caption="${(s.text||'Opción').replace(/"/g,'&quot;')}" title="Ver imagen"><i class="fa fa-image"></i></button>
            ${isExisting ? `<button type="button" class="btn btn-outline-warning btn-sm js-clear-img" title="Quitar imagen"><i class="fa fa-eraser"></i></button>`:``}` : `<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Sin imagen"><i class="fa fa-image"></i></button>`}
            <button type="button" class="btn btn-outline-danger btn-sm js-del-opt" title="Eliminar opción"><i class="fa fa-trash"></i></button>
          </div>
        </div>
        <div class="form-group mb-2">
          <label>Texto</label>
          <input type="text" class="form-control js-dirty" name="${isExisting ? 'options[]' : 'options_new[]'}" value="${(s.text||'').replace(/"/g,'&quot;')}" required>
        </div>
        ${s.ref ? `
          <div class="mb-2 js-current-img">
            <img class="ref-thumb" src="${s.ref}" alt="Imagen de referencia">
            <small class="text-muted ml-1">Actual</small>
          </div>` : ``}
        <div class="custom-file mb-2">
          <input type="file" class="custom-file-input js-live-file" name="${isExisting ? 'option_images[]' : 'option_images_new[]'}" accept="image/*">
          <label class="custom-file-label">Imagen (opcional)</label>
        </div>
        <div class="mt-1 d-none js-live-preview-wrap">
          <img class="ref-thumb js-live-preview" alt="Vista previa">
          <small class="text-muted ml-1">Nueva (sin guardar)</small>
        </div>
        ${isExisting ? `
          <input type="hidden" name="existing_option_id[]" value="${s.id||''}">
          <input type="hidden" name="existing_reference_image[]" value="${s.ref||''}">
          <input type="hidden" name="clear_image[]" value="0">` : ``}
      `;
      c.appendChild(node);
    });

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-secondary';
    addBtn.textContent = '+ Agregar opción';
    addBtn.onclick = addEditOption;
    c.appendChild(addBtn);

    // reenganchar previews
    setTimeout(function(){
      document.querySelectorAll('.custom-file-input.js-live-file').forEach(function(inp){
        inp.addEventListener('change', function(e){
          const file = e.target.files && e.target.files[0];
          const label = e.target.nextElementSibling;
          if (label) label.innerText = file ? file.name : 'Imagen (opcional)';
          const wrap = e.target.closest('.option-yn, .option-block')?.querySelector('.js-live-preview-wrap');
          const img  = wrap ? wrap.querySelector('.js-live-preview') : null;
          if (file && /^image\//.test(file.type) && img && wrap){
            const rd = new FileReader();
            rd.onload = function(ev){
              img.src = ev.target.result;
              wrap.classList.remove('d-none');
              img.onclick = function(){
                $('#refImageModalImg').attr('src', ev.target.result);
                $('#refImageCaption').text('Vista previa');
                $('#refImageModal').modal('show');
              };
            };
            rd.readAsDataURL(file);
          } else if (wrap){
            wrap.classList.add('d-none');
          }
          __dirty = true;
        });
      });
      $('[data-toggle="tooltip"]').tooltip({container:'body'});
    }, 10);

    initOptionsSortable();
  }

  document.getElementById('edit_type').addEventListener('change', function(){
    const v = parseInt(this.value||'0',10);
    const valWrap = document.getElementById('edit_val_wrap');
    const optsSec = document.getElementById('edit_opts_section');
    const snap = snapshotOptions();

    valWrap.style.display = ([2,3].includes(v)) ? 'block':'none';
    optsSec.style.display = ([1,2,3].includes(v)) ? 'block':'none';

    if ([1,2,3].includes(v)){
      if (v === 1){ renderYesNoFromSnapshot(snap); }
      else { renderGenericOptionsFromSnapshot(snap); }
      __dirty = true;
    }
  });

  // Agregar NUEVA opción (2/3) — queda fuera del sortable (se inserta al final en BD)
  window.addEditOption = function(){
    const c=document.getElementById('edit_opts_container');
    const btn = c.querySelector('button.btn.btn-sm.btn-secondary');
    const div=document.createElement('div');
    div.className='option-block new-option border rounded p-2 mb-2';
    div.innerHTML=`
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div></div>
        <div class="opt-toolbar">
          <button type="button" class="btn btn-outline-danger btn-sm js-del-opt" title="Eliminar opción"><i class="fa fa-trash"></i></button>
        </div>
      </div>
      <div class="form-group mb-2">
        <label>Texto</label>
        <input type="text" class="form-control js-dirty" name="options_new[]" placeholder="Texto de la opción" required>
      </div>
      <div class="custom-file mb-2">
        <input type="file" class="custom-file-input js-live-file" name="option_images_new[]" accept="image/*">
        <label class="custom-file-label">Imagen (opcional)</label>
      </div>
      <div class="mt-1 d-none js-live-preview-wrap">
        <img class="ref-thumb js-live-preview" alt="Vista previa">
        <small class="text-muted ml-1">Nueva (sin guardar)</small>
      </div>
    `;
    if (btn) c.insertBefore(div, btn); else c.appendChild(div);

    // Hook label + preview
    const input = div.querySelector('.custom-file-input.js-live-file');
    input.addEventListener('change', function(e){
      const file = e.target.files && e.target.files[0];
      const label = e.target.nextElementSibling;
      if (label) label.innerText = file ? file.name : 'Imagen (opcional)';
      const wrap = div.querySelector('.js-live-preview-wrap');
      const img  = div.querySelector('.js-live-preview');
      if (file && /^image\//.test(file.type)){
        const rd = new FileReader();
        rd.onload = function(ev){
          img.src = ev.target.result;
          wrap.classList.remove('d-none');
          img.onclick = function(){
            $('#refImageModalImg').attr('src', ev.target.result);
            $('#refImageCaption').text('Vista previa');
            $('#refImageModal').modal('show');
          };
        };
        rd.readAsDataURL(file);
      } else {
        wrap.classList.add('d-none');
      }
      __dirty = true;
    });
  };
})();

// ===== Submit AJAX =====
function submitEditSetQ(form){
  var fd = new FormData(form);
  $.ajax({
    url: 'ajax_editar_set_question.php',
    method: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    success: function(msg){
      if (msg.trim()==='OK'){
        __dirty = false;
        if (__keepOpen){
          __keepOpen = false;
          // recargar solo la página base para refrescar árbol pero dejar al usuario en contexto
          location.reload();
          // Nota: si quisieras mantener el modal abierto, deberías recargar vía AJAX el propio modal.
        } else {
          $('#editarSetPreguntaModal').modal('hide');
          location.reload();
        }
      } else {
        alert(msg);
      }
    },
    error: function(){ alert('Error guardando los cambios.'); }
  });
  return false;
}
</script>

<?php

session_start();
if (!isset($_SESSION['usuario_id'])) {
  header("Location: login.php");
  exit();
}

// DEBUG (apaga en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- Helpers de datos ---------- */

function getSets($conn){
  $res = $conn->query("SELECT id, nombre_set, description FROM question_set ORDER BY id DESC");
  $out = [];
  if ($res) while($r = $res->fetch_assoc()) $out[] = $r;
  return $out;
}
function getSet($conn, $id){
  $stmt = $conn->prepare("SELECT * FROM question_set WHERE id = ?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $r ?: null;
}
function getQuestionsFlat($conn, $idSet){
  $stmt = $conn->prepare("
    SELECT q.*
    FROM question_set_questions q
    WHERE q.id_question_set = ?
    ORDER BY q.sort_order ASC, q.id ASC
  ");
  $stmt->bind_param("i", $idSet);
  $stmt->execute();
  $res = $stmt->get_result();
  $qs  = [];
  while($row = $res->fetch_assoc()) $qs[] = $row;
  $stmt->close();
  return $qs;
}
function getOptionsForQuestions($conn, $questionIds){
  if (empty($questionIds)) return [];
  $in  = implode(',', array_fill(0, count($questionIds), '?'));
  $types = str_repeat('i', count($questionIds));
  $sql = "SELECT * FROM question_set_options WHERE id_question_set_question IN ($in) ORDER BY sort_order ASC, id ASC";
  $stmt = $conn->prepare($sql);
  $refs = [];
  foreach ($questionIds as $k => $v) { $questionIds[$k] = (int)$v; $refs[$k] = &$questionIds[$k]; }
  $stmt->bind_param($types, ...$refs);
  $stmt->execute();
  $res = $stmt->get_result();
  $map = [];
  while($r = $res->fetch_assoc()){
    $map[$r['id_question_set_question']][] = $r;
  }
  $stmt->close();
  return $map;
}

/** Devuelve id de pregunta padre dada la opción disparadora */
function parentQuestionIdByOption($conn, $optId){
  $stmt = $conn->prepare("SELECT id_question_set_question FROM question_set_options WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $optId);
  $stmt->execute();
  $stmt->bind_result($pid);
  $ok = $stmt->fetch();
  $stmt->close();
  return $ok ? (int)$pid : null;
}

/** Construye árbol agrupando por opción disparadora */
function buildTree($questions, $optionsMap, $conn){
  // índice por id
  $byId = [];
  foreach($questions as $q){
    $q['children'] = []; // hijos directos (cualquier opción)
    $q['options']  = $optionsMap[$q['id']] ?? [];
    $byId[ (int)$q['id'] ] = $q;
  }

  $roots = [];
  foreach ($byId as $id => &$q){
    $depOpt = (int)$q['id_dependency_option'];
    if ($depOpt){
      $parentId = parentQuestionIdByOption($conn, $depOpt);
      if ($parentId && isset($byId[$parentId])){
        $byId[$parentId]['children'][] =& $q;
      } else {
        // órfanos (opción no existente) => raíz
        $roots[] =& $q;
      }
    } else {
      $roots[] =& $q;
    }
  }
  return $roots;
}

/** Texto tipo */
function tipoTexto($t){
  $t = (int)$t;
  switch($t){
    case 1: return "Sí/No";
    case 2: return "Selección única";
    case 3: return "Selección múltiple";
    case 4: return "Texto";
    case 5: return "Numérico";
    case 6: return "Fecha";
    case 7: return "Foto";
    default: return "Otro";
  }
}

/* ---------- Acciones POST básicas (sets + agregar pregunta) ---------- */

if ($_SERVER['REQUEST_METHOD']==='POST'){
  $action = $_POST['action'] ?? '';

  if ($action==='create_set'){
    $nombre = trim($_POST['nombre_set'] ?? '');
    $desc   = trim($_POST['desc_set'] ?? '');
    if ($nombre===''){ $_SESSION['error_sets']="El nombre es obligatorio."; header("Location: gestionar_sets.php"); exit(); }
    $stmt=$conn->prepare("INSERT INTO question_set (nombre_set, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
    $stmt->bind_param("ss", $nombre, $desc);
    $stmt->execute(); $newId=$stmt->insert_id; $stmt->close();
    $_SESSION['success_sets']="Set creado (ID: $newId).";
    header("Location: gestionar_sets.php?idSet=".$newId); exit();
  }

  if ($action==='update_set'){
    $idSet = (int)($_POST['id_set'] ?? 0);
    $nombre = trim($_POST['nombre_set'] ?? '');
    $desc   = trim($_POST['desc_set'] ?? '');
    if ($idSet<=0 || $nombre===''){ $_SESSION['error_sets']="Datos inválidos."; header("Location: gestionar_sets.php"); exit(); }
    $stmt=$conn->prepare("UPDATE question_set SET nombre_set=?, description=?, updated_at=NOW() WHERE id=?");
    $stmt->bind_param("ssi", $nombre, $desc, $idSet);
    $stmt->execute(); $stmt->close();
    $_SESSION['success_sets']="Set actualizado.";
    header("Location: gestionar_sets.php?idSet=".$idSet); exit();
  }

  if ($action==='add_question'){
    $idSet            = (int)($_POST['id_set'] ?? 0);
    $question_text    = trim($_POST['question_text'] ?? '');
    $id_question_type = (int)($_POST['id_question_type'] ?? 0);
    $is_required      = isset($_POST['is_required']) ? 1 : 0;
    $is_valued        = isset($_POST['is_valued']) ? 1 : 0;
    $dependency_option= (isset($_POST['dependency_option']) && $_POST['dependency_option'] !== '') ? (int)$_POST['dependency_option'] : null;

    if ($idSet<=0 || $question_text==='' || $id_question_type<=0){
      $_SESSION['error_sets']="Faltan datos.";
      header("Location: gestionar_sets.php?idSet=".$idSet); exit();
    }

    try {
      // Iniciamos transacción porque vamos a mover sort_order + insertar
      $conn->begin_transaction();

      // Calcular sort_order según si tiene dependencia o no
      if (is_null($dependency_option)) {
        // Sin dependencia => al final, como antes
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM question_set_questions WHERE id_question_set=?");
        $stmt->bind_param("i",$idSet);
        $stmt->execute();
        $stmt->bind_result($newSort);
        $stmt->fetch();
        $stmt->close();
      } else {
        // Con dependencia: buscamos la pregunta PADRE de esa opción y su sort_order
        $stmt = $conn->prepare("
          SELECT q.id, q.sort_order
          FROM question_set_questions q
          JOIN question_set_options o ON o.id_question_set_question = q.id
          WHERE q.id_question_set = ? AND o.id = ?
          LIMIT 1
        ");
        $stmt->bind_param("ii", $idSet, $dependency_option);
        $stmt->execute();
        $stmt->bind_result($parentId, $parentSort);
        $hasParent = $stmt->fetch();
        $stmt->close();

        if ($hasParent) {
          // Queremos que la nueva pregunta quede inmediatamente debajo del padre
          $newSort = (int)$parentSort + 1;

          // Desplazar hacia abajo todas las preguntas desde ahí
          $stmt = $conn->prepare("
            UPDATE question_set_questions
               SET sort_order = sort_order + 1
             WHERE id_question_set = ?
               AND sort_order >= ?
          ");
          $stmt->bind_param("ii", $idSet, $newSort);
          $stmt->execute();
          $stmt->close();
        } else {
          // Opción no válida o de otro set: degradar a "sin dependencia" y poner al final
          $stmt = $conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM question_set_questions WHERE id_question_set=?");
          $stmt->bind_param("i",$idSet);
          $stmt->execute();
          $stmt->bind_result($newSort);
          $stmt->fetch();
          $stmt->close();

          $dependency_option = null;
        }
      }

      // Insertar la pregunta con el sort_order calculado
      $stmt=$conn->prepare("
        INSERT INTO question_set_questions
          (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued)
        VALUES (?,?,?,?,?,?,?)
      ");
      // id_dependency_option puede ser null; bind_param lo envía como int o null
      $stmt->bind_param(
        "isiiiii",
        $idSet,
        $question_text,
        $id_question_type,
        $newSort,
        $is_required,
        $dependency_option,
        $is_valued
      );
      $ok = $stmt->execute();
      $newQ = $stmt->insert_id;
      $stmt->close();

      if(!$ok){
        throw new Exception("Error insertando la pregunta.");
      }

      // Opciones según tipo (mismo comportamiento de antes)
      if (in_array($id_question_type,[1,2,3],true)){
        if (!empty($_POST['options']) && is_array($_POST['options'])){
          $sortOpt=1;
          foreach($_POST['options'] as $i=>$txt){
            $txt=trim($txt); if($txt==='') continue;
            $ref='';
            if(isset($_FILES['option_images']['name'][$i]) && $_FILES['option_images']['error'][$i]===UPLOAD_ERR_OK){
              $tmp  = $_FILES['option_images']['tmp_name'][$i];
              $name = basename($_FILES['option_images']['name'][$i]);
              $ext  = strtolower(pathinfo($name,PATHINFO_EXTENSION));
              $destDir = $_SERVER['DOCUMENT_ROOT'].'/uploads/opciones/';
              if(!is_dir($destDir)) mkdir($destDir,0755,true);
              $fname = uniqid('opt_',true).'.'.$ext;
              if(move_uploaded_file($tmp,$destDir.$fname)) $ref = '/uploads/opciones/'.$fname;
            }
            $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
            $st->bind_param("issi",$newQ,$txt,$ref,$sortOpt); 
            $st->execute(); 
            $st->close();
            $sortOpt++;
          }
        } elseif ($id_question_type===1){
          // Sí / No fijos si no se mandó nada
          $sortOpt=1;
          foreach(["Sí","No"] as $txt){
            $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
            $empty=''; 
            $st->bind_param("issi",$newQ,$txt,$empty,$sortOpt); 
            $st->execute(); 
            $st->close(); 
            $sortOpt++;
          }
        }
      }

      // Todo OK
      $conn->commit();

      $_SESSION['success_sets']="Pregunta agregada.";
      header("Location: gestionar_sets.php?idSet=".$idSet); 
      exit();

    } catch (Exception $e) {
      // Revertir en caso de error
      if ($conn->errno === 0) {
        // por si la transacción no alcanzó a empezar, evitar warning
        try { $conn->rollback(); } catch (Throwable $t) {}
      } else {
        try { $conn->rollback(); } catch (Throwable $t) {}
      }
      $_SESSION['error_sets']="Error insertando la pregunta: ".$e->getMessage();
      header("Location: gestionar_sets.php?idSet=".$idSet); 
      exit();
    }
  }
}

/* ---------- Carga para vista ---------- */

$sets = getSets($conn);
$idSetSel = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
$selectedSet = $idSetSel ? getSet($conn, $idSetSel) : null;

$tree = [];
$flat = [];
$optionsMap = [];
if ($selectedSet){
  $flat = getQuestionsFlat($conn, $idSetSel);
  $ids  = array_map(fn($r)=>(int)$r['id'],$flat);
  $optionsMap = getOptionsForQuestions($conn,$ids);
  $tree = buildTree($flat, $optionsMap, $conn);
}

/* ---------- Mapa de dependencias por opción (para badges + tooltips) ---------- */
$depCountsByOption = [];
$depChildrenByOption = [];
if ($selectedSet){
  $stmt = $conn->prepare("
    SELECT id, question_text, id_dependency_option
    FROM question_set_questions
    WHERE id_question_set = ? AND id_dependency_option IS NOT NULL
  ");
  $stmt->bind_param("i", $idSetSel);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $optId = (int)$row['id_dependency_option'];
    if (!isset($depCountsByOption[$optId])) $depCountsByOption[$optId] = 0;
    $depCountsByOption[$optId]++;
    $depChildrenByOption[$optId][] = $row['question_text'];
  }
  $stmt->close();
}

/* --------- Modelo JS para simulador --------- */
$jsQuestions = [];
if ($selectedSet){
  foreach($flat as $q){
    $ops = $optionsMap[$q['id']] ?? [];
    $jsQuestions[] = [
      'id' => (int)$q['id'],
      'text' => $q['question_text'],
      'type' => (int)$q['id_question_type'],
      'required' => (int)$q['is_required']===1,
      'valued' => (int)$q['is_valued']===1,
      'dep' => $q['id_dependency_option'] ? (int)$q['id_dependency_option'] : null,
      'options' => array_map(fn($o)=>['id'=>(int)$o['id'],'text'=>$o['option_text']], $ops)
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Constructor de Sets de Preguntas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
  body{background:#f6f7fb;}
  .left-col{transition:all .25s ease;}
  .builder-card{border:0; box-shadow:0 10px 24px rgba(0,0,0,.06); border-radius:14px;}
  .toolbar{position:sticky; top:0; z-index:1020; background:#fff; padding:.5rem 1rem; border-bottom:1px solid #eee; border-top-left-radius:14px; border-top-right-radius:14px;}
  .q-item{list-style:none; margin-bottom:.75rem;}
  .q-card{background:#fff; border:1px solid #e9ecef; border-radius:10px; padding:.75rem 1rem;}
  .q-head{display:flex; align-items:center; justify-content:space-between;}
  .drag-handle{cursor:move; color:#adb5bd; margin-right:.5rem;}
  .q-title{font-weight:600; margin:0;}
  .q-title[contenteditable="true"]{outline: 2px dashed #bcd; border-radius:4px; padding:2px 4px;}
  .chips .badge{margin-left:.25rem;}
  .q-actions .btn{margin-left:.25rem;}
  .rail{background:#fbfcfe; border:1px dashed #e1e6ef; border-radius:8px; padding:.5rem .75rem; margin-top:.5rem;}
  .rail-title{font-size:.85rem; color:#6c757d; margin-bottom:.25rem; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;}
  .opt-chip{display:inline-block; padding:.15rem .5rem; border-radius:999px; background:#eaf2ff; font-weight:600;}
  .ref-thumb{width:28px; height:28px; object-fit:cover; border-radius:4px; border:1px solid #e1e6ef;}
  .child-sortable{min-height:18px; padding-left:0; margin-bottom:0;}
  .sortable-placeholder{border:2px dashed #91b0ff; background:#eef4ff; height:52px; margin:.5rem 0; border-radius:8px;}
  .small-help{font-size:.85rem; color:#6c757d;}
  .btn-light-outline{background:#fff;border:1px solid #ced4da;}
  .list-unstyled{padding-left:0; margin:0;}
  .hide-left .left-col{display:none;}
  .hide-left .right-col{flex:0 0 100%; max-width:100%;}
  .collapsed > .q-card .rail { display:none; }
  .highlight { animation: flash 1.2s ease-in-out 1; }
  @keyframes flash {
    0%{box-shadow:0 0 0 rgba(17,120,255,0);}
    25%{box-shadow:0 0 0 6px rgba(17,120,255,.2);}
    100%{box-shadow:0 0 0 rgba(17,120,255,0);}
  }
  /* Toasts */
  .toast-container{position:fixed; top:12px; right:12px; z-index:2000;}
</style>
</head>
<body class="p-3">
<div class="container-fluid">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">Constructor de preguntas</h3>
    <?php if($selectedSet): ?>
      <span class="ml-2 text-muted">Set: <strong><?= htmlspecialchars($selectedSet['nombre_set'], ENT_QUOTES) ?></strong></span>
    <?php endif; ?>
    <div class="ml-auto">
      <div class="input-group input-group-sm" style="max-width:280px;">
        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
        <input type="text" class="form-control" id="searchInput" placeholder="Buscar pregunta/opción…">
      </div>
      <button class="btn btn-sm btn-outline-secondary ml-2" id="toggleLeft">Ocultar panel</button>
    </div>
  </div>

  <?php if (!empty($_SESSION['success_sets'])): ?>
    <div class="alert alert-success"><?= $_SESSION['success_sets']; unset($_SESSION['success_sets']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error_sets'])): ?>
    <div class="alert alert-danger"><?= $_SESSION['error_sets']; unset($_SESSION['error_sets']); ?></div>
  <?php endif; ?>

  <div class="row no-gutters">
    <!-- Panel izquierdo -->
    <div class="col-md-3 pr-3 left-col">
      <div class="card builder-card mb-3">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
          <strong>Sets disponibles</strong>
          <?php if($selectedSet): ?>
          <div class="btn-group btn-group-sm">
            <a class="btn btn-light" href="clonar_set.php?idSet=<?= $selectedSet['id'] ?>" title="Clonar set"><i class="fa fa-copy"></i></a>
            <a class="btn btn-light" href="exportar_set_csv.php?idSet=<?= $selectedSet['id'] ?>" title="Exportar CSV"><i class="fa fa-file-csv"></i></a>
          </div>
          <?php endif; ?>
        </div>
        <div class="card-body" style="max-height:70vh; overflow-y:auto;">
          <?php if($sets): ?>
            <div class="list-group">
              <?php foreach($sets as $s): ?>
                <a href="?idSet=<?= $s['id'] ?>" class="list-group-item list-group-item-action <?= ($idSetSel==$s['id']?'active':'') ?>">
                  <div class="d-flex justify-content-between">
                    <div>
                      <div class="font-weight-bold"><?= htmlspecialchars($s['nombre_set'], ENT_QUOTES) ?></div>
                      <small class="text-muted"><?= htmlspecialchars($s['description']??'', ENT_QUOTES) ?></small>
                    </div>
                    <span><i class="fa fa-chevron-right"></i></span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="mb-0">No hay sets.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card builder-card">
        <div class="card-header bg-success text-white"><strong>Crear nuevo set</strong></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="create_set">
            <div class="form-group">
              <label>Nombre</label>
              <input type="text" class="form-control" name="nombre_set" required>
            </div>
            <div class="form-group">
              <label>Descripción</label>
              <textarea class="form-control" name="desc_set"></textarea>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Crear set</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Panel derecho (constructor) -->
    <div class="col-md-9 right-col">
      <?php if($selectedSet): ?>
      <div class="card builder-card">
        <div class="toolbar d-flex align-items-center flex-wrap">
          <div class="custom-control custom-switch mr-3 mb-1">
            <input type="checkbox" class="custom-control-input" id="toggleDnD">
            <label class="custom-control-label" for="toggleDnD">Arrastrar para reordenar (opcional)</label>
          </div>

          <div class="btn-group btn-group-sm ml-2 mb-1">
            <button class="btn btn-outline-secondary" id="btnExpandAll"><i class="fa fa-plus-square"></i> Expandir todo</button>
            <button class="btn btn-outline-secondary" id="btnCollapseAll"><i class="fa fa-minus-square"></i> Colapsar todo</button>
          </div>

          <div class="ml-auto btn-group btn-group-sm mb-1">
            <button class="btn btn-outline-info" id="btnSimular"><i class="fa fa-vial"></i> Simular</button>
            <button class="btn btn-primary" id="btnSaveStructure" disabled><i class="fa fa-layer-group"></i> Guardar estructura</button>
          </div>
        </div>

        <div class="card-body">
          <!-- Editar set -->
          <form method="post" class="mb-3">
            <input type="hidden" name="action" value="update_set">
            <input type="hidden" name="id_set" value="<?= $selectedSet['id'] ?>">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label>Nombre</label>
                <input type="text" name="nombre_set" class="form-control" value="<?= htmlspecialchars($selectedSet['nombre_set'], ENT_QUOTES) ?>" required>
              </div>
              <div class="form-group col-md-6">
                <label>Descripción</label>
                <textarea name="desc_set" class="form-control" rows="1"><?= htmlspecialchars($selectedSet['description'], ENT_QUOTES) ?></textarea>
              </div>
            </div>
            <div class="text-right"><button class="btn btn-success" type="submit">Actualizar set</button></div>
          </form>

          <hr>

          <!-- Añadir UNA pregunta -->
          <h5 class="mt-4">Añadir UNA pregunta</h5>
          <form method="post" enctype="multipart/form-data" class="mb-4 p-3 border rounded bg-white">
            <input type="hidden" name="action" value="add_question">
            <input type="hidden" name="id_set" value="<?= $selectedSet['id'] ?>">
            <div class="form-group">
              <label>Texto de la pregunta</label>
              <input type="text" name="question_text" class="form-control" required>
            </div>
            <div class="form-row">
              <div class="form-group col-md-4">
                <label>Tipo</label>
                <select name="id_question_type" id="questionTypeSingle" class="form-control" required>
                  <option value="">-- Seleccione --</option>
                  <option value="1">Sí/No</option>
                  <option value="2">Selección única</option>
                  <option value="3">Selección múltiple</option>
                  <option value="4">Texto</option>
                  <option value="5">Numérico</option>
                  <option value="6">Fecha</option>
                  <option value="7">Foto</option>
                </select>
              </div>
              <div class="form-group col-md-4">
                <label>Depende de (opcional)</label>
                <select name="dependency_option" class="form-control">
                  <option value="">Ninguna</option>
                  <?php
                  // opciones disparadoras disponibles (tipos 1,2,3) — agrupadas por pregunta
                  foreach($flat as $q){
                    if (in_array((int)$q['id_question_type'],[1,2,3],true)){
                      $opts = $optionsMap[$q['id']] ?? [];
                      if (!$opts) continue;
                      echo '<optgroup label="P: '.htmlspecialchars($q['question_text'],ENT_QUOTES).'">';
                      foreach($opts as $op){
                        $label = 'Opción: '.htmlspecialchars($op['option_text'],ENT_QUOTES);
                        echo '<option value="'.$op['id'].'">'.$label.'</option>';
                      }
                      echo '</optgroup>';
                    }
                  }
                  ?>
                </select>
                <small class="text-muted">Selecciona la <b>opción</b> que dispara esta pregunta.</small>
              </div>
              <div class="form-group col-md-4">
                <div class="custom-control custom-checkbox mt-4">
                  <input type="checkbox" class="custom-control-input" id="isRequiredSingle" name="is_required" value="1">
                  <label class="custom-control-label" for="isRequiredSingle">¿Requerida?</label>
                </div>
                <div class="custom-control custom-checkbox mt-2" id="valuedContainerSingle" style="display:none;">
                  <input type="checkbox" class="custom-control-input" id="isValuedSingle" name="is_valued" value="1">
                  <label class="custom-control-label" for="isValuedSingle">¿Valorizada?</label>
                </div>
              </div>
            </div>

            <div id="optionContainerSingle" style="display:none;">
              <label>Opciones</label>
              <div id="optionRowsSingle"></div>
              <button class="btn btn-sm btn-secondary" type="button" onclick="addSingleOptionRow()">+ Opción</button>
            </div>

            <div class="text-right mt-3">
              <button class="btn btn-primary" type="submit">Agregar pregunta</button>
            </div>
          </form>

          <hr>

          <!-- Leyenda rápida -->
          <p class="text-muted mb-2">
            <span class="badge badge-primary">N hijos</span> = total de preguntas dependientes. En cada opción verás
            <span class="badge badge-info">N dependientes</span> 
          </p>

          <!-- Árbol visual -->
          <h5 class="mt-2 mb-3">Preguntas del set</h5>
          <ul id="sortableRoot" class="list-unstyled">
            <?php
            // renderer recursivo con badges y previews
            function renderNode($node, $setId, $depCountsByOption, $depChildrenByOption){
              $qid  = (int)$node['id'];
              $tipo = tipoTexto($node['id_question_type']);
              $req  = (int)$node['is_required']===1;
              $val  = (int)$node['is_valued']===1;

              // total hijos = suma de count por cada opción
              $totalHijos = 0;
              if (!empty($node['options'])) {
                foreach ($node['options'] as $op) {
                  $totalHijos += $depCountsByOption[$op['id']] ?? 0;
                }
              }

              echo '<li class="q-item" data-id="'.$qid.'">';
              echo '  <div class="q-card">';
              echo '    <div class="q-head">';
              echo '      <div class="d-flex align-items-center">';
              echo '        <span class="drag-handle mr-2"><i class="fa fa-grip-vertical"></i></span>';
              echo '        <button class="btn btn-sm btn-link text-secondary px-1 collapse-toggle" title="Colapsar/expandir"><i class="fa fa-chevron-down"></i></button>';
              echo '        <h6 class="q-title mb-0" data-id="'.$qid.'" title="Doble click para editar">'.$node['question_text'].'</h6>';
              echo '        <div class="chips ml-2">';
              echo '          <span class="badge badge-light">'.htmlspecialchars($tipo,ENT_QUOTES).'</span>';
              if ($req) echo ' <span class="badge badge-danger">Requerida</span>';
              if ($val && in_array((int)$node['id_question_type'],[2,3],true)) echo ' <span class="badge badge-info">Valorizada</span>';
              if ($totalHijos > 0) {
                echo ' <span class="badge badge-primary js-badge-children" data-qid="'.$qid.'" data-toggle="tooltip" title="Total de dependientes de sus opciones">'
                   . $totalHijos . ' ' . ($totalHijos === 1 ? 'hijo' : 'hijos') . '</span>';
              }
              echo '        </div>';
              echo '      </div>';
              echo '      <div class="q-actions">';
              echo '        <button class="btn btn-sm btn-light-outline move-up" title="Subir"><i class="fa fa-arrow-up"></i></button>';
              echo '        <button class="btn btn-sm btn-light-outline move-down" title="Bajar"><i class="fa fa-arrow-down"></i></button>';
              echo '        <button class="btn btn-sm btn-outline-secondary move-to" title="Cambiar condición / destino"><i class="fa fa-share"></i></button>';
              // echo '        <a class="btn btn-sm btn-outline-primary" href="duplicar_pregunta.php?id='.$qid.'&idSet='.$setId.'&mode=solo" title="Duplicar pregunta"><i class="fa fa-copy"></i></a>';
              // echo '        <a class="btn btn-sm btn-outline-primary" href="duplicar_pregunta.php?id='.$qid.'&idSet='.$setId.'&mode=rama" title="Duplicar rama"><i class="fa fa-sitemap"></i></a>';
              echo '        <button class="btn btn-sm btn-info" onclick="editarSetPregunta('.$qid.', '.$setId.')" title="Editar"><i class="fa fa-pen"></i></button>';
              echo '        <a class="btn btn-sm btn-danger" href="eliminar_set_pregunta.php?id='.$qid.'&idSet='.$setId.'" title="Eliminar"><i class="fa fa-trash"></i></a>';
              echo '      </div>';
              echo '    </div>';

              // rails por opción con preview y badge dependientes
              if (!empty($node['options'])){
                echo '<div class="mt-2">';
                foreach($node['options'] as $op){
                  $optId = (int)$op['id'];
                  $optTx = htmlspecialchars($op['option_text'],ENT_QUOTES);
                  $cnt   = $depCountsByOption[$optId] ?? 0;

                  // tooltip con hijos por opción
                  $tooltip = '';
                  if ($cnt > 0) {
                    $children = $depChildrenByOption[$optId] ?? [];
                    $html = '';
                    foreach ($children as $cTxt) {
                      $html .= htmlspecialchars($cTxt, ENT_QUOTES) . '<br>';
                    }
                    $tooltip = ' data-toggle="tooltip" data-html="true" title="'.$html.'"';
                  }

                  echo '<div class="rail" data-parent-id="'.$qid.'" data-dep="'.$optId.'">';
                  echo '  <div class="rail-title">';
                  // Preview de referencia (si existe)
                  if (!empty($op['reference_image'])) {
                    $ref = htmlspecialchars($op['reference_image'], ENT_QUOTES);
                    echo '    <button type="button" class="btn btn-link p-0 js-ref-thumb" data-src="'.$ref.'" data-caption="'.$optTx.'" title="Ver imagen de referencia">';
                    echo '      <img class="ref-thumb" src="'.$ref.'" alt="Imagen de referencia">';
                    echo '    </button>';
                  }
                  echo '    <span class="opt-chip">'.$optTx.'</span>';
                  echo '    <span class="badge badge-'.($cnt>0?'info':'light').' js-show-children" data-dep="'.$optId.'"'.$tooltip.'>'
                       . $cnt . ' ' . ($cnt === 1 ? 'dependiente' : 'dependientes') . '</span>';
                  echo '    <span class="text-muted">Aparecen si eliges esta opción</span>';
                  echo '  </div>';

                  echo '  <ul class="child-sortable list-unstyled" data-parent-id="'.$qid.'" data-dep="'.$optId.'">';
                  // hijos que dependan de esta opción
                  if (!empty($node['children'])){
                    foreach($node['children'] as $child){
                      if ((int)$child['id_dependency_option'] === $optId){
                        renderNode($child, $setId, $depCountsByOption, $depChildrenByOption);
                      }
                    }
                  }
                  echo '  </ul>';
                  echo '</div>';
                }
                echo '</div>';
              }

              echo '  </div>';
              echo '</li>';
            }

            foreach($tree as $n) renderNode($n, $selectedSet['id'], $depCountsByOption, $depChildrenByOption);
            ?>
          </ul>

          <div hidden class="small-help mt-3">
            • Usa <b>↑ / ↓</b> para mover dentro del mismo bloque.  
            • <b>“Cambiar condición / destino”</b> para enviar una pregunta a otro bloque/condición.  
            • Doble click sobre el título para edición rápida.  
            • Activa el <b>arrastre</b> si te acomoda mover con DnD.  
            • <b>Ctrl / Cmd + S</b> guarda la estructura.
          </div>

        </div>
      </div>
      <?php else: ?>
        <div class="alert alert-info">Selecciona un set en el panel izquierdo para construir su cuestionario.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Toasts -->
<div class="toast-container" id="toastContainer"></div>

<!-- Modal Mover a… -->
<div class="modal fade" id="moveModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Cambiar condición / destino</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Destino</label>
          <select id="moveTarget" class="form-control"></select>
          <small class="text-muted">“Raíz del set” o la <b>opción</b> de una pregunta disparadora.</small>
        </div>
        <div class="form-group mb-0">
          <label>Posición</label>
          <select id="movePosition" class="form-control">
            <option value="end">Al final</option>
            <option value="start">Al inicio</option>
            <option value="before">Antes de… (misma lista)</option>
            <option value="after">Después de… (misma lista)</option>
          </select>
          <select id="moveSibling" class="form-control mt-2" style="display:none;"></select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary btn-sm" id="confirmMove">Mover</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal editar (AJAX) -->
<div class="modal fade" id="editarSetPreguntaModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content" id="editarSetPreguntaModalContent"></div></div>
</div>

<!-- Modal de previsualización de imagen de referencia -->
<div class="modal fade" id="refImageModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="refImageCaption">Imagen de referencia</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-0 text-center">
        <img id="refImageModalImg" src="" alt="Imagen de referencia" style="max-width:100%;height:auto;">
      </div>
    </div>
  </div>
</div>

<!-- Modal Simulación -->
<div class="modal fade" id="simModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Simulador de flujo</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="simContainer" class="p-2"></div>
      </div>
    </div>
  </div>
</div>

<script>
  window.SET_MODEL = <?= json_encode(['questions'=>$jsQuestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ====== Utils ====== */
function notify(type, msg){
  var id = 't'+Date.now();
  var cls = (type==='error'?'bg-danger text-white': type==='info'?'bg-info text-white': 'bg-success text-white');
  var html = `
    <div class="toast ${cls}" id="${id}" role="alert" aria-live="assertive" aria-atomic="true" data-delay="2500">
      <div class="toast-body d-flex align-items-center">
        <i class="mr-2 ${type==='error'?'fa fa-times-circle': type==='info'?'fa fa-info-circle':'fa fa-check-circle'}"></i>
        <div>${msg}</div>
      </div>
    </div>`;
  $('#toastContainer').append(html);
  $('#'+id).toast('show').on('hidden.bs.toast', function(){ $(this).remove(); });
}
let dirty = false; // seguimos usando dirty solo para habilitar el botón de guardar
function markDirty(){
  if (!dirty){
    dirty = true;
    $('#btnSaveStructure').prop('disabled', false);
  }
}


const SHOW_UNSAVED_WARNING = false; //dejar en true para mostrar mensajes de confirmacion al editars sets
window.addEventListener('beforeunload', function(e){
  if (SHOW_UNSAVED_WARNING && dirty){
    e.preventDefault();
    e.returnValue = '';
  }
});

/* ====== Imagen preview modal ====== */
$(document).on('click', '.js-ref-thumb', function (e) {
  e.preventDefault();
  var src = $(this).data('src');
  var caption = $(this).data('caption') || 'Imagen de referencia';
  $('#refImageModalImg').attr('src', src);
  $('#refImageCaption').text(caption);
  $('#refImageModal').modal('show');
});
$('#refImageModal').on('hidden.bs.modal', function () {
  $('#refImageModalImg').attr('src', '');
});

/* ====== Panel izquierdo ====== */
$('#toggleLeft').on('click', function(){
  document.body.classList.toggle('hide-left');
  $(this).text(document.body.classList.contains('hide-left') ? 'Mostrar panel' : 'Ocultar panel');
});

/* ====== Tooltips ====== */
$(function(){ $('[data-toggle="tooltip"]').tooltip({container:'body'}); });

/* ====== Alta de opciones en creación ====== */
function previewOptionImage(evt, previewId){
  var f=evt.target.files[0]; if(!f) return;
  if(!f.type.startsWith('image/')){ notify('error','Solo imágenes'); evt.target.value=''; return; }
  var rd=new FileReader(); rd.onload=e=>{ var im=document.getElementById(previewId); if(im){ im.src=e.target.result; im.style.display='block'; } };
  rd.readAsDataURL(f);
}
function addSingleOptionRow(){
  var wrap=document.getElementById('optionRowsSingle');
  var idx=wrap.children.length, prev='singlePrev_'+idx;
  var div=document.createElement('div');
  div.className='border rounded p-2 mb-2';
  div.innerHTML=`
    <div class="d-flex align-items-center">
      <input type="text" class="form-control" name="options[${idx}]" placeholder="Texto de la opción" required>
      <button type="button" class="btn btn-sm btn-outline-danger ml-2" onclick="this.closest('.border').remove()">X</button>
    </div>
    <div class="custom-file mt-2">
      <input type="file" class="custom-file-input" name="option_images[${idx}]" accept="image/*" onchange="previewOptionImage(event,'${prev}')">
      <label class="custom-file-label">Imagen (opcional)</label>
    </div>
    <img id="${prev}" style="max-width:100px; margin-top:6px; display:none;">
  `;
  wrap.appendChild(div);
}
document.addEventListener('DOMContentLoaded', function(){
  var sel=document.getElementById('questionTypeSingle');
  if(!sel) return;
  var optWrap=document.getElementById('optionContainerSingle');
  var valWrap=document.getElementById('valuedContainerSingle');
  var rows=document.getElementById('optionRowsSingle');
  sel.addEventListener('change', function(){
    var v=parseInt(this.value||'0',10);
    rows.innerHTML='';
    valWrap.style.display= ([2,3].includes(v)) ? 'block' : 'none';
    if([1,2,3].includes(v)){
      optWrap.style.display='block';
      if(v===1){
        rows.innerHTML = `
          <div class="border rounded p-2 mb-2">
            <div class="d-flex align-items-center">
              <input type="text" class="form-control" name="options[0]" value="Sí" readonly>
              <button type="button" class="btn btn-sm btn-secondary ml-2" disabled>Fijo</button>
            </div>
            <div class="custom-file mt-2">
              <input type="file" class="custom-file-input" name="option_images[0]" accept="image/*" onchange="previewOptionImage(event,'singlePrev_0')">
              <label class="custom-file-label">Imagen (opcional)</label>
            </div>
            <img id="singlePrev_0" style="max-width:100px; margin-top:6px; display:none;">
          </div>
          <div class="border rounded p-2 mb-2">
            <div class="d-flex align-items-center">
              <input type="text" class="form-control" name="options[1]" value="No" readonly>
              <button type="button" class="btn btn-sm btn-secondary ml-2" disabled>Fijo</button>
            </div>
            <div class="custom-file mt-2">
              <input type="file" class="custom-file-input" name="option_images[1]" accept="image/*" onchange="previewOptionImage(event,'singlePrev_1')">
              <label class="custom-file-label">Imagen (opcional)</label>
            </div>
            <img id="singlePrev_1" style="max-width:100px; margin-top:6px; display:none;">
          </div>`;
      }
    } else {
      optWrap.style.display='none';
    }
  });
});

/* ====== Edición AJAX ====== */
function editarSetPregunta(idSetQuestion, idSet){
  $.get('ajax_editar_set_question.php',{idSetQuestion,idSet}, function(html){
    $('#editarSetPreguntaModalContent').html(html);
    $('#editarSetPreguntaModal').modal('show');
  }).fail(()=>notify('error','Error al cargar el formulario de edición.'));
}

/* ====== ↑ / ↓ ====== */
$(document).on('click','.move-up', function(){
  var li=$(this).closest('.q-item'); var prev=li.prev('.q-item'); if(prev.length){ prev.before(li); markDirty(); }
});
$(document).on('click','.move-down', function(){
  var li=$(this).closest('.q-item'); var next=li.next('.q-item'); if(next.length){ next.after(li); markDirty(); }
});

/* ====== Mover a… ====== */
let movingLI=null;
$(document).on('click','.move-to', function(){
  movingLI=$(this).closest('.q-item');
  buildMoveTargets();
  $('#movePosition').val('end'); $('#moveSibling').hide().empty();
  $('#moveModal').modal('show');
});
$('#movePosition').on('change', function(){
  var pos=$(this).val();
  if(pos==='before'||pos==='after'){ buildMoveSiblings($('#moveTarget').val()); $('#moveSibling').show(); }
  else { $('#moveSibling').hide().empty(); }
});
$('#moveTarget').on('change', function(){
  var pos=$('#movePosition').val();
  if(pos==='before'||pos==='after'){ buildMoveSiblings($(this).val()); }
});
$('#confirmMove').on('click', function(){
  var target=$('#moveTarget').val(), pos=$('#movePosition').val(), sib=$('#moveSibling').val();
  var listEl = (target==='root') ? $('#sortableRoot') : getRailByValue(target);
  if(!listEl || !listEl.length) return;
  if(pos==='start') listEl.prepend(movingLI);
  else if(pos==='end') listEl.append(movingLI);
  else {
    var ref = $('.q-item[data-id="'+sib+'"]');
    if(ref.length){
      if(pos==='before') ref.before(movingLI); else ref.after(movingLI);
    } else listEl.append(movingLI);
  }
  $('#moveModal').modal('hide'); movingLI=null; markDirty();
});

function buildMoveTargets(){
  var sel=$('#moveTarget').empty();
  sel.append('<option value="root">Raíz del set</option>');
  $('.rail').each(function(){
    var parent=$(this).data('parent-id'), dep=$(this).data('dep');
    var parentTitle=$('.q-item[data-id="'+parent+'"]').find('> .q-card .q-title').first().text().trim();
    var optText=$(this).find('.opt-chip').first().text().trim();
    sel.append('<option value="'+parent+':'+dep+'">'+escapeHtml(parentTitle)+' → opción: '+escapeHtml(optText)+'</option>');
  });
}
function buildMoveSiblings(value){
  var sel=$('#moveSibling').empty();
  var list = (value==='root') ? $('#sortableRoot') : getRailByValue(value);
  if(!list || !list.length) return;
  list.children('.q-item').each(function(){
    var id=$(this).data('id'); if(movingLI && id==movingLI.data('id')) return;
    var t=$(this).find('> .q-card .q-title').first().text().trim();
    sel.append('<option value="'+id+'">'+escapeHtml(t)+'</option>');
  });
}
function getRailByValue(v){
  var p=v.split(':'); return $('.child-sortable[data-parent-id="'+p[0]+'"][data-dep="'+p[1]+'"]');
}
function escapeHtml(s){return (s||'').replace(/[&<>"'`=\/]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[c]});}

/* ====== DnD opcional ====== */
let dndActive=false;
function activateDnD(){
  if(dndActive) return;
  $('#sortableRoot, .child-sortable').sortable({
    connectWith:'#sortableRoot, .child-sortable',
    handle:'.drag-handle',
    placeholder:'sortable-placeholder',
    forcePlaceholderSize:true,
    tolerance:'pointer',
    cancel:'a,button,.btn,input,[contenteditable]'
  }).on('sortupdate', markDirty).disableSelection();
  dndActive=true;
}
function deactivateDnD(){
  if(!dndActive) return;
  try{$('#sortableRoot').sortable('destroy');}catch(e){}
  $('.child-sortable').each(function(){try{$(this).sortable('destroy');}catch(e){}});
  dndActive=false;
}
$('#toggleDnD').on('change', function(){ this.checked?activateDnD():deactivateDnD(); });

/* ====== Guardar estructura ====== */
function buildOrderTree($rootUl){
  function extract($ul){
    var arr=[];
    $ul.children('.q-item').each(function(){
      var node={ id: $(this).data('id'), children: [] };
      $(this).find('> .q-card .rail .child-sortable').each(function(){
        node.children = node.children.concat(extract($(this)));
      });
      arr.push(node);
    });
    return arr;
  }
  return extract($rootUl);
}
function collectStructure(){
  var rows=[], order=1;
  function collectFrom($ul){
    var pid = $ul.data('parent-id') || null;
    var dep = $ul.data('dep') || null;
    $ul.children('.q-item').each(function(){
      rows.push({
        id: $(this).data('id'),
        parent_id: pid?parseInt(pid,10):null,
        dep_option_id: dep?parseInt(dep,10):null,
        sort_order: order++
      });
      $(this).find('> .q-card .rail .child-sortable').each(function(){ collectFrom($(this)); });
    });
  }
  collectFrom($('#sortableRoot'));
  return rows;
}
$('#btnSaveStructure').on('click', saveStructure);
function saveStructure(){
  const $btn = $('#btnSaveStructure').prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Guardando…');
  const payload = collectStructure();
  $.post('actualizar_orden_dependencias_set.php',{
    idSet: <?= $selectedSet? $selectedSet['id'] : 0 ?>,
    data: JSON.stringify(payload)
  }).done(msg => { notify('success', msg || 'Estructura guardada.'); dirty=false; $('#btnSaveStructure').prop('disabled', true).html('<i class="fa fa-layer-group"></i> Guardar estructura'); })
    .fail(xhr => { notify('error', xhr.responseText || 'Error al guardar.'); $('#btnSaveStructure').prop('disabled', false).html('<i class="fa fa-layer-group"></i> Guardar estructura'); });
}
document.addEventListener('keydown', function(e){
  if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){
    e.preventDefault();
    if (!$('#btnSaveStructure').prop('disabled')) saveStructure();
  }
});

/* ====== Expandir / Colapsar ====== */
$('#btnExpandAll').on('click', function(){ $('.q-item').removeClass('collapsed'); });
$('#btnCollapseAll').on('click', function(){ $('.q-item').addClass('collapsed'); });
$(document).on('click','.collapse-toggle', function(){
  $(this).closest('.q-item').toggleClass('collapsed');
});

/* ====== Resaltar hijos al click en badge ====== */
$(document).on('click','.js-show-children', function(){
  var dep = $(this).data('dep');
  var $list = $('.child-sortable[data-dep="'+dep+'"]');
  if($list.length){
    $('html,body').animate({scrollTop:$list.offset().top-120}, 300);
    $list.addClass('highlight'); setTimeout(()=>{$list.removeClass('highlight');}, 1200);
  }
});

/* ====== Edición inline de títulos ====== */
$(document).on('dblclick','.q-title', function(){
  $(this).attr('contenteditable','true').focus();
});
$(document).on('blur keydown','.q-title[contenteditable="true"]', function(e){
  if (e.type==='keydown' && e.key!=='Enter') return;
  e.preventDefault();
  var $el=$(this); $el.removeAttr('contenteditable');
  var text=$el.text().trim(), id=$el.data('id');
  if (text===''){ notify('error','El texto no puede ser vacío.'); return; }
  $.post('ajax_update_question_text.php', { idSet: <?= (int)$idSetSel ?>, idQuestion:id, text:text })
    .done(()=>notify('success','Título actualizado.'))
    .fail(()=>notify('error','No se pudo actualizar.'));
});

/* ====== Buscador ====== */
$('#searchInput').on('input', function(){
  const q = (this.value||'').toLowerCase().trim();
  if(!q){
    $('.q-item').show();
    return;
  }
  // Ocultar todo inicialmente
  $('.q-item').hide();

  // Filtrar por texto de pregunta u opciones visibles en rail-title
  $('.q-item').each(function(){
    const $li = $(this);
    const title = $li.find('> .q-card .q-title').first().text().toLowerCase();
    let match = title.includes(q);
    if(!match){
      $li.find('> .q-card .rail .rail-title .opt-chip').each(function(){
        if ($(this).text().toLowerCase().includes(q)) { match=true; return false; }
      });
    }
    if(match){
      $li.show();
      // mostrar ancestros
      $li.parents('.q-item').show();
    }
  });
});

/* ====== Simulador ====== */
$('#btnSimular').on('click', function(){
  const data = window.SET_MODEL && window.SET_MODEL.questions ? window.SET_MODEL.questions : [];
  const byId = {}; const byOpt = {};
  data.forEach(q => { byId[q.id]=q; (q.options||[]).forEach(o=>{byOpt[o.id]=q.id;}); });

  const $c = $('#simContainer').empty();

  // estado seleccionado por questionId -> Set de optionIds (para tipo 3) o single optionId
  const state = {};

  function visibleQuestions(){
    // Una pregunta es visible si dep=null, o si su dep (optionId) está seleccionado en su padre
    return data.filter(q=>{
      if (q.dep===null) return true;
      const parentQ = byOpt[q.dep]; if(!parentQ) return false;
      const sel = state[parentQ];
      if (Array.isArray(sel)) return sel.includes(q.dep);
      return sel===q.dep;
    });
  }

  function render(){
    $c.empty();
    const vis = visibleQuestions();
    vis.forEach(q=>{
      const $row = $('<div class="border rounded p-2 mb-2"></div>');
      $row.append('<div class="font-weight-bold mb-1">'+escapeHtml(q.text)+'</div>');
      if ([1,2].includes(q.type)){ // sí/no o única => radio
        const name='q_'+q.id;
        const ops = q.type===1 ? (q.options||[]) : (q.options||[]);
        ops.forEach(o=>{
          const $opt = $(`
            <div class="custom-control custom-radio">
              <input type="radio" id="sim_${o.id}" name="${name}" class="custom-control-input" value="${o.id}">
              <label class="custom-control-label" for="sim_${o.id}">${escapeHtml(o.text)}</label>
            </div>`);
          $row.append($opt);
        });
        if (state[q.id]) $row.find('input[value="'+state[q.id]+'"]').prop('checked', true);
        $row.on('change','input[type=radio]', function(){ state[q.id]=parseInt(this.value,10); render(); });
      } else if (q.type===3){ // múltiple => checkbox
        const ops = q.options||[];
        const sel = Array.isArray(state[q.id]) ? state[q.id] : [];
        ops.forEach(o=>{
          const checked = sel.includes(o.id) ? 'checked' : '';
          const $opt=$(`
            <div class="custom-control custom-checkbox">
              <input type="checkbox" id="sim_${o.id}" class="custom-control-input" value="${o.id}" ${checked}>
              <label class="custom-control-label" for="sim_${o.id}">${escapeHtml(o.text)}</label>
            </div>`);
          $row.append($opt);
        });
        $row.on('change','input[type=checkbox]', function(){
          let arr = Array.isArray(state[q.id]) ? state[q.id] : [];
          const val=parseInt(this.value,10);
          if (this.checked){ if(!arr.includes(val)) arr.push(val); }
          else { arr = arr.filter(x=>x!==val); }
          state[q.id]=arr; render();
        });
      } else {
        $row.append('<input type="text" class="form-control" placeholder="Respuesta…">');
      }
      $c.append($row);
    });
  }
  render();
  $('#simModal').modal('show');
});

/* ====== Acciones pequeñas ====== */
$(function(){ $('[data-toggle="tooltip"]').tooltip({container:'body'}); });
$(document).on('click','.q-actions .btn, .move-up, .move-down, .move-to', markDirty);

/* ====== Título a HTML seguro ====== */
function escapeHtml(s){return (s||'').replace(/[&<>"'`=\/]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[c]});}
</script>
</body>
</html>

<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idForm = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;
if ($idForm<=0) die("ID de formulario no proporcionado.");

/* ----------------- Helpers ----------------- */
function getForm($conn, $id){
  $st=$conn->prepare("SELECT id, nombre FROM formulario WHERE id=?");
  $st->bind_param("i",$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r?:null;
}
function getQuestionsFlat($conn, $idForm){
  $st=$conn->prepare("
    SELECT * FROM form_questions
    WHERE id_formulario=?
    ORDER BY sort_order ASC, id ASC
  ");
  $st->bind_param("i",$idForm); $st->execute(); $res=$st->get_result(); $out=[];
  while($r=$res->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}
function getOptionsForQuestions($conn, $qIds){
  if (empty($qIds)) return [];
  $in=implode(',', array_fill(0,count($qIds),'?')); $types=str_repeat('i',count($qIds));
  $sql="SELECT * FROM form_question_options WHERE id_form_question IN ($in) ORDER BY sort_order ASC, id ASC";
  $st=$conn->prepare($sql);
  $refs=[]; foreach($qIds as $k=>$v){ $qIds[$k]=(int)$v; $refs[$k]=&$qIds[$k]; }
  $st->bind_param($types, ...$refs);
  $st->execute(); $res=$st->get_result(); $map=[];
  while($r=$res->fetch_assoc()){
    $map[(int)$r['id_form_question']][]=$r;
  }
  $st->close(); return $map;
}
function parentQuestionIdByOption($conn, $optId){
  $st=$conn->prepare("SELECT id_form_question FROM form_question_options WHERE id=? LIMIT 1");
  $st->bind_param("i",$optId); $st->execute(); $st->bind_result($pid); $ok=$st->fetch(); $st->close();
  return $ok? (int)$pid : null;
}
function buildTree($questions, $optionsMap, $conn){
  $byId=[];
  foreach($questions as $q){
    $q['children']=[]; $q['options'] = $optionsMap[$q['id']] ?? [];
    $byId[(int)$q['id']] = $q;
  }
  $roots=[];
  foreach($byId as $id=>&$q){
    $depOpt = (int)$q['id_dependency_option'];
    if ($depOpt){
      $parentId = parentQuestionIdByOption($conn, $depOpt);
      if ($parentId && isset($byId[$parentId])){
        $byId[$parentId]['children'][] =& $q;
      } else { $roots[] =& $q; }
    } else { $roots[] =& $q; }
  }
  return $roots;
}
function tipoTexto($t){
  $t=(int)$t;
  return [1=>"Sí/No",2=>"Selección única",3=>"Selección múltiple",4=>"Texto",5=>"Numérico",6=>"Fecha",7=>"Foto"][$t] ?? "Otro";
}

/* ----------------- Carga base ----------------- */
$form = getForm($conn,$idForm);
if(!$form) die("Formulario no encontrado.");

$flat = getQuestionsFlat($conn,$idForm);
$ids  = array_map(fn($r)=>(int)$r['id'],$flat);
$optionsMap = getOptionsForQuestions($conn,$ids);
$tree = buildTree($flat, $optionsMap, $conn);

/* --- Mapa de dependencias por opción --- */
$depCountsByOption = [];
$depChildrenByOption = [];
$st=$conn->prepare("SELECT id, question_text, id_dependency_option FROM form_questions WHERE id_formulario=? AND id_dependency_option IS NOT NULL");
$st->bind_param("i",$idForm); $st->execute(); $res=$st->get_result();
while($r=$res->fetch_assoc()){
  $opt=(int)$r['id_dependency_option'];
  if(!isset($depCountsByOption[$opt])) $depCountsByOption[$opt]=0;
  $depCountsByOption[$opt]++;
  $depChildrenByOption[$opt][]=$r['question_text'];
}
$st->close();

/* --- Modelo JS --- */
$jsQuestions=[];
foreach($flat as $q){
  $ops = $optionsMap[$q['id']] ?? [];
  $jsQuestions[] = [
    'id'   => (int)$q['id'],
    'text' => $q['question_text'],
    'type' => (int)$q['id_question_type'],
    'required' => (int)$q['is_required']===1,
    'valued'   => (int)$q['is_valued']===1,
    'dep'  => $q['id_dependency_option'] ? (int)$q['id_dependency_option'] : null,
    'options' => array_map(fn($o)=>['id'=>(int)$o['id'],'text'=>$o['option_text']], $ops)
  ];
}

/* --- HTML opciones dependencia (Alta) --- */
ob_start();
echo '<option value="">Ninguna</option>';
foreach($flat as $q){
  if (in_array((int)$q['id_question_type'],[1,2,3],true)){
    $ops=$optionsMap[$q['id']] ?? [];
    if(!$ops) continue;
    echo '<optgroup label="P: '.htmlspecialchars($q['question_text'],ENT_QUOTES).'">';
    foreach($ops as $op){
      echo '<option value="'.$op['id'].'">Opción: '.htmlspecialchars($op['option_text'],ENT_QUOTES).'</option>';
    }
    echo '</optgroup>';
  }
}
$dependencyOptionsHtml = ob_get_clean();

/* ----------------- POST: alta UNA pregunta ----------------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_question'){
  $question_text    = trim($_POST['question_text']??'');
  $id_question_type = (int)($_POST['id_question_type']??0);
  $is_required      = isset($_POST['is_required'])?1:0;
  $is_valued        = isset($_POST['is_valued'])?1:0;
  $dependency_option= ($_POST['dependency_option']!=='')? (int)$_POST['dependency_option'] : null;
  if($question_text==='' || $id_question_type<=0){
    $_SESSION['error']="Faltan datos."; header("Location: gestionar_preguntas.php?id_formulario=".$idForm); exit();
  }
  $st=$conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM form_questions WHERE id_formulario=?");
  $st->bind_param("i",$idForm); $st->execute(); $st->bind_result($newSort); $st->fetch(); $st->close();

  $st=$conn->prepare("INSERT INTO form_questions (id_formulario, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued) VALUES (?,?,?,?,?,?,?)");
  $st->bind_param("isiiiii",$idForm,$question_text,$id_question_type,$newSort,$is_required,$dependency_option,$is_valued);
  $ok=$st->execute(); $newQ=$st->insert_id; $st->close();
  if(!$ok){ $_SESSION['error']="Error insertando la pregunta."; header("Location: gestionar_preguntas.php?id_formulario=".$idForm); exit(); }

  if (in_array($id_question_type,[1,2,3],true)){
    if (!empty($_POST['options']) && is_array($_POST['options'])){
      $sortOpt=1;
      foreach($_POST['options'] as $i=>$txt){
        $txt=trim($txt); if($txt==='') continue;
        $ref='';
        if(isset($_FILES['option_images']['name'][$i]) && $_FILES['option_images']['error'][$i]===UPLOAD_ERR_OK){
          $tmp=$_FILES['option_images']['tmp_name'][$i];
          $name=basename($_FILES['option_images']['name'][$i]);
          $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
          $dir=$_SERVER['DOCUMENT_ROOT'].'/uploads/opciones/'; if(!is_dir($dir)) mkdir($dir,0755,true);
          $fn=uniqid('opt_',true).'.'.$ext;
          if(move_uploaded_file($tmp,$dir.$fn)) $ref='/uploads/opciones/'.$fn;
        }
        $st=$conn->prepare("INSERT INTO form_question_options (id_form_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
        $st->bind_param("issi",$newQ,$txt,$ref,$sortOpt); $st->execute(); $st->close(); $sortOpt++;
      }
    } elseif ($id_question_type===1){
      $sortOpt=1;
      foreach(["Sí","No"] as $txt){
        $empty=''; $st=$conn->prepare("INSERT INTO form_question_options (id_form_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
        $st->bind_param("issi",$newQ,$txt,$empty,$sortOpt); $st->execute(); $st->close(); $sortOpt++;
      }
    }
  }
  $_SESSION['success']="Pregunta agregada.";
  header("Location: gestionar_preguntas.php?id_formulario=".$idForm); exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Constructor de preguntas del formulario</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<style>
  body{background:#f6f7fb;}
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
  .collapsed > .q-card .rail { display:none; }
  .highlight { animation: flash 1.2s ease-in-out 1; }
  @keyframes flash { 0%{box-shadow:0 0 0 rgba(17,120,255,0);} 25%{box-shadow:0 0 0 6px rgba(17,120,255,.2);} 100%{box-shadow:0 0 0 rgba(17,120,255,0);} }
  .small-help{font-size:.85rem; color:#6c757d;}
  .toast-container{position:fixed; top:12px; right:12px; z-index:2000;}
</style>
</head>
<body class="p-3">
<div class="container-fluid">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">Constructor de preguntas</h3>
    <span class="ml-2 text-muted">Formulario: <strong><?= htmlspecialchars($form['nombre']??("#".$idForm),ENT_QUOTES) ?></strong></span>
    <div class="ml-auto">
      <div class="input-group input-group-sm" style="max-width:280px;">
        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
        <input type="text" class="form-control" id="searchInput" placeholder="Buscar pregunta/opción…">
      </div>
      <a class="btn btn-sm btn-outline-secondary ml-2" href="editar_formulario.php?id=<?= $idForm ?>">Volver al formulario</a>
    </div>
  </div>

  <?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
  <?php endif; ?>

  <div class="row no-gutters">
    <div class="col-md-12">
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
          <!-- Alta UNA pregunta -->
          <h5 class="mt-1">Añadir UNA pregunta</h5>
          <form method="post" enctype="multipart/form-data" class="mb-4 p-3 border rounded bg-white">
            <input type="hidden" name="action" value="add_question">
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
                  <?= $dependencyOptionsHtml ?>
                </select>
                <small class="text-muted">Selecciona la <b>opción</b> disparadora.</small>
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

          <p class="text-muted mb-2">
            <span class="badge badge-primary">N hijos</span> = total de preguntas dependientes. En cada opción verás
            <span class="badge badge-info">N dependientes</span>
          </p>

          <!-- Árbol -->
          <ul id="sortableRoot" class="list-unstyled">
            <?php
            function renderNode($node, $formId, $depCountsByOption, $depChildrenByOption){
              $qid  = (int)$node['id'];
              $tipo = tipoTexto($node['id_question_type']);
              $req  = (int)$node['is_required']===1;
              $val  = (int)$node['is_valued']===1;
              $totalHijos = 0;
              if (!empty($node['options'])) {
                foreach ($node['options'] as $op) { $totalHijos += $depCountsByOption[$op['id']] ?? 0; }
              }
              echo '<li class="q-item" data-id="'.$qid.'">';
              echo '  <div class="q-card">';
              echo '    <div class="q-head">';
              echo '      <div class="d-flex align-items-center">';
              echo '        <span class="drag-handle mr-2"><i class="fa fa-grip-vertical"></i></span>';
              echo '        <button class="btn btn-sm btn-link text-secondary px-1 collapse-toggle" title="Colapsar/expandir"><i class="fa fa-chevron-down"></i></button>';
              echo '        <h6 class="q-title mb-0" data-id="'.$qid.'" title="Doble click para editar">'.htmlspecialchars($node['question_text'],ENT_QUOTES).'</h6>';
              echo '        <div class="chips ml-2">';
              echo '          <span class="badge badge-light">'.htmlspecialchars($tipo,ENT_QUOTES).'</span>';
              if ($req) echo ' <span class="badge badge-danger">Requerida</span>';
              if ($val && in_array((int)$node['id_question_type'],[2,3],true)) echo ' <span class="badge badge-info">Valorizada</span>';
              if ($totalHijos>0) echo ' <span class="badge badge-primary js-badge-children" data-qid="'.$qid.'" data-toggle="tooltip" title="Total de dependientes de sus opciones">'.$totalHijos.' '.($totalHijos===1?'hijo':'hijos').'</span>';
              echo '        </div>';
              echo '      </div>';
              echo '      <div class="q-actions">';
              echo '        <button class="btn btn-sm btn-light-outline move-up" title="Subir"><i class="fa fa-arrow-up"></i></button>';
              echo '        <button class="btn btn-sm btn-light-outline move-down" title="Bajar"><i class="fa fa-arrow-down"></i></button>';
              echo '        <button class="btn btn-sm btn-outline-secondary move-to" title="Cambiar condición / destino"><i class="fa fa-share"></i></button>';
            //  echo '        <a class="btn btn-sm btn-outline-primary" href="duplicar_form_pregunta.php?id='.$qid.'&id_formulario='.$formId.'&mode=solo" title="Duplicar pregunta"><i class="fa fa-copy"></i></a>';
             // echo '        <a class="btn btn-sm btn-outline-primary" href="duplicar_form_pregunta.php?id='.$qid.'&id_formulario='.$formId.'&mode=rama" title="Duplicar rama"><i class="fa fa-sitemap"></i></a>';
              echo '        <button class="btn btn-sm btn-info" onclick="editarFormPregunta('.$qid.', '.$formId.')" title="Editar"><i class="fa fa-pen"></i></button>';
              echo '        <a class="btn btn-sm btn-danger" href="eliminar_form_pregunta.php?id='.$qid.'&id_formulario='.$formId.'" title="Eliminar"><i class="fa fa-trash"></i></a>';
              echo '      </div>';
              echo '    </div>';

              if (!empty($node['options'])){
                echo '<div class="mt-2">';
                foreach($node['options'] as $op){
                  $optId=(int)$op['id']; $optTx=htmlspecialchars($op['option_text'],ENT_QUOTES);
                  $cnt=$depCountsByOption[$optId] ?? 0;
                  $tooltip=''; if($cnt>0){
                    $children=$depChildrenByOption[$optId] ?? [];
                    $html=''; foreach($children as $cTxt){ $html.=htmlspecialchars($cTxt,ENT_QUOTES).'<br>'; }
                    $tooltip=' data-toggle="tooltip" data-html="true" title="'.$html.'"';
                  }
                  echo '<div class="rail" data-parent-id="'.$qid.'" data-dep="'.$optId.'">';
                  echo '  <div class="rail-title">';
                  if (!empty($op['reference_image'])) {
                    $ref=htmlspecialchars($op['reference_image'],ENT_QUOTES);
                    echo '    <button type="button" class="btn btn-link p-0 js-ref-thumb" data-src="'.$ref.'" data-caption="'.$optTx.'" title="Ver imagen de referencia">';
                    echo '      <img class="ref-thumb" src="'.$ref.'" alt="Imagen de referencia">';
                    echo '    </button>';
                  }
                  echo '    <span class="opt-chip">'.$optTx.'</span>';
                  echo '    <span class="badge badge-'.($cnt>0?'info':'light').' js-show-children" data-dep="'.$optId.'"'.$tooltip.'>'.$cnt.' '.($cnt===1?'dependiente':'dependientes').'</span>';
                  echo '    <span class="text-muted">Aparecen si eliges esta opción</span>';
                  echo '  </div>';
                  echo '  <ul class="child-sortable list-unstyled" data-parent-id="'.$qid.'" data-dep="'.$optId.'">';
                  if (!empty($node['children'])){
                    foreach($node['children'] as $child){
                      if ((int)$child['id_dependency_option'] === $optId){
                        renderNode($child,$formId,$depCountsByOption,$depChildrenByOption);
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
            foreach($tree as $n) renderNode($n, $idForm, $depCountsByOption, $depChildrenByOption);
            ?>
          </ul>

          <div class="small-help mt-3">
            • Usa <b>↑ / ↓</b> para mover dentro del mismo bloque.
            • <b>“Cambiar condición / destino”</b> para enviar una pregunta a otro bloque/condición.
            • Doble click sobre el título para edición rápida.
            • Activa el <b>arrastre</b> si te acomoda mover con DnD.
            • <b>Ctrl / Cmd + S</b> guarda la estructura.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<!-- Modales -->
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
          <small class="text-muted">“Raíz del formulario” o la <b>opción</b> de una pregunta disparadora.</small>
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

<div class="modal fade" id="editarFormPreguntaModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content" id="editarFormPreguntaModalContent"></div></div>
</div>

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
</div>

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
<!-- SortableJS global (para el modal de edición) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<script>
/* ====== Utils / toasts ====== */
function notify(type, msg){
  var id='t'+Date.now();
  var cls=(type==='error'?'bg-danger text-white': type==='info'?'bg-info text-white':'bg-success text-white');
  var html=`<div class="toast ${cls}" id="${id}" role="alert" aria-live="assertive" aria-atomic="true" data-delay="2200">
      <div class="toast-body d-flex align-items-center">
        <i class="mr-2 ${type==='error'?'fa fa-times-circle': type==='info'?'fa fa-info-circle':'fa fa-check-circle'}"></i>
        <div>${msg}</div></div></div>`;
  $('#toastContainer').append(html);
  $('#'+id).toast('show').on('hidden.bs.toast', function(){ $(this).remove(); });
}
let dirty=false;
function markDirty(){ if(!dirty){ dirty=true; $('#btnSaveStructure').prop('disabled', false); } }

/* ====== Imagen referencia ====== */
$(document).on('click','.js-ref-thumb', function(e){
  e.preventDefault();
  $('#refImageModalImg').attr('src', $(this).data('src'));
  $('#refImageCaption').text($(this).data('caption')||'Imagen de referencia');
  $('#refImageModal').modal('show');
});
$('#refImageModal').on('hidden.bs.modal', function(){ $('#refImageModalImg').attr('src',''); });

/* ====== Tooltips ====== */
$(function(){ $('[data-toggle="tooltip"]').tooltip({container:'body'}); });

/* ====== Alta opciones (form) ====== */
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
  var optWrap=document.getElementById('optionContainerSingle');
  var valWrap=document.getElementById('valuedContainerSingle');
  var rows=document.getElementById('optionRowsSingle');
  if(!sel) return;
  sel.addEventListener('change', function(){
    var v=parseInt(this.value||'0',10);
    rows.innerHTML='';
    valWrap.style.display= ([2,3].includes(v)) ? 'block' : 'none';
    if([1,2,3].includes(v)){
      optWrap.style.display='block';
      if(v===1){
        rows.innerHTML=`
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

/* ====== Editar (AJAX modal) ====== */
function editarFormPregunta(idFormQuestion, idForm){
  $.get('ajax_editar_form_question.php',{idFormQuestion, idForm}, function(html){
    // Por seguridad, removemos <script> del fragmento
    const $tmp = $('<div>').html(html);
    $tmp.find('script').remove();
    $('#editarFormPreguntaModalContent').html($tmp.html());
    $('#editarFormPreguntaModal').modal('show');
    initEditFormQuestionModal(); // ← aquí armamos DnD, previews y submit
  }).fail(()=>notify('error','Error al cargar el formulario de edición.'));
}

function initEditFormQuestionModal(){
  const $m   = $('#editarFormPreguntaModal');
  const cont = $m.find('#editOptionsContainer')[0];
  const typeSel = $m.find('#editTypeSel');

  // Previews (delegado)
  $m.off('change', '.opt-image-input').on('change', '.opt-image-input', function(){
    const img = $(this).closest('.form-group').find('.opt-thumb')[0];
    const f = this.files && this.files[0]; if(!img||!f) return;
    if(!f.type || !f.type.startsWith('image/')){ this.value=''; return; }
    const r = new FileReader(); r.onload=e=>{ img.src=e.target.result; img.style.display='inline-block'; }; r.readAsDataURL(f);
  });

  // Agregar opción
  $m.off('click', '#btnAddEditOpt').on('click', '#btnAddEditOpt', function(){
    const idx = Date.now();
    $('#editOptionsContainer').append(
      `<div class="option-block mb-3 p-2 border rounded d-flex align-items-start" data-kind="new" data-id="${idx}">
        <span class="drag-handle mr-2" style="cursor:grab; font-size:18px; line-height:36px;"><i class="fa fa-grip-vertical"></i></span>
        <div class="flex-grow-1">
          <div class="form-group mb-2">
            <label class="mb-1">Texto de la opción</label>
            <input type="text" class="form-control" name="options_new[${idx}]" required>
          </div>
          <div class="form-group mb-0">
            <label class="mb-1">Imagen (opcional)</label>
            <input type="file" class="form-control-file opt-image-input" name="image_new[${idx}]" accept="image/*">
            <img class="img-thumbnail opt-thumb mt-1" style="display:none; max-width:120px">
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-danger ml-2" onclick="$(this).closest('.option-block').remove()">Eliminar</button>
        <input type="hidden" name="sort_new[${idx}]" value="9999" class="sort-holder">
      </div>`
    );
    renumSort();
  });

  // Sortable (bloquea arrastre en las dos primeras si es Sí/No)
  let sortable;
  function buildSortable(){
    if (!cont) return;
    if (sortable) sortable.destroy();
    const isYN = parseInt(typeSel.val()||'0',10) === 1;
    sortable = Sortable.create(cont, {
      animation: 150,
      handle: '.drag-handle',
      draggable: '.option-block',
      filter: isYN ? '.option-block[data-fixed="1"]' : '',
      onEnd: renumSort
    });
    renumSort();
  }
  function renumSort(){
    let n = 1;
    $('#editOptionsContainer .option-block').each(function(){
      $(this).find('.sort-holder').val(n++);
    });
  }
  // Marcar fijas las dos primeras si tipo=1 (el HTML ya venía con data-fixed="1" cuando corresponde)
  buildSortable();
  typeSel.off('change.modal').on('change.modal', buildSortable);

  // Submit AJAX → JSON
  $m.off('submit', '#editFormQuestionForm').on('submit', '#editFormQuestionForm', function(e){
    e.preventDefault();
    renumSort();
    const fd = new FormData(this);
    $.ajax({
      url: 'ajax_editar_form_question.php',
      method: 'POST',
      data: fd,
      contentType: false,
      processData: false,
      dataType: 'json'
    }).done(function(resp){
      if (resp && resp.ok) {
        $('#editarFormPreguntaModal').modal('hide');
        notify('success', resp.message || 'Guardado.');
        setTimeout(()=>location.reload(), 600);
      } else {
        const msg = resp && resp.error ? resp.error : 'Error al guardar';
        $('<div class="alert alert-danger">').text(msg).prependTo($m.find('.modal-body'));
      }
    }).fail(function(xhr){
      const msg = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : (xhr.responseText || 'Error al guardar');
      $('<div class="alert alert-danger">').text(msg).prependTo($m.find('.modal-body'));
    });
  });
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
    var ref=$('.q-item[data-id="'+sib+'"]');
    if(ref.length){ if(pos==='before') ref.before(movingLI); else ref.after(movingLI); }
    else { listEl.append(movingLI); }
  }
  $('#moveModal').modal('hide'); movingLI=null; markDirty();
});

function buildMoveTargets(){
  var sel=$('#moveTarget').empty();
  sel.append('<option value="root">Raíz del formulario</option>');
  $('.rail').each(function(){
    var parent=$(this).data('parent-id'), dep=$(this).data('dep');
    var parentTitle=$('.q-item[data-id="'+parent+'"]').find('> .q-card .q-title').first().text().trim();
    var optText=$(this).find('.opt-chip').first().text().trim();
    sel.append('<option value="'+parent+':'+dep+'">'+escapeHtml(parentTitle)+' → opción: '+escapeHtml(optText)+'</option>');
  });
}
function buildMoveSiblings(value){
  var sel=$('#moveSibling').empty();
  var list=(value==='root')? $('#sortableRoot') : getRailByValue(value);
  if(!list || !list.length) return;
  list.children('.q-item').each(function(){
    var id=$(this).data('id'); if(movingLI && id==movingLI.data('id')) return;
    var t=$(this).find('> .q-card .q-title').first().text().trim();
    sel.append('<option value="'+id+'">'+escapeHtml(t)+'</option>');
  });
}
function getRailByValue(v){ var p=v.split(':'); return $('.child-sortable[data-parent-id="'+p[0]+'"][data-dep="'+p[1]+'"]'); }
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
function collectStructure(){
  var rows=[], order=1;
  function collectFrom($ul){
    var pid=$ul.data('parent-id')||null;
    var dep=$ul.data('dep')||null;
    $ul.children('.q-item').each(function(){
      rows.push({ id:$(this).data('id'), parent_id: pid?parseInt(pid,10):null, dep_option_id: dep?parseInt(dep,10):null, sort_order: order++ });
      $(this).find('> .q-card .rail .child-sortable').each(function(){ collectFrom($(this)); });
    });
  }
  collectFrom($('#sortableRoot'));
  return rows;
}
$('#btnSaveStructure').on('click', saveStructure);
function saveStructure(){
  const $btn=$('#btnSaveStructure').prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span>Guardando…');
  const payload=collectStructure();
  $.post('actualizar_orden_dependencias_form.php',{
    idForm: <?= (int)$idForm ?>,
    data: JSON.stringify(payload)
  }).done(msg=>{ notify('success', msg||'Estructura guardada.'); dirty=false; $('#btnSaveStructure').prop('disabled', true).html('<i class="fa fa-layer-group"></i> Guardar estructura'); })
    .fail(xhr=>{ notify('error', xhr.responseText||'Error al guardar.'); $('#btnSaveStructure').prop('disabled', false).html('<i class="fa fa-layer-group"></i> Guardar estructura'); });
}
document.addEventListener('keydown', function(e){
  if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='s'){
    e.preventDefault();
    if(!$('#btnSaveStructure').prop('disabled')) saveStructure();
  }
});

/* ====== Expandir / Colapsar ====== */
$('#btnExpandAll').on('click', function(){ $('.q-item').removeClass('collapsed'); });
$('#btnCollapseAll').on('click', function(){ $('.q-item').addClass('collapsed'); });
$(document).on('click','.collapse-toggle', function(){ $(this).closest('.q-item').toggleClass('collapsed'); });

/* ====== Resaltar hijos ====== */
$(document).on('click','.js-show-children', function(){
  var dep=$(this).data('dep'); var $list=$('.child-sortable[data-dep="'+dep+'"]');
  if($list.length){ $('html,body').animate({scrollTop:$list.offset().top-120},300); $list.addClass('highlight'); setTimeout(()=>{$list.removeClass('highlight');},1200); }
});

/* ====== Edit inline título ====== */
$(document).on('dblclick','.q-title', function(){ $(this).attr('contenteditable','true').focus(); });
$(document).on('blur keydown','.q-title[contenteditable="true"]', function(e){
  if(e.type==='keydown' && e.key!=='Enter') return;
  e.preventDefault();
  var $el=$(this); $el.removeAttr('contenteditable');
  var text=$el.text().trim(), id=$el.data('id');
  if(text===''){ notify('error','El texto no puede ser vacío.'); return; }
  $.post('ajax_update_form_question_text.php', { idForm: <?= (int)$idForm ?>, idQuestion:id, text:text })
    .done(()=>notify('success','Título actualizado.'))
    .fail(()=>notify('error','No se pudo actualizar.'));
});

/* ====== Buscador ====== */
$('#searchInput').on('input', function(){
  const q=(this.value||'').toLowerCase().trim();
  if(!q){ $('.q-item').show(); return; }
  $('.q-item').hide();
  $('.q-item').each(function(){
    const $li=$(this);
    const title=$li.find('> .q-card .q-title').first().text().toLowerCase();
    let match=title.includes(q);
    if(!match){
      $li.find('> .q-card .rail .rail-title .opt-chip').each(function(){
        if ($(this).text().toLowerCase().includes(q)) { match=true; return false; }
      });
    }
    if(match){ $li.show(); $li.parents('.q-item').show(); }
  });
});

/* ====== Simulador ====== */
$('#btnSimular').on('click', function(){
  const data=(window.SET_MODEL && window.SET_MODEL.questions) ? window.SET_MODEL.questions : [];
  const byOpt={}; data.forEach(q => (q.options||[]).forEach(o=>{byOpt[o.id]=q.id;}));
  const $c=$('#simContainer').empty(); const state={};

  function visibleQuestions(){
    return data.filter(q=>{
      if(q.dep===null) return true;
      const parentQ=byOpt[q.dep]; if(!parentQ) return false;
      const sel=state[parentQ];
      if(Array.isArray(sel)) return sel.includes(q.dep);
      return sel===q.dep;
    });
  }
  function render(){
    $c.empty();
    const vis=visibleQuestions();
    vis.forEach(q=>{
      const $row=$('<div class="border rounded p-2 mb-2"></div>');
      $row.append('<div class="font-weight-bold mb-1">'+escapeHtml(q.text)+'</div>');
      if([1,2].includes(q.type)){
        const name='q_'+q.id; const ops=q.options||[];
        ops.forEach(o=>{
          const $opt=$(`
            <div class="custom-control custom-radio">
              <input type="radio" id="sim_${o.id}" name="${name}" class="custom-control-input" value="${o.id}">
              <label class="custom-control-label" for="sim_${o.id}">${escapeHtml(o.text)}</label>
            </div>`);
          $row.append($opt);
        });
        if(state[q.id]) $row.find('input[value="'+state[q.id]+'"]').prop('checked', true);
        $row.on('change','input[type=radio]', function(){ state[q.id]=parseInt(this.value,10); render(); });
      } else if(q.type===3){
        const ops=q.options||[]; const sel=Array.isArray(state[q.id])?state[q.id]:[];
        ops.forEach(o=>{
          const checked=sel.includes(o.id)?'checked':'';
          const $opt=$(`
            <div class="custom-control custom-checkbox">
              <input type="checkbox" id="sim_${o.id}" class="custom-control-input" value="${o.id}" ${checked}>
              <label class="custom-control-label" for="sim_${o.id}">${escapeHtml(o.text)}</label>
            </div>`);
          $row.append($opt);
        });
        $row.on('change','input[type=checkbox]', function(){
          let arr=Array.isArray(state[q.id])?state[q.id]:[]; const val=parseInt(this.value,10);
          if(this.checked){ if(!arr.includes(val)) arr.push(val); } else { arr=arr.filter(x=>x!==val); }
          state[q.id]=arr; render();
        });
      } else {
        $row.append('<input type="text" class="form-control" placeholder="Respuesta…">');
      }
      $c.append($row);
    });
  }
  render(); $('#simModal').modal('show');
});
</script>
</body>
</html>

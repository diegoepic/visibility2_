<?php
// exportar_set_csv.php — Exporta un set a CSV (UTF-8 BOM, ; separador)

session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo "No autorizado."; exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$idSet = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
if ($idSet<=0){ http_response_code(400); echo "Parámetros inválidos."; exit(); }

// set
$st=$conn->prepare("SELECT id, nombre_set, description FROM question_set WHERE id=?");
$st->bind_param("i",$idSet); $st->execute();
$set=$st->get_result()->fetch_assoc(); $st->close();
if(!$set){ http_response_code(404); echo "Set no encontrado."; exit(); }

// preguntas
$st=$conn->prepare("
  SELECT id, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued
  FROM question_set_questions
  WHERE id_question_set=? ORDER BY sort_order ASC, id ASC
");
$st->bind_param("i",$idSet); $st->execute();
$qs=[]; $r=$st->get_result(); while($row=$r->fetch_assoc()) $qs[]=$row; $st->close();
if(!$qs){ header('Content-Type: text/plain; charset=UTF-8'); echo "El set no tiene preguntas."; exit(); }

// opciones por pregunta
$qids = array_map(fn($x)=>(int)$x['id'],$qs);
$in = implode(',', array_fill(0,count($qids),'?')); $types = str_repeat('i', count($qids));
$st=$conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question IN ($in) ORDER BY id_question_set_question, sort_order, id");
$refs=[]; foreach($qids as $k=>$v){ $qids[$k]=(int)$v; $refs[$k]=&$qids[$k]; }
$st->bind_param($types, ...$refs); $st->execute(); $res=$st->get_result();
$optsByQ=[]; $optTextById=[];
while($row=$res->fetch_assoc()){
  $qid=(int)$row['id_question_set_question'];
  if(!isset($optsByQ[$qid])) $optsByQ[$qid]=[];
  $optsByQ[$qid][]=$row;
  $optTextById[(int)$row['id']]=$row['option_text'];
}
$st->close();

// map de opción=>pregunta padre (para dependencia)
$st=$conn->prepare("SELECT id, id_question_set_question FROM question_set_options WHERE id_question_set_question IN ($in)");
$st->bind_param($types, ...$refs); $st->execute(); $r=$st->get_result();
$parentQOfOpt=[]; while($row=$r->fetch_assoc()) $parentQOfOpt[(int)$row['id']]=(int)$row['id_question_set_question']; $st->close();

function tipoTexto($t){
  switch((int)$t){
    case 1: return "Sí/No"; case 2: return "Selección única"; case 3: return "Selección múltiple";
    case 4: return "Texto"; case 5: return "Numérico"; case 6: return "Fecha"; case 7: return "Foto";
    default: return "Otro";
  }
}

$fnameBase = preg_replace('~[^\w\-\.\(\) ]+~u','_', $set['nombre_set'] ?: ("set_".$idSet));
$fname = "set_{$idSet}_".$fnameBase.".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF"; // BOM
$out=fopen('php://output','w'); $sep=';';

fputcsv($out, [
  'set_id','set_nombre',
  'question_id','question_sort_order','question_text','question_type','is_required','is_valued',
  'dependency_option_id','dependency_parent_question_id','dependency_parent_question_text','dependency_option_text',
  'options_count','options_detalle'
], $sep);

$byQ=[]; foreach($qs as $q){ $byQ[(int)$q['id']]=$q; }

foreach($qs as $q){
  $qid=(int)$q['id']; $ops=$optsByQ[$qid] ?? [];
  $depId = $q['id_dependency_option']; $depParentId=''; $depParentTxt=''; $depOptTxt='';
  if(!is_null($depId)&&$depId!==''){
    $depId=(int)$depId; $depParentId=$parentQOfOpt[$depId] ?? ''; $depParentTxt=$depParentId && isset($byQ[$depParentId])?$byQ[$depParentId]['question_text']:'';
    $depOptTxt=$optTextById[$depId] ?? '';
  }

  $optionsDetail = '';
  if ($ops){
    $parts = [];
    foreach($ops as $op){
      $img = $op['reference_image'] ? " (img: {$op['reference_image']})" : '';
      $parts[] = (int)$op['sort_order'] . ". [" . (int)$op['id'] . "] " . $op['option_text'] . $img;
    }
    $optionsDetail = implode(' | ', $parts);
  }

  fputcsv($out, [
    $idSet,$set['nombre_set'],
    $qid,$q['sort_order'],$q['question_text'],tipoTexto($q['id_question_type']),(int)$q['is_required'],(int)$q['is_valued'],
    $depId?:'',$depParentId?:'',$depParentTxt,$depOptTxt,
    count($ops),$optionsDetail
  ], $sep);
}
fclose($out);
exit;

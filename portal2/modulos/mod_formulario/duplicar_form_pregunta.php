<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idQ    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$idForm = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;
$mode   = isset($_GET['mode']) ? $_GET['mode'] : 'solo';

if ($idQ<=0 || $idForm<=0) die("Parámetros inválidos");

// Helpers
function getQ($conn,$idQ,$idForm){
  $st=$conn->prepare("SELECT * FROM form_questions WHERE id=? AND id_formulario=?");
  $st->bind_param("ii",$idQ,$idForm); $st->execute();
  $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?:null;
}
function getOpts($conn,$idQ){
  $st=$conn->prepare("SELECT * FROM form_question_options WHERE id_form_question=? ORDER BY sort_order ASC, id ASC");
  $st->bind_param("i",$idQ); $st->execute(); $rs=$st->get_result(); $out=[];
  while($r=$rs->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}
function childrenOf($conn,$idForm,$parentOptIds){ // hijos directos por conjunto de opciones
  if (empty($parentOptIds)) return [];
  $in = implode(',', array_fill(0, count($parentOptIds), '?')); $types=str_repeat('i', count($parentOptIds));
  $sql="SELECT * FROM form_questions WHERE id_formulario=? AND id_dependency_option IN ($in) ORDER BY sort_order ASC, id ASC";
  $st=$conn->prepare($sql);
  $types2='i'.$types; $args = array_merge([$types2, $idForm], $parentOptIds);
  $st->bind_param(...$args);
  $st->execute(); $rs=$st->get_result(); $out=[];
  while($r=$rs->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}
function nextSort($conn,$idForm){
  $st=$conn->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM form_questions WHERE id_formulario=?");
  $st->bind_param("i",$idForm); $st->execute(); $st->bind_result($n); $st->fetch(); $st->close(); return (int)$n;
}

$conn->begin_transaction();
try {
  $q = getQ($conn,$idQ,$idForm);
  if (!$q) throw new Exception("Pregunta no encontrada");

  // Duplica una pregunta y sus opciones. Devuelve [newQId, mapOldOpt=>NewOpt]
  $dupOne = function($conn,$origQ,$newDepOptId=null) {
    $sort = nextSort($conn, (int)$origQ['id_formulario']);
    $st=$conn->prepare("INSERT INTO form_questions (id_formulario, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued, id_question_set_question)
                        VALUES (?,?,?,?,?,?,?, NULL)");
    $st->bind_param("isiiiii",
      $origQ['id_formulario'],
      $origQ['question_text'],
      $origQ['id_question_type'],
      $sort,
      $origQ['is_required'],
      $newDepOptId, // puede ser null
      $origQ['is_valued']
    );
    $st->execute(); $newQId=$st->insert_id; $st->close();

    // Copiar opciones
    $opts = getOpts($conn, (int)$origQ['id']);
    $map = [];
    foreach ($opts as $op){
      $st=$conn->prepare("INSERT INTO form_question_options (id_form_question, option_text, sort_order, reference_image, id_question_set_option)
                          VALUES (?,?,?,?, NULL)");
      $st->bind_param("isis", $newQId, $op['option_text'], $op['sort_order'], $op['reference_image']);
      $st->execute(); $newOpId=$st->insert_id; $st->close();
      $map[(int)$op['id']] = (int)$newOpId;
    }
    return [$newQId, $map];
  };

  if ($mode === 'solo') {
    // misma dependencia
    [$newQ, $map] = $dupOne($conn, $q, $q['id_dependency_option'] ? (int)$q['id_dependency_option'] : null);
    $conn->commit();
    header("Location: gestionar_preguntas.php?id_formulario=".$idForm);
    exit();
  }

  // Duplicar rama completa
  // BFS: por niveles desde q, remapeando dependencias con el mapa de opciones
  // Mapa por pregunta original -> nueva pregunta
  $Qmap = [];
  // Mapa global de opciones
  $OptMap = [];

  // 1) duplicar raíz (con misma dependencia que original)
  [$newRoot, $rootOptMap] = $dupOne($conn, $q, $q['id_dependency_option'] ? (int)$q['id_dependency_option'] : null);
  $Qmap[(int)$q['id']] = $newRoot;
  $OptMap = array_merge($OptMap, $rootOptMap);

  // 2) cola por niveles: cada elemento = idPreguntaOriginal
  $queue = [(int)$q['id']];
  while(!empty($queue)){
    $origId = array_shift($queue);
    $origQ  = getQ($conn,$origId,$idForm);
    $origOps= getOpts($conn,$origId);
    $origOptIds = array_map(fn($o)=>(int)$o['id'], $origOps);

    // hijos directos del original
    $children = childrenOf($conn,$idForm,$origOptIds);
    foreach ($children as $child){
      $childDepOpt = $child['id_dependency_option'] ? (int)$child['id_dependency_option'] : null;
      $newDepOpt   = $childDepOpt ? ($OptMap[$childDepOpt] ?? null) : null; // mapear opción
      [$newChildId, $childOptMap] = $dupOne($conn, $child, $newDepOpt);
      $Qmap[(int)$child['id']] = $newChildId;
      $OptMap = $OptMap + $childOptMap; // merge preservando anteriores
      $queue[] = (int)$child['id'];
    }
  }

  $conn->commit();
  header("Location: gestionar_preguntas.php?id_formulario=".$idForm);
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  die("Error al duplicar: ".htmlspecialchars($e->getMessage(),ENT_QUOTES));
}

<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$idQ   = isset($_GET['id'])    ? (int)$_GET['id']    : 0; // raíz a clonar
$idSet = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;

if ($idQ<=0 || $idSet<=0){ $_SESSION['error_sets']="Parámetros inválidos."; header("Location: gestionar_sets.php?idSet=".$idSet); exit(); }

// Helpers
function fetchQuestion(mysqli $conn, int $idQ, int $idSet): ?array {
  $st=$conn->prepare("SELECT * FROM question_set_questions WHERE id=? AND id_question_set=?");
  $st->bind_param("ii",$idQ,$idSet);
  $st->execute();
  $r=$st->get_result()->fetch_assoc();
  $st->close();
  return $r ?: null;
}
function fetchOptionsByQuestions(mysqli $conn, array $qIds): array {
  if (empty($qIds)) return [];
  $in = implode(',', array_fill(0,count($qIds),'?'));
  $types = str_repeat('i', count($qIds));
  $st = $conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question IN ($in) ORDER BY sort_order ASC, id ASC");
  $refs=[];
  foreach($qIds as $k=>$v){ $qIds[$k]=(int)$v; $refs[$k]=&$qIds[$k]; }
  $st->bind_param($types, ...$refs);
  $st->execute();
  $res = $st->get_result();
  $map = [];
  while($row=$res->fetch_assoc()){
    $qid = (int)$row['id_question_set_question'];
    if (!isset($map[$qid])) $map[$qid]=[];
    $map[$qid][] = $row;
  }
  $st->close();
  return $map;
}
function fetchChildrenByOptions(mysqli $conn, int $idSet, array $optIds): array {
  if (empty($optIds)) return [];
  $in=implode(',', array_fill(0,count($optIds),'?'));
  $types=str_repeat('i',count($optIds));
  $sql="SELECT id, id_dependency_option FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st=$conn->prepare($sql);
  $st->bind_param('i'.$types, $idSet, ...$optIds);
  $st->execute();
  $res=$st->get_result();
  $rows=[];
  while($r=$res->fetch_assoc()){ $rows[]=['id'=>(int)$r['id'],'dep'=>(int)$r['id_dependency_option']]; }
  $st->close();
  return $rows;
}
function subtreeIds(mysqli $conn, int $idSet, int $rootQ): array {
  $all=[];
  $queue=[(int)$rootQ];
  while(!empty($queue)){
    $cur=array_shift($queue);
    // opciones del actual
    $st=$conn->prepare("SELECT id FROM question_set_options WHERE id_question_set_question=?");
    $st->bind_param("i",$cur);
    $st->execute();
    $res=$st->get_result();
    $ops=[]; while($row=$res->fetch_assoc()) $ops[]=(int)$row['id'];
    $st->close();

    if (!empty($ops)){
      $ch = fetchChildrenByOptions($GLOBALS['conn'], $idSet, $ops);
      foreach($ch as $c){
        $cid=$c['id'];
        if(!in_array($cid,$all,true)){ $all[]=$cid; $queue[]=$cid; }
      }
    }
  }
  return $all;
}

// 1) Validaciones y cargas
$root = fetchQuestion($conn, $idQ, $idSet);
if (!$root){ $_SESSION['error_sets']="Pregunta raíz no encontrada."; header("Location: gestionar_sets.php?idSet=".$idSet); exit(); }

// Descendientes (IDs)
$desc = subtreeIds($conn, $idSet, $idQ);
$allQ = array_merge([$idQ], $desc);

// Detalles de preguntas a clonar
$in = implode(',', array_fill(0,count($allQ),'?'));
$types = str_repeat('i', count($allQ));
$st = $conn->prepare("SELECT * FROM question_set_questions WHERE id IN ($in) AND id_question_set=?");
$params = array_merge($allQ, [$idSet]);
$refs = [];
foreach ($params as $k=>$v){ $params[$k]=(int)$v; $refs[$k]=&$params[$k]; }
$st->bind_param($types.'i', ...$refs);
$st->execute();
$res = $st->get_result();
$qRows = [];
while($row=$res->fetch_assoc()) $qRows[(int)$row['id']]=$row;
$st->close();

// Opciones por pregunta
$optsByQ = fetchOptionsByQuestions($conn, $allQ);

// Mapa inverso: opción vieja -> hijos (dentro del subárbol)
$childrenByOldOpt = [];
if (!empty($optsByQ)){
  $allOldOptIds = [];
  foreach($optsByQ as $qid=>$ops) foreach($ops as $op) $allOldOptIds[]=(int)$op['id'];
  if (!empty($allOldOptIds)){
    $ch = fetchChildrenByOptions($conn, $idSet, $allOldOptIds);
    foreach($ch as $r){
      $dep = (int)$r['dep'];
      if (!isset($childrenByOldOpt[$dep])) $childrenByOldOpt[$dep]=[];
      // Solo nos interesan hijos que estén dentro del subárbol:
      if (isset($qRows[(int)$r['id']])) $childrenByOldOpt[$dep][] = (int)$r['id'];
    }
  }
}

// 2) Clonado (append al final del set)
$conn->begin_transaction();
try {
  // sort base al final
  $stMax = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM question_set_questions WHERE id_question_set=?");
  $stMax->bind_param("i",$idSet);
  $stMax->execute();
  $stMax->bind_result($maxSort);
  $stMax->fetch();
  $stMax->close();

  $newQIdByOld = [];
  $newOptIdByOld = [];

  // BFS: clonar padre -> luego hijos que dependan de sus opciones
  $queue = [$idQ];
  $visited = [];

  while(!empty($queue)){
    $curOld = array_shift($queue);
    if (isset($visited[$curOld])) continue;
    $visited[$curOld]=true;

    $row = $qRows[$curOld];

    // dependencia del "nuevo"
    if ($curOld == $idQ){
      // raíz: conserva la dependencia original (puede ser NULL o una opción externa al subárbol)
      $newDep = is_null($row['id_dependency_option']) ? NULL : (int)$row['id_dependency_option'];
      // marcar texto como copia para el root
      $newText = $row['question_text'].' (Copia)';
    } else {
      // hijos: remapear a la NUEVA opción del padre clonado
      $oldDep = (int)$row['id_dependency_option'];
      if (!isset($newOptIdByOld[$oldDep])){
        throw new Exception("No se pudo remapear dependencia interna (opción $oldDep).");
      }
      $newDep = (int)$newOptIdByOld[$oldDep];
      $newText = $row['question_text'];
    }

    // insertar pregunta clonada (al final, orden incremental)
    $maxSort++;
    $stIns = $conn->prepare("INSERT INTO question_set_questions (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued) VALUES (?,?,?,?,?,?,?)");
    $stIns->bind_param("isiiiii", $idSet, $newText, $row['id_question_type'], $maxSort, $row['is_required'], $newDep, $row['is_valued']);
    $stIns->execute();
    $newId = $stIns->insert_id;
    $stIns->close();
    $newQIdByOld[$curOld] = $newId;

    // clonar opciones del curOld y mapear ids
    $ops = $optsByQ[$curOld] ?? [];
    if (!empty($ops)){
      $stOpt = $conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
      foreach($ops as $op){
        $opTxt = $op['option_text'];
        $ref   = $op['reference_image'];
        $ord   = (int)$op['sort_order'];
        $stOpt->bind_param("issi", $newId, $opTxt, $ref, $ord);
        $stOpt->execute();
        $newOptId = $stOpt->insert_id;
        $newOptIdByOld[(int)$op['id']] = $newOptId;
      }
      $stOpt->close();
    }

    // Encolar hijos (dentro del subárbol) que dependan de alguna de MIS opciones antiguas
    foreach(($optsByQ[$curOld] ?? []) as $op){
      $oldOptId = (int)$op['id'];
      if (!empty($childrenByOldOpt[$oldOptId])){
        foreach($childrenByOldOpt[$oldOptId] as $childOldQ){
          if (!isset($visited[$childOldQ])) $queue[] = $childOldQ;
        }
      }
    }
  }

  $conn->commit();
  $_SESSION['success_sets'] = "Rama clonada. La copia quedó al final del set.";
} catch(Exception $e){
  $conn->rollback();
  $_SESSION['error_sets'] = "Error al clonar rama: ".$e->getMessage();
}

header("Location: gestionar_sets.php?idSet=".$idSet);
exit;

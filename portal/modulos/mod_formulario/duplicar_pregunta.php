<?php
// duplicar_pregunta.php — Duplicar pregunta o rama completa según ?mode=solo|rama

session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/mod_formulario/sort_order_helpers.php';

$idQ   = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
$idSet = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
$mode  = isset($_GET['mode'])  ? $_GET['mode']       : 'solo'; // 'solo' | 'rama'

if ($idQ<=0 || $idSet<=0){ $_SESSION['error_sets']="Parámetros inválidos."; header("Location: gestionar_sets.php?idSet=".$idSet); exit(); }

// Helpers
function fetchQ(mysqli $conn, int $idQ, int $idSet): ?array {
  $st=$conn->prepare("SELECT * FROM question_set_questions WHERE id=? AND id_question_set=?");
  $st->bind_param("ii",$idQ,$idSet); $st->execute();
  $r=$st->get_result()->fetch_assoc(); $st->close(); return $r?:null;
}
function fetchOpts(mysqli $conn, int $qid): array {
  $st=$conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question=? ORDER BY sort_order ASC, id ASC");
  $st->bind_param("i",$qid); $st->execute();
  $rows=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $st->close(); return $rows;
}
function childrenByOptions(mysqli $conn, int $idSet, array $optIds): array {
  if (empty($optIds)) return [];
  $in=implode(',', array_fill(0,count($optIds),'?')); $types=str_repeat('i',count($optIds));
  $sql="SELECT id, id_dependency_option FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st=$conn->prepare($sql); $st->bind_param('i'.$types, $idSet, ...$optIds); $st->execute();
  $rows=[]; $res=$st->get_result();
  while($r=$res->fetch_assoc()){ $rows[]=['id'=>(int)$r['id'],'dep'=>(int)$r['id_dependency_option']]; }
  $st->close(); return $rows;
}
function subtreeIds(mysqli $conn, int $idSet, int $rootQ): array {
  $all=[]; $q=[(int)$rootQ];
  while(!empty($q)){
    $cur=array_shift($q);
    $ops=array_column(fetchOpts($conn,$cur),'id');
    if ($ops){
      $ch=childrenByOptions($conn,$idSet,$ops);
      foreach($ch as $c){ if(!in_array($c['id'],$all,true)){ $all[]=$c['id']; $q[]=$c['id']; } }
    }
  }
  return $all; // ids sin incluir root
}

$q = fetchQ($conn,$idQ,$idSet);
if(!$q){ $_SESSION['error_sets']="Pregunta no encontrada."; header("Location: gestionar_sets.php?idSet=".$idSet); exit(); }

if ($mode === 'solo') {
  // Duplicar SOLO la pregunta (y sus opciones) justo después
  $opts = fetchOpts($conn,$idQ);

  $conn->begin_transaction();
  try{
    normalizar_sort_order_set($conn, $idSet);
    $q = fetchQ($conn, $idQ, $idSet);
    if (!$q) { throw new Exception("Pregunta no encontrada tras normalizar."); }
    $desc = subtreeIds($conn, $idSet, $idQ);
    $subtree = array_merge([$idQ], $desc);
    $in = implode(',', array_fill(0, count($subtree), '?'));
    $types = str_repeat('i', count($subtree));
    $params = array_merge($subtree, [$idSet]);
    $refs = [];
    foreach ($params as $k => $v) { $params[$k] = (int)$v; $refs[$k] = &$params[$k]; }
    $st=$conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM question_set_questions WHERE id IN ($in) AND id_question_set=?");
    $st->bind_param($types.'i', ...$refs);
    $st->execute();
    $st->bind_result($maxSort);
    $st->fetch();
    $st->close();

    // Desplazar sort_order siguientes
    $st=$conn->prepare("UPDATE question_set_questions SET sort_order=sort_order+1 WHERE id_question_set=? AND sort_order>?");
    $st->bind_param("ii",$idSet,$maxSort); $st->execute(); $st->close();

    $newSort=(int)$maxSort+1;
    $txt = $q['question_text']." (Copia)";
    $dep = is_null($q['id_dependency_option']) ? NULL : (int)$q['id_dependency_option'];

    $st=$conn->prepare("INSERT INTO question_set_questions (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued) VALUES (?,?,?,?,?,?,?)");
    $st->bind_param("isiiiii",$idSet,$txt,$q['id_question_type'],$newSort,$q['is_required'],$dep,$q['is_valued']);
    $st->execute(); $newQ=$st->insert_id; $st->close();

    if ($opts){
      $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
      foreach($opts as $op){
        $st->bind_param("issi",$newQ,$op['option_text'],$op['reference_image'],$op['sort_order']);
        $st->execute();
      }
      $st->close();
    }

    normalizar_sort_order_set($conn, $idSet);
    $conn->commit();
    $_SESSION['success_sets']="Pregunta duplicada.";
  } catch(Exception $e){
    $conn->rollback();
    $_SESSION['error_sets']="Error al duplicar: ".$e->getMessage();
  }
  header("Location: gestionar_sets.php?idSet=".$idSet);
  exit();
}

// === mode === 'rama' : duplicar rama completa al final del set ===
$desc = subtreeIds($conn,$idSet,$idQ);
$allQ = array_merge([$idQ], $desc);

// Cargar detalles
$in = implode(',', array_fill(0,count($allQ),'?'));
$types = str_repeat('i', count($allQ));
$st = $conn->prepare("SELECT * FROM question_set_questions WHERE id IN ($in) AND id_question_set=?");
$params = array_merge($allQ, [$idSet]); $refs=[];
foreach($params as $k=>$v){ $params[$k]=(int)$v; $refs[$k]=&$params[$k]; }
$st->bind_param($types.'i', ...$refs); $st->execute();
$qRows=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $qRows[(int)$row['id']]=$row; $st->close();

// Opciones por pregunta
$optsByQ = [];
if ($allQ){
  $st = $conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question IN ($in) ORDER BY sort_order ASC, id ASC");
  $st->bind_param($types, ...array_values($allQ)); $st->execute();
  $res = $st->get_result();
  while($row=$res->fetch_assoc()){
    $qid=(int)$row['id_question_set_question'];
    if(!isset($optsByQ[$qid])) $optsByQ[$qid]=[];
    $optsByQ[$qid][]=$row;
  }
  $st->close();
}

// Mapa: vieja opción -> hijos (solo del subárbol)
$childrenByOldOpt=[];
if($optsByQ){
  $allOptIds=[]; foreach($optsByQ as $qid=>$ops){ foreach($ops as $op){ $allOptIds[]=(int)$op['id']; } }
  if($allOptIds){
    $ch = childrenByOptions($conn,$idSet,$allOptIds);
    foreach($ch as $r){
      if(isset($qRows[(int)$r['id']])){
        $dep=(int)$r['dep'];
        if(!isset($childrenByOldOpt[$dep])) $childrenByOldOpt[$dep]=[];
        $childrenByOldOpt[$dep][]=(int)$r['id'];
      }
    }
  }
}

$conn->begin_transaction();
try{
  // sort base al final
  $st=$conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM question_set_questions WHERE id_question_set=?");
  $st->bind_param("i",$idSet); $st->execute(); $st->bind_result($max); $st->fetch(); $st->close();
  $sort=(int)$max;

  $newQIdByOld=[]; $newOptIdByOld=[];

  // BFS desde la raíz
  $queue = [$idQ]; $seen=[];
  while($queue){
    $curOld = array_shift($queue);
    if(isset($seen[$curOld])) continue; $seen[$curOld]=1;

    $row = $qRows[$curOld];

    // dependencia de la NUEVA pregunta
    if ($curOld===$idQ){
      // raíz: mantiene dependencia original (puede ser NULL o externa al subárbol)
      $newDep = is_null($row['id_dependency_option']) ? NULL : (int)$row['id_dependency_option'];
      $newText = $row['question_text']." (Copia)";
    } else {
      // hijos: remapear a opción NUEVA del padre
      $oldDep = (int)$row['id_dependency_option'];
      if (!isset($newOptIdByOld[$oldDep])) throw new Exception("No se pudo remapear la dependencia interna.");
      $newDep = (int)$newOptIdByOld[$oldDep];
      $newText = $row['question_text'];
    }


    $sort++;
    $st=$conn->prepare("INSERT INTO question_set_questions (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued) VALUES (?,?,?,?,?,?,?)");
    $st->bind_param("isiiiii",$idSet,$newText,$row['id_question_type'],$sort,$row['is_required'],$newDep,$row['is_valued']);
    $st->execute(); $newId=$st->insert_id; $st->close();
    $newQIdByOld[$curOld]=$newId;

    // clonar opciones y mapear
    $ops = $optsByQ[$curOld] ?? [];
    if ($ops){
      $st=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
      foreach($ops as $op){
        $st->bind_param("issi",$newId,$op['option_text'],$op['reference_image'],$op['sort_order']);
        $st->execute();
        $newOptIdByOld[(int)$op['id']] = $st->insert_id;
      }
      $st->close();
    }

    // encolar hijos del subárbol que dependían de mis opciones viejas
    foreach(($optsByQ[$curOld] ?? []) as $op){
      $oldOptId=(int)$op['id'];
      if (!empty($childrenByOldOpt[$oldOptId])){
        foreach($childrenByOldOpt[$oldOptId] as $childOldQ){
          if(!isset($seen[$childOldQ])) $queue[]=$childOldQ;
        }
      }
    }
  }

  $conn->commit();
  $_SESSION['success_sets']="Rama duplicada (la copia quedó al final del set).";
} catch(Exception $e){
  $conn->rollback();
  $_SESSION['error_sets']="Error al duplicar rama: ".$e->getMessage();
}

header("Location: gestionar_sets.php?idSet=".$idSet);
exit();
<?php
// clonar_set.php — Clona un set completo, remapeando dependencias a nuevas opciones

session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$idSet = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
if ($idSet<=0){ $_SESSION['error_sets']="Parámetros inválidos."; header("Location: gestionar_sets.php"); exit(); }

// Cargar set
$st=$conn->prepare("SELECT * FROM question_set WHERE id=?");
$st->bind_param("i",$idSet); $st->execute();
$set=$st->get_result()->fetch_assoc(); $st->close();
if(!$set){ $_SESSION['error_sets']="Set no encontrado."; header("Location: gestionar_sets.php"); exit(); }

// Preguntas
$st=$conn->prepare("SELECT * FROM question_set_questions WHERE id_question_set=? ORDER BY sort_order ASC, id ASC");
$st->bind_param("i",$idSet); $st->execute();
$qs=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $qs[]=$row; $st->close();

$conn->begin_transaction();
try{
  // Nuevo set
  $name = ($set['nombre_set'] ?: 'Set')." (Copia)";
  $desc = $set['description'] ?: '';
  $st=$conn->prepare("INSERT INTO question_set (nombre_set, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
  $st->bind_param("ss",$name,$desc); $st->execute(); $newSetId=$st->insert_id; $st->close();

  if ($qs){
    // Insert preguntas sin dependencia (temporalmente)
    $newQByOld=[]; $oldQsById=[];
    foreach($qs as $q){
      $st=$conn->prepare("INSERT INTO question_set_questions (id_question_set, question_text, id_question_type, sort_order, is_required, id_dependency_option, is_valued)
                          VALUES (?,?,?,?,?,?,NULL)");
      $st->bind_param("isiiii",$newSetId,$q['question_text'],$q['id_question_type'],$q['sort_order'],$q['is_required'],$q['is_valued']);
      $st->execute(); $newId=$st->insert_id; $st->close();
      $newQByOld[(int)$q['id']]=$newId; $oldQsById[(int)$q['id']]=$q;
    }

    // Clonar opciones de cada pregunta y mapear
    $newOptByOld=[];
    foreach($qs as $q){
      $st=$conn->prepare("SELECT * FROM question_set_options WHERE id_question_set_question=? ORDER BY sort_order ASC, id ASC");
      $st->bind_param("i",$q['id']); $st->execute();
      $res=$st->get_result();
      if ($res->num_rows){
        $stIns=$conn->prepare("INSERT INTO question_set_options (id_question_set_question, option_text, reference_image, sort_order) VALUES (?,?,?,?)");
        while($op=$res->fetch_assoc()){
          $newQ=$newQByOld[(int)$q['id']];
          $stIns->bind_param("issi",$newQ,$op['option_text'],$op['reference_image'],$op['sort_order']);
          $stIns->execute();
          $newOptByOld[(int)$op['id']] = $stIns->insert_id;
        }
        $stIns->close();
      }
      $st->close();
    }

    // Remapear dependencias
    $stUp=$conn->prepare("UPDATE question_set_questions SET id_dependency_option=? WHERE id=? AND id_question_set=?");
    foreach($qs as $q){
      $oldDep = $q['id_dependency_option'];
      $newQ   = $newQByOld[(int)$q['id']];
      if (is_null($oldDep)){
        $dep = NULL;
        $stUp->bind_param("iii",$dep,$newQ,$newSetId); // pass null safely
      } else {
        $newDep = $newOptByOld[(int)$oldDep] ?? NULL;
        $stUp->bind_param("iii",$newDep,$newQ,$newSetId);
      }
      $stUp->execute();
    }
    $stUp->close();
  }

  $conn->commit();
  $_SESSION['success_sets']="Set clonado (ID: $newSetId).";
  header("Location: gestionar_sets.php?idSet=".$newSetId);
  exit();
} catch(Exception $e){
  $conn->rollback();
  $_SESSION['error_sets']="Error al clonar set: ".$e->getMessage();
  header("Location: gestionar_sets.php?idSet=".$idSet);
  exit();
}

<?php
session_start();
require 'con_.php';
header('Content-Type: application/json');
if(!isset($_SESSION['usuario_id'])){ http_response_code(403); echo json_encode(['error'=>'no login']); exit; }
$uid = $_SESSION['usuario_id'];
$idFQ       = intval($_POST['idFQ'] ?? 0);
$valor      = isset($_POST['valor']) ? intval($_POST['valor']) : null;
$obs        = trim($_POST['observacion'] ?? '');
$motivoSel  = trim($_POST['motivoSelect'] ?? '');
$motivoNo   = trim($_POST['motivoNoImplementado'] ?? '');
// valida existencia de formularioQuestion y permisos
// … idéntico a procesar_gestion.php …
if(!$idFQ){ http_response_code(400); echo json_encode(['error'=>'falta idFQ']); exit; }
// Detectamos si “implementa” viene o no, por la presencia de valor
if($valor!==null){
  // actualizar valor y observación
  $sql = "UPDATE formularioQuestion 
           SET valor=?, observacion=?, latGestion=NULL, lngGestion=NULL 
         WHERE id=?";
  $stmt= $conn->prepare($sql);
  $stmt->bind_param("isi",$valor,$obs,$idFQ);
} else {
  // actualizar solo observación de no implementación
  $concat = $motivoSel . ($motivoNo? " - $motivoNo": '');
  $sql = "UPDATE formularioQuestion
           SET observacion=CONCAT(COALESCE(observacion,''),' ',?)
         WHERE id=?";
  $stmt= $conn->prepare($sql);
  $stmt->bind_param("si",$concat,$idFQ);
}
if(!$stmt->execute()){ http_response_code(500); echo json_encode(['error'=>$stmt->error]); exit; }
echo json_encode(['status'=>'ok']);

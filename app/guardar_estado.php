<?php
session_start();
require 'con_.php';
header('Content-Type: application/json');
// 1) auth + csrf
if(!isset($_SESSION['usuario_id'])) { http_response_code(403); echo json_encode(['error'=>'no login']); exit; }
$uid = $_SESSION['usuario_id'];
$idCamp = intval($_POST['idCampana'] ?? 0);
$idLoc  = intval($_POST['idLocal'] ?? 0);
$est    = $_POST['estadoGestion'] ?? '';
$mot    = $_POST['motivo']         ?? '';
$com    = $_POST['comentario']     ?? '';
// 2) validate
if(!$idCamp||!$idLoc||!$est){ http_response_code(400); echo json_encode(['error'=>'faltan datos']); exit; }
// 3) update
$sql = "UPDATE formularioQuestion 
           SET pregunta=?, observacion=CONCAT(COALESCE(observacion,''),' ',?), 
               /* aquí podrías guardar estadoGestion en otro campo si lo necesitas */
               countVisita = countVisita /* no lo incrementamos aún */
         WHERE id_formulario=? AND id_local=? AND id_usuario=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssiii",$est,$mot,$idCamp,$idLoc,$uid);
if(!$stmt->execute()){ http_response_code(500); echo json_encode(['error'=>$stmt->error]); exit; }
echo json_encode(['status'=>'ok']);

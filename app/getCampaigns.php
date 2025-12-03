<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_id'])) {
  echo json_encode(['error'=>'Sesión expirada']); exit;
}
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';

$u = intval($_SESSION['usuario_id']);
$e = intval($_SESSION['empresa_id']);
$l = intval($_POST['idLocal'] ?? 0);
$m = ($_POST['modo']==='reag') ? 'reag' : 'prog';

if ($l<=0) {
  echo json_encode(['error'=>'Local inválido']); exit;
}

if ($m==='reag') {
  // solo “en proceso” + “sin_material”
  $sql = "
    SELECT DISTINCT f.id AS idCampana, f.nombre AS nombreCampana
    FROM formularioQuestion fq
    JOIN formulario f ON f.id=fq.id_formulario
    WHERE fq.id_usuario=? 
      AND fq.id_local=? 
      AND f.id_empresa=? 
      AND fq.pregunta='en proceso'
      AND LOWER(fq.observacion) LIKE '%sin_material%'
      AND f.tipo IN(3,1) AND f.estado=1
    ORDER BY f.fechaInicio DESC
  ";
} else {
  // solo las pendientes por contarVisita=0
  $sql = "
    SELECT DISTINCT f.id AS idCampana, f.nombre AS nombreCampana
    FROM formularioQuestion fq
    JOIN formulario f ON f.id=fq.id_formulario
    WHERE fq.id_usuario=? 
      AND fq.id_local=? 
      AND f.id_empresa=? 
      AND fq.countVisita=0
      AND f.tipo IN(3,1) AND f.estado=1
    ORDER BY f.fechaInicio DESC
  ";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $u,$l,$e);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while($r = $res->fetch_assoc()){
  $out[] = [
    'idCampana'     => (int)$r['idCampana'],
    'nombreCampana' => $r['nombreCampana']
  ];
}

echo json_encode($out);

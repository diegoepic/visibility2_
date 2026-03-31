<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
// Validar permisos idénticos a los del JS:
if ($_SESSION['perfil_nombre']!="editor"
    || $_SESSION['empresa_id']!=103
    || $_SESSION['division_id']!=1
    || !isset($_POST['id'])
) {
  echo json_encode(['ok'=>false,'error'=>'Permisos insuficientes']);
  exit;
}
$id = intval($_POST['id']);
if (!isset($_FILES['new_ref']) || $_FILES['new_ref']['error']) {
  echo json_encode(['ok'=>false,'error'=>'Archivo inválido']);
  exit;
}
// Validar tipo MIME si quieres...
$ext = pathinfo($_FILES['new_ref']['name'], PATHINFO_EXTENSION);
$destDir = $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/uploads/reference_images/';
if (!is_dir($destDir)) mkdir($destDir,0755,true);
$newName = 'ref_'.$id.'_'.time().'.'.$ext;
$dest = $destDir.$newName;
if (move_uploaded_file($_FILES['new_ref']['tmp_name'], $dest)) {
  // Guardar en DB (ruta relativa)
  $relPath = '/visibility2/portal/uploads/reference_images/'.$newName;
  $stmt = $conn->prepare("UPDATE formulario SET reference_image = ? WHERE id = ?");
  $stmt->bind_param("si", $relPath, $id);
  $stmt->execute(); $stmt->close();
  echo json_encode(['ok'=>true,'url'=>$relPath]);
} else {
  echo json_encode(['ok'=>false,'error'=>'No se pudo mover el archivo']);
}
?>

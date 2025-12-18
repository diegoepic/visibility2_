<?php
// procesar_pregunta_fotoIW.php
header('Content-Type: application/json; charset=utf-8');
ob_start(); // capturar cualquier warning/notice que rompa el JSON
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* =========================
   1) Seguridad básica
   ========================= */
if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
  http_response_code(401);
  echo json_encode(['status'=>'error','message'=>'Sesión no iniciada']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['status'=>'error','message'=>'Método inválido']); exit;
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
  if (function_exists('deny_csrf_json')) { deny_csrf_json(); }
  http_response_code(419);
  echo json_encode(['status'=>'error','message'=>'CSRF inválido']); exit;
}

/* =========================
   2) Inputs
   ========================= */
$qid       = isset($_POST['id_form_question']) ? (int)$_POST['id_form_question'] : 0;
$visita_id = isset($_POST['visita_id'])        ? (int)$_POST['visita_id']        : 0;
$capture   = isset($_POST['capture_source'])    ? (string)$_POST['capture_source'] : 'unknown';

if ($qid <= 0 || $visita_id <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'Parámetros inválidos']); exit;
}
if (!isset($_FILES['fotoPregunta']) || $_FILES['fotoPregunta']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'No se recibió la foto']); exit;
}

$tmp  = $_FILES['fotoPregunta']['tmp_name'];
$size = (int)$_FILES['fotoPregunta']['size'];
$mime = @mime_content_type($tmp) ?: 'application/octet-stream';
if (strpos($mime, 'image/') !== 0) {
  http_response_code(415);
  echo json_encode(['status'=>'error','message'=>'El archivo no es una imagen']); exit;
}
if ($size > 5 * 1024 * 1024) {
  http_response_code(413);
  echo json_encode(['status'=>'error','message'=>'La imagen excede 5 MB']); exit;
}

/* =========================
   3) DB y permisos
   ========================= */
require_once __DIR__ . '/con_.php';

define('IW_UPLOAD_DIR', __DIR__ . '/uploads/fotos_IW');             //  ruta física
define('IW_UPLOAD_URL', '/visibility2/app/uploads/fotos_IW');       // ruta públca


$usuario   = (int)$_SESSION['usuario_id'];
$empresaId = (int)$_SESSION['empresa_id'];

$sqlQ = "
  SELECT fq.id_formulario, f.id_empresa, f.tipo
  FROM form_questions fq
  JOIN formulario f ON f.id = fq.id_formulario
  WHERE fq.id = ?
  LIMIT 1
";
$stmt = $conn->prepare($sqlQ);
$stmt->bind_param("i", $qid);
$stmt->execute();
$metaQ = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$metaQ) {
  http_response_code(404);
  echo json_encode(['status'=>'error','message'=>'Pregunta no encontrada']); exit;
}
if ((int)$metaQ['tipo'] !== 2 || (int)$metaQ['id_empresa'] !== $empresaId) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Sin permiso para esta pregunta']); exit;
}
$idCampana = (int)$metaQ['id_formulario'];

// Aprovechamos para conocer id_local de la visita (si aplica; si no, quedará 0)
$idLocalVisita = 0;
$sqlV = "SELECT id_local FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? LIMIT 1";
$stmt = $conn->prepare($sqlV);
$stmt->bind_param("iii", $visita_id, $usuario, $idCampana);
$stmt->execute();
$stmt->bind_result($idLocalVisita);
$okV = $stmt->fetch();
$stmt->close();
if (!$okV) {
  http_response_code(403);
  echo json_encode(['status'=>'error','message'=>'Visita no válida para esta campaña']); exit;
}
$idLocalVisita = (int)$idLocalVisita;

/* =========================
   4) Utilidades imagen
   ========================= */
function convertirAWebP($src, $dst, $quality=80) {
  if (class_exists('Imagick')) {
    try {
      $img = new Imagick($src);
      $img->setImageFormat('webp');
      $img->setImageCompressionQuality($quality);
      $img->writeImages($dst, true);
      return true;
    } catch (Throwable $e) { /* fallback a GD */ }
  }
  $info = @getimagesize($src);
  if (!$info) return false;
  switch ($info['mime']) {
    case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
    case 'image/png':
      $im = @imagecreatefrompng($src);
      if ($im) {
        @imagepalettetotruecolor($im);
        @imagealphablending($im, true);
        @imagesavealpha($im, true);
      }
      break;
    case 'image/webp': $im = @imagecreatefromwebp($src); break;
    default: return false;
  }
  if (!$im) return false;
  $ok = function_exists('imagewebp') ? @imagewebp($im, $dst, $quality)
                                     : @imagejpeg($im, $dst, $quality);
  @imagedestroy($im);
  return (bool)$ok;
}
function gpsToFloat($ref, $coord) {
  if (!is_array($coord) || count($coord) < 3) return null;
  $deg = $coord[0][0]/max($coord[0][1],1);
  $min = $coord[1][0]/max($coord[1][1],1);
  $sec = $coord[2][0]/max($coord[2][1],1);
  $sign = ($ref==='S' || $ref==='W') ? -1 : 1;
  return $sign * ($deg + $min/60 + $sec/3600);
}
function exifToMysql(?string $s): ?string {
  if (!$s) return null;
  $s = trim($s);
  // formato típico "YYYY:MM:DD HH:MM:SS"
  if (preg_match('/^\d{4}:\d{2}:\d{2}\s+\d{2}:\d{2}:\d{2}$/', $s)) {
    return str_replace(':', '-', substr($s, 0, 10)) . substr($s, 10);
  }
  $t = strtotime($s);
  return $t ? date('Y-m-d H:i:s', $t) : null;
}

/* =========================
   5) EXIF / medidas / hash
   ========================= */
$sha1   = @sha1_file($tmp) ?: '';
$dim    = @getimagesize($tmp);
$w = $dim ? $dim[0] : null;
$h = $dim ? $dim[1] : null;

$exifMake = $exifModel = $exifDateTime = $exifSoft = $exifLens = null;
$exifLat = $exifLng = $exifAlt = $exifDir = null;
$exifFNum = $exifExp = $exifIso = $exifFocal = null;
$exifOrient = null;

$ext = strtolower(pathinfo($_FILES['fotoPregunta']['name'], PATHINFO_EXTENSION));
if (function_exists('exif_read_data') && in_array($ext, ['jpg','jpeg','tif','tiff'])) {
  try {
    $exif = @exif_read_data($tmp, 'IFD0,EXIF,GPS', true, false);
    if ($exif) {
      $exifMake     = $exif['IFD0']['Make']              ?? null;
      $exifModel    = $exif['IFD0']['Model']             ?? null;
      $exifSoft     = $exif['IFD0']['Software']          ?? null;
      $exifLens     = $exif['EXIF']['LensModel']         ?? null;
      $exifDateTime = $exif['EXIF']['DateTimeOriginal']  ?? ($exif['IFD0']['DateTime'] ?? null);
      $exifOrient   = isset($exif['IFD0']['Orientation']) ? (int)$exif['IFD0']['Orientation'] : null;

      if (!empty($exif['EXIF']['FNumber'])) {
        $f = $exif['EXIF']['FNumber'];
        $exifFNum = is_array($f)? $f[0]/max($f[1],1) : (float)$f;
      }
      if (!empty($exif['EXIF']['ExposureTime']))   { $exifExp = (string)$exif['EXIF']['ExposureTime']; }
      if (!empty($exif['EXIF']['ISOSpeedRatings'])){
        $iso = $exif['EXIF']['ISOSpeedRatings']; $exifIso = is_array($iso)? (int)reset($iso) : (int)$iso;
      }
      if (!empty($exif['EXIF']['FocalLength']))    {
        $fl = $exif['EXIF']['FocalLength']; $exifFocal = is_array($fl)? $fl[0]/max($fl[1],1) : (float)$fl;
      }

      if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLatitudeRef'])) {
        $exifLat = gpsToFloat($exif['GPS']['GPSLatitudeRef'], $exif['GPS']['GPSLatitude']);
      }
      if (!empty($exif['GPS']['GPSLongitude']) && !empty($exif['GPS']['GPSLongitudeRef'])) {
        $exifLng = gpsToFloat($exif['GPS']['GPSLongitudeRef'], $exif['GPS']['GPSLongitude']);
      }
      if (!empty($exif['GPS']['GPSAltitude'])) {
        $a = $exif['GPS']['GPSAltitude'];
        $exifAlt = is_array($a) ? $a[0]/max($a[1],1) : (float)$a;
      }
      if (!empty($exif['GPS']['GPSImgDirection'])) {
        $d = $exif['GPS']['GPSImgDirection'];
        $exifDir = is_array($d) ? $d[0]/max($d[1],1) : (float)$d;
      }
    }
  } catch (Throwable $t) { /* silencioso */ }
}

/* =========================
   6) Guardado físico (WebP)
   ========================= */
$dateDir = date('Y-m-d');
$dirAbs  = IW_UPLOAD_DIR . "/{$dateDir}/";
if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true)) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'No se pudo crear directorio destino']); exit;
}
$uniq   = uniqid("IW_{$qid}_", true) . '.webp';
$dstAbs = $dirAbs . $uniq;

if (!convertirAWebP($tmp, $dstAbs, 80)) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>'Error al convertir a WebP']); exit;
}

$urlRel = IW_UPLOAD_URL . "/{$dateDir}/{$uniq}";
$now    = date('Y-m-d H:i:s');

/* =========================
   7) Insert en form_question_responses
   ========================= */
$sqlIns = "
  INSERT INTO form_question_responses
    (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at)
  VALUES (?, ?, ?, ?, ?, 0, ?)
";
$stIns = $conn->prepare($sqlIns);
$stIns->bind_param("iiiiss", $visita_id, $qid, $idLocalVisita, $usuario, $urlRel, $now);
$stIns->execute();
$respId = $stIns->insert_id;
$stIns->close();

/* =========================
   8) Insert metadata (AJUSTADO A TU ESQUEMA)
   ========================= */
try {
  $exifDT = exifToMysql($exifDateTime);

  $metaJson = json_encode([
    'mime'            => $mime,
    'bytes'           => $size,
    'width'           => $w,
    'height'          => $h,
    'sha1'            => $sha1,
    'id_form_question'=> $qid,
    'id_formulario'   => $idCampana,
    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? null
  ], JSON_UNESCAPED_UNICODE);

  $sqlMeta = "INSERT INTO form_question_photo_meta
  (resp_id, visita_id, id_local, id_usuario, foto_url,
   exif_datetime, exif_lat, exif_lng, exif_altitude, exif_img_direction,
   exif_make, exif_model, exif_software, exif_lens_model,
   exif_fnumber, exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
   capture_source, meta_json, created_at)
  VALUES
  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stMeta = $conn->prepare($sqlMeta);

$types = "iiiissddddssssdsidisss"; // 22 tipos (incluye exif_orientation como 'i')

$stMeta->bind_param(
  $types,
  $respId,           // i  resp_id
  $visita_id,        // i  visita_id
  $idLocalVisita,    // i  id_local
  $usuario,          // i  id_usuario
  $urlRel,           // s  foto_url
  $exifDT,           // s  exif_datetime
  $exifLat,          // d  exif_lat
  $exifLng,          // d  exif_lng
  $exifAlt,          // d  exif_altitude
  $exifDir,          // d  exif_img_direction
  $exifMake,         // s  exif_make
  $exifModel,        // s  exif_model
  $exifSoft,         // s  exif_software
  $exifLens,         // s  exif_lens_model
  $exifFNum,         // d  exif_fnumber
  $exifExp,          // s  exif_exposure_time
  $exifIso,          // i  exif_iso
  $exifFocal,        // d  exif_focal_length
  $exifOrient,       // i  exif_orientation
  $capture,          // s  capture_source
  $metaJson,         // s  meta_json
  $now               // s  created_at
);

if (!$stMeta->execute()) {
  error_log('IW meta insert failed: ' . $stMeta->error);
}
$stMeta->close();

} catch (Throwable $e) {
  // No romper el flujo si falla metadatos
  error_log('IW meta insert exception: ' . $e->getMessage());
}

/* =========================
   9) Respuesta
   ========================= */
ob_clean(); // elimina cualquier warning capturado
echo json_encode([
  'status'  => 'success',
  'fotoUrl' => $urlRel,
  'resp_id' => $respId
], JSON_UNESCAPED_UNICODE);
exit;

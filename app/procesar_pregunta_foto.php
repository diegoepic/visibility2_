<?php

header('Content-Type: application/json');

ini_set('display_errors', 0);

if (
  empty($_POST['csrf_token']) ||
  empty($_SESSION['csrf_token']) ||
  !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
  http_response_code(419); // “authentication timeout/CSRF”
  echo json_encode([
    'status'  => 'error',
    'message' => 'CSRF inválido o ausente'
  ]);
  exit();
}


if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Sesión no iniciada'
    ]);
    exit();
}

$visita_id  = isset($_POST['visita_id']) ? intval($_POST['visita_id']) : 0;
$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);
date_default_timezone_set('America/Santiago');
$now        = date('Y-m-d H:i:s');

// 2) Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Método inválido'
    ]);
    exit();
}

// 3) Leer parámetros
if (!isset($_POST['id_form_question'], $_POST['id_local'])) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Faltan parámetros id_form_question o id_local'
    ]);
    exit();
}
$id_form_question = intval($_POST['id_form_question']);
$id_local         = intval($_POST['id_local']);
if ($id_form_question <= 0 || $id_local <= 0) {
    echo json_encode([
        'status'=>'error',
        'message'=>'Parámetros no válidos'
    ]);
    exit();
}

// 4) Verificar archivo
if (!isset($_FILES['fotoPregunta'])) {
    echo json_encode([
        'status'=>'error',
        'message'=>'No se recibió la foto'
    ]);
    exit();
}
$fileError = $_FILES['fotoPregunta']['error'];
if ($fileError !== UPLOAD_ERR_OK) {
    echo json_encode([
        'status'=>'error',
        'message'=>'Error al subir la foto (código ' . $fileError . ')'
    ]);
    exit();
}

$tmpName  = $_FILES['fotoPregunta']['tmp_name'];
$origName = $_FILES['fotoPregunta']['name'];
$sizeFile = $_FILES['fotoPregunta']['size'];

// 5) Validaciones tipo y tamaño (aceptar HEIC/HEIF)
$mimeType = @mime_content_type($tmpName);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$looksImage = (strpos((string)$mimeType, 'image/') === 0) || in_array($ext, ['heic','heif']);
if (!$looksImage) {
    echo json_encode([
        'status'=>'error',
        'message'=>'El archivo no es una imagen válida'
    ]);
    exit();
}
$maxSize = 5 * 1024 * 1024;
if ($sizeFile > $maxSize) {
    echo json_encode([
        'status'=>'error',
        'message'=>'La imagen excede 5MB'
    ]);
    exit();
}

// 6) Verificar permiso en BD
$sqlCheck = "
    SELECT COUNT(*) as cnt
      FROM form_questions fq
      JOIN formulario f ON f.id = fq.id_formulario
      JOIN formularioQuestion fq2 ON fq2.id_formulario = f.id
     WHERE fq.id=? AND fq2.id_local=? AND fq2.id_usuario=? AND f.id_empresa=?
     LIMIT 1
";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("iiii", $id_form_question, $id_local, $usuario_id, $empresa_id);
$stmtCheck->execute();
$resC = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();
if (intval($resC['cnt']) === 0) {
    echo json_encode([
        'status'=>'error',
        'message'=>'No tienes permiso o no existe la pregunta/local.'
    ]);
    exit();
}

// Helpers de lectura/saneo de POST
function post_str($k, $default=null) {
  return isset($_POST[$k]) && $_POST[$k] !== '' ? trim((string)$_POST[$k]) : $default;
}
function post_float($k, $default=null) {
  return (isset($_POST[$k]) && $_POST[$k] !== '' && is_numeric($_POST[$k])) ? floatval($_POST[$k]) : $default;
}
function post_int($k, $default=null) {
  return (isset($_POST[$k]) && $_POST[$k] !== '' && is_numeric($_POST[$k])) ? intval($_POST[$k]) : $default;
}
function normalize_datetime($s) {
  if (!$s) return null;
  $ts = @strtotime($s);
  return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

// Función de conversión a WebP (con soporte HEIC si está disponible)
function convertToWebP($srcPath, $dstPath, $maxDim = 1280, $quality = 80) {
  if (class_exists('Imagick')) {
    try {
      $img = new Imagick();
      $img->readImage($srcPath);

      if ($img->getNumberImages() > 1) {
        $img = $img->coalesceImages();
        $img->setIteratorIndex(0);
      }
      if (method_exists($img,'autoOrient')) $img->autoOrient();

      $w = $img->getImageWidth();
      $h = $img->getImageHeight();
      if ($w > $maxDim || $h > $maxDim) {
        if ($w >= $h) $img->resizeImage($maxDim, 0, Imagick::FILTER_LANCZOS, 1, true);
        else          $img->resizeImage(0, $maxDim, Imagick::FILTER_LANCZOS, 1, true);
      }

      $img->setImageFormat('webp');
      $img->setImageCompressionQuality($quality);
      if (method_exists($img,'setImageAlphaChannel')) {
        $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
      }
      $img->writeImage($dstPath);
      $img->clear(); $img->destroy();
      return true;
    } catch (Exception $e) {
      // seguir a fallback
    }
  }

  $info = @getimagesize($srcPath);
  $mime = $info ? $info['mime'] : '';
  $isHeic = preg_match('/heic|heif/i', (string)$mime) || preg_match('/\.(heic|heif)$/i', $srcPath);

  if ($isHeic) {
    $bin = @trim(shell_exec('command -v heif-convert'));
    if ($bin) {
      $tmpJpg = $dstPath.'.jpg';
      @shell_exec(escapeshellcmd("$bin ".escapeshellarg($srcPath).' '.escapeshellarg($tmpJpg)));
      if (file_exists($tmpJpg)) {
        $ok = convertToWebP($tmpJpg, $dstPath, $maxDim, $quality);
        @unlink($tmpJpg);
        return $ok;
      }
    }
    return false;
  }

  switch ($mime) {
    case 'image/jpeg':
      $im = @imagecreatefromjpeg($srcPath);
      if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcPath);
        if (!empty($exif['Orientation'])) {
          if ($exif['Orientation'] == 3) $im = imagerotate($im, 180, 0);
          elseif ($exif['Orientation'] == 6) $im = imagerotate($im, -90, 0);
          elseif ($exif['Orientation'] == 8) $im = imagerotate($im, 90, 0);
        }
      }
      break;
    case 'image/png':
      $im = @imagecreatefrompng($srcPath);
      imagepalettetotruecolor($im);
      imagealphablending($im, true);
      imagesavealpha($im, true);
      break;
    case 'image/webp':
      if (function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($srcPath);
      } else {
        return copy($srcPath, $dstPath);
      }
      break;
    default:
      return false;
  }

  if (!$im) return false;

  $w = imagesx($im); $h = imagesy($im);
  if ($w > $maxDim || $h > $maxDim) {
    if ($w >= $h) { $newW = $maxDim; $newH = (int)($h * $maxDim / $w); }
    else          { $newH = $maxDim; $newW = (int)($w * $maxDim / $h); }
    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0,0, 0,0, $newW,$newH, $w,$h);
    imagedestroy($im);
    $im = $dst;
  }

  $ok = function_exists('imagewebp') ? imagewebp($im, $dstPath, $quality) : imagejpeg($im, $dstPath, 85);
  imagedestroy($im);
  return (bool)$ok;
}

// 7) Convertir SIEMPRE a .webp
$dirUpload = __DIR__ . '/uploads/uploads_fotos_pregunta/';
if (!is_dir($dirUpload)) mkdir($dirUpload, 0755, true);

$unique  = uniqid('q7_', true) . '.webp';
$destino = $dirUpload . $unique;

if (!convertToWebP($tmpName, $destino, 1280, 80)) {
    $isHeic = preg_match('/\.(heic|heif)$/i', $origName) || preg_match('/heic|heif/i', (string)$mimeType);
    $msg = $isHeic
      ? 'No se pudo convertir HEIC a WebP. Instala Imagick con libheif o heif-convert en el servidor.'
      : 'No se pudo convertir la imagen a WebP.';
    echo json_encode(['status'=>'error','message'=>$msg]);
    exit();
}

// 8) URL relativa (la que usas en la app)
$urlFoto = "/visibility2/app/uploads/uploads_fotos_pregunta/{$unique}";

// 9) Insertar respuesta en form_question_responses
$sqlResp = "
  INSERT INTO form_question_responses
    (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at)
  VALUES (?, ?, ?, ?, ?, 0, ?)
";
$stmtR = $conn->prepare($sqlResp);
if (!$stmtR) {
  echo json_encode(['status'=>'error','message'=>'Error preparando insert de respuesta.']);
  exit();
}
$stmtR->bind_param("iiiiss",
    $visita_id,
    $id_form_question,
    $id_local,
    $usuario_id,
    $urlFoto,
    $now
);
if (!$stmtR->execute()) {
    echo json_encode([
        'status'=>'error',
        'message'=>'Error al guardar en BD: ' . $stmtR->error
    ]);
    exit();
}
$response_id = $stmtR->insert_id;
$stmtR->close();

// 10) Metadatos (opcionalmente enviados por el front ANTES de comprimir)
$captureSource = strtolower((string)post_str('capture_source', 'unknown'));
if (!in_array($captureSource, ['camera','gallery','unknown'], true)) {
    $captureSource = 'unknown';
}

$exif_datetime       = normalize_datetime(post_str('exif_datetime', null));
$exif_lat            = post_float('exif_lat', null);
$exif_lng            = post_float('exif_lng', null);
$exif_altitude       = post_float('exif_altitude', null);
$exif_img_direction  = post_float('exif_img_direction', null);
$exif_make           = post_str('exif_make', null);
$exif_model          = post_str('exif_model', null);
$exif_software       = post_str('exif_software', null);
$exif_lens_model     = post_str('exif_lens_model', null);
$exif_fnumber        = post_float('exif_fnumber', null);
$exif_exposure_time  = post_str('exif_exposure_time', null);
$exif_iso            = post_int('exif_iso', null);
$exif_focal_length   = post_float('exif_focal_length', null);
$exif_orientation    = post_int('exif_orientation', null);

// meta_json → validar JSON o dejar NULL
$meta_json_raw = post_str('meta_json', null);
$meta_json     = null;
if ($meta_json_raw !== null && $meta_json_raw !== '') {
    $decoded = json_decode($meta_json_raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $meta_json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    } else {
        $meta_json = null;
    }
}

// 11) Insertar metadatos en form_question_photo_meta
$sqlMeta = "
  INSERT INTO form_question_photo_meta
  (resp_id, visita_id, id_local, id_usuario, foto_url,
   exif_datetime, exif_lat, exif_lng, exif_altitude, exif_img_direction,
   exif_make, exif_model, exif_software, exif_lens_model,
   exif_fnumber, exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
   capture_source, meta_json, created_at)
  VALUES
  (?,?,?,?,?,
   ?,?,?,?,?,?,
   ?,?,?,?,
   ?,?,?,?,
   ?,?,?)
";
$stmtM = $conn->prepare($sqlMeta);
if ($stmtM) {
    // Types: i i i i s  s d d d d  s s s s  d s i d i  s s s
    $stmtM->bind_param(
        "iiiissddddssssdsidisss",
        $response_id,
        $visita_id,
        $id_local,
        $usuario_id,
        $urlFoto,
        $exif_datetime,
        $exif_lat,
        $exif_lng,
        $exif_altitude,
        $exif_img_direction,
        $exif_make,
        $exif_model,
        $exif_software,
        $exif_lens_model,
        $exif_fnumber,
        $exif_exposure_time,
        $exif_iso,
        $exif_focal_length,
        $exif_orientation,
        $captureSource,
        $meta_json,
        $now
    );
    // si falla el execute, no bloqueamos el flujo principal (solo log)
    if (!$stmtM->execute()) {
        error_log("form_question_photo_meta insert error: ".$stmtM->error);
    }
    $stmtM->close();
} else {
    error_log("Error preparando form_question_photo_meta: ".$conn->error);
}

// 12) Responder JSON
echo json_encode([
    'status'  => 'success',
    'message' => 'Foto subida y guardada',
    'fotoUrl' => $urlFoto,
    'resp_id' => $response_id
]);
exit();

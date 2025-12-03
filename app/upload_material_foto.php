<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
header('Content-Type: application/json');


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

try {
    // 1) Validaciones
    if (
      $_SERVER['REQUEST_METHOD']!=='POST' ||
      empty($_POST['csrf_token']) ||
      !isset($_SESSION['csrf_token']) ||
      $_POST['csrf_token']!==$_SESSION['csrf_token'] ||
      !isset($_POST['idFQ'], $_POST['idCampana'], $_POST['idLocal'], $_POST['division_id']) ||
      empty($_FILES['foto'])
    ) {
      throw new Exception('Parámetros inválidos');
    }

    $idFQ       = (int) $_POST['idFQ'];
    $idCampana  = (int) $_POST['idCampana'];
    $idLocal    = (int) $_POST['idLocal'];
    $divisionId = (int) $_POST['division_id'];
    $usuarioId  = (int) $_SESSION['usuario_id'];
    $visita_id  = intval($_POST['visita_id']);

    // 2) Permisos
    $stmt = $conn->prepare("
      SELECT COUNT(*) FROM formularioQuestion
       WHERE id=? AND id_formulario=? AND id_local=? AND id_usuario=?
    ");
    $stmt->bind_param("iiii",$idFQ,$idCampana,$idLocal,$usuarioId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    if ($cnt===0) throw new Exception('Sin permiso sobre este material');

    // 3) Leer material
    $stmt = $conn->prepare("SELECT material FROM formularioQuestion WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$idFQ);
    $stmt->execute();
    $stmt->bind_result($matName);
    if (!$stmt->fetch()) throw new Exception('Material no encontrado');
    $stmt->close();

    // 4) Obtener o crear registro en material
    $stmt = $conn->prepare("
      SELECT id FROM material WHERE nombre=? AND id_division=? LIMIT 1
    ");
    $stmt->bind_param("si",$matName,$divisionId);
    $stmt->execute();
    $stmt->bind_result($matId);
    if (!$stmt->fetch()) {
        $stmt->close();
        $ins = $conn->prepare("INSERT INTO material(nombre,id_division) VALUES(?,?)");
        $ins->bind_param("si",$matName,$divisionId);
        $ins->execute();
        $matId = $ins->insert_id;
        $ins->close();
    } else {
        $stmt->close();
    }

    // 5) Validar archivo (aceptar HEIC/HEIF)
    $file = $_FILES['foto'];
    if ($file['error']!==UPLOAD_ERR_OK) throw new Exception('Error al subir archivo');
    $tmp  = $file['tmp_name'];
    $mime = @mime_content_type($tmp);
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $looksImage = (strpos((string)$mime,'image/') === 0) || in_array($ext, ['heic','heif']);
    if (!$looksImage) {
      throw new Exception('El archivo no es una imagen válida');
    }

    // 6) Directorio por fecha y material
    $hoy  = date('Y-m-d');
    $base = __DIR__.'/uploads/';
    $dir  = "{$base}{$hoy}/material_{$matId}/";
    if (!is_dir($dir) && !mkdir($dir,0755,true)) {
      throw new Exception("No se pudo crear directorio $dir");
    }

    // 7) Conversión a WebP con soporte HEIC
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
          // seguir al fallback
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

    // 8) Guardar WebP
    $filename = uniqid('mat_', true) . '.webp';
    $dest     = $dir . $filename;

    if (!convertToWebP($tmp, $dest, 1280, 80)) {
      if (preg_match('/\.(heic|heif)$/i', $file['name']) || preg_match('/heic|heif/i', (string)$mime)) {
        throw new Exception('No se pudo convertir HEIC a WebP. Instala Imagick con libheif o el binario heif-convert.');
      }
      throw new Exception('Falló conversión a WebP');
    }
    $urlRel = "uploads/{$hoy}/material_{$matId}/{$filename}";

    // 9) Metadatos recibidos desde el FRONT (antes de comprimir)
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

    // meta_json debe ser JSON válido o NULL
    $meta_json_raw = post_str('meta_json', null);
    $meta_json     = null;
    if ($meta_json_raw !== null && $meta_json_raw !== '') {
        $decoded = json_decode($meta_json_raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $meta_json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        } else {
            // si vino inválido lo dejamos en NULL para evitar error de MySQL
            $meta_json = null;
        }
    }

    // Coordenadas de usuario (no EXIF) – vienen del front al momento de la toma
    $lat = post_float('lat', 0.0);
    $lng = post_float('lng', 0.0);

    // 10) Insertar en fotoVisita con metadatos
    $sql = "
      INSERT INTO fotoVisita
        (visita_id, url, exif_datetime, id_usuario, id_formulario, id_local, id_material, id_formularioQuestion,
         fotoLat, fotoLng,
         exif_lat, exif_lng, exif_altitude, exif_img_direction,
         exif_make, exif_model, exif_software, exif_lens_model,
         exif_fnumber, exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
         capture_source, meta_json)
      VALUES(?,?,?,?,?,?,?,?,?,
             ?, ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             ?, ?)
    ";
    $i3 = $conn->prepare($sql);
    if (!$i3) {
      throw new Exception("Error preparando INSERT fotoVisita: " . $conn->error);
    }

    // Types: i s s i i i i i d d d d d d s s s s d s i d i s s  (25)
    $i3->bind_param(
      "issiiiiiddddddssssdsidiss",
      $visita_id,       // i
      $urlRel,          // s
      $exif_datetime,   // s (nullable)
      $usuarioId,       // i
      $idCampana,       // i
      $idLocal,         // i
      $matId,           // i
      $idFQ,            // i
      $lat,             // d
      $lng,             // d
      $exif_lat,        // d (nullable)
      $exif_lng,        // d (nullable)
      $exif_altitude,   // d (nullable)
      $exif_img_direction, // d (nullable)
      $exif_make,       // s
      $exif_model,      // s
      $exif_software,   // s
      $exif_lens_model, // s
      $exif_fnumber,    // d (nullable)
      $exif_exposure_time, // s
      $exif_iso,        // i (nullable)
      $exif_focal_length, // d (nullable)
      $exif_orientation,  // i (nullable)
      $captureSource,     // s
      $meta_json          // s (nullable)
    );

    if (!$i3->execute()) {
      throw new Exception("Error al insertar fotoVisita: " . $i3->error);
    }
    $newId = $i3->insert_id;
    $i3->close();

    echo json_encode([
      'status'=>'success',
      'url'   =>$urlRel,
      'id'    =>$newId
    ]);

} catch(Exception $e) {
    http_response_code(500);
    echo json_encode([
      'status'=>'error',
      'message'=>$e->getMessage()
    ]);
    error_log("upload_material_foto.php: ".$e->getMessage());
}

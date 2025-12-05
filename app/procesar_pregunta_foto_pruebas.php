<?php
// /visibility2/app/procesar_pregunta_foto_pruebas.php
// Subida de foto para pregunta (tipo 7) con:
// - POST + CSRF (header X-CSRF-Token o body csrf_token)
// - Permisos por empresa/local/usuario
// - Conversión a WebP (HEIC si hay dependencias)
// - Idempotencia funcional (request_log) endpoint 'pregunta_foto'
// - Fallback por client_guid para resolver/crear visita si no llega visita_id
// - Inserción en FQR con columnas dinámicas (created_at/valor/foto_visita_id si existen)

declare(strict_types=1);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
date_default_timezone_set('America/Santiago');

/* ===== Helpers ===== */
function json_out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function post_str($k, $default=null){ return isset($_POST[$k]) && $_POST[$k]!=='' ? trim((string)$_POST[$k]) : $default; }
function post_int($k, $default=null){ return (isset($_POST[$k]) && $_POST[$k]!=='' && is_numeric($_POST[$k])) ? (int)$_POST[$k] : $default; }
function post_float($k, $default=null){ return (isset($_POST[$k]) && $_POST[$k]!=='' && is_numeric($_POST[$k])) ? (float)$_POST[$k] : $default; }
function normalize_datetime($s){ if(!$s) return null; $ts=@strtotime($s); return $ts?date('Y-m-d H:i:s',$ts):null; }
function get_header_lower(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$key]) && $_SERVER[$key] !== '' ? trim((string)$_SERVER[$key]) : null;
}
function read_csrf(): ?string {
  $h = get_header_lower('X-CSRF-Token');
  if (!empty($h)) return $h;
  return isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null;
}
function sanitize_idempotency_key(): void {
  $raw = get_header_lower('X-Idempotency-Key');
  if (!$raw && isset($_POST['X_Idempotency_Key'])) $raw = (string)$_POST['X_Idempotency_Key'];
  if ($raw !== null && $raw !== '') {
    $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
    if (strlen($k) > 64) $k = substr(hash('sha256', $k), 0, 64);
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
    $_POST['X_Idempotency_Key'] = $k;
  }
}
function table_has_col(mysqli $c, string $table, string $col): bool {
  try {
    $res = $c->query("SHOW COLUMNS FROM `$table` LIKE '".$c->real_escape_string($col)."'");
    if ($res) { $ok = $res->num_rows > 0; $res->close(); return $ok; }
  } catch (Throwable $e) {}
  return false;
}

/* ===== CSRF ===== */
$csrf = read_csrf();
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  json_out(419, ['status'=>'error','message'=>'CSRF inválido o ausente']);
}

/* ===== sesión ===== */
if (!isset($_SESSION['usuario_id'])) {
  json_out(401, ['status'=>'error','message'=>'Sesión no iniciada']);
}
$now        = date('Y-m-d H:i:s');
$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

/* ===== DB ===== */
/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
  if (!isset($conn) || !($conn instanceof mysqli)) {
    json_out(500, ['status'=>'error','message'=>'Sin conexión a BD']);
  }
}
@$conn->set_charset('utf8mb4');

/* ===== método ===== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(405, ['status'=>'error','message'=>'Método inválido']);
}

/* ===== Idempotencia ===== */
require_once __DIR__ . '/lib/idempotency.php';
sanitize_idempotency_key();
idempo_claim_or_fail($conn, 'pregunta_foto'); // si existe respuesta previa, responde y exit

/* ===== inputs ===== */
$visita_id        = post_int('visita_id', 0);
$id_form_question = post_int('id_form_question', 0);            // tabla form_questions.id
$id_local         = post_int('id_local', 0);
$client_guid      = post_str('client_guid', '');                 // clave del cliente para reconciliar offline
$app_lat          = post_float('lat', null);                     // coords que manda el front (no exif)
$app_lng          = post_float('lng', null);

if ($id_form_question<=0 || $id_local<=0) {
  json_out(400, ['status'=>'error','message'=>'Parámetros incompletos']);
}

/* ===== archivo ===== */
if (!isset($_FILES['fotoPregunta'])) {
  json_out(400, ['status'=>'error','message'=>'No se recibió la foto']);
}
$file = $_FILES['fotoPregunta'];
if ($file['error'] !== UPLOAD_ERR_OK) {
  json_out(400, ['status'=>'error','message'=>'Error al subir la foto (código '.$file['error'].')']);
}
$tmp      = $file['tmp_name'];
$origName = $file['name'];
$size     = (int)$file['size'];
$mime     = @mime_content_type($tmp) ?: ($file['type'] ?? '');
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$looksImg = (strpos((string)$mime, 'image/') === 0) || in_array($ext, ['heic','heif'], true);
if (!$looksImg) {
  json_out(400, ['status'=>'error','message'=>'El archivo no es una imagen válida']);
}
if ($size > 20 * 1024 * 1024) { // 20MB
  json_out(413, ['status'=>'error','message'=>'La imagen excede 20MB']);
}

/* ===== permisos: pregunta debe pertenecer a local/usuario/empresa ===== */
$stmt = $conn->prepare("
  SELECT f.id AS id_formulario
    FROM form_questions q
    JOIN formulario f ON f.id = q.id_formulario
    JOIN formularioQuestion fq2
      ON fq2.id_formulario = f.id
     AND fq2.id_local      = ?
     AND fq2.id_usuario    = ?
  WHERE q.id = ?
    AND f.id_empresa = ?
  LIMIT 1
");
$stmt->bind_param("iiii", $id_local, $usuario_id, $id_form_question, $empresa_id);
$stmt->execute();
$stmt->bind_result($id_formulario);
if (!$stmt->fetch()) {
  $stmt->close();
  json_out(403, ['status'=>'error','message'=>'No tienes permiso para esta pregunta/local']);
}
$stmt->close();

/* ===== resolver/crear visita si no llega visita_id (fallback offline por client_guid) ===== */
if ($visita_id <= 0) {
  // 1) por client_guid (abierta)
  if ($client_guid !== '') {
    if ($q = $conn->prepare("
      SELECT id, fecha_fin
        FROM visita
       WHERE id_usuario=? AND id_formulario=? AND id_local=? AND client_guid=?
       LIMIT 1
    ")) {
      $q->bind_param("iiis", $usuario_id, $id_formulario, $id_local, $client_guid);
      $q->execute();
      $r = $q->get_result();
      if ($row = $r->fetch_assoc()) {
        if (empty($row['fecha_fin']) || $row['fecha_fin']==='0000-00-00 00:00:00') {
          $visita_id = (int)$row['id'];
        }
      }
      $q->close();
    }
  }
  // 2) abierta genérica
  if ($visita_id <= 0) {
    if ($q2 = $conn->prepare("
      SELECT id
        FROM visita
       WHERE id_usuario=? AND id_formulario=? AND id_local=?
         AND (fecha_fin IS NULL OR fecha_fin='0000-00-00 00:00:00')
       ORDER BY id DESC
       LIMIT 1
    ")) {
      $q2->bind_param("iii", $usuario_id, $id_formulario, $id_local);
      $q2->execute();
      $r2 = $q2->get_result();
      if ($row2 = $r2->fetch_assoc()) {
        $visita_id = (int)$row2['id'];
      }
      $q2->close();
    }
  }
  // 3) crear si aún no existe (usa client_guid; si no viene, genera uno)
  if ($visita_id <= 0) {
    if ($client_guid === '') { $client_guid = bin2hex(random_bytes(16)); }
    if ($ins = $conn->prepare("
      INSERT INTO visita
        (id_usuario, id_formulario, id_local, client_guid, fecha_inicio, latitud, longitud)
      VALUES (?,?,?,?,?,?,?)
    ")) {
      $lat0 = is_null($app_lat) ? 0.0 : (float)$app_lat;
      $lng0 = is_null($app_lng) ? 0.0 : (float)$app_lng;
      $ins->bind_param("iiissdd", $usuario_id, $id_formulario, $id_local, $client_guid, $now, $lat0, $lng0);
      if ($ins->execute()) { $visita_id = (int)$ins->insert_id; }
      $ins->close();
    }
    if ($visita_id <= 0) {
      json_out(428, ['status'=>'error','message'=>'No fue posible resolver/crear la visita para esta foto']);
    }
  }
}

/* ===== conversión a WebP (soporta HEIC si hay dependencias) ===== */
function convertToWebP($srcPath, $dstPath, $maxDim = 1280, $quality = 80): bool {
  if (class_exists('Imagick')) {
    try {
      $img = new Imagick();
      $img->readImage($srcPath);
      if ($img->getNumberImages() > 1) { $img = $img->coalesceImages(); $img->setIteratorIndex(0); }
      if (method_exists($img,'autoOrient')) $img->autoOrient();
      $w = $img->getImageWidth(); $h = $img->getImageHeight();
      if ($w > $maxDim || $h > $maxDim) {
        if ($w >= $h) $img->resizeImage($maxDim, 0, Imagick::FILTER_LANCZOS, 1, true);
        else          $img->resizeImage(0, $maxDim, Imagick::FILTER_LANCZOS, 1, true);
      }
      $img->setImageFormat('webp');
      $img->setImageCompressionQuality($quality);
      if (method_exists($img,'setImageAlphaChannel')) $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
      $img->writeImage($dstPath);
      $img->clear(); $img->destroy();
      return true;
    } catch (Throwable $e) {}
  }
  $info = @getimagesize($srcPath);
  $mime = $info['mime'] ?? '';
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
      imagepalettetotruecolor($im); imagealphablending($im, true); imagesavealpha($im, true);
      break;
    case 'image/webp':
      if (function_exists('imagecreatefromwebp')) { $im = @imagecreatefromwebp($srcPath); }
      else { return copy($srcPath, $dstPath); }
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
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0,0, 0,0, $newW,$newH, $w,$h);
    imagedestroy($im);
    $im = $dst;
  }
  $ok = function_exists('imagewebp') ? imagewebp($im, $dstPath, $quality) : imagejpeg($im, $dstPath, 85);
  imagedestroy($im);
  return (bool)$ok;
}

/* ===== ruta consistente: uploads/YYYY-MM-DD/pregunta_{id}/file.webp ===== */
$hoy      = date('Y-m-d');
$dirBase  = __DIR__.'/uploads/';
$dirFecha = $dirBase.$hoy.'/';
$dirQ     = $dirFecha.'pregunta_'.$id_form_question.'/';
foreach ([$dirBase,$dirFecha,$dirQ] as $d) {
  if (!is_dir($d) && !mkdir($d,0755,true)) {
    json_out(500, ['status'=>'error','message'=>'No se pudo preparar el directorio de subida']);
  }
}
$filename = 'qfoto_'.uniqid('',true).'.webp';
$destAbs  = $dirQ.$filename;

if (!convertToWebP($tmp, $destAbs, 1280, 80)) {
  $isHeic = preg_match('/\.(heic|heif)$/i', $origName) || preg_match('/heic|heif/i', (string)$mime);
  $msg = $isHeic
    ? 'No se pudo convertir HEIC/HEIF a WebP (faltan dependencias en el server).'
    : 'No se pudo convertir la imagen a WebP.';
  json_out(500, ['status'=>'error','message'=>$msg]);
}

/* URL relativa (igual que otros endpoints: empieza por "uploads/") */
$relUrl   = 'uploads/'.$hoy.'/pregunta_'.$id_form_question.'/'.$filename;
$absolute = '/visibility2/app/'.$relUrl;

/* ===== columnas dinámicas para FQR ===== */
$FQR_HAS_CREATED_AT   = table_has_col($conn, 'form_question_responses', 'created_at');
$FQR_HAS_VALOR        = table_has_col($conn, 'form_question_responses', 'valor');
$FQR_HAS_FOTO_VISITA  = table_has_col($conn, 'form_question_responses', 'foto_visita_id');

/* ===== registrar respuesta (answer_text = url) + metadatos ===== */
$conn->begin_transaction();
try {
  // 1) INSERT en form_question_responses con columnas presentes
  $cols  = ['visita_id','id_form_question','id_local','id_usuario','answer_text','id_option'];
  $ph    = ['?','?','?','?','?','?'];
  $types = 'iiiisi';
  $args  = [$visita_id, $id_form_question, $id_local, $usuario_id, $relUrl, 0];

  if ($FQR_HAS_CREATED_AT) { $cols[]='created_at'; $ph[]='?'; $types.='s'; $args[]=$now; }
  if ($FQR_HAS_VALOR)      { $cols[]='valor';      $ph[]='?'; $types.='d'; $args[]=0.0; }
  if ($FQR_HAS_FOTO_VISITA){ $cols[]='foto_visita_id'; $ph[]='?'; $types.='i'; $args[]=null; } // NULL explícito

  $sqlFqr = "INSERT INTO form_question_responses (".implode(',',$cols).") VALUES (".implode(',',$ph).")";
  $stmtF  = $conn->prepare($sqlFqr);
  if (!$stmtF) throw new Exception('Prep insert respuesta: '.$conn->error);

  // bind dinámico por referencia
  $refs = []; $refs[] = $types;
  foreach ($args as $k=>&$v) { $refs[] = &$v; }
  if (!call_user_func_array([$stmtF,'bind_param'], $refs)) {
    throw new Exception('bind_param dinámico falló');
  }
  if (!$stmtF->execute()) throw new Exception('Insert respuesta: '.$stmtF->error);
  $resp_id = $stmtF->insert_id;
  $stmtF->close();

  // 2) Metadatos opcionales (EXIF + capture_source + meta_json + coords de app)
  $capture_source      = strtolower((string)post_str('capture_source','unknown'));
  if (!in_array($capture_source,['camera','gallery','unknown'],true)) $capture_source='unknown';

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

  $meta_json_raw = post_str('meta_json', null);
  $meta_json     = null;
  if ($meta_json_raw !== null && $meta_json_raw !== '') {
    $decoded = json_decode($meta_json_raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      $decoded['_app_coords'] = ['lat'=>$app_lat, 'lng'=>$app_lng];
      $meta_json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }
  }

  // Existe tabla form_question_photo_meta?
  $has_meta_table = false;
  try {
    $chk = $conn->query("DESCRIBE form_question_photo_meta");
    if ($chk && $chk->num_rows > 0) $has_meta_table = true;
    if ($chk) $chk->close();
  } catch (Throwable $e) { $has_meta_table = false; }

  if ($has_meta_table) {
    $stmtM = $conn->prepare("
      INSERT INTO form_question_photo_meta
      (resp_id, visita_id, id_local, id_usuario, foto_url,
       exif_datetime, exif_lat, exif_lng, exif_altitude, exif_img_direction,
       exif_make, exif_model, exif_software, exif_lens_model,
       exif_fnumber, exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
       capture_source, meta_json, created_at)
      VALUES (?,?,?,?,?,
              ?,?,?,?,?,
              ?,?,?,?,
              ?,?,?,?,?,
              ?,?,?)
    ");
    if ($stmtM) {
      $stmtM->bind_param(
        "iiiissddddssssdsidisss",
        $resp_id, $visita_id, $id_local, $usuario_id, $relUrl,
        $exif_datetime, $exif_lat, $exif_lng, $exif_altitude, $exif_img_direction,
        $exif_make, $exif_model, $exif_software, $exif_lens_model,
        $exif_fnumber, $exif_exposure_time, $exif_iso, $exif_focal_length, $exif_orientation,
        $capture_source, $meta_json, $now
      );
      if (!$stmtM->execute()) { error_log('form_question_photo_meta insert: '.$stmtM->error); }
      $stmtM->close();
    }
  } else {
    // Sidecar JSON al lado del archivo
    @file_put_contents(
      $destAbs.'.json',
      json_encode([
        'resp_id'=>$resp_id,'visita_id'=>$visita_id,'id_local'=>$id_local,'id_usuario'=>$usuario_id,
        'foto_url'=>$relUrl,
        'exif'=>[
          'datetime'=>$exif_datetime,'lat'=>$exif_lat,'lng'=>$exif_lng,'altitude'=>$exif_altitude,'img_direction'=>$exif_img_direction,
          'make'=>$exif_make,'model'=>$exif_model,'software'=>$exif_software,'lens_model'=>$exif_lens_model,
          'fnumber'=>$exif_fnumber,'exposure_time'=>$exif_exposure_time,'iso'=>$exif_iso,'focal_length'=>$exif_focal_length,'orientation'=>$exif_orientation,
          'capture_source'=>$capture_source,'meta_json'=>$meta_json
        ],
        '_app_coords'=>['lat'=>$app_lat, 'lng'=>$app_lng],
        'saved_at'=>date('c')
      ], JSON_UNESCAPED_UNICODE)
    );
  }

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  if (is_file($destAbs)) @unlink($destAbs);
  if (is_file($destAbs.'.json')) @unlink($destAbs.'.json');
  error_log('procesar_pregunta_foto_pruebas.php: '.$e->getMessage());
  json_out(500, ['status'=>'error','message'=>'No se pudo guardar la foto: '.$e->getMessage()]);
}

/* ===== responder guardando en log de idempotencia ===== */
idempo_store_and_reply($conn, 'pregunta_foto', 200, [
  'status'      => 'success',
  'message'     => 'Foto subida y guardada',
  'fotoUrl'     => $relUrl,
  'absolute'    => $absolute,
  'resp_id'     => $resp_id,
  'visita_id'   => $visita_id,
  'id_form_question' => $id_form_question
]);
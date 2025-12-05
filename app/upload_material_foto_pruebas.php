<?php

declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const APP_BASE = '/visibility2/app';

/** Normaliza a 'uploads/...' sin prefijos ni barras dobles */
function norm_rel_url(string $u): string {
  $u = str_replace('\\','/',$u);
  $u = preg_replace('#/{2,}#','/',$u);
  $u = ltrim($u, '/');
  if (stripos($u, 'visibility2/app/') === 0) {
    $u = substr($u, strlen('visibility2/app/'));
  }
  if (stripos($u, 'uploads/') !== 0) {
    $u = 'uploads/' . ltrim($u, '/');
  }
  return $u;
}
/** Convierte relativa ('uploads/...') a absoluta ('/visibility2/app/uploads/...') */
function abs_url(string $rel): string {
  return APP_BASE . '/' . norm_rel_url($rel);
}

/* ---------------- Helpers varios ---------------- */
function json_fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['status'=>'error','message'=>$msg] + $extra, JSON_UNESCAPED_UNICODE);
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
/** CORS best-effort + OPTIONS */
function allow_cors_and_options(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allow  = 'https://visibility.cl';
  if ($origin && preg_match('#https://([a-z0-9.-]+\.)?visibility\.cl$#i', $origin)) { $allow = $origin; }
  header('Vary: Origin');
  header('Access-Control-Allow-Origin: ' . $allow);
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token, X-Idempotency-Key, X-HTTP-Method-Override, X_Offline_Queue, X-Offline-Queue');
  header('Access-Control-Max-Age: 600');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); echo ''; exit; }
}
/** Sanitiza X-Idempotency-Key para evitar char inválidos/longitudes */
function sanitize_idempotency_key(): void {
  $raw = get_header_lower('X-Idempotency-Key');
  if (!$raw && isset($_POST['X_Idempotency_Key'])) $raw = (string)$_POST['X_Idempotency_Key'];
  if ($raw !== null && $raw !== '') {
    $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
    if (strlen($k) > 64) $k = substr(hash('sha256', $k), 0, 64);
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
    $_POST['X_Idempotency_Key']        = $k;
  }
}

/* ---------------- CORS / OPTIONS ---------------- */
allow_cors_and_options();

/* ---------------- Seguridad básica ---------------- */
if (!isset($_SESSION['usuario_id'])) { json_fail(401, 'Sesión no iniciada'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Allow: POST, OPTIONS');
  json_fail(405, 'Método inválido');
}
$csrf = read_csrf();
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  json_fail(419, 'CSRF inválido o ausente');
}
$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

/* ---------------- DB ---------------- */
/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
  if (!isset($conn) || !($conn instanceof mysqli)) { json_fail(500, 'Sin conexión a BD'); }
}
@$conn->set_charset('utf8mb4');

/* ---------------- Idempotencia ---------------- */
require_once __DIR__ . '/lib/idempotency.php';
sanitize_idempotency_key();
idempo_claim_or_fail($conn, 'upload_material_foto'); // responde si ya existe para esa key

/* ---------------- Inputs ---------------- */
$idCampana    = post_int('idCampana', 0);          // id_formulario
$idLocal      = post_int('idLocal', 0);
$division_id  = post_int('division_id', 0);        // puede venir vacío → se resuelve
$visita_id    = post_int('visita_id', 0);          // puede venir 0 → fallback
$idFQ         = post_int('idFQ', 0);               // formularioQuestion.id
$fotoLat      = post_float('lat', 0.0);
$fotoLng      = post_float('lng', 0.0);
$client_guid  = post_str('client_guid', '');       // para reconciliar visitas offline

if ($idCampana<=0 || $idLocal<=0 || $idFQ<=0) {
  json_fail(400, 'Parámetros insuficientes (falta campaña/local/FQ)');
}
if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
  json_fail(400, 'No se recibió la imagen o hubo un error al subirla');
}

/* ---------------- Permisos: FQ pertenece a usuario/empresa/local/formulario ---------------- */
$perm_ok = false; $fq_form = 0; $matName = '';
if ($st = $conn->prepare("
  SELECT f.id AS id_formulario, fq.material
    FROM formularioQuestion fq
    JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
   WHERE fq.id = ? AND fq.id_local = ? AND fq.id_usuario = ?
   LIMIT 1
")) {
  $st->bind_param('iiii', $empresa_id, $idFQ, $idLocal, $usuario_id);
  if ($st->execute()) {
    $st->bind_result($fq_form, $matName);
    if ($st->fetch()) { $perm_ok = true; }
  }
  $st->close();
}
if (!$perm_ok) { json_fail(403, 'Sin permiso sobre este material/local o no existe'); }
if ($fq_form !== $idCampana) { json_fail(403, 'El FQ no pertenece a la campaña indicada'); }

/* ---------------- Resolver división si no vino ---------------- */
if ($division_id <= 0) {
  if ($q = $conn->prepare("SELECT id_division FROM formulario WHERE id=? AND id_empresa=? LIMIT 1")) {
    $q->bind_param('ii', $idCampana, $empresa_id);
    $q->execute();
    $q->bind_result($division_id);
    $q->fetch();
    $q->close();
  }
  if ($division_id <= 0) { json_fail(400, 'No fue posible resolver la división'); }
}

/* ---------------- Material.id por (nombre, división) ---------------- */
$idMaterial = 0;
if ($m = $conn->prepare("SELECT id FROM material WHERE nombre=? AND id_division=? LIMIT 1")) {
  $m->bind_param('si', $matName, $division_id);
  $m->execute();
  $m->bind_result($idMaterial);
  if (!$m->fetch()) {
    $m->close();
    if ($ins = $conn->prepare("INSERT INTO material (nombre,id_division) VALUES (?,?)")) {
      $ins->bind_param('si', $matName, $division_id);
      if (!$ins->execute()) { $ins->close(); json_fail(500, 'No se pudo crear el material para esta división'); }
      $idMaterial = (int)$ins->insert_id;
      $ins->close();
    }
  } else { $m->close(); }
}
if ($idMaterial <= 0) { json_fail(500, 'No se pudo determinar el material'); }

/* ---------------- Resolver/crear VISITA si visita_id <= 0 (fallback offline) ---------------- */
if ($visita_id <= 0) {
  // 1) Reusar por client_guid abierta
  if ($client_guid !== '') {
    if ($q = $conn->prepare("
      SELECT id, fecha_fin FROM visita
      WHERE id_usuario=? AND id_formulario=? AND id_local=? AND client_guid=? LIMIT 1
    ")) {
      $q->bind_param('iiis', $usuario_id, $idCampana, $idLocal, $client_guid);
      $q->execute();
      $q->bind_result($rid, $rfin);
      if ($q->fetch() && (empty($rfin) || $rfin==='0000-00-00 00:00:00')) { $visita_id = (int)$rid; }
      $q->close();
    }
  }
  // 2) Reusar abierta genérica
  if ($visita_id <= 0) {
    if ($q2 = $conn->prepare("
      SELECT id FROM visita
      WHERE id_usuario=? AND id_formulario=? AND id_local=?
        AND (fecha_fin IS NULL OR fecha_fin='0000-00-00 00:00:00')
      ORDER BY id DESC LIMIT 1
    ")) {
      $q2->bind_param('iii', $usuario_id, $idCampana, $idLocal);
      $q2->execute();
      $q2->bind_result($rid2);
      if ($q2->fetch()) { $visita_id = (int)$rid2; }
      $q2->close();
    }
  }
  // 3) Crear nueva
  if ($visita_id <= 0) {
    if ($client_guid === '') { $client_guid = bin2hex(random_bytes(16)); }
    if ($ins = $conn->prepare("
      INSERT INTO visita
        (id_usuario, id_formulario, id_local, client_guid, fecha_inicio, latitud, longitud)
      VALUES (?, ?, ?, ?, NOW(), ?, ?)
    ")) {
      // i i i s d d
      $ins->bind_param('iiisdd', $usuario_id, $idCampana, $idLocal, $client_guid, $fotoLat, $fotoLng);
      if ($ins->execute()) { $visita_id = (int)$ins->insert_id; }
      $ins->close();
    }
    if ($visita_id <= 0) { json_fail(428, 'No fue posible resolver/crear la visita para esta foto'); }
  }
}

/* ---------------- Validar archivo ---------------- */
$f   = $_FILES['foto'];
$tmp = $f['tmp_name'];
$nm  = $f['name'];
$sz  = (int)$f['size'];
if ($sz <= 0) json_fail(400, 'Archivo vacío');
$mime = @mime_content_type($tmp) ?: ($f['type'] ?? '');
$ext  = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
$looksImage = (strpos((string)$mime,'image/') === 0) || in_array($ext, ['heic','heif'], true);
if (!$looksImage) json_fail(400, 'El archivo no parece una imagen válida');
if ($sz > 20*1024*1024) json_fail(413, 'La imagen excede 20MB');

/* ---------------- Utilidad de conversión ---------------- */
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
    } catch (Throwable $e) { error_log('[WEBP/Imagick] '.$e->getMessage()); }
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
          if     ($exif['Orientation'] == 3) $im = imagerotate($im, 180, 0);
          elseif ($exif['Orientation'] == 6) $im = imagerotate($im, -90, 0);
          elseif ($exif['Orientation'] == 8) $im = imagerotate($im, 90, 0);
        }
      }
      break;
    case 'image/png':
      $im = @imagecreatefrompng($srcPath);
      if ($im) { imagepalettetotruecolor($im); imagealphablending($im, true); imagesavealpha($im, true); }
      break;
    case 'image/webp':
      if (function_exists('imagecreatefromwebp')) { $im = @imagecreatefromwebp($srcPath); }
      else { return @copy($srcPath, $dstPath); }
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
  $ok = function_exists('imagewebp') ? @imagewebp($im, $dstPath, $quality) : @imagejpeg($im, $dstPath, 85);
  imagedestroy($im);
  return (bool)$ok;
}

/* ---------------- Directorios destino ---------------- */
$hoy      = date('Y-m-d');
$dirBase  = __DIR__ . '/uploads/';
$dirFecha = $dirBase . $hoy . '/';
$dirMat   = $dirFecha . 'material_' . $idMaterial . '/';

foreach ([$dirBase, $dirFecha, $dirMat] as $d) {
  if (!is_dir($d) && !mkdir($d, 0755, true)) { json_fail(500, 'No se pudo preparar el directorio de subida'); }
}

/* ---------------- Convertir y guardar (con fallback si falla WebP) ---------------- */
$filenameBase = 'foto_' . uniqid('', true);
$destWebp     = $dirMat . $filenameBase . '.webp';
$savedPath    = null;   // pista del archivo realmente guardado
$relUrl       = '';

if (!convertToWebP($tmp, $destWebp, 1280, 80)) {
  // Guardar original (sin imprimir warnings)
  $ext = strtolower($ext ?: 'jpg');
  if (!in_array($ext, ['jpg','jpeg','png','webp','heic','heif'], true)) { $ext = 'jpg'; }
  $destOrig = $dirMat . $filenameBase . '.' . $ext;
  if (!@move_uploaded_file($tmp, $destOrig)) {
    if (!@copy($tmp, $destOrig)) { json_fail(500, 'No se pudo guardar la imagen (fallback)'); }
  }
  $savedPath = $destOrig;
} else {
  $savedPath = $destWebp;
}

// URL relativa normalizada SIEMPRE 'uploads/...'
$relUrl = norm_rel_url('uploads/' . $hoy . '/material_' . $idMaterial . '/' . basename($savedPath));
// URL absoluta pública (con base de app)
$absUrl = abs_url($relUrl);

/* ---------------- Metadatos del front (EXIF/otros) ---------------- */
$captureSource = strtolower((string)post_str('capture_source', 'unknown'));
if (!in_array($captureSource, ['camera','gallery','unknown'], true)) $captureSource = 'unknown';

$exif_data = [
  'exif_datetime'      => normalize_datetime(post_str('exif_datetime', null)),
  'exif_lat'           => post_float('exif_lat', null),
  'exif_lng'           => post_float('exif_lng', null),
  'exif_altitude'      => post_float('exif_altitude', null),
  'exif_img_direction' => post_float('exif_img_direction', null),
  'exif_make'          => post_str('exif_make', null),
  'exif_model'         => post_str('exif_model', null),
  'exif_software'      => post_str('exif_software', null),
  'exif_lens_model'    => post_str('exif_lens_model', null),
  'exif_fnumber'       => post_float('exif_fnumber', null),
  'exif_exposure_time' => post_str('exif_exposure_time', null),
  'exif_iso'           => post_int('exif_iso', null),
  'exif_focal_length'  => post_float('exif_focal_length', null),
  'exif_orientation'   => post_int('exif_orientation', null),
  'capture_source'     => $captureSource,
  'meta_json'          => null
];
$meta_json_raw = post_str('meta_json', null);
if ($meta_json_raw !== null && $meta_json_raw !== '') {
  $decoded = json_decode($meta_json_raw, true);
  if (json_last_error() === JSON_ERROR_NONE) {
    // Inyecta coords de la app por conveniencia
    $decoded['_app_coords'] = ['lat'=>$fotoLat, 'lng'=>$fotoLng];
    $exif_data['meta_json'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
  }
}

/* ---------------- Persistencia ---------------- */
$conn->begin_transaction();
try {
  // 1) Insert en fotoVisita (URL ABSOLUTA para consistencia con procesar_gestion)
  $stmt = $conn->prepare("
    INSERT INTO fotoVisita
      (visita_id, url, id_usuario, id_formulario, id_local, id_material, id_formularioQuestion, fotoLat, fotoLng)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) throw new Exception('Prep fotoVisita: '.$conn->error);
  $stmt->bind_param(
    'isiiiiidd',
    $visita_id, $relUrl, $usuario_id, $idCampana, $idLocal, $idMaterial, $idFQ, $fotoLat, $fotoLng
  );
  if (!$stmt->execute()) throw new Exception('Exec fotoVisita: '.$stmt->error);
  $idFoto = (int)$stmt->insert_id;
  $stmt->close();

  // 2) EXIF/meta si existe tabla; si no, sidecar junto al archivo REAL
  $has_meta_table = false;
  try {
    $chk = $conn->query("DESCRIBE fotoVisita_meta");
    if ($chk && $chk->num_rows > 0) $has_meta_table = true;
    if ($chk) $chk->close();
  } catch (Throwable $e) { $has_meta_table = false; }

  if ($has_meta_table) {
    $stmt = $conn->prepare("
      INSERT INTO fotoVisita_meta
        (id_foto, exif_datetime, exif_lat, exif_lng, exif_altitude, exif_img_direction,
         exif_make, exif_model, exif_software, exif_lens_model, exif_fnumber,
         exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
         capture_source, raw_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if ($stmt) {
      $stmt->bind_param(
        "isddddssssdsidiss",
        $idFoto,
        $exif_data['exif_datetime'],
        $exif_data['exif_lat'],
        $exif_data['exif_lng'],
        $exif_data['exif_altitude'],
        $exif_data['exif_img_direction'],
        $exif_data['exif_make'],
        $exif_data['exif_model'],
        $exif_data['exif_software'],
        $exif_data['exif_lens_model'],
        $exif_data['exif_fnumber'],
        $exif_data['exif_exposure_time'],
        $exif_data['exif_iso'],
        $exif_data['exif_focal_length'],
        $exif_data['exif_orientation'],
        $exif_data['capture_source'],
        $exif_data['meta_json']
      );
      if (!$stmt->execute()) { error_log('[EXIF] fotoVisita_meta: '.$stmt->error); }
      $stmt->close();
    }
  } else {
    @file_put_contents(
      ($savedPath . '.json'),
      json_encode([
        'id_foto'              => $idFoto,
        'url'                  => $relUrl,
        'abs_url'              => $absUrl,
        'id_usuario'           => $usuario_id,
        'id_formulario'        => $idCampana,
        'id_local'             => $idLocal,
        'id_material'          => $idMaterial,
        'id_formularioQuestion'=> $idFQ,
        'fotoLat'              => $fotoLat,
        'fotoLng'              => $fotoLng,
        'exif'                 => $exif_data,
        'saved_at'             => date('c')
      ], JSON_UNESCAPED_UNICODE)
    );
  }

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  if ($savedPath && is_file($savedPath)) @unlink($savedPath);
  if ($savedPath && is_file($savedPath.'.json')) @unlink($savedPath.'.json');
  error_log('upload_material_foto_pruebas.php: '.$e->getMessage());
  json_fail(500, 'No se pudo procesar la foto: '.$e->getMessage());
}

/* ---------------- Responder (idempotente) ---------------- */
idempo_store_and_reply($conn, 'upload_material_foto', 200, [
  'status'    => 'success',
  'url'       => $absUrl,     // principal ABSOLUTA (coincide con lo que almacenamos)
  'relative'  => $relUrl,     // por compatibilidad
  'absolute'  => $absUrl,     // alias histórico
  'id_foto'   => $idFoto,
  'idMat'     => $idMaterial,
  'idFQ'      => $idFQ,
  'visita_id' => $visita_id
]);
exit;
<?php

declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const APP_BASE = '/visibility2/app';


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
  echo json_encode(['ok'=>false,'status'=>'error','message'=>$msg] + $extra, JSON_UNESCAPED_UNICODE);
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
function table_has_col(mysqli $c, string $table, string $col): bool {
  try {
    $res = $c->query("SHOW COLUMNS FROM `$table` LIKE '".$c->real_escape_string($col)."'");
    if ($res) { $ok = $res->num_rows > 0; $res->close(); return $ok; }
  } catch (Throwable $e) {}
  return false;
}

/* ---------------- Utilidades de imagen ---------------- */
function convertirAWebP($sourcePath, $destPath, $quality = 80) {
  if (class_exists('Imagick')) {
    try {
      $img = new Imagick($sourcePath);
      if ($img->getNumberImages() > 1) { $img = $img->coalesceImages(); }
      if (method_exists($img,'autoOrient')) { @$img->autoOrient(); }
      $img->setImageFormat('webp');
      $img->setImageCompressionQuality($quality);
      $ok = @$img->writeImages($destPath, true);
      $img->clear(); $img->destroy();
      if ($ok) return true;
    } catch (Throwable $e) {}
  }
  $info = @getimagesize($sourcePath); if (!$info) return false;
  $mime = $info['mime'] ?? '';
  switch ($mime) {
    case 'image/jpeg': $image=@imagecreatefromjpeg($sourcePath); break;
    case 'image/png':  $image=@imagecreatefrompng($sourcePath); if ($image){@imagepalettetotruecolor($image);@imagealphablending($image,true);@imagesavealpha($image,true);} break;
    case 'image/gif':  $image=@imagecreatefromgif($sourcePath); break;
    case 'image/webp': return @copy($sourcePath,$destPath);
    default: return false;
  }
  if (!$image) return false;
  $ok=(function_exists('imagewebp') && @imagewebp($image,$destPath,$quality));
  imagedestroy($image);
  return (bool)$ok;
}
function guardarFotoEstado(array $file, string $subdir, string $prefix, int $idLocal): array {
  if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error de subida de imagen ($prefix).");
  $baseDir = __DIR__ . "/uploads/" . trim($subdir, "/") . "/";
  if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) throw new Exception("No se pudo crear el directorio de imágenes ($subdir).");
  $tmp = $file['tmp_name']; $origName=$file['name']; $mime=@mime_content_type($tmp);
  if (in_array($mime, ['image/heic','image/heif'], true)) {
    $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'heic';
    $filename = "{$prefix}{$idLocal}_".uniqid('',true).".{$ext}";
    $destino  = $baseDir.$filename;
    if (!move_uploaded_file($tmp,$destino)) throw new Exception("No se pudo guardar archivo HEIC/HEIF ($prefix).");
  } else {
    $filename = "{$prefix}{$idLocal}_".uniqid('',true).".webp";
    $destino  = $baseDir.$filename;
    if (!convertirAWebP($tmp,$destino,80)) throw new Exception("No se pudo convertir la imagen a WebP ($prefix).");
  }
  $url = "/visibility2/app/uploads/".trim($subdir,"/")."/".$filename;
  return ['url'=>$url,'path'=>$destino];
}

/* ---------------- CORS / OPTIONS ---------------- */
allow_cors_and_options();

/* ---------------- Seguridad básica ---------------- */
if (!isset($_SESSION['usuario_id'])) { json_fail(401, 'Sesión no iniciada', ['error_code' => 'NO_SESSION', 'retryable' => false]); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Allow: POST, OPTIONS');
  json_fail(405, 'Método inválido', ['error_code' => 'METHOD_NOT_ALLOWED', 'retryable' => false]);
}
$csrf = read_csrf();
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  json_fail(419, 'CSRF inválido o ausente', ['error_code' => 'CSRF_INVALID', 'retryable' => false]);
}
$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

if (getenv('V2_TEST_MODE') === '1') {
  echo json_encode([
    'ok' => true,
    'status' => 'success',
    'url' => APP_BASE . '/uploads/test_estado.jpg',
    'relative' => 'uploads/test_estado.jpg',
    'absolute' => APP_BASE . '/uploads/test_estado.jpg',
    'foto_visita_id' => 1,
    'visita_id' => 999
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- DB ---------------- */
/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/con_.php';
  if (!isset($conn) || !($conn instanceof mysqli)) { json_fail(500, 'Sin conexión a BD', ['error_code' => 'DB_ERROR', 'retryable' => true]); }
}
@$conn->set_charset('utf8mb4');

/* ---------------- Idempotencia ---------------- */
require_once __DIR__ . '/lib/idempotency.php';
sanitize_idempotency_key();
idempo_claim_or_fail($conn, 'upload_estado_foto');

/* ---------------- Inputs ---------------- */
$idCampana    = post_int('id_formulario', 0);
if ($idCampana <= 0) { $idCampana = post_int('idCampana', 0); }
$idLocal      = post_int('id_local', 0);
if ($idLocal <= 0) { $idLocal = post_int('idLocal', 0); }
$visita_id    = post_int('visita_id', 0);
$client_guid  = post_str('client_guid', '');
$estado       = strtolower((string)post_str('estado_tipo', ''));
if ($estado === '') { $estado = strtolower((string)post_str('estado', '')); }
$fotoLat      = post_float('lat_foto', 0.0);
if ($fotoLat === 0.0) { $fotoLat = post_float('lat', 0.0); }
$fotoLng      = post_float('lng_foto', 0.0);
if ($fotoLng === 0.0) { $fotoLng = post_float('lng', 0.0); }
$debug_id     = post_str('debug_id', '');

if ($idCampana<=0 || $idLocal<=0) {
  json_fail(400, 'Parámetros insuficientes (falta campaña/local)', ['error_code' => 'VALIDATION', 'retryable' => false]);
}
if (!in_array($estado, ['pendiente','cancelado'], true)) {
  json_fail(400, 'Estado inválido para foto de evidencia', ['error_code' => 'VALIDATION', 'retryable' => false]);
}
if ((!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
  && (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK)
) {
  json_fail(400, 'No se recibió la imagen o hubo un error al subirla', ['error_code' => 'UPLOAD_ERROR', 'retryable' => false]);
}

/* ---------------- Permisos: local/campaña pertenecen a usuario/empresa ---------------- */
$perm_ok = false;
if ($st = $conn->prepare("\
  SELECT f.id\n    FROM formularioQuestion fq\n    JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?\n   WHERE fq.id_formulario = ? AND fq.id_local = ? AND fq.id_usuario = ?\n   LIMIT 1\n")) {
  $st->bind_param('iiii', $empresa_id, $idCampana, $idLocal, $usuario_id);
  if ($st->execute()) {
    $st->store_result();
    $perm_ok = ($st->num_rows > 0);
  }
  $st->close();
}
if (!$perm_ok) { json_fail(403, 'Sin permiso sobre este local/campaña', ['error_code' => 'FORBIDDEN', 'retryable' => false]); }

/* ---------------- Resolver/crear VISITA si visita_id <= 0 (fallback offline) ---------------- */
if ($visita_id <= 0) {
  // 1) Reusar por client_guid abierta
  if ($client_guid !== '') {
    if ($q = $conn->prepare("\
      SELECT id, fecha_fin FROM visita\n      WHERE id_usuario=? AND id_formulario=? AND id_local=? AND client_guid=? LIMIT 1\n    ")) {
      $q->bind_param('iiis', $usuario_id, $idCampana, $idLocal, $client_guid);
      $q->execute();
      $q->bind_result($rid, $rfin);
      if ($q->fetch() && (empty($rfin) || $rfin==='0000-00-00 00:00:00')) { $visita_id = (int)$rid; }
      $q->close();
    }
  }
  // 2) Reusar abierta genérica
  if ($visita_id <= 0) {
    if ($q2 = $conn->prepare("\
      SELECT id FROM visita\n      WHERE id_usuario=? AND id_formulario=? AND id_local=?\n        AND (fecha_fin IS NULL OR fecha_fin='0000-00-00 00:00:00')\n      ORDER BY id DESC LIMIT 1\n    ")) {
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
    if ($ins = $conn->prepare("\
      INSERT INTO visita (id_usuario,id_formulario,id_local,fecha_inicio,latitud,longitud,client_guid)\n      VALUES (?,?,?,?,?,?,?)\n    ")) {
      $now = date('Y-m-d H:i:s');
      $ins->bind_param('iiisdds', $usuario_id, $idCampana, $idLocal, $now, $fotoLat, $fotoLng, $client_guid);
      if ($ins->execute()) { $visita_id = (int)$ins->insert_id; }
      $ins->close();
    }
  }
}
if ($visita_id <= 0) {
  json_fail(422, 'No se pudo resolver la visita para la foto', ['error_code' => 'VISITA_NOT_FOUND', 'retryable' => true]);
}

/* ---------------- Metadatos/EXIF ---------------- */
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
    $decoded['_app_coords'] = ['lat'=>$fotoLat, 'lng'=>$fotoLng];
    $exif_data['meta_json'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
  }
}

/* ---------------- Guardado de imagen ---------------- */
$kind = $estado === 'pendiente' ? 'estado_pendiente' : 'estado_cancelado';
$prefix = $estado === 'pendiente' ? 'pendiente_' : 'cancelado_';
$savedPath = null;
$dateDir = date('Y-m-d');
$subdir = $dateDir . '/estado_' . $estado . '/local_' . $idLocal;
try {
  $file = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK ? $_FILES['file'] : $_FILES['foto'];
  $up = guardarFotoEstado($file, $subdir, $prefix, $idLocal);
  $savedPath = $up['path'];
  $relUrl = norm_rel_url($up['url']);
  $absUrl = abs_url($relUrl);
} catch (Throwable $e) {
  json_fail(500, 'No se pudo guardar la imagen: '.$e->getMessage(), ['error_code' => 'UPLOAD_ERROR', 'retryable' => true]);
}

$sha1 = @sha1_file($savedPath) ?: '';
$size = @filesize($savedPath) ?: 0;

/* ---------------- Persistencia ---------------- */
$conn->begin_transaction();
try {
  $FV_HAS_KIND = table_has_col($conn, 'fotoVisita', 'kind');
  $FV_HAS_SHA1 = table_has_col($conn, 'fotoVisita', 'sha1');
  $FV_HAS_SIZE = table_has_col($conn, 'fotoVisita', 'size');

  $cols = [
    'visita_id',
    'url',
    'id_usuario',
    'id_formulario',
    'id_local',
    'id_material',
    'id_formularioQuestion',
    'fotoLat',
    'fotoLng'
  ];
  $ph   = ['?','?','?','?','?','NULL','NULL','?','?'];
  $types = 'isiiidd';
  $args  = [$visita_id, $relUrl, $usuario_id, $idCampana, $idLocal, $fotoLat, $fotoLng];

  if ($FV_HAS_KIND) { $cols[] = 'kind'; $ph[] = '?'; $types .= 's'; $args[] = $kind; }
  if ($FV_HAS_SHA1) { $cols[] = 'sha1'; $ph[] = '?'; $types .= 's'; $args[] = $sha1; }
  if ($FV_HAS_SIZE) { $cols[] = 'size'; $ph[] = '?'; $types .= 'i'; $args[] = (int)$size; }

  $sql = "INSERT INTO fotoVisita (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('Prep fotoVisita: '.$conn->error);

  $refs = []; $refs[] = $types; foreach ($args as &$v) { $refs[] = &$v; }
  if (!@$stmt->bind_param(...$refs)) { $stmt->close(); throw new Exception('Bind fotoVisita: '.$conn->error); }
  if (!$stmt->execute()) throw new Exception('Exec fotoVisita: '.$stmt->error);
  $idFoto = (int)$stmt->insert_id;
  $stmt->close();

  // EXIF/meta si existe tabla; si no, sidecar junto al archivo REAL
  $has_meta_table = false;
  try {
    $chk = $conn->query("DESCRIBE fotoVisita_meta");
    if ($chk && $chk->num_rows > 0) $has_meta_table = true;
    if ($chk) $chk->close();
  } catch (Throwable $e) { $has_meta_table = false; }

  $meta_payload = [
    'kind' => $kind,
    'estado_tipo' => $estado,
    'debug_id' => $debug_id,
    'sha1' => $sha1,
    'size' => $size
  ];

  if ($has_meta_table) {
    $stmt = $conn->prepare("\
      INSERT INTO fotoVisita_meta
        (id_foto, exif_datetime, exif_lat, exif_lng, exif_altitude, exif_img_direction,
         exif_make, exif_model, exif_software, exif_lens_model, exif_fnumber,
         exif_exposure_time, exif_iso, exif_focal_length, exif_orientation,
         capture_source, raw_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    if ($stmt) {
      $rawJson = $exif_data['meta_json'];
      if (!$rawJson) {
        $rawJson = json_encode($meta_payload, JSON_UNESCAPED_UNICODE);
      } else {
        $rawJson = json_encode(array_merge($meta_payload, json_decode($rawJson, true) ?: []), JSON_UNESCAPED_UNICODE);
      }
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
        $rawJson
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
        'kind'                 => $kind,
        'estado_tipo'          => $estado,
        'fotoLat'              => $fotoLat,
        'fotoLng'              => $fotoLng,
        'sha1'                 => $sha1,
        'size'                 => $size,
        'exif'                 => $exif_data,
        'debug_id'             => $debug_id,
        'saved_at'             => date('c')
      ], JSON_UNESCAPED_UNICODE)
    );
  }

  $conn->commit();

} catch (Throwable $e) {
  $conn->rollback();
  if ($savedPath && is_file($savedPath)) @unlink($savedPath);
  if ($savedPath && is_file($savedPath.'.json')) @unlink($savedPath.'.json');
  error_log('upload_estado_foto_pruebas.php: '.$e->getMessage());
  json_fail(500, 'No se pudo procesar la foto: '.$e->getMessage(), ['error_code' => 'UPLOAD_ERROR', 'retryable' => true]);
}

/* ---------------- Responder (idempotente) ---------------- */
idempo_store_and_reply($conn, 'upload_estado_foto', 200, [
  'ok'        => true,
  'status'    => 'success',
  'url'       => $absUrl,
  'relative'  => $relUrl,
  'absolute'  => $absUrl,
  'foto_visita_id' => $idFoto,
  'kind'      => $kind,
  'sha1'      => $sha1,
  'size'      => $size,
  'visita_id' => $visita_id,
  'client_guid' => $client_guid,
  'debug_id'  => $debug_id
]);
exit;
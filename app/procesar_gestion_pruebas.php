<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function wants_json(): bool {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  return (stripos($accept, 'application/json') !== false)
      || ($xhr === 'XMLHttpRequest')
      || isset($_POST['return_json'])
      || isset($_SERVER['HTTP_X_OFFLINE_QUEUE']);
}
function respond_ok(array $payload, ?string $redirect = null) {
  if (wants_json()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'status'=>'success'] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($redirect) { header("Location: ".$redirect); exit; }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>true,'status'=>'success'] + $payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function respond_err(string $msg, int $http = 400, ?string $redirect = null) {
  if (wants_json()) {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    $retryable = ($http >= 500 || in_array($http, [408, 429], true));
    $code = $http === 401 ? 'NO_SESSION' : ($http === 419 ? 'CSRF_INVALID' : ($http === 403 ? 'FORBIDDEN' : 'ERROR'));
    echo json_encode(['ok'=>false,'status'=>'error','message'=>$msg,'error_code'=>$code,'retryable'=>$retryable], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($redirect) {
    $redir = $redirect.(str_contains($redirect,'?')?'&':'?').'status=error&mensaje='.urlencode($msg);
    header("Location: ".$redir); exit;
  }
  http_response_code($http);
  header('Content-Type: application/json; charset=utf-8');
  $retryable = ($http >= 500 || in_array($http, [408, 429], true));
  $code = $http === 401 ? 'NO_SESSION' : ($http === 419 ? 'CSRF_INVALID' : ($http === 403 ? 'FORBIDDEN' : 'ERROR'));
  echo json_encode(['ok'=>false,'status'=>'error','message'=>$msg,'error_code'=>$code,'retryable'=>$retryable], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------------- Seguridad base ---------------- */
if (!isset($_SESSION['usuario_id'])) { if (wants_json()) respond_err('Sesión expirada',401); header("Location: login.php"); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { if (wants_json()) respond_err('Método inválido',405); header("Location: index.php"); exit; }
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
  respond_err('Token CSRF inválido.',419);
}

if (getenv('V2_TEST_MODE') === '1') {
  respond_ok([
    'visita_id' => 999,
    'estado_final' => 'completa',
    'status' => 'success'
  ]);
}

/* ---------------- Conexión ---------------- */
/** @var mysqli $conn */
if (!isset($conn) || !($conn instanceof mysqli)) { require_once __DIR__ . '/con_.php'; }
if (!isset($conn) || !($conn instanceof mysqli)) { respond_err('No hay conexión a BD',500); }
@$conn->set_charset('utf8mb4');

/* ---------------- Idempotencia ---------------- */
require_once __DIR__ . '/lib/idempotency.php';
if (function_exists('idempo_claim_or_fail')) { idempo_claim_or_fail($conn, 'procesar_gestion'); }

/* ---------------- Utilidades columnas opcionales ---------------- */
function table_has_col(mysqli $c, string $table, string $col): bool {
  try {
    $res = $c->query("SHOW COLUMNS FROM `$table` LIKE '".$c->real_escape_string($col)."'");
    if ($res) { $ok = $res->num_rows > 0; $res->close(); return $ok; }
  } catch (Throwable $e) {}
  return false;
}
$FQR_HAS_CREATED_AT   = table_has_col($conn, 'form_question_responses', 'created_at');
$FQR_HAS_VALOR        = table_has_col($conn, 'form_question_responses', 'valor');
$FQR_HAS_FOTO_VISITA  = table_has_col($conn, 'form_question_responses', 'foto_visita_id');
$GV_HAS_FOTO_ESTADO   = table_has_col($conn, 'gestion_visita', 'foto_visita_id_estado');

function insert_fqr(
  mysqli $conn, array $flags, int $visita_id, int $id_form_question, int $id_local, int $id_usuario,
  string $answer_text, int $id_option, float $valor, ?int $foto_visita_id, string $now
) {
  $cols  = ['visita_id','id_form_question','id_local','id_usuario','answer_text','id_option'];
  $ph    = ['?','?','?','?','?','?'];
  $types = 'iiiisi';
  $args  = [$visita_id,$id_form_question,$id_local,$id_usuario,$answer_text,$id_option];

  if ($flags['created_at']) { $cols[]='created_at'; $ph[]='?'; $types.='s'; $args[]=$now; }
  if ($flags['valor'])      { $cols[]='valor';      $ph[]='?'; $types.='d'; $args[]=$valor; }

  $fotoExpr = null;
  if ($flags['foto_visita_id']) {
    $cols[] = 'foto_visita_id';
    if ($foto_visita_id === null) { $fotoExpr = 'NULL'; } else { $ph[]='?'; $types.='i'; $args[]=$foto_visita_id; }
  }

  $values = implode(',', $ph);
  if ($fotoExpr !== null) { $values .= ($fotoExpr==='NULL') ? ',NULL' : ',?'; }

  $sql = "INSERT INTO form_question_responses (".implode(',', $cols).") VALUES ($values)";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('Prep insert FQR: '.$conn->error);

  if ($types !== '') {
    $refs = []; $refs[] = $types; foreach ($args as &$v) { $refs[] = &$v; }
    if (!@$stmt->bind_param(...$refs)) { $stmt->close(); throw new Exception('Bind FQR: '.$conn->error); }
  }
  if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); throw new Exception('Insert FQR: '.$err); }
  $id = $stmt->insert_id; $stmt->close();
  return $id;
}

/* ---------------- Params ---------------- */
date_default_timezone_set('America/Santiago');
$now = date('Y-m-d H:i:s');

$division_id   = isset($_POST['division_id']) ? intval($_POST['division_id']) : 0;
$estadoGestion = isset($_POST['estadoGestion']) ? strtolower(trim((string)$_POST['estadoGestion'])) : '';
$motivo        = isset($_POST['motivo']) ? trim((string)$_POST['motivo']) : '';
$comentario    = isset($_POST['comentario']) ? trim((string)$_POST['comentario']) : '';

$valores       = (isset($_POST['valor']) && is_array($_POST['valor'])) ? $_POST['valor'] : [];
$observaciones = (isset($_POST['observacion']) && is_array($_POST['observacion'])) ? $_POST['observacion'] : [];
$motivoSelect  = (isset($_POST['motivoSelect']) && is_array($_POST['motivoSelect'])) ? $_POST['motivoSelect'] : [];
$motivoNoImpl  = (isset($_POST['motivoNoImplementado']) && is_array($_POST['motivoNoImplementado'])) ? $_POST['motivoNoImplementado'] : [];

$idCampana     = isset($_POST['idCampana']) ? intval($_POST['idCampana']) : 0;
$nombreCampana = isset($_POST['nombreCampana']) ? trim((string)$_POST['nombreCampana']) : '';
$idLocal       = isset($_POST['idLocal']) ? intval($_POST['idLocal']) : 0;

$usuario_id    = intval($_SESSION['usuario_id']);
$empresa_id    = intval($_SESSION['empresa_id']);

$latitudLocal  = isset($_POST['latitudLocal']) ? floatval($_POST['latitudLocal']) : 0.0;
$longitudLocal = isset($_POST['longitudLocal']) ? floatval($_POST['longitudLocal']) : 0.0;

$latGestion    = isset($_POST['latGestion']) ? floatval($_POST['latGestion']) : 0.0;
$lngGestion    = isset($_POST['lngGestion']) ? floatval($_POST['lngGestion']) : 0.0;

$client_guid   = isset($_POST['client_guid']) ? trim((string)$_POST['client_guid']) : '';
$started_at    = isset($_POST['started_at']) ? trim((string)$_POST['started_at']) : '';
$fotoVisitaEstadoId = isset($_POST['foto_visita_id_estado']) ? intval($_POST['foto_visita_id_estado']) : 0;

/* --- Normalizar estadoGestion (acepta alias del front) --- */
$estado_raw = $estadoGestion;
switch ($estadoGestion) {
  case 'completa':
  case 'finalizada':
  case 'finalizado':
  case 'ok':
  case 'terminado':
  case 'implementado':
  case 'implementado_y_auditado':
    $estadoGestion = 'implementado_auditado'; break;
  case 'auditoria':
    $estadoGestion = 'solo_auditoria'; break;
  // 'pendiente' / 'cancelado' quedan tal cual
}

/* ---------------- Validaciones previas ---------------- */
$errores = [];
if ($idCampana <= 0 || $idLocal <= 0) { $errores[] = "Parámetros de campaña o local inválidos."; }
if ($estadoGestion === '') { $errores[] = "El estado de gestión es obligatorio."; }
if (($estadoGestion === 'pendiente' || $estadoGestion === 'cancelado') && $motivo === '') {
  $errores[] = "El motivo es obligatorio para estados Pendiente o Cancelado.";
}
if (!empty($errores)) {
  respond_err(implode(" ", $errores), 422,
    "gestionarPruebas.php?idCampana=$idCampana&nombreCampana=".urlencode($nombreCampana)."&idLocal=$idLocal"
  );
}

/* --- Resolver division_id si no vino --- */
if ($division_id <= 0) {
  if ($q = $conn->prepare("SELECT id_division FROM formulario WHERE id=? AND id_empresa=? LIMIT 1")) {
    $q->bind_param('ii', $idCampana, $empresa_id);
    $q->execute(); $q->bind_result($division_id); $q->fetch(); $q->close();
  }
  if ($division_id <= 0) { respond_err('No fue posible resolver la división.', 422); }
}

/* ========================================================
 * Normalizador de URLs de foto y DEDUPE helper
 * ====================================================== */
function normalize_foto_url(string $u): string {
  $u = trim($u);
  if ($u === '') return '';
  // quitar dominio si viene absoluto
  $u = preg_replace('#^https?://[^/]+#i', '', $u) ?: $u;
  // ya normalizada absoluta?
  if (strpos($u, '/visibility2/app/uploads/') === 0) return $u;
  // distintos formatos comunes → absoluta estándar
  if (strpos($u, 'uploads/') === 0) return '/visibility2/app/' . $u;
  if (strpos($u, '/uploads/') === 0) return '/visibility2/app' . $u;
  if (($pos = strpos($u, '/uploads/')) !== false) return '/visibility2/app' . substr($u, $pos);
  return $u;
}

/** Revisa si ya existe una foto (misma visita + FQ + misma URL normalizada) */
function foto_already_linked(mysqli $conn, int $visita_id, int $idFQ, string $url): bool {
  $needle = normalize_foto_url($url);
  $stmt = $conn->prepare("SELECT url FROM fotoVisita WHERE visita_id=? AND id_formularioQuestion=?");
  if (!$stmt) return false;
  $stmt->bind_param("ii", $visita_id, $idFQ);
  $stmt->execute();
  $res = $stmt->get_result();
  $found = false;
  while ($row = $res->fetch_assoc()) {
    $dbNorm = normalize_foto_url((string)$row['url']);
    if ($dbNorm === $needle) { $found = true; break; }
  }
  $stmt->close();
  return $found;
}

/* ---------------- Utilidades de imagen ---------------- */
function convertirAWebP($sourcePath, $destPath, $quality = 80) {
  if (class_exists('Imagick')) {
    try {
      $img = new Imagick($sourcePath);
      if ($img->getNumberImages() > 1) { $img = $img->coalesceImages(); }
      if (method_exists($img,'autoOrient')) { @$img->autoOrient(); }
      $img->setImageFormat('webp'); $img->setImageCompressionQuality($quality);
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
function guardarFotoUnitaria(array $file, string $subdir, string $prefix, int $idLocal): array {
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

function insertarFotoVisitaEstado(
  mysqli $conn,
  int $visita_id,
  int $usuario_id,
  int $idCampana,
  int $idLocal,
  string $url,
  string $path,
  string $estado,
  float $fotoLat,
  float $fotoLng
): ?int {
  $FV_HAS_KIND = table_has_col($conn, 'fotoVisita', 'kind');
  $FV_HAS_SHA1 = table_has_col($conn, 'fotoVisita', 'sha1');
  $FV_HAS_SIZE = table_has_col($conn, 'fotoVisita', 'size');

  $sha1 = is_file($path) ? @sha1_file($path) : '';
  $size = is_file($path) ? @filesize($path) : 0;
  $kind = $estado === 'pendiente' ? 'estado_pendiente' : 'estado_cancelado';

  $cols = [
    'visita_id','url','id_usuario','id_formulario','id_local',
    'id_material','id_formularioQuestion','fotoLat','fotoLng'
  ];
  $ph = ['?','?','?','?','?','NULL','NULL','?','?'];
  $types = 'isiiidd';
  $args = [$visita_id, $url, $usuario_id, $idCampana, $idLocal, $fotoLat, $fotoLng];

  if ($FV_HAS_KIND) { $cols[] = 'kind'; $ph[] = '?'; $types .= 's'; $args[] = $kind; }
  if ($FV_HAS_SHA1) { $cols[] = 'sha1'; $ph[] = '?'; $types .= 's'; $args[] = $sha1; }
  if ($FV_HAS_SIZE) { $cols[] = 'size'; $ph[] = '?'; $types .= 'i'; $args[] = (int)$size; }

  $sql = "INSERT INTO fotoVisita (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('Prep fotoVisita estado: '.$conn->error);
  $refs = []; $refs[] = $types; foreach ($args as &$v) { $refs[] = &$v; }
  if (!@$stmt->bind_param(...$refs)) { $stmt->close(); throw new Exception('Bind fotoVisita estado: '.$conn->error); }
  if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); throw new Exception('Insert fotoVisita estado: '.$err); }
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id > 0 ? $id : null;
}

/* ---------------- Reestructurar fotos materiales (si vienen por este endpoint) ---------------- */
function reestructurarFiles($filePost) {
  $files = [];
  if (!isset($filePost['name']) || !is_array($filePost['name'])) return $files;
  foreach ($filePost['name'] as $idFQ => $fileNames) {
    if (!is_array($fileNames)) continue;
    foreach ($fileNames as $idx => $name) {
      if (!isset($filePost['error'][$idFQ][$idx]) || $filePost['error'][$idFQ][$idx] === UPLOAD_ERR_NO_FILE) continue;
      $files[] = [
        'id_formularioQuestion' => $idFQ,
        'index' => $idx,
        'name' => $filePost['name'][$idFQ][$idx] ?? '',
        'type' => $filePost['type'][$idFQ][$idx] ?? '',
        'tmp_name' => $filePost['tmp_name'][$idFQ][$idx] ?? '',
        'error' => $filePost['error'][$idFQ][$idx] ?? UPLOAD_ERR_NO_FILE,
        'size' => $filePost['size'][$idFQ][$idx] ?? 0
      ];
    }
  }
  return $files;
}

/* ---------------- Historial ---------------- */
function insertGestionVisita(
  mysqli $conn, int $visita_id, int $usuario_id, int $idCampana, int $idLocal, int $idFQ, int $idMaterial,
  string $fechaVisita, string $estadoGestion, int $valorReal, string $observacion, string $motivoNoImpl,
  ?string $fotoUrl=null, ?int $fotoVisitaEstadoId=null, float $fotoLat=0.0, float $fotoLng=0.0, float $latGestion=0.0, float $lngGestion=0.0
) {
  $cols = [
    'visita_id','id_usuario','id_formulario','id_local','id_formularioQuestion','id_material',
    'fecha_visita','estado_gestion','valor_real','observacion','motivo_no_implementacion',
    'foto_url','lat_foto','lng_foto','latitud','longitud'
  ];
  $ph = array_fill(0, count($cols), '?');
  $types = "iiiiiississsdddd";
  $args = [
    $visita_id,$usuario_id,$idCampana,$idLocal,$idFQ,$idMaterial,$fechaVisita,$estadoGestion,
    $valorReal,$observacion,$motivoNoImpl,$fotoUrl,$fotoLat,$fotoLng,$latGestion,$lngGestion
  ];

  if (!empty($GLOBALS['GV_HAS_FOTO_ESTADO'])) {
    $cols[] = 'foto_visita_id_estado';
    if ($fotoVisitaEstadoId === null) {
      $ph[] = 'NULL';
    } else {
      $ph[] = '?';
      $types .= 'i';
      $args[] = $fotoVisitaEstadoId;
    }
  }

  $sql = "INSERT INTO gestion_visita (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('Prep gestion_visita: '.$conn->error);
  if ($types !== '') {
    $refs = []; $refs[] = $types; foreach ($args as &$v) { $refs[] = &$v; }
    if (!@$stmt->bind_param(...$refs)) { $stmt->close(); throw new Exception('Bind gestion_visita: '.$conn->error); }
  }
  if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); throw new Exception('Insert gestion_visita: '.$err); }
  $stmt->close();
}

/* ========================================================
 * A) Resolver visita_id (acepta client_guid)
 * ====================================================== */
$visita_id = 0;
if (isset($_POST['visita_id']) && intval($_POST['visita_id']) > 0) {
  $visita_id = intval($_POST['visita_id']);
} elseif ($client_guid !== '') {
  $q = $conn->prepare("SELECT id FROM visita WHERE client_guid=? AND id_usuario=? AND id_formulario=? AND id_local=? ORDER BY id DESC LIMIT 1");
  if ($q) { $q->bind_param("siii",$client_guid,$usuario_id,$idCampana,$idLocal); $q->execute(); $q->bind_result($found_id); if ($q->fetch()) { $visita_id = intval($found_id); } $q->close(); }
  if ($visita_id <= 0) {
    $fecha_ini = ($started_at && strtotime($started_at)) ? date('Y-m-d H:i:s', strtotime($started_at)) : $now;
    $ins = $conn->prepare("INSERT INTO visita (id_usuario,id_formulario,id_local,fecha_inicio,latitud,longitud,client_guid) VALUES (?,?,?,?,?,?,?)");
    if ($ins) { $ins->bind_param("iiisdds",$usuario_id,$idCampana,$idLocal,$fecha_ini,$latGestion,$lngGestion,$client_guid); if ($ins->execute()) { $visita_id=$ins->insert_id; } $ins->close(); }
  }
}
if ($visita_id <= 0) {
  respond_err("Falta el identificador de visita.", 422,
    "gestionarPruebas.php?idCampana=$idCampana&nombreCampana=".urlencode($nombreCampana)."&idLocal=$idLocal"
  );
}

$fotoVisitaEstadoIdFinal = $fotoVisitaEstadoId > 0 ? $fotoVisitaEstadoId : null;
if ($fotoVisitaEstadoIdFinal) {
  $chk = $conn->prepare("
    SELECT id
      FROM fotoVisita
     WHERE id=? AND id_usuario=? AND id_formulario=? AND id_local=? AND visita_id=?
     LIMIT 1
  ");
  if (!$chk) { respond_err('No se pudo validar la foto de estado.', 500); }
  $chk->bind_param("iiiii", $fotoVisitaEstadoIdFinal, $usuario_id, $idCampana, $idLocal, $visita_id);
  $chk->execute();
  $chk->store_result();
  if ($chk->num_rows === 0) {
    $chk->close();
    respond_err('La foto de estado no es válida para este local/campaña.', 403);
  }
  $chk->close();
}

/* ========================================================
 * B) Subida de fotos de materiales (si llegan aquí)
 * ====================================================== */
$archivosReestructurados = [];
if (isset($_FILES['fotos'])) { $archivosReestructurados = reestructurarFiles($_FILES['fotos']); }
$imagenes = []; $erroresSubida = [];

if (!empty($archivosReestructurados)) {
  foreach ($archivosReestructurados as $archivo) {
    $idFQ = intval($archivo['id_formularioQuestion']);
    $idxFoto = intval($archivo['index']);
    $name = $archivo['name']; $tmpName = $archivo['tmp_name'];
    $errorFile = $archivo['error']; $sizeFile = $archivo['size'];

    if ($errorFile !== UPLOAD_ERR_OK) { $erroresSubida[] = "Error al subir foto '$name' (formularioQuestion=$idFQ)."; continue; }
    $tipoMIME = @mime_content_type($tmpName);
    if (strpos((string)$tipoMIME,'image/') !== 0) { $erroresSubida[] = "El archivo '$name' no es una imagen válida (idFQ=$idFQ)."; continue; }
    if ($sizeFile > 30*1024*1024) { $erroresSubida[] = "La foto '$name' excede 30MB (idFQ=$idFQ)."; continue; }

    // Nombre material desde FQ
    $stmtGetM = $conn->prepare("SELECT material FROM formularioQuestion WHERE id=? LIMIT 1");
    if (!$stmtGetM) { $erroresSubida[] = "Error SELECT material (idFQ=$idFQ): ".$conn->error; continue; }
    $stmtGetM->bind_param("i",$idFQ); $stmtGetM->execute();
    $resGetM = $stmtGetM->get_result(); $matName = $resGetM->fetch_assoc()['material'] ?? null; $stmtGetM->close();
    if (!$matName) { $erroresSubida[] = "No se encontró formularioQuestion.id=$idFQ."; continue; }

    // id material por división
    $stmtCh = $conn->prepare("SELECT id FROM material WHERE nombre=? AND id_division=? LIMIT 1");
    if (!$stmtCh) { $erroresSubida[] = "Error SELECT material: ".$conn->error; continue; }
    $stmtCh->bind_param("si",$matName,$division_id); $stmtCh->execute();
    $rCh = $stmtCh->get_result();
    if ($rCh->num_rows > 0) { $idMaterial = (int)$rCh->fetch_assoc()['id']; $stmtCh->close();
    } else {
      $stmtCh->close();
      $stmtIns = $conn->prepare("INSERT INTO material (nombre,id_division) VALUES (?,?)");
      if (!$stmtIns) { $erroresSubida[] = "Error INSERT material: ".$conn->error; continue; }
      $stmtIns->bind_param("si",$matName,$division_id);
      if (!$stmtIns->execute()) { $erroresSubida[] = "Error insert material '$matName': ".$stmtIns->error; $stmtIns->close(); continue; }
      $idMaterial = $stmtIns->insert_id; $stmtIns->close();
    }

    // Guardar imagen
    $fechaHoy = date('Y-m-d');
    $dirBase  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/visibility2/app/uploads/';
    $dirFecha = $dirBase.$fechaHoy.'/';
    $dirMat   = $dirFecha.'material_'.$idMaterial.'/';
    if (!is_dir($dirMat) && !mkdir($dirMat, 0755, true)) { $erroresSubida[] = "No se pudo crear directorio para material=$idMaterial."; continue; }

    $extOrig = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');
    $baseName = uniqid('foto_', true); $destinoWebp = $dirMat.$baseName.'.webp';

    if (!convertirAWebP($tmpName,$destinoWebp,80)) {
      $destinoOrig = $dirMat.$baseName.'.'.$extOrig;
      if (!@move_uploaded_file($tmpName,$destinoOrig) && !@copy($tmpName,$destinoOrig)) {
        $erroresSubida[] = "Error al guardar imagen (fallback) '$name' (material=$idMaterial)."; continue;
      }
      $rutaRel = "uploads/$fechaHoy/material_".$idMaterial."/".basename($destinoOrig);
    } else {
      $rutaRel = "uploads/$fechaHoy/material_".$idMaterial."/".basename($destinoWebp);
    }

    // Si esa misma URL ya está linkeada, no la programamos para insertar otra vez
    if (foto_already_linked($conn, $visita_id, $idFQ, $rutaRel)) {
      continue;
    }
    $imagenes[] = ['url'=>$rutaRel,'idMat'=>$idMaterial,'idFQ'=>$idFQ,'idx'=>$idxFoto];
  }
}
if (!empty($erroresSubida)) { $errores = array_merge($erroresSubida, $errores); }
if (!empty($errores)) {
  respond_err(implode(" ", $errores), 422,
    "gestionarPruebas.php?idCampana=$idCampana&nombreCampana=".urlencode($nombreCampana)."&idLocal=$idLocal"
  );
}

/* ========================================================
 * C) Transacción principal
 * ====================================================== */
$conn->begin_transaction();

$fechaVisita = $now;
$obsGeneral  = "";
$metrics = ['updates_fq'=>0,'inserts_gv'=>0,'inserts_fqr'=>0,'inserts_fv'=>0,'skipped_fv_dups'=>0];

try {
  /* Permisos */
  $stmtVer = $conn->prepare("SELECT COUNT(*) AS cnt FROM formularioQuestion WHERE id_formulario=? AND id_local=? AND id_usuario=?");
  if (!$stmtVer) throw new Exception("Error verificación: ".$conn->error);
  $stmtVer->bind_param("iii",$idCampana,$idLocal,$usuario_id);
  $stmtVer->execute(); $rowVer = $stmtVer->get_result()->fetch_assoc(); $stmtVer->close();
  if ((int)$rowVer['cnt'] === 0) throw new Exception("No tienes permisos o no existe la campaña/local.");

  /* ----- Estados que tocan formularioQuestion / gestion_visita ----- */

  if (in_array($estadoGestion, ['implementado_auditado','solo_implementado','solo_retirado'], true)) {
    $stmtAll = $conn->prepare("UPDATE formularioQuestion SET fechaVisita=?, countVisita=countVisita+1, pregunta=? WHERE id_formulario=? AND id_local=? AND id_usuario=?");
    if (!$stmtAll) throw new Exception("Error preparando actualización global: ".$conn->error);
    $stmtAll->bind_param("ssiii",$fechaVisita,$estadoGestion,$idCampana,$idLocal,$usuario_id);
    if (!$stmtAll->execute()) throw new Exception("Error actualizando registros globales: ".$stmtAll->error);
    $metrics['updates_fq'] += $stmtAll->affected_rows; $stmtAll->close();

    foreach ($valores as $idFQ => $valorImp) {
      $valorImp = trim((string)$valorImp);
      if ($valorImp === '') continue;
      if (!ctype_digit($valorImp) || intval($valorImp) < 1) { throw new Exception("Valor implementado (ID=$idFQ) debe ser un entero ≥ 1."); }
      $valorNum = intval($valorImp);

      $stmtVal = $conn->prepare("SELECT valor_propuesto FROM formularioQuestion WHERE id=? LIMIT 1");
      $stmtVal->bind_param("i",$idFQ); $stmtVal->execute();
      $rowV = $stmtVal->get_result()->fetch_assoc(); $stmtVal->close();
      if ($rowV && $valorNum > intval($rowV['valor_propuesto'])) { throw new Exception("Valor ($valorNum) excede valor_propuesto ({$rowV['valor_propuesto']}) en ID=$idFQ."); }

      $obs = isset($observaciones[$idFQ]) ? htmlspecialchars(trim((string)$observaciones[$idFQ]), ENT_QUOTES, 'UTF-8') : '';
      $stmtUp = $conn->prepare("UPDATE formularioQuestion SET valor=?, motivo='-', observacion=?, latGestion=?, lngGestion=? WHERE id=? AND id_formulario=? AND id_local=? AND id_usuario=?");
      if (!$stmtUp) throw new Exception("Error preparando actualización de material (ID=$idFQ): ".$conn->error);
      $stmtUp->bind_param("isddiiii",$valorNum,$obs,$latGestion,$lngGestion,$idFQ,$idCampana,$idLocal,$usuario_id);
      if (!$stmtUp->execute()) throw new Exception("Error actualizando material (ID=$idFQ): ".$stmtUp->error);
      $metrics['updates_fq'] += $stmtUp->affected_rows; $stmtUp->close();

      // id material para historial
      $stmtMatFetch = $conn->prepare("SELECT m.id FROM material m JOIN formularioQuestion fq ON fq.material=m.nombre AND m.id_division=? WHERE fq.id=? LIMIT 1");
      $stmtMatFetch->bind_param("ii",$division_id,$idFQ); $stmtMatFetch->execute();
      $resMatFetch = $stmtMatFetch->get_result(); $idMaterial = $resMatFetch->num_rows ? intval($resMatFetch->fetch_assoc()['id']) : 0; $stmtMatFetch->close();

      insertGestionVisita($conn,$visita_id,$usuario_id,$idCampana,$idLocal,$idFQ,$idMaterial,$fechaVisita,$estadoGestion,$valorNum,$obs,"",null,null,0.0,0.0,$latGestion,$lngGestion);
      $metrics['inserts_gv']++;
    }

    if (is_array($motivoSelect)) {
      foreach ($motivoSelect as $idFQ => $motivoSel) {
        if (!isset($valores[$idFQ]) || trim((string)$valores[$idFQ]) === '') {
          $motNImpl  = isset($motivoNoImpl[$idFQ]) ? trim((string)$motivoNoImpl[$idFQ]) : '';
          $obsConcat = trim($motivoSel . ($motNImpl !== '' ? ' - ' . $motNImpl : ''));
          $stmtNo = $conn->prepare("UPDATE formularioQuestion SET observacion = CONCAT(COALESCE(observacion,''),' ',?) WHERE id=? AND id_formulario=? AND id_local=? AND id_usuario=?");
          if (!$stmtNo) throw new Exception("Error preparando actualización de no implementación (ID=$idFQ): ".$conn->error);
          $stmtNo->bind_param("siiii",$obsConcat,$idFQ,$idCampana,$idLocal,$usuario_id);
          if (!$stmtNo->execute()) throw new Exception("Error actualizando no implementado (ID=$idFQ): ".$stmtNo->error);
          $metrics['updates_fq'] += $stmtNo->affected_rows; $stmtNo->close();

          $stmtMatFetch = $conn->prepare("SELECT m.id FROM material m JOIN formularioQuestion fq ON fq.material=m.nombre AND m.id_division=? WHERE fq.id=? LIMIT 1");
          $stmtMatFetch->bind_param("ii",$division_id,$idFQ); $stmtMatFetch->execute();
          $resMatFetch = $stmtMatFetch->get_result(); $idMaterial = $resMatFetch->num_rows ? intval($resMatFetch->fetch_assoc()['id']) : 0; $stmtMatFetch->close();

          insertGestionVisita($conn,$visita_id,$usuario_id,$idCampana,$idLocal,$idFQ,$idMaterial,$fechaVisita,'no_implementado',0,$obsConcat,'',null,null,0.0,0.0,$latGestion,$lngGestion);
          $metrics['inserts_gv']++;
        }
      }
    }
  }
  elseif ($estadoGestion === 'pendiente') {
    $fotoUrl = null; $obsGeneral = $motivo.($comentario!=='' ? ' - '.$comentario : '');
    if (!$fotoVisitaEstadoIdFinal) {
      if ($motivo === 'local_cerrado' && isset($_FILES['fotoLocalCerrado']) && $_FILES['fotoLocalCerrado']['error']===UPLOAD_ERR_OK) {
        $up = guardarFotoUnitaria($_FILES['fotoLocalCerrado'],'local_cerrado','localcerrado_',$idLocal); $fotoUrl=$up['url'];
      } elseif ($motivo === 'local_no_existe' && isset($_FILES['fotoLocalNoExiste']) && $_FILES['fotoLocalNoExiste']['error']===UPLOAD_ERR_OK) {
        $up = guardarFotoUnitaria($_FILES['fotoLocalNoExiste'],'local_no_existe','localnoexiste_',$idLocal); $fotoUrl=$up['url'];
      } elseif (isset($_FILES['fotoPendienteGenerica']) && $_FILES['fotoPendienteGenerica']['error']===UPLOAD_ERR_OK) {
        $up = guardarFotoUnitaria($_FILES['fotoPendienteGenerica'],'pendiente_generica','pendiente_',$idLocal); $fotoUrl=$up['url'];
      }
      if ($fotoUrl && !empty($GLOBALS['GV_HAS_FOTO_ESTADO'])) {
        $fotoVisitaEstadoIdFinal = insertarFotoVisitaEstado(
          $conn,
          $visita_id,
          $usuario_id,
          $idCampana,
          $idLocal,
          $fotoUrl,
          $up['path'],
          'pendiente',
          $latGestion,
          $lngGestion
        );
      }
    }
    if (!$fotoVisitaEstadoIdFinal && !$fotoUrl) { throw new Exception("Debe adjuntar al menos una foto para el estado Pendiente."); }

    $stmtP = $conn->prepare("UPDATE formularioQuestion SET fechaVisita=?, countVisita=countVisita+1, observacion=?, pregunta='en proceso' WHERE id_formulario=? AND id_local=? AND id_usuario=?");
    if (!$stmtP) throw new Exception("Error update pendiente: ".$conn->error);
    $stmtP->bind_param("ssiii",$fechaVisita,$obsGeneral,$idCampana,$idLocal,$usuario_id);
    if (!$stmtP->execute()) throw new Exception("Error al actualizar pendiente: ".$stmtP->error);
    $metrics['updates_fq'] += $stmtP->affected_rows; $stmtP->close();

    insertGestionVisita($conn,$visita_id,$usuario_id,$idCampana,$idLocal,0,0,$fechaVisita,$estadoGestion,0,$obsGeneral,'',$fotoUrl,$fotoVisitaEstadoIdFinal,0.0,0.0,$latGestion,$lngGestion);
    $metrics['inserts_gv']++;
  }
  elseif ($estadoGestion === 'cancelado') {
    $estadoNum = 2; $fotoUrl = null; $obsGeneral = $motivo.($comentario!=='' ? ' - '.$comentario : '');
    if (!$fotoVisitaEstadoIdFinal) {
      if ($motivo === 'mueble_no_esta_en_sala' && isset($_FILES['fotoMuebleNoSala']) && $_FILES['fotoMuebleNoSala']['error']===UPLOAD_ERR_OK) {
        $up = guardarFotoUnitaria($_FILES['fotoMuebleNoSala'],'mueble_no_existe','mueble_',$idLocal); $fotoUrl=$up['url'];
      } elseif (isset($_FILES['fotoCanceladoGenerica']) && $_FILES['fotoCanceladoGenerica']['error']===UPLOAD_ERR_OK) {
        $up = guardarFotoUnitaria($_FILES['fotoCanceladoGenerica'],'cancelado_generica','cancelado_',$idLocal); $fotoUrl=$up['url'];
      }
      if ($fotoUrl && !empty($GLOBALS['GV_HAS_FOTO_ESTADO'])) {
        $fotoVisitaEstadoIdFinal = insertarFotoVisitaEstado(
          $conn,
          $visita_id,
          $usuario_id,
          $idCampana,
          $idLocal,
          $fotoUrl,
          $up['path'],
          'cancelado',
          $latGestion,
          $lngGestion
        );
      }
    }
    if (!$fotoVisitaEstadoIdFinal && !$fotoUrl) { throw new Exception("Debe adjuntar al menos una foto para el estado Cancelado."); }

    $stmtC = $conn->prepare("UPDATE formularioQuestion SET estado=?, fechaVisita=?, countVisita=countVisita+1, observacion=?, pregunta='cancelado' WHERE id_formulario=? AND id_local=? AND id_usuario=?");
    if (!$stmtC) throw new Exception("Error update cancelado: ".$conn->error);
    $stmtC->bind_param("issiii",$estadoNum,$fechaVisita,$obsGeneral,$idCampana,$idLocal,$usuario_id);
    if (!$stmtC->execute()) throw new Exception("Error al actualizar cancelado: ".$stmtC->error);
    $metrics['updates_fq'] += $stmtC->affected_rows; $stmtC->close();

    insertGestionVisita($conn,$visita_id,$usuario_id,$idCampana,$idLocal,0,0,$fechaVisita,$estadoGestion,0,$obsGeneral,'',$fotoUrl,$fotoVisitaEstadoIdFinal,0.0,0.0,$latGestion,$lngGestion);
    $metrics['inserts_gv']++;
  }
  elseif ($estadoGestion === 'solo_auditoria') {
    $stmtAud = $conn->prepare("UPDATE formularioQuestion SET fechaVisita=?, countVisita=countVisita+1, latGestion=?, lngGestion=?, pregunta='solo_auditoria' WHERE id_formulario=? AND id_local=? AND id_usuario=?");
    if (!$stmtAud) throw new Exception("Error update solo_auditoria: ".$conn->error);
    $stmtAud->bind_param("sddiii",$fechaVisita,$latGestion,$lngGestion,$idCampana,$idLocal,$usuario_id);
    if (!$stmtAud->execute()) throw new Exception("Error al actualizar solo_auditoria: ".$stmtAud->error);
    $metrics['updates_fq'] += $stmtAud->affected_rows; $stmtAud->close();

    insertGestionVisita($conn,$visita_id,$usuario_id,$idCampana,$idLocal,0,0,$fechaVisita,$estadoGestion,0,'','',null,null,0.0,0.0,$latGestion,$lngGestion);
    $metrics['inserts_gv']++;
  }

  /* ----- Encuesta (SIEMPRE que venga 'respuesta') ----- */
  $flags = ['created_at'=>$FQR_HAS_CREATED_AT, 'valor'=>$FQR_HAS_VALOR, 'foto_visita_id'=>$FQR_HAS_FOTO_VISITA];

  // Permitir que el front envíe alternativa JSON: respuesta_json
  if (!isset($_POST['respuesta']) && isset($_POST['respuesta_json'])) {
    $tmp = json_decode((string)$_POST['respuesta_json'], true);
    if (is_array($tmp)) $_POST['respuesta'] = $tmp;
  }

  if (isset($_POST['respuesta']) && is_array($_POST['respuesta'])) {
    foreach ($_POST['respuesta'] as $id_form_question => $respValue) {
      $id_form_question = (int)$id_form_question;

      $stmtT = $conn->prepare("SELECT id_question_type FROM form_questions WHERE id=? LIMIT 1");
      if (!$stmtT) throw new Exception("Error tipoPregunta: ".$conn->error);
      $stmtT->bind_param("i",$id_form_question);
      $stmtT->execute(); $stmtT->bind_result($tipoPregunta);
      if (!$stmtT->fetch()) { $stmtT->close(); throw new Exception("Pregunta no existe (ID=$id_form_question)."); }
      $stmtT->close();

      if ($tipoPregunta == 1 || $tipoPregunta == 2) {
        $id_option = intval($respValue); if ($id_option === 0) continue;
        $stmtO = $conn->prepare("SELECT option_text FROM form_question_options WHERE id=? AND id_form_question=? LIMIT 1");
        if (!$stmtO) throw new Exception("Error option_text: ".$conn->error);
        $stmtO->bind_param("ii",$id_option,$id_form_question);
        $stmtO->execute(); $stmtO->bind_result($option_text);
        if (!$stmtO->fetch()) { $stmtO->close(); throw new Exception("Opción inválida ($id_option) para pregunta $id_form_question."); }
        $stmtO->close();
        $answer_text = htmlspecialchars((string)$option_text, ENT_QUOTES, 'UTF-8');
        $valor = 0.0;
        if (isset($_POST['valorRespuesta'][$id_form_question][$id_option]) && is_numeric($_POST['valorRespuesta'][$id_form_question][$id_option])) {
          $valor = (float)$_POST['valorRespuesta'][$id_form_question][$id_option];
        }
        insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,$answer_text,$id_option,$valor,null,$now);
        $metrics['inserts_fqr']++;

      } elseif ($tipoPregunta == 3) {
        if (!is_array($respValue)) throw new Exception("Respuesta inválida (no array) en pregunta $id_form_question.");
        $filtered = array_filter($respValue, fn($v) => intval($v) !== 0); if (empty($filtered)) continue;
        foreach ($filtered as $optVal) {
          $optVal = intval($optVal);
          $stmtO = $conn->prepare("SELECT option_text FROM form_question_options WHERE id=? AND id_form_question=? LIMIT 1");
          if (!$stmtO) throw new Exception("Error option_text multiple: ".$conn->error);
          $stmtO->bind_param("ii",$optVal,$id_form_question);
          $stmtO->execute(); $stmtO->bind_result($option_text);
          if (!$stmtO->fetch()) { $stmtO->close(); throw new Exception("Opción inválida ($optVal) en pregunta $id_form_question."); }
          $stmtO->close();
          $answer_text = htmlspecialchars((string)$option_text, ENT_QUOTES, 'UTF-8');
          $valor = 0.0;
          if (isset($_POST['valorRespuesta'][$id_form_question][$optVal]) && is_numeric($_POST['valorRespuesta'][$id_form_question][$optVal])) {
            $valor = (float)$_POST['valorRespuesta'][$id_form_question][$optVal];
          }
          insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,$answer_text,$optVal,$valor,null,$now);
          $metrics['inserts_fqr']++;
        }

      } elseif ($tipoPregunta == 4) {
        $respTxt = htmlspecialchars(trim((string)$respValue), ENT_QUOTES, 'UTF-8'); if ($respTxt === '') continue;
        insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,$respTxt,0,0.0,null,$now);
        $metrics['inserts_fqr']++;

      } elseif ($tipoPregunta == 5) {
        $respNumStr = trim((string)$respValue); if ($respNumStr === '' || !is_numeric($respNumStr)) continue;
        insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,(string)$respNumStr,0,(float)$respNumStr,null,$now);
        $metrics['inserts_fqr']++;

      } elseif ($tipoPregunta == 6) {
        $respDateRaw = trim((string)$respValue);
        if ($respDateRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $respDateRaw)) {
          continue; // no insertes fila vacía si la fecha es inválida
        }
        $respDate = htmlspecialchars($respDateRaw, ENT_QUOTES, 'UTF-8');
        insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,$respDate,0,0.0,null,$now);
        $metrics['inserts_fqr']++;

      } else {
        $respTxt = htmlspecialchars(trim((string)$respValue), ENT_QUOTES, 'UTF-8');
        insert_fqr($conn,$flags,$visita_id,$id_form_question,$idLocal,$usuario_id,$respTxt,0,0.0,null,$now);
        $metrics['inserts_fqr']++;
      }
    }
  }

  /* ----- Foto material (archivos convertidos en este request) → fotoVisita ----- */
  if (!empty($imagenes)) {
    $stmtFV = $conn->prepare("INSERT INTO fotoVisita (visita_id,url,id_usuario,id_formulario,id_local,id_material,id_formularioQuestion,fotoLat,fotoLng) VALUES (?,?,?,?,?,?,?,?,?)");
    if (!$stmtFV) throw new Exception("Error insert fotoVisita: ".$conn->error);
    foreach ($imagenes as $img) {
      $rawUrl = (string)$img['url']; // normalmente 'uploads/...'
      $normForCompare = normalize_foto_url($rawUrl);
      // Canon: guardar SIEMPRE 'uploads/...'
      $urlFoto = ltrim(preg_replace('#^/visibility2/app/#','', $normForCompare), '/');
      if ($urlFoto === '') { continue; }

      $idMat=(int)$img['idMat']; $idFQ=(int)$img['idFQ']; $idx=(int)$img['idx'];

      // DEDUPE: si ya existe para esta visita+FQ+URL, saltar
      if (foto_already_linked($conn, $visita_id, $idFQ, $urlFoto)) {
        $metrics['skipped_fv_dups']++; continue;
      }

      $latFoto=0.0; $lngFoto=0.0;
      if (isset($_POST['coordsFoto'][$idFQ][$idx]['lat']) && isset($_POST['coordsFoto'][$idFQ][$idx]['lng'])) {
        $latFoto=(float)$_POST['coordsFoto'][$idFQ][$idx]['lat']; $lngFoto=(float)$_POST['coordsFoto'][$idFQ][$idx]['lng'];
      }
      $stmtFV->bind_param("isiiiiidd",$visita_id,$urlFoto,$usuario_id,$idCampana,$idLocal,$idMat,$idFQ,$latFoto,$lngFoto);
      if (!$stmtFV->execute()) throw new Exception("Error insert fotoVisita => ".$stmtFV->error);
      $metrics['inserts_fv']++;
    }
    $stmtFV->close();
  }

  /* ----- Foto material (URLs ya subidas por el front) → fotoVisita (DEDUPED) ----- */
  if (isset($_POST['fotos']) && is_array($_POST['fotos'])) {
    $stmtFV2 = $conn->prepare("INSERT INTO fotoVisita (visita_id,url,id_usuario,id_formulario,id_local,id_material,id_formularioQuestion,fotoLat,fotoLng) VALUES (?,?,?,?,?,?,?,?,?)");
    if (!$stmtFV2) throw new Exception("Error prepare fotoVisita (urls): ".$conn->error);

    foreach ($_POST['fotos'] as $idFQ => $urls) {
      $idFQ = (int)$idFQ; if (!is_array($urls)) continue;

      $stmtMatFetch = $conn->prepare("SELECT m.id FROM material m JOIN formularioQuestion fq ON fq.material=m.nombre AND m.id_division=? WHERE fq.id=? LIMIT 1");
      $stmtMatFetch->bind_param("ii",$division_id,$idFQ);
      $stmtMatFetch->execute(); $resMatFetch = $stmtMatFetch->get_result();
      $idMaterial = $resMatFetch->num_rows ? (int)$resMatFetch->fetch_assoc()['id'] : 0; $stmtMatFetch->close();

      $idx=-1;
      foreach ($urls as $u) {
        $idx++;
        $normForCompare = normalize_foto_url((string)$u);
        $urlFoto = ltrim(preg_replace('#^/visibility2/app/#','', $normForCompare), '/'); // canon 'uploads/...'
        if ($urlFoto === '') continue;

        // DEDUPE fuerte (evita que se dupliquen las que ya subió el AJAX de materiales)
        if (foto_already_linked($conn, $visita_id, $idFQ, $urlFoto)) {
          $metrics['skipped_fv_dups']++; continue;
        }

        $latFoto = isset($_POST['coordsFoto'][$idFQ][$idx]['lat']) ? (float)$_POST['coordsFoto'][$idFQ][$idx]['lat'] : 0.0;
        $lngFoto = isset($_POST['coordsFoto'][$idFQ][$idx]['lng']) ? (float)$_POST['coordsFoto'][$idFQ][$idx]['lng'] : 0.0;

        $stmtFV2->bind_param("isiiiiidd",$visita_id,$urlFoto,$usuario_id,$idCampana,$idLocal,$idMaterial,$idFQ,$latFoto,$lngFoto);
        if (!$stmtFV2->execute()) { throw new Exception("Error insert fotoVisita (url): ".$stmtFV2->error); }
        $metrics['inserts_fv']++;
      }
    }
    $stmtFV2->close();
  }

  /* ----- Cerrar visita ----- */
  $upd = $conn->prepare("UPDATE visita SET latitud=?, longitud=?, fecha_fin=NOW() WHERE id=?");
  $upd->bind_param("ddi",$latGestion,$lngGestion,$visita_id);
  if (!$upd->execute()) throw new Exception("Error actualizando visita: ".$upd->error);
  $upd->close();

  $conn->commit();

  if (function_exists('idempo_get_key') && idempo_get_key()) {
    idempo_store_and_reply($conn, 'procesar_gestion', 200, [
      'status'=>'success','visita_id'=>$visita_id,
      'estado_normalizado'=>$estadoGestion,'estado_raw'=>$estado_raw,'metrics'=>$metrics
    ]);
    exit;
  }


} catch (Exception $e) {
  $conn->rollback();

  foreach ($imagenes as $img) {
    $abs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . normalize_foto_url($img['url']);
    if (is_string($abs) && $abs !== '' && @file_exists($abs)) @unlink($abs);
  }

  error_log("Error en procesar_gestion_pruebas.php: ".$e->getMessage());

  if (function_exists('idempo_get_key') && idempo_get_key()) {
    idempo_store_and_reply($conn, 'procesar_gestion', 500, [
      'status'=>'error',
      'message'=>"No se pudo procesar la gestión: ".$e->getMessage()
    ]);
    exit;
  }

  respond_err("No se pudo procesar la gestión: ".$e->getMessage(), 500,
    "gestionarPruebas.php?idCampana=$idCampana&nombreCampana=".urlencode($nombreCampana)."&idLocal=$idLocal"
  );
}

/* OK */
$_SESSION['success'] = "La gestión se subió correctamente.";
if (wants_json()) { respond_ok(['visita_id'=>$visita_id,'estado_normalizado'=>$estadoGestion,'estado_raw'=>$estado_raw,'metrics'=>$metrics]); }
header("Location: index_pruebas.php");
exit;

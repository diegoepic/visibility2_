<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors','0');
date_default_timezone_set('America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/* -------------------- Conexión -------------------- */
if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
}
if (isset($conn) && $conn instanceof mysqli) { @$conn->set_charset('utf8mb4'); }

/* -------------------- Idempotencia -------------------- */
require_once __DIR__ . '/lib/idempotency.php';

/* -------------------- Helpers -------------------- */
function json_fail(int $code, string $message, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['status'=>'error','message'=>$message] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $payload): void {
  http_response_code(200);
  echo json_encode(['status'=>'success'] + $payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function get_header_lower(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return isset($_SERVER[$key]) && $_SERVER[$key] !== '' ? trim((string)$_SERVER[$key]) : null;
}
function read_csrf(): ?string {
  $h = get_header_lower('X-CSRF-Token');
  if (!empty($h)) return $h;
  if (isset($_POST['csrf_token']) && $_POST['csrf_token']!=='') return (string)$_POST['csrf_token'];
  if (isset($_GET['csrf_token'])  && $_GET['csrf_token']!=='')  return (string)$_GET['csrf_token'];
  return null;
}
function norm_lat($v): float {
  $x = is_numeric($v) ? (float)$v : 0.0;
  if ($x > 90)  $x = 90.0;
  if ($x < -90) $x = -90.0;
  return $x;
}
function norm_lng($v): float {
  $x = is_numeric($v) ? (float)$v : 0.0;
  if ($x > 180)  $x = 180.0;
  if ($x < -180) $x = -180.0;
  return $x;
}
function normalize_datetime(?string $s): ?string {
  if (!$s) return null;
  $ts = @strtotime($s);
  return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
function allow_cors_and_options(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allow  = 'https://visibility.cl';
  if ($origin && preg_match('#https://([a-z0-9.-]+\.)?visibility\.cl$#i', $origin)) {
    $allow = $origin;
  }
  header('Vary: Origin');
  header('Access-Control-Allow-Origin: ' . $allow);
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token, X-Idempotency-Key, X-HTTP-Method-Override, X_Offline_Queue, X-Offline-Queue');
  header('Access-Control-Max-Age: 600');

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    echo '';
    exit;
  }
}
/**
 * Normaliza el Idempotency-Key del request para evitar "Data too long..." y caracteres raros.
 * Reinyecta el valor normalizado en $_SERVER/$_POST para que lo lea idempotency.php
 */
function sanitize_idempotency_key(): void {
  $raw = get_header_lower('X-Idempotency-Key');
  if (!$raw && isset($_POST['X_Idempotency_Key'])) $raw = (string)$_POST['X_Idempotency_Key'];

  if ($raw !== null && $raw !== '') {
    $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', (string)$raw);
    if (strlen($k) > 64) {
      $k = substr(hash('sha256', $k), 0, 64);
    }
    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $k;
    $_POST['X_Idempotency_Key'] = $k;
  }
}

/* -------------------- CORS / OPTIONS -------------------- */
allow_cors_and_options();

/* -------------------- Método (con override) -------------------- */
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = get_header_lower('X-HTTP-Method-Override')
          ?? ($_POST['_method'] ?? null)
          ?? ($_GET['_method'] ?? null);
if ($override) { $method = strtoupper((string)$override); }

if ($method !== 'POST') {
  header('Allow: POST, OPTIONS');
  json_fail(405, 'Método no permitido. Usa POST.');
}

/* -------------------- Seguridad base -------------------- */
if (!isset($_SESSION['usuario_id'])) {
  json_fail(401, 'No autenticado.');
}
$user_id = (int)$_SESSION['usuario_id'];

$csrf = read_csrf();
if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  json_fail(419, 'CSRF inválido o ausente.');
}

/* -------------------- Inputs -------------------- */
$form_id      = isset($_POST['id_formulario'])   ? (int)$_POST['id_formulario']   : (int)($_GET['id_formulario'] ?? 0);
$local_id     = isset($_POST['id_local'])        ? (int)$_POST['id_local']        : (int)($_GET['id_local']      ?? 0);
/* Alias compatibles con otros endpoints/front */
if ($form_id === 0)  { $form_id  = isset($_POST['idCampana']) ? (int)$_POST['idCampana'] : (int)($_GET['idCampana'] ?? 0); }
if ($local_id === 0) { $local_id = isset($_POST['idLocal'])   ? (int)$_POST['idLocal']   : (int)($_GET['idLocal']   ?? 0); }

$client_guid  = isset($_POST['client_guid'])     ? trim((string)$_POST['client_guid']) : (string)($_GET['client_guid'] ?? '');
$visita_local = isset($_POST['visita_local_id']) ? trim((string)$_POST['visita_local_id']) : (string)($_GET['visita_local_id'] ?? '');
$lat          = norm_lat($_POST['lat'] ?? ($_GET['lat'] ?? 0));
$lng          = norm_lng($_POST['lng'] ?? ($_GET['lng'] ?? 0));
$started_at_in = isset($_POST['started_at']) ? trim((string)$_POST['started_at']) : (string)($_GET['started_at'] ?? '');
$started_at    = normalize_datetime($started_at_in);

if ($form_id <= 0 || $local_id <= 0) {
  json_fail(400, 'Parámetros inválidos: id_formulario/idCampana e id_local/idLocal son requeridos.');
}

/* -------------------- Permisos mínimos -------------------- */
$perm_ok = false;
if ($st = $conn->prepare("
  SELECT 1 FROM formularioQuestion
   WHERE id_formulario=? AND id_local=? AND id_usuario=?
   LIMIT 1
")) {
  $st->bind_param('iii', $form_id, $local_id, $user_id);
  $st->execute();
  $st->store_result();
  $perm_ok = ($st->num_rows > 0);
  $st->close();
}
if (!$perm_ok) {
  json_fail(403, 'No tienes permisos para crear una visita en este local/campaña.');
}

/* -------------------- Idempotencia (claim) -------------------- */
sanitize_idempotency_key();
idempo_claim_or_fail($conn, 'create_visita'); // si existe respuesta previa, responde y sale

/* -------------------- Lógica principal -------------------- */
try {
  $now = date('Y-m-d H:i:s');
  $visita_id = 0;
  $reused    = false;
  $canonical_guid = $client_guid;

  // 0) Reusar visita ABIERTA reciente aunque el GUID cambie (ventana de 6h)
  if ($visita_id === 0) {
    $windowStart = date('Y-m-d H:i:s', strtotime('-6 hours', strtotime($now)));
    if ($sel0 = $conn->prepare("
      SELECT id, client_guid
        FROM visita
       WHERE id_usuario=? AND id_formulario=? AND id_local=?
         AND (fecha_fin IS NULL OR fecha_fin='0000-00-00 00:00:00')
         AND fecha_inicio >= ?
       ORDER BY id DESC
       LIMIT 1
    ")) {
      $sel0->bind_param('iiis', $user_id, $form_id, $local_id, $windowStart);
      if ($sel0->execute()) {
        $row0_id = null; $row0_guid = '';
        $sel0->bind_result($row0_id, $row0_guid);
        if ($sel0->fetch()) {
          $visita_id = (int)$row0_id;
          $canonical_guid = $row0_guid ?: $canonical_guid;
          $reused    = true;
        }
      }
      $sel0->close();
    }
  }

  // 1) Si viene client_guid, reusar visita ABIERTA con ese guid
  if ($client_guid !== '') {
    if ($sel = $conn->prepare("
      SELECT id, fecha_fin
        FROM visita
       WHERE id_usuario=? AND id_formulario=? AND id_local=? AND client_guid=?
       LIMIT 1
    ")) {
      $sel->bind_param('iiis', $user_id, $form_id, $local_id, $client_guid);
      if ($sel->execute()) {
        $row_id = null; $row_fin = null;
        $sel->bind_result($row_id, $row_fin);
        if ($sel->fetch()) {
          if (empty($row_fin) || $row_fin==='0000-00-00 00:00:00') {
            $visita_id = (int)$row_id;
            $canonical_guid = $client_guid;
            $reused    = true;
          } else {
            $sel->close();
            json_fail(409, 'client_guid ya fue usado en una visita cerrada. Genera un GUID nuevo.', [
              'code'        => 'GUID_REUSED',
              'client_guid' => $client_guid,
              'visita_id'   => (int)$row_id
            ]);
          }
        }
      }
      $sel->close();
    }
  }

  // 2) Si no hay guid: reusar visita ABIERTA "genérica" del mismo trío
  if ($visita_id === 0 && $client_guid === '') {
    if ($sel2 = $conn->prepare("
      SELECT id
        FROM visita
       WHERE id_usuario=? AND id_formulario=? AND id_local=?
         AND (fecha_fin IS NULL OR fecha_fin='0000-00-00 00:00:00')
       ORDER BY id DESC
       LIMIT 1
    ")) {
      $sel2->bind_param('iii', $user_id, $form_id, $local_id);
      if ($sel2->execute()) {
        $row2_id = null;
        $sel2->bind_result($row2_id);
        if ($sel2->fetch()) {
          $visita_id = (int)$row2_id;
          $canonical_guid = $client_guid ?: $canonical_guid;
          $reused    = true;
        }
      }
      $sel2->close();
    }
  }

  // 3) Crear si no existe
  if ($visita_id === 0) {
    // Normalizar/crear GUID (32 chars) si viene muy largo o vacío
    if ($client_guid === '') {
      try { $client_guid = bin2hex(random_bytes(16)); } // 32
      catch (Throwable $e) { $client_guid = substr(hash('sha1', uniqid((string)$user_id, true)), 0, 32); }
    } else {
      if (strlen($client_guid) > 34) {
        $client_guid = substr(hash('sha1', $client_guid), 0, 32);
      }
    }

    // Transacción ACID para el INSERT (y manejar duplicados limpiamente)
    $conn->begin_transaction();
    $fecha_ini = $started_at ?: $now;

    if ($ins = $conn->prepare("
      INSERT INTO visita
        (id_usuario, id_formulario, id_local, client_guid, fecha_inicio, latitud, longitud)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ")) {
      $ins->bind_param('iiissdd', $user_id, $form_id, $local_id, $client_guid, $fecha_ini, $lat, $lng);
      if (!$ins->execute()) {
        $err = (string)$ins->error;
        $ins->close();
        $conn->rollback();

        // Si hay duplicado por UNIQUE(client_guid, user, form, local) => analizar
        if (stripos($err, 'duplicate') !== false || stripos($err, 'uniq') !== false) {
          if ($sel3 = $conn->prepare("
            SELECT id, fecha_fin FROM visita
             WHERE id_usuario=? AND id_formulario=? AND id_local=? AND client_guid=?
             LIMIT 1
          ")) {
            $sel3->bind_param('iiis', $user_id, $form_id, $local_id, $client_guid);
            if ($sel3->execute()) {
              $row3_id = null; $row3_fin = null;
              $sel3->bind_result($row3_id, $row3_fin);
              if ($sel3->fetch()) {
                if (empty($row3_fin) || $row3_fin==='0000-00-00 00:00:00') {
                  $visita_id = (int)$row3_id;
                  $reused    = true;
                } else {
                  $sel3->close();
                  json_fail(409, 'client_guid ya fue usado en una visita cerrada. Genera un GUID nuevo.', [
                    'code'        => 'GUID_REUSED',
                    'client_guid' => $client_guid,
                    'visita_id'   => (int)$row3_id
                  ]);
                }
              } else {
                $sel3->close();
                json_fail(500, 'Error recuperando visita existente tras duplicado.');
              }
            } else {
              $sel3->close();
              json_fail(500, 'Error ejecutando SELECT tras duplicado.');
            }
            $sel3->close();
          } else {
            json_fail(500, 'Error preparando SELECT de visita existente.');
          }
        } else {
          error_log('create_visita_pruebas.php INSERT error: ' . $err);
          json_fail(500, 'Error al crear la visita.');
        }
      } else {
        $visita_id = (int)$ins->insert_id;
        $ins->close();
        $conn->commit();
      }
    } else {
      $conn->rollback();
      error_log('create_visita_pruebas.php PREPARE error: ' . $conn->error);
      json_fail(500, 'Error preparando INSERT de visita.');
    }

  } else {
    // Si reutilizamos, refrescar lat/lng inicial sólo si estaban en 0 (opcional)
    if ($upd = $conn->prepare("
      UPDATE visita
         SET latitud = IFNULL(NULLIF(latitud,0), ?),
             longitud= IFNULL(NULLIF(longitud,0), ?)
       WHERE id=? LIMIT 1
    ")) {
      $upd->bind_param('ddi', $lat, $lng, $visita_id);
      $upd->execute();
      $upd->close();
    }
  }

  // Guarda en sesión por conveniencia
  $_SESSION['current_visita_id'] = $visita_id;

  $payload = [
    'visita_id'   => $visita_id,
    'client_guid' => $canonical_guid ?: $client_guid,
    'reused'      => $reused,
    'now'         => $now,
    'started_at'  => $started_at ?: $now,
    'server_time' => $now
  ];
  if ($visita_local !== '') $payload['visita_local_id'] = $visita_local;

  // Persistir respuesta en el log de idempotencia (si vino X-Idempotency-Key)
  if (function_exists('idempo_get_key') && idempo_get_key()) {
    idempo_store_and_reply($conn, 'create_visita', 200, ['status'=>'success'] + $payload);
  } else {
    json_ok($payload);
  }

} catch (Throwable $e) {
  // Intentar rollback si quedó una transacción abierta
  if ($conn instanceof mysqli) { @mysqli_rollback($conn); }
  error_log('create_visita_pruebas.php ERROR: ' . $e->getMessage());
  json_fail(500, 'Error interno al crear la visita.');
}

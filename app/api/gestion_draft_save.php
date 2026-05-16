<?php
/**
 * gestion_draft_save.php
 *
 * Guarda el estado del formulario en servidor como respaldo del draft local.
 * Llamado periódicamente (cada ~30s) por gestion_draft.js cuando hay conexión.
 *
 * POST params: client_guid, form_id, local_id, form_state_json, schema_version
 * Optional:    visita_id
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../lib/api_helpers.php';
require_once __DIR__ . '/../con_.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error_code' => 'DB_ERROR']);
    exit;
}
@$conn->set_charset('utf8mb4');

// ── Autenticación ─────────────────────────────────────────────────────────────
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error_code' => 'NO_SESSION']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_REFRESH'] ?? '';
$csrf_post   = $_POST['csrf_token'] ?? '';
$csrf_valid  = $csrf_header ?: $csrf_post;
if (!$csrf_valid || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf_valid)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error_code' => 'CSRF_INVALID']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

// ── Parámetros ────────────────────────────────────────────────────────────────
$client_guid     = trim((string)($_POST['client_guid'] ?? ''));
$form_id         = (int)($_POST['form_id']         ?? 0);
$local_id        = (int)($_POST['local_id']        ?? 0);
$visita_id       = (int)($_POST['visita_id']       ?? 0);
$schema_version  = max(1, (int)($_POST['schema_version'] ?? 1));
$form_state_raw  = $_POST['form_state_json'] ?? null;

if ($client_guid === '' || $form_id <= 0 || $local_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'MISSING_PARAMS',
                      'message' => 'Se requieren client_guid, form_id y local_id']);
    exit;
}

if ($form_state_raw === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'MISSING_STATE']);
    exit;
}

// Validar tamaño (max 2MB)
if (strlen($form_state_raw) > 2_097_152) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error_code' => 'PAYLOAD_TOO_LARGE']);
    exit;
}

// Validar JSON bien formado
json_decode($form_state_raw);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'INVALID_JSON',
                      'message' => json_last_error_msg()]);
    exit;
}

// ── Verificar permisos ────────────────────────────────────────────────────────
$perm = $conn->prepare(
    "SELECT 1 FROM formularioQuestion fq
     INNER JOIN formulario f ON f.id = fq.id_formulario
     WHERE fq.id_formulario = ? AND fq.id_local = ? AND fq.id_usuario = ?
       AND f.id_empresa = ?
     LIMIT 1"
);
if (!$perm) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error_code' => 'DB_PREPARE_ERROR']);
    exit;
}
$perm->bind_param('iiii', $form_id, $local_id, $usuario_id, $empresa_id);
$perm->execute();
$perm->store_result();
if ($perm->num_rows === 0) {
    $perm->close();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error_code' => 'FORBIDDEN']);
    exit;
}
$perm->close();

// ── Verificar que la tabla existe ─────────────────────────────────────────────
$tbl_check = @$conn->query("SHOW TABLES LIKE 'gestion_draft'");
$has_table = ($tbl_check && $tbl_check->num_rows > 0);
if ($tbl_check) $tbl_check->close();

if (!$has_table) {
    // Tabla aún no creada; no es error, simplemente no guardamos
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'TABLE_NOT_READY']);
    exit;
}

// ── Verificar que draft no esté completado (no sobrescribir gestión cerrada) ──
$existing = function_exists('get_gestion_draft') ? get_gestion_draft($conn, $client_guid) : null;
if ($existing && $existing['status'] === 'completed') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error_code' => 'ALREADY_COMPLETED',
                      'message' => 'La gestión ya fue cerrada']);
    exit;
}

// ── Verificar columna form_state_json ─────────────────────────────────────────
$col_check = @$conn->query("SHOW COLUMNS FROM gestion_draft LIKE 'form_state_json'");
$has_col   = ($col_check && $col_check->num_rows > 0);
if ($col_check) $col_check->close();

if (!$has_col) {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'COL_NOT_READY']);
    exit;
}

// ── UPSERT ────────────────────────────────────────────────────────────────────
if ($existing) {
    // Actualizar registro existente
    $extra = [
        'form_state_json' => $form_state_raw,
        'schema_version'  => $schema_version,
    ];
    if ($visita_id > 0) {
        $extra['visita_id'] = $visita_id;
    }
    $ok = function_exists('update_gestion_draft')
        ? update_gestion_draft($conn, $client_guid, $existing['status'], $extra)
        : false;

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error_code' => 'UPDATE_FAILED']);
        exit;
    }
} else {
    // Insertar nuevo draft mínimo con form_state_json
    $vid_bind = $visita_id > 0 ? $visita_id : null;
    $stmt = $conn->prepare(
        "INSERT INTO gestion_draft
         (client_guid, user_id, form_id, local_id, visita_id, status, estado_gestion,
          payload_json, form_state_json, form_state_updated_at, schema_version)
         VALUES (?, ?, ?, ?, ?, 'draft', '',
                 '{}', ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
             form_state_json        = VALUES(form_state_json),
             form_state_updated_at  = NOW(),
             schema_version         = VALUES(schema_version),
             updated_at             = NOW()"
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error_code' => 'DB_PREPARE_ERROR']);
        exit;
    }
    $stmt->bind_param('siiiisi',
        $client_guid, $usuario_id, $form_id, $local_id, $vid_bind,
        $form_state_raw, $schema_version
    );
    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error_code' => 'INSERT_FAILED']);
        exit;
    }
    $stmt->close();
}

// ── Respuesta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'         => true,
    'updated_at' => gmdate('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);

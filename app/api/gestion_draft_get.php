<?php

/**
 * gestion_draft_get.php
 *
 * Devuelve el draft guardado en servidor para un client_guid dado.
 * Usado opcionalmente por el cliente para validar sincronización.
 * La ruta principal de restauración es gestion_snapshot.php.
 *
 * GET params: client_guid, form_id, local_id
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

$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

// ── Parámetros ────────────────────────────────────────────────────────────────
$client_guid = trim((string)($_GET['client_guid'] ?? $_REQUEST['client_guid'] ?? ''));
$form_id     = (int)($_GET['form_id']  ?? $_REQUEST['form_id']  ?? 0);
$local_id    = (int)($_GET['local_id'] ?? $_REQUEST['local_id'] ?? 0);

if ($client_guid === '' || $form_id <= 0 || $local_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'MISSING_PARAMS']);
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

// ── Verificar tabla ───────────────────────────────────────────────────────────
$tbl_check = @$conn->query("SHOW TABLES LIKE 'gestion_draft'");
$has_table = ($tbl_check && $tbl_check->num_rows > 0);
if ($tbl_check) $tbl_check->close();

if (!$has_table) {
    echo json_encode(['ok' => true, 'draft' => null]);
    exit;
}

// ── Obtener draft ─────────────────────────────────────────────────────────────
$draft = function_exists('get_gestion_draft') ? get_gestion_draft($conn, $client_guid) : null;

if (!$draft) {
    echo json_encode(['ok' => true, 'draft' => null]);
    exit;
}

// Seguridad: verificar que el draft pertenece al usuario autenticado
if ((int)$draft['user_id'] !== $usuario_id) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error_code' => 'FORBIDDEN']);
    exit;
}

// Decodificar form_state_json si existe
if (isset($draft['form_state_json']) && $draft['form_state_json']) {
    $decoded = json_decode($draft['form_state_json'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $draft['form_state_json'] = $decoded;
    } else {
        $draft['form_state_json'] = null;
    }
}

// Castear campos numéricos
$draft['id']               = (int)$draft['id'];
$draft['user_id']          = (int)$draft['user_id'];
$draft['form_id']          = (int)$draft['form_id'];
$draft['local_id']         = (int)$draft['local_id'];
$draft['visita_id']        = $draft['visita_id'] ? (int)$draft['visita_id'] : null;
$draft['expected_photos']  = (int)$draft['expected_photos'];
$draft['uploaded_photos']  = (int)$draft['uploaded_photos'];
$draft['schema_version']   = isset($draft['schema_version']) ? (int)$draft['schema_version'] : 1;

echo json_encode([
    'ok'    => true,
    'draft' => $draft,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

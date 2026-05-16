<?php
/**
 * gestion_snapshot.php
 *
 * Devuelve el estado persistido en servidor de una gestión en curso:
 * fotos ya subidas (fotoVisita + form_question_responses tipo 7),
 * visita abierta y draft server-side.
 *
 * Usado por gestionarPruebas.php al cargar para restaurar thumbnails
 * y datos previos al cierre de la app.
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
    echo json_encode(['ok' => false, 'error' => 'DB_ERROR']);
    exit;
}
@$conn->set_charset('utf8mb4');

// ── Autenticación ────────────────────────────────────────────────────────────
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error_code' => 'NO_SESSION']);
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

// ── Parámetros (GET o POST) ──────────────────────────────────────────────────
$form_id     = isset($_REQUEST['form_id'])     ? (int)$_REQUEST['form_id']     : 0;
$local_id    = isset($_REQUEST['local_id'])    ? (int)$_REQUEST['local_id']    : 0;
$client_guid = trim((string)($_REQUEST['client_guid'] ?? ''));
$visita_id   = isset($_REQUEST['visita_id'])   ? (int)$_REQUEST['visita_id']   : 0;

if ($form_id <= 0 || $local_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'MISSING_PARAMS',
                      'message' => 'Se requieren form_id y local_id']);
    exit;
}

if (empty($client_guid) && $visita_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error_code' => 'MISSING_IDENTIFIER',
                      'message' => 'Se requiere client_guid o visita_id']);
    exit;
}

// ── Verificar permisos: el usuario debe tener acceso a este form/local ───────
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
    echo json_encode(['ok' => false, 'error_code' => 'FORBIDDEN',
                      'message' => 'Sin acceso a este formulario/local']);
    exit;
}
$perm->close();

// ── 1. Resolver visita ───────────────────────────────────────────────────────
$visita_row = null;

if ($visita_id > 0) {
    // Buscar por ID explícito — re-validar propietario
    $st = $conn->prepare(
        "SELECT id, client_guid, fecha_inicio, fecha_fin, id_formulario, id_local
         FROM visita
         WHERE id = ? AND id_usuario = ? AND id_formulario = ? AND id_local = ?
         LIMIT 1"
    );
    if ($st) {
        $st->bind_param('iiii', $visita_id, $usuario_id, $form_id, $local_id);
        $st->execute();
        $visita_row = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

if (!$visita_row && $client_guid !== '') {
    // Buscar por client_guid — priorizar visita abierta, luego la más reciente
    $st = $conn->prepare(
        "SELECT id, client_guid, fecha_inicio, fecha_fin, id_formulario, id_local
         FROM visita
         WHERE client_guid = ? AND id_usuario = ? AND id_formulario = ? AND id_local = ?
         ORDER BY (fecha_fin IS NULL) DESC, id DESC
         LIMIT 1"
    );
    if ($st) {
        $st->bind_param('siii', $client_guid, $usuario_id, $form_id, $local_id);
        $st->execute();
        $visita_row = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

if (!$visita_row && $client_guid === '') {
    // Último recurso: visita abierta más reciente para este user/form/local
    $st = $conn->prepare(
        "SELECT id, client_guid, fecha_inicio, fecha_fin, id_formulario, id_local
         FROM visita
         WHERE id_usuario = ? AND id_formulario = ? AND id_local = ?
           AND (fecha_fin IS NULL OR fecha_fin > DATE_SUB(NOW(), INTERVAL 2 HOUR))
         ORDER BY id DESC LIMIT 1"
    );
    if ($st) {
        $st->bind_param('iii', $usuario_id, $form_id, $local_id);
        $st->execute();
        $visita_row = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

// Sin visita: no hay nada que mostrar, respuesta vacía y válida
if (!$visita_row) {
    echo json_encode([
        'ok'               => true,
        'visita'           => null,
        'fotos_materiales' => (object)[],
        'fotos_preguntas'  => (object)[],
        'draft_server'     => null,
    ]);
    exit;
}

$resolved_visita_id  = (int)$visita_row['id'];
$resolved_guid       = $visita_row['client_guid'];
$visita_is_open      = ($visita_row['fecha_fin'] === null || $visita_row['fecha_fin'] === '0000-00-00 00:00:00');

// ── 2. Fotos de materiales (fotoVisita) ──────────────────────────────────────
$fotos_materiales = [];

$st = $conn->prepare(
    "SELECT id, id_formularioQuestion AS fq_id, url, fotoLat, fotoLng, id_material
     FROM fotoVisita
     WHERE visita_id = ? AND id_usuario = ? AND id_formulario = ? AND id_local = ?
     ORDER BY id ASC"
);
if ($st) {
    $st->bind_param('iiii', $resolved_visita_id, $usuario_id, $form_id, $local_id);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $fq_id = (int)$row['fq_id'];
        if (!isset($fotos_materiales[$fq_id])) {
            $fotos_materiales[$fq_id] = [];
        }
        $fotos_materiales[$fq_id][] = [
            'id'         => (int)$row['id'],
            'url'        => $row['url'],
            'fotoLat'    => $row['fotoLat'],
            'fotoLng'    => $row['fotoLng'],
            'id_material'=> (int)$row['id_material'],
        ];
    }
    $st->close();
}

// ── 3. Fotos de preguntas tipo 7 (form_question_responses) ───────────────────
$fotos_preguntas = [];

$st = $conn->prepare(
    "SELECT r.id AS resp_id, r.id_form_question, r.answer_text AS url
     FROM form_question_responses r
     INNER JOIN form_questions q ON q.id = r.id_form_question
     WHERE r.visita_id = ? AND r.id_usuario = ? AND r.id_local = ?
       AND q.id_question_type = 7
       AND r.answer_text IS NOT NULL AND r.answer_text <> ''
     ORDER BY r.id ASC"
);
if ($st) {
    $st->bind_param('iii', $resolved_visita_id, $usuario_id, $local_id);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
        $q_id = (int)$row['id_form_question'];
        // Una pregunta foto puede tener solo una respuesta vigente (la más reciente)
        $fotos_preguntas[$q_id] = [
            'resp_id'          => (int)$row['resp_id'],
            'id_form_question' => $q_id,
            'url'              => $row['url'],
        ];
    }
    $st->close();
}

// ── 4. Draft server-side (si existe tabla gestion_draft) ─────────────────────
$draft_server = null;

$check_draft_table = @$conn->query("SHOW TABLES LIKE 'gestion_draft'");
$has_draft_table   = ($check_draft_table && $check_draft_table->num_rows > 0);
if ($check_draft_table) $check_draft_table->close();

if ($has_draft_table) {
    // Buscar por client_guid resuelto o por el enviado en request
    $guid_search = $resolved_guid ?: $client_guid;
    if ($guid_search !== '') {
        // Verificar si existe columna form_state_json (puede no existir aún)
        static $has_form_state = null;
        if ($has_form_state === null) {
            $col_check = @$conn->query("SHOW COLUMNS FROM gestion_draft LIKE 'form_state_json'");
            $has_form_state = ($col_check && $col_check->num_rows > 0);
            if ($col_check) $col_check->close();
        }

        $cols = 'status, estado_gestion, updated_at, created_at';
        if ($has_form_state) {
            $cols .= ', form_state_json, form_state_updated_at, schema_version';
        }

        $st = $conn->prepare(
            "SELECT $cols FROM gestion_draft
             WHERE client_guid = ? AND user_id = ?
             LIMIT 1"
        );
        if ($st) {
            $st->bind_param('si', $guid_search, $usuario_id);
            $st->execute();
            $draft_row = $st->get_result()->fetch_assoc();
            $st->close();

            if ($draft_row) {
                $draft_server = [
                    'status'         => $draft_row['status'],
                    'estado_gestion' => $draft_row['estado_gestion'],
                    'updated_at'     => $draft_row['updated_at'],
                    'created_at'     => $draft_row['created_at'],
                    'form_state_json'=> null,
                    'schema_version' => 1,
                ];
                if ($has_form_state) {
                    $raw_json = $draft_row['form_state_json'] ?? null;
                    if ($raw_json) {
                        $decoded = json_decode($raw_json, true);
                        // Solo pasar si decodifica correctamente
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $draft_server['form_state_json']     = $decoded;
                            $draft_server['form_state_updated_at']= $draft_row['form_state_updated_at'] ?? null;
                            $draft_server['schema_version']       = (int)($draft_row['schema_version'] ?? 1);
                        }
                    }
                }
            }
        }
    }
}

// ── Respuesta ─────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'     => true,
    'visita' => [
        'id'           => $resolved_visita_id,
        'client_guid'  => $resolved_guid,
        'fecha_inicio' => $visita_row['fecha_inicio'],
        'fecha_fin'    => $visita_row['fecha_fin'],
        'is_open'      => $visita_is_open,
    ],
    'fotos_materiales' => $fotos_materiales ?: (object)[],
    'fotos_preguntas'  => $fotos_preguntas  ?: (object)[],
    'draft_server'     => $draft_server,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

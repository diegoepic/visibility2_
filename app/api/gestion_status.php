<?php
/**
 * gestion_status.php - Endpoint para consultar estado de gestión
 *
 * Permite a la UI y a la cola offline consultar el estado real de una gestión:
 * - Si existe el draft
 * - Si la visita fue creada
 * - Cuántas fotos se subieron
 * - Si la gestión fue cerrada
 *

 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Responder a CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Incluir helpers
require_once __DIR__ . '/../lib/api_helpers.php';

// Conexión
require_once __DIR__ . '/../con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    api_response_error_v2(500, 'No hay conexión a BD', [], null, 'gestion_status');
}
@$conn->set_charset('utf8mb4');

// Autenticación
if (!isset($_SESSION['usuario_id'])) {
    api_response_error_v2(401, 'Sesión no iniciada o expirada', ['error_code' => 'NO_SESSION'], $conn, 'gestion_status');
}

$usuario_id = (int)$_SESSION['usuario_id'];

// Parámetros (GET o POST)
$client_guid = $_REQUEST['client_guid'] ?? $_REQUEST['guid'] ?? null;
$visita_id = isset($_REQUEST['visita_id']) ? (int)$_REQUEST['visita_id'] : null;
$form_id = isset($_REQUEST['form_id']) ? (int)$_REQUEST['form_id'] : null;
$local_id = isset($_REQUEST['local_id']) ? (int)$_REQUEST['local_id'] : null;

// Validar que venga al menos un identificador
if (empty($client_guid) && !$visita_id && (!$form_id || !$local_id)) {
    api_response_error_v2(422, 'Debe proporcionar client_guid, visita_id, o form_id+local_id', [
        'error_code' => 'MISSING_PARAMS'
    ], $conn, 'gestion_status');
}

$result = [
    'client_guid' => $client_guid,
    'visita_id' => $visita_id,
    'form_id' => $form_id,
    'local_id' => $local_id,
    'draft' => null,
    'visita' => null,
    'fotos_count' => 0,
    'respuestas_count' => 0,
    'gestion_visita_count' => 0,
    'is_complete' => false,
    'status_summary' => 'unknown'
];

// 1. Buscar draft si existe y tenemos client_guid
if (!empty($client_guid)) {
    if (function_exists('get_gestion_draft')) {
        $draft = get_gestion_draft($conn, $client_guid);
        if ($draft) {
            $result['draft'] = [
                'id' => (int)$draft['id'],
                'status' => $draft['status'],
                'visita_id' => $draft['visita_id'] ? (int)$draft['visita_id'] : null,
                'estado_gestion' => $draft['estado_gestion'],
                'expected_photos' => (int)$draft['expected_photos'],
                'uploaded_photos' => (int)$draft['uploaded_photos'],
                'created_at' => $draft['created_at'],
                'completed_at' => $draft['completed_at'],
                'error_code' => $draft['error_code'],
                'error_message' => $draft['error_message']
            ];
            // Usar visita_id del draft si no vino como parámetro
            if (!$visita_id && $draft['visita_id']) {
                $visita_id = (int)$draft['visita_id'];
                $result['visita_id'] = $visita_id;
            }
        }
    }

    // Buscar visita por client_guid si aún no tenemos visita_id
    if (!$visita_id) {
        $stmt = $conn->prepare("
            SELECT id, fecha_inicio, fecha_fin, id_formulario, id_local
            FROM visita
            WHERE client_guid = ? AND id_usuario = ?
            ORDER BY id DESC LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("si", $client_guid, $usuario_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $visita_id = (int)$row['id'];
                $result['visita_id'] = $visita_id;
                $form_id = $form_id ?: (int)$row['id_formulario'];
                $local_id = $local_id ?: (int)$row['id_local'];
            }
            $stmt->close();
        }
    }
}

// 2. Buscar visita
if ($visita_id) {
    $stmt = $conn->prepare("
        SELECT id, id_usuario, id_formulario, id_local, fecha_inicio, fecha_fin, client_guid, latitud, longitud
        FROM visita
        WHERE id = ? AND id_usuario = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $visita_id, $usuario_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $result['visita'] = [
                'id' => (int)$row['id'],
                'form_id' => (int)$row['id_formulario'],
                'local_id' => (int)$row['id_local'],
                'fecha_inicio' => $row['fecha_inicio'],
                'fecha_fin' => $row['fecha_fin'],
                'client_guid' => $row['client_guid'],
                'latitud' => (float)$row['latitud'],
                'longitud' => (float)$row['longitud'],
                'is_closed' => !empty($row['fecha_fin'])
            ];
            $form_id = $form_id ?: (int)$row['id_formulario'];
            $local_id = $local_id ?: (int)$row['id_local'];
        }
        $stmt->close();
    }
}

// 3. Contar fotos
if ($visita_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM fotoVisita WHERE visita_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $visita_id);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $result['fotos_count'] = (int)$cnt;
        $stmt->close();
    }
}

// 4. Contar respuestas de encuesta
if ($visita_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM form_question_responses WHERE visita_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $visita_id);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $result['respuestas_count'] = (int)$cnt;
        $stmt->close();
    }
}

// 5. Contar historial de gestión
if ($visita_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM gestion_visita WHERE visita_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $visita_id);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $result['gestion_visita_count'] = (int)$cnt;
        $stmt->close();
    }
}

// 6. Determinar estado resumido
if ($result['visita'] && $result['visita']['is_closed']) {
    $result['is_complete'] = true;
    $result['status_summary'] = 'completed';
} elseif ($result['visita']) {
    $result['status_summary'] = 'in_progress';
} elseif ($result['draft']) {
    $status = $result['draft']['status'];
    $result['status_summary'] = match($status) {
        'completed' => 'completed',
        'failed' => 'failed',
        'processing' => 'processing',
        'abandoned' => 'abandoned',
        default => 'pending'
    };
} else {
    $result['status_summary'] = 'not_found';
}

// Responder
api_response_ok($result);

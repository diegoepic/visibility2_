<?php
/**
 * diagnostic_bundle.php - Endpoint para generar bundle de diagnóstico
 *
 * Genera un JSON con información de diagnóstico para soporte técnico:
 * - Últimos requests del usuario
 * - Gestiones recientes (drafts)
 * - Errores recientes
 * - Estado de visitas
 * - Fotos potencialmente huérfanas
 *
 * Requiere autenticación y solo devuelve datos del usuario actual.
 *

 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Incluir helpers
require_once __DIR__ . '/../lib/api_helpers.php';

// Conexión
require_once __DIR__ . '/../con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    api_response_error_v2(500, 'No hay conexión a BD', [], null, 'diagnostic_bundle');
}
@$conn->set_charset('utf8mb4');

// Autenticación
if (!isset($_SESSION['usuario_id'])) {
    api_response_error_v2(401, 'Sesión no iniciada o expirada', ['error_code' => 'NO_SESSION'], $conn, 'diagnostic_bundle');
}

$usuario_id = (int)$_SESSION['usuario_id'];

// Parámetros
$hours = isset($_REQUEST['hours']) ? min(168, max(1, (int)$_REQUEST['hours'])) : 24; // máx 7 días
$limit = isset($_REQUEST['limit']) ? min(100, max(10, (int)$_REQUEST['limit'])) : 50;
$include_payload = isset($_REQUEST['include_payload']) && $_REQUEST['include_payload'] === '1';

$bundle = [
    'generated_at' => date('c'),
    'user_id' => $usuario_id,
    'period_hours' => $hours,
    'stats' => [],
    'recent_requests' => [],
    'recent_drafts' => [],
    'recent_errors' => [],
    'open_visitas' => [],
    'orphan_photos' => []
];

$since = date('Y-m-d H:i:s', time() - ($hours * 3600));

// 1. Estadísticas generales
$stats_queries = [
    'total_visitas' => "SELECT COUNT(*) FROM visita WHERE id_usuario = ? AND fecha_inicio >= ?",
    'completed_visitas' => "SELECT COUNT(*) FROM visita WHERE id_usuario = ? AND fecha_fin IS NOT NULL AND fecha_inicio >= ?",
    'total_fotos' => "SELECT COUNT(*) FROM fotoVisita WHERE id_usuario = ? AND EXISTS (SELECT 1 FROM visita WHERE visita.id = fotoVisita.visita_id AND fecha_inicio >= ?)",
    'total_respuestas' => "SELECT COUNT(*) FROM form_question_responses WHERE id_usuario = ? AND EXISTS (SELECT 1 FROM visita WHERE visita.id = form_question_responses.visita_id AND fecha_inicio >= ?)"
];

foreach ($stats_queries as $key => $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $usuario_id, $since);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $bundle['stats'][$key] = (int)$cnt;
        $stmt->close();
    }
}

// 2. Requests recientes (de request_log si existe)
$check = $conn->query("SHOW TABLES LIKE 'request_log'");
$has_request_log = ($check && $check->num_rows > 0);
if ($check) $check->close();

if ($has_request_log) {
    $stmt = $conn->prepare("
        SELECT idempotency_key, endpoint, status, status_code, created_at, completed_at
        FROM request_log
        WHERE user_id = ? AND created_at >= ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param("isi", $usuario_id, $since, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $bundle['recent_requests'][] = $row;
        }
        $stmt->close();
    }
}

// 3. Drafts de gestión (si existe la tabla)
$check = $conn->query("SHOW TABLES LIKE 'gestion_draft'");
$has_drafts = ($check && $check->num_rows > 0);
if ($check) $check->close();

if ($has_drafts) {
    $cols = "id, client_guid, form_id, local_id, visita_id, estado_gestion, status, expected_photos, uploaded_photos, created_at, completed_at, error_code, error_message";
    if ($include_payload) {
        $cols .= ", payload_json";
    }

    $stmt = $conn->prepare("
        SELECT {$cols}
        FROM gestion_draft
        WHERE user_id = ? AND created_at >= ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param("isi", $usuario_id, $since, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // Truncar payload si es muy largo
            if (isset($row['payload_json']) && strlen($row['payload_json']) > 1000) {
                $row['payload_json'] = substr($row['payload_json'], 0, 1000) . '... [truncated]';
            }
            $bundle['recent_drafts'][] = $row;
        }
        $stmt->close();
    }
}

// 4. Errores recientes (si existe la tabla)
$check = $conn->query("SHOW TABLES LIKE 'error_log'");
$has_error_log = ($check && $check->num_rows > 0);
if ($check) $check->close();

if ($has_error_log) {
    $stmt = $conn->prepare("
        SELECT error_id, endpoint, error_code, error_message, http_status, created_at, client_guid
        FROM error_log
        WHERE user_id = ? AND created_at >= ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    if ($stmt) {
        $stmt->bind_param("isi", $usuario_id, $since, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $bundle['recent_errors'][] = $row;
        }
        $stmt->close();
    }
}

// 5. Visitas abiertas (sin cerrar)
$stmt = $conn->prepare("
    SELECT v.id, v.id_formulario, v.id_local, v.fecha_inicio, v.client_guid,
           f.nombre as formulario_nombre,
           l.nombre as local_nombre,
           (SELECT COUNT(*) FROM fotoVisita fv WHERE fv.visita_id = v.id) as fotos_count,
           (SELECT COUNT(*) FROM gestion_visita gv WHERE gv.visita_id = v.id) as gestion_count
    FROM visita v
    LEFT JOIN formulario f ON f.id = v.id_formulario
    LEFT JOIN local l ON l.id = v.id_local
    WHERE v.id_usuario = ? AND v.fecha_fin IS NULL AND v.fecha_inicio >= ?
    ORDER BY v.fecha_inicio DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param("is", $usuario_id, $since);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bundle['open_visitas'][] = $row;
    }
    $stmt->close();
}

// 6. Fotos potencialmente huérfanas (visita no cerrada + foto antigua)
$orphan_since = date('Y-m-d H:i:s', time() - (3600 * 2)); // más de 2 horas
$stmt = $conn->prepare("
    SELECT fv.id, fv.url, fv.visita_id, v.fecha_inicio as visita_inicio, v.client_guid
    FROM fotoVisita fv
    JOIN visita v ON v.id = fv.visita_id
    WHERE fv.id_usuario = ?
      AND v.fecha_fin IS NULL
      AND v.fecha_inicio < ?
    ORDER BY v.fecha_inicio ASC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param("is", $usuario_id, $orphan_since);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bundle['orphan_photos'][] = $row;
    }
    $stmt->close();
}

// 7. Resumen de problemas detectados
$problems = [];

if (count($bundle['open_visitas']) > 5) {
    $problems[] = [
        'type' => 'many_open_visitas',
        'severity' => 'warning',
        'message' => 'Hay ' . count($bundle['open_visitas']) . ' visitas sin cerrar',
        'recommendation' => 'Verificar si hay gestiones pendientes de sincronización'
    ];
}

if (count($bundle['orphan_photos']) > 0) {
    $problems[] = [
        'type' => 'orphan_photos',
        'severity' => 'warning',
        'message' => 'Hay ' . count($bundle['orphan_photos']) . ' fotos en visitas sin cerrar con más de 2 horas',
        'recommendation' => 'Verificar estado de sincronización de esas gestiones'
    ];
}

$failed_drafts = array_filter($bundle['recent_drafts'], fn($d) => $d['status'] === 'failed');
if (count($failed_drafts) > 0) {
    $problems[] = [
        'type' => 'failed_drafts',
        'severity' => 'error',
        'message' => 'Hay ' . count($failed_drafts) . ' gestiones fallidas',
        'recommendation' => 'Revisar los error_code y error_message de cada draft'
    ];
}

$abandoned_drafts = array_filter($bundle['recent_drafts'], fn($d) => $d['status'] === 'abandoned');
if (count($abandoned_drafts) > 0) {
    $problems[] = [
        'type' => 'abandoned_drafts',
        'severity' => 'warning',
        'message' => 'Hay ' . count($abandoned_drafts) . ' gestiones abandonadas (timeout)',
        'recommendation' => 'Posible cierre de app durante sincronización'
    ];
}

$bundle['problems'] = $problems;
$bundle['has_problems'] = count($problems) > 0;

// Responder
api_response_ok($bundle);

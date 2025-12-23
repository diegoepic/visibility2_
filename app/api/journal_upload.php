<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'UNAUTHENTICATED',
        'message' => 'Sesión no válida. Vuelve a iniciar sesión.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../con_.php'; // $conn (mysqli)

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ($conn instanceof mysqli) {
    $conn->set_charset('utf8');
}

$user_id    = (int)($_SESSION['usuario_id'] ?? 0);
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'BAD_PAYLOAD',
        'message' => 'Payload inválido.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$events = $payload['events'] ?? [];
if (!is_array($events) || count($events) === 0) {
    echo json_encode([
        'status' => 'ok',
        'stored' => 0,
        'ids'    => []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sql = "
    INSERT INTO journal_event
      (event_id, job_id, created_at, type, status, http_status, error_code, message, attempts, url, payload, user_id, empresa_id)
    VALUES
      (?, ?, FROM_UNIXTIME(? / 1000), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      status = VALUES(status),
      http_status = VALUES(http_status),
      error_code = VALUES(error_code),
      message = VALUES(message),
      attempts = VALUES(attempts),
      url = VALUES(url),
      payload = VALUES(payload),
      updated_at = CURRENT_TIMESTAMP
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error'  => 'DB_PREPARE',
        'message'=> 'No se pudo preparar la consulta.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stored = 0;
$storedIds = [];

foreach ($events as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $eventId = (string)($ev['id'] ?? '');
    $jobId = (string)($ev['job_id'] ?? '');
    $created = (int)($ev['created'] ?? 0);
    $type = (string)($ev['type'] ?? '');
    $status = isset($ev['status']) ? (string)$ev['status'] : null;
    $httpStatus = isset($ev['http_status']) ? (int)$ev['http_status'] : null;
    $error = isset($ev['error']) ? (string)$ev['error'] : null;
    $message = isset($ev['message']) ? (string)$ev['message'] : null;
    $attempts = isset($ev['attempts']) ? (int)$ev['attempts'] : null;
    $url = isset($ev['url']) ? (string)$ev['url'] : null;
    $payloadJson = json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($eventId === '' || $jobId === '' || $created <= 0) {
        continue;
    }

    $stmt->bind_param(
        'ssissississi',
        $eventId,
        $jobId,
        $created,
        $type,
        $status,
        $httpStatus,
        $error,
        $message,
        $attempts,
        $url,
        $payloadJson,
        $user_id,
        $empresa_id
    );
    $stmt->execute();
    $stored++;
    $storedIds[] = $eventId;
}

$stmt->close();

echo json_encode([
    'status' => 'ok',
    'stored' => $stored,
    'ids'    => $storedIds
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

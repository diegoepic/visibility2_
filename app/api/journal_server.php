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

$user_id    = (int)($_SESSION['usuario_id']   ?? 0);
$empresa_id = (int)($_SESSION['empresa_id']   ?? 0);
$div_id     = (int)($_SESSION['division_id']  ?? 0);

// -----------------------------------------------------------------------------
// Helper: leer parámetros (GET o POST) y normalizar fechas
// -----------------------------------------------------------------------------
$source = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$from = isset($source['from']) ? trim((string)$source['from']) : '';
$to   = isset($source['to'])   ? trim((string)$source['to'])   : '';

$formulario_id = isset($source['formulario_id']) ? (int)$source['formulario_id'] : 0;
$local_id      = isset($source['local_id'])      ? (int)$source['local_id']      : 0;

// Si no mandan fechas, tomar HOY
$today = (new DateTime('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
if ($from === '' && $to === '') {
    $from = $today;
    $to   = $today;
} elseif ($from === '' && $to !== '') {
    $from = $to;
} elseif ($from !== '' && $to === '') {
    $to = $from;
}

// Validar formato YYYY-MM-DD
$reDate = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($reDate, $from) || !preg_match($reDate, $to)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'BAD_DATE',
        'message' => 'Parámetros from/to deben tener formato YYYY-MM-DD.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Limitar rango a, por ejemplo, 31 días para que no explote
try {
    $dFrom = new DateTime($from);
    $dTo   = new DateTime($to);
    if ($dFrom > $dTo) {
        // si lo mandan al revés, los invertimos
        [$dFrom, $dTo] = [$dTo, $dFrom];
        $from = $dFrom->format('Y-m-d');
        $to   = $dTo->format('Y-m-d');
    }
    $diffDays = (int)$dFrom->diff($dTo)->format('%a');
    if ($diffDays > 31) {
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'error'   => 'RANGE_TOO_WIDE',
            'message' => 'El rango máximo permitido es de 31 días.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'BAD_DATE',
        'message' => 'Fechas inválidas.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// Construir SQL: resumen por visita
// Tablas principales: visita, formulario, local, distrito
// Joins de agregados: gestion_visita, fotoVisita, form_question_responses
// -----------------------------------------------------------------------------

$sql = "
SELECT
    v.id                         AS visita_id,
    v.client_guid                AS client_guid,
    v.id_local                   AS id_local,
    l.codigo                     AS local_codigo,
    l.nombre                     AS local_nombre,
    l.direccion                  AS local_direccion,
    l.id_distrito                AS id_distrito,
    dstr.nombre_distrito         AS distrito_nombre,

    v.id_formulario              AS id_formulario,
    f.nombre                     AS formulario_nombre,
    f.tipo                       AS formulario_tipo,
    f.modalidad                  AS modalidad,

    v.fecha_inicio               AS fecha_inicio,
    v.fecha_fin                  AS fecha_fin,
    TIMESTAMPDIFF(
        SECOND,
        v.fecha_inicio,
        COALESCE(v.fecha_fin, NOW())
    )                            AS duracion_seg,

    COUNT(DISTINCT gv.id)        AS gestiones_totales,
    COUNT(DISTINCT gv.id_material) AS materiales_distintos,

    COUNT(DISTINCT fv.id)        AS fotos_totales,
    COUNT(DISTINCT CASE WHEN fv.id_material IS NOT NULL THEN fv.id END)          AS fotos_material,
    COUNT(DISTINCT CASE WHEN fv.id_formularioQuestion IS NOT NULL THEN fv.id END) AS fotos_pregunta,

    COUNT(DISTINCT fqr.id_form_question) AS preguntas_respondidas,

    MAX(gv.fecha_visita)         AS ultima_gestion_at
FROM visita v
INNER JOIN formulario f   ON f.id = v.id_formulario
INNER JOIN local l        ON l.id = v.id_local
LEFT JOIN distrito dstr   ON dstr.id = l.id_distrito
LEFT JOIN gestion_visita gv
       ON gv.visita_id = v.id
LEFT JOIN fotoVisita fv
       ON fv.visita_id = v.id
LEFT JOIN form_question_responses fqr
       ON fqr.visita_id = v.id
WHERE
    v.id_usuario = ?
    AND f.id_empresa = ?
    AND DATE(v.fecha_inicio) BETWEEN ? AND ?
";

$params   = [];
$types    = 'iiss'; // user_id (i), empresa_id (i), from (s), to (s)

$params[] = $user_id;
$params[] = $empresa_id;
$params[] = $from;
$params[] = $to;

// Filtro opcional por formulario
if ($formulario_id > 0) {
    $sql      .= " AND v.id_formulario = ? ";
    $types    .= 'i';
    $params[] = $formulario_id;
}

// Filtro opcional por local
if ($local_id > 0) {
    $sql      .= " AND v.id_local = ? ";
    $types    .= 'i';
    $params[] = $local_id;
}

$sql .= "
GROUP BY
    v.id
ORDER BY
    v.fecha_inicio DESC,
    v.id DESC
LIMIT 500
";

try {
    /** @var mysqli_stmt $stmt */
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta.');
    }

    // bind_param dinámico
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Normalizar tipos numéricos
        $items[] = [
            'visita_id'            => (int)$row['visita_id'],
            'client_guid'          => $row['client_guid'] ?? null,

            'local' => [
                'id'         => (int)$row['id_local'],
                'codigo'     => $row['local_codigo'],
                'nombre'     => $row['local_nombre'],
                'direccion'  => $row['local_direccion'],
                'distrito'   => [
                    'id'     => isset($row['id_distrito']) ? (int)$row['id_distrito'] : null,
                    'nombre' => $row['distrito_nombre'] ?? null,
                ],
            ],

            'formulario' => [
                'id'        => (int)$row['id_formulario'],
                'nombre'    => $row['formulario_nombre'],
                'tipo'      => isset($row['formulario_tipo']) ? (int)$row['formulario_tipo'] : null,
                'modalidad' => $row['modalidad'],
            ],

            'tiempos' => [
                'fecha_inicio'  => $row['fecha_inicio'],
                'fecha_fin'     => $row['fecha_fin'],
                'duracion_seg'  => isset($row['duracion_seg']) ? (int)$row['duracion_seg'] : null,
                'ultima_gestion_at' => $row['ultima_gestion_at'],
            ],

            'conteos' => [
                'gestiones_totales'    => (int)$row['gestiones_totales'],
                'materiales_distintos' => (int)$row['materiales_distintos'],

                'fotos_totales'   => (int)$row['fotos_totales'],
                'fotos_material'  => (int)$row['fotos_material'],
                'fotos_pregunta'  => (int)$row['fotos_pregunta'],

                'preguntas_respondidas' => (int)$row['preguntas_respondidas'],
            ],
        ];
    }

    $stmt->close();

    echo json_encode([
        'status'  => 'ok',
        'from'    => $from,
        'to'      => $to,
        'count'   => count($items),
        'items'   => $items
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    // No exponemos el mensaje real en producción; si quieres, loguéalo en error_log.
    echo json_encode([
        'status'  => 'error',
        'error'   => 'SERVER_ERROR',
        'message' => 'Ocurrió un error al consultar el resumen de gestiones.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

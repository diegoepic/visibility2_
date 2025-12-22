<?php
declare(strict_types=1);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'status' => 'no_session',
            'error_code' => 'NO_SESSION',
            'message' => 'Sesión expirada',
            'retryable' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('DB connection not available');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');

    // -------- Contexto usuario
    $usuario_id  = (int)($_SESSION['usuario_id']  ?? 0);
    $empresa_id  = (int)($_SESSION['empresa_id']  ?? 0);
    $division_id = (int)($_SESSION['division_id'] ?? 0);

    // -------- Parámetros de rango de fechas
    $tz  = new DateTimeZone('America/Santiago');
    $now = new DateTime('now', $tz);

    $from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])
        ? (string)$_GET['from'] : $now->format('Y-m-d');
    $to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])
        ? (string)$_GET['to'] : $from;

    $reagendadosDays = isset($_GET['reagendados_days']) && is_numeric($_GET['reagendados_days'])
        ? max(0, (int)$_GET['reagendados_days']) : 7;

    // (reservado) delta_since
    $deltaSince = null;
    if (!empty($_GET['delta_since'])) {
        $tmp = date_create((string)$_GET['delta_since']);
        if ($tmp !== false) {
            $deltaSince = $tmp->format('Y-m-d H:i:00'); // minuto-resolución
        }
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    // -------- Helpers
    $inClause = function(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return ['placeholders' => 'NULL', 'types' => '', 'values' => []];
        }
        return [
            'placeholders' => implode(',', array_fill(0, count($ids), '?')),
            'types'        => str_repeat('i', count($ids)),
            'values'       => $ids,
        ];
    };

    // -------- 1) Campañas relevantes (para ETag y alcance)
    $sqlCamp = "
        SELECT DISTINCT
            f.id,
            f.nombre,
            f.tipo,
            f.estado,
            f.fechaInicio,
            f.fechaTermino,
            GREATEST(
                IFNULL(f.fechaInicio,  '1970-01-01'),
                IFNULL(f.fechaTermino, '1970-01-01')
            ) AS updated_at
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        WHERE fq.id_usuario = ?
          AND f.id_empresa  = ?
          AND (f.id_division = ? OR ? = 0)
    ";
    if ($deltaSince) {
        $sqlCamp .= "
          AND GREATEST(
                IFNULL(f.fechaInicio,  '1970-01-01 00:00:00'),
                IFNULL(f.fechaTermino, '1970-01-01 00:00:00')
          ) >= ?
        ";
    }

    $stmt = $conn->prepare($sqlCamp);
    if ($deltaSince) {
        // 4 ints + 1 string
        $stmt->bind_param('iiiis', $usuario_id, $empresa_id, $division_id, $division_id, $deltaSince);
    } else {
        $stmt->bind_param('iiii',  $usuario_id, $empresa_id, $division_id, $division_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $campaigns = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $campaignIds = array_map('intval', array_column($campaigns, 'id'));

    // -------- 2) Agenda "programados" entre [from, to]
    $sqlProg = "
        SELECT
            fq.id_formulario, f.nombre AS nombre_campana,
            fq.id_local, fq.is_priority, DATE(fq.fechaPropuesta) AS fechaPropuesta,
            fq.estado, fq.countVisita,
            l.codigo, l.nombre AS local_nombre, l.direccion, l.lat, l.lng,
            c.nombre AS cadena, co.comuna AS comuna
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN local l       ON l.id = fq.id_local
        INNER JOIN cadena c      ON c.id = l.id_cadena
        LEFT  JOIN comuna co     ON co.id = l.id_comuna
        WHERE fq.id_usuario = ?
          AND f.id_empresa  = ?
          AND (f.id_division = ? OR ? = 0)
          AND f.tipo IN (3,1)
          AND (fq.countVisita IS NULL OR fq.countVisita = 0)
          AND DATE(fq.fechaPropuesta) BETWEEN ? AND ?
        ORDER BY fq.fechaPropuesta ASC, c.nombre, l.direccion
    ";
    $stmt = $conn->prepare($sqlProg);
    $stmt->bind_param('iiisss', $usuario_id, $empresa_id, $division_id, $division_id, $from, $to);
    $stmt->execute();
    $progRes = $stmt->get_result();
    $programados = $progRes->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // -------- 3) Agenda "reagendados" recientes
    $sqlReag = "
        SELECT
            fq.id_formulario, f.nombre AS nombre_campana,
            fq.id_local, fq.is_priority, DATE(fq.fechaPropuesta) AS fechaPropuesta,
            fq.estado, fq.countVisita,
            l.codigo, l.nombre AS local_nombre, l.direccion, l.lat, l.lng,
            c.nombre AS cadena, co.comuna AS comuna,
            (CASE WHEN fq.pregunta = 'en proceso' THEN 1 ELSE 0 END) AS flag_en_proceso
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN local l       ON l.id = fq.id_local
        INNER JOIN cadena c      ON c.id = l.id_cadena
        LEFT  JOIN comuna co     ON co.id = l.id_comuna
        WHERE fq.id_usuario = ?
          AND f.id_empresa  = ?
          AND (f.id_division = ? OR ? = 0)
          AND f.tipo IN (3,1)
          AND (fq.countVisita IS NULL OR fq.countVisita = 0)
          AND DATE(fq.fechaPropuesta) < ?
          AND DATE(fq.fechaPropuesta) >= DATE_SUB(?, INTERVAL ? DAY)
        ORDER BY fq.fechaPropuesta DESC
        LIMIT 500
    ";
    $stmt = $conn->prepare($sqlReag);
    $stmt->bind_param('iiisssi', $usuario_id, $empresa_id, $division_id, $division_id, $from, $from, $reagendadosDays);
    $stmt->execute();
    $reagRes = $stmt->get_result();
    $reagendados = $reagRes->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // -------- 4) Construir "agenda"
    $agenda = [];

    $pushAgenda = function(array $row, bool $isReag) use (&$agenda) {
        $agenda[] = [
            'fechaPropuesta' => (string)$row['fechaPropuesta'],
            'reagendado'     => $isReag,
            'local' => [
                'id_local'  => (int)$row['id_local'],
                'nombre'    => (string)($row['local_nombre'] ?? ''),
                'direccion' => (string)($row['direccion'] ?? ''),
                'comuna'    => (string)($row['comuna'] ?? ''),
                'cadena'    => (string)($row['cadena'] ?? ''),
            ],
            'camp'  => [
                'id_formulario' => (int)$row['id_formulario'],
                'nombre'        => (string)($row['nombre_campana'] ?? ''),
            ],
        ];
    };

    foreach ($programados as $r) { $pushAgenda($r, false); }
    foreach ($reagendados as $r) { $pushAgenda($r, true); }

    // -------- 5) Activos a precachear
    $APP_SCOPE = '/visibility2/app';
    $assets = [
        [ 'url' => "$APP_SCOPE/assets/plugins/bootstrap/css/bootstrap.min.css" ],
        [ 'url' => "$APP_SCOPE/assets/plugins/font-awesome/css/font-awesome.min.css" ],
        [ 'url' => "$APP_SCOPE/assets/css/main.css" ],
        [ 'url' => "$APP_SCOPE/assets/css/main-responsive.css" ],
        [ 'url' => "$APP_SCOPE/assets/plugins/bootstrap/js/bootstrap.min.js" ],
        [ 'url' => "$APP_SCOPE/assets/js/db.js" ],
        [ 'url' => "$APP_SCOPE/assets/js/offline-queue.js" ],
       // [ 'url' => "$APP_SCOPE/assets/js/v2_cache.js" ],
        //[ 'url' => "$APP_SCOPE/assets/js/bootstrap_index_cache.js" ],
        [ 'url' => "$APP_SCOPE/gestionar_spa.html" ],
        [ 'url' => "$APP_SCOPE/assets/js/gestionar_spa.js" ],
        [ 'url' => "$APP_SCOPE/assets/css/offline.css" ],
    ];

    // -------- 6) ETag / max updated
    $routeLocalIds = array_map('intval', array_unique(array_merge(
        array_column($programados, 'id_local'),
        array_column($reagendados, 'id_local')
    )));
    $routeFormIds = array_map('intval', array_unique(array_merge(
        array_column($programados, 'id_formulario'),
        array_column($reagendados, 'id_formulario'),
        $campaignIds
    )));

    $maxUpd = [
        'formulario' => '1970-01-01 00:00:00',
        'local'      => '1970-01-01 00:00:00',
        'material'   => '1970-01-01 00:00:00',
        'form_q'     => '1970-01-01 00:00:00',
    ];

    // formulario
    if (!empty($routeFormIds)) {
        $in = $inClause($routeFormIds);
        if ($in['placeholders'] !== 'NULL') {
            $stmt = $conn->prepare(
                "SELECT MAX(COALESCE(updated_at, '1970-01-01 00:00:00')) AS mx FROM formulario WHERE id IN ({$in['placeholders']})"
            );
            $stmt->bind_param($in['types'], ...$in['values']);
            $stmt->execute();
            $mx = $stmt->get_result()->fetch_assoc();
            $maxUpd['formulario'] = (string)($mx['mx'] ?? $maxUpd['formulario']);
            $stmt->close();
        }
    }

    // local
    if (!empty($routeLocalIds)) {
        $in = $inClause($routeLocalIds);
        if ($in['placeholders'] !== 'NULL') {
            $stmt = $conn->prepare(
                "SELECT MAX(COALESCE(updated_at, '1970-01-01 00:00:00')) AS mx FROM local WHERE id IN ({$in['placeholders']})"
            );
            $stmt->bind_param($in['types'], ...$in['values']);
            $stmt->execute();
            $mx = $stmt->get_result()->fetch_assoc();
            $maxUpd['local'] = (string)($mx['mx'] ?? $maxUpd['local']);
            $stmt->close();
        }
    }

    // material (por división)
    $stmt = $conn->prepare(
        "SELECT MAX(COALESCE(updated_at, '1970-01-01 00:00:00')) AS mx FROM material WHERE (id_division = ? OR ? = 0)"
    );
    $stmt->bind_param('ii', $division_id, $division_id);
    $stmt->execute();
    $mx = $stmt->get_result()->fetch_assoc();
    $maxUpd['material'] = (string)($mx['mx'] ?? $maxUpd['material']);
    $stmt->close();

    // form_questions
    if (!empty($routeFormIds)) {
        $in = $inClause($routeFormIds);
        if ($in['placeholders'] !== 'NULL') {
            $stmt = $conn->prepare(
                "SELECT MAX(COALESCE(updated_at, '1970-01-01 00:00:00')) AS mx FROM form_questions WHERE deleted_at IS NULL AND id_formulario IN ({$in['placeholders']})"
            );
            $stmt->bind_param($in['types'], ...$in['values']);
            $stmt->execute();
            $mx = $stmt->get_result()->fetch_assoc();
            $maxUpd['form_q'] = (string)($mx['mx'] ?? $maxUpd['form_q']);
            $stmt->close();
        }
    }

    $etagPayload = json_encode([
        'usuario' => $usuario_id,
        'empresa' => $empresa_id,
        'division'=> $division_id,
        'from'    => $from,
        'to'      => $to,
        'reag'    => $reagendadosDays,
        'forms'   => $routeFormIds,
        'locals'  => $routeLocalIds,
        'max'     => $maxUpd,
    ], $jsonFlags);
    $etag = substr(sha1($etagPayload ?: ''), 0, 32);

    // If-None-Match handling
    $clientEtag = '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            $clientEtag = $h['If-None-Match'] ?? $h['if-none-match'] ?? '';
        }
    }
    if (!$clientEtag && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $clientEtag = (string)$_SERVER['HTTP_IF_NONE_MATCH'];
    }

    if ($clientEtag && trim($clientEtag, '"') === $etag) {
        header('ETag: ' . $etag);
        http_response_code(304);
        exit;
    }

    // -------- 7) Locales detallados
    $locales = [];
    if (!empty($routeLocalIds)) {
        $in = $inClause($routeLocalIds);
        if ($in['placeholders'] !== 'NULL') {
            $sqlLoc = "
                SELECT l.id, l.codigo, l.nombre, l.direccion, l.lat, l.lng, l.id_comuna,
                       co.comuna
                  FROM local l
                  LEFT JOIN comuna co ON co.id = l.id_comuna
                 WHERE l.id IN ({$in['placeholders']})
            ";
            $stmt = $conn->prepare($sqlLoc);
            $stmt->bind_param($in['types'], ...$in['values']);
            $stmt->execute();
            $locales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    // -------- 8) Preguntas + opciones por formulario
    $questionsByForm = [];
    if (!empty($routeFormIds)) {
        $inForms = $inClause($routeFormIds);
        if ($inForms['placeholders'] !== 'NULL') {
            $sqlQ = "
                SELECT id, id_formulario, question_text, id_question_type, is_required, is_valued, sort_order
                  FROM form_questions
                 WHERE deleted_at IS NULL
                   AND id_formulario IN ({$inForms['placeholders']})
                 ORDER BY id_formulario, sort_order, id
            ";
            $stmt = $conn->prepare($sqlQ);
            $stmt->bind_param($inForms['types'], ...$inForms['values']);
            $stmt->execute();
            $qRes = $stmt->get_result();
            $questions = $qRes->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $qIds = array_map('intval', array_column($questions, 'id'));
            $optionsByQ = [];
            if (!empty($qIds)) {
                $inQ = $inClause($qIds);
                if ($inQ['placeholders'] !== 'NULL') {
                    $sqlOpt = "
                        SELECT id, id_form_question, option_text, sort_order, reference_image
                          FROM form_question_options
                         WHERE deleted_at IS NULL
                           AND id_form_question IN ({$inQ['placeholders']})
                         ORDER BY id_form_question, sort_order, id
                    ";
                    $stmt = $conn->prepare($sqlOpt);
                    $stmt->bind_param($inQ['types'], ...$inQ['values']);
                    $stmt->execute();
                    $optRes = $stmt->get_result();
                    while ($row = $optRes->fetch_assoc()) {
                        $qid = (int)$row['id_form_question'];
                        if (!isset($optionsByQ[$qid])) { $optionsByQ[$qid] = []; }
                        $optionsByQ[$qid][] = [
                            'id'                => (int)$row['id'],
                            'id_form_question'  => $qid,
                            'option_text'       => (string)($row['option_text'] ?? ''),
                            'sort_order'        => (int)($row['sort_order'] ?? 0),
                            'reference_image'   => $row['reference_image'] !== null ? (string)$row['reference_image'] : null,
                        ];
                    }
                    $stmt->close();
                }
            }

            foreach ($questions as $q) {
                $fid = (int)$q['id_formulario'];
                if (!isset($questionsByForm[$fid])) {
                    $questionsByForm[$fid] = [
                        'formulario_id' => $fid,
                        'preguntas'     => [],
                    ];
                }
                $qid = (int)$q['id'];
                $questionsByForm[$fid]['preguntas'][] = [
                    'id'               => $qid,
                    'question_text'    => (string)($q['question_text'] ?? ''),
                    'id_question_type' => (int)($q['id_question_type'] ?? 0),
                    'is_required'      => (int)($q['is_required'] ?? 0),
                    'is_valued'        => (int)($q['is_valued'] ?? 0),
                    'options'          => $optionsByQ[$qid] ?? [],
                ];
            }
        }
    }

    // -------- 9) Respuesta final
    $bundle = [
        'manifest' => [
            'etag' => $etag,
            'generated_at' => (new DateTime('now', $tz))->format(DateTime::ATOM),
            'date_range' => [
                'from' => $from,
                'to'   => $to,
                'route_date' => $to,
                'reagendados_days' => $reagendadosDays,
            ],
        ],
        'etag'  => $etag,
        'from'  => $from,
        'to'    => $to,
        'date'  => $to,
        'campaigns' => $campaigns,
        'locales'   => $locales,
        'route' => [
            'programados' => $programados,
            'reagendados' => $reagendados,
        ],
        'agenda'    => $agenda,
        'questions' => array_values($questionsByForm),
        'assets'    => $assets,
    ];

    header('ETag: ' . $etag);
    echo json_encode($bundle, $jsonFlags);
} catch (Throwable $e) {
    http_response_code(500);
    $msg = $e->getMessage();
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
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

// -----------------------------------------------------------------------------
// Leer parámetro visita_id
// -----------------------------------------------------------------------------
$source = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$visita_id = isset($source['visita_id']) ? (int)$source['visita_id'] : 0;

if ($visita_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'BAD_REQUEST',
        'message' => 'visita_id es requerido y debe ser numérico.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    
    $sqlHead = "
        SELECT
            v.id AS visita_id,
            v.client_guid AS client_guid,
            v.id_usuario AS id_usuario,
            v.id_local AS id_local,
            v.id_formulario AS id_formulario,
            v.fecha_inicio AS fecha_inicio,
            v.fecha_fin AS fecha_fin,
            v.latitud AS latitud,
            v.longitud AS longitud,

            f.nombre AS formulario_nombre,
            f.tipo AS formulario_tipo,
            f.modalidad AS modalidad,

            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            l.id_distrito AS id_distrito,
            dstr.nombre_distrito AS distrito_nombre,

            u.nombre AS usuario_nombre,
            u.apellido AS usuario_apellido
        FROM visita v
        INNER JOIN formulario f   ON f.id = v.id_formulario
        INNER JOIN local      l   ON l.id = v.id_local
        LEFT JOIN distrito    dstr ON dstr.id = l.id_distrito
        LEFT JOIN usuario     u   ON u.id = v.id_usuario
        WHERE
            v.id = ?
            AND v.id_usuario = ?
            AND f.id_empresa = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sqlHead);
    $stmt->bind_param('iii', $visita_id, $user_id, $empresa_id);
    $stmt->execute();
    $resHead = $stmt->get_result();
    $head = $resHead->fetch_assoc();
    $stmt->close();

    if (!$head) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'error'   => 'NOT_FOUND',
            'message' => 'Visita no encontrada o no pertenece a este usuario.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Normalizar encabezado
    $basic = [
        'visita_id'   => (int)$head['visita_id'],
        'client_guid' => $head['client_guid'] ?? null,
        'tiempos' => [
            'fecha_inicio' => $head['fecha_inicio'],
            'fecha_fin'    => $head['fecha_fin'],
            'latitud'      => $head['latitud'],
            'longitud'     => $head['longitud'],
        ],
        'local' => [
            'id'         => (int)$head['id_local'],
            'codigo'     => $head['local_codigo'],
            'nombre'     => $head['local_nombre'],
            'direccion'  => $head['local_direccion'],
            'distrito'   => [
                'id'     => isset($head['id_distrito']) ? (int)$head['id_distrito'] : null,
                'nombre' => $head['distrito_nombre'] ?? null,
            ],
        ],
        'formulario' => [
            'id'        => (int)$head['id_formulario'],
            'nombre'    => $head['formulario_nombre'],
            'tipo'      => isset($head['formulario_tipo']) ? (int)$head['formulario_tipo'] : null,
            'modalidad' => $head['modalidad'],
        ],
        'usuario' => [
            'id'        => (int)$head['id_usuario'],
            'nombre'    => $head['usuario_nombre'],
            'apellido'  => $head['usuario_apellido'],
        ]
    ];

    // -------------------------------------------------------------------------
    // 2) Todas las fotos de la visita (las usaremos para materiales + encuesta)
    // -------------------------------------------------------------------------
    $sqlFotos = "
        SELECT
            id,
            url,
            id_material,
            id_formularioQuestion,
            fotoLat,
            fotoLng
        FROM fotoVisita
        WHERE visita_id = ?
        ORDER BY id ASC
    ";
    $stmt = $conn->prepare($sqlFotos);
    $stmt->bind_param('i', $visita_id);
    $stmt->execute();
    $resFotos = $stmt->get_result();
    $photos = [];
    $photosById = [];
    $photosByMaterial = [];
    $photosByQuestion = [];
    $photosMisc = [];

    while ($row = $resFotos->fetch_assoc()) {
        $p = [
            'id'   => (int)$row['id'],
            'url'  => $row['url'],
            'id_material'          => $row['id_material']        !== null ? (int)$row['id_material'] : null,
            'id_form_question'     => $row['id_formularioQuestion'] !== null ? (int)$row['id_formularioQuestion'] : null,
            'lat'  => $row['fotoLat'],
            'lng'  => $row['fotoLng'],
        ];
        $photos[] = $p;
        $photosById[$p['id']] = $p;

        if ($p['id_material'] !== null) {
            $mid = $p['id_material'];
            $photosByMaterial[$mid] = $photosByMaterial[$mid] ?? [];
            $photosByMaterial[$mid][] = $p;
        } elseif ($p['id_form_question'] !== null) {
            $qid = $p['id_form_question'];
            $photosByQuestion[$qid] = $photosByQuestion[$qid] ?? [];
            $photosByQuestion[$qid][] = $p;
        } else {
            $photosMisc[] = $p;
        }
    }
    $stmt->close();

    // -------------------------------------------------------------------------
    // 3) Implementación / gestión de materiales (gestion_visita + material)
    // -------------------------------------------------------------------------
    $sqlGest = "
        SELECT
            gv.id,
            gv.id_material,
            gv.id_formularioQuestion,
            gv.estado_gestion,
            gv.observacion,
            gv.valor_real,
            gv.motivo_no_implementacion,
            gv.fecha_visita,
            m.nombre AS material_nombre
        FROM gestion_visita gv
        LEFT JOIN material m ON m.id = gv.id_material
        WHERE gv.visita_id = ?
        ORDER BY gv.fecha_visita ASC, gv.id ASC
    ";

    $stmt = $conn->prepare($sqlGest);
    $stmt->bind_param('i', $visita_id);
    $stmt->execute();
    $resGest = $stmt->get_result();

    $materialsById = [];
    while ($row = $resGest->fetch_assoc()) {
        $mid = ($row['id_material'] !== null) ? (int)$row['id_material'] : 0;
        if (!isset($materialsById[$mid])) {
            $materialsById[$mid] = [
                'material_id'   => $mid ?: null,
                'material_nombre' => $row['material_nombre'] ?: 'Sin material',
                'gestiones'     => [],
                'photos'        => [],
            ];
        }
        $materialsById[$mid]['gestiones'][] = [
            'id'                    => (int)$row['id'],
            'id_form_question'      => $row['id_formularioQuestion'] !== null ? (int)$row['id_formularioQuestion'] : null,
            'estado_gestion'        => $row['estado_gestion'],
            'observacion'           => $row['observacion'],
            'valor_real'            => $row['valor_real'],
            'motivo_no_implementacion' => $row['motivo_no_implementacion'],
            'fecha_visita'          => $row['fecha_visita'],
        ];
    }
    $stmt->close();

    // Adjuntar fotos a cada material (por id_material)
    foreach ($materialsById as $mid => &$mat) {
        if ($mid && isset($photosByMaterial[$mid])) {
            $mat['photos'] = $photosByMaterial[$mid];
        }
    }
    unset($mat);

    // Materiales como lista
    $materials = array_values($materialsById);

    // -------------------------------------------------------------------------
    // 4) Respuestas de encuesta por pregunta
    // -------------------------------------------------------------------------
    $sqlResp = "
        SELECT
            r.id                      AS response_id,
            r.id_form_question        AS id_form_question,
            r.answer_text,
            r.valor,
            r.id_option,
            r.foto_visita_id,
            r.created_at,

            q.question_text,
            q.id_question_type,
            qt.name                   AS question_type_name,
            q.is_required,
            q.is_valued,
            q.sort_order,

            opt.option_text
        FROM form_question_responses r
        INNER JOIN form_questions q
                ON q.id = r.id_form_question
        LEFT JOIN question_type qt
                ON qt.id = q.id_question_type
        LEFT JOIN form_question_options opt
                ON opt.id = r.id_option
        WHERE r.visita_id = ?
        ORDER BY q.sort_order ASC, q.id ASC, r.id ASC
    ";

    $stmt = $conn->prepare($sqlResp);
    $stmt->bind_param('i', $visita_id);
    $stmt->execute();
    $resResp = $stmt->get_result();

    $questionsById = [];
    $totalQuestionsAnswered = 0;

    while ($row = $resResp->fetch_assoc()) {
        $qid = (int)$row['id_form_question'];
        if (!isset($questionsById[$qid])) {
            $questionsById[$qid] = [
                'id_form_question'   => $qid,
                'question_text'      => $row['question_text'],
                'question_type'      => [
                    'id'   => $row['id_question_type'] !== null ? (int)$row['id_question_type'] : null,
                    'name' => $row['question_type_name'] ?? null,
                ],
                'is_required'        => (bool)$row['is_required'],
                'is_valued'          => (bool)$row['is_valued'],
                'answers'            => [],
                'photos'             => [], // fotos adicionales por id_form_question
            ];
            $totalQuestionsAnswered++;
        }

        $respPhotos = [];
        $fotoId = $row['foto_visita_id'] !== null ? (int)$row['foto_visita_id'] : null;
        if ($fotoId && isset($photosById[$fotoId])) {
            $respPhotos[] = $photosById[$fotoId];
        }

        $questionsById[$qid]['answers'][] = [
            'response_id'   => (int)$row['response_id'],
            'answer_text'   => $row['answer_text'],
            'valor'         => $row['valor'],
            'id_option'     => $row['id_option'] !== null ? (int)$row['id_option'] : null,
            'option_text'   => $row['option_text'],
            'foto_visita_id'=> $fotoId,
            'photos'        => $respPhotos,
            'created_at'    => $row['created_at'],
        ];
    }
    $stmt->close();

    // Adjuntar fotos asociadas por id_form_question (además de foto_visita_id)
    foreach ($photosByQuestion as $qid => $pfotos) {
        if (isset($questionsById[$qid])) {
            // evitar duplicados por id
            $existing = [];
            foreach ($questionsById[$qid]['photos'] as $p) {
                $existing[$p['id']] = true;
            }
            foreach ($pfotos as $p) {
                if (!isset($existing[$p['id']])) {
                    $questionsById[$qid]['photos'][] = $p;
                    $existing[$p['id']] = true;
                }
            }
        }
    }

    $survey = [
        'total_questions_answered' => $totalQuestionsAnswered,
        'questions'                => array_values($questionsById),
    ];

    // -------------------------------------------------------------------------
    // 5) Conteos globales para este detalle (coherentes con el resumen)
    // -------------------------------------------------------------------------
    $summary = [
        'materials_count'   => count(array_filter($materials, function($m){
            return $m['material_id'] !== null;
        })),
        'photos_total'      => count($photos),
        'photos_material'   => count($photosByMaterial, COUNT_NORMAL),
        'photos_question'   => count($photosByQuestion, COUNT_NORMAL),
        'photos_misc'       => count($photosMisc),
        'questions_answered'=> $totalQuestionsAnswered,
    ];

    // -------------------------------------------------------------------------
    // Respuesta final
    // -------------------------------------------------------------------------
    echo json_encode([
        'status'   => 'ok',
        'visita_id'=> $visita_id,
        'basic'    => $basic,
        'summary'  => $summary,
        'materials'=> $materials,
        'survey'   => $survey,
        'photos_misc' => $photosMisc,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'error'   => 'SERVER_ERROR',
        'message' => 'Ocurrió un error al obtener el detalle de la visita.'
        // Si quieres, loguea $e->getMessage() en error_log().
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

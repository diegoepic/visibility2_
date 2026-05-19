<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

error_reporting(0);
ini_set('display_errors', 0);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($conn) || !$conn) {
    echo json_encode([
        'ok' => false,
        'msg' => 'No se pudo conectar a la base de datos.'
    ]);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

$formularioId = 138;
$baseUrl = 'https://visibility.cl/visibility2/app/';

$preguntasPermitidas = [
    'INGRESE KILOMETRAJE DE LA CAMIONETA:',
    'FOTO ODOMETRO',
    'FOTO FRONTAL DEL VEHICULO',
    'FOTO LATERAL DERECHO DEL VEHICULO',
    'FOTO LATERAL IZQUIERDO DEL VEHICULO',
    'FOTO TRASERA DEL VEHICULO',
    'FOTO INTERIOR DEL VEHICULO',
    'FOTO DE LA TARJETA COPEC'
];

$preguntasPermitidasUpper = array_map(function ($txt) {
    return mb_strtoupper(trim($txt), 'UTF-8');
}, $preguntasPermitidas);

$placeholdersPreguntas = implode(',', array_fill(0, count($preguntasPermitidasUpper), '?'));

$conn->query("SET SESSION group_concat_max_len = 1000000");

function codificar_path_url_reporte($path) {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    $parts = explode('/', $path);

    $encoded = array_map(function ($part) {
        return rawurlencode($part);
    }, $parts);

    return implode('/', $encoded);
}

function limpiar_ruta_foto_reporte($valor) {
    $valor = trim(html_entity_decode((string)$valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $valor = str_replace('\\', '/', $valor);
    $valor = ltrim($valor, '/');

    /*
     * Evita duplicar visibility2/app cuando answer_text ya viene con esa ruta.
     * Ejemplo:
     * visibility2/app/uploads/fotos_IW/archivo.webp
     * queda:
     * uploads/fotos_IW/archivo.webp
     */
    $valor = preg_replace('#^(visibility2/app/)+#i', '', $valor);

    return $valor;
}

function normalizar_url_foto_reporte($valor, $baseUrl) {
    $valor = trim(html_entity_decode((string)$valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($valor === '') {
        return '';
    }

    /*
     * Si ya viene como URL completa, también limpiamos duplicados internos.
     */
    if (preg_match('/^https?:\/\//i', $valor)) {
        $valor = preg_replace(
            '#https://visibility\.cl/visibility2/app/visibility2/app/#i',
            'https://visibility.cl/visibility2/app/',
            $valor
        );

        $valor = preg_replace(
            '#http://visibility\.cl/visibility2/app/visibility2/app/#i',
            'https://visibility.cl/visibility2/app/',
            $valor
        );

        return $valor;
    }

    $rel = limpiar_ruta_foto_reporte($valor);

    $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $appFs = $documentRoot . '/visibility2/app/';
    $appUrl = rtrim($baseUrl, '/') . '/';

    $candidatos = [];

    /*
     * Caso normal:
     * uploads/fotos_IW/2026-05-18/archivo.webp
     */
    $candidatos[] = $rel;

    /*
     * Si viene solo el nombre del archivo, buscamos en carpetas posibles.
     */
    if (strpos($rel, '/') === false) {
        $candidatos[] = 'uploads/fotos_IW/' . $rel;
        $candidatos[] = 'uploads/' . $rel;
        $candidatos[] = 'uploads/fotos/' . $rel;
        $candidatos[] = 'uploads/fotos_visitas/' . $rel;
        $candidatos[] = 'uploads/visitas/' . $rel;
        $candidatos[] = 'uploads/form_question_responses/' . $rel;
        $candidatos[] = 'uploads/complementarias/' . $rel;
    }

    foreach ($candidatos as $candidato) {
        $rutaFisica = $appFs . $candidato;

        if (is_file($rutaFisica)) {
            return $appUrl . codificar_path_url_reporte($candidato);
        }
    }

    /*
     * Fallback limpio.
     */
    return $appUrl . codificar_path_url_reporte($rel);
}

function nombre_foto_reporte($url) {
    $path = parse_url($url, PHP_URL_PATH);

    if (!$path) {
        return basename($url);
    }

    return basename($path);
}

/* =========================================================
   1) Obtener preguntas del formulario 138
   ========================================================= */
$questions = [];

$sqlPreguntas = "
    SELECT 
        id,
        question_text,
        id_question_type,
        is_valued,
        sort_order
    FROM form_questions
    WHERE id_formulario = ?
      AND UPPER(TRIM(question_text)) IN ($placeholdersPreguntas)
    ORDER BY FIELD(
        UPPER(TRIM(question_text)),
        $placeholdersPreguntas
    ), sort_order ASC, id ASC
";

$stmtPreguntas = $conn->prepare($sqlPreguntas);

if (!$stmtPreguntas) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al preparar preguntas del reporte.'
    ]);
    exit;
}

$typesPreguntas = 'i' . str_repeat('s', count($preguntasPermitidasUpper)) . str_repeat('s', count($preguntasPermitidasUpper));

$paramsPreguntas = array_merge(
    [$formularioId],
    $preguntasPermitidasUpper,
    $preguntasPermitidasUpper
);

$stmtPreguntas->bind_param($typesPreguntas, ...$paramsPreguntas);
$stmtPreguntas->execute();
$resPreguntas = $stmtPreguntas->get_result();

while ($p = $resPreguntas->fetch_assoc()) {
    $questions[] = [
        'id' => (int)$p['id'],
        'question_text' => (string)$p['question_text'],
        'id_question_type' => (int)$p['id_question_type'],
        'is_valued' => (int)$p['is_valued'],
        'sort_order' => (int)$p['sort_order']
    ];
}

$stmtPreguntas->close();

/* =========================================================
   2) Obtener vehículos + última visita/respuesta del formulario 138
      por trabajador asignado actual
   ========================================================= */
$sql = "
WITH eventos_form_138 AS (
    SELECT
        r.id_usuario,
        COALESCE(r.visita_id, 0) AS visita_id,
        DATE(r.created_at) AS fecha_respuesta,
        TIME(r.created_at) AS hora_respuesta,
        MAX(r.created_at) AS fecha_evento
    FROM form_question_responses r
    INNER JOIN form_questions q
        ON q.id = r.id_form_question
    WHERE q.id_formulario = ?
    GROUP BY
        r.id_usuario,
        COALESCE(r.visita_id, 0),
        DATE(r.created_at),
        TIME(r.created_at)
),

ultimo_evento AS (
    SELECT
        e.*,
        ROW_NUMBER() OVER (
            PARTITION BY e.id_usuario
            ORDER BY e.fecha_evento DESC, e.visita_id DESC
        ) AS rn
    FROM eventos_form_138 e
)

SELECT 
    v.id AS id_vehiculo,
    v.patente,
    v.modelo,
    v.tipo_combustible,
    v.estado,

    h.id_merchan,

    u.rut AS rut_usuario,
    u.usuario AS usuario_merchan,
    UPPER(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS nombre_completo,

    ue.fecha_respuesta AS fecha_ultima_respuesta,
    ue.hora_respuesta AS hora_ultima_respuesta,
    ue.fecha_evento AS fecha_hora_ultima_respuesta,
    ue.visita_id AS visita_id_form_138,

    q.id AS id_pregunta,
    q.question_text,
    q.id_question_type,
    q.is_valued,
    q.sort_order,

    r.id AS respuesta_id,
    r.answer_text,
    r.valor,
    r.foto_visita_id,
    r.created_at AS respuesta_created_at

FROM vehiculo v

LEFT JOIN vehiculo_asignacion_historial h
    ON h.id_vehiculo = v.id
    AND h.fecha_termino IS NULL

LEFT JOIN usuario u
    ON u.id = h.id_merchan

LEFT JOIN ultimo_evento ue
    ON ue.id_usuario = h.id_merchan
    AND ue.rn = 1

LEFT JOIN form_question_responses r
    ON r.id_usuario = ue.id_usuario
    AND (
        (
            ue.visita_id > 0
            AND r.visita_id = ue.visita_id
        )
        OR
        (
            ue.visita_id = 0
            AND DATE(r.created_at) = ue.fecha_respuesta
            AND TIME(r.created_at) = ue.hora_respuesta
        )
    )
    AND EXISTS (
        SELECT 1
        FROM form_questions qf
        WHERE qf.id = r.id_form_question
          AND qf.id_formulario = ?
          AND UPPER(TRIM(qf.question_text)) IN ($placeholdersPreguntas)
    )
LEFT JOIN form_questions q
    ON q.id = r.id_form_question
    AND q.id_formulario = ?
    AND UPPER(TRIM(q.question_text)) IN ($placeholdersPreguntas)
    
WHERE v.deleted_at IS NULL

ORDER BY
    ue.fecha_evento DESC,
    v.id DESC,
    q.sort_order ASC,
    r.created_at ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al preparar consulta del reporte.'
    ]);
    exit;
}

$typesMain = 'ii' . str_repeat('s', count($preguntasPermitidasUpper)) . 'i' . str_repeat('s', count($preguntasPermitidasUpper));

$paramsMain = array_merge(
    [$formularioId, $formularioId],
    $preguntasPermitidasUpper,
    [$formularioId],
    $preguntasPermitidasUpper
);

$stmt->bind_param($typesMain, ...$paramsMain);
$stmt->execute();

$result = $stmt->get_result();

$rows = [];

while ($row = $result->fetch_assoc()) {
    $idVehiculo = (int)$row['id_vehiculo'];

    if (!isset($rows[$idVehiculo])) {
        $rows[$idVehiculo] = [
            'id' => $idVehiculo,
            'patente' => $row['patente'],
            'modelo' => $row['modelo'],
            'tipo_combustible' => $row['tipo_combustible'],
            'estado' => $row['estado'],

            'id_merchan' => $row['id_merchan'],
            'rut_usuario' => $row['rut_usuario'],
            'usuario_merchan' => $row['usuario_merchan'],
            'nombre_completo' => trim($row['nombre_completo'] ?? ''),

            'fecha_ultima_respuesta' => $row['fecha_ultima_respuesta'],
            'hora_ultima_respuesta' => $row['hora_ultima_respuesta'],
            'fecha_hora_ultima_respuesta' => $row['fecha_hora_ultima_respuesta'],
            'visita_id_form_138' => $row['visita_id_form_138'],

            'answers' => []
        ];
    }

    $idPregunta = (int)($row['id_pregunta'] ?? 0);

    if ($idPregunta <= 0) {
        continue;
    }

    $key = 'q_' . $idPregunta;
    $tipoPregunta = (int)$row['id_question_type'];
    $esFoto = $tipoPregunta === 7;

    if ($esFoto) {
        if (!isset($rows[$idVehiculo]['answers'][$key])) {
            $rows[$idVehiculo]['answers'][$key] = [
                'type' => 'photo',
                'value' => '',
                'photos' => []
            ];
        }

        $answerText = trim((string)($row['answer_text'] ?? ''));

        if ($answerText !== '') {
            $partes = preg_split('/\s*\|\|\s*/', $answerText);

            foreach ($partes as $parte) {
                $parte = trim($parte);

                if ($parte === '') {
                    continue;
                }

                $url = normalizar_url_foto_reporte($parte, $baseUrl);

                if ($url === '') {
                    continue;
                }

                $rows[$idVehiculo]['answers'][$key]['photos'][] = [
                    'url' => $url,
                    'name' => nombre_foto_reporte($url),
                    'resp_id' => $row['respuesta_id'],
                    'foto_visita_id' => $row['foto_visita_id']
                ];
            }
        }

        continue;
    }

    $respuestaTexto = trim((string)($row['answer_text'] ?? ''));
    $valor = trim((string)($row['valor'] ?? ''));
    $isValued = (int)($row['is_valued'] ?? 0) === 1;

    if ($isValued && $valor !== '') {
        if ($respuestaTexto === '') {
            $respuestaTexto = $valor;
        } else {
            $respuestaTexto .= ' (Valor: ' . $valor . ')';
        }
    } elseif ($respuestaTexto === '' && $valor !== '') {
        $respuestaTexto = $valor;
    }

    if (!isset($rows[$idVehiculo]['answers'][$key])) {
        $rows[$idVehiculo]['answers'][$key] = [
            'type' => 'text',
            'value' => ''
        ];
    }

    if ($respuestaTexto !== '') {
        if ($rows[$idVehiculo]['answers'][$key]['value'] !== '') {
            $rows[$idVehiculo]['answers'][$key]['value'] .= ' / ' . $respuestaTexto;
        } else {
            $rows[$idVehiculo]['answers'][$key]['value'] = $respuestaTexto;
        }
    }
}

$stmt->close();
$conn->close();

echo json_encode([
    'ok' => true,
    'formulario_id' => $formularioId,
    'questions' => $questions,
    'data' => array_values($rows)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
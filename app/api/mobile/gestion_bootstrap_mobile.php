<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
require_once __DIR__ . '/_auth_mobile.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    mobile_json_response(500, [
        'ok' => false,
        'message' => 'No hay conexión válida a la base de datos'
    ]);
}
$conn->set_charset('utf8mb4');

$user = mobile_require_auth($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    mobile_json_response(405, [
        'ok' => false,
        'message' => 'Método no permitido'
    ]);
}

$idCampana = (int)($_GET['idCampana'] ?? 0);
$idLocal   = (int)($_GET['idLocal'] ?? 0);

if ($idCampana <= 0 || $idLocal <= 0) {
    mobile_json_response(422, [
        'ok' => false,
        'message' => 'Faltan idCampana o idLocal'
    ]);
}

function dbFetchAllAssocMobile(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        mobile_json_response(500, [
            'ok' => false,
            'message' => 'Error al preparar consulta',
            'db_error' => $conn->error
        ]);
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        mobile_json_response(500, [
            'ok' => false,
            'message' => 'Error al ejecutar consulta',
            'db_error' => $err
        ]);
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        $stmt->close();
        return [];
    }

    $row = [];
    $binds = [];
    $fields = [];

    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $binds[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $binds);

    $results = [];
    while ($stmt->fetch()) {
        $tmp = [];
        foreach ($fields as $f) {
            $tmp[$f] = $row[$f];
        }
        $results[] = $tmp;
    }

    $stmt->close();
    return $results;
}

function dbFetchOneAssocMobile(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = dbFetchAllAssocMobile($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function obtenerOpcionesMobile(mysqli $conn, int $idFormQuestion): array {
    $rows = dbFetchAllAssocMobile(
        $conn,
        "SELECT id, option_text, reference_image
         FROM form_question_options
         WHERE id_form_question = ?
         ORDER BY sort_order ASC",
        "i",
        [$idFormQuestion]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'text' => (string)($r['option_text'] ?? ''),
            'reference_image' => (string)($r['reference_image'] ?? ''),
        ];
    }
    return $out;
}

function getParentQuestionIdMobile(mysqli $conn, int $dependencyOptionId): ?int {
    $row = dbFetchOneAssocMobile(
        $conn,
        "SELECT id_form_question FROM form_question_options WHERE id = ? LIMIT 1",
        "i",
        [$dependencyOptionId]
    );
    return $row ? (int)$row['id_form_question'] : null;
}

$usuarioId = (int)$user['id'];
$empresaId = (int)$user['id_empresa'];

$sqlValidar = "
    SELECT
        f.id AS idCampana,
        f.nombre AS nombreCampanaDB,
        f.id_division AS idDivision,
        f.modalidad AS modalidadCampana,
        l.id AS idLocal,
        l.codigo AS codigoLocal,
        l.nombre AS nombreLocal,
        l.direccion AS direccionLocal,
        l.lat AS lat,
        l.lng AS lng,
        IFNULL(v.nombre_vendedor, '') AS vendedor
    FROM formularioQuestion fq
    INNER JOIN formulario AS f ON f.id = fq.id_formulario
    INNER JOIN local AS l ON l.id = fq.id_local
    LEFT JOIN vendedor AS v ON v.id = l.id_vendedor
    WHERE fq.id_formulario = ?
      AND fq.id_local = ?
      AND fq.id_usuario = ?
      AND f.id_empresa = ?
    LIMIT 1
";

$headerRow = dbFetchOneAssocMobile(
    $conn,
    $sqlValidar,
    "iiii",
    [$idCampana, $idLocal, $usuarioId, $empresaId]
);

if (!$headerRow) {
    mobile_json_response(403, [
        'ok' => false,
        'message' => 'No tienes permisos para gestionar esta campaña o no existe',
        'debug' => [
            'idCampana' => $idCampana,
            'idLocal' => $idLocal,
            'usuario_id' => $usuarioId,
            'empresa_id' => $empresaId,
            'usuario_nombre' => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
            'usuario_login' => $user['usuario'] ?? '',
        ]
    ]);
}

$modalidad = (string)$headerRow['modalidadCampana'];
$isRetiro = ($modalidad === 'retiro');

$estadoOptions = match ($modalidad) {
    'implementacion_auditoria' => [
        ['value' => 'implementado_auditado', 'label' => 'Implementado y Auditado'],
        ['value' => 'pendiente', 'label' => 'Pendiente'],
        ['value' => 'cancelado', 'label' => 'Cancelado'],
    ],
    'solo_implementacion' => [
        ['value' => 'solo_implementado', 'label' => 'Solo Implementado'],
        ['value' => 'pendiente', 'label' => 'Pendiente'],
        ['value' => 'cancelado', 'label' => 'Cancelado'],
    ],
    'retiro' => [
        ['value' => 'solo_retirado', 'label' => 'Solo Retirado'],
        ['value' => 'pendiente', 'label' => 'Pendiente'],
        ['value' => 'cancelado', 'label' => 'Cancelado'],
    ],
    default => [
        ['value' => 'implementado_auditado', 'label' => 'Implementado y Auditado'],
        ['value' => 'pendiente', 'label' => 'Pendiente'],
        ['value' => 'cancelado', 'label' => 'Cancelado'],
    ],
};

$motivosPendiente = [
    ['value' => 'local_cerrado', 'label' => 'Local cerrado'],
    ['value' => 'no_permitieron', 'label' => 'No permitieron'],
    ['value' => 'sin_productos', 'label' => 'Sin productos'],
    ['value' => 'sin_material', 'label' => 'Sin material'],
    ['value' => 'no_ubicado', 'label' => 'No ubicado'],
    ['value' => 'otro', 'label' => 'Otro'],
];

$motivosCancelado = [
    ['value' => 'campana_cancelada', 'label' => 'Campaña cancelada'],
    ['value' => 'local_no_aplica', 'label' => 'Local no aplica'],
    ['value' => 'otro', 'label' => 'Otro'],
];

$sqlMateriales = "
    SELECT 
      fq.id,
      fq.material,
      fq.valor_propuesto,
      MAX(fq.valor) AS valor,
      MAX(fq.fechaVisita) AS fechaVisita,
      MAX(fq.observacion) AS observacion,
      MAX(m.ref_image) AS ref_image
    FROM formularioQuestion fq
    LEFT JOIN material m ON fq.material = m.nombre
    WHERE fq.id_local = ?
      AND fq.id_formulario = ?
      AND fq.id_usuario = ?
    GROUP BY fq.id, fq.material, fq.valor_propuesto
    ORDER BY fq.id ASC
";

$rowsMateriales = dbFetchAllAssocMobile(
    $conn,
    $sqlMateriales,
    "iii",
    [$idLocal, $idCampana, $usuarioId]
);

$materiales = [];
foreach ($rowsMateriales as $mat) {
    $materiales[] = [
        'id' => (int)$mat['id'],
        'material' => (string)($mat['material'] ?? ''),
        'valor_propuesto' => (string)($mat['valor_propuesto'] ?? ''),
        'valor' => (string)($mat['valor'] ?? ''),
        'fecha_visita' => (string)($mat['fechaVisita'] ?? ''),
        'observacion' => (string)($mat['observacion'] ?? ''),
        'ref_image' => (string)($mat['ref_image'] ?? ''),
    ];
}

$sqlPreguntas = "
    SELECT
      id AS id_form_question,
      question_text,
      id_question_type,
      id_dependency_option,
      is_valued,
      is_required
    FROM form_questions
    WHERE id_formulario = ?
    ORDER BY sort_order ASC
";

$rowsPreguntas = dbFetchAllAssocMobile($conn, $sqlPreguntas, "i", [$idCampana]);

$preguntas = [];
$parentQuestions = [];
$conditionalQuestions = [];

foreach ($rowsPreguntas as $p) {
    $item = [
        'id_form_question' => (int)$p['id_form_question'],
        'question_text' => (string)($p['question_text'] ?? ''),
        'id_question_type' => (int)$p['id_question_type'],
        'id_dependency_option' => (int)($p['id_dependency_option'] ?? 0),
        'is_valued' => (int)($p['is_valued'] ?? 0),
        'is_required' => (int)($p['is_required'] ?? 0),
        'options' => obtenerOpcionesMobile($conn, (int)$p['id_form_question']),
        'parent_question_id' => null,
    ];

    if ($item['id_dependency_option'] > 0) {
        $item['parent_question_id'] = getParentQuestionIdMobile($conn, $item['id_dependency_option']);
    }

    $preguntas[] = $item;

    if ($item['id_dependency_option'] > 0 && in_array($item['id_question_type'], [2, 3], true)) {
        $conditionalQuestions[] = $item;
    } else {
        $parentQuestions[] = $item;
    }
}

$totalSteps = count($preguntas) > 0 ? 3 : 2;

mobile_json_response(200, [
    'ok' => true,
    'message' => 'Bootstrap de gestión cargado',
    'data' => [
        'header' => [
            'id_campana' => (int)$headerRow['idCampana'],
            'nombre_campana' => (string)$headerRow['nombreCampanaDB'],
            'id_local' => (int)$headerRow['idLocal'],
            'codigo_local' => (string)($headerRow['codigoLocal'] ?? ''),
            'nombre_local' => (string)($headerRow['nombreLocal'] ?? ''),
            'direccion_local' => (string)($headerRow['direccionLocal'] ?? ''),
            'vendedor' => (string)($headerRow['vendedor'] ?? ''),
            'lat' => (float)($headerRow['lat'] ?? 0),
            'lng' => (float)($headerRow['lng'] ?? 0),
            'division_id' => (int)$headerRow['idDivision'],
        ],
        'workflow' => [
            'modalidad' => $modalidad,
            'is_retiro' => $isRetiro,
            'action_label' => $isRetiro ? 'Retirar' : 'Implementar',
            'section_label' => $isRetiro ? 'Retiro' : 'Implementación',
            'total_steps' => $totalSteps,
            'estado_options' => $estadoOptions,
            'motivos_pendiente' => $motivosPendiente,
            'motivos_cancelado' => $motivosCancelado,
        ],
        'materials' => $materiales,
        'questions' => [
            'all' => $preguntas,
            'parent' => $parentQuestions,
            'conditional' => $conditionalQuestions,
        ],
        'user' => [
            'id' => (int)$user['id'],
            'nombre_completo' => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
            'empresa_id' => (int)$user['id_empresa'],
            'empresa_nombre' => (string)$user['empresa_nombre'],
        ],
    ]
]);
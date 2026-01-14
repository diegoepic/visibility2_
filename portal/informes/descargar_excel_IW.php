<?php

session_start();
ob_clean();

// Ocultar errores en producción
error_reporting(0);
ini_set('display_errors', 0);

// Conexión y datos de sesión
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// ------------------------------------------------------------------------------------
// 1) Entradas y validaciones básicas
// ------------------------------------------------------------------------------------
$formulario_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$inline        = isset($_GET['inline']) && $_GET['inline'] === '1';

if ($formulario_id <= 0) {
    die("ID de formulario inválido.");
}

// Rango de fechas (opcional, YYYY-MM-DD)
$start_date = (isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']))
    ? $_GET['start_date'] : null;
$end_date   = (isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']))
    ? $_GET['end_date'] : null;

// Empresa en sesión (multi-cuenta)
$empresa_id_sesion = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : 0;

// ------------------------------------------------------------------------------------
// 2) Formulario: validar que sea tipo=2 y obtener nombre + iw_requiere_local + empresa
// ------------------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT nombre, iw_requiere_local, id_empresa
    FROM formulario
    WHERE id = ?
      AND tipo = 2
    LIMIT 1
");
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $stmt->close();
    die("No existe el formulario o no es de tipo complementario.");
}
$stmt->bind_result($camp_name, $iw_requiere_local, $form_empresa_id);
$stmt->fetch();
$stmt->close();

// Validación multi-cuenta
if ($empresa_id_sesion > 0 && (int)$form_empresa_id !== $empresa_id_sesion) {
    die("No tienes permiso para exportar este formulario.");
}
$iw_requiere_local = (int)$iw_requiere_local === 1;

// ------------------------------------------------------------------------------------
// 3) Obtener preguntas (excluye tipo 7/fotos) y su flag is_valued
// ------------------------------------------------------------------------------------
$pregs     = [];   // id_question => question_text
$isValued  = [];   // id_question => bool
$isValuedByText = []; // question_text => bool   ✅ NUEVO
$questions = [];   // en orden

$stmt = $conn->prepare("
    SELECT id, question_text, is_valued
    FROM form_questions
    WHERE id_formulario = ?
      AND id_question_type <> 7
    ORDER BY sort_order ASC
");
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$rs = $stmt->get_result();
while ($r = $rs->fetch_assoc()) {
    $qid = (int)$r['id'];
    $txt = (string)$r['question_text'];

    $pregs[$qid]    = $txt;
    $isValued[$qid] = (bool)$r['is_valued'];
    $isValuedByText[$txt] = (bool)$r['is_valued']; // ✅ NUEVO
    $questions[]    = $txt;
}
$stmt->close();

// ------------------------------------------------------------------------------------
// 4) Mapear usuarios (id_usuario => usuario/login)
// ------------------------------------------------------------------------------------
$usernames = [];
$res = $conn->query("SELECT id, usuario FROM usuario");
while ($u = $res->fetch_assoc()) {
    $usernames[(int)$u['id']] = (string)$u['usuario'];
}
$res->free();

// ------------------------------------------------------------------------------------
// 5) Construir filtro de fechas para respuestas
// ------------------------------------------------------------------------------------
$filtroFecha = "";
if ($start_date) {
    $filtroFecha .= " AND DATE(r.created_at) >= '{$conn->real_escape_string($start_date)}'";
}
if ($end_date) {
    $filtroFecha .= " AND DATE(r.created_at) <= '{$conn->real_escape_string($end_date)}'";
}

// ------------------------------------------------------------------------------------
// 6) Traer respuestas (no-foto) + visita/local para agrupar por visita_id
//    local_id_efectivo = COALESCE(r.id_local, v.id_local, 0)
// ------------------------------------------------------------------------------------
$sql = "
    SELECT
      r.id_form_question,
      r.id_usuario,
      r.answer_text,
      r.valor,
      r.created_at,
      r.visita_id,
      COALESCE(r.id_local, v.id_local, 0) AS local_id_effective
    FROM form_question_responses AS r
    JOIN form_questions AS q ON q.id = r.id_form_question
    LEFT JOIN visita AS v ON v.id = r.visita_id
    WHERE q.id_formulario = ?
      AND q.id_question_type <> 7
      {$filtroFecha}
    ORDER BY r.created_at ASC, r.id_usuario ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$rs = $stmt->get_result();

// ------------------------------------------------------------------------------------
// 7) Agrupar y pivotear
//    - Si hay visita_id > 0, agrupar por visita_id (recomendado)
//    - Fallback puntual a "usuario|minuto" sólo si no hay visita (histórico raro)
//    - Para iw_requiere_local=1 se añadirá Dirección/Nombre/Código Local
// ------------------------------------------------------------------------------------
$data = [];               // eventos (alineables)
$localIdsToFetch = [];    // set de id_local para lookup posterior

while ($row = $rs->fetch_assoc()) {

    $qid = (int)$row['id_form_question'];
    if (!isset($pregs[$qid])) continue;

    $uid = (int)$row['id_usuario'];
    $lid = (int)$row['local_id_effective'];
    $visitaId = (int)($row['visita_id'] ?? 0);

    $cat = (string)$row['created_at'];
    $ts  = strtotime($cat);

    $fecha = $ts ? date('Y-m-d', $ts) : '';
    $hora  = $ts ? date('H:i:s', $ts) : '';

    // Clave del evento (1 visita / local / fecha / hora)
    $eventKey = $lid . '|' . $visitaId . '|' . $fecha . '|' . $hora;

    // Inicializar evento
    if (!isset($data[$eventKey])) {
        $data[$eventKey] = [
            'meta' => [
                'local_id'          => $lid,
                'Nombre Usuario'    => $usernames[$uid] ?? $uid,
                'Fecha Respuesta'   => $fecha,
                'Hora Respuesta'    => $hora,
                'Fecha Planificada' => $fecha
            ],
            'answers' => []
        ];
    }

    // Guardar respuesta (1 pregunta puede tener N respuestas)
    $data[$eventKey]['answers'][$pregs[$qid]][] = [
        'answer' => (string)$row['answer_text'],
        'valor'  => ($isValued[$qid] ? $row['valor'] : '')
    ];

    // Guardar local para lookup posterior
    if ($iw_requiere_local && $lid > 0) {
        $localIdsToFetch[$lid] = true;
    }
}

$stmt->close();



// ------------------------------------------------------------------------------------
// 8) Lookup masivo de locales (sólo si iw_requiere_local = 1)
// ------------------------------------------------------------------------------------
$localInfo = []; // id_local => ['codigo'=>..., 'nombre'=>..., 'direccion'=>...]
if ($iw_requiere_local && !empty($localIdsToFetch)) {
    $ids = array_keys($localIdsToFetch);
    // preparar placeholders dinámicos
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
       $sqlL = "
        SELECT 
            l.id, 
            l.codigo, 
            l.nombre,
            l.direccion,
            cu.nombre   AS cuenta,
            ca.nombre   AS cadena,
            co.comuna   AS comuna,
            re.region   AS region,
            jv.nombre   AS jefeVenta
        FROM local l
        JOIN cuenta cu  ON cu.id = l.id_cuenta
        JOIN cadena ca  ON ca.id = l.id_cadena
        JOIN comuna co  ON co.id = l.id_comuna
        JOIN region re  ON re.id = co.id_region
        LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
        WHERE l.id IN ($place)
    ";
    
    $stmtL = $conn->prepare($sqlL);
    $stmtL->bind_param($types, ...$ids);
    $stmtL->execute();
    $resL = $stmtL->get_result();
    
    while ($lr = $resL->fetch_assoc()) {
        $localInfo[(int)$lr['id']] = [
            'codigo'    => (string)($lr['codigo'] ?? ''),
            'nombre'    => (string)($lr['nombre'] ?? ''),
            'direccion' => (string)($lr['direccion'] ?? ''),
            'cuenta'    => (string)($lr['cuenta'] ?? ''),
            'cadena'    => (string)($lr['cadena'] ?? ''),
            'comuna'    => (string)($lr['comuna'] ?? ''),
            'region'    => (string)($lr['region'] ?? ''),
            'jefeVenta' => (string)($lr['jefeVenta'] ?? '')            
        ];
    }
    
    $stmtL->close();
}

// Helper para Código Local en CSV (preservar ceros a la izquierda en Excel)
function iw_format_codigo_csv($codigo, $isCsv) {
    if ($codigo === null || $codigo === '') return 'N/A';
    // Prefijo apóstrofe fuerza texto en Excel
    return $isCsv ? ("'".$codigo) : $codigo;
}

// ------------------------------------------------------------------------------------
// 9) Construir filas finales
// ------------------------------------------------------------------------------------

// ------------------------------------------------------------------------------------
// 9) Construir filas finales ALINEADAS (sin duplicar valores)
// ------------------------------------------------------------------------------------
$finalData = [];
$isCsvOutput = !$inline;

foreach ($data as $event) {

    $meta    = $event['meta'];
    $answers = $event['answers'];

    // 1) calcular cuántas filas necesita este evento
    $maxRows = 1;
    foreach ($answers as $respList) {
        $maxRows = max($maxRows, count($respList));
    }

    // 2) construir filas alineadas
    for ($i = 0; $i < $maxRows; $i++) {

        $row = [
            'ID Campana'        => $meta['local_id'],
            'Nombre Campana'    => $camp_name,
            'Nombre Usuario'    => $meta['Nombre Usuario'],
            'Fecha Planificada' => $meta['Fecha Planificada'],
            'Fecha Respuesta'   => $meta['Fecha Respuesta'],
            'Hora Respuesta'    => $meta['Hora Respuesta']
        ];

        // 3) info del local
        if ($iw_requiere_local) {
            $lid = (int)$meta['local_id'];

            if ($lid > 0 && isset($localInfo[$lid])) {
                $codigoLocal = iw_format_codigo_csv($localInfo[$lid]['codigo'], $isCsvOutput);

                $row['Codigo Local']    = $codigoLocal;
                $row['N Local']         = $codigoLocal;
                $row['Nombre Local']    = $localInfo[$lid]['nombre'];
                $row['Direccion Local'] = $localInfo[$lid]['direccion'];
                $row['Cuenta']          = $localInfo[$lid]['cuenta'];
                $row['Cadena']          = $localInfo[$lid]['cadena'];
                $row['Comuna']          = $localInfo[$lid]['comuna'];
                $row['Region']          = $localInfo[$lid]['region'];
                $row['JefeVenta']       = $localInfo[$lid]['jefeVenta'];
            } else {
                $row['Codigo Local']    = 'N/A';
                $row['N Local']         = 'N/A';
                $row['Nombre Local']    = 'N/A';
                $row['Direccion Local'] = 'N/A';
                $row['Cuenta']          = 'N/A';
                $row['Cadena']          = 'N/A';
                $row['Comuna']          = 'N/A';
                $row['Region']          = 'N/A';
                $row['JefeVenta']       = 'N/A';
            }
        }

        // 4) columnas dinámicas (preguntas)
        foreach ($questions as $qText) {

            // valor por defecto
            $row[$qText] = '';

            if (!empty($isValuedByText[$qText])) {
                $row[$qText . ' (Valor)'] = '';
            }

            // si existe respuesta para esta pregunta e índice
            if (isset($answers[$qText][$i])) {
                $row[$qText] = $answers[$qText][$i]['answer'] ?? '';

                if (!empty($isValuedByText[$qText])) {
                    $row[$qText . ' (Valor)'] = $answers[$qText][$i]['valor'] ?? '';
                }
            }
        }

        $finalData[] = $row;
    }
}

// reemplazar data original
$data = $finalData;
unset($finalData);



// ------------------------------------------------------------------------------------
// 10) Eliminar columnas "(Valor)" que queden completamente vacías
// ------------------------------------------------------------------------------------
$valorCols = [];
if (!empty($data)) {
    foreach ($data as $r) {
        foreach ($r as $col => $val) {
            if (substr($col, -8) === ' (Valor)') {
                if (trim((string)$val) !== '') {
                    $valorCols[$col] = true;
                } else {
                    if (!isset($valorCols[$col])) {
                        $valorCols[$col] = false;
                    }
                }
            }
        }
    }
    foreach ($data as &$r) {
        foreach ($valorCols as $col => $has) {
            if (!$has) unset($r[$col]);
        }
    }
    unset($r);
}


if ($iw_requiere_local) {
    $fixedHeaders = [
        'ID Campana',
        'Codigo Local',
        'N Local',
        'Nombre Campana',
        'Cuenta',
        'Cadena',
        'Nombre Local',
        'Direccion Local',
        'Comuna',
        'Region',
        'JefeVenta',        
        'Nombre Usuario',
        'Fecha Planificada',         
        'Fecha Respuesta',
        'Hora Respuesta'
    ];
} else {
    // fallback histórico (por seguridad)
    $fixedHeaders = [
        'ID Campana',
        'Nombre Campana',
        'Nombre Usuario',
        'Fecha Respuesta'
    ];
}

$dynamic = [];

foreach ($questions as $qText) {
    $dynamic[] = $qText;
    if (!empty($isValuedByText[$qText])) {
        $dynamic[] = $qText . ' (Valor)';
    }
}

$headers = array_merge($fixedHeaders, $dynamic);

// ------------------------------------------------------------------------------------
// 12) Salida HTML inline (vista previa) o descarga CSV
// ------------------------------------------------------------------------------------
if ($inline) {
    echo "<!DOCTYPE html>\n<html lang=\"es\">\n<head>\n<meta charset=\"UTF-8\">\n";
    echo "<title>Vista en línea - Encuesta Complementaria {$formulario_id}</title>\n";
    echo "<style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #999; padding: 4px; word-wrap: break-word; }
          </style>\n";
    echo "</head>\n<body>\n";
    echo "<h2>Encuesta Complementaria: " . htmlspecialchars($camp_name, ENT_QUOTES, 'UTF-8') . "</h2>\n";
    echo "<table>\n<tr>";
    foreach ($headers as $h) {
        echo "<th>" . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . "</th>";
    }
    echo "</tr>\n";
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($headers as $col) {
            echo "<td>" . htmlspecialchars($row[$col] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        }
        echo "</tr>\n";
    }
    echo "</table>\n";
    echo "</body>\n</html>";
    exit();
}

// CSV
$filename = "complementaria_{$formulario_id}_" . date('Ymd_His') . ".csv";
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");

$out = fopen('php://output', 'w');
// BOM para Excel
fwrite($out, "\xEF\xBB\xBF");

// Encabezados
fputcsv($out, $headers, ';');

// Filas
foreach ($data as $row) {
    $line = [];
    foreach ($headers as $col) {
        $line[] = $row[$col] ?? '';
    }
    fputcsv($out, $line, ';');
}

fclose($out);
$conn->close();
exit();

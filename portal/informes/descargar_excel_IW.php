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
$grouped = [];           // key => grupo
$localIdsToFetch = [];   // set de id_local para lookup posterior

while ($row = $rs->fetch_assoc()) {
    $qid  = (int)$row['id_form_question'];
    if (!isset($pregs[$qid])) continue; // seguridad

    $uid  = (int)$row['id_usuario'];
    $ans  = (string)$row['answer_text'];
    $val  = $row['valor'];
    $cat  = (string)$row['created_at'];
    $vid  = (int)$row['visita_id'];
    $lid  = (int)$row['local_id_effective'];

    // ts_min para fallback (usuario+minuto)
    $ts_min = '';
    if ($cat !== '') {
        $t = strtotime($cat);
        $ts_min = $t ? date('Y-m-d H:i', $t) : '';
    }

    // clave de agrupación
    $groupKey = ($vid > 0) ? ('v'.$vid) : ($uid . '|' . $ts_min);

    // Inicializar grupo
    if (!isset($grouped[$groupKey])) {
        $grouped[$groupKey] = [
            'ID Usuario'      => $uid,
            'Nombre Usuario'  => isset($usernames[$uid]) ? $usernames[$uid] : $uid,
            'Fecha Respuesta' => ($ts_min ?: ($cat ? date('Y-m-d H:i', strtotime($cat)) : '')),
            'visita_id'       => $vid,
            'local_id'        => $lid,  // puede ser 0 (histórico sin local)
            'min_ts_unix'     => ($cat ? strtotime($cat) : PHP_INT_MAX),
            'answers'         => []     // question_text => ['answer'=>..., 'valor'=>...]
        ];
        if ($iw_requiere_local && $lid > 0) {
            $localIdsToFetch[$lid] = true;
        }
    } else {
        // mantener la fecha mínima del grupo
        $u = ($cat ? strtotime($cat) : PHP_INT_MAX);
        if ($u < $grouped[$groupKey]['min_ts_unix']) {
            $grouped[$groupKey]['min_ts_unix'] = $u;
            $grouped[$groupKey]['Fecha Respuesta'] = date('Y-m-d H:i', $u);
        }
        // si no tenía local y aparece uno > 0, úsalo
        if ($iw_requiere_local && (int)$grouped[$groupKey]['local_id'] === 0 && $lid > 0) {
            $grouped[$groupKey]['local_id'] = $lid;
            $localIdsToFetch[$lid] = true;
        }
    }

    // Pivot de preguntas
    $qText = $pregs[$qid];
    if (!isset($grouped[$groupKey]['answers'][$qText])) {
        $grouped[$groupKey]['answers'][$qText] = ['answer' => '', 'valor' => ''];
    }
    // concatenar múltiple opción en mismo grupo
    if ($ans !== '') {
        $grouped[$groupKey]['answers'][$qText]['answer'] =
            ($grouped[$groupKey]['answers'][$qText]['answer'] === '')
            ? $ans
            : ($grouped[$groupKey]['answers'][$qText]['answer'] . "; " . $ans);
    }
    if ($isValued[$qid] && $val !== null && $val !== '') {
        $grouped[$groupKey]['answers'][$qText]['valor'] =
            ($grouped[$groupKey]['answers'][$qText]['valor'] === '')
            ? $val
            : ($grouped[$groupKey]['answers'][$qText]['valor'] . "; " . $val);
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
$data = [];
$isCsvOutput = !$inline;

foreach ($grouped as $g) {
    $row = [
        //'ID Campana'      => $formulario_id,
        'ID Campana'      => $g['local_id'],
        'Nombre Campana'  => $camp_name,
        'ID Usuario'      => $g['ID Usuario'],
        'Nombre Usuario'  => $g['Nombre Usuario'],
        'Fecha Respuesta' => $g['Fecha Respuesta'],
    ];

// Columnas de Local sólo si la campaña requiere local
if ($iw_requiere_local) {
    $lid = (int)$g['local_id'];

    if ($lid > 0 && isset($localInfo[$lid])) {
        $codigoLocal = iw_format_codigo_csv($localInfo[$lid]['codigo'], $isCsvOutput);

        $row['Direccion Local'] = $localInfo[$lid]['direccion'] !== '' ? $localInfo[$lid]['direccion'] : 'N/A';
        $row['Nombre Local']    = $localInfo[$lid]['nombre']    !== '' ? $localInfo[$lid]['nombre']    : 'N/A';
        $row['Codigo Local']    = $codigoLocal;
        $row['N Local']         = $codigoLocal; // homologación

        // 👇 NUEVOS CAMPOS
        $row['Cuenta'] = $localInfo[$lid]['cuenta'] !== '' ? $localInfo[$lid]['cuenta'] : 'N/A';
        $row['Cadena'] = $localInfo[$lid]['cadena'] !== '' ? $localInfo[$lid]['cadena'] : 'N/A';
        $row['Comuna'] = $localInfo[$lid]['comuna'] !== '' ? $localInfo[$lid]['comuna'] : 'N/A';
        $row['Region'] = $localInfo[$lid]['region'] !== '' ? $localInfo[$lid]['region'] : 'N/A';
        $row['JefeVenta'] = $localInfo[$lid]['jefeVenta'] !== '' ? $localInfo[$lid]['jefeVenta'] : 'N/A';        

    } else {
        $row['Direccion Local'] = 'N/A';
        $row['Nombre Local']    = 'N/A';
        $row['Codigo Local']    = 'N/A';
        $row['N Local']         = 'N/A';

        // 👇 NUEVOS CAMPOS (fallback)
        $row['Cuenta'] = 'N/A';
        $row['Cadena'] = 'N/A';
        $row['Comuna'] = 'N/A';
        $row['Region'] = 'N/A';
        $row['JefeVenta'] = 'N/A';        
    }
}


    // Pivot de preguntas: respuesta y (valor) cuando aplique
    foreach ($questions as $qText) {
        $colAns   = $qText;
        $colValor = $qText . ' (Valor)';
        if (isset($g['answers'][$qText])) {
            $row[$colAns]   = $g['answers'][$qText]['answer'];
            $row[$colValor] = $g['answers'][$qText]['valor'];
        } else {
            $row[$colAns]   = '';
            $row[$colValor] = '';
        }
    }
    $data[] = $row;
}

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
        'Fecha Respuesta'
    ];
} else {
    // fallback histórico (por seguridad)
    $fixedHeaders = [
        'ID Campana',
        'Nombre Campana',
        'ID Usuario',
        'Nombre Usuario',
        'Fecha Respuesta'
    ];
}

$dynamic = [];
if (!empty($data)) {
    $firstRow = $data[0];
    foreach ($firstRow as $col => $_) {
        if (!in_array($col, $fixedHeaders, true)) {
            $dynamic[] = $col;
        }
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

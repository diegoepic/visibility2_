<?php
// informes/descargar_excel_IW.php

// ---------------------------------------------------------
// 1) Limpiar buffer y configurar errores
// ---------------------------------------------------------
ob_clean();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('memory_limit', '1024M');
set_time_limit(0);
ini_set('display_errors', '0');

// ---------------------------------------------------------
// 2) Iniciar sesión si no está iniciada (para evitar bloqueos)
// ---------------------------------------------------------
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
session_write_close();

// ---------------------------------------------------------
// 3) Incluir conexión a la base de datos
// ---------------------------------------------------------
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// ---------------------------------------------------------
// 4) Validar parámetro 'id' (debe ser entero)
// ---------------------------------------------------------
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    die('ID de campaña inválido.');
}
$formulario_id = intval($_GET['id']);

// -----------------------------------------
// 5) Ejecutar la consulta que concatena
// -----------------------------------------
$sql = "
    SELECT
        f.id                                        AS idCampana,
        UPPER(f.nombre)                             AS nombreCampana,
        UPPER(u.usuario)                            AS usuario,
        DATE(fqr.created_at)                        AS fecha_respuesta,
        UPPER(fp.question_text)                     AS question_text,
        UPPER(
          GROUP_CONCAT(
            DISTINCT fqr.answer_text
            ORDER BY fp.sort_order
            SEPARATOR '; '
          )
        )                                           AS concat_answers,
        UPPER(
          GROUP_CONCAT(
            DISTINCT CASE WHEN fqr.valor <> '0.00' THEN fqr.valor END
            ORDER BY fp.sort_order
            SEPARATOR '; '
          )
        )                                           AS concat_valores
    FROM formulario f
    JOIN form_questions fp           ON fp.id_formulario = f.id
    JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
    JOIN usuario u                   ON u.id = fqr.id_usuario
    WHERE f.id = {$formulario_id}
    GROUP BY
        DATE(fqr.created_at),
        fp.question_text,
        u.usuario,           -- añadir usuario en el GROUP BY para mantener “por usuario” si hacen falta
        f.id,                -- agrupar también por campaña (aunque es siempre la misma)
        f.nombre
    ORDER BY
        DATE(fqr.created_at) ASC,
        fp.sort_order ASC
";

$res = mysqli_query($conn, $sql);
if (!$res) {
    die("Error en la consulta IW: " . mysqli_error($conn));
}

// ---------------------------------------------------------
// 6) Recuperar resultados en un arreglo asociativo
// ---------------------------------------------------------
$data = mysqli_fetch_all($res, MYSQLI_ASSOC);

// ---------------------------------------------------------
// 7) Si no hay datos, mostrar mensaje y terminar
// ---------------------------------------------------------
if (empty($data)) {
    die("No se encontraron registros para la campaña ID = {$formulario_id}");
}

// ---------------------------------------------------------
// 8) Detectar todas las preguntas únicas y si alguna tuvo valor ≠ 0
// ---------------------------------------------------------
$allQuestions = [];   // Lista de preguntas únicas (question_text)
$hasValorQ    = [];   // “question_text” => true/false si concat_valores <> '' al menos en una fila

foreach ($data as $row) {
    $q = $row['question_text'];
    if (!in_array($q, $allQuestions, true)) {
        $allQuestions[] = $q;
        $hasValorQ[$q]  = false;
    }
    // Si concat_valores no está vacío, significa que hubo algún valor ≠ 0
    if (trim($row['concat_valores']) !== '') {
        $hasValorQ[$q] = true;
    }
}

// ---------------------------------------------------------
// 9) Agrupar por “idCampana + usuario + fecha_respuesta”
// ---------------------------------------------------------
//    Cada grupo contendrá varias filas, una por cada pregunta que tenga respuestas en esa fecha.
$grouped = [];
foreach ($data as $r) {
    // Clave única por campaña+usuario+fecha
    $key = $r['idCampana'] . '_' . $r['usuario'] . '_' . $r['fecha_respuesta'];

    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'idCampana'       => $r['idCampana'],
            'nombreCampana'   => $r['nombreCampana'],
            'usuario'         => $r['usuario'],
            'fecha_respuesta' => $r['fecha_respuesta'],
            'answers'         => []  // --> aquí guardaremos: 'question_text' => ['concat_answers' => ..., 'concat_valores' => ...]
        ];
    }

    $preg = $r['question_text'];
    // Como nuestra consulta ya agrupó (GROUP_CONCAT) por (fecha_respuesta, question_text),
    // en cada grupo habrá exactamente UNA fila por pregunta. Así que simplemente
    // pero por seguridad nos aseguramos de asignar directamente:
    $grouped[$key]['answers'][$preg] = [
        'concat_answers' => $r['concat_answers'],
        'concat_valores' => $r['concat_valores']
    ];
}

// ---------------------------------------------------------
// 10) Construir arreglo pivotado ($final), fila por “campaña+usuario+fecha”
// ---------------------------------------------------------
$final = [];
foreach ($grouped as $g) {
    // Columnas fijas para cada fila
    $fila = [
        'ID Campana'       => $g['idCampana'],
        'Nombre Campana'   => $g['nombreCampana'],
        'Usuario'          => $g['usuario'],
        'Fecha Respuesta'  => $g['fecha_respuesta'],
    ];

    // Volcar cada pregunta en su propia columna: “<Pregunta>” y, si corresponde, “<Pregunta> ‐ Valor”
    foreach ($allQuestions as $preg) {
        if (isset($g['answers'][$preg])) {
            // Si la fila del grupo contiene esta pregunta, usamos lo que ya concatenó SQL
            $fila[$preg] = $g['answers'][$preg]['concat_answers'];

            if ($hasValorQ[$preg]) {
                // Si esa pregunta tuvo algún valor ≠ 0 (en cualquier fecha), creamos columna “<Pregunta> ‐ Valor”
                $fila[$preg . ' ‐ Valor'] = $g['answers'][$preg]['concat_valores'];
            }
        } else {
            // Si esa pregunta no se respondió en esta “fecha/usuario”, dejamos en blanco
            $fila[$preg] = '';
            if ($hasValorQ[$preg]) {
                $fila[$preg . ' ‐ Valor'] = '';
            }
        }
    }

    $final[] = $fila;
}

// ---------------------------------------------------------
// 11) Generar HTML/XLS
// ---------------------------------------------------------
$columns = [
    'ID Campana',
    'Nombre Campana',
    'Usuario',
    'Fecha Respuesta'
];
foreach ($allQuestions as $preg) {
    $columns[] = $preg;
    if ($hasValorQ[$preg]) {
        $columns[] = $preg . ' ‐ Valor';
    }
}

$html = "<html><head>
  <meta charset=\"UTF-8\">
  <style>
    table { width: 100%; border-collapse: collapse; }
    th, td {
      border: 1px solid #666;
      padding: 4px;
      white-space: normal;
      word-wrap: break-word;
      vertical-align: top;
      font-size: 9pt;
    }
    th { background-color: #f0f0f0; }
  </style>
</head><body>";

$html .= "<table><tr>";
foreach ($columns as $colName) {
    $html .= "<th>" . htmlspecialchars($colName, ENT_QUOTES, 'UTF-8') . "</th>";
}
$html .= "</tr>";

foreach ($final as $row) {
    $html .= "<tr>";
    foreach ($columns as $colName) {
        $v = isset($row[$colName]) ? $row[$colName] : '';
        $html .= "<td>" . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . "</td>";
    }
    $html .= "</tr>";
}
$html .= "</table></body></html>";

// ---------------------------------------------------------
// 12) Determinar nombre de archivo dinámico
// ---------------------------------------------------------
$nombreCampana = $data[0]['nombreCampana'];
$nombreCampana = substr($nombreCampana, 0, 30);
$campana_sanitizada = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreCampana);
$archivo = "{$campana_sanitizada}_" . date('Y-m-d_His') . ".xls";

// ---------------------------------------------------------
// 13) Forzar descarga en formato .xls
// ---------------------------------------------------------
$content = "\xEF\xBB\xBF" . $html; // BOM UTF-8 para compatibilidad con Excel
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Expires: 0");
header("Content-Disposition: attachment; filename={$archivo}");
header("Content-Length: " . strlen($content));

mysqli_close($conn);
echo $content;
exit();
?>

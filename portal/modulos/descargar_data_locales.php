<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
$autoloadPath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    exit('No se encontró el autoload de Composer en: ' . $autoloadPath);
}
require_once $autoloadPath;

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('America/Santiago');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (!class_exists(Spreadsheet::class)) {
    http_response_code(500);
    exit('Composer fue cargado, pero PhpSpreadsheet no está instalado en portal/vendor.');
}

function fail(string $message): void
{
    if (ob_get_length()) {
        ob_end_clean();
    }
    exit($message);
}

function setCellByIndex($sheet, int $col, int $row, $value): void
{
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $value);
}

function setStringByIndex($sheet, int $col, int $row, string $value): void
{
    $sheet->setCellValueExplicit(
        Coordinate::stringFromColumnIndex($col) . $row,
        $value,
        DataType::TYPE_STRING
    );
}

function limpiarNombreArchivo(string $texto): string
{
    $texto = trim($texto);
    $texto = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $texto);
    $texto = preg_replace('/\s+/u', '_', $texto);
    return $texto !== '' ? $texto : 'locales';
}

function mayusculasSinAcentos(?string $texto): string
{
    $texto = trim((string)$texto);
    if ($texto === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        $normalizado = Normalizer::normalize($texto, Normalizer::FORM_D);
        if ($normalizado !== false) {
            $texto = preg_replace('/\p{Mn}+/u', '', $normalizado);
        }
    }

    $texto = strtr($texto, [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','Á'=>'A','À'=>'A','Â'=>'A','Ä'=>'A','Ã'=>'A',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C'
    ]);

    $texto = function_exists('mb_strtoupper')
        ? mb_strtoupper($texto, 'UTF-8')
        : strtoupper($texto);
    $texto = preg_replace('/[\p{Z}\s]+/u', ' ', $texto);

    return trim((string)$texto);
}

/* =========================
   Parámetros
   ========================= */

function getIntArrayParam(string $key, ?string $fallbackKey = null): array
{
    $raw = $_GET[$key] ?? null;

    if ($raw === null && $fallbackKey !== null) {
        $raw = $_GET[$fallbackKey] ?? null;
    }

    if ($raw === null || $raw === '') {
        return [];
    }

    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $values = [];

    foreach ($raw as $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        $intValue = (int)$value;

        if ($intValue > 0) {
            $values[] = $intValue;
        }
    }

    return array_values(array_unique($values));
}

function addInFilter(
    string $column,
    array $values,
    array &$where,
    array &$params,
    string &$types
): void {
    if (empty($values)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($values), '?'));

    $where[] = "$column IN ($placeholders)";

    foreach ($values as $value) {
        $params[] = $value;
        $types .= 'i';
    }
}

$canales    = getIntArrayParam('canal');
$distritos  = getIntArrayParam('distrito');
$divisiones = getIntArrayParam('division', 'id_division');

$formato = strtolower(trim((string)($_GET['formato'] ?? 'csv'))); // excel | csv
$formato = in_array($formato, ['excel', 'xlsx', 'csv'], true) ? $formato : 'csv';
$debug   = isset($_GET['debug']) && $_GET['debug'] === '1';

/* =========================
   Filtros seguros
   ========================= */

$where  = [];
$params = [];
$types  = '';

addInFilter('l.id_canal', $canales, $where, $params, $types);
addInFilter('l.id_distrito', $distritos, $where, $params, $types);
addInFilter('l.id_division', $divisiones, $where, $params, $types);

$filtrosSql = '';

if (!empty($where)) {
    $filtrosSql = ' AND ' . implode(' AND ', $where);
}

/* =========================
   SQL
   ========================= */
$sql = "
SELECT
    l.id AS IDLOCAL,
    COALESCE(d.nombre, 'SIN DIVISION') AS DIVISION,
    l.codigo AS CODIGO,
    SUBSTRING_INDEX(TRIM(l.nombre), ' ', 1) AS NUMERO_LOCAL,
    COALESCE(can.nombre_canal, 'SIN CANAL') AS CANAL,
    COALESCE(sub.nombre_subcanal, 'SIN CANAL') AS SUBCANAL,    
    COALESCE(ca.nombre, 'SIN CADENA') AS CADENA,
    COALESCE(cu.nombre, 'SIN CUENTA') AS CUENTA,
    l.nombre AS LOCAL,
    l.direccion_original AS DIRECCION_ORIGINAL,
    l.direccion AS DIRECCION,
    l.direccion_google AS DIRECCION_GOOGLE,
    COALESCE(l.estado_address_validation, 'SIN VALIDAR') AS ESTADO_DIRECCION,
    l.direccion_validada_at AS FECHA_VALIDACION_DIRECCION,
    l.google_response_id AS GOOGLE_RESPONSE_ID,
    COALESCE(co.comuna, 'SIN COMUNA') AS COMUNA,
    COALESCE(re.region, 'SIN REGION') AS REGION,
    COALESCE(di.nombre_distrito, 'SIN DISTRITO') AS DISTRITO,
    COALESCE(zo.nombre_zona, 'SIN ZONA') AS ZONA,
    COALESCE(jv.nombre, 'SIN JEFE DE VENTA') AS JEFEVENTA,
    l.lat AS LATITUD,
    l.lng AS LONGITUD
FROM local l
LEFT JOIN division_empresa d ON d.id = l.id_division
LEFT JOIN cadena ca ON ca.id = l.id_cadena
LEFT JOIN cuenta cu ON cu.id = l.id_cuenta
LEFT JOIN canal can ON can.id = l.id_canal
LEFT JOIN subcanal sub ON sub.id = l.id_subcanal
LEFT JOIN comuna co ON co.id = l.id_comuna
LEFT JOIN region re ON re.id = co.id_region
LEFT JOIN distrito di ON di.id = l.id_distrito
LEFT JOIN zona zo ON zo.id = l.id_zona
LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
WHERE 1 = 1
$filtrosSql
ORDER BY
    DIVISION ASC,
    CODIGO ASC
";

/* =========================
   Debug
   ========================= */
if ($debug) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "GET:\n";
    print_r($_GET);
    echo "\nSQL:\n$sql\n";
    echo "\nPARAMS:\n";
    print_r($params);

    $stmtDebug = $conn->prepare($sql);
    if (!$stmtDebug) {
        echo "\nERROR PREPARE: " . $conn->error . "\n";
        exit;
    }

    if (!empty($params)) {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmtDebug, 'bind_param'], $bind);
    }

    $stmtDebug->execute();
    $resDebug = $stmtDebug->get_result();
    if ($resDebug) {
        $totalDebug = $resDebug->num_rows;
        $rowsDebug = [];
        while (count($rowsDebug) < 3 && ($debugRow = $resDebug->fetch_assoc())) {
            $rowsDebug[] = $debugRow;
        }
        echo "\nFILAS: " . $totalDebug . "\n";
        echo "PRIMERAS 3 FILAS:\n";
        echo json_encode(array_slice($rowsDebug, 0, 3), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo "\nERROR SQL: " . $stmtDebug->error . "\n";
    }
    $stmtDebug->close();
    exit;
}

/* =========================
   Ejecutar consulta
   ========================= */
$stmt = $conn->prepare($sql);
if (!$stmt) {
    fail('Error preparando consulta: ' . $conn->error);
}

if (!empty($params)) {
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

$stmt->execute();
$res = $stmt->get_result();
if (!$res) {
    $stmt->close();
    fail('Error en la consulta: ' . $conn->error);
}

if ($res->num_rows < 1) {
    $stmt->close();
    fail('No hay datos para exportar.');
}

$headers = array_map(static function ($field): string {
    return (string)$field->name;
}, $res->fetch_fields());

/* CSV masivo: salida directa, sin construir celdas PhpSpreadsheet en memoria. */
if ($formato === 'csv') {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $nombreArchivo = 'locales_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if (!$out) {
        $stmt->close();
        fail('No fue posible abrir la salida CSV.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');

    while ($row = $res->fetch_assoc()) {
        $row['DIRECCION_GOOGLE'] = mayusculasSinAcentos($row['DIRECCION_GOOGLE'] ?? '');
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[$header] ?? '';
        }
        fputcsv($out, $line, ';');
    }

    fclose($out);
    $stmt->close();
    exit;
}

/* PhpSpreadsheet mantiene cada celda en memoria; proteger exportaciones muy grandes. */
$maxExcelRows = 8000;
if ($res->num_rows > $maxExcelRows) {
    $totalRows = $res->num_rows;
    $stmt->close();
    fail("La exportación XLSX contiene $totalRows filas y supera el máximo seguro de $maxExcelRows. Usa formato=csv para descargar el total.");
}

/* =========================
   Excel con PhpSpreadsheet
   ========================= */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Locales');
$sheet->setShowGridlines(false);
$sheet->freezePane('A2');
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

$rowNum = 1;
$colNum = 1;

/* Cabecera */
foreach ($headers as $header) {
    setCellByIndex($sheet, $colNum++, $rowNum, $header);
}

$lastCol = Coordinate::stringFromColumnIndex(count($headers));
$headerRange = "A1:{$lastCol}1";

$sheet->getStyle($headerRange)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12,
        'name' => 'Calibri'
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F4E78']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'BFBFBF']
        ]
    ]
]);

$sheet->getRowDimension(1)->setRowHeight(28);

/* Datos */
$rowNum = 2;
while ($r = $res->fetch_assoc()) {
    $r['DIRECCION_GOOGLE'] = mayusculasSinAcentos($r['DIRECCION_GOOGLE'] ?? '');
    $colNum = 1;
    foreach ($headers as $c) {
        $value = $r[$c];

        if (in_array($c, ['CODIGO', 'NUMERO_LOCAL'], true)) {
            setStringByIndex($sheet, $colNum++, $rowNum, (string)$value);
        } elseif (in_array($c, ['LATITUD', 'LONGITUD'], true)) {
            setCellByIndex($sheet, $colNum++, $rowNum, $value !== null && $value !== '' ? (float)$value : '');
        } else {
            setStringByIndex($sheet, $colNum++, $rowNum, (string)($value ?? ''));
        }
    }
    $rowNum++;
}
$stmt->close();

if ($rowNum > 2) {
    $dataRange = "A1:{$lastCol}" . ($rowNum - 1);

    $sheet->getStyle($dataRange)->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E5E5E5']
            ]
        ]
    ]);

    $sheet->setAutoFilter($dataRange);

    // Fuerza alineación izquierda en todos los datos.
    $sheet->getStyle("A2:{$lastCol}" . ($rowNum - 1))
          ->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

/* Anchos legibles y acotados. */
$columnWidths = [
    'IDLOCAL' => 11, 'DIVISION' => 24, 'CODIGO' => 15, 'NUMERO_LOCAL' => 15,
    'CANAL' => 18, 'SUBCANAL' => 18, 'CADENA' => 22, 'CUENTA' => 22,
    'LOCAL' => 38, 'DIRECCION_ORIGINAL' => 42, 'DIRECCION' => 42,
    'DIRECCION_GOOGLE' => 48, 'ESTADO_DIRECCION' => 20,
    'FECHA_VALIDACION_DIRECCION' => 23, 'GOOGLE_RESPONSE_ID' => 32,
    'COMUNA' => 20, 'REGION' => 30, 'DISTRITO' => 22, 'ZONA' => 20,
    'JEFEVENTA' => 28, 'LATITUD' => 15, 'LONGITUD' => 15
];
foreach ($headers as $index => $header) {
    $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
    $sheet->getColumnDimension($columnLetter)->setWidth($columnWidths[$header] ?? 18);
}

/* Salida */
if (ob_get_length()) {
    ob_end_clean();
}

$nombreArchivo = 'locales_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

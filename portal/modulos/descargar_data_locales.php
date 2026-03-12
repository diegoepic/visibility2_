<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/vendor/autoload.php';

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('America/Santiago');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

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

/* =========================
   Par¨¢metros
   ========================= */
$canal    = (int)($_GET['canal'] ?? 0);
$distrito = (int)($_GET['distrito'] ?? 0);
$division = (int)($_GET['division'] ?? ($_GET['id_division'] ?? 0));
$formato  = strtolower(trim((string)($_GET['formato'] ?? 'excel'))); // excel | csv
$debug    = isset($_GET['debug']) && $_GET['debug'] === '1';

/* =========================
   Filtros seguros
   ========================= */
$where = [];
$params = [];
$types = '';

if ($canal > 0) {
    $where[] = 'l.id_canal = ?';
    $params[] = $canal;
    $types .= 'i';
}

if ($distrito > 0) {
    $where[] = 'l.id_distrito = ?';
    $params[] = $distrito;
    $types .= 'i';
}

if ($division > 0) {
    $where[] = 'l.id_division = ?';
    $params[] = $division;
    $types .= 'i';
}

$filtrosSql = '';
if (!empty($where)) {
    $filtrosSql = ' AND ' . implode(' AND ', $where);
}

/* =========================
   SQL
   ========================= */
$sql = "
SELECT 
    COALESCE(d.nombre, 'SIN DIVISION') AS DIVISION,
    l.codigo AS CODIGO,
    SUBSTRING_INDEX(TRIM(l.nombre), ' ', 1) AS NUMERO_LOCAL,
    COALESCE(can.nombre_canal, 'SIN CANAL') AS CANAL,
    COALESCE(ca.nombre, 'SIN CADENA') AS CADENA,
    COALESCE(cu.nombre, 'SIN CUENTA') AS CUENTA,
    l.nombre AS LOCAL,
    l.direccion AS DIRECCION,
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
        $rowsDebug = $resDebug->fetch_all(MYSQLI_ASSOC);
        echo "\nFILAS: " . count($rowsDebug) . "\n";
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

$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    fail('No hay datos para exportar.');
}

/* =========================
   CSV
   ========================= */
if ($formato === 'csv') {
    $archivo = 'locales_' . date('Y-m-d_His') . '.csv';

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    $cols = array_keys($rows[0]);

    fputcsv($out, $cols, ';');

    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $line[] = $r[$c];
        }
        fputcsv($out, $line, ';');
    }

    fclose($out);
    exit;
}

/* =========================
   Excel con PhpSpreadsheet
   ========================= */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Locales');

$headers = array_keys($rows[0]);
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

$sheet->getRowDimension(1)->setRowHeight(26);

$sheet->getRowDimension(1)->setRowHeight(22);

/* Datos */
$rowNum = 2;
foreach ($rows as $r) {
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

if ($rowNum > 2) {
    $dataRange = "A1:{$lastCol}" . ($rowNum - 1);

    $sheet->getStyle($dataRange)->applyFromArray([
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E5E5E5']
            ]
        ]
    ]);

    $sheet->setAutoFilter($dataRange);
}

/* Auto ancho */
$maxColIndex = Coordinate::columnIndexFromString($lastCol);
for ($i = 1; $i <= $maxColIndex; $i++) {
    $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
}

/* Nombre archivo */
$nombreArchivo = 'locales_' . date('Y-m-d_His') . '.xlsx';

/* Salida */
if (ob_get_length()) {
    ob_end_clean();
}

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
<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function failDownload(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

function styleHeader($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF004AAD']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFD9E2F3']],
        ],
    ]);
}

function uniqueSheetTitle(Spreadsheet $spreadsheet, string $baseTitle): string
{
    $baseTitle = substr($baseTitle, 0, 31);
    $candidate = $baseTitle;
    $suffixNumber = 2;

    while ($spreadsheet->getSheetByName($candidate) !== null) {
        $suffix = ' (' . $suffixNumber . ')';
        $candidate = substr($baseTitle, 0, 31 - strlen($suffix)) . $suffix;
        $suffixNumber++;
    }

    return $candidate;
}

$raw = $_POST['rows'] ?? '';
$rows = json_decode((string)$raw, true);
if (!is_array($rows) || empty($rows)) {
    failDownload('No se recibieron datos para descargar.');
}

$routeBaseRows = [];
$rawRouteBaseRows = trim((string)($_POST['route_base_rows'] ?? ''));
if ($rawRouteBaseRows !== '') {
    $routeBaseRows = json_decode($rawRouteBaseRows, true);
    if (!is_array($routeBaseRows)) {
        failDownload('El rutero base seleccionado no tiene un formato válido.');
    }
}

$baseWorkbookIncluded = false;
$baseWorkbookName = '';
$originalSheetNames = [];
$baseUpload = $_FILES['archivoRutasBaseOriginal'] ?? null;

if (is_array($baseUpload) && (int)($baseUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ((int)($baseUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        failDownload('No se pudo recibir el archivo original de rutas base.');
    }

    $baseWorkbookName = basename((string)($baseUpload['name'] ?? 'rutas_base.xlsx'));
    $extension = strtolower((string)pathinfo($baseWorkbookName, PATHINFO_EXTENSION));
    $temporaryPath = (string)($baseUpload['tmp_name'] ?? '');
    if (!in_array($extension, ['xlsx', 'xls'], true) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        failDownload('El archivo original de rutas base debe ser un Excel válido (.xlsx o .xls).');
    }

    try {
        $spreadsheet = IOFactory::load($temporaryPath);
    } catch (Throwable $error) {
        failDownload('No se pudo abrir el archivo original de rutas base. Verifica que no esté dañado.');
    }

    $baseWorkbookIncluded = true;
    $originalSheetNames = $spreadsheet->getSheetNames();
} else {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Visibility')
        ->setTitle('Planificación de rutas editada');
}

if ($baseWorkbookIncluded) {
    $summary = $spreadsheet->createSheet();
} else {
    $summary = $spreadsheet->getActiveSheet();
}
$summary->setTitle(uniqueSheetTitle($spreadsheet, 'Resumen'));

$planned = array_values(array_filter($rows, fn(array $row): bool => empty($row['sin_ruta'])));
$unplanned = array_values(array_filter($rows, fn(array $row): bool => !empty($row['sin_ruta'])));
$groups = [];
$dates = [];
foreach ($planned as $row) {
    if (!empty($row['grupo_ruta'])) {
        $groups[(string)$row['grupo_ruta']] = true;
    }
    if (!empty($row['fecha_ruta_sql'])) {
        $dates[(string)$row['fecha_ruta_sql']] = true;
    }
}

$summaryRows = [
    ['Campo', 'Valor'],
    ['Fecha descarga', date('d-m-Y H:i:s')],
    ['Total locales', count($rows)],
    ['Locales con ruta', count($planned)],
    ['Locales sin ruta', count($unplanned)],
    ['Grupos de ruta', count($groups)],
    ['Fechas planificadas', count($dates)],
    ['Rutero base incluido', ($baseWorkbookIncluded || $routeBaseRows) ? 'Sí' : 'No'],
    ['Locales únicos en rutero base', count($routeBaseRows)],
    ['Formato base conservado', $baseWorkbookIncluded ? 'Sí' : 'No'],
    ['Archivo base', $baseWorkbookIncluded ? $baseWorkbookName : 'No aplica'],
    ['Hojas originales conservadas', $baseWorkbookIncluded ? implode(', ', $originalSheetNames) : 'No aplica'],
];
$summary->fromArray($summaryRows, null, 'A1');
styleHeader($summary, 'A1:B1');
$summary->getColumnDimension('A')->setWidth(28);
$summary->getColumnDimension('B')->setWidth(24);
$summary->freezePane('A2');

$headers = [
    'Código Local', 'Nombre', 'Dirección', 'Comuna', 'Lat', 'Lng',
    'Cantidad Objetivo Día', 'Días Planificados', 'Grupo Ruta', 'Grupo Ruta Sugerido',
    'Fecha Ruta', 'Usuario ID', 'Usuario Login', 'Usuario Nombre',
    'Día Plan', 'Semana Plan', 'Día Semana Nº', 'Día Semana',
    'Orden Visita', 'Tamaño Ruta', 'Distancia Desde Anterior (KM)',
    'Distancia Total Ruta (KM)', 'Observación',
];

$planSheet = $spreadsheet->createSheet();
$planSheet->setTitle(uniqueSheetTitle($spreadsheet, 'Planificacion'));
$planSheet->fromArray([$headers], null, 'A1');
styleHeader($planSheet, 'A1:W1');

$rowNumber = 2;
foreach ($rows as $row) {
    $values = [
        $row['codigo_local'] ?? '',
        $row['nombre'] ?? '',
        $row['direccion'] ?? '',
        $row['comuna'] ?? '',
        $row['lat'] ?? '',
        $row['lng'] ?? '',
        $row['cantidad_objetivo_dia'] ?? '',
        $row['dias_planificados'] ?? '',
        $row['grupo_ruta'] ?? '',
        $row['grupo_ruta_sugerido'] ?? '',
        $row['fecha_ruta_sql'] ?? '',
        $row['usuario_id'] ?? '',
        $row['usuario_login'] ?? '',
        $row['usuario_nombre'] ?? '',
        $row['dia_plan'] ?? '',
        $row['semana_plan'] ?? '',
        $row['dia_semana_num'] ?? '',
        $row['dia_semana'] ?? '',
        $row['orden_visita'] ?? '',
        $row['tamano_ruta'] ?? '',
        $row['distancia_desde_anterior_km'] ?? '',
        $row['distancia_total_ruta_km'] ?? '',
        $row['observacion'] ?? '',
    ];
    $planSheet->fromArray([$values], null, 'A' . $rowNumber);
    $planSheet->setCellValueExplicit('A' . $rowNumber, (string)($row['codigo_local'] ?? ''), DataType::TYPE_STRING);
    $rowNumber++;
}

$lastPlanRow = max(2, $rowNumber - 1);
$planSheet->freezePane('A2');
$planSheet->setAutoFilter("A1:W{$lastPlanRow}");
$planSheet->getStyle("A2:W{$lastPlanRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$widths = [18, 28, 36, 20, 14, 14, 20, 18, 18, 20, 15, 14, 18, 26, 14, 14, 15, 16, 14, 14, 24, 24, 55];
foreach ($widths as $index => $width) {
    $planSheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
}

if ($routeBaseRows) {
    $baseHeaders = [
        'Usuario Login', 'Usuario Nombre', 'Grupo Ruta', 'Identificador Ruta', 'Código Local',
        'Nombre', 'Dirección', 'Comuna', 'Lat', 'Lng', 'Orden Visita', 'Tamaño Ruta',
        'Cantidad Objetivo Día', 'Distancia Total Ruta (KM)', 'Primera Fecha Original',
        'Última Fecha Original', 'Observación',
    ];
    $baseSheet = $spreadsheet->createSheet();
    $baseSheet->setTitle(uniqueSheetTitle($spreadsheet, 'Rutas Base'));
    $baseSheet->fromArray([$baseHeaders], null, 'A1');
    styleHeader($baseSheet, 'A1:Q1');

    $baseRowNumber = 2;
    foreach ($routeBaseRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userLogin = (string)($row['usuario_login'] ?? '');
        $routeGroup = (string)($row['grupo_ruta'] ?? '');
        $routeIdentifier = $routeGroup !== '' ? trim($userLogin . ' | ' . $routeGroup) : '';
        $baseSheet->fromArray([[
            $userLogin,
            $row['usuario_nombre'] ?? '',
            $routeGroup,
            $routeIdentifier,
            $row['codigo_local'] ?? '',
            $row['nombre'] ?? '',
            $row['direccion'] ?? '',
            $row['comuna'] ?? '',
            $row['lat'] ?? '',
            $row['lng'] ?? '',
            $row['orden_visita'] ?? '',
            $row['tamano_ruta'] ?? '',
            $row['cantidad_objetivo_dia'] ?? '',
            $row['distancia_total_ruta_km'] ?? '',
            $row['primera_fecha_original'] ?? '',
            $row['ultima_fecha_original'] ?? '',
            $row['observacion'] ?? '',
        ]], null, 'A' . $baseRowNumber);
        $baseSheet->setCellValueExplicit('A' . $baseRowNumber, $userLogin, DataType::TYPE_STRING);
        $baseSheet->setCellValueExplicit('E' . $baseRowNumber, (string)($row['codigo_local'] ?? ''), DataType::TYPE_STRING);
        $baseRowNumber++;
    }

    $lastBaseRow = max(2, $baseRowNumber - 1);
    $baseSheet->freezePane('A2');
    $baseSheet->setAutoFilter("A1:Q{$lastBaseRow}");
    $baseSheet->getStyle("A2:Q{$lastBaseRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $baseWidths = [20, 26, 20, 30, 18, 28, 36, 20, 14, 14, 14, 14, 22, 25, 21, 21, 55];
    foreach ($baseWidths as $index => $width) {
        $baseSheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
    }
}

$sinRutaSheet = $spreadsheet->createSheet();
$sinRutaSheet->setTitle(uniqueSheetTitle($spreadsheet, 'Sin Ruta'));
$sinRutaHeaders = ['Código Local', 'Nombre', 'Dirección', 'Comuna', 'Lat', 'Lng', 'Motivo'];
$sinRutaSheet->fromArray([$sinRutaHeaders], null, 'A1');
styleHeader($sinRutaSheet, 'A1:G1');

$rowNumber = 2;
foreach ($unplanned as $row) {
    $sinRutaSheet->fromArray([[
        $row['codigo_local'] ?? '',
        $row['nombre'] ?? '',
        $row['direccion'] ?? '',
        $row['comuna'] ?? '',
        $row['lat'] ?? '',
        $row['lng'] ?? '',
        'Sin grupo de ruta o fecha planificada',
    ]], null, 'A' . $rowNumber);
    $sinRutaSheet->setCellValueExplicit('A' . $rowNumber, (string)($row['codigo_local'] ?? ''), DataType::TYPE_STRING);
    $rowNumber++;
}

$lastUnplannedRow = max(2, $rowNumber - 1);
$sinRutaSheet->freezePane('A2');
$sinRutaSheet->setAutoFilter("A1:G{$lastUnplannedRow}");
foreach ([18, 28, 36, 20, 14, 14, 40] as $index => $width) {
    $sinRutaSheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
}

$spreadsheet->setActiveSheetIndexByName($planSheet->getTitle());

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filenamePrefix = ($baseWorkbookIncluded || $routeBaseRows)
    ? 'rutero_periodo_con_rutas_base_'
    : 'planificacion_rutas_editada_';
$filename = $filenamePrefix . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;

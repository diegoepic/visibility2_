<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

$raw = $_POST['rows'] ?? '';
$rows = json_decode((string)$raw, true);
if (!is_array($rows) || empty($rows)) {
    failDownload('No se recibieron datos para descargar.');
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Visibility')
    ->setTitle('Planificación de rutas editada');

$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('Resumen');

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
$planSheet->setTitle('Planificacion');
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

$sinRutaSheet = $spreadsheet->createSheet();
$sinRutaSheet->setTitle('Sin Ruta');
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

$spreadsheet->setActiveSheetIndex(1);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'planificacion_rutas_editada_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;

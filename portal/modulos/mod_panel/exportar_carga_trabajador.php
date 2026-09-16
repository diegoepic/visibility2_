<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('America/Santiago');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$db = $conn ?? $conexion ?? null;
if (!($db instanceof mysqli) || empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('La sesión no es válida.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db->set_charset('utf8mb4');

function exportFail(string $message, int $status = 422): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function validExportDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTime && $date->format('Y-m-d') === $value ? $value : '';
}

function safeExportFilename(string $value): string
{
    $value = trim($value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii !== false ? $ascii : $value;
    $value = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $value), '_');
    return $value !== '' ? $value : 'trabajador';
}

function applyWorkerFilters(string &$sql, string &$types, array &$params, int $division, int $subdivision, string $classification, string $userAlias = 'u'): void
{
    if ($division > 0) {
        $sql .= " AND {$userAlias}.id_division = ? ";
        $types .= 'i';
        $params[] = $division;
    }
    if ($subdivision > 0) {
        $sql .= " AND {$userAlias}.id_subdivision = ? ";
        $types .= 'i';
        $params[] = $subdivision;
    }
    if ($classification === 'interno' || $classification === 'externo') {
        $sql .= " AND {$userAlias}.clasificacion_usuario = ? ";
        $types .= 's';
        $params[] = $classification;
    }
}

function setExplicitString(Worksheet $sheet, string $cell, $value): void
{
    $sheet->setCellValueExplicit($cell, (string)($value ?? ''), DataType::TYPE_STRING);
}

function setProposalDates(Worksheet $sheet, string $cell, array $proposals): void
{
    sort($proposals);
    if (count($proposals) === 1) {
        $sheet->setCellValue($cell, ExcelDate::PHPToExcel(new DateTime($proposals[0] . ' 12:00:00')));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
    } elseif ($proposals) {
        setExplicitString($sheet, $cell, implode("\n", array_map(static function ($date) {
            return (new DateTime($date))->format('d-m-Y');
        }, $proposals)));
    }
    $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
}

function styleTableHeader(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF004AAD']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['ARGB' => 'FF003879']]],
    ]);
}

function styleDataRows(Worksheet $sheet, int $lastColumn, int $lastRow, int $startRow = 2): void
{
    if ($lastRow < $startRow) {
        return;
    }
    $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);
    $sheet->getStyle('A' . $startRow . ':' . $lastColumnLetter . $lastRow)
        ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    for ($row = $startRow; $row <= $lastRow; $row += 2) {
        $sheet->getStyle('A' . $row . ':' . $lastColumnLetter . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F6FC');
    }
}

function paintYesNo(Worksheet $sheet, string $column, int $startRow, int $lastRow): void
{
    for ($row = $startRow; $row <= $lastRow; $row++) {
        $value = strtoupper(trim((string)$sheet->getCell($column . $row)->getValue()));
        $color = $value === 'SI' ? 'FFDDF3E4' : 'FFFCE8E6';
        $sheet->getStyle($column . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
        $sheet->getStyle($column . $row)->getFont()->setBold(true);
        $sheet->getStyle($column . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}

$companyId = (int)($_SESSION['empresa_id'] ?? 0);
$workerId = (int)($_GET['id_ejecutor'] ?? 0);
$division = (int)($_GET['division_usuario'] ?? 0);
$subdivision = (int)($_GET['subdivision_usuario'] ?? 0);
$classification = strtolower(trim((string)($_GET['clasificacion_usuario'] ?? 'todos')));
$managementState = strtolower(trim((string)($_GET['estado_gestion'] ?? 'todos')));
$formState = strtolower(trim((string)($_GET['formulario_estado'] ?? 'activos')));
$dateFrom = validExportDate((string)($_GET['fecha_desde'] ?? ''));
$dateTo = validExportDate((string)($_GET['fecha_hasta'] ?? ''));
$exportScope = strtolower(trim((string)($_GET['alcance'] ?? 'panel')));
if (!in_array($exportScope, ['panel', 'activos', 'ejecuciones'], true)) {
    exportFail('El alcance de la descarga no es válido.');
}
// Las dos descargas del modal tienen un alcance independiente del panel.
if ($exportScope === 'activos') {
    $formState = 'activos';
    $managementState = 'todos';
} elseif ($exportScope === 'ejecuciones') {
    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        exportFail('Selecciona fechas válidas: desde debe ser anterior o igual a hasta.');
    }
    $formState = 'todos';
    $managementState = 'todos';
}
// La descarga respeta el alcance global de los formularios en curso.
$allowDateFilter = $exportScope === 'ejecuciones'
    || ($formState === 'inactivos' && $managementState !== 'activa');
if (!$allowDateFilter) {
    $dateFrom = '';
    $dateTo = '';
}
$format = strtolower(trim((string)($_GET['formato'] ?? 'fechas')));
$useVisitDate = $dateFrom !== '' || $dateTo !== '';

if ($companyId <= 0 || $workerId <= 0 || !in_array($format, ['detalle', 'fechas'], true)) {
    exportFail('Los parámetros de la descarga no son válidos.');
}

$workerSql = "SELECT u.id, u.nombre, u.apellido, u.usuario, u.id_division, u.id_subdivision, u.clasificacion_usuario
              FROM usuario u
              WHERE u.id = ? AND u.id_empresa = ? AND u.id_perfil = 3 AND u.activo = 1";
$workerTypes = 'ii';
$workerParams = [$workerId, $companyId];
applyWorkerFilters($workerSql, $workerTypes, $workerParams, $division, $subdivision, $classification);
$workerSql .= ' LIMIT 1';
$workerStmt = $db->prepare($workerSql);
if (!$workerStmt) {
    exportFail('No fue posible validar el trabajador.', 500);
}
$workerStmt->bind_param($workerTypes, ...$workerParams);
$workerStmt->execute();
$worker = $workerStmt->get_result()->fetch_assoc();
$workerStmt->close();
if (!$worker) {
    exportFail('El trabajador no existe o no pertenece al alcance seleccionado.', 404);
}

$validProposal = "fq.fechaPropuesta IS NOT NULL
    AND CAST(fq.fechaPropuesta AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaPropuesta AS CHAR(10)) <> '0000-00-00'";
$validVisit = "fq.fechaVisita IS NOT NULL
    AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaVisita AS CHAR(10)) <> '0000-00-00'";
$validMeasurement = $useVisitDate ? $validVisit : $validProposal;
$managed = "(COALESCE(fq.countVisita, 0) > 0 OR COALESCE(fq.pregunta, '') IN ('solo_auditoria', 'solo_implementado', 'implementado_auditado', 'completado'))";
$visitDateSql = "CASE WHEN {$validVisit} THEN DATE(fq.fechaVisita) ELSE NULL END";
$proposalDateSql = "CASE WHEN {$validProposal} THEN DATE(fq.fechaPropuesta) ELSE NULL END";

$sql = "SELECT
            f.id AS id_formulario,
            COALESCE(f.nombre, 'SIN FORMULARIO') AS formulario,
            COALESCE(df.nombre, 'SIN DEPARTAMENTO') AS departamento,
            COALESCE(sf.nombre, '') AS subdivision_formulario,
            l.id AS id_local,
            l.codigo AS codigo_local,
            COALESCE(l.nombre, 'SIN NOMBRE') AS local,
            COALESCE(l.direccion, '') AS direccion,
            COALESCE(co.comuna, '') AS comuna,
            COALESCE(re.region, '') AS region,
            {$proposalDateSql} AS fecha_planificada,
            {$visitDateSql} AS fecha_visita,
            MAX(CASE WHEN {$managed} THEN 1 ELSE 0 END) AS gestionado
        FROM formularioQuestion fq
        INNER JOIN usuario u ON u.id = fq.id_usuario AND u.id_empresa = ? AND u.activo = 1
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN local l ON l.id = fq.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        LEFT JOIN region re ON re.id = co.id_region
        LEFT JOIN division_empresa df ON df.id = f.id_division
        LEFT JOIN subdivision sf ON sf.id = f.id_subdivision
        WHERE fq.id_usuario = ? AND {$validMeasurement}";
$types = 'ii';
$params = [$companyId, $workerId];
applyWorkerFilters($sql, $types, $params, $division, $subdivision, $classification);

if ($dateFrom !== '') {
    $sql .= ' AND DATE(fq.fechaVisita) >= ? ';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND DATE(fq.fechaVisita) <= ? ';
    $types .= 's';
    $params[] = $dateTo;
}
if ($managementState === 'activa') {
    $sql .= " AND NOT {$managed} ";
} elseif ($managementState === 'finalizada') {
    $sql .= " AND {$managed} ";
}
if ($formState === 'activos') {
    $sql .= ' AND f.estado = 1 ';
} elseif ($formState === 'inactivos') {
    $sql .= ' AND f.estado <> 1 ';
}
$sql .= " GROUP BY f.id, f.nombre, df.nombre, sf.nombre, l.id, l.codigo, l.nombre,
                   l.direccion, co.comuna, re.region, {$proposalDateSql}, {$visitDateSql}
          ORDER BY departamento, formulario, l.codigo, fecha_visita";

$stmt = $db->prepare($sql);
if (!$stmt) {
    exportFail('No fue posible preparar el detalle del trabajador: ' . $db->error, 500);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$detailMap = [];
$datesSet = [];
$appendRow = static function (array $source) use (&$detailMap, &$datesSet): void {
    $key = (string)$source['origen'] . '|' . (string)$source['id_formulario'] . '|' . (string)$source['id_local'];
    if (!isset($detailMap[$key])) {
        $detailMap[$key] = [
            'origen' => (string)$source['origen'],
            'departamento' => (string)$source['departamento'],
            'subdivision_formulario' => (string)($source['subdivision_formulario'] ?? ''),
            'id_formulario' => (string)$source['id_formulario'],
            'formulario' => (string)$source['formulario'],
            'id_local' => (string)$source['id_local'],
            'codigo_local' => (string)$source['codigo_local'],
            'local' => (string)$source['local'],
            'direccion' => (string)($source['direccion'] ?? ''),
            'comuna' => (string)($source['comuna'] ?? ''),
            'region' => (string)($source['region'] ?? ''),
            'fecha_planificada' => (string)($source['fecha_planificada'] ?? ''),
            'fechas_propuestas' => [],
            'propuestas_por_visita' => [],
            'visitado' => false,
            'gestionado' => false,
            'fechas' => [],
        ];
    }
    // Una sala puede tener varias programaciones dentro de la misma campaña.
    // Conservamos todas; la fecha de visita nunca reemplaza a la propuesta.
    $proposal = trim((string)($source['fecha_planificada'] ?? ''));
    if ($source['origen'] === 'PLANIFICADO' && $proposal !== '') {
        $detailMap[$key]['fechas_propuestas'][$proposal] = true;
        ksort($detailMap[$key]['fechas_propuestas']);
        $detailMap[$key]['fecha_planificada'] = array_key_first($detailMap[$key]['fechas_propuestas']);
    }
    $date = trim((string)($source['fecha_visita'] ?? ''));
    $isManaged = (int)($source['gestionado'] ?? 0) > 0;
    if ($date !== '') {
        if ($source['origen'] === 'PLANIFICADO' && $proposal !== '') {
            $detailMap[$key]['propuestas_por_visita'][$date][$proposal] = true;
        }
        $datesSet[$date] = true;
        $detailMap[$key]['visitado'] = true;
        $previous = $detailMap[$key]['fechas'][$date] ?? '';
        $detailMap[$key]['fechas'][$date] = $isManaged || $previous === 'GESTIONADO' ? 'GESTIONADO' : 'VISITADO';
    }
    if ($isManaged) {
        $detailMap[$key]['gestionado'] = true;
    }
};

while ($row = $result->fetch_assoc()) {
    $row['origen'] = 'PLANIFICADO';
    $appendRow($row);
}
$stmt->close();

if ($managementState !== 'activa') {
    $sqlIw = "SELECT
                f.id AS id_formulario,
                CONCAT(COALESCE(f.nombre, 'SIN FORMULARIO'), ' (IW)') AS formulario,
                COALESCE(df.nombre, 'SIN DEPARTAMENTO') AS departamento,
                COALESCE(sf.nombre, '') AS subdivision_formulario,
                COALESCE(r.id_local, v.id_local, 0) AS id_local,
                COALESCE(l.codigo, 'SIN CODIGO') AS codigo_local,
                COALESCE(l.nombre, 'SIN LOCAL ASOCIADO') AS local,
                COALESCE(l.direccion, '') AS direccion,
                COALESCE(co.comuna, '') AS comuna,
                COALESCE(re.region, '') AS region,
                DATE(r.created_at) AS fecha_planificada,
                DATE(r.created_at) AS fecha_visita,
                1 AS gestionado,
                'IW' AS origen
            FROM form_question_responses r
            INNER JOIN form_questions q ON q.id = r.id_form_question AND q.id_question_type <> 7
            INNER JOIN formulario f ON f.id = q.id_formulario AND f.tipo = 2 AND f.id NOT IN (138, 2187)
            INNER JOIN usuario u ON u.id = r.id_usuario AND u.id_empresa = ? AND u.activo = 1
            LEFT JOIN visita v ON v.id = r.visita_id
            LEFT JOIN local l ON l.id = COALESCE(r.id_local, v.id_local)
            LEFT JOIN comuna co ON co.id = l.id_comuna
            LEFT JOIN region re ON re.id = co.id_region
            LEFT JOIN division_empresa df ON df.id = f.id_division
            LEFT JOIN subdivision sf ON sf.id = f.id_subdivision
            WHERE r.id_usuario = ?";
    $typesIw = 'ii';
    $paramsIw = [$companyId, $workerId];
    applyWorkerFilters($sqlIw, $typesIw, $paramsIw, $division, $subdivision, $classification);
    if ($dateFrom !== '') {
        $sqlIw .= ' AND DATE(r.created_at) >= ? ';
        $typesIw .= 's';
        $paramsIw[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sqlIw .= ' AND DATE(r.created_at) <= ? ';
        $typesIw .= 's';
        $paramsIw[] = $dateTo;
    }
    if ($formState === 'activos') {
        $sqlIw .= ' AND f.estado = 1 ';
    } elseif ($formState === 'inactivos') {
        $sqlIw .= ' AND f.estado <> 1 ';
    }
    $sqlIw .= " GROUP BY f.id, f.nombre, df.nombre, sf.nombre, COALESCE(r.id_local, v.id_local, 0), l.codigo, l.nombre,
                        l.direccion, co.comuna, re.region, DATE(r.created_at)
                ORDER BY departamento, formulario, codigo_local, fecha_visita";
    $stmtIw = $db->prepare($sqlIw);
    if (!$stmtIw) {
        exportFail('No fue posible preparar las actividades IW: ' . $db->error, 500);
    }
    $stmtIw->bind_param($typesIw, ...$paramsIw);
    $stmtIw->execute();
    $resultIw = $stmtIw->get_result();
    while ($rowIw = $resultIw->fetch_assoc()) {
        $appendRow($rowIw);
    }
    $stmtIw->close();
}

$details = array_values($detailMap);
usort($details, static function (array $left, array $right): int {
    foreach (['departamento', 'formulario', 'codigo_local'] as $column) {
        $comparison = strcasecmp((string)$left[$column], (string)$right[$column]);
        if ($comparison !== 0) {
            return $comparison;
        }
    }
    return 0;
});
$dates = array_keys($datesSet);
sort($dates);

$departments = [];
foreach ($details as $item) {
    $department = $item['departamento'] !== '' ? $item['departamento'] : 'SIN DEPARTAMENTO';
    if (!isset($departments[$department])) {
        $departments[$department] = ['departamento' => $department, 'campanas' => [], 'asignados' => 0, 'visitados' => 0, 'gestionados' => 0];
    }
    $campaignKey = $item['origen'] . '|' . $item['id_formulario'];
    if (!isset($departments[$department]['campanas'][$campaignKey])) {
        $departments[$department]['campanas'][$campaignKey] = [
            'formulario' => $item['formulario'],
            'asignados' => 0,
            'visitados' => 0,
            'gestionados' => 0,
            'ultima_visita' => '',
        ];
    }

    $lastVisit = $item['fechas'] ? max(array_keys($item['fechas'])) : '';
    $departments[$department]['campanas'][$campaignKey]['asignados']++;
    $departments[$department]['campanas'][$campaignKey]['visitados'] += $item['visitado'] ? 1 : 0;
    $departments[$department]['campanas'][$campaignKey]['gestionados'] += $item['gestionado'] ? 1 : 0;
    if ($lastVisit > $departments[$department]['campanas'][$campaignKey]['ultima_visita']) {
        $departments[$department]['campanas'][$campaignKey]['ultima_visita'] = $lastVisit;
    }
    $departments[$department]['asignados']++;
    $departments[$department]['visitados'] += $item['visitado'] ? 1 : 0;
    $departments[$department]['gestionados'] += $item['gestionado'] ? 1 : 0;
}

$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('Resumen');
$workerName = trim((string)$worker['nombre'] . ' ' . (string)$worker['apellido']);
$summary->mergeCells('A1:G1');
$summary->setCellValue('A1', $exportScope === 'ejecuciones'
    ? 'ACTIVIDAD REALIZADA: CAMPAÑAS ACTIVAS Y FINALIZADAS'
    : ($exportScope === 'activos' ? 'CARGA ACTIVA: REALIZADAS Y PENDIENTES' : 'RESUMEN DE CARGA POR TRABAJADOR'));
$summary->getStyle('A1:G1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['ARGB' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF004AAD']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$summary->getRowDimension(1)->setRowHeight(28);
$summary->setCellValue('A3', 'Trabajador');
$summary->setCellValue('B3', $workerName);
$summary->setCellValue('D3', 'Usuario');
setExplicitString($summary, 'E3', $worker['usuario']);
$summary->setCellValue('A4', 'Período');
$summary->setCellValue('B4', $exportScope === 'activos' ? 'Todo lo activo, sin filtro de fechas'
    : ($dateFrom !== '' ? $dateFrom : 'Sin límite') . ' a ' . ($dateTo !== '' ? $dateTo : 'Sin límite'));
$summary->setCellValue('D4', 'Formato');
$summary->setCellValue('E4', $format === 'detalle' ? 'Detalle vertical' : 'Matriz por fecha');
$summary->getStyle('A3:A4')->getFont()->setBold(true);
$summary->getStyle('D3:D4')->getFont()->setBold(true);

$summaryHeaders = ['Departamento', 'Campañas asignadas', 'Locales asignados', 'Visitados', 'Gestionados', 'Ratio avance', 'Última fecha visita'];
if ($exportScope === 'ejecuciones') {
    $summaryHeaders = ['Departamento', 'Campañas con actividad', 'Locales con actividad', 'Visitados', 'Gestionados', 'Gestión sobre visitados', 'Última fecha visita'];
}
$summary->fromArray($summaryHeaders, null, 'A6');
styleTableHeader($summary, 'A6:G6');
$summaryRow = 7;
foreach ($departments as $department) {
    $campaigns = $department['campanas'];
    uasort($campaigns, static function (array $left, array $right): int {
        return strcasecmp((string)$left['formulario'], (string)$right['formulario']);
    });

    foreach ($campaigns as $campaign) {
        $summary->fromArray([
            $department['departamento'],
            $campaign['formulario'],
            $campaign['asignados'],
            $campaign['visitados'],
            $campaign['gestionados'],
            $campaign['asignados'] > 0 ? $campaign['gestionados'] / $campaign['asignados'] : 0,
            $campaign['ultima_visita'],
        ], null, 'A' . $summaryRow);
        if ($campaign['ultima_visita'] !== '') {
            $summary->setCellValue('G' . $summaryRow, ExcelDate::PHPToExcel(new DateTime($campaign['ultima_visita'] . ' 12:00:00')));
            $summary->getStyle('G' . $summaryRow)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        }
        $summary->getStyle('B' . $summaryRow)->getAlignment()->setWrapText(true);
        $summary->getStyle('F' . $summaryRow)->getNumberFormat()->setFormatCode('0%');
        $summaryRow++;
    }
}
$summaryLastRow = max(6, $summaryRow - 1);
styleDataRows($summary, 7, $summaryLastRow, 7);
$summary->setAutoFilter('A6:G' . $summaryLastRow);
foreach (['A' => 24, 'B' => 48, 'C' => 18, 'D' => 14, 'E' => 14, 'F' => 16, 'G' => 20] as $column => $width) {
    $summary->getColumnDimension($column)->setWidth($width);
}

$dataSheet = $spreadsheet->createSheet();
if ($format === 'detalle') {
    $dataSheet->setTitle('Detalle');
    $headers = ['Trabajador', 'Usuario', 'Origen', 'Departamento formulario', 'ID formulario', 'Formulario asignado', 'Código local', 'Local asignado', 'Fecha planificada', 'Fecha(s) visita', 'Visitado', 'Gestionado'];
    $dataSheet->fromArray($headers, null, 'A1');
    styleTableHeader($dataSheet, 'A1:L1');
    $rowNumber = 2;
    foreach ($details as $item) {
        $visitDates = array_keys($item['fechas']);
        sort($visitDates);
        $dataSheet->fromArray([
            $workerName, $worker['usuario'], $item['origen'], $item['departamento'], $item['id_formulario'],
            $item['formulario'], $item['codigo_local'], $item['local'], $item['fecha_planificada'],
            implode(', ', $visitDates), $item['visitado'] ? 'SI' : 'NO', $item['gestionado'] ? 'SI' : 'NO',
        ], null, 'A' . $rowNumber);
        setExplicitString($dataSheet, 'B' . $rowNumber, $worker['usuario']);
        setExplicitString($dataSheet, 'E' . $rowNumber, $item['id_formulario']);
        setExplicitString($dataSheet, 'G' . $rowNumber, $item['codigo_local']);
        if ($item['fecha_planificada'] !== '') {
            $dataSheet->setCellValue('I' . $rowNumber, ExcelDate::PHPToExcel(new DateTime($item['fecha_planificada'] . ' 12:00:00')));
            $dataSheet->getStyle('I' . $rowNumber)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        }
        $rowNumber++;
    }
    $lastRow = max(1, $rowNumber - 1);
    styleDataRows($dataSheet, 12, $lastRow);
    if ($lastRow >= 2) {
        paintYesNo($dataSheet, 'K', 2, $lastRow);
        paintYesNo($dataSheet, 'L', 2, $lastRow);
    }
    $dataSheet->setAutoFilter('A1:L' . $lastRow);
    foreach (['A' => 26, 'B' => 18, 'C' => 14, 'D' => 25, 'E' => 14, 'F' => 38, 'G' => 16, 'H' => 38, 'I' => 18, 'J' => 28, 'K' => 13, 'L' => 13] as $column => $width) {
        $dataSheet->getColumnDimension($column)->setWidth($width);
    }
} else {
    $dataSheet->setTitle('Matriz por fecha');
    $fixedHeaders = ['Departamento formulario', 'ID formulario', 'Formulario asignado', 'Código local', 'Local asignado'];
    if ($exportScope === 'activos' || $exportScope === 'ejecuciones') {
        $fixedHeaders = array_merge($fixedHeaders, ['Dirección', 'Comuna', 'Región', 'Fecha propuesta']);
    }
    $fixedHeaders = array_merge($fixedHeaders, ['Visitado', 'Gestionado']);
    if ($exportScope === 'activos') $fixedHeaders[] = 'Pendiente';
    $locationColumns = ['F' => 'direccion', 'G' => 'comuna', 'H' => 'region'];
    $proposalColumn = 'I';
    $dataSheet->fromArray($fixedHeaders, null, 'A1');
    foreach ($dates as $dateIndex => $date) {
        $column = Coordinate::stringFromColumnIndex(count($fixedHeaders) + $dateIndex + 1);
        $dataSheet->setCellValue($column . '1', ExcelDate::PHPToExcel(new DateTime($date . ' 12:00:00')));
        $dataSheet->getStyle($column . '1')->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        $dataSheet->getColumnDimension($column)->setWidth(15);
    }
    $lastColumn = count($fixedHeaders) + count($dates);
    $lastColumnLetter = Coordinate::stringFromColumnIndex(max(1, $lastColumn));
    styleTableHeader($dataSheet, 'A1:' . $lastColumnLetter . '1');
    $rowNumber = 2;
    foreach ($details as $item) {
        $matrixValues = [
            $item['departamento'], $item['id_formulario'], $item['formulario'], $item['codigo_local'],
            $item['local'],
        ];
        if ($exportScope === 'activos' || $exportScope === 'ejecuciones') {
            $matrixValues = array_merge($matrixValues, [$item['direccion'], $item['comuna'], $item['region'], '']);
        }
        $matrixValues = array_merge($matrixValues, [$item['visitado'] ? 'SI' : 'NO', $item['gestionado'] ? 'SI' : 'NO']);
        if ($exportScope === 'activos') $matrixValues[] = $item['gestionado'] ? 'NO' : 'SI';
        $dataSheet->fromArray($matrixValues, null, 'A' . $rowNumber);
        setExplicitString($dataSheet, 'B' . $rowNumber, $item['id_formulario']);
        setExplicitString($dataSheet, 'D' . $rowNumber, $item['codigo_local']);
        if ($exportScope === 'activos' || $exportScope === 'ejecuciones') {
            foreach ($locationColumns as $column => $field) {
                setExplicitString($dataSheet, $column . $rowNumber, $item[$field]);
                $dataSheet->getStyle($column . $rowNumber)->getAlignment()->setWrapText(true);
            }
            setProposalDates($dataSheet, $proposalColumn . $rowNumber, array_keys($item['fechas_propuestas']));
        }
        foreach ($dates as $dateIndex => $date) {
            $column = Coordinate::stringFromColumnIndex(count($fixedHeaders) + $dateIndex + 1);
            $value = $item['fechas'][$date] ?? '';
            $dataSheet->setCellValue($column . $rowNumber, $value);
        }
        $rowNumber++;
    }
    $lastRow = max(1, $rowNumber - 1);
    styleDataRows($dataSheet, $lastColumn, $lastRow);
    if ($lastRow >= 2) {
        foreach (['Visitado', 'Gestionado'] as $statusHeader) {
            paintYesNo($dataSheet, Coordinate::stringFromColumnIndex(array_search($statusHeader, $fixedHeaders, true) + 1), 2, $lastRow);
        }
        foreach ($details as $detailIndex => $item) {
            $excelRow = $detailIndex + 2;
            foreach ($dates as $dateIndex => $date) {
                $value = $item['fechas'][$date] ?? '';
                if ($value === '') {
                    continue;
                }
                $column = Coordinate::stringFromColumnIndex(count($fixedHeaders) + $dateIndex + 1);
                $fill = $value === 'GESTIONADO' ? 'FFDDF3E4' : 'FFFFF1CC';
                $dataSheet->getStyle($column . $excelRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fill);
                $dataSheet->getStyle($column . $excelRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
    }
    if ($exportScope === 'activos' || $exportScope === 'ejecuciones') {
        $locationWidths = ['F' => 42, 'G' => 24, 'H' => 28, 'I' => 20, 'J' => 13, 'K' => 13];
        if ($exportScope === 'activos') $locationWidths['L'] = 14;
        foreach ($locationWidths as $column => $width) {
            $dataSheet->getColumnDimension($column)->setWidth($width);
        }
        $dataSheet->getComment($proposalColumn . '1')->getText()->createTextRun('FechaPropuesta de la programación. Si hay varias para el mismo local y campaña, se muestran todas. Las columnas siguientes son fechas reales de visita. IW no tiene fecha propuesta.');
    }
    $dataSheet->setAutoFilter('A1:' . $lastColumnLetter . $lastRow);
    foreach (['A' => 25, 'B' => 14, 'C' => 38, 'D' => 16, 'E' => 38] as $column => $width) {
        $dataSheet->getColumnDimension($column)->setWidth($width);
    }
}

$dataSheet->getStyle($dataSheet->calculateWorksheetDimension())->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
if ($exportScope === 'ejecuciones') {
    $dailySheet = $spreadsheet->createSheet();
    $dailySheet->setTitle('Ejecuciones por día');
    $dailySheet->fromArray(['Fecha real', 'Departamento', 'ID formulario', 'Formulario', 'Código local', 'Local asignado', 'Dirección', 'Comuna', 'Región', 'Fecha propuesta', 'Actividad', 'Origen'], null, 'A1');
    styleTableHeader($dailySheet, 'A1:L1');
    $dailySheet->getComment('J1')->getText()->createTextRun('Fecha propuesta asociada a la visita de esta fila. IW no tiene fecha propuesta.');
    $dailyRow = 2;
    // Una fila por día/local/campaña; varias respuestas de una visita no se duplican.
    foreach ($dates as $date) {
        foreach ($details as $item) {
            if (!isset($item['fechas'][$date])) continue;
            $dailySheet->fromArray([
                ExcelDate::PHPToExcel(new DateTime($date . ' 12:00:00')),
                $item['departamento'], $item['id_formulario'], $item['formulario'],
                $item['codigo_local'], $item['local'], $item['direccion'], $item['comuna'], $item['region'], '',
                $item['fechas'][$date], $item['origen'],
            ], null, 'A' . $dailyRow);
            setExplicitString($dailySheet, 'C' . $dailyRow, $item['id_formulario']);
            setExplicitString($dailySheet, 'E' . $dailyRow, $item['codigo_local']);
            foreach (['G' => 'direccion', 'H' => 'comuna', 'I' => 'region'] as $column => $field) {
                setExplicitString($dailySheet, $column . $dailyRow, $item[$field]);
                $dailySheet->getStyle($column . $dailyRow)->getAlignment()->setWrapText(true);
            }
            setProposalDates($dailySheet, 'J' . $dailyRow, array_keys($item['propuestas_por_visita'][$date] ?? []));
            $dailySheet->getStyle('A' . $dailyRow)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
            $dailyRow++;
        }
    }
    styleDataRows($dailySheet, 12, $dailyRow - 1);
    $dailySheet->setAutoFilter('A1:L' . max(1, $dailyRow - 1));
    foreach (['A' => 18, 'B' => 25, 'C' => 16, 'D' => 40, 'E' => 18, 'F' => 40, 'G' => 42, 'H' => 24, 'I' => 28, 'J' => 20, 'K' => 18, 'L' => 18] as $column => $width) {
        $dailySheet->getColumnDimension($column)->setWidth($width);
    }
    $dataSheet = $dailySheet;
}
$spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($dataSheet));
$filename = 'carga_' . ($format === 'detalle' ? 'detalle_' : 'por_fechas_') . safeExportFilename((string)$worker['usuario']) . '_' . date('Ymd_His') . '.xlsx';
if ($exportScope !== 'panel') {
    $filename = ($exportScope === 'activos' ? 'todo_lo_activo_' : 'ejecuciones_' . $dateFrom . '_' . $dateTo . '_')
        . safeExportFilename((string)$worker['usuario']) . '_' . date('Ymd_His') . '.xlsx';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
(new Xlsx($spreadsheet))->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;

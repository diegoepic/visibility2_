<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');
ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$db = $conexion ?? $conn ?? $mysqli ?? null;

function panelJson(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function panelExportExecutiveExcel(array $rows, array $filters): void
{
    $autoloadPath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        panelJson(['ok' => false, 'message' => 'No se encontró el autoload requerido para generar Excel.'], 500);
    }
    require_once $autoloadPath;

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        panelJson(['ok' => false, 'message' => 'PhpSpreadsheet no está disponible en el servidor.'], 500);
    }

    $assigned = count($rows);
    $visited = count(array_filter($rows, static fn(array $row): bool => $row['visitado']));
    $executed = count(array_filter($rows, static fn(array $row): bool => $row['ejecutado']));
    $closed = 0;
    $notExists = 0;
    $daily = [];
    $types = [];
    $merchants = [];
    $campaigns = [];

    foreach ($rows as $row) {
        $date = $row['fecha_planificada'] !== '' ? $row['fecha_planificada'] : 'SIN FECHA';
        $daily[$date] ??= ['asignadas' => 0, 'visitadas' => 0, 'ejecutadas' => 0];
        $daily[$date]['asignadas']++;
        if ($row['visitado']) {
            $daily[$date]['visitadas']++;
            $type = trim((string)$row['estado']) !== '' ? (string)$row['estado'] : 'SIN GESTIÓN';
            $types[$type] = ($types[$type] ?? 0) + 1;
            $normalizedType = strtoupper($type);
            if (strpos($normalizedType, 'LOCAL CERRADO') !== false) {
                $closed++;
            }
            if (strpos($normalizedType, 'LOCAL NO EXISTE') !== false) {
                $notExists++;
            }
        }
        if ($row['ejecutado']) {
            $daily[$date]['ejecutadas']++;
        }

        $user = trim((string)$row['usuario']);
        if ($user !== '' && $user !== 'SIN EJECUTOR') {
            $merchants[$user] ??= ['asignadas' => 0, 'visitadas' => 0, 'ejecutadas' => 0];
            $merchants[$user]['asignadas']++;
            $merchants[$user]['visitadas'] += $row['visitado'] ? 1 : 0;
            $merchants[$user]['ejecutadas'] += $row['ejecutado'] ? 1 : 0;
        }

        $campaignId = (int)($row['id_campana'] ?? 0);
        $campaignKey = (string)$campaignId;
        $campaignName = trim((string)($row['campana'] ?? ''));
        if ($campaignName === '') {
            $campaignName = $campaignId > 0 ? "CAMPAÑA #{$campaignId}" : 'SIN CAMPAÑA';
        }
        $campaigns[$campaignKey] ??= [
            'id_campana' => $campaignId,
            'campana' => $campaignName,
            'asignadas' => 0,
            'visitadas' => 0,
            'ejecutadas' => 0,
            'cerrados' => 0,
            'no_existe' => 0,
            'usuarios' => [],
        ];
        $campaigns[$campaignKey]['asignadas']++;
        $campaigns[$campaignKey]['visitadas'] += $row['visitado'] ? 1 : 0;
        $campaigns[$campaignKey]['ejecutadas'] += $row['ejecutado'] ? 1 : 0;
        $campaignUser = $user !== '' ? $user : 'SIN EJECUTOR';
        $campaigns[$campaignKey]['usuarios'][$campaignUser] ??= ['asignadas' => 0, 'ejecutadas' => 0];
        $campaigns[$campaignKey]['usuarios'][$campaignUser]['asignadas']++;
        $campaigns[$campaignKey]['usuarios'][$campaignUser]['ejecutadas'] += $row['ejecutado'] ? 1 : 0;
        if ($row['visitado']) {
            $campaignState = strtoupper(trim((string)$row['estado']));
            $campaigns[$campaignKey]['cerrados'] += strpos($campaignState, 'LOCAL CERRADO') !== false ? 1 : 0;
            $campaigns[$campaignKey]['no_existe'] += strpos($campaignState, 'LOCAL NO EXISTE') !== false ? 1 : 0;
        }
    }

    ksort($daily);
    arsort($types);
    $merchantRows = [];
    foreach ($merchants as $user => $metrics) {
        if ($metrics['asignadas'] <= 0) {
            continue;
        }
        $metrics['usuario'] = $user;
        $metrics['eficiencia'] = $metrics['ejecutadas'] / $metrics['asignadas'];
        $merchantRows[] = $metrics;
    }

    $best = $merchantRows;
    usort($best, static function (array $a, array $b): int {
        return ($b['eficiencia'] <=> $a['eficiencia'])
            ?: ($b['ejecutadas'] <=> $a['ejecutadas'])
            ?: ($b['asignadas'] <=> $a['asignadas'])
            ?: strcmp($a['usuario'], $b['usuario']);
    });
    $best = array_slice($best, 0, 5);

    $slowest = $merchantRows;
    usort($slowest, static function (array $a, array $b): int {
        return ($a['eficiencia'] <=> $b['eficiencia'])
            ?: ($b['asignadas'] <=> $a['asignadas'])
            ?: ($a['ejecutadas'] <=> $b['ejecutadas'])
            ?: strcmp($a['usuario'], $b['usuario']);
    });
    $slowest = array_slice($slowest, 0, 3);

    $campaignRows = array_values($campaigns);
    foreach ($campaignRows as &$campaignRow) {
        $campaignUsers = [];
        foreach ($campaignRow['usuarios'] as $campaignUser => $userMetrics) {
            $userMetrics['usuario'] = $campaignUser;
            $userMetrics['avance'] = $userMetrics['asignadas'] > 0
                ? $userMetrics['ejecutadas'] / $userMetrics['asignadas']
                : 0;
            $campaignUsers[] = $userMetrics;
        }
        usort($campaignUsers, static function (array $a, array $b): int {
            return ($a['avance'] <=> $b['avance'])
                ?: strcasecmp($a['usuario'], $b['usuario']);
        });
        $campaignRow['usuarios_detalle'] = implode("\n", array_map(
            static fn(array $item): string => sprintf(
                '%s · %s%% (%d/%d)',
                $item['usuario'],
                number_format($item['avance'] * 100, 1, ',', '.'),
                $item['ejecutadas'],
                $item['asignadas']
            ),
            $campaignUsers
        ));
        unset($campaignRow['usuarios']);
    }
    unset($campaignRow);
    usort($campaignRows, static function (array $a, array $b): int {
        return strcmp($a['campana'], $b['campana'])
            ?: ($a['id_campana'] <=> $b['id_campana']);
    });

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Visibility')
        ->setTitle('Informe ejecutivo de visitas')
        ->setSubject('Desempeño operacional de rutas y gestiones')
        ->setDescription('Informe generado desde Panel Visitas Dashboard con los filtros activos.');
    $spreadsheet->getDefaultStyle()->getFont()->setName('Aptos')->setSize(10);

    $summary = $spreadsheet->getActiveSheet();
    $summary->setTitle('Resumen ejecutivo');
    $navy = 'FF12313D';
    $teal = 'FF087F96';
    $tealDark = 'FF006879';
    $tealLight = 'FFDCEEF2';
    $green = 'FF16845B';
    $greenLight = 'FFE4F4EC';
    $amber = 'FFE9A23B';
    $amberLight = 'FFFFF1D6';
    $red = 'FFB54747';
    $redLight = 'FFFBE7E7';
    $slate = 'FF5E737D';
    $line = 'FFD5E2E7';
    $white = 'FFFFFFFF';
    $titleStyle = [
        'font' => ['bold' => true, 'color' => ['ARGB' => $white], 'size' => 18],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $navy]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ];
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['ARGB' => $white]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $tealDark]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['ARGB' => $tealDark]]],
    ];
    $subheaderStyle = [
        'font' => ['bold' => true, 'color' => ['ARGB' => $navy]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $tealLight]],
        'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['ARGB' => $line]]],
    ];

    $summary->mergeCells('A1:I1')->setCellValue('A1', 'INFORME EJECUTIVO · PANEL DE VISITAS');
    $summary->getStyle('A1:I1')->applyFromArray($titleStyle);
    $summary->fromArray([
        ['Generado', date('d-m-Y H:i:s')],
        ['División', $filters['division']],
        ['Subdivisión', $filters['subdivision']],
        ['Estado campaña', $filters['estado']],
        ['Merchandiser', $filters['usuario']],
        ['Día planificado', $filters['fecha']],
    ], null, 'A3');
    $summary->getStyle('A3:A8')->applyFromArray($subheaderStyle);

    $summary->setCellValue('A10', 'RESUMEN DE GESTIONES');
    $summary->getStyle('A10:B10')->applyFromArray($headerStyle);
    $summary->fromArray([
        ['Asignaciones planificadas', $assigned],
        ['Locales visitados', $visited],
        ['Gestiones ejecutadas', $executed],
        ['Locales pendientes', max(0, $assigned - $visited)],
        ['Locales cerrados', $closed],
        ['Locales no existe', $notExists],
        ['Avance de visita', $assigned > 0 ? $visited / $assigned : 0],
        ['Eficiencia de ejecución', $assigned > 0 ? $executed / $assigned : 0],
    ], null, 'A11');
    $summary->getStyle('B17:B18')->getNumberFormat()->setFormatCode('0.0%');

    $summary->setCellValue('D10', 'TOP 5 MERCHANTS · MAYOR EFICIENCIA');
    $summary->getStyle('D10:G10')->applyFromArray($headerStyle);
    $summary->fromArray([['Merchandiser', 'Asignadas', 'Ejecutadas', 'Eficiencia']], null, 'D11');
    $summary->getStyle('D11:G11')->applyFromArray($subheaderStyle);
    $rowNumber = 12;
    foreach ($best as $item) {
        $summary->fromArray([$item['usuario'], $item['asignadas'], $item['ejecutadas'], $item['eficiencia']], null, "D{$rowNumber}");
        $summary->getStyle("G{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }

    $summary->setCellValue('D19', 'TOP 3 MERCHANTS · AVANCE MÁS LENTO');
    $summary->getStyle('D19:G19')->applyFromArray($headerStyle);
    $summary->fromArray([['Merchandiser', 'Asignadas', 'Ejecutadas', 'Avance']], null, 'D20');
    $summary->getStyle('D20:G20')->applyFromArray($subheaderStyle);
    $rowNumber = 21;
    foreach ($slowest as $item) {
        $summary->fromArray([$item['usuario'], $item['asignadas'], $item['ejecutadas'], $item['eficiencia']], null, "D{$rowNumber}");
        $summary->getStyle("G{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }
    $summary->setCellValue('A21', 'Criterio de rankings');
    $summary->setCellValue('B21', 'Gestiones ejecutadas / asignaciones planificadas. Solo merchants con base asignada mayor que cero; desempate por volumen y nombre.');
    $summary->mergeCells('B21:B23');
    $summary->getStyle('B21')->getAlignment()->setWrapText(true);

    $dailySheet = $spreadsheet->createSheet();
    $dailySheet->setTitle('Avance diario');
    $dailySheet->fromArray(['Día planificado', 'Asignadas', 'Visitadas', 'Ejecutadas', 'Pendientes', '% visita', '% ejecución'], null, 'A1');
    $dailySheet->getStyle('A1:G1')->applyFromArray($headerStyle);
    $rowNumber = 2;
    foreach ($daily as $date => $metrics) {
        $dailySheet->fromArray([
            $date,
            $metrics['asignadas'],
            $metrics['visitadas'],
            $metrics['ejecutadas'],
            max(0, $metrics['asignadas'] - $metrics['visitadas']),
            $metrics['asignadas'] > 0 ? $metrics['visitadas'] / $metrics['asignadas'] : 0,
            $metrics['asignadas'] > 0 ? $metrics['ejecutadas'] / $metrics['asignadas'] : 0,
        ], null, "A{$rowNumber}");
        $dailySheet->getStyle("F{$rowNumber}:G{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }

    $typesSheet = $spreadsheet->createSheet();
    $typesSheet->setTitle('Tipificaciones');
    $typesSheet->fromArray(['Tipificación', 'Locales', '% de visitados'], null, 'A1');
    $typesSheet->getStyle('A1:C1')->applyFromArray($headerStyle);
    $rowNumber = 2;
    foreach ($types as $type => $count) {
        $typesSheet->fromArray([$type, $count, $visited > 0 ? $count / $visited : 0], null, "A{$rowNumber}");
        $typesSheet->getStyle("C{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }

    $merchantsSheet = $spreadsheet->createSheet();
    $merchantsSheet->setTitle('Merchants');
    $merchantsSheet->fromArray(['Merchandiser', 'Asignadas', 'Visitadas', 'Ejecutadas', 'Pendientes', 'Eficiencia'], null, 'A1');
    $merchantsSheet->getStyle('A1:F1')->applyFromArray($headerStyle);
    usort($merchantRows, static fn(array $a, array $b): int => strcmp($a['usuario'], $b['usuario']));
    $rowNumber = 2;
    foreach ($merchantRows as $item) {
        $merchantsSheet->fromArray([
            $item['usuario'], $item['asignadas'], $item['visitadas'], $item['ejecutadas'],
            max(0, $item['asignadas'] - $item['visitadas']), $item['eficiencia'],
        ], null, "A{$rowNumber}");
        $merchantsSheet->getStyle("F{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }

    $campaignSheet = $spreadsheet->createSheet();
    $campaignSheet->setTitle('Avance por campaña');
    $campaignSheet->fromArray([
        'ID', 'Campaña / actividad', 'Asignaciones', 'Visitados', 'Ejecutados',
        'Pendientes', 'Cerrados', 'No existe', 'Avance', 'Eficiencia', 'Avance por usuario',
    ], null, 'A1');
    $campaignSheet->getStyle('A1:K1')->applyFromArray($headerStyle);
    $rowNumber = 2;
    foreach ($campaignRows as $item) {
        $campaignSheet->fromArray([
            $item['id_campana'],
            $item['campana'],
            $item['asignadas'],
            $item['visitadas'],
            $item['ejecutadas'],
            max(0, $item['asignadas'] - $item['visitadas']),
            $item['cerrados'],
            $item['no_existe'],
            $item['asignadas'] > 0 ? $item['visitadas'] / $item['asignadas'] : 0,
            $item['asignadas'] > 0 ? $item['ejecutadas'] / $item['asignadas'] : 0,
            $item['usuarios_detalle'],
        ], null, "A{$rowNumber}");
        $campaignSheet->getStyle("I{$rowNumber}:J{$rowNumber}")->getNumberFormat()->setFormatCode('0.0%');
        $rowNumber++;
    }

    $bodyStyle = [
        'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['ARGB' => $line]]],
        'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ];
    $totalStyle = [
        'font' => ['bold' => true, 'color' => ['ARGB' => $white]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $navy]],
        'borders' => ['top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['ARGB' => $navy]]],
    ];

    /* Portada y resumen ejecutivo. */
    $summary->getRowDimension(1)->setRowHeight(34);
    $summary->mergeCells('A2:I2')->setCellValue('A2', 'Desempeño operacional de rutas, visitas y gestiones · preparado para presentación a clientes');
    $summary->getStyle('A2:I2')->applyFromArray([
        'font' => ['color' => ['ARGB' => $white], 'italic' => true, 'size' => 10],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $teal]],
        'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $summary->getRowDimension(2)->setRowHeight(22);
    foreach (range(3, 8) as $filterRow) {
        $summary->mergeCells("B{$filterRow}:C{$filterRow}");
    }
    $summary->setCellValue('B3', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable()));
    $summary->getStyle('B3')->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['fecha'])) {
        $summary->setCellValue('B8', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable((string)$filters['fecha'])));
        $summary->getStyle('B8')->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
    }
    $summary->getStyle('A3:A8')->applyFromArray($subheaderStyle);
    $summary->getStyle('B3:C8')->applyFromArray($bodyStyle);
    $summary->mergeCells('A10:B10');
    $summary->mergeCells('D10:G10');
    $summary->mergeCells('D19:G19');
    $summary->getStyle('A10:B10')->applyFromArray($headerStyle);
    $summary->getStyle('D10:G10')->applyFromArray($headerStyle);
    $summary->getRowDimension(10)->setRowHeight(24);
    $summary->getRowDimension(11)->setRowHeight(22);
    $summary->getStyle('D19:G19')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['ARGB' => $white]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => $red]],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $summary->getRowDimension(19)->setRowHeight(24);
    $summary->getRowDimension(20)->setRowHeight(22);
    $summary->getStyle('A11:A18')->applyFromArray($subheaderStyle);
    $summary->getStyle('B11:B18')->applyFromArray($bodyStyle);
    $summary->getStyle('B11:B16')->getNumberFormat()->setFormatCode('#,##0');
    $summary->getStyle('B11:B18')->getFont()->setBold(true)->getColor()->setARGB($navy);
    $summary->getStyle('A15:B16')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($redLight);
    $bestEndRow = 11 + count($best);
    if ($bestEndRow >= 12) {
        $summary->getStyle("D12:G{$bestEndRow}")->applyFromArray($bodyStyle);
        $summary->getStyle("D12:G{$bestEndRow}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($greenLight);
        $summary->getStyle("E12:F{$bestEndRow}")->getNumberFormat()->setFormatCode('#,##0');
    }
    $slowEndRow = 20 + count($slowest);
    if ($slowEndRow >= 21) {
        $summary->getStyle("D21:G{$slowEndRow}")->applyFromArray($bodyStyle);
        $summary->getStyle("D21:G{$slowEndRow}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($redLight);
        $summary->getStyle("E21:F{$slowEndRow}")->getNumberFormat()->setFormatCode('#,##0');
    }
    $summary->mergeCells('A21:A23');
    $summary->getStyle('A21:A23')->applyFromArray($subheaderStyle);
    $summary->getStyle('A21:A23')->getAlignment()->setWrapText(true);
    $summary->getStyle('B21:B23')->applyFromArray([
        'font' => ['italic' => true, 'color' => ['ARGB' => $slate]],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FFF4F8F9']],
        'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    foreach (['A' => 24, 'B' => 20, 'C' => 3, 'D' => 25, 'E' => 13, 'F' => 13, 'G' => 14, 'H' => 3, 'I' => 3] as $column => $width) {
        $summary->getColumnDimension($column)->setWidth($width);
    }
    $summary->freezePane('A3');
    $summary->getTabColor()->setARGB($teal);

    /* Hojas tabulares: título, subtítulo, totales, filtros y formatos. */
    $dailyCount = count($daily);
    $dailySheet->insertNewRowBefore(1, 3);
    $dailySheet->mergeCells('A1:G1')->setCellValue('A1', 'AVANCE DIARIO');
    $dailySheet->getStyle('A1:G1')->applyFromArray($titleStyle);
    $dailySheet->mergeCells('A2:G2')->setCellValue('A2', 'Cumplimiento por día planificado · porcentajes sobre asignaciones');
    $dailySheet->getStyle('A2:G2')->applyFromArray($subheaderStyle);
    $dailySheet->getStyle('A4:G4')->applyFromArray($headerStyle);
    $dailySheet->getRowDimension(1)->setRowHeight(32);
    $dailySheet->getRowDimension(2)->setRowHeight(22);
    $dailySheet->getRowDimension(4)->setRowHeight(24);
    $dailyDataEnd = 4 + $dailyCount;
    for ($rowIndex = 5; $rowIndex <= $dailyDataEnd; $rowIndex++) {
        $rawDate = (string)$dailySheet->getCell("A{$rowIndex}")->getValue();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $dailySheet->setCellValue("A{$rowIndex}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($rawDate)));
            $dailySheet->getStyle("A{$rowIndex}")->getNumberFormat()->setFormatCode('dd-mmm-yyyy');
        }
        $dailySheet->getStyle("A{$rowIndex}:G{$rowIndex}")->applyFromArray($bodyStyle);
        $dailySheet->getStyle("B{$rowIndex}:E{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        if ($rowIndex % 2 === 0) {
            $dailySheet->getStyle("A{$rowIndex}:G{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F8F9');
        }
    }
    $dailyTotalRow = $dailyDataEnd + 1;
    $dailySheet->fromArray(['TOTAL', $assigned, $visited, $executed, max(0, $assigned - $visited), $assigned > 0 ? $visited / $assigned : 0, $assigned > 0 ? $executed / $assigned : 0], null, "A{$dailyTotalRow}");
    $dailySheet->getStyle("A{$dailyTotalRow}:G{$dailyTotalRow}")->applyFromArray($totalStyle);
    $dailySheet->getStyle("B{$dailyTotalRow}:E{$dailyTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
    $dailySheet->getStyle("F{$dailyTotalRow}:G{$dailyTotalRow}")->getNumberFormat()->setFormatCode('0.0%');
    if ($dailyCount > 0) {
        $dailySheet->setAutoFilter("A4:G{$dailyDataEnd}");
    }
    $dailySheet->freezePane('B5');
    foreach (['A' => 21, 'B' => 13, 'C' => 13, 'D' => 13, 'E' => 13, 'F' => 13, 'G' => 14] as $column => $width) {
        $dailySheet->getColumnDimension($column)->setWidth($width);
    }
    $dailySheet->getTabColor()->setARGB($teal);

    $typesCount = count($types);
    $typesSheet->insertNewRowBefore(1, 3);
    $typesSheet->mergeCells('A1:C1')->setCellValue('A1', 'TIPIFICACIONES DE GESTIÓN');
    $typesSheet->getStyle('A1:C1')->applyFromArray($titleStyle);
    $typesSheet->mergeCells('A2:C2')->setCellValue('A2', 'Distribución de estados sobre locales visitados');
    $typesSheet->getStyle('A2:C2')->applyFromArray($subheaderStyle);
    $typesSheet->getStyle('A4:C4')->applyFromArray($headerStyle);
    $typesSheet->getRowDimension(1)->setRowHeight(32);
    $typesSheet->getRowDimension(2)->setRowHeight(22);
    $typesSheet->getRowDimension(4)->setRowHeight(24);
    $typesDataEnd = 4 + $typesCount;
    for ($rowIndex = 5; $rowIndex <= $typesDataEnd; $rowIndex++) {
        $typesSheet->getStyle("A{$rowIndex}:C{$rowIndex}")->applyFromArray($bodyStyle);
        $typesSheet->getStyle("B{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        if ($rowIndex % 2 === 0) {
            $typesSheet->getStyle("A{$rowIndex}:C{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F8F9');
        }
    }
    $typesTotalRow = $typesDataEnd + 1;
    $typesSheet->fromArray(['TOTAL VISITADOS', array_sum($types), $visited > 0 ? array_sum($types) / $visited : 0], null, "A{$typesTotalRow}");
    $typesSheet->getStyle("A{$typesTotalRow}:C{$typesTotalRow}")->applyFromArray($totalStyle);
    $typesSheet->getStyle("B{$typesTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
    $typesSheet->getStyle("C{$typesTotalRow}")->getNumberFormat()->setFormatCode('0.0%');
    if ($typesCount > 0) {
        $typesSheet->setAutoFilter("A4:C{$typesDataEnd}");
    }
    $typesSheet->freezePane('A5');
    foreach (['A' => 36, 'B' => 14, 'C' => 18] as $column => $width) {
        $typesSheet->getColumnDimension($column)->setWidth($width);
    }
    $typesSheet->getTabColor()->setARGB($amber);

    $merchantCount = count($merchantRows);
    $merchantsSheet->insertNewRowBefore(1, 3);
    $merchantsSheet->mergeCells('A1:F1')->setCellValue('A1', 'DESEMPEÑO POR MERCHANDISER');
    $merchantsSheet->getStyle('A1:F1')->applyFromArray($titleStyle);
    $merchantsSheet->mergeCells('A2:F2')->setCellValue('A2', 'Eficiencia = gestiones ejecutadas / asignaciones planificadas');
    $merchantsSheet->getStyle('A2:F2')->applyFromArray($subheaderStyle);
    $merchantsSheet->getStyle('A4:F4')->applyFromArray($headerStyle);
    $merchantsSheet->getRowDimension(1)->setRowHeight(32);
    $merchantsSheet->getRowDimension(2)->setRowHeight(22);
    $merchantsSheet->getRowDimension(4)->setRowHeight(24);
    $merchantDataEnd = 4 + $merchantCount;
    for ($rowIndex = 5; $rowIndex <= $merchantDataEnd; $rowIndex++) {
        $merchantsSheet->getStyle("A{$rowIndex}:F{$rowIndex}")->applyFromArray($bodyStyle);
        $merchantsSheet->getStyle("B{$rowIndex}:E{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $efficiency = (float)$merchantsSheet->getCell("F{$rowIndex}")->getValue();
        $efficiencyFill = $efficiency >= 0.8 ? $greenLight : ($efficiency >= 0.5 ? $amberLight : $redLight);
        $merchantsSheet->getStyle("F{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($efficiencyFill);
        if ($rowIndex % 2 === 0) {
            $merchantsSheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F8F9');
        }
    }
    $merchantAssigned = array_sum(array_column($merchantRows, 'asignadas'));
    $merchantVisited = array_sum(array_column($merchantRows, 'visitadas'));
    $merchantExecuted = array_sum(array_column($merchantRows, 'ejecutadas'));
    $merchantTotalRow = $merchantDataEnd + 1;
    $merchantsSheet->fromArray(['TOTAL', $merchantAssigned, $merchantVisited, $merchantExecuted, max(0, $merchantAssigned - $merchantVisited), $merchantAssigned > 0 ? $merchantExecuted / $merchantAssigned : 0], null, "A{$merchantTotalRow}");
    $merchantsSheet->getStyle("A{$merchantTotalRow}:F{$merchantTotalRow}")->applyFromArray($totalStyle);
    $merchantsSheet->getStyle("B{$merchantTotalRow}:E{$merchantTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
    $merchantsSheet->getStyle("F{$merchantTotalRow}")->getNumberFormat()->setFormatCode('0.0%');
    if ($merchantCount > 0) {
        $merchantsSheet->setAutoFilter("A4:F{$merchantDataEnd}");
    }
    $merchantsSheet->freezePane('B5');
    foreach (['A' => 28, 'B' => 13, 'C' => 13, 'D' => 13, 'E' => 13, 'F' => 15] as $column => $width) {
        $merchantsSheet->getColumnDimension($column)->setWidth($width);
    }
    $merchantsSheet->getTabColor()->setARGB($green);

    $campaignCount = count($campaignRows);
    $campaignSheet->insertNewRowBefore(1, 3);
    $campaignSheet->mergeCells('A1:K1')->setCellValue('A1', 'AVANCE POR CAMPAÑA / ACTIVIDAD');
    $campaignSheet->getStyle('A1:K1')->applyFromArray($titleStyle);
    $campaignSheet->mergeCells('A2:K2')->setCellValue('A2', 'Progreso por campaña (formulario.nombre) · avance = visitados / asignaciones · eficiencia y avance por usuario = ejecutados / asignaciones');
    $campaignSheet->getStyle('A2:K2')->applyFromArray($subheaderStyle);
    $campaignSheet->getStyle('A4:K4')->applyFromArray($headerStyle);
    $campaignSheet->getStyle('K4')->getAlignment()->setWrapText(true);
    $campaignSheet->getRowDimension(1)->setRowHeight(32);
    $campaignSheet->getRowDimension(2)->setRowHeight(22);
    $campaignSheet->getRowDimension(4)->setRowHeight(24);
    $campaignDataEnd = 4 + $campaignCount;
    for ($rowIndex = 5; $rowIndex <= $campaignDataEnd; $rowIndex++) {
        $campaignSheet->getStyle("A{$rowIndex}:K{$rowIndex}")->applyFromArray($bodyStyle);
        $campaignSheet->getStyle("A{$rowIndex}:K{$rowIndex}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        $campaignSheet->getStyle("K{$rowIndex}")->getAlignment()->setWrapText(true);
        $campaignSheet->getStyle("A{$rowIndex}:H{$rowIndex}")->getNumberFormat()->setFormatCode('#,##0');
        $campaignSheet->getStyle("I{$rowIndex}:J{$rowIndex}")->getNumberFormat()->setFormatCode('0.0%');
        $efficiency = (float)$campaignSheet->getCell("J{$rowIndex}")->getValue();
        $efficiencyFill = $efficiency >= 0.8 ? $greenLight : ($efficiency >= 0.5 ? $amberLight : $redLight);
        $campaignSheet->getStyle("J{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($efficiencyFill);
        if ($rowIndex % 2 === 0) {
            $campaignSheet->getStyle("A{$rowIndex}:I{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F8F9');
            $campaignSheet->getStyle("K{$rowIndex}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F8F9');
        }
        $detailLines = max(1, substr_count((string)$campaignSheet->getCell("K{$rowIndex}")->getValue(), "\n") + 1);
        $campaignSheet->getRowDimension($rowIndex)->setRowHeight(min(409, max(24, 15 * $detailLines + 6)));
    }
    $campaignTotalRow = $campaignDataEnd + 1;
    $campaignSheet->fromArray([
        '', 'TOTAL', $assigned, $visited, $executed, max(0, $assigned - $visited),
        $closed, $notExists, $assigned > 0 ? $visited / $assigned : 0,
        $assigned > 0 ? $executed / $assigned : 0,
        '',
    ], null, "A{$campaignTotalRow}");
    $campaignSheet->getStyle("A{$campaignTotalRow}:K{$campaignTotalRow}")->applyFromArray($totalStyle);
    $campaignSheet->getStyle("C{$campaignTotalRow}:H{$campaignTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
    $campaignSheet->getStyle("I{$campaignTotalRow}:J{$campaignTotalRow}")->getNumberFormat()->setFormatCode('0.0%');
    if ($campaignCount > 0) {
        $campaignSheet->setAutoFilter("A4:K{$campaignDataEnd}");
    }
    $campaignSheet->freezePane('C5');
    foreach (['A' => 11, 'B' => 38, 'C' => 14, 'D' => 13, 'E' => 13, 'F' => 13, 'G' => 12, 'H' => 12, 'I' => 13, 'J' => 13, 'K' => 44] as $column => $width) {
        $campaignSheet->getColumnDimension($column)->setWidth($width);
    }
    $campaignSheet->getTabColor()->setARGB($tealDark);

    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheet->setShowGridlines(false);
        $sheet->getSheetView()->setZoomScale($sheet === $summary ? 90 : 95);
        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setHorizontalCentered(true);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $sheet === $summary ? 2 : 4);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getPageSetup()->setPrintArea($sheet->calculateWorksheetDimension());
    }

    $spreadsheet->setActiveSheetIndex(0);
    $filename = 'informe_ejecutivo_visitas_' . date('Ymd_His') . '.xlsx';
    $temporaryFile = tempnam(sys_get_temp_dir(), 'visdash_');
    if ($temporaryFile === false) {
        throw new RuntimeException('No fue posible crear el archivo temporal del informe.');
    }

    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($temporaryFile);
        $fileSize = filesize($temporaryFile);
        $handle = fopen($temporaryFile, 'rb');
        $signature = $handle !== false ? fread($handle, 2) : false;
        if ($handle !== false) {
            fclose($handle);
        }
        if ($fileSize === false || $fileSize <= 0 || $signature !== 'PK') {
            throw new RuntimeException('PhpSpreadsheet no generó un archivo XLSX válido.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        readfile($temporaryFile);
        unlink($temporaryFile);
        $spreadsheet->disconnectWorksheets();
        exit;
    } catch (Throwable $exception) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        $spreadsheet->disconnectWorksheets();
        throw $exception;
    }
}

if (!($db instanceof mysqli)) {
    panelJson(['ok' => false, 'message' => 'No fue posible conectar con la base de datos.'], 500);
}
$db->set_charset('utf8mb4');

$sessionUserId = (int)($_SESSION['usuario_id'] ?? 0);
$sessionCompanyId = (int)($_SESSION['empresa_id'] ?? 0);
$sessionDivisionId = (int)($_SESSION['division_id'] ?? 0);

if ($sessionUserId <= 0) {
    panelJson(['ok' => false, 'message' => 'La sesión expiró. Vuelve a ingresar.'], 401);
}

function panelIsMcDivision(mysqli $db, int $divisionId): bool
{
    if ($divisionId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtoupper(trim((string)($row['nombre'] ?? ''))) === 'MC';
}

function panelCanAccessDivision(mysqli $db, int $divisionId, int $sessionDivisionId): bool
{
    return panelIsMcDivision($db, $sessionDivisionId)
        || ($sessionDivisionId > 0 && $divisionId === $sessionDivisionId);
}

function panelDivisionExists(mysqli $db, int $divisionId, int $companyId): bool
{
    $sql = 'SELECT id FROM division_empresa WHERE id = ? AND estado = 1';
    if ($companyId > 0) {
        $sql .= ' AND id_empresa = ?';
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($companyId > 0) {
        $stmt->bind_param('ii', $divisionId, $companyId);
    } else {
        $stmt->bind_param('i', $divisionId);
    }
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'catalogs')));

if ($action === 'catalogs') {
    $isMc = panelIsMcDivision($db, $sessionDivisionId);
    $divisions = [];
    $sql = 'SELECT id, nombre FROM division_empresa WHERE estado = 1';
    $params = [];
    $types = '';
    if ($sessionCompanyId > 0) {
        $sql .= ' AND id_empresa = ?';
        $params[] = $sessionCompanyId;
        $types .= 'i';
    }
    if (!$isMc) {
        $sql .= ' AND id = ?';
        $params[] = $sessionDivisionId;
        $types .= 'i';
    }
    $sql .= ' ORDER BY nombre';
    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $divisions[] = ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']];
    }
    $stmt->close();

    $subdivisions = [];
    if ($divisions) {
        $ids = implode(',', array_map(static fn(array $row): int => $row['id'], $divisions));
        $result = $db->query("SELECT id, id_division, nombre FROM subdivision WHERE id_division IN ({$ids}) ORDER BY nombre");
        while ($row = $result->fetch_assoc()) {
            $subdivisions[] = [
                'id' => (int)$row['id'],
                'id_division' => (int)$row['id_division'],
                'nombre' => (string)$row['nombre'],
            ];
        }
        $result->free();
    }

    panelJson([
        'ok' => true,
        'data' => [
            'divisions' => $divisions,
            'subdivisions' => $subdivisions,
            'default_division' => !$isMc ? $sessionDivisionId : 0,
        ],
    ]);
}

if (!in_array($action, ['data', 'export'], true)) {
    panelJson(['ok' => false, 'message' => 'Acción no válida.'], 400);
}

$divisionId = (int)($_GET['id_division'] ?? 0);
$subdivisionId = (int)($_GET['id_subdivision'] ?? 0);
$campaignStatus = (int)($_GET['estado'] ?? 0);

if ($divisionId <= 0) {
    panelJson(['ok' => false, 'message' => 'Selecciona una división.'], 400);
}
if (!in_array($campaignStatus, [0, 1, 3], true)) {
    panelJson(['ok' => false, 'message' => 'El estado de campaña no es válido.'], 400);
}
if (!panelCanAccessDivision($db, $divisionId, $sessionDivisionId)
    || !panelDivisionExists($db, $divisionId, $sessionCompanyId)) {
    panelJson(['ok' => false, 'message' => 'No tienes acceso a la división seleccionada.'], 403);
}
if ($subdivisionId > 0) {
    $stmt = $db->prepare('SELECT id FROM subdivision WHERE id = ? AND id_division = ? LIMIT 1');
    $stmt->bind_param('ii', $subdivisionId, $divisionId);
    $stmt->execute();
    $validSubdivision = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$validSubdivision) {
        panelJson(['ok' => false, 'message' => 'La subdivisión no pertenece a la división seleccionada.'], 400);
    }
}

$where = ["f.id_division = {$divisionId}", 'f.tipo IN (1,3)'];
if ($subdivisionId > 0) {
    $where[] = "f.id_subdivision = {$subdivisionId}";
}
if ($campaignStatus > 0) {
    $where[] = "f.estado = {$campaignStatus}";
}
$whereSql = implode(' AND ', $where);
$normalizedQuestion = "UPPER(REPLACE(TRIM(COALESCE(fq.pregunta, '')), '_', ' '))";

$query = "
    SELECT
        CONCAT_WS('|', f.id, fq.id_local, fq.id_usuario) AS `key`,
        f.id AS id_campana,
        COALESCE(NULLIF(TRIM(MAX(f.nombre)), ''), CONCAT('CAMPAÑA #', f.id)) AS campana,
        COALESCE(NULLIF(TRIM(u.usuario), ''), 'SIN EJECUTOR') AS usuario,
        DATE_FORMAT(
            MAX(
                CASE
                    WHEN fq.fechaPropuesta IS NOT NULL
                     AND TRIM(CAST(fq.fechaPropuesta AS CHAR)) <> ''
                     AND LEFT(CAST(fq.fechaPropuesta AS CHAR), 10) <> '0000-00-00'
                    THEN fq.fechaPropuesta
                    ELSE NULL
                END
            ),
            '%Y-%m-%d'
        ) AS fecha_planificada,
        MAX(CASE WHEN fq.fechaVisita IS NOT NULL
                  AND LEFT(CAST(fq.fechaVisita AS CHAR), 10) <> '0000-00-00'
                 THEN 1 ELSE 0 END) AS visitado,
        MAX(CASE WHEN UPPER(TRIM(COALESCE(fq.pregunta, ''))) IN
                         ('AUDITORIA','IMPLEMENTACION','IMPL/AUD','IMPLEMENTADO_AUDITADO','SOLO_AUDITORIA','SOLO_AUDITADO','SOLO_IMPLEMENTADO')
                 THEN 1 ELSE 0 END) AS ejecutado,
        CASE
            WHEN MAX(CASE WHEN UPPER(TRIM(COALESCE(fq.pregunta, ''))) IN
                                  ('AUDITORIA','IMPLEMENTACION','IMPL/AUD','IMPLEMENTADO_AUDITADO','SOLO_AUDITORIA','SOLO_AUDITADO','SOLO_IMPLEMENTADO')
                          THEN 1 ELSE 0 END) = 1
            THEN 'GESTIONADO'
            ELSE COALESCE(
                NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF({$normalizedQuestion}, '') ORDER BY fq.id DESC SEPARATOR '||'), '||', 1), ''),
                'SIN GESTIÓN'
            )
        END AS estado
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN usuario u ON u.id = fq.id_usuario
    WHERE {$whereSql}
    GROUP BY f.id, fq.id_local, fq.id_usuario, u.usuario
    ORDER BY fecha_planificada ASC, usuario ASC
";

$result = $db->query($query);
if (!$result) {
    error_log('[panel_visitas_data] ' . $db->error);
    panelJson([
        'ok' => false,
        'message' => 'No fue posible consultar los registros del panel. Detalle: ' . $db->error,
    ], 500);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'key' => (string)$row['key'],
        'id_campana' => (int)$row['id_campana'],
        'campana' => (string)$row['campana'],
        'usuario' => (string)$row['usuario'],
        'fecha_planificada' => (string)($row['fecha_planificada'] ?? ''),
        'visitado' => (bool)$row['visitado'],
        'ejecutado' => (bool)$row['ejecutado'],
        'estado' => (string)$row['estado'],
    ];
}
$result->free();

if ($action === 'export') {
    $userFilter = trim((string)($_GET['usuario'] ?? ''));
    $dateFilter = trim((string)($_GET['fecha_planificada'] ?? ''));
    if ($dateFilter !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
        panelJson(['ok' => false, 'message' => 'El día planificado no es válido.'], 400);
    }
    $rows = array_values(array_filter($rows, static function (array $row) use ($userFilter, $dateFilter): bool {
        return ($userFilter === '' || $row['usuario'] === $userFilter)
            && ($dateFilter === '' || $row['fecha_planificada'] === $dateFilter);
    }));

    $stmt = $db->prepare('SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $divisionName = (string)($stmt->get_result()->fetch_assoc()['nombre'] ?? $divisionId);
    $stmt->close();

    $subdivisionName = 'Todas';
    if ($subdivisionId > 0) {
        $stmt = $db->prepare('SELECT nombre FROM subdivision WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $subdivisionId);
        $stmt->execute();
        $subdivisionName = (string)($stmt->get_result()->fetch_assoc()['nombre'] ?? $subdivisionId);
        $stmt->close();
    }

    try {
        panelExportExecutiveExcel($rows, [
            'division' => $divisionName,
            'subdivision' => $subdivisionName,
            'estado' => [0 => 'Ambos estados', 1 => 'En curso', 3 => 'Finalizadas'][$campaignStatus],
            'usuario' => $userFilter !== '' ? $userFilter : 'Todos',
            'fecha' => $dateFilter !== '' ? $dateFilter : 'Todos los días',
        ]);
    } catch (Throwable $exception) {
        error_log('[panel_visitas_export] ' . $exception->getMessage());
        panelJson([
            'ok' => false,
            'message' => 'No fue posible generar el informe Excel. Revisa que PhpSpreadsheet y la extensión ZIP estén disponibles.',
        ], 500);
    }
}

panelJson(['ok' => true, 'data' => $rows, 'total' => count($rows)]);

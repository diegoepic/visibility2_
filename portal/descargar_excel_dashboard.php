<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('memory_limit', '2048M');
set_time_limit(0);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

mysqli_set_charset($conn, 'utf8mb4');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function dashboardFail(string $message, int $status = 400): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function dashboardTextoPlano(Worksheet $sheet, int $column, int $row, string $value): void
{
    $cell = Coordinate::stringFromColumnIndex($column) . $row;
    $sheet->setCellValueExplicit($cell, trim($value), DataType::TYPE_STRING);
}

function dashboardEstadoFormulario(int $estado): string
{
    return match ($estado) {
        1 => 'EN CURSO',
        2 => 'EN PROCESO',
        3 => 'FINALIZADO',
        4 => 'CANCELADO',
        default => 'DESCONOCIDO',
    };
}

function dashboardFechaValida($value): string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }

    return $value;
}

function dashboardEjecucion(array $row): string
{
    $pregunta = strtolower(trim((string)($row['pregunta'] ?? '')));
    $modalidad = strtolower(trim((string)($row['modalidad'] ?? '')));
    $etapa = strtolower(trim((string)($row['etapa_material'] ?? '')));
    $valor = (float)($row['valor'] ?? 0);

    if ($modalidad === 'retiro') {
        return ($valor >= 1 || in_array($pregunta, ['solo_implementado', 'implementado_auditado', 'solo_retirado'], true))
            ? 'RETIRADO'
            : 'NO RETIRADO';
    }

    if ($modalidad === 'entrega') {
        return ($valor >= 1 || in_array($pregunta, ['solo_implementado', 'implementado_auditado'], true))
            ? 'ENTREGADO'
            : 'NO ENTREGADO';
    }

    if ($modalidad === 'implementacion_por_etapas') {
        return in_array($etapa, ['implementado', 'retirado'], true)
            ? strtoupper($etapa)
            : 'NO IMPLEMENTADO';
    }

    if ($valor >= 1 || in_array($pregunta, ['solo_implementado', 'implementado_auditado'], true)) {
        return 'IMPLEMENTADO';
    }

    if (in_array($pregunta, ['solo_auditado', 'solo_auditoria'], true)) {
        return 'AUDITORIA';
    }

    if ($pregunta === 'solo_retirado') {
        return 'RETIRADO';
    }

    return 'NO IMPLEMENTADO';
}

function dashboardMotivo(array $row): string
{
    $pregunta = strtolower(trim((string)($row['pregunta'] ?? '')));
    $observacion = trim((string)($row['observacion'] ?? ''));
    $valor = (float)($row['valor'] ?? 0);

    if ($valor <= 0 || in_array($pregunta, ['en proceso', 'cancelado'], true)) {
        $observacion = str_replace('|', '-', $observacion);
        $partes = explode('-', $observacion, 2);
        return mb_strtoupper(trim((string)($partes[0] ?? '')), 'UTF-8');
    }

    if (in_array($pregunta, ['solo_implementado', 'solo_auditoria', 'implementado_auditado', 'solo_retirado'], true)) {
        return '';
    }

    return mb_strtoupper(str_replace('_', ' ', $pregunta), 'UTF-8');
}

function dashboardNormalizarFoto(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $url = ltrim(str_replace('\\', '/', $url), '/');
    return 'https://www.visibility.cl/visibility2/app/' . $url;
}

function dashboardEjecucionPrincipal(array $estados): string
{
    $prioridad = [
        'IMPLEMENTADO' => 100,
        'RETIRADO' => 100,
        'ENTREGADO' => 100,
        'AUDITORIA' => 80,
        'NO IMPLEMENTADO' => 10,
        'NO RETIRADO' => 10,
        'NO ENTREGADO' => 10,
    ];

    $seleccionado = '';
    $puntaje = -1;
    foreach (array_keys($estados) as $estado) {
        $actual = $prioridad[$estado] ?? 0;
        if ($actual > $puntaje) {
            $seleccionado = $estado;
            $puntaje = $actual;
        }
    }

    return $seleccionado;
}

function dashboardAplicarCabecera(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 10,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '111827'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'bottom' => [
                'borderStyle' => 'thin',
                'color' => ['rgb' => '7030A0'],
            ],
        ],
    ]);
}

function dashboardAplicarTarjeta(Worksheet $sheet, string $range, string $labelRange, string $valueRange, string $color): void
{
    $sheet->mergeCells($labelRange);
    $sheet->mergeCells($valueRange);
    $sheet->getStyle($range)->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F8FAFC'],
        ],
        'borders' => [
            'outline' => [
                'borderStyle' => 'thin',
                'color' => ['rgb' => 'CBD5E1'],
            ],
        ],
    ]);
    $sheet->getStyle($labelRange)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '64748B'], 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle($valueRange)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => $color], 'size' => 18],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
}

$empresaSesion = (int)($_SESSION['empresa_id'] ?? 0);
$divisionSesion = (int)($_SESSION['division_id'] ?? 0);

if ($empresaSesion <= 0 || $divisionSesion <= 0) {
    dashboardFail('La sesion no contiene empresa o division activa.', 403);
}

$empresaNombre = '';
$stmtEmpresa = $conn->prepare('SELECT nombre FROM empresa WHERE id = ? LIMIT 1');
if (!$stmtEmpresa) {
    dashboardFail('No fue posible validar la empresa de la sesion.', 500);
}
$stmtEmpresa->bind_param('i', $empresaSesion);
$stmtEmpresa->execute();
$stmtEmpresa->bind_result($empresaNombre);
$stmtEmpresa->fetch();
$stmtEmpresa->close();

$esMenteCreativa = mb_strtolower(trim($empresaNombre), 'UTF-8') === 'mentecreativa';

$divisionGet = filter_input(INPUT_GET, 'division', FILTER_VALIDATE_INT);
$divisionFiltro = $divisionGet === false || $divisionGet === null ? 0 : max(0, (int)$divisionGet);

if (!$esMenteCreativa) {
    $divisionFiltro = $divisionSesion;
}

$estadoGet = filter_input(INPUT_GET, 'estado', FILTER_VALIDATE_INT);
$estadoFiltro = $estadoGet === false || $estadoGet === null ? 1 : (int)$estadoGet;
if (!in_array($estadoFiltro, [1, 2, 3], true)) {
    dashboardFail('El estado debe ser 1 (en curso), 2 (en proceso) o 3 (finalizado).');
}

$condiciones = [
    'f.tipo IN (1, 3)',
    'f.estado = ' . $estadoFiltro,
];

if ($divisionFiltro > 0) {
    $condiciones[] = 'f.id_division = ' . $divisionFiltro;
}

if (!$esMenteCreativa) {
    $condiciones[] = 'f.id_empresa = ' . $empresaSesion;
}

$whereSql = implode("\n AND ", $condiciones);

$divisionNombre = $divisionFiltro > 0 ? ('DIVISION ' . $divisionFiltro) : 'TODAS LAS DIVISIONES';
if ($divisionFiltro > 0) {
    $stmtDivision = $conn->prepare('SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1');
    if ($stmtDivision) {
        $stmtDivision->bind_param('i', $divisionFiltro);
        $stmtDivision->execute();
        $nombreEncontrado = '';
        $stmtDivision->bind_result($nombreEncontrado);
        if ($stmtDivision->fetch() && trim($nombreEncontrado) !== '') {
            $divisionNombre = mb_strtoupper(trim($nombreEncontrado), 'UTF-8');
        }
        $stmtDivision->close();
    }
}

$sqlResumen = "
    SELECT
        COUNT(DISTINCT CASE WHEN f.tipo = 1 THEN f.id END) AS total_campanas,
        COUNT(DISTINCT CASE WHEN f.tipo = 3 THEN f.id END) AS total_rutas,
        COUNT(DISTINCT CONCAT(f.id, ':', fq.id_local)) AS locales_programados,
        COUNT(DISTINCT CASE
            WHEN fq.fechaVisita IS NOT NULL
             AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
            THEN CONCAT(f.id, ':', fq.id_local)
        END) AS locales_visitados,
        COUNT(DISTINCT CASE
            WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
              OR (f.modalidad = 'implementacion_por_etapas' AND fq.etapa_material IN ('implementado','retirado'))
            THEN CONCAT(f.id, ':', fq.id_local)
        END) AS locales_ejecutados
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
";

$resultadoResumen = $conn->query($sqlResumen);
if (!$resultadoResumen) {
    dashboardFail('Error consultando el resumen: ' . $conn->error, 500);
}

$resumen = $resultadoResumen->fetch_assoc() ?: [];
$resultadoResumen->free();

$totalCampanas = (int)($resumen['total_campanas'] ?? 0);
$totalRutas = (int)($resumen['total_rutas'] ?? 0);
$localesProgramados = (int)($resumen['locales_programados'] ?? 0);
$localesVisitados = (int)($resumen['locales_visitados'] ?? 0);
$localesEjecutados = (int)($resumen['locales_ejecutados'] ?? 0);
$porcentajeVisita = $localesProgramados > 0 ? $localesVisitados / $localesProgramados : 0;
$porcentajeEjecucion = $localesProgramados > 0 ? $localesEjecutados / $localesProgramados : 0;

$materiales = [];
$sqlMateriales = "
    SELECT DISTINCT UPPER(TRIM(fq.material)) AS material
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
      AND fq.material IS NOT NULL
      AND TRIM(fq.material) <> ''
      AND TRIM(fq.material) <> '-'
    ORDER BY material ASC
";
$resultadoMateriales = $conn->query($sqlMateriales);
if (!$resultadoMateriales) {
    dashboardFail('Error consultando los materiales: ' . $conn->error, 500);
}
while ($material = $resultadoMateriales->fetch_assoc()) {
    $nombreMaterial = trim((string)($material['material'] ?? ''));
    if ($nombreMaterial !== '') {
        $materiales[] = $nombreMaterial;
    }
}
$resultadoMateriales->free();

$maxFotos = 0;
$sqlMaxFotos = "
    SELECT COALESCE(MAX(x.total_fotos), 0) AS max_fotos
    FROM (
        SELECT fq.id_formulario, fq.id_local,
               COUNT(DISTINCT CASE
                   WHEN fv.url IS NOT NULL AND TRIM(fv.url) <> '' THEN fv.url
               END) AS total_fotos
        FROM formulario f
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        LEFT JOIN fotoVisita fv
          ON fv.id_formulario = f.id
         AND fv.id_formularioQuestion = fq.id
        WHERE {$whereSql}
          AND (f.tipo <> 3 OR fq.id_usuario <> 50)
        GROUP BY fq.id_formulario, fq.id_local
    ) x
";
$resultadoMaxFotos = $conn->query($sqlMaxFotos);
if (!$resultadoMaxFotos) {
    dashboardFail('Error consultando la cantidad de fotos: ' . $conn->error, 500);
}
$filaMaxFotos = $resultadoMaxFotos->fetch_assoc() ?: [];
$maxFotos = (int)($filaMaxFotos['max_fotos'] ?? 0);
$resultadoMaxFotos->free();

$sqlDetalle = "
    SELECT
        f.tipo,
        f.id AS id_campana,
        UPPER(f.nombre) AS nombre_campana,
        f.modalidad,
        l.id AS id_local,
        l.codigo AS codigo_local,
        SUBSTRING_INDEX(TRIM(l.nombre), ' ', 1) AS numero_local,
        UPPER(l.nombre) AS nombre_local,
        UPPER(COALESCE(cu.nombre, '')) AS cuenta,
        CASE
            WHEN fq.fechaVisita IS NULL OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(fq.fechaVisita)
        END AS fecha_visita,
        fq.id AS id_formulario_question,
        fq.pregunta,
        fq.etapa_material,
        UPPER(TRIM(COALESCE(fq.material, ''))) AS material,
        COALESCE(fq.valor, 0) AS valor,
        UPPER(COALESCE(fq.observacion, '')) AS observacion,
        fv.id AS id_foto,
        fv.url AS foto_url
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN cuenta cu ON cu.id = l.id_cuenta
    LEFT JOIN fotoVisita fv
      ON fv.id_formulario = f.id
     AND fv.id_formularioQuestion = fq.id
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
    ORDER BY f.tipo ASC, f.fechaInicio DESC, f.nombre ASC, f.id ASC, l.codigo ASC, l.id ASC, fq.id ASC, fv.id ASC
";

$resultadoDetalle = $conn->query($sqlDetalle, MYSQLI_USE_RESULT);
if (!$resultadoDetalle) {
    dashboardFail('Error consultando el detalle: ' . $conn->error, 500);
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Visibility')
    ->setLastModifiedBy('Visibility')
    ->setTitle('Dashboard de campanas y rutas')
    ->setSubject('Campanas y rutas filtradas')
    ->setDescription('Dashboard generado desde el detalle de formularioQuestion');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Dashboard');
$sheet->setShowGridlines(false);
$sheet->getSheetView()->setZoomScale(85);

$headers = ['CAMPAÑA', 'N LOCAL', 'SALAS', 'SUB CADENA', 'ESTADO', 'EJECUTADO', 'MOTIVO'];
foreach ($materiales as $material) {
    $headers[] = $material;
}
for ($numeroFoto = 1; $numeroFoto <= $maxFotos; $numeroFoto++) {
    $headers[] = 'FOTO ' . $numeroFoto;
}

$tableLastColumn = Coordinate::stringFromColumnIndex(count($headers));
$lastColumn = Coordinate::stringFromColumnIndex(max(17, count($headers)));
$sheet->mergeCells("A1:{$lastColumn}2");
$sheet->setCellValue('A1', 'DASHBOARD DE CAMPAÑAS Y RUTAS');
$sheet->getStyle("A1:{$lastColumn}2")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => '7030A0'], 'size' => 20],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$sheet->mergeCells("A3:{$lastColumn}3");
$sheet->setCellValue(
    'A3',
    'DIVISION: ' . $divisionNombre . '  |  ESTADO: ' . dashboardEstadoFormulario($estadoFiltro) .
    '  |  ACTUALIZADO: ' . date('d-m-Y H:i')
);
$sheet->getStyle("A3:{$lastColumn}3")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7030A0']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(3)->setRowHeight(24);

$tarjetas = [
    ['A5:B7', 'A5:B5', 'A6:B7', 'CAMPAÑAS', $totalCampanas, '7030A0', '#,##0'],
    ['D5:E7', 'D5:E5', 'D6:E7', 'RUTAS', $totalRutas, '2563EB', '#,##0'],
    ['G5:H7', 'G5:H5', 'G6:H7', 'LOCALES PROGRAMADOS', $localesProgramados, '0F172A', '#,##0'],
    ['J5:K7', 'J5:K5', 'J6:K7', 'LOCALES VISITADOS', $localesVisitados, '0EA5E9', '#,##0'],
    ['M5:N7', 'M5:N5', 'M6:N7', 'LOCALES EJECUTADOS', $localesEjecutados, '16A34A', '#,##0'],
    ['P5:Q7', 'P5:Q5', 'P6:Q7', '% EJECUCIÓN', $porcentajeEjecucion, 'DC2626', '0%'],
];

foreach ($tarjetas as [$range, $labelRange, $valueRange, $label, $value, $color, $format]) {
    dashboardAplicarTarjeta($sheet, $range, $labelRange, $valueRange, $color);
    $sheet->setCellValue(explode(':', $labelRange)[0], $label);
    $sheet->setCellValue(explode(':', $valueRange)[0], $value);
    $sheet->getStyle($valueRange)->getNumberFormat()->setFormatCode($format);
}

$sheet->mergeCells("A9:{$lastColumn}9");
$sheet->setCellValue(
    'A9',
    'VISITA: ' . number_format($porcentajeVisita * 100, 0, ',', '.') .
    '%  |  EJECUCIÓN: ' . number_format($porcentajeEjecucion * 100, 0, ',', '.') . '%'
);
$sheet->getStyle("A9:{$lastColumn}9")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '111827']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$headerRow = 11;
$column = 1;
foreach ($headers as $header) {
    dashboardTextoPlano($sheet, $column++, $headerRow, $header);
}
dashboardAplicarCabecera($sheet, "A{$headerRow}:{$tableLastColumn}{$headerRow}");
$sheet->getRowDimension($headerRow)->setRowHeight(30);

$rowNumber = $headerRow + 1;
$materialIndex = array_flip($materiales);

$escribirGrupo = static function (?array $grupo) use (
    $sheet,
    &$rowNumber,
    $materiales,
    $maxFotos
): void {
    if ($grupo === null) {
        return;
    }

    $valoresBase = [
        $grupo['nombre_campana'],
        $grupo['numero_local'],
        $grupo['nombre_local'],
        $grupo['cuenta'],
        $grupo['visitado'] ? 'VISITADO' : 'NO VISITADO',
        dashboardEjecucionPrincipal($grupo['ejecuciones']),
        implode(' | ', array_keys($grupo['motivos'])),
    ];

    $columna = 1;
    foreach ($valoresBase as $valor) {
        dashboardTextoPlano($sheet, $columna++, $rowNumber, (string)$valor);
    }

    foreach ($materiales as $material) {
        $celda = Coordinate::stringFromColumnIndex($columna++) . $rowNumber;
        $sheet->setCellValue($celda, (float)($grupo['materiales'][$material] ?? 0));
    }

    for ($indiceFoto = 0; $indiceFoto < $maxFotos; $indiceFoto++) {
        $url = (string)($grupo['fotos'][$indiceFoto] ?? '');
        $celda = Coordinate::stringFromColumnIndex($columna++) . $rowNumber;
        $sheet->setCellValueExplicit($celda, $url, DataType::TYPE_STRING);
        if ($url !== '') {
            $sheet->getCell($celda)->getHyperlink()->setUrl($url);
            $sheet->getStyle($celda)->getFont()->getColor()->setRGB('0563C1');
            $sheet->getStyle($celda)->getFont()->setUnderline(true);
        }
    }

    $rowNumber++;
};

$grupoActual = null;
$claveActual = '';
while ($row = $resultadoDetalle->fetch_assoc()) {
    $clave = (string)($row['id_campana'] ?? '') . ':' . (string)($row['id_local'] ?? '');
    if ($clave !== $claveActual) {
        $escribirGrupo($grupoActual);

        $numeroLocal = trim((string)($row['numero_local'] ?? ''));
        if ($numeroLocal === '') {
            $numeroLocal = (string)($row['codigo_local'] ?? '');
        }

        $grupoActual = [
            'nombre_campana' => (string)($row['nombre_campana'] ?? ''),
            'numero_local' => $numeroLocal,
            'nombre_local' => (string)($row['nombre_local'] ?? ''),
            'cuenta' => (string)($row['cuenta'] ?? ''),
            'visitado' => false,
            'ejecuciones' => [],
            'motivos' => [],
            'materiales' => [],
            'fotos' => [],
            'fotos_vistas' => [],
            'fq_vistos' => [],
        ];
        $claveActual = $clave;
    }

    $fechaVisita = dashboardFechaValida($row['fecha_visita'] ?? '');
    if ($fechaVisita !== '') {
        $grupoActual['visitado'] = true;
    }

    $ejecucion = dashboardEjecucion($row);
    if ($ejecucion !== '') {
        $grupoActual['ejecuciones'][$ejecucion] = true;
    }

    $motivo = dashboardMotivo($row);
    if ($motivo !== '') {
        $grupoActual['motivos'][$motivo] = true;
    }

    $fqId = (int)($row['id_formulario_question'] ?? 0);
    if ($fqId > 0 && !isset($grupoActual['fq_vistos'][$fqId])) {
        $material = trim((string)($row['material'] ?? ''));
        if ($material !== '' && $material !== '-' && isset($materialIndex[$material])) {
            $grupoActual['materiales'][$material] =
                (float)($grupoActual['materiales'][$material] ?? 0) + (float)($row['valor'] ?? 0);
        }
        $grupoActual['fq_vistos'][$fqId] = true;
    }

    $foto = dashboardNormalizarFoto((string)($row['foto_url'] ?? ''));
    if ($foto !== '' && !isset($grupoActual['fotos_vistas'][$foto])) {
        $grupoActual['fotos'][] = $foto;
        $grupoActual['fotos_vistas'][$foto] = true;
    }
}
$escribirGrupo($grupoActual);
$resultadoDetalle->free();

$lastDataRow = max($headerRow, $rowNumber - 1);
$sheet->setAutoFilter("A{$headerRow}:{$tableLastColumn}{$lastDataRow}");
$sheet->freezePane('A12');

if ($lastDataRow >= 12) {
    $sheet->getStyle("A12:{$tableLastColumn}{$lastDataRow}")->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => '1F2937']],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => false],
        'borders' => [
            'bottom' => [
                'borderStyle' => 'hair',
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ]);

    if (count($materiales) > 0) {
        $primeraColumnaMaterial = Coordinate::stringFromColumnIndex(8);
        $ultimaColumnaMaterial = Coordinate::stringFromColumnIndex(7 + count($materiales));
        $sheet->getStyle("{$primeraColumnaMaterial}12:{$ultimaColumnaMaterial}{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.##');
    }
}

$widths = [
    1 => 42, 2 => 14, 3 => 48, 4 => 20, 5 => 18, 6 => 22, 7 => 32,
];
for ($indiceMaterial = 0; $indiceMaterial < count($materiales); $indiceMaterial++) {
    $widths[8 + $indiceMaterial] = 22;
}
for ($indiceFoto = 0; $indiceFoto < $maxFotos; $indiceFoto++) {
    $widths[8 + count($materiales) + $indiceFoto] = 40;
}
foreach ($widths as $columnIndex => $width) {
    $sheet->getColumnDimensionByColumn($columnIndex)->setWidth($width);
}

$sheet->getPageSetup()
    ->setOrientation('landscape')
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sheet->getPageMargins()
    ->setTop(0.4)
    ->setRight(0.3)
    ->setLeft(0.3)
    ->setBottom(0.4);
$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

$spreadsheet->setActiveSheetIndex(0);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$downloadToken = trim((string)($_GET['download_token'] ?? ''));
if ($downloadToken !== '') {
    setcookie('fileDownloadToken', $downloadToken, 0, '/');
}

$estadoArchivo = match ($estadoFiltro) {
    2 => 'En_Proceso',
    3 => 'Finalizadas',
    default => 'En_Curso',
};

$nombreArchivo = sprintf(
    'Dashboard_Campanas_Rutas_%s_%s_%s.xlsx',
    preg_replace('/[^A-Z0-9_-]+/i', '_', $divisionNombre),
    $estadoArchivo,
    date('Y-m-d_His')
);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($writer, $spreadsheet);
exit;

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
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
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
            'name' => 'Arial',
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 10,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '4472C4'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'bottom' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'D9E2F3'],
            ],
        ],
    ]);
}

function dashboardAplicarTituloReporte(
    Worksheet $sheet,
    string $lastColumn,
    string $titulo,
    string $subtitulo
): void {
    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', $titulo);
    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 15, 'name' => 'Arial'],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(25);

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', $subtitulo);
    $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
        'font' => ['italic' => true, 'color' => ['rgb' => '244062'], 'size' => 10, 'name' => 'Arial'],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(18);
    $sheet->getRowDimension(3)->setRowHeight(12);
}

function dashboardEscribirFecha(Worksheet $sheet, string $cell, $value): void
{
    $fecha = dashboardFechaValida($value);
    if ($fecha === '') {
        $sheet->setCellValue($cell, '');
        return;
    }

    try {
        $sheet->setCellValue($cell, Date::PHPToExcel(new DateTimeImmutable(substr($fecha, 0, 10))));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    } catch (Throwable $error) {
        $sheet->setCellValueExplicit($cell, substr($fecha, 0, 10), DataType::TYPE_STRING);
    }
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
                'color' => ['rgb' => '0070C0'],
            ],
        ],
    ]);
    $sheet->getStyle($labelRange)->applyFromArray([
        'font' => ['name' => 'Arial', 'bold' => true, 'color' => ['rgb' => '0F172A'], 'size' => 9],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle($valueRange)->applyFromArray([
        'font' => ['name' => 'Arial', 'bold' => true, 'color' => ['rgb' => $color], 'size' => 18],
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

$idsRaw = trim((string)($_GET['ids'] ?? ''));
$idsSeleccionados = [];
if ($idsRaw !== '') {
    foreach (preg_split('/\s*,\s*/', $idsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $idRaw) {
        if (ctype_digit($idRaw) && (int)$idRaw > 0) {
            $idsSeleccionados[(int)$idRaw] = (int)$idRaw;
        }
    }
    $idsSeleccionados = array_values($idsSeleccionados);
    if ($idsSeleccionados === []) {
        dashboardFail('Selecciona al menos una campaña o ruta válida.');
    }
    if (count($idsSeleccionados) > 200) {
        dashboardFail('La selección supera el máximo permitido de 200 campañas o rutas.');
    }
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

if ($idsSeleccionados !== []) {
    $condiciones[] = 'f.id IN (' . implode(',', $idsSeleccionados) . ')';
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

$formulariosSeleccionados = [];
$sqlSeleccion = "
    SELECT f.id, f.tipo, UPPER(TRIM(f.nombre)) AS nombre
    FROM formulario f
    WHERE {$whereSql}
    ORDER BY f.tipo ASC, f.nombre ASC
";
$resultadoSeleccion = $conn->query($sqlSeleccion);
if (!$resultadoSeleccion) {
    dashboardFail('Error validando las campañas y rutas seleccionadas: ' . $conn->error, 500);
}
while ($seleccion = $resultadoSeleccion->fetch_assoc()) {
    $formulariosSeleccionados[] = $seleccion;
}
$resultadoSeleccion->free();

if ($idsSeleccionados !== [] && count($formulariosSeleccionados) !== count($idsSeleccionados)) {
    dashboardFail('Una o más campañas o rutas no pertenecen al alcance filtrado.', 403);
}

$nombresSeleccionados = array_column($formulariosSeleccionados, 'nombre');
$seleccionResumen = count($nombresSeleccionados) === 1
    ? (string)$nombresSeleccionados[0]
    : count($nombresSeleccionados) . ' CAMPAÑAS / RUTAS SELECCIONADAS';

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
            WHEN COALESCE(fq.valor, 0) >= 1
              OR fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
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
$porcentajeEfectividad = $localesVisitados > 0 ? $localesEjecutados / $localesVisitados : 0;

$sqlResumenEjecutivo = "
    SELECT
        f.id,
        f.tipo,
        UPPER(TRIM(f.nombre)) AS campana,
        UPPER(COALESCE(NULLIF(TRIM(t.nombre), ''), 'SIN TRADE')) AS trade,
        CASE
            WHEN f.fechaInicio IS NULL OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaInicio)
        END AS fecha_inicio,
        CASE
            WHEN f.fechaTermino IS NULL OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaTermino)
        END AS fecha_termino,
        MAX(CASE
            WHEN fq.fechaVisita IS NULL OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(fq.fechaVisita)
        END) AS ultima_fecha_visita,
        COUNT(DISTINCT fq.id_local) AS locales_programados,
        COUNT(DISTINCT CASE
            WHEN fq.fechaVisita IS NOT NULL
             AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
            THEN fq.id_local
        END) AS locales_visitados
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    LEFT JOIN trade t ON t.id = f.id_trade
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
    GROUP BY f.id, f.tipo, f.nombre, t.nombre, f.fechaInicio, f.fechaTermino
    ORDER BY f.tipo ASC, f.fechaInicio DESC, f.nombre ASC
";
$resultadoResumenEjecutivo = $conn->query($sqlResumenEjecutivo);
if (!$resultadoResumenEjecutivo) {
    dashboardFail('Error consultando el resumen ejecutivo: ' . $conn->error, 500);
}
$filasResumenEjecutivo = [];
while ($filaResumen = $resultadoResumenEjecutivo->fetch_assoc()) {
    $filasResumenEjecutivo[] = $filaResumen;
}
$resultadoResumenEjecutivo->free();

$sqlAvanceRegional = "
    SELECT
        f.id AS id_formulario,
        f.tipo,
        UPPER(TRIM(f.nombre)) AS campana,
        UPPER(COALESCE(NULLIF(TRIM(t.nombre), ''), 'SIN TRADE')) AS trade,
        r.id AS id_region,
        UPPER(COALESCE(NULLIF(TRIM(r.region), ''), 'SIN REGION')) AS region,
        CASE
            WHEN f.fechaInicio IS NULL OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaInicio)
        END AS fecha_inicio,
        CASE
            WHEN f.fechaTermino IS NULL OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaTermino)
        END AS fecha_termino,
        MAX(CASE
            WHEN fq.fechaVisita IS NULL OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(fq.fechaVisita)
        END) AS ultima_fecha_visita,
        COUNT(DISTINCT fq.id_local) AS locales_programados,
        COUNT(DISTINCT CASE
            WHEN fq.fechaVisita IS NOT NULL
             AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
            THEN fq.id_local
        END) AS locales_visitados
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN comuna cm ON cm.id = l.id_comuna
    LEFT JOIN region r ON r.id = cm.id_region
    LEFT JOIN trade t ON t.id = f.id_trade
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
    GROUP BY f.id, f.tipo, f.nombre, t.nombre, r.id, r.region, f.fechaInicio, f.fechaTermino
    ORDER BY f.tipo ASC, f.nombre ASC, r.region ASC
";
$resultadoAvanceRegional = $conn->query($sqlAvanceRegional);
if (!$resultadoAvanceRegional) {
    dashboardFail('Error consultando el avance detallado: ' . $conn->error, 500);
}
$filasAvanceRegional = [];
while ($filaAvance = $resultadoAvanceRegional->fetch_assoc()) {
    $filasAvanceRegional[] = $filaAvance;
}
$resultadoAvanceRegional->free();

$sqlAvanceDiario = "
    SELECT
        f.id AS id_formulario,
        COALESCE(r.id, 0) AS id_region,
        DATE(fq.fechaVisita) AS fecha_visita,
        COUNT(DISTINCT fq.id_local) AS locales_visitados
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN comuna cm ON cm.id = l.id_comuna
    LEFT JOIN region r ON r.id = cm.id_region
    WHERE {$whereSql}
      AND (f.tipo <> 3 OR fq.id_usuario <> 50)
      AND fq.fechaVisita IS NOT NULL
      AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
    GROUP BY f.id, COALESCE(r.id, 0), DATE(fq.fechaVisita)
    ORDER BY DATE(fq.fechaVisita) ASC
";
$resultadoAvanceDiario = $conn->query($sqlAvanceDiario);
if (!$resultadoAvanceDiario) {
    dashboardFail('Error consultando el avance diario: ' . $conn->error, 500);
}
$fechasVisita = [];
$avanceDiario = [];
while ($filaDiaria = $resultadoAvanceDiario->fetch_assoc()) {
    $fechaDiaria = dashboardFechaValida($filaDiaria['fecha_visita'] ?? '');
    if ($fechaDiaria === '') {
        continue;
    }
    $fechaDiaria = substr($fechaDiaria, 0, 10);
    $claveRegional = (int)$filaDiaria['id_formulario'] . ':' . (int)$filaDiaria['id_region'];
    $fechasVisita[$fechaDiaria] = $fechaDiaria;
    $avanceDiario[$claveRegional][$fechaDiaria] = (int)$filaDiaria['locales_visitados'];
}
$resultadoAvanceDiario->free();
ksort($fechasVisita);
$fechasVisita = array_values($fechasVisita);

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
        UPPER(l.nombre) AS nombre_local,
        UPPER(COALESCE(cu.nombre, '')) AS cuenta,
        UPPER(COALESCE(cm.comuna, '')) AS comuna,
        CASE
            WHEN fq.fechaVisita IS NULL OR CAST(fq.fechaVisita AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(fq.fechaVisita)
        END AS fecha_visita,
        fq.id AS id_formulario_question,
        fq.pregunta,
        fq.etapa_material,
        UPPER(COALESCE(
            NULLIF(TRIM(fq.material), ''),
            NULLIF(TRIM(fq.categoria), ''),
            'GESTIÓN'
        )) AS material,
        COALESCE(fq.valor, 0) AS valor,
        COALESCE(fq.valor_propuesto, 0) AS valor_propuesto,
        UPPER(COALESCE(fq.observacion, '')) AS observacion,
        fv.id AS id_foto,
        fv.url AS foto_url
    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    LEFT JOIN cuenta cu ON cu.id = l.id_cuenta
    LEFT JOIN comuna cm ON cm.id = l.id_comuna
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
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
$spreadsheet->getProperties()
    ->setCreator('Visibility')
    ->setLastModifiedBy('Visibility')
    ->setTitle('Dashboard de campanas y rutas')
    ->setSubject('Campanas y rutas filtradas')
    ->setDescription('Dashboard generado desde el detalle de formularioQuestion');

$sheetResumen = $spreadsheet->getActiveSheet();
$sheetResumen->setTitle('Resumen ejecutivo');
$sheetResumen->setShowGridlines(false);
$sheetResumen->getSheetView()->setZoomScale(90);
$sheetResumen->getTabColor()->setRGB('1F4E78');
dashboardAplicarTituloReporte(
    $sheetResumen,
    'H',
    'Resumen Ejecutivo de Campañas y Rutas',
    'Consolidado de programación y visitas. Generado el ' . date('Y-m-d H:i') .
    ' | División: ' . $divisionNombre
);

$headersResumen = [
    'CAMPAÑA / RUTA', 'PRIORIDAD', 'FECHA INICIO', 'FECHA TERMINO',
    'ULTIMA FECHA VISITA', 'LOCALES PROGRAMADOS', 'LOCALES VISITADOS', 'AVANCE VISITA',
];
$headerResumenRow = 4;
foreach ($headersResumen as $indice => $header) {
    dashboardTextoPlano($sheetResumen, $indice + 1, $headerResumenRow, $header);
}
dashboardAplicarCabecera($sheetResumen, "A{$headerResumenRow}:H{$headerResumenRow}");
$sheetResumen->getRowDimension($headerResumenRow)->setRowHeight(42);

$filaResumenExcel = $headerResumenRow + 1;
foreach ($filasResumenEjecutivo as $filaResumen) {
    $tipoResumen = (int)($filaResumen['tipo'] ?? 0) === 3 ? 'RUTA' : 'CAMPAÑA';
    dashboardTextoPlano(
        $sheetResumen,
        1,
        $filaResumenExcel,
        $tipoResumen . ' · ' . (string)($filaResumen['campana'] ?? '')
    );
    dashboardTextoPlano($sheetResumen, 2, $filaResumenExcel, 'MEDIA');
    dashboardEscribirFecha($sheetResumen, 'C' . $filaResumenExcel, $filaResumen['fecha_inicio'] ?? '');
    dashboardEscribirFecha($sheetResumen, 'D' . $filaResumenExcel, $filaResumen['fecha_termino'] ?? '');
    dashboardEscribirFecha($sheetResumen, 'E' . $filaResumenExcel, $filaResumen['ultima_fecha_visita'] ?? '');
    $programadosResumen = (int)($filaResumen['locales_programados'] ?? 0);
    $visitadosResumen = (int)($filaResumen['locales_visitados'] ?? 0);
    $sheetResumen->setCellValue('F' . $filaResumenExcel, $programadosResumen);
    $sheetResumen->setCellValue('G' . $filaResumenExcel, $visitadosResumen);
    $sheetResumen->setCellValue(
        'H' . $filaResumenExcel,
        $programadosResumen > 0 ? $visitadosResumen / $programadosResumen : 0
    );
    $filaResumenExcel++;
}
$ultimaFilaResumen = max($headerResumenRow, $filaResumenExcel - 1);
$sheetResumen->setAutoFilter("A{$headerResumenRow}:H{$ultimaFilaResumen}");
$sheetResumen->freezePane('A' . ($headerResumenRow + 1));
if ($ultimaFilaResumen > $headerResumenRow) {
    $sheetResumen->getStyle('A' . ($headerResumenRow + 1) . ':H' . $ultimaFilaResumen)->applyFromArray([
        'font' => ['name' => 'Arial', 'size' => 9],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_DOTTED, 'color' => ['rgb' => 'D9D9D9']]],
    ]);
    $sheetResumen->getStyle('F' . ($headerResumenRow + 1) . ':G' . $ultimaFilaResumen)
        ->getNumberFormat()->setFormatCode('#,##0');
    $sheetResumen->getStyle('H' . ($headerResumenRow + 1) . ':H' . $ultimaFilaResumen)
        ->getNumberFormat()->setFormatCode('0.0%');
}
$anchosResumen = [1 => 68, 2 => 14, 3 => 16, 4 => 16, 5 => 19, 6 => 20, 7 => 18, 8 => 16];
foreach ($anchosResumen as $columnaResumen => $anchoResumen) {
    $sheetResumen->getColumnDimensionByColumn($columnaResumen)->setWidth($anchoResumen);
}
$sheetResumen->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
$sheetResumen->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerResumenRow, $headerResumenRow);

$sheetAvance = $spreadsheet->createSheet();
$sheetAvance->setTitle('Avance detallado');
$sheetAvance->setShowGridlines(false);
$sheetAvance->getSheetView()->setZoomScale(80);
$sheetAvance->getTabColor()->setRGB('4472C4');
$columnasFijasAvance = 10;
$totalColumnasAvance = $columnasFijasAvance + count($fechasVisita);
$ultimaColumnaAvance = Coordinate::stringFromColumnIndex(max($columnasFijasAvance, $totalColumnasAvance));
dashboardAplicarTituloReporte(
    $sheetAvance,
    $ultimaColumnaAvance,
    'Avance Detallado por Campaña y Región',
    'Cada celda verde representa locales únicos visitados en esa fecha. División: ' . $divisionNombre
);

$headersAvance = [
    'TRADE', 'CAMPAÑA / RUTA', 'PRIORIDAD', 'REGION', 'FECHA INICIO', 'FECHA TERMINO',
    'ULTIMA FECHA VISITA', 'LOCALES PROGRAMADOS', 'LOCALES VISITADOS', 'AVANCE VISITA',
];
foreach ($fechasVisita as $fechaVisitaHeader) {
    $headersAvance[] = $fechaVisitaHeader;
}
$headerAvanceRow = 4;
foreach ($headersAvance as $indice => $header) {
    dashboardTextoPlano($sheetAvance, $indice + 1, $headerAvanceRow, $header);
}
dashboardAplicarCabecera(
    $sheetAvance,
    'A' . $headerAvanceRow . ':' . $ultimaColumnaAvance . $headerAvanceRow
);
$sheetAvance->getRowDimension($headerAvanceRow)->setRowHeight(78);
if ($totalColumnasAvance > $columnasFijasAvance) {
    $inicioFechaColumn = Coordinate::stringFromColumnIndex($columnasFijasAvance + 1);
    $sheetAvance->getStyle(
        $inicioFechaColumn . $headerAvanceRow . ':' . $ultimaColumnaAvance . $headerAvanceRow
    )->getAlignment()->setTextRotation(90);
}

$filaAvanceExcel = $headerAvanceRow + 1;
foreach ($filasAvanceRegional as $filaAvance) {
    $idRegion = (int)($filaAvance['id_region'] ?? 0);
    $claveRegional = (int)($filaAvance['id_formulario'] ?? 0) . ':' . $idRegion;
    $tipoAvance = (int)($filaAvance['tipo'] ?? 0) === 3 ? 'RUTA' : 'CAMPAÑA';
    dashboardTextoPlano($sheetAvance, 1, $filaAvanceExcel, (string)($filaAvance['trade'] ?? 'SIN TRADE'));
    dashboardTextoPlano(
        $sheetAvance,
        2,
        $filaAvanceExcel,
        $tipoAvance . ' · ' . (string)($filaAvance['campana'] ?? '')
    );
    dashboardTextoPlano($sheetAvance, 3, $filaAvanceExcel, 'MEDIA');
    $regionLabel = $idRegion > 0
        ? str_pad((string)$idRegion, 2, '0', STR_PAD_LEFT) . ' - ' . (string)($filaAvance['region'] ?? '')
        : (string)($filaAvance['region'] ?? 'SIN REGION');
    dashboardTextoPlano($sheetAvance, 4, $filaAvanceExcel, $regionLabel);
    dashboardEscribirFecha($sheetAvance, 'E' . $filaAvanceExcel, $filaAvance['fecha_inicio'] ?? '');
    dashboardEscribirFecha($sheetAvance, 'F' . $filaAvanceExcel, $filaAvance['fecha_termino'] ?? '');
    dashboardEscribirFecha($sheetAvance, 'G' . $filaAvanceExcel, $filaAvance['ultima_fecha_visita'] ?? '');
    $programadosRegion = (int)($filaAvance['locales_programados'] ?? 0);
    $visitadosRegion = (int)($filaAvance['locales_visitados'] ?? 0);
    $sheetAvance->setCellValue('H' . $filaAvanceExcel, $programadosRegion);
    $sheetAvance->setCellValue('I' . $filaAvanceExcel, $visitadosRegion);
    $sheetAvance->setCellValue('J' . $filaAvanceExcel, $programadosRegion > 0 ? $visitadosRegion / $programadosRegion : 0);

    foreach ($fechasVisita as $indiceFecha => $fechaVisita) {
        $columnaFecha = Coordinate::stringFromColumnIndex($columnasFijasAvance + $indiceFecha + 1);
        $valorDiario = (int)($avanceDiario[$claveRegional][$fechaVisita] ?? 0);
        if ($valorDiario > 0) {
            $sheetAvance->setCellValue($columnaFecha . $filaAvanceExcel, $valorDiario);
            $sheetAvance->getStyle($columnaFecha . $filaAvanceExcel)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '92D050']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
    }
    $filaAvanceExcel++;
}
$ultimaFilaAvance = max($headerAvanceRow, $filaAvanceExcel - 1);
$sheetAvance->setAutoFilter('A' . $headerAvanceRow . ':' . $ultimaColumnaAvance . $ultimaFilaAvance);
$sheetAvance->freezePane('E' . ($headerAvanceRow + 1));
if ($ultimaFilaAvance > $headerAvanceRow) {
    $sheetAvance->getStyle('A' . ($headerAvanceRow + 1) . ':' . $ultimaColumnaAvance . $ultimaFilaAvance)
        ->getFont()->setName('Arial')->setSize(9);
    $sheetAvance->getStyle('A' . ($headerAvanceRow + 1) . ':' . $ultimaColumnaAvance . $ultimaFilaAvance)
        ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheetAvance->getStyle('A' . ($headerAvanceRow + 1) . ':' . $ultimaColumnaAvance . $ultimaFilaAvance)
        ->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOTTED)->getColor()->setRGB('D9D9D9');
    $sheetAvance->getStyle('H' . ($headerAvanceRow + 1) . ':I' . $ultimaFilaAvance)
        ->getNumberFormat()->setFormatCode('#,##0');
    $sheetAvance->getStyle('J' . ($headerAvanceRow + 1) . ':J' . $ultimaFilaAvance)
        ->getNumberFormat()->setFormatCode('0.0%');
}
$anchosAvance = [1 => 18, 2 => 68, 3 => 14, 4 => 30, 5 => 16, 6 => 16, 7 => 19, 8 => 19, 9 => 18, 10 => 16];
foreach ($anchosAvance as $columnaAvance => $anchoAvance) {
    $sheetAvance->getColumnDimensionByColumn($columnaAvance)->setWidth($anchoAvance);
}
for ($indiceFecha = 0; $indiceFecha < count($fechasVisita); $indiceFecha++) {
    $sheetAvance->getColumnDimensionByColumn($columnasFijasAvance + $indiceFecha + 1)->setWidth(6);
}
$sheetAvance->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
$sheetAvance->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerAvanceRow, $headerAvanceRow);

$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Resumen por local');
$sheet->setShowGridlines(false);
$sheet->getSheetView()->setZoomScale(85);
$sheet->getTabColor()->setRGB('70AD47');

$headers = [
    'TIPO', 'CAMPAÑA / RUTA', 'CÓDIGO', 'SALA', 'COMUNA', 'CUENTA',
    'ESTADO VISITA', 'ESTADO ACTIVIDAD', 'MOTIVO', 'MATERIAL',
    'EJECUTADO', 'PROPUESTO', 'AVANCE',
];
for ($numeroFoto = 1; $numeroFoto <= $maxFotos; $numeroFoto++) {
    $headers[] = 'FOTO ' . $numeroFoto;
}

$tableLastColumn = Coordinate::stringFromColumnIndex(count($headers));
$lastColumn = Coordinate::stringFromColumnIndex(max(20, count($headers)));
dashboardAplicarTituloReporte(
    $sheet,
    $lastColumn,
    'Resumen por Local y Material',
    'Detalle de ejecución, propuesta, avance y evidencia fotográfica. División: ' . $divisionNombre .
    ' | ' . $seleccionResumen . ' | Estado: ' . dashboardEstadoFormulario($estadoFiltro)
);

$tarjetas = [
    ['A6:C8', 'A6:C6', 'A7:C8', 'SALAS PROGRAMADAS', $localesProgramados, '0F172A', '#,##0'],
    ['E6:G8', 'E6:G6', 'E7:G8', 'SALAS VISITADAS', $localesVisitados, '0F172A', '#,##0'],
    ['I6:K8', 'I6:K6', 'I7:K8', 'SALAS EJECUTADAS', $localesEjecutados, '0F172A', '#,##0'],
    ['M6:N8', 'M6:N6', 'M7:N8', '% RECORRIDO', $porcentajeVisita, '0F172A', '0%'],
    ['P6:Q8', 'P6:Q6', 'P7:Q8', '% EJECUTADO', $porcentajeEjecucion, '0F172A', '0%'],
    ['S6:T8', 'S6:T6', 'S7:T8', '% EFECTIVIDAD', $porcentajeEfectividad, '0F172A', '0%'],
];

foreach ($tarjetas as [$range, $labelRange, $valueRange, $label, $value, $color, $format]) {
    dashboardAplicarTarjeta($sheet, $range, $labelRange, $valueRange, $color);
    $sheet->setCellValue(explode(':', $labelRange)[0], $label);
    $sheet->setCellValue(explode(':', $valueRange)[0], $value);
    $sheet->getStyle($valueRange)->getNumberFormat()->setFormatCode($format);
}

$sheet->mergeCells("A10:{$lastColumn}10");
$sheet->setCellValue('A10', 'AVANCE DE IMPLEMENTACIÓN');
$sheet->getStyle("A10:{$lastColumn}10")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->getRowDimension(10)->setRowHeight(20);

$sheet->mergeCells("A11:{$lastColumn}11");
$sheet->setCellValue(
    'A11',
    'Fuente: Visibility | División: ' . $divisionNombre . ' | Actualizado: ' . date('d-m-Y H:i')
);
$sheet->getStyle("A11:{$lastColumn}11")->applyFromArray([
    'font' => ['italic' => true, 'color' => ['rgb' => '64748B'], 'size' => 9],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
]);

$headerRow = 13;
$column = 1;
foreach ($headers as $header) {
    dashboardTextoPlano($sheet, $column++, $headerRow, $header);
}
dashboardAplicarCabecera($sheet, "A{$headerRow}:{$tableLastColumn}{$headerRow}");
$sheet->getRowDimension($headerRow)->setRowHeight(30);

$rowNumber = $headerRow + 1;

$escribirGrupo = static function (?array $grupo) use (
    $sheet,
    &$rowNumber,
    $maxFotos
): void {
    if ($grupo === null) {
        return;
    }

    $valoresBase = [
        $grupo['tipo'],
        $grupo['nombre_campana'],
        $grupo['codigo_local'],
        $grupo['nombre_local'],
        $grupo['comuna'],
        $grupo['cuenta'],
        $grupo['visitado'] ? 'VISITADO' : 'NO VISITADO',
        $grupo['estado_actividad'],
        $grupo['motivo'],
        $grupo['material'],
    ];

    $columna = 1;
    foreach ($valoresBase as $valor) {
        dashboardTextoPlano($sheet, $columna++, $rowNumber, (string)$valor);
    }

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $rowNumber, $grupo['ejecutado']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columna++) . $rowNumber, $grupo['propuesto']);

    $avance = $grupo['propuesto'] > 0 ? $grupo['ejecutado'] / $grupo['propuesto'] : 0;
    $celdaAvance = Coordinate::stringFromColumnIndex($columna++) . $rowNumber;
    $sheet->setCellValue($celdaAvance, $avance);
    $sheet->getStyle($celdaAvance)->getNumberFormat()->setFormatCode('0%');

    $colorAvance = $avance >= 1 ? 'C6EFCE' : ($avance > 0 ? 'FFEB9C' : 'F1F5F9');
    $sheet->getStyle($celdaAvance)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB($colorAvance);
    $sheet->getStyle($celdaAvance)->getFont()->setBold(true);

    for ($indiceFoto = 0; $indiceFoto < $maxFotos; $indiceFoto++) {
        $url = (string)($grupo['fotos'][$indiceFoto] ?? '');
        $celda = Coordinate::stringFromColumnIndex($columna++) . $rowNumber;
        if ($url !== '') {
            $sheet->setCellValueExplicit($celda, 'Foto ' . ($indiceFoto + 1), DataType::TYPE_STRING);
            $sheet->getCell($celda)->getHyperlink()->setUrl($url);
            $sheet->getStyle($celda)->getFont()->getColor()->setRGB('0563C1');
            $sheet->getStyle($celda)->getFont()->setUnderline(true);
        } else {
            $sheet->setCellValueExplicit($celda, '', DataType::TYPE_STRING);
        }
    }

    $rowNumber++;
};

$grupoActual = null;
$claveActual = '';
while ($row = $resultadoDetalle->fetch_assoc()) {
    $clave = (string)($row['id_formulario_question'] ?? '');
    if ($clave !== $claveActual) {
        $escribirGrupo($grupoActual);

        $grupoActual = [
            'tipo' => (int)($row['tipo'] ?? 0) === 3 ? 'RUTA' : 'CAMPAÑA',
            'nombre_campana' => (string)($row['nombre_campana'] ?? ''),
            'codigo_local' => (string)($row['codigo_local'] ?? ''),
            'nombre_local' => (string)($row['nombre_local'] ?? ''),
            'comuna' => (string)($row['comuna'] ?? ''),
            'cuenta' => (string)($row['cuenta'] ?? ''),
            'visitado' => dashboardFechaValida($row['fecha_visita'] ?? '') !== '',
            'estado_actividad' => dashboardEjecucion($row),
            'motivo' => dashboardMotivo($row),
            'material' => trim((string)($row['material'] ?? '')),
            'ejecutado' => (float)($row['valor'] ?? 0),
            'propuesto' => (float)($row['valor_propuesto'] ?? 0),
            'fotos' => [],
            'fotos_vistas' => [],
        ];
        $claveActual = $clave;
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
$sheet->freezePane('A' . ($headerRow + 1));

if ($lastDataRow > $headerRow) {
    $firstDataRow = $headerRow + 1;
    $sheet->getStyle("A{$firstDataRow}:{$tableLastColumn}{$lastDataRow}")->applyFromArray([
        'font' => ['name' => 'Arial', 'size' => 9, 'color' => ['rgb' => '1F2937']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
        'borders' => [
            'bottom' => [
                'borderStyle' => 'hair',
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ]);

    $sheet->getStyle("K{$firstDataRow}:L{$lastDataRow}")
        ->getNumberFormat()->setFormatCode('#,##0.##');
    for ($fila = $firstDataRow; $fila <= $lastDataRow; $fila++) {
        $sheet->getRowDimension($fila)->setRowHeight(20);
    }
}

$widths = [
    1 => 13, 2 => 38, 3 => 14, 4 => 48, 5 => 20, 6 => 20, 7 => 18,
    8 => 22, 9 => 30, 10 => 28, 11 => 12, 12 => 12, 13 => 12,
];
for ($indiceFoto = 0; $indiceFoto < $maxFotos; $indiceFoto++) {
    $widths[14 + $indiceFoto] = 13;
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

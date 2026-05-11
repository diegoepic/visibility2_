<?php
declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(120);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/* ─────────────────────────────────────────────────────────────
 * Seguridad
 * ───────────────────────────────────────────────────────────── */
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
    http_response_code(403);
    exit('Acceso inválido.');
}

/* ─────────────────────────────────────────────────────────────
 * Entrada
 * ───────────────────────────────────────────────────────────── */
$start_date = trim($_POST['start_date'] ?? '');
$end_date   = trim($_POST['end_date']   ?? '');
$feriados   = array_values(array_filter(
    (array)($_POST['feriados'] ?? []),
    static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)
));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)   ||
    $start_date > $end_date) {
    exit('Rango de fechas inválido.');
}

/* Verificar acceso de la empresa al formulario 138 */
$stmtAcc = $conn->prepare("SELECT id FROM formulario WHERE id = 138 AND id_empresa = ? LIMIT 1");
$stmtAcc->bind_param('i', $empresa_id);
$stmtAcc->execute();
$stmtAcc->store_result();
if ($stmtAcc->num_rows === 0) {
    $stmtAcc->close();
    exit('No tienes acceso a este informe.');
}
$stmtAcc->close();

mysqli_set_charset($conn, 'utf8mb4');

/* ─────────────────────────────────────────────────────────────
 * Helpers de fecha
 * ───────────────────────────────────────────────────────────── */
function isoWeekKey(string $date): string
{
    $d  = new DateTime($date);
    return $d->format('o') . '-W' . $d->format('W');
}

function consecutiveBlocks(array $dates): array
{
    if (empty($dates)) return [];
    sort($dates);
    $blocks  = [[$dates[0]]];
    for ($i = 1, $n = count($dates); $i < $n; $i++) {
        $diff = (int)(new DateTime($dates[$i]))->diff(new DateTime($dates[$i - 1]))->days;
        if ($diff === 1) {
            $blocks[count($blocks) - 1][] = $dates[$i];
        } else {
            $blocks[] = [$dates[$i]];
        }
    }
    return $blocks;
}

/**
 * Calcula los días de subida esperados para un rango, excluyendo feriados.
 * Devuelve [['date'=>'YYYY-MM-DD','tipo'=>'inicio'|'termino'], ...] ordenados.
 */
function calcExpectedDays(string $start, string $end, array $holidays): array
{
    $byWeek   = [];
    $weekOrd  = [];
    $current  = new DateTime($start);
    $last     = new DateTime($end);

    while ($current <= $last) {
        $dow  = (int)$current->format('N'); // 1=Lun…7=Dom
        if ($dow <= 5) {
            $date = $current->format('Y-m-d');
            if (!in_array($date, $holidays, true)) {
                $key = isoWeekKey($date);
                if (!isset($byWeek[$key])) {
                    $byWeek[$key] = [];
                    $weekOrd[]    = $key;
                }
                $byWeek[$key][] = $date;
            }
        }
        $current->modify('+1 day');
    }

    $result = [];
    $seen   = [];

    foreach ($weekOrd as $key) {
        foreach (consecutiveBlocks($byWeek[$key]) as $block) {
            $first = $block[0];
            $last2 = $block[count($block) - 1];

            $k1 = $first . '|inicio';
            if (!isset($seen[$k1])) {
                $seen[$k1]  = true;
                $result[] = ['date' => $first, 'tipo' => 'inicio'];
            }
            $k2 = $last2 . '|termino';
            if (!isset($seen[$k2])) {
                $seen[$k2]  = true;
                $result[] = ['date' => $last2, 'tipo' => 'termino'];
            }
        }
    }

    return $result;
}

function fmtDate(string $date): string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d/m/Y') : $date;
}

function fmtDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    $d = new DateTime($dt);
    return $d->format('d/m/Y H:i');
}

$ES_DAYS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$ES_DAYS_SHORT = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/* ─────────────────────────────────────────────────────────────
 * Calcular días esperados
 * ───────────────────────────────────────────────────────────── */
$expectedDays = calcExpectedDays($start_date, $end_date, $feriados);

/* Mapa date → tipos para lookup rápido */
$expectedMap = []; // ['YYYY-MM-DD' => ['inicio','termino'] o subconjunto]
foreach ($expectedDays as $e) {
    $expectedMap[$e['date']][] = $e['tipo'];
}

/* Fechas esperadas únicas (para contar cumplimiento) */
$expectedDatesUnique = array_keys($expectedMap);
sort($expectedDatesUnique);

/* ─────────────────────────────────────────────────────────────
 * Query 1: Usuarios elegibles
 * ───────────────────────────────────────────────────────────── */
$users = [];
$stmtU = $conn->prepare("
    SELECT u.id,
           u.usuario,
           CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,'')) AS nombre_completo,
           COALESCE(u.email, '') AS email,
           COALESCE(de.nombre, '—') AS division
    FROM usuario u
    LEFT JOIN division_empresa de ON de.id = u.id_division
    WHERE u.activo = 1
      AND u.clasificacion_usuario = 'interno'
      AND u.id_perfil = 3
      AND u.id_empresa = ?
    ORDER BY u.usuario ASC
");
$stmtU->bind_param('i', $empresa_id);
$stmtU->execute();
$resU = $stmtU->get_result();
while ($row = $resU->fetch_assoc()) {
    $users[(int)$row['id']] = [
        'usuario'         => trim($row['usuario']),
        'nombre_completo' => trim($row['nombre_completo']),
        'email'           => trim($row['email']),
        'division'        => trim($row['division']),
    ];
}
$stmtU->close();

/* ─────────────────────────────────────────────────────────────
 * Query 2: Subidas reales (usuario × día)
 * ───────────────────────────────────────────────────────────── */
$uploads = []; // [user_id][date] => ['total','primera_hora','ultima_hora']
$allUploadRows = []; // para Hoja 3

$stmtS = $conn->prepare("
    SELECT
        r.id_usuario,
        DATE(COALESCE(m.created_at, r.created_at))     AS fecha_subida,
        COUNT(*)                                         AS total_fotos,
        MIN(COALESCE(m.created_at, r.created_at))       AS primera_hora,
        MAX(COALESCE(m.created_at, r.created_at))       AS ultima_hora
    FROM form_question_responses r
    LEFT JOIN form_question_photo_meta m ON m.resp_id = r.id
    JOIN form_questions fq ON fq.id = r.id_form_question
    WHERE fq.id_formulario = 138
      AND fq.id_question_type = 7
      AND COALESCE(m.created_at, r.created_at) BETWEEN ? AND ?
    GROUP BY r.id_usuario, fecha_subida
    ORDER BY r.id_usuario, fecha_subida
");
$startDt = $start_date . ' 00:00:00';
$endDt   = $end_date   . ' 23:59:59';
$stmtS->bind_param('ss', $startDt, $endDt);
$stmtS->execute();
$resS = $stmtS->get_result();
while ($row = $resS->fetch_assoc()) {
    $uid  = (int)$row['id_usuario'];
    $date = (string)$row['fecha_subida'];
    $uploads[$uid][$date] = [
        'total'       => (int)$row['total_fotos'],
        'primera'     => (string)$row['primera_hora'],
        'ultima'      => (string)$row['ultima_hora'],
    ];
    $allUploadRows[] = [
        'id_usuario'  => $uid,
        'fecha'       => $date,
        'total_fotos' => (int)$row['total_fotos'],
        'primera_hora'=> (string)$row['primera_hora'],
        'ultima_hora' => (string)$row['ultima_hora'],
    ];
}
$stmtS->close();

/* ─────────────────────────────────────────────────────────────
 * Query 3: Fotos duplicadas (por SHA1, misma campaña, días distintos)
 * ───────────────────────────────────────────────────────────── */
$duplicates = [];
$dupsByUser = []; // [user_id] = true  (para columna "Con duplicados")

$stmtD = $conn->prepare("
    SELECT
        r.id_usuario,
        CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,'')) AS nombre_completo,
        COALESCE(u.usuario, CONCAT('user_', r.id_usuario))           AS username,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1'))              AS sha1,
        COUNT(*)                                                       AS total_subidas,
        COUNT(DISTINCT DATE(m.created_at))                            AS dias_distintos,
        GROUP_CONCAT(DISTINCT DATE(m.created_at)
                     ORDER BY DATE(m.created_at) SEPARATOR ', ')      AS fechas,
        MIN(m.created_at)                                              AS primera_subida,
        MAX(m.created_at)                                              AS ultima_subida,
        MIN(m.foto_url)                                                AS primera_url
    FROM form_question_photo_meta m
    JOIN form_question_responses r ON r.id = m.resp_id
    JOIN form_questions fq ON fq.id = r.id_form_question
    JOIN usuario u ON u.id = r.id_usuario AND u.activo = 1
    WHERE fq.id_formulario = 138
      AND fq.id_question_type = 7
      AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
      AND DATE(m.created_at) BETWEEN ? AND ?
    GROUP BY r.id_usuario, JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1'))
    HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
    ORDER BY primera_subida DESC
");
$stmtD->bind_param('ss', $start_date, $end_date);
$stmtD->execute();
$resD = $stmtD->get_result();
while ($row = $resD->fetch_assoc()) {
    $duplicates[] = $row;
    $dupsByUser[(int)$row['id_usuario']] = true;
}
$stmtD->close();

/* ─────────────────────────────────────────────────────────────
 * Query 4: Respuestas texto/numéricas del formulario 138
 * (ej. "Ingrese Kilometraje Camioneta", "INGRESE PATENTE…")
 * ───────────────────────────────────────────────────────────── */
$nonPhotoQuestions = []; // [qid => ['text'=>..., 'type'=>...]]
$textNumAnswers    = []; // [uid][date][qid] => ['answer_text'=>..., 'valor'=>...]

$stmtNPQ = $conn->prepare("
    SELECT id, question_text, id_question_type
    FROM form_questions
    WHERE id_formulario = 138
      AND id_question_type IN (4, 5)
      AND deleted_at IS NULL
    ORDER BY sort_order ASC
");
$stmtNPQ->execute();
$resNPQ = $stmtNPQ->get_result();
while ($row = $resNPQ->fetch_assoc()) {
    $nonPhotoQuestions[(int)$row['id']] = [
        'text' => trim($row['question_text']),
        'type' => (int)$row['id_question_type'],
    ];
}
$stmtNPQ->close();

if (!empty($nonPhotoQuestions)) {
    $qidList = implode(',', array_map('intval', array_keys($nonPhotoQuestions)));
    $stmtNPR = $conn->prepare("
        SELECT
            r.id_usuario,
            DATE(r.created_at) AS fecha,
            r.id_form_question,
            r.answer_text,
            r.valor
        FROM form_question_responses r
        WHERE r.id_form_question IN ($qidList)
          AND r.created_at BETWEEN ? AND ?
        ORDER BY r.id_usuario, fecha
    ");
    $stmtNPR->bind_param('ss', $startDt, $endDt);
    $stmtNPR->execute();
    $resNPR = $stmtNPR->get_result();
    while ($row = $resNPR->fetch_assoc()) {
        $uid  = (int)$row['id_usuario'];
        $date = (string)$row['fecha'];
        $qid  = (int)$row['id_form_question'];
        $textNumAnswers[$uid][$date][$qid] = [
            'answer_text' => (string)($row['answer_text'] ?? ''),
            'valor'       => $row['valor'],
        ];
    }
    $stmtNPR->close();
}

$conn->close();

/* ─────────────────────────────────────────────────────────────
 * Estadísticas por usuario (para Hoja 1)
 * ───────────────────────────────────────────────────────────── */
$userStats = [];
foreach ($users as $uid => $udata) {
    $expected  = count($expectedDatesUnique);
    $complied  = 0;
    foreach ($expectedDatesUnique as $d) {
        if (isset($uploads[$uid][$d])) {
            $complied++;
        }
    }
    $missed  = $expected - $complied;
    $pct     = $expected > 0 ? round($complied / $expected * 100, 1) : 0.0;
    $hasDups = isset($dupsByUser[$uid]);

    $userStats[$uid] = [
        'expected' => $expected,
        'complied' => $complied,
        'missed'   => $missed,
        'pct'      => $pct,
        'has_dups' => $hasDups,
    ];
}

/* Ordenar por % cumplimiento ASC (peor primero) */
uasort($userStats, static fn($a, $b) => $a['pct'] <=> $b['pct']);

/* ─────────────────────────────────────────────────────────────
 * Helpers PhpSpreadsheet
 * ───────────────────────────────────────────────────────────── */
function cellRef(int $col, int $row): string
{
    return Coordinate::stringFromColumnIndex($col) . $row;
}

function setVal(Worksheet $ws, int $col, int $row, mixed $val): void
{
    $ws->setCellValue(cellRef($col, $row), $val);
}

function setStr(Worksheet $ws, int $col, int $row, string $val): void
{
    $ws->setCellValueExplicit(cellRef($col, $row), $val, DataType::TYPE_STRING);
}

function applyHeaderStyle(Worksheet $ws, string $range,
                          string $bg = '217346', string $fg = 'FFFFFF'): void
{
    $ws->getStyle($range)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 10,
                        'color' => ['argb' => 'FF' . $fg]],
        'fill'      => ['fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF' . $bg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                          'color'       => ['argb' => 'FF888888']]],
    ]);
}

function applyDataBorders(Worksheet $ws, string $range): void
{
    $ws->getStyle($range)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                        'color'       => ['argb' => 'FFD0D0D0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
}

function applyRowColor(Worksheet $ws, string $range,
                       string $bg, string $fg): void
{
    $ws->getStyle($range)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
        'font' => ['color' => ['argb' => 'FF' . $fg]],
    ]);
}

function complianceColors(float $pct): array
{
    if ($pct >= 80.0) return ['C6EFCE', '276221']; // verde
    if ($pct >= 50.0) return ['FFEB9C', '9C6500']; // amarillo
    return ['FFC7CE', '9C0006'];                     // rojo
}

/* ─────────────────────────────────────────────────────────────
 * Crear libro
 * ───────────────────────────────────────────────────────────── */
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setTitle('Informe Kilometrajes')
    ->setSubject('KILOMETRAJES ' . $start_date . ' a ' . $end_date)
    ->setCreator('Sistema Visibility');

$now   = (new DateTime())->format('d/m/Y H:i');
$title = 'INFORME KILOMETRAJES — ' . fmtDate($start_date) . ' al ' . fmtDate($end_date);

/* ═════════════════════════════════════════════════════════════
 * HOJA 1 — RESUMEN
 * ═════════════════════════════════════════════════════════════ */
$ws1 = $spreadsheet->getActiveSheet();
$ws1->setTitle('Resumen');

/* Cabecera informativa */
$ws1->mergeCells('A1:H1');
setVal($ws1, 1, 1, $title);
$ws1->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws1->getRowDimension(1)->setRowHeight(28);

$ws1->mergeCells('A2:H2');
setStr($ws1, 1, 2, 'Período: ' . fmtDate($start_date) . ' → ' . fmtDate($end_date)
    . '    |    Generado: ' . $now
    . '    |    Usuarios elegibles: ' . count($users)
    . '    |    Días esperados: ' . count($expectedDatesUnique));
$ws1->getStyle('A2')->applyFromArray([
    'font'      => ['size' => 9, 'color' => ['argb' => 'FF333333']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF5EC']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);

if (!empty($feriados)) {
    $ws1->mergeCells('A3:H3');
    setStr($ws1, 1, 3, 'Feriados marcados: ' . implode(', ', array_map('fmtDate', $feriados)));
    $ws1->getStyle('A3')->applyFromArray([
        'font'      => ['size' => 9, 'italic' => true, 'color' => ['argb' => 'FF8B4513']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFF8E1']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ]);
    $dataStartRow1 = 5;
} else {
    $dataStartRow1 = 4;
}

/* Encabezados de tabla */
$hdr1 = ['Usuario', 'Nombre completo', 'Email',
          'Días esperados', 'Días cumplidos', 'Días sin subida',
          '% Cumplimiento', 'Con duplicados'];
$hdrRow1 = $dataStartRow1;
foreach ($hdr1 as $i => $h) {
    setVal($ws1, $i + 1, $hdrRow1, $h);
}
applyHeaderStyle($ws1, 'A' . $hdrRow1 . ':H' . $hdrRow1);
$ws1->getRowDimension($hdrRow1)->setRowHeight(22);

/* Datos */
$r = $hdrRow1 + 1;
foreach ($userStats as $uid => $stats) {
    $udata = $users[$uid];
    setStr($ws1, 1, $r, $udata['usuario']);
    setStr($ws1, 2, $r, $udata['nombre_completo']);
    setStr($ws1, 3, $r, $udata['email']);
    setVal($ws1, 4, $r, $stats['expected']);
    setVal($ws1, 5, $r, $stats['complied']);
    setVal($ws1, 6, $r, $stats['missed']);
    $ws1->setCellValue(cellRef(7, $r), $stats['pct'] / 100);
    $ws1->getStyle(cellRef(7, $r))->getNumberFormat()
        ->setFormatCode('0.0%');
    setStr($ws1, 8, $r, $stats['has_dups'] ? 'Sí' : 'No');

    applyDataBorders($ws1, 'A' . $r . ':H' . $r);

    [$bg, $fg] = complianceColors($stats['pct']);
    applyRowColor($ws1, 'G' . $r . ':G' . $r, $bg, $fg);

    if ($stats['has_dups']) {
        $ws1->getStyle('H' . $r)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF9C0006']],
        ]);
    }

    $r++;
}

/* Anchos columna Hoja 1 */
foreach (['A' => 18, 'B' => 28, 'C' => 28,
          'D' => 15, 'E' => 15, 'F' => 16,
          'G' => 16, 'H' => 14] as $col => $w) {
    $ws1->getColumnDimension($col)->setWidth($w);
}
$ws1->freezePane('A' . ($hdrRow1 + 1));

/* ═════════════════════════════════════════════════════════════
 * HOJA 2 — CUMPLIMIENTO (MATRIZ)
 * ═════════════════════════════════════════════════════════════ */
$ws2 = $spreadsheet->createSheet()->setTitle('Cumplimiento');
/** @var Worksheet $ws2 */
$ws2 = $spreadsheet->getSheetByName('Cumplimiento');

/* Construir columnas de la matriz (expected days deduplicados) */
$matrixCols = []; // [['date'=>..., 'tipos'=>[...], 'label'=>...]]
foreach ($expectedDatesUnique as $date) {
    $tipos   = $expectedMap[$date];
    $d       = new DateTime($date);
    $dowNum  = (int)$d->format('N');
    $dayShort = $ES_DAYS_SHORT[$dowNum] ?? '?';
    $dateFmt  = $dayShort . ' ' . $d->format('d/m');

    if (in_array('inicio', $tipos, true) && in_array('termino', $tipos, true)) {
        $label = $dateFmt . "\nambos";
    } elseif (in_array('inicio', $tipos, true)) {
        $label = $dateFmt . "\nmañana";
    } else {
        $label = $dateFmt . "\ntarde";
    }
    $matrixCols[] = ['date' => $date, 'tipos' => $tipos, 'label' => $label];
}

/* Título */
$totalCols2 = count($matrixCols) + 4; // A=Usuario, B=Nombre, C=División, ...días..., last=%
$lastColLetter2 = Coordinate::stringFromColumnIndex($totalCols2);
$ws2->mergeCells('A1:' . $lastColLetter2 . '1');
setVal($ws2, 1, 1, $title);
$ws2->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws2->getRowDimension(1)->setRowHeight(26);

/* Encabezados */
$hdrRow2 = 3;
setVal($ws2, 1, $hdrRow2, 'Usuario');
setVal($ws2, 2, $hdrRow2, 'Nombre');
setVal($ws2, 3, $hdrRow2, 'División');
$col2 = 4;
foreach ($matrixCols as $mc) {
    setVal($ws2, $col2, $hdrRow2, $mc['label']);
    $ws2->getRowDimension($hdrRow2)->setRowHeight(30);
    $col2++;
}
setVal($ws2, $col2, $hdrRow2, '% Cumplimiento');
applyHeaderStyle($ws2, 'A' . $hdrRow2 . ':' . Coordinate::stringFromColumnIndex($col2) . $hdrRow2);

/* Datos */
$r2 = $hdrRow2 + 1;
foreach ($userStats as $uid => $stats) {
    $udata = $users[$uid];
    setStr($ws2, 1, $r2, $udata['usuario']);
    setStr($ws2, 2, $r2, $udata['nombre_completo']);
    setStr($ws2, 3, $r2, $udata['division'] ?? '—');

    $col2 = 4;
    foreach ($matrixCols as $mc) {
        $uploaded = isset($uploads[$uid][$mc['date']]);
        $symbol   = $uploaded ? '✓' : '✗';
        setVal($ws2, $col2, $r2, $symbol);
        $cellRef = cellRef($col2, $r2);
        if ($uploaded) {
            $ws2->getStyle($cellRef)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF276221']],
                'fill' => ['fillType' => Fill::FILL_SOLID,
                           'startColor' => ['argb' => 'FFC6EFCE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        } else {
            $ws2->getStyle($cellRef)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FF9C0006']],
                'fill' => ['fillType' => Fill::FILL_SOLID,
                           'startColor' => ['argb' => 'FFFFC7CE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        applyDataBorders($ws2, $cellRef);
        $col2++;
    }

    $pctCell = cellRef($col2, $r2);
    $ws2->setCellValue($pctCell, $stats['pct'] / 100);
    $ws2->getStyle($pctCell)->getNumberFormat()->setFormatCode('0.0%');
    [$bg, $fg] = complianceColors($stats['pct']);
    applyRowColor($ws2, $pctCell, $bg, $fg);
    $ws2->getStyle($pctCell)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    applyDataBorders($ws2, $pctCell);

    applyDataBorders($ws2, 'A' . $r2 . ':C' . $r2);
    $r2++;
}

/* Anchos Hoja 2 */
$ws2->getColumnDimension('A')->setWidth(18);
$ws2->getColumnDimension('B')->setWidth(26);
$ws2->getColumnDimension('C')->setWidth(22);
for ($c = 4; $c <= $col2; $c++) {
    $ws2->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
}
$ws2->freezePane('D' . ($hdrRow2 + 1));

/* ═════════════════════════════════════════════════════════════
 * HOJA 3 — DETALLE DE SUBIDAS
 * ═════════════════════════════════════════════════════════════ */
$ws3 = $spreadsheet->createSheet()->setTitle('Detalle Subidas');
/** @var Worksheet $ws3 */
$ws3 = $spreadsheet->getSheetByName('Detalle Subidas');

/* Calcular número total de columnas en Hoja 3 (9 fijas + extra por cada pregunta) */
$ws3TotalCols  = 9 + count($nonPhotoQuestions);
$ws3LastLetter = Coordinate::stringFromColumnIndex($ws3TotalCols);

$ws3->mergeCells('A1:' . $ws3LastLetter . '1');
setVal($ws3, 1, 1, $title . ' — Detalle de Subidas');
$ws3->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws3->getRowDimension(1)->setRowHeight(26);

$hdr3 = ['Usuario', 'Nombre', 'Fecha', 'Día semana',
          'Primera subida', 'Última subida',
          '# Fotos', '¿Día esperado?', 'Tipo'];
foreach ($nonPhotoQuestions as $qdef) {
    $hdr3[] = $qdef['text'];
}

$hdrRow3 = 3;
foreach ($hdr3 as $i => $h) {
    setVal($ws3, $i + 1, $hdrRow3, $h);
}
applyHeaderStyle($ws3, 'A' . $hdrRow3 . ':' . $ws3LastLetter . $hdrRow3);
$ws3->getRowDimension($hdrRow3)->setRowHeight(20);

$r3 = $hdrRow3 + 1;
foreach ($allUploadRows as $urow) {
    $uid   = $urow['id_usuario'];
    if (!isset($users[$uid])) continue;
    $date  = $urow['fecha'];
    $udata = $users[$uid];

    $d     = new DateTime($date);
    $dowN  = (int)$d->format('N');
    $dayName = $ES_DAYS[$dowN] ?? '?';

    $isExpected = isset($expectedMap[$date]);
    $tipos      = $isExpected ? implode(' y ', $expectedMap[$date]) : '';

    setStr($ws3, 1, $r3, $udata['usuario']);
    setStr($ws3, 2, $r3, $udata['nombre_completo']);
    setStr($ws3, 3, $r3, fmtDate($date));
    setStr($ws3, 4, $r3, $dayName);
    setStr($ws3, 5, $r3, fmtDateTime($urow['primera_hora']));
    setStr($ws3, 6, $r3, fmtDateTime($urow['ultima_hora']));
    setVal($ws3, 7, $r3, $urow['total_fotos']);
    setStr($ws3, 8, $r3, $isExpected ? 'Sí' : 'No');
    setStr($ws3, 9, $r3, $tipos ?: '—');

    /* Columnas extra: respuestas texto/numéricas */
    $extraCol = 10;
    foreach ($nonPhotoQuestions as $qid => $qdef) {
        $ans = $textNumAnswers[$uid][$date][$qid] ?? null;
        if ($ans === null) {
            setStr($ws3, $extraCol, $r3, '—');
        } elseif ($qdef['type'] === 5) {
            $txt = trim($ans['answer_text']);
            if ($txt !== '') {
                is_numeric($txt)
                    ? setVal($ws3, $extraCol, $r3, (float)$txt)
                    : setStr($ws3, $extraCol, $r3, $txt);
            } elseif ($ans['valor'] !== null && (float)$ans['valor'] !== 0.0) {
                setVal($ws3, $extraCol, $r3, (float)$ans['valor']);
            } else {
                setStr($ws3, $extraCol, $r3, '—');
            }
        } else {
            setStr($ws3, $extraCol, $r3, trim($ans['answer_text']) ?: '—');
        }
        $extraCol++;
    }

    applyDataBorders($ws3, 'A' . $r3 . ':' . $ws3LastLetter . $r3);

    if (!$isExpected) {
        $ws3->getStyle('A' . $r3 . ':' . $ws3LastLetter . $r3)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID,
                       'startColor' => ['argb' => 'FFFFFF99']],
        ]);
    }

    $r3++;
}

if ($r3 === $hdrRow3 + 1) {
    $ws3->mergeCells('A' . $r3 . ':' . $ws3LastLetter . $r3);
    setVal($ws3, 1, $r3, 'Sin subidas registradas en el período.');
    $ws3->getStyle('A' . $r3)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

foreach (['A' => 18, 'B' => 26, 'C' => 12, 'D' => 12,
          'E' => 18, 'F' => 18, 'G' => 9,
          'H' => 14, 'I' => 14] as $col => $w) {
    $ws3->getColumnDimension($col)->setWidth($w);
}
/* Anchos para columnas de preguntas extra */
for ($c = 10; $c <= $ws3TotalCols; $c++) {
    $ws3->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(22);
}
$ws3->freezePane('A' . ($hdrRow3 + 1));

/* ═════════════════════════════════════════════════════════════
 * HOJA 4 — FOTOS DUPLICADAS
 * ═════════════════════════════════════════════════════════════ */
$ws4 = $spreadsheet->createSheet()->setTitle('Fotos Duplicadas');
/** @var Worksheet $ws4 */
$ws4 = $spreadsheet->getSheetByName('Fotos Duplicadas');

$ws4->mergeCells('A1:I1');
setVal($ws4, 1, 1, $title . ' — Fotos Duplicadas');
$ws4->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws4->getRowDimension(1)->setRowHeight(26);

$hdr4 = ['Usuario', 'Nombre', 'SHA1 (7 car.)',
          '# Subidas', 'Días distintos', 'Fechas de subida',
          'Primera subida', 'Última subida', 'URL primera foto'];
$hdrRow4 = 3;
foreach ($hdr4 as $i => $h) {
    setVal($ws4, $i + 1, $hdrRow4, $h);
}
applyHeaderStyle($ws4, 'A' . $hdrRow4 . ':I' . $hdrRow4, 'A50000');
$ws4->getRowDimension($hdrRow4)->setRowHeight(20);

$r4 = $hdrRow4 + 1;
if (empty($duplicates)) {
    $ws4->mergeCells('A' . $r4 . ':I' . $r4);
    setVal($ws4, 1, $r4, 'Sin fotos duplicadas detectadas en el período.');
    $ws4->getStyle('A' . $r4)->applyFromArray([
        'font'      => ['italic' => true, 'color' => ['argb' => 'FF555555']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
} else {
    foreach ($duplicates as $dup) {
        $sha1Short = substr((string)$dup['sha1'], 0, 7);
        setStr($ws4, 1, $r4, (string)$dup['username']);
        setStr($ws4, 2, $r4, trim((string)$dup['nombre_completo']));
        setStr($ws4, 3, $r4, $sha1Short);
        setVal($ws4, 4, $r4, (int)$dup['total_subidas']);
        setVal($ws4, 5, $r4, (int)$dup['dias_distintos']);
        setStr($ws4, 6, $r4, (string)$dup['fechas']);
        setStr($ws4, 7, $r4, fmtDateTime($dup['primera_subida']));
        setStr($ws4, 8, $r4, fmtDateTime($dup['ultima_subida']));
        setStr($ws4, 9, $r4, (string)$dup['primera_url']);

        applyDataBorders($ws4, 'A' . $r4 . ':I' . $r4);
        $ws4->getStyle('C' . $r4)->applyFromArray([
            'font' => ['name' => 'Courier New', 'size' => 9],
        ]);
        $ws4->getStyle('I' . $r4)->getFont()->setSize(8);
        $r4++;
    }
}

foreach (['A' => 18, 'B' => 26, 'C' => 10, 'D' => 11,
          'E' => 14, 'F' => 32, 'G' => 18, 'H' => 18, 'I' => 50] as $col => $w) {
    $ws4->getColumnDimension($col)->setWidth($w);
}
$ws4->freezePane('A' . ($hdrRow4 + 1));

/* ─────────────────────────────────────────────────────────────
 * Activar primera hoja y descargar
 * ───────────────────────────────────────────────────────────── */
$spreadsheet->setActiveSheetIndex(0);

$filename = 'Informe_Kilometrajes_' . str_replace('-', '', $start_date)
          . '_' . str_replace('-', '', $end_date) . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

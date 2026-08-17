<?php
declare(strict_types=1);

ini_set('memory_limit', '1G');
set_time_limit(300);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
/* Cálculo compartido con la vista en pantalla (mod_vehiculos → Estatus vehículo).
   Acá solo queda la presentación en Excel; los números salen de la librería,
   así ambas vistas no pueden discrepar. */
require_once __DIR__ . '/lib_estatus_vehiculo.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/* ─────────────────────────────────────────────────────────────
 * Configuración
 * ─────────────────────────────────────────────────────────────
 * Hoja "Fotos Vehículo": incrusta cada foto dentro del .xlsx, así que el
 * archivo crece muchísimo (cada imagen suma peso real al documento) y además
 * obliga a leer y convertir todas las fotos del período en el servidor.
 * Se desactiva por defecto. Poner en true SOLO si se necesita el informe con
 * las fotos embebidas; el resto de las hojas no depende de esto.
 */
const INCLUIR_HOJA_FOTOS = false;

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
/* Evaluación siempre DIARIA: el modo "clásico" se eliminó cuando la operación
   pasó a exigir dos subidas por día hábil. */

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

function slotFromDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    try {
        $h = (int)(new DateTime($dt))->format('H');
        return $h < 12 ? 'Entrada' : 'Salida';
    } catch (Throwable $e) {
        return '—';
    }
}

function slotKeyFromDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return 'sin_hora';
    try {
        $h = (int)(new DateTime($dt))->format('H');
        return $h < 12 ? 'inicio' : 'termino';
    } catch (Throwable $e) {
        return 'sin_hora';
    }
}

function normalizarPatente(string $p): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p));
}

$ES_DAYS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$ES_DAYS_SHORT = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/* ─────────────────────────────────────────────────────────────
 * Cálculo del informe (evaluación diaria)
 * ───────────────────────────────────────────────────────────── */
/* Todo el cálculo (usuarios, subidas, duplicadas, respuestas, vehículos y
   estadísticas de cumplimiento) viene de la librería compartida. */
$evData = ev_calcular_informe($conn, $empresa_id, $start_date, $end_date, $feriados);

$expectedDays        = $evData['expectedDays'];
$expectedMap         = $evData['expectedMap'];
$expectedDatesUnique = $evData['expectedDatesUnique'];
$users               = $evData['users'];
$uploads             = $evData['uploads'];
$allUploadRows       = $evData['allUploadRows'];
$duplicates          = $evData['duplicates'];
$dupsByUser          = $evData['dupsByUser'];
$nonPhotoQuestions   = $evData['nonPhotoQuestions'];
$textNumAnswers      = $evData['textNumAnswers'];
$vehicleInfo         = $evData['vehicleInfo'];
$userStats           = $evData['userStats'];
$matrixCols          = $evData['matrixCols'];
$qid_patente_encuesta = $evData['qid_patente_encuesta'];
$qid_km_restantes     = $evData['qid_km_restantes'];

/* ─────────────────────────────────────────────────────────────
 * Query 5: Preguntas fotográficas y fotos del período
 * Solo se ejecuta si la hoja de fotos está activa: traer todas las fotos del
 * período es caro y no lo usa ninguna otra hoja.
 * ───────────────────────────────────────────────────────────── */
$photoQuestions = []; // [qid => ['text'=>..., 'sort_order'=>...]]
$photoRows      = []; // [uid][date][grupo] => [...]

if (INCLUIR_HOJA_FOTOS) {

$stmtPQ = $conn->prepare("
    SELECT id, question_text, COALESCE(sort_order, 0) AS sort_order
    FROM form_questions
    WHERE id_formulario = 138
      AND id_question_type = 7
      AND deleted_at IS NULL
    ORDER BY sort_order ASC, id ASC
");
$stmtPQ->execute();
$resPQ = $stmtPQ->get_result();
while ($row = $resPQ->fetch_assoc()) {
    $photoQuestions[(int)$row['id']] = [
        'text'       => trim((string)$row['question_text']),
        'sort_order' => (int)$row['sort_order'],
    ];
}
$stmtPQ->close();

$stmtF = $conn->prepare("
    SELECT
        m.id_usuario,
        COALESCE(r.visita_id, 0) AS visita_id,
        DATE(m.created_at)       AS fecha_foto,
        m.foto_url,
        m.created_at             AS hora_foto,
        r.id_form_question,
        fq.question_text,
        COALESCE(fq.sort_order, 0) AS sort_order
    FROM form_question_photo_meta m
    JOIN form_question_responses r ON r.id  = m.resp_id
    JOIN form_questions fq         ON fq.id = r.id_form_question
    JOIN usuario u                 ON u.id  = m.id_usuario
    WHERE fq.id_formulario    = 138
      AND fq.id_question_type = 7
      AND m.created_at BETWEEN ? AND ?
      AND u.id_empresa = ?
      AND u.activo     = 1
      AND u.id        <> 50
    ORDER BY m.id_usuario, fecha_foto, visita_id, fq.sort_order, m.created_at
");
$stmtF->bind_param('ssi', $startDt, $endDt, $empresa_id);
$stmtF->execute();
$resF = $stmtF->get_result();
while ($row = $resF->fetch_assoc()) {
    $uid      = (int)$row['id_usuario'];
    $date     = (string)$row['fecha_foto'];
    $visitaId = (int)$row['visita_id'];
    $hora     = (string)$row['hora_foto'];
    $qid      = (int)$row['id_form_question'];
    $slotKey  = slotKeyFromDateTime($hora);

    if (!isset($photoQuestions[$qid])) {
        $photoQuestions[$qid] = [
            'text'       => trim((string)$row['question_text']) ?: ('Pregunta #' . $qid),
            'sort_order' => (int)$row['sort_order'],
        ];
    }

    /*
     * Agrupación de la hoja de fotos:
     * - Si existe visita_id, se usa como agrupador principal.
     * - Si visita_id viene en 0, se separa por bloque horario: entrada (<12) / salida (>=12).
     * Esto evita mezclar fotos de entrada y salida en la misma fila.
     */
    $groupKey = $visitaId > 0 ? ('v' . $visitaId) : ('slot_' . $slotKey);

    if (!isset($photoRows[$uid][$date][$groupKey])) {
        $photoRows[$uid][$date][$groupKey] = [
            'visita_id'    => $visitaId,
            'tipo'         => slotFromDateTime($hora),
            'primera_hora' => $hora,
            'ultima_hora'  => $hora,
            'sort_ts'      => strtotime($hora) ?: 0,
            'photos'       => [],
        ];
    }

    if (strtotime($hora) < strtotime($photoRows[$uid][$date][$groupKey]['primera_hora'])) {
        $photoRows[$uid][$date][$groupKey]['primera_hora'] = $hora;
        $photoRows[$uid][$date][$groupKey]['tipo'] = slotFromDateTime($hora);
        $photoRows[$uid][$date][$groupKey]['sort_ts'] = strtotime($hora) ?: 0;
    }
    if (strtotime($hora) > strtotime($photoRows[$uid][$date][$groupKey]['ultima_hora'])) {
        $photoRows[$uid][$date][$groupKey]['ultima_hora'] = $hora;
    }

    $photoRows[$uid][$date][$groupKey]['photos'][$qid][] = [
        'url'  => (string)$row['foto_url'],
        'hora' => $hora,
    ];
}
$stmtF->close();

uasort($photoQuestions, static function ($a, $b) {
    return ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['text'], $b['text']);
});

} // fin if (INCLUIR_HOJA_FOTOS) — Query 5

$conn->close();

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

/**
 * Resuelve una foto_url a ruta de archivo usable por Drawing.
 * Convierte webp → JPEG temporal si es necesario.
 * Retorna null si el archivo no existe o no es imagen soportada.
 */
function resolveDrawingPath(string $fotoUrl): ?string
{
    $absPath = $_SERVER['DOCUMENT_ROOT'] . $fotoUrl;
    if (!file_exists($absPath)) {
        return null;
    }

    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

    if ($ext === 'webp') {
        if (!function_exists('imagecreatefromwebp')) {
            return null;
        }
        $img = @imagecreatefromwebp($absPath);
        if ($img === false) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'viz_') . '.jpg';
        imagejpeg($img, $tmp, 85);
        unset($img);
        return $tmp;
    }

    return $absPath;
}

function addPhotoDrawing(Worksheet $ws, string $cell, string $fotoUrl,
                         string $name, int $index, array &$tmpFiles): bool
{
    $drawPath = resolveDrawingPath($fotoUrl);
    if ($drawPath === null) {
        return false;
    }

    $absOriginal = $_SERVER['DOCUMENT_ROOT'] . $fotoUrl;
    if ($drawPath !== $absOriginal) {
        $tmpFiles[] = $drawPath;
    }

    try {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName($name . '_' . $index);
        $drawing->setPath($drawPath);
        $drawing->setCoordinates($cell);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4 + ($index * 98));
        $drawing->setWidth(120);
        $drawing->setHeight(90);
        $drawing->setWorksheet($ws);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/* ─────────────────────────────────────────────────────────────
 * Crear libro
 * ───────────────────────────────────────────────────────────── */
$spreadsheet = new Spreadsheet();
$modoTitleLabel = 'Evaluación Diaria';
$spreadsheet->getProperties()
    ->setTitle('Informe Estatus Vehículo')
    ->setSubject('ESTATUS VEHICULO DIARIO ' . $start_date . ' a ' . $end_date)
    ->setCreator('Sistema Visibility');

$now   = (new DateTime())->format('d/m/Y H:i');
$title = 'INFORME ESTATUS VEHÍCULO — ' . $modoTitleLabel . ' — ' . fmtDate($start_date) . ' al ' . fmtDate($end_date);

/* ═════════════════════════════════════════════════════════════
 * HOJA 1 — RESUMEN
 * ═════════════════════════════════════════════════════════════ */
$ws1 = $spreadsheet->getActiveSheet();
$ws1->setTitle('Resumen');
$ws1LastLetter = 'N';

/* Cabecera informativa */
$ws1->mergeCells('A1:' . $ws1LastLetter . '1');
setVal($ws1, 1, 1, $title);
$ws1->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws1->getRowDimension(1)->setRowHeight(28);

$ws1->mergeCells('A2:' . $ws1LastLetter . '2');
$modoDescripcion = 'Diario (2 subidas por día hábil: mañana y tarde)';
setStr($ws1, 1, 2, 'Período: ' . fmtDate($start_date) . ' → ' . fmtDate($end_date)
    . '    |    Generado: ' . $now
    . '    |    Modo: ' . $modoDescripcion
    . '    |    Usuarios elegibles: ' . count($users)
    . '    |    Subidas esperadas: ' . count($expectedDays));
$ws1->getStyle('A2')->applyFromArray([
    'font'      => ['size' => 9, 'color' => ['argb' => 'FF333333']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF5EC']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);

if (!empty($feriados)) {
    $ws1->mergeCells('A3:' . $ws1LastLetter . '3');
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
$hdr1 = ['Usuario', 'RUT ejecutor', 'Nombre completo', 'Email',
          'Patente', 'Modelo vehículo', 'División',
          'Subidas esperadas', 'Subidas realizadas', 'Subidas pendientes',
          '% Cumplimiento', 'Con duplicados',
          'Km rest. (último)', '# Días patente distinta'];
$hdrRow1 = $dataStartRow1;
foreach ($hdr1 as $i => $h) {
    setVal($ws1, $i + 1, $hdrRow1, $h);
}
applyHeaderStyle($ws1, 'A' . $hdrRow1 . ':' . $ws1LastLetter . $hdrRow1);
$ws1->getRowDimension($hdrRow1)->setRowHeight(22);

/* Datos */
$r = $hdrRow1 + 1;
foreach ($userStats as $uid => $stats) {
    $udata = $users[$uid];
    $vdata = $vehicleInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];

    setStr($ws1, 1, $r, $udata['usuario']);
    setStr($ws1, 2, $r, $udata['rut'] ?: '—');
    setStr($ws1, 3, $r, $udata['nombre_completo']);
    setStr($ws1, 4, $r, $udata['email']);
    setStr($ws1, 5, $r, $vdata['patente']);
    setStr($ws1, 6, $r, $vdata['modelo']);
    setStr($ws1, 7, $r, $udata['division'] ?? '—');
    setVal($ws1, 8, $r, $stats['expected']);
    setVal($ws1, 9, $r, $stats['complied']);
    setVal($ws1, 10, $r, $stats['missed']);
    $ws1->setCellValue(cellRef(11, $r), $stats['pct'] / 100);
    $ws1->getStyle(cellRef(11, $r))->getNumberFormat()
        ->setFormatCode('0.0%');
    setStr($ws1, 12, $r, $stats['has_dups'] ? 'Sí' : 'No');

    /* Km restantes (último valor registrado) y días con patente distinta */
    $kmRestantesUltimo = '—';
    $diasPatenteDist   = 0;
    $patenteAsignada   = normalizarPatente($vdata['patente'] ?? '');

    $fechasOrdenadas = array_keys($uploads[$uid] ?? []);
    sort($fechasOrdenadas);
    foreach ($fechasOrdenadas as $fecha) {
        if ($qid_km_restantes !== null) {
            $ansKR = $textNumAnswers[$uid][$fecha][$qid_km_restantes] ?? null;
            if ($ansKR !== null) {
                $txtKR = trim((string)($ansKR['answer_text'] ?? ''));
                if ($txtKR !== '') {
                    $kmRestantesUltimo = $txtKR;
                } elseif ($ansKR['valor'] !== null) {
                    $kmRestantesUltimo = (string)$ansKR['valor'];
                }
            }
        }
        if ($qid_patente_encuesta !== null && $patenteAsignada !== '') {
            $ansP = $textNumAnswers[$uid][$fecha][$qid_patente_encuesta] ?? null;
            if ($ansP !== null) {
                $pEnc = normalizarPatente(trim((string)($ansP['answer_text'] ?? '')));
                if ($pEnc !== '' && $pEnc !== $patenteAsignada) {
                    $diasPatenteDist++;
                }
            }
        }
    }

    setStr($ws1, 13, $r, $kmRestantesUltimo);
    setVal($ws1, 14, $r, $diasPatenteDist);

    applyDataBorders($ws1, 'A' . $r . ':' . $ws1LastLetter . $r);

    [$bg, $fg] = complianceColors($stats['pct']);
    applyRowColor($ws1, 'K' . $r . ':K' . $r, $bg, $fg);

    if ($stats['has_dups']) {
        $ws1->getStyle('L' . $r)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF9C0006']],
        ]);
    }

    if ($diasPatenteDist > 0) {
        $ws1->getStyle('N' . $r)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF9C0006']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFC7CE']],
        ]);
    }

    $r++;
}

/* Anchos columna Hoja 1 */
foreach (['A' => 18, 'B' => 14, 'C' => 28, 'D' => 28,
          'E' => 18, 'F' => 24, 'G' => 22,
          'H' => 15, 'I' => 15, 'J' => 16,
          'K' => 16, 'L' => 14, 'M' => 18, 'N' => 16] as $col => $w) {
    $ws1->getColumnDimension($col)->setWidth($w);
}
$ws1->freezePane('A' . ($hdrRow1 + 1));

/* ═════════════════════════════════════════════════════════════
 * HOJA 2 — CUMPLIMIENTO (MATRIZ)
 * ═════════════════════════════════════════════════════════════ */
$ws2 = $spreadsheet->createSheet()->setTitle('Cumplimiento');
/** @var Worksheet $ws2 */
$ws2 = $spreadsheet->getSheetByName('Cumplimiento');

/* Columnas de la matriz: vienen de la librería compartida (ev_matrixCols), la
   misma que usa la vista en pantalla, para que ambas muestren los mismos días. */

/* Título */
$totalCols2 = count($matrixCols) + 7; // 6 fijas + días + %
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
setVal($ws2, 2, $hdrRow2, 'RUT ejecutor');
setVal($ws2, 3, $hdrRow2, 'Nombre');
setVal($ws2, 4, $hdrRow2, 'Patente');
setVal($ws2, 5, $hdrRow2, 'Modelo vehículo');
setVal($ws2, 6, $hdrRow2, 'División');
$col2 = 7;
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
    $vdata = $vehicleInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];

    setStr($ws2, 1, $r2, $udata['usuario']);
    setStr($ws2, 2, $r2, $udata['rut'] ?: '—');
    setStr($ws2, 3, $r2, $udata['nombre_completo']);
    setStr($ws2, 4, $r2, $vdata['patente']);
    setStr($ws2, 5, $r2, $vdata['modelo']);
    setStr($ws2, 6, $r2, $udata['division'] ?? '—');

    $col2 = 7;
    foreach ($matrixCols as $mc) {
        // Regla de cumplimiento centralizada en la librería: el Excel y la vista
        // en pantalla evalúan la celda exactamente igual.
        $complied_cell = ev_cumpleCelda($uploads[$uid] ?? [], $mc);
        $symbol = $complied_cell ? '✓' : '✗';
        setVal($ws2, $col2, $r2, $symbol);
        $cellRef = cellRef($col2, $r2);
        if ($complied_cell) {
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

    applyDataBorders($ws2, 'A' . $r2 . ':F' . $r2);
    $r2++;
}

/* Anchos Hoja 2 */
$ws2->getColumnDimension('A')->setWidth(18);
$ws2->getColumnDimension('B')->setWidth(14);
$ws2->getColumnDimension('C')->setWidth(26);
$ws2->getColumnDimension('D')->setWidth(18);
$ws2->getColumnDimension('E')->setWidth(24);
$ws2->getColumnDimension('F')->setWidth(22);
for ($c = 7; $c <= $col2; $c++) {
    $ws2->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
}
$ws2->freezePane('G' . ($hdrRow2 + 1));

/* ═════════════════════════════════════════════════════════════
 * HOJA 3 — DETALLE DE SUBIDAS
 * ═════════════════════════════════════════════════════════════ */
$ws3 = $spreadsheet->createSheet()->setTitle('Detalle Subidas');
/** @var Worksheet $ws3 */
$ws3 = $spreadsheet->getSheetByName('Detalle Subidas');

/* Calcular número total de columnas en Hoja 3 (15 fijas + extra por cada pregunta) */
$ws3TotalCols  = 15 + count($nonPhotoQuestions);
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

$hdr3 = ['Usuario', 'RUT ejecutor', 'Nombre', 'Fecha', 'Día semana',
          'Patente asignada', 'Modelo vehículo', 'División',
          'Primera subida', 'Última subida',
          '# Fotos', '¿Día esperado?', 'Tipo',
          'Patente ingresada', '¿Coincide patente?'];
foreach ($nonPhotoQuestions as $qdef) {
    $hdr3[] = $qdef['text'];
}

$hdrRow3 = 3;
foreach ($hdr3 as $i => $h) {
    setVal($ws3, $i + 1, $hdrRow3, $h);
}
applyHeaderStyle($ws3, 'A' . $hdrRow3 . ':' . $ws3LastLetter . $hdrRow3);
$ws3->getRowDimension($hdrRow3)->setRowHeight(24);

$r3 = $hdrRow3 + 1;
foreach ($allUploadRows as $urow) {
    $uid = $urow['id_usuario'];
    if (!isset($users[$uid])) continue;
    $date  = $urow['fecha'];
    $udata = $users[$uid];
    $vdata = $vehicleInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];

    $d       = new DateTime($date);
    $dowN    = (int)$d->format('N');
    $dayName = $ES_DAYS[$dowN] ?? '?';

    $isExpected = isset($expectedMap[$date]);
    $tipos      = $isExpected ? implode(' y ', $expectedMap[$date]) : '';

    setStr($ws3, 1, $r3, $udata['usuario']);
    setStr($ws3, 2, $r3, $udata['rut'] ?: '—');
    setStr($ws3, 3, $r3, $udata['nombre_completo']);
    setStr($ws3, 4, $r3, fmtDate($date));
    setStr($ws3, 5, $r3, $dayName);
    setStr($ws3, 6, $r3, $vdata['patente']);
    setStr($ws3, 7, $r3, $vdata['modelo']);
    setStr($ws3, 8, $r3, $udata['division'] ?? '—');
    setStr($ws3, 9, $r3, fmtDateTime($urow['primera_hora']));
    setStr($ws3, 10, $r3, fmtDateTime($urow['ultima_hora']));
    setVal($ws3, 11, $r3, $urow['total_fotos']);
    setStr($ws3, 12, $r3, $isExpected ? 'Sí' : 'No');
    setStr($ws3, 13, $r3, $tipos ?: '—');

    /* Col 14: patente ingresada en encuesta; Col 15: ¿coincide con asignada? */
    $patenteAsig3 = $vdata['patente'] ?? '';
    $patenteEnc3  = '';
    if ($qid_patente_encuesta !== null) {
        $ansP3 = $textNumAnswers[$uid][$date][$qid_patente_encuesta] ?? null;
        if ($ansP3 !== null) {
            $patenteEnc3 = trim((string)($ansP3['answer_text'] ?? ''));
        }
    }
    setStr($ws3, 14, $r3, $patenteEnc3 !== '' ? $patenteEnc3 : '—');

    if ($patenteEnc3 !== '' && $patenteAsig3 !== '' && $patenteAsig3 !== '—') {
        $plateMatch = normalizarPatente($patenteEnc3) === normalizarPatente($patenteAsig3);
        setVal($ws3, 15, $r3, $plateMatch ? '✓' : '✗');
        $ws3->getStyle(cellRef(15, $r3))->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => $plateMatch ? 'FF276221' : 'FF9C0006']],
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => $plateMatch ? 'FFC6EFCE' : 'FFFFC7CE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    } else {
        setStr($ws3, 15, $r3, '—');
    }

    /* Columnas extra: respuestas texto/numéricas */
    $extraCol = 16;
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

foreach (['A' => 18, 'B' => 14, 'C' => 26, 'D' => 12, 'E' => 12,
          'F' => 18, 'G' => 24, 'H' => 22,
          'I' => 18, 'J' => 18, 'K' => 9,
          'L' => 14, 'M' => 14, 'N' => 18, 'O' => 14] as $col => $w) {
    $ws3->getColumnDimension($col)->setWidth($w);
}
/* Anchos para columnas de preguntas extra */
for ($c = 16; $c <= $ws3TotalCols; $c++) {
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

/* ═════════════════════════════════════════════════════════════
 * HOJA 5 — FOTOS VEHÍCULO   (desactivada: ver INCLUIR_HOJA_FOTOS arriba)
 * Las imágenes se incrustan en el .xlsx y disparan el peso del archivo.
 * ═════════════════════════════════════════════════════════════ */
$tmpFiles = [];   // se usa en la limpieza final aunque la hoja esté apagada

if (INCLUIR_HOJA_FOTOS) {

$ws5 = $spreadsheet->createSheet()->setTitle('Fotos Vehículo');
/** @var Worksheet $ws5 */
$ws5 = $spreadsheet->getSheetByName('Fotos Vehículo');

$fixedCols5 = 12;
$ws5TotalCols = $fixedCols5 + count($photoQuestions);
$ws5LastLetter = Coordinate::stringFromColumnIndex(max(1, $ws5TotalCols));

$ws5->mergeCells('A1:' . $ws5LastLetter . '1');
setVal($ws5, 1, 1, $title . ' — Fotos Vehículo por Pregunta');
$ws5->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$ws5->getRowDimension(1)->setRowHeight(26);

$hdr5 = ['Usuario', 'RUT ejecutor', 'Nombre', 'Fecha', 'Patente asignada',
          'Modelo vehículo', 'Kilometraje', 'Km rest. mantención', 'División', 'Día', 'Tipo', 'Rango subida'];
$qColMap = [];
$col5 = $fixedCols5 + 1;
foreach ($photoQuestions as $qid => $qdef) {
    $hdr5[] = $qdef['text'];
    $qColMap[$qid] = $col5;
    $col5++;
}

$hdrRow5 = 3;
foreach ($hdr5 as $i => $h) {
    setVal($ws5, $i + 1, $hdrRow5, $h);
}
applyHeaderStyle($ws5, 'A' . $hdrRow5 . ':' . $ws5LastLetter . $hdrRow5);
$ws5->getRowDimension($hdrRow5)->setRowHeight(34);

$r5 = $hdrRow5 + 1;

foreach ($users as $uid => $udata) {
    if (empty($photoRows[$uid])) {
        continue;
    }

    ksort($photoRows[$uid]);
    foreach ($photoRows[$uid] as $date => $groups) {
        uasort($groups, static function ($a, $b) {
            return ($a['sort_ts'] ?? 0) <=> ($b['sort_ts'] ?? 0);
        });

        foreach ($groups as $group) {
            $vdata   = $vehicleInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];
            $d       = new DateTime($date);
            $dowName = $ES_DAYS[(int)$d->format('N')] ?? '?';
            $rango   = fmtDateTime($group['primera_hora']) . ' → ' . fmtDateTime($group['ultima_hora']);
            $kilometraje = '—';
            foreach ($nonPhotoQuestions as $kqid => $kqdef) {
                if (stripos($kqdef['text'], 'kilometraje') === false) {
                    continue;
                }
                $ansKm = $textNumAnswers[$uid][$date][$kqid] ?? null;
                if ($ansKm === null) {
                    continue;
                }
                $txtKm = trim((string)($ansKm['answer_text'] ?? ''));
                if ($txtKm !== '') {
                    $kilometraje = $txtKm;
                    break;
                }
                if ($ansKm['valor'] !== null && (float)$ansKm['valor'] !== 0.0) {
                    $kilometraje = (string)$ansKm['valor'];
                    break;
                }
            }

            $kmRestantes5 = '—';
            if ($qid_km_restantes !== null) {
                $ansKmR = $textNumAnswers[$uid][$date][$qid_km_restantes] ?? null;
                if ($ansKmR !== null) {
                    $txtKmR = trim((string)($ansKmR['answer_text'] ?? ''));
                    if ($txtKmR !== '') {
                        $kmRestantes5 = $txtKmR;
                    } elseif ($ansKmR['valor'] !== null && (float)$ansKmR['valor'] !== 0.0) {
                        $kmRestantes5 = (string)$ansKmR['valor'];
                    }
                }
            }

            setStr($ws5, 1, $r5, $udata['usuario']);
            setStr($ws5, 2, $r5, $udata['rut'] ?: '—');
            setStr($ws5, 3, $r5, $udata['nombre_completo']);
            setStr($ws5, 4, $r5, fmtDate($date));
            setStr($ws5, 5, $r5, $vdata['patente']);
            setStr($ws5, 6, $r5, $vdata['modelo']);
            setStr($ws5, 7, $r5, $kilometraje);
            setStr($ws5, 8, $r5, $kmRestantes5);
            setStr($ws5, 9, $r5, $udata['division'] ?? '—');
            setStr($ws5, 10, $r5, $dowName);
            setStr($ws5, 11, $r5, $group['tipo'] ?: '—');
            setStr($ws5, 12, $r5, $rango);

            $maxFotosInRow = 1;
            foreach ($photoQuestions as $qid => $_qdef) {
                $targetCol = $qColMap[$qid];
                $cell      = cellRef($targetCol, $r5);
                $fotos     = $group['photos'][$qid] ?? [];

                if (empty($fotos)) {
                    setStr($ws5, $targetCol, $r5, '—');
                    continue;
                }

                $maxFotosInRow = max($maxFotosInRow, count($fotos));
                setStr($ws5, $targetCol, $r5, count($fotos) > 1 ? count($fotos) . ' fotos' : '');

                $idx = 0;
                foreach ($fotos as $foto) {
                    $ok = addPhotoDrawing(
                        $ws5,
                        $cell,
                        $foto['url'],
                        'foto_' . $uid . '_' . $r5 . '_' . $qid,
                        $idx,
                        $tmpFiles
                    );
                    if (!$ok && $idx === 0) {
                        setStr($ws5, $targetCol, $r5, '(sin foto)');
                    }
                    $idx++;
                }
            }

            applyDataBorders($ws5, 'A' . $r5 . ':' . $ws5LastLetter . $r5);
            $ws5->getRowDimension($r5)->setRowHeight(max(82, $maxFotosInRow * 74));
            $r5++;
        }
    }
}

if ($r5 === $hdrRow5 + 1) {
    $ws5->mergeCells('A' . $r5 . ':' . $ws5LastLetter . $r5);
    setVal($ws5, 1, $r5, 'Sin fotos registradas en el período.');
    $ws5->getStyle('A' . $r5)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

foreach (['A' => 18, 'B' => 14, 'C' => 26, 'D' => 12, 'E' => 18,
          'F' => 24, 'G' => 14, 'H' => 16, 'I' => 22, 'J' => 12, 'K' => 12, 'L' => 35] as $col => $w) {
    $ws5->getColumnDimension($col)->setWidth($w);
}
for ($c = $fixedCols5 + 1; $c <= $ws5TotalCols; $c++) {
    $ws5->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(24);
}
$ws5->freezePane(Coordinate::stringFromColumnIndex($fixedCols5 + 1) . ($hdrRow5 + 1));

} // fin if (INCLUIR_HOJA_FOTOS) — Hoja 5

/* ─────────────────────────────────────────────────────────────
 * Activar primera hoja y descargar
 * ───────────────────────────────────────────────────────────── */
$spreadsheet->setActiveSheetIndex(0);

$modoLabel = 'Diario';
$filename = 'Informe_Estatus_Vehiculo_' . $modoLabel . '_'
          . str_replace('-', '', $start_date)
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

foreach ($tmpFiles as $tmp) {
    @unlink($tmp);
}
exit;
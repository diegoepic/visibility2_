<?php
declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(120);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) session_start();

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

/* ── Seguridad ── */
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
    http_response_code(403);
    exit('Acceso inválido.');
}

/* ── Parámetros ── */
$id_campana  = (int)($_GET['id_campana']  ?? 0);
$fecha_ini   = trim($_GET['fecha_ini']   ?? '');
$fecha_fin   = trim($_GET['fecha_fin']   ?? '');

if ($id_campana <= 0) exit('Campaña no especificada.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_ini)) $fecha_ini = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin))  $fecha_fin  = '';

mysqli_set_charset($conn, 'utf8mb4');

/* ── Verificar que la campaña pertenece a la empresa ── */
$stmtF = $conn->prepare("
    SELECT f.nombre, f.modalidad, f.fechaInicio, f.fechaTermino
    FROM formulario f
    WHERE f.id = ? AND f.id_empresa = ?
      AND f.tipo = 1 AND f.modalidad IN ('implementacion_auditoria','solo_implementacion')
    LIMIT 1
");
$stmtF->bind_param('ii', $id_campana, $empresa_id);
$stmtF->execute();
$campana = $stmtF->get_result()->fetch_assoc();
$stmtF->close();
if (!$campana) exit('Campaña no encontrada.');

/* ── Materiales propuestos (referencia) ── */
$stmtDivCamp = $conn->prepare("SELECT id_division FROM formulario WHERE id = ? LIMIT 1");
$stmtDivCamp->bind_param('i', $id_campana);
$stmtDivCamp->execute();
$divRow = $stmtDivCamp->get_result()->fetch_assoc();
$stmtDivCamp->close();
$id_division = (int)($divRow['id_division'] ?? 0);

$stmtMat = $conn->prepare("
    SELECT MIN(fq.id) AS id_fq, fq.material AS nombre, SUM(fq.valor_propuesto) AS cantidad_propuesta
    FROM formularioQuestion fq
    WHERE fq.id_formulario = ?
      AND fq.material IS NOT NULL AND TRIM(fq.material) != ''
    GROUP BY fq.material
    HAVING SUM(fq.valor_propuesto) > 0
    ORDER BY fq.material
");
$stmtMat->bind_param('i', $id_campana);
$stmtMat->execute();
$materiales = $stmtMat->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtMat->close();

/* ── Recepciones cabecera ── */
$sqlRec = "
    SELECT
        mr.id,
        mr.fecha_recepcion,
        mr.created_at,
        mr.numero_guia,
        mr.foto_guia_url,
        mr.observacion,
        CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,'')) AS nombre_ejecutor,
        u.usuario,
        u.email
    FROM material_recepcion mr
    JOIN usuario u ON u.id = mr.id_usuario
    WHERE mr.id_formulario = ? AND mr.id_empresa = ?
";
$pRec  = [$id_campana, $empresa_id];
$tRec  = 'ii';
if ($fecha_ini !== '') { $sqlRec .= " AND mr.fecha_recepcion >= ?"; $pRec[] = $fecha_ini; $tRec .= 's'; }
if ($fecha_fin !== '') { $sqlRec .= " AND mr.fecha_recepcion <= ?"; $pRec[] = $fecha_fin; $tRec .= 's'; }
$sqlRec .= " ORDER BY mr.fecha_recepcion DESC, mr.created_at DESC";

$stmtR = $conn->prepare($sqlRec);
$stmtR->bind_param($tRec, ...$pRec);
$stmtR->execute();
$recepciones = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtR->close();

/* ── Detalles por recepción ── */
$recIds = array_column($recepciones, 'id');
$detallesPorRec = [];
if (!empty($recIds)) {
    $ph = implode(',', array_fill(0, count($recIds), '?'));
    $stmtD = $conn->prepare("
        SELECT id_recepcion, nombre_material, cantidad_propuesta, cantidad_recibida
        FROM material_recepcion_detalle
        WHERE id_recepcion IN ($ph)
        ORDER BY nombre_material
    ");
    $stmtD->bind_param(str_repeat('i', count($recIds)), ...$recIds);
    $stmtD->execute();
    foreach ($stmtD->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $detallesPorRec[(int)$row['id_recepcion']][] = $row;
    }
    $stmtD->close();
}

$conn->close();

/* Normalizar foto_guia_url: puede ser JSON array o URL simple (registros antiguos) */
foreach ($recepciones as &$rec) {
    $decoded = json_decode($rec['foto_guia_url'] ?? '', true);
    $rec['_fotos_str'] = is_array($decoded) ? implode(' | ', $decoded) : ($rec['foto_guia_url'] ?? '');
}
unset($rec);

/* ── Helpers ── */
function cellRef2(int $col, int $row): string
{
    return Coordinate::stringFromColumnIndex($col) . $row;
}
function setVal2(Worksheet $ws, int $col, int $row, mixed $val): void
{
    $ws->setCellValue(cellRef2($col, $row), $val);
}
function setStr2(Worksheet $ws, int $col, int $row, string $val): void
{
    $ws->setCellValueExplicit(cellRef2($col, $row), $val, DataType::TYPE_STRING);
}
function hdrStyle2(Worksheet $ws, string $range, string $bg = '217346'): void
{
    $ws->getStyle($range)->applyFromArray([
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                          'color'       => ['argb' => 'FF888888']]],
    ]);
}
function dataBorders2(Worksheet $ws, string $range): void
{
    $ws->getStyle($range)->applyFromArray([
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                          'color'       => ['argb' => 'FFD0D0D0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
}

/* ── Crear libro ── */
$spreadsheet = new Spreadsheet();
$nombreCampana = $campana['nombre'];
$now = (new DateTime())->format('d/m/Y H:i');
$titleStr = 'RECEPCIÓN DE MATERIALES — ' . strtoupper($nombreCampana);
if ($fecha_ini || $fecha_fin) {
    $titleStr .= '  |  ' . ($fecha_ini ? date('d/m/Y', strtotime($fecha_ini)) : '…')
               . ' → ' . ($fecha_fin ? date('d/m/Y', strtotime($fecha_fin)) : '…');
}

/* ══════════════════════════════════════════════════════════════
 * HOJA 1 — RESUMEN POR EJECUTOR
 * ══════════════════════════════════════════════════════════════ */
$ws1 = $spreadsheet->getActiveSheet()->setTitle('Resumen');

$ws1->mergeCells('A1:H1');
setVal2($ws1, 1, 1, $titleStr);
$ws1->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$ws1->getRowDimension(1)->setRowHeight(26);

$ws1->mergeCells('A2:H2');
setStr2($ws1, 1, 2, 'Generado: ' . $now . '    |    Modalidad: ' . $campana['modalidad']
    . '    |    Registros exportados: ' . count($recepciones));
$ws1->getStyle('A2')->applyFromArray([
    'font'      => ['size' => 9, 'color' => ['argb' => 'FF333333']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF5EC']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$hdr1 = ['Ejecutor', 'Usuario', 'Email', 'Fecha retiro material', 'Fecha registro sistema', 'N° Guía', 'Materiales recibidos', 'Observación'];
$hdrRow = 4;
foreach ($hdr1 as $i => $h) setVal2($ws1, $i + 1, $hdrRow, $h);
hdrStyle2($ws1, 'A4:H4');
$ws1->getRowDimension($hdrRow)->setRowHeight(22);

$r1 = $hdrRow + 1;
foreach ($recepciones as $rec) {
    $dets = $detallesPorRec[(int)$rec['id']] ?? [];
    $resumen = implode(', ', array_map(
        fn($d) => $d['nombre_material'] . ' (' . (float)$d['cantidad_recibida'] . ')',
        $dets
    ));
    setStr2($ws1, 1, $r1, trim($rec['nombre_ejecutor']));
    setStr2($ws1, 2, $r1, $rec['usuario']);
    setStr2($ws1, 3, $r1, $rec['email'] ?? '');
    setStr2($ws1, 4, $r1, date('d/m/Y', strtotime($rec['fecha_recepcion'])));
    setStr2($ws1, 5, $r1, date('d/m/Y H:i', strtotime($rec['created_at'])));
    setStr2($ws1, 6, $r1, $rec['numero_guia'] ?? '—');
    setStr2($ws1, 7, $r1, $resumen ?: '—');
    setStr2($ws1, 8, $r1, $rec['observacion'] ?? '');
    dataBorders2($ws1, "A{$r1}:H{$r1}");
    $r1++;
}

if ($r1 === $hdrRow + 1) {
    $ws1->mergeCells("A{$r1}:H{$r1}");
    setVal2($ws1, 1, $r1, 'Sin recepciones en el período seleccionado.');
    $ws1->getStyle("A{$r1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

foreach (['A' => 26, 'B' => 18, 'C' => 26, 'D' => 16, 'E' => 16, 'F' => 18, 'G' => 50, 'H' => 30] as $col => $w) {
    $ws1->getColumnDimension($col)->setWidth($w);
}
$ws1->freezePane('A' . ($hdrRow + 1));

/* ══════════════════════════════════════════════════════════════
 * HOJA 2 — DETALLE POR MATERIAL
 * ══════════════════════════════════════════════════════════════ */
$ws2 = $spreadsheet->createSheet()->setTitle('Detalle Materiales');
/** @var Worksheet $ws2 */
$ws2 = $spreadsheet->getSheetByName('Detalle Materiales');

$ws2->mergeCells('A1:H1');
setVal2($ws2, 1, 1, $titleStr . ' — Detalle por Material');
$ws2->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$ws2->getRowDimension(1)->setRowHeight(26);

$hdr2 = ['Ejecutor', 'Usuario', 'Fecha retiro material', 'Fecha registro sistema', 'N° Guía', 'URL Foto Guía',
         'Material', 'Cant. propuesta', 'Cant. recibida'];
$hdrRow2 = 3;
foreach ($hdr2 as $i => $h) setVal2($ws2, $i + 1, $hdrRow2, $h);
hdrStyle2($ws2, 'A3:I3');
$ws2->getRowDimension($hdrRow2)->setRowHeight(22);

$r2 = $hdrRow2 + 1;
foreach ($recepciones as $rec) {
    $dets = $detallesPorRec[(int)$rec['id']] ?? [];
    if (empty($dets)) {
        setStr2($ws2, 1, $r2, trim($rec['nombre_ejecutor']));
        setStr2($ws2, 2, $r2, $rec['usuario']);
        setStr2($ws2, 3, $r2, date('d/m/Y', strtotime($rec['fecha_recepcion'])));
        setStr2($ws2, 4, $r2, date('d/m/Y H:i', strtotime($rec['created_at'])));
        setStr2($ws2, 5, $r2, $rec['numero_guia'] ?? '—');
        setStr2($ws2, 6, $r2, $rec['_fotos_str']);
        setStr2($ws2, 7, $r2, '—');
        setVal2($ws2, 8, $r2, 0);
        setVal2($ws2, 9, $r2, 0);
        dataBorders2($ws2, "A{$r2}:I{$r2}");
        $r2++;
    } else {
        foreach ($dets as $det) {
            setStr2($ws2, 1, $r2, trim($rec['nombre_ejecutor']));
            setStr2($ws2, 2, $r2, $rec['usuario']);
            setStr2($ws2, 3, $r2, date('d/m/Y', strtotime($rec['fecha_recepcion'])));
            setStr2($ws2, 4, $r2, date('d/m/Y H:i', strtotime($rec['created_at'])));
            setStr2($ws2, 5, $r2, $rec['numero_guia'] ?? '—');
            setStr2($ws2, 6, $r2, $rec['_fotos_str']);
            setStr2($ws2, 7, $r2, $det['nombre_material']);
            setVal2($ws2, 8, $r2, (float)$det['cantidad_propuesta']);
            setVal2($ws2, 9, $r2, (float)$det['cantidad_recibida']);
            dataBorders2($ws2, "A{$r2}:I{$r2}");
            $r2++;
        }
    }
}

if ($r2 === $hdrRow2 + 1) {
    $ws2->mergeCells("A{$r2}:I{$r2}");
    setVal2($ws2, 1, $r2, 'Sin datos.');
    $ws2->getStyle("A{$r2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

foreach (['A' => 26, 'B' => 18, 'C' => 16, 'D' => 16, 'E' => 18, 'F' => 50,
          'G' => 28, 'H' => 14, 'I' => 14] as $col => $w) {
    $ws2->getColumnDimension($col)->setWidth($w);
}
$ws2->freezePane('A' . ($hdrRow2 + 1));

/* ══════════════════════════════════════════════════════════════
 * HOJA 3 — PROPUESTO vs RECIBIDO (por material)
 * ══════════════════════════════════════════════════════════════ */
$ws3 = $spreadsheet->createSheet()->setTitle('Propuesto vs Recibido');
/** @var Worksheet $ws3 */
$ws3 = $spreadsheet->getSheetByName('Propuesto vs Recibido');

$ws3->mergeCells('A1:D1');
setVal2($ws3, 1, 1, $titleStr . ' — Propuesto vs Recibido');
$ws3->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FFFFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF217346']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$ws3->getRowDimension(1)->setRowHeight(26);

$hdr3 = ['Material', 'Cant. propuesta campaña', 'Cant. total recibida', '% Recibido'];
$hdrRow3 = 3;
foreach ($hdr3 as $i => $h) setVal2($ws3, $i + 1, $hdrRow3, $h);
hdrStyle2($ws3, 'A3:D3');
$ws3->getRowDimension($hdrRow3)->setRowHeight(22);

/* Agrupar recibido por material */
$totRecibPorMat = [];
foreach ($detallesPorRec as $dets) {
    foreach ($dets as $d) {
        $nom = $d['nombre_material'];
        $totRecibPorMat[$nom] = ($totRecibPorMat[$nom] ?? 0) + (float)$d['cantidad_recibida'];
    }
}

$r3 = $hdrRow3 + 1;
foreach ($materiales as $mat) {
    $nom      = $mat['nombre'];
    $propuesta = (float)$mat['cantidad_propuesta'];
    $recibida  = $totRecibPorMat[$nom] ?? 0.0;
    $pct       = $propuesta > 0 ? round($recibida / $propuesta * 100, 1) : 0.0;

    setStr2($ws3, 1, $r3, $nom);
    setVal2($ws3, 2, $r3, $propuesta);
    setVal2($ws3, 3, $r3, $recibida);
    $ws3->setCellValue(cellRef2(4, $r3), $pct / 100);
    $ws3->getStyle(cellRef2(4, $r3))->getNumberFormat()->setFormatCode('0.0%');

    $bg = $pct >= 80 ? 'C6EFCE' : ($pct >= 40 ? 'FFEB9C' : 'FFC7CE');
    $fg = $pct >= 80 ? '276221' : ($pct >= 40 ? '9C6500' : '9C0006');
    $ws3->getStyle(cellRef2(4, $r3))->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
        'font' => ['color' => ['argb' => 'FF' . $fg], 'bold' => true],
    ]);
    dataBorders2($ws3, "A{$r3}:D{$r3}");
    $r3++;
}

if ($r3 === $hdrRow3 + 1) {
    $ws3->mergeCells("A{$r3}:D{$r3}");
    setVal2($ws3, 1, $r3, 'Sin materiales propuestos para esta campaña.');
    $ws3->getStyle("A{$r3}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

foreach (['A' => 32, 'B' => 20, 'C' => 20, 'D' => 14] as $col => $w) {
    $ws3->getColumnDimension($col)->setWidth($w);
}
$ws3->freezePane('A' . ($hdrRow3 + 1));

/* ── Activar primera hoja y enviar ── */
$spreadsheet->setActiveSheetIndex(0);

$safeNombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreCampana);
$filename   = 'Recepcion_Materiales_' . $safeNombre . '_' . date('Ymd_His') . '.xlsx';

while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

(new Xlsx($spreadsheet))->save('php://output');
exit();

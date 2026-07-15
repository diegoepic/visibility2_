<?php
declare(strict_types=1);
// descargar_excel_seguimiento_etapas.php
// Reporte completo de una campaña "implementacion_por_etapas":
//   Hoja 1 Detalle por material + etapa (con faltante, apoyos y fotos por etapa)
//   Hoja 2 Pendientes
//   Hoja 3 Apoyos
//   Hoja 4 Historial (gestion_visita)
// Estructura/estilos basados en portal/informes/descargar_excel.php.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '2048M');
set_time_limit(0);
date_default_timezone_set('America/Santiago');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Sesión expirada.');
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

function fail(string $m): void { while (ob_get_level() > 0) { ob_end_clean(); } http_response_code(400); exit($m); }

function limpiarNombreArchivo(string $t): string {
    $t = trim($t);
    if ($t === '') return 'reporte';
    $t = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $t);
    $t = preg_replace('/\s+/u', '_', $t);
    return $t !== '' ? $t : 'reporte';
}
function setStr(Worksheet $s, int $c, int $r, string $v): void {
    $s->setCellValueExplicit(Coordinate::stringFromColumnIndex($c) . $r, $v, DataType::TYPE_STRING);
}
function setNum(Worksheet $s, int $c, int $r, $v): void {
    $s->setCellValue(Coordinate::stringFromColumnIndex($c) . $r, $v);
}
function estiloCabecera(Worksheet $s, string $range): void {
    $s->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
}
function estiloDatos(Worksheet $s, string $range): void {
    $s->getStyle($range)->applyFromArray(['alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);
}
function normFoto(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $url = ltrim(str_replace('\\', '/', $url), '/');
    return 'https://www.visibility.cl/visibility2/app/' . $url;
}
function escribirCabeceras(Worksheet $sheet, array $headers): void {
    $col = 1;
    foreach ($headers as $h) { setStr($sheet, $col++, 1, (string)$h); }
    $last = Coordinate::stringFromColumnIndex(count($headers));
    estiloCabecera($sheet, "A1:{$last}1");
    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->freezePane('A2');
}

/* ---------------- Entrada ---------------- */
$idForm = isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT) ? (int)$_GET['id'] : 0;
if ($idForm <= 0) fail('ID de campaña inválido.');

$empresa_id  = (int)($_SESSION['empresa_id'] ?? 0);
$division_id = (int)($_SESSION['division_id'] ?? 0);
$isMC = ($division_id === 1); // MC puede descargar cualquier empresa

$sqlForm = "SELECT nombre, modalidad FROM formulario WHERE id = ? " . ($isMC ? "" : "AND id_empresa = ? ") . "LIMIT 1";
$stmt = $conn->prepare($sqlForm);
if ($isMC) { $stmt->bind_param('i', $idForm); }
else       { $stmt->bind_param('ii', $idForm, $empresa_id); }
$stmt->execute();
$form = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$form) fail('Campaña no encontrada.');
if (strtolower(trim((string)$form['modalidad'])) !== 'implementacion_por_etapas') {
    fail('Esta exportación es solo para campañas de Implementación por Etapas.');
}
$nombreCampana = (string)$form['nombre'];

// ¿Incluir columnas de fotos por etapa? (el modal del dashboard manda fotos=1/0; por defecto sí)
$incluirFotos = !isset($_GET['fotos'])
    || in_array(strtolower(trim((string)$_GET['fotos'])), ['1', 'true', 'si', 'sí', 'yes'], true);

$ETAPA_LABEL = ['armado' => 'ARMADO', 'entregado' => 'ENTREGADO', 'implementado' => 'IMPLEMENTADO', 'retirado' => 'RETIRADO'];

/* ---------------- 1) Detalle por material ---------------- */
$sqlDet = "
    SELECT
        fq.id AS idFQ, fq.id_local, l.codigo AS codigo, UPPER(l.nombre) AS local_nombre,
        UPPER(COALESCE(l.direccion,'')) AS direccion,
        UPPER(COALESCE(co.comuna,'')) AS comuna, UPPER(COALESCE(re.region,'')) AS region,
        UPPER(COALESCE(ca.nombre,'')) AS cadena, UPPER(COALESCE(cu.nombre,'')) AS cuenta,
        UPPER(COALESCE(fq.material,'')) AS material,
        UPPER(COALESCE(fq.categoria,'')) AS categoria, UPPER(COALESCE(fq.marca,'')) AS marca,
        UPPER(COALESCE(u.usuario,'')) AS ejecutor,
        fq.etapa_material,
        fq.pregunta,
        CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED) AS propuesto,
        CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) AS implementado,
        DATE(fq.fechaVisita) AS fecha_visita, DATE(fq.fechaPropuesta) AS fecha_propuesta,
        UPPER(COALESCE(fq.observacion,'')) AS observacion
    FROM formularioQuestion fq
    INNER JOIN local l    ON l.id = fq.id_local
    LEFT  JOIN comuna co  ON co.id = l.id_comuna
    LEFT  JOIN region re  ON re.id = co.id_region
    LEFT  JOIN cadena ca  ON ca.id = l.id_cadena
    LEFT  JOIN cuenta cu  ON cu.id = l.id_cuenta
    LEFT  JOIN usuario u  ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
    ORDER BY l.codigo, fq.material
";
$stmt = $conn->prepare($sqlDet);
$stmt->bind_param('i', $idForm);
$stmt->execute();
$res = $stmt->get_result();
$detalle = [];
$fqIds = [];
while ($r = $res->fetch_assoc()) { $detalle[] = $r; $fqIds[] = (int)$r['idFQ']; }
$stmt->close();

/* ---------------- Fotos por etapa + apoyos (en memoria) ---------------- */
$fotosPorFQ = [];   // [idFQ][kind] = [urls]
$apoyosPorFQ = [];  // [idFQ] = ["nombre (etapa)"]
if (!empty($fqIds)) {
    $ph = implode(',', array_fill(0, count($fqIds), '?'));
    $types = str_repeat('i', count($fqIds));

    // Fotos (solo si se pidieron)
    if ($incluirFotos) {
        $sqlF = "SELECT id_formularioQuestion, kind, url FROM fotoVisita
                 WHERE id_formularioQuestion IN ($ph) AND kind IN ('armado','entregado','implementado','retirado') ORDER BY id";
        $stmtF = $conn->prepare($sqlF);
        $stmtF->bind_param($types, ...$fqIds);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        while ($f = $resF->fetch_assoc()) {
            $fotosPorFQ[(int)$f['id_formularioQuestion']][(string)$f['kind']][] = normFoto((string)$f['url']);
        }
        $stmtF->close();
    }

    // Apoyos
    $sqlA = "SELECT ga.id_formularioQuestion AS idFQ, ga.etapa,
                    TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS nombre, u.usuario
             FROM gestion_apoyo ga INNER JOIN usuario u ON u.id = ga.id_usuario_apoyo
             WHERE ga.id_formularioQuestion IN ($ph) ORDER BY ga.created_at";
    $stmtA = $conn->prepare($sqlA);
    $stmtA->bind_param($types, ...$fqIds);
    $stmtA->execute();
    $resA = $stmtA->get_result();
    while ($a = $resA->fetch_assoc()) {
        $nom = trim((string)$a['nombre']) !== '' ? trim((string)$a['nombre']) : (string)$a['usuario'];
        $et  = (string)($a['etapa'] ?? '');
        $apoyosPorFQ[(int)$a['idFQ']][] = $nom . ($et !== '' ? ' (' . $et . ')' : '');
    }
    $stmtA->close();
}

/* ---------------- Construir spreadsheet ---------------- */
$ss = new Spreadsheet();
$ss->getProperties()->setCreator('Visibility')->setTitle('Seguimiento por Etapas');

/* === Hoja 1: Detalle por material + etapa === */
$h1 = $ss->getActiveSheet();
$h1->setTitle('Detalle por material');
$headers1 = ['CAMPAÑA', 'CÓDIGO', 'ID LOCAL', 'LOCAL', 'DIRECCIÓN', 'COMUNA', 'REGIÓN', 'CADENA', 'CUENTA',
    'MATERIAL', 'CATEGORÍA', 'MARCA', 'EJECUTOR',
    'ETAPA ACTUAL', 'PROPUESTO', 'IMPLEMENTADO', 'FALTANTE', 'ESTADO', 'FECHA VISITA', 'FECHA PROPUESTA',
    'OBSERVACIÓN', 'APOYOS'];
if ($incluirFotos) {
    array_push($headers1, 'FOTOS ARMADO', 'FOTOS ENTREGA', 'FOTOS IMPLEMENTADO', 'FOTOS RETIRADO');
}
escribirCabeceras($h1, $headers1);
$row = 2;
foreach ($detalle as $d) {
    $idFQ  = (int)$d['idFQ'];
    $etapa = (string)($d['etapa_material'] ?? '');
    $prop  = (int)$d['propuesto'];
    $impl  = (int)$d['implementado'];
    $falt  = max(0, $prop - $impl);
    if ($etapa === 'retirado' || ($etapa === 'implementado' && $impl >= $prop && $prop > 0)) { $estado = 'COMPLETO'; }
    elseif ($etapa === 'implementado' && $impl < $prop) { $estado = 'PARCIAL'; }
    elseif ($etapa === 'armado' || $etapa === 'entregado') { $estado = 'EN PROCESO'; }
    else {
        // Sin etapa: si la sala fue visitada y quedó Pendiente/Cancelada, reflejarlo
        // (el flujo de estado escribe pregunta='en proceso'/'cancelado' sin tocar etapa_material)
        $pregEstado = strtolower(trim((string)($d['pregunta'] ?? '')));
        if ($pregEstado === 'cancelado')      { $estado = 'CANCELADO'; }
        elseif ($pregEstado === 'en proceso') { $estado = 'PENDIENTE (VISITADO)'; }
        else                                  { $estado = 'SIN INICIAR'; }
    }

    $fotos = $fotosPorFQ[$idFQ] ?? [];
    $col = 1;
    setStr($h1, $col++, $row, $nombreCampana);
    setStr($h1, $col++, $row, (string)$d['codigo']);
    setNum($h1, $col++, $row, (int)$d['id_local']);
    setStr($h1, $col++, $row, (string)$d['local_nombre']);
    setStr($h1, $col++, $row, (string)$d['direccion']);
    setStr($h1, $col++, $row, (string)$d['comuna']);
    setStr($h1, $col++, $row, (string)$d['region']);
    setStr($h1, $col++, $row, (string)$d['cadena']);
    setStr($h1, $col++, $row, (string)$d['cuenta']);
    setStr($h1, $col++, $row, (string)$d['material']);
    setStr($h1, $col++, $row, (string)$d['categoria']);
    setStr($h1, $col++, $row, (string)$d['marca']);
    setStr($h1, $col++, $row, (string)$d['ejecutor']);
    setStr($h1, $col++, $row, $etapa !== '' ? ($ETAPA_LABEL[$etapa] ?? strtoupper($etapa)) : 'SIN INICIAR');
    setNum($h1, $col++, $row, $prop);
    setNum($h1, $col++, $row, $impl);
    setNum($h1, $col++, $row, $falt);
    setStr($h1, $col++, $row, $estado);
    setStr($h1, $col++, $row, (string)($d['fecha_visita'] ?? ''));
    setStr($h1, $col++, $row, (string)($d['fecha_propuesta'] ?? ''));
    setStr($h1, $col++, $row, (string)$d['observacion']);
    setStr($h1, $col++, $row, implode("\n", $apoyosPorFQ[$idFQ] ?? []));
    if ($incluirFotos) {
        setStr($h1, $col++, $row, implode("\n", $fotos['armado'] ?? []));
        setStr($h1, $col++, $row, implode("\n", $fotos['entregado'] ?? []));
        setStr($h1, $col++, $row, implode("\n", $fotos['implementado'] ?? []));
        setStr($h1, $col++, $row, implode("\n", $fotos['retirado'] ?? []));
    }
    $row++;
}
if ($row > 2) {
    $last = Coordinate::stringFromColumnIndex(count($headers1));
    estiloDatos($h1, "A2:{$last}" . ($row - 1));
    $h1->setAutoFilter("A1:{$last}" . ($row - 1));
}
$anchasH1 = [1 => 32, 5 => 40]; // CAMPAÑA, DIRECCIÓN
foreach (range(1, count($headers1)) as $c) { $h1->getColumnDimensionByColumn($c)->setWidth($anchasH1[$c] ?? ($c >= 22 ? 45 : 18)); }

/* === Hoja 2: Pendientes === */
$h2 = $ss->createSheet();
$h2->setTitle('Pendientes');
$headers2 = ['CÓDIGO', 'LOCAL', 'COMUNA', 'MATERIAL', 'CATEGORÍA', 'MARCA', 'EJECUTOR', 'ETAPA ACTUAL', 'PROPUESTO', 'IMPLEMENTADO', 'FALTANTE', 'ESTADO', 'FECHA PROPUESTA', 'DÍAS PENDIENTE'];
escribirCabeceras($h2, $headers2);
$row = 2;
$hoy = new DateTime('today');
foreach ($detalle as $d) {
    $etapa = (string)($d['etapa_material'] ?? '');
    $prop  = (int)$d['propuesto'];
    $impl  = (int)$d['implementado'];
    $completo = ($etapa === 'retirado') || ($etapa === 'implementado' && $impl >= $prop && $prop > 0);
    if ($completo) continue; // solo pendientes
    $falt = max(0, $prop - $impl);
    if ($etapa === 'implementado' && $impl < $prop) { $estado = 'PARCIAL'; }
    elseif ($etapa === 'armado' || $etapa === 'entregado') { $estado = 'EN PROCESO'; }
    else {
        $pregEstado = strtolower(trim((string)($d['pregunta'] ?? '')));
        if ($pregEstado === 'cancelado')      { $estado = 'CANCELADO'; }
        elseif ($pregEstado === 'en proceso') { $estado = 'PENDIENTE (VISITADO)'; }
        else                                  { $estado = 'SIN INICIAR'; }
    }

    $dias = '';
    if (!empty($d['fecha_propuesta'])) {
        $fp = DateTime::createFromFormat('Y-m-d', (string)$d['fecha_propuesta']);
        if ($fp) { $dias = (string)$hoy->diff($fp)->days; }
    }
    $col = 1;
    setStr($h2, $col++, $row, (string)$d['codigo']);
    setStr($h2, $col++, $row, (string)$d['local_nombre']);
    setStr($h2, $col++, $row, (string)$d['comuna']);
    setStr($h2, $col++, $row, (string)$d['material']);
    setStr($h2, $col++, $row, (string)$d['categoria']);
    setStr($h2, $col++, $row, (string)$d['marca']);
    setStr($h2, $col++, $row, (string)$d['ejecutor']);
    setStr($h2, $col++, $row, $etapa !== '' ? ($ETAPA_LABEL[$etapa] ?? strtoupper($etapa)) : 'SIN INICIAR');
    setNum($h2, $col++, $row, $prop);
    setNum($h2, $col++, $row, $impl);
    setNum($h2, $col++, $row, $falt);
    setStr($h2, $col++, $row, $estado);
    setStr($h2, $col++, $row, (string)($d['fecha_propuesta'] ?? ''));
    setStr($h2, $col++, $row, $dias);
    $row++;
}
if ($row > 2) {
    $last = Coordinate::stringFromColumnIndex(count($headers2));
    estiloDatos($h2, "A2:{$last}" . ($row - 1));
    $h2->setAutoFilter("A1:{$last}" . ($row - 1));
}
foreach (range(1, count($headers2)) as $c) { $h2->getColumnDimensionByColumn($c)->setWidth(18); }

/* === Hoja 3: Apoyos === */
$h3 = $ss->createSheet();
$h3->setTitle('Apoyos');
$headers3 = ['CÓDIGO', 'LOCAL', 'MATERIAL', 'ETAPA', 'EJECUTOR APOYO', 'USUARIO', 'FECHA'];
escribirCabeceras($h3, $headers3);
$row = 2;
$sqlAp = "
    SELECT l.codigo, UPPER(l.nombre) AS local_nombre, UPPER(COALESCE(fq.material,'')) AS material,
           ga.etapa, TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS nombre,
           u.usuario, DATE(ga.created_at) AS fecha
    FROM gestion_apoyo ga
    INNER JOIN formularioQuestion fq ON fq.id = ga.id_formularioQuestion
    INNER JOIN local l ON l.id = ga.id_local
    INNER JOIN usuario u ON u.id = ga.id_usuario_apoyo
    WHERE ga.id_formulario = ?
    ORDER BY l.codigo, ga.created_at
";
$stmt = $conn->prepare($sqlAp);
$stmt->bind_param('i', $idForm);
$stmt->execute();
$resAp = $stmt->get_result();
while ($a = $resAp->fetch_assoc()) {
    $et = (string)($a['etapa'] ?? '');
    $col = 1;
    setStr($h3, $col++, $row, (string)$a['codigo']);
    setStr($h3, $col++, $row, (string)$a['local_nombre']);
    setStr($h3, $col++, $row, (string)$a['material']);
    setStr($h3, $col++, $row, $et !== '' ? ($ETAPA_LABEL[$et] ?? strtoupper($et)) : '');
    setStr($h3, $col++, $row, trim((string)$a['nombre']) !== '' ? trim((string)$a['nombre']) : (string)$a['usuario']);
    setStr($h3, $col++, $row, (string)$a['usuario']);
    setStr($h3, $col++, $row, (string)($a['fecha'] ?? ''));
    $row++;
}
$stmt->close();
if ($row > 2) {
    $last = Coordinate::stringFromColumnIndex(count($headers3));
    estiloDatos($h3, "A2:{$last}" . ($row - 1));
    $h3->setAutoFilter("A1:{$last}" . ($row - 1));
}
foreach (range(1, count($headers3)) as $c) { $h3->getColumnDimensionByColumn($c)->setWidth(20); }

/* === Hoja 4: Historial (gestion_visita) === */
$h4 = $ss->createSheet();
$h4->setTitle('Historial');
$headers4 = ['CÓDIGO', 'LOCAL', 'MATERIAL', 'FECHA', 'ETAPA', 'CANTIDAD', 'MOTIVO PARCIAL', 'EJECUTOR', 'OBSERVACIÓN'];
escribirCabeceras($h4, $headers4);
$row = 2;
$sqlH = "
    SELECT l.codigo, UPPER(l.nombre) AS local_nombre, UPPER(COALESCE(fq.material,'')) AS material,
           gv.fecha_visita, gv.estado_gestion, gv.valor_real,
           UPPER(COALESCE(gv.motivo_no_implementacion,'')) AS motivo,
           UPPER(COALESCE(u.usuario,'')) AS ejecutor, UPPER(COALESCE(gv.observacion,'')) AS observacion
    FROM gestion_visita gv
    INNER JOIN local l ON l.id = gv.id_local
    LEFT  JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
    LEFT  JOIN usuario u ON u.id = gv.id_usuario
    WHERE gv.id_formulario = ?
    ORDER BY l.codigo, gv.fecha_visita, gv.id
";
$stmt = $conn->prepare($sqlH);
$stmt->bind_param('i', $idForm);
$stmt->execute();
$resH = $stmt->get_result();
while ($t = $resH->fetch_assoc()) {
    $et = (string)($t['estado_gestion'] ?? '');
    $col = 1;
    setStr($h4, $col++, $row, (string)$t['codigo']);
    setStr($h4, $col++, $row, (string)$t['local_nombre']);
    setStr($h4, $col++, $row, (string)$t['material']);
    setStr($h4, $col++, $row, (string)($t['fecha_visita'] ?? ''));
    setStr($h4, $col++, $row, $et !== '' ? ($ETAPA_LABEL[strtolower($et)] ?? strtoupper($et)) : '');
    setStr($h4, $col++, $row, (string)($t['valor_real'] ?? ''));
    setStr($h4, $col++, $row, (string)$t['motivo']);
    setStr($h4, $col++, $row, (string)$t['ejecutor']);
    setStr($h4, $col++, $row, (string)$t['observacion']);
    $row++;
}
$stmt->close();
if ($row > 2) {
    $last = Coordinate::stringFromColumnIndex(count($headers4));
    estiloDatos($h4, "A2:{$last}" . ($row - 1));
    $h4->setAutoFilter("A1:{$last}" . ($row - 1));
}
foreach (range(1, count($headers4)) as $c) { $h4->getColumnDimensionByColumn($c)->setWidth($c === 9 ? 40 : 18); }

$ss->setActiveSheetIndex(0);

while (ob_get_level() > 0) { ob_end_clean(); }
$archivo = 'Seguimiento_Etapas_' . limpiarNombreArchivo($nombreCampana) . '_' . date('Y-m-d_His') . '.xlsx';

// Señal para el overlay del dashboard (esperarDescargaReal espera la cookie fileDownloadToken).
$downloadToken = $_GET['download_token'] ?? '';
if ($downloadToken !== '') {
    setcookie('fileDownloadToken', $downloadToken, 0, '/');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($ss);
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');
$ss->disconnectWorksheets();
exit;

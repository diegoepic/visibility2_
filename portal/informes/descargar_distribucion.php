<?php
ob_clean();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '1024M');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

mysqli_set_charset($conn, 'utf8mb4');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function setCellByIndex($sheet, int $col, int $row, $value): void {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $value);
}

if (!isset($_SESSION['usuario_id'])) {
    exit('No autorizado');
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function limpiarNombreArchivo(string $texto): string {
    $texto = trim($texto);
    $texto = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $texto);
    $texto = preg_replace('/\s+/', '_', $texto);
    return $texto ?: 'archivo';
}

/* =====================================================
   1) VALIDAR PARÁMETROS
   ===================================================== */
$idCampana = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($idCampana <= 0) {
    exit('ID de campaña inválido');
}

$idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);
if ($idEmpresa <= 0) {
    exit('Empresa inválida en sesión');
}

/* =====================================================
   2) VALIDAR CAMPAÑA
   ===================================================== */
$sqlValida = "
    SELECT 
        f.id,
        f.nombre,
        f.modalidad
    FROM formulario f
    WHERE f.id = ?
      AND f.id_empresa = ?
    LIMIT 1
";

$stmtVal = $conn->prepare($sqlValida);
if (!$stmtVal) {
    exit('Error preparando validación: ' . $conn->error);
}

$stmtVal->bind_param("ii", $idCampana, $idEmpresa);
$stmtVal->execute();
$resVal = $stmtVal->get_result();

if (!$resVal || $resVal->num_rows === 0) {
    exit('Campaña no encontrada o sin permisos');
}

$campana = $resVal->fetch_assoc();
$stmtVal->close();

/* =====================================================
   3) QUERY DETALLE
   ===================================================== */
$sql = "
    SELECT
        f.id AS id_campana,
        f.modalidad AS modalidad,
        UPPER(f.nombre) AS nombre_campana,
        DATE(f.fechaInicio) AS fecha_inicio,
        DATE(f.fechaTermino) AS fecha_termino,
        DATE(fq.fechaPropuesta) AS fecha_propuesta,

        l.codigo AS codigo_local,
        CASE
            WHEN l.nombre REGEXP '^[0-9]+'
                THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
            ELSE CAST(l.codigo AS UNSIGNED)
        END AS numero_local,

        UPPER(cu.nombre) AS cuenta,
        UPPER(ca.nombre) AS cadena,
        UPPER(l.nombre) AS nombre_local,
        UPPER(l.direccion) AS direccion_local,
        UPPER(cm.comuna) AS comuna,
        UPPER(re.region) AS region,

        CASE
            WHEN UPPER(re.region) = '01 - TARAPACA' THEN 'PDQ IQUIQUE'
            WHEN UPPER(re.region) = '02 - ANTOFAGASTA' THEN 'PDQ ANTOFAGASTA'
            WHEN UPPER(re.region) = '03 - ATACAMA' THEN 'PDQ COQUIMBO'
            WHEN UPPER(re.region) = '04 - COQUIMBO' THEN 'PDQ COQUIMBO'
            WHEN UPPER(re.region) = '05 - VALPARAISO' THEN 'RETIRO MC'
            WHEN UPPER(re.region) = '06 - LIBERTADOR GENERAL BERNARDO OHIGGINS' THEN 'RETIRO MC'
            WHEN UPPER(re.region) = '07 - MAULE' THEN 'PDQ TALCA'
            WHEN UPPER(re.region) = '08 - BIOBIO' THEN 'PDQ CONCEPCION'
            WHEN UPPER(re.region) = '09 - LA ARAUCANÍA' THEN 'PDQ TEMUCO'
            WHEN UPPER(re.region) = '10 - LOS LAGOS' THEN 'PDQ PUERTO MONTT'
            WHEN UPPER(re.region) = '11 - AYSÉN DEL GENERAL CARLOS IBAÑEZ DEL CAMPO' THEN 'SIN COBERTURA'
            WHEN UPPER(re.region) = '12 - MAGALLANES Y DE LA ANTÁRTICA CHILENA' THEN 'PDQ PUNTA ARENAS'
            WHEN UPPER(re.region) = '13 - METROPOLITANA DE SANTIAGO' THEN 'RETIRO MC'
            WHEN UPPER(re.region) = '14 - LOS RIOS' THEN 'PDQ TEMUCO'
            WHEN UPPER(re.region) = '15 - ARICA Y PARINACOTA' THEN 'PDQ IQUIQUE'
            WHEN UPPER(re.region) = '16 - ÑUBLE' THEN 'PDQ CONCEPCION'
            ELSE 'SIN DEFINIR'
        END AS tipo_retiro,

        UPPER(COALESCE(u.usuario, '')) AS usuario,
        UPPER(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS nombre_operador,
        COALESCE(u.telefono, '') AS contacto,

        UPPER(TRIM(fq.material)) AS material,
        COALESCE(fq.valor_propuesto, 0) AS cantidad_material

    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local l ON l.id = fq.id_local
    INNER JOIN usuario u ON u.id = fq.id_usuario
    INNER JOIN cuenta cu ON cu.id = l.id_cuenta
    INNER JOIN cadena ca ON ca.id = l.id_cadena
    INNER JOIN comuna cm ON cm.id = l.id_comuna
    INNER JOIN region re ON re.id = cm.id_region

    WHERE f.id = ?
      AND COALESCE(fq.valor_propuesto, 0) > 0
      AND COALESCE(fq.material, '') <> ''
      AND fq.fechaPropuesta IS NOT NULL

    ORDER BY
        tipo_retiro,
        nombre_operador,
        l.codigo,
        fq.fechaPropuesta
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    exit('Error preparando consulta: ' . $conn->error);
}

$stmt->bind_param("i", $idCampana);
$stmt->execute();
$res = $stmt->get_result();

$detalleRows = [];
while ($row = $res->fetch_assoc()) {
    $detalleRows[] = $row;
}
$stmt->close();

/* =====================================================
   4) ARMAR RESUMEN PIVOTE
   ===================================================== */
$materiales = [];
$resumen = [];

foreach ($detalleRows as $r) {
    $tipoRetiro     = trim((string)$r['tipo_retiro']);
    $nombreOperador = trim((string)$r['nombre_operador']);
    $contacto       = trim((string)$r['contacto']);
    $material       = trim((string)$r['material']);
    $cantidad       = (float)$r['cantidad_material'];

    if ($material === '') {
        continue;
    }

    $materiales[$material] = $material;

    $key = mb_strtoupper($tipoRetiro . '||' . $nombreOperador . '||' . $contacto, 'UTF-8');

    if (!isset($resumen[$key])) {
        $resumen[$key] = [
            'tipo_retiro'     => $tipoRetiro,
            'nombre_operador' => $nombreOperador,
            'contacto'        => $contacto,
            'materiales'      => []
        ];
    }

    if (!isset($resumen[$key]['materiales'][$material])) {
        $resumen[$key]['materiales'][$material] = 0;
    }

    $resumen[$key]['materiales'][$material] += $cantidad;
}

/* Ordenar materiales alfabéticamente */
$materiales = array_values($materiales);
sort($materiales, SORT_NATURAL | SORT_FLAG_CASE);

/* Ordenar resumen por tipo_retiro y nombre */
usort($resumen, function ($a, $b) {
    $cmp1 = strcasecmp($a['tipo_retiro'], $b['tipo_retiro']);
    if ($cmp1 !== 0) return $cmp1;

    $cmp2 = strcasecmp($a['nombre_operador'], $b['nombre_operador']);
    if ($cmp2 !== 0) return $cmp2;

    return strcasecmp($a['contacto'], $b['contacto']);
});

/* =====================================================
   5) CREAR EXCEL
   ===================================================== */
$spreadsheet = new Spreadsheet();

/* =====================================================
   6) ESTILOS BASE
   ===================================================== */
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F4E78']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'D9D9D9']
        ]
    ]
];

$dataStyle = [
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'E5E5E5']
        ]
    ]
];

/* =====================================================
   7) HOJA RESUMEN
   ===================================================== */
$sheetResumen = $spreadsheet->getActiveSheet();
$sheetResumen->setTitle('Resumen');

$fila = 1;

/* Título */
$sheetResumen->setCellValue("A{$fila}", 'RESUMEN DISTRIBUCIÓN');
$sheetResumen->mergeCells("A{$fila}:D{$fila}");
$sheetResumen->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(14);
$fila += 2;

/* Cabecera fija */
$col = 1;
setCellByIndex($sheetResumen, $col++, $fila, 'TIPO DE RETIRO');
setCellByIndex($sheetResumen, $col++, $fila, 'NOMBRE');
setCellByIndex($sheetResumen, $col++, $fila, 'CONTACTO');

foreach ($materiales as $mat) {
    setCellByIndex($sheetResumen, $col++, $fila, $mat);
}

/* Estilo cabecera */
$ultimaColResumen = $sheetResumen->getHighestColumn();
$sheetResumen->getStyle("A{$fila}:{$ultimaColResumen}{$fila}")->applyFromArray($headerStyle);
$sheetResumen->getRowDimension($fila)->setRowHeight(22);

$fila++;

/* Datos resumen */
foreach ($resumen as $item) {
    $col = 1;
    setCellByIndex($sheetResumen, $col++, $fila, $item['tipo_retiro']);
    setCellByIndex($sheetResumen, $col++, $fila, $item['nombre_operador']);
    setCellByIndex($sheetResumen, $col++, $fila, $item['contacto']);

    foreach ($materiales as $mat) {
        $valor = $item['materiales'][$mat] ?? 0;
        setCellByIndex($sheetResumen, $col++, $fila, $valor);
    }

    $fila++;
}

/* Estilos y autofiltro */
if ($fila > 4) {
    $sheetResumen->getStyle("A3:{$ultimaColResumen}" . ($fila - 1))->applyFromArray($dataStyle);
    $sheetResumen->setAutoFilter("A3:{$ultimaColResumen}" . ($fila - 1));
}

/* Congelar panel */
$sheetResumen->freezePane('A4');

/* Ajuste de ancho */
for ($i = 1; $i <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ultimaColResumen); $i++) {
    $sheetResumen->getColumnDimensionByColumn($i)->setAutoSize(true);
}

/* =====================================================
   8) HOJA DETALLE
   ===================================================== */
$sheetDetalle = $spreadsheet->createSheet();
$sheetDetalle->setTitle('Detalle');

$fila = 1;

$sheetDetalle->setCellValue("A{$fila}", 'DETALLE DISTRIBUCIÓN');
$sheetDetalle->mergeCells("A{$fila}:F{$fila}");
$sheetDetalle->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(14);
$fila += 2;

$headersDetalle = [
    'ID CAMPAÑA',
    'MODALIDAD',
    'NOMBRE CAMPAÑA',
    'FECHA INICIO',
    'FECHA TÉRMINO',
    'FECHA PROPUESTA',
    'CÓDIGO LOCAL',
    'NÚMERO LOCAL',
    'CUENTA',
    'CADENA',
    'NOMBRE LOCAL',
    'DIRECCIÓN LOCAL',
    'COMUNA',
    'REGIÓN',
    'TIPO RETIRO',
    'USUARIO',
    'NOMBRE OPERADOR',
    'CONTACTO',
    'MATERIAL',
    'CANTIDAD MATERIAL'
];

$col = 1;
foreach ($headersDetalle as $header) {
    setCellByIndex($sheetDetalle, $col++, $fila, $header);
}

$ultimaColDetalle = $sheetDetalle->getHighestColumn();
$sheetDetalle->getStyle("A{$fila}:{$ultimaColDetalle}{$fila}")->applyFromArray($headerStyle);
$sheetDetalle->getRowDimension($fila)->setRowHeight(22);

$fila++;

/* Datos detalle */
foreach ($detalleRows as $r) {
    $col = 1;
    setCellByIndex($sheetDetalle, $col++, $fila, $r['id_campana']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['modalidad']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['nombre_campana']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['fecha_inicio']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['fecha_termino']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['fecha_propuesta']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['codigo_local']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['numero_local']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['cuenta']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['cadena']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['nombre_local']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['direccion_local']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['comuna']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['region']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['tipo_retiro']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['usuario']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['nombre_operador']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['contacto']);
    setCellByIndex($sheetDetalle, $col++, $fila, $r['material']);
    setCellByIndex($sheetDetalle, $col++, $fila, (float)$r['cantidad_material']);
    $fila++;
}

if ($fila > 4) {
    $sheetDetalle->getStyle("A3:{$ultimaColDetalle}" . ($fila - 1))->applyFromArray($dataStyle);
    $sheetDetalle->setAutoFilter("A3:{$ultimaColDetalle}" . ($fila - 1));
}

$sheetDetalle->freezePane('A4');

for ($i = 1; $i <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ultimaColDetalle); $i++) {
    $sheetDetalle->getColumnDimensionByColumn($i)->setAutoSize(true);
}

/* =====================================================
   9) HOJA ACTIVA INICIAL
   ===================================================== */
$spreadsheet->setActiveSheetIndex(0);

/* =====================================================
   10) DESCARGA
   ===================================================== */
$nombreCampana = limpiarNombreArchivo($campana['nombre'] ?? 'campana');
$fechaArchivo = date('Ymd_His');
$nombreArchivo = "distribucion_{$idCampana}_{$nombreCampana}_{$fechaArchivo}.xlsx";

if (ob_get_length()) {
    ob_end_clean();
}

$downloadToken = $_GET['download_token'] ?? '';
if ($downloadToken !== '') {
    setcookie('fileDownloadToken', $downloadToken, 0, '/');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
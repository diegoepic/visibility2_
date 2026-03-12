<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
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

function fail(string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    exit($message);
}

function limpiarNombreArchivo(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return 'reporte_detalle';
    }

    $texto = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $texto);
    $texto = preg_replace('/\s+/u', '_', $texto);

    return $texto !== '' ? $texto : 'reporte_detalle';
}

function normalizarBooleanGet(string $key, bool $default = true): bool
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    $value = strtolower(trim((string)$_GET[$key]));
    return in_array($value, ['1', 'true', 'si', 'sí', 'yes'], true);
}

function setCellByIndex(Worksheet $sheet, int $col, int $row, $value): void
{
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $value);
}

function setStringByIndex(Worksheet $sheet, int $col, int $row, string $value): void
{
    $sheet->setCellValueExplicit(
        Coordinate::stringFromColumnIndex($col) . $row,
        $value,
        DataType::TYPE_STRING
    );
}

function obtenerFormularioBase(mysqli $conn, int $idForm): array
{
    $sql = "
        SELECT id, nombre, modalidad
        FROM formulario
        WHERE id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando formulario: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        fail('Formulario no encontrado.');
    }

    return $row;
}

function getCampaignData(mysqli $conn, int $idForm): array
{
    $sql = "
        SELECT
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            f.modalidad,
            f.tipo,
            e.nombre AS nombre_empresa,
            de.nombre AS nombre_division,
            COUNT(DISTINCT l.codigo) AS locales_programados,
            COUNT(DISTINCT CASE
                WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria',
                    'local_cerrado','no_permitieron'
                )
                AND fq.fechaVisita IS NOT NULL
                AND fq.fechaVisita <> '0000-00-00 00:00:00'
                THEN l.id
            END) AS locales_visitados,
            COUNT(DISTINCT CASE
                WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria'
                )
                THEN l.id
            END) AS locales_implementados
        FROM formulario f
        INNER JOIN empresa e             ON e.id = f.id_empresa
        LEFT JOIN division_empresa de    ON de.id = f.id_division
        INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
        INNER JOIN local l               ON l.id = fq.id_local
        WHERE f.id = ?
        GROUP BY
            f.id, f.nombre, f.fechaInicio, f.fechaTermino,
            f.modalidad, f.tipo, e.nombre, de.nombre
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getCampaignData: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function getLocalesDetails(mysqli $conn, int $idForm): array
{
    $sql = "
        SELECT
            l.id AS id_local,
            fq.id AS id_formulario_question,
            l.codigo AS codigo_local,
            SUBSTRING_INDEX(TRIM(l.nombre), ' ', 1) AS numero_local,
            f.modalidad AS modalidad,
            UPPER(f.nombre) AS nombre_campana,
            DATE(f.fechaInicio) AS fecha_inicio,
            DATE(f.fechaTermino) AS fecha_termino,
            DATE(fq.fechaVisita) AS fecha_visita,
            TIME(fq.fechaVisita) AS hora,
            DATE(fq.fechaPropuesta) AS fecha_propuesta,
            UPPER(l.nombre) AS nombre_local,
            UPPER(l.direccion) AS direccion_local,
            UPPER(cm.comuna) AS comuna,
            UPPER(re.region) AS region,
            UPPER(cu.nombre) AS cuenta,
            UPPER(ca.nombre) AS cadena,
            UPPER(COALESCE(fq.material, ''))  AS material,
            UPPER(COALESCE(fq.categoria, '')) AS categoria,
            UPPER(COALESCE(fq.marca, ''))     AS marca,            
            UPPER(COALESCE(jv.nombre, '')) AS jefe_venta,
            COALESCE(fq.valor_propuesto, 0) AS valor_propuesto,
            COALESCE(fq.valor, 0) AS valor,
            UPPER(COALESCE(fq.observacion, '')) AS observacion,
            CASE
                WHEN fq.fechaVisita IS NOT NULL
                     AND fq.fechaVisita <> '0000-00-00 00:00:00'
                    THEN 'VISITADO'
                ELSE 'NO VISITADO'
            END AS estado_visita,
            CASE
                WHEN f.modalidad = 'retiro' THEN
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'RETIRADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO RETIRADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'RETIRADO'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'RETIRADO'
                        ELSE 'NO RETIRADO'
                    END
                WHEN f.modalidad = 'entrega' THEN
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'ENTREGADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO ENTREGADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'ENTREGADO'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'ENTREGADO'
                        ELSE 'NO ENTREGADO'
                    END
                ELSE
                    CASE
                        WHEN IFNULL(fq.valor, 0) >= 1 THEN 'IMPLEMENTADO'
                        WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
                        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'IMPLEMENTADO'
                        WHEN LOWER(fq.pregunta) IN ('solo_auditado', 'solo_auditoria') THEN 'AUDITORIA'
                        WHEN LOWER(fq.pregunta) = 'retiro' THEN 'RETIRO'
                        WHEN LOWER(fq.pregunta) = 'entrega' THEN 'ENTREGA'
                        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
                        ELSE 'NO IMPLEMENTADO'
                    END
            END AS estado_actividad,
            UPPER(
                REPLACE(
                    CASE
                        WHEN IFNULL(fq.valor, 0) = 0 THEN
                            TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(fq.observacion, ''), '|', '-'), '-', 1))
                        WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                            TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(fq.observacion, ''), '|', '-'), '-', 1))
                        WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN ''
                        ELSE COALESCE(fq.pregunta, '')
                    END,
                    '_',
                    ' '
                )
            ) AS motivo,
            UPPER(u.usuario) AS gestionado_por
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN local l ON l.id = fq.id_local
        LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
        INNER JOIN usuario u ON u.id = fq.id_usuario
        INNER JOIN cuenta cu ON cu.id = l.id_cuenta
        INNER JOIN cadena ca ON ca.id = l.id_cadena
        INNER JOIN comuna cm ON cm.id = l.id_comuna
        INNER JOIN region re ON re.id = cm.id_region
        WHERE f.id = ?
        ORDER BY l.codigo ASC, fq.fechaVisita ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getLocalesDetails: ' . $conn->error);
    }

    $stmt->bind_param('i', $idForm);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function getFotosImplementaciones(mysqli $conn, int $idForm, array $fqIds): array
{
    $normalizarFoto = static function (string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $url = ltrim(str_replace('\\', '/', $url), '/');
        return 'https://www.visibility.cl/visibility2/app/' . $url;
    };

    $fqIds = array_values(array_filter(array_unique(array_map('intval', $fqIds)), static fn($v) => $v > 0));
    if (empty($fqIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($fqIds), '?'));
    $types = 'i' . str_repeat('i', count($fqIds));

    $sql = "
        SELECT id_formularioQuestion, url
        FROM fotoVisita
        WHERE id_formulario = ?
          AND id_formularioQuestion IN ($placeholders)
        ORDER BY id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fail('Error preparando getFotosImplementaciones: ' . $conn->error);
    }

    $params = array_merge([$types], [$idForm], $fqIds);
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $fqId = (int)$row['id_formularioQuestion'];
        $out[$fqId][] = $normalizarFoto((string)$row['url']);
    }

    $stmt->close();
    return $out;
}

function obtenerEtiquetasPorModalidad(string $modalidad): array
{
    $modalidadLower = strtolower(trim($modalidad));

    if ($modalidadLower === 'retiro') {
        return ['MATERIAL RETIRADO', 'CANTIDAD MATERIAL RETIRADO'];
    }

    if ($modalidadLower === 'entrega') {
        return ['MATERIAL ENTREGADO', 'CANTIDAD MATERIAL ENTREGADO'];
    }

    return ['MATERIAL', 'CANTIDAD MATERIAL EJECUTADO'];
}

function aplicarEstiloCabecera(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 11,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1F4E78'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);
}

function aplicarEstiloDatos(Worksheet $sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'alignment' => [
            'vertical' => Alignment::VERTICAL_TOP,
            'wrapText' => true,
        ],
    ]);
}

function setFixedWidthsDetalleLocales(Worksheet $sheet, int $maxFotosLocales): void
{
        $widths = [
            1  => 12, // ID LOCAL
            2  => 35, // CAMPAÑA
            3  => 20, // CUENTA
            4  => 20, // CADENA
            5  => 14, // CODIGO
            6  => 12, // N° LOCAL
            7  => 30, // LOCAL
            8  => 35, // DIRECCION
            9  => 18, // COMUNA
            10 => 18, // REGION
            11 => 22, // JEFE VENTA
            12 => 14, // FECHA INICIO
            13 => 14, // FECHA TÉRMINO
            14 => 16, // FECHA PLANIFICADA
            15 => 14, // FECHA VISITA
            16 => 12, // HORA
            17 => 20, // USUARIO
            18 => 16, // ESTADO VISITA
            19 => 22, // ESTADO ACTIVIDAD
            20 => 25, // MOTIVO
            21 => 28, // MATERIAL
            22 => 22, // CATEGORIA
            23 => 22, // MARCA
            24 => 18, // CANTIDAD MATERIAL EJECUTADO
            25 => 18, // MATERIAL PROPUESTO
            26 => 35, // OBSERVACION
        ];
        
        for ($i = 0; $i < $maxFotosLocales; $i++) {
            $widths[27 + $i] = 40;
        }

    foreach ($widths as $col => $width) {
        $sheet->getColumnDimensionByColumn($col)->setWidth($width);
    }
}

function escribirTextoPlano(Worksheet $sheet, int $col, int $row, string $value): void
{
    $cell = Coordinate::stringFromColumnIndex($col) . $row;
    $sheet->setCellValueExplicit($cell, trim($value), DataType::TYPE_STRING);
}

function generarExcelDetalle(
    array $campaign,
    array $locales,
    string $archivo,
    array $fotosLocales,
    int $maxFotosLocales,
    string $modalidad,
    bool $incluirAutoFiltro = true,
    bool $congelarCabeceras = false
): void {
    [$etiquetaMaterial, $etiquetaCantidad] = obtenerEtiquetasPorModalidad($modalidad);

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Visibility')
        ->setLastModifiedBy('Visibility')
        ->setTitle('Reporte detalle campaña')
        ->setSubject('Exportación detalle campaña')
        ->setDescription('Exportación de detalle generada con PhpSpreadsheet');

    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Detalle Locales');

    $row = 1;

    $headersLocales = [
        'ID LOCAL',
        'CAMPAÑA',
        'CUENTA',
        'CADENA',
        'CODIGO',
        'N° LOCAL',
        'LOCAL',
        'DIRECCION',
        'COMUNA',
        'REGION',
        'JEFE VENTA',
        'FECHA INICIO',
        'FECHA TÉRMINO',
        'FECHA PLANIFICADA',
        'FECHA VISITA',
        'HORA',
        'USUARIO',
        'ESTADO VISITA',
        'ESTADO ACTIVIDAD',
        'MOTIVO',
        $etiquetaMaterial,
        'CATEGORIA',
        'MARCA',
        $etiquetaCantidad,
        'MATERIAL PROPUESTO',
        'OBSERVACION',
    ];

    for ($i = 1; $i <= $maxFotosLocales; $i++) {
        $headersLocales[] = 'FOTO ' . $i;
    }

    $headerRow1 = $row;
    $col = 1;
    foreach ($headersLocales as $header) {
        setCellByIndex($sheet1, $col++, $row, $header);
    }

    $lastCol1 = Coordinate::stringFromColumnIndex(count($headersLocales));
    aplicarEstiloCabecera($sheet1, "A{$headerRow1}:{$lastCol1}{$headerRow1}");
    $sheet1->getRowDimension($headerRow1)->setRowHeight(24);
    $row++;

    foreach ($locales as $l) {
        $col = 1;

        $numeroLocal = (string)($l['numero_local'] ?? '');
        if ($numeroLocal === '') {
            $numeroLocal = preg_replace('/\D+/', '', (string)($l['codigo_local'] ?? ''));
        }

        setStringByIndex($sheet1, $col++, $row, (string)($l['id_local'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['nombre_campana'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['cuenta'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['cadena'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['codigo_local'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, $numeroLocal);
        setStringByIndex($sheet1, $col++, $row, (string)($l['nombre_local'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['direccion_local'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['comuna'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['region'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['jefe_venta'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['fecha_inicio'] ?? '-'));
        setStringByIndex($sheet1, $col++, $row, (string)($l['fecha_termino'] ?? '-'));
        setStringByIndex($sheet1, $col++, $row, (string)($l['fecha_propuesta'] ?? '-'));
        setStringByIndex($sheet1, $col++, $row, (string)($l['fecha_visita'] ?? '-'));
        setStringByIndex($sheet1, $col++, $row, (string)($l['hora'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['gestionado_por'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['estado_visita'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['estado_actividad'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['motivo'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['material'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['categoria'] ?? ''));
        setStringByIndex($sheet1, $col++, $row, (string)($l['marca'] ?? ''));
        setCellByIndex($sheet1, $col++, $row, (float)($l['valor'] ?? 0));
        setCellByIndex($sheet1, $col++, $row, (float)($l['valor_propuesto'] ?? 0));
        setStringByIndex($sheet1, $col++, $row, (string)($l['observacion'] ?? ''));

        $fotos = [];
        $fqId = (int)($l['id_formulario_question'] ?? 0);
        if ($fqId > 0 && isset($fotosLocales[$fqId])) {
            $fotos = $fotosLocales[$fqId];
        }

        for ($fi = 0; $fi < $maxFotosLocales; $fi++) {
            $url = trim((string)($fotos[$fi] ?? ''));
            escribirTextoPlano($sheet1, $col++, $row, $url);
        }

        $row++;
    }

    if ($row > ($headerRow1 + 1)) {
        aplicarEstiloDatos($sheet1, "A" . ($headerRow1 + 1) . ":{$lastCol1}" . ($row - 1));

        if ($incluirAutoFiltro) {
            $sheet1->setAutoFilter("A{$headerRow1}:{$lastCol1}" . ($row - 1));
        }

        if ($congelarCabeceras) {
            $sheet1->freezePane('A' . ($headerRow1 + 1));
        }
    }

    setFixedWidthsDetalleLocales($sheet1, $maxFotosLocales);
    $spreadsheet->setActiveSheetIndex(0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $downloadToken = $_GET['download_token'] ?? '';
    if ($downloadToken !== '') {
        setcookie('fileDownloadToken', $downloadToken, 0, '/');
    }    

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: max-age=0');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    unset($writer, $spreadsheet, $campaign, $locales, $fotosLocales);

    exit;
}

$formularioId = isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)
    ? (int)$_GET['id']
    : 0;

if ($formularioId <= 0) {
    fail('ID de formulario inválido.');
}

$incluirFotosMaterial = normalizarBooleanGet('fotos', true);

$formularioBase = obtenerFormularioBase($conn, $formularioId);
$nombreForm = (string)$formularioBase['nombre'];
$modalidad = (string)$formularioBase['modalidad'];

$campaignData = getCampaignData($conn, $formularioId);
$localesDetails = getLocalesDetails($conn, $formularioId);

$fotosLocales = [];
$maxFotosLocales = 0;

if ($incluirFotosMaterial && !empty($localesDetails)) {
    $fqIds = array_column($localesDetails, 'id_formulario_question');
    $fotosLocales = getFotosImplementaciones($conn, $formularioId, $fqIds);

    foreach ($fotosLocales as $lista) {
        $maxFotosLocales = max($maxFotosLocales, count($lista));
    }
}

if (empty($campaignData) && empty($localesDetails)) {
    fail('No se encontraron datos de detalle para la campaña.');
}

$nombreCampana = limpiarNombreArchivo($nombreForm);
$archivo = 'Detalle_' . $nombreCampana . '_' . date('Y-m-d_His') . '.xlsx';

generarExcelDetalle(
    $campaignData,
    $localesDetails,
    $archivo,
    $fotosLocales,
    $maxFotosLocales,
    $modalidad,
    true,
    false
);
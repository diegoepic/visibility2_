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

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

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
        return 'reporte';
    }

    $texto = preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $texto);
    $texto = preg_replace('/\s+/u', '_', $texto);

    return $texto !== '' ? $texto : 'reporte';
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

function normalizarBooleanGet(string $key, bool $default = true): bool
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    $value = strtolower(trim((string)$_GET[$key]));
    return in_array($value, ['1', 'true', 'si', 'sí', 'yes'], true);
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
                WHEN (
                    fq.pregunta IN (
                        'implementado_auditado','solo_implementado','solo_auditoria',
                        'local_cerrado','no_permitieron'
                    )
                    OR (f.modalidad = 'implementacion_por_etapas' AND fq.etapa_material IS NOT NULL)
                )
                AND fq.fechaVisita IS NOT NULL
                AND fq.fechaVisita <> '0000-00-00 00:00:00'
                THEN l.id
            END) AS locales_visitados,
            COUNT(DISTINCT CASE
                WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria'
                )
                OR (f.modalidad = 'implementacion_por_etapas' AND fq.etapa_material IN ('implementado','retirado'))
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
            f.id AS id_campana,
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
            UPPER(COALESCE(fq.material, '')) AS material,
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
                WHEN f.modalidad = 'implementacion_por_etapas' THEN
                    CASE fq.etapa_material
                        WHEN 'implementado' THEN 'IMPLEMENTADO'
                        WHEN 'retirado'     THEN 'RETIRADO'
                        WHEN 'entregado'    THEN 'ENTREGADO'
                        WHEN 'armado'       THEN 'ARMADO'
                        ELSE 'SIN INICIAR'
                    END
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

function normalizarUrlEncuesta(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (
        !preg_match('#(uploads|visibility2|app/|pregunta_)\b#i', $url)
        && !preg_match('#\.(webp|jpe?g|png|gif)(?:\?.*)?$#i', $url)
    ) {
        return $url;
    }

    $url = str_replace('\\', '/', $url);
    $url = ltrim($url, '/');
    $baseUrl = 'https://www.visibility.cl/';

    if (preg_match('#^visibility2/#i', $url)) {
        return $baseUrl . $url;
    }
    if (preg_match('#^app/#i', $url)) {
        return $baseUrl . 'visibility2/' . $url;
    }
    if (preg_match('#^uploads_fotos_pregunta/#i', $url)) {
        return $baseUrl . 'visibility2/app/uploads/' . $url;
    }
    if (preg_match('#^uploads/#i', $url)) {
        return $baseUrl . 'visibility2/app/' . $url;
    }

    return $baseUrl . 'visibility2/app/' . $url;
}

function dividirRespuestaEncuesta(string $valor): array
{
    $valor = trim($valor);

    if ($valor === '') {
        return [''];
    }

    $partes = preg_split('/\s*;\s*/', $valor);
    $partes = array_values(array_filter(array_map('trim', $partes), static function ($v) {
        return $v !== '';
    }));

    return !empty($partes) ? $partes : [''];
}

function expandirEncuestaMultiplesRespuestas(array $encuesta): array
{
    if (empty($encuesta)) {
        return [];
    }

    $columnasBase = [
        'ID CAMPAÑA',
        'NOMBRE CAMPAÑA',
        'CUENTA',
        'CADENA',
        'CODIGO LOCAL',
        'N° LOCAL',
        'LOCAL',
        'DIRECCION',
        'COMUNA',
        'REGION',
        'USUARIO',
        'FECHA VISITA'
    ];

    $salida = [];

    foreach ($encuesta as $fila) {
        $partesPorColumna = [];
        $maxFilas = 1;

        foreach ($fila as $columna => $valor) {
            if (in_array((string)$columna, $columnasBase, true)) {
                continue;
            }

            $partes = dividirRespuestaEncuesta((string)($valor ?? ''));
            $partesPorColumna[$columna] = $partes;

            if (count($partes) > $maxFilas) {
                $maxFilas = count($partes);
            }
        }

        for ($i = 0; $i < $maxFilas; $i++) {
            $nuevaFila = $fila;

            foreach ($partesPorColumna as $columna => $partes) {
                if (count($partes) === 1) {
                    $nuevaFila[$columna] = $partes[0];
                } else {
                    $nuevaFila[$columna] = $partes[$i] ?? '';
                }
            }

            $salida[] = $nuevaFila;
        }
    }

    return $salida;
}

function removerFotosDeEncuesta(array $encuesta): array
{
    $esUrlFoto = static function (string $valor): bool {
        $v = trim($valor);
        if ($v === '') {
            return false;
        }

        return preg_match('#https?://#i', $v)
            || preg_match('#\.(?:jpe?g|png|gif|webp)(?:\?.*)?$#i', $v)
            || preg_match('#\buploads#i', $v)
            || preg_match('#\bvisibility2\b#i', $v);
    };

    foreach ($encuesta as &$fila) {
        foreach ($fila as $col => $valor) {
            $valorStr = (string)($valor ?? '');
            if ($valorStr === '') {
                continue;
            }

            $partsFiltradas = [];
            foreach (preg_split('/\s*;\s*/', $valorStr) as $parte) {
                if ($parte === '') {
                    continue;
                }
                if ($esUrlFoto($parte)) {
                    continue;
                }
                $partsFiltradas[] = $parte;
            }

            $fila[$col] = $partsFiltradas ? implode('; ', $partsFiltradas) : '';
        }
    }
    unset($fila);

    return $encuesta;
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
        1  => 12, // ID CAMPAÑA
        2  => 12, // ID LOCAL
        3  => 35, // CAMPAÑA
        4  => 20, // CUENTA
        5  => 20, // CADENA
        6  => 14, // CODIGO
        7  => 12, // N° LOCAL
        8  => 30, // LOCAL
        9  => 35, // DIRECCION
        10 => 18, // COMUNA
        11 => 18, // REGION
        12 => 22, // JEFE VENTA
        13 => 14, // FECHA INICIO
        14 => 14, // FECHA TÉRMINO
        15 => 16, // FECHA PLANIFICADA
        16 => 14, // FECHA VISITA
        17 => 12, // HORA
        18 => 20, // USUARIO
        19 => 16, // ESTADO VISITA
        20 => 20, // ESTADO ACTIVIDAD
        21 => 25, // MOTIVO
        22 => 25, // MATERIAL
        23 => 12, // CANTIDAD
        24 => 18, // MATERIAL PROPUESTO
        25 => 35, // OBSERVACION
    ];

    for ($i = 0; $i < $maxFotosLocales; $i++) {
        $widths[26 + $i] = 40;
    }

    foreach ($widths as $col => $width) {
        $sheet->getColumnDimensionByColumn($col)->setWidth($width);
    }
}

function setFixedWidthsEncuesta(Worksheet $sheet, array $headers): void
{
    foreach ($headers as $i => $header) {
        $col = $i + 1;
        $headerUpper = mb_strtoupper((string)$header, 'UTF-8');
        $width = 20;

        if (in_array($headerUpper, ['ID CAMPAÑA', 'CODIGO LOCAL', 'N° LOCAL'], true)) {
            $width = 14;
        } elseif (in_array($headerUpper, ['NOMBRE CAMPAÑA', 'LOCAL', 'DIRECCION'], true)) {
            $width = 32;
        } elseif (in_array($headerUpper, ['CUENTA', 'CADENA', 'COMUNA', 'REGION', 'USUARIO'], true)) {
            $width = 20;
        } elseif ($headerUpper === 'FECHA VISITA') {
            $width = 14;
        } elseif (str_ends_with($headerUpper, '_VALOR')) {
            $width = 14;
        } else {
            $width = 28;
        }

        $sheet->getColumnDimensionByColumn($col)->setWidth($width);
    }
}

function escribirTextoPlano(Worksheet $sheet, int $col, int $row, string $value): void
{
    $cell = Coordinate::stringFromColumnIndex($col) . $row;
    $sheet->setCellValueExplicit($cell, trim($value), DataType::TYPE_STRING);
}

function escribirMultiplesUrlsComoTexto(Worksheet $sheet, int $col, int $row, string $value): void
{
    $parts = array_filter(array_map('trim', preg_split('/\s*;\s*/', $value)));
    $normalized = [];

    foreach ($parts as $part) {
        $normalized[] = normalizarUrlEncuesta($part);
    }

    $texto = implode('; ', $normalized);
    escribirTextoPlano($sheet, $col, $row, $texto);
}

function generarExcelPhpSpreadsheet(
    array $campaign,
    array $locales,
    array $encuesta,
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
        ->setTitle('Reporte campaña')
        ->setSubject('Exportación campaña')
        ->setDescription('Exportación generada con PhpSpreadsheet');

    // =========================
    // Hoja 1: Detalle Locales
    // =========================
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Detalle Locales');

    $row = 1;

    $headersLocales = [
        'ID CAMPAÑA',
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

        setStringByIndex($sheet1, $col++, $row, (string)($l['id_campana'] ?? ''));
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

    // =========================
    // Hoja 2: Encuesta
    // =========================
    if (!empty($encuesta)) {
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Encuesta');

        $row = 1;
        $headersEncuesta = array_keys($encuesta[0]);

        $headerRow2 = $row;
        $col = 1;
        foreach ($headersEncuesta as $header) {
            setCellByIndex($sheet2, $col++, $row, $header);
        }

        $lastCol2 = Coordinate::stringFromColumnIndex(count($headersEncuesta));
        aplicarEstiloCabecera($sheet2, "A{$headerRow2}:{$lastCol2}{$headerRow2}");
        $sheet2->getRowDimension($headerRow2)->setRowHeight(24);
        $row++;

        foreach ($encuesta as $fila) {
            $col = 1;
            foreach ($headersEncuesta as $header) {
                $valor = (string)($fila[$header] ?? '');

                if (preg_match('#https?://#i', $valor) || strpos($valor, ';') !== false) {
                    escribirMultiplesUrlsComoTexto($sheet2, $col++, $row, $valor);
                } else {
                    setStringByIndex($sheet2, $col++, $row, $valor);
                }
            }
            $row++;
        }

        if ($row > ($headerRow2 + 1)) {
            aplicarEstiloDatos($sheet2, "A" . ($headerRow2 + 1) . ":{$lastCol2}" . ($row - 1));

            if ($incluirAutoFiltro) {
                $sheet2->setAutoFilter("A{$headerRow2}:{$lastCol2}" . ($row - 1));
            }

            if ($congelarCabeceras) {
                $sheet2->freezePane('A' . ($headerRow2 + 1));
            }
        }

        setFixedWidthsEncuesta($sheet2, $headersEncuesta);
    }

    $spreadsheet->setActiveSheetIndex(0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!empty($_GET['download_token'])) {
        setcookie('download_token', (string)$_GET['download_token'], [
            'expires' => time() + 300,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
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
    unset($writer, $spreadsheet, $campaign, $locales, $encuesta, $fotosLocales);

    exit;
}

// =========================
// Punto de entrada
// =========================
$formularioIdRaw = $_GET['id']
    ?? $_GET['id_campana']
    ?? $_GET['id_formulario']
    ?? $_GET['formulario_id']
    ?? null;

$formularioId = filter_var($formularioIdRaw, FILTER_VALIDATE_INT)
    ? (int)$formularioIdRaw
    : 0;

if ($formularioId <= 0) {
    fail('ID de formulario inválido. Debes enviar id, id_campana o id_formulario.');
}

$incluirFotosMaterial = normalizarBooleanGet('fotos', true);
$incluirFotosEncuesta = normalizarBooleanGet('fotos_encuesta', true);

$formularioBase = obtenerFormularioBase($conn, $formularioId);
$nombreForm = (string)$formularioBase['nombre'];
$modalidad = (string)$formularioBase['modalidad'];

$campaignData = getCampaignData($conn, $formularioId);
$localesDetails = [];
$encuestaPivot = [];

switch (strtolower(trim($modalidad))) {
    case 'solo_implementacion':
    case 'implementacion_por_etapas':
        $localesDetails = getLocalesDetails($conn, $formularioId);
        break;

    case 'solo_auditoria':
        $encuestaPivot = function_exists('getEncuestaPivot') ? getEncuestaPivot($conn, $formularioId) : [];
        break;

    case 'implementacion_auditoria':
        $localesDetails = getLocalesDetails($conn, $formularioId);
        $encuestaPivot = function_exists('getEncuestaPivot') ? getEncuestaPivot($conn, $formularioId) : [];
        break;

    default:
        $localesDetails = getLocalesDetails($conn, $formularioId);
        $encuestaPivot = function_exists('getEncuestaPivot') ? getEncuestaPivot($conn, $formularioId) : [];
        break;
}

$fotosLocales = [];
$maxFotosLocales = 0;

if ($incluirFotosMaterial && !empty($localesDetails)) {
    $fqIds = array_column($localesDetails, 'id_formulario_question');
    $fotosLocales = getFotosImplementaciones($conn, $formularioId, $fqIds);

    foreach ($fotosLocales as $lista) {
        $maxFotosLocales = max($maxFotosLocales, count($lista));
    }
}

if (!$incluirFotosEncuesta && !empty($encuestaPivot)) {
    $encuestaPivot = removerFotosDeEncuesta($encuestaPivot);
}

if (!empty($encuestaPivot)) {
    $encuestaPivot = expandirEncuestaMultiplesRespuestas($encuestaPivot);
}

if (empty($campaignData) && empty($localesDetails) && empty($encuestaPivot)) {
    fail('No se encontraron datos para la campaña.');
}

$nombreCampana = limpiarNombreArchivo($nombreForm);
$archivo = 'Reporte_' . $nombreCampana . '_' . date('Y-m-d_His') . '.xlsx';

generarExcelPhpSpreadsheet(
    $campaignData,
    $localesDetails,
    $encuestaPivot,
    $archivo,
    $fotosLocales,
    $maxFotosLocales,
    $modalidad,
    true,
    false
);

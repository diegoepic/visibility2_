<?php
ob_start();
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$autoload = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

if (!file_exists($autoload)) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    die('No se encontró vendor/autoload.php en: ' . $autoload);
}

require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    die('No existe conexión a base de datos.');
}

$mysqli->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN
|--------------------------------------------------------------------------
*/

$tipo = $_GET['tipo'] ?? 'actual';

if (!in_array($tipo, ['actual', 'historico'], true)) {
    $tipo = 'actual';
}

$fechaArchivo = date('Ymd_His');

$filename = $tipo === 'historico'
    ? "vehiculos_historico_{$fechaArchivo}.xlsx"
    : "vehiculos_estado_actual_{$fechaArchivo}.xlsx";

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function valorExcel($value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string)$value);
}

function escribirFila($sheet, int $rowNumber, array $data): void
{
    $col = 1;

    foreach ($data as $value) {
        $cell = Coordinate::stringFromColumnIndex($col) . $rowNumber;

        $sheet->setCellValueExplicit(
            $cell,
            valorExcel($value),
            DataType::TYPE_STRING
        );

        $col++;
    }
}

function prepararHoja($spreadsheet, string $titulo, array $headers)
{
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($titulo, 0, 31));

    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', $titulo);

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', 'Generado el ' . date('Y-m-d H:i:s'));

    $sheet->getStyle("A1")->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '172848'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $sheet->getStyle("A2")->applyFromArray([
        'font' => [
            'size' => 10,
            'color' => ['rgb' => '7285A4'],
        ],
    ]);

    $headerRow = 4;

    escribirFila($sheet, $headerRow, $headers);

    $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 10,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '172848'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'D7E4F6'],
            ],
        ],
    ]);

    $sheet->getRowDimension(1)->setRowHeight(26);
    $sheet->getRowDimension($headerRow)->setRowHeight(28);
    $sheet->freezePane('A5');

    return $sheet;
}

function finalizarHoja($sheet, int $totalColumnas, int $ultimaFila): void
{
    $lastColumn = Coordinate::stringFromColumnIndex($totalColumnas);

    if ($ultimaFila >= 4) {
        $sheet->setAutoFilter("A4:{$lastColumn}{$ultimaFila}");
    }

    if ($ultimaFila >= 5) {
        $sheet->getStyle("A5:{$lastColumn}{$ultimaFila}")->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '223A5D'],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E4ECF7'],
                ],
            ],
        ]);
    }

    for ($col = 1; $col <= $totalColumnas; $col++) {
        $letter = Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }
}

/*
|--------------------------------------------------------------------------
| CREAR EXCEL
|--------------------------------------------------------------------------
*/

try {
    $spreadsheet = new Spreadsheet();

    $spreadsheet->getProperties()
        ->setCreator('Visibility 2')
        ->setTitle($tipo === 'historico' ? 'Histórico de vehículos' : 'Estado actual de vehículos')
        ->setSubject('Flota de vehículos')
        ->setDescription('Exportación generada desde Visibility 2');

    if ($tipo === 'actual') {

        /*
        |--------------------------------------------------------------------------
        | ESTADO ACTUAL
        |--------------------------------------------------------------------------
        */

        $headers = [
            'Patente',
            'Modelo',
            'Combustible',
            'Dirección Origen',
            'Latitud Origen',
            'Longitud Origen',
            'Estado',
            'Empresa',
            'División',
            'Subdivisión',
            'Merchan Actual',
            'Usuario Merchan',
            'Fecha Inicio Asignación'
        ];

        $sheet = prepararHoja($spreadsheet, 'Estado actual de vehículos', $headers);

        $sql = "
            SELECT 
                UPPER(v.patente) AS patente,
                UPPER(v.modelo) AS modelo,
                UPPER(v.tipo_combustible) AS tipo_combustible,
                UPPER(v.direccion_origen) AS direccion_origen,
                v.lat_origen,
                v.lng_origen,
                v.estado,

                UPPER(e.nombre) AS empresa,
                UPPER(d.nombre) AS division,
                UPPER(s.nombre) AS subdivision,
                UPPER(TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')))) AS merchan,
                UPPER(u.usuario) AS usuario_merchan,
                h.fecha_inicio

            FROM vehiculo v

            LEFT JOIN vehiculo_asignacion_historial h
                ON h.id_vehiculo = v.id
                AND h.fecha_termino IS NULL

            LEFT JOIN empresa e
                ON e.id = COALESCE(h.id_empresa, v.id_empresa)

            LEFT JOIN division_empresa d
                ON d.id = COALESCE(h.id_division, v.id_division)

            LEFT JOIN subdivision s
                ON s.id = COALESCE(h.id_subdivision, v.id_subdivision)

            LEFT JOIN usuario u
                ON u.id = COALESCE(h.id_merchan, v.id_merchan)

            WHERE v.deleted_at IS NULL

            ORDER BY v.id DESC
        ";

        $res = $mysqli->query($sql);

        if (!$res) {
            throw new Exception($mysqli->error);
        }

        $rowNumber = 5;

        while ($row = $res->fetch_assoc()) {
            escribirFila($sheet, $rowNumber, [
                $row['patente'] ?? '',
                $row['modelo'] ?? '',
                $row['tipo_combustible'] ?? '',
                $row['direccion_origen'] ?? '',
                $row['lat_origen'] ?? '',
                $row['lng_origen'] ?? '',
                ((int)($row['estado'] ?? 0) === 1 ? 'ACTIVO' : 'INACTIVO'),
                $row['empresa'] ?? '',
                $row['division'] ?? '',
                $row['subdivision'] ?? '',
                trim($row['merchan'] ?? ''),
                $row['usuario_merchan'] ?? '',
                $row['fecha_inicio'] ?? ''
            ]);

            $rowNumber++;
        }

        finalizarHoja($sheet, count($headers), max(4, $rowNumber - 1));

    } else {

        /*
        |--------------------------------------------------------------------------
        | HISTÓRICO
        |--------------------------------------------------------------------------
        */

        $headers = [
            'Patente',
            'Modelo',
            'Combustible',
            'Dirección Origen',
            'Latitud Origen',
            'Longitud Origen',
            'Estado Vehículo',
            'Empresa',
            'División',
            'Subdivisión',
            'Merchan',
            'Usuario Merchan',
            'Fecha Inicio',
            'Fecha Término',
            'Estado Asignación',
            'Observación'
        ];

        $sheet = prepararHoja($spreadsheet, 'Histórico de asignaciones', $headers);

        $sql = "
            SELECT 
                UPPER(v.patente) AS patente,
                UPPER(v.modelo) AS modelo,
                UPPER(v.tipo_combustible) AS tipo_combustible,
                UPPER(v.direccion_origen) AS direccion_origen,
                v.lat_origen,
                v.lng_origen,
                v.estado,

                h.fecha_inicio,
                h.fecha_termino,
                UPPER(h.observacion) AS observacion,

                UPPER(e.nombre) AS empresa,
                UPPER(d.nombre) AS division,
                UPPER(s.nombre) AS subdivision,
                UPPER(TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, '')))) AS merchan,
                UPPER(u.usuario) AS usuario_merchan

            FROM vehiculo v

            LEFT JOIN vehiculo_asignacion_historial h
                ON h.id_vehiculo = v.id

            LEFT JOIN empresa e
                ON e.id = h.id_empresa

            LEFT JOIN division_empresa d
                ON d.id = h.id_division

            LEFT JOIN subdivision s
                ON s.id = h.id_subdivision

            LEFT JOIN usuario u
                ON u.id = h.id_merchan

            WHERE v.deleted_at IS NULL

            ORDER BY v.patente ASC, h.fecha_inicio DESC, h.id DESC
        ";

        $res = $mysqli->query($sql);

        if (!$res) {
            throw new Exception($mysqli->error);
        }

        $rowNumber = 5;

        while ($row = $res->fetch_assoc()) {
            escribirFila($sheet, $rowNumber, [
                $row['patente'] ?? '',
                $row['modelo'] ?? '',
                $row['tipo_combustible'] ?? '',
                $row['direccion_origen'] ?? '',
                $row['lat_origen'] ?? '',
                $row['lng_origen'] ?? '',
                ((int)($row['estado'] ?? 0) === 1 ? 'ACTIVO' : 'INACTIVO'),
                $row['empresa'] ?? '',
                $row['division'] ?? '',
                $row['subdivision'] ?? '',
                trim($row['merchan'] ?? ''),
                $row['usuario_merchan'] ?? '',
                $row['fecha_inicio'] ?? '',
                $row['fecha_termino'] ?? '',
                empty($row['fecha_termino']) ? 'VIGENTE' : 'CERRADO',
                $row['observacion'] ?? ''
            ]);

            $rowNumber++;
        }

        finalizarHoja($sheet, count($headers), max(4, $rowNumber - 1));
    }

    /*
    |--------------------------------------------------------------------------
    | DESCARGA
    |--------------------------------------------------------------------------
    */

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error al generar archivo Excel: ' . $e->getMessage();
    exit;
}
<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/*
|--------------------------------------------------------------------------
| AJUSTA ESTA RUTA SEGÚN TU PROYECTO
|--------------------------------------------------------------------------
| Ejemplo:
| /home/visibility/public_html/vendor/autoload.php
| o
| $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'
|--------------------------------------------------------------------------
*/
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

$conn->set_charset('utf8mb4');

set_time_limit(0);
ini_set('memory_limit', '512M');

/**
 * Limpia cualquier salida previa para evitar corrupción del archivo.
 */
function clearOutputBuffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/**
 * Normaliza valores para exportación.
 * Además protege contra fórmulas en Excel.
 */
function normalizeExportValue($value): string
{
    if ($value === null) {
        return '';
    }

    $value = trim((string)$value);

    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Exporta CSV en streaming.
 */
function exportCsv(array $rows, string $filename): void
{
    clearOutputBuffers();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM para Excel
    fwrite($out, "\xEF\xBB\xBF");

    $headers = array_keys($rows[0]);
    fputcsv($out, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
    exit;
}

/**
 * Exporta XLSX con formato básico profesional.
 */
function exportXlsx(array $rows, string $filename): void
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Visibility')
        ->setTitle('Exportación de usuarios')
        ->setSubject('Usuarios')
        ->setDescription('Exportación de usuarios del sistema');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Usuarios');

    $headers = array_keys($rows[0]);

    // Encabezados
    foreach ($headers as $index => $header) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
        $sheet->setCellValue($colLetter . '1', $header);
    }

    // Datos
    $rowNumber = 2;
    foreach ($rows as $row) {
        $colNumber = 1;
        foreach ($headers as $header) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNumber);
    
            $sheet->setCellValueExplicit(
                $colLetter . $rowNumber,
                (string)($row[$header] ?? ''),
                DataType::TYPE_STRING
            );
    
            $colNumber++;
        }
        $rowNumber++;
    }

    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
    $lastRow    = count($rows) + 1;

    // Estilo cabecera
    $headerRange = "A1:{$lastColumn}1";
    $sheet->getStyle($headerRange)->applyFromArray([
        'font' => [
            'bold'  => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size'  => 11,
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1F4E78'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // Estilo datos
    if ($lastRow >= 2) {
        $dataRange = "A2:{$lastColumn}{$lastRow}";
        $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle($dataRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    // Filtro y congelar cabecera
    $sheet->setAutoFilter($headerRange);
    $sheet->freezePane('A2');

    // Auto ancho columnas
    for ($i = 1; $i <= count($headers); $i++) {
        $columnLetter = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }

    clearOutputBuffers();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet, $writer);

    exit;
}

// ----------------------
// Captura y validación
// ----------------------
$formato  = strtolower(trim($_GET['formato'] ?? 'csv')); // dejo csv por compatibilidad
$perfil   = (isset($_GET['perfil'])   && $_GET['perfil']   !== '') ? (int)$_GET['perfil']   : null;
$division = (isset($_GET['division']) && $_GET['division'] !== '') ? (int)$_GET['division'] : null;

$formatosPermitidos = ['csv', 'xlsx'];
if (!in_array($formato, $formatosPermitidos, true)) {
    http_response_code(400);
    exit('Formato no válido. Usa csv o xlsx.');
}

// ----------------------
// Query base
// ----------------------
$sqlBase = "
    SELECT
        UPPER(COALESCE(de.nombre, '')) AS DIVISION,
        UPPER(COALESCE(u.rut, '')) AS RUT,
        UPPER(COALESCE(u.nombre, '')) AS NOMBRE,
        UPPER(COALESCE(u.apellido, '')) AS APELLIDO,
        UPPER(COALESCE(u.telefono, '')) AS TELEFONO,
        UPPER(COALESCE(u.email, '')) AS CORREO,
        UPPER(COALESCE(u.usuario, '')) AS USUARIO,
        CASE
            WHEN u.fechaCreacion IS NULL THEN ''
            WHEN CAST(u.fechaCreacion AS CHAR(19)) = '0000-00-00 00:00:00' THEN ''
            ELSE DATE_FORMAT(u.fechaCreacion, '%Y-%m-%d %H:%i:%s')
        END AS `FECHA CREACION`,
        
        COALESCE(u.login_count, 0) AS LOGINS,
        
        CASE
            WHEN u.last_login IS NULL THEN ''
            WHEN CAST(u.last_login AS CHAR(19)) = '0000-00-00 00:00:00' THEN ''
            ELSE DATE_FORMAT(u.last_login, '%Y-%m-%d %H:%i:%s')
        END AS `ULTIMO LOGIN`,
        UPPER(COALESCE(p.nombre, '')) AS PERFIL,
		CASE
			WHEN u.activo = 1 THEN 'ACTIVO'
			WHEN u.activo = 0 THEN 'INACTIVO'
			ELSE ''
		END AS ESTADO
    FROM usuario u
    INNER JOIN division_empresa de ON de.id = u.id_division
    INNER JOIN perfil p ON p.id = u.id_perfil
    WHERE 1=1
";

$sqlOrder = " ORDER BY de.nombre ASC, p.nombre ASC, u.nombre ASC, u.apellido ASC";

// ----------------------
// Preparar según filtros
// ----------------------
if ($perfil !== null && $division !== null) {
    $sql = $sqlBase . " AND u.id_perfil = ? AND u.id_division = ? " . $sqlOrder;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $perfil, $division);
} elseif ($perfil !== null) {
    $sql = $sqlBase . " AND u.id_perfil = ? " . $sqlOrder;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $perfil);
} elseif ($division !== null) {
    $sql = $sqlBase . " AND u.id_division = ? " . $sqlOrder;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $division);
} else {
    $sql = $sqlBase . $sqlOrder;
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();

// ----------------------
// Armar dataset
// ----------------------
$rows = [];
while ($row = $result->fetch_assoc()) {
    foreach ($row as $key => $value) {
        $row[$key] = normalizeExportValue($value);
    }
    $rows[] = $row;
}

$stmt->close();
$conn->close();

if (empty($rows)) {
    http_response_code(404);
    exit('No hay datos disponibles para exportar con los filtros seleccionados.');
}

// ----------------------
// Nombre archivo
// ----------------------
$filename = 'usuarios_' . date('Ymd_His');

// ----------------------
// Exportar
// ----------------------
if ($formato === 'xlsx') {
    exportXlsx($rows, $filename);
}

exportCsv($rows, $filename);
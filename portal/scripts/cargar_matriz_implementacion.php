<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
    die('PhpSpreadsheet NO está instalado');
}

$archivo = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/repositorio/ABL/MATRIZ_ABL.xlsx';

if (!file_exists($archivo)) {
    die('Archivo no encontrado: ' . $archivo);
}

$mysqli = new mysqli(
    'localhost',
    'visibility',
    'xyPz8e/rgaC2',
    'visibility_visibility2'
);

if ($mysqli->connect_error) {
    die('Error DB: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

$spreadsheet = IOFactory::load($archivo);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray();

$stmt = $mysqli->prepare("
    INSERT INTO dim_campana_visibility (
        nombre_campana_individual,
        trade,
        marca_segmento,
        nombre_campana_agrupada,
        fecha_inicio_campana,
        fecha_max_implementacion,
        fecha_termino_campana,
        fecha_termino_implementacion,
        fuente_origen,
        fecha_carga
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'XLSX', NOW())
    ON DUPLICATE KEY UPDATE
        trade = VALUES(trade),
        marca_segmento = VALUES(marca_segmento),
        nombre_campana_agrupada = VALUES(nombre_campana_agrupada),
        fecha_inicio_campana = VALUES(fecha_inicio_campana),
        fecha_max_implementacion = VALUES(fecha_max_implementacion),
        fecha_termino_campana = VALUES(fecha_termino_campana),
        fecha_termino_implementacion = VALUES(fecha_termino_implementacion),
        fecha_carga = NOW()
");

foreach ($rows as $i => $row) {
    if ($i === 0) continue; // header

    [
        $trade,
        $marca,
        $campana_agrupada,
        $f_inicio,
        $f_max_impl,
        $f_termino,
        $f_termino_impl,
        $campana_individual
    ] = $row;

    if (empty($campana_individual)) {
        continue; // clave obligatoria
    }

    // Normalizar fechas (robusto para Excel)
    $f_inicio       = is_numeric($f_inicio) ? date('Y-m-d', Date::excelToTimestamp($f_inicio)) : date('Y-m-d', strtotime($f_inicio));
    $f_max_impl     = is_numeric($f_max_impl) ? date('Y-m-d', Date::excelToTimestamp($f_max_impl)) : date('Y-m-d', strtotime($f_max_impl));
    $f_termino      = is_numeric($f_termino) ? date('Y-m-d', Date::excelToTimestamp($f_termino)) : date('Y-m-d', strtotime($f_termino));
    $f_termino_impl = is_numeric($f_termino_impl) ? date('Y-m-d', Date::excelToTimestamp($f_termino_impl)) : date('Y-m-d', strtotime($f_termino_impl));

    $stmt->bind_param(
        'ssssssss',
        $campana_individual,
        $trade,
        $marca,
        $campana_agrupada,
        $f_inicio,
        $f_max_impl,
        $f_termino,
        $f_termino_impl
    );

    if (!$stmt->execute()) {
        echo 'Error SQL: ' . $stmt->error . PHP_EOL;
    }
}

$stmt->close();
$mysqli->close();

echo "Carga de campañas finalizada correctamente";

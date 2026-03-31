<?php
// panel/api/export_excel.php
require __DIR__.'/_db.php';
require __DIR__.'/data.php'; // reutilizamos la lógica preparando $payload y haciendo echo JSON
// NOTA: como data.php hace exit(), mejor convertimos su core a función compartida.
// Para acelerar, aquí vuelvo a ejecutar la misma consulta de forma sucinta.

require __DIR__.'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Para evitar duplicar: pedimos JSON con fetch al mismo endpoint data.php en el front y posteamos aquí el payload ya listo.
// Para este ejemplo, reejecuto data.php mentalmente: mejor pegame el JSON del front en 'dataset' y acá solo construyo archivo.

$dataset = jread('dataset', null);
if (!$dataset) fail('Falta dataset (json serializado de data.php)');

$dataset = is_string($dataset) ? json_decode($dataset, true) : $dataset;
if (!$dataset || !isset($dataset['meta'])) fail('Dataset inválido');

$spread = new Spreadsheet();
$sheet1 = $spread->getActiveSheet();
$sheet1->setTitle('Opciones');

$rows = $dataset['option_counts'] ?? [];
$cols = array_keys($rows[0] ?? ['set_qid'=>'','option_set_id'=>'','cnt'=>'']);
$r=1; $c=1;
foreach ($cols as $col) { $sheet1->setCellValueByColumnAndRow($c++,$r,$col); }
foreach ($rows as $row) {
  $r++; $c=1; foreach ($cols as $col) $sheet1->setCellValueByColumnAndRow($c++,$r,$row[$col] ?? '');
}

$sheet2 = $spread->createSheet();
$sheet2->setTitle('Numericas');
$rows2 = $dataset['numeric_stats'] ?? [];
$cols2 = array_keys($rows2[0] ?? ['set_qid'=>'','n'=>'','avg_val'=>'','sum_val'=>'','min_val'=>'','max_val'=>'']);
$r=1; $c=1;
foreach ($cols2 as $col) { $sheet2->setCellValueByColumnAndRow($c++,$r,$col); }
foreach ($rows2 as $row) {
  $r++; $c=1; foreach ($cols2 as $col) $sheet2->setCellValueByColumnAndRow($c++,$r,$row[$col] ?? '');
}

$fname = 'reporte_encuestas_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
$writer = new Xlsx($spread);
$writer->save('php://output');
exit;

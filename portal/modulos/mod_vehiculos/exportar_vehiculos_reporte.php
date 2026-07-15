<?php
ob_start();
session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

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

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    die('No existe conexion a base de datos.');
}

$mysqli->set_charset('utf8mb4');

$formularioId = 138;
$preguntaKilometraje = 'INGRESE KILOMETRAJE DE LA CAMIONETA:';
$preguntaPatente = 'INGRESE PATENTE DEL VEHÍCULO (FORMATO ABCD-12)';
$preguntasPermitidasUpper = [
    mb_strtoupper($preguntaKilometraje, 'UTF-8'),
    mb_strtoupper($preguntaPatente, 'UTF-8'),
    'INGRESE PATENTE DEL VEHÍCULO (FORMATO ABCD-12)',
];
$placeholdersPreguntas = implode(',', array_fill(0, count($preguntasPermitidasUpper), '?'));
$fechaDesde = trim((string)($_GET['fecha_desde'] ?? date('Y-m-01')));
$fechaHasta = trim((string)($_GET['fecha_hasta'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
    $fechaDesde = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
    $fechaHasta = date('Y-m-d');
}

if ($fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$fechaArchivo = date('Ymd_His');
$filename = "vehiculos_reporte_formulario_138_{$fechaDesde}_{$fechaHasta}_{$fechaArchivo}.xlsx";

function valorExcelReporte($value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string)$value);
}

function escribirFilaReporte(Worksheet $sheet, int $rowNumber, array $data): void
{
    $col = 1;

    foreach ($data as $value) {
        $cell = Coordinate::stringFromColumnIndex($col) . $rowNumber;
        $sheet->setCellValueExplicit($cell, valorExcelReporte($value), DataType::TYPE_STRING);
        $col++;
    }
}

function aplicarFormatoReporte(Worksheet $sheet, int $totalColumnas, int $ultimaFila): void
{
    $lastColumn = Coordinate::stringFromColumnIndex($totalColumnas);

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', 'Reporte kilometraje vehiculos');

    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', 'Generado el ' . date('Y-m-d H:i:s'));

    $sheet->getStyle('A1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
            'color' => ['rgb' => '172848'],
        ],
    ]);

    $sheet->getStyle('A2')->applyFromArray([
        'font' => [
            'size' => 10,
            'color' => ['rgb' => '7285A4'],
        ],
    ]);

    $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
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

    $sheet->getRowDimension(1)->setRowHeight(26);
    $sheet->getRowDimension(4)->setRowHeight(34);
    $sheet->freezePane($totalColumnas >= 7 ? 'G5' : 'A5');
    $sheet->setAutoFilter("A4:{$lastColumn}" . max(4, $ultimaFila));

    for ($col = 1; $col <= $totalColumnas; $col++) {
        $letter = Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }
}

function bindReporte(mysqli_stmt $stmt, string $types, array $params): void
{
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function numeroKilometrajeReporte($value): ?float
{
    $texto = trim((string)$value);

    if ($texto === '') {
        return null;
    }

    $texto = preg_replace('/[^0-9,.-]/', '', $texto);
    $texto = str_replace('.', '', $texto);
    $texto = str_replace(',', '.', $texto);

    if ($texto === '' || !is_numeric($texto)) {
        return null;
    }

    return (float)$texto;
}

function normalizarPatenteMerchan($value): string
{
    $texto = mb_strtoupper(trim((string)$value), 'UTF-8');

    if ($texto === '') {
        return '';
    }

    $texto = preg_replace('/[^A-Z0-9]/', '', $texto);

    if (preg_match('/^([A-Z]+)([0-9]+)$/', $texto, $matches)) {
        return $matches[1] . '-' . $matches[2];
    }

    return $texto;
}

try {
    $mysqli->query("SET SESSION group_concat_max_len = 1000000");

    $sql = "
        WITH eventos_form_138 AS (
            SELECT
                r.id_usuario,
                COALESCE(r.visita_id, 0) AS visita_id,
                DATE(r.created_at) AS fecha_respuesta,
                TIME(r.created_at) AS hora_respuesta,
                MAX(r.created_at) AS fecha_evento
            FROM form_question_responses r
            INNER JOIN form_questions q
                ON q.id = r.id_form_question
            WHERE q.id_formulario = ?
              AND DATE(r.created_at) BETWEEN ? AND ?
            GROUP BY
                r.id_usuario,
                COALESCE(r.visita_id, 0),
                DATE(r.created_at),
                TIME(r.created_at)
        )

        SELECT
            v.id AS id_vehiculo,
            UPPER(v.patente) AS patente,
            UPPER(COALESCE(d.nombre, '')) AS division,
            UPPER(COALESCE(s.nombre, '')) AS subdivision,
            u.rut AS rut_usuario,
            UPPER(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS nombre_completo,
            e.fecha_respuesta,
            e.hora_respuesta,
            e.fecha_evento AS fecha_hora_respuesta,
            e.visita_id AS visita_id_form_138,
            q.question_text,
            r.answer_text,
            r.valor

        FROM vehiculo v

        LEFT JOIN vehiculo_asignacion_historial h
            ON h.id_vehiculo = v.id
            AND h.fecha_termino IS NULL

        LEFT JOIN usuario u
            ON u.id = h.id_merchan

        LEFT JOIN division_empresa d
            ON d.id = COALESCE(h.id_division, v.id_division)

        LEFT JOIN subdivision s
            ON s.id = COALESCE(h.id_subdivision, v.id_subdivision)

        INNER JOIN eventos_form_138 e
            ON e.id_usuario = h.id_merchan

        LEFT JOIN form_question_responses r
            ON r.id_usuario = e.id_usuario
            AND (
                (
                    e.visita_id > 0
                    AND r.visita_id = e.visita_id
                )
                OR
                (
                    e.visita_id = 0
                    AND DATE(r.created_at) = e.fecha_respuesta
                    AND TIME(r.created_at) = e.hora_respuesta
                )
            )
            AND EXISTS (
                SELECT 1
                FROM form_questions qf
                WHERE qf.id = r.id_form_question
                  AND qf.id_formulario = ?
                  AND UPPER(TRIM(qf.question_text)) IN ($placeholdersPreguntas)
            )

        LEFT JOIN form_questions q
            ON q.id = r.id_form_question
            AND q.id_formulario = ?
            AND UPPER(TRIM(q.question_text)) IN ($placeholdersPreguntas)

        WHERE v.deleted_at IS NULL

        ORDER BY
            v.patente ASC,
            e.fecha_evento ASC,
            e.visita_id ASC,
            q.sort_order ASC,
            r.created_at ASC
    ";

    $types = 'iss' . 'i' . str_repeat('s', count($preguntasPermitidasUpper)) . 'i' . str_repeat('s', count($preguntasPermitidasUpper));
    $params = array_merge(
        [$formularioId, $fechaDesde, $fechaHasta, $formularioId],
        $preguntasPermitidasUpper,
        [$formularioId],
        $preguntasPermitidasUpper
    );

    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        throw new Exception($mysqli->error);
    }

    bindReporte($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $events = [];

    while ($row = $result->fetch_assoc()) {
        $idVehiculo = (int)$row['id_vehiculo'];
        $fechaHora = (string)($row['fecha_hora_respuesta'] ?? '');
        $visitaId = (int)($row['visita_id_form_138'] ?? 0);
        $eventKey = $idVehiculo . '|' . $fechaHora . '|' . $visitaId;

        if (!isset($events[$eventKey])) {
            $events[$eventKey] = [
                'id_vehiculo' => $idVehiculo,
                'patente' => $row['patente'] ?? '',
                'division' => $row['division'] ?? '',
                'subdivision' => $row['subdivision'] ?? '',
                'rut_usuario' => $row['rut_usuario'] ?? '',
                'nombre_completo' => trim($row['nombre_completo'] ?? ''),
                'fecha_subida' => $row['fecha_respuesta'] ?? '',
                'fecha_hora_subida' => $fechaHora,
                'kilometraje' => '',
                'patente_ingresada' => '',
                'km_numero' => null,
            ];
        }

        $question = mb_strtoupper(trim((string)($row['question_text'] ?? '')), 'UTF-8');

        if ($question === '') {
            continue;
        }

        $answerText = trim((string)($row['answer_text'] ?? ''));
        $valor = trim((string)($row['valor'] ?? ''));
        $respuesta = $answerText !== '' ? $answerText : $valor;

        if ($question === $preguntasPermitidasUpper[0]) {
            $events[$eventKey]['kilometraje'] = $respuesta;
            $events[$eventKey]['km_numero'] = numeroKilometrajeReporte($respuesta);
        }

        if ($question === $preguntasPermitidasUpper[1] || $question === $preguntasPermitidasUpper[2]) {
            $events[$eventKey]['patente_ingresada'] = normalizarPatenteMerchan($respuesta);
        }
    }

    $stmt->close();

    $events = array_values($events);
    usort($events, function ($a, $b) {
        return strcmp((string)$a['patente'], (string)$b['patente'])
            ?: strcmp((string)$a['fecha_hora_subida'], (string)$b['fecha_hora_subida'])
            ?: ((int)$a['id_vehiculo'] <=> (int)$b['id_vehiculo']);
    });

    $pivotRows = [];
    $dateColumns = [];

    foreach ($events as $event) {
        $fecha = (string)$event['fecha_subida'];

        if ($fecha === '') {
            continue;
        }

        $rowKey = (int)$event['id_vehiculo'] . '|' . (string)$event['rut_usuario'];

        if (!isset($pivotRows[$rowKey])) {
            $pivotRows[$rowKey] = [
                'patente' => $event['patente'],
                'patente_ingresada' => '',
                'rut_usuario' => $event['rut_usuario'],
                'nombre_completo' => $event['nombre_completo'],
                'division' => $event['division'],
                'subdivision' => $event['subdivision'],
                'ultima_patente_fecha_hora' => '',
                'kms' => [],
                'alert_dates' => [],
            ];
        }

        $dateColumns[$fecha] = true;

        if (
            $event['patente_ingresada'] !== ''
            && (
                $pivotRows[$rowKey]['ultima_patente_fecha_hora'] === ''
                || strcmp((string)$event['fecha_hora_subida'], (string)$pivotRows[$rowKey]['ultima_patente_fecha_hora']) >= 0
            )
        ) {
            $pivotRows[$rowKey]['patente_ingresada'] = $event['patente_ingresada'];
            $pivotRows[$rowKey]['ultima_patente_fecha_hora'] = $event['fecha_hora_subida'];
        }

        if ($event['kilometraje'] === '') {
            continue;
        }

        if (
            !isset($pivotRows[$rowKey]['kms'][$fecha])
            || strcmp((string)$event['fecha_hora_subida'], (string)$pivotRows[$rowKey]['kms'][$fecha]['fecha_hora']) >= 0
        ) {
            $pivotRows[$rowKey]['kms'][$fecha] = [
                'texto' => $event['kilometraje'],
                'km' => $event['km_numero'],
                'fecha_hora' => $event['fecha_hora_subida'],
            ];
        }
    }

    $dateColumns = array_keys($dateColumns);
    sort($dateColumns);

    foreach ($pivotRows as &$pivotRow) {
        $lastKm = null;

        foreach ($dateColumns as $fecha) {
            if (!isset($pivotRow['kms'][$fecha])) {
                continue;
            }

            $currentKm = $pivotRow['kms'][$fecha]['km'];

            if ($currentKm !== null && $lastKm !== null && $lastKm > $currentKm) {
                $pivotRow['alert_dates'][$fecha] = true;
            }

            if ($currentKm !== null) {
                $lastKm = $currentKm;
            }
        }
    }
    unset($pivotRow);

    $rows = array_values($pivotRows);
    usort($rows, function ($a, $b) {
        return strcmp((string)$a['patente'], (string)$b['patente'])
            ?: strcmp((string)$a['rut_usuario'], (string)$b['rut_usuario']);
    });

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('Visibility 2')
        ->setTitle('Reporte kilometraje vehiculos')
        ->setSubject('Reporte vehiculos')
        ->setDescription('Reporte descargado desde modulo vehiculos');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Reporte');

    $headers = array_merge([
        'Placa patente',
        'PATENTE DEL VEHÍCULO (MERCHAN)',
        'RUT usuario',
        'Nombre completo',
        'División',
        'Subdivisión',
    ], $dateColumns);

    escribirFilaReporte($sheet, 4, $headers);

    $rowNumber = 5;
    $firstDateColumnIndex = 7;

    foreach ($rows as $row) {
        $data = [
            $row['patente'],
            $row['patente_ingresada'],
            $row['rut_usuario'],
            $row['nombre_completo'],
            $row['division'],
            $row['subdivision'],
        ];

        foreach ($dateColumns as $fecha) {
            $data[] = $row['kms'][$fecha]['texto'] ?? '';
        }

        escribirFilaReporte($sheet, $rowNumber, $data);

        foreach ($dateColumns as $idx => $fecha) {
            if (empty($row['alert_dates'][$fecha])) {
                continue;
            }

            $column = Coordinate::stringFromColumnIndex($firstDateColumnIndex + $idx);
            $sheet->getStyle("{$column}{$rowNumber}")->applyFromArray([
                'font' => [
                    'color' => ['rgb' => '9C1C1C'],
                    'bold' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FDE2E2'],
                ],
            ]);
        }

        $rowNumber++;
    }

    aplicarFormatoReporte($sheet, count($headers), max(4, $rowNumber - 1));
    $sheet->setCellValue('A2', 'Generado el ' . date('Y-m-d H:i:s') . " | Rango: {$fechaDesde} al {$fechaHasta}");

    $sheetPatente = $spreadsheet->createSheet();
    $sheetPatente->setTitle('Patente actual');

    $headersPatente = [
        'Placa patente',
        'PATENTE DEL VEHÍCULO (MERCHAN)',
        'RUT usuario',
        'Nombre completo',
    ];

    escribirFilaReporte($sheetPatente, 4, $headersPatente);

    $rowPatente = 5;
    foreach ($rows as $row) {
        escribirFilaReporte($sheetPatente, $rowPatente, [
            $row['patente'],
            $row['patente_ingresada'],
            $row['rut_usuario'],
            $row['nombre_completo'],
        ]);
        $rowPatente++;
    }

    aplicarFormatoReporte($sheetPatente, count($headersPatente), max(4, $rowPatente - 1));
    $sheetPatente->setCellValue('A1', 'Patente actual');
    $sheetPatente->setCellValue('A2', 'Generado el ' . date('Y-m-d H:i:s') . " | Rango: {$fechaDesde} al {$fechaHasta}");
    $spreadsheet->setActiveSheetIndex(0);

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

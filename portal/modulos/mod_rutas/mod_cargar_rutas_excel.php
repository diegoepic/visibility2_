<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeHeader(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false) {
        $text = $ascii;
    }
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    return trim((string)$text, '_');
}

function columnFor(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $normalized = normalizeHeader($alias);
        if (isset($columns[$normalized])) {
            return $columns[$normalized];
        }
    }
    return null;
}

function cellValue(array $row, ?string $column, $default = '')
{
    return $column !== null ? ($row[$column] ?? $default) : $default;
}

function toFloatValue($value): ?float
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    $value = str_replace(' ', '', trim((string)$value));
    if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }
    return is_numeric($value) ? (float)$value : null;
}

function toIntValue($value): int
{
    return trim((string)$value) === '' ? 0 : (int)$value;
}

function normalizeDateValue($value): array
{
    if ($value === null || trim((string)$value) === '') {
        return ['', ''];
    }

    try {
        if (is_numeric($value)) {
            $date = ExcelDate::excelToDateTimeObject((float)$value);
        } else {
            $date = new DateTime(trim((string)$value));
        }
        return [$date->format('d-m-Y'), $date->format('Y-m-d')];
    } catch (Throwable $e) {
        $raw = trim((string)$value);
        return [$raw, $raw];
    }
}

if (!isset($_FILES['archivoPlanificacion']) || $_FILES['archivoPlanificacion']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No se recibió un archivo válido.'], 400);
}

$tmpFile = $_FILES['archivoPlanificacion']['tmp_name'];
$originalName = $_FILES['archivoPlanificacion']['name'] ?? 'archivo.xlsx';
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, ['xlsx', 'xls'], true) || !is_uploaded_file($tmpFile)) {
    jsonResponse(['success' => false, 'message' => 'Sube un archivo Excel .xlsx o .xls válido.'], 400);
}

try {
    $spreadsheet = IOFactory::load($tmpFile);
    $sheet = $spreadsheet->sheetNameExists('Planificacion')
        ? $spreadsheet->getSheetByName('Planificacion')
        : $spreadsheet->getActiveSheet();

    if ($sheet === null) {
        jsonResponse(['success' => false, 'message' => 'No se encontró la hoja Planificacion.'], 400);
    }

    $rows = $sheet->toArray(null, true, true, true);
    if (count($rows) < 2) {
        jsonResponse(['success' => false, 'message' => 'La hoja Planificacion no contiene datos.'], 400);
    }

    $headerRowNumber = array_key_first($rows);
    $columns = [];
    foreach ($rows[$headerRowNumber] as $column => $label) {
        $normalized = normalizeHeader((string)$label);
        if ($normalized !== '') {
            $columns[$normalized] = $column;
        }
    }

    $map = [
        'codigo' => columnFor($columns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo']),
        'nombre' => columnFor($columns, ['Nombre', 'Nombre Local', 'Local']),
        'direccion' => columnFor($columns, ['Dirección', 'Direccion']),
        'comuna' => columnFor($columns, ['Comuna']),
        'lat' => columnFor($columns, ['Lat', 'Latitud']),
        'lng' => columnFor($columns, ['Lng', 'Longitud', 'Lon']),
        'cantidad' => columnFor($columns, ['Cantidad Objetivo Día', 'Cantidad Objetivo Dia']),
        'dias' => columnFor($columns, ['Días Planificados', 'Dias Planificados']),
        'grupo' => columnFor($columns, ['Grupo Ruta', 'Grupo Ruta Usuario', 'Ruta']),
        'ruta_global' => columnFor($columns, ['Ruta Global']),
        'fecha' => columnFor($columns, ['Fecha Ruta', 'Fecha Planificada', 'Fecha']),
        'usuario_id' => columnFor($columns, ['Usuario ID', 'Id Usuario']),
        'usuario_login' => columnFor($columns, ['Usuario Login', 'Usuario']),
        'usuario_nombre' => columnFor($columns, ['Usuario Nombre', 'Nombre Usuario']),
        'dia_plan' => columnFor($columns, ['Día Plan', 'Dia Plan']),
        'semana' => columnFor($columns, ['Semana Plan']),
        'dia_num' => columnFor($columns, ['Día Semana Nº', 'Dia Semana N', 'Dia Semana Num']),
        'dia_semana' => columnFor($columns, ['Día Semana', 'Dia Semana']),
        'orden' => columnFor($columns, ['Orden Visita']),
        'tamano' => columnFor($columns, ['Tamaño Ruta', 'Tamano Ruta']),
        'dist_anterior' => columnFor($columns, ['Distancia Desde Anterior (KM)', 'Distancia Desde Anterior KM']),
        'dist_total' => columnFor($columns, ['Distancia Total Ruta (KM)', 'Distancia Total Ruta KM']),
        'observacion' => columnFor($columns, ['Observación', 'Observacion']),
    ];

    foreach (['codigo', 'lat', 'lng'] as $required) {
        if ($map[$required] === null) {
            jsonResponse(['success' => false, 'message' => 'Falta la columna requerida: ' . $required], 400);
        }
    }

    $dataRows = [];
    $ignoredRows = 0;
    $unplannedRows = 0;

    foreach ($rows as $rowNumber => $row) {
        if ((int)$rowNumber === (int)$headerRowNumber) {
            continue;
        }

        $codigo = trim((string)cellValue($row, $map['codigo']));
        if ($codigo === '') {
            continue;
        }

        $lat = toFloatValue(cellValue($row, $map['lat'], null));
        $lng = toFloatValue(cellValue($row, $map['lng'], null));
        if ($lat === null || $lng === null) {
            $ignoredRows++;
            continue;
        }

        $grupo = trim((string)cellValue($row, $map['grupo']));
        [$fechaRuta, $fechaRutaSql] = normalizeDateValue(cellValue($row, $map['fecha']));
        $sinRuta = $grupo === '' || $fechaRutaSql === '';

        if ($sinRuta) {
            $unplannedRows++;
        }

        $dataRows[] = [
            'row_id' => 'row_' . $rowNumber,
            'codigo_local' => $codigo,
            'nombre' => trim((string)cellValue($row, $map['nombre'])),
            'direccion' => trim((string)cellValue($row, $map['direccion'])),
            'comuna' => trim((string)cellValue($row, $map['comuna'])),
            'lat' => $lat,
            'lng' => $lng,
            'cantidad_objetivo_dia' => trim((string)cellValue($row, $map['cantidad'])),
            'dias_planificados' => trim((string)cellValue($row, $map['dias'])),
            'grupo_ruta' => $grupo,
            'ruta_global' => trim((string)cellValue($row, $map['ruta_global'])),
            'fecha_ruta' => $fechaRuta,
            'fecha_ruta_sql' => $fechaRutaSql,
            'usuario_id' => trim((string)cellValue($row, $map['usuario_id'])),
            'usuario_login' => trim((string)cellValue($row, $map['usuario_login'])),
            'usuario_nombre' => trim((string)cellValue($row, $map['usuario_nombre'])),
            'dia_plan' => trim((string)cellValue($row, $map['dia_plan'])),
            'semana_plan' => trim((string)cellValue($row, $map['semana'])),
            'dia_semana_num' => trim((string)cellValue($row, $map['dia_num'])),
            'dia_semana' => trim((string)cellValue($row, $map['dia_semana'])),
            'orden_visita' => toIntValue(cellValue($row, $map['orden'], 0)),
            'tamano_ruta' => trim((string)cellValue($row, $map['tamano'])),
            'distancia_desde_anterior_km' => toFloatValue(cellValue($row, $map['dist_anterior'], null)) ?? 0.0,
            'distancia_total_ruta_km' => toFloatValue(cellValue($row, $map['dist_total'], null)) ?? 0.0,
            'observacion' => trim((string)cellValue($row, $map['observacion'])),
            'sin_ruta' => $sinRuta,
        ];
    }

    if ($spreadsheet->sheetNameExists('Sin Ruta Asignada')) {
        $sinRutaSheet = $spreadsheet->getSheetByName('Sin Ruta Asignada');
        $sinRutaRows = $sinRutaSheet ? $sinRutaSheet->toArray(null, true, true, true) : [];

        if (count($sinRutaRows) >= 2) {
            $sinRutaHeaderNumber = array_key_first($sinRutaRows);
            $sinRutaColumns = [];
            foreach ($sinRutaRows[$sinRutaHeaderNumber] as $column => $label) {
                $normalized = normalizeHeader((string)$label);
                if ($normalized !== '') {
                    $sinRutaColumns[$normalized] = $column;
                }
            }

            $sinMap = [
                'codigo' => columnFor($sinRutaColumns, ['Código Local', 'Codigo Local']),
                'nombre' => columnFor($sinRutaColumns, ['Nombre']),
                'direccion' => columnFor($sinRutaColumns, ['Dirección', 'Direccion']),
                'comuna' => columnFor($sinRutaColumns, ['Comuna']),
                'lat' => columnFor($sinRutaColumns, ['Lat', 'Latitud']),
                'lng' => columnFor($sinRutaColumns, ['Lng', 'Longitud']),
                'usuario_id' => columnFor($sinRutaColumns, ['Usuario ID']),
                'usuario_login' => columnFor($sinRutaColumns, ['Usuario Login', 'Usuario']),
                'usuario_nombre' => columnFor($sinRutaColumns, ['Usuario Nombre']),
                'grupo_sugerido' => columnFor($sinRutaColumns, ['Grupo Ruta Sugerido']),
                'orden' => columnFor($sinRutaColumns, ['Orden Visita']),
                'tamano' => columnFor($sinRutaColumns, ['Tamaño Ruta', 'Tamano Ruta']),
                'dist_anterior' => columnFor($sinRutaColumns, ['Distancia Desde Anterior (KM)']),
                'dist_total' => columnFor($sinRutaColumns, ['Distancia Total Ruta (KM)']),
                'motivo' => columnFor($sinRutaColumns, ['Motivo Descarte']),
                'observacion' => columnFor($sinRutaColumns, ['Observación', 'Observacion']),
            ];

            foreach ($sinRutaRows as $rowNumber => $row) {
                if ((int)$rowNumber === (int)$sinRutaHeaderNumber) {
                    continue;
                }

                $codigo = trim((string)cellValue($row, $sinMap['codigo']));
                if ($codigo === '') {
                    continue;
                }

                $lat = toFloatValue(cellValue($row, $sinMap['lat'], null));
                $lng = toFloatValue(cellValue($row, $sinMap['lng'], null));
                if ($lat === null || $lng === null) {
                    $ignoredRows++;
                    continue;
                }

                $motivo = trim((string)cellValue($row, $sinMap['motivo']));
                $observacion = trim((string)cellValue($row, $sinMap['observacion']));
                $grupoSugerido = trim((string)cellValue($row, $sinMap['grupo_sugerido']));

                $dataRows[] = [
                    'row_id' => 'sin_ruta_' . $rowNumber,
                    'codigo_local' => $codigo,
                    'nombre' => trim((string)cellValue($row, $sinMap['nombre'])),
                    'direccion' => trim((string)cellValue($row, $sinMap['direccion'])),
                    'comuna' => trim((string)cellValue($row, $sinMap['comuna'])),
                    'lat' => $lat,
                    'lng' => $lng,
                    'cantidad_objetivo_dia' => '',
                    'dias_planificados' => '',
                    'grupo_ruta' => '',
                    'grupo_ruta_sugerido' => $grupoSugerido,
                    'ruta_global' => '',
                    'fecha_ruta' => '',
                    'fecha_ruta_sql' => '',
                    'usuario_id' => trim((string)cellValue($row, $sinMap['usuario_id'])),
                    'usuario_login' => trim((string)cellValue($row, $sinMap['usuario_login'])),
                    'usuario_nombre' => trim((string)cellValue($row, $sinMap['usuario_nombre'])),
                    'dia_plan' => '',
                    'semana_plan' => '',
                    'dia_semana_num' => '',
                    'dia_semana' => '',
                    'orden_visita' => toIntValue(cellValue($row, $sinMap['orden'], 0)),
                    'tamano_ruta' => trim((string)cellValue($row, $sinMap['tamano'])),
                    'distancia_desde_anterior_km' => toFloatValue(cellValue($row, $sinMap['dist_anterior'], null)) ?? 0.0,
                    'distancia_total_ruta_km' => toFloatValue(cellValue($row, $sinMap['dist_total'], null)) ?? 0.0,
                    'observacion' => trim($motivo . ($motivo !== '' && $observacion !== '' ? ' - ' : '') . $observacion),
                    'sin_ruta' => true,
                ];
                $unplannedRows++;
            }
        }
    }

    if (empty($dataRows)) {
        jsonResponse(['success' => false, 'message' => 'No se encontraron locales con coordenadas válidas.'], 400);
    }

    usort($dataRows, function (array $a, array $b): int {
        if ($a['sin_ruta'] !== $b['sin_ruta']) {
            return $a['sin_ruta'] <=> $b['sin_ruta'];
        }
        $groupCompare = strcmp((string)$a['grupo_ruta'], (string)$b['grupo_ruta']);
        return $groupCompare !== 0 ? $groupCompare : ($a['orden_visita'] <=> $b['orden_visita']);
    });

    $groupMap = [];
    $users = [];
    $dates = [];

    foreach ($dataRows as $row) {
        if (!$row['sin_ruta']) {
            $groupName = $row['grupo_ruta'];
            if (!isset($groupMap[$groupName])) {
                $groupMap[$groupName] = [
                    'grupo_ruta' => $groupName,
                    'usuario_id' => $row['usuario_id'],
                    'usuario_login' => $row['usuario_login'],
                    'usuario_nombre' => $row['usuario_nombre'],
                    'fecha_ruta' => $row['fecha_ruta'],
                    'fecha_ruta_sql' => $row['fecha_ruta_sql'],
                    'total_paradas' => 0,
                    'distancia_total_ruta_km' => 0.0,
                ];
            }
            $groupMap[$groupName]['total_paradas']++;
            $groupMap[$groupName]['distancia_total_ruta_km'] = max(
                (float)$groupMap[$groupName]['distancia_total_ruta_km'],
                (float)$row['distancia_total_ruta_km']
            );
        }

        $userKey = $row['usuario_id'] ?: $row['usuario_login'];
        if ($userKey !== '') {
            $users[$userKey] = [
                'usuario_id' => $row['usuario_id'],
                'usuario_login' => $row['usuario_login'],
                'usuario_nombre' => $row['usuario_nombre'],
            ];
        }
        if ($row['fecha_ruta_sql'] !== '') {
            $dates[$row['fecha_ruta_sql']] = [
                'fecha_ruta' => $row['fecha_ruta'],
                'fecha_ruta_sql' => $row['fecha_ruta_sql'],
            ];
        }
    }

    ksort($groupMap);
    ksort($users);
    ksort($dates);

    jsonResponse([
        'success' => true,
        'message' => 'Archivo procesado correctamente.',
        'summary' => [
            'archivo' => $originalName,
            'hoja_utilizada' => $sheet->getTitle(),
            'total_filas_validas' => count($dataRows),
            'total_grupos' => count($groupMap),
            'total_usuarios' => count($users),
            'total_fechas' => count($dates),
            'sin_ruta' => $unplannedRows,
            'filas_ignoradas' => $ignoredRows,
        ],
        'groups' => array_values($groupMap),
        'rows' => $dataRows,
        'filters' => [
            'usuarios' => array_values($users),
            'fechas' => array_values($dates),
            'grupos' => array_keys($groupMap),
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Error al leer el archivo: ' . $e->getMessage()], 500);
}

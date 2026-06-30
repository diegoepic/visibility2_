<?php
/* mod_cargar_rutas_excel */
declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeHeader(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));

    $replacements = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'º' => '', '°' => '', '(' => ' ', ')' => ' ',
        '/' => ' ', '-' => ' ', '.' => ' ', ',' => ' '
    ];

    $text = strtr($text, $replacements);
    $text = preg_replace('/[^a-z0-9]+/u', '_', $text);
    $text = trim((string)$text, '_');

    return $text;
}

function toFloatValue($value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = str_replace([' '], '', $value);

    if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    return is_numeric($value) ? (float)$value : null;
}

function toIntValue($value): int
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    return (int)$value;
}

function getCellValue(array $row, array $columns, array $aliases, $default = '')
{
    foreach ($aliases as $alias) {
        if (isset($columns[$alias])) {
            return $row[$columns[$alias]] ?? $default;
        }
    }
    return $default;
}

if (!isset($_FILES['archivoPlanificacion']) || $_FILES['archivoPlanificacion']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse([
        'success' => false,
        'message' => 'No se recibió un archivo válido.'
    ], 400);
}

$tmpFile = $_FILES['archivoPlanificacion']['tmp_name'];
$originalName = $_FILES['archivoPlanificacion']['name'] ?? 'archivo.xlsx';
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, ['xlsx', 'xls'], true)) {
    jsonResponse([
        'success' => false,
        'message' => 'Formato no permitido. Sube un archivo .xlsx o .xls'
    ], 400);
}

if (!is_uploaded_file($tmpFile)) {
    jsonResponse([
        'success' => false,
        'message' => 'El archivo subido no es válido.'
    ], 400);
}

try {
    $spreadsheet = IOFactory::load($tmpFile);

    $sheet = $spreadsheet->sheetNameExists('Planificacion')
        ? $spreadsheet->getSheetByName('Planificacion')
        : $spreadsheet->getActiveSheet();

    if ($sheet === null) {
        jsonResponse([
            'success' => false,
            'message' => 'No se encontró una hoja válida para leer.'
        ], 400);
    }

    $rows = $sheet->toArray(null, true, true, true);

    if (count($rows) < 2) {
        jsonResponse([
            'success' => false,
            'message' => 'La hoja seleccionada no contiene datos suficientes.'
        ], 400);
    }

    $headerRowNumber = array_key_first($rows);
    $headerRow = $rows[$headerRowNumber];

    $columns = [];
    foreach ($headerRow as $col => $headerLabel) {
        $normalized = normalizeHeader((string)$headerLabel);
        if ($normalized !== '') {
            $columns[$normalized] = $col;
        }
    }

    $requiredHeaders = [
        'codigo_local',
        'lat',
        'lng',
        'grupo_ruta_usuario',
        'orden_visita'
    ];

    foreach ($requiredHeaders as $required) {
        if (!isset($columns[$required])) {
            jsonResponse([
                'success' => false,
                'message' => 'Falta la columna requerida en la hoja Planificacion: ' . $required
            ], 400);
        }
    }

    $dataRows = [];
    $ignoredRows = 0;

    foreach ($rows as $rowNumber => $row) {
        if ((int)$rowNumber === (int)$headerRowNumber) {
            continue;
        }

        $codigo = trim((string)getCellValue($row, $columns, ['codigo_local']));
        $grupo  = trim((string)getCellValue($row, $columns, ['grupo_ruta_usuario']));

        if ($codigo === '' && $grupo === '') {
            continue;
        }

        $lat = toFloatValue(getCellValue($row, $columns, ['lat'], null));
        $lng = toFloatValue(getCellValue($row, $columns, ['lng'], null));

        if ($codigo === '' || $grupo === '' || $lat === null || $lng === null) {
            $ignoredRows++;
            continue;
        }

        $usuarioId = trim((string)getCellValue($row, $columns, ['usuario_id']));
        $usuarioLogin = trim((string)getCellValue($row, $columns, ['usuario_login']));
        $usuarioNombre = trim((string)getCellValue($row, $columns, ['usuario_nombre']));
        $rutaGlobal = trim((string)getCellValue($row, $columns, ['ruta_global']));
        $fechaInicioBase = trim((string)getCellValue($row, $columns, ['fecha_inicio_base']));
        $fechaRuta = trim((string)getCellValue($row, $columns, ['fecha_ruta']));
        $fechaRutaSql = trim((string)getCellValue($row, $columns, ['fecha_ruta_sql']));

        $dataRows[] = [
            'codigo_local'                => $codigo,
            'nombre'                      => trim((string)getCellValue($row, $columns, ['nombre'])),
            'direccion'                   => trim((string)getCellValue($row, $columns, ['direccion'])),
            'comuna'                      => trim((string)getCellValue($row, $columns, ['comuna'])),
            'lat'                         => $lat,
            'lng'                         => $lng,

            'usuario_id'                  => $usuarioId,
            'usuario_login'               => $usuarioLogin,
            'usuario_nombre'              => $usuarioNombre,

            'cantidad_objetivo_dia'       => trim((string)getCellValue($row, $columns, ['cantidad_objetivo_dia'])),
            'dias_planificados'           => trim((string)getCellValue($row, $columns, ['dias_planificados_usuario', 'dias_planificados'])),

            'grupo_ruta'                  => $grupo,
            'ruta_global'                 => $rutaGlobal,

            'dia_plan'                    => trim((string)getCellValue($row, $columns, ['dia_plan'])),
            'semana_plan'                 => trim((string)getCellValue($row, $columns, ['semana_plan'])),
            'dia_semana_num'              => trim((string)getCellValue($row, $columns, ['dia_semana_n', 'dia_semana_no', 'dia_semana_num'])),
            'dia_semana'                  => trim((string)getCellValue($row, $columns, ['dia_semana'])),

            'fecha_inicio_base'           => $fechaInicioBase,
            'fecha_ruta'                  => $fechaRuta,
            'fecha_ruta_sql'              => $fechaRutaSql,

            'orden_visita'                => toIntValue(getCellValue($row, $columns, ['orden_visita'], 0)),
            'tamano_ruta'                 => trim((string)getCellValue($row, $columns, ['tamano_ruta'])),
            'distancia_desde_anterior_km' => toFloatValue(getCellValue($row, $columns, ['distancia_desde_anterior_km'], null)) ?? 0.0,
            'distancia_total_ruta_km'     => toFloatValue(getCellValue($row, $columns, ['distancia_total_ruta_km'], null)) ?? 0.0,
            'observacion'                 => trim((string)getCellValue($row, $columns, ['observacion'])),
        ];
    }

    if (empty($dataRows)) {
        jsonResponse([
            'success' => false,
            'message' => 'No se encontraron filas válidas en la hoja Planificacion.'
        ], 400);
    }

    usort($dataRows, function ($a, $b) {
        $cmpUsuario = strcmp((string)$a['usuario_nombre'], (string)$b['usuario_nombre']);
        if ($cmpUsuario !== 0) {
            return $cmpUsuario;
        }

        $cmpGrupo = strcmp((string)$a['grupo_ruta'], (string)$b['grupo_ruta']);
        if ($cmpGrupo !== 0) {
            return $cmpGrupo;
        }

        if ((int)$a['orden_visita'] !== (int)$b['orden_visita']) {
            return (int)$a['orden_visita'] <=> (int)$b['orden_visita'];
        }

        return strcmp((string)$a['codigo_local'], (string)$b['codigo_local']);
    });

    $groupMap = [];
    $usuariosMap = [];
    $fechasMap = [];

    foreach ($dataRows as $row) {
        $groupName = $row['grupo_ruta'];

        if (!isset($groupMap[$groupName])) {
            $groupMap[$groupName] = [
                'grupo_ruta'               => $groupName,
                'ruta_global'              => $row['ruta_global'],
                'usuario_id'               => $row['usuario_id'],
                'usuario_login'            => $row['usuario_login'],
                'usuario_nombre'           => $row['usuario_nombre'],
                'fecha_ruta'               => $row['fecha_ruta'],
                'fecha_ruta_sql'           => $row['fecha_ruta_sql'],
                'total_paradas'            => 0,
                'distancia_total_ruta_km'  => 0.0,
                'dia_plan'                 => $row['dia_plan'],
                'dia_semana'               => $row['dia_semana'],
                'semana_plan'              => $row['semana_plan'],
                'cantidad_objetivo_dia'    => $row['cantidad_objetivo_dia'],
            ];
        }

        $groupMap[$groupName]['total_paradas']++;

        if ((float)$row['distancia_total_ruta_km'] > 0) {
            $groupMap[$groupName]['distancia_total_ruta_km'] = (float)$row['distancia_total_ruta_km'];
        }

        $usuarioKey = $row['usuario_id'] !== '' ? $row['usuario_id'] : $row['usuario_login'];
        if ($usuarioKey !== '' && !isset($usuariosMap[$usuarioKey])) {
            $usuariosMap[$usuarioKey] = [
                'usuario_id' => $row['usuario_id'],
                'usuario_login' => $row['usuario_login'],
                'usuario_nombre' => $row['usuario_nombre'],
            ];
        }

        $fechaKey = $row['fecha_ruta_sql'] !== '' ? $row['fecha_ruta_sql'] : $row['fecha_ruta'];
        if ($fechaKey !== '' && !isset($fechasMap[$fechaKey])) {
            $fechasMap[$fechaKey] = [
                'fecha_ruta' => $row['fecha_ruta'],
                'fecha_ruta_sql' => $row['fecha_ruta_sql'],
            ];
        }
    }

    $groups = array_values($groupMap);
    $usuarios = array_values($usuariosMap);
    $fechas = array_values($fechasMap);

    usort($groups, function ($a, $b) {
        $cmpUsuario = strcmp((string)$a['usuario_nombre'], (string)$b['usuario_nombre']);
        if ($cmpUsuario !== 0) {
            return $cmpUsuario;
        }

        $cmpFecha = strcmp((string)$a['fecha_ruta_sql'], (string)$b['fecha_ruta_sql']);
        if ($cmpFecha !== 0) {
            return $cmpFecha;
        }

        return strcmp((string)$a['grupo_ruta'], (string)$b['grupo_ruta']);
    });

    usort($usuarios, function ($a, $b) {
        return strcmp((string)$a['usuario_nombre'], (string)$b['usuario_nombre']);
    });

    usort($fechas, function ($a, $b) {
        return strcmp((string)$a['fecha_ruta_sql'], (string)$b['fecha_ruta_sql']);
    });

    jsonResponse([
        'success' => true,
        'message' => 'Archivo procesado correctamente.',
        'summary' => [
            'archivo' => $originalName,
            'hoja_utilizada' => $sheet->getTitle(),
            'total_filas_validas' => count($dataRows),
            'total_grupos' => count($groups),
            'total_usuarios' => count($usuarios),
            'total_fechas' => count($fechas),
            'filas_ignoradas' => $ignoredRows,
        ],
        'filters' => [
            'usuarios' => $usuarios,
            'fechas' => $fechas,
            'grupos' => $groups,
        ],
        'groups' => $groups,
        'rows' => $dataRows,
    ]);

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error al leer el archivo: ' . $e->getMessage()
    ], 500);
}
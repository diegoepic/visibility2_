<?php
declare(strict_types=1);

session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('memory_limit', '512M');
set_time_limit(180);

ob_start();

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }

        echo "FATAL ERROR\n";
        echo "Tipo: {$error['type']}\n";
        echo "Mensaje: {$error['message']}\n";
        echo "Archivo: {$error['file']}\n";
        echo "Línea: {$error['line']}\n";
    }
});

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    http_response_code(500);
    exit('PhpSpreadsheet no está disponible aunque autoload.php fue cargado.');
}

mysqli_set_charset($conn, 'utf8mb4');
date_default_timezone_set('America/Santiago');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

function fail(string $message): void
{
    if (ob_get_length()) {
        ob_end_clean();
    }

    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

function cleanCodeArray($items): array
{
    if (!is_array($items)) {
        return [];
    }

    $out = [];
    foreach ($items as $item) {
        $code = trim((string)$item);
        if ($code !== '') {
            $out[] = $code;
        }
    }

    return array_values(array_unique($out));
}

function hasValidCoords(array $local): bool
{
    return isset($local['lat'], $local['lng'])
        && $local['lat'] !== null
        && $local['lng'] !== null
        && $local['lat'] !== ''
        && $local['lng'] !== ''
        && is_numeric((string)$local['lat'])
        && is_numeric((string)$local['lng']);
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function distanceBetweenPoints(array $a, array $b): float
{
    return haversineKm(
        (float)$a['lat'],
        (float)$a['lng'],
        (float)$b['lat'],
        (float)$b['lng']
    );
}

function calculateCentroid(array $group): array
{
    if (empty($group)) {
        return ['lat' => 0.0, 'lng' => 0.0];
    }

    $sumLat = 0.0;
    $sumLng = 0.0;

    foreach ($group as $local) {
        $sumLat += (float)$local['lat'];
        $sumLng += (float)$local['lng'];
    }

    return [
        'lat' => $sumLat / count($group),
        'lng' => $sumLng / count($group),
    ];
}

function distanceToCentroid(array $local, array $group): float
{
    $centroid = calculateCentroid($group);

    return haversineKm(
        (float)$local['lat'],
        (float)$local['lng'],
        (float)$centroid['lat'],
        (float)$centroid['lng']
    );
}

function minDistanceToGroup(array $local, array $group): float
{
    if (empty($group)) {
        return 0.0;
    }

    $best = PHP_FLOAT_MAX;

    foreach ($group as $member) {
        $dist = haversineKm(
            (float)$local['lat'],
            (float)$local['lng'],
            (float)$member['lat'],
            (float)$member['lng']
        );

        if ($dist < $best) {
            $best = $dist;
        }
    }

    return $best;
}

function distributeCapacities(int $total, int $groupCount): array
{
    if ($groupCount <= 0) {
        return [];
    }

    $base = intdiv($total, $groupCount);
    $remainder = $total % $groupCount;

    $capacities = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $capacities[$i] = $base + ($i < $remainder ? 1 : 0);
    }

    return $capacities;
}

function pickSeedIndexes(array $locales, int $groupCount): array
{
    $count = count($locales);

    if ($count === 0 || $groupCount <= 0) {
        return [];
    }

    if ($groupCount >= $count) {
        return range(0, $count - 1);
    }

    $seedIndexes = [];
    $globalCentroid = calculateCentroid($locales);

    $bestIndex = 0;
    $bestDistance = -1.0;

    foreach ($locales as $i => $local) {
        $dist = haversineKm(
            (float)$local['lat'],
            (float)$local['lng'],
            (float)$globalCentroid['lat'],
            (float)$globalCentroid['lng']
        );

        if ($dist > $bestDistance) {
            $bestDistance = $dist;
            $bestIndex = $i;
        }
    }

    $seedIndexes[] = $bestIndex;

    while (count($seedIndexes) < $groupCount) {
        $candidateIndex = null;
        $candidateScore = -1.0;

        foreach ($locales as $i => $local) {
            if (in_array($i, $seedIndexes, true)) {
                continue;
            }

            $minDistToExistingSeed = PHP_FLOAT_MAX;

            foreach ($seedIndexes as $seedIdx) {
                $dist = haversineKm(
                    (float)$local['lat'],
                    (float)$local['lng'],
                    (float)$locales[$seedIdx]['lat'],
                    (float)$locales[$seedIdx]['lng']
                );

                if ($dist < $minDistToExistingSeed) {
                    $minDistToExistingSeed = $dist;
                }
            }

            if ($minDistToExistingSeed > $candidateScore) {
                $candidateScore = $minDistToExistingSeed;
                $candidateIndex = $i;
            }
        }

        if ($candidateIndex === null) {
            break;
        }

        $seedIndexes[] = $candidateIndex;
    }

    return $seedIndexes;
}

function buildNearestNeighborRoute(array $group, int $startIndex = 0): array
{
    if (count($group) <= 1) {
        return $group;
    }

    $remaining = array_values($group);
    $startIndex = max(0, min($startIndex, count($remaining) - 1));

    $route = [];
    $current = $remaining[$startIndex];
    $route[] = $current;
    array_splice($remaining, $startIndex, 1);

    while (!empty($remaining)) {
        $bestIndex = 0;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($remaining as $i => $candidate) {
            $dist = haversineKm(
                (float)$current['lat'],
                (float)$current['lng'],
                (float)$candidate['lat'],
                (float)$candidate['lng']
            );

            if ($dist < $bestDistance) {
                $bestDistance = $dist;
                $bestIndex = $i;
            }
        }

        $current = $remaining[$bestIndex];
        $route[] = $current;
        array_splice($remaining, $bestIndex, 1);
    }

    return $route;
}

function estimateRouteDistanceKm(array $orderedGroup): float
{
    $total = 0.0;

    for ($i = 1; $i < count($orderedGroup); $i++) {
        $total += haversineKm(
            (float)$orderedGroup[$i - 1]['lat'],
            (float)$orderedGroup[$i - 1]['lng'],
            (float)$orderedGroup[$i]['lat'],
            (float)$orderedGroup[$i]['lng']
        );
    }

    return $total;
}

function orderGroupByBestStart(array $group): array
{
    if (count($group) <= 2) {
        return buildNearestNeighborRoute($group, 0);
    }

    $centroid = calculateCentroid($group);

    $nearestIdx = 0;
    $nearestDist = PHP_FLOAT_MAX;

    $farthestIdx = 0;
    $farthestDist = -1.0;

    foreach ($group as $i => $local) {
        $dist = haversineKm(
            (float)$local['lat'],
            (float)$local['lng'],
            (float)$centroid['lat'],
            (float)$centroid['lng']
        );

        if ($dist < $nearestDist) {
            $nearestDist = $dist;
            $nearestIdx = $i;
        }

        if ($dist > $farthestDist) {
            $farthestDist = $dist;
            $farthestIdx = $i;
        }
    }

    $routeA = buildNearestNeighborRoute($group, $nearestIdx);
    $routeB = buildNearestNeighborRoute($group, $farthestIdx);

    return estimateRouteDistanceKm($routeA) <= estimateRouteDistanceKm($routeB)
        ? $routeA
        : $routeB;
}

function buildBalancedDistanceGroups(array $locales, int $groupCount): array
{
    if ($groupCount <= 0 || empty($locales)) {
        return [];
    }

    if ($groupCount >= count($locales)) {
        $groups = [];
        foreach ($locales as $local) {
            $groups[] = [$local];
        }
        return $groups;
    }

    $capacities = distributeCapacities(count($locales), $groupCount);
    $seedIndexes = pickSeedIndexes($locales, $groupCount);

    $groups = array_fill(0, $groupCount, []);
    $usedIndexes = [];

    foreach ($seedIndexes as $groupIdx => $seedIndex) {
        $groups[$groupIdx][] = $locales[$seedIndex];
        $usedIndexes[$seedIndex] = true;
    }

    $remaining = [];
    foreach ($locales as $i => $local) {
        if (!isset($usedIndexes[$i])) {
            $remaining[] = $local;
        }
    }

    usort($remaining, function ($a, $b) use ($groups) {
        $distA = PHP_FLOAT_MAX;
        $distB = PHP_FLOAT_MAX;

        foreach ($groups as $group) {
            $dA = minDistanceToGroup($a, $group);
            $dB = minDistanceToGroup($b, $group);

            if ($dA < $distA) {
                $distA = $dA;
            }
            if ($dB < $distB) {
                $distB = $dB;
            }
        }

        return $distB <=> $distA;
    });

    foreach ($remaining as $local) {
        $bestGroupIdx = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($groups as $groupIdx => $group) {
            if (count($group) >= $capacities[$groupIdx]) {
                continue;
            }

            $distCentroid = distanceToCentroid($local, $group);
            $distNearest = minDistanceToGroup($local, $group);
            $fillRatio = count($group) / max(1, $capacities[$groupIdx]);

            $score = ($distCentroid * 0.60) + ($distNearest * 0.35) + ($fillRatio * 5);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestGroupIdx = $groupIdx;
            }
        }

        if ($bestGroupIdx === null) {
            $bestGroupIdx = 0;
        }

        $groups[$bestGroupIdx][] = $local;
    }

    usort($groups, function ($a, $b) {
        $ca = calculateCentroid($a);
        $cb = calculateCentroid($b);

        $cmpLat = $cb['lat'] <=> $ca['lat'];
        if ($cmpLat !== 0) {
            return $cmpLat;
        }

        return $ca['lng'] <=> $cb['lng'];
    });

    foreach ($groups as $idx => $group) {
        $groups[$idx] = orderGroupByBestStart($group);
    }

    return $groups;
}

function normalizeGeoKey(string $text): string
{
    $text = trim($text);

    $map = [
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'Ñ'=>'N','ñ'=>'n'
    ];

    $text = strtr($text, $map);
    $text = strtolower($text);
    $text = preg_replace('/\s+/', ' ', $text);

    return $text === '' ? 'sin_comuna' : $text;
}

function bucketLocalesByComuna(array $locales): array
{
    $buckets = [];

    foreach ($locales as $local) {
        $displayComuna = trim((string)($local['comuna'] ?? ''));
        if ($displayComuna === '') {
            $displayComuna = 'SIN COMUNA';
        }

        $key = normalizeGeoKey($displayComuna);

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'comuna'  => $displayComuna,
                'locales' => []
            ];
        }

        $buckets[$key]['locales'][] = $local;
    }

    return $buckets;
}

function allocateGroupCountsByComuna(array $buckets, int $groupCount): array
{
    if ($groupCount <= 0 || empty($buckets)) {
        return [];
    }

    $totalLocales = 0;
    foreach ($buckets as $bucket) {
        $totalLocales += count($bucket['locales']);
    }

    if ($totalLocales <= 0) {
        return [];
    }

    $alloc = [];
    $meta = [];

    foreach ($buckets as $key => $bucket) {
        $count = count($bucket['locales']);
        $exact = ($count / $totalLocales) * $groupCount;
        $base  = (int) floor($exact);

        $alloc[$key] = $base;
        $meta[$key] = [
            'count'     => $count,
            'exact'     => $exact,
            'remainder' => $exact - $base
        ];
    }

    // Si hay suficientes rutas para dar al menos 1 por comuna, hacerlo
    if ($groupCount >= count($buckets)) {
        foreach ($buckets as $key => $bucket) {
            if (($alloc[$key] ?? 0) < 1 && count($bucket['locales']) > 0) {
                $alloc[$key] = 1;
            }
        }
    }

    $assigned = array_sum($alloc);
    $remaining = $groupCount - $assigned;

    uasort($meta, function ($a, $b) {
        $cmp = $b['remainder'] <=> $a['remainder'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return $b['count'] <=> $a['count'];
    });

    while ($remaining > 0) {
        foreach ($meta as $key => $info) {
            if ($remaining <= 0) {
                break;
            }

            $alloc[$key] = ($alloc[$key] ?? 0) + 1;
            $remaining--;
        }
    }

    return $alloc;
}

function buildCommuneFirstGroups(array $locales, int $groupCount): array
{
    if ($groupCount <= 0 || empty($locales)) {
        return [];
    }

    if ($groupCount >= count($locales)) {
        $groups = [];
        foreach ($locales as $local) {
            $groups[] = [$local];
        }
        return $groups;
    }

    $buckets = bucketLocalesByComuna($locales);

    // Ordenar comunas por cantidad de locales (más grandes primero)
    uasort($buckets, function ($a, $b) {
        return count($b['locales']) <=> count($a['locales']);
    });

    $allocations = allocateGroupCountsByComuna($buckets, $groupCount);

    $groups = [];
    $overflowBuckets = [];

    foreach ($buckets as $key => $bucket) {
        $bucketLocales = $bucket['locales'];
        $bucketRouteCount = min((int)($allocations[$key] ?? 0), count($bucketLocales));

        if ($bucketRouteCount <= 0) {
            $overflowBuckets[] = $bucket;
            continue;
        }

        // Aquí reutilizamos tu agrupador existente, pero solo dentro de la misma comuna
        $subGroups = buildBalancedDistanceGroups($bucketLocales, $bucketRouteCount);

        foreach ($subGroups as $subGroup) {
            $groups[] = $subGroup;
        }
    }

    // Si hubo comunas que no alcanzaron a tener ruta propia,
    // recién aquí se mezclan con el grupo más cercano.
    if (!empty($overflowBuckets) && !empty($groups)) {
        foreach ($overflowBuckets as $bucket) {
            foreach ($bucket['locales'] as $local) {
                $bestGroupIdx = null;
                $bestScore = PHP_FLOAT_MAX;

                foreach ($groups as $groupIdx => $group) {
                    $score = (distanceToCentroid($local, $group) * 0.70)
                           + (minDistanceToGroup($local, $group) * 0.30);

                    if ($score < $bestScore) {
                        $bestScore = $score;
                        $bestGroupIdx = $groupIdx;
                    }
                }

                if ($bestGroupIdx === null) {
                    $bestGroupIdx = 0;
                }

                $groups[$bestGroupIdx][] = $local;
            }
        }
    }

    // Orden final visual de norte a sur / oeste a este
    usort($groups, function ($a, $b) {
        $ca = calculateCentroid($a);
        $cb = calculateCentroid($b);

        $cmpLat = $cb['lat'] <=> $ca['lat'];
        if ($cmpLat !== 0) {
            return $cmpLat;
        }

        return $ca['lng'] <=> $cb['lng'];
    });

    // Reordenar secuencia interna de visita
    foreach ($groups as $idx => $group) {
        $groups[$idx] = orderGroupByBestStart($group);
    }

    return $groups;
}

function styleHeader($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1F4E78']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'D9D9D9']
            ]
        ]
    ]);
}

function styleDataRange($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E5E5E5']
            ]
        ]
    ]);
}

function setFixedColumnsWidth($sheet, array $widths): void
{
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
}

$frecuenciaSemanas = (int)($_POST['frecuencia_semanas'] ?? 0);
if ($frecuenciaSemanas < 1) {
    fail('Frecuencia inválida.');
}

$codigosJson = $_POST['codigos_json'] ?? '[]';
$codigos = json_decode($codigosJson, true);

if (!is_array($codigos)) {
    fail('No se pudieron leer los códigos.');
}

$codigos = cleanCodeArray($codigos);
if (empty($codigos)) {
    fail('No se recibieron códigos para planificar.');
}

$placeholders = implode(',', array_fill(0, count($codigos), '?'));
$types = str_repeat('s', count($codigos));

$sql = "
    SELECT
        l.codigo,
        l.nombre,
        l.direccion,
        c.comuna,
        l.lat,
        l.lng
    FROM local l
    LEFT JOIN comuna c
        ON c.id = l.id_comuna
    WHERE l.codigo IN ($placeholders)
      AND l.deleted_at IS NULL
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    fail('Error al preparar consulta: ' . $conn->error);
}

$stmt->bind_param($types, ...$codigos);

if (!$stmt->execute()) {
    fail('Error al ejecutar consulta: ' . $stmt->error);
}

$result = $stmt->get_result();

$locales = [];
$codigosEncontrados = [];

while ($row = $result->fetch_assoc()) {
    $codigo = trim((string)$row['codigo']);
    $codigosEncontrados[] = $codigo;

    $locales[] = [
        'codigo'    => $codigo,
        'nombre'    => $row['nombre'] ?? '',
        'direccion' => $row['direccion'] ?? '',
        'comuna'    => $row['comuna'] ?? '',
        'lat'       => $row['lat'],
        'lng'       => $row['lng']
    ];
}

$stmt->close();

$codigosEncontrados = array_values(array_unique($codigosEncontrados));
$codigosNoEncontrados = array_values(array_diff($codigos, $codigosEncontrados));

$localesConCoords = [];
$localesSinCoords = [];

foreach ($locales as $local) {
    if (hasValidCoords($local)) {
        $localesConCoords[] = $local;
    } else {
        $localesSinCoords[] = $local;
    }
}

$diasLaboralesCiclo = $frecuenciaSemanas * 5;
$cantidadLocalesGeoref = count($localesConCoords);
$groupCount = ($cantidadLocalesGeoref > 0)
    ? min($diasLaboralesCiclo, $cantidadLocalesGeoref)
    : 0;

$groups = [];
if ($groupCount > 0 && !empty($localesConCoords)) {
    $groups = buildCommuneFirstGroups($localesConCoords, $groupCount);
}

$dayNames = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
];

$planRows = [];
$routeGroup = 0;
$totalKmEstimado = 0.0;

foreach ($groups as $group) {
    $routeGroup++;
    $diaCiclo = $routeGroup;
    $semanaPlan = intdiv($diaCiclo - 1, 5) + 1;
    $diaSemanaNum = (($diaCiclo - 1) % 5) + 1;
    $diaSemanaNombre = $dayNames[$diaSemanaNum] ?? 'N/D';

    $distanciaRutaKm = round(estimateRouteDistanceKm($group), 2);
    $totalKmEstimado += $distanciaRutaKm;

    foreach ($group as $order => $local) {
        $distAnterior = 0.0;

        if ($order > 0) {
            $prev = $group[$order - 1];
            $distAnterior = round(haversineKm(
                (float)$prev['lat'],
                (float)$prev['lng'],
                (float)$local['lat'],
                (float)$local['lng']
            ), 2);
        }

        $planRows[] = [
            'codigo'                   => $local['codigo'],
            'nombre'                   => $local['nombre'],
            'direccion'                => $local['direccion'],
            'comuna'                   => $local['comuna'],
            'lat'                      => (float)$local['lat'],
            'lng'                      => (float)$local['lng'],
            'ciclo_semanas'            => $frecuenciaSemanas,
            'dias_ciclo'               => $diasLaboralesCiclo,
            'grupo_ruta'               => 'RUTA ' . str_pad((string)$routeGroup, 2, '0', STR_PAD_LEFT),
            'dia_ciclo'                => $diaCiclo,
            'semana_plan'              => $semanaPlan,
            'dia_semana_num'           => $diaSemanaNum,
            'dia_semana'               => $diaSemanaNombre,
            'orden_visita'             => $order + 1,
            'tamano_ruta'              => count($group),
            'distancia_desde_anterior' => $distAnterior,
            'distancia_ruta_km'        => $distanciaRutaKm,
            'observacion'              => 'Ruta priorizada por comuna y luego por proximidad geográfica'
        ];
    }
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

//
// HOJA 1: RESUMEN
//
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen');

$sheet->fromArray([
    ['Campo', 'Valor'],
    ['Fecha generación', date('d-m-Y H:i:s')],
    ['Frecuencia objetivo (semanas)', $frecuenciaSemanas],
    ['Días laborales por ciclo', $diasLaboralesCiclo],
    ['Códigos recibidos', count($codigos)],
    ['Locales encontrados', count($locales)],
    ['Locales con coordenadas', count($localesConCoords)],
    ['Locales sin coordenadas', count($localesSinCoords)],
    ['Códigos no encontrados', count($codigosNoEncontrados)],
    ['Grupos/rutas generadas', count($groups)],
    ['Promedio estimado locales por día', $diasLaboralesCiclo > 0 ? ceil(count($localesConCoords) / $diasLaboralesCiclo) : 0],
    ['KM totales estimados', round($totalKmEstimado, 2)],
    ['Promedio KM por ruta', count($groups) > 0 ? round($totalKmEstimado / count($groups), 2) : 0],
    ['Criterio de agrupación', 'Prioridad por comuna, luego compacidad geográfica y balance de carga'],
], null, 'A1');

styleHeader($sheet, 'A1:B1');
styleDataRange($sheet, 'A2:B14');
$sheet->freezePane('A2');
setFixedColumnsWidth($sheet, [
    'A' => 35,
    'B' => 40,
]);

//
// HOJA 2: PLANIFICACION
//
$sheetPlan = $spreadsheet->createSheet();
$sheetPlan->setTitle('Planificacion');

$headersPlan = [
    'Código Local',
    'Nombre',
    'Dirección',
    'Comuna',
    'Lat',
    'Lng',
    'Ciclo Semanas',
    'Días Ciclo',
    'Grupo Ruta',
    'Día del Ciclo',
    'Semana Plan',
    'Día Semana Nº',
    'Día Semana',
    'Orden Visita',
    'Tamaño Ruta',
    'Distancia Desde Anterior (KM)',
    'Distancia Total Ruta (KM)',
    'Observación',
];

$sheetPlan->fromArray([$headersPlan], null, 'A1');

$rowIndex = 2;
foreach ($planRows as $row) {
    $sheetPlan->fromArray([[
        $row['codigo'],
        $row['nombre'],
        $row['direccion'],
        $row['comuna'],
        $row['lat'],
        $row['lng'],
        $row['ciclo_semanas'],
        $row['dias_ciclo'],
        $row['grupo_ruta'],
        $row['dia_ciclo'],
        $row['semana_plan'],
        $row['dia_semana_num'],
        $row['dia_semana'],
        $row['orden_visita'],
        $row['tamano_ruta'],
        $row['distancia_desde_anterior'],
        $row['distancia_ruta_km'],
        $row['observacion'],
    ]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetPlan, 'A1:R1');
if ($rowIndex > 2) {
    styleDataRange($sheetPlan, 'A2:R' . ($rowIndex - 1));
}
$sheetPlan->freezePane('A2');
setFixedColumnsWidth($sheetPlan, [
    'A' => 18,
    'B' => 28,
    'C' => 35,
    'D' => 20,
    'E' => 14,
    'F' => 14,
    'G' => 14,
    'H' => 14,
    'I' => 16,
    'J' => 14,
    'K' => 14,
    'L' => 14,
    'M' => 16,
    'N' => 14,
    'O' => 14,
    'P' => 24,
    'Q' => 24,
    'R' => 45,
]);

//
// HOJA 3: SIN COORDENADAS
//
$sheetSin = $spreadsheet->createSheet();
$sheetSin->setTitle('Sin Coordenadas');

$sheetSin->fromArray([[
    'Código Local',
    'Nombre',
    'Dirección',
    'Comuna',
    'Motivo'
]], null, 'A1');

$rowIndex = 2;
foreach ($localesSinCoords as $local) {
    $sheetSin->fromArray([[
        $local['codigo'],
        $local['nombre'],
        $local['direccion'],
        $local['comuna'],
        'Existe en sistema pero no tiene lat/lng válidos'
    ]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetSin, 'A1:E1');
if ($rowIndex > 2) {
    styleDataRange($sheetSin, 'A2:E' . ($rowIndex - 1));
}
$sheetSin->freezePane('A2');
setFixedColumnsWidth($sheetSin, [
    'A' => 18,
    'B' => 28,
    'C' => 35,
    'D' => 20,
    'E' => 45,
]);

//
// HOJA 4: NO ENCONTRADOS
//
$sheetNo = $spreadsheet->createSheet();
$sheetNo->setTitle('No Encontrados');

$sheetNo->fromArray([[
    'Código'
]], null, 'A1');

$rowIndex = 2;
foreach ($codigosNoEncontrados as $codigo) {
    $sheetNo->fromArray([[$codigo]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetNo, 'A1:A1');
if ($rowIndex > 2) {
    styleDataRange($sheetNo, 'A2:A' . ($rowIndex - 1));
}
$sheetNo->freezePane('A2');
setFixedColumnsWidth($sheetNo, [
    'A' => 20,
]);

$filename = 'propuesta_ruta_' . date('Ymd_His') . '.xlsx';

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión ZIP no está habilitada en PHP. PhpSpreadsheet la necesita para generar XLSX.');
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error generando Excel:\n";
    echo $e->getMessage() . "\n\n";
    echo $e->getFile() . ' línea ' . $e->getLine();
    exit;
}
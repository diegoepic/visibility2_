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

function countDistinctComunas(array $group): int
{
    $keys = [];

    foreach ($group as $local) {
        $keys[normalizeGeoKey((string)($local['comuna'] ?? ''))] = true;
    }

    return count($keys);
}

function calculateMedoid(array $group): array
{
    if (empty($group)) {
        return ['lat' => 0.0, 'lng' => 0.0];
    }

    if (count($group) === 1) {
        return $group[0];
    }

    $bestIdx = 0;
    $bestScore = PHP_FLOAT_MAX;

    foreach ($group as $i => $candidate) {
        $sum = 0.0;

        foreach ($group as $j => $other) {
            if ($i === $j) {
                continue;
            }

            $sum += haversineKm(
                (float)$candidate['lat'],
                (float)$candidate['lng'],
                (float)$other['lat'],
                (float)$other['lng']
            );
        }

        if ($sum < $bestScore) {
            $bestScore = $sum;
            $bestIdx = $i;
        }
    }

    return $group[$bestIdx];
}

function maxDistanceToMedoid(array $group): float
{
    if (count($group) <= 1) {
        return 0.0;
    }

    $medoid = calculateMedoid($group);
    $max = 0.0;

    foreach ($group as $local) {
        $dist = haversineKm(
            (float)$medoid['lat'],
            (float)$medoid['lng'],
            (float)$local['lat'],
            (float)$local['lng']
        );

        if ($dist > $max) {
            $max = $dist;
        }
    }

    return $max;
}

function groupDiameterKm(array $group): float
{
    $count = count($group);

    if ($count <= 1) {
        return 0.0;
    }

    $max = 0.0;

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $dist = haversineKm(
                (float)$group[$i]['lat'],
                (float)$group[$i]['lng'],
                (float)$group[$j]['lat'],
                (float)$group[$j]['lng']
            );

            if ($dist > $max) {
                $max = $dist;
            }
        }
    }

    return $max;
}

function routeViolatesConstraints(array $group, array $opts): bool
{
    if (empty($group)) {
        return false;
    }

    $maxDiameterKm   = (float)($opts['max_diameter_km'] ?? 0);
    $maxRadiusKm     = (float)($opts['max_radius_km'] ?? 0);
    $maxComunasRuta  = (int)($opts['max_comunas_ruta'] ?? 0);

    if ($maxDiameterKm > 0 && groupDiameterKm($group) > $maxDiameterKm) {
        return true;
    }

    if ($maxRadiusKm > 0 && maxDistanceToMedoid($group) > $maxRadiusKm) {
        return true;
    }

    if ($maxComunasRuta > 0 && countDistinctComunas($group) > $maxComunasRuta) {
        return true;
    }

    return false;
}

function splitGroupByConstraints(array $orderedGroup, array $opts): array
{
    if (empty($orderedGroup)) {
        return [];
    }

    $maxJumpKm = (float)($opts['max_jump_km'] ?? 0);

    $chunks = [[$orderedGroup[0]]];

    for ($i = 1; $i < count($orderedGroup); $i++) {
        $currentChunkIdx = count($chunks) - 1;
        $prev = $orderedGroup[$i - 1];
        $curr = $orderedGroup[$i];

        $jumpKm = haversineKm(
            (float)$prev['lat'],
            (float)$prev['lng'],
            (float)$curr['lat'],
            (float)$curr['lng']
        );

        $candidateChunk = $chunks[$currentChunkIdx];
        $candidateChunk[] = $curr;

        $breakByJump  = ($maxJumpKm > 0 && $jumpKm > $maxJumpKm);
        $breakByShape = routeViolatesConstraints($candidateChunk, $opts);

        if ($breakByJump || $breakByShape) {
            $chunks[] = [$curr];
        } else {
            $chunks[$currentChunkIdx][] = $curr;
        }
    }

    return $chunks;
}

function averageDistanceToKNearest(array $local, array $allLocales, int $k = 3): float
{
    $distances = [];

    foreach ($allLocales as $other) {
        if (($other['codigo'] ?? null) === ($local['codigo'] ?? null)) {
            continue;
        }

        $distances[] = haversineKm(
            (float)$local['lat'],
            (float)$local['lng'],
            (float)$other['lat'],
            (float)$other['lng']
        );
    }

    if (empty($distances)) {
        return 0.0;
    }

    sort($distances, SORT_NUMERIC);
    $slice = array_slice($distances, 0, max(1, $k));

    return array_sum($slice) / count($slice);
}

function partitionSuspiciousOutliers(array $locales, float $thresholdKm = 0.0, int $k = 3): array
{
    if ($thresholdKm <= 0 || count($locales) <= $k) {
        return [$locales, []];
    }

    $valid = [];
    $suspicious = [];

    foreach ($locales as $local) {
        $avg = averageDistanceToKNearest($local, $locales, $k);

        if ($avg > $thresholdKm) {
            $local['_motivo_sospechoso'] = 'Promedio a ' . $k . ' vecinos más cercanos: ' . round($avg, 2) . ' km';
            $suspicious[] = $local;
        } else {
            $valid[] = $local;
        }
    }

    return [$valid, $suspicious];
}

function getMaxPerDay(int $targetPerDay): int
{
    return $targetPerDay + max(1, (int) ceil($targetPerDay * 0.15));
}

function buildCapacitiesByTarget(int $totalLocales, int $targetPerDay): array
{
    if ($totalLocales <= 0 || $targetPerDay <= 0) {
        return [];
    }

    $maxPerDay = getMaxPerDay($targetPerDay);
    $groupCount = max(1, (int) ceil($totalLocales / $maxPerDay));

    $base = intdiv($totalLocales, $groupCount);
    $remainder = $totalLocales % $groupCount;

    $capacities = [];
    for ($i = 0; $i < $groupCount; $i++) {
        $capacities[] = $base + ($i < $remainder ? 1 : 0);
    }

    rsort($capacities);

    return $capacities;
}

function sameComuna(array $a, array $b): bool
{
    return normalizeGeoKey((string)($a['comuna'] ?? '')) === normalizeGeoKey((string)($b['comuna'] ?? ''));
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

function sortGroupsByPriority(array $groups): array
{
    if (empty($groups)) {
        return [];
    }

    $meta = [];

    foreach ($groups as $idx => $group) {
        if (empty($group)) {
            continue;
        }

        $meta[] = [
            'original_index' => $idx,
            'group'          => $group,
            'size'           => count($group),
            'km'             => estimateRouteDistanceKm($group),
        ];
    }

    usort($meta, function ($a, $b) {
        $cmp = $b['size'] <=> $a['size'];
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $b['km'] <=> $a['km'];
        if ($cmp !== 0) {
            return $cmp;
        }

        return $a['original_index'] <=> $b['original_index'];
    });

    return array_values(array_map(
        static fn($item) => $item['group'],
        $meta
    ));
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

function buildBalancedDistanceGroups(array $locales, array $capacities, array $opts = []): array
{
    if (empty($locales) || empty($capacities)) {
        return [];
    }

    $groupCount = count($capacities);
    $defaultCapacity = max(1, (int)max($capacities));
    $maxJumpKm = (float)($opts['max_jump_km'] ?? 0);

    if ($groupCount >= count($locales)) {
        $groups = [];
        foreach ($locales as $local) {
            $groups[] = [$local];
        }
        return $groups;
    }

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
            if (empty($group)) {
                continue;
            }

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
            $groupCapacity = $capacities[$groupIdx] ?? $defaultCapacity;

            if (count($group) >= $groupCapacity) {
                continue;
            }

            $distNearest = minDistanceToGroup($local, $group);

            if (!empty($group) && $maxJumpKm > 0 && $distNearest > $maxJumpKm) {
                continue;
            }

            $candidateGroup = $group;
            $candidateGroup[] = $local;

            if (routeViolatesConstraints($candidateGroup, $opts)) {
                continue;
            }

            $distCentroid = distanceToCentroid($local, $group);
            $fillRatio    = count($group) / max(1, $groupCapacity);

            $sameComunaBonus = 0.0;
            foreach ($group as $member) {
                if (sameComuna($local, $member)) {
                    $sameComunaBonus = -2.5;
                    break;
                }
            }

            $diameterAfter = groupDiameterKm($candidateGroup);
            $radiusAfter   = maxDistanceToMedoid($candidateGroup);

            $score = ($distCentroid * 0.42)
                   + ($distNearest  * 0.28)
                   + ($fillRatio    * 4.50)
                   + ($diameterAfter * 0.18)
                   + ($radiusAfter   * 0.20)
                   + $sameComunaBonus;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestGroupIdx = $groupIdx;
            }
        }

        if ($bestGroupIdx === null) {
            $groups[] = [$local];
            $capacities[] = $defaultCapacity;
            continue;
        }

        $groups[$bestGroupIdx][] = $local;
    }

    $finalGroups = [];

    foreach ($groups as $group) {
        if (empty($group)) {
            continue;
        }

        $orderedGroup = orderGroupByBestStart($group);
        $chunks = splitGroupByConstraints($orderedGroup, $opts);

        foreach ($chunks as $chunk) {
            if (!empty($chunk)) {
                $finalGroups[] = $chunk;
            }
        }
    }

    usort($finalGroups, function ($a, $b) {
        $ca = calculateCentroid($a);
        $cb = calculateCentroid($b);

        $cmpLat = $cb['lat'] <=> $ca['lat'];
        if ($cmpLat !== 0) {
            return $cmpLat;
        }

        return $ca['lng'] <=> $cb['lng'];
    });

    return $finalGroups;
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

function buildCommuneFirstGroups(array $locales, int $targetPerDay, array $opts = []): array
{
    if ($targetPerDay <= 0 || empty($locales)) {
        return [];
    }

    $capacities = buildCapacitiesByTarget(count($locales), $targetPerDay);
    if (empty($capacities)) {
        return [];
    }

    $buckets = bucketLocalesByComuna($locales);

    uasort($buckets, function ($a, $b) {
        return count($b['locales']) <=> count($a['locales']);
    });

    $orderedLocales = [];

    foreach ($buckets as $bucket) {
        $bucketOrdered = orderGroupByBestStart($bucket['locales']);
        foreach ($bucketOrdered as $local) {
            $orderedLocales[] = $local;
        }
    }

    return buildBalancedDistanceGroups($orderedLocales, $capacities, $opts);
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

function addBusinessDays(DateTime $date, int $daysToAdd): DateTime
{
    $result = clone $date;

    while ($daysToAdd > 0) {
        $result->modify('+1 day');
        $dayOfWeek = (int)$result->format('N');
        if ($dayOfWeek <= 5) {
            $daysToAdd--;
        }
    }

    return $result;
}

/**/
function getGroupProposalPriorityMeta(array $group): array
{
    $sinFechaCount = 0;
    $oldestTs = PHP_INT_MAX;
    $sumDays = 0;
    $daysCount = 0;

    foreach ($group as $local) {
        $fecha = trim((string)($local['ultima_fecha_propuesta_sql'] ?? ''));

        if ($fecha === '') {
            $sinFechaCount++;
            continue;
        }

        $ts = strtotime($fecha);
        if ($ts !== false && $ts < $oldestTs) {
            $oldestTs = $ts;
        }

        if (isset($local['dias_desde_ultima_propuesta']) && $local['dias_desde_ultima_propuesta'] !== null) {
            $sumDays += (int)$local['dias_desde_ultima_propuesta'];
            $daysCount++;
        }
    }

    return [
        'sin_fecha_count' => $sinFechaCount,
        'oldest_ts' => $oldestTs === PHP_INT_MAX ? null : $oldestTs,
        'avg_days' => $daysCount > 0 ? ($sumDays / $daysCount) : null,
        'size' => count($group),
        'km' => estimateRouteDistanceKm($group),
    ];
}

function sortGroupsByProposalPriority(array $groups): array
{
    usort($groups, function ($a, $b) {
        $ma = getGroupProposalPriorityMeta($a);
        $mb = getGroupProposalPriorityMeta($b);

        // 1) Prioridad máxima para rutas con locales sin fecha previa
        if ($ma['sin_fecha_count'] !== $mb['sin_fecha_count']) {
            return $mb['sin_fecha_count'] <=> $ma['sin_fecha_count'];
        }

        // 2) Ruta con fecha más antigua primero
        $ta = $ma['oldest_ts'] ?? PHP_INT_MAX;
        $tb = $mb['oldest_ts'] ?? PHP_INT_MAX;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }

        // 3) Mayor promedio de antigüedad primero
        $aa = $ma['avg_days'] ?? -1;
        $ab = $mb['avg_days'] ?? -1;
        if ($aa !== $ab) {
            return $ab <=> $aa;
        }

        // 4) Desempate: ruta más grande primero
        if ($ma['size'] !== $mb['size']) {
            return $mb['size'] <=> $ma['size'];
        }

        // 5) Desempate final: ruta más larga primero
        return $mb['km'] <=> $ma['km'];
    });

    return $groups;
}

$cantidadPorDia = (int)($_POST['cantidad_por_dia'] ?? 0);
if ($cantidadPorDia < 1) {
    fail('Cantidad por día inválida.');
}

$fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
if ($fechaInicio === '') {
    fail('Debes indicar una fecha de inicio.');
}

$dtFechaInicio = DateTime::createFromFormat('Y-m-d', $fechaInicio);
if (!$dtFechaInicio || $dtFechaInicio->format('Y-m-d') !== $fechaInicio) {
    fail('La fecha de inicio no es válida.');
}

$maxKmEntrePuntos = isset($_POST['max_km_ruta']) && is_numeric($_POST['max_km_ruta'])
    ? max(1.0, (float)$_POST['max_km_ruta'])
    : 30.0;

$maxDiameterKm = isset($_POST['max_diameter_km']) && is_numeric($_POST['max_diameter_km'])
    ? max(1.0, (float)$_POST['max_diameter_km'])
    : 20.0;

$maxRadiusKm = isset($_POST['max_radius_km']) && is_numeric($_POST['max_radius_km'])
    ? max(1.0, (float)$_POST['max_radius_km'])
    : 10.0;

$maxComunasRuta = isset($_POST['max_comunas_ruta']) && is_numeric($_POST['max_comunas_ruta'])
    ? max(1, (int)$_POST['max_comunas_ruta'])
    : 2;

$outlierKnnKm = isset($_POST['outlier_knn_km']) && is_numeric($_POST['outlier_knn_km'])
    ? max(0.0, (float)$_POST['outlier_knn_km'])
    : 0.0;

$routeOptions = [
    'max_jump_km'      => $maxKmEntrePuntos,
    'max_diameter_km'  => $maxDiameterKm,
    'max_radius_km'    => $maxRadiusKm,
    'max_comunas_ruta' => $maxComunasRuta,
    'outlier_knn_km'   => $outlierKnnKm,
];

$registrosJson = $_POST['registros_json'] ?? '[]';
$registros = json_decode($registrosJson, true);

if (!is_array($registros) || empty($registros)) {
    fail('No se pudieron leer los registros para planificar.');
}

$registrosNormalizados = [];
$codigos = [];

foreach ($registros as $item) {
    if (!is_array($item)) {
        continue;
    }

    $codigo = trim((string)($item['codigo'] ?? ''));
    if ($codigo === '') {
        continue;
    }

    $usuarioId = (int)($item['usuario_id'] ?? 0);
    $usuarioLogin = trim((string)($item['usuario_login'] ?? ''));
    $usuarioNombre = trim((string)($item['usuario_nombre'] ?? ''));
    $usuarioInput = trim((string)($item['usuario_input'] ?? ''));

    if ($usuarioId <= 0) {
        continue;
    }

    $registrosNormalizados[] = [
        'codigo'         => $codigo,
        'usuario_id'     => $usuarioId,
        'usuario_login'  => $usuarioLogin,
        'usuario_nombre' => $usuarioNombre,
        'usuario_input'  => $usuarioInput,
    ];

    $codigos[] = $codigo;
}

$codigos = cleanCodeArray($codigos);

if (empty($registrosNormalizados) || empty($codigos)) {
    fail('No se recibieron registros válidos con usuario asociado.');
}

$placeholders = implode(',', array_fill(0, count($codigos), '?'));
$types = str_repeat('s', count($codigos));

$sql = "
    SELECT
        l.id AS id_local,
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

$localesPorCodigo = [];
while ($row = $result->fetch_assoc()) {
    $codigo = trim((string)$row['codigo']);
    $localesPorCodigo[$codigo] = [
        'id_local'   => (int)$row['id_local'],
        'codigo'     => $codigo,
        'nombre'     => $row['nombre'] ?? '',
        'direccion'  => $row['direccion'] ?? '',
        'comuna'     => $row['comuna'] ?? '',
        'lat'        => $row['lat'],
        'lng'        => $row['lng']
    ];
}
$stmt->close();

$locales = [];
$registrosNoEncontrados = [];

foreach ($registrosNormalizados as $registro) {
    $codigo = $registro['codigo'];

    if (!isset($localesPorCodigo[$codigo])) {
        $registrosNoEncontrados[] = $registro;
        continue;
    }

    $local = $localesPorCodigo[$codigo];
    $local['usuario_id'] = $registro['usuario_id'];
    $local['usuario_login'] = $registro['usuario_login'];
    $local['usuario_nombre'] = $registro['usuario_nombre'];
    $local['usuario_input'] = $registro['usuario_input'];

    $locales[] = $local;
}

$codigosNoEncontrados = array_values(array_unique(array_map(
    static fn($r) => $r['codigo'],
    $registrosNoEncontrados
)));

$idsLocales = [];
foreach ($locales as $local) {
    if (!empty($local['id_local'])) {
        $idsLocales[] = (int)$local['id_local'];
    }
}
$idsLocales = array_values(array_unique(array_filter($idsLocales)));

$ultimaFechaPorLocal = [];

if (!empty($idsLocales)) {
    $placeholdersFq = implode(',', array_fill(0, count($idsLocales), '?'));
    $typesFq = str_repeat('i', count($idsLocales));

    $sqlFq = "
        SELECT
            fq.id_local,
            MAX(fq.fechaPropuesta) AS ultima_fecha_propuesta
        FROM formularioQuestion fq
        WHERE fq.id_local IN ($placeholdersFq)
          AND fq.fechaPropuesta IS NOT NULL
        GROUP BY fq.id_local
    ";

    $stmtFq = $conn->prepare($sqlFq);
    if (!$stmtFq) {
        fail('Error al preparar consulta de fechaPropuesta: ' . $conn->error);
    }

    $stmtFq->bind_param($typesFq, ...$idsLocales);

    if (!$stmtFq->execute()) {
        fail('Error al ejecutar consulta de fechaPropuesta: ' . $stmtFq->error);
    }

    $resFq = $stmtFq->get_result();
    while ($fq = $resFq->fetch_assoc()) {
        $idLocal = (int)$fq['id_local'];
        $ultimaFechaPorLocal[$idLocal] = $fq['ultima_fecha_propuesta'] ?: null;
    }

    $stmtFq->close();
}

$hoy = new DateTime('today');

foreach ($locales as &$local) {
    $idLocal = (int)($local['id_local'] ?? 0);
    $ultima = $ultimaFechaPorLocal[$idLocal] ?? null;

    $local['ultima_fecha_propuesta_sql'] = $ultima;
    $local['ultima_fecha_propuesta'] = $ultima
        ? date('d-m-Y', strtotime($ultima))
        : '';

    if ($ultima) {
        $dtUltima = new DateTime($ultima);
        $local['dias_desde_ultima_propuesta'] = (int)$dtUltima->diff($hoy)->days;
    } else {
        $local['dias_desde_ultima_propuesta'] = null;
    }
}
unset($local);

$localesExcluidosPorFecha = [];
$localesFiltradosPorFecha = [];

$fechaFiltro = clone $dtFechaInicio;
$fechaFiltro->setTime(0, 0, 0);

foreach ($locales as $local) {
    $ultimaSql = trim((string)($local['ultima_fecha_propuesta_sql'] ?? ''));
    $local['dias_diferencia_fecha_seleccionada'] = null;

    if ($ultimaSql !== '') {
        $dtUltima = new DateTime($ultimaSql);
        $dtUltima->setTime(0, 0, 0);

        $diasDiff = (int)$dtUltima->diff($fechaFiltro)->days;
        $local['dias_diferencia_fecha_seleccionada'] = $diasDiff;

        // Excluir si la diferencia con la fecha seleccionada es menor a 7 días
        if ($diasDiff < 7) {
            $local['motivo_exclusion_fecha'] = 'Última fechaPropuesta con menos de 7 días de diferencia respecto a la fecha seleccionada';
            $localesExcluidosPorFecha[] = $local;
            continue;
        }
    }

    $localesFiltradosPorFecha[] = $local;
}

$locales = $localesFiltradosPorFecha;
$totalLocalesExcluidosPorFecha = count($localesExcluidosPorFecha);

$localesSinCoords = [];
$localesConCoords = [];

foreach ($locales as $local) {
    if (hasValidCoords($local)) {
        $localesConCoords[] = $local;
    } else {
        $localesSinCoords[] = $local;
    }
}

$maximoPorDia = getMaxPerDay($cantidadPorDia);

$dayNames = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
];

$localesPorUsuario = [];
foreach ($locales as $local) {
    $keyUsuario = (string)$local['usuario_id'];

    if (!isset($localesPorUsuario[$keyUsuario])) {
        $localesPorUsuario[$keyUsuario] = [
            'usuario_id' => $local['usuario_id'],
            'usuario_login' => $local['usuario_login'],
            'usuario_nombre' => $local['usuario_nombre'],
            'locales' => []
        ];
    }

    $localesPorUsuario[$keyUsuario]['locales'][] = $local;
}

$planRows = [];
$sinRutaAsignadaRows = [];
$totalKmEstimado = 0.0;
$totalRutasGeneradas = 0;
$totalSospechosos = 0;
$localesSospechosos = [];
$totalRutasDescartadas = 0;
$totalLocalesSinRuta = 0;

$rutasValidasPorUsuario = [];

foreach ($localesPorUsuario as $bloqueUsuario) {
    $usuarioId = (int)$bloqueUsuario['usuario_id'];
    $usuarioLogin = (string)$bloqueUsuario['usuario_login'];
    $usuarioNombre = (string)$bloqueUsuario['usuario_nombre'];

if (!isset($rutasValidasPorUsuario[$usuarioId])) {
    $rutasValidasPorUsuario[$usuarioId] = 0;
}

    $usuarioLocales = $bloqueUsuario['locales'];

    $usuarioLocalesConCoords = [];
    foreach ($usuarioLocales as $local) {
        if (hasValidCoords($local)) {
            $usuarioLocalesConCoords[] = $local;
        }
    }

    $usuarioLocalesSospechosos = [];
    if ($routeOptions['outlier_knn_km'] > 0) {
        [$usuarioLocalesConCoords, $usuarioLocalesSospechosos] = partitionSuspiciousOutliers(
            $usuarioLocalesConCoords,
            $routeOptions['outlier_knn_km'],
            3
        );
        $totalSospechosos += count($usuarioLocalesSospechosos);
        foreach ($usuarioLocalesSospechosos as $s) {
            $localesSospechosos[] = $s;
        }
    }

    if (empty($usuarioLocalesConCoords)) {
        continue;
    }

    $groupsUsuario = buildCommuneFirstGroups($usuarioLocalesConCoords, $cantidadPorDia, $routeOptions);
    
    // Mantiene la lógica actual de construcción,
    // pero la asignación final de fechas prioriza antigüedad real
    $groupsUsuario = sortGroupsByProposalPriority($groupsUsuario);

    $diasPlanificadosUsuario = count($groupsUsuario);

foreach ($groupsUsuario as $idxRuta => $group) {
    $tamanoRuta = count($group);

    $metaGrupo = getGroupProposalPriorityMeta($group);

    $fechaPrioridadGrupoSql = $metaGrupo['oldest_ts']
        ? date('Y-m-d', $metaGrupo['oldest_ts'])
        : '';

    $fechaPrioridadGrupo = $metaGrupo['oldest_ts']
        ? date('d-m-Y', $metaGrupo['oldest_ts'])
        : '';

    $promedioDiasGrupo = $metaGrupo['avg_days'] !== null
        ? round((float)$metaGrupo['avg_days'], 2)
        : '';

    $distanciaRutaKm = round(estimateRouteDistanceKm($group), 2);

    // DESCARTAR DEFINITIVAMENTE rutas de 6 locales o menos
    if ($tamanoRuta <= 6) {
        $totalRutasDescartadas++;
        $totalLocalesSinRuta += $tamanoRuta;

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

            $sinRutaAsignadaRows[] = [
                'usuario_id'                    => $usuarioId,
                'usuario_login'                 => $usuarioLogin,
                'usuario_nombre'                => $usuarioNombre,

                'id_local'                      => $local['id_local'] ?? '',
                'codigo'                        => $local['codigo'],
                'nombre'                        => $local['nombre'],
                'direccion'                     => $local['direccion'],
                'comuna'                        => $local['comuna'],
                'lat'                           => (float)$local['lat'],
                'lng'                           => (float)$local['lng'],

                'ultima_fecha_propuesta'        => $local['ultima_fecha_propuesta'] ?? '',
                'ultima_fecha_propuesta_sql'    => $local['ultima_fecha_propuesta_sql'] ?? '',
                'dias_desde_ultima_propuesta'   => $local['dias_desde_ultima_propuesta'] ?? '',

                'fecha_prioridad_grupo'         => $fechaPrioridadGrupo,
                'fecha_prioridad_grupo_sql'     => $fechaPrioridadGrupoSql,
                'promedio_dias_grupo'           => $promedioDiasGrupo,

                'grupo_ruta_sugerido'           => 'RUTA ' . str_pad((string)($idxRuta + 1), 2, '0', STR_PAD_LEFT),
                'orden_visita'                  => $order + 1,
                'tamano_ruta'                   => $tamanoRuta,
                'distancia_desde_anterior'      => $distAnterior,
                'distancia_ruta_km'             => $distanciaRutaKm,

                'motivo_descarte'               => 'Ruta descartada por baja cobertura: 6 locales o menos',
                'observacion'                   => 'Estos locales fueron agrupados geográficamente, pero quedaron fuera de la planificación final por no alcanzar el mínimo requerido de 7 locales.'
            ];
        }

        continue;
    }

    // SOLO LAS RUTAS DE 7 O MÁS ENTRAN A PLANIFICACION
$rutasValidasPorUsuario[$usuarioId]++;
$numeroRutaUsuario = $rutasValidasPorUsuario[$usuarioId];

$totalRutasGeneradas++;
$rutaGlobalNumero = $totalRutasGeneradas;

$fechaRuta = addBusinessDays($dtFechaInicio, $numeroRutaUsuario - 1);
$fechaRutaSql = $fechaRuta->format('Y-m-d');
$fechaRutaDisplay = $fechaRuta->format('d-m-Y');

$diaSemanaNum = (int)$fechaRuta->format('N');
$diaSemanaNombre = $dayNames[$diaSemanaNum] ?? 'N/D';

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
            'usuario_id'                    => $usuarioId,
            'usuario_login'                 => $usuarioLogin,
            'usuario_nombre'                => $usuarioNombre,

            'id_local'                      => $local['id_local'] ?? '',
            'codigo'                        => $local['codigo'],
            'nombre'                        => $local['nombre'],
            'direccion'                     => $local['direccion'],
            'comuna'                        => $local['comuna'],
            'lat'                           => (float)$local['lat'],
            'lng'                           => (float)$local['lng'],

            'ultima_fecha_propuesta'        => $local['ultima_fecha_propuesta'] ?? '',
            'ultima_fecha_propuesta_sql'    => $local['ultima_fecha_propuesta_sql'] ?? '',
            'dias_desde_ultima_propuesta'   => $local['dias_desde_ultima_propuesta'] ?? '',

            'fecha_prioridad_grupo'         => $fechaPrioridadGrupo,
            'fecha_prioridad_grupo_sql'     => $fechaPrioridadGrupoSql,
            'promedio_dias_grupo'           => $promedioDiasGrupo,

            'cantidad_objetivo_dia'         => $cantidadPorDia,
            'dias_planificados'             => null, // lo recalcularemos después
            'grupo_ruta' => 'RUTA ' . str_pad((string)$numeroRutaUsuario, 2, '0', STR_PAD_LEFT),
            'ruta_global' => 'RUTA ' . str_pad((string)$rutaGlobalNumero, 2, '0', STR_PAD_LEFT),
            'dia_plan' => $numeroRutaUsuario,
            'semana_plan'                   => intdiv($numeroRutaUsuario - 1, 5) + 1,
            'dia_semana_num'                => $diaSemanaNum,
            'dia_semana'                    => $diaSemanaNombre,

            'fecha_inicio_base'             => $dtFechaInicio->format('d-m-Y'),
            'fecha_ruta'                    => $fechaRutaDisplay,
            'fecha_ruta_sql'                => $fechaRutaSql,

            'orden_visita'                  => $order + 1,
            'tamano_ruta'                   => $tamanoRuta,
            'distancia_desde_anterior'      => $distAnterior,
            'distancia_ruta_km'             => $distanciaRutaKm,

            'asignacion_recomendada'        => 'ASIGNAR',
            'motivo_asignacion'             => 'Cobertura suficiente (7 locales o más)',

            'observacion'                   => 'Ruta optimizada por cercanía y comuna para usuario asignado. Priorizada por antigüedad de fechaPropuesta y manteniendo compactación geográfica.'
        ];
    }
}
}

$diasPlanificados = $totalRutasGeneradas;

// actualizar dias_planificados reales solo sobre rutas válidas
foreach ($planRows as &$rowPlan) {
    $rowPlan['dias_planificados'] = $diasPlanificados;
}
unset($rowPlan);

$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen');

$sheet->fromArray([
    ['Campo', 'Valor'],
    ['Fecha generación', date('d-m-Y H:i:s')],
    ['Fecha inicio base', $dtFechaInicio->format('d-m-Y')],
    ['Cantidad objetivo por día', $cantidadPorDia],
    ['Máximo permitido por día', $maximoPorDia],
    ['Rango esperado por ruta', $cantidadPorDia . ' a ' . $maximoPorDia],
    ['Máx. salto entre puntos (KM)', round($maxKmEntrePuntos, 2)],
    ['Máx. diámetro por ruta (KM)', round($maxDiameterKm, 2)],
    ['Máx. radio por ruta (KM)', round($maxRadiusKm, 2)],
    ['Máx. comunas por ruta', $maxComunasRuta],
    ['Filtro outlier KNN (KM)', $outlierKnnKm > 0 ? round($outlierKnnKm, 2) : 'Desactivado'],
    ['Usuarios procesados', count($localesPorUsuario)],
    ['Días planificados', $diasPlanificados],
    ['Registros recibidos', count($registrosNormalizados)],
    ['Locales encontrados', count($locales)],
    ['Locales con coordenadas', count($localesConCoords)],
    ['Locales sospechosos por georreferencia', $totalSospechosos],
    ['Locales sin coordenadas', count($localesSinCoords)],
    ['Códigos no encontrados', count($codigosNoEncontrados)],
    ['Rutas descartadas por baja cobertura', $totalRutasDescartadas],
    ['Locales sin ruta asignada', $totalLocalesSinRuta],
    ['Grupos/rutas generadas', $totalRutasGeneradas],
    ['Promedio real locales por ruta', $totalRutasGeneradas > 0 ? round(count($localesConCoords) / $totalRutasGeneradas, 2) : 0],
    ['KM totales estimados', round($totalKmEstimado, 2)],
    ['Promedio KM por ruta', $totalRutasGeneradas > 0 ? round($totalKmEstimado / $totalRutasGeneradas, 2) : 0],
    ['Criterio de agrupación', 'Prioridad por comuna, proximidad geográfica y compactación real de ruta por usuario'],
    ['Locales excluidos por cercanía de fechaPropuesta', $totalLocalesExcluidosPorFecha],    
], null, 'A1');

styleHeader($sheet, 'A1:B1');
styleDataRange($sheet, 'A2:B25');
$sheet->freezePane('A2');
setFixedColumnsWidth($sheet, [
    'A' => 35,
    'B' => 55,
]);

$sheetPlan = $spreadsheet->createSheet();
$sheetPlan->setTitle('Planificacion');

$headersPlan = [
    'Usuario ID',
    'Usuario Login',
    'Usuario Nombre',
    'ID Local',
    'Código Local',
    'Nombre',
    'Dirección',
    'Comuna',
    'Lat',
    'Lng',
    'Última Fecha Propuesta',
    'Última Fecha Propuesta SQL',
    'Días Desde Última Propuesta',
    'Fecha Prioridad Grupo',
    'Fecha Prioridad Grupo SQL',
    'Promedio Días Grupo',
    'Cantidad Objetivo Día',
    'Días Planificados Usuario',
    'Grupo Ruta Usuario',
    'Ruta Global',
    'Día Plan',
    'Semana Plan',
    'Día Semana Nº',
    'Día Semana',
    'Fecha Inicio Base',
    'Fecha Ruta',
    'Fecha Ruta SQL',
    'Orden Visita',
    'Tamaño Ruta',
    'Distancia Desde Anterior (KM)',
    'Distancia Total Ruta (KM)',
    'Asignación Recomendada',
    'Motivo Asignación',
    'Observación',
];

$sheetPlan->fromArray([$headersPlan], null, 'A1');

$rowIndex = 2;
foreach ($planRows as $row) {
    $sheetPlan->fromArray([[
    $row['usuario_id'],
    $row['usuario_login'],
    $row['usuario_nombre'],
    $row['id_local'],
    $row['codigo'],
    $row['nombre'],
    $row['direccion'],
    $row['comuna'],
    $row['lat'],
    $row['lng'],
    $row['ultima_fecha_propuesta'],
    $row['ultima_fecha_propuesta_sql'],
    $row['dias_desde_ultima_propuesta'],
    $row['fecha_prioridad_grupo'],
    $row['fecha_prioridad_grupo_sql'],
    $row['promedio_dias_grupo'],
    $row['cantidad_objetivo_dia'],
    $row['dias_planificados'],
    $row['grupo_ruta'],
    $row['ruta_global'],
    $row['dia_plan'],
    $row['semana_plan'],
    $row['dia_semana_num'],
    $row['dia_semana'],
    $row['fecha_inicio_base'],
    $row['fecha_ruta'],
    $row['fecha_ruta_sql'],
    $row['orden_visita'],
    $row['tamano_ruta'],
    $row['distancia_desde_anterior'],
    $row['distancia_ruta_km'],
    $row['asignacion_recomendada'],
    $row['motivo_asignacion'],
    $row['observacion'],
]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetPlan, 'A1:AH1');
if ($rowIndex > 2) {
    styleDataRange($sheetPlan, 'A2:AH' . ($rowIndex - 1));
}
$sheetPlan->freezePane('A2');
setFixedColumnsWidth($sheetPlan, [
    'A' => 12,
    'B' => 20,
    'C' => 28,
    'D' => 12,
    'E' => 18,
    'F' => 28,
    'G' => 35,
    'H' => 20,
    'I' => 14,
    'J' => 14,
    'K' => 18,
    'L' => 18,
    'M' => 18,
    'N' => 18,
    'O' => 18,
    'P' => 18,
    'Q' => 18,
    'R' => 22,
    'S' => 18,
    'T' => 16,
    'U' => 12,
    'V' => 14,
    'W' => 14,
    'X' => 16,
    'Y' => 18,
    'Z' => 16,
    'AA' => 16,
    'AB' => 14,
    'AC' => 14,
    'AD' => 24,
    'AE' => 24,
    'AF' => 20,
    'AG' => 28,
    'AH' => 60,
]);

$sheetSinRuta = $spreadsheet->createSheet();
$sheetSinRuta->setTitle('Sin Ruta Asignada');

$sheetSinRuta->fromArray([[
    'Usuario ID',
    'Usuario Login',
    'Usuario Nombre',
    'ID Local',
    'Código Local',
    'Nombre',
    'Dirección',
    'Comuna',
    'Lat',
    'Lng',
    'Última Fecha Propuesta',
    'Última Fecha Propuesta SQL',
    'Días Desde Última Propuesta',
    'Fecha Prioridad Grupo',
    'Fecha Prioridad Grupo SQL',
    'Promedio Días Grupo',
    'Grupo Ruta Sugerido',
    'Orden Visita',
    'Tamaño Ruta',
    'Distancia Desde Anterior (KM)',
    'Distancia Total Ruta (KM)',
    'Motivo Descarte',
    'Observación',
]], null, 'A1');

$rowIndex = 2;
foreach ($sinRutaAsignadaRows as $row) {
    $sheetSinRuta->fromArray([[
        $row['usuario_id'],
        $row['usuario_login'],
        $row['usuario_nombre'],
        $row['id_local'],
        $row['codigo'],
        $row['nombre'],
        $row['direccion'],
        $row['comuna'],
        $row['lat'],
        $row['lng'],
        $row['ultima_fecha_propuesta'],
        $row['ultima_fecha_propuesta_sql'],
        $row['dias_desde_ultima_propuesta'],
        $row['fecha_prioridad_grupo'],
        $row['fecha_prioridad_grupo_sql'],
        $row['promedio_dias_grupo'],
        $row['grupo_ruta_sugerido'],
        $row['orden_visita'],
        $row['tamano_ruta'],
        $row['distancia_desde_anterior'],
        $row['distancia_ruta_km'],
        $row['motivo_descarte'],
        $row['observacion'],
    ]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetSinRuta, 'A1:W1');
if ($rowIndex > 2) {
    styleDataRange($sheetSinRuta, 'A2:W' . ($rowIndex - 1));
}
$sheetSinRuta->freezePane('A2');
setFixedColumnsWidth($sheetSinRuta, [
    'A' => 12,
    'B' => 20,
    'C' => 28,
    'D' => 12,
    'E' => 18,
    'F' => 28,
    'G' => 35,
    'H' => 20,
    'I' => 14,
    'J' => 14,
    'K' => 18,
    'L' => 18,
    'M' => 18,
    'N' => 18,
    'O' => 18,
    'P' => 18,
    'Q' => 18,
    'R' => 14,
    'S' => 14,
    'T' => 24,
    'U' => 24,
    'V' => 32,
    'W' => 60,
]);

$sheetSin = $spreadsheet->createSheet();
$sheetSin->setTitle('Sin Coordenadas');

$sheetSin->fromArray([[
    'Usuario ID',
    'Usuario Login',
    'Usuario Nombre',
    'Código Local',
    'Nombre',
    'Dirección',
    'Comuna',
    'Motivo'
]], null, 'A1');

$rowIndex = 2;
foreach ($localesSinCoords as $local) {
    $sheetSin->fromArray([[
        $local['usuario_id'] ?? '',
        $local['usuario_login'] ?? '',
        $local['usuario_nombre'] ?? '',
        $local['codigo'],
        $local['nombre'],
        $local['direccion'],
        $local['comuna'],
        'Existe en sistema pero no tiene lat/lng válidos'
    ]], null, 'A' . $rowIndex);
    $rowIndex++;
}

styleHeader($sheetSin, 'A1:H1');
if ($rowIndex > 2) {
    styleDataRange($sheetSin, 'A2:H' . ($rowIndex - 1));
}
$sheetSin->freezePane('A2');
setFixedColumnsWidth($sheetSin, [
    'A' => 12,
    'B' => 20,
    'C' => 28,
    'D' => 18,
    'E' => 28,
    'F' => 35,
    'G' => 20,
    'H' => 45,
]);

if (!empty($localesSospechosos)) {
    $sheetSusp = $spreadsheet->createSheet();
    $sheetSusp->setTitle('Sospechosos');

    $sheetSusp->fromArray([[
        'Usuario ID',
        'Usuario Login',
        'Usuario Nombre',
        'Código Local',
        'Nombre',
        'Dirección',
        'Comuna',
        'Lat',
        'Lng',
        'Motivo'
    ]], null, 'A1');

    $rowIndex = 2;
    foreach ($localesSospechosos as $local) {
        $sheetSusp->fromArray([[
            $local['usuario_id'] ?? '',
            $local['usuario_login'] ?? '',
            $local['usuario_nombre'] ?? '',
            $local['codigo'],
            $local['nombre'],
            $local['direccion'],
            $local['comuna'],
            $local['lat'],
            $local['lng'],
            $local['_motivo_sospechoso'] ?? 'Coordenada potencialmente aislada'
        ]], null, 'A' . $rowIndex);
        $rowIndex++;
    }

    styleHeader($sheetSusp, 'A1:J1');
    if ($rowIndex > 2) {
        styleDataRange($sheetSusp, 'A2:J' . ($rowIndex - 1));
    }
    $sheetSusp->freezePane('A2');
    setFixedColumnsWidth($sheetSusp, [
        'A' => 12,
        'B' => 20,
        'C' => 28,
        'D' => 18,
        'E' => 28,
        'F' => 35,
        'G' => 20,
        'H' => 14,
        'I' => 14,
        'J' => 45,
    ]);
}

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

$filename = 'propuesta_ruta_por_usuario_' . date('Ymd_His') . '.xlsx';

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
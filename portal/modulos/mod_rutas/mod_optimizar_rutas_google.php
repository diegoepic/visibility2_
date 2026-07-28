<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(180);
date_default_timezone_set('America/Santiago');

function routeJsonFail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function routeValidCoordinates(array $row): bool
{
    if (!isset($row['lat'], $row['lng']) || !is_numeric($row['lat']) || !is_numeric($row['lng'])) {
        return false;
    }
    $lat = (float)$row['lat'];
    $lng = (float)$row['lng'];
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        && (abs($lat) > 0.0001 || abs($lng) > 0.0001);
}

function routeDistanceKm(array $a, array $b): float
{
    $lat1 = deg2rad((float)$a['lat']);
    $lat2 = deg2rad((float)$b['lat']);
    $dLat = $lat2 - $lat1;
    $dLng = deg2rad((float)$b['lng'] - (float)$a['lng']);
    $value = sin($dLat / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
    return 6371 * 2 * atan2(sqrt($value), sqrt(max(0, 1 - $value)));
}

/** @return array<int,array<int,int>> Índices de componentes conectados. */
function routeConnectedComponents(array $rows, float $thresholdKm): array
{
    $count = count($rows);
    $visited = array_fill(0, $count, false);
    $components = [];

    for ($start = 0; $start < $count; $start++) {
        if ($visited[$start]) {
            continue;
        }
        $visited[$start] = true;
        $queue = [$start];
        $component = [];

        while ($queue) {
            $current = array_pop($queue);
            $component[] = $current;
            for ($candidate = 0; $candidate < $count; $candidate++) {
                if ($visited[$candidate]) {
                    continue;
                }
                if (routeDistanceKm($rows[$current], $rows[$candidate]) <= $thresholdKm) {
                    $visited[$candidate] = true;
                    $queue[] = $candidate;
                }
            }
        }
        $components[] = $component;
    }

    usort($components, static fn(array $a, array $b): int => count($b) <=> count($a));
    return $components;
}

function routeConfig(): array
{
    $path = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\')
        . '/visibility2/portal/config/maps_config.php';
    $config = is_file($path) ? include $path : [];
    return is_array($config) ? $config : [];
}

function routeBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function routeLoadServiceAccount(array $config): array
{
    if (isset($config['route_optimization_service_account'])
        && is_array($config['route_optimization_service_account'])) {
        return $config['route_optimization_service_account'];
    }

    $file = trim((string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''));
    if ($file === '') {
        $file = trim((string)($config['route_optimization_service_account_file']
            ?? $config['google_application_credentials']
            ?? ''));
    }
    if ($file === '') {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        $automaticFile = dirname($documentRoot) . '/secure/route-optimizer.json';
        if (is_file($automaticFile)) {
            $file = $automaticFile;
        } else {
            throw new RuntimeException(
                'Route Optimization requiere OAuth 2.0. Renombra el JSON como '
                . $automaticFile . ' o configura route_optimization_service_account_file.'
            );
        }
    }
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $file)) {
        $file = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/' . ltrim($file, '/\\');
    }
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('No se puede leer el archivo de cuenta de servicio configurado: ' . $file);
    }

    $credentials = json_decode((string)file_get_contents($file), true);
    if (!is_array($credentials)) {
        throw new RuntimeException('El archivo de cuenta de servicio no contiene JSON válido.');
    }
    return $credentials;
}

function routeServiceAccountAccessToken(array $credentials): string
{
    $email = trim((string)($credentials['client_email'] ?? ''));
    $privateKey = (string)($credentials['private_key'] ?? '');
    $tokenUri = trim((string)($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($email === '' || $privateKey === '') {
        throw new RuntimeException('El JSON no corresponde a una cuenta de servicio: faltan client_email o private_key.');
    }
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('El servidor PHP necesita la extensión OpenSSL para generar el token OAuth 2.0.');
    }

    $cacheFile = rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR . 'visibility_route_oauth_' . md5($email) . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)
            && !empty($cached['access_token'])
            && (int)($cached['expires_at'] ?? 0) > time() + 90) {
            return (string)$cached['access_token'];
        }
    }

    $issuedAt = time();
    $header = routeBase64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claims = routeBase64Url(json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud' => $tokenUri,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ], JSON_UNESCAPED_SLASHES));
    $unsignedJwt = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No se pudo firmar el JWT con la clave privada de la cuenta de servicio.');
    }
    $assertion = $unsignedJwt . '.' . routeBase64Url($signature);

    $curl = curl_init($tokenUri);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ], '', '&', PHP_QUERY_RFC3986),
    ]);
    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $response = json_decode((string)$body, true);
    if ($body === false || $curlError !== '' || $httpCode !== 200 || !is_array($response) || empty($response['access_token'])) {
        $oauthMessage = is_array($response)
            ? (string)($response['error_description'] ?? $response['error'] ?? 'respuesta OAuth inválida')
            : ($curlError !== '' ? $curlError : 'respuesta OAuth inválida');
        throw new RuntimeException("No se pudo obtener el token OAuth 2.0 (HTTP {$httpCode}): {$oauthMessage}");
    }

    $accessToken = (string)$response['access_token'];
    @file_put_contents($cacheFile, json_encode([
        'access_token' => $accessToken,
        'expires_at' => time() + max(300, (int)($response['expires_in'] ?? 3600)),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($cacheFile, 0600);
    return $accessToken;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    routeJsonFail('Método no permitido.', 405);
}
$csrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['route_optimizer_csrf'] ?? '');
if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    routeJsonFail('La sesión de optimización venció. Recarga la página e inténtalo nuevamente.', 403);
}

$decodedRows = json_decode((string)($_POST['rows'] ?? ''), true);
if (!is_array($decodedRows) || !$decodedRows) {
    routeJsonFail('No se recibieron locales para optimizar.');
}
if (count($decodedRows) > 2000) {
    routeJsonFail('La optimización admite hasta 2.000 locales por ejecución. Filtra por usuario o zona.', 413);
}

$targetSize = max(2, min(30, (int)($_POST['target_size'] ?? 10)));
$minSize = max(1, (int)ceil($targetSize * 0.8));
$maxSize = min(30, max($targetSize, (int)ceil($targetSize * 1.5)));
$isolationKm = max(2.0, min(150.0, (float)($_POST['isolation_km'] ?? 25)));

$rows = [];
foreach ($decodedRows as $input) {
    if (!is_array($input) || !routeValidCoordinates($input)) {
        continue;
    }
    $rowId = trim((string)($input['row_id'] ?? ''));
    if ($rowId === '') {
        continue;
    }
    $rows[] = [
        'row_id' => $rowId,
        'codigo_local' => trim((string)($input['codigo_local'] ?? '')),
        'usuario_id' => trim((string)($input['usuario_id'] ?? '')),
        'usuario_login' => trim((string)($input['usuario_login'] ?? '')),
        'usuario_nombre' => trim((string)($input['usuario_nombre'] ?? '')),
        'lat' => (float)$input['lat'],
        'lng' => (float)$input['lng'],
    ];
}
if (!$rows) {
    routeJsonFail('No hay locales con coordenadas válidas para optimizar.');
}

$byUser = [];
foreach ($rows as $row) {
    $userKey = $row['usuario_id'] !== '' ? $row['usuario_id']
        : ($row['usuario_login'] !== '' ? $row['usuario_login'] : $row['usuario_nombre']);
    $userKey = $userKey !== '' ? $userKey : 'SIN_USUARIO';
    $byUser[$userKey][] = $row;
}

$eligibleGroups = [];
$skipped = [];
foreach ($byUser as $userKey => $userRows) {
    $components = routeConnectedComponents($userRows, $isolationKm);
    if (count($components) <= 1) {
        $eligibleGroups[] = ['user_key' => $userKey, 'rows' => $userRows];
        continue;
    }

    $largeComponents = array_values(array_filter(
        $components,
        static fn(array $component): bool => count($component) >= $minSize
    ));
    $keptComponents = $largeComponents ?: [$components[0]];
    $keep = [];
    foreach ($keptComponents as $component) {
        $componentRows = [];
        foreach ($component as $index) {
            $keep[$index] = true;
            $componentRows[] = $userRows[$index];
        }
        $eligibleGroups[] = ['user_key' => $userKey, 'rows' => $componentRows];
    }

    foreach ($userRows as $index => $row) {
        if (!isset($keep[$index])) {
            $skipped[] = [
                'row_id' => $row['row_id'],
                'codigo_local' => $row['codigo_local'],
                'reason' => "Local aislado: pertenece a un grupo menor a {$minSize} locales y separado más de {$isolationKm} km.",
                'source' => 'ISOLATION_RULE',
            ];
        }
    }
}

$shipments = [];
$shipmentRows = [];
$vehicles = [];
$vehicleMeta = [];
$nextUserDay = [];

foreach ($eligibleGroups as $eligibleGroup) {
    $userKey = (string)$eligibleGroup['user_key'];
    $userRows = $eligibleGroup['rows'];
    if (!$userRows) {
        continue;
    }
    $minimumVehicles = max(1, (int)ceil(count($userRows) / $maxSize));
    $maximumVehicles = max(1, (int)floor(count($userRows) / $minSize));
    $idealVehicles = max(1, (int)round(count($userRows) / $targetSize));
    $vehicleCount = max($minimumVehicles, min($idealVehicles, $maximumVehicles));
    $allowedVehicleIndices = [];
    $firstDayIndex = (int)($nextUserDay[$userKey] ?? 0);

    for ($day = 0; $day < $vehicleCount; $day++) {
        $userDayIndex = $firstDayIndex + $day;
        $vehicleIndex = count($vehicles);
        $allowedVehicleIndices[] = $vehicleIndex;
        $visitLoadLimit = ['maxLoad' => (string)$maxSize];
        if ($targetSize < $maxSize) {
            $visitLoadLimit['softMaxLoad'] = (string)$targetSize;
            $visitLoadLimit['costPerUnitAboveSoftMax'] = 8;
        }
        $vehicles[] = [
            'label' => $userKey . '|DIA|' . ($userDayIndex + 1),
            'loadLimits' => [
                'visitas' => $visitLoadLimit,
            ],
            'fixedCost' => 20,
            'costPerKilometer' => 1,
            'costPerTraveledHour' => 12,
        ];
        $vehicleMeta[$vehicleIndex] = [
            'user_key' => $userKey,
            'user_day_index' => $userDayIndex,
        ];
    }
    $nextUserDay[$userKey] = $firstDayIndex + $vehicleCount;

    foreach ($userRows as $row) {
        $shipmentIndex = count($shipments);
        $shipmentRows[$shipmentIndex] = $row;
        $shipments[] = [
            'label' => $row['row_id'],
            'pickups' => [[
                'arrivalLocation' => [
                    'latitude' => $row['lat'],
                    'longitude' => $row['lng'],
                ],
                'duration' => '300s',
                'label' => $row['codigo_local'],
            ]],
            'loadDemands' => [
                'visitas' => ['amount' => '1'],
            ],
            'allowedVehicleIndices' => $allowedVehicleIndices,
            // Si el desvío vial de un local resulta demasiado caro, Google puede omitirlo.
            'penaltyCost' => 50,
        ];
    }
}

if (!$shipments || !$vehicles) {
    echo json_encode([
        'success' => true,
        'provider' => 'GOOGLE_ROUTE_OPTIMIZATION',
        'routes' => [],
        'skipped' => $skipped,
        'message' => 'Todos los locales quedaron fuera por aislamiento geográfico.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = routeConfig();
try {
    $serviceAccount = routeLoadServiceAccount($config);
    $accessToken = routeServiceAccountAccessToken($serviceAccount);
} catch (Throwable $error) {
    routeJsonFail($error->getMessage(), 500, ['oauth_required' => true]);
}
$projectId = trim((string)(getenv('GOOGLE_CLOUD_PROJECT') ?: ''));
if ($projectId === '') {
    $projectId = trim((string)($config['google_cloud_project_id']
        ?? $config['project_id']
        ?? $serviceAccount['project_id']
        ?? ''));
}
if ($projectId === '') {
    routeJsonFail('Falta configurar google_cloud_project_id en config/maps_config.php.', 500);
}
$credentialProjectId = trim((string)($serviceAccount['project_id'] ?? ''));
if ($credentialProjectId !== '' && $credentialProjectId !== $projectId) {
    routeJsonFail(
        "El proyecto configurado ({$projectId}) no coincide con el project_id del JSON de la cuenta de servicio ({$credentialProjectId}). Corrige google_cloud_project_id antes de llamar a Route Optimization.",
        500,
        [
            'configured_project_id' => $projectId,
            'credential_project_id' => $credentialProjectId,
        ]
    );
}
$projectNumber = trim((string)($config['google_cloud_project_number'] ?? ''));
$resourceProject = $projectNumber !== '' ? $projectNumber : $projectId;

$start = new DateTimeImmutable('today 08:00:00', new DateTimeZone('America/Santiago'));
$end = $start->modify('+16 hours');
$payload = [
    'timeout' => '60s',
    'considerRoadTraffic' => false,
    'populatePolylines' => true,
    'model' => [
        'globalStartTime' => $start->format(DateTimeInterface::RFC3339),
        'globalEndTime' => $end->format(DateTimeInterface::RFC3339),
        'shipments' => $shipments,
        'vehicles' => $vehicles,
    ],
];

$url = 'https://routeoptimization.googleapis.com/v1/projects/' . rawurlencode($resourceProject)
    . ':optimizeTours';
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 80,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $accessToken,
        // Declara de forma explicita el proyecto que consume cuota y facturacion.
        // La cuenta de servicio debe tener serviceusage.services.use en este proyecto
        // (incluido en el rol Service Usage Consumer).
        'X-Goog-User-Project: ' . $projectId,
        'X-Goog-Request-Params: parent=' . rawurlencode('projects/' . $resourceProject),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$body = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($body === false || $curlError !== '') {
    routeJsonFail('Route Optimization API no respondió: ' . $curlError, 502);
}
$response = json_decode((string)$body, true);
if ($httpCode !== 200 || !is_array($response)) {
    $apiMessage = is_array($response) ? ($response['error']['message'] ?? 'Respuesta inválida') : 'Respuesta inválida';
    $apiStatus = is_array($response) ? (string)($response['error']['status'] ?? '') : '';
    $apiDetails = is_array($response) && isset($response['error']['details'])
        ? json_encode($response['error']['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    error_log("Route Optimization API HTTP {$httpCode} {$apiStatus}: {$apiMessage} {$apiDetails}");

    $apiReason = '';
    if (is_array($response) && isset($response['error']['details']) && is_array($response['error']['details'])) {
        foreach ($response['error']['details'] as $detail) {
            if (is_array($detail) && isset($detail['reason'])) {
                $apiReason = (string)$detail['reason'];
                break;
            }
        }
    }
    if ($apiReason === 'CONSUMER_INVALID') {
        routeJsonFail(
            "Google no reconoce el proyecto consumidor {$projectId} (numero usado: {$resourceProject}). Verifica que el ID y el numero coincidan con el proyecto del JSON de la cuenta de servicio.",
            502,
            [
                'google_http_code' => $httpCode,
                'google_status' => $apiStatus,
                'google_reason' => $apiReason,
                'google_project_id' => $projectId,
                'google_project_number' => $projectNumber,
                'google_resource_project' => $resourceProject,
            ]
        );
    }
    $detailSuffix = $apiDetails !== '' ? ' Detalle: ' . $apiDetails : '';
    routeJsonFail("Route Optimization API devolvió HTTP {$httpCode} {$apiStatus}: {$apiMessage}{$detailSuffix}", 502, [
        'google_http_code' => $httpCode,
        'google_status' => $apiStatus,
    ]);
}

$routes = [];
foreach (($response['routes'] ?? []) as $googleRoute) {
    $visits = $googleRoute['visits'] ?? [];
    if (!$visits) {
        continue;
    }
    $vehicleIndex = (int)($googleRoute['vehicleIndex'] ?? 0);
    $rowIds = [];
    foreach ($visits as $visit) {
        $shipmentIndex = (int)($visit['shipmentIndex'] ?? -1);
        if (isset($shipmentRows[$shipmentIndex])) {
            $rowIds[] = $shipmentRows[$shipmentIndex]['row_id'];
        }
    }
    if (!$rowIds) {
        continue;
    }
    $meta = $vehicleMeta[$vehicleIndex] ?? ['user_key' => '', 'user_day_index' => 0];
    $routes[] = [
        'user_key' => $meta['user_key'],
        'user_day_index' => $meta['user_day_index'],
        'row_ids' => $rowIds,
        'encoded_polyline' => (string)($googleRoute['routePolyline']['points'] ?? ''),
        'distance_meters' => (int)($googleRoute['metrics']['travelDistanceMeters'] ?? 0),
        'total_duration' => (string)($googleRoute['metrics']['totalDuration'] ?? ''),
    ];
}

foreach (($response['skippedShipments'] ?? []) as $googleSkipped) {
    $shipmentIndex = isset($googleSkipped['index']) ? (int)$googleSkipped['index'] : -1;
    $row = $shipmentRows[$shipmentIndex] ?? null;
    if (!$row && !empty($googleSkipped['label'])) {
        foreach ($shipmentRows as $candidate) {
            if ($candidate['row_id'] === (string)$googleSkipped['label']) {
                $row = $candidate;
                break;
            }
        }
    }
    if ($row) {
        $skipped[] = [
            'row_id' => $row['row_id'],
            'codigo_local' => $row['codigo_local'],
            'reason' => 'Google lo dejó fuera porque su costo o desvío vial no es conveniente para las rutas disponibles.',
            'source' => 'GOOGLE_ROUTE_OPTIMIZATION',
        ];
    }
}

echo json_encode([
    'success' => true,
    'provider' => 'GOOGLE_ROUTE_OPTIMIZATION',
    'routes' => $routes,
    'skipped' => $skipped,
    'summary' => [
        'input_rows' => count($rows),
        'optimized_rows' => array_sum(array_map(static fn(array $route): int => count($route['row_ids']), $routes)),
        'skipped_rows' => count($skipped),
        'routes' => count($routes),
        'isolation_km' => $isolationKm,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

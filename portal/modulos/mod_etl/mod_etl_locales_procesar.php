<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($usuario_id)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado.']);
    exit();
}

$sessionToken = trim($_SESSION['csrf_token'] ?? '');
$postToken    = trim($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Token CSRF inválido.'
    ]);
    exit();
}

$google_maps_api_key = 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw';

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function removeBOM(string $str): string {
    $bom = pack('H*', 'EFBBBF');
    if (substr($str, 0, 3) === $bom) {
        return substr($str, 3);
    }
    return $str;
}

function normalizeHeader(string $header): string {
    $header = removeBOM($header);
    $header = preg_replace('/[\x00-\x1F\x7F]/u', '', $header);
    $header = strtolower(trim($header));

    $replace = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n'
    ];
    $header = strtr($header, $replace);
    $header = preg_replace('/\s+/', ' ', $header);

    return $header;
}

function detectDelimiter(string $filePath): string {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ';';
    }

    $firstLine = fgets($handle);
    fclose($handle);

    if ($firstLine === false) {
        return ';';
    }

    $semicolonCount = substr_count($firstLine, ';');
    $commaCount = substr_count($firstLine, ',');

    return ($semicolonCount >= $commaCount) ? ';' : ',';
}

function resolveColumnIndexes(array $headers): array|false {
    $aliases = [
        'codigo'       => ['codigo', 'codigo local', 'cod local', 'cod_local'],
        'nombre_local' => ['nombre local', 'nombre', 'nombre_local', 'local'],
        'direccion'    => ['direccion', 'dirección'],
        'comuna'       => ['comuna'],
        'region'       => ['region', 'región'],
    ];

    $normalizedHeaders = array_map('normalizeHeader', $headers);
    $indexes = [];

    foreach ($aliases as $logical => $possibles) {
        $found = false;
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibles, true)) {
                $indexes[$logical] = $idx;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    return $indexes;
}

function getRegionId(mysqli $conn, string $regionName, array &$errors, int $lineNumber): int|false {
    $stmt = $conn->prepare("SELECT id FROM region WHERE LOWER(region) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT region en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $regionName);
    $stmt->execute();
    $stmt->bind_result($regionId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$regionId;
    }

    $stmtIns = $conn->prepare("INSERT INTO region (region) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT region en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $regionName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando región '$regionName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getComunaId(mysqli $conn, string $comunaName, int $regionId, array &$errors, int $lineNumber): int|false {
    $stmt = $conn->prepare("SELECT id FROM comuna WHERE LOWER(comuna) = LOWER(?) AND id_region = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT comuna en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('si', $comunaName, $regionId);
    $stmt->execute();
    $stmt->bind_result($comunaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$comunaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO comuna (comuna, id_region) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT comuna en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $comunaName, $regionId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando comuna '$comunaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getExistingLocalByCode(mysqli $conn, string $codigo, array &$errors, int $lineNumber): array|false {
    $sql = "
        SELECT
            l.id,
            l.codigo,
            l.nombre,
            l.direccion,
            l.id_comuna,
            c.comuna,
            r.region
        FROM local l
        LEFT JOIN comuna c ON c.id = l.id_comuna
        LEFT JOIN region r ON r.id = c.id_region
        WHERE l.codigo = ?
          AND l.deleted_at IS NULL
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "Error preparando SELECT local en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $errors[] = "El código '$codigo' no existe en la tabla local.";
        return false;
    }

    return $row;
}

function geocodeRequest(string $address, string $apiKey): array|false {
    $addrEnc = urlencode($address);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addrEnc}&key={$apiKey}&components=country:CL";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    $resp = json_decode($response, true);
    if (!is_array($resp) || !isset($resp['status'])) {
        return false;
    }

    if ($resp['status'] === 'OK' && !empty($resp['results'][0]['geometry']['location'])) {
        return [
            'lat' => $resp['results'][0]['geometry']['location']['lat'],
            'lng' => $resp['results'][0]['geometry']['location']['lng']
        ];
    }

    return false;
}

function geocodeAddress(string $direccion, string $comuna, string $region, string $apiKey, array &$errors, int $lineNumber): array|false {
    $candidates = [
        "{$direccion}, {$comuna}, {$region}, Chile",
        "{$direccion}, {$comuna}, Chile"
    ];

    foreach ($candidates as $candidate) {
        $coords = geocodeRequest($candidate, $apiKey);
        if ($coords !== false) {
            return $coords;
        }
    }

    $errors[] = "Geocoding fallido en línea $lineNumber para la dirección '{$direccion}, {$comuna}, {$region}'.";
    return false;
}

function updateLocalByCodigo(
    mysqli $conn,
    string $codigo,
    string $nombre,
    string $direccion,
    int $comunaId,
    float $lat,
    float $lng,
    array &$errors,
    int $lineNumber
): bool {
    $sql = "
        UPDATE local
        SET
            nombre = ?,
            direccion = ?,
            id_comuna = ?,
            lat = ?,
            lng = ?,
            updated_at = NOW()
        WHERE codigo = ?
          AND deleted_at IS NULL
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "Error preparando UPDATE local en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param(
        'ssidds',
        $nombre,
        $direccion,
        $comunaId,
        $lat,
        $lng,
        $codigo
    );

    if (!$stmt->execute()) {
        $errors[] = "Error actualizando local '$codigo' en línea $lineNumber: " . $stmt->error;
        $stmt->close();
        return false;
    }

    if ($stmt->affected_rows < 0) {
        $errors[] = "No fue posible actualizar el local '$codigo' en línea $lineNumber.";
        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No se subió correctamente el archivo CSV.'], 400);
}

$csvFile = $_FILES['csvFile']['tmp_name'];
$extension = strtolower(pathinfo($_FILES['csvFile']['name'], PATHINFO_EXTENSION));

if ($extension !== 'csv') {
    jsonResponse(['success' => false, 'message' => 'El archivo debe ser CSV.'], 400);
}

$delimiter = detectDelimiter($csvFile);

$handle = fopen($csvFile, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'No se pudo abrir el archivo CSV.'], 400);
}

$conn->set_charset('utf8mb4');

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'message' => 'El archivo CSV está vacío.'], 400);
}

if (count($headers) > 0) {
    $headers[0] = removeBOM($headers[0]);
}

$indexes = resolveColumnIndexes($headers);
if ($indexes === false) {
    fclose($handle);
    jsonResponse([
        'success' => false,
        'message' => 'Los encabezados no son válidos. Deben incluir: codigo, nombre local, direccion, comuna, region.'
    ], 400);
}

$lineNumber = 1;
$successes = [];
$failures = [];
$errors = [];
$seenCodes = [];

while (($dataLine = fgetcsv($handle, 200000, $delimiter)) !== false) {
    $lineNumber++;

    if (count(array_filter($dataLine, fn($v) => trim((string)$v) !== '')) === 0) {
        continue;
    }

    $codigo      = trim((string)($dataLine[$indexes['codigo']] ?? ''));
    $nombreLocal = trim((string)($dataLine[$indexes['nombre_local']] ?? ''));
    $direccion   = trim((string)($dataLine[$indexes['direccion']] ?? ''));
    $comunaName  = trim((string)($dataLine[$indexes['comuna']] ?? ''));
    $regionName  = trim((string)($dataLine[$indexes['region']] ?? ''));

    if ($codigo === '' || $nombreLocal === '' || $direccion === '' || $comunaName === '' || $regionName === '') {
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => 'Faltan campos requeridos.'
        ];
        continue;
    }

    if (isset($seenCodes[$codigo])) {
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => 'Código repetido dentro del mismo archivo.'
        ];
        continue;
    }
    $seenCodes[$codigo] = true;

    $localActual = getExistingLocalByCode($conn, $codigo, $errors, $lineNumber);
    if ($localActual === false) {
        $reason = array_pop($errors) ?: 'No se encontró el local.';
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        continue;
    }

    $regionId = getRegionId($conn, $regionName, $errors, $lineNumber);
    if ($regionId === false) {
        $reason = array_pop($errors) ?: 'No se pudo resolver la región.';
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        continue;
    }

    $comunaId = getComunaId($conn, $comunaName, $regionId, $errors, $lineNumber);
    if ($comunaId === false) {
        $reason = array_pop($errors) ?: 'No se pudo resolver la comuna.';
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        continue;
    }

    $coords = geocodeAddress($direccion, $comunaName, $regionName, $google_maps_api_key, $errors, $lineNumber);
    if ($coords === false) {
        $reason = array_pop($errors) ?: 'No se pudo geolocalizar la dirección.';
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        continue;
    }

    $ok = updateLocalByCodigo(
        $conn,
        $codigo,
        $nombreLocal,
        $direccion,
        $comunaId,
        (float)$coords['lat'],
        (float)$coords['lng'],
        $errors,
        $lineNumber
    );

    if (!$ok) {
        $reason = array_pop($errors) ?: 'No se pudo actualizar el local.';
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        continue;
    }

    $successes[] = [
        'line'              => $lineNumber,
        'codigo'            => $codigo,
        'nombre_anterior'   => $localActual['nombre'] ?? '',
        'nombre_nuevo'      => $nombreLocal,
        'direccion_anterior'=> $localActual['direccion'] ?? '',
        'direccion_nueva'   => $direccion,
        'comuna_anterior'   => $localActual['comuna'] ?? '',
        'comuna_nueva'      => $comunaName,
        'lat'               => $coords['lat'],
        'lng'               => $coords['lng']
    ];

    usleep(120000);
}

fclose($handle);

$reportUrl = '';
if (!empty($failures)) {
    $reportDir = __DIR__ . '/uploads/reports/';
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0755, true);
    }

    $reportName = 'etl_locales_fallidos_' . time() . '.csv';
    $reportPath = $reportDir . $reportName;

    $fp = fopen($reportPath, 'w');
    fputcsv($fp, ['linea', 'codigo', 'nombre local', 'motivo de fallo'], ';');

    foreach ($failures as $f) {
        fputcsv($fp, [
            $f['line'],
            $f['codigo'],
            $f['nombre'],
            $f['reason']
        ], ';');
    }

    fclose($fp);

    $reportUrl = dirname($_SERVER['SCRIPT_NAME']) . '/uploads/reports/' . $reportName;
}

jsonResponse([
    'success'   => true,
    'updated'   => count($successes),
    'failed'    => count($failures),
    'reportUrl' => $reportUrl,
    'successes' => $successes,
    'failures'  => $failures
]);
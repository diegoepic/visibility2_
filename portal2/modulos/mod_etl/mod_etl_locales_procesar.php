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
    $requiredAliases = [
        'codigo'       => ['codigo', 'codigo local', 'cod local', 'cod_local'],
        'nombre_local' => ['nombre local', 'nombre', 'nombre_local', 'local'],
        'direccion'    => ['direccion', 'dirección'],
        'comuna'       => ['comuna'],
        'region'       => ['region', 'región'],
    ];

    $optionalAliases = [
        'cuenta'   => ['cuenta'],
        'cadena'   => ['cadena'],
        'zona'     => ['zona'],
        'distrito' => ['distrito']
    ];

    $normalizedHeaders = array_map('normalizeHeader', $headers);
    $indexes = [];

    foreach ($requiredAliases as $logical => $possibles) {
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

    foreach ($optionalAliases as $logical => $possibles) {
        $indexes[$logical] = null;
        foreach ($normalizedHeaders as $idx => $header) {
            if (in_array($header, $possibles, true)) {
                $indexes[$logical] = $idx;
                break;
            }
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

function getCuentaId(mysqli $conn, string $cuentaName, array &$errors, int $lineNumber): int|false {
    $stmt = $conn->prepare("SELECT id FROM cuenta WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT cuenta en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $cuentaName);
    $stmt->execute();
    $stmt->bind_result($cuentaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$cuentaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO cuenta (nombre) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT cuenta en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $cuentaName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando cuenta '$cuentaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getZonaId(mysqli $conn, string $zonaName, array &$errors, int $lineNumber): int|false {
    $stmt = $conn->prepare("SELECT id FROM zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT zona en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmt->bind_param('s', $zonaName);
    $stmt->execute();
    $stmt->bind_result($zonaId);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        return (int)$zonaId;
    }

    $stmtIns = $conn->prepare("INSERT INTO zona (nombre_zona) VALUES (?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT zona en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('s', $zonaName);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando zona '$zonaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return $newId;
}

function getCadenaData(mysqli $conn, string $cadenaName, ?int $cuentaId, array &$errors, int $lineNumber): array|false {
    if ($cuentaId !== null) {
        $stmt = $conn->prepare("
            SELECT id, id_cuenta
            FROM cadena
            WHERE LOWER(nombre) = LOWER(?)
              AND id_cuenta = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT cadena en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('si', $cadenaName, $cuentaId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, id_cuenta
            FROM cadena
            WHERE LOWER(nombre) = LOWER(?)
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT cadena en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('s', $cadenaName);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        return [
            'id'        => (int)$row['id'],
            'id_cuenta' => isset($row['id_cuenta']) ? (int)$row['id_cuenta'] : null
        ];
    }

    if ($cuentaId === null) {
        $errors[] = "La cadena '$cadenaName' no existe y no se indicó una cuenta para crearla en línea $lineNumber.";
        return false;
    }

    $stmtIns = $conn->prepare("INSERT INTO cadena (nombre, id_cuenta) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT cadena en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $cadenaName, $cuentaId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando cadena '$cadenaName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return [
        'id'        => $newId,
        'id_cuenta' => $cuentaId
    ];
}

function getDistritoData(mysqli $conn, string $distritoName, ?int $zonaId, array &$errors, int $lineNumber): array|false {
    if ($zonaId !== null) {
        $stmt = $conn->prepare("
            SELECT id, id_zona
            FROM distrito
            WHERE LOWER(nombre_distrito) = LOWER(?)
              AND id_zona = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT distrito en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('si', $distritoName, $zonaId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, id_zona
            FROM distrito
            WHERE LOWER(nombre_distrito) = LOWER(?)
            LIMIT 1
        ");
        if (!$stmt) {
            $errors[] = "Error preparando SELECT distrito en línea $lineNumber: " . $conn->error;
            return false;
        }

        $stmt->bind_param('s', $distritoName);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        return [
            'id'      => (int)$row['id'],
            'id_zona' => isset($row['id_zona']) ? (int)$row['id_zona'] : null
        ];
    }

    if ($zonaId === null) {
        $errors[] = "El distrito '$distritoName' no existe y no se indicó una zona para crearlo en línea $lineNumber.";
        return false;
    }

    $stmtIns = $conn->prepare("INSERT INTO distrito (nombre_distrito, id_zona) VALUES (?, ?)");
    if (!$stmtIns) {
        $errors[] = "Error preparando INSERT distrito en línea $lineNumber: " . $conn->error;
        return false;
    }

    $stmtIns->bind_param('si', $distritoName, $zonaId);
    if (!$stmtIns->execute()) {
        $errors[] = "Error insertando distrito '$distritoName' en línea $lineNumber: " . $stmtIns->error;
        $stmtIns->close();
        return false;
    }

    $newId = (int)$stmtIns->insert_id;
    $stmtIns->close();

    return [
        'id'      => $newId,
        'id_zona' => $zonaId
    ];
}

function getExistingLocalByCode(mysqli $conn, string $codigo, array &$errors, int $lineNumber): array|false {
    $sql = "
        SELECT
            l.id,
            l.codigo,
            l.nombre,
            l.direccion,
            l.id_comuna,
            l.id_cuenta,
            l.id_cadena,
            l.id_zona,
            l.id_distrito,
            c.comuna,
            r.region,
            cu.nombre AS cuenta,
            ca.nombre AS cadena,
            ca.id_cuenta AS cadena_id_cuenta,
            z.nombre_zona AS zona,
            d.nombre_distrito AS distrito,
            d.id_zona AS distrito_id_zona
        FROM local l
        LEFT JOIN comuna c   ON c.id = l.id_comuna
        LEFT JOIN region r   ON r.id = c.id_region
        LEFT JOIN cuenta cu  ON cu.id = l.id_cuenta
        LEFT JOIN cadena ca  ON ca.id = l.id_cadena
        LEFT JOIN zona z     ON z.id = l.id_zona
        LEFT JOIN distrito d ON d.id = l.id_distrito
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
    ?int $cuentaId,
    ?int $cadenaId,
    ?int $zonaId,
    ?int $distritoId,
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
            id_cuenta = ?,
            id_cadena = ?,
            id_zona = ?,
            id_distrito = ?,
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
        'ssiddiiiis',
        $nombre,
        $direccion,
        $comunaId,
        $lat,
        $lng,
        $cuentaId,
        $cadenaId,
        $zonaId,
        $distritoId,
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
        'message' => 'Los encabezados no son válidos. Deben incluir al menos: codigo, nombre local, direccion, comuna, region. Opcionales: cuenta, cadena, zona, distrito.'
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

    $cuentaName   = ($indexes['cuenta']   !== null) ? trim((string)($dataLine[$indexes['cuenta']]   ?? '')) : '';
    $cadenaName   = ($indexes['cadena']   !== null) ? trim((string)($dataLine[$indexes['cadena']]   ?? '')) : '';
    $zonaName     = ($indexes['zona']     !== null) ? trim((string)($dataLine[$indexes['zona']]     ?? '')) : '';
    $distritoName = ($indexes['distrito'] !== null) ? trim((string)($dataLine[$indexes['distrito']] ?? '')) : '';

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

    $cuentaId   = !empty($localActual['id_cuenta'])   ? (int)$localActual['id_cuenta']   : null;
    $cadenaId   = !empty($localActual['id_cadena'])   ? (int)$localActual['id_cadena']   : null;
    $zonaId     = !empty($localActual['id_zona'])     ? (int)$localActual['id_zona']     : null;
    $distritoId = !empty($localActual['id_distrito']) ? (int)$localActual['id_distrito'] : null;

    $cuentaFinal   = $localActual['cuenta'] ?? '';
    $cadenaFinal   = $localActual['cadena'] ?? '';
    $zonaFinal     = $localActual['zona'] ?? '';
    $distritoFinal = $localActual['distrito'] ?? '';

    if ($cuentaName !== '') {
        $tmpCuentaId = getCuentaId($conn, $cuentaName, $errors, $lineNumber);
        if ($tmpCuentaId === false) {
            $reason = array_pop($errors) ?: 'No se pudo resolver la cuenta.';
            $failures[] = [
                'line'   => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => $reason
            ];
            continue;
        }

        $cuentaId = $tmpCuentaId;
        $cuentaFinal = $cuentaName;

        if ($cadenaName === '' && $cadenaId !== null) {
            $cadenaCuentaActual = isset($localActual['cadena_id_cuenta']) ? (int)$localActual['cadena_id_cuenta'] : null;
            if ($cadenaCuentaActual !== null && $cadenaCuentaActual !== $cuentaId) {
                $failures[] = [
                    'line'   => $lineNumber,
                    'codigo' => $codigo,
                    'nombre' => $nombreLocal,
                    'reason' => 'La cuenta nueva no coincide con la cadena actual. Debes informar también la cadena.'
                ];
                continue;
            }
        }
    }

    if ($cadenaName !== '') {
        $cadenaData = getCadenaData($conn, $cadenaName, $cuentaId, $errors, $lineNumber);
        if ($cadenaData === false) {
            $reason = array_pop($errors) ?: 'No se pudo resolver la cadena.';
            $failures[] = [
                'line'   => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => $reason
            ];
            continue;
        }

        $cadenaId = (int)$cadenaData['id'];
        $cadenaFinal = $cadenaName;

        if ($cuentaId === null && isset($cadenaData['id_cuenta'])) {
            $cuentaId = (int)$cadenaData['id_cuenta'];
        }

        if ($cuentaId !== null) {
            $cuentaLookup = $conn->prepare("SELECT nombre FROM cuenta WHERE id = ? LIMIT 1");
            if ($cuentaLookup) {
                $cuentaLookup->bind_param('i', $cuentaId);
                $cuentaLookup->execute();
                $cuentaLookup->bind_result($tmpCuentaNombre);
                if ($cuentaLookup->fetch()) {
                    $cuentaFinal = $tmpCuentaNombre;
                }
                $cuentaLookup->close();
            }
        }
    }

    if ($zonaName !== '') {
        $tmpZonaId = getZonaId($conn, $zonaName, $errors, $lineNumber);
        if ($tmpZonaId === false) {
            $reason = array_pop($errors) ?: 'No se pudo resolver la zona.';
            $failures[] = [
                'line'   => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => $reason
            ];
            continue;
        }

        $zonaId = $tmpZonaId;
        $zonaFinal = $zonaName;

        if ($distritoName === '' && $distritoId !== null) {
            $distritoZonaActual = isset($localActual['distrito_id_zona']) ? (int)$localActual['distrito_id_zona'] : null;
            if ($distritoZonaActual !== null && $distritoZonaActual !== $zonaId) {
                $failures[] = [
                    'line'   => $lineNumber,
                    'codigo' => $codigo,
                    'nombre' => $nombreLocal,
                    'reason' => 'La zona nueva no coincide con el distrito actual. Debes informar también el distrito.'
                ];
                continue;
            }
        }
    }

    if ($distritoName !== '') {
        $distritoData = getDistritoData($conn, $distritoName, $zonaId, $errors, $lineNumber);
        if ($distritoData === false) {
            $reason = array_pop($errors) ?: 'No se pudo resolver el distrito.';
            $failures[] = [
                'line'   => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => $reason
            ];
            continue;
        }

        $distritoId = (int)$distritoData['id'];
        $distritoFinal = $distritoName;

        if ($zonaId === null && isset($distritoData['id_zona'])) {
            $zonaId = (int)$distritoData['id_zona'];
        }

        if ($zonaId !== null) {
            $zonaLookup = $conn->prepare("SELECT nombre_zona FROM zona WHERE id = ? LIMIT 1");
            if ($zonaLookup) {
                $zonaLookup->bind_param('i', $zonaId);
                $zonaLookup->execute();
                $zonaLookup->bind_result($tmpZonaNombre);
                if ($zonaLookup->fetch()) {
                    $zonaFinal = $tmpZonaNombre;
                }
                $zonaLookup->close();
            }
        }
    }

    $ok = updateLocalByCodigo(
        $conn,
        $codigo,
        $nombreLocal,
        $direccion,
        $comunaId,
        (float)$coords['lat'],
        (float)$coords['lng'],
        $cuentaId,
        $cadenaId,
        $zonaId,
        $distritoId,
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
        'line'               => $lineNumber,
        'codigo'             => $codigo,

        'nombre_anterior'    => $localActual['nombre'] ?? '',
        'nombre_nuevo'       => $nombreLocal,

        'direccion_anterior' => $localActual['direccion'] ?? '',
        'direccion_nueva'    => $direccion,

        'comuna_anterior'    => $localActual['comuna'] ?? '',
        'comuna_nueva'       => $comunaName,

        'cuenta_anterior'    => $localActual['cuenta'] ?? '',
        'cuenta_nueva'       => $cuentaFinal,

        'cadena_anterior'    => $localActual['cadena'] ?? '',
        'cadena_nueva'       => $cadenaFinal,

        'zona_anterior'      => $localActual['zona'] ?? '',
        'zona_nueva'         => $zonaFinal,

        'distrito_anterior'  => $localActual['distrito'] ?? '',
        'distrito_nuevo'     => $distritoFinal,

        'lat'                => $coords['lat'],
        'lng'                => $coords['lng']
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
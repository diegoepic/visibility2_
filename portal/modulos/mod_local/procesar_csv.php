<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

file_put_contents(__DIR__ . '/debug_files.txt', print_r($_FILES, true) . PHP_EOL . print_r($_POST, true));

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';


// Verificar que el usuario esté autenticado
if (!isset($usuario_id)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado.']);
    exit();
}

$sessionToken = trim($_SESSION['csrf_token']);
$postToken    = trim($_POST['csrf_token']);

if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    // aquí no ha habido ningún echo previo, así que headers funcionan
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'message' => 'Token CSRF inválido.'
    ]);
    exit();
}

$importMode = strtolower(trim((string)($_POST['import_mode'] ?? 'preview')));
if (!in_array($importMode, ['preview', 'create'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Modo de procesamiento no válido.']);
    exit();
}
$isPreview = $importMode === 'preview';
$createScope = strtolower(trim((string)($_POST['create_scope'] ?? 'accepted_only')));
if (!$isPreview && !in_array($createScope, ['accepted_only', 'accepted_review'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La selección de estados para crear no es válida.']);
    exit();
}
$creatableStatuses = $createScope === 'accepted_review'
    ? ['ACEPTADA', 'REVISION']
    : ['ACEPTADA'];

// Verificar que se haya seleccionado una Empresa
if (!$isPreview && (!isset($_POST['empresa_id']) || !filter_var($_POST['empresa_id'], FILTER_VALIDATE_INT))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar una Empresa para la carga masiva.']);
    exit();
}
$empresa_id_for_csv = intval($_POST['empresa_id'] ?? 0);

// Verificar que se haya seleccionado una División
if (!$isPreview && (!isset($_POST['division_id']) || !filter_var($_POST['division_id'], FILTER_VALIDATE_INT))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar una División para la carga masiva.']);
    exit();
}
$division_id_for_csv = intval($_POST['division_id'] ?? 0);

// ------------------------------------------------------------
// FUNCIONES AUXILIARES
// ------------------------------------------------------------
function removeBOM($str) {
    $bom = pack('H*','EFBBBF');
    if (substr($str, 0, 3) === $bom) {
        return substr($str, 3);
    }
    return $str;
}

function normalizeHeader($header) {
    $header = normalizeCsvText(removeBOM($header));
    $header = preg_replace('/[\x00-\x1F\x7F]/u', '', $header);
    $header = strtolower(trim($header));
    $header = preg_replace('/\s+/', ' ', $header);
    return $header;
}

/**
 * Convierte texto proveniente de Excel/CSV a UTF-8 y unifica espacios.
 * Tambien repara el mojibake comun (por ejemplo, "MUÃ‘OZ" -> "MUÑOZ").
 */
function normalizeCsvText($value) {
    $value = removeBOM((string)$value);

    if (preg_match('//u', $value) !== 1) {
        if (function_exists('mb_convert_encoding')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
    }

    if (
        function_exists('mb_convert_encoding') &&
        preg_match('/(?:Ã.|Â.|â.|�)/u', $value)
    ) {
        $repaired = @mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        if (
            $repaired !== false &&
            mb_check_encoding($repaired, 'UTF-8') &&
            !preg_match('/(?:Ã.|Â.|â.|�)/u', $repaired)
        ) {
            $value = $repaired;
        }
    }

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }

    // Incluye espacios normales, saltos de linea y separadores Unicode/NBSP.
    $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
    return trim($value);
}

require_once __DIR__ . '/etl_address_validation.php';
$google_maps_api_key = etlLoadGoogleMapsApiKey();
if ($google_maps_api_key === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Falta configurar GOOGLE_MAPS_API_KEY para Address Validation API.'
    ]);
    exit();
}

function acquireImportLock($conn, $lockName) {
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0)');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->bind_result($acquired);
    $stmt->fetch();
    $stmt->close();
    return intval($acquired) === 1;
}

function releaseImportLock($conn, $lockName) {
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if ($stmt) {
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->close();
    }
}

// Cada helper retorna el ID o false. En caso de false,
// se registra un mensaje en $errors; luego en el bucle
// principal lo convertimos en $failures structurado.

function getRegionId($conn, $regionName, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM region WHERE LOWER(region) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT region en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('s', $regionName);
    $stmt->execute();
    $stmt->bind_result($region_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $region_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO region (region) VALUES (?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT region en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('s', $regionName);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar nueva región '$regionName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getComunaId($conn, $comunaName, $region_id, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM comuna WHERE LOWER(comuna) = LOWER(?) AND id_region = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT comuna en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('si', $comunaName, $region_id);
    $stmt->execute();
    $stmt->bind_result($comuna_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $comuna_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO comuna (comuna, id_region) VALUES (?, ?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT comuna en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('si', $comunaName, $region_id);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar nueva comuna '$comunaName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getCuentaId($conn, $cuentaName, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM cuenta WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT cuenta en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('s', $cuentaName);
    $stmt->execute();
    $stmt->bind_result($cuenta_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $cuenta_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO cuenta (nombre) VALUES (?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT cuenta en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('s', $cuentaName);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar cuenta '$cuentaName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getCadenaId($conn, $cadenaName, $cuenta_id, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM cadena WHERE LOWER(nombre) = LOWER(?) AND id_cuenta = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT cadena en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('si', $cadenaName, $cuenta_id);
    $stmt->execute();
    $stmt->bind_result($cadena_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $cadena_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO cadena (nombre, id_cuenta) VALUES (?, ?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT cadena en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('si', $cadenaName, $cuenta_id);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar cadena '$cadenaName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getChannelId($conn, $channelName, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM canal WHERE LOWER(nombre_canal) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT canal en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('s', $channelName);
    $stmt->execute();
    $stmt->bind_result($channel_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $channel_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO canal (nombre_canal) VALUES (?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT canal en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('s', $channelName);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar canal '$channelName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getSubcanalId($conn, $subName, $canalId, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM subcanal WHERE LOWER(nombre_subcanal) = LOWER(?) AND id_canal = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT subcanal en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param('si', $subName, $canalId);
    $stmt->execute();
    $stmt->bind_result($subcanal_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $subcanal_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO subcanal (nombre_subcanal, id_canal) VALUES (?, ?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT subcanal en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param('si', $subName, $canalId);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar subcanal '$subName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function getZonaId($conn, $zonaName, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM zona WHERE LOWER(nombre_zona) = LOWER(?) LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT zona en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("s", $zonaName);
    $stmt->execute();
    $stmt->bind_result($zona_id);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists) {
        return $zona_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO zona (nombre_zona) VALUES (?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT zona en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param("s", $zonaName);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar nueva zona '$zonaName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_zona_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_zona_id;
    }
}

function getDistritoId($conn, $distritoName, $zona_id, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM distrito WHERE LOWER(nombre_distrito) = LOWER(?) AND id_zona = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT distrito en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("si", $distritoName, $zona_id);
    $stmt->execute();
    $stmt->bind_result($dist_id);
    $exists = $stmt->fetch();
    $stmt->close();
    if ($exists) {
        return $dist_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO distrito (nombre_distrito, id_zona) VALUES (?, ?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT distrito en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param("si", $distritoName, $zona_id);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar distrito '$distritoName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_dist_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_dist_id;
    }
}

function getJefeVentaId($conn, $jefeName, &$errors, $lineNumber) {
    $jefeName = etlNormalizeOptionalPerson($jefeName);
    $stmt = $conn->prepare("SELECT id FROM jefe_venta WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) ORDER BY id LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT jefe_venta en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("s", $jefeName);
    $stmt->execute();
    $stmt->bind_result($jefe_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $jefe_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO jefe_venta (nombre) VALUES (?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT jefe_venta en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param("s", $jefeName);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar jefe de venta '$jefeName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_jefe_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_jefe_id;
    }
}

function getVendedorId($conn, $idVendedor, $nombreVendedor, &$errors, $lineNumber) {
    $idVendTrim   = trim($idVendedor);
    $nomVendTrim  = etlNormalizeOptionalPerson($nombreVendedor);
    $vendCode = intval($idVendTrim);
    if ($vendCode > 0) {
        $stmt = $conn->prepare("SELECT id FROM vendedor WHERE id_vendedor = ? ORDER BY id LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $vendCode);
        }
    } else {
        $stmt = $conn->prepare("SELECT id FROM vendedor WHERE LOWER(TRIM(nombre_vendedor)) = LOWER(TRIM(?)) ORDER BY id LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $nomVendTrim);
        }
    }
    if (!$stmt) {
        $errors[] = "Error preparando SELECT vendedor en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->execute();
    $stmt->bind_result($dbVendId);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        if ($vendCode > 0 && $nomVendTrim !== 'NO APLICA') {
            $stmt_up = $conn->prepare("UPDATE vendedor SET nombre_vendedor = ? WHERE id = ?");
            if ($stmt_up) {
                $stmt_up->bind_param("si", $nomVendTrim, $dbVendId);
                $stmt_up->execute();
                $stmt_up->close();
            } else {
                $errors[] = "Error preparando UPDATE vendedor en línea $lineNumber: " . $conn->error;
            }
        }
        return $dbVendId;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO vendedor (id_vendedor, nombre_vendedor) VALUES (?, ?)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT vendedor en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param("is", $vendCode, $nomVendTrim);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar vendedor (id_vendedor=$vendCode) en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $newVendId = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $newVendId;
    }
}

function inspectJefeVentaByName($conn, $jefeName) {
    $jefeName = etlNormalizeOptionalPerson($jefeName);
    $stmt = $conn->prepare("SELECT id FROM jefe_venta WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) ORDER BY id");
    if (!$stmt) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA'];
    }
    $stmt->bind_param('s', $jefeName);
    $stmt->execute();
    $stmt->bind_result($id);
    $ids = [];
    while ($stmt->fetch()) {
        $ids[] = (int)$id;
    }
    $stmt->close();
    return [
        'id' => $ids[0] ?? null,
        'matches' => count($ids),
        'status' => count($ids) > 1 ? 'DUPLICADO_EN_BASE' : (count($ids) === 1 ? 'EXISTENTE' : 'NUEVO')
    ];
}

function inspectVendedorByName($conn, $vendedorName) {
    $vendedorName = etlNormalizeOptionalPerson($vendedorName);
    $stmt = $conn->prepare("SELECT id FROM vendedor WHERE LOWER(TRIM(nombre_vendedor)) = LOWER(TRIM(?)) ORDER BY id");
    if (!$stmt) {
        return ['id' => null, 'matches' => 0, 'status' => 'ERROR_CONSULTA'];
    }
    $stmt->bind_param('s', $vendedorName);
    $stmt->execute();
    $stmt->bind_result($id);
    $ids = [];
    while ($stmt->fetch()) {
        $ids[] = (int)$id;
    }
    $stmt->close();
    return [
        'id' => $ids[0] ?? null,
        'matches' => count($ids),
        'status' => count($ids) > 1 ? 'DUPLICADO_EN_BASE' : (count($ids) === 1 ? 'EXISTENTE' : 'NUEVO')
    ];
}

function getDivisionId($conn, $divisionName, $empresa_id, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM division_empresa WHERE LOWER(nombre) = LOWER(?) AND id_empresa = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT división en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("si", $divisionName, $empresa_id);
    $stmt->execute();
    $stmt->bind_result($division_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        return $division_id;
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO division_empresa (nombre, id_empresa, estado) VALUES (?, ?, 1)");
        if (!$stmt_ins) {
            $errors[] = "Error preparando INSERT división en línea $lineNumber: " . $conn->error;
            return false;
        }
        $stmt_ins->bind_param("si", $divisionName, $empresa_id);
        if (!$stmt_ins->execute()) {
            $errors[] = "Error al insertar nueva división '$divisionName' en línea $lineNumber: " . $stmt_ins->error;
            $stmt_ins->close();
            return false;
        }
        $new_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        return $new_id;
    }
}

function geocodeAddress($fullAddress, $apiKey, &$errors, $lineNumber) {
    $addrEnc = urlencode($fullAddress);
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addrEnc}&key=" . rawurlencode($apiKey) . "&components=country:CL";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $errors[] = "cURL error en linea $lineNumber: " . curl_error($ch);
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    if ($http_code != 200) {
        $errors[] = "Error HTTP ($http_code) geocodificando en línea $lineNumber.";
        return false;
    }
    $resp = json_decode($response, true);
    if ($resp['status'] === 'OK') {
        return [
            'lat' => $resp['results'][0]['geometry']['location']['lat'],
            'lng' => $resp['results'][0]['geometry']['location']['lng']
        ];
    } else {
        $errors[] = "Geocoding fallido en línea $lineNumber: " . $resp['status'];
        return false;
    }
}

function insertLocal($conn, $data, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_division = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT local en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("si", $data['codigo'], $data['id_division']);
    $stmt->execute();
    $stmt->bind_result($local_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        $errors[] = "El código de local '{$data['codigo']}' ya existe para esta división (línea $lineNumber).";
        return false;
    }
    $sql = "INSERT INTO local (
        codigo, nombre, direccion, direccion_original, direccion_google,
        estado_address_validation, google_response_id, id_cuenta, id_cadena,
        id_comuna, id_empresa, id_canal, id_subcanal,
        lat, lng, relevancia, id_zona, id_distrito,
        id_jefe_venta, id_vendedor, id_division, direccion_validada_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt_ins = $conn->prepare($sql);
    if (!$stmt_ins) {
        $errors[] = "Error preparando INSERT local en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt_ins->bind_param(
        "sssssssiiiiiiddiiiiii",
        $data['codigo'], $data['nombre'], $data['direccion'],
        $data['direccion_original'], $data['direccion_google'],
        $data['estado_address_validation'], $data['google_response_id'],
        $data['id_cuenta'], $data['id_cadena'], $data['id_comuna'],
        $data['id_empresa'], $data['id_canal'], $data['id_subcanal'],
        $data['lat'], $data['lng'], $data['relevancia'],
        $data['id_zona'], $data['id_distrito'], $data['id_jefe_venta'],
        $data['id_vendedor'], $data['id_division']
    );
    if (!$stmt_ins->execute()) {
        $errors[] = "Error al insertar local (código={$data['codigo']}) en línea $lineNumber: " . $stmt_ins->error;
        $stmt_ins->close();
        return false;
    }
    $stmt_ins->close();
    return true;
}

function localAlreadyExists($conn, $codigo, $divisionId, &$errors, $lineNumber) {
    $stmt = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_division = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando validacion de local en linea $lineNumber: " . $conn->error;
        return null;
    }
    $stmt->bind_param('si', $codigo, $divisionId);
    if (!$stmt->execute()) {
        $errors[] = "Error validando el codigo de local en linea $lineNumber: " . $stmt->error;
        $stmt->close();
        return null;
    }
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

// ------------------------------------------------------------
// PROCESAMIENTO PRINCIPAL
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método de solicitud no permitido.']);
    exit();
}

if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No se ha subido correctamente el archivo CSV.']);
    exit();
}

$csvFile   = $_FILES['csvFile']['tmp_name'];
$fileInfo  = pathinfo($_FILES['csvFile']['name']);
$extension = strtolower($fileInfo['extension']);
if ($extension !== 'csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El archivo subido no es un archivo CSV.']);
    exit();
}

$delimiter = ";";
if (($handle = fopen($csvFile, "r")) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Error al abrir el archivo CSV.']);
    exit();
}

$conn->set_charset('utf8mb4');
$lineNumber = 1;
$successes  = [];
$acceptedRows = [];
$previewRows = [];
$previewValidationCache = [];
$failures   = [];
$errors     = [];
error_log('CSV leído completamente. Total insertados: ' . count($successes) . ', fallidos: ' . count($failures));

$headers = fgetcsv($handle, 200000, $delimiter);
if ($headers === false) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El archivo CSV está vacío.']);
    exit();
}
if (count($headers) > 0) {
    $headers[0] = removeBOM($headers[0]);
}

$encabezadosEsperados = [
    'codigo','canal','subcanal','cuenta','cadena',
    'nombre local','direccion','comuna','nombre vendedor','jefe de venta'
];

$headersLower   = array_map('normalizeHeader', $headers);
$esperadosLower = array_map('normalizeHeader', $encabezadosEsperados);
if (count($headersLower) !== count($esperadosLower) || $headersLower !== $esperadosLower) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Los encabezados del CSV no coinciden con los esperados.']);
    exit();
}

$uploadHash = hash_file('sha256', $csvFile);
if ($uploadHash === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No fue posible verificar el archivo subido.']);
    exit();
}

if (!$isPreview) {
    $previewHash = $_SESSION['local_etl_preview_hash'] ?? '';
    $previewTime = intval($_SESSION['local_etl_preview_time'] ?? 0);
    $previewEmpresa = intval($_SESSION['local_etl_preview_empresa'] ?? 0);
    $previewDivision = intval($_SESSION['local_etl_preview_division'] ?? 0);
    $storedValidationCache = $_SESSION['local_etl_preview_results'] ?? null;
    $previewExpired = $previewTime <= 0 || (time() - $previewTime) > 1800;

    if ($previewExpired || $previewHash === '' || !hash_equals($previewHash, $uploadHash)
        || $previewEmpresa !== $empresa_id_for_csv || $previewDivision !== $division_id_for_csv
        || !is_array($storedValidationCache) || empty($storedValidationCache)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Debes ejecutar nuevamente la prueba del mismo archivo, empresa y división antes de crear locales.'
        ]);
        exit();
    }
    $previewValidationCache = $storedValidationCache;
}

$importLockName = $isPreview ? 'visibility_mod_local_csv_preview' : 'visibility_mod_local_csv_import';
if (!acquireImportLock($conn, $importLockName)) {
    fclose($handle);
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Ya hay una carga masiva de locales en proceso. Espere a que termine antes de volver a intentarlo.'
    ]);
    exit();
}



while (($dataLine = fgetcsv($handle, 2000, $delimiter)) !== false) {
    $lineNumber++;

    // chequeo de columnas
    if (count($dataLine) !== 10) {
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => '',
            'nombre' => '',
            'reason' => "Número de columnas incorrecto (se esperaban 10)."
        ];
        continue;
    }

    // extraer campos
    list(
        $codigo, $canalName, $subName, $cuentaName, $cadenaName,
        $nombreLocal, $direccion, $comunaName, $nombreVend, $jefeVentaName
    ) = array_map('normalizeCsvText', $dataLine);

    $distritoName = '';
    $zonaName = '';
    $regionName = '';
    $relevanciaStr = '0';
    $idVendedorStr = '';

    // Limpieza equivalente a G:R y salida normalizada S:AA de la hoja ETL.
    $direccionOriginal = $direccion;
    $nombreOriginal = $nombreLocal;
    $comunaOriginal = $comunaName;
    $distritoOriginal = $distritoName;
    $zonaOriginal = $zonaName;
    $regionOriginal = $regionName;
    $codigo = etlUpper($codigo);
    $comunaName = etlUpper($comunaName);

    // Equivalente a los BUSCARV de W:Y: la comuna manda sobre los valores recibidos.
    $territory = etlLookupTerritoryByComuna($comunaName);
    if ($territory === null) {
        $failures[] = [
            'line' => $lineNumber,
            'codigo' => $codigo,
            'nombre' => etlBuildLocalName($codigo, $nombreLocal, $comunaName),
            'reason' => "La comuna '$comunaOriginal' no existe en el catálogo extraído de datos!A:D.",
            'estado_validacion' => 'COMUNA_SIN_MAPEO',
            'direccion_original' => $direccionOriginal,
            'comuna_original' => $comunaOriginal
        ];
        continue;
    }

    $comunaName = $territory['comuna'];
    $distritoName = $territory['distrito'];
    $zonaName = $territory['zona'];
    $regionName = $territory['region'];
    $direccion = etlCleanAddress($direccion, $comunaName);
    $nombreLocal = etlBuildLocalName($codigo, $nombreLocal, $comunaName);
    $canalName = etlUpper($canalName);
    $subName = etlUpper($subName);
    $cuentaName = etlUpper($cuentaName);
    $cadenaName = etlUpper($cadenaName);
    $nombreVend = etlNormalizeOptionalPerson($nombreVend);
    $jefeVentaName = etlNormalizeOptionalPerson($jefeVentaName);

    // validaciones básicas
    if (
        $codigo === '' || $canalName === '' || $subName === '' ||
        $cuentaName === '' || $cadenaName === '' ||
        $nombreOriginal === '' || $direccion === '' ||
        $comunaName === '' || $distritoName === '' ||
        $zonaName === '' || $regionName === ''
    ) {
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => "Algún campo requerido está vacío."
        ];
        continue;
    }

    $relevancia = intval($relevanciaStr);
    $vendedorResolution = inspectVendedorByName($conn, $nombreVend);
    $jefeResolution = inspectJefeVentaByName($conn, $jefeVentaName);

    // La prueba puede consultar catálogos para informar coincidencias, pero nunca los modifica.
    // La existencia del código de local se revisa solo al crear.
    if (!$isPreview) {
        $localExists = localAlreadyExists($conn, $codigo, $division_id_for_csv, $errors, $lineNumber);
        if ($localExists === null) {
            $reason = array_pop($errors);
            $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
            continue;
        }
        if ($localExists) {
            $failures[] = [
                'line'   => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => "El codigo de local '$codigo' ya existe para esta division."
            ];
            continue;
        }
    }

    // Validar antes de escribir datos maestros. Solo ACEPTADA puede insertarse.
    if ($isPreview) {
        $validation = etlValidateAddress($direccion, $comunaName, $google_maps_api_key, $lineNumber);
        $previewValidationCache[$lineNumber] = $validation;
    } else {
        $validation = $previewValidationCache[$lineNumber] ?? null;
        if (!is_array($validation)) {
            $failures[] = [
                'line' => $lineNumber,
                'codigo' => $codigo,
                'nombre' => $nombreLocal,
                'reason' => 'La fila no tiene un resultado vigente de la prueba previa.'
            ];
            continue;
        }
    }
    $previewRow = [
        'line' => $lineNumber,
        'codigo' => $codigo,
        'nombre_original' => $nombreOriginal,
        'nombre_normalizado' => $nombreLocal,
        'direccion_original' => $direccionOriginal,
        'direccion_limpia' => $direccion,
        'direccion_google' => $validation['formatted_address'],
        // Nunca perder la direccion util del archivo por una respuesta vacia de Google.
        'direccion_propuesta' => etlPreferredAddress($validation, $direccion),
        'comuna_original' => $comunaOriginal,
        'comuna_normalizada' => $comunaName,
        'comuna_google' => $validation['suggested_locality'],
        'distrito_original' => $distritoOriginal,
        'distrito_mapeado' => $distritoName,
        'zona_original' => $zonaOriginal,
        'zona_mapeada' => $zonaName,
        'region_original' => $regionOriginal,
        'region_mapeada' => $regionName,
        'canal' => $canalName,
        'subcanal' => $subName,
        'cuenta' => $cuentaName,
        'cadena' => $cadenaName,
        'relevancia' => $relevancia,
        'nombre_vendedor' => $nombreVend,
        'vendedor_id' => $vendedorResolution['id'],
        'vendedor_status' => $vendedorResolution['status'],
        'vendedor_matches' => $vendedorResolution['matches'],
        'jefe_venta' => $jefeVentaName,
        'jefe_id' => $jefeResolution['id'],
        'jefe_status' => $jefeResolution['status'],
        'jefe_matches' => $jefeResolution['matches'],
        'estado' => $validation['status'],
        'motivo' => $validation['reason'],
        'accion_google' => $validation['possible_next_action'],
        'granularidad_validacion' => $validation['validation_granularity'],
        'granularidad_geocodigo' => $validation['geocode_granularity'],
        'componentes_faltantes' => $validation['missing_components'],
        'tokens_no_resueltos' => $validation['unresolved_tokens'],
        'lat' => $validation['lat'],
        'lng' => $validation['lng'],
        'response_id' => $validation['response_id']
    ];

    if ($isPreview) {
        $previewRows[] = $previewRow;
        continue;
    }

    if (!in_array($validation['status'], $creatableStatuses, true)) {
        $failures[] = [
            'line' => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $validation['reason'],
            'estado_validacion' => $validation['status'],
            'direccion_original' => $direccionOriginal,
            'direccion_limpia' => $direccion,
            'direccion_sugerida_google' => $validation['formatted_address'],
            'calle_sugerida_google' => $validation['suggested_street'],
            'comuna_original' => $comunaOriginal,
            'comuna_sugerida_google' => $validation['suggested_locality'],
            'granularidad_validacion' => $validation['validation_granularity'],
            'granularidad_geocodigo' => $validation['geocode_granularity'],
            'accion_google' => $validation['possible_next_action'],
            'componentes_faltantes' => $validation['missing_components'],
            'tokens_no_resueltos' => $validation['unresolved_tokens'],
            'response_id' => $validation['response_id']
        ];
        continue;
    }

    $direccion = etlPreferredAddress($validation, $direccion);
    $coords = ['lat' => $validation['lat'], 'lng' => $validation['lng']];

    $conn->begin_transaction();

    // 1) Región
    $regionId = getRegionId($conn, $regionName, $errors, $lineNumber);
    if ($regionId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 2) Comuna
    $comunaId = getComunaId($conn, $comunaName, $regionId, $errors, $lineNumber);
    if ($comunaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 3) Canal
    $canalId = getChannelId($conn, $canalName, $errors, $lineNumber);
    if ($canalId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 4) Subcanal
    $subcanalId = getSubcanalId($conn, $subName, $canalId, $errors, $lineNumber);
    if ($subcanalId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 5) Cuenta
    $cuentaId = getCuentaId($conn, $cuentaName, $errors, $lineNumber);
    if ($cuentaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 6) Cadena
    $cadenaId = getCadenaId($conn, $cadenaName, $cuentaId, $errors, $lineNumber);
    if ($cadenaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 7) Zona
    $zonaId = getZonaId($conn, $zonaName, $errors, $lineNumber);
    if ($zonaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 8) Distrito
    $distritoId = getDistritoId($conn, $distritoName, $zonaId, $errors, $lineNumber);
    if ($distritoId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 9) Jefe de venta
    $jefeVentaId = getJefeVentaId($conn, $jefeVentaName, $errors, $lineNumber);
    if ($jefeVentaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 10) Vendedor
    $vendedorId = getVendedorId($conn, $idVendedorStr, $nombreVend, $errors, $lineNumber);
    if ($vendedorId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        $conn->rollback();
        continue;
    }

    // 11) División (ya viene seleccionada)
    $divisionId = $division_id_for_csv;

    // Preparar los datos para inserción
    $localData = [
        'codigo'        => $codigo,
        'nombre'        => $nombreLocal,
        'direccion'     => $direccion,
        'direccion_original' => $direccionOriginal,
        'direccion_google' => $validation['formatted_address'],
        'estado_address_validation' => $validation['status'],
        'google_response_id' => $validation['response_id'],
        'id_cuenta'     => $cuentaId,
        'id_cadena'     => $cadenaId,
        'id_comuna'     => $comunaId,
        'id_empresa'    => $empresa_id_for_csv,
        'id_canal'      => $canalId,
        'id_subcanal'   => $subcanalId,
        'lat'           => $coords['lat'],
        'lng'           => $coords['lng'],
        'relevancia'    => $relevancia,
        'id_zona'       => $zonaId,
        'id_distrito'   => $distritoId,
        'id_jefe_venta' => $jefeVentaId,
        'id_vendedor'   => $vendedorId,
        'id_division'   => $divisionId
    ];

    // 13) Insertar local
    $ok = insertLocal($conn, $localData, $errors, $lineNumber);
    if (!$ok) {
        $reason = array_pop($errors);
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => $codigo,
            'nombre' => $nombreLocal,
            'reason' => $reason
        ];
        $conn->rollback();
        continue;
    }

    // Si todo salió bien:
    $conn->commit();
    $successes[] = "Línea $lineNumber: Local '$nombreLocal' (código $codigo) insertado.";
    $acceptedRows[] = [
        'line' => $lineNumber,
        'codigo' => $codigo,
        'nombre' => $nombreLocal,
        'estado' => $validation['status'],
        'direccion_original' => $direccionOriginal,
        'direccion_limpia' => etlCleanAddress($direccionOriginal, $comunaName),
        'direccion_google' => $validation['formatted_address'],
        'direccion_guardada' => $direccion,
        'comuna' => $comunaName,
        'comuna_google' => $validation['suggested_locality'],
        'distrito' => $distritoName,
        'zona' => $zonaName,
        'region' => $regionName,
        'cadena' => $cadenaName,
        'cuenta' => $cuentaName,
        'lat' => $coords['lat'],
        'lng' => $coords['lng'],
        'granularidad_validacion' => $validation['validation_granularity'],
        'granularidad_geocodigo' => $validation['geocode_granularity'],
        'response_id' => $validation['response_id']
    ];
}

fclose($handle);
releaseImportLock($conn, $importLockName);

if ($isPreview) {
    $previewReportName = 'address_validation_preview_' . time() . '.csv';
    $previewReportDir = __DIR__ . '/uploads/reports/';
    if (!is_dir($previewReportDir)) {
        mkdir($previewReportDir, 0755, true);
    }
    $previewReportPath = $previewReportDir . $previewReportName;
    $previewFp = fopen($previewReportPath, 'w');
    fwrite($previewFp, "\xEF\xBB\xBF");
    fputcsv($previewFp, [
        'codigo','canal','subcanal','cuenta','cadena','nombre local','direccion',
        'comuna','distrito','zona','region','relevancia','id vendedor','nombre vendedor',
        'jefe de venta','id jefe de venta','estado vendedor','coincidencias vendedor',
        'estado jefe de venta','coincidencias jefe de venta','direccion original',
        'direccion limpia','direccion completa google','comuna original','comuna google',
        'estado address validation','motivo','accion google','granularidad validacion',
        'granularidad geocodigo','componentes faltantes','tokens no resueltos',
        'lat','lng','response id','linea origen'
    ], ';');

    foreach ($previewRows as $row) {
        fputcsv($previewFp, [
            $row['codigo'], $row['canal'], $row['subcanal'], $row['cuenta'], $row['cadena'],
            $row['nombre_normalizado'], $row['direccion_propuesta'], $row['comuna_normalizada'],
            $row['distrito_mapeado'], $row['zona_mapeada'], $row['region_mapeada'],
            $row['relevancia'], $row['vendedor_id'] ?? 'SE CREARA', $row['nombre_vendedor'],
            $row['jefe_venta'], $row['jefe_id'] ?? 'SE CREARA', $row['vendedor_status'],
            $row['vendedor_matches'], $row['jefe_status'], $row['jefe_matches'],
            $row['direccion_original'], $row['direccion_limpia'], $row['direccion_google'],
            $row['comuna_original'], $row['comuna_google'], $row['estado'], $row['motivo'],
            $row['accion_google'], $row['granularidad_validacion'], $row['granularidad_geocodigo'],
            $row['componentes_faltantes'], $row['tokens_no_resueltos'], $row['lat'], $row['lng'],
            $row['response_id'], $row['line']
        ], ';');
    }

    foreach ($failures as $failure) {
        $failureRow = array_fill(0, 36, '');
        $failureAddressOriginal = $failure['direccion_original'] ?? '';
        $failureAddressClean = $failure['direccion_limpia'] ?? '';
        if ($failureAddressClean === '' && $failureAddressOriginal !== '') {
            $failureAddressClean = etlUpper($failureAddressOriginal);
        }
        $failureRow[0] = $failure['codigo'] ?? '';
        $failureRow[5] = $failure['nombre'] ?? '';
        $failureRow[6] = $failureAddressClean;
        $failureRow[20] = $failureAddressOriginal;
        $failureRow[21] = $failureAddressClean;
        $failureRow[23] = $failure['comuna_original'] ?? '';
        $failureRow[25] = $failure['estado_validacion'] ?? 'ERROR_ENTRADA';
        $failureRow[26] = $failure['reason'] ?? '';
        $failureRow[35] = $failure['line'] ?? '';
        fputcsv($previewFp, $failureRow, ';');
    }
    fclose($previewFp);

    $acceptedCount = count(array_filter($previewRows, function ($row) {
        return $row['estado'] === 'ACEPTADA';
    }));
    $reviewCount = count(array_filter($previewRows, function ($row) {
        return $row['estado'] === 'REVISION';
    }));
    $rejectedCount = count(array_filter($previewRows, function ($row) {
        return $row['estado'] === 'RECHAZADA';
    })) + count($failures);

    $_SESSION['local_etl_preview_hash'] = $uploadHash;
    $_SESSION['local_etl_preview_time'] = time();
    $_SESSION['local_etl_preview_empresa'] = $empresa_id_for_csv;
    $_SESSION['local_etl_preview_division'] = $division_id_for_csv;
    $_SESSION['local_etl_preview_results'] = $previewValidationCache;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'preview' => true,
        'tested' => count($previewRows) + count($failures),
        'accepted' => $acceptedCount,
        'review' => $reviewCount,
        'rejected' => $rejectedCount,
        'previewReportUrl' => '/visibility2/portal/modulos/mod_local/uploads/reports/' . $previewReportName
    ]);
    exit();
}

// Reporte auditable de direcciones efectivamente insertadas.
$acceptedReportUrl = '';
if (!empty($acceptedRows)) {
    $acceptedReportName = 'validated_locals_' . time() . '.csv';
    $acceptedReportDir = __DIR__ . '/uploads/reports/';
    if (!is_dir($acceptedReportDir)) {
        mkdir($acceptedReportDir, 0755, true);
    }
    $acceptedReportPath = $acceptedReportDir . $acceptedReportName;
    $acceptedFp = fopen($acceptedReportPath, 'w');
    fwrite($acceptedFp, "\xEF\xBB\xBF");
    fputcsv($acceptedFp, [
        'linea','codigo','nombre local','estado address validation','direccion original','direccion limpia',
        'direccion validada google','direccion guardada','comuna','comuna google',
        'distrito','zona','region','cadena','cuenta','lat','lng',
        'granularidad validacion','granularidad geocodigo','response id'
    ], ';');
    foreach ($acceptedRows as $row) {
        fputcsv($acceptedFp, [
            $row['line'], $row['codigo'], $row['nombre'], $row['estado'], $row['direccion_original'],
            $row['direccion_limpia'], $row['direccion_google'], $row['direccion_guardada'],
            $row['comuna'], $row['comuna_google'], $row['distrito'], $row['zona'],
            $row['region'], $row['cadena'], $row['cuenta'], $row['lat'], $row['lng'],
            $row['granularidad_validacion'], $row['granularidad_geocodigo'], $row['response_id']
        ], ';');
    }
    fclose($acceptedFp);
    $acceptedReportUrl = '/visibility2/portal/modulos/mod_local/uploads/reports/' . $acceptedReportName;
}

//  Generar CSV de fallidos si hay alguno
$reportUrl = '';
if (!empty($failures)) {
    $reportName = 'failed_locals_' . time() . '.csv';
    $reportDir  = __DIR__ . '/uploads/reports/';
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0755, true);
    }
    $reportPath = $reportDir . $reportName;
    $fp = fopen($reportPath, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    // Cabecera
    fputcsv($fp, [
        'linea','codigo','nombre local','estado validacion','motivo de fallo',
        'direccion original','direccion limpia','direccion sugerida google',
        'calle sugerida google','comuna original','comuna sugerida google',
        'granularidad validacion','granularidad geocodigo','accion google',
        'componentes faltantes','tokens no resueltos','response id'
    ], ';');
    foreach ($failures as $f) {
        fputcsv($fp, [
            $f['line'],
            $f['codigo'],
            $f['nombre'],
            $f['estado_validacion'] ?? 'ERROR_IMPORTACION',
            $f['reason'],
            $f['direccion_original'] ?? '',
            $f['direccion_limpia'] ?? '',
            $f['direccion_sugerida_google'] ?? '',
            $f['calle_sugerida_google'] ?? '',
            $f['comuna_original'] ?? '',
            $f['comuna_sugerida_google'] ?? '',
            $f['granularidad_validacion'] ?? '',
            $f['granularidad_geocodigo'] ?? '',
            $f['accion_google'] ?? '',
            $f['componentes_faltantes'] ?? '',
            $f['tokens_no_resueltos'] ?? '',
            $f['response_id'] ?? ''
        ], ';');
    }
    fclose($fp);
    // Ruta accesible desde el navegador
    $reportUrl = '/visibility2/portal/modulos/mod_local/uploads/reports/' . $reportName;
}

$dataFinal = [
    'success'    => !empty($successes),
    'inserted'   => count($successes),
    'failed'     => count($failures),
    'acceptedReportUrl' => $acceptedReportUrl,
    'reportUrl'  => $reportUrl,
    'successes'  => $successes,
    'failures'   => $failures
];
$ok = file_put_contents(__DIR__ . '/debug_respuesta_final.txt', json_encode($dataFinal, JSON_PRETTY_PRINT));

if ($ok === false) {
    error_log('Error: No se pudo escribir debug_respuesta_final.txt');
}

// Respuesta final
unset(
    $_SESSION['local_etl_preview_hash'],
    $_SESSION['local_etl_preview_time'],
    $_SESSION['local_etl_preview_empresa'],
    $_SESSION['local_etl_preview_division'],
    $_SESSION['local_etl_preview_results']
);
http_response_code(200);
echo json_encode([
    'success'    => !empty($successes),
    'inserted'   => count($successes),
    'failed'     => count($failures),
    'acceptedReportUrl' => $acceptedReportUrl,
    'reportUrl'  => $reportUrl,
    'successes'  => $successes,
    'failures'   => $failures
]);
exit();
?>

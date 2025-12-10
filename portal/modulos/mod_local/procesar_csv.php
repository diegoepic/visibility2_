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

// Verificar que se haya seleccionado una Empresa
if (!isset($_POST['empresa_id']) || !filter_var($_POST['empresa_id'], FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar una Empresa para la carga masiva.']);
    exit();
}
$empresa_id_for_csv = intval($_POST['empresa_id']);

// Verificar que se haya seleccionado una División
if (!isset($_POST['division_id']) || !filter_var($_POST['division_id'], FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar una División para la carga masiva.']);
    exit();
}
$division_id_for_csv = intval($_POST['division_id']);

// Clave de Maps
$google_maps_api_key = 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw';

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
    $header = removeBOM($header);
    $header = preg_replace('/[\x00-\x1F\x7F]/u', '', $header);
    $header = strtolower(trim($header));
    $header = preg_replace('/\s+/', ' ', $header);
    return $header;
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
    if (trim($jefeName) === '') {
        return 0;
    }
    $stmt = $conn->prepare("SELECT id FROM jefe_venta WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
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
    $nomVendTrim  = trim($nombreVendedor);
    if ($idVendTrim === '' && $nomVendTrim === '') {
        return 0;
    }
    $vendCode = intval($idVendTrim);
    $stmt = $conn->prepare("SELECT id FROM vendedor WHERE id_vendedor = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT vendedor en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("i", $vendCode);
    $stmt->execute();
    $stmt->bind_result($dbVendId);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        if ($nomVendTrim !== '') {
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
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$addrEnc}&key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&components=country:CL";
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
    $stmt = $conn->prepare("SELECT id FROM local WHERE codigo = ? LIMIT 1");
    if (!$stmt) {
        $errors[] = "Error preparando SELECT local en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt->bind_param("s", $data['codigo']);
    $stmt->execute();
    $stmt->bind_result($local_id);
    $existe = $stmt->fetch();
    $stmt->close();
    if ($existe) {
        $errors[] = "El código de local '{$data['codigo']}' ya existe (línea $lineNumber).";
        return false;
    }
    $sql = "INSERT INTO local (
        codigo, nombre, direccion, id_cuenta, id_cadena,
        id_comuna, id_empresa, id_canal, id_subcanal,
        lat, lng, relevancia, id_zona, id_distrito,
        id_jefe_venta, id_vendedor, id_division
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_ins = $conn->prepare($sql);
    if (!$stmt_ins) {
        $errors[] = "Error preparando INSERT local en línea $lineNumber: " . $conn->error;
        return false;
    }
    $stmt_ins->bind_param(
        "sssiiiiiiddiiiiii",
        $data['codigo'], $data['nombre'], $data['direccion'],
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

$conn->set_charset('utf8');
$lineNumber = 1;
$successes  = [];
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
    'nombre local','direccion','comuna','distrito',
    'zona','region','relevancia','id vendedor',
    'nombre vendedor','jefe de venta'
];

$headersLower   = array_map('normalizeHeader', $headers);
$esperadosLower = array_map('normalizeHeader', $encabezadosEsperados);
if (count($headersLower) !== count($esperadosLower) || $headersLower !== $esperadosLower) {
    fclose($handle);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Los encabezados del CSV no coinciden con los esperados.']);
    exit();
}



while (($dataLine = fgetcsv($handle, 2000, $delimiter)) !== false) {
    $lineNumber++;

    // chequeo de columnas
    if (count($dataLine) !== 15) {
        $failures[] = [
            'line'   => $lineNumber,
            'codigo' => '',
            'nombre' => '',
            'reason' => "Número de columnas incorrecto (se esperaban 15)."
        ];
        continue;
    }

    // extraer campos
    list(
        $codigo, $canalName, $subName, $cuentaName, $cadenaName,
        $nombreLocal, $direccion, $comunaName, $distritoName,
        $zonaName, $regionName, $relevanciaStr, $idVendedorStr,
        $nombreVend, $jefeVentaName
    ) = array_map('trim', $dataLine);

    // validaciones básicas
    if (
        $codigo === '' || $canalName === '' || $subName === '' ||
        $cuentaName === '' || $cadenaName === '' ||
        $nombreLocal === '' || $direccion === '' ||
        $comunaName === '' || $distritoName === '' ||
        $zonaName === '' || $regionName === '' ||
        $relevanciaStr === ''
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

    // 1) Región
    $regionId = getRegionId($conn, $regionName, $errors, $lineNumber);
    if ($regionId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 2) Comuna
    $comunaId = getComunaId($conn, $comunaName, $regionId, $errors, $lineNumber);
    if ($comunaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 3) Canal
    $canalId = getChannelId($conn, $canalName, $errors, $lineNumber);
    if ($canalId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 4) Subcanal
    $subcanalId = getSubcanalId($conn, $subName, $canalId, $errors, $lineNumber);
    if ($subcanalId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 5) Cuenta
    $cuentaId = getCuentaId($conn, $cuentaName, $errors, $lineNumber);
    if ($cuentaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 6) Cadena
    $cadenaId = getCadenaId($conn, $cadenaName, $cuentaId, $errors, $lineNumber);
    if ($cadenaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 7) Zona
    $zonaId = getZonaId($conn, $zonaName, $errors, $lineNumber);
    if ($zonaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 8) Distrito
    $distritoId = getDistritoId($conn, $distritoName, $zonaId, $errors, $lineNumber);
    if ($distritoId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 9) Jefe de venta
    $jefeVentaId = getJefeVentaId($conn, $jefeVentaName, $errors, $lineNumber);
    if ($jefeVentaId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 10) Vendedor
    $vendedorId = getVendedorId($conn, $idVendedorStr, $nombreVend, $errors, $lineNumber);
    if ($vendedorId === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // 11) División (ya viene seleccionada)
    $divisionId = $division_id_for_csv;

    // 12) Geocoding
    $fullAddress = "$direccion, $comunaName, $regionName, Chile";
    $coords = geocodeAddress($fullAddress, $google_maps_api_key, $errors, $lineNumber);
    if ($coords === false) {
        $reason = array_pop($errors);
        $failures[] = ['line'=>$lineNumber,'codigo'=>$codigo,'nombre'=>$nombreLocal,'reason'=>$reason];
        continue;
    }

    // Preparar los datos para inserción
    $localData = [
        'codigo'        => $codigo,
        'nombre'        => $nombreLocal,
        'direccion'     => $direccion,
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
        continue;
    }

    // Si todo salió bien:
    $successes[] = "Línea $lineNumber: Local '$nombreLocal' (código $codigo) insertado.";
}

fclose($handle);

//  Generar CSV de fallidos si hay alguno
$reportUrl = '';
if (!empty($failures)) {
    header('Content-Type: text/csv; charset=utf-8');
    $reportName = 'failed_locals_' . time() . '.csv';
    $reportDir  = __DIR__ . '/uploads/reports/';
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0755, true);
    }
    $reportPath = $reportDir . $reportName;
    $fp = fopen($reportPath, 'w');
    // Cabecera
    fputcsv($fp, ['linea','codigo','nombre local','motivo de fallo'], ';');
    foreach ($failures as $f) {
        fputcsv($fp, [
            $f['line'],
            $f['codigo'],
            $f['nombre'],
            $f['reason']
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
    'reportUrl'  => $reportUrl,
    'successes'  => $successes,
    'failures'   => $failures
];
$ok = file_put_contents(__DIR__ . '/debug_respuesta_final.txt', json_encode($dataFinal, JSON_PRETTY_PRINT));

if ($ok === false) {
    error_log('Error: No se pudo escribir debug_respuesta_final.txt');
}

// Respuesta final
http_response_code(200);
echo json_encode([
    'success'    => !empty($successes),
    'inserted'   => count($successes),
    'failed'     => count($failures),
    'reportUrl'  => $reportUrl,
    'successes'  => $successes,
    'failures'   => $failures
]);
exit();
?>
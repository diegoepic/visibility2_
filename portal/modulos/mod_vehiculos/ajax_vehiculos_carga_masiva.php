<?php
session_start();

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

date_default_timezone_set('America/Santiago');

header('Content-Type: application/json; charset=utf-8');

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode([
        'ok' => false,
        'msg' => 'No existe conexión a base de datos.'
    ]);
    exit;
}

$mysqli->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN GOOGLE GEOCODING
|--------------------------------------------------------------------------
| Pega acá tu API Key de Google Maps / Geocoding.
| Recomendación: usar una key restringida por IP del servidor.
|--------------------------------------------------------------------------
*/

$GOOGLE_GEOCODING_API_KEY = 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw';

/*
|--------------------------------------------------------------------------
| HELPERS GENERALES
|--------------------------------------------------------------------------
*/

function responder($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizarTexto($texto) {
    $texto = trim((string)$texto);
    $texto = mb_strtolower($texto, 'UTF-8');

    $buscar = ['á', 'é', 'í', 'ó', 'ú', 'ñ'];
    $reemplazar = ['a', 'e', 'i', 'o', 'u', 'n'];

    $texto = str_replace($buscar, $reemplazar, $texto);
    $texto = preg_replace('/[^a-z0-9_]+/u', '_', $texto);
    $texto = trim($texto, '_');

    return $texto;
}

function valorFila($row, $keys, $default = '') {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return $default;
}

function detectarDelimitador($linea) {
    $puntoComa = substr_count($linea, ';');
    $coma = substr_count($linea, ',');

    return $puntoComa >= $coma ? ';' : ',';
}

function limpiarPatente($patente) {
    $patente = strtoupper(trim((string)$patente));
    $patente = str_replace([' ', '.', '_'], '', $patente);

    return $patente;
}

function validarFecha($fecha) {
    if (!$fecha) {
        return date('Y-m-d');
    }

    $fecha = trim($fecha);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return $fecha;
    }

    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $fecha)) {
        $partes = explode('-', $fecha);
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }

    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fecha)) {
        $partes = explode('/', $fecha);
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }

    throw new Exception('Fecha inválida. Use formato YYYY-MM-DD o DD-MM-YYYY.');
}

function normalizarEstadoCarga($valor, $default = 1) {
    $valorOriginal = trim((string)$valor);

    if ($valorOriginal === '') {
        return $default;
    }

    $valorNormalizado = normalizarTexto($valorOriginal);

    if (in_array($valorNormalizado, ['0', 'inactivo', 'inactiva', 'inactive', 'no'], true)) {
        return 0;
    }

    if (in_array($valorNormalizado, ['1', 'activo', 'activa', 'active', 'si'], true)) {
        return 1;
    }

    return ((int)$valorOriginal === 0) ? 0 : 1;
}

function textosSonDistintos($a, $b) {
    return normalizarTexto($a) !== normalizarTexto($b);
}

/*
|--------------------------------------------------------------------------
| GOOGLE GEOCODING
|--------------------------------------------------------------------------
*/

function geocodificarDireccionGoogle($direccion, $apiKey) {
    $direccion = trim((string)$direccion);
    $apiKey = trim((string)$apiKey);

    if ($direccion === '') {
        return [
            'ok' => false,
            'msg' => 'Dirección vacía.'
        ];
    }

    if ($apiKey === '') {
        return [
            'ok' => false,
            'msg' => 'Debe configurar la API Key de Google Geocoding en el backend.'
        ];
    }

    $direccionConsulta = $direccion;

    if (stripos($direccionConsulta, 'chile') === false) {
        $direccionConsulta .= ', Chile';
    }

    $params = http_build_query([
        'address' => $direccionConsulta,
        'region' => 'cl',
        'key' => $apiKey
    ]);

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . $params;

    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            return [
                'ok' => false,
                'msg' => 'Error cURL al geocodificar dirección: ' . $curlError
            ];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'ok' => false,
                'msg' => 'No se pudo consultar Google Geocoding.'
            ];
        }
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'msg' => 'Respuesta inválida desde Google Geocoding.'
        ];
    }

    $status = $json['status'] ?? 'UNKNOWN';

    if ($status !== 'OK') {
        $mensajeGoogle = $json['error_message'] ?? $status;

        return [
            'ok' => false,
            'msg' => 'Google Geocoding no pudo resolver la dirección: ' . $mensajeGoogle
        ];
    }

    $location = $json['results'][0]['geometry']['location'] ?? null;

    if (!$location || !isset($location['lat'], $location['lng'])) {
        return [
            'ok' => false,
            'msg' => 'Google Geocoding no retornó coordenadas.'
        ];
    }

    return [
        'ok' => true,
        'lat' => (float)$location['lat'],
        'lng' => (float)$location['lng'],
        'direccion_formateada' => $json['results'][0]['formatted_address'] ?? $direccionConsulta
    ];
}

/*
|--------------------------------------------------------------------------
| RESOLUCIÓN DE CATÁLOGOS
|--------------------------------------------------------------------------
*/

function resolverEmpresa($mysqli, $valor, $idActual = null, $mantenerSiVacio = false) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        if ($mantenerSiVacio && $idActual) {
            return (int)$idActual;
        }

        throw new Exception('Empresa obligatoria.');
    }

    if (ctype_digit($valor)) {
        $id = (int)$valor;

        $stmt = $mysqli->prepare("
            SELECT id
            FROM empresa
            WHERE id = ?
            AND activo = 1
            LIMIT 1
        ");

        $stmt->bind_param("i", $id);
    } else {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM empresa
            WHERE UPPER(nombre) = UPPER(?)
            AND activo = 1
            LIMIT 1
        ");

        $stmt->bind_param("s", $valor);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        throw new Exception('Empresa no encontrada o inactiva: ' . $valor);
    }

    return (int)$row['id'];
}

function resolverDivision($mysqli, $valor, $idEmpresa, $idActual = null, $mantenerSiVacio = false) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        if ($mantenerSiVacio && $idActual) {
            return (int)$idActual;
        }

        throw new Exception('División obligatoria.');
    }

    if (ctype_digit($valor)) {
        $id = (int)$valor;

        $stmt = $mysqli->prepare("
            SELECT id
            FROM division_empresa
            WHERE id = ?
            AND id_empresa = ?
            AND estado = 1
            LIMIT 1
        ");

        $stmt->bind_param("ii", $id, $idEmpresa);
    } else {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM division_empresa
            WHERE UPPER(nombre) = UPPER(?)
            AND id_empresa = ?
            AND estado = 1
            LIMIT 1
        ");

        $stmt->bind_param("si", $valor, $idEmpresa);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        throw new Exception('División no encontrada para la empresa indicada: ' . $valor);
    }

    return (int)$row['id'];
}

function resolverSubdivision($mysqli, $valor, $idDivision, $idActual = null, $mantenerSiVacio = false) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        if ($mantenerSiVacio) {
            return $idActual ? (int)$idActual : null;
        }

        return null;
    }

    if (ctype_digit($valor)) {
        $id = (int)$valor;

        $stmt = $mysqli->prepare("
            SELECT id
            FROM subdivision
            WHERE id = ?
            AND id_division = ?
            LIMIT 1
        ");

        $stmt->bind_param("ii", $id, $idDivision);
    } else {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM subdivision
            WHERE UPPER(nombre) = UPPER(?)
            AND id_division = ?
            LIMIT 1
        ");

        $stmt->bind_param("si", $valor, $idDivision);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        throw new Exception('Subdivisión no encontrada para la división indicada: ' . $valor);
    }

    return (int)$row['id'];
}

function resolverMerchan($mysqli, $valor, $idDivision, $idSubdivision) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        throw new Exception('Merchan obligatorio.');
    }

    if (ctype_digit($valor)) {
        $id = (int)$valor;

        $stmt = $mysqli->prepare("
            SELECT id
            FROM usuario
            WHERE id = ?
            AND activo = 1
            AND id_perfil = 3
            AND (id_division IS NULL OR id_division = ?)
            AND (id_subdivision IS NULL OR id_subdivision = ? OR ? IS NULL)
            LIMIT 1
        ");

        $stmt->bind_param(
            "iiii",
            $id,
            $idDivision,
            $idSubdivision,
            $idSubdivision
        );
    } else {
        $stmt = $mysqli->prepare("
            SELECT id
            FROM usuario
            WHERE activo = 1
            AND id_perfil = 3
            AND (
                UPPER(usuario) = UPPER(?)
                OR UPPER(email) = UPPER(?)
                OR UPPER(CONCAT_WS(' ', nombre, apellido)) = UPPER(?)
                OR UPPER(CONCAT_WS(' ', nombre, apellido, '-', usuario)) = UPPER(?)
            )
            AND (id_division IS NULL OR id_division = ?)
            AND (id_subdivision IS NULL OR id_subdivision = ? OR ? IS NULL)
            LIMIT 1
        ");

        $stmt->bind_param(
            "ssssiii",
            $valor,
            $valor,
            $valor,
            $valor,
            $idDivision,
            $idSubdivision,
            $idSubdivision
        );
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        throw new Exception(
            'Merchan no encontrado, inactivo, no es perfil 3 o no pertenece a la división/subdivisión indicada: ' . $valor
        );
    }

    return (int)$row['id'];
}

/*
|--------------------------------------------------------------------------
| VEHÍCULOS
|--------------------------------------------------------------------------
*/

function buscarVehiculoActualPorPatente($mysqli, $patente) {
    $stmt = $mysqli->prepare("
        SELECT 
            v.id,
            v.patente,
            v.modelo,
            v.tipo_combustible,
            v.direccion_origen,
            v.lat_origen,
            v.lng_origen,
            v.id_empresa,
            v.id_division,
            v.id_subdivision,
            v.id_merchan,
            v.estado,

            h.id AS id_historial_activo,
            h.id_empresa AS h_id_empresa,
            h.id_division AS h_id_division,
            h.id_subdivision AS h_id_subdivision,
            h.id_merchan AS h_id_merchan,
            h.fecha_inicio AS h_fecha_inicio

        FROM vehiculo v

        LEFT JOIN vehiculo_asignacion_historial h
            ON h.id_vehiculo = v.id
            AND h.fecha_termino IS NULL

        WHERE v.patente = ?
        AND v.deleted_at IS NULL

        LIMIT 1
    ");

    $stmt->bind_param("s", $patente);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return null;
    }

    $row['id_empresa_actual'] = $row['h_id_empresa'] ?: $row['id_empresa'];
    $row['id_division_actual'] = $row['h_id_division'] ?: $row['id_division'];
    $row['id_subdivision_actual'] = $row['h_id_subdivision'] ?: $row['id_subdivision'];
    $row['id_merchan_actual'] = $row['h_id_merchan'] ?: $row['id_merchan'];
    $row['fecha_inicio_actual'] = $row['h_fecha_inicio'] ?: null;

    return $row;
}

function insertarVehiculo($mysqli, $data) {
    $stmt = $mysqli->prepare("
        INSERT INTO vehiculo (
            patente,
            modelo,
            tipo_combustible,
            direccion_origen,
            lat_origen,
            lng_origen,
            id_empresa,
            id_division,
            id_subdivision,
            id_merchan,
            estado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssssddiiiii",
        $data['patente'],
        $data['modelo'],
        $data['tipo_combustible'],
        $data['direccion_origen'],
        $data['lat_origen'],
        $data['lng_origen'],
        $data['id_empresa'],
        $data['id_division'],
        $data['id_subdivision'],
        $data['id_merchan'],
        $data['estado']
    );

    $stmt->execute();

    return (int)$mysqli->insert_id;
}

function actualizarVehiculo($mysqli, $idVehiculo, $data) {
    $stmt = $mysqli->prepare("
        UPDATE vehiculo
        SET 
            modelo = ?,
            tipo_combustible = ?,
            direccion_origen = ?,
            lat_origen = ?,
            lng_origen = ?,
            id_empresa = ?,
            id_division = ?,
            id_subdivision = ?,
            id_merchan = ?,
            estado = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "sssddiiiiii",
        $data['modelo'],
        $data['tipo_combustible'],
        $data['direccion_origen'],
        $data['lat_origen'],
        $data['lng_origen'],
        $data['id_empresa'],
        $data['id_division'],
        $data['id_subdivision'],
        $data['id_merchan'],
        $data['estado'],
        $idVehiculo
    );

    $stmt->execute();
}

/*
|--------------------------------------------------------------------------
| HISTORIAL
|--------------------------------------------------------------------------
*/

function gestionarHistorial($mysqli, $idVehiculo, $data) {
    $stmt = $mysqli->prepare("
        SELECT 
            id,
            id_empresa,
            id_division,
            id_subdivision,
            id_merchan,
            fecha_inicio
        FROM vehiculo_asignacion_historial
        WHERE id_vehiculo = ?
        AND fecha_termino IS NULL
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param("i", $idVehiculo);
    $stmt->execute();

    $actual = $stmt->get_result()->fetch_assoc();

    /*
    |--------------------------------------------------------------------------
    | VEHÍCULO INACTIVO
    |--------------------------------------------------------------------------
    | Si queda inactivo, no crea nueva asignación.
    | Solo cierra la asignación vigente, si existe.
    |--------------------------------------------------------------------------
    */

    if ((int)$data['estado'] === 0) {
        if (!$actual) {
            return 'sin_cambio';
        }

        $fechaTerminoAnterior = date('Y-m-d', strtotime($data['fecha_inicio'] . ' -1 day'));

        if ($fechaTerminoAnterior < $actual['fecha_inicio']) {
            $fechaTerminoAnterior = $actual['fecha_inicio'];
        }

        $observacionCierre = trim((string)$data['observacion']) !== ''
            ? $data['observacion']
            : 'Vehículo marcado como inactivo desde carga masiva.';

        $stmt = $mysqli->prepare("
            UPDATE vehiculo_asignacion_historial
            SET 
                fecha_termino = ?,
                observacion = CASE
                    WHEN observacion IS NULL OR observacion = '' THEN ?
                    ELSE observacion
                END
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ssi",
            $fechaTerminoAnterior,
            $observacionCierre,
            $actual['id']
        );

        $stmt->execute();

        return 'historial_cerrado';
    }

    /*
    |--------------------------------------------------------------------------
    | VEHÍCULO ACTIVO
    |--------------------------------------------------------------------------
    */

    $debeCrear = false;

    if (!$actual) {
        $debeCrear = true;
    } else {
        $cambioEmpresa = (int)$actual['id_empresa'] !== (int)$data['id_empresa'];
        $cambioDivision = (int)$actual['id_division'] !== (int)$data['id_division'];
        $cambioSubdivision = (int)($actual['id_subdivision'] ?? 0) !== (int)($data['id_subdivision'] ?? 0);
        $cambioMerchan = (int)($actual['id_merchan'] ?? 0) !== (int)($data['id_merchan'] ?? 0);

        if ($cambioEmpresa || $cambioDivision || $cambioSubdivision || $cambioMerchan) {
            $debeCrear = true;
        }
    }

    if (!$debeCrear) {
        $stmt = $mysqli->prepare("
            UPDATE vehiculo_asignacion_historial
            SET 
                fecha_inicio = ?,
                observacion = CASE 
                    WHEN ? <> '' THEN ?
                    ELSE observacion
                END
            WHERE id = ?
        ");

        $stmt->bind_param(
            "sssi",
            $data['fecha_inicio'],
            $data['observacion'],
            $data['observacion'],
            $actual['id']
        );

        $stmt->execute();

        return 'historial_actualizado';
    }

    if ($actual) {
        $fechaTerminoAnterior = date('Y-m-d', strtotime($data['fecha_inicio'] . ' -1 day'));

        if ($fechaTerminoAnterior < $actual['fecha_inicio']) {
            $fechaTerminoAnterior = $actual['fecha_inicio'];
        }

        $stmt = $mysqli->prepare("
            UPDATE vehiculo_asignacion_historial
            SET fecha_termino = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "si",
            $fechaTerminoAnterior,
            $actual['id']
        );

        $stmt->execute();
    }

    $stmt = $mysqli->prepare("
        INSERT INTO vehiculo_asignacion_historial (
            id_vehiculo,
            id_empresa,
            id_division,
            id_subdivision,
            id_merchan,
            fecha_inicio,
            observacion
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iiiiiss",
        $idVehiculo,
        $data['id_empresa'],
        $data['id_division'],
        $data['id_subdivision'],
        $data['id_merchan'],
        $data['fecha_inicio'],
        $data['observacion']
    );

    $stmt->execute();

    return 'historial_creado';
}

/*
|--------------------------------------------------------------------------
| VALIDAR ARCHIVO
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
    responder([
        'ok' => false,
        'msg' => 'Debe seleccionar un archivo CSV válido.'
    ]);
}

$tmp = $_FILES['archivo_csv']['tmp_name'];
$contenido = file_get_contents($tmp);

if ($contenido === false || trim($contenido) === '') {
    responder([
        'ok' => false,
        'msg' => 'El archivo está vacío o no se pudo leer.'
    ]);
}

$contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
$lineas = preg_split('/\r\n|\r|\n/', trim($contenido));

if (count($lineas) < 2) {
    responder([
        'ok' => false,
        'msg' => 'El archivo debe contener encabezado y al menos una fila de datos.'
    ]);
}

$delimitador = detectarDelimitador($lineas[0]);
$headersOriginales = str_getcsv($lineas[0], $delimitador);
$headers = [];

foreach ($headersOriginales as $header) {
    $headers[] = normalizarTexto($header);
}

$resumen = [
    'total' => 0,
    'insertados' => 0,
    'actualizados' => 0,
    'historial_creado' => 0,
    'sin_cambio' => 0,
    'errores' => 0
];

$resultadoFilas = [];

/*
|--------------------------------------------------------------------------
| PROCESAR FILAS
|--------------------------------------------------------------------------
*/

for ($i = 1; $i < count($lineas); $i++) {
    $linea = trim($lineas[$i]);

    if ($linea === '') {
        continue;
    }

    $resumen['total']++;

    $numeroFila = $i + 1;
    $valores = str_getcsv($linea, $delimitador);
    $row = [];

    foreach ($headers as $index => $header) {
        $row[$header] = $valores[$index] ?? '';
    }

    $patente = limpiarPatente(valorFila($row, ['patente', 'placa']));

    try {
        $mysqli->begin_transaction();

        if ($patente === '') {
            throw new Exception('Patente obligatoria.');
        }

        $vehiculoActual = buscarVehiculoActualPorPatente($mysqli, $patente);
        $existeVehiculo = $vehiculoActual !== null;

        /*
        |--------------------------------------------------------------------------
        | VALORES CSV
        |--------------------------------------------------------------------------
        */

        $modeloCsv = strtoupper(valorFila($row, ['modelo', 'modelo_vehiculo']));
        $tipoCombustibleCsv = strtoupper(valorFila($row, ['tipo_combustible', 'combustible', 'octanaje']));
        $direccionCsv = strtoupper(valorFila($row, ['direccion_origen', 'origen', 'punto_partida', 'direccion']));

        $fechaCsv = valorFila($row, ['fecha_inicio', 'fecha_asignacion']);
        $estadoCsv = valorFila($row, ['estado']);
        $observacion = valorFila($row, ['observacion', 'comentario'], 'Carga masiva');

        $empresaValor = valorFila($row, ['empresa', 'id_empresa']);
        $divisionValor = valorFila($row, ['division', 'id_division']);
        $subdivisionValor = valorFila($row, ['subdivision', 'id_subdivision']);
        $merchanValor = valorFila($row, ['merchan', 'usuario_merchan', 'id_merchan', 'usuario']);

        /*
        |--------------------------------------------------------------------------
        | MANTENER DATOS EXISTENTES SI CSV VIENE VACÍO
        |--------------------------------------------------------------------------
        */

        $modelo = $modeloCsv !== ''
            ? $modeloCsv
            : ($existeVehiculo ? strtoupper((string)$vehiculoActual['modelo']) : '');

        $tipoCombustible = $tipoCombustibleCsv !== ''
            ? $tipoCombustibleCsv
            : ($existeVehiculo ? strtoupper((string)$vehiculoActual['tipo_combustible']) : null);

        if ($tipoCombustible !== '' && $tipoCombustible !== null && !in_array($tipoCombustible, ['93', '95', '97', 'DIESEL'], true)) {
            throw new Exception('Tipo de combustible inválido. Use 93, 95, 97 o DIESEL.');
        }

        if ($tipoCombustible === '') {
            $tipoCombustible = null;
        }

        $direccionOrigen = $direccionCsv !== ''
            ? $direccionCsv
            : ($existeVehiculo ? strtoupper((string)$vehiculoActual['direccion_origen']) : '');

        $fechaInicio = $fechaCsv !== ''
            ? validarFecha($fechaCsv)
            : (
                $existeVehiculo && !empty($vehiculoActual['fecha_inicio_actual'])
                    ? $vehiculoActual['fecha_inicio_actual']
                    : date('Y-m-d')
            );

        $estadoDefault = $existeVehiculo ? (int)$vehiculoActual['estado'] : 1;
        $estado = normalizarEstadoCarga($estadoCsv, $estadoDefault);

        /*
        |--------------------------------------------------------------------------
        | EMPRESA / DIVISIÓN / SUBDIVISIÓN
        |--------------------------------------------------------------------------
        */

        $idEmpresaActual = $existeVehiculo ? ($vehiculoActual['id_empresa_actual'] ?? null) : null;
        $idDivisionActual = $existeVehiculo ? ($vehiculoActual['id_division_actual'] ?? null) : null;
        $idSubdivisionActual = $existeVehiculo ? ($vehiculoActual['id_subdivision_actual'] ?? null) : null;
        $idMerchanActual = $existeVehiculo ? ($vehiculoActual['id_merchan_actual'] ?? null) : null;

        $idEmpresa = resolverEmpresa(
            $mysqli,
            $empresaValor,
            $idEmpresaActual,
            $existeVehiculo
        );

        $idDivision = resolverDivision(
            $mysqli,
            $divisionValor,
            $idEmpresa,
            $idDivisionActual,
            $existeVehiculo
        );

        $idSubdivision = resolverSubdivision(
            $mysqli,
            $subdivisionValor,
            $idDivision,
            $idSubdivisionActual,
            $existeVehiculo
        );

        /*
        |--------------------------------------------------------------------------
        | MERCHAN
        |--------------------------------------------------------------------------
        | Si viene inactivo, se limpia.
        | Si viene activo:
        |   - Si CSV trae merchan, lo resuelve.
        |   - Si CSV viene vacío y existe vehículo, mantiene merchan actual.
        |   - Si CSV viene vacío y es vehículo nuevo, error.
        |--------------------------------------------------------------------------
        */

        if ($estado === 0) {
            $idMerchan = null;
        } else {
            if ($merchanValor !== '') {
                $idMerchan = resolverMerchan($mysqli, $merchanValor, $idDivision, $idSubdivision);
            } elseif ($existeVehiculo && $idMerchanActual) {
                $idMerchan = (int)$idMerchanActual;
            } else {
                throw new Exception('Merchan obligatorio para vehículos activos.');
            }
        }

        /*
        |--------------------------------------------------------------------------
        | LATITUD / LONGITUD
        |--------------------------------------------------------------------------
        | Solo geocodifica si viene una dirección con datos en el CSV
        | y es nueva o distinta a la actual, o si no existen coordenadas.
        |--------------------------------------------------------------------------
        */

        $latOrigen = $existeVehiculo ? $vehiculoActual['lat_origen'] : null;
        $lngOrigen = $existeVehiculo ? $vehiculoActual['lng_origen'] : null;

        $debeGeocodificar = false;

        if ($direccionCsv !== '') {
            if (!$existeVehiculo) {
                $debeGeocodificar = true;
            } else {
                $direccionActual = $vehiculoActual['direccion_origen'] ?? '';

                if (textosSonDistintos($direccionCsv, $direccionActual)) {
                    $debeGeocodificar = true;
                }

                if ($latOrigen === null || $latOrigen === '' || $lngOrigen === null || $lngOrigen === '') {
                    $debeGeocodificar = true;
                }
            }
        }

        if ($debeGeocodificar) {
            $geo = geocodificarDireccionGoogle($direccionOrigen, $GLOBALS['GOOGLE_GEOCODING_API_KEY']);

            if (!$geo['ok']) {
                throw new Exception($geo['msg']);
            }

            $latOrigen = $geo['lat'];
            $lngOrigen = $geo['lng'];
        }

        /*
        |--------------------------------------------------------------------------
        | DATA FINAL
        |--------------------------------------------------------------------------
        */

        $data = [
            'patente' => $patente,
            'modelo' => $modelo,
            'tipo_combustible' => $tipoCombustible,
            'direccion_origen' => $direccionOrigen,
            'lat_origen' => $latOrigen,
            'lng_origen' => $lngOrigen,
            'id_empresa' => $idEmpresa,
            'id_division' => $idDivision,
            'id_subdivision' => $idSubdivision,
            'id_merchan' => $idMerchan,
            'fecha_inicio' => $fechaInicio,
            'estado' => $estado,
            'observacion' => $observacion
        ];

        /*
        |--------------------------------------------------------------------------
        | INSERT / UPDATE
        |--------------------------------------------------------------------------
        */

        if ($existeVehiculo) {
            $idVehiculo = (int)$vehiculoActual['id'];
            actualizarVehiculo($mysqli, $idVehiculo, $data);

            $accionVehiculo = 'Actualizado';
            $resumen['actualizados']++;
        } else {
            $idVehiculo = insertarVehiculo($mysqli, $data);

            $accionVehiculo = 'Insertado';
            $resumen['insertados']++;
        }

        /*
        |--------------------------------------------------------------------------
        | HISTORIAL
        |--------------------------------------------------------------------------
        */

        $resultadoHistorial = gestionarHistorial($mysqli, $idVehiculo, $data);

        if ($resultadoHistorial === 'historial_creado') {
            $resumen['historial_creado']++;
            $mensaje = $accionVehiculo . ' con historial creado.';
        } elseif ($resultadoHistorial === 'historial_cerrado') {
            $resumen['sin_cambio']++;
            $mensaje = $accionVehiculo . '. Vehículo inactivo, historial vigente cerrado.';
        } elseif ($resultadoHistorial === 'historial_actualizado') {
            $resumen['sin_cambio']++;
            $mensaje = $accionVehiculo . '. Asignación vigente actualizada.';
        } else {
            $resumen['sin_cambio']++;
            $mensaje = $accionVehiculo . '. Asignación sin cambios.';
        }

        if ($debeGeocodificar) {
            $mensaje .= ' Dirección geocodificada.';
        }

        $mysqli->commit();

        $resultadoFilas[] = [
            'fila' => $numeroFila,
            'patente' => $patente,
            'accion' => $accionVehiculo,
            'estado' => 'ok',
            'mensaje' => $mensaje
        ];

    } catch (Throwable $e) {
        $mysqli->rollback();

        $resumen['errores']++;

        $resultadoFilas[] = [
            'fila' => $numeroFila,
            'patente' => $patente,
            'accion' => 'No procesado',
            'estado' => 'error',
            'mensaje' => $e->getMessage()
        ];
    }
}

responder([
    'ok' => true,
    'resumen' => $resumen,
    'filas' => $resultadoFilas
]);
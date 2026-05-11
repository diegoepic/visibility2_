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

function resolverEmpresa($mysqli, $valor) {
    $valor = trim((string)$valor);

    if ($valor === '') {
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

function resolverDivision($mysqli, $valor, $idEmpresa) {
    $valor = trim((string)$valor);

    if ($valor === '') {
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

function resolverSubdivision($mysqli, $valor, $idDivision) {
    $valor = trim((string)$valor);

    if ($valor === '') {
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
        $stmt->bind_param("iiii", $id, $idDivision, $idSubdivision, $idSubdivision);
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
        throw new Exception('Merchan no encontrado, inactivo, no es perfil 3 o no pertenece a la división/subdivisión indicada: ' . $valor);
    }

    return (int)$row['id'];
}

function buscarVehiculoPorPatente($mysqli, $patente) {
    $stmt = $mysqli->prepare("
        SELECT id
        FROM vehiculo
        WHERE patente = ?
        AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param("s", $patente);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return $row ? (int)$row['id'] : 0;
}

function insertarVehiculo($mysqli, $data) {
    $latOrigen = null;
    $lngOrigen = null;

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
        $latOrigen,
        $lngOrigen,
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
    $latOrigen = null;
    $lngOrigen = null;

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
        $latOrigen,
        $lngOrigen,
        $data['id_empresa'],
        $data['id_division'],
        $data['id_subdivision'],
        $data['id_merchan'],
        $data['estado'],
        $idVehiculo
    );

    $stmt->execute();
}

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

    $debeCrear = false;

    if (!$actual) {
        $debeCrear = true;
    } else {
        $cambioEmpresa = (int)$actual['id_empresa'] !== (int)$data['id_empresa'];
        $cambioDivision = (int)$actual['id_division'] !== (int)$data['id_division'];
        $cambioSubdivision = (int)($actual['id_subdivision'] ?? 0) !== (int)($data['id_subdivision'] ?? 0);
        $cambioMerchan = (int)$actual['id_merchan'] !== (int)$data['id_merchan'];

        if ($cambioEmpresa || $cambioDivision || $cambioSubdivision || $cambioMerchan) {
            $debeCrear = true;
        }
    }

    if (!$debeCrear) {
        return 'sin_cambio';
    }

    if ($actual) {
        if ($data['fecha_inicio'] <= $actual['fecha_inicio']) {
            throw new Exception(
                'La fecha de nueva asignación debe ser posterior a la asignación activa actual: ' .
                $actual['fecha_inicio']
            );
        }

        $fechaTerminoAnterior = date('Y-m-d', strtotime($data['fecha_inicio'] . ' -1 day'));

        $stmt = $mysqli->prepare("
            UPDATE vehiculo_asignacion_historial
            SET fecha_termino = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $fechaTerminoAnterior, $actual['id']);
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

        $modelo = strtoupper(valorFila($row, ['modelo', 'modelo_vehiculo']));
        $tipoCombustible = strtoupper(valorFila($row, ['tipo_combustible', 'combustible', 'octanaje']));

        if ($tipoCombustible !== '' && !in_array($tipoCombustible, ['93', '95', '97', 'DIESEL'], true)) {
            throw new Exception('Tipo de combustible inválido. Use 93, 95, 97 o DIESEL.');
        }

        if ($tipoCombustible === '') {
            $tipoCombustible = null;
        }

        $direccionOrigen = strtoupper(valorFila($row, ['direccion_origen', 'origen', 'punto_partida', 'direccion']));
        $fechaInicio = validarFecha(valorFila($row, ['fecha_inicio', 'fecha_asignacion'], date('Y-m-d')));
        $estado = (int)valorFila($row, ['estado'], '1');
        $estado = $estado === 0 ? 0 : 1;
        $observacion = valorFila($row, ['observacion', 'comentario'], 'Carga masiva');

        $empresaValor = valorFila($row, ['empresa', 'id_empresa']);
        $divisionValor = valorFila($row, ['division', 'id_division']);
        $subdivisionValor = valorFila($row, ['subdivision', 'id_subdivision']);
        $merchanValor = valorFila($row, ['merchan', 'usuario_merchan', 'id_merchan', 'usuario']);

        $idEmpresa = resolverEmpresa($mysqli, $empresaValor);
        $idDivision = resolverDivision($mysqli, $divisionValor, $idEmpresa);
        $idSubdivision = resolverSubdivision($mysqli, $subdivisionValor, $idDivision);
        $idMerchan = resolverMerchan($mysqli, $merchanValor, $idDivision, $idSubdivision);

        $data = [
            'patente' => $patente,
            'modelo' => $modelo,
            'tipo_combustible' => $tipoCombustible,
            'direccion_origen' => $direccionOrigen,
            'id_empresa' => $idEmpresa,
            'id_division' => $idDivision,
            'id_subdivision' => $idSubdivision,
            'id_merchan' => $idMerchan,
            'fecha_inicio' => $fechaInicio,
            'estado' => $estado,
            'observacion' => $observacion
        ];

        $idVehiculo = buscarVehiculoPorPatente($mysqli, $patente);

        if ($idVehiculo > 0) {
            actualizarVehiculo($mysqli, $idVehiculo, $data);
            $accionVehiculo = 'Actualizado';
            $resumen['actualizados']++;
        } else {
            $idVehiculo = insertarVehiculo($mysqli, $data);
            $accionVehiculo = 'Insertado';
            $resumen['insertados']++;
        }

        $resultadoHistorial = gestionarHistorial($mysqli, $idVehiculo, $data);

        if ($resultadoHistorial === 'historial_creado') {
            $resumen['historial_creado']++;
            $mensaje = $accionVehiculo . ' con historial creado.';
        } else {
            $resumen['sin_cambio']++;
            $mensaje = $accionVehiculo . '. Asignación sin cambios.';
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
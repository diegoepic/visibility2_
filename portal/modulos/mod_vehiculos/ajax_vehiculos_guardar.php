<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: application/json; charset=utf-8');

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode([
        'ok' => false,
        'msg' => 'No existe conexión a base de datos.'
    ]);
    exit;
}

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : 0;

$patente = strtoupper(trim($_POST['patente'] ?? ''));
$modelo = trim($_POST['modelo'] ?? '');

$tipo_combustible = trim($_POST['tipo_combustible'] ?? '');
$direccion_origen = trim($_POST['direccion_origen'] ?? '');

$lat_origen = $_POST['lat_origen'] !== '' ? (float)$_POST['lat_origen'] : null;
$lng_origen = $_POST['lng_origen'] !== '' ? (float)$_POST['lng_origen'] : null;

if ($tipo_combustible === '') {
    $tipo_combustible = null;
}

if ($direccion_origen === '') {
    $direccion_origen = null;
}

$id_empresa = !empty($_POST['id_empresa']) ? (int)$_POST['id_empresa'] : null;
$id_division = !empty($_POST['id_division']) ? (int)$_POST['id_division'] : null;
$id_subdivision = !empty($_POST['id_subdivision']) ? (int)$_POST['id_subdivision'] : null;
$id_merchan = !empty($_POST['id_merchan']) ? (int)$_POST['id_merchan'] : null;

$fecha_inicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
$estado = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;
$observacion = trim($_POST['observacion'] ?? '');

if ($patente === '') {
    echo json_encode([
        'ok' => false,
        'msg' => 'La patente es obligatoria.'
    ]);
    exit;
}

if (!$id_empresa || !$id_division || !$id_merchan) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Empresa, división y merchan son obligatorios.'
    ]);
    exit;
}

try {
    $mysqli->begin_transaction();

    // Validar patente duplicada
    if ($id > 0) {
        $stmt = $mysqli->prepare("
            SELECT id 
            FROM vehiculo 
            WHERE patente = ? 
            AND id <> ? 
            AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->bind_param("si", $patente, $id);
    } else {
        $stmt = $mysqli->prepare("
            SELECT id 
            FROM vehiculo 
            WHERE patente = ? 
            AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->bind_param("s", $patente);
    }

    $stmt->execute();
    $duplicado = $stmt->get_result()->fetch_assoc();

    if ($duplicado) {
        throw new Exception('Ya existe un vehículo registrado con esa patente.');
    }

    // Crear o actualizar vehículo base
    if ($id > 0) {
        $stmt = $mysqli->prepare("
            UPDATE vehiculo
            SET 
                patente = ?,
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
                "ssssddiiiiii",
                $patente,
                $modelo,
                $tipo_combustible,
                $direccion_origen,
                $lat_origen,
                $lng_origen,
                $id_empresa,
                $id_division,
                $id_subdivision,
                $id_merchan,
                $estado,
                $id
            );

        $stmt->execute();

    } else {
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
            $patente,
            $modelo,
            $tipo_combustible,
            $direccion_origen,
            $lat_origen,
            $lng_origen,
            $id_empresa,
            $id_division,
            $id_subdivision,
            $id_merchan,
            $estado
        );

        $stmt->execute();
        $id = $mysqli->insert_id;
    }

    // Buscar asignación activa actual
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

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $asignacionActual = $stmt->get_result()->fetch_assoc();

    $debeCrearHistorial = false;

    if (!$asignacionActual) {
        $debeCrearHistorial = true;
    } else {
        $cambioEmpresa = (int)$asignacionActual['id_empresa'] !== (int)$id_empresa;
        $cambioDivision = (int)$asignacionActual['id_division'] !== (int)$id_division;
        $cambioSubdivision = (int)$asignacionActual['id_subdivision'] !== (int)$id_subdivision;
        $cambioMerchan = (int)$asignacionActual['id_merchan'] !== (int)$id_merchan;

        if ($cambioEmpresa || $cambioDivision || $cambioSubdivision || $cambioMerchan) {
            $debeCrearHistorial = true;
        }
    }

    if ($debeCrearHistorial) {

        if ($asignacionActual) {
            if ($fecha_inicio <= $asignacionActual['fecha_inicio']) {
                throw new Exception(
                    'La fecha de inicio de la nueva asignación debe ser posterior a la fecha de inicio actual: ' .
                    $asignacionActual['fecha_inicio']
                );
            }

            $fechaTerminoAnterior = date('Y-m-d', strtotime($fecha_inicio . ' -1 day'));

            $stmt = $mysqli->prepare("
                UPDATE vehiculo_asignacion_historial
                SET fecha_termino = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "si",
                $fechaTerminoAnterior,
                $asignacionActual['id']
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
            $id,
            $id_empresa,
            $id_division,
            $id_subdivision,
            $id_merchan,
            $fecha_inicio,
            $observacion
        );

        $stmt->execute();
    }

    $mysqli->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'Vehículo guardado correctamente.'
    ]);

} catch (Throwable $e) {
    $mysqli->rollback();

    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}
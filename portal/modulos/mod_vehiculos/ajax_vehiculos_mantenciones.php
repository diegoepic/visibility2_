<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión.']);
    exit;
}

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$idVehiculo = (int)($_GET['id_vehiculo'] ?? $_POST['id_vehiculo'] ?? 0);

// ─────────────────────────────────────────
// LIST
// ─────────────────────────────────────────
if ($action === 'list') {
    if ($idVehiculo <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'id_vehiculo requerido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT id, tipo, fecha, km_en_mantencion, proveedor, costo, descripcion
        FROM vehiculo_mantencion
        WHERE id_vehiculo = ? AND deleted_at IS NULL
        ORDER BY fecha DESC
    ");
    $stmt->bind_param('i', $idVehiculo);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

// ─────────────────────────────────────────
// SAVE (INSERT or UPDATE)
// ─────────────────────────────────────────
if ($action === 'save') {
    $id          = (int)($_POST['id'] ?? 0);
    $tipo        = trim($_POST['tipo'] ?? '');
    $fecha       = trim($_POST['fecha'] ?? '');
    $proveedor   = trim($_POST['proveedor'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    $kmRaw    = $_POST['km_en_mantencion'] ?? '';
    $costoRaw = $_POST['costo'] ?? '';
    $km    = ($kmRaw !== '')    ? (int)$kmRaw       : null;
    $costo = ($costoRaw !== '') ? (float)$costoRaw  : null;

    $tiposValidos = ['preventiva', 'correctiva', 'revision', 'neumaticos', 'frenos', 'aceite', 'otro'];
    if (!in_array($tipo, $tiposValidos, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Tipo inválido.']);
        exit;
    }
    if (!$fecha) {
        echo json_encode(['ok' => false, 'msg' => 'Fecha requerida.']);
        exit;
    }
    if ($idVehiculo <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Vehículo inválido.']);
        exit;
    }

    // Usar 's' para campos nullable así MySQL castea correctamente
    $kmStr    = is_null($km)    ? null : (string)$km;
    $costoStr = is_null($costo) ? null : (string)$costo;

    if ($id > 0) {
        $stmt = $mysqli->prepare("
            UPDATE vehiculo_mantencion
            SET tipo=?, fecha=?, km_en_mantencion=?, proveedor=?, costo=?, descripcion=?, updated_at=NOW()
            WHERE id=? AND id_vehiculo=? AND deleted_at IS NULL
        ");
        $stmt->bind_param('ssssssii', $tipo, $fecha, $kmStr, $proveedor, $costoStr, $descripcion, $id, $idVehiculo);
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO vehiculo_mantencion
                (id_vehiculo, tipo, fecha, km_en_mantencion, proveedor, costo, descripcion)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssss', $idVehiculo, $tipo, $fecha, $kmStr, $proveedor, $costoStr, $descripcion);
    }

    if (!$stmt->execute()) {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $mysqli->error]);
        exit;
    }
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Mantención guardada correctamente.']);
    exit;
}

// ─────────────────────────────────────────
// DELETE (soft)
// ─────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE vehiculo_mantencion SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Registro eliminado.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión.']);
    exit;
}

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$idVehiculo = (int)($_GET['id_vehiculo'] ?? $_POST['id_vehiculo'] ?? 0);

// ─────────────────────────────────────────
// LIST
// ─────────────────────────────────────────
if ($action === 'list') {
    if ($idVehiculo <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'id_vehiculo requerido.']);
        exit;
    }

    $stmt = $mysqli->prepare("
        SELECT id, tipo, fecha, km_en_mantencion, proveedor, costo, descripcion
        FROM vehiculo_mantencion
        WHERE id_vehiculo = ? AND deleted_at IS NULL
        ORDER BY fecha DESC
    ");
    $stmt->bind_param('i', $idVehiculo);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

// ─────────────────────────────────────────
// SAVE (INSERT or UPDATE)
// ─────────────────────────────────────────
if ($action === 'save') {
    $id          = (int)($_POST['id'] ?? 0);
    $tipo        = trim($_POST['tipo'] ?? '');
    $fecha       = trim($_POST['fecha'] ?? '');
    $proveedor   = trim($_POST['proveedor'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    $kmRaw    = $_POST['km_en_mantencion'] ?? '';
    $costoRaw = $_POST['costo'] ?? '';
    $km    = ($kmRaw !== '')    ? (int)$kmRaw       : null;
    $costo = ($costoRaw !== '') ? (float)$costoRaw  : null;

    $tiposValidos = ['preventiva', 'correctiva', 'revision', 'neumaticos', 'frenos', 'aceite', 'otro'];
    if (!in_array($tipo, $tiposValidos, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Tipo inválido.']);
        exit;
    }
    if (!$fecha) {
        echo json_encode(['ok' => false, 'msg' => 'Fecha requerida.']);
        exit;
    }
    if ($idVehiculo <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Vehículo inválido.']);
        exit;
    }

    // Usar 's' para campos nullable así MySQL castea correctamente
    $kmStr    = is_null($km)    ? null : (string)$km;
    $costoStr = is_null($costo) ? null : (string)$costo;

    if ($id > 0) {
        $stmt = $mysqli->prepare("
            UPDATE vehiculo_mantencion
            SET tipo=?, fecha=?, km_en_mantencion=?, proveedor=?, costo=?, descripcion=?, updated_at=NOW()
            WHERE id=? AND id_vehiculo=? AND deleted_at IS NULL
        ");
        $stmt->bind_param('ssssssii', $tipo, $fecha, $kmStr, $proveedor, $costoStr, $descripcion, $id, $idVehiculo);
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO vehiculo_mantencion
                (id_vehiculo, tipo, fecha, km_en_mantencion, proveedor, costo, descripcion)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssss', $idVehiculo, $tipo, $fecha, $kmStr, $proveedor, $costoStr, $descripcion);
    }

    if (!$stmt->execute()) {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $mysqli->error]);
        exit;
    }
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Mantención guardada correctamente.']);
    exit;
}

// ─────────────────────────────────────────
// DELETE (soft)
// ─────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE vehiculo_mantencion SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Registro eliminado.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);

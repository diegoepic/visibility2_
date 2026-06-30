<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión a la base de datos.']);
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
        SELECT id, tipo, numero_documento, fecha_emision, fecha_vencimiento,
               archivo, observacion,
               DATEDIFF(fecha_vencimiento, CURDATE()) AS dias_restantes
        FROM vehiculo_documento
        WHERE id_vehiculo = ? AND deleted_at IS NULL
        ORDER BY fecha_vencimiento ASC
    ");
    $stmt->bind_param('i', $idVehiculo);
    $stmt->execute();
    $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['ok' => true, 'data' => $docs]);
    exit;
}

// ─────────────────────────────────────────
// SAVE (INSERT or UPDATE)
// ─────────────────────────────────────────
if ($action === 'save') {
    $id              = (int)($_POST['id'] ?? 0);
    $tipo            = trim($_POST['tipo'] ?? '');
    $numeroDoc       = trim($_POST['numero_documento'] ?? '');
    $fechaEmision    = trim($_POST['fecha_emision'] ?? '');
    $fechaVencimiento = trim($_POST['fecha_vencimiento'] ?? '');
    $observacion     = trim($_POST['observacion'] ?? '');

    $tiposValidos = ['soap', 'revision_tecnica', 'permiso_circulacion', 'seguro', 'otro'];
    if (!in_array($tipo, $tiposValidos, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Tipo de documento inválido.']);
        exit;
    }
    if (!$fechaVencimiento) {
        echo json_encode(['ok' => false, 'msg' => 'Fecha de vencimiento requerida.']);
        exit;
    }
    if ($idVehiculo <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Vehículo inválido.']);
        exit;
    }

    // Verificar vehículo
    $chk = $mysqli->prepare("SELECT id FROM vehiculo WHERE id = ? AND deleted_at IS NULL");
    $chk->bind_param('i', $idVehiculo);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) {
        $chk->close();
        echo json_encode(['ok' => false, 'msg' => 'Vehículo no encontrado.']);
        exit;
    }
    $chk->close();

    // Manejar archivo adjunto
    $archivoNombre = null;
    if (!empty($_FILES['archivo']['name'])) {
        $ext           = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
        $extPermitidas = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $extPermitidas, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Extensión no permitida. Use PDF, JPG o PNG.']);
            exit;
        }
        if ($_FILES['archivo']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'msg' => 'El archivo supera los 5 MB.']);
            exit;
        }

        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/vehiculos_docs/' . $idVehiculo . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $archivoNombre = uniqid('doc_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $uploadDir . $archivoNombre)) {
            echo json_encode(['ok' => false, 'msg' => 'No se pudo guardar el archivo.']);
            exit;
        }
    }

    $fechaEmisionVal = $fechaEmision !== '' ? $fechaEmision : null;

    if ($id > 0) {
        // Eliminar archivo anterior si se sube uno nuevo
        if ($archivoNombre) {
            $prev = $mysqli->prepare("SELECT archivo FROM vehiculo_documento WHERE id = ? AND id_vehiculo = ?");
            $prev->bind_param('ii', $id, $idVehiculo);
            $prev->execute();
            $prevRow = $prev->get_result()->fetch_assoc();
            $prev->close();
            if ($prevRow && $prevRow['archivo']) {
                $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/vehiculos_docs/' . $idVehiculo . '/' . $prevRow['archivo'];
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            $stmt = $mysqli->prepare("
                UPDATE vehiculo_documento
                SET tipo=?, numero_documento=?, fecha_emision=?, fecha_vencimiento=?,
                    archivo=?, observacion=?, updated_at=NOW()
                WHERE id=? AND id_vehiculo=? AND deleted_at IS NULL
            ");
            $stmt->bind_param('ssssssii', $tipo, $numeroDoc, $fechaEmisionVal, $fechaVencimiento,
                              $archivoNombre, $observacion, $id, $idVehiculo);
        } else {
            $stmt = $mysqli->prepare("
                UPDATE vehiculo_documento
                SET tipo=?, numero_documento=?, fecha_emision=?, fecha_vencimiento=?,
                    observacion=?, updated_at=NOW()
                WHERE id=? AND id_vehiculo=? AND deleted_at IS NULL
            ");
            $stmt->bind_param('sssssii', $tipo, $numeroDoc, $fechaEmisionVal, $fechaVencimiento,
                              $observacion, $id, $idVehiculo);
        }
    } else {
        $stmt = $mysqli->prepare("
            INSERT INTO vehiculo_documento
                (id_vehiculo, tipo, numero_documento, fecha_emision, fecha_vencimiento, archivo, observacion)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssss', $idVehiculo, $tipo, $numeroDoc, $fechaEmisionVal,
                          $fechaVencimiento, $archivoNombre, $observacion);
    }

    if (!$stmt->execute()) {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . $mysqli->error]);
        exit;
    }
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Documento guardado correctamente.']);
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

    $stmt = $mysqli->prepare("UPDATE vehiculo_documento SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Documento eliminado.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);

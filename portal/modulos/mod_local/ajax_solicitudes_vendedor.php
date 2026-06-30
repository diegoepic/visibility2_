<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Solo editores pueden gestionar solicitudes
$perfilNombre = strtolower($_SESSION['perfil_nombre'] ?? '');
$esEditor = $perfilNombre === 'editor';

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── list (accesible para editores y coordinadores para ver) ─────
if ($action === 'list') {
    $validEstados = ['pendiente', 'aprobada', 'rechazada'];
    $estadoFilter = in_array($_GET['estado'] ?? '', $validEstados, true) ? $_GET['estado'] : 'pendiente';
    $soloSospechosos = isset($_GET['sospechoso']) && $_GET['sospechoso'] === '1';

    $extraWhere = $soloSospechosos ? ' AND s.sospechoso = 1' : '';

    $sql = "
        SELECT
            s.id,
            s.id_local,
            s.id_usuario,
            s.id_vendedor_anterior,
            s.nombre_vendedor_anterior,
            s.id_vendedor_nuevo,
            s.nombre_vendedor_nuevo,
            s.telefono_nuevo,
            s.es_nuevo_vendedor,
            s.sospechoso,
            s.motivo_sospecha,
            s.estado,
            s.reviewed_at,
            s.observacion_revisor,
            s.created_at,
            l.nombre    AS local_nombre,
            l.codigo    AS local_codigo,
            u.nombre    AS usuario_nombre,
            u.apellido  AS usuario_apellido
        FROM solicitud_cambio_vendedor s
        JOIN local   l ON l.id = s.id_local
        JOIN usuario u ON u.id = s.id_usuario
        WHERE s.estado = ? AND s.deleted_at IS NULL
        {$extraWhere}
        ORDER BY s.sospechoso DESC, s.created_at DESC
        LIMIT 300
    ";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $estadoFilter);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['ok' => true, 'data' => $rows]);
    exit;
}

// ─── count (badge en header) ─────────────────────────────────
if ($action === 'count') {
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total, SUM(sospechoso) AS sospechosos FROM solicitud_cambio_vendedor WHERE estado='pendiente' AND deleted_at IS NULL");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['ok' => true, 'total' => (int)$row['total'], 'sospechosos' => (int)($row['sospechosos'] ?? 0)]);
    exit;
}

// ─── aprobar (solo editor) ──────────────────────────────────
if ($action === 'aprobar') {
    if (!$esEditor) {
        echo json_encode(['ok' => false, 'msg' => 'Sin permisos.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $observacion = trim($_POST['observacion_revisor'] ?? '');
    $idRevisor   = (int)($_SESSION['usuario_id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
        exit;
    }

    $stmtS = $mysqli->prepare("SELECT id_local, id_vendedor_nuevo, nombre_vendedor_nuevo, telefono_nuevo, es_nuevo_vendedor FROM solicitud_cambio_vendedor WHERE id=? AND estado='pendiente' AND deleted_at IS NULL");
    $stmtS->bind_param('i', $id);
    $stmtS->execute();
    $solicitud = $stmtS->get_result()->fetch_assoc();
    $stmtS->close();

    if (!$solicitud) {
        echo json_encode(['ok' => false, 'msg' => 'Solicitud no encontrada o ya procesada.']);
        exit;
    }

    $idLocal         = (int)$solicitud['id_local'];
    $esNuevoVendedor = (int)$solicitud['es_nuevo_vendedor'] === 1;
    $nombreNuevo     = (string)$solicitud['nombre_vendedor_nuevo'];
    $telefonoNuevo   = $solicitud['telefono_nuevo']; // puede ser null

    $mysqli->begin_transaction();
    try {
        if ($esNuevoVendedor) {
            // Crear el vendedor nuevo; id_vendedor (columna legacy) no se usa funcionalmente, se deja en 0
            $stmtV = $mysqli->prepare("INSERT INTO vendedor (id_vendedor, nombre_vendedor, telefono) VALUES (0, ?, ?)");
            $stmtV->bind_param('ss', $nombreNuevo, $telefonoNuevo);
            $stmtV->execute();
            $idVendedorFinal = (int)$mysqli->insert_id;
            $stmtV->close();
        } else {
            $idVendedorFinal = (int)$solicitud['id_vendedor_nuevo'];
            if ($telefonoNuevo !== null) {
                $stmtT = $mysqli->prepare("UPDATE vendedor SET telefono=? WHERE id=?");
                $stmtT->bind_param('si', $telefonoNuevo, $idVendedorFinal);
                $stmtT->execute();
                $stmtT->close();
            }
        }

        // Actualizar tabla local
        $stmtL = $mysqli->prepare("UPDATE local SET id_vendedor=?, updated_at=NOW() WHERE id=?");
        $stmtL->bind_param('ii', $idVendedorFinal, $idLocal);
        $stmtL->execute();
        $stmtL->close();

        // Marcar la solicitud como aprobada
        $stmtA = $mysqli->prepare("UPDATE solicitud_cambio_vendedor SET estado='aprobada', id_revisor=?, observacion_revisor=?, reviewed_at=NOW() WHERE id=?");
        $stmtA->bind_param('isi', $idRevisor, $observacion, $id);
        $stmtA->execute();
        $stmtA->close();

        // Rechazar automáticamente otras solicitudes pendientes del mismo local
        $stmtR = $mysqli->prepare("UPDATE solicitud_cambio_vendedor SET estado='rechazada', observacion_revisor='Superada por solicitud aprobada', reviewed_at=NOW() WHERE id_local=? AND estado='pendiente' AND id != ? AND deleted_at IS NULL");
        $stmtR->bind_param('ii', $idLocal, $id);
        $stmtR->execute();
        $stmtR->close();

        $mysqli->commit();
        echo json_encode(['ok' => true, 'msg' => 'Solicitud aprobada. Vendedor del local actualizado.']);
    } catch (Throwable $e) {
        $mysqli->rollback();
        echo json_encode(['ok' => false, 'msg' => 'Error al aprobar: ' . $e->getMessage()]);
    }
    exit;
}

// ─── rechazar (solo editor) ─────────────────────────────────
if ($action === 'rechazar') {
    if (!$esEditor) {
        echo json_encode(['ok' => false, 'msg' => 'Sin permisos.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $observacion = trim($_POST['observacion_revisor'] ?? '');
    $idRevisor   = (int)($_SESSION['usuario_id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE solicitud_cambio_vendedor SET estado='rechazada', id_revisor=?, observacion_revisor=?, reviewed_at=NOW() WHERE id=? AND estado='pendiente' AND deleted_at IS NULL");
    $stmt->bind_param('isi', $idRevisor, $observacion, $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'msg' => 'Solicitud rechazada.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);

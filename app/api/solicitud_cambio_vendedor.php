<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sin sesión.']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión.']);
    exit;
}
$conn->set_charset('utf8mb4');
$usuarioId = (int)$_SESSION['usuario_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── crear ──────────────────────────────────────────────────
if ($action === 'crear') {
    $idLocal         = (int)($_POST['id_local'] ?? 0);
    $idVendedorNuevo = (int)($_POST['id_vendedor_nuevo'] ?? 0);
    $nombreNuevo     = trim($_POST['nombre_vendedor_nuevo'] ?? '');
    $telefonoNuevo   = trim($_POST['telefono_nuevo'] ?? '');
    $esNuevoVendedor = $idVendedorNuevo <= 0 ? 1 : 0;

    if ($idLocal <= 0 || $nombreNuevo === '') {
        echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']);
        exit;
    }

    if ($telefonoNuevo === '') {
        $telefonoNuevo = null;
    }

    // Si se eligió un vendedor existente, validar que exista
    if (!$esNuevoVendedor) {
        $stmtChk = $conn->prepare("SELECT id FROM vendedor WHERE id = ?");
        $stmtChk->bind_param('i', $idVendedorNuevo);
        $stmtChk->execute();
        $existeVend = $stmtChk->get_result()->fetch_assoc();
        $stmtChk->close();
        if (!$existeVend) {
            echo json_encode(['ok' => false, 'msg' => 'Vendedor seleccionado no existe.']);
            exit;
        }
    }

    // Snapshot del vendedor actual del local
    $stmtL = $conn->prepare("
        SELECT l.id_vendedor, v.nombre_vendedor
          FROM local l
          JOIN vendedor v ON v.id = l.id_vendedor
         WHERE l.id = ? AND l.deleted_at IS NULL
    ");
    $stmtL->bind_param('i', $idLocal);
    $stmtL->execute();
    $localRow = $stmtL->get_result()->fetch_assoc();
    $stmtL->close();

    if (!$localRow) {
        echo json_encode(['ok' => false, 'msg' => 'Local no encontrado.']);
        exit;
    }

    $idVendedorAnterior     = (int)$localRow['id_vendedor'];
    $nombreVendedorAnterior = (string)$localRow['nombre_vendedor'];

    // Evaluación de reglas de sospecha
    $sospechoso = 0;
    $motivos    = [];

    // R1: más de 5 solicitudes del mismo usuario en la última hora
    $s1 = $conn->prepare("SELECT COUNT(*) AS cnt FROM solicitud_cambio_vendedor WHERE id_usuario=? AND created_at >= NOW()-INTERVAL 1 HOUR AND deleted_at IS NULL");
    $s1->bind_param('i', $usuarioId);
    $s1->execute();
    if ((int)$s1->get_result()->fetch_assoc()['cnt'] > 5) {
        $sospechoso = 1; $motivos[] = 'Más de 5 cambios en 1 hora';
    }
    $s1->close();

    // R2: > 3 usuarios distintos al mismo local en las últimas 24h
    $s2 = $conn->prepare("SELECT COUNT(DISTINCT id_usuario) AS cnt FROM solicitud_cambio_vendedor WHERE id_local=? AND created_at >= NOW()-INTERVAL 24 HOUR AND deleted_at IS NULL");
    $s2->bind_param('i', $idLocal);
    $s2->execute();
    if ((int)$s2->get_result()->fetch_assoc()['cnt'] >= 3) {
        $sospechoso = 1; $motivos[] = 'Local modificado por +3 usuarios en 24h';
    }
    $s2->close();

    // R3: si es vendedor nuevo, verificar que no exista ya uno con el mismo nombre (posible duplicado)
    if ($esNuevoVendedor) {
        $s3 = $conn->prepare("SELECT nombre_vendedor FROM vendedor WHERE TRIM(LOWER(nombre_vendedor)) = TRIM(LOWER(?)) LIMIT 1");
        $s3->bind_param('s', $nombreNuevo);
        $s3->execute();
        $dupe = $s3->get_result()->fetch_assoc();
        $s3->close();
        if ($dupe) {
            $sospechoso = 1; $motivos[] = "Posible vendedor duplicado: ya existe '" . $dupe['nombre_vendedor'] . "'";
        }
    }

    $motivoStr = count($motivos) ? implode('; ', $motivos) : null;

    // ¿Existe ya una solicitud pendiente de este usuario para este local?
    $stmtEx = $conn->prepare("SELECT id FROM solicitud_cambio_vendedor WHERE id_local=? AND id_usuario=? AND estado='pendiente' AND deleted_at IS NULL LIMIT 1");
    $stmtEx->bind_param('ii', $idLocal, $usuarioId);
    $stmtEx->execute();
    $existing = $stmtEx->get_result()->fetch_assoc();
    $stmtEx->close();

    if ($existing) {
        // UPDATE: reemplaza la propuesta anterior sin crear un duplicado
        $stmtU = $conn->prepare("
            UPDATE solicitud_cambio_vendedor
               SET id_vendedor_anterior=?, nombre_vendedor_anterior=?,
                   id_vendedor_nuevo=?, nombre_vendedor_nuevo=?, telefono_nuevo=?,
                   es_nuevo_vendedor=?, sospechoso=?, motivo_sospecha=?, created_at=NOW()
             WHERE id=?
        ");
        $idVendedorNuevoParam = $esNuevoVendedor ? null : $idVendedorNuevo;
        $stmtU->bind_param('isissiisi',
            $idVendedorAnterior, $nombreVendedorAnterior,
            $idVendedorNuevoParam, $nombreNuevo, $telefonoNuevo,
            $esNuevoVendedor, $sospechoso, $motivoStr, $existing['id']
        );
        $stmtU->execute();
        $stmtU->close();
    } else {
        // INSERT: nueva solicitud
        $stmtI = $conn->prepare("
            INSERT INTO solicitud_cambio_vendedor
                (id_local, id_usuario, id_vendedor_anterior, nombre_vendedor_anterior,
                 id_vendedor_nuevo, nombre_vendedor_nuevo, telefono_nuevo,
                 es_nuevo_vendedor, sospechoso, motivo_sospecha)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $idVendedorNuevoParam = $esNuevoVendedor ? null : $idVendedorNuevo;
        $stmtI->bind_param('iiisissiis',
            $idLocal, $usuarioId,
            $idVendedorAnterior, $nombreVendedorAnterior,
            $idVendedorNuevoParam, $nombreNuevo, $telefonoNuevo,
            $esNuevoVendedor, $sospechoso, $motivoStr
        );
        $stmtI->execute();
        $stmtI->close();
    }

    echo json_encode(['ok' => true, 'sospechoso' => (bool)$sospechoso]);
    exit;
}

// ─── mis_pendientes ──────────────────────────────────────────
if ($action === 'mis_pendientes') {
    $stmt = $conn->prepare("SELECT id_local FROM solicitud_cambio_vendedor WHERE id_usuario=? AND estado='pendiente' AND deleted_at IS NULL");
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok' => true, 'data' => array_map(fn($r) => (int)$r['id_local'], $rows)]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);

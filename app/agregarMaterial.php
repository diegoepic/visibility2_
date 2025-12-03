<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);


if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No hay sesión']);
    exit;
}
$idUsuario = (int)$_SESSION['usuario_id'];


if (isset($_POST['csrf_token'])) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF inválido']);
        exit;
    }
}

// --- Datos POST ---
$idCampana = isset($_POST['idCampana']) ? (int)$_POST['idCampana'] : 0;
$idLocal   = isset($_POST['idLocal'])   ? (int)$_POST['idLocal']   : 0;
$nombreMat = isset($_POST['nombreMaterial']) ? trim($_POST['nombreMaterial']) : '';
$valorImp  = isset($_POST['valorImplementado']) ? trim($_POST['valorImplementado']) : '';

// --- Validaciones mínimas ---
if ($idCampana <= 0 || $idLocal <= 0 || $nombreMat === '') {
    echo json_encode(['status' => 'error', 'message' => 'Datos inválidos']);
    exit;
}
if ($valorImp === '' || !ctype_digit($valorImp)) {
    echo json_encode(['status' => 'error', 'message' => 'Valor implementado no válido']);
    exit;
}
$valorNum = (int)$valorImp;
if ($valorNum < 0 || $valorNum > 9) {
    echo json_encode(['status' => 'error', 'message' => 'Valor implementado fuera de rango (0-9)']);
    exit;
}

// (opcional) fija zona horaria de la conexión si tu MySQL no está en tu TZ
// $conn->query("SET time_zone = '-03:00'");

// --- Transacción por consistencia ---
$conn->begin_transaction();

try {
    // INSERT: fuerza fechaPropuesta = CURDATE()
    $sql = "
        INSERT INTO formularioQuestion (
            pregunta, motivo, material, valor, valor_propuesto,
            fechaVisita, countVisita, observacion,
            id_formulario, id_local, id_usuario,
            estado, is_priority, latGestion, lngGestion,
            created_at, fechaPropuesta
        ) VALUES (
            '', '', ?, 0, ?,
            NULL, 0, '',
            ?, ?, ?,
            0, 0, NULL, NULL,
            NOW(), CURDATE()
        )
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error al preparar INSERT: '.$conn->error);
    }
    $stmt->bind_param('siiii', $nombreMat, $valorNum, $idCampana, $idLocal, $idUsuario);
    if (!$stmt->execute()) {
        throw new Exception('Error al insertar: '.$stmt->error);
    }
    $idNuevo = $stmt->insert_id;
    $stmt->close();

    // UPDATE 1: si por cualquier razón quedó NULL/0000 en la fila recién creada, corrígela
    $upd1 = $conn->prepare("
        UPDATE formularioQuestion
        SET fechaPropuesta = CURDATE()
        WHERE id = ?
          AND (fechaPropuesta IS NULL
               OR fechaPropuesta = '0000-00-00'
               OR fechaPropuesta = '0000-00-00 00:00:00')
    ");
    if ($upd1) {
        $upd1->bind_param('i', $idNuevo);
        $upd1->execute();
        $upd1->close();
    }

    // UPDATE 2 (opcional pero útil):
    // normaliza cualquier otro registro del mismo local/campaña/usuario que aún tenga fechaPropuesta NULL/0000
    $upd2 = $conn->prepare("
        UPDATE formularioQuestion
        SET fechaPropuesta = CURDATE()
        WHERE id_formulario = ? AND id_local = ? AND id_usuario = ?
          AND (fechaPropuesta IS NULL
               OR fechaPropuesta = '0000-00-00'
               OR fechaPropuesta = '0000-00-00 00:00:00')
    ");
    if ($upd2) {
        $upd2->bind_param('iii', $idCampana, $idLocal, $idUsuario);
        $upd2->execute();
        $upd2->close();
    }

    $conn->commit();

    echo json_encode(['status' => 'success', 'idNuevo' => $idNuevo]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();

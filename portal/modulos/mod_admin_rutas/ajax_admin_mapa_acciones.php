<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function responder($ok, $message, $extra = []) {
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /*
    |--------------------------------------------------------------------------
    | 1) Detectar sesión real del sistema
    |--------------------------------------------------------------------------
    */

    $idAdmin = (int)($_SESSION['usuario_id'] ?? 0);
    $idEmpresaSesion = (int)($_SESSION['empresa_id'] ?? 0);

    if ($idAdmin <= 0) {
        responder(false, 'Sesión no válida. No se encontró usuario_id en sesión.');
    }

    /*
    |--------------------------------------------------------------------------
    | 2) Obtener perfil desde BD
    |--------------------------------------------------------------------------
    */

    $idPerfil = 0;

    $stmtPerfil = $conn->prepare("
        SELECT id_perfil
        FROM usuario
        WHERE id = ?
        LIMIT 1
    ");
    $stmtPerfil->bind_param("i", $idAdmin);
    $stmtPerfil->execute();
    $resPerfil = $stmtPerfil->get_result();

    if ($rowPerfil = $resPerfil->fetch_assoc()) {
        $idPerfil = (int)$rowPerfil['id_perfil'];
    }

    $stmtPerfil->close();

    if ($idPerfil !== 1) {
        responder(false, 'No tienes permisos para ejecutar esta acción.');
    }

    /*
    |--------------------------------------------------------------------------
    | 3) Leer JSON recibido
    |--------------------------------------------------------------------------
    */

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        responder(false, 'Solicitud inválida.');
    }

    $accion = $data['accion'] ?? '';
    $seleccionados = $data['seleccionados'] ?? [];

    if (!in_array($accion, ['reasignar_usuario', 'cambiar_fecha', 'eliminar'], true)) {
        responder(false, 'Acción no permitida.');
    }

    if (!is_array($seleccionados) || count($seleccionados) === 0) {
        responder(false, 'No hay locales seleccionados.');
    }

    if (count($seleccionados) > 500) {
        responder(false, 'Por seguridad, no puedes modificar más de 500 locales a la vez.');
    }

    $pares = [];

    foreach ($seleccionados as $item) {
        $idFormulario = (int)($item['id_formulario'] ?? 0);
        $idLocal = (int)($item['id_local'] ?? 0);

        if ($idFormulario <= 0 || $idLocal <= 0) {
            continue;
        }

        $pares[] = [
            'id_formulario' => $idFormulario,
            'id_local' => $idLocal
        ];
    }

    if (count($pares) === 0) {
        responder(false, 'La selección no contiene datos válidos.');
    }

    /*
    |--------------------------------------------------------------------------
    | 4) Ejecutar acción
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();

    $totalAfectados = 0;

    /*
      Importante:
      Se usa CAST(fechaVisita AS CHAR) para evitar errores de MySQL 8
      cuando existan fechas antiguas tipo 0000-00-00 00:00:00.
    */
    $condicionPendiente = "
        (
            fechaVisita IS NULL
            OR CAST(fechaVisita AS CHAR) = ''
            OR CAST(fechaVisita AS CHAR) = '0000-00-00 00:00:00'
        )
    ";

    if ($accion === 'reasignar_usuario') {
        $idUsuario = (int)($data['id_usuario'] ?? 0);

        if ($idUsuario <= 0) {
            throw new Exception('Debes indicar un usuario válido.');
        }

        $stmtCheck = $conn->prepare("
            SELECT id
            FROM usuario
            WHERE id = ?
              AND activo = 1
            LIMIT 1
        ");
        $stmtCheck->bind_param('i', $idUsuario);
        $stmtCheck->execute();
        $usuarioExiste = $stmtCheck->get_result()->num_rows > 0;
        $stmtCheck->close();

        if (!$usuarioExiste) {
            throw new Exception('El usuario seleccionado no existe o no está activo.');
        }

        $stmt = $conn->prepare("
            UPDATE formularioQuestion
            SET
                id_usuario = ?,
                updated_at = NOW()
            WHERE id_formulario = ?
              AND id_local = ?
              AND {$condicionPendiente}
        ");

        foreach ($pares as $par) {
            $stmt->bind_param(
                'iii',
                $idUsuario,
                $par['id_formulario'],
                $par['id_local']
            );
            $stmt->execute();
            $totalAfectados += $stmt->affected_rows;
        }

        $stmt->close();
    }

    if ($accion === 'cambiar_fecha') {
        $fechaPropuesta = trim((string)($data['fechaPropuesta'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPropuesta)) {
            throw new Exception('La fecha propuesta no es válida.');
        }

        $stmt = $conn->prepare("
            UPDATE formularioQuestion
            SET
                fechaPropuesta = ?,
                updated_at = NOW()
            WHERE id_formulario = ?
              AND id_local = ?
              AND {$condicionPendiente}
        ");

        foreach ($pares as $par) {
            $stmt->bind_param(
                'sii',
                $fechaPropuesta,
                $par['id_formulario'],
                $par['id_local']
            );
            $stmt->execute();
            $totalAfectados += $stmt->affected_rows;
        }

        $stmt->close();
    }

    if ($accion === 'eliminar') {
        $stmt = $conn->prepare("
            DELETE FROM formularioQuestion
            WHERE id_formulario = ?
              AND id_local = ?
              AND {$condicionPendiente}
        ");

        foreach ($pares as $par) {
            $stmt->bind_param(
                'ii',
                $par['id_formulario'],
                $par['id_local']
            );
            $stmt->execute();
            $totalAfectados += $stmt->affected_rows;
        }

        $stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | 5) Log de auditoría opcional
    |--------------------------------------------------------------------------
    */

    try {
        $payloadJson = json_encode([
            'accion' => $accion,
            'seleccionados' => $pares,
            'extra' => [
                'id_usuario' => $data['id_usuario'] ?? null,
                'fechaPropuesta' => $data['fechaPropuesta'] ?? null,
                'empresa_id' => $idEmpresaSesion,
            ]
        ], JSON_UNESCAPED_UNICODE);

        $stmtLog = $conn->prepare("
            INSERT INTO formularioQuestion_admin_log
                (accion, id_admin, payload_json, total_afectados, created_at)
            VALUES
                (?, ?, ?, ?, NOW())
        ");

        $stmtLog->bind_param(
            'sisi',
            $accion,
            $idAdmin,
            $payloadJson,
            $totalAfectados
        );

        $stmtLog->execute();
        $stmtLog->close();

    } catch (Throwable $logError) {
        error_log('[admin_mapa_acciones][log] ' . $logError->getMessage());
    }

    $conn->commit();

    responder(true, "Acción ejecutada correctamente. Registros afectados: {$totalAfectados}.", [
        'total_afectados' => $totalAfectados
    ]);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('[admin_mapa_acciones][rollback] ' . $rollbackError->getMessage());
        }
    }

    responder(false, $e->getMessage());
}
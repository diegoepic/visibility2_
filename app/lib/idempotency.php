<?php
/**
 * idempotency.php - Sistema de idempotencia mejorado para Visibility 2
 *
 * MEJORAS IMPLEMENTADAS (Auditoría 2026-01-22):
 * - Estado 'processing' para detectar requests en progreso
 * - Hash del request para detectar cambios en el payload
 * - Timeout automático para placeholders estancados

 */

declare(strict_types=1);

// Timeout en minutos para considerar un placeholder como abandonado
if (!defined('IDEMPO_PROCESSING_TIMEOUT_MINUTES')) {
    define('IDEMPO_PROCESSING_TIMEOUT_MINUTES', 10);
}

// TTL en días para entradas completadas
if (!defined('IDEMPO_COMPLETED_TTL_DAYS')) {
    define('IDEMPO_COMPLETED_TTL_DAYS', 30);
}

// ============================================================================
// FUNCIONES BASE (PRESERVADAS)
// ============================================================================

if (!function_exists('idempo_raw_header')) {
    function idempo_raw_header(): ?string
    {
        $h = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
        if ($h === '' && isset($_POST['X_Idempotency_Key'])) {
            $h = (string)$_POST['X_Idempotency_Key'];
        }
        return $h !== '' ? $h : null;
    }
}

if (!function_exists('idempo_sanitize')) {
    /**
     * Permite únicamente [A-Za-z0-9_.:-] y limita a 64 chars.
     * Si excede, aplica SHA-256 y recorta a 64 para estabilidad.
     */
    function idempo_sanitize(?string $k): ?string
    {
        if ($k === null || $k === '') return null;
        $k = preg_replace('/[^A-Za-z0-9_\-:\.]/', '', $k);
        if ($k === null) $k = '';
        if (strlen($k) > 64) {
            $k = substr(hash('sha256', $k), 0, 64);
        }
        return $k !== '' ? $k : null;
    }
}

if (!function_exists('idempo_get_key')) {
    /**
     * Key final lista para usar en índices y comparaciones.
     */
    function idempo_get_key(): ?string
    {
        return idempo_sanitize(idempo_raw_header());
    }
}

// ============================================================================
// FUNCIONES MEJORADAS CON STATUS
// ============================================================================

if (!function_exists('idempo_compute_request_hash')) {
    /**
     * Calcula un hash del request body para detectar cambios
     * Excluye campos que cambian entre intentos (csrf, timestamps)
     */
    function idempo_compute_request_hash(): ?string
    {
        if (empty($_POST)) {
            return null;
        }

        $body = $_POST;
        // Excluir campos que pueden variar entre reintentos legítimos
        unset(
            $body['csrf_token'],
            $body['X_Idempotency_Key'],
            $body['_timestamp'],
            $body['_nonce']
        );

        if (empty($body)) {
            return null;
        }

        ksort($body);
        return hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('idempo_claim_or_fail')) {
    /**
     * Busca respuesta persistida para (key, endpoint, user).
     * MEJORADO: Ahora maneja estado 'processing' para detectar requests concurrentes.
     *
     * Comportamiento:
     * - Si existe respuesta completada: la retorna y exit
     * - Si está en 'processing' y no ha expirado: retorna 409 (request en progreso)
     * - Si está en 'processing' y ha expirado: lo toma y continúa
     * - Si no existe: inserta placeholder 'processing' y continúa
     *
     * @return ?array Metadatos (reservado para futuros usos)
     */
    function idempo_claim_or_fail(mysqli $conn, string $endpoint): ?array
    {
        $key = idempo_get_key();
        if (!$key) return null;

        $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
        $request_hash = idempo_compute_request_hash();
        $timeout_minutes = IDEMPO_PROCESSING_TIMEOUT_MINUTES;

        // Verificar si existe entrada previa
        $stmt = $conn->prepare("
            SELECT id, status, status_code, response_json, request_hash, created_at,
                   TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes
            FROM request_log
            WHERE idempotency_key = ? AND endpoint = ? AND user_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            error_log("idempo_claim_or_fail: Failed to prepare SELECT: " . $conn->error);
            return null;
        }

        // Iniciar transacción para atomicidad
        $was_autocommit = true;
        if ($conn->autocommit !== false) {
            $conn->autocommit(false);
        } else {
            $was_autocommit = false;
        }

        $stmt->bind_param("ssi", $key, $endpoint, $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $status = $row['status'] ?? 'pending';
            $status_code = $row['status_code'];
            $response_json = $row['response_json'];
            $stored_hash = $row['request_hash'];
            $age_minutes = (int)($row['age_minutes'] ?? 0);
            $row_id = (int)$row['id'];

            // Caso 1: Ya completado - retornar respuesta almacenada
            if ($status === 'completed' && $status_code !== null) {
                $conn->commit();
                if ($was_autocommit) $conn->autocommit(true);

                // Limpiar cualquier output previo (warnings PHP, etc.)
                while (ob_get_level()) { ob_end_clean(); }
                http_response_code((int)$status_code);
                header('Content-Type: application/json; charset=UTF-8');
                header('X-Idempotent-Replay: true');
                echo $response_json ?? '{}';
                exit;
            }

            // Caso 2: En procesamiento y NO ha expirado
            if ($status === 'processing' && $age_minutes < $timeout_minutes) {
                $conn->commit();
                if ($was_autocommit) $conn->autocommit(true);

                // Limpiar cualquier output previo
                while (ob_get_level()) { ob_end_clean(); }
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => false,
                    'status' => 'error',
                    'error_code' => 'REQUEST_IN_PROGRESS',
                    'message' => 'Este request ya está siendo procesado. Espera unos segundos.',
                    'retry_after' => max(5, $timeout_minutes - $age_minutes) * 60,
                    'retryable' => true
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Caso 3: En procesamiento pero EXPIRADO - tomar el control
            // También detectar si el hash cambió (payload diferente con misma key)
            if ($request_hash !== null && $stored_hash !== null && $request_hash !== $stored_hash) {
                $conn->commit();
                if ($was_autocommit) $conn->autocommit(true);

                // Limpiar cualquier output previo
                while (ob_get_level()) { ob_end_clean(); }
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => false,
                    'status' => 'error',
                    'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                    'message' => 'La misma idempotency key se usó con datos diferentes.',
                    'retryable' => false
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Actualizar a 'processing' (retomar placeholder expirado o pendiente)
            $timeout_at = date('Y-m-d H:i:s', time() + ($timeout_minutes * 60));
            $upd = $conn->prepare("
                UPDATE request_log
                SET status = 'processing',
                    request_hash = ?,
                    timeout_at = ?,
                    created_at = NOW()
                WHERE id = ?
            ");
            if ($upd) {
                $upd->bind_param("ssi", $request_hash, $timeout_at, $row_id);
                $upd->execute();
                $upd->close();
            }

            $conn->commit();
            if ($was_autocommit) $conn->autocommit(true);

            return ['retaken' => true, 'previous_status' => $status];
        }

        // No existe - crear placeholder 'processing'
        $timeout_at = date('Y-m-d H:i:s', time() + ($timeout_minutes * 60));

        $ins = $conn->prepare("
            INSERT INTO request_log
            (idempotency_key, endpoint, user_id, status, request_hash, timeout_at, created_at)
            VALUES (?, ?, ?, 'processing', ?, ?, NOW())
        ");

        if ($ins) {
            $ins->bind_param("ssiss", $key, $endpoint, $uid, $request_hash, $timeout_at);
            if (!$ins->execute()) {
                // Puede fallar por race condition - otro proceso insertó primero
                // En ese caso, reintentar la lectura
                $ins->close();
                $conn->rollback();
                if ($was_autocommit) $conn->autocommit(true);

                // Recursión controlada (una sola vez)
                static $retry_count = 0;
                if ($retry_count < 1) {
                    $retry_count++;
                    usleep(50000); // 50ms
                    return idempo_claim_or_fail($conn, $endpoint);
                }

                return null;
            }
            $ins->close();
        }

        $conn->commit();
        if ($was_autocommit) $conn->autocommit(true);

        return ['created' => true];
    }
}

if (!function_exists('idempo_store_and_reply')) {
    /**
     * Persiste la respuesta final de la operación idempotente y responde.
     * MEJORADO: Actualiza status a 'completed'.
     */
    function idempo_store_and_reply(mysqli $conn, string $endpoint, int $statusCode, array $payload): void
    {
        $key = idempo_get_key();
        $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($key) {
            $up = $conn->prepare("
                UPDATE request_log
                SET status = 'completed',
                    status_code = ?,
                    response_json = ?,
                    completed_at = NOW(),
                    timeout_at = NULL
                WHERE idempotency_key = ? AND endpoint = ? AND user_id = ?
            ");

            if ($up) {
                $up->bind_param("isssi", $statusCode, $json, $key, $endpoint, $uid);
                $up->execute();
                $up->close();
            }
        }

        // Limpiar cualquier output previo (warnings PHP, espacios, etc.)
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo $json;
        exit;
    }
}

if (!function_exists('idempo_mark_failed')) {
    /**
     * Marca una entrada como fallida (para errores recuperables)
     * Esto permite que el cliente reintente con la misma key
     */
    function idempo_mark_failed(mysqli $conn, string $endpoint, ?string $error_code = null): bool
    {
        $key = idempo_get_key();
        if (!$key) return false;

        $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;

        $up = $conn->prepare("
            UPDATE request_log
            SET status = 'failed',
                timeout_at = NULL
            WHERE idempotency_key = ? AND endpoint = ? AND user_id = ?
              AND status = 'processing'
        ");

        if (!$up) return false;

        $up->bind_param("ssi", $key, $endpoint, $uid);
        $result = $up->execute();
        $up->close();

        return $result;
    }
}

if (!function_exists('idempo_gc')) {
    /**
     * Limpieza mejorada:
     * - Placeholders 'processing' expirados → 'failed'
     * - Entradas 'failed' antiguas (> 2 días) → eliminar
     * - Entradas 'completed' antiguas (> 30 días) → eliminar
     */
    function idempo_gc(mysqli $conn): void
    {
        // Marcar como fallidos los processing expirados
        @$conn->query("
            UPDATE request_log
            SET status = 'failed'
            WHERE status = 'processing'
              AND (
                (timeout_at IS NOT NULL AND timeout_at < NOW())
                OR (timeout_at IS NULL AND created_at < NOW() - INTERVAL " . IDEMPO_PROCESSING_TIMEOUT_MINUTES . " MINUTE)
              )
        ");

        // Eliminar pendientes/fallidos antiguos
        @$conn->query("
            DELETE FROM request_log
            WHERE status IN ('pending', 'failed')
              AND created_at < NOW() - INTERVAL 2 DAY
        ");

        // Eliminar completados muy antiguos
        @$conn->query("
            DELETE FROM request_log
            WHERE status = 'completed'
              AND completed_at IS NOT NULL
              AND completed_at < NOW() - INTERVAL " . IDEMPO_COMPLETED_TTL_DAYS . " DAY
        ");
    }
}

if (!function_exists('idempo_get_status')) {
    /**
     * Obtiene el estado actual de una key de idempotencia
     * Útil para diagnóstico y debugging
     */
    function idempo_get_status(mysqli $conn, string $endpoint, ?string $key = null): ?array
    {
        $key = $key ?? idempo_get_key();
        if (!$key) return null;

        $uid = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;

        $stmt = $conn->prepare("
            SELECT id, status, status_code, request_hash, created_at, completed_at, timeout_at
            FROM request_log
            WHERE idempotency_key = ? AND endpoint = ? AND user_id = ?
            LIMIT 1
        ");

        if (!$stmt) return null;

        $stmt->bind_param("ssi", $key, $endpoint, $uid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

// ============================================================================
// GARBAGE COLLECTION AUTOMÁTICO (Eventual - 1%)
// ============================================================================

if (mt_rand(1, 100) === 1 && isset($conn) && $conn instanceof mysqli) {
    idempo_gc($conn);
}

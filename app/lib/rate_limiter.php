<?php
/**
 * Rate limiter de ventana deslizante usando MySQL.
 * Crea la tabla automáticamente si no existe.
 * Falla de forma silenciosa para no romper el flujo de la app.
 */

function _rl_ensure_table(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $r = $conn->query(
            "CREATE TABLE IF NOT EXISTS rate_limit_log (
                id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_hash   VARCHAR(64)  NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_key_created (key_hash, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $ready = ($r !== false);
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Verifica si la request actual está dentro del límite.
 *
 * @param mysqli $conn
 * @param string $endpoint   Identificador del endpoint (p.ej. 'procesar_gestion')
 * @param int    $usuario_id
 * @param int    $max         Máximo de requests permitidas en la ventana
 * @param int    $window_sec  Tamaño de la ventana en segundos
 * @return bool  true = permitido, false = límite excedido
 */
function rate_limit_check(
    mysqli $conn,
    string $endpoint,
    int    $usuario_id,
    int    $max        = 20,
    int    $window_sec = 60
): bool {
    if (!_rl_ensure_table($conn)) return true;

    $key = hash('sha256', $endpoint . ':' . $usuario_id);

    try {
        // Limpieza probabilística (1 de cada 10 requests) para no acumular filas
        if (random_int(1, 10) === 1) {
            $conn->query("DELETE FROM rate_limit_log WHERE created_at < NOW() - INTERVAL 1 HOUR");
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt FROM rate_limit_log
             WHERE key_hash = ? AND created_at > NOW() - INTERVAL ? SECOND"
        );
        if (!$stmt) return true;
        $stmt->bind_param('si', $key, $window_sec);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();

        if ((int)$cnt >= $max) return false;

        $ins = $conn->prepare("INSERT INTO rate_limit_log (key_hash) VALUES (?)");
        if (!$ins) return true;
        $ins->bind_param('s', $key);
        $ins->execute();
        $ins->close();

        return true;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Responde con HTTP 429 y termina la ejecución.
 */
function rate_limit_abort(string $endpoint = ''): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    echo json_encode([
        'ok'       => false,
        'status'   => 'error',
        'code'     => 'RATE_LIMIT_EXCEEDED',
        'retryable'=> false,
        'message'  => 'Demasiadas solicitudes. Espera un momento antes de continuar.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

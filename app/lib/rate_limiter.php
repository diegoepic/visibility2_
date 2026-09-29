<?php
/**
 * rate_limiter.php — tope de peticiones por usuario y endpoint.
 *
 *
 * QUE CAMBIO EN LA TANDA 6, Y QUE NO
 * ----------------------------------
 * El hallazgo V-23 decía dos cosas: que el limitador falla abierto y que cuesta dos
 * consultas por petición. Las dos eran ciertas. Lo que la auditoría no decía —y se
 * comprobó antes de tocar nada— es cuánto pesaban:
 *
 *   * El UNICO llamador era `api/odometro_read.php`, un endpoint de LECTURA del piloto de
 *     odómetro (campaña 138), cuya función de respuesta dice literalmente "Nunca 5xx: el
 *     cliente siempre debe poder caer a manual".
 *   * `login_attempts` es otra implementación, en línea, dentro de `procesar_login.php`, y
 *     esa **ya falla cerrada** (`if (!$stmt) { error_log(...); fail_and_back(); }`).
 *   * `rate_limit_log` tenía 35 filas en el volcado del 2026-09-03.
 *
 * O sea que "amplifica una caída en vez de contenerla" describe un riesgo real del diseño,
 * pero su alcance hoy es: si la base se cae, el piloto del odómetro pierde su tope
 * antibucle. Nada más.
 *
 * Por eso el cambio NO es poner `return false` en los caminos de error —eso habría roto el
 * único llamador— sino dejar el limitador en condiciones de servir para lo que el plan
 * quiere usarlo:
 *
 *   1. `$fallaCerrado` explícito por llamador. El que quiera bloquear ante la duda lo pide.
 *      El valor por defecto conserva el comportamiento actual, a propósito.
 *   2. Contador en APCu cuando está disponible. Además de quitar las dos consultas, esto
 *      permite limitar SIN conexión a la base — que es lo que hacía imposible poner un tope
 *      en `api/route_compute.php`, el proxy de Google Routes, que no abre `$conn`.
 *   3. La limpieza probabilística (`DELETE` 1 de cada 10 peticiones) se fue a
 *      `scripts/cron_gc.php`, igual que se hizo con el GC de idempotencia en la T5-3. Es el
 *      mismo antipatrón: trabajo de mantenimiento pagado por un usuario esperando.
 *
 *
 * VENTANA FIJA vs DESLIZANTE
 * --------------------------
 * La rama de MySQL cuenta una ventana DESLIZANTE (`created_at > NOW() - INTERVAL n SECOND`).
 * La de APCu usa una ventana FIJA: la clave incluye `floor(time / ventana)`, así que el
 * contador se reinicia de golpe al cambiar de tramo. En el peor caso alguien mete hasta el
 * doble del tope repartido entre el final de un tramo y el principio del siguiente.
 *
 * Es el compromiso normal: la ventana deslizante en APCu necesita guardar la lista de
 * marcas de tiempo y recortarla en cada petición, y para un tope antibucle no vale la pena.
 * Si algún día hace falta precisión, la rama de MySQL sigue ahí.
 *
 *
 * APCu ES POR PROCESO
 * -------------------
 * El contador vive en la memoria del proceso PHP. En este hosting hay un solo servidor, así
 * que alcanza. Si algún día la app corre en varias máquinas, cada una llevará su propia
 * cuenta y el tope efectivo se multiplica por el número de máquinas — ahí hay que pasar a
 * Redis o volver a MySQL.
 */

if (!function_exists('rate_limit_apcu_disponible')) {
    function rate_limit_apcu_disponible(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        $ok = function_exists('apcu_fetch')
           && function_exists('apcu_inc')
           && function_exists('apcu_enabled')
           && @apcu_enabled();
        return $ok;
    }
}

if (!function_exists('_rl_ensure_table')) {
    function _rl_ensure_table(mysqli $conn): bool
    {
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
}

if (!function_exists('_rl_apcu')) {
    /**
     * Ventana fija en APCu. Ver la nota de arriba sobre por qué no es deslizante.
     * @return bool|null true/false = decisión tomada; null = APCu no pudo responder.
     */
    function _rl_apcu(string $key, int $max, int $window_sec): ?bool
    {
        if (!rate_limit_apcu_disponible()) return null;

        $tramo = (int)floor(time() / max(1, $window_sec));
        $k     = 'v2rl:' . $key . ':' . $tramo;

        $ok = false;
        // El TTL es el doble de la ventana para que la entrada del tramo anterior caduque
        // sola sin dejar basura, incluso si nadie vuelve a tocar esa clave.
        $n = @apcu_inc($k, 1, $ok, $window_sec * 2);
        if (!$ok) return null;               // APCu está pero no pudo: que decida el llamador

        return ((int)$n) <= $max;
    }
}

if (!function_exists('_rl_mysql')) {
    /** Ventana deslizante en MySQL. @return bool|null null = no se pudo decidir. */
    function _rl_mysql(mysqli $conn, string $key, int $max, int $window_sec): ?bool
    {
        if (!_rl_ensure_table($conn)) return null;

        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS cnt FROM rate_limit_log
                 WHERE key_hash = ? AND created_at > NOW() - INTERVAL ? SECOND"
            );
            if (!$stmt) return null;
            $stmt->bind_param('si', $key, $window_sec);
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($cnt);
            $stmt->fetch();
            $stmt->close();

            if ((int)$cnt >= $max) return false;

            $ins = $conn->prepare("INSERT INTO rate_limit_log (key_hash) VALUES (?)");
            if (!$ins) return null;
            $ins->bind_param('s', $key);
            $ins->execute();
            $ins->close();

            return true;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('rate_limit_check')) {
    /**
     * ¿La petición actual entra dentro del tope?
     *
     * @param mysqli|null $conn         Puede ser null: con APCu no hace falta base de datos.
     *                                  Eso es lo que permite limitar `api/route_compute.php`,
     *                                  que no abre conexión.
     * @param string      $endpoint     Identificador lógico ('procesar_gestion', 'route_compute'…)
     * @param int         $usuario_id
     * @param int         $max          Máximo de peticiones en la ventana
     * @param int         $window_sec   Tamaño de la ventana, en segundos
     * @param bool        $fallaCerrado Qué responder cuando NINGUN backend pudo decidir.
     *                                  false (por defecto) = dejar pasar, como hasta ahora.
     *                                  true = bloquear. Elegilo mirando qué duele más: que
     *                                  el tope no se aplique, o que el endpoint se caiga.
     * @return bool true = permitido
     */
    function rate_limit_check(
        ?mysqli $conn,
        string  $endpoint,
        int     $usuario_id,
        int     $max          = 20,
        int     $window_sec   = 60,
        bool    $fallaCerrado = false
    ): bool {
        $key = hash('sha256', $endpoint . ':' . $usuario_id);

        $r = _rl_apcu($key, $max, $window_sec);
        if ($r !== null) return $r;

        if ($conn instanceof mysqli) {
            $r = _rl_mysql($conn, $key, $max, $window_sec);
            if ($r !== null) return $r;
        }

        // Ningún backend pudo decidir. Se registra siempre: un tope que no se está
        // aplicando tiene que ser visible, se deje pasar o no.
        error_log(sprintf(
            '[rate_limit] sin backend disponible en %s (uid=%d); %s',
            $endpoint, $usuario_id, $fallaCerrado ? 'se BLOQUEA' : 'se deja pasar'
        ));
        return !$fallaCerrado;
    }
}

if (!function_exists('rate_limit_abort')) {
    /** Responde con HTTP 429 y termina la ejecución. */
    function rate_limit_abort(string $endpoint = ''): void
    {
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
}

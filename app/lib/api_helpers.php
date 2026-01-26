<?php
/**
 * api_helpers.php - Funciones helper para APIs de Visibility 2
 *
 * Incluye:
 * - Generación de Error IDs únicos para diagnóstico
 * - Funciones de respuesta JSON estandarizadas
 * - Logging estructurado de errores
 * - Validación y sanitización común

 */

declare(strict_types=1);

// ============================================================================
// FUNCIONES EXISTENTES (PRESERVADAS)
// ============================================================================

if (!function_exists('api_trace_id')) {
    function api_trace_id(): string
    {
        try {
            return bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4));
        } catch (Throwable $_) {
            return uniqid('trace_', true);
        }
    }
}

if (!function_exists('api_ts')) {
    function api_ts(): string
    {
        return gmdate('c');
    }
}

if (!function_exists('is_api_request')) {
    function is_api_request(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $path = $uri ?: $script;
        $path = is_string($path) ? strtolower($path) : '';

        $apiPrefixes = [
            '/visibility2/app/api/',
            '/visibility2/app/ping.php',
            '/visibility2/app/csrf_refresh.php',
            '/visibility2/app/create_visita_pruebas.php',
            '/visibility2/app/procesar_',
            '/visibility2/app/upload_',
        ];

        foreach ($apiPrefixes as $prefix) {
            if ($prefix === '') { continue; }
            if (str_starts_with($path, strtolower($prefix))) {
                return true;
            }
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $sfm    = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';

        return (
            (is_string($accept) && stripos($accept, 'application/json') !== false) ||
            ($xhr === 'XMLHttpRequest') ||
            ($sfm !== '' && strtolower($sfm) !== 'navigate')
        );
    }
}

if (!function_exists('api_json_response')) {
    function api_json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
}

if (!function_exists('api_auth_expired')) {
    function api_auth_expired(?string $message = null): void
    {
        $traceId = api_trace_id();
        $payload = [
            'ok'    => false,
            'code'  => 'AUTH_EXPIRED',
            'error' => $message ?: 'Sesión expirada',
            'trace_id' => $traceId,
            'ts' => api_ts(),
        ];
        api_json_response($payload, 401);
    }
}

if (!function_exists('api_error_response')) {
    function api_error_response(string $code, string $message, int $status = 500, array $extra = []): void
    {
        $payload = [
            'ok'   => false,
            'code' => $code,
            'error' => $message,
            'trace_id' => api_trace_id(),
            'ts' => api_ts(),
        ] + $extra;
        api_json_response($payload, $status);
    }
}

// ============================================================================
// NUEVAS FUNCIONES: GENERACIÓN DE ERROR ID ÚNICO
// ============================================================================

if (!function_exists('generate_error_id')) {
    /**
     * Genera un ID de error único para trazabilidad
     * Formato: ERR-YYYYMMDD-HHMMSS-XXXX donde XXXX es hex aleatorio
     *
     * @return string Error ID único
     */
    function generate_error_id(): string
    {
        $date = date('Ymd-His');
        try {
            $rand = bin2hex(random_bytes(4)); // 8 chars hex
        } catch (Throwable $_) {
            $rand = substr(uniqid('', true), -8);
        }
        return "ERR-{$date}-{$rand}";
    }
}

if (!function_exists('generate_request_id')) {
    /**
     * Genera un ID de request para tracking
     * Formato: REQ-YYYYMMDD-HHMMSS-XXXX
     *
     * @return string Request ID único
     */
    function generate_request_id(): string
    {
        $date = date('Ymd-His');
        try {
            $rand = bin2hex(random_bytes(4));
        } catch (Throwable $_) {
            $rand = substr(uniqid('', true), -8);
        }
        return "REQ-{$date}-{$rand}";
    }
}

// ============================================================================
// LOGGING ESTRUCTURADO A BASE DE DATOS
// ============================================================================

if (!function_exists('log_error_to_db')) {
    /**
     * Registra un error en la tabla error_log con ID único
     *
     * @param mysqli $conn Conexión a BD
     * @param string $error_id ID único del error
     * @param string $endpoint Nombre del endpoint
     * @param array $details Detalles adicionales del error
     * @return bool True si se guardó correctamente
     */
    function log_error_to_db(mysqli $conn, string $error_id, string $endpoint, array $details = []): bool
    {
        // Verificar que existe la tabla
        static $table_exists = null;
        if ($table_exists === null) {
            try {
                $check = @$conn->query("SHOW TABLES LIKE 'error_log'");
                $table_exists = ($check && $check->num_rows > 0);
                if ($check) $check->close();
            } catch (Throwable $_) {
                $table_exists = false;
            }
        }

        if (!$table_exists) {
            // Fallback a error_log de PHP
            error_log("[{$error_id}] {$endpoint}: " . json_encode($details));
            return false;
        }

        $user_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
        $http_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $error_code = $details['error_code'] ?? null;
        $error_message = $details['error_message'] ?? $details['message'] ?? null;
        $stack_trace = $details['stack_trace'] ?? null;
        $http_status = isset($details['http_status']) ? (int)$details['http_status'] : null;
        $response_snippet = isset($details['response']) ? substr(json_encode($details['response']), 0, 500) : null;
        $idempotency_key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_POST['X_Idempotency_Key'] ?? null;
        $client_guid = $details['client_guid'] ?? $_POST['client_guid'] ?? null;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

        // Hash del body para no guardar datos sensibles
        $request_body_hash = null;
        if (!empty($_POST)) {
            $body_copy = $_POST;
            // Eliminar campos sensibles antes del hash
            unset($body_copy['csrf_token'], $body_copy['password'], $body_copy['passwd']);
            $request_body_hash = hash('sha256', json_encode($body_copy));
        }

        // Headers relevantes (sin cookies ni auth)
        $safe_headers = [];
        foreach (['HTTP_X_IDEMPOTENCY_KEY', 'HTTP_X_CSRF_TOKEN', 'HTTP_X_OFFLINE_QUEUE', 'CONTENT_TYPE', 'HTTP_ACCEPT'] as $h) {
            if (isset($_SERVER[$h])) {
                $safe_headers[$h] = $_SERVER[$h];
            }
        }
        $headers_json = !empty($safe_headers) ? json_encode($safe_headers) : null;

        try {
            $stmt = $conn->prepare("
                INSERT INTO error_log
                (error_id, user_id, endpoint, http_method, error_code, error_message, stack_trace,
                 request_body_hash, request_headers_json, idempotency_key, client_guid,
                 http_status, response_snippet, user_agent, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                error_log("[{$error_id}] Failed to prepare error_log insert: " . $conn->error);
                return false;
            }

            $stmt->bind_param(
                "sisssssssssisss",
                $error_id, $user_id, $endpoint, $http_method, $error_code, $error_message, $stack_trace,
                $request_body_hash, $headers_json, $idempotency_key, $client_guid,
                $http_status, $response_snippet, $user_agent, $ip_address
            );

            $result = $stmt->execute();
            $stmt->close();

            // También log a archivo para redundancia
            error_log("[{$error_id}] {$endpoint}: " . ($error_message ?? 'Unknown error'));

            return $result;
        } catch (Throwable $e) {
            error_log("[{$error_id}] Exception logging to DB: " . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// RESPUESTAS JSON MEJORADAS CON ERROR ID
// ============================================================================

if (!function_exists('api_response_ok')) {
    /**
     * Envía respuesta JSON exitosa y termina ejecución
     *
     * @param array $payload Datos a incluir en la respuesta
     * @param int $status_code Código HTTP (default 200)
     * @return never
     */
    function api_response_ok(array $payload, int $status_code = 200): void
    {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $response = array_merge([
            'ok' => true,
            'status' => 'success'
        ], $payload);

        // Agregar request_id si está disponible
        if (defined('V2_REQUEST_ID')) {
            $response['request_id'] = V2_REQUEST_ID;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_response_error_v2')) {
    /**
     * Envía respuesta JSON de error con error_id para diagnóstico
     * Versión mejorada con logging a BD
     *
     * @param int $http_status Código HTTP
     * @param string $message Mensaje de error
     * @param array $extra Datos adicionales
     * @param mysqli|null $conn Conexión para log a BD (opcional)
     * @param string|null $endpoint Nombre del endpoint para log
     * @return never
     */
    function api_response_error_v2(
        int $http_status,
        string $message,
        array $extra = [],
        ?mysqli $conn = null,
        ?string $endpoint = null
    ): void {
        $error_id = generate_error_id();

        // Determinar si es retryable
        $retryable = ($http_status >= 500 || in_array($http_status, [408, 429, 502, 503, 504], true));

        // Mapeo de códigos de error
        $error_code = $extra['error_code'] ?? match($http_status) {
            401 => 'NO_SESSION',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            419 => 'CSRF_INVALID',
            422 => 'VALIDATION',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'GATEWAY_TIMEOUT',
            default => 'ERROR'
        };

        // Log a BD si hay conexión
        if ($conn && $endpoint) {
            log_error_to_db($conn, $error_id, $endpoint, array_merge([
                'error_code' => $error_code,
                'error_message' => $message,
                'http_status' => $http_status
            ], $extra));
        } else {
            // Fallback a error_log
            error_log("[{$error_id}] HTTP {$http_status}: {$message}");
        }

        http_response_code($http_status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $response = [
            'ok' => false,
            'status' => 'error',
            'message' => $message,
            'error_code' => $error_code,
            'error_id' => $error_id,
            'retryable' => $retryable
        ];

        // Incluir extras sin sobreescribir campos core
        foreach ($extra as $k => $v) {
            if (!isset($response[$k])) {
                $response[$k] = $v;
            }
        }

        // Agregar request_id si está disponible
        if (defined('V2_REQUEST_ID')) {
            $response['request_id'] = V2_REQUEST_ID;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================================
// FUNCIONES DE VALIDACIÓN
// ============================================================================

if (!function_exists('validate_csrf')) {
    /**
     * Valida CSRF desde headers o POST
     *
     * @return string|null Token si es válido, null si es inválido
     */
    function validate_csrf(): ?string
    {
        // Leer de header primero
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        // Fallback a POST
        if (empty($csrf) && isset($_POST['csrf_token'])) {
            $csrf = trim((string)$_POST['csrf_token']);
        }

        // Validar contra sesión
        if (empty($csrf) || empty($_SESSION['csrf_token'])) {
            return null;
        }

        if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
            return null;
        }

        return $csrf;
    }
}

if (!function_exists('require_auth')) {
    /**
     * Verifica que el usuario esté autenticado
     *
     * @param mysqli|null $conn Conexión para log
     * @param string|null $endpoint Endpoint para log
     * @return int User ID si está autenticado
     */
    function require_auth(?mysqli $conn = null, ?string $endpoint = null): int
    {
        if (!isset($_SESSION['usuario_id'])) {
            api_response_error_v2(401, 'Sesión no iniciada o expirada', [
                'error_code' => 'NO_SESSION'
            ], $conn, $endpoint);
        }

        return (int)$_SESSION['usuario_id'];
    }
}

if (!function_exists('require_csrf')) {
    /**
     * Verifica CSRF y falla si es inválido
     *
     * @param mysqli|null $conn Conexión para log
     * @param string|null $endpoint Endpoint para log
     * @return string Token válido
     */
    function require_csrf(?mysqli $conn = null, ?string $endpoint = null): string
    {
        $token = validate_csrf();
        if ($token === null) {
            api_response_error_v2(419, 'Token CSRF inválido o ausente', [
                'error_code' => 'CSRF_INVALID'
            ], $conn, $endpoint);
        }
        return $token;
    }
}

if (!function_exists('require_post_method')) {
    /**
     * Verifica que el método sea POST
     *
     * @param mysqli|null $conn Conexión para log
     * @param string|null $endpoint Endpoint para log
     * @return void
     */
    function require_post_method(?mysqli $conn = null, ?string $endpoint = null): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Permitir override
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']
            ?? $_POST['_method']
            ?? $_GET['_method']
            ?? null;

        if ($override) {
            $method = strtoupper((string)$override);
        }

        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            api_response_error_v2(405, 'Método no permitido. Use POST.', [
                'error_code' => 'METHOD_NOT_ALLOWED',
                'allowed_methods' => ['POST', 'OPTIONS']
            ], $conn, $endpoint);
        }
    }
}

// ============================================================================
// HELPERS DE ENTRADA TIPADOS
// ============================================================================

if (!function_exists('post_int_v2')) {
    /**
     * Obtiene un entero de POST
     */
    function post_int_v2(string $key, ?int $default = null): ?int
    {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            return $default;
        }
        return is_numeric($_POST[$key]) ? (int)$_POST[$key] : $default;
    }
}

if (!function_exists('post_float_v2')) {
    /**
     * Obtiene un float de POST
     */
    function post_float_v2(string $key, ?float $default = null): ?float
    {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            return $default;
        }
        return is_numeric($_POST[$key]) ? (float)$_POST[$key] : $default;
    }
}

if (!function_exists('post_str_v2')) {
    /**
     * Obtiene un string de POST
     */
    function post_str_v2(string $key, ?string $default = null): ?string
    {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            return $default;
        }
        return trim((string)$_POST[$key]);
    }
}

if (!function_exists('post_bool_v2')) {
    /**
     * Obtiene un boolean de POST
     */
    function post_bool_v2(string $key, bool $default = false): bool
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        $val = $_POST[$key];
        if (is_bool($val)) return $val;
        if (is_numeric($val)) return (int)$val !== 0;
        if (is_string($val)) {
            $val = strtolower(trim($val));
            return in_array($val, ['1', 'true', 'yes', 'on', 'si'], true);
        }
        return $default;
    }
}

// ============================================================================
// HASH DE PAYLOAD PARA IDEMPOTENCIA
// ============================================================================

if (!function_exists('compute_payload_hash')) {
    /**
     * Calcula hash del payload para detectar cambios
     *
     * @param array $post Datos POST
     * @param array $exclude Campos a excluir del hash
     * @return string SHA256 del payload
     */
    function compute_payload_hash(array $post, array $exclude = ['csrf_token', 'X_Idempotency_Key']): string
    {
        $clean = $post;
        foreach ($exclude as $key) {
            unset($clean[$key]);
        }
        ksort($clean); // Ordenar para consistencia
        return hash('sha256', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }
}

// ============================================================================
// GESTION DRAFT HELPERS
// ============================================================================

if (!function_exists('create_gestion_draft')) {
    /**
     * Crea un draft de gestión para el patrón SAGA
     *
     * @param mysqli $conn Conexión a BD
     * @param string $client_guid GUID del cliente
     * @param int $user_id ID del usuario
     * @param int $form_id ID del formulario
     * @param int $local_id ID del local
     * @param string $estado_gestion Estado de gestión solicitado
     * @param array $payload Datos completos del request
     * @return int|null ID del draft o null si falló
     */
    function create_gestion_draft(
        mysqli $conn,
        string $client_guid,
        int $user_id,
        int $form_id,
        int $local_id,
        string $estado_gestion,
        array $payload = []
    ): ?int {
        // Verificar que existe la tabla
        static $table_exists = null;
        if ($table_exists === null) {
            try {
                $check = @$conn->query("SHOW TABLES LIKE 'gestion_draft'");
                $table_exists = ($check && $check->num_rows > 0);
                if ($check) $check->close();
            } catch (Throwable $_) {
                $table_exists = false;
            }
        }

        if (!$table_exists) {
            return null; // Tabla no existe, ignorar silenciosamente
        }

        $idempotency_key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_POST['X_Idempotency_Key'] ?? null;
        $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payload_checksum = hash('sha256', $payload_json);
        $expected_photos = isset($payload['expected_photos']) ? (int)$payload['expected_photos'] : 0;

        // Intentar reusar draft existente con mismo client_guid
        $stmt = $conn->prepare("
            INSERT INTO gestion_draft
            (client_guid, idempotency_key, user_id, form_id, local_id, estado_gestion,
             payload_json, payload_checksum, expected_photos, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE
                idempotency_key = VALUES(idempotency_key),
                payload_json = VALUES(payload_json),
                payload_checksum = VALUES(payload_checksum),
                expected_photos = VALUES(expected_photos),
                updated_at = NOW()
        ");

        if (!$stmt) {
            error_log("create_gestion_draft: Failed to prepare: " . $conn->error);
            return null;
        }

        $stmt->bind_param(
            "ssiiisssi",
            $client_guid, $idempotency_key, $user_id, $form_id, $local_id, $estado_gestion,
            $payload_json, $payload_checksum, $expected_photos
        );

        if (!$stmt->execute()) {
            error_log("create_gestion_draft: Failed to execute: " . $stmt->error);
            $stmt->close();
            return null;
        }

        $draft_id = (int)$stmt->insert_id;
        $stmt->close();

        // Si fue UPDATE, obtener el ID real
        if ($draft_id === 0) {
            $sel = $conn->prepare("SELECT id FROM gestion_draft WHERE client_guid = ? LIMIT 1");
            if ($sel) {
                $sel->bind_param("s", $client_guid);
                $sel->execute();
                $sel->bind_result($draft_id);
                $sel->fetch();
                $sel->close();
            }
        }

        return $draft_id > 0 ? $draft_id : null;
    }
}

if (!function_exists('update_gestion_draft')) {
    /**
     * Actualiza el estado de un draft
     *
     * @param mysqli $conn Conexión a BD
     * @param string $client_guid GUID del cliente
     * @param string $status Nuevo estado
     * @param array $extra Datos adicionales (visita_id, error_code, etc.)
     * @return bool True si se actualizó
     */
    function update_gestion_draft(
        mysqli $conn,
        string $client_guid,
        string $status,
        array $extra = []
    ): bool {
        $sets = ['status = ?', 'updated_at = NOW()'];
        $types = 's';
        $values = [$status];

        if ($status === 'processing') {
            $sets[] = 'started_at = NOW()';
        } elseif (in_array($status, ['completed', 'failed'], true)) {
            $sets[] = 'completed_at = NOW()';
        }

        if (isset($extra['visita_id'])) {
            $sets[] = 'visita_id = ?';
            $types .= 'i';
            $values[] = (int)$extra['visita_id'];
        }

        if (isset($extra['uploaded_photos'])) {
            $sets[] = 'uploaded_photos = ?';
            $types .= 'i';
            $values[] = (int)$extra['uploaded_photos'];
        }

        if (isset($extra['error_code'])) {
            $sets[] = 'error_code = ?';
            $types .= 's';
            $values[] = $extra['error_code'];
        }

        if (isset($extra['error_message'])) {
            $sets[] = 'error_message = ?';
            $types .= 's';
            $values[] = $extra['error_message'];
        }

        // Agregar client_guid al final para el WHERE
        $types .= 's';
        $values[] = $client_guid;

        $sql = "UPDATE gestion_draft SET " . implode(', ', $sets) . " WHERE client_guid = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}

if (!function_exists('get_gestion_draft')) {
    /**
     * Obtiene un draft por client_guid
     *
     * @param mysqli $conn Conexión a BD
     * @param string $client_guid GUID del cliente
     * @return array|null Draft o null si no existe
     */
    function get_gestion_draft(mysqli $conn, string $client_guid): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, client_guid, idempotency_key, user_id, form_id, local_id, visita_id,
                   estado_gestion, status, payload_json, expected_photos, uploaded_photos,
                   created_at, updated_at, started_at, completed_at, error_code, error_message
            FROM gestion_draft
            WHERE client_guid = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("s", $client_guid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

// ============================================================================
// INICIALIZACIÓN
// ============================================================================

// Generar request_id para toda la petición si no existe
if (!defined('V2_REQUEST_ID')) {
    define('V2_REQUEST_ID', generate_request_id());
}

<?php
declare(strict_types=1);

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
<?php
declare(strict_types=1);

if (defined('VISIBILITY_BOOTSTRAPPED')) {
    return;
}

define('VISIBILITY_BOOTSTRAPPED', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Determina si la respuesta debe entregarse como JSON.
 */
function v2_is_json_request(): bool
{
    $accept       = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType  = $_SERVER['CONTENT_TYPE'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $uri          = $_SERVER['REQUEST_URI'] ?? '';

    if (stripos($accept, 'application/json') !== false) return true;
    if (stripos($contentType, 'application/json') !== false) return true;
    if (strcasecmp($requestedWith, 'XMLHttpRequest') === 0) return true;
    if (strpos($uri, '/api/') !== false) return true;

    return false;
}

function v2_log_message(string $message): void
{
    error_log('[visibility2] ' . $message);
}

function v2_format_throwable(Throwable $e): string
{
    return sprintf(
        '%s: %s in %s:%d | trace: %s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
}

function v2_clean_buffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function v2_render_error_response(): void
{
    http_response_code(500);

    if (v2_is_json_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error'   => 'internal_error',
            'message' => 'Ocurrió un error inesperado. Intenta nuevamente más tarde.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html>'
        . '<html lang="es">'
        . '<head>'
        . '<meta charset="UTF-8">'
        . '<title>Error del servidor</title>'
        . '</head>'
        . '<body>'
        . '<h1>500 - Error interno</h1>'
        . '<p>Ocurrió un error inesperado. Intenta nuevamente más tarde.</p>'
        . '</body>'
        . '</html>';
}

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // Respetar @ y configuraciones de error_reporting
    if (!(error_reporting() & $severity)) {
        return false;
    }

    v2_log_message(sprintf('PHP error [%d] %s in %s:%d', $severity, $message, $file, $line));

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    v2_log_message('Uncaught exception ' . v2_format_throwable($e));
    v2_clean_buffers();
    v2_render_error_response();
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    v2_log_message(sprintf('Fatal error [%d] %s in %s:%d', $error['type'], $error['message'], $error['file'], $error['line']));
    v2_clean_buffers();
    v2_render_error_response();
});
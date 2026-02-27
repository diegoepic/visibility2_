<?php
declare(strict_types=1);

/**
 * Bootstrap del módulo Panel de Encuesta (MVC).
 *
 * - Registra el autoloader PSR-4-like para el namespace PanelEncuesta\
 * - Carga panel_encuesta_helpers.php (funciones globales compartidas)
 * - Configura timezone y error reporting
 *
 * Uso en cada entry point:
 *   require_once __DIR__ . '/src/Bootstrap.php';
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
 *   (new PanelEncuesta\Controllers\DataController($conn))->handle();
 */

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/';
    $map  = [
        'PanelEncuesta\\Controllers\\'  => 'Controllers/',
        'PanelEncuesta\\Services\\'     => 'Services/',
        'PanelEncuesta\\Repositories\\' => 'Repositories/',
        'PanelEncuesta\\ValueObjects\\'  => 'ValueObjects/',
        'PanelEncuesta\\Config\\'        => 'Config/',
    ];
    foreach ($map as $ns => $dir) {
        if (str_starts_with($class, $ns)) {
            $rel  = str_replace('\\', '/', substr($class, strlen($ns)));
            $file = $base . $dir . $rel . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// Funciones globales compartidas: CSRF, json_response, build_panel_encuesta_filters, etc.
require_once __DIR__ . '/../panel_encuesta_helpers.php';

// Setup común (idéntico al que hacía cada endpoint)
date_default_timezone_set('America/Santiago');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

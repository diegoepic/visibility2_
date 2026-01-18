<?php
// Copia este archivo como config/local.php (NO versionado) para staging.
// Devuelve un array con overrides de entorno.

return [
    'env' => 'staging',
    'db' => [
        'host' => 'localhost',
        'user' => 'visibility_staging_user',
        'pass' => 'CHANGE_ME',
        'name' => 'visibility_staging',
    ],
    'app' => [
        // URL base para links absolutos, si aplica.
        'base_url' => 'https://staging.example.com',
    ],
    'paths' => [
        // Rutas absolutas fuera del repo, si necesitas separar uploads/logs.
        'uploads' => '/home/visibility/public_html/public_html_staging/uploads',
        'logs' => '/home/visibility/public_html/public_html_staging/logs',
        'tmp' => '/home/visibility/public_html/public_html_staging/tmp',
    ],
];

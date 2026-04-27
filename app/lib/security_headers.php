<?php
/**
 * Emite headers de seguridad HTTP para páginas HTML.
 * Llamar antes de cualquier output.
 *
 * Nota sobre 'unsafe-inline' en script-src:
 *   El código JavaScript de la app está embebido directamente en las páginas PHP
 *   (inline scripts), por lo que se requiere 'unsafe-inline' de forma transitoria.
 *   La mejora futura es migrar a nonces por request para eliminarlo.
 */
function emit_security_headers(): void {
    if (headers_sent()) return;

    $csp = implode('; ', [
        "default-src 'self'",
        // cdn.jsdelivr.net: browser-image-compression + exifr
        // maps.googleapis.com + maps.gstatic.com: Google Maps
        "script-src 'self' 'unsafe-inline' cdn.jsdelivr.net maps.googleapis.com maps.gstatic.com",
        "style-src 'self' 'unsafe-inline'",
        // data: para thumbnails base64, blob: para Object URLs de previews de fotos
        // visibility.cl: imágenes de referencia de materiales
        // *.gstatic.com: tiles y assets de Google Maps
        "img-src 'self' data: blob: https://visibility.cl https://*.googleapis.com https://*.gstatic.com",
        // connect-src: fetch/XHR (ping.php, create_visita, etc.) + geocoding de Maps
        "connect-src 'self' https://maps.googleapis.com",
        "font-src 'self' data:",
        "worker-src blob: 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "upgrade-insecure-requests",
    ]);

    header("Content-Security-Policy: $csp");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Permite geolocalización y cámara (necesarios para la app), bloquea el resto
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=()");
}

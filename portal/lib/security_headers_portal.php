<?php
/**
 * Cabeceras de seguridad HTTP del portal.
 *
 * Por qué no se reutiliza app/lib/security_headers.php: la política de la app
 * permite cdn.jsdelivr.net, maps.googleapis.com y poco más. El portal carga
 * desde una docena de CDN distintos (DataTables, Bootstrap, jQuery, CKEditor,
 * html2canvas) y embebe informes de Power BI en iframes. Aplicarle la política
 * de la app dejaría el portal en blanco.
 *
 * MODO REPORTE POR DEFECTO
 * La CSP se emite como Content-Security-Policy-Report-Only: el navegador anota
 * las violaciones en consola pero NO bloquea nada. Es a propósito. El portal
 * tiene módulos poco usados, y basta uno que cargue un host no listado para
 * romper una pantalla en producción sin que nadie lo note hasta que un editor
 * la abra.
 *
 * Para pasar a bloqueo, cuando un recorrido completo del portal no reporte
 * ninguna violación:
 *     portal_security_headers(false);
 * o definir PORTAL_CSP_ENFORCE=1 en el .env.
 *
 * Las otras cabeceras (nosniff, frame-options, referrer) sí bloquean desde el
 * primer día: no dependen de la allowlist y no pueden romper nada.
 */

if (!function_exists('portal_security_headers')) {

    function portal_csp_policy(): string
    {
        // Hosts verificados sobre el código del portal, agrupados por directiva.
        // Si aparece una violación en consola, el host que falta va acá.
        $scripts = [
            "'self'",
            // El JS del portal está embebido en los .php como <script> inline y
            // usa handlers onclick=. Quitar 'unsafe-inline' exige migrar a
            // nonces por request; queda para después.
            "'unsafe-inline'",
            "'unsafe-eval'",              // DataTables y pdfmake lo necesitan
            'cdnjs.cloudflare.com',
            'cdn.datatables.net',
            'stackpath.bootstrapcdn.com',
            'cdn.jsdelivr.net',
            'code.jquery.com',
            'ajax.googleapis.com',
            'unpkg.com',
            'maps.googleapis.com',
            'maps.gstatic.com',
            'html2canvas.hertzen.com',
        ];

        $estilos = [
            "'self'",
            "'unsafe-inline'",
            'cdnjs.cloudflare.com',
            'cdn.datatables.net',
            'stackpath.bootstrapcdn.com',
            'cdn.jsdelivr.net',
            'code.jquery.com',
            'unpkg.com',
            'fonts.googleapis.com',
            'www.datatables.net',
            'www.sprymedia.co.uk',
        ];

        return implode('; ', [
            "default-src 'self'",
            'script-src ' . implode(' ', $scripts),
            'style-src '  . implode(' ', $estilos),
            "font-src 'self' data: fonts.gstatic.com cdnjs.cloudflare.com stackpath.bootstrapcdn.com",
            // data: y blob: para las miniaturas y las exportaciones que se
            // arman en el navegador (html2canvas, pdfmake, PhpSpreadsheet).
            "img-src 'self' data: blob: https://visibility.cl https://*.googleapis.com https://*.gstatic.com",
            "connect-src 'self' https://maps.googleapis.com",
            // Los informes de Power BI van embebidos en iframes.
            "frame-src 'self' https://app.powerbi.com https://visibility.cl",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'upgrade-insecure-requests',
        ]);
    }

    /**
     * @param bool|null $reportOnly  null = decide el .env (PORTAL_CSP_ENFORCE).
     */
    function portal_security_headers(?bool $reportOnly = null): void
    {
        if (headers_sent()) {
            return;
        }

        // Estas no dependen de la allowlist: van en modo bloqueo siempre.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ($reportOnly === null) {
            $reportOnly = (getenv('PORTAL_CSP_ENFORCE') !== '1');
        }

        $cabecera = $reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        header($cabecera . ': ' . portal_csp_policy());
    }
}

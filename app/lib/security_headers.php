<?php
/**
 * Emite headers de seguridad HTTP para páginas HTML.
 * Llamar antes de cualquier output.
 *
 * Nota sobre 'unsafe-inline' en script-src:
 *   El código JavaScript de la app está embebido directamente en las páginas PHP
 *   (inline scripts), por lo que se requiere 'unsafe-inline' de forma transitoria.
 *   La mejora futura es migrar a nonces por request para eliminarlo.
 *
 * ---------------------------------------------------------------------------
 * ANTES DE APRETAR ESTA POLÍTICA, LEER ESTO
 * ---------------------------------------------------------------------------
 * El mapa VECTORIAL de Google (el que permite 3D: inclinación y rotación) no es
 * el mapa raster de toda la vida. Es un motor WebGL que:
 *
 *   1. compila WebAssembly            -> necesita 'wasm-unsafe-eval' en script-src
 *   2. baja su hoja de estilos desde
 *      https://www.gstatic.com/maps/  -> necesita *.gstatic.com en CONNECT-src,
 *                                        no sólo en img-src
 *   3. hace fetch() de URLs data:     -> necesita data: en connect-src
 *   4. corre en workers desde blob:   -> necesita blob: en worker-src y connect-src
 *
 * Si falta cualquiera de las cuatro, el mapa no da error visible: se queda GRIS,
 * con la ruta dibujada encima y nada debajo. En la consola aparecen violaciones de
 * CSP mezcladas con "Script error" de webgl.js, que no dicen qué falta.
 *
 * Cada línea de acá abajo dice para qué está. Si vas a quitar algo, probá el mapa
 * en modo navegación (no sólo la vista de ruta) antes de subirlo.
 */
function emit_security_headers(): void {
    if (headers_sent()) return;

    $csp = implode('; ', [
        "default-src 'self'",

        // Ya no hay CDN de librerías: browser-image-compression, heic2any, exifr y
        // sweetalert2 se sirven desde app/assets/vendor/ con versión fija (ver su README).
        //
        // 'wasm-unsafe-eval': el renderer vectorial de Maps compila un módulo WebAssembly
        //   (shared-label-worker.js). Es la palabra clave ACOTADA: permite compilar wasm
        //   pero NO habilita eval() ni new Function(), a diferencia de 'unsafe-eval'.
        //   Sin ella el motor cae a "ArrayBuffer instantiation", que también falla, y el
        //   mapa termina gris.
        // maps.googleapis.com: el loader (maps/api/js) y sus módulos.
        // maps.gstatic.com:    webgl.js, labeler_wrapper.js, shared-label-worker.js.
        "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://maps.googleapis.com https://maps.gstatic.com",

        "style-src 'self' 'unsafe-inline'",

        // data: para thumbnails base64, blob: para Object URLs de previews de fotos
        // visibility.cl: imágenes de referencia de materiales
        // *.gstatic.com / *.googleapis.com: tiles, sprites e íconos de Google Maps
        // *.ggpht.com: fotos de Street View / Places, si algún día se usan
        "img-src 'self' data: blob: https://visibility.cl https://*.googleapis.com https://*.gstatic.com https://*.ggpht.com",

        // connect-src: fetch/XHR de la app (ping.php, create_visita, sync_bundle…) y todo
        // lo que el mapa se baja por su cuenta:
        //   *.googleapis.com  -> tiles vectoriales, geocoding
        //   *.gstatic.com     -> el "style set" del mapa vectorial (www.gstatic.com/maps/res/...).
        //                        Ojo: es www., no maps., así que el comodín es obligatorio.
        //   routes.googleapis.com -> SÓLO para el rollback de ROUTES_PROXY_URL='' (llamada
        //                        directa desde el navegador). Con el proxy activo la
        //                        petición es de mismo origen y la cubre 'self'.
        //   data: / blob:     -> el worker de etiquetas hace fetch() de sus propios recursos
        //                        embebidos como data:image/png;base64 y de blobs.
        "connect-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://routes.googleapis.com",

        "font-src 'self' data:",

        // El mapa vectorial levanta sus workers desde blob:
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

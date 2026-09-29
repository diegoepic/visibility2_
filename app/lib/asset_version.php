<?php
declare(strict_types=1);

/**
 * Versión de los assets estáticos de la app.
 *
 * Antes cada página traía su propio literal (o directamente no lo traía, como
 * gestionarPruebas.php). Ahora hay un solo lugar, porque la versión ya se tiene que
 * mantener sincronizada a mano con dos archivos JS que PHP no puede leer:
 *
 *   app/sw.js                  -> const VERSION
 *   app/assets/js/v2_cache.js  -> const SW_VERSION
 *
 * Si los tres no coinciden, el service worker sigue sirviendo los archivos viejos y el
 * despliegue "no llega" a los teléfonos. Subir siempre los tres juntos.
 */
if (!defined('V2_ASSET_VER')) {
    define('V2_ASSET_VER', 'v7.7.1');
}

/** Sufijo listo para pegar en un src/href: "?v=v7.4.0" */
function v2_asset_q(): string
{
    return '?v=' . rawurlencode(V2_ASSET_VER);
}

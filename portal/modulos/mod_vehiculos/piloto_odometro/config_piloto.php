<?php
/**
 * config_piloto.php
 * -----------------------------------------------------------------------------
 * Configuración del PILOTO de lectura de odómetro con IA.
 *
 * ⚠️  LA API KEY NO SE SUBE AL REPO. En el server, edita este archivo y pega la
 *     key en OPENAI_API_KEY (o defínela en el entorno como OPENAI_API_KEY y deja
 *     el valor aquí vacío: se toma primero getenv()).
 *
 * Requisitos del server: extensión cURL y GD (idealmente con soporte WebP).
 * Si GD/WebP no está disponible, el piloto envía la imagen original sin
 * redimensionar (funciona, pero consume un poco más).
 */

if (defined('PILOTO_CONFIG_CARGADA')) {
    return;
}
define('PILOTO_CONFIG_CARGADA', true);

/* =========================================================================
   1) API KEY  (getenv primero; si está vacío usa el literal de abajo)
   ========================================================================= */
$__envKey = getenv('OPENAI_API_KEY');
define('OPENAI_API_KEY', ($__envKey !== false && $__envKey !== '')
    ? $__envKey
    : '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');   // <-- EDITAR EN EL SERVER

/* =========================================================================
   2) MODELOS a comparar (los 2 tiers del piloto)
      Ajusta los 'id' al nombre exacto de modelo disponible en TU cuenta.
      'label' es solo para la UI.
   ========================================================================= */
$GLOBALS['PILOTO_MODELOS'] = [
    ['id' => 'gpt-4o',      'label' => 'Máxima precisión'],
    // ['id' => 'gpt-4o-mini', 'label' => 'Económico'],  // descartado: se rate-limitea con imágenes y no es más barato
];

/* =========================================================================
   3) PRECIOS  (USD por 1.000.000 de tokens)  — VERIFICA los precios vigentes
      de tu cuenta. Se usan solo para ESTIMAR costo; los tokens que se guardan
      son los reales que devuelve la API.
   ========================================================================= */
$GLOBALS['PILOTO_PRECIOS'] = [
    'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60],
    'gpt-4o'      => ['in' => 2.50, 'out' => 10.00],
];

/* =========================================================================
   4) Parámetros del piloto
   ========================================================================= */
define('PILOTO_SAMPLE_CAP',        100);   // Nº máx de fotos a procesar en total
define('PILOTO_CHUNK',             3);     // Fotos por request AJAX (x nº modelos = llamadas)
define('PILOTO_IMG_MAX_PX',        1024);  // Lado mayor tras redimensionar
define('PILOTO_IMG_JPEG_QUALITY',  85);
define('PILOTO_DETAIL',            'high');// 'high' lee dígitos chicos mejor; 'low' es más barato
define('PILOTO_UMBRAL_CONFIANZA',  0.70);  // Bajo esto = "dudosa" (candidata a reintento)
define('PILOTO_PROYECCION_MENSUAL', 4600); // Imágenes/mes para proyectar costo (del correo)

// Freno de seguridad: si el costo total acumulado en la tabla supera esto (USD),
// el procesador se niega a seguir. Evita gastar de más por un error.
define('PILOTO_MAX_COSTO_TOTAL_USD', 15.00);

// Timeout por llamada a la API (segundos)
define('PILOTO_HTTP_TIMEOUT', 45);

/* =========================================================================
   5) Preguntas de la encuesta 138 (se resuelven por TEXTO, no por id fijo)
   ========================================================================= */
define('PILOTO_FORMULARIO_ID', 138);
define('PILOTO_TXT_KM',   'INGRESE KILOMETRAJE DE LA CAMIONETA:');
define('PILOTO_TXT_FOTO', 'FOTO ODOMETRO');

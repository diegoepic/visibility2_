<?php
/**
 * Observaciones de gestión: escapado y largo máximo, en un solo sitio.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * --------------------------
 * `formularioQuestion.observacion` era `varchar(360)` y el guardado de la gestión reventaba
 * con "Data too long for column 'observacion' at row 1". No era un caso raro: sobre el
 * volcado del 2026-09-03 hay **72 fallos de 14 usuarios distintos, desde el 2026-03-18**,
 * y 98 filas ya estaban clavadas en el techo de 360 caracteres.
 *
 * Lo caro no era el error en sí, sino lo que venía después: el fallo sale como HTTP 500,
 * `offline-queue.js` clasifica los 5xx como reintentables, y el reintento vuelve a chocar
 * con la misma fila llena. O sea el trabajo se queda en la cola reintentando para siempre y
 * la gestión no se guarda nunca. En el log se ve el mismo `client_guid` fallando dos veces
 * con 33 segundos de diferencia, y un usuario con 38 fallos acumulados.
 *
 * Tres cosas se sumaban:
 *
 *   1. El `<textarea>` no tenía `maxlength`, así que se podía escribir cualquier cosa.
 *   2. `htmlspecialchars()` infla el texto ANTES de guardarlo: un `<` pasa a 4 caracteres y
 *      un apóstrofo a 6 (`&#039;`). 300 caracteres escritos pueden superar los 360 guardados.
 *   3. Y la de fondo: el UPDATE de "no implementado" hacía
 *      `CONCAT(COALESCE(observacion,''), ' ', ?)`. **Acumula**, no reemplaza. Visita tras
 *      visita el texto crece hasta que revienta, y a partir de ahí ese material no se puede
 *      guardar nunca más.
 *
 * `scripts/40` sube la columna a `varchar(2000)`. Este archivo es la otra mitad: recorta
 * antes de escribir, para que el guardado no pueda volver a fallar por largo **tenga la
 * columna el tamaño que tenga**, incluso si la migración todavía no se aplicó.
 *
 * SOBRE LOS NÚMEROS
 * -----------------
 * Medido sobre 687.651 filas: p99 = 189 caracteres, máximo ≈ 378, y 400.345 vacías. Los
 * topes de acá (1.000 y 1.900) están muy por encima de todo eso a propósito: no cambian ni
 * una sola observación de las que existen hoy, sólo impiden el desborde. Si alguna vez se
 * vuelven estrechos, la columna admite subir a TEXT sin tocar este archivo.
 *
 * Lo usan `app/procesar_gestion_pruebas.php`, `app/procesar_gestion.php` (la versión vieja,
 * que tenía el mismo bug) y `portal/modulos/mod_seguimiento_etapas/ajax_acciones.php`.
 */

/** Largo máximo de UNA observación, ya escapada. */
/* La observacion termina en formularioQuestion.observacion, que sigue en utf8mb3 y no
   admite caracteres de 4 bytes. Un emoji ahi aborta la transaccion entera y la gestion
   se pierde. Ver lib/dominio/texto_utf8mb3.php. */
require_once __DIR__ . '/texto_utf8mb3.php';

if (!defined('V2_OBSERVACION_MAX')) {
    define('V2_OBSERVACION_MAX', 1000);
}

/**
 * Largo máximo de la observación ACUMULADA por el CONCAT de "no implementado".
 * Queda por debajo del ancho de la columna (2000) para dejar margen al separador.
 */
if (!defined('V2_OBSERVACION_ACUM_MAX')) {
    define('V2_OBSERVACION_ACUM_MAX', 1900);
}

if (!function_exists('observacion_recortar')) {
    /**
     * Recorta un texto YA ESCAPADO a `$max` caracteres sin partir una entidad HTML.
     *
     * Cortar a lo bruto puede dejar `&#03` en vez de `&#039;`: se ve como basura y, si algo
     * lo vuelve a escapar, el `&` se duplica. Por eso se quita la entidad incompleta del
     * final. El `{0,9}` cubre la más larga que produce htmlspecialchars (`&quot;`) con
     * holgura, y no puede comerse texto normal porque exige que empiece en `&`.
     */
    function observacion_recortar(string $txt, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        $largo = function_exists('mb_strlen') ? mb_strlen($txt, 'UTF-8') : strlen($txt);
        if ($largo <= $max) {
            return $txt;
        }
        $txt = function_exists('mb_substr')
             ? mb_substr($txt, 0, $max, 'UTF-8')
             : substr($txt, 0, $max);
        return (string)preg_replace('/&[#a-zA-Z0-9]{0,9}$/', '', $txt);
    }
}

if (!function_exists('observacion_normalizar')) {
    /**
     * Lo que escribió el ejecutor -> lo que se guarda: trim, escapado y recorte.
     *
     * Reemplaza al `htmlspecialchars(trim($x), ENT_QUOTES, 'UTF-8')` que estaba copiado en
     * los cinco sitios que escriben la columna. El recorte va DESPUÉS del escapado a
     * propósito: es el largo escapado el que tiene que caber en la columna, no el escrito.
     *
     * @param mixed $raw Valor crudo de $_POST (puede no ser string ni existir).
     */
    function observacion_normalizar($raw): string
    {
        $txt = trim((string)$raw);
        if ($txt === '') {
            return '';
        }
        /* El saneado va ANTES de htmlspecialchars y del recorte. Si se hiciera despues,
           el recorte podria partir por la mitad una secuencia de 4 bytes y dejar bytes
           sueltos, que es peor que el emoji. */
        return observacion_recortar(
            htmlspecialchars(utf8mb3_saneado($txt), ENT_QUOTES, 'UTF-8'),
            V2_OBSERVACION_MAX
        );
    }
}

if (!function_exists('observacion_sql_concat')) {
    /**
     * La expresión SQL que acumula un motivo nuevo sobre la observación anterior, CON TOPE.
     *
     * Se usa `RIGHT(...)` y no `LEFT(...)` porque cuando el tope llegue a activarse hay que
     * conservar lo más RECIENTE: al que lee la observación le importa el último motivo, no
     * el de hace veinte visitas. El precio es que, si alguna vez se corta, puede quedar una
     * entidad HTML partida al principio del texto; con el tope actual eso está a cinco veces
     * del máximo que existe hoy en la base, así que no ocurre en la práctica.
     *
     * @param string $col Nombre de la columna (no viene de entrada de usuario).
     */
    function observacion_sql_concat(string $col = 'observacion'): string
    {
        return "RIGHT(CONCAT(COALESCE({$col},''),' ',?), " . V2_OBSERVACION_ACUM_MAX . ")";
    }
}

<?php
declare(strict_types=1);
/**
 * texto_utf8mb3.php — quitar de un texto lo que una columna utf8mb3 no puede guardar.
 *
 * EL FALLO QUE ESTO EVITA (visto en produccion el 28-09-2026)
 * ----------------------------------------------------------
 * Un ejecutor escribio un emoji al gestionar un local y la gestion NO se guardo. En
 * `error_log` quedo:
 *
 *     endpoint    procesar_gestion_pruebas.php
 *     error_code  TRANSACTION_FAILED
 *     mensaje     Conversion from collation utf8mb4_0900_ai_ci into
 *                 utf8mb3_unicode_ci impossible for parameter
 *
 * La cadena completa:
 *
 *   1. con_.php abre la conexion en `utf8`, que es utf8mb3.
 *   2. procesar_gestion_pruebas.php la cambia a utf8mb4 — con razon: `gestion_visita`
 *      SI es utf8mb4 y sin ese cambio se escribiria mal.
 *   3. Desde ahi todo parametro de texto viaja como utf8mb4.
 *   4. Dos columnas del mismo guardado siguen en utf8mb3:
 *          formularioQuestion.observacion
 *          form_question_responses.answer_text
 *   5. utf8mb3 llega a 3 bytes por caracter; un emoji ocupa 4. MySQL no puede convertir
 *      el parametro y aborta LA TRANSACCION ENTERA.
 *
 * Y lo peor no es que falle: TRANSACTION_FAILED sale como 5xx, que offline-queue.js
 * clasifica como reintentable, asi que ese trabajo reintenta para siempre sin entrar nunca.
 * Es la misma trampa que el bug de `observacion` demasiado larga (ver observacion.php).
 *
 * POR QUE ESTO NO ESTABA ANTES
 * ----------------------------
 * `procesar_gestion.php`, el archivo antiguo, NO llama a set_charset: se queda en utf8mb3
 * y MySQL reemplaza el emoji por '?' en silencio. Feo, pero guarda. El set_charset del
 * archivo nuevo convirtio un problema cosmetico en una gestion perdida.
 *
 * POR QUE SE BORRA Y NO SE REEMPLAZA
 * ----------------------------------
 * Un emoji no aporta a una auditoria, y sustituirlo por un marcador ('?', '[emoji]')
 * ensucia el texto que despues alguien lee en un informe. Si el comentario era SOLO un
 * emoji queda vacio, que es exactamente lo que valia.
 *
 * CUANDO SE PUEDE BORRAR ESTE ARCHIVO
 * -----------------------------------
 * Cuando las dos columnas esten en utf8mb4 (scripts/41 hace la primera; la segunda son
 * 9,4 M de filas y necesita ventana). Hasta entonces esto es la red que impide perder
 * trabajo de campo. Ojo: quedan 62 de 108 tablas en utf8mb3 — hallazgo V-32 —, asi que
 * cualquier columna de texto libre que se agregue deberia pasar por aca.
 */

if (!function_exists('utf8mb3_saneado')) {
    /**
     * Devuelve $txt sin los caracteres que utf8mb3 no admite (los de 4 bytes en UTF-8:
     * emojis y algunos ideogramas raros, todo lo que esta fuera del plano basico).
     *
     * Es seguro llamarlo siempre: si el texto no trae nada de eso, vuelve igual.
     */
    function utf8mb3_saneado($txt): string
    {
        $txt = (string)$txt;
        if ($txt === '') {
            return '';
        }

        // Camino normal. El modificador /u exige UTF-8 valido.
        $limpio = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $txt);

        if ($limpio === null) {
            /* preg_replace devuelve null si /u encuentra UTF-8 invalido, y eso pasa: llega
               texto de teclados y portapapeles de todo tipo. Se cae a un filtro por BYTES,
               que no necesita que la cadena sea valida: en UTF-8 una secuencia de 4 bytes
               empieza con 0xF0-0xF7 y sigue con tres bytes de continuacion. */
            $limpio = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $txt);
        }

        if ($limpio === null) {
            // Ni siquiera el filtro por bytes pudo. Mejor el texto original que perder la
            // gestion: si trae un emoji fallara igual, pero no se rompe nada mas por aca.
            return $txt;
        }

        // Al quitar el emoji suelen quedar espacios dobles o un espacio final.
        $limpio = preg_replace('/ {2,}/', ' ', $limpio);
        return trim((string)$limpio);
    }
}

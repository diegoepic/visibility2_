-- 40_observacion_larga.sql — la observación de la gestión deja de reventar por larga
--
-- QUE HACE
-- --------
-- Sube `formularioQuestion.observacion` de varchar(360) a varchar(2000).
--
--
-- POR QUE
-- -------
-- Guardar una gestión fallaba con:
--
--     Data too long for column 'observacion' at row 1
--
-- No era un caso de laboratorio. En `error_log`, sobre el volcado del 2026-09-03:
--
--     72 fallos    de 14 usuarios distintos
--     desde el 2026-03-18 hasta el ultimo dia del volcado
--     un solo usuario (63) acumula 38
--
-- Y lo caro no es el error, es lo que pasa despues. El fallo sale como HTTP 500;
-- offline-queue.js clasifica los 5xx como reintentables; el reintento vuelve a chocar con
-- la misma fila llena. El trabajo se queda en la cola reintentando PARA SIEMPRE y la
-- gestion no se guarda nunca. En el log se ve el mismo client_guid fallando dos veces con
-- 33 segundos de diferencia.
--
--
-- POR QUE SE LLENABA
-- ------------------
-- Tres cosas se sumaban, y sólo la tercera explica por qué es permanente:
--
--   1. El <textarea> no tenia maxlength: se podia escribir cualquier cosa.
--   2. htmlspecialchars() infla el texto antes de guardarlo. Un `<` pasa a 4 caracteres y
--      un apostrofo a 6 (`&#039;`), asi que 300 caracteres escritos pueden pasar de 360.
--   3. El UPDATE de "no implementado" hacia:
--
--          UPDATE formularioQuestion
--             SET observacion = CONCAT(COALESCE(observacion,''), ' ', ?)
--
--      ACUMULA, no reemplaza. Cada visita en que un material queda sin implementar añade
--      el motivo al texto anterior. Cuando la fila llega al techo, ese material no se puede
--      guardar NUNCA MAS: todos los intentos siguientes revientan en el mismo sitio.
--
-- Medido sobre las 687.651 filas de formularioQuestion:
--
--     observacion vacia            400.345 filas
--     p50                                0 caracteres
--     p90                               88
--     p99                              189
--     maximo                         ~ 378
--     filas ya clavadas en el techo     98      <- estas son las que no se podian guardar
--
-- Aplicar esto DESBLOQUEA esas 98 de inmediato.
--
--
-- POR QUE VARCHAR(2000) Y NO TEXT
-- -------------------------------
-- TEXT es el tipo semanticamente correcto para un texto que se acumula, y es lo que ya usa
-- `gestion_visita.observacion`. Pero varchar -> TEXT obliga a RECONSTRUIR la tabla entera:
-- 687.651 filas, minutos de trabajo y espacio en disco, en una base de produccion que se
-- migra a mano.
--
-- Ensanchar un VARCHAR, en cambio, es IN-PLACE si no cambia la cantidad de bytes de
-- longitud que necesita la columna. utf8mb3 usa hasta 3 bytes por caracter:
--
--     varchar( 360) ->  1.080 bytes  -> ya necesita 2 bytes de longitud (>= 256)
--     varchar(2000) ->  6.000 bytes  -> sigue necesitando 2
--
-- O sea que cabe en ALGORITHM=INPLACE, LOCK=NONE: sin reconstruccion y sin bloquear
-- escrituras. Por eso el ALTER de mas abajo pide ese algoritmo EXPLICITAMENTE: si la
-- suposicion fuera falsa, MySQL da error en vez de ponerse a copiar la tabla en silencio.
--
-- 2.000 caracteres son mas de 5 veces el maximo que existe hoy (378). Si algun dia se
-- queda corto, pasar a TEXT no obliga a tocar codigo: `app/lib/dominio/observacion.php`
-- recorta por su cuenta y sus topes son independientes del ancho de la columna.
--
-- Se mantienen a proposito el juego de caracteres (utf8mb3) y el NOT NULL: cambiar
-- cualquiera de los dos convertiria esto en una reconstruccion, que es justo lo que se
-- quiere evitar.
--
--
-- RIESGO
-- ------
-- Bajo. Ensanchar una columna no toca ningun dato existente y no rompe ninguna lectura.
-- La otra mitad del arreglo (recortar antes de escribir) va en el codigo, asi que el
-- guardado deja de fallar aunque este script NO se aplique — sólo que entonces las 98
-- filas atascadas siguen atascadas.
--
--
-- REVERSION
-- ---------
-- No se puede volver a varchar(360) sin perder datos si alguna observacion ya crecio.
-- Para volver, primero recortar y despues estrechar:
--
--   UPDATE formularioQuestion SET observacion = LEFT(observacion, 360)
--    WHERE CHAR_LENGTH(observacion) > 360;
--   ALTER TABLE formularioQuestion
--     MODIFY `observacion` varchar(360) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL;
--
-- ---------------------------------------------------------------------------

-- Reidempotente: si la columna ya mide 2000 o mas, no hace nada.
SET @ancho = (SELECT CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME   = 'formularioQuestion'
                 AND COLUMN_NAME  = 'observacion');

SET @sql = IF(@ancho IS NOT NULL AND @ancho < 2000,
  "ALTER TABLE formularioQuestion
     MODIFY `observacion` varchar(2000)
            CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL
            COMMENT 'Observacion de la gestion. Se acumula en el flujo de no implementado; el tope real lo pone app/lib/dominio/observacion.php. Ver scripts/40.',
     ALGORITHM=INPLACE, LOCK=NONE",
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SELECT COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME   = 'formularioQuestion'
--      AND COLUMN_NAME  = 'observacion';
--     -> varchar(2000) / NO / 2000
--
--   -- Las filas que estaban atascadas, ahora con aire para crecer:
--   SELECT COUNT(*) FROM formularioQuestion WHERE CHAR_LENGTH(observacion) >= 355;
--     -> ~116 filas, y ninguna vuelve a fallar al guardar
--
--   -- Que no vuelvan a aparecer fallos nuevos:
--   SELECT COUNT(*), MAX(created_at) FROM error_log
--    WHERE error_message LIKE 'Data too long for column ''observacion''%';
--     -> el MAX debe quedarse anclado en la fecha de aplicacion de este script

-- 41_utf8mb4_observacion.sql --- que la observacion acepte emojis sin perder la gestion
--
-- QUE HACE
-- --------
-- Convierte SOLO la columna `formularioQuestion.observacion` a utf8mb4. No toca la tabla
-- entera ni ninguna otra columna.
--
--
-- EL FALLO QUE CIERRA (produccion, 28-09-2026)
-- --------------------------------------------
-- Un ejecutor escribio un emoji al gestionar un local y la gestion NO se guardo:
--
--     endpoint    procesar_gestion_pruebas.php
--     error_code  TRANSACTION_FAILED
--     mensaje     Conversion from collation utf8mb4_0900_ai_ci into
--                 utf8mb3_unicode_ci impossible for parameter
--
-- con_.php abre la conexion en `utf8` (= utf8mb3) y procesar_gestion_pruebas.php la cambia
-- a utf8mb4 en la linea 90 --- con razon, porque `gestion_visita` SI es utf8mb4. Desde ahi
-- todo parametro de texto viaja como utf8mb4, y al enlazarlo contra una columna utf8mb3
-- MySQL intenta convertir. Un emoji ocupa 4 bytes y utf8mb3 llega a 3: no puede, y aborta
-- la transaccion entera. TRANSACTION_FAILED sale como 5xx, que offline-queue.js clasifica
-- como reintentable, asi que ese trabajo reintenta para siempre sin entrar nunca.
--
-- Es el hallazgo V-32 (utf8mb3 -> utf8mb4) mordiendo en produccion. Medido: 62 de 108
-- tablas siguen en utf8mb3.
--
--
-- POR QUE SOLO LA COLUMNA Y NO LA TABLA
-- -------------------------------------
-- Convertir `formularioQuestion` entera es innecesariamente arriesgado. Se reviso:
--
--   * `formularioQuestion.material` se compara contra `material.nombre` en 9 consultas
--     (gestionarPruebas.php, procesar_gestion*.php, obtener_materiales.php,
--     recepcion_materiales.php, gestion_bootstrap_mobile.php...). `material` sigue en
--     utf8mb3_unicode_ci, asi que convertir un lado y no el otro arriesga un
--     "Illegal mix of collations" en todas ellas.
--
--   * `observacion`, en cambio, NO se compara contra ninguna columna: solo contra literales
--     (dashboard_campana.php hace LIKE '%local_cerrado%') y se asigna = ''. Un literal toma
--     la colacion de la conexion y se acomoda solo. Tampoco lleva indice.
--
-- O sea: convertir esta columna no puede romper ninguna consulta. Convertir la tabla, si.
--
-- Se usa utf8mb4_unicode_ci y NO el utf8mb4_0900_ai_ci que MySQL 8 pone por defecto: al
-- quedar en la misma familia de colacion que el resto de la tabla, se reduce el riesgo de
-- mezcla si en el futuro alguien compara esta columna con otra.
--
--
-- POR QUE EL ALTER SE ARMA SOLO
-- -----------------------------
-- `MODIFY COLUMN` obliga a repetir la definicion COMPLETA, y el largo de esta columna esta
-- en disputa: la auditoria la vio en varchar(360), scripts/40 la deja en varchar(2000) y en
-- produccion al 28-09-2026 estaba en varchar(600). Escribir el largo a mano truncaria datos
-- en cuanto uno de esos tres ambientes no coincida. Asi que se lee de information_schema y
-- se conserva tal cual, igual que el NOT NULL.
--
--
-- COSTE Y CUANDO CORRERLO
-- -----------------------
-- Cambiar el juego de caracteres de una columna NO admite ALGORITHM=INPLACE: MySQL copia la
-- tabla entera. Son ~690.000 filas, del orden de decenas de segundos, CON BLOQUEO.
--
--   >> Correr FUERA de jornada. Con ejecutores en terreno, durante la copia las gestiones
--   >> fallan. Nunca despues de las 15:00, que es cuando sincronizan.
--
-- El saneado en PHP (lib/dominio/texto_utf8mb3.php) ya evita el fallo sin esta migracion,
-- asi que esto NO es urgente: se puede correr el fin de semana. Lo que gana es que el emoji
-- se guarde en vez de descartarse.
--
--
-- LO QUE ESTA MIGRACION **NO** ARREGLA
-- ------------------------------------
-- `form_question_responses.answer_text` tambien esta en utf8mb3 y tambien recibe texto libre
-- del ejecutor (preguntas de tipo texto). No se toca aca porque:
--
--   * son 9.498.425 filas --- esto ya no son segundos, es ventana de mantenimiento, y
--     conviene pt-online-schema-change;
--   * `answer_text_norm` es una columna GENERATED ALWAYS AS (lower(trim(answer_text)))
--     STORED e indexada: hay que convertir las dos A LA VEZ o la generada terminaria
--     escribiendo utf8mb4 dentro de una columna utf8mb3;
--   * hay tres indices de prefijo sobre ellas --- answer_text(100), answer_text(50) y
--     answer_text_norm(50) ---. En utf8mb4 quedan en 400, 200 y 200 bytes, muy por debajo
--     del tope de 3.072, asi que por ahi no hay problema. El problema es el tamano.
--
-- Hasta entonces, el saneado de PHP cubre ese camino.
--
--
-- REVERSION
-- ---------
--   ALTER TABLE formularioQuestion
--     MODIFY observacion VARCHAR(<largo>) CHARACTER SET utf8mb3
--            COLLATE utf8mb3_unicode_ci NOT NULL;
--   (los emojis ya guardados se perderian: por eso no se revierte despues de usarse)


-- ---------------------------------------------------------------------------
-- Comprobacion previa: que es lo que hay ahora
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME,
       COLLATION_NAME, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'formularioQuestion'
   AND COLUMN_NAME  = 'observacion';


-- ---------------------------------------------------------------------------
-- La conversion. Reidempotente: si ya esta en utf8mb4 no hace nada.
-- ---------------------------------------------------------------------------
SET @cs = (SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'formularioQuestion'
              AND COLUMN_NAME = 'observacion');

SET @largo = (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'formularioQuestion'
                 AND COLUMN_NAME = 'observacion');

SET @nulo = (SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'formularioQuestion'
                AND COLUMN_NAME = 'observacion');

SET @sql = IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE formularioQuestion MODIFY `observacion` VARCHAR(', @largo, ')',
         ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
         IF(@nulo = 'NO', ' NOT NULL', ' NULL')),
  'DO 0');

PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SELECT CHARACTER_SET_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'formularioQuestion'
--      AND COLUMN_NAME = 'observacion';
--     -> utf8mb4 / utf8mb4_unicode_ci / el MISMO largo de antes / el MISMO NOT NULL
--
--   -- Que las 9 consultas que cruzan material sigan funcionando (no deberian verse
--   -- afectadas, pero vale la pena confirmarlo en vez de suponerlo):
--   SELECT COUNT(*) FROM formularioQuestion fq
--     LEFT JOIN material m ON m.nombre = fq.material
--    WHERE fq.id_formulario > 0 LIMIT 1;
--     -> debe responder sin "Illegal mix of collations"
--
--   -- Cuantas gestiones se habian perdido por esto:
--   SELECT DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT user_id) usuarios
--     FROM error_log
--    WHERE error_message LIKE '%Conversion from collation%'
--    GROUP BY d ORDER BY d DESC;

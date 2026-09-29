-- 38_ruta_plan.sql — dejar utilizables las tablas `ruta` y `ruta_parada`
--
-- QUE HACE
-- --------
-- Agrega `ruta.modo ENUM('prog','reag')` y la clave unica (usuario_id, fecha, modo), que es
-- lo que permite guardar la ruta planificada sin duplicarla en cada recalculo.
--
--
-- POR QUE (hallazgo C-2 de la auditoria)
-- --------------------------------------
-- Las dos tablas YA EXISTEN y estan bien disenadas. Se midio sobre el volcado del
-- 2026-09-03:
--
--     ruta          2 filas    ruta_parada   2 filas
--
-- y ninguna linea de app/, portal/, portal2/ ni scripts/ las lee o las escribe (los aciertos
-- de un grep por "ruta" son `ruta_set` y `rutas_excel_sets`, que son las asignaciones del
-- portal, otra cosa). En el historial de git la cadena "ruta_parada" solo aparece dentro de
-- los propios volcados: nunca hubo un escritor versionado.
--
-- Las 2 filas son del 2025-08-26, del usuario 50, ambas 'en_progreso' y nunca finalizadas,
-- cada una con una sola parada al mismo local. El AUTO_INCREMENT esta en 11, o sea que los
-- ids 1 a 8 ya se habian borrado a mano. Es un experimento abandonado.
--
-- Lo valioso es el esquema, que se adelanta a media Tanda 7:
--     ruta_parada.priority     -> V-28 (prioridad en la funcion de costo)
--     ruta_parada.service_min  -> V-28 (tiempo de servicio)
--     ruta_parada.ventana_ini/fin -> V-28 (ventanas horarias; las alimenta
--                                 cuenta.hora_apertura, scripts/37)
--     ruta_parada.eta          -> estimacion de cierre de jornada
--     ruta.finished_at/estado  -> B-1 (cierre de jornada verificado)
--
-- Esta migracion no usa ninguna de esas columnas todavia. Solo abre la puerta.
--
--
-- POR QUE HACE FALTA UNA COLUMNA `modo`
-- -------------------------------------
-- La ruta se planifica sobre los locales marcados de UNA de las dos listas: programados o
-- reagendados. Lo decide window.modoLocal, y routeStateHash lo incluye como entrada, o sea
-- que para la app "prog del martes" y "reag del martes" son dos rutas distintas.
--
-- El esquema original no lo contemplaba: tiene (usuario_id, fecha) y nada mas. Sin `modo`,
-- planificar los reagendados pisaria la ruta de los programados del mismo dia.
--
-- La alternativa era guardar UNA ruta por dia con la union de las dos listas. Se descarto:
-- la app no planifica asi, y guardar una union que nadie calculo seria inventar un dato.
--
--
-- ATENCION: ESTA MIGRACION BORRA FILAS
-- ------------------------------------
-- Es la unica de todo el plan que lo hace, asi que va explicito.
--
-- La clave unica no se puede crear si hay duplicados, y los hay: las dos filas existentes
-- son del MISMO usuario (50) y la MISMA fecha (2025-08-30), asi que al ponerles modo='prog'
-- por defecto colisionan entre si.
--
-- El bloque de mas abajo borra, de cada grupo (usuario_id, fecha, modo) duplicado, todas las
-- filas menos la de id mas alto. Con los datos de hoy eso es EXACTAMENTE 1 fila de `ruta`
-- (la id 9) y su unica parada. Se protege ademas a cualquier ruta 'finalizada', que no se
-- toca nunca.
--
-- Es seguro porque nada lee estas tablas — se verifico arriba —, pero si preferis no borrar
-- nada, corre solo el ALTER de la columna `modo` y deja el indice unico fuera: el endpoint
-- app/api/ruta_plan_guardar.php resuelve el upsert dentro de una transaccion y funciona
-- igual, solo que sin la red de seguridad de la base.
--
--
-- COSTE
-- -----
-- 2 filas. Instantaneo.
--
-- REVERSION
-- ---------
--   ALTER TABLE ruta DROP INDEX uq_ruta_usuario_fecha_modo;
--   ALTER TABLE ruta DROP COLUMN modo;
--   (las 2 filas de prueba borradas no se recuperan; no las lee nadie)


-- ---------------------------------------------------------------------------
-- 1. La columna `modo`
-- ---------------------------------------------------------------------------
SET @existe_modo = (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'ruta'
                       AND COLUMN_NAME = 'modo');
SET @sql = IF(@existe_modo = 0,
  "ALTER TABLE ruta
     ADD COLUMN `modo` ENUM('prog','reag') NOT NULL DEFAULT 'prog'
     COMMENT 'Lista sobre la que se planifico: programados o reagendados. Ver scripts/38.'",
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2. Colapsar duplicados para poder crear la clave unica
--    Se conserva el id mas alto de cada grupo. Las rutas finalizadas quedan intactas.
--    Primero las paradas (dependen de ruta_id), despues la ruta.
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_ruta_sobrantes AS
SELECT r.id
  FROM ruta r
  JOIN (SELECT usuario_id, fecha, modo, MAX(id) AS conservar
          FROM ruta
         GROUP BY usuario_id, fecha, modo
        HAVING COUNT(*) > 1) d
    ON d.usuario_id = r.usuario_id
   AND d.fecha      = r.fecha
   AND d.modo       = r.modo
 WHERE r.id <> d.conservar
   AND r.estado <> 'finalizada';

DELETE FROM ruta_parada WHERE ruta_id IN (SELECT id FROM tmp_ruta_sobrantes);
DELETE FROM ruta        WHERE id      IN (SELECT id FROM tmp_ruta_sobrantes);

DROP TEMPORARY TABLE IF EXISTS tmp_ruta_sobrantes;


-- ---------------------------------------------------------------------------
-- 3. La clave unica: una ruta por usuario, fecha y modo
-- ---------------------------------------------------------------------------
SET @existe_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'ruta'
                      AND INDEX_NAME = 'uq_ruta_usuario_fecha_modo');
SET @sql = IF(@existe_idx = 0,
  "ALTER TABLE ruta
     ADD UNIQUE KEY `uq_ruta_usuario_fecha_modo` (`usuario_id`,`fecha`,`modo`)",
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SHOW COLUMNS FROM ruta LIKE 'modo';
--     -> enum('prog','reag'), NO, DEFAULT 'prog'
--
--   SHOW INDEX FROM ruta WHERE Key_name = 'uq_ruta_usuario_fecha_modo';
--     -> 3 filas (usuario_id, fecha, modo), Non_unique = 0
--
--   SELECT COUNT(*) FROM ruta;          -- 1  (quedaba la id 10)
--   SELECT COUNT(*) FROM ruta_parada;   -- 1  (quedaba la id 26)
--
--   -- Y que no haya quedado ninguna parada huerfana:
--   SELECT COUNT(*) FROM ruta_parada p
--    WHERE NOT EXISTS (SELECT 1 FROM ruta r WHERE r.id = p.ruta_id);
--     -> 0

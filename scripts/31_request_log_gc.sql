-- 31_request_log_gc.sql — un indice para que la limpieza de request_log no barra la tabla
--
-- CONTEXTO
-- --------
-- La limpieza de idempotencia (idempo_gc) hace tres cosas:
--
--   1. UPDATE ... WHERE status='processing' AND timeout_at < NOW()
--   2. DELETE ... WHERE status IN ('pending','failed') AND created_at < NOW() - 2 DAY
--   3. DELETE ... WHERE status='completed' AND completed_at < NOW() - 30 DAY
--
-- Las dos primeras ya estan cubiertas por el indice `idx_timeout_cleanup (status,
-- timeout_at)` que la tabla YA tiene: `status` es su columna mas a la izquierda, y las
-- filas en 'processing', 'pending' o 'failed' son un punado en cualquier momento.
--
-- La tercera es la cara: 'completed' es la inmensa mayoria de la tabla, asi que el motor
-- recorre practicamente todas las filas para mirarles el `completed_at`.
--
-- POR QUE SOLO UN INDICE, Y NO LOS TRES QUE PEDIA EL PLAN
-- -------------------------------------------------------
-- Porque `request_log` se escribe en la ruta caliente: una fila por cada peticion
-- idempotente de la app. Cada indice de mas es trabajo en CADA gestion y CADA foto. Con
-- la migracion 29 acabamos de quitar 76 indices redundantes de la base; anadir dos que no
-- hacen falta seria deshacer ese trabajo en la tabla equivocada.
--
-- Este indice si hace falta, y solo este.
--
-- TAMANO REAL DE LA TABLA (volcado del 2026-09-03): 76.566 filas.
-- No es enorme —el plan decia 560 K, y era falso— pero un recorrido completo no tiene
-- por que ocurrir nunca dentro de una peticion con un ejecutor esperando. El indice, mas
-- el paso de la limpieza a cron (scripts/cron_gc.php), lo sacan del camino.

DROP PROCEDURE IF EXISTS v2_add_index;
DELIMITER $$
CREATE PROCEDURE v2_add_index(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = p_tabla
                    AND INDEX_NAME   = p_indice) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD INDEX `', p_indice, '` (', p_cols, ')');
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- Cubre:  DELETE FROM request_log WHERE status='completed' AND completed_at < ?
CALL v2_add_index('request_log', 'idx_rl_status_completed', '`status`, `completed_at`');

DROP PROCEDURE IF EXISTS v2_add_index;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   EXPLAIN DELETE FROM request_log
--    WHERE status = 'completed' AND completed_at < NOW() - INTERVAL 30 DAY;
--
-- En la columna `key` debe aparecer idx_rl_status_completed, y `rows` debe ser el numero
-- de filas viejas, no el total de la tabla. Si sigue diciendo NULL o PRIMARY, el indice
-- no se creo o el optimizador tiene estadisticas viejas: ANALYZE TABLE request_log;
--
-- Nota: ADD INDEX si reconstruye el indice sobre las filas existentes. Con 76 K filas es
-- cuestion de segundos, pero es la unica operacion de la tanda 5 que no es instantanea.

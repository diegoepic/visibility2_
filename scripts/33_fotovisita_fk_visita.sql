-- 33_fotovisita_fk_visita.sql — clave foranea fotoVisita -> visita
--
-- QUE HACE, EN UNA LINEA
-- ----------------------
-- Convierte los `visita_id = 0` en NULL y pone la clave foranea, para que a partir de hoy
-- sea imposible tener evidencia fotografica colgando de una visita que no existe.
--
--
-- POR QUE HAY 122.066 CEROS
-- -------------------------
-- Son fotos anteriores a que existiera la tabla `visita`. No es corrupcion: es historia de
-- antes de que el modelo tuviera visitas. El valor 0 era la forma de decir "esta foto no
-- pertenece a ninguna visita", que en SQL se escribe NULL.
--
-- Al pasarlos a NULL el significado no cambia — se hace explicito. Y ademas es lo que
-- permite poner la FK: una columna NOT NULL con ceros no puede tener clave foranea, porque
-- no existe ninguna visita con id 0.
--
-- El analisis completo y las decisiones que llevaron aqui estan en
-- scripts/35_integridad_diagnostico.sql (solo lectura).
--
-- Recuento sobre el volcado del 2026-09-03:
--     238.686 filas en fotoVisita
--     122.066 con visita_id = 0   -> pasan a NULL (historia pre-visita)
--          41 apuntando a una visita inexistente -> pasan a NULL (no se puede saber cual era)
--     116.579 correctas -> no se tocan
--
--
-- POR QUE ESTO NO ROMPE NINGUNA CONSULTA
-- --------------------------------------
-- Se reviso el codigo antes de escribir esto:
--   * Todos los cruces con visita son de la forma `ON v.id = fv.visita_id`. Con 0 NUNCA
--     casaban (no hay visita con id 0) y con NULL tampoco casan. Mismo resultado.
--   * El unico sitio que compara la columna contra 0 es
--     portal/modulos/mod_vehiculos/piloto_odometro/lib_piloto.php:348, y usa `> 0`, que con
--     NULL da falso igual que con 0.
--   * Los indices sobre visita_id no son UNIQUE, asi que los NULL no molestan.
--
--
-- COSTE
-- -----
-- 238 K filas. El MODIFY reconstruye la tabla (MySQL 8.4 lo hace INPLACE y admite lecturas
-- y escrituras mientras tanto). Cuestion de segundos. No hace falta ventana.
--
-- REVERSION
-- ---------
--   ALTER TABLE fotoVisita DROP FOREIGN KEY fk_fv_visita;
--   UPDATE fotoVisita SET visita_id = 0 WHERE visita_id IS NULL;
--   ALTER TABLE fotoVisita MODIFY visita_id INT NOT NULL;

DROP PROCEDURE IF EXISTS v2_paso;
DELIMITER $$
CREATE PROCEDURE v2_paso(IN p_sql TEXT)
BEGIN
  SET @s = p_sql;
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$
DELIMITER ;

-- 1) La columna admite NULL. Solo si todavia no lo admite.
SET @nulable = (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'fotoVisita' AND COLUMN_NAME = 'visita_id');
SET @sql = IF(@nulable = 'NO',
              'ALTER TABLE fotoVisita MODIFY `visita_id` INT NULL',
              'DO 0');
CALL v2_paso(@sql);

-- 2) Los ceros historicos pasan a NULL.
UPDATE fotoVisita SET visita_id = NULL WHERE visita_id = 0;

-- 3) Las que apuntan a una visita que ya no existe, tambien. No se puede saber cual era,
--    y dejarlas apuntando a un id fantasma impide poner la FK.
UPDATE fotoVisita f
  LEFT JOIN visita v ON v.id = f.visita_id
   SET f.visita_id = NULL
 WHERE f.visita_id IS NOT NULL AND v.id IS NULL;

-- 4) La clave foranea. RESTRICT en las dos direcciones: una visita con evidencia
--    fotografica no se borra nunca, ni por accidente ni en cascada.
SET @existe = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'fotoVisita'
                  AND CONSTRAINT_NAME = 'fk_fv_visita');
SET @sql = IF(@existe = 0,
              'ALTER TABLE fotoVisita ADD CONSTRAINT fk_fv_visita
                 FOREIGN KEY (visita_id) REFERENCES visita(id)
                 ON DELETE RESTRICT ON UPDATE RESTRICT',
              'DO 0');
CALL v2_paso(@sql);

DROP PROCEDURE IF EXISTS v2_paso;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SELECT COUNT(*) AS ceros_restantes FROM fotoVisita WHERE visita_id = 0;
--     -> debe dar 0
--
--   SELECT COUNT(*) AS sin_visita FROM fotoVisita WHERE visita_id IS NULL;
--     -> debe dar ~122.107 (los 122.066 ceros + los 41 huerfanos)
--
--   SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fotoVisita'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';
--     -> debe aparecer fk_fv_visita

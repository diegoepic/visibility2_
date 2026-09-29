-- 36_estado_gestion.sql — el estado de la gestion, en una columna que se llama como lo que guarda
--
-- QUE HACE
-- --------
-- Agrega `formularioQuestion.estado_gestion` (ENUM) y la puebla desde `pregunta`.
-- NO borra ni cambia `pregunta`: las dos columnas conviven hasta que todos los lectores
-- migren. Es aditiva a proposito — el codigo desplegado hoy sigue funcionando igual.
--
--
-- POR QUE
-- -------
-- El estado de una gestion vive en una columna llamada `pregunta` (hallazgo V-30). Sobre
-- el volcado del 2026-09-03, sus 674.020 filas contienen ONCE valores distintos:
--
--     'solo_auditoria'         165.252
--     'solo_implementado'      153.094
--     ''                       130.861   <- sin gestionar
--     'implementado_auditado'  115.662
--     'en proceso'              69.235
--     'cancelado'               37.652
--     'solo_retirado'            1.584
--     ' '                          489   <- un espacio suelto. Tampoco es un estado.
--     NULL                         127
--     'completado'                  59   <- legacy: ningun camino de escritura lo produce
--     'no_implementado'              5   <- legacy: la app lo escribe en gestion_visita
--
-- Tres formas distintas de decir "sin gestionar" ('', ' ' y NULL) y dos valores que ya
-- nadie escribe. El ENUM deja eso explicito y de una sola forma.
--
--
-- POR QUE NO SE FUSIONA CON etapa_material
-- ----------------------------------------
-- Porque no son lo mismo contado dos veces. `pregunta` describe como termino la VISITA al
-- local; `etapa_material` en que punto va ESE material. Hay filas reales con
--
--     pregunta='cancelado'  x etapa_material='retirado'      24
--     pregunta='en proceso' x etapa_material='implementado'  11
--     pregunta='cancelado'  x etapa_material='implementado'   7
--
-- que no son contradicciones: un local se puede cancelar despues de retirar un material.
-- Colapsarlas en un solo valor perderia informacion. `etapa_material` no se toca (esta en
-- NULL en 673.322 de 674.020 filas: solo 698 usan la modalidad por etapas).
--
--
-- LOS VALORES LEGACY SE CONSERVAN
-- -------------------------------
-- 'completado' (59) y 'no_implementado' (5) entran en el ENUM aunque ningun codigo actual
-- los escriba. Una migracion no puede perder 64 filas para que el ENUM quede mas limpio.
--
--
-- COSTE
-- -----
-- 674.020 filas. En MySQL 8.4 un `ADD COLUMN` con valor por defecto es INSTANT (solo
-- metadatos). El UPDATE que la puebla si recorre la tabla, por eso va por lotes con una
-- pausa entre medias, igual que scripts/34. Cuestion de un par de minutos, sin ventana.
--
-- REVERSION
-- ---------
--   ALTER TABLE formularioQuestion DROP COLUMN estado_gestion;
--   (nada mas: `pregunta` no se toco)
--
-- DESPUES DE ESTO
-- ---------------
-- El lector unico esta en app/lib/dominio/estado_gestion.php. Mientras haya ambientes sin
-- esta migracion, `estado_gestion_sql()` normaliza desde `pregunta` y da exactamente el
-- mismo resultado que la columna nueva — eso es lo que permite desplegar codigo y correr
-- la migracion en momentos distintos. Cuando la columna este en todos lados, esa funcion
-- puede pasar a leerla directo.
--
-- Los ~20 exports del portal que derivan el estado con su propio CASE NO se tocan acá:
-- cada uno usa un vocabulario distinto ('IMPLEMENTADO' vs 'implementado' vs 'IMPLE/AUDI')
-- y unificarlos cambia lo que el cliente ve en sus planillas. Eso es una decision de
-- producto, no un efecto colateral de esta migracion.

DROP PROCEDURE IF EXISTS v2_paso;
DROP PROCEDURE IF EXISTS v2_poblar_estado_gestion;

DELIMITER $$

CREATE PROCEDURE v2_paso(IN p_sql TEXT)
BEGIN
  SET @s = p_sql;
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

-- 674 K filas de golpe bloquean la tabla demasiado tiempo. Por lotes, cada transaccion es
-- corta y las escrituras de la app siguen pasando entre medias.
CREATE PROCEDURE v2_poblar_estado_gestion()
BEGIN
  DECLARE v_filas INT DEFAULT 1;
  DECLARE v_vueltas INT DEFAULT 0;
  WHILE v_filas > 0 AND v_vueltas < 300 DO
    UPDATE formularioQuestion
       SET estado_gestion = CASE
             WHEN LOWER(TRIM(COALESCE(pregunta,''))) = '' THEN 'sin_gestionar'
             WHEN LOWER(TRIM(pregunta)) IN ('en proceso','pendiente') THEN 'pendiente'
             WHEN LOWER(TRIM(pregunta)) = 'cancelado' THEN 'cancelado'
             WHEN LOWER(TRIM(pregunta)) = 'solo_auditoria' THEN 'solo_auditoria'
             WHEN LOWER(TRIM(pregunta)) = 'solo_implementado' THEN 'solo_implementado'
             WHEN LOWER(TRIM(pregunta)) = 'solo_retirado' THEN 'solo_retirado'
             WHEN LOWER(TRIM(pregunta)) = 'implementado_auditado' THEN 'implementado_auditado'
             WHEN LOWER(TRIM(pregunta)) = 'no_implementado' THEN 'no_implementado'
             WHEN LOWER(TRIM(pregunta)) = 'completado' THEN 'completado'
             ELSE 'sin_gestionar'
           END
     WHERE estado_gestion IS NULL
     LIMIT 5000;
    SET v_filas = ROW_COUNT();
    SET v_vueltas = v_vueltas + 1;
    DO SLEEP(0.05);
  END WHILE;
END$$

DELIMITER ;

-- 1) La columna. NULL por defecto para poder distinguir "todavia no poblada" de
--    "poblada como sin_gestionar" mientras corre el backfill.
SET @existe = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'formularioQuestion'
                  AND COLUMN_NAME = 'estado_gestion');
SET @sql = IF(@existe = 0,
  "ALTER TABLE formularioQuestion
     ADD COLUMN `estado_gestion` ENUM(
       'sin_gestionar','pendiente','cancelado','solo_auditoria','solo_implementado',
       'solo_retirado','implementado_auditado','no_implementado','completado'
     ) NULL DEFAULT NULL COMMENT 'Estado de la VISITA. etapa_material es el otro eje. Ver scripts/36.'",
  'DO 0');
CALL v2_paso(@sql);

-- 2) Poblarla desde `pregunta`, por lotes.
CALL v2_poblar_estado_gestion();

-- 3) Indice para agrupar y filtrar por estado. Va DESPUES del backfill: crearlo antes
--    obliga a mantenerlo durante el UPDATE de 674 K filas.
SET @existe = (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'formularioQuestion'
                  AND INDEX_NAME = 'idx_fq_estado_gestion');
SET @sql = IF(@existe = 0,
  'ALTER TABLE formularioQuestion ADD INDEX idx_fq_estado_gestion (id_formulario, estado_gestion)',
  'DO 0');
CALL v2_paso(@sql);

DROP PROCEDURE IF EXISTS v2_paso;
DROP PROCEDURE IF EXISTS v2_poblar_estado_gestion;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   -- No debe quedar ninguna sin poblar:
--   SELECT COUNT(*) FROM formularioQuestion WHERE estado_gestion IS NULL;   -> 0
--
--   -- Debe coincidir con el recuento de `pregunta` de arriba:
--   SELECT estado_gestion, COUNT(*) FROM formularioQuestion
--    GROUP BY estado_gestion ORDER BY 2 DESC;
--
--   -- Y sobre todo: la columna nueva y la normalizacion de la vieja deben decir lo
--   -- mismo en TODAS las filas. Si esto no da 0, algo se poblo mal.
--   SELECT COUNT(*) FROM formularioQuestion
--    WHERE estado_gestion <> CASE
--            WHEN LOWER(TRIM(COALESCE(pregunta,''))) = '' THEN 'sin_gestionar'
--            WHEN LOWER(TRIM(pregunta)) IN ('en proceso','pendiente') THEN 'pendiente'
--            WHEN LOWER(TRIM(pregunta)) IN ('cancelado','solo_auditoria','solo_implementado',
--                 'solo_retirado','implementado_auditado','no_implementado','completado')
--                 THEN LOWER(TRIM(pregunta))
--            ELSE 'sin_gestionar'
--          END;                                                            -> 0

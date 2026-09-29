-- 34_fqr_fk_visita.sql — clave foranea form_question_responses -> visita
--
-- ⚠ ESTA ES LA CARA. LEE LA SECCION "MERECE LA PENA?" ANTES DE EJECUTARLA.
--
--
-- QUE HACE
-- --------
-- Lo mismo que la 33 pero sobre la tabla grande: convierte los `visita_id = 0` en NULL y
-- pone la clave foranea hacia `visita`.
--
-- El analisis completo y las decisiones que llevaron aqui estan en
-- scripts/35_integridad_diagnostico.sql (solo lectura).
--
-- Recuento sobre el volcado del 2026-09-03:
--     9.362.552 filas
--     1.515.410 con visita_id = 0   -> pasan a NULL (respuestas sin visita asociada)
--             0 huerfanas de verdad  <-- CERO. El resto de los datos ya esta limpio.
--
--
-- MERECE LA PENA?
-- ---------------
-- Hay que decidirlo con esto delante, porque no es obvio:
--
--   A FAVOR: la columna es lo que ata una respuesta de encuesta a la visita en que se
--            respondio. Sin la FK, nada impide que manana aparezcan respuestas colgando de
--            visitas inexistentes, que es exactamente lo que le paso a `formularioQuestion`
--            (56 K filas apuntando a locales que ya no estan).
--
--   EN CONTRA: hoy hay CERO huerfanas. La FK no arregla nada existente; solo blinda el
--            futuro. Y el precio es alto: el MODIFY reconstruye una tabla de 9,4 millones
--            de filas y ~877 MB. MySQL 8.4 lo hace INPLACE y admite lecturas y escrituras
--            durante el proceso, pero es mucha E/S en un hosting compartido, y son varios
--            minutos.
--
-- RECOMENDACION: no la ejecutes sola. Si algun dia abres ventana de mantenimiento por otra
-- cosa, metela ahi. Mientras tanto, la vigilancia nocturna de scripts/cron_gc.php avisa si
-- empiezan a aparecer huerfanas nuevas, que es la senal de que esto pasa a ser urgente.
--
--
-- ANTES DE EJECUTAR
-- -----------------
--   1. Respaldo de la tabla, o de la base entera.
--   2. Comprobar que sigue habiendo cero huerfanas:
--        SELECT COUNT(*) FROM form_question_responses r
--          LEFT JOIN visita v ON v.id = r.visita_id
--         WHERE r.visita_id <> 0 AND v.id IS NULL;
--      Si NO da 0, para: hay que entender por que antes de seguir.
--   3. Hazlo de madrugada.
--
-- REVERSION
-- ---------
--   ALTER TABLE form_question_responses DROP FOREIGN KEY fk_fqr_visita;
--   UPDATE form_question_responses SET visita_id = 0 WHERE visita_id IS NULL;
--   ALTER TABLE form_question_responses MODIFY visita_id INT NOT NULL;
--   (la vuelta atras cuesta lo mismo que la ida: otro par de reconstrucciones)

DROP PROCEDURE IF EXISTS v2_paso;
DROP PROCEDURE IF EXISTS v2_ceros_a_null;

DELIMITER $$

CREATE PROCEDURE v2_paso(IN p_sql TEXT)
BEGIN
  SET @s = p_sql;
  PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END$$

-- 1,5 millones de filas de golpe bloquean la tabla demasiado tiempo. Por lotes, cada
-- transaccion es corta y las escrituras de la app siguen pasando entre medias.
CREATE PROCEDURE v2_ceros_a_null()
BEGIN
  DECLARE v_filas INT DEFAULT 1;
  DECLARE v_vueltas INT DEFAULT 0;
  WHILE v_filas > 0 AND v_vueltas < 500 DO
    UPDATE form_question_responses SET visita_id = NULL WHERE visita_id = 0 LIMIT 5000;
    SET v_filas = ROW_COUNT();
    SET v_vueltas = v_vueltas + 1;
    DO SLEEP(0.05);
  END WHILE;
END$$

DELIMITER ;

-- 1) La columna admite NULL (reconstruccion de la tabla: aqui se va el tiempo).
SET @nulable = (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'form_question_responses' AND COLUMN_NAME = 'visita_id');
SET @sql = IF(@nulable = 'NO',
              'ALTER TABLE form_question_responses MODIFY `visita_id` INT NULL',
              'DO 0');
CALL v2_paso(@sql);

-- 2) Los ceros pasan a NULL, por lotes.
CALL v2_ceros_a_null();

-- 3) Cualquier resto que apunte a una visita inexistente (deberia ser 0 filas).
UPDATE form_question_responses r
  LEFT JOIN visita v ON v.id = r.visita_id
   SET r.visita_id = NULL
 WHERE r.visita_id IS NOT NULL AND v.id IS NULL;

-- 4) La clave foranea.
SET @existe = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'form_question_responses'
                  AND CONSTRAINT_NAME = 'fk_fqr_visita');
SET @sql = IF(@existe = 0,
              'ALTER TABLE form_question_responses ADD CONSTRAINT fk_fqr_visita
                 FOREIGN KEY (visita_id) REFERENCES visita(id)
                 ON DELETE RESTRICT ON UPDATE RESTRICT',
              'DO 0');
CALL v2_paso(@sql);

DROP PROCEDURE IF EXISTS v2_paso;
DROP PROCEDURE IF EXISTS v2_ceros_a_null;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SELECT COUNT(*) FROM form_question_responses WHERE visita_id = 0;   -> 0
--   SELECT COUNT(*) FROM form_question_responses WHERE visita_id IS NULL;  -> ~1.515.410
--   SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_question_responses'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';   -> fk_fqr_visita

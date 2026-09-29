-- 29_indices_duplicados.sql — quitar los indices EXACTAMENTE duplicados
--
-- Que es un duplicado exacto: dos indices sobre la MISMA lista de columnas en el MISMO
-- orden. El segundo no aporta ningun plan de ejecucion nuevo; solo se escribe en cada
-- INSERT, UPDATE y DELETE, y ocupa disco. `formularioQuestion(id_formulario)` existe
-- CUATRO veces con nombres distintos. `local(id)` tiene tres indices sobre su propia
-- clave primaria.
--
-- En total: 76 indices duplicados en 24 tablas.
--
-- POR QUE ES SEGURO
--   * De cada grupo se conserva SIEMPRE uno. Nunca se toca PRIMARY, y si en el grupo hay
--     un UNIQUE se conserva el UNIQUE (lleva una restriccion, no solo un indice).
--   * Se comprobo una por una que las 28 claves foraneas de la base siguen teniendo un
--     indice que empieza por su columna. MySQL rechaza (errno 1553) un DROP que dejaria
--     una FK sin indice; aqui no pasa en ninguna.
--   * En InnoDB, DROP INDEX no reconstruye la tabla: es un cambio de metadatos. No hace
--     falta ventana de mantenimiento para este archivo.
--
-- ANTES DE EJECUTAR — guardate la vuelta atras:
--   SHOW CREATE TABLE form_question_responses\G
--   SHOW CREATE TABLE formularioQuestion\G
--   SHOW CREATE TABLE local\G
--   (recrear un indice es un ALTER: lento en 9 M filas, pero seguro)
--
-- MEDIR, no intuir. Antes y despues:
--   SELECT TABLE_NAME, ROUND(DATA_LENGTH/1024/1024) AS datos_mb,
--          ROUND(INDEX_LENGTH/1024/1024) AS indices_mb
--     FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND TABLE_NAME IN ('form_question_responses','formularioQuestion','local','fotoVisita');
--
-- Generado el 2026-09-03 a partir del volcado visibility_visibility2 (35).sql


-- Ayudante: borra un indice solo si existe, para que el archivo se pueda relanzar.
-- MySQL no admite `DROP INDEX IF EXISTS`, y sin esto un segundo intento se cae con el
-- error 1091 y deja la migracion a medias.
-- Necesita permiso CREATE ROUTINE. Si el hosting no lo da, mira la nota del final.
DROP PROCEDURE IF EXISTS v2_drop_index;
DELIMITER $$
CREATE PROCEDURE v2_drop_index(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = p_tabla
                AND INDEX_NAME   = p_indice) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` DROP INDEX `', p_indice, '`');
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;


-- --------------------------------------------------------------------------
-- local  (12 indices)
-- --------------------------------------------------------------------------
--   se conserva: PRIMARY
-- id                                   (id)
CALL v2_drop_index('local','id');
--   se conserva: l_idx_comuna
-- idx_id_comuna                        (id_comuna)
CALL v2_drop_index('local','idx_id_comuna');
--   se conserva: idx_local_jv
-- idx_id_jefe_venta                    (id_jefe_venta)
CALL v2_drop_index('local','idx_id_jefe_venta');
--   se conserva: PRIMARY
-- idx_id_local                         (id)
CALL v2_drop_index('local','idx_id_local');
--   se conserva: idx_local_vend
-- idx_id_vendedor                      (id_vendedor)
CALL v2_drop_index('local','idx_id_vendedor');
--   se conserva: l_idx_cadena
-- idx_local_cadena                     (id_cadena)
CALL v2_drop_index('local','idx_local_cadena');
--   se conserva: l_idx_comuna
-- idx_local_comuna                     (id_comuna)
CALL v2_drop_index('local','idx_local_comuna');
--   se conserva: idx_id_distrito
-- idx_local_distrito                   (id_distrito)
CALL v2_drop_index('local','idx_local_distrito');
--   se conserva: idx_local_scope
-- idx_local_emp_div_distr_jv           (id_empresa,id_division,id_distrito,id_jefe_venta)
CALL v2_drop_index('local','idx_local_emp_div_distr_jv');
--   se conserva: idx_local_emp_div
-- idx_local_empresa_div                (id_empresa,id_division)
CALL v2_drop_index('local','idx_local_empresa_div');
--   se conserva: l_idx_cadena
-- idx_local_id_cadena                  (id_cadena)
CALL v2_drop_index('local','idx_local_id_cadena');
--   se conserva: idx_local_vend
-- l_idx_vendedor                       (id_vendedor)
CALL v2_drop_index('local','l_idx_vendedor');

-- --------------------------------------------------------------------------
-- form_question_responses  (11 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_fqr_q
-- id_form_question                     (id_form_question)
CALL v2_drop_index('form_question_responses','id_form_question');
--   se conserva: idx_fqr_created
-- idx_fqr_created_at                   (created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_created_at');
--   se conserva: idx_fqr_form_fecha
-- idx_fqr_form_question                (id_form_question,created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_form_question');
--   se conserva: idx_fqr_q
-- idx_fqr_formq                        (id_form_question)
CALL v2_drop_index('form_question_responses','idx_fqr_formq');
--   se conserva: idx_fqr_option
-- idx_fqr_id_option                    (id_option)
CALL v2_drop_index('form_question_responses','idx_fqr_id_option');
--   se conserva: idx_fqr_form_fecha
-- idx_fqr_pregunta_fecha               (id_form_question,created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_pregunta_fecha');
--   se conserva: idx_fqr_form_fecha
-- idx_fqr_question_created             (id_form_question,created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_question_created');
--   se conserva: idx_fqr_user
-- idx_fqr_usuario                      (id_usuario)
CALL v2_drop_index('form_question_responses','idx_fqr_usuario');
--   se conserva: idx_fqr_visita
-- idx_fqr_visita_id                    (visita_id)
CALL v2_drop_index('form_question_responses','idx_fqr_visita_id');
--   se conserva: idx_fqr_visit_local_q
-- idx_fqr_visita_local_pregunta        (visita_id,id_local,id_form_question)
CALL v2_drop_index('form_question_responses','idx_fqr_visita_local_pregunta');
--   se conserva: idx_visita_usuario
-- idx_fqr_visita_usuario               (visita_id,id_usuario)
CALL v2_drop_index('form_question_responses','idx_fqr_visita_usuario');

-- --------------------------------------------------------------------------
-- formularioQuestion  (10 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_fq_usuario_form
-- fq_idx_usuario_empresa               (id_usuario,id_formulario)
CALL v2_drop_index('formularioQuestion','fq_idx_usuario_empresa');
--   se conserva: id_formulario
-- idx_formularioQuestion_formulario    (id_formulario)
CALL v2_drop_index('formularioQuestion','idx_formularioQuestion_formulario');
--   se conserva: idx_id_local
-- idx_formularioQuestion_local         (id_local)
CALL v2_drop_index('formularioQuestion','idx_formularioQuestion_local');
--   se conserva: idx_id_usuario
-- idx_formularioQuestion_usuario       (id_usuario)
CALL v2_drop_index('formularioQuestion','idx_formularioQuestion_usuario');
--   se conserva: fq_idx_fecha
-- idx_fq_fecha                         (fechaPropuesta)
CALL v2_drop_index('formularioQuestion','idx_fq_fecha');
--   se conserva: fq_idx_fecha
-- idx_fq_fecha_propuesta               (fechaPropuesta)
CALL v2_drop_index('formularioQuestion','idx_fq_fecha_propuesta');
--   se conserva: id_formulario
-- idx_fq_formulario                    (id_formulario)
CALL v2_drop_index('formularioQuestion','idx_fq_formulario');
--   se conserva: fq_idx_pregunta
-- idx_fq_pregunta                      (pregunta)
CALL v2_drop_index('formularioQuestion','idx_fq_pregunta');
--   se conserva: idx_fq_usuario_form_local
-- idx_fq_usuario_formulario_local      (id_usuario,id_formulario,id_local)
CALL v2_drop_index('formularioQuestion','idx_fq_usuario_formulario_local');
--   se conserva: id_formulario
-- idx_id_formulario                    (id_formulario)
CALL v2_drop_index('formularioQuestion','idx_id_formulario');

-- --------------------------------------------------------------------------
-- form_questions  (7 indices)
-- --------------------------------------------------------------------------
--   se conserva: fq_form
-- idx_fq_form                          (id_formulario,sort_order)
CALL v2_drop_index('form_questions','idx_fq_form');
--   se conserva: idx_form_type
-- idx_fq_form_qtype                    (id_formulario,id_question_type)
CALL v2_drop_index('form_questions','idx_fq_form_qtype');
--   se conserva: fq_form
-- idx_fq_form_sort                     (id_formulario,sort_order)
CALL v2_drop_index('form_questions','idx_fq_form_sort');
--   se conserva: fq_form
-- idx_fq_formulario_sort               (id_formulario,sort_order)
CALL v2_drop_index('form_questions','idx_fq_formulario_sort');
--   se conserva: idx_fq_vsig
-- idx_fq_vsignature                    (v_signature)
CALL v2_drop_index('form_questions','idx_fq_vsignature');
--   se conserva: idx_fq_dependency_opt
-- idx_id_dependency_option             (id_dependency_option)
CALL v2_drop_index('form_questions','idx_id_dependency_option');
--   se conserva: idx_form_type
-- ix_fq_form_tipo                      (id_formulario,id_question_type)
CALL v2_drop_index('form_questions','ix_fq_form_tipo');

-- --------------------------------------------------------------------------
-- user_sessions  (6 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_seen
-- idx_last_seen                        (last_seen_at)
CALL v2_drop_index('user_sessions','idx_last_seen');
--   se conserva: user_id
-- idx_user                             (user_id)
CALL v2_drop_index('user_sessions','idx_user');
--   se conserva: uq_user_fpr
-- idx_user_fpr                         (user_id,session_fpr)
CALL v2_drop_index('user_sessions','idx_user_fpr');
--   se conserva: idx_revoked
-- idx_user_sessions_revoked            (revoked_at)
CALL v2_drop_index('user_sessions','idx_user_sessions_revoked');
--   se conserva: user_id
-- idx_user_sessions_user               (user_id)
CALL v2_drop_index('user_sessions','idx_user_sessions_user');
--   se conserva: uq_session_fpr
-- session_fpr                          (session_fpr)
CALL v2_drop_index('user_sessions','session_fpr');

-- --------------------------------------------------------------------------
-- fotoVisita  (4 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_fv_visita
-- fv_visita_only                       (visita_id)
CALL v2_drop_index('fotoVisita','fv_visita_only');
--   se conserva: idx_fv_visita
-- idx_fotoVisita_visita_id             (visita_id)
CALL v2_drop_index('fotoVisita','idx_fotoVisita_visita_id');
--   se conserva: idx_fv_visita
-- idx_fotov_visita                     (visita_id)
CALL v2_drop_index('fotoVisita','idx_fotov_visita');
--   se conserva: idx_fotov_fq
-- idx_fv_form_question                 (id_formularioQuestion)
CALL v2_drop_index('fotoVisita','idx_fv_form_question');

-- --------------------------------------------------------------------------
-- login_attempts  (3 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_user_time
-- idx_login_attempts_user_time         (user_id,intento_at)
CALL v2_drop_index('login_attempts','idx_login_attempts_user_time');
--   se conserva: outcome
-- idx_outcome                          (outcome)
CALL v2_drop_index('login_attempts','idx_outcome');
--   se conserva: idx_user_time
-- idx_userid_time                      (user_id,intento_at)
CALL v2_drop_index('login_attempts','idx_userid_time');

-- --------------------------------------------------------------------------
-- form_question_options  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_fqo_pregunta
-- idx_fqo_question                     (id_form_question)
CALL v2_drop_index('form_question_options','idx_fqo_question');
--   se conserva: idx_fqo_formq_sort
-- idx_fqo_question_sort                (id_form_question,sort_order)
CALL v2_drop_index('form_question_options','idx_fqo_question_sort');

-- --------------------------------------------------------------------------
-- form_question_photo_meta  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: resp_id
-- idx_fqpm_resp                        (resp_id)
CALL v2_drop_index('form_question_photo_meta','idx_fqpm_resp');
--   se conserva: resp_id
-- idx_photo_resp                       (resp_id)
CALL v2_drop_index('form_question_photo_meta','idx_photo_resp');

-- --------------------------------------------------------------------------
-- jefe_venta  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: nombre
-- idx_jefe_venta_nombre                (nombre)
CALL v2_drop_index('jefe_venta','idx_jefe_venta_nombre');
--   se conserva: nombre
-- nombre_2                             (nombre)
CALL v2_drop_index('jefe_venta','nombre_2');

-- --------------------------------------------------------------------------
-- security_events  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_user_type_time
-- idx_security_events_user_type_time   (user_id,type,created_at)
CALL v2_drop_index('security_events','idx_security_events_user_type_time');
--   se conserva: type
-- idx_type                             (type)
CALL v2_drop_index('security_events','idx_type');

-- --------------------------------------------------------------------------
-- usuario  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: PRIMARY
-- id                                   (id)
CALL v2_drop_index('usuario','id');
--   se conserva: idx_id_division
-- idx_usuario_div                      (id_division)
CALL v2_drop_index('usuario','idx_usuario_div');

-- --------------------------------------------------------------------------
-- visita  (2 indices)
-- --------------------------------------------------------------------------
--   se conserva: id_usuario
-- idx_visita_usuario_form_local        (id_usuario,id_formulario,id_local)
CALL v2_drop_index('visita','idx_visita_usuario_form_local');
--   se conserva: uq_visita_client_guid
-- uniq_visita_client_guid              (client_guid)
CALL v2_drop_index('visita','uniq_visita_client_guid');

-- --------------------------------------------------------------------------
-- comuna  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_id_region
-- idx_comuna_region                    (id_region)
CALL v2_drop_index('comuna','idx_comuna_region');

-- --------------------------------------------------------------------------
-- dim_campana_visibility  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: nombre_campana_individual
-- uk_nombre_campana_individual         (nombre_campana_individual)
CALL v2_drop_index('dim_campana_visibility','uk_nombre_campana_individual');

-- --------------------------------------------------------------------------
-- distrito  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_distrito_nombre
-- idx_nombre_distrito                  (nombre_distrito)
CALL v2_drop_index('distrito','idx_nombre_distrito');

-- --------------------------------------------------------------------------
-- formulario  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_formulario_scope
-- idx_form_empresa_div_tipo_deleted    (id_empresa,id_division,id_subdivision,tipo,deleted_at)
CALL v2_drop_index('formulario','idx_form_empresa_div_tipo_deleted');

-- --------------------------------------------------------------------------
-- gestion_visita  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: gv_form_local_fecha_id
-- idx_gv_form_local_fecha_id           (id_formulario,id_local,fecha_visita,id)
CALL v2_drop_index('gestion_visita','idx_gv_form_local_fecha_id');

-- --------------------------------------------------------------------------
-- material  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: ux_mat_nombre_div
-- ux_material_nombre_division          (nombre,id_division)
CALL v2_drop_index('material','ux_material_nombre_division');

-- --------------------------------------------------------------------------
-- question_set_options  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_qso_qsq_sort
-- idx_qso_question_sort                (id_question_set_question,sort_order)
CALL v2_drop_index('question_set_options','idx_qso_question_sort');

-- --------------------------------------------------------------------------
-- request_log  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: uniq_req
-- uk_idempo                            (idempotency_key,endpoint,user_id)
CALL v2_drop_index('request_log','uk_idempo');

-- --------------------------------------------------------------------------
-- subdivision  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: idx_subdivision_div
-- idx_subdivision_division             (id_division)
CALL v2_drop_index('subdivision','idx_subdivision_division');

-- --------------------------------------------------------------------------
-- user_security  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: PRIMARY
-- uq_user_security                     (user_id)
CALL v2_drop_index('user_security','uq_user_security');

-- --------------------------------------------------------------------------
-- zona  (1 indices)
-- --------------------------------------------------------------------------
--   se conserva: nombre_zona
-- nombre_zona_2                        (nombre_zona)
CALL v2_drop_index('zona','nombre_zona_2');
DROP PROCEDURE IF EXISTS v2_drop_index;

-- ---------------------------------------------------------------------------
-- Si el usuario de BD no tiene permiso CREATE ROUTINE
-- ---------------------------------------------------------------------------
-- Cambia cada linea  CALL v2_drop_index('tabla','indice');
-- por               ALTER TABLE `tabla` DROP INDEX `indice`;
-- y quita el bloque del procedimiento. Pierdes la reidempotencia: al relanzarlo,
-- los indices ya borrados daran error 1091, que en ese caso puedes ignorar.

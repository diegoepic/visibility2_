-- 30_indices_prefijos.sql — quitar los indices que son prefijo de otro
--
-- REQUIERE HABER APLICADO 29_indices_duplicados.sql ANTES.
--
-- Que es un prefijo redundante: un indice sobre (a) cuando ya existe otro sobre (a,b).
-- InnoDB puede usar el segundo para todo lo que servia el primero —da igual que la
-- consulta solo filtre por `a`— porque `a` sigue siendo la columna mas a la izquierda.
-- El corto solo aporta trabajo de escritura.
--
-- En total: 53 indices en 19 tablas.
--
-- POR QUE ES SEGURO, Y DONDE ESTA EL LIMITE
--   * Solo se borran indices NO UNICOS. Un UNIQUE es una restriccion de negocio, no una
--     ayuda al optimizador, y quitarlo cambiaria lo que la base acepta.
--   * El superindice conserva las columnas del borrado como prefijo, en el mismo orden,
--     asi que las claves foraneas siguen cubiertas (verificado: 0 casos problematicos).
--   * Riesgo real, honesto: un indice mas corto es mas pequeno, y en un recorrido de
--     rango muy grande puede leer menos paginas que el largo. Es una diferencia de
--     margen, no de plan, y se paga con una escritura extra en CADA insercion. Por eso
--     este archivo va DESPUES del 29 y en un despliegue distinto: si algo se degrada,
--     se sabe cual de los dos fue.
--
-- ESTE ARCHIVO NO CIERRA EL TEMA. Bajar `form_question_responses` de 24 a ~12 indices
-- necesita datos de uso reales, que no se pueden deducir de un volcado:
--   SELECT * FROM sys.schema_unused_indexes WHERE object_schema = DATABASE();
-- Esa vista necesita al menos un mes de estadisticas acumuladas desde el ultimo reinicio
-- de MySQL. Hasta entonces, borrar por intuicion es apostar.
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
-- form_question_responses  (11 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_fqr_created_at_id (created_at,id)
-- idx_fqr_created                      (created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_created');
--   lo cubre: idx_fqr_qdate_local_user (id_form_question,created_at,id_local,id_usuario)
-- idx_fqr_form_fecha                   (id_form_question,created_at)
CALL v2_drop_index('form_question_responses','idx_fqr_form_fecha');
--   lo cubre: idx_fqr_local_created (id_local,created_at)
-- idx_fqr_local                        (id_local)
CALL v2_drop_index('form_question_responses','idx_fqr_local');
--   lo cubre: fqr_form_q_local_dt (id_form_question,id_local,id_usuario,created_at)
-- idx_fqr_q                            (id_form_question)
CALL v2_drop_index('form_question_responses','idx_fqr_q');
--   lo cubre: idx_fqr_q_opt_created (id_form_question,id_option,created_at,id)
-- idx_fqr_q_opt                        (id_form_question,id_option)
CALL v2_drop_index('form_question_responses','idx_fqr_q_opt');
--   lo cubre: idx_fqr_usuario_created (id_usuario,created_at)
-- idx_fqr_user                         (id_usuario)
CALL v2_drop_index('form_question_responses','idx_fqr_user');
--   lo cubre: idx_fqr_vis_q_usr_opt_id (visita_id,id_form_question,id_usuario,id_option,id)
-- idx_fqr_vis_q_usr_opt                (visita_id,id_form_question,id_usuario,id_option)
CALL v2_drop_index('form_question_responses','idx_fqr_vis_q_usr_opt');
--   lo cubre: idx_fqr_vis_usr_q (visita_id,id_usuario,id_form_question)
-- idx_fqr_visita                       (visita_id)
CALL v2_drop_index('form_question_responses','idx_fqr_visita');
--   lo cubre: idx_fqr_visit_local_q (visita_id,id_local,id_form_question)
-- idx_fqr_visita_local                 (visita_id,id_local)
CALL v2_drop_index('form_question_responses','idx_fqr_visita_local');
--   lo cubre: idx_fqr_vis_q_usr_opt_id (visita_id,id_form_question,id_usuario,id_option,id)
-- idx_fqr_visita_pregunta              (visita_id,id_form_question)
CALL v2_drop_index('form_question_responses','idx_fqr_visita_pregunta');
--   lo cubre: idx_fqr_vis_usr_q (visita_id,id_usuario,id_form_question)
-- idx_visita_usuario                   (visita_id,id_usuario)
CALL v2_drop_index('form_question_responses','idx_visita_usuario');

-- --------------------------------------------------------------------------
-- formularioQuestion  (10 indices)
-- --------------------------------------------------------------------------
--   lo cubre: fq_form_local_fecha (id_formulario,id_local,fechaVisita)
-- fq_form_local                        (id_formulario,id_local)
CALL v2_drop_index('formularioQuestion','fq_form_local');
--   lo cubre: idx_fq_fecha_user (fechaPropuesta,id_usuario)
-- fq_idx_fecha                         (fechaPropuesta)
CALL v2_drop_index('formularioQuestion','fq_idx_fecha');
--   lo cubre: idx_fq_pregunta_user (pregunta,id_usuario)
-- fq_idx_pregunta                      (pregunta)
CALL v2_drop_index('formularioQuestion','fq_idx_pregunta');
--   lo cubre: idx_fq_user_emp (id_usuario,id_local,id_formulario)
-- fq_idx_usuario_local                 (id_usuario,id_local)
CALL v2_drop_index('formularioQuestion','fq_idx_usuario_local');
--   lo cubre: idx_formulario_fecha (id_formulario,fechaVisita)
-- id_formulario                        (id_formulario)
CALL v2_drop_index('formularioQuestion','id_formulario');
--   lo cubre: idx_fq_etapa (id_local,id_formulario,id_usuario,etapa_material)
-- idx_fq_local_formulario              (id_local,id_formulario)
CALL v2_drop_index('formularioQuestion','idx_fq_local_formulario');
--   lo cubre: fq_usuario_form_local_fecha (id_usuario,id_formulario,id_local,fechaPropuesta)
-- idx_fq_usuario_form                  (id_usuario,id_formulario)
CALL v2_drop_index('formularioQuestion','idx_fq_usuario_form');
--   lo cubre: fq_usuario_form_local_fecha (id_usuario,id_formulario,id_local,fechaPropuesta)
-- idx_fq_usuario_form_local            (id_usuario,id_formulario,id_local)
CALL v2_drop_index('formularioQuestion','idx_fq_usuario_form_local');
--   lo cubre: idx_fq_etapa (id_local,id_formulario,id_usuario,etapa_material)
-- idx_id_local                         (id_local)
CALL v2_drop_index('formularioQuestion','idx_id_local');
--   lo cubre: idx_fq_user_emp (id_usuario,id_local,id_formulario)
-- idx_id_usuario                       (id_usuario)
CALL v2_drop_index('formularioQuestion','idx_id_usuario');

-- --------------------------------------------------------------------------
-- local  (7 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_local_distrito_jv (id_distrito,id_jefe_venta)
-- idx_id_distrito                      (id_distrito)
CALL v2_drop_index('local','idx_id_distrito');
--   lo cubre: ux_local_codigo_division (codigo,id_division)
-- idx_local_codigo                     (codigo)
CALL v2_drop_index('local','idx_local_codigo');
--   lo cubre: idx_local_emp_div_distr_jv (id_empresa,id_division,id_distrito,id_jefe_venta)
-- idx_local_emp_div                    (id_empresa,id_division)
CALL v2_drop_index('local','idx_local_emp_div');
--   lo cubre: idx_local_emp_div_distr_jv (id_empresa,id_division,id_distrito,id_jefe_venta)
-- idx_local_id_empresa                 (id_empresa)
CALL v2_drop_index('local','idx_local_id_empresa');
--   lo cubre: idx_local_filt (id_empresa,id_division,id_distrito,id_jefe_venta,codigo)
-- idx_local_scope                      (id_empresa,id_division,id_distrito,id_jefe_venta)
CALL v2_drop_index('local','idx_local_scope');
--   lo cubre: idx_l_rel (id_cadena,id_vendedor,id_comuna)
-- l_idx_cadena                         (id_cadena)
CALL v2_drop_index('local','l_idx_cadena');
--   lo cubre: ix_local_comuna_cadena_cuenta (id_comuna,id_cadena,id_cuenta)
-- l_idx_comuna                         (id_comuna)
CALL v2_drop_index('local','l_idx_comuna');

-- --------------------------------------------------------------------------
-- form_questions  (4 indices)
-- --------------------------------------------------------------------------
--   lo cubre: fq_form_sort (id_formulario,id_question_type,sort_order)
-- idx_form_type                        (id_formulario,id_question_type)
CALL v2_drop_index('form_questions','idx_form_type');
--   lo cubre: fq_form_sort (id_formulario,id_question_type,sort_order)
-- idx_fq_formulario                    (id_formulario)
CALL v2_drop_index('form_questions','idx_fq_formulario');
--   lo cubre: idx_fq_form_deleted_type (id_formulario,deleted_at,id_question_type)
-- idx_fq_formulario_activo             (id_formulario,deleted_at)
CALL v2_drop_index('form_questions','idx_fq_formulario_activo');
--   lo cubre: idx_fq_set (id_question_set_question,id_question_type)
-- idx_fq_qset                          (id_question_set_question)
CALL v2_drop_index('form_questions','idx_fq_qset');

-- --------------------------------------------------------------------------
-- formulario  (4 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_form_fecha (fechaInicio,fechaTermino)
-- idx_fechaInicio                      (fechaInicio)
CALL v2_drop_index('formulario','idx_fechaInicio');
--   lo cubre: f_idx_empresa_tipo_estado (id_empresa,tipo,estado,id_division)
-- idx_form_empresa_tipo_estado         (id_empresa,tipo,estado)
CALL v2_drop_index('formulario','idx_form_empresa_tipo_estado');
--   lo cubre: idx_form_tipo_estado_subdiv (id_empresa,id_division,id_subdivision,tipo,estado)
-- idx_form_scope                       (id_empresa,id_division,id_subdivision,tipo)
CALL v2_drop_index('formulario','idx_form_scope');
--   lo cubre: idx_formulario_div_estado_tipo_sub (id_division,estado,tipo,id_subdivision)
-- idx_id_division                      (id_division)
CALL v2_drop_index('formulario','idx_id_division');

-- --------------------------------------------------------------------------
-- fotoVisita  (2 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_fv_visita_fq_form (visita_id,id_formularioQuestion,id_formulario)
-- idx_fv_visita                        (visita_id)
CALL v2_drop_index('fotoVisita','idx_fv_visita');
--   lo cubre: idx_fv_visita_fq_form (visita_id,id_formularioQuestion,id_formulario)
-- idx_visita_fq                        (visita_id,id_formularioQuestion)
CALL v2_drop_index('fotoVisita','idx_visita_fq');

-- --------------------------------------------------------------------------
-- login_attempts  (2 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_login_attempts_outcome_time (outcome,intento_at)
-- outcome                              (outcome)
CALL v2_drop_index('login_attempts','outcome');
--   lo cubre: idx_user_time (user_id,intento_at)
-- user_id                              (user_id)
CALL v2_drop_index('login_attempts','user_id');

-- --------------------------------------------------------------------------
-- usuario  (2 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_usuario_panel_carga (id_empresa,id_division,id_perfil,activo,clasificacion_usuario)
-- idx_usuario_emp_div                  (id_empresa,id_division)
CALL v2_drop_index('usuario','idx_usuario_emp_div');
--   lo cubre: idx_usuario_usuario_empresa (usuario,id_empresa)
-- idx_usuario_usuario                  (usuario)
CALL v2_drop_index('usuario','idx_usuario_usuario');

-- --------------------------------------------------------------------------
-- db_encuesta_unificada  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_local_fecha (codigo_local,fecha_respuesta)
-- idx_local                            (codigo_local)
CALL v2_drop_index('db_encuesta_unificada','idx_local');

-- --------------------------------------------------------------------------
-- form_question_options  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_fqo_formq_qseto (id_form_question,id_question_set_option)
-- idx_fqo_pregunta                     (id_form_question)
CALL v2_drop_index('form_question_options','idx_fqo_pregunta');

-- --------------------------------------------------------------------------
-- formulario_division_habilitada  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: uq_formulario_division_clasificacion (id_formulario,id_division,clasificacion_usuario)
-- idx_formulario                       (id_formulario)
CALL v2_drop_index('formulario_division_habilitada','idx_formulario');

-- --------------------------------------------------------------------------
-- foto_duplicada_log  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_fdl_form_created (id_formulario,created_at)
-- idx_fdl_form                         (id_formulario)
CALL v2_drop_index('foto_duplicada_log','idx_fdl_form');

-- --------------------------------------------------------------------------
-- gestion_apoyo  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: uq_apoyo_visita_fq_usuario (visita_id,id_formularioQuestion,id_usuario_apoyo)
-- idx_apoyo_visita                     (visita_id)
CALL v2_drop_index('gestion_apoyo','idx_apoyo_visita');

-- --------------------------------------------------------------------------
-- journal_resumen_diario  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_dia_problemas (ymd,errores,pendientes)
-- idx_ymd                              (ymd)
CALL v2_drop_index('journal_resumen_diario','idx_ymd');

-- --------------------------------------------------------------------------
-- material  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: ux_mat_nombre_div (nombre,id_division)
-- idx_nombre                           (nombre)
CALL v2_drop_index('material','idx_nombre');

-- --------------------------------------------------------------------------
-- security_events  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_user_type_time (user_id,type,created_at)
-- user_id                              (user_id)
CALL v2_drop_index('security_events','user_id');

-- --------------------------------------------------------------------------
-- subdivision  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_subdivision_division_nombre (id_division,nombre)
-- idx_subdivision_div                  (id_division)
CALL v2_drop_index('subdivision','idx_subdivision_div');

-- --------------------------------------------------------------------------
-- user_sessions  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: uq_user_fpr (user_id,session_fpr)
-- user_id                              (user_id)
CALL v2_drop_index('user_sessions','user_id');

-- --------------------------------------------------------------------------
-- visita  (1 indices)
-- --------------------------------------------------------------------------
--   lo cubre: idx_visita_open (id_usuario,id_formulario,id_local,fecha_fin)
-- id_usuario                           (id_usuario,id_formulario,id_local)
CALL v2_drop_index('visita','id_usuario');
DROP PROCEDURE IF EXISTS v2_drop_index;

-- ---------------------------------------------------------------------------
-- Si el usuario de BD no tiene permiso CREATE ROUTINE
-- ---------------------------------------------------------------------------
-- Cambia cada linea  CALL v2_drop_index('tabla','indice');
-- por               ALTER TABLE `tabla` DROP INDEX `indice`;
-- y quita el bloque del procedimiento. Pierdes la reidempotencia: al relanzarlo,
-- los indices ya borrados daran error 1091, que en ese caso puedes ignorar.

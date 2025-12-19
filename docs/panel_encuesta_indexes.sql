-- Panel Encuesta: índices recomendados (MySQL 5.7)
-- NOTA: revisar cardinalidades y ejecutar en ventana de mantenimiento.

-- 1) Respuestas por visita/pregunta (filtros principales + joins)
-- Usado por panel_encuesta_data.php y export_* con filtros por visita/local/pregunta.
CREATE INDEX idx_fqr_visita_local_pregunta
  ON form_question_responses (visita_id, id_local, id_form_question);

-- 2) Respuestas por pregunta/fecha (rango + filtros)
-- Ayuda a recortar por fechas (created_at) cuando se filtra por pregunta.
CREATE INDEX idx_fqr_pregunta_fecha
  ON form_question_responses (id_form_question, created_at);

-- 3) Respuestas por usuario (filtro de usuario en panel)
CREATE INDEX idx_fqr_usuario
  ON form_question_responses (id_usuario);

-- 4) Preguntas activas por formulario (scope y soft-delete)
CREATE INDEX idx_fq_formulario_activo
  ON form_questions (id_formulario, deleted_at);

-- 5) Formularios por empresa/división/tipo (carga de campañas)
CREATE INDEX idx_formulario_scope
  ON formulario (id_empresa, id_division, id_subdivision, tipo, deleted_at);

-- 6) Locales por empresa/división y filtros de distrito/jefe
CREATE INDEX idx_local_scope
  ON local (id_empresa, id_division, id_distrito, id_jefe_venta);

-- Justificación por query:
-- - panel_encuesta_data.php: filtros por f.id_empresa, f.id_division, f.tipo, fqr.visita_id/local, fq.id
-- - export_csv_panel_encuesta.php / export_pdf_panel_encuesta*.php: mismos joins, orden por visita/fecha
-- - ajax_preguntas_lookup.php / ajax_pregunta_meta.php: filtro por formulario + deleted_at

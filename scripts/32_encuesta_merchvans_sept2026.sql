-- ============================================================
-- 32_encuesta_merchvans_sept2026.sql
-- Set de preguntas 'Merchvans Sept 2026' (Red Bull).
-- Origen: 'Encuenta Merchvans sept 2026.xlsx' (4 hojas).
--
-- Crea la PLANTILLA (question_set), no la encuesta de una campana. Para usarla:
--   portal > Formulario > editar campana > pestana 'Anadir Set de Preguntas'.
-- Ese import copia todo a form_questions / form_question_options y remapea las
-- dependencias solo. Este script no toca ninguna campana.
--
-- ESTRUCTURA
--   P1 'Tipo de local' ramifica en 4. Todo lo demas cuelga de una de sus opciones:
--     Petrolera              -> 30 preguntas
--     Tienda de conveniencia -> 31 preguntas
--     Supermercado           -> 48 preguntas
--     Farmacia / New business-> 15 preguntas
--
--   Petrolera y conveniencia comparten el mismo cuestionario, pero sus preguntas
--   estan DUPLICADAS a proposito: en Visibility una pregunta cuelga de UNA sola
--   opcion, asi que compartirlas obligaria a fundir ambos canales en una opcion
--   y se perderia de que tipo de local se trata. La unica diferencia real entre
--   las dos ramas es 'El cooler en sala esta a menos de 2 metros de la caja',
--   que en el Excel solo aparece para conveniencia.
--
-- TEXTOS REPETIDOS
--   Sin prefijos, varias preguntas comparten texto entre ramas (por ejemplo
--   'Existe cooler en sala Red Bull' esta en petrolera, conveniencia y farmacia).
--   Es correcto y no rompe nada: no hay UNIQUE sobre question_text, el import a la
--   campana sincroniza por id_question_set_question y no por texto, y en cada
--   visita solo se responde una rama. En la exportacion CSV, que pivota por texto,
--   las tres caen en una misma columna con un unico valor por fila; el canal se lee
--   en la columna 'Tipo de local'.
--
--   Por eso mismo las opciones y las dependencias de este script se resuelven por
--   sort_order y NO por texto: buscar por texto engancharia la pregunta equivocada.
--
-- TIPOS: 1 Si/No | 2 unica | 3 multiple | 4 texto | 5 numerico | 7 foto
--   is_valued=1 pone un campo numerico junto a CADA opcion. Es lo que convierte
--   'Precios de fleje' en UNA pregunta con sus formatos en vez de una por formato.
--   El precio queda en form_question_responses.valor, decimal(10,2).
--
-- OJO CON 'OBLIGATORIA': no se valida en tipo 3 ni en tipo 7. La plataforma no
--   escribe el atributo required en los checkbox, la foto se sube fuera del
--   formulario, y no hay control en el servidor. Por eso las listas de SKU y las
--   fotos van con is_required=0: marcarlas daria una garantia que no existe.
-- ============================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- ------------------------------------------------------------
-- 1) El set
-- ------------------------------------------------------------
INSERT INTO question_set (nombre_set, description) VALUES
  ('Merchvans Sept 2026',
   'Red Bull. Ramifica por tipo de local: petrolera, conveniencia, supermercado y farmacia.');
SET @set_id = LAST_INSERT_ID();

-- ------------------------------------------------------------
-- 2) Preguntas (125)
--    Entran con id_dependency_option NULL: una opcion no existe hasta que existe
--    su pregunta. Se enganchan en el paso 4. El sort_order es la clave con la que
--    se identifican los pasos 3 y 4, por eso va explicito y correlativo.
-- ------------------------------------------------------------
INSERT INTO question_set_questions
  (id_question_set, question_text, id_question_type, sort_order, is_required, is_valued) VALUES
  (@set_id, 'Tipo de local', 2, 1, 1, 0),   /* P1 */
  (@set_id, 'Tiene puerta de frio con la categoria energeticas', 1, 2, 1, 0),   /* PETR_puerta */
  (@set_id, 'Foto de la puerta de frio', 7, 3, 0, 0),   /* PETR_puerta_f */
  (@set_id, 'Que SKU tiene la puerta de frio', 3, 4, 0, 0),   /* PETR_puerta_sku */
  (@set_id, 'Existe cooler en sala Red Bull', 1, 5, 1, 0),   /* PETR_sala */
  (@set_id, 'Foto del cooler en sala Red Bull', 7, 6, 0, 0),   /* PETR_sala_f */
  (@set_id, 'Que SKU tiene el cooler en sala', 3, 7, 0, 0),   /* PETR_sala_sku */
  (@set_id, 'Existe cooler en sala de Monster', 1, 8, 1, 0),   /* PETR_sala_mon */
  (@set_id, 'Foto del cooler en sala de Monster', 7, 9, 0, 0),   /* PETR_sala_mon_f */
  (@set_id, 'Existe cooler en sala de Score', 1, 10, 1, 0),   /* PETR_sala_sco */
  (@set_id, 'Foto del cooler en sala de Score', 7, 11, 0, 0),   /* PETR_sala_sco_f */
  (@set_id, 'Existe cooler tras caja Red Bull', 1, 12, 1, 0),   /* PETR_caja */
  (@set_id, 'Foto del cooler tras caja Red Bull', 7, 13, 0, 0),   /* PETR_caja_f */
  (@set_id, 'Que SKU tiene el cooler tras caja', 3, 14, 0, 0),   /* PETR_caja_sku */
  (@set_id, 'Existe cooler tras caja de Monster', 1, 15, 1, 0),   /* PETR_caja_mon */
  (@set_id, 'Foto del cooler tras caja de Monster', 7, 16, 0, 0),   /* PETR_caja_mon_f */
  (@set_id, 'Existe cooler tras caja de Score', 1, 17, 1, 0),   /* PETR_caja_sco */
  (@set_id, 'Foto del cooler tras caja de Score', 7, 18, 0, 0),   /* PETR_caja_sco_f */
  (@set_id, 'Existe exhibicion ambiente de Red Bull', 1, 19, 1, 0),   /* PETR_amb */
  (@set_id, 'Foto de la exhibicion ambiente de Red Bull', 7, 20, 0, 0),   /* PETR_amb_f */
  (@set_id, 'Precios de fleje', 3, 21, 0, 1),   /* PETR_precios */
  (@set_id, 'Foto de los flejes de promocion', 7, 22, 0, 0),   /* PETR_precios_f */
  (@set_id, 'Hay oportunidad de cooler adicional', 1, 23, 1, 0),   /* PETR_op1 */
  (@set_id, 'Foto del espacio para cooler adicional', 7, 24, 0, 0),   /* PETR_op1_f */
  (@set_id, 'Tiene punto electrico para el cooler adicional', 1, 25, 1, 0),   /* PETR_op1_e */
  (@set_id, 'Comentarios sobre la oportunidad de cooler adicional', 4, 26, 0, 0),   /* PETR_op1_c */
  (@set_id, 'Hay oportunidad de cooler mas grande', 1, 27, 1, 0),   /* PETR_op2 */
  (@set_id, 'Foto del espacio para cooler mas grande', 7, 28, 0, 0),   /* PETR_op2_f */
  (@set_id, 'Comentarios sobre la oportunidad de cooler mas grande', 4, 29, 0, 0),   /* PETR_op2_c */
  (@set_id, 'Cuantas cajas tiene la sala', 5, 30, 1, 0),   /* PETR_cajas */
  (@set_id, 'Comentarios adicionales', 4, 31, 0, 0),   /* PETR_coment */
  (@set_id, 'Tiene puerta de frio con la categoria energeticas', 1, 32, 1, 0),   /* CONV_puerta */
  (@set_id, 'Foto de la puerta de frio', 7, 33, 0, 0),   /* CONV_puerta_f */
  (@set_id, 'Que SKU tiene la puerta de frio', 3, 34, 0, 0),   /* CONV_puerta_sku */
  (@set_id, 'Existe cooler en sala Red Bull', 1, 35, 1, 0),   /* CONV_sala */
  (@set_id, 'Foto del cooler en sala Red Bull', 7, 36, 0, 0),   /* CONV_sala_f */
  (@set_id, 'Que SKU tiene el cooler en sala', 3, 37, 0, 0),   /* CONV_sala_sku */
  (@set_id, 'El cooler en sala esta a menos de 2 metros de la caja', 1, 38, 1, 0),   /* CONV_sala_2m */
  (@set_id, 'Existe cooler en sala de Monster', 1, 39, 1, 0),   /* CONV_sala_mon */
  (@set_id, 'Foto del cooler en sala de Monster', 7, 40, 0, 0),   /* CONV_sala_mon_f */
  (@set_id, 'Existe cooler en sala de Score', 1, 41, 1, 0),   /* CONV_sala_sco */
  (@set_id, 'Foto del cooler en sala de Score', 7, 42, 0, 0),   /* CONV_sala_sco_f */
  (@set_id, 'Existe cooler tras caja Red Bull', 1, 43, 1, 0),   /* CONV_caja */
  (@set_id, 'Foto del cooler tras caja Red Bull', 7, 44, 0, 0),   /* CONV_caja_f */
  (@set_id, 'Que SKU tiene el cooler tras caja', 3, 45, 0, 0),   /* CONV_caja_sku */
  (@set_id, 'Existe cooler tras caja de Monster', 1, 46, 1, 0),   /* CONV_caja_mon */
  (@set_id, 'Foto del cooler tras caja de Monster', 7, 47, 0, 0),   /* CONV_caja_mon_f */
  (@set_id, 'Existe cooler tras caja de Score', 1, 48, 1, 0),   /* CONV_caja_sco */
  (@set_id, 'Foto del cooler tras caja de Score', 7, 49, 0, 0),   /* CONV_caja_sco_f */
  (@set_id, 'Existe exhibicion ambiente de Red Bull', 1, 50, 1, 0),   /* CONV_amb */
  (@set_id, 'Foto de la exhibicion ambiente de Red Bull', 7, 51, 0, 0),   /* CONV_amb_f */
  (@set_id, 'Precios de fleje', 3, 52, 0, 1),   /* CONV_precios */
  (@set_id, 'Foto de los flejes de promocion', 7, 53, 0, 0),   /* CONV_precios_f */
  (@set_id, 'Hay oportunidad de cooler adicional', 1, 54, 1, 0),   /* CONV_op1 */
  (@set_id, 'Foto del espacio para cooler adicional', 7, 55, 0, 0),   /* CONV_op1_f */
  (@set_id, 'Tiene punto electrico para el cooler adicional', 1, 56, 1, 0),   /* CONV_op1_e */
  (@set_id, 'Comentarios sobre la oportunidad de cooler adicional', 4, 57, 0, 0),   /* CONV_op1_c */
  (@set_id, 'Hay oportunidad de cooler mas grande', 1, 58, 1, 0),   /* CONV_op2 */
  (@set_id, 'Foto del espacio para cooler mas grande', 7, 59, 0, 0),   /* CONV_op2_f */
  (@set_id, 'Comentarios sobre la oportunidad de cooler mas grande', 4, 60, 0, 0),   /* CONV_op2_c */
  (@set_id, 'Cuantas cajas tiene la sala', 5, 61, 1, 0),   /* CONV_cajas */
  (@set_id, 'Comentarios adicionales', 4, 62, 0, 0),   /* CONV_coment */
  (@set_id, 'Tiene gondola de energizantes', 1, 63, 1, 0),   /* S_gon */
  (@set_id, 'Foto de la gondola', 7, 64, 0, 0),   /* S_gon_f */
  (@set_id, 'Que SKU tiene la gondola', 3, 65, 0, 0),   /* S_gon_sku */
  (@set_id, 'Tiene Energy Zone', 1, 66, 1, 0),   /* S_ez */
  (@set_id, 'Precios de fleje', 3, 67, 0, 1),   /* S_precios */
  (@set_id, 'Foto de los flejes de promocion', 7, 68, 0, 0),   /* S_precios_f */
  (@set_id, 'Existe cooler en sala Red Bull fuera del lineal de caja', 1, 69, 1, 0),   /* S_sala */
  (@set_id, 'Foto del cooler en sala Red Bull', 7, 70, 0, 0),   /* S_sala_f */
  (@set_id, 'Cuantos coolers Red Bull hay en sala', 5, 71, 1, 0),   /* S_sala_n */
  (@set_id, 'Que SKU tiene el cooler en sala', 3, 72, 0, 0),   /* S_sala_sku */
  (@set_id, 'Existe cooler en sala de Monster', 1, 73, 1, 0),   /* S_sala_mon */
  (@set_id, 'Foto del cooler en sala de Monster', 7, 74, 0, 0),   /* S_sala_mon_f */
  (@set_id, 'Existe cooler en sala de Score', 1, 75, 1, 0),   /* S_sala_sco */
  (@set_id, 'Foto del cooler en sala de Score', 7, 76, 0, 0),   /* S_sala_sco_f */
  (@set_id, 'Existe puerta de frio con la categoria', 1, 77, 1, 0),   /* S_pf */
  (@set_id, 'Foto de la puerta de frio', 7, 78, 0, 0),   /* S_pf_f */
  (@set_id, 'Que SKU tiene la puerta de frio', 3, 79, 0, 0),   /* S_pf_sku */
  (@set_id, 'Existe exhibicion ambiente de Red Bull', 1, 80, 1, 0),   /* S_amb */
  (@set_id, 'Foto de la exhibicion ambiente de Red Bull', 7, 81, 0, 0),   /* S_amb_f */
  (@set_id, 'Existe exhibicion ambiente de otras energeticas', 1, 82, 1, 0),   /* S_amb2 */
  (@set_id, 'Foto de la exhibicion ambiente de otras energeticas', 7, 83, 0, 0),   /* S_amb2_f */
  (@set_id, 'Lineal de caja: existe cooler Red Bull', 1, 84, 1, 0),   /* S_lin_rb */
  (@set_id, 'Foto del cooler Red Bull en lineal de caja', 7, 85, 0, 0),   /* S_lin_rb_f */
  (@set_id, 'Cuantos cooler Red Bull hay en lineal de caja', 5, 86, 1, 0),   /* S_lin_rb_n */
  (@set_id, 'Lineal de caja: existe cooler generico con Red Bull', 1, 87, 1, 0),   /* S_lin_grb */
  (@set_id, 'Foto del cooler generico con Red Bull en lineal de caja', 7, 88, 0, 0),   /* S_lin_grb_f */
  (@set_id, 'Cuantos cooler generico con Red Bull hay en lineal de caja', 5, 89, 1, 0),   /* S_lin_grb_n */
  (@set_id, 'Lineal de caja: existe cooler Red Bull en caja de autoservicio', 1, 90, 1, 0),   /* S_lin_arb */
  (@set_id, 'Foto del cooler Red Bull en caja de autoservicio en lineal de caja', 7, 91, 0, 0),   /* S_lin_arb_f */
  (@set_id, 'Cuantos cooler Red Bull en caja de autoservicio hay en lineal de caja', 5, 92, 1, 0),   /* S_lin_arb_n */
  (@set_id, 'Lineal de caja: existe cooler Monster', 1, 93, 1, 0),   /* S_lin_mon */
  (@set_id, 'Foto del cooler Monster en lineal de caja', 7, 94, 0, 0),   /* S_lin_mon_f */
  (@set_id, 'Cuantos cooler Monster hay en lineal de caja', 5, 95, 1, 0),   /* S_lin_mon_n */
  (@set_id, 'Lineal de caja: existe cooler generico con Monster', 1, 96, 1, 0),   /* S_lin_gmon */
  (@set_id, 'Foto del cooler generico con Monster en lineal de caja', 7, 97, 0, 0),   /* S_lin_gmon_f */
  (@set_id, 'Cuantos cooler generico con Monster hay en lineal de caja', 5, 98, 1, 0),   /* S_lin_gmon_n */
  (@set_id, 'Lineal de caja: existe cooler Monster en caja de autoservicio', 1, 99, 1, 0),   /* S_lin_amon */
  (@set_id, 'Foto del cooler Monster en caja de autoservicio en lineal de caja', 7, 100, 0, 0),   /* S_lin_amon_f */
  (@set_id, 'Cuantos cooler Monster en caja de autoservicio hay en lineal de caja', 5, 101, 1, 0),   /* S_lin_amon_n */
  (@set_id, 'Lineal de caja: existe cooler Score', 1, 102, 1, 0),   /* S_lin_sco */
  (@set_id, 'Foto del cooler Score en lineal de caja', 7, 103, 0, 0),   /* S_lin_sco_f */
  (@set_id, 'Cuantos cooler Score hay en lineal de caja', 5, 104, 1, 0),   /* S_lin_sco_n */
  (@set_id, 'Lineal de caja: existe cooler generico con Score', 1, 105, 1, 0),   /* S_lin_gsco */
  (@set_id, 'Foto del cooler generico con Score en lineal de caja', 7, 106, 0, 0),   /* S_lin_gsco_f */
  (@set_id, 'Cuantos cooler generico con Score hay en lineal de caja', 5, 107, 1, 0),   /* S_lin_gsco_n */
  (@set_id, 'Lineal de caja: existe cooler Score en caja de autoservicio', 1, 108, 1, 0),   /* S_lin_asco */
  (@set_id, 'Foto del cooler Score en caja de autoservicio en lineal de caja', 7, 109, 0, 0),   /* S_lin_asco_f */
  (@set_id, 'Cuantos cooler Score en caja de autoservicio hay en lineal de caja', 5, 110, 1, 0),   /* S_lin_asco_n */
  (@set_id, 'Existe cooler en tienda con la categoria', 1, 111, 1, 0),   /* F_cool */
  (@set_id, 'Foto del cooler en tienda', 7, 112, 0, 0),   /* F_cool_f */
  (@set_id, 'Que SKU tiene el cooler en tienda', 3, 113, 0, 0),   /* F_cool_sku */
  (@set_id, 'Existe cooler en sala Red Bull', 1, 114, 1, 0),   /* F_sala */
  (@set_id, 'Foto del cooler en sala Red Bull', 7, 115, 0, 0),   /* F_sala_f */
  (@set_id, 'Existe exhibicion ambiente de Red Bull', 1, 116, 1, 0),   /* F_amb */
  (@set_id, 'Foto de la exhibicion ambiente de Red Bull', 7, 117, 0, 0),   /* F_amb_f */
  (@set_id, 'Precios de fleje', 3, 118, 0, 1),   /* F_precios */
  (@set_id, 'Foto de los flejes de promocion', 7, 119, 0, 0),   /* F_precios_f */
  (@set_id, 'Hay oportunidad de cooler adicional', 1, 120, 1, 0),   /* F_op1 */
  (@set_id, 'Foto del espacio para cooler adicional', 7, 121, 0, 0),   /* F_op1_f */
  (@set_id, 'Tiene punto electrico para el cooler adicional', 1, 122, 1, 0),   /* F_op1_e */
  (@set_id, 'Comentarios sobre la oportunidad de cooler adicional', 4, 123, 0, 0),   /* F_op1_c */
  (@set_id, 'Cuantas cajas tiene la sala', 5, 124, 1, 0),   /* F_cajas */
  (@set_id, 'Comentarios adicionales', 4, 125, 0, 0);   /* F_coment */

-- ------------------------------------------------------------
-- 3) Opciones   (se ubica la pregunta por sort_order, no por texto)
-- ------------------------------------------------------------
-- P1             n=1   Tipo de local
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Petrolera', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=1
UNION ALL SELECT id, 'Tienda de conveniencia', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=1
UNION ALL SELECT id, 'Supermercado', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=1
UNION ALL SELECT id, 'Farmacia / New business', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=1;

-- PETR_puerta    n=2   Tiene puerta de frio con la categoria energeticas
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=2
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=2;

-- PETR_puerta_sku n=4   Que SKU tiene la puerta de frio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=4;

-- PETR_sala      n=5   Existe cooler en sala Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=5
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=5;

-- PETR_sala_sku  n=7   Que SKU tiene el cooler en sala
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=7;

-- PETR_sala_mon  n=8   Existe cooler en sala de Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=8
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=8;

-- PETR_sala_sco  n=10  Existe cooler en sala de Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=10
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=10;

-- PETR_caja      n=12  Existe cooler tras caja Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=12
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=12;

-- PETR_caja_sku  n=14  Que SKU tiene el cooler tras caja
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=14;

-- PETR_caja_mon  n=15  Existe cooler tras caja de Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=15
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=15;

-- PETR_caja_sco  n=17  Existe cooler tras caja de Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=17
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=17;

-- PETR_amb       n=19  Existe exhibicion ambiente de Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=19
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=19;

-- PETR_precios   n=21  Precios de fleje
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, '250 ml', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, '355 ml', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, '473 ml', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Fourpacks', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo 250 ml', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo 355 ml', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo 473 ml', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo fourpacks', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Monster 473 ml', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo Monster', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Score 473 ml', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21
UNION ALL SELECT id, 'Promo Score', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=21;

-- PETR_op1       n=23  Hay oportunidad de cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=23
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=23;

-- PETR_op1_e     n=25  Tiene punto electrico para el cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=25
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=25;

-- PETR_op2       n=27  Hay oportunidad de cooler mas grande
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=27
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=27;

-- CONV_puerta    n=32  Tiene puerta de frio con la categoria energeticas
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=32
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=32;

-- CONV_puerta_sku n=34  Que SKU tiene la puerta de frio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=34;

-- CONV_sala      n=35  Existe cooler en sala Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=35
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=35;

-- CONV_sala_sku  n=37  Que SKU tiene el cooler en sala
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=37;

-- CONV_sala_2m   n=38  El cooler en sala esta a menos de 2 metros de la caja
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=38
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=38;

-- CONV_sala_mon  n=39  Existe cooler en sala de Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=39
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=39;

-- CONV_sala_sco  n=41  Existe cooler en sala de Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=41
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=41;

-- CONV_caja      n=43  Existe cooler tras caja Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=43
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=43;

-- CONV_caja_sku  n=45  Que SKU tiene el cooler tras caja
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=45;

-- CONV_caja_mon  n=46  Existe cooler tras caja de Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=46
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=46;

-- CONV_caja_sco  n=48  Existe cooler tras caja de Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=48
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=48;

-- CONV_amb       n=50  Existe exhibicion ambiente de Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=50
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=50;

-- CONV_precios   n=52  Precios de fleje
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, '250 ml', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, '355 ml', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, '473 ml', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Fourpacks', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo 250 ml', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo 355 ml', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo 473 ml', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo fourpacks', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Monster 473 ml', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo Monster', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Score 473 ml', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52
UNION ALL SELECT id, 'Promo Score', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=52;

-- CONV_op1       n=54  Hay oportunidad de cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=54
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=54;

-- CONV_op1_e     n=56  Tiene punto electrico para el cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=56
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=56;

-- CONV_op2       n=58  Hay oportunidad de cooler mas grande
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=58
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=58;

-- S_gon          n=63  Tiene gondola de energizantes
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=63
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=63;

-- S_gon_sku      n=65  Que SKU tiene la gondola
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Monster', 19 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Score', 20 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65
UNION ALL SELECT id, 'Otras', 21 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=65;

-- S_ez           n=66  Tiene Energy Zone
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=66
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=66;

-- S_precios      n=67  Precios de fleje
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, '250 ml', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, '355 ml', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, '473 ml', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Fourpacks', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo 250 ml', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo 355 ml', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo 473 ml', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo fourpacks', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Monster 473 ml', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo Monster', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Score 473 ml', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67
UNION ALL SELECT id, 'Promo Score', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=67;

-- S_sala         n=69  Existe cooler en sala Red Bull fuera del lineal de caja
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=69
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=69;

-- S_sala_sku     n=72  Que SKU tiene el cooler en sala
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=72;

-- S_sala_mon     n=73  Existe cooler en sala de Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=73
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=73;

-- S_sala_sco     n=75  Existe cooler en sala de Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=75
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=75;

-- S_pf           n=77  Existe puerta de frio con la categoria
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=77
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=77;

-- S_pf_sku       n=79  Que SKU tiene la puerta de frio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Zero 355', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Ed 473', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'SF 473', 14 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Ed fourpack', 15 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'SF fourpack', 16 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Yellow fourpack', 17 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Innovacion', 18 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Monster', 19 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Score', 20 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79
UNION ALL SELECT id, 'Otras', 21 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=79;

-- S_amb          n=80  Existe exhibicion ambiente de Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=80
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=80;

-- S_amb2         n=82  Existe exhibicion ambiente de otras energeticas
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=82
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=82;

-- S_lin_rb       n=84  Lineal de caja: existe cooler Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=84
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=84;

-- S_lin_grb      n=87  Lineal de caja: existe cooler generico con Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=87
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=87;

-- S_lin_arb      n=90  Lineal de caja: existe cooler Red Bull en caja de autoservicio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=90
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=90;

-- S_lin_mon      n=93  Lineal de caja: existe cooler Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=93
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=93;

-- S_lin_gmon     n=96  Lineal de caja: existe cooler generico con Monster
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=96
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=96;

-- S_lin_amon     n=99  Lineal de caja: existe cooler Monster en caja de autoservicio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=99
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=99;

-- S_lin_sco      n=102 Lineal de caja: existe cooler Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=102
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=102;

-- S_lin_gsco     n=105 Lineal de caja: existe cooler generico con Score
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=105
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=105;

-- S_lin_asco     n=108 Lineal de caja: existe cooler Score en caja de autoservicio
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=108
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=108;

-- F_cool         n=111 Existe cooler en tienda con la categoria
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=111
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=111;

-- F_cool_sku     n=113 Que SKU tiene el cooler en tienda
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Ed 250', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'SF 250', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Pomelo', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Yellow', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Zero 250', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Acai', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Red', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Blue', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Green', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Ed 355', 10 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'SF 355', 11 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Ed 473', 12 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113
UNION ALL SELECT id, 'Innovacion', 13 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=113;

-- F_sala         n=114 Existe cooler en sala Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=114
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=114;

-- F_amb          n=116 Existe exhibicion ambiente de Red Bull
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=116
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=116;

-- F_precios      n=118 Precios de fleje
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, '250 ml', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, '355 ml', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, '473 ml', 3 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Promo 250 ml', 4 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Promo 355 ml', 5 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Promo 473 ml', 6 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Monster 473 ml', 7 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Promo Monster', 8 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118
UNION ALL SELECT id, 'Score 473 ml', 9 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=118;

-- F_op1          n=120 Hay oportunidad de cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=120
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=120;

-- F_op1_e        n=122 Tiene punto electrico para el cooler adicional
INSERT INTO question_set_options (id_question_set_question, option_text, sort_order)
SELECT id, 'Si', 1 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=122
UNION ALL SELECT id, 'No', 2 FROM question_set_questions WHERE id_question_set=@set_id AND sort_order=122;

-- ------------------------------------------------------------
-- 4) Dependencias
--    (sort_order de la hija, sort_order del padre, texto de la opcion que la abre)
-- ------------------------------------------------------------
CREATE TEMPORARY TABLE tmp_dep (
  hija   INT NOT NULL,
  padre  INT NOT NULL,
  opcion VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tmp_dep (hija, padre, opcion) VALUES
  (  2,   1, 'Petrolera')   /* PETR_puerta    <- P1 */,
  (  3,   2, 'Si')   /* PETR_puerta_f  <- PETR_puerta */,
  (  4,   2, 'Si')   /* PETR_puerta_sku <- PETR_puerta */,
  (  5,   1, 'Petrolera')   /* PETR_sala      <- P1 */,
  (  6,   5, 'Si')   /* PETR_sala_f    <- PETR_sala */,
  (  7,   5, 'Si')   /* PETR_sala_sku  <- PETR_sala */,
  (  8,   1, 'Petrolera')   /* PETR_sala_mon  <- P1 */,
  (  9,   8, 'Si')   /* PETR_sala_mon_f <- PETR_sala_mon */,
  ( 10,   1, 'Petrolera')   /* PETR_sala_sco  <- P1 */,
  ( 11,  10, 'Si')   /* PETR_sala_sco_f <- PETR_sala_sco */,
  ( 12,   1, 'Petrolera')   /* PETR_caja      <- P1 */,
  ( 13,  12, 'Si')   /* PETR_caja_f    <- PETR_caja */,
  ( 14,  12, 'Si')   /* PETR_caja_sku  <- PETR_caja */,
  ( 15,   1, 'Petrolera')   /* PETR_caja_mon  <- P1 */,
  ( 16,  15, 'Si')   /* PETR_caja_mon_f <- PETR_caja_mon */,
  ( 17,   1, 'Petrolera')   /* PETR_caja_sco  <- P1 */,
  ( 18,  17, 'Si')   /* PETR_caja_sco_f <- PETR_caja_sco */,
  ( 19,   1, 'Petrolera')   /* PETR_amb       <- P1 */,
  ( 20,  19, 'Si')   /* PETR_amb_f     <- PETR_amb */,
  ( 21,   1, 'Petrolera')   /* PETR_precios   <- P1 */,
  ( 22,   1, 'Petrolera')   /* PETR_precios_f <- P1 */,
  ( 23,   1, 'Petrolera')   /* PETR_op1       <- P1 */,
  ( 24,  23, 'Si')   /* PETR_op1_f     <- PETR_op1 */,
  ( 25,  23, 'Si')   /* PETR_op1_e     <- PETR_op1 */,
  ( 26,  23, 'Si')   /* PETR_op1_c     <- PETR_op1 */,
  ( 27,   1, 'Petrolera')   /* PETR_op2       <- P1 */,
  ( 28,  27, 'Si')   /* PETR_op2_f     <- PETR_op2 */,
  ( 29,  27, 'Si')   /* PETR_op2_c     <- PETR_op2 */,
  ( 30,   1, 'Petrolera')   /* PETR_cajas     <- P1 */,
  ( 31,   1, 'Petrolera')   /* PETR_coment    <- P1 */,
  ( 32,   1, 'Tienda de conveniencia')   /* CONV_puerta    <- P1 */,
  ( 33,  32, 'Si')   /* CONV_puerta_f  <- CONV_puerta */,
  ( 34,  32, 'Si')   /* CONV_puerta_sku <- CONV_puerta */,
  ( 35,   1, 'Tienda de conveniencia')   /* CONV_sala      <- P1 */,
  ( 36,  35, 'Si')   /* CONV_sala_f    <- CONV_sala */,
  ( 37,  35, 'Si')   /* CONV_sala_sku  <- CONV_sala */,
  ( 38,  35, 'Si')   /* CONV_sala_2m   <- CONV_sala */,
  ( 39,   1, 'Tienda de conveniencia')   /* CONV_sala_mon  <- P1 */,
  ( 40,  39, 'Si')   /* CONV_sala_mon_f <- CONV_sala_mon */,
  ( 41,   1, 'Tienda de conveniencia')   /* CONV_sala_sco  <- P1 */,
  ( 42,  41, 'Si')   /* CONV_sala_sco_f <- CONV_sala_sco */,
  ( 43,   1, 'Tienda de conveniencia')   /* CONV_caja      <- P1 */,
  ( 44,  43, 'Si')   /* CONV_caja_f    <- CONV_caja */,
  ( 45,  43, 'Si')   /* CONV_caja_sku  <- CONV_caja */,
  ( 46,   1, 'Tienda de conveniencia')   /* CONV_caja_mon  <- P1 */,
  ( 47,  46, 'Si')   /* CONV_caja_mon_f <- CONV_caja_mon */,
  ( 48,   1, 'Tienda de conveniencia')   /* CONV_caja_sco  <- P1 */,
  ( 49,  48, 'Si')   /* CONV_caja_sco_f <- CONV_caja_sco */,
  ( 50,   1, 'Tienda de conveniencia')   /* CONV_amb       <- P1 */,
  ( 51,  50, 'Si')   /* CONV_amb_f     <- CONV_amb */,
  ( 52,   1, 'Tienda de conveniencia')   /* CONV_precios   <- P1 */,
  ( 53,   1, 'Tienda de conveniencia')   /* CONV_precios_f <- P1 */,
  ( 54,   1, 'Tienda de conveniencia')   /* CONV_op1       <- P1 */,
  ( 55,  54, 'Si')   /* CONV_op1_f     <- CONV_op1 */,
  ( 56,  54, 'Si')   /* CONV_op1_e     <- CONV_op1 */,
  ( 57,  54, 'Si')   /* CONV_op1_c     <- CONV_op1 */,
  ( 58,   1, 'Tienda de conveniencia')   /* CONV_op2       <- P1 */,
  ( 59,  58, 'Si')   /* CONV_op2_f     <- CONV_op2 */,
  ( 60,  58, 'Si')   /* CONV_op2_c     <- CONV_op2 */,
  ( 61,   1, 'Tienda de conveniencia')   /* CONV_cajas     <- P1 */,
  ( 62,   1, 'Tienda de conveniencia')   /* CONV_coment    <- P1 */,
  ( 63,   1, 'Supermercado')   /* S_gon          <- P1 */,
  ( 64,  63, 'Si')   /* S_gon_f        <- S_gon */,
  ( 65,  63, 'Si')   /* S_gon_sku      <- S_gon */,
  ( 66,   1, 'Supermercado')   /* S_ez           <- P1 */,
  ( 67,   1, 'Supermercado')   /* S_precios      <- P1 */,
  ( 68,   1, 'Supermercado')   /* S_precios_f    <- P1 */,
  ( 69,   1, 'Supermercado')   /* S_sala         <- P1 */,
  ( 70,  69, 'Si')   /* S_sala_f       <- S_sala */,
  ( 71,  69, 'Si')   /* S_sala_n       <- S_sala */,
  ( 72,  69, 'Si')   /* S_sala_sku     <- S_sala */,
  ( 73,   1, 'Supermercado')   /* S_sala_mon     <- P1 */,
  ( 74,  73, 'Si')   /* S_sala_mon_f   <- S_sala_mon */,
  ( 75,   1, 'Supermercado')   /* S_sala_sco     <- P1 */,
  ( 76,  75, 'Si')   /* S_sala_sco_f   <- S_sala_sco */,
  ( 77,   1, 'Supermercado')   /* S_pf           <- P1 */,
  ( 78,  77, 'Si')   /* S_pf_f         <- S_pf */,
  ( 79,  77, 'Si')   /* S_pf_sku       <- S_pf */,
  ( 80,   1, 'Supermercado')   /* S_amb          <- P1 */,
  ( 81,  80, 'Si')   /* S_amb_f        <- S_amb */,
  ( 82,   1, 'Supermercado')   /* S_amb2         <- P1 */,
  ( 83,  82, 'Si')   /* S_amb2_f       <- S_amb2 */,
  ( 84,   1, 'Supermercado')   /* S_lin_rb       <- P1 */,
  ( 85,  84, 'Si')   /* S_lin_rb_f     <- S_lin_rb */,
  ( 86,  84, 'Si')   /* S_lin_rb_n     <- S_lin_rb */,
  ( 87,   1, 'Supermercado')   /* S_lin_grb      <- P1 */,
  ( 88,  87, 'Si')   /* S_lin_grb_f    <- S_lin_grb */,
  ( 89,  87, 'Si')   /* S_lin_grb_n    <- S_lin_grb */,
  ( 90,   1, 'Supermercado')   /* S_lin_arb      <- P1 */,
  ( 91,  90, 'Si')   /* S_lin_arb_f    <- S_lin_arb */,
  ( 92,  90, 'Si')   /* S_lin_arb_n    <- S_lin_arb */,
  ( 93,   1, 'Supermercado')   /* S_lin_mon      <- P1 */,
  ( 94,  93, 'Si')   /* S_lin_mon_f    <- S_lin_mon */,
  ( 95,  93, 'Si')   /* S_lin_mon_n    <- S_lin_mon */,
  ( 96,   1, 'Supermercado')   /* S_lin_gmon     <- P1 */,
  ( 97,  96, 'Si')   /* S_lin_gmon_f   <- S_lin_gmon */,
  ( 98,  96, 'Si')   /* S_lin_gmon_n   <- S_lin_gmon */,
  ( 99,   1, 'Supermercado')   /* S_lin_amon     <- P1 */,
  (100,  99, 'Si')   /* S_lin_amon_f   <- S_lin_amon */,
  (101,  99, 'Si')   /* S_lin_amon_n   <- S_lin_amon */,
  (102,   1, 'Supermercado')   /* S_lin_sco      <- P1 */,
  (103, 102, 'Si')   /* S_lin_sco_f    <- S_lin_sco */,
  (104, 102, 'Si')   /* S_lin_sco_n    <- S_lin_sco */,
  (105,   1, 'Supermercado')   /* S_lin_gsco     <- P1 */,
  (106, 105, 'Si')   /* S_lin_gsco_f   <- S_lin_gsco */,
  (107, 105, 'Si')   /* S_lin_gsco_n   <- S_lin_gsco */,
  (108,   1, 'Supermercado')   /* S_lin_asco     <- P1 */,
  (109, 108, 'Si')   /* S_lin_asco_f   <- S_lin_asco */,
  (110, 108, 'Si')   /* S_lin_asco_n   <- S_lin_asco */,
  (111,   1, 'Farmacia / New business')   /* F_cool         <- P1 */,
  (112, 111, 'Si')   /* F_cool_f       <- F_cool */,
  (113, 111, 'Si')   /* F_cool_sku     <- F_cool */,
  (114,   1, 'Farmacia / New business')   /* F_sala         <- P1 */,
  (115, 114, 'Si')   /* F_sala_f       <- F_sala */,
  (116,   1, 'Farmacia / New business')   /* F_amb          <- P1 */,
  (117, 116, 'Si')   /* F_amb_f        <- F_amb */,
  (118,   1, 'Farmacia / New business')   /* F_precios      <- P1 */,
  (119,   1, 'Farmacia / New business')   /* F_precios_f    <- P1 */,
  (120,   1, 'Farmacia / New business')   /* F_op1          <- P1 */,
  (121, 120, 'Si')   /* F_op1_f        <- F_op1 */,
  (122, 120, 'Si')   /* F_op1_e        <- F_op1 */,
  (123, 120, 'Si')   /* F_op1_c        <- F_op1 */,
  (124,   1, 'Farmacia / New business')   /* F_cajas        <- P1 */,
  (125,   1, 'Farmacia / New business')   /* F_coment       <- P1 */;

UPDATE question_set_questions h
  JOIN tmp_dep d                ON d.hija = h.sort_order
  JOIN question_set_questions p ON p.id_question_set = @set_id AND p.sort_order = d.padre
  JOIN question_set_options   o ON o.id_question_set_question = p.id AND o.option_text = d.opcion
   SET h.id_dependency_option = o.id
 WHERE h.id_question_set = @set_id;

DROP TEMPORARY TABLE tmp_dep;

COMMIT;

-- ------------------------------------------------------------
-- 5) VERIFICACION (correr despues del COMMIT, en la misma sesion)
-- ------------------------------------------------------------
-- Esperado: 125 preguntas, 320 opciones, 124 con dependencia, 4 valorizadas.
SELECT
  (SELECT COUNT(*) FROM question_set_questions
    WHERE id_question_set=@set_id)                                      AS preguntas,
  (SELECT COUNT(*) FROM question_set_options o
     JOIN question_set_questions q ON q.id = o.id_question_set_question
    WHERE q.id_question_set=@set_id)                                    AS opciones,
  (SELECT COUNT(*) FROM question_set_questions
    WHERE id_question_set=@set_id AND id_dependency_option IS NOT NULL) AS con_dependencia,
  (SELECT COUNT(*) FROM question_set_questions
    WHERE id_question_set=@set_id AND is_valued=1)                      AS valorizadas;

-- Debe devolver CERO filas: toda pregunta salvo 'Tipo de local' cuelga de algo.
SELECT q.sort_order, q.question_text
  FROM question_set_questions q
 WHERE q.id_question_set = @set_id
   AND q.id_dependency_option IS NULL
   AND q.sort_order <> 1
 ORDER BY q.sort_order;

-- Cuantas preguntas quedaron colgando de cada canal (sin contar las de segundo
-- nivel). Esperado: Petrolera 14, Conveniencia 14, Supermercado 19, Farmacia 8.
-- o.sort_order va en el GROUP BY y no solo en el ORDER BY: el servidor corre con
-- only_full_group_by y rechaza ordenar por una columna que no esta agrupada.
SELECT o.sort_order AS n, o.option_text AS canal, COUNT(*) AS preguntas_directas
  FROM question_set_questions q
  JOIN question_set_options o ON o.id = q.id_dependency_option
  JOIN question_set_questions p ON p.id = o.id_question_set_question AND p.sort_order = 1
 WHERE q.id_question_set = @set_id
 GROUP BY o.sort_order, o.option_text
 ORDER BY o.sort_order;

-- El arbol completo, para revisarlo antes de importarlo.
SELECT q.sort_order AS n, q.question_text AS pregunta, q.id_question_type AS tipo,
       q.is_required AS oblig, q.is_valued AS valorizada,
       CONCAT(p.sort_order, '. ', p.question_text, ' = ', o.option_text) AS depende_de
  FROM question_set_questions q
  LEFT JOIN question_set_options   o ON o.id = q.id_dependency_option
  LEFT JOIN question_set_questions p ON p.id = o.id_question_set_question
 WHERE q.id_question_set = @set_id
 ORDER BY q.sort_order;

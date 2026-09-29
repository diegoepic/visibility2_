-- 39_set_canal_tradicional_3.sql --- crea el set de preguntas "CANAL TRADICIONAL 3.0"
--
-- QUE HACE
-- --------
-- Inserta un set NUEVO (question_set + question_set_questions + question_set_options)
-- derivado del set 66 "CANAL TRADICIONAL 2.0 (modificaciones 16-01-2026 crosselot)".
-- No toca el set 66 ni ninguna campana existente: es puramente aditivo.
--
-- POR QUE NO SE EDITA EL SET 66
-- -----------------------------
-- El set 66 esta importado en 60 campanas con 3.679.781 respuestas. Re-sincronizar una
-- campana con un set recortado ejecuta, en editar_formulario.php:370-383:
--
--     DELETE FROM form_questions WHERE id_formulario = ? AND id_cadena <=> ?
--       AND id_question_set_question IS NOT NULL AND id_question_set_question NOT IN (...)
--
-- y form_question_responses NO tiene clave foranea a form_questions (su unico constraint
-- es fk_fqr_fotovisita -> fotoVisita). Las respuestas no se borran: quedan HUERFANAS, y
-- todos los informes parten de un JOIN con form_questions, asi que dejan de verse.
-- Ya paso antes: hay 25.679 respuestas huerfanas de 669 preguntas borradas, sobre
-- 9.498.425 filas (medido sobre el volcado del 2026-09-10).
--
-- Por eso el set 66 queda congelado y este set se aplica SOLO a campanas nuevas.
--
-- QUE CAMBIA RESPECTO AL 66
-- -------------------------
--  1. La rama "Cuantas puertas hay de cooler CCU?" ya no replica contenido. Colgaban de
--     ella 36 de las 160 preguntas, que eran las mismas ~9 escritas cuatro veces. Ahora
--     el numero de puertas es un dato, y solo cuelgan de el las preguntas que de verdad
--     son especificas de 4 puertas (bandeja exclusiva Gatorade y full bandeja Red Bull).
--  2. Presencia de Lipton / Gatorade / Rockstar en el cooler CCU: una sola vez cada una
--     (antes dos, porque la rama directa y la de puertas se disparaban las dos).
--  3. Contaminacion del cooler CCU: una sola pregunta con UNA lista de opciones (la union
--     de las cuatro listas distintas que habia). Antes eran 4 preguntas con vocabularios
--     diferentes, o sea no agregables en un informe.
--  4. Disponibilidad: una sola pregunta multiple (Red Bull / Gatorade / Lipton / Rockstar /
--     Monster / Score / Ninguno) que abre por formatos. Antes solo existia disponibilidad
--     de local para Red Bull, Monster y Score; Gatorade, Lipton y Rockstar solo se median
--     dentro del cooler CCU.
--  5. Se eliminan "prueba", "prueba1", "prueba no" y "prueba si" (0 respuestas; ademas
--     "prueba" dependia de su propia opcion, dependencia circular).
--  6. Todos los textos son unicos. En el 66 "Marca los SKUs presentes Y Precio" aparecia
--     10 veces, y el CSV de encuestas pivotea por texto de pregunta.
--
-- LO QUE NO SE TOCA
-- -----------------
-- is_valued queda en 0 en todas las preguntas, igual que en el set 66. Decision del
-- cliente: esta asi a proposito.
--
-- IDEMPOTENTE
-- -----------
-- Todas las inserciones van con NOT EXISTS, asi que volver a correrlo no duplica nada.
--
-- COMO SE APLICA
-- --------------
--   php scripts/migrate.php run        (el runner abre la conexion en utf8mb4)
-- o pegandolo en phpMyAdmin. Si se usa el cliente de consola hay que forzar el juego
-- de caracteres, porque el archivo lleva acentos y signos de apertura:
--   mysql --default-character-set=utf8mb4 -u <user> -p <base> < scripts/39_....sql
--
-- DESPUES DE APLICARLO
-- --------------------
-- El set aparece en el desplegable de "Anadir Set de Preguntas" de cada campana.
-- Importarlo SOLO en campanas nuevas. No re-sincronizar ninguna campana del set 66.
--

START TRANSACTION;

-- 1) El set ------------------------------------------------------------------
INSERT INTO `question_set` (`nombre_set`, `description`, `created_at`, `updated_at`)
SELECT 'CANAL TRADICIONAL 3.0', 'Set actualizado de Canal Tradicional. Deriva del set 66 quitando las preguntas repetidas por numero de puertas del cooler CCU y unificando la disponibilidad de producto en una sola pregunta.', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `question_set` WHERE `nombre_set` = 'CANAL TRADICIONAL 3.0');

SET @set := (SELECT `id` FROM `question_set` WHERE `nombre_set` = 'CANAL TRADICIONAL 3.0' ORDER BY `id` LIMIT 1);

-- 2) Tablas temporales con la definicion --------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_ct3_preg`;
CREATE TEMPORARY TABLE `tmp_ct3_preg` (
  `orden`      INT          NOT NULL,
  `texto`      VARCHAR(255) NOT NULL,
  `tipo`       INT          NOT NULL,
  `oblig`      TINYINT      NOT NULL,
  `dep_preg`   VARCHAR(255) NULL,
  `dep_opcion` VARCHAR(255) NULL
) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;

INSERT INTO `tmp_ct3_preg` (`orden`, `texto`, `tipo`, `oblig`, `dep_preg`, `dep_opcion`) VALUES
  (1, 'Toma una foto de la fachada del local', 7, 0, NULL, NULL),
  (2, '¿El cliente tiene un Window Vision?', 1, 0, NULL, NULL),
  (3, 'Ingrese Foto de Window Vision', 7, 0, '¿El cliente tiene un Window Vision?', 'Sí'),
  (4, '¿Local tiene POP Exterior?', 1, 1, NULL, NULL),
  (5, 'Selecciona material POP en el exterior de la tienda:', 3, 1, '¿Local tiene POP Exterior?', 'Sí'),
  (6, 'FOTO DE LATA GIGANTE EXTERIOR', 7, 0, 'Selecciona material POP en el exterior de la tienda:', 'LATA GIGANTE'),
  (7, 'FOTO DE AFICHE EXTERIOR', 7, 0, 'Selecciona material POP en el exterior de la tienda:', 'AFICHE'),
  (8, 'FOTO DE PENDON', 7, 0, 'Selecciona material POP en el exterior de la tienda:', 'PENDON'),
  (9, '¿Local tiene Lata Gigante Amarilla?', 1, 1, NULL, NULL),
  (10, 'Foto lata gigante amarilla', 7, 0, '¿Local tiene Lata Gigante Amarilla?', 'Sí'),
  (11, '¿Local tiene POP interior?', 1, 1, NULL, NULL),
  (12, 'Selecciona material POP en el interior de la tienda:', 3, 1, '¿Local tiene POP interior?', 'Sí'),
  (13, 'FOTO DE AFICHE INTERIOR', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'AFICHE'),
  (14, 'FOTO DE COLGANTE', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'COLGANTE'),
  (15, 'FOTO DE FLEJERA', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'FLEJERA'),
  (16, 'FOTO DE STICKER', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'STICKER'),
  (17, 'FOTO DE STOPPER', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'STOPPER'),
  (18, 'FOTO DE LATA GIGANTE INTERIOR', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'LATA GIGANTE'),
  (19, 'FOTO DE OTROS', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'OTROS'),
  (20, 'Tipifique nombre del material', 4, 1, 'Selecciona material POP en el interior de la tienda:', 'OTROS'),
  (21, 'FOTO DE COLGANTE CON PRECIO', 7, 0, 'Selecciona material POP en el interior de la tienda:', 'COLGANTE CON PRECIO'),
  (22, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 3, 1, NULL, NULL),
  (23, 'Formatos de Red Bull disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Red Bull'),
  (24, 'INDIQUE PRECIO DE PRODUCTOS RED BULL 250ML', 5, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Red Bull'),
  (25, 'INDIQUE PRECIO DE PRODUCTOS RED BULL 355ML', 5, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Red Bull'),
  (26, 'INDIQUE PRECIO DE PRODUCTOS RED BULL 473', 5, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Red Bull'),
  (27, 'Formatos de Gatorade disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Gatorade'),
  (28, 'Formatos de Lipton disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Lipton'),
  (29, 'Formatos de Rockstar disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Rockstar'),
  (30, 'INDIQUE PRECIO DE SKU ROCKSTAR', 5, 0, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Rockstar'),
  (31, 'Formatos de Monster disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Monster'),
  (32, 'INDIQUE PRECIO DE PRODUCTOS MONSTER', 5, 0, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Monster'),
  (33, 'Formatos de Score disponibles en el local', 3, 1, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Score'),
  (34, 'INDIQUE PRECIO DE PRODUCTOS SCORE', 5, 0, '¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 'Score'),
  (35, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 1, 1, NULL, NULL),
  (36, '¿Cuántas puertas hay de cooler CCU?', 2, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (37, '¿Tenemos presencia de Red Bull en el cooler CCU?', 1, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (38, 'Seleccione Productos Red Bull Presente en Cooler CCU', 3, 1, '¿Tenemos presencia de Red Bull en el cooler CCU?', 'Sí'),
  (39, '¿El precio está debidamente comunicado en cooler CCU?', 1, 1, '¿Tenemos presencia de Red Bull en el cooler CCU?', 'Sí'),
  (40, 'TOMAR FOTO DEL PRECIO DEBIDAMENTE COMUNICADO', 7, 1, '¿El precio está debidamente comunicado en cooler CCU?', 'Sí'),
  (41, 'Tomar una foto del Planograma del Cooler CCU', 7, 0, '¿Tenemos presencia de Red Bull en el cooler CCU?', 'Sí'),
  (42, '¿Hay productos Gatorade en el cooler CCU?', 1, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (43, 'Marca los SKUs de Gatorade presentes en el cooler CCU', 3, 1, '¿Hay productos Gatorade en el cooler CCU?', 'Sí'),
  (44, 'Toma una foto del cooler CCU con productos Gatorade', 7, 0, '¿Hay productos Gatorade en el cooler CCU?', 'Sí'),
  (45, '¿Hay productos Lipton en el cooler CCU?', 1, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (46, 'Marca los SKUs de Lipton presentes en el cooler CCU', 3, 1, '¿Hay productos Lipton en el cooler CCU?', 'Sí'),
  (47, 'Toma una foto del cooler CCU con productos Lipton', 7, 0, '¿Hay productos Lipton en el cooler CCU?', 'Sí'),
  (48, '¿Hay productos Rockstar en el cooler CCU?', 1, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (49, '¿El cooler CCU se encuentra contaminado con productos de la competencia?', 3, 1, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 'Sí'),
  (50, '¿HAY BANDEJA EXCLUSIVA DE GATORADE? (4 Puertas)', 1, 1, '¿Cuántas puertas hay de cooler CCU?', '4'),
  (51, 'INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 2, 1, '¿Cuántas puertas hay de cooler CCU?', '4'),
  (52, '¿Hay Full Bandeja de Red Bull? (4 Puertas)', 1, 1, '¿Cuántas puertas hay de cooler CCU?', '4'),
  (53, 'TOMAR FOTO DE LA FULL BANDEJA', 7, 1, '¿Hay Full Bandeja de Red Bull? (4 Puertas)', 'Sí'),
  (54, '¿Existe cooler de Coca Cola?', 1, 1, NULL, NULL),
  (55, '¿Cuántas puertas tiene el cooler Coca Cola?', 2, 1, '¿Existe cooler de Coca Cola?', 'Sí'),
  (56, '¿Hay Monster en el cooler Coca Cola?', 1, 1, '¿Existe cooler de Coca Cola?', 'Sí'),
  (57, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 3, 1, '¿Hay Monster en el cooler Coca Cola?', 'Sí'),
  (58, 'Caras en Cooler de Monster Energy (regular)', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Energy (regular)'),
  (59, 'Caras en Cooler de Monster Absolutely Zero', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Absolutely Zero'),
  (60, 'Caras en Cooler de Monster Ultra (blanca)', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ultra (blanca)'),
  (61, 'Caras en Cooler de Monster Ultra Gold', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ultra Gold'),
  (62, 'Caras en Cooler de Monster Aussie Lemonade', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Aussie Lemonade'),
  (63, 'Caras en Cooler de Monster Ripper', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ripper'),
  (64, 'Caras en Cooler de Monster Mango Loco', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Mango Loco'),
  (65, 'Caras en Cooler de Monster Ultra Peachy', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ultra Peachy'),
  (66, 'Caras en Cooler de Monster Ultra Paradise', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ultra Paradise'),
  (67, 'Caras en Cooler de Monster Ultra Watermelon', 5, 1, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster Ultra Watermelon'),
  (68, 'Caras en Cooler de Monster PipeLane Punch', 5, 0, 'Marca SKUs de Monster presentes en el cooler Coca Cola', 'Monster PipeLane Punch'),
  (69, 'Toma una foto del cooler Coca Cola', 7, 0, '¿Existe cooler de Coca Cola?', 'Sí'),
  (70, '¿El PDV cuenta con cooler genérico?', 1, 1, NULL, NULL),
  (71, '¿El cooler genérico contiene algún tipo de producto Red Bull?', 1, 1, '¿El PDV cuenta con cooler genérico?', 'Sí'),
  (72, 'Foto de cooler genérico con Red Bull', 7, 0, '¿El cooler genérico contiene algún tipo de producto Red Bull?', 'Sí'),
  (73, '¿Existe cooler Red Bull en el local?', 1, 1, NULL, NULL),
  (74, '¿El cooler Red Bull se encuentra contaminado?', 1, 1, '¿Existe cooler Red Bull en el local?', 'Sí'),
  (75, '¿Qué productos contaminan el cooler Red Bull?', 3, 1, '¿El cooler Red Bull se encuentra contaminado?', 'Sí'),
  (76, 'Toma una foto del cooler Red Bull', 7, 1, '¿Existe cooler Red Bull en el local?', 'Sí'),
  (77, '¿El cooler Red Bull se encuentra revestido con una cabecera?', 1, 1, '¿Existe cooler Red Bull en el local?', 'Sí'),
  (78, '¿La cabecera está contaminada con otros productos?', 1, 1, '¿El cooler Red Bull se encuentra revestido con una cabecera?', 'Sí'),
  (79, '¿Qué productos contaminan la cabecera?', 3, 1, '¿La cabecera está contaminada con otros productos?', 'Sí'),
  (80, 'Señala nivel de stock de cabecera', 2, 1, '¿El cooler Red Bull se encuentra revestido con una cabecera?', 'Sí'),
  (81, 'Toma una foto de la cabecera', 7, 0, '¿El cooler Red Bull se encuentra revestido con una cabecera?', 'Sí'),
  (82, '¿El local tiene cooler de Score?', 1, 1, NULL, NULL),
  (83, 'Toma una foto del cooler Score', 7, 0, '¿El local tiene cooler de Score?', 'Sí'),
  (84, '¿El local tiene cooler de Monster?', 1, 1, NULL, NULL),
  (85, 'Toma una foto del cooler Monster', 7, 0, '¿El local tiene cooler de Monster?', 'Sí'),
  (86, '¿El local tiene cooler compartido de CCU/Red Bull?', 1, 1, NULL, NULL),
  (87, '¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS LIPTON?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (88, '¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS GATORLIT?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (89, '¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON 1/2 DE BANDEJA GATORADE?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (90, '¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON LAS 3 BANDEJAS RED BULL?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (91, '¿COOLER COMPARTIDO CC/RED BULL TIENE PRODUCTOS RED BULL?', 1, 1, '¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON LAS 3 BANDEJAS RED BULL?', 'No'),
  (92, '¿Hay productos de la competencia (Coca Cola, DESA, etc.) en el cooler compartido?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (93, 'Indica los productos de la competencia que contaminan cooler compartido:', 3, 1, '¿Hay productos de la competencia (Coca Cola, DESA, etc.) en el cooler compartido?', 'Sí'),
  (94, '¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 1, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (95, '¿Qué bandejas no cumplen con el layout?', 2, 0, '¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 'Sí'),
  (96, '¿Cuántas bandejas cumplen con el layout en este cliente?  Ingrese de 1 a 4', 5, 0, '¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 'Sí'),
  (97, 'Indica los productos faltantes:', 3, 1, '¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 'Sí'),
  (98, 'Tome una foto del cooler compartido CCU/Red Bull', 7, 1, '¿El local tiene cooler compartido de CCU/Red Bull?', 'Sí'),
  (99, '¿El cliente tiene cooler Gatorade VV-10?', 1, 0, NULL, NULL),
  (100, '¿El cooler Gatorade VV-10 tiene productos Gatorade?', 1, 1, '¿El cliente tiene cooler Gatorade VV-10?', 'Sí'),
  (101, 'Seleccione productos Gatorade presentes en el cooler VV-10', 3, 1, '¿El cooler Gatorade VV-10 tiene productos Gatorade?', 'Sí'),
  (102, '¿El cooler Gatorade VV-10 está contaminado con productos que no son CCU?', 1, 1, '¿El cliente tiene cooler Gatorade VV-10?', 'Sí'),
  (103, 'Marca productos que contaminan cooler Gatorade', 3, 1, '¿El cooler Gatorade VV-10 está contaminado con productos que no son CCU?', 'Sí'),
  (104, 'Toma foto del cooler Gatorade', 7, 0, '¿El cliente tiene cooler Gatorade VV-10?', 'Sí'),
  (105, '¿El local cuenta con Energy Checkout?', 1, 1, NULL, NULL),
  (106, '¿El Cooler del Energy Checkout se encuentra contaminado?', 1, 1, '¿El local cuenta con Energy Checkout?', 'Sí'),
  (107, 'Señala nivel de llenado de Energy Checkout:', 2, 0, '¿El local cuenta con Energy Checkout?', 'Sí'),
  (108, 'Toma una foto del Energy Checkout', 7, 1, '¿El local cuenta con Energy Checkout?', 'Sí'),
  (109, '¿El local tiene POP exterior de Monster?', 1, 1, NULL, NULL),
  (110, 'Toma una foto del POP exterior de Monster', 7, 0, '¿El local tiene POP exterior de Monster?', 'Sí'),
  (111, '¿Hay POP interior de Monster en el local?', 1, 1, NULL, NULL),
  (112, 'Toma una foto del POP Monster.', 7, 0, '¿Hay POP interior de Monster en el local?', 'Sí'),
  (113, 'Cliente tiene POP score?', 1, 1, NULL, NULL),
  (114, 'Ingrese foto de material POP Score', 7, 0, 'Cliente tiene POP score?', 'Sí'),
  (115, '¿Existe material POP Premium de Red Bull en el PDV?', 1, 1, NULL, NULL),
  (116, 'Indica qué material POP Premium se encuentra presente:', 3, 1, '¿Existe material POP Premium de Red Bull en el PDV?', 'Sí'),
  (117, 'Toma una foto del POP Premium', 7, 0, '¿Existe material POP Premium de Red Bull en el PDV?', 'Sí'),
  (118, '¿El local tiene POP exterior de Gatorade?', 1, 1, NULL, NULL),
  (119, 'Toma una foto del POP exterior de Gatorade', 7, 0, '¿El local tiene POP exterior de Gatorade?', 'Sí'),
  (120, '¿El local tiene POP exterior de Lipton?', 1, 1, NULL, NULL),
  (121, 'Toma una foto del POP exterior de Lipton', 7, 0, '¿El local tiene POP exterior de Lipton?', 'Sí'),
  (122, '¿El local tiene POP exterior de Powerade?', 1, 1, NULL, NULL),
  (123, 'Toma una foto del POP exterior de Powerade', 7, 0, '¿El local tiene POP exterior de Powerade?', 'Sí'),
  (124, 'OBSERVACION*', 4, 0, NULL, NULL);

DROP TEMPORARY TABLE IF EXISTS `tmp_ct3_opt`;
CREATE TEMPORARY TABLE `tmp_ct3_opt` (
  `preg`   VARCHAR(255) NOT NULL,
  `orden`  INT          NOT NULL,
  `texto`  VARCHAR(255) NOT NULL,
  `imagen` VARCHAR(255) NULL
) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;

INSERT INTO `tmp_ct3_opt` (`preg`, `orden`, `texto`, `imagen`) VALUES
  ('¿El cliente tiene un Window Vision?', 1, 'Sí', NULL),
  ('¿El cliente tiene un Window Vision?', 2, 'No', NULL),
  ('¿Local tiene POP Exterior?', 1, 'Sí', NULL),
  ('¿Local tiene POP Exterior?', 2, 'No', NULL),
  ('Selecciona material POP en el exterior de la tienda:', 1, 'LATA GIGANTE', '/uploads/opciones/opt_680634b264a7f.png'),
  ('Selecciona material POP en el exterior de la tienda:', 2, 'AFICHE', '/uploads/opciones/opt_68bb2e5f45eea6.22034777.png'),
  ('Selecciona material POP en el exterior de la tienda:', 3, 'PENDON', '/uploads/opciones/opt_6a47fc7de6ead8.70028132.webp'),
  ('¿Local tiene Lata Gigante Amarilla?', 1, 'Sí', '/uploads/opciones/opt_6970c42c27d133.40813505.webp'),
  ('¿Local tiene Lata Gigante Amarilla?', 2, 'No', NULL),
  ('¿Local tiene POP interior?', 1, 'Sí', NULL),
  ('¿Local tiene POP interior?', 2, 'No', NULL),
  ('Selecciona material POP en el interior de la tienda:', 1, 'AFICHE', '/uploads/opciones/opt_68063388d0921.png'),
  ('Selecciona material POP en el interior de la tienda:', 2, 'COLGANTE', '/uploads/opciones/opt_68063388d149b.png'),
  ('Selecciona material POP en el interior de la tienda:', 3, 'FLEJERA', '/uploads/opciones/opt_68063388d195e.png'),
  ('Selecciona material POP en el interior de la tienda:', 4, 'STICKER', '/uploads/opciones/opt_68063388d1ea8.png'),
  ('Selecciona material POP en el interior de la tienda:', 5, 'STOPPER', '/uploads/opciones/opt_68063388d23f2.png'),
  ('Selecciona material POP en el interior de la tienda:', 6, 'LATA GIGANTE', '/uploads/opciones/opt_689534f82d6a03.70326994_opt_680634b264a7f.png'),
  ('Selecciona material POP en el interior de la tienda:', 7, 'OTROS', NULL),
  ('Selecciona material POP en el interior de la tienda:', 8, 'COLGANTE CON PRECIO', '/uploads/opciones/opt_68a898823d89e5.00834662_COLGANTE PRECIO.png'),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 1, 'Red Bull', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 2, 'Gatorade', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 3, 'Lipton', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 4, 'Rockstar', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 5, 'Monster', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 6, 'Score', NULL),
  ('¿Existe disponibilidad de alguno de estos productos en alguna parte del local? (no importa ubicación)', 7, 'Ninguno de los anteriores', NULL),
  ('Formatos de Red Bull disponibles en el local', 1, 'Red Bull ED 250ml', '/uploads/opciones/opt_696e5cde1ae4a2.37849907.webp'),
  ('Formatos de Red Bull disponibles en el local', 2, 'Red Bull ED 355ml', '/uploads/opciones/opt_696e5cdeaa2e92.80609420.webp'),
  ('Formatos de Red Bull disponibles en el local', 3, 'Red Bull ED 473ml', '/uploads/opciones/opt_696e5cdf4c4707.26856940.webp'),
  ('Formatos de Red Bull disponibles en el local', 4, 'Red Bull SF 250ml', '/uploads/opciones/opt_696e5cdf76a5b3.16730850.webp'),
  ('Formatos de Red Bull disponibles en el local', 5, 'Red Bull SF 355ml', '/uploads/opciones/opt_696e5cdfaa4979.52056565.webp'),
  ('Formatos de Red Bull disponibles en el local', 6, 'Red Bull SF 473ml', '/uploads/opciones/opt_696e5cdfe37c09.91948915.webp'),
  ('Formatos de Red Bull disponibles en el local', 7, 'Red Bull Yellow Edition', '/uploads/opciones/opt_696e5ce01da420.49962654.webp'),
  ('Formatos de Red Bull disponibles en el local', 8, 'Red Bull Red Edition', '/uploads/opciones/opt_696e5ce040a337.56668949.webp'),
  ('Formatos de Red Bull disponibles en el local', 9, 'Red Bull Purple Edition', '/uploads/opciones/opt_696e5ce097ef56.58345626.webp'),
  ('Formatos de Red Bull disponibles en el local', 10, 'Red Bull Green Edition', '/uploads/opciones/opt_696e5ce1281502.39272283.webp'),
  ('Formatos de Red Bull disponibles en el local', 11, 'Red Bull Blue Edition', '/uploads/opciones/opt_696e5ce15f4de0.06941121.webp'),
  ('Formatos de Red Bull disponibles en el local', 12, 'Red Bull ZERO', '/uploads/opciones/opt_696e5ce20a8af8.54939865.webp'),
  ('Formatos de Red Bull disponibles en el local', 13, 'Red Bull POMELO', '/uploads/opciones/opt_6a26e41fa10e24.57963746.webp'),
  ('Formatos de Red Bull disponibles en el local', 14, 'Red Bull SPRING', '/uploads/opciones/opt_6aa2cf36689b01.06702066.webp'),
  ('Formatos de Gatorade disponibles en el local', 1, 'Productos Gatorade 500ml', NULL),
  ('Formatos de Gatorade disponibles en el local', 2, 'Productos Gatorade 750ml', NULL),
  ('Formatos de Gatorade disponibles en el local', 3, 'Productos Gatorade 1 LT (1000ml)', NULL),
  ('Formatos de Gatorade disponibles en el local', 4, 'Productos Gatorade Zero', NULL),
  ('Formatos de Gatorade disponibles en el local', 5, 'Productos Gatorlit 591ml', NULL),
  ('Formatos de Lipton disponibles en el local', 1, 'Productos Lipton 310ml', NULL),
  ('Formatos de Lipton disponibles en el local', 2, 'Productos Lipton 600ml', NULL),
  ('Formatos de Lipton disponibles en el local', 3, 'Productos Lipton 1.5L', NULL),
  ('Formatos de Rockstar disponibles en el local', 1, 'Rockstar Original', NULL),
  ('Formatos de Rockstar disponibles en el local', 2, 'Rockstar Mango', NULL),
  ('Formatos de Monster disponibles en el local', 1, 'Monster Energy (regular)', '/uploads/opciones/opt_690e0a53ee0836.48034411.webp'),
  ('Formatos de Monster disponibles en el local', 2, 'Monster Absolutely Zero', '/uploads/opciones/opt_690e0a545485b5.89859125.webp'),
  ('Formatos de Monster disponibles en el local', 3, 'Monster Ultra (blanca)', '/uploads/opciones/opt_690e0a5479b160.01138751.webp'),
  ('Formatos de Monster disponibles en el local', 4, 'Monster Ultra Gold', '/uploads/opciones/opt_690e0a54d1ab10.95817307.webp'),
  ('Formatos de Monster disponibles en el local', 5, 'Monster Ultra Watermelon', '/uploads/opciones/opt_690e0a55809fa7.12443501.webp'),
  ('Formatos de Monster disponibles en el local', 6, 'Monster Ultra Paradise', '/uploads/opciones/opt_690e0a56078188.94001860.webp'),
  ('Formatos de Monster disponibles en el local', 7, 'Monster Ultra Peachy', '/uploads/opciones/opt_690e0a56364d07.15640801.webp'),
  ('Formatos de Monster disponibles en el local', 8, 'Monster Mango Loco', '/uploads/opciones/opt_690e0a56803d77.55118028.webp'),
  ('Formatos de Monster disponibles en el local', 9, 'Monster Ripper', '/uploads/opciones/opt_690e0a570918e3.00497788.webp'),
  ('Formatos de Monster disponibles en el local', 10, 'Monster Aussie Lemonade', '/uploads/opciones/opt_690e0a57edc550.36400053.webp'),
  ('Formatos de Monster disponibles en el local', 11, 'Monster PipeLane Punch', '/uploads/opciones/opt_690e085ba54b99.80653524.webp'),
  ('Formatos de Score disponibles en el local', 1, 'Score Original', NULL),
  ('Formatos de Score disponibles en el local', 2, 'Score Gorilla', NULL),
  ('Formatos de Score disponibles en el local', 3, 'Score Gorilla Zero', NULL),
  ('Formatos de Score disponibles en el local', 4, 'Score Frosted', NULL),
  ('Formatos de Score disponibles en el local', 5, 'Score Fruit Punch', NULL),
  ('Formatos de Score disponibles en el local', 6, 'Score Bubble Gum', NULL),
  ('Formatos de Score disponibles en el local', 7, 'Score Mojito', NULL),
  ('Formatos de Score disponibles en el local', 8, 'Score Mango', NULL),
  ('Formatos de Score disponibles en el local', 9, 'Score Water', NULL),
  ('¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 1, 'Sí', NULL),
  ('¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)', 2, 'No', NULL),
  ('¿Cuántas puertas hay de cooler CCU?', 1, '1', NULL),
  ('¿Cuántas puertas hay de cooler CCU?', 2, '2', NULL),
  ('¿Cuántas puertas hay de cooler CCU?', 3, '3', NULL),
  ('¿Cuántas puertas hay de cooler CCU?', 4, '4', NULL),
  ('¿Tenemos presencia de Red Bull en el cooler CCU?', 1, 'Sí', NULL),
  ('¿Tenemos presencia de Red Bull en el cooler CCU?', 2, 'No', NULL),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 1, 'Red Bull ED 250ml', '/uploads/opciones/opt_696e5b5fb3e5a7.90505778.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 2, 'Red Bull ED 355ml', '/uploads/opciones/opt_696e5b6042c742.74547629.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 3, 'Red Bull ED 473ml', '/uploads/opciones/opt_696e5b60cf0f81.46870756.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 4, 'Red Bull SF 250ml', '/uploads/opciones/opt_696e5b61068e41.23255118.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 5, 'Red Bull SF 355ml', '/uploads/opciones/opt_696e5b6133b9c8.41618147.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 6, 'Red Bull SF 473ml', '/uploads/opciones/opt_696e5b615db921.55970751.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 7, 'Red Bull Yellow Edition', '/uploads/opciones/opt_696e5b6183da21.54955757.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 8, 'Red Bull Red Edition', '/uploads/opciones/opt_696e5b619fb9f3.40217258.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 9, 'Red Bull Purple Edition', '/uploads/opciones/opt_696e5b61e5a739.83437624.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 10, 'Red Bull Green Edition', '/uploads/opciones/opt_696e5b625fffc4.98657404.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 11, 'Red Bull Blue Edition', '/uploads/opciones/opt_696e5b62968b71.31224100.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 12, 'Red Bull ZERO', '/uploads/opciones/opt_696e5b63404533.19066017.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 13, 'Red Bull POMELO', '/uploads/opciones/opt_6a26e444886c28.32984331.webp'),
  ('Seleccione Productos Red Bull Presente en Cooler CCU', 14, 'Red Bull SPRING', '/uploads/opciones/opt_6aa2cf560f9490.74603538.webp'),
  ('¿El precio está debidamente comunicado en cooler CCU?', 1, 'Sí', NULL),
  ('¿El precio está debidamente comunicado en cooler CCU?', 2, 'No', NULL),
  ('¿Hay productos Gatorade en el cooler CCU?', 1, 'Sí', NULL),
  ('¿Hay productos Gatorade en el cooler CCU?', 2, 'No', NULL),
  ('Marca los SKUs de Gatorade presentes en el cooler CCU', 1, 'Productos Gatorade 500ml', NULL),
  ('Marca los SKUs de Gatorade presentes en el cooler CCU', 2, 'Productos Gatorade 750ml', NULL),
  ('Marca los SKUs de Gatorade presentes en el cooler CCU', 3, 'Productos Gatorade 1 LT (1000ml)', NULL),
  ('Marca los SKUs de Gatorade presentes en el cooler CCU', 4, 'Productos Gatorlit 591ml', NULL),
  ('¿Hay productos Lipton en el cooler CCU?', 1, 'Sí', NULL),
  ('¿Hay productos Lipton en el cooler CCU?', 2, 'No', NULL),
  ('Marca los SKUs de Lipton presentes en el cooler CCU', 1, 'Productos Lipton 310ml', NULL),
  ('Marca los SKUs de Lipton presentes en el cooler CCU', 2, 'Productos Lipton 600ml', NULL),
  ('Marca los SKUs de Lipton presentes en el cooler CCU', 3, 'Productos Lipton 1.5L', NULL),
  ('¿Hay productos Rockstar en el cooler CCU?', 1, 'Sí', NULL),
  ('¿Hay productos Rockstar en el cooler CCU?', 2, 'No', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 1, 'Productos Coca Cola', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 2, 'Monster', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 3, 'Score', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 4, 'Powerade', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 5, 'Suerox', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 6, 'Fastlyte', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 7, 'Electrolit', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 8, 'Glaceau Vitamin Water', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 9, 'Otros', NULL),
  ('¿El cooler CCU se encuentra contaminado con productos de la competencia?', 10, 'No esta contaminado', NULL),
  ('¿HAY BANDEJA EXCLUSIVA DE GATORADE? (4 Puertas)', 1, 'Sí', NULL),
  ('¿HAY BANDEJA EXCLUSIVA DE GATORADE? (4 Puertas)', 2, 'No', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 1, '0', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 2, '1', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 3, '2', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 4, '3', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 5, '4', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 6, '5', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 7, '6', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 8, '7', NULL),
  ('INDIQUE CANTIDAD DE BANDEJAS CON PRESENCIA DE GATORADE (4 Puertas)', 9, '8 O +', NULL),
  ('¿Hay Full Bandeja de Red Bull? (4 Puertas)', 1, 'Sí', NULL),
  ('¿Hay Full Bandeja de Red Bull? (4 Puertas)', 2, 'No', NULL),
  ('¿Existe cooler de Coca Cola?', 1, 'Sí', NULL),
  ('¿Existe cooler de Coca Cola?', 2, 'No', NULL),
  ('¿Cuántas puertas tiene el cooler Coca Cola?', 1, '1', NULL),
  ('¿Cuántas puertas tiene el cooler Coca Cola?', 2, '2', NULL),
  ('¿Cuántas puertas tiene el cooler Coca Cola?', 3, '3', NULL),
  ('¿Cuántas puertas tiene el cooler Coca Cola?', 4, '4', NULL),
  ('¿Cuántas puertas tiene el cooler Coca Cola?', 5, '5', NULL),
  ('¿Hay Monster en el cooler Coca Cola?', 1, 'Sí', NULL),
  ('¿Hay Monster en el cooler Coca Cola?', 2, 'No', NULL),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 1, 'Monster Energy (regular)', '/uploads/opciones/opt_69139e3c83c822.53313423.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 2, 'Monster Absolutely Zero', '/uploads/opciones/opt_69139e3ce0d821.96320617.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 3, 'Monster Ultra (blanca)', '/uploads/opciones/opt_69139e3d12ec47.51082487.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 4, 'Monster Ultra Gold', '/uploads/opciones/opt_69139e3d640f33.26565930.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 5, 'Monster Aussie Lemonade', '/uploads/opciones/opt_69148b72043b96.94309986.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 6, 'Monster Ripper', '/uploads/opciones/opt_69148bac324ce8.13729432.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 7, 'Monster Mango Loco', '/uploads/opciones/opt_69148c8ba62be0.51356463.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 8, 'Monster Ultra Peachy', '/uploads/opciones/opt_69148c8c100df4.99848571.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 9, 'Monster Ultra Paradise', '/uploads/opciones/opt_69148c8c64eca0.78367447.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 10, 'Monster Ultra Watermelon', '/uploads/opciones/opt_69148c8cdd1749.83860769.webp'),
  ('Marca SKUs de Monster presentes en el cooler Coca Cola', 11, 'Monster PipeLane Punch', '/uploads/opciones/opt_69270febd7fb41.88087110.webp'),
  ('¿El PDV cuenta con cooler genérico?', 1, 'Sí', NULL),
  ('¿El PDV cuenta con cooler genérico?', 2, 'No', NULL),
  ('¿El cooler genérico contiene algún tipo de producto Red Bull?', 1, 'Sí', NULL),
  ('¿El cooler genérico contiene algún tipo de producto Red Bull?', 2, 'No', NULL),
  ('¿Existe cooler Red Bull en el local?', 1, 'Sí', NULL),
  ('¿Existe cooler Red Bull en el local?', 2, 'No', NULL),
  ('¿El cooler Red Bull se encuentra contaminado?', 1, 'Sí', NULL),
  ('¿El cooler Red Bull se encuentra contaminado?', 2, 'No', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 1, 'CCU', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 2, 'Coca Cola Andina', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 3, 'Monster', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 4, 'Score', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 5, 'Rockstar', NULL),
  ('¿Qué productos contaminan el cooler Red Bull?', 6, 'Otros', NULL),
  ('¿El cooler Red Bull se encuentra revestido con una cabecera?', 1, 'Sí', '/uploads/opciones/opt_6806282e6613f.png'),
  ('¿El cooler Red Bull se encuentra revestido con una cabecera?', 2, 'No', NULL),
  ('¿La cabecera está contaminada con otros productos?', 1, 'Sí', NULL),
  ('¿La cabecera está contaminada con otros productos?', 2, 'No', NULL),
  ('¿Qué productos contaminan la cabecera?', 1, 'Monster', NULL),
  ('¿Qué productos contaminan la cabecera?', 2, 'Score', NULL),
  ('¿Qué productos contaminan la cabecera?', 3, 'Productos Coca Cola Andina', NULL),
  ('¿Qué productos contaminan la cabecera?', 4, 'Productos CCU', NULL),
  ('¿Qué productos contaminan la cabecera?', 5, 'Abarrotes (papas fritas, frutos secos, chocolates, etc.)', NULL),
  ('¿Qué productos contaminan la cabecera?', 6, 'Otros', NULL),
  ('Señala nivel de stock de cabecera', 1, 'Alto', '/uploads/opciones/opt_68062a17598f8.jpg'),
  ('Señala nivel de stock de cabecera', 2, 'Medio', '/uploads/opciones/opt_68062a1759d36.jpg'),
  ('Señala nivel de stock de cabecera', 3, 'Bajo', '/uploads/opciones/opt_68062a175a107.png'),
  ('¿El local tiene cooler de Score?', 1, 'Sí', NULL),
  ('¿El local tiene cooler de Score?', 2, 'No', NULL),
  ('¿El local tiene cooler de Monster?', 1, 'Sí', NULL),
  ('¿El local tiene cooler de Monster?', 2, 'No', NULL),
  ('¿El local tiene cooler compartido de CCU/Red Bull?', 1, 'Sí', '/uploads/opciones/opt_692973ea5ad876.81346637.webp'),
  ('¿El local tiene cooler compartido de CCU/Red Bull?', 2, 'No', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS LIPTON?', 1, 'Sí', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS LIPTON?', 2, 'No', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS GATORLIT?', 1, 'Sí', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL TIENE PRODUCTOS GATORLIT?', 2, 'No', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON 1/2 DE BANDEJA GATORADE?', 1, 'Sí', '/uploads/opciones/opt_692976372ffb51.06143935.webp'),
  ('¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON 1/2 DE BANDEJA GATORADE?', 2, 'No', NULL),
  ('¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON LAS 3 BANDEJAS RED BULL?', 1, 'Sí', '/uploads/opciones/opt_6929748cc3d8d1.29568202.png'),
  ('¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON LAS 3 BANDEJAS RED BULL?', 2, 'No', NULL),
  ('¿COOLER COMPARTIDO CC/RED BULL TIENE PRODUCTOS RED BULL?', 1, 'Sí', NULL),
  ('¿COOLER COMPARTIDO CC/RED BULL TIENE PRODUCTOS RED BULL?', 2, 'No', NULL),
  ('¿Hay productos de la competencia (Coca Cola, DESA, etc.) en el cooler compartido?', 1, 'Sí', NULL),
  ('¿Hay productos de la competencia (Coca Cola, DESA, etc.) en el cooler compartido?', 2, 'No', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 1, 'Coca Cola', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 2, 'Monster', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 3, 'Score', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 4, 'Powerade', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 5, 'Vital', NULL),
  ('Indica los productos de la competencia que contaminan cooler compartido:', 6, 'Otros', NULL),
  ('¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 1, 'Sí', '/uploads/opciones/opt_692976c9a0dcb1.97390164.webp'),
  ('¿Hay productos CCU que no corresponden al layout pero están en el cooler?', 2, 'No', NULL),
  ('¿Qué bandejas no cumplen con el layout?', 1, '1', NULL),
  ('¿Qué bandejas no cumplen con el layout?', 2, '2', NULL),
  ('¿Qué bandejas no cumplen con el layout?', 3, '3', NULL),
  ('¿Qué bandejas no cumplen con el layout?', 4, '4', NULL),
  ('¿Qué bandejas no cumplen con el layout?', 5, 'Todas cumplen el layout', NULL),
  ('Indica los productos faltantes:', 1, 'Gatorade Frutos Rojos 750ml', NULL),
  ('Indica los productos faltantes:', 2, 'Gatorade Cool Blue 750ml', NULL),
  ('Indica los productos faltantes:', 3, 'Gatorade Frutos Rojos 1000ml', NULL),
  ('Indica los productos faltantes:', 4, 'Gatorade Cool Blue 1000ml', NULL),
  ('Indica los productos faltantes:', 5, 'Cachantun PET 600cc sin gas', NULL),
  ('Indica los productos faltantes:', 6, 'Cachantun PET 600cc con gas', NULL),
  ('Indica los productos faltantes:', 7, 'Lipton PET 600cc', NULL),
  ('Indica los productos faltantes:', 8, 'Rockstar Original', NULL),
  ('Indica los productos faltantes:', 9, 'Rockstar Mango', NULL),
  ('Indica los productos faltantes:', 10, 'Gatorlit', NULL),
  ('¿El cliente tiene cooler Gatorade VV-10?', 1, 'Sí', '/uploads/opciones/opt_68062f8f68c33.webp'),
  ('¿El cliente tiene cooler Gatorade VV-10?', 2, 'No', NULL),
  ('¿El cooler Gatorade VV-10 tiene productos Gatorade?', 1, 'Sí', NULL),
  ('¿El cooler Gatorade VV-10 tiene productos Gatorade?', 2, 'No', NULL),
  ('Seleccione productos Gatorade presentes en el cooler VV-10', 1, 'Productos Gatorade 500ml', NULL),
  ('Seleccione productos Gatorade presentes en el cooler VV-10', 2, 'Productos Gatorade 750ml', NULL),
  ('Seleccione productos Gatorade presentes en el cooler VV-10', 3, 'Productos Gatorade 1 LT (1000ml)', NULL),
  ('Seleccione productos Gatorade presentes en el cooler VV-10', 4, 'Productos Gatorade Zero', NULL),
  ('¿El cooler Gatorade VV-10 está contaminado con productos que no son CCU?', 1, 'Sí', NULL),
  ('¿El cooler Gatorade VV-10 está contaminado con productos que no son CCU?', 2, 'No', NULL),
  ('Marca productos que contaminan cooler Gatorade', 1, 'Powerade', NULL),
  ('Marca productos que contaminan cooler Gatorade', 2, 'Electrolit', NULL),
  ('Marca productos que contaminan cooler Gatorade', 3, 'Suerox', NULL),
  ('Marca productos que contaminan cooler Gatorade', 4, 'Coca Cola (marcas varias)', NULL),
  ('Marca productos que contaminan cooler Gatorade', 5, 'Otros', NULL),
  ('¿El local cuenta con Energy Checkout?', 1, 'Sí', '/uploads/opciones/opt_680631d469aba3.58350639_CHECK OUT.png'),
  ('¿El local cuenta con Energy Checkout?', 2, 'No', NULL),
  ('¿El Cooler del Energy Checkout se encuentra contaminado?', 1, 'Sí', NULL),
  ('¿El Cooler del Energy Checkout se encuentra contaminado?', 2, 'No', NULL),
  ('Señala nivel de llenado de Energy Checkout:', 1, 'Alto', '/uploads/opciones/opt_6806329eaa1a7.jpg'),
  ('Señala nivel de llenado de Energy Checkout:', 2, 'Medio', '/uploads/opciones/opt_6806329eaa60a.png'),
  ('Señala nivel de llenado de Energy Checkout:', 3, 'Bajo/Sin Stock', '/uploads/opciones/opt_6806329eaaf48.png'),
  ('¿El local tiene POP exterior de Monster?', 1, 'Sí', NULL),
  ('¿El local tiene POP exterior de Monster?', 2, 'No', NULL),
  ('¿Hay POP interior de Monster en el local?', 1, 'Sí', NULL),
  ('¿Hay POP interior de Monster en el local?', 2, 'No', NULL),
  ('Cliente tiene POP score?', 1, 'Sí', NULL),
  ('Cliente tiene POP score?', 2, 'No', NULL),
  ('¿Existe material POP Premium de Red Bull en el PDV?', 1, 'Sí', NULL),
  ('¿Existe material POP Premium de Red Bull en el PDV?', 2, 'No', NULL),
  ('Indica qué material POP Premium se encuentra presente:', 1, 'Rhombus Logo Box', NULL),
  ('Indica qué material POP Premium se encuentra presente:', 2, 'Illuminated Kiosk Can', NULL),
  ('Indica qué material POP Premium se encuentra presente:', 3, 'Siren', NULL),
  ('¿El local tiene POP exterior de Gatorade?', 1, 'Sí', NULL),
  ('¿El local tiene POP exterior de Gatorade?', 2, 'No', NULL),
  ('¿El local tiene POP exterior de Lipton?', 1, 'Sí', NULL),
  ('¿El local tiene POP exterior de Lipton?', 2, 'No', NULL),
  ('¿El local tiene POP exterior de Powerade?', 1, 'Sí', NULL),
  ('¿El local tiene POP exterior de Powerade?', 2, 'No', NULL);

-- 3) Preguntas ----------------------------------------------------------------
INSERT INTO `question_set_questions`
  (`id_question_set`, `question_text`, `id_question_type`, `sort_order`, `is_required`, `id_dependency_option`, `is_valued`)
SELECT @set, t.`texto`, t.`tipo`, t.`orden`, t.`oblig`, NULL, 0
FROM `tmp_ct3_preg` t
WHERE NOT EXISTS (
  SELECT 1 FROM `question_set_questions` q
  WHERE q.`id_question_set` = @set AND q.`question_text` = t.`texto`
);

-- 4) Opciones ------------------------------------------------------------------
INSERT INTO `question_set_options` (`id_question_set_question`, `option_text`, `reference_image`, `sort_order`)
SELECT q.`id`, o.`texto`, o.`imagen`, o.`orden`
FROM `tmp_ct3_opt` o
JOIN `question_set_questions` q
  ON q.`id_question_set` = @set AND q.`question_text` = o.`preg`
WHERE NOT EXISTS (
  SELECT 1 FROM `question_set_options` so
  WHERE so.`id_question_set_question` = q.`id` AND so.`option_text` = o.`texto`
);

-- 5) Dependencias (se resuelven despues, cuando las opciones ya tienen id) ------
UPDATE `question_set_questions` hijo
JOIN `tmp_ct3_preg` t
  ON t.`texto` = hijo.`question_text` AND t.`dep_preg` IS NOT NULL
JOIN `question_set_questions` padre
  ON padre.`id_question_set` = @set AND padre.`question_text` = t.`dep_preg`
JOIN `question_set_options` op
  ON op.`id_question_set_question` = padre.`id` AND op.`option_text` = t.`dep_opcion`
SET hijo.`id_dependency_option` = op.`id`
WHERE hijo.`id_question_set` = @set;

-- 6) Las dependencias que la especificacion pedia y no se resolvieron -----------
--    (si esto deja filas, algun texto de padre u opcion no coincide: revisar antes
--     de usar el set). Se guarda en una temporal para poder mirarla despues del COMMIT.
DROP TEMPORARY TABLE IF EXISTS `tmp_ct3_sin_resolver`;
CREATE TEMPORARY TABLE `tmp_ct3_sin_resolver` AS
SELECT t.`orden`, t.`texto`, t.`dep_preg`, t.`dep_opcion`
FROM `tmp_ct3_preg` t
JOIN `question_set_questions` q
  ON q.`id_question_set` = @set AND q.`question_text` = t.`texto`
WHERE t.`dep_preg` IS NOT NULL AND q.`id_dependency_option` IS NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_ct3_preg`;
DROP TEMPORARY TABLE IF EXISTS `tmp_ct3_opt`;

COMMIT;

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SET @set := (SELECT id FROM question_set WHERE nombre_set = 'CANAL TRADICIONAL 3.0');
--
--   SELECT COUNT(*) FROM question_set_questions WHERE id_question_set = @set;
--     -> 124
--
--   SELECT COUNT(*) FROM question_set_options o
--     JOIN question_set_questions q ON q.id = o.id_question_set_question
--    WHERE q.id_question_set = @set;
--     -> 257
--
--   SELECT COUNT(*) FROM question_set_questions
--    WHERE id_question_set = @set AND id_dependency_option IS NOT NULL;
--     -> 101
--
--   -- Dependencias que no se pudieron resolver:
--   SELECT * FROM tmp_ct3_sin_resolver;
--     -> 0 filas
--
--   -- Y que ninguna dependencia apunte a una opcion de OTRO set:
--   SELECT q.sort_order, q.question_text
--     FROM question_set_questions q
--     LEFT JOIN question_set_options o  ON o.id = q.id_dependency_option
--     LEFT JOIN question_set_questions p ON p.id = o.id_question_set_question
--    WHERE q.id_question_set = @set
--      AND q.id_dependency_option IS NOT NULL
--      AND (p.id IS NULL OR p.id_question_set <> @set);
--     -> 0 filas


-- ---------------------------------------------------------------------------
-- Apendice: que sale del set 66 (160 preguntas) para llegar a este (124)
-- Las respuestas indicadas son el historico del set 66 sobre el volcado del
-- 2026-09-10. NO se pierden: el set 66 queda intacto y sus campanas tambien.
-- ---------------------------------------------------------------------------
--
-- SE ELIMINAN, 20 preguntas (203.641 respuestas historicas):
--   Replicas por numero de puertas, que se disparaban ADEMAS de la pregunta directa
--   (las dos ramas colgaban de "tiene cooler CCU? = Si", asi que salian siempre):
--     Q1245 / Q1244 / Q1243          Gatorlit (1, 2 y 3 puertas). Ademas Gatorlit 591ml
--                                    ya es una opcion de la lista de SKUs de Gatorade.
--     Q1326 / Q1304 / Q1275 / Q1249  Lipton   (1, 2, 3 y 4 puertas)
--     Q1328 / Q1301 / Q1297 / Q1251  Gatorade (1, 2, 3 y 4 puertas)
--     Q1330 / Q1303 / Q1274 / Q1248  Rockstar (1, 2, 3 y 4 puertas)
--     Q1277  "Tenemos presencia de Red Bull en el cooler CCU?  -3-": copia literal de
--            Q1331 con un sufijo, para la rama de 3 puertas.
--   Preguntas de prueba en produccion (0 respuestas):
--     Q1442 "prueba", Q1444 "prueba1", Q1446 "prueba no", Q1448 "prueba si".
--     Q1442 ademas dependia de su propia opcion (3029): dependencia circular.
--
-- SE FUSIONAN:
--   Q1350 + Q1294 + Q1300 + Q1273  ->  una sola contaminacion de cooler CCU. Eran
--     cuatro con LISTAS DE OPCIONES DISTINTAS (Coca Cola/Monster/Score/Otros por un
--     lado; Powerade/Suerox/Fastlyte/Electrolit por otro; una con Glaceau Vitamin
--     Water), o sea no agregables entre si. La nueva usa la union de las cuatro.
--   Q1455 + Q1700  ->  "Hay Full Bandeja de Red Bull? (4 Puertas)". Eran "hay?" y
--     "pudiste ejecutarla?", cada una con su propia foto de la misma bandeja.
--   Q1482 + Q1657  ->  "Tome una foto del cooler compartido CCU/Red Bull". Eran dos
--     fotos del mismo cooler colgando de la misma pregunta padre.
--   Q1454 + Q1698 + Q1699  ->  opciones "Red Bull" / "Monster" / "Score" de la unica
--     pregunta de DISPONIBILIDAD. Gatorade, Lipton y Rockstar no tenian equivalente:
--     solo se median dentro del cooler CCU, asi que un local que los tuviera en
--     gondola y no en cooler no quedaba registrado en ninguna parte.
--
-- SE RENOMBRAN (mismo dato; texto unico para que el CSV de encuestas pivotee bien):
--   Las 10 "Marca los SKUs presentes Y Precio" pasan a nombrar producto y ubicacion.
--   Q1221 / Q1227 "FOTO DE LATA GIGANTE"  ->  ...EXTERIOR / ...INTERIOR.
--   Q1362 "Caras de Cooler..." y Q1355 "Caras en Cooler de Monster de Monster..."
--     quedan con la misma forma que sus nueve hermanas.
--
-- CAMBIO DE COBERTURA A TENER EN CUENTA
-- -------------------------------------
-- Monster y Score se preguntaban con un Si/No obligatorio que respondian las 1.370
-- visitas de la campana 3094. Ahora son opciones de una multiple: el "No" deja de ser
-- explicito y pasa a ser "no marcado". Por eso la lista incluye "Ninguno de los
-- anteriores", que es el negativo explicito. Las series historicas de esas dos
-- preguntas no son comparables columna a columna con las nuevas.

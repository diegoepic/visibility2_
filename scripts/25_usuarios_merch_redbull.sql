-- ============================================================
-- 25_usuarios_merch_redbull.sql
-- Alta de merchandisers Red Bull / Transportes Martinez
-- Fuente: correo tmartine9@gmail.com del 21-08-2026 16:34
--         (reenviado por Manuel Gomez el 24-08-2026)
--
-- Replica exactamente lo que hace portal/modulos/mod_user/procesar.php:
--   * rut     -> sin puntos ni guion, en mayuscula
--   * nombre/apellido/email/usuario -> mb_strtoupper
--   * clave   -> password_hash() bcrypt $2y$10$
--   * fechaCreacion=NOW(), activo=1, login_count=0, last_login=NULL
--   * tipo_usuario=0, id_perfil=3 (ejecutor), id_empresa=103, id_division=14 (REDBULL)
--
-- Los 11 RUT fueron validados con modulo 11: TODOS correctos.
-- Ninguno existe hoy en la tabla usuario (verificado contra el respaldo).
--
-- OJO: el DISTRITO del correo (FARMACIA, PETROLERA, MAIPU, ...) NO es una
-- columna de usuario: vive en local.id_distrito. Se asigna despues, armando
-- las rutas de cada merch. Aqui solo queda el canal (subdivision).
-- ============================================================

START TRANSACTION;

INSERT INTO `usuario`
  (`rut`, `nombre`, `apellido`, `telefono`, `email`, `usuario`, `fotoPerfil`, `clave`,
   `fechaCreacion`, `activo`, `id_empresa`, `id_division`, `id_subdivision`,
   `login_count`, `last_login`, `id_perfil`, `tipo_usuario`, `clasificacion_usuario`, `puede_adelantar_ruta`)
VALUES
  -- ABIGAIL   HERNANDEZ BORIC     17244435-0   canal MODERNO     distrito FARMACIA
  ('172444350', 'ABIGAIL', 'HERNANDEZ BORIC', '0000000000', 'ABIGAIL.HERNANDEZ@LOCALMENTE.CL', 'AHERNANDEZ', NULL, '$2y$10$Zi3pDpF3IixpOG3hJUBhy.TblBjQq2n4Ds1l/gJAHrxql3igCN3pm',
   NOW(), 1, 103, 14, 2, 0, NULL, 3, 0, 'externo', 1),
  -- NICOLAS   ALFARO CHEUQUEPAN   21453902-0   canal MODERNO     distrito PETROLERA
  ('214539020', 'NICOLAS', 'ALFARO CHEUQUEPAN', '0000000000', 'NICOLAS.ALFARO@LOCALMENTE.CL', 'NALFARO', NULL, '$2y$10$HdFWzf4q4NVqfWJt4Vy8j.KPnq3kDxYyVRrMm0L.ZtU9oNXgTq7xO',
   NOW(), 1, 103, 14, 2, 0, NULL, 3, 0, 'externo', 1),
  -- JAVIER    ABARCA CONCHA       19845417-6   canal MODERNO     distrito CONVENIENCIA
  ('198454176', 'JAVIER', 'ABARCA CONCHA', '0000000000', 'JAVIER.ABARCA@LOCALMENTE.CL', 'JABARCA', NULL, '$2y$10$d/8/i8lP35fQDliaa3ozq.FVKGXsqNfoc8/2uGxhB/q.d2WuDm1ji',
   NOW(), 1, 103, 14, 2, 0, NULL, 3, 0, 'externo', 1),
  -- SALVADOR  NARVAES ASTUDILLO   18075707-4   canal MODERNO     distrito PETROLERA
  ('180757074', 'SALVADOR', 'NARVAES ASTUDILLO', '0000000000', 'SALVADOR.NARVAES@LOCALMENTE.CL', 'SNARVAES', NULL, '$2y$10$7eIdvXuaPeKGRd3Szwe.Uem3cvMbb.bZ4J5w5bMEOhraaVqUltNDC',
   NOW(), 1, 103, 14, 2, 0, NULL, 3, 0, 'externo', 1),
  -- MATIAS    LIVERSON PERAL      20379364-2   canal TRADICIONAL distrito FLORIDA
  ('203793642', 'MATIAS', 'LIVERSON PERAL', '0000000000', 'MATIAS.LIVERSON@LOCALMENTE.CL', 'MLIVERSON', NULL, '$2y$10$boPzJhl8qsvpeKiBIgqezuMfNCm8RyauTh7bTu4u936xLD6EHx.5a',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- CARLOS    CRUZ MENA           16742332-9   canal TRADICIONAL distrito MAIPU
  ('167423329', 'CARLOS', 'CRUZ MENA', '0000000000', 'CARLOS.CRUZ@LOCALMENTE.CL', 'CCRUZ', NULL, '$2y$10$UNm8IANPuzgKYukMVwgzCe4js0T33XLMLejekKp7mZD2O4Rm1iX0q',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- JACOB     NIETO CABRERA       18880021-1   canal TRADICIONAL distrito VESPUCIO
  ('188800211', 'JACOB', 'NIETO CABRERA', '0000000000', 'JACOB.NIETO@LOCALMENTE.CL', 'JNIETO', NULL, '$2y$10$iVNxx/fKZL0lT.sD3oFo7OK8bM8dA6xvdkr7caqZSoItE.H2Ynp7K',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- ABRAHAM   MUNOZ SUBE          20452285-5   canal TRADICIONAL distrito MODELO
  ('204522855', 'ABRAHAM', 'MUNOZ SUBE', '0000000000', 'ABRAHAM.MUNOZ@LOCALMENTE.CL', 'ABMUNOZ', NULL, '$2y$10$3hIYlb2JRsIpsAi.QOEY1uObqj3gFSYScttEIW1o5G38yaI6Ng/4S',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- BRYAN     PINILLA VALENZUELA  18191962-0   canal TRADICIONAL distrito FLORIDA
  ('181919620', 'BRYAN', 'PINILLA VALENZUELA', '0000000000', 'BRYAN.PINILLA@LOCALMENTE.CL', 'BPINILLA', NULL, '$2y$10$DDQ5RgDqRRAivK3wwMHUi.GeHb2uuRPgh5GSYRvYKXAgkPMrg3LnO',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- RODRIGO   VERA CAMUS          14184096-7   canal TRADICIONAL distrito VESPUCIO
  ('141840967', 'RODRIGO', 'VERA CAMUS', '0000000000', 'RODRIGO.VERA@LOCALMENTE.CL', 'RVERA', NULL, '$2y$10$cHlysx30zkUjwl8m8uQSBOVcm2ul1mZv43q6NviZaEJF/.g4L/y/y',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1),
  -- ELENA     CORDOVA CARRERA     16250444-4   canal TRADICIONAL distrito RANCAGUA
  ('162504444', 'ELENA', 'CORDOVA CARRERA', '0000000000', 'ELENA.CORDOVA@LOCALMENTE.CL', 'ECORDOVA', NULL, '$2y$10$qBmNltGF/NRYv5uORMSLhuEXR.yvVEHChk9q1aXaEwTOr18ocARGK',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'externo', 1);

-- ------------------------------------------------------------
-- ARIEL GARRIDO LOPEZ 15930540-6: el correo lo marca CREAR, pero YA EXISTE
-- (id=267, AGARRIDO) y esta DESACTIVADO. No hay que crearlo de nuevo: eso
-- duplicaria el RUT y romperia el ingreso por RUT del 01-09. Se reactiva.
-- El correo ademas lo mueve a canal TM MAYORISTA -> queda en TRADICIONAL (1).
-- ------------------------------------------------------------
UPDATE `usuario` SET `activo` = 1, `id_subdivision` = 1 WHERE `id` = 267;

-- ------------------------------------------------------------
-- MODIFICAR CANAL (ya existen, solo cambia la subdivision)
-- CRISTIAN MORRO  20453352-0 id=266: hoy TRADICIONAL(1) -> el correo lo pone
--                 en CANAL MODERNO, distrito PETROLERA.
UPDATE `usuario` SET `id_subdivision` = 2 WHERE `id` = 266;
-- MARCO MARCHANT  15956470-3 id=300: hoy CONSUMO(3) -> el correo lo pone en
--                 CANAL TRADICIONAL, distrito PIRAMIDE.
UPDATE `usuario` SET `id_subdivision` = 1 WHERE `id` = 300;

COMMIT;

-- ------------------------------------------------------------
-- VERIFICACION (correr despues del COMMIT)
-- ------------------------------------------------------------
SELECT u.id, u.rut, u.usuario, u.nombre, u.apellido, u.email,
       s.nombre AS canal, u.activo
  FROM usuario u
  LEFT JOIN subdivision s ON s.id = u.id_subdivision
 WHERE u.rut IN ('172444350','214539020','198454176','180757074','203793642',
                 '167423329','188800211','204522855','181919620','141840967',
                 '162504444','159305406','204533520','159564703')
 ORDER BY u.id_subdivision, u.apellido;

-- Ningun RUT duplicado ni RUT con formato (requisito del login del 01-09):
SELECT rut, COUNT(*) n FROM usuario WHERE activo=1 GROUP BY rut HAVING n>1;
SELECT id, usuario, rut FROM usuario WHERE activo=1 AND rut REGEXP '[^0-9kK]';

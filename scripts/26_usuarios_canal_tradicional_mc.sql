-- ============================================================
-- 26_usuarios_canal_tradicional_mc.sql
-- Alta de cuentas Visibility - Red Bull, canal TRADICIONAL, internos.
-- Fuente: correo de Cristian Henriquez del 24-08-2026 11:57
--         (asunto: creacion de cuentas Visibility canal tradicional MC)
--
-- Replica portal/modulos/mod_user/procesar.php:
--   rut sin puntos ni guion y en mayuscula | nombre/apellido/email/usuario en
--   mayuscula | clave con password_hash() bcrypt $2y$10$ | fechaCreacion=NOW()
--   activo=1, login_count=0, last_login=NULL, tipo_usuario=0
--   id_empresa=103, id_division=14 (REDBULL), id_subdivision=1 (TRADICIONAL)
--   id_perfil=3 (ejecutor), clasificacion_usuario='interno'
--
-- El correo trae 7 personas, pero solo 4 se pueden dar de alta. Ver notas al final.
-- Contrasena para las 4: 2435
--
-- El correo no informa telefonos: quedan con el provisorio 0000000000.
-- ============================================================

START TRANSACTION;

INSERT INTO `usuario`
  (`rut`, `nombre`, `apellido`, `telefono`, `email`, `usuario`, `fotoPerfil`, `clave`,
   `fechaCreacion`, `activo`, `id_empresa`, `id_division`, `id_subdivision`,
   `login_count`, `last_login`, `id_perfil`, `tipo_usuario`, `clasificacion_usuario`, `puede_adelantar_ruta`)
VALUES
  -- VICTOR JOSE SILVA MEDINA  178682369
  ('178682369', 'VICTOR JOSE', 'SILVA MEDINA', '0000000000', 'VSGRAFIC@HOTMAIL.COM', 'VSILVA', NULL, '$2y$10$wIDqWGK7.w8G./f5cnjCHeIUd87GPT.6gC4SrRQ9HmezJYR60JSR6',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'interno', 1),
  -- JONATHAN ERIC CÁCERES DOMINGUEZ  16327275K
  ('16327275K', 'JONATHAN ERIC', 'CÁCERES DOMINGUEZ', '0000000000', 'JERIC.CACERES16@GMAIL.COM', 'JCACERES', NULL, '$2y$10$rmUPK6ZDv3yWQfcynu0rgON1K3FVFAk7VmnIr/MHE./7aFVqYpKW2',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'interno', 1),
  -- KARLA ANDREA JIMÉNEZ PÁEZ  141159224
  ('141159224', 'KARLA ANDREA', 'JIMÉNEZ PÁEZ', '0000000000', 'PKARLAJIMENEZ@GMAIL.COM', 'KJIMENEZ', NULL, '$2y$10$PY2LEQlbe/YLm9A1ezO0kuJOM3yNm3GCwkTR4a2O2Qi8Wi2aSR.ce',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'interno', 1),
  -- PABLO ANTONIO CORTES RIOS  216593561
  ('216593561', 'PABLO ANTONIO', 'CORTES RIOS', '0000000000', 'PABLO.CORTES.RIOS18@GMAIL.COM', 'PCORTES', NULL, '$2y$10$GEOjV.ub5g2vbY0RqUX4gOBoZdrdpCG0Z65flRktKpzvej/uVnw5.',
   NOW(), 1, 103, 14, 1, 0, NULL, 3, 0, 'interno', 1);

COMMIT;

-- ============================================================
-- CASOS QUE NO SE CREARON  (revisar antes de ejecutar cualquier cosa)
-- ============================================================

-- (1) JONATHAN VLADIMIR DIAZ LOBOS - 18014033-4  ->  RUT INVALIDO
--     El digito verificador de 18014033 es 6, no 4. validarRut() lo rechaza,
--     y desde el 01-09 el ingreso es por RUT, asi que cargarlo mal lo dejaria
--     sin poder entrar. Hay que confirmarlo con Cristian antes de crearlo.
--     Si el RUT correcto resulta ser 18014033-6, se crea con:
--     INSERT ... ('180140336', 'JONATHAN VLADIMIR', 'DIAZ LOBOS', ...)

-- (2) DAGOBERTO ANDRES VASQUEZ MUNOZ - 11454157-5  ->  YA EXISTE (id=142)
--     Activo, REDBULL / TRADICIONAL / interno: ya esta donde el correo lo pide.
--     Arrastra el usuario generico MERCHVAN_20 y solo registra 2 ingresos
--     (el ultimo en marzo de 2025). Si se quiere completar su ficha con los
--     datos del correo, descomentar:
-- UPDATE `usuario`
--    SET `nombre` = 'DAGOBERTO ANDRES',
--        `apellido` = 'VASQUEZ MUÑOZ',
--        `email` = 'DAGOBERTO.VASQUEZ58@GMAIL.COM'
--  WHERE `id` = 142;

-- (3) EDUARDO PATRICIO PALACIOS CARVAJAL - 18897414-7  ->  YA EXISTE (id=207)
--     CUENTA EN USO INTENSIVO: 718 ingresos, el ultimo el 20-08-2026.
--     Ya esta en REDBULL / TRADICIONAL, pero figura como 'externo' y su correo
--     registrado es AUDITOR1REDBULL@GMAIL.COM.
--     NO se le toca la clave: cambiarla a 2435 le cortaria el acceso a alguien
--     que usa el sistema a diario. Si Cristian confirma que pasa a interno y
--     que su correo cambia, descomentar:
-- UPDATE `usuario`
--    SET `clasificacion_usuario` = 'interno',
--        `nombre` = 'EDUARDO PATRICIO',
--        `apellido` = 'PALACIOS CARVAJAL',
--        `email` = 'PALACIOSEDO@GMAIL.COM'
--  WHERE `id` = 207;

-- ------------------------------------------------------------
-- VERIFICACION
-- ------------------------------------------------------------
SELECT u.id, u.rut, u.usuario, u.nombre, u.apellido, u.email,
       d.nombre AS division, s.nombre AS canal,
       u.clasificacion_usuario, u.activo
  FROM usuario u
  LEFT JOIN division_empresa d ON d.id = u.id_division
  LEFT JOIN subdivision      s ON s.id = u.id_subdivision
 WHERE u.rut IN ('178682369','16327275K','141159224','216593561',
                 '114541575','188974147')
 ORDER BY u.apellido;

-- Sin RUT duplicados ni con formato (requisito del login por RUT del 01-09):
SELECT rut, COUNT(*) n FROM usuario WHERE activo=1 GROUP BY rut HAVING n>1;
SELECT id, usuario, rut FROM usuario WHERE activo=1 AND rut REGEXP '[^0-9kK]';

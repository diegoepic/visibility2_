-- ============================================================
-- 23_login_rut.sql
-- Prepara los datos para el ingreso SOLO por RUT en la APP (rige 01-09-2026).
-- El portal no tiene corte: sigue aceptando usuario, RUT o email.
--
-- La app y el portal comparan el RUT tecleado, ya normalizado, contra
-- usuario.rut TAL CUAL está guardado. Cualquier fila con puntos, guion o
-- espacios deja a esa persona sin poder entrar a la app: no hay error a la
-- vista, simplemente nunca calza.
--
-- Revisado contra el respaldo del 20-08-2026 (311 usuarios activos):
--   sin RUT ............ 0
--   RUT repetido ....... 0
--   RUT con formato .... 1   <-- id 241, CCU.GENERICO, '1111111-1'
--   DV en minúscula .... 0
-- ============================================================

-- ------------------------------------------------------------
-- 1) ANTES: confirmar que el panorama sigue igual.
--    Si alguna de las tres devuelve filas, hay que resolverlo ANTES del corte.
-- ------------------------------------------------------------

-- 1.a Activos sin RUT: NO podrían entrar.
SELECT id, usuario, email, last_login
FROM usuario
WHERE activo = 1 AND (rut IS NULL OR TRIM(rut) = '');

-- 1.b RUT repetido entre activos: el login quedaría ambiguo.
SELECT rut, COUNT(*) AS n, GROUP_CONCAT(usuario) AS cuentas
FROM usuario
WHERE activo = 1 AND TRIM(COALESCE(rut, '')) <> ''
GROUP BY rut
HAVING n > 1;

-- 1.c RUT con algo distinto de dígitos o K: no calzaría con lo tecleado.
SELECT id, usuario, rut
FROM usuario
WHERE activo = 1 AND rut REGEXP '[^0-9kK]';

-- ------------------------------------------------------------
-- 2) ARREGLO: dejar el RUT como lo guarda el portal al crear un usuario
--    (mod_user/procesar.php): sin puntos, sin guion y en mayúscula.
--
--    Se aplica a TODA la tabla y no sólo a la fila conocida, para que también
--    queden sanos los inactivos que mañana se reactiven.
--    Es idempotente: una fila ya limpia no cambia.
-- ------------------------------------------------------------

UPDATE usuario
SET rut = UPPER(
      REPLACE(REPLACE(REPLACE(REPLACE(rut, '.', ''), '-', ''), ' ', ''), CHAR(9), '')
    )
WHERE rut IS NOT NULL
  AND rut <> UPPER(
        REPLACE(REPLACE(REPLACE(REPLACE(rut, '.', ''), '-', ''), ' ', ''), CHAR(9), '')
      );

-- ------------------------------------------------------------
-- 3) DESPUÉS: 1.c no debe devolver ninguna fila.
--    Y confirmar que la limpieza no creó duplicados (1.b otra vez).
-- ------------------------------------------------------------

SELECT id, usuario, rut
FROM usuario
WHERE activo = 1 AND rut REGEXP '[^0-9kK]';

SELECT rut, COUNT(*) AS n, GROUP_CONCAT(usuario) AS cuentas
FROM usuario
WHERE activo = 1 AND TRIM(COALESCE(rut, '')) <> ''
GROUP BY rut
HAVING n > 1;

-- ------------------------------------------------------------
-- NOTA sobre CCU.GENERICO (id 241)
-- Su RUT '1111111-1' es de relleno: el dígito verificador real de 1111111 es 4,
-- así que la cuenta se creó saltándose validarRut(). El UPDATE lo deja como
-- '11111111' y con eso puede seguir entrando. Si el negocio prefiere que esa
-- cuenta compartida deje de existir, desactivarla es más limpio que arrastrar
-- un RUT inventado:
--     UPDATE usuario SET activo = 0 WHERE id = 241;
-- ------------------------------------------------------------

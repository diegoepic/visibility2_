-- ============================================================
-- 27_material_division_huerfanos.sql
-- Complemento del arreglo "cada division ve su propia materialidad".
--
-- CONTEXTO
-- La app ahora resuelve la foto de referencia con:
--     LEFT JOIN material m ON m.nombre = fq.material AND m.id_division = <division de la campana>
-- (antes el JOIN era solo por nombre, y como el 95% de los materiales no tiene
--  ref_image, el MAX() terminaba trayendo la foto de la unica division que si
--  la tenia: Red Bull o Savory. De ahi que ABL y CPW vieran el STOPPER de Red Bull.)
--
-- EFECTO SECUNDARIO A RESOLVER
-- Hay 9 nombres de material que existen UNICAMENTE con id_division = 0
-- ("sin division"), heredados de antes de que la columna existiera. Con el
-- filtro nuevo esos nunca calzan con ninguna campana. Para 7 da lo mismo
-- (no tienen foto cargada, no se veia nada). Pero 2 SI tienen foto y la
-- perderian:
--
--     id=32  PARANTE EXHIBIDOR LATA   uploads/foto_ref_material/PARANTE EXHIBIDOR LATA.png
--     id=34  MINI TOTEM MODE          uploads/foto_ref_material/MINI TOTEM MODERNO.png
--
-- POR QUE SE PROPONE REDBULL (id_division = 14)
--   * Ambos caen dentro del bloque de ids de Red Bull: 25, 26, 27, 31 y 38 son
--     todos REDBULL, y 30/32/34/35/37 quedaron en 0.
--   * El material id=38 EXHIBIDOR, que SI es REDBULL, apunta a la misma imagen
--     'PARANTE EXHIBIDOR LATA.png' que el id=32.
--   * Ninguno de los dos nombres existe hoy en REDBULL, asi que el UNIQUE
--     (nombre, id_division) no se rompe.
--
-- Es una inferencia, no un dato del negocio: correr primero el paso 1 y
-- confirmar con quien administre el catalogo antes del paso 2.
-- ============================================================

-- ------------------------------------------------------------
-- 1) ANTES: quien usa realmente esos dos nombres.
--    Si aparecen campanas de una division distinta de REDBULL, el paso 2
--    esta mal y hay que crear el material en esa division en vez de mover este.
-- ------------------------------------------------------------
SELECT
    d.nombre                AS division_de_la_campana,
    fq.material,
    COUNT(*)                AS gestiones,
    COUNT(DISTINCT fq.id_formulario) AS campanas,
    MAX(fq.fechaVisita)     AS ultima_visita
FROM formularioQuestion fq
INNER JOIN formulario f        ON f.id = fq.id_formulario
LEFT  JOIN division_empresa d  ON d.id = f.id_division
WHERE fq.material IN ('PARANTE EXHIBIDOR LATA', 'MINI TOTEM MODE')
GROUP BY d.nombre, fq.material
ORDER BY gestiones DESC;

-- Los 9 nombres que solo existen en division 0 (los otros 7 no tienen foto,
-- se listan para dejar constancia).
SELECT m.id, m.nombre, m.ref_image
FROM material m
WHERE m.id_division = 0
  AND m.deleted_at IS NULL
  AND NOT EXISTS (
        SELECT 1 FROM material m2
        WHERE m2.nombre = m.nombre
          AND m2.id_division <> 0
          AND m2.deleted_at IS NULL
      )
ORDER BY (m.ref_image IS NULL), m.id;

-- ------------------------------------------------------------
-- 2) ARREGLO: asignar la division a los dos que tienen foto.
--    Idempotente (solo toca filas que sigan en 0) y protegido contra el
--    UNIQUE (nombre, id_division): si Red Bull ya tuviera ese nombre, no hace nada.
-- ------------------------------------------------------------
UPDATE material m
SET m.id_division = 14           -- REDBULL
WHERE m.id IN (32, 34)
  AND m.id_division = 0
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT id, nombre, id_division, deleted_at FROM material) m2
        WHERE m2.nombre = m.nombre
          AND m2.id_division = 14
          AND m2.deleted_at IS NULL
      );

-- ------------------------------------------------------------
-- 3) DESPUES: las dos filas deben quedar en REDBULL.
-- ------------------------------------------------------------
SELECT m.id, m.nombre, m.id_division, d.nombre AS division, m.ref_image
FROM material m
LEFT JOIN division_empresa d ON d.id = m.id_division
WHERE m.id IN (32, 34);

-- ------------------------------------------------------------
-- CONSULTA DE CONTROL (opcional, para vigilar el problema de fondo)
-- Nombres de material repetidos en varias divisiones donde mas de una tiene
-- foto: son los unicos casos donde el catalogo puede seguir confundiendo a
-- alguien si se vuelve a quitar el filtro por division.
-- ------------------------------------------------------------
SELECT m.nombre,
       COUNT(*)                                   AS divisiones,
       SUM(m.ref_image IS NOT NULL AND m.ref_image <> '') AS con_foto,
       GROUP_CONCAT(DISTINCT d.nombre ORDER BY d.nombre SEPARATOR ', ') AS en_divisiones
FROM material m
LEFT JOIN division_empresa d ON d.id = m.id_division
WHERE m.deleted_at IS NULL
GROUP BY m.nombre
HAVING divisiones > 1 AND con_foto > 0
ORDER BY con_foto DESC, divisiones DESC;

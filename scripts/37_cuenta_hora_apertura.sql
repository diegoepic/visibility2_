-- 37_cuenta_hora_apertura.sql — la hora a la que abre una cuenta, como dato y no como texto
--
-- QUE HACE
-- --------
-- Agrega `cuenta.hora_apertura TIME NULL` y la puebla para la unica cuenta que hoy tiene
-- una restriccion horaria: BOTILLERIA (id 228), que abre alrededor de las 13:00.
--
--
-- POR QUE (hallazgo V-29)
-- -----------------------
-- Hasta ahora "este local abre tarde" se decidia asi:
--
--     stripos($row['tipo_cuenta'], 'botiller') !== false
--
-- Una regla de negocio escrita como coincidencia de texto sobre el NOMBRE de la cuenta.
-- Eso significa que:
--
--   * renombrar la cuenta a algo sin la palabra "botilleria" APAGA la regla en silencio;
--   * crear una cuenta nueva que abra tarde (una petrolera nocturna, un mall que abre a
--     las 11) no tiene forma de expresarlo, salvo metiendole "botilleria" al nombre;
--   * y la hora, 13:00, esta escrita a mano en tres sitios del front.
--
-- Nadie se entera de ninguna de las tres cosas hasta que un ejecutor llega a un local
-- cerrado.
--
-- Se midio antes de escribir esto, sobre el volcado del 2026-09-03:
--     253 cuentas en total
--       1 con "botiller" en el nombre: (228, 'BOTILLERIA')
--   5.544 locales colgando de ella, de 188.664  (2,9%)
--
-- O sea la superficie es una fila de `cuenta`, pero afecta a 5.544 locales todos los dias.
--
--
-- POR QUE NULL SIGNIFICA "SIN RESTRICCION"
-- ----------------------------------------
-- La inmensa mayoria de las cuentas no tiene hora de apertura relevante para la ruta: abren
-- antes de que el ejecutor empiece la jornada. NULL dice exactamente eso, y ademas hace que
-- la migracion sea segura: al aplicarla, 252 de las 253 cuentas quedan como estaban.
--
-- Un '00:00:00' NO serviria para lo mismo: seria "abre a medianoche", que es un dato, no la
-- ausencia de dato. La diferencia importa el dia que alguien quiera consultar "cuentas sin
-- horario declarado" para completarlas.
--
--
-- COSTE
-- -----
-- 253 filas. Instantaneo. Aditiva: no toca ninguna columna existente.
--
-- REVERSION
-- ---------
--   ALTER TABLE cuenta DROP COLUMN hora_apertura;
--
--
-- DESPUES DE ESTO
-- ---------------
-- El lector unico esta en app/lib/dominio/ventana_horaria.php. Mientras haya ambientes sin
-- esta migracion, esa libreria cae al `stripos('botiller')` de siempre, asi que el codigo se
-- puede desplegar antes o despues de correr esto, en cualquier orden.
--
-- Para agregar una cuenta con horario mas adelante NO hace falta tocar codigo:
--   UPDATE cuenta SET hora_apertura = '11:00:00' WHERE nombre = 'MALL PLAZA';

SET @existe = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'cuenta'
                  AND COLUMN_NAME = 'hora_apertura');
SET @sql = IF(@existe = 0,
  "ALTER TABLE cuenta
     ADD COLUMN `hora_apertura` TIME NULL DEFAULT NULL
     COMMENT 'Hora a la que abren los locales de esta cuenta. NULL = sin restriccion horaria para la ruta. Ver scripts/37.'",
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- La unica cuenta con restriccion horaria conocida. Se identifica por NOMBRE y no por id
-- para que el script valga igual en un ambiente donde los ids no coincidan.
-- El WHERE con IS NULL lo hace reidempotente y, sobre todo, evita pisar un horario que
-- alguien haya ajustado a mano despues de la primera pasada.
UPDATE cuenta
   SET hora_apertura = '13:00:00'
 WHERE hora_apertura IS NULL
   AND LOWER(nombre) LIKE '%botiller%';

-- ---------------------------------------------------------------------------
-- Comprobacion despues de aplicar
-- ---------------------------------------------------------------------------
--   SELECT id, nombre, hora_apertura FROM cuenta WHERE hora_apertura IS NOT NULL;
--     -> deberia salir solo (228, 'BOTILLERIA', '13:00:00')
--
--   -- Cuantos locales quedan afectados por una ventana horaria:
--   SELECT c.nombre, c.hora_apertura, COUNT(*) AS locales
--     FROM local l JOIN cuenta c ON c.id = l.id_cuenta
--    WHERE c.hora_apertura IS NOT NULL
--    GROUP BY c.nombre, c.hora_apertura;
--     -> BOTILLERIA / 13:00:00 / ~5.544

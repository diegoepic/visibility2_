-- 35_integridad_diagnostico.sql — SOLO LECTURA. No modifica nada.
--
-- PARA QUE SIRVE
-- --------------
-- La tanda 5 del plan (T5-5) proponia anadir tres claves foraneas al nucleo. Antes de
-- escribir un solo ALTER hay que saber cuantas filas apuntan hoy a nada, porque una FK
-- sobre datos sucios FALLA y deja quemada la ventana de mantenimiento.
--
-- Este archivo son las consultas que dan ese numero. Ejecutalo en produccion y compara
-- con la columna "volcado 2026-09-03": si los numeros se parecen, el analisis vale; si no,
-- algo cambio y hay que rehacerlo.
--
-- El runner lo ejecutara como cualquier otro archivo, pero no cambia nada: son SELECT.
--
--
-- RESULTADO DEL ANALISIS SOBRE EL VOLCADO DEL 2026-09-03
-- ------------------------------------------------------
--
--   tabla.columna                              filas    "= 0"   HUERFANOS   nulable
--   ---------------------------------------------------------------------------------
--   fotoVisita.visita_id                     238.686  122.066          41   NOT NULL
--   form_question_responses.visita_id      9.362.552 1.515.410           0   NOT NULL
--   form_question_responses.id_form_question 9.362.552        0      25.449   NOT NULL
--   formularioQuestion.id_formulario          674.020        0       1.625   NULL ok
--   formularioQuestion.id_local               674.020        2      56.306   NULL ok
--   formularioQuestion.id_usuario             674.020        0      59.342   NULL ok
--
-- CONCLUSION: ninguna de las tres FK del plan se puede poner tal cual.
--
--   1. Los CEROS son el obstaculo mayor, no los huerfanos. `fotoVisita.visita_id` tiene
--      122.066 filas con valor 0 (el 51% de la tabla) y la columna es NOT NULL. Una FK
--      exige que cada valor exista en el padre, y no hay ninguna visita con id 0. Para
--      ponerla habria que hacer la columna nulable y convertir 0 -> NULL, lo que cambia el
--      significado para todo el codigo que hoy consulta `visita_id = 0`.
--
--   2. Los HUERFANOS de formularioQuestion son decisiones de negocio, no de tecnica.
--      59.342 filas apuntan a usuarios que ya no existen y 56.306 a locales que ya no
--      existen: son asignaciones de ruta historicas. Borrarlas destruye historia;
--      ponerlas a NULL las deja sin dueno. Eso lo decide el negocio, no una migracion.
--
--   3. La unica candidata cercana es form_question_responses.id_form_question:
--      sin ceros, sin nulos, y 25.449 huerfanos sobre 9,36 millones (0,27%). Sigue siendo
--      una decision: son respuestas de encuesta cuya pregunta se borro de la campana.
--
--
-- DECISIONES TOMADAS (2026-09-03) Y ALCANCE FINAL
-- -----------------------------------------------
-- Con los numeros de arriba delante se decidio lo siguiente:
--
--   * Los 122.066 `fotoVisita.visita_id = 0` son registros ANTERIORES a que existiera la
--     tabla `visita`. No son corrupcion: el 0 era la forma de decir "sin visita". Pasan a
--     NULL, que es como se escribe eso en SQL, y con eso la FK ya es posible.
--     -> scripts/33_fotovisita_fk_visita.sql
--
--   * Las 59.342 asignaciones a usuarios inexistentes y las 56.306 a locales inexistentes
--     de `formularioQuestion` SE CONSERVAN COMO HISTORIA. Son rutas de ejecutores que ya
--     no estan; borrarlas o desvincularlas destruiria el registro de lo que se hizo.
--     -> POR TANTO NO SE PONE FK en formularioQuestion.id_usuario ni .id_local ni
--        .id_formulario. Una FK rechazaria esas filas, y la decision es quedarselas.
--        Queda cerrado: no es una tarea pendiente, es una decision.
--
--   * Los 25.449 `form_question_responses.id_form_question` huerfanos son respuestas cuya
--     pregunta se borro de la campana. Misma naturaleza que el punto anterior: historia.
--     -> tampoco se pone FK ahi.
--
--   * `form_question_responses.visita_id` tiene CERO huerfanos, asi que la FK es viable,
--     pero cuesta reconstruir una tabla de 9,4 millones de filas para blindar algo que ya
--     esta limpio. Queda escrita y marcada como opcional.
--     -> scripts/34_fqr_fk_visita.sql
--
-- Lo que protege mientras tanto: la vigilancia nocturna de scripts/cron_gc.php (bloque 3)
-- avisa si empiezan a aparecer huerfanos NUEVOS. Eso es el 90% del valor de una FK sin
-- ninguno de sus riesgos, y es lo unico que hace falta para las tablas donde se decidio
-- conservar la historia.


-- ---------------------------------------------------------------------------
-- 1. fotoVisita -> visita
-- ---------------------------------------------------------------------------
SELECT 'fotoVisita.visita_id' AS columna,
       COUNT(*)                                              AS filas,
       SUM(f.visita_id = 0)                                  AS valor_cero,
       SUM(f.visita_id <> 0 AND v.id IS NULL)                AS huerfanos
  FROM fotoVisita f
  LEFT JOIN visita v ON v.id = f.visita_id;

-- ---------------------------------------------------------------------------
-- 2. form_question_responses -> visita / form_questions
--    OJO: 9,36 millones de filas. Estas dos consultas tardan. No las lances en
--    hora punta.
-- ---------------------------------------------------------------------------
SELECT 'form_question_responses.visita_id' AS columna,
       COUNT(*)                                              AS filas,
       SUM(r.visita_id = 0)                                  AS valor_cero,
       SUM(r.visita_id <> 0 AND v.id IS NULL)                AS huerfanos
  FROM form_question_responses r
  LEFT JOIN visita v ON v.id = r.visita_id;

SELECT 'form_question_responses.id_form_question' AS columna,
       COUNT(*)                                              AS filas,
       SUM(r.id_form_question = 0)                           AS valor_cero,
       SUM(r.id_form_question <> 0 AND q.id IS NULL)         AS huerfanos
  FROM form_question_responses r
  LEFT JOIN form_questions q ON q.id = r.id_form_question;

-- ---------------------------------------------------------------------------
-- 3. formularioQuestion -> formulario / local / usuario
-- ---------------------------------------------------------------------------
SELECT 'formularioQuestion.id_formulario' AS columna,
       COUNT(*)                                              AS filas,
       SUM(fq.id_formulario IS NULL)                         AS nulos,
       SUM(fq.id_formulario IS NOT NULL AND f.id IS NULL)    AS huerfanos
  FROM formularioQuestion fq
  LEFT JOIN formulario f ON f.id = fq.id_formulario;

SELECT 'formularioQuestion.id_local' AS columna,
       COUNT(*)                                              AS filas,
       SUM(fq.id_local IS NULL)                              AS nulos,
       SUM(fq.id_local IS NOT NULL AND fq.id_local <> 0 AND l.id IS NULL) AS huerfanos
  FROM formularioQuestion fq
  LEFT JOIN local l ON l.id = fq.id_local;

SELECT 'formularioQuestion.id_usuario' AS columna,
       COUNT(*)                                              AS filas,
       SUM(fq.id_usuario IS NULL)                            AS nulos,
       SUM(fq.id_usuario IS NOT NULL AND u.id IS NULL)       AS huerfanos
  FROM formularioQuestion fq
  LEFT JOIN usuario u ON u.id = fq.id_usuario;

-- ---------------------------------------------------------------------------
-- 4. La pregunta que de verdad decide si merece la pena seguir:
--    ¿se siguen creando huerfanos, o son todos historicos?
-- ---------------------------------------------------------------------------
SELECT 'huerfanos de los ultimos 30 dias' AS que,
       SUM(q.id IS NULL) AS respuestas_sin_pregunta,
       COUNT(*)          AS respuestas_del_periodo
  FROM form_question_responses r
  LEFT JOIN form_questions q ON q.id = r.id_form_question
 WHERE r.created_at >= NOW() - INTERVAL 30 DAY;

-- Y la distribucion por ano de los huerfanos de formularioQuestion: si estan todos en
-- 2024 y 2025, es historia y la FK protegeria lo que ya no ocurre.
SELECT YEAR(fq.fechaPropuesta) AS anio,
       COUNT(*)                AS asignaciones_a_usuario_inexistente
  FROM formularioQuestion fq
  LEFT JOIN usuario u ON u.id = fq.id_usuario
 WHERE fq.id_usuario IS NOT NULL AND u.id IS NULL
 GROUP BY YEAR(fq.fechaPropuesta)
 ORDER BY anio;

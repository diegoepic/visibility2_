-- 28_journal_resumen_diario.sql
--
-- Hoy la pregunta "¿quién tiene trabajo sin subir?" no se puede responder. El estado de la
-- cola vive sólo en el IndexedDB de cada teléfono: si un ejecutor termina la jornada con
-- seis gestiones en error, nadie en la oficina se entera hasta que alguien echa de menos
-- las fotos, normalmente días después.
--
-- Esta tabla es el eco de esa cola en el servidor. La app manda un resumen (contadores del
-- día, no la bitácora entera) cuando recupera conexión y cuando termina de drenar. Una fila
-- por usuario y día.
--
-- Es deliberadamente pequeña: NO es una copia de la cola ni sirve para recuperar trabajo
-- perdido. Sirve para saber a quién hay que llamar.
--
-- Ejecutar en produccion:  mysql -u <user> -p <base> < scripts/28_journal_resumen_diario.sql

CREATE TABLE IF NOT EXISTS journal_resumen_diario (
  user_id           INT          NOT NULL,
  ymd               DATE         NOT NULL,
  pendientes        INT          NOT NULL DEFAULT 0 COMMENT 'encolados + enviando',
  errores           INT          NOT NULL DEFAULT 0 COMMENT 'error + failed_permanent + conflict',
  subidas           INT          NOT NULL DEFAULT 0 COMMENT 'confirmados por el servidor ese dia',
  bloqueados        INT          NOT NULL DEFAULT 0 COMMENT 'esperando reingreso (sesion/CSRF)',
  ultimo_error_code VARCHAR(64)      NULL,
  app_version       VARCHAR(32)      NULL COMMENT 'SW_VERSION del cliente que reporto',
  online            TINYINT(1)   NOT NULL DEFAULT 1,
  actualizado_at    DATETIME     NOT NULL,
  PRIMARY KEY (user_id, ymd),
  KEY idx_ymd (ymd),
  -- Para el panel de la T7: "dame los que hoy tienen algo sin subir", ordenado por gravedad.
  KEY idx_dia_problemas (ymd, errores, pendientes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consulta de referencia (la que hoy es imposible):
--
--   SELECT u.id, u.nombre, u.apellido, r.pendientes, r.errores, r.ultimo_error_code,
--          r.actualizado_at
--     FROM journal_resumen_diario r
--     JOIN usuario u ON u.id = r.user_id
--    WHERE r.ymd = CURDATE()
--      AND (r.pendientes > 0 OR r.errores > 0)
--    ORDER BY r.errores DESC, r.pendientes DESC;

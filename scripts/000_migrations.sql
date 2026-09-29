-- 000_migrations.sql — tabla de control del runner de migraciones
--
-- Hasta ahora no existía ningún registro de qué cambios de esquema se habían aplicado.
-- La convención era "el siguiente número gana", y ya está rota: el directorio tiene dos
-- archivos 26_, dos 27_ y ningún 24_. Peor: los scripts 12 a 22 NUNCA se versionaron —
-- la regla `*.sql` del .gitignore se los tragó antes de que existiera la excepción
-- `!scripts/*.sql`— así que sus archivos están perdidos aunque sus cambios estén en
-- producción. La única forma de saber qué corrió era mirar el esquema a ojo.
--
-- Esta tabla es el registro. La llena scripts/migrate.php.
--
-- Este archivo es el ÚNICO que se aplica a mano; a partir de aquí manda el runner:
--   mysql -u <user> -p <base> < scripts/000_migrations.sql
--   php scripts/migrate.php status

CREATE TABLE IF NOT EXISTS schema_migrations (
  version      VARCHAR(190) NOT NULL COMMENT 'nombre del archivo, p.ej. 28_journal_resumen_diario.sql',
  checksum     CHAR(64)         NULL COMMENT 'sha256 del archivo al aplicarse; NULL si el archivo se perdió',
  aplicado_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aplicado_por VARCHAR(100)     NULL COMMENT 'usuario del sistema que lo corrió',
  duracion_ms  INT              NULL,
  sentencias   INT              NULL COMMENT 'cuántas sentencias tenía el archivo',
  nota         VARCHAR(255)     NULL COMMENT 'p.ej. "reconciliado: aplicado antes del runner"',
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

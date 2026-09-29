# Migraciones de esquema

## Cómo se hacía hasta ahora, y por qué se cambió

Los cambios de esquema eran archivos sueltos en esta carpeta que alguien pegaba en
phpMyAdmin. No quedaba registro de cuál se había aplicado, así que la única forma de
saberlo era mirar el esquema columna por columna. Los síntomas ya estaban a la vista:

- **Dos archivos `26_`, dos `27_`, y ningún `24_`.** La convención "el siguiente número
  gana" se rompió sola en cuanto dos personas tocaron el repositorio la misma semana.
- **Los scripts 12 a 22 no existen.** Sus cambios **sí** están en producción (fotos
  duplicadas, preguntas por cadena, permiso de adelantar ruta, tótem), pero los archivos
  se perdieron: la regla `*.sql` del `.gitignore` se los tragó antes de que existiera la
  excepción `!scripts/*.sql`. Nunca llegaron a un commit.

## Cómo se hace ahora

```bash
php scripts/migrate.php status         # qué hay aplicado y qué falta (no toca nada)
php scripts/migrate.php dry-run        # qué haría, sentencia por sentencia
php scripts/migrate.php run            # aplica lo pendiente y lo registra
php scripts/migrate.php next           # qué número toca para el próximo script
```

El registro vive en la tabla `schema_migrations`, que se crea **una sola vez y a mano**:

```bash
mysql -u <user> -p <base> < scripts/000_migrations.sql
```

### Puesta en marcha (sólo la primera vez)

Todo lo que hay en esta carpeta **ya está aplicado en producción** — se verificó contra el
volcado del 2026-09-03, marcador por marcador. Así que el primer paso es registrarlo sin
volver a ejecutarlo:

```bash
mysql -u <user> -p <base> < scripts/000_migrations.sql
php scripts/migrate.php mark-applied
php scripts/migrate.php status          # debe decir "0 pendientes"
```

> **`mark-applied` no ejecuta nada**, sólo anota. Es importante que sea el primer paso:
> varios de estos archivos **no son reidempotentes** — `25_usuarios_merch_redbull.sql` y
> `26_usuarios_canal_tradicional_mc.sql` son `INSERT INTO usuario`, y volver a correrlos
> duplicaría personas. `23_login_rut.sql` y `27_material_division_huerfanos.sql` son en
> buena parte `SELECT` de diagnóstico.

## Si el hosting no da acceso a shell

```bash
php scripts/migrate.php sql
```

Imprime el SQL pendiente **más el `INSERT` en `schema_migrations`** que hay que pegar
después, para que el registro no se quede atrás. `migrate.php` se niega a ejecutarse por
HTTP a propósito: un runner de migraciones accesible desde la web es una puerta abierta al
esquema.

## Mantenimiento programado

```bash
php scripts/cron_gc.php --dry-run     # dice cuánto borraría, sin borrar
php scripts/cron_gc.php --quiet       # lo que se pone en el cron
```

Limpia `request_log` (la tabla de idempotencia). **Hasta ahora esto corría dentro de las
peticiones**: `lib/idempotency.php` traía un `if (mt_rand(1, 100) === 1) { idempo_gc(); }`,
y ese archivo lo incluyen 8 endpoints — una de cada cien gestiones pagaba tres sentencias
de limpieza con un ejecutor esperando en el terreno. Ahora corre de madrugada:

```
15 3 * * *  /usr/local/bin/php /home/visibility/public_html/visibility2/scripts/cron_gc.php --quiet >> /home/visibility/logs/cron_gc.log 2>&1
```

Hace dos cosas:

1. **`request_log`** (idempotencia): marca como fallidos los colgados y borra lo caducado.
2. **`visita`**: cierra las **huérfanas** — abiertas, de más de `VISITA_ORPHAN_HOURS` (24 por
   defecto), y sin ninguna gestión, foto ni respuesta colgando. Se cierran poniendo
   `fecha_fin = fecha_inicio`; no se borran nunca.

Todo va por lotes con una pausa entre medias, para no bloquear las tablas mientras la app
escribe. **Si el hosting no permite programar cron**, `V2_GC_INLINE=1` en `.env` devuelve una
versión mucho más suave de la parte de `request_log` dentro de las peticiones (1 de cada 2000,
y sólo las sentencias que ya tienen índice). Está apagada por defecto a propósito.

> ⚠️ **La primera pasada de las huérfanas toca ~20.700 filas.** Córrela a mano y mirando,
> antes de programar el cron:
>
> ```bash
> php scripts/cron_gc.php --dry-run    # confirma el número
> php scripts/cron_gc.php              # y ejecútala
> ```
>
> A partir de ahí son unas 17 al día.

## Reglas para escribir un script nuevo

1. **Pide el número con `php scripts/migrate.php next`.** No lo adivines mirando la
   carpeta: los huecos no se rellenan, porque reusar un número que se perdió confundiría
   el registro para siempre.
2. **Escríbelo reidempotente.** `CREATE TABLE IF NOT EXISTS`, guardas con
   `SHOW COLUMNS FROM ... LIKE`, `INSERT ... ON DUPLICATE KEY UPDATE`. En MySQL el DDL hace
   commit implícito: si el archivo falla a mitad, las sentencias anteriores ya se aplicaron
   y hay que poder relanzarlo desde el principio.
3. **No edites un script ya aplicado.** El runner guarda el sha256 y avisa si cambió, pero
   editarlo no lo vuelve a ejecutar: lo único que consigues es que el archivo deje de
   describir lo que hay en la base. Un cambio nuevo va en un archivo nuevo.
4. **Recuerda que el código se despliega aparte.** El portal y la app deben protegerse con
   `SHOW COLUMNS` / `SHOW TABLES` cuando dependan de algo que este directorio todavía no
   haya aplicado (mira `table_has_col()` en `app/procesar_gestion_pruebas.php`).

## Pruebas

```bash
node --test "scripts/tests/*.test.mjs"
```

Prueban el partidor de sentencias SQL: la pieza del runner donde un fallo hace daño de
verdad, porque cortar mal significa ejecutar media sentencia contra el esquema. Cubren los
`;` dentro de cadenas y backticks, las comillas escapadas y duplicadas, los comentarios
—incluidos los ejecutables `/*! */` de mysqldump— y los bloques `DELIMITER` de triggers.

`sql_splitter.mjs` es un **puerto** de `partir_sentencias()` de `migrate.php`, no el código
que corre. Existe porque en el entorno de desarrollo no hay PHP CLI. **Si tocas el PHP, toca
el puerto igual**, o las pruebas dejan de significar nada.

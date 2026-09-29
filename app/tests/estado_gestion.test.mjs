/**
 * Pruebas de la normalización del estado de gestión (T6-3b).
 *
 * EL RIESGO QUE CUBREN
 * --------------------
 * La misma tabla de equivalencias existe TRES veces, porque tiene que:
 *
 *   1. `estado_gestion_normalizar()`  — PHP, para quien ya tiene la fila
 *   2. `estado_gestion_sql()`         — SQL, para agrupar sin traerse las filas
 *   3. `scripts/36_estado_gestion.sql` — SQL, para poblar la columna nueva
 *
 * Tres copias de un mapeo es exactamente lo que esta tanda persigue, pero acá no se pueden
 * fundir: una corre en PHP, otra dentro de un SELECT y la tercera dentro de un UPDATE por
 * lotes. Lo que sí se puede es exigir que digan lo mismo, y eso es lo que se prueba.
 *
 * Si divergen, el síntoma sería silencioso y feo: la columna `estado_gestion` diría una
 * cosa y la normalización desde `pregunta` otra, en informes distintos, sobre las mismas
 * filas.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const LIB = readFileSync(join(RAIZ, 'app', 'lib', 'dominio', 'estado_gestion.php'), 'utf8');
const MIG = readFileSync(join(RAIZ, 'scripts', '36_estado_gestion.sql'), 'utf8');

/** Los nueve valores del vocabulario canónico, sacados de la constante. */
function canonicos() {
  const bloque = LIB.slice(LIB.indexOf("define('V2_ESTADOS_GESTION'"), LIB.indexOf('if (!function_exists'));
  return [...bloque.matchAll(/'([a-z_]+)',/g)].map((m) => m[1]);
}

/** Las etiquetas que el switch de PHP devuelve tal cual (los `case`). */
function casosPhp() {
  const ini = LIB.indexOf('function estado_gestion_normalizar');
  const fin = LIB.indexOf('function estado_gestion_terminado');
  const cuerpo = LIB.slice(ini, fin === -1 ? undefined : fin);
  return [...cuerpo.matchAll(/case '([^']+)':/g)].map((m) => m[1]);
}

/** Los valores que la expresión SQL reconoce. */
function valoresSql() {
  const ini = LIB.indexOf('function estado_gestion_sql');
  const cuerpo = LIB.slice(ini);
  return [...cuerpo.matchAll(/'([a-z_ ]+)'/g)].map((m) => m[1]);
}

/** Los valores que reconoce el UPDATE de la migración. */
function valoresMigracion() {
  const ini = MIG.indexOf('SET estado_gestion = CASE');
  const fin = MIG.indexOf('WHERE estado_gestion IS NULL', ini);
  return [...MIG.slice(ini, fin).matchAll(/'([a-z_ ]+)'/g)].map((m) => m[1]);
}

const CANON = canonicos();

test('el vocabulario canónico tiene los nueve valores medidos', () => {
  assert.deepStrictEqual(CANON, [
    'sin_gestionar', 'pendiente', 'cancelado', 'solo_auditoria', 'solo_implementado',
    'solo_retirado', 'implementado_auditado', 'no_implementado', 'completado',
  ]);
});

test('el ENUM de la migración coincide con el vocabulario canónico', () => {
  const ini = MIG.indexOf('ADD COLUMN `estado_gestion` ENUM(');
  assert.notStrictEqual(ini, -1, 'no se encontró el ADD COLUMN en scripts/36');
  const enumTxt = MIG.slice(ini, MIG.indexOf(')', MIG.indexOf('ENUM(', ini) + 5));
  const valores = [...enumTxt.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
  assert.deepStrictEqual(valores.slice().sort(), CANON.slice().sort(),
    'El ENUM de scripts/36 y V2_ESTADOS_GESTION no dicen lo mismo.\n' +
    '  ENUM : ' + valores.join(', ') + '\n' +
    '  canon: ' + CANON.join(', '));
});

test('PHP y SQL reconocen los mismos valores de entrada', () => {
  // Entradas que las tres implementaciones deben tratar igual. 'en proceso' y 'pendiente'
  // colapsan en 'pendiente'; el resto se devuelve tal cual.
  const ENTRADAS = ['en proceso', 'pendiente', 'cancelado', 'solo_auditoria',
    'solo_implementado', 'solo_retirado', 'implementado_auditado',
    'no_implementado', 'completado'];

  const php = casosPhp();
  const sql = valoresSql();
  const mig = valoresMigracion();

  for (const e of ENTRADAS) {
    assert.ok(php.includes(e), `estado_gestion_normalizar() no contempla '${e}'`);
    assert.ok(sql.includes(e), `estado_gestion_sql() no contempla '${e}'`);
    assert.ok(mig.includes(e), `scripts/36 no contempla '${e}'`);
  }
});

test('las tres implementaciones tratan el vacío igual', () => {
  // '' , ' ' y NULL son las TRES formas de decir "sin gestionar" que hay en la tabla
  // (130.861 + 489 + 127 filas). El espacio suelto es el que se escapa si no hay TRIM.
  for (const [nombre, txt] of [['la expresión SQL', LIB.slice(LIB.indexOf('function estado_gestion_sql'))],
                               ['la migración', MIG]]) {
    assert.ok(/TRIM\(COALESCE\(/.test(txt),
      nombre + ' no hace TRIM(COALESCE(...)): el valor \' \' (489 filas) se le escapa');
  }
  const php = LIB.slice(LIB.indexOf('function estado_gestion_normalizar'));
  assert.ok(/trim\(\(string\)\$pregunta\)/.test(php),
    'estado_gestion_normalizar() no hace trim: el valor \' \' se le escapa');
});

test('la migración es aditiva: no toca la columna pregunta', () => {
  assert.ok(!/DROP COLUMN `?pregunta`?/i.test(MIG), 'scripts/36 borra `pregunta`');
  assert.ok(!/SET\s+pregunta\s*=/i.test(MIG), 'scripts/36 escribe en `pregunta`');
  assert.ok(!/MODIFY\s+`?pregunta`?/i.test(MIG), 'scripts/36 altera `pregunta`');
});

test('la migración es reidempotente', () => {
  // Regla 2 de scripts/README.md: en MySQL el DDL hace commit implícito, así que un
  // archivo que falla a mitad tiene que poder relanzarse desde el principio.
  assert.ok(MIG.includes('information_schema.COLUMNS'), 'falta la guarda del ADD COLUMN');
  assert.ok(MIG.includes('information_schema.STATISTICS'), 'falta la guarda del ADD INDEX');
  assert.ok(MIG.includes('WHERE estado_gestion IS NULL'),
    'el backfill no se acota a lo no poblado: relanzarlo reescribiría toda la tabla');
});

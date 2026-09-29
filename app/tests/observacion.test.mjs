/**
 * La observación de la gestión no puede volver a reventar por larga.
 *
 * QUÉ PASÓ
 * --------
 * `formularioQuestion.observacion` era varchar(360) y guardar una gestión fallaba con
 * "Data too long for column 'observacion' at row 1". Sobre el volcado del 2026-09-03:
 * **72 fallos, 14 usuarios distintos, desde el 2026-03-18**, y 98 filas ya clavadas en el
 * techo de 360 caracteres.
 *
 * Lo caro no era el error sino lo que venía después: sale como HTTP 500, la cola offline
 * clasifica los 5xx como reintentables, y el reintento choca con la misma fila llena. El
 * trabajo se queda reintentando para siempre y la gestión no se guarda nunca.
 *
 * La causa de fondo era el UPDATE de "no implementado", que hacía
 * `CONCAT(COALESCE(observacion,''),' ',?)`: **acumula, no reemplaza**. Cuando una fila
 * llegaba al techo, ese material no se podía guardar NUNCA MÁS.
 *
 * El arreglo tiene dos mitades y estas pruebas cuidan que no se separen: `scripts/40`
 * ensancha la columna, y `app/lib/dominio/observacion.php` recorta antes de escribir.
 * La segunda es la que importa: hace que el guardado no pueda fallar por largo aunque la
 * migración no se haya aplicado.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const APP = join(RAIZ, 'app');
const leer = (...p) => readFileSync(join(RAIZ, ...p), 'utf8');

const LIB = leer('app', 'lib', 'dominio', 'observacion.php');
const MIG = leer('scripts', '40_observacion_larga.sql');

/** Los cinco sitios que escriben la columna. El del portal incluido: la columna es la misma. */
const ESCRITORES = [
  ['app/procesar_gestion_pruebas.php', leer('app', 'procesar_gestion_pruebas.php')],
  ['app/procesar_gestion.php', leer('app', 'procesar_gestion.php')],
  ['portal/.../ajax_acciones.php',
   leer('portal', 'modulos', 'mod_seguimiento_etapas', 'ajax_acciones.php')],
];

/** Plantillas donde vive el <textarea>. Las dos últimas lo generan desde JavaScript. */
const PLANTILLAS = [
  ['app/gestionarPruebas.php', leer('app', 'gestionarPruebas.php')],
  ['app/gestionar.php', leer('app', 'gestionar.php')],
  ['app/assets/js/gestion_form.js', leer('app', 'assets', 'js', 'gestion_form.js')],
];

/**
 * Quita comentarios de //, de bloque y de SQL. Sin esto una prueba encuentra el literal que
 * prohíbe dentro del comentario que EXPLICA por qué está prohibido, y falla sola.
 */
function sinComentarios(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/)
    .map((l) => l.replace(/(^|\s)\/\/.*$/, '$1'))
    .map((l) => l.replace(/^\s*--.*$/, ''))
    .join('\n');
}

const numero = (src, rx, quien) => {
  const m = src.match(rx);
  assert.ok(m, 'no se encontró ' + quien);
  return Number(m[1]);
};

const MAX = numero(LIB, /define\('V2_OBSERVACION_MAX',\s*(\d+)\)/, 'V2_OBSERVACION_MAX');
const ACUM = numero(LIB, /define\('V2_OBSERVACION_ACUM_MAX',\s*(\d+)\)/, 'V2_OBSERVACION_ACUM_MAX');
// Sin comentarios: la seccion de REVERSION del propio script menciona varchar(360),
// y el regex lo encontraba ahi en vez de en el ALTER de verdad.
const ANCHO = numero(sinComentarios(MIG), /MODIFY `observacion` varchar\((\d+)\)/, 'el ancho en scripts/40');

/* ------------------------------------------------------------------ la librería existe */

test('la librería de dominio expone lo que hace falta', () => {
  for (const f of ['observacion_recortar', 'observacion_normalizar', 'observacion_sql_concat']) {
    assert.match(LIB, new RegExp('function ' + f + '\\('), 'falta ' + f);
  }
});

test('el recorte no parte una entidad HTML por la mitad', () => {
  // Cortar a lo bruto deja "&#03" en vez de "&#039;": se ve como basura y si algo lo vuelve
  // a escapar, el & se duplica.
  assert.match(LIB, /preg_replace\('\/&\[#a-zA-Z0-9\]\{0,9\}\$\/'/,
    'observacion_recortar() perdió la limpieza de la entidad cortada');
});

test('se escapa ANTES de recortar, no al revés', () => {
  // htmlspecialchars() infla el texto: un apóstrofo pasa a 6 caracteres. Lo que tiene que
  // caber en la columna es el texto YA escapado, así que el recorte va después.
  assert.match(LIB, /observacion_recortar\(\s*htmlspecialchars\(/,
    'observacion_normalizar() recorta antes de escapar: el escapado puede volver a pasarse');
});

/* ------------------------------------------------- nadie escribe la columna sin la regla */

test('ningún escritor vuelve a escapar la observación a mano', () => {
  for (const [nombre, src] of ESCRITORES) {
    const limpio = sinComentarios(src);
    assert.ok(!/htmlspecialchars\(\s*trim\([^)]*observacion/i.test(limpio),
      nombre + ' volvió a hacer htmlspecialchars(trim(...)) sobre la observación.\n' +
      '  Eso escapa pero no recorta, que es exactamente como reventaba antes.\n' +
      '  Usá observacion_normalizar().');
    assert.ok(limpio.includes('observacion_normalizar(') || limpio.includes('observacion_recortar('),
      nombre + ' no usa la regla de dominio');
    assert.ok(src.includes('dominio/observacion.php'), nombre + ' no requiere la librería');
  }
});

test('el CONCAT que acumula lleva tope en todos lados', () => {
  // Este es el que hacía el daño permanente: sin tope, una fila llena no se puede volver a
  // guardar nunca. La expresión con tope la construye observacion_sql_concat().
  for (const [nombre, src] of ESCRITORES) {
    const limpio = sinComentarios(src);
    assert.ok(!/CONCAT\(\s*COALESCE\(\s*observacion/i.test(limpio),
      nombre + ' tiene un CONCAT(COALESCE(observacion...)) escrito a mano y sin tope.\n' +
      '  Así fue como las filas se llenaron hasta no poder guardarse más.\n' +
      '  Usá observacion_sql_concat().');
  }
  assert.match(LIB, /RIGHT\(CONCAT\(/,
    'observacion_sql_concat() debe usar RIGHT(): al cortar hay que conservar lo más reciente');
});

/* --------------------------------------------------- los números tienen que ser coherentes */

test('el tope acumulado cabe en la columna', () => {
  // Si alguien sube el tope por encima del ancho de la columna, vuelve el error de raíz.
  assert.ok(ACUM < ANCHO,
    `V2_OBSERVACION_ACUM_MAX (${ACUM}) no cabe en varchar(${ANCHO}) de scripts/40`);
  assert.ok(MAX <= ACUM,
    `V2_OBSERVACION_MAX (${MAX}) no puede superar al acumulado (${ACUM})`);
  // Margen real sobre lo medido: el máximo que existe hoy en la base es ~378 caracteres.
  assert.ok(MAX >= 500, `V2_OBSERVACION_MAX (${MAX}) queda por debajo de lo ya medido en producción`);
});

test('el maxlength del formulario coincide con el tope del servidor', () => {
  // Si no coinciden, el ejecutor escribe hasta un límite y el servidor le corta en otro,
  // que es la clase de sorpresa que hace que nadie confíe en el formulario.
  for (const [nombre, src] of PLANTILLAS) {
    const encontrados = [...src.matchAll(/maxlength=['"](\d+)['"]/g)].map((m) => Number(m[1]));
    assert.ok(encontrados.length > 0, nombre + ' perdió el maxlength del textarea');
    for (const n of encontrados) {
      assert.equal(n, MAX,
        nombre + ' usa maxlength=' + n + ' y V2_OBSERVACION_MAX es ' + MAX);
    }
  }
});

test('todo textarea de observación tiene maxlength', () => {
  for (const [nombre, src] of PLANTILLAS) {
    const textareas = (src.match(/name=['"]observacion\[/g) || []).length;
    const conTope = (src.match(/maxlength=['"]\d+['"]/g) || []).length;
    assert.ok(conTope >= textareas,
      nombre + ' tiene ' + textareas + ' textarea(s) de observación y sólo ' + conTope +
      ' con maxlength');
  }
});

/* -------------------------------------------------------------------------- la migración */

test('scripts/40 ensancha sin reconstruir la tabla ni tocar datos', () => {
  assert.ok(/ALGORITHM=INPLACE, LOCK=NONE/.test(MIG),
    'falta ALGORITHM=INPLACE, LOCK=NONE: sin eso MySQL puede copiar 687.651 filas en silencio');
  assert.ok(/information_schema\.COLUMNS/.test(MIG), 'falta la guarda de reidempotencia');
  assert.ok(/utf8mb3/.test(MIG),
    'cambiar el juego de caracteres convertiría el ALTER en una reconstrucción');
  const sql = sinComentarios(MIG);
  for (const prohibido of [/DROP COLUMN/i, /DROP TABLE/i, /DELETE FROM/i, /UPDATE /i]) {
    assert.ok(!prohibido.test(sql), 'scripts/40 hace algo destructivo: ' + prohibido);
  }
});

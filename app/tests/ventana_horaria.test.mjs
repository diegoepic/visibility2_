/**
 * V-29: la hora a la que abre un local sale de un dato, no del nombre de la cuenta (T6-5).
 *
 * QUE SE MIDIO ANTES DE CAMBIARLO (volcado del 2026-09-03)
 * -------------------------------------------------------
 *       253 cuentas
 *         1 con "botiller" en el nombre: (228, 'BOTILLERIA')
 *     5.544 locales colgando de ella, de 188.664  (2,9%)
 *
 * La regla afecta a casi el 3% de los locales todos los días, así que no es teórica. Lo que
 * sí era frágil es cómo se decidía: `stripos($tipo_cuenta, 'botiller')`. Renombrar la cuenta
 * la apagaba en silencio, no había forma de declarar que otra cuenta abre tarde, y la hora
 * —13:00— estaba escrita a mano en seis sitios del front.
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

const LIB = readFileSync(join(APP, 'lib', 'dominio', 'ventana_horaria.php'), 'utf8');
const MIG = readFileSync(join(RAIZ, 'scripts', '37_cuenta_hora_apertura.sql'), 'utf8');
const IDX = readFileSync(join(APP, 'index_pruebas.php'), 'utf8');
const SYNC = readFileSync(join(APP, 'api', 'sync_bundle.php'), 'utf8');
const SEL = readFileSync(join(APP, 'assets', 'js', 'route_selection.js'), 'utf8');
/* El mapa, el InfoWindow y el aviso de ruta salieron del <script> inline de
   index_pruebas.php a assets/js/ruta_mapa.js en T6-6. Las comprobaciones del FRONT miran
   los dos archivos: lo que renderiza PHP (las filas de la tabla) sigue en la pagina. */
const MAPA = readFileSync(join(APP, 'assets', 'js', 'ruta_mapa.js'), 'utf8');
const FRONT = IDX + MAPA;

/** Quita comentarios: si no, la prueba encuentra el literal en el comentario que lo prohíbe. */
function sinComentarios(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/)
    .map((l) => l.replace(/(^|\s)\/\/.*$/, '$1'))
    .map((l) => l.replace(/^\s*--.*$/, ''))   // comentarios SQL: la reversión los usa
    .join('\n');
}

test('la librería de dominio expone lo que hace falta', () => {
  for (const f of ['ventana_horaria_activa', 'ventana_horaria_col',
                   'ventana_horaria_de_fila', 'ventana_horaria_abre_tarde',
                   'ventana_horaria_minutos']) {
    assert.match(LIB, new RegExp('function ' + f + '\\('), 'falta ' + f);
  }
});

test('nadie decide el horario mirando el nombre de la cuenta', () => {
  // La firma exacta del hallazgo V-29. Fuera de la librería no debe quedar ninguna.
  for (const [nombre, src] of [['index_pruebas.php', IDX], ['api/sync_bundle.php', SYNC]]) {
    assert.ok(!/stripos\([^)]*'botiller'/.test(sinComentarios(src)),
      nombre + ' volvió a decidir el horario con stripos(...,\'botiller\'). ' +
      'El dato vive en cuenta.hora_apertura; usá ventana_horaria_abre_tarde().');
  }
});

test('la hora ya no está escrita a mano en el front', () => {
  const limpio = sinComentarios(FRONT);
  // El respaldo '13:00' sí puede aparecer (mientras scripts/37 no esté aplicado), pero no
  // como texto fijo dentro de un mensaje al usuario.
  assert.ok(!/abre a las 13:00 hrs/.test(limpio),
    'quedó un "abre a las 13:00 hrs" fijo: la hora tiene que salir de horaApertura');
  // El 13*60 sobrevive como RESPALDO —para cuando scripts/37 no está aplicado y el servidor
  // no manda la hora— pero no puede ser lo único: el umbral real sale de los puntos.
  assert.ok(/aperturaTardia/.test(limpio),
    'el aviso del mapa volvió a usar un umbral fijo en vez de la hora de las cuentas');
  assert.ok(limpio.includes('horaApertura'),
    'ni index_pruebas.php ni ruta_mapa.js usan horaApertura');
});

test('los dos lectores del servidor cargan la librería y la usan', () => {
  for (const [nombre, src] of [['index_pruebas.php', IDX], ['api/sync_bundle.php', SYNC]]) {
    assert.ok(src.includes('dominio/ventana_horaria.php'), nombre + ' no requiere la librería');
    assert.ok(src.includes('ventana_horaria_abre_tarde('), nombre + ' no la usa');
    assert.ok(src.includes('ventana_horaria_col('), nombre + ' no trae la columna');
  }
});

test('el helper NO impone MAX(): rompería la consulta sin GROUP BY', () => {
  // sync_bundle.php no agrupa. Un agregado ahí colapsaría la consulta entera a una fila,
  // y el ejecutor se quedaría con un solo local en la ruta.
  assert.ok(!/return[^;]*MAX\(/.test(LIB),
    'ventana_horaria_col() volvió a devolver un MAX(): eso rompe api/sync_bundle.php');
  assert.ok(/MAX\(\{\$colHoraApertura\}\)/.test(IDX),
    'index_pruebas.php agrupa: ahí el MAX() sí hace falta y lo pone el llamador');
  assert.ok(/\{\$colHoraApertura\} AS hora_apertura/.test(SYNC),
    'api/sync_bundle.php no debe envolver la columna en un agregado');
});

test('el cliente recibe la hora y la usa para decidir', () => {
  assert.ok(SEL.includes('horaapertura'),
    'route_selection.js no lee data-horaapertura de la fila');
  assert.ok(/data-horaapertura=/.test(IDX),
    'las filas de la tabla no llevan data-horaapertura');
  // El 13:00 sobrevive SÓLO como respaldo para cuando el servidor no manda la hora.
  assert.ok(/13 \* 60/.test(SEL),
    'route_selection.js perdió el respaldo de 13:00 para cuando falta el dato');
});

test('la migración es aditiva y reidempotente', () => {
  assert.ok(MIG.includes('information_schema.COLUMNS'), 'falta la guarda del ADD COLUMN');
  assert.ok(/ADD COLUMN `hora_apertura` TIME NULL/.test(MIG), 'la columna no es TIME NULL');
  assert.ok(/WHERE hora_apertura IS NULL/.test(MIG),
    'el UPDATE no se acota a lo no poblado: relanzarlo pisaría un horario ajustado a mano');
  // Sin comentarios: la sección de REVERSION documenta el DROP COLUMN a propósito.
  const sql = sinComentarios(MIG);
  for (const prohibido of [/DROP COLUMN/i, /DROP TABLE/i, /DELETE FROM/i]) {
    assert.ok(!prohibido.test(sql), 'scripts/37 hace algo destructivo: ' + prohibido);
  }
});

test('NULL significa "sin restricción", no "abre a medianoche"', () => {
  // Es la decisión que hace segura la migración: 252 de las 253 cuentas quedan como estaban.
  assert.ok(/TIME NULL DEFAULT NULL/.test(MIG), 'la columna no admite NULL por defecto');
  assert.ok(/return\s+ventana_horaria_de_fila\(\$fila\) !== null;/.test(LIB),
    'abre_tarde ya no se define como "tiene hora declarada"');
});

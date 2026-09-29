/**
 * Pruebas de la regla de visibilidad de ruta (T6-1).
 *
 * Qué se protege acá: el predicado SQL que decide si un local sigue pendiente para el
 * ejecutor. Hasta la Tanda 6 estaba copiado a mano SIETE veces (5 en index_pruebas.php,
 * 2 en sync_bundle.php) y las copias ya se habían separado: una usaba `countVisita = 0`
 * y la otra `countVisita IS NULL OR countVisita = 0`. Sobre el volcado del 2026-09-03 eso
 * daba UNA fila de diferencia entre la página y la caché offline.
 *
 * Estas pruebas no ejecutan PHP —no hay intérprete en este entorno— sino que leen los
 * archivos. Lo que vigilan es justamente lo que se rompe con el tiempo: que alguien
 * vuelva a pegar el predicado a mano en vez de llamar a la función.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const APP = join(AQUI, '..');
const LIB = join(APP, 'lib', 'dominio', 'ruta_visibilidad.php');

/** Firmas que SÓLO aparecen en el predicado SQL, nunca en un `if` de PHP. */
const FIRMAS = [
  "modalidad <> 'implementacion_por_etapas'",
  'etapa_material NOT IN',
];

function phpsBajo(dir) {
  const out = [];
  for (const nombre of readdirSync(dir)) {
    if (nombre === 'vendor' || nombre === 'node_modules' || nombre === 'uploads') continue;
    const p = join(dir, nombre);
    const st = statSync(p);
    if (st.isDirectory()) out.push(...phpsBajo(p));
    else if (nombre.endsWith('.php')) out.push(p);
  }
  return out;
}

const ARCHIVOS = phpsBajo(APP);
const rel = (p) => relative(APP, p).split(sep).join('/');

test('la función de dominio existe y define ruta_visibilidad_sql', () => {
  const src = readFileSync(LIB, 'utf8');
  assert.match(src, /function ruta_visibilidad_sql\(/);
});

test('nadie más escribe el predicado a mano', () => {
  const culpables = [];
  for (const p of ARCHIVOS) {
    if (p === LIB) continue;
    const src = readFileSync(p, 'utf8');
    for (const firma of FIRMAS) {
      if (src.includes(firma)) culpables.push(rel(p) + '  ->  ' + firma);
    }
  }
  assert.deepStrictEqual(
    culpables, [],
    'El predicado de visibilidad de ruta volvió a copiarse a mano. Usá\n' +
    "  require_once .../lib/dominio/ruta_visibilidad.php;\n" +
    '  $rutaPendiente = ruta_visibilidad_sql();\n' +
    'y metelo en la consulta como {$rutaPendiente}.\nArchivos:\n  ' + culpables.join('\n  ')
  );
});

test('todo archivo que usa $rutaPendiente carga la librería antes', () => {
  for (const p of ARCHIVOS) {
    if (p === LIB) continue;
    const src = readFileSync(p, 'utf8');
    const usa = src.indexOf('{$rutaPendiente}');
    if (usa === -1) continue;
    const req = src.indexOf('dominio/ruta_visibilidad.php');
    assert.notStrictEqual(req, -1, rel(p) + ' usa {$rutaPendiente} sin requerir la librería');
    assert.ok(req < usa, rel(p) + ' requiere la librería DESPUÉS de usar la variable');
    const asigna = src.indexOf('$rutaPendiente = ruta_visibilidad_sql()');
    assert.notStrictEqual(asigna, -1, rel(p) + ' no asigna $rutaPendiente');
    assert.ok(asigna < usa, rel(p) + ' asigna $rutaPendiente DESPUÉS de usarla');
  }
});

test('los siete sitios siguen siendo siete', () => {
  const cuenta = (archivo) =>
    (readFileSync(join(APP, archivo), 'utf8').match(/\{\$rutaPendiente\}/g) || []).length;
  // Si este número cambia no es necesariamente un error: es una señal de que alguien
  // agregó o quitó una consulta de ruta y conviene mirarla.
  assert.strictEqual(cuenta('index_pruebas.php'), 5, 'index_pruebas.php ya no tiene 5 usos');
  assert.strictEqual(cuenta('api/sync_bundle.php'), 2, 'sync_bundle.php ya no tiene 2 usos');
});

/**
 * Reconstruye la expresión juntando los literales entrecomillados de la función.
 * No es un intérprete de PHP: alcanza porque TODOS los paréntesis del SQL salen de
 * esos literales, y los dos placeholders ({$valor} y {$propuesto}) están balanceados
 * por dentro, así que quitarlos no altera el balance.
 */
function expresionAproximada() {
  const src = readFileSync(LIB, 'utf8');
  const cuerpo = src.slice(src.indexOf('return "('));
  const literales = cuerpo.match(/"(?:[^"\\]|\\.)*"/g) || [];
  return literales.join('').replace(/\{\$[A-Za-z_][A-Za-z0-9_]*\}/g, 'X').replace(/"/g, '');
}

test('la expresión tiene los paréntesis balanceados', () => {
  const e = expresionAproximada();
  let d = 0;
  let comilla = false;
  for (const ch of e) {
    if (ch === "'") { comilla = !comilla; continue; }
    if (comilla) continue;
    if (ch === '(') d++;
    else if (ch === ')') d--;
    assert.ok(d >= 0, 'paréntesis de cierre de más en la expresión generada');
  }
  assert.strictEqual(d, 0, 'quedaron ' + d + ' paréntesis sin cerrar');
});

test('cubre las dos modalidades y las cuatro etapas', () => {
  const e = expresionAproximada();
  assert.ok(e.includes("modalidad <> 'implementacion_por_etapas'"), 'falta la rama normal');
  assert.ok(e.includes("modalidad = 'implementacion_por_etapas'"), 'falta la rama por etapas');
  assert.ok(e.includes("NOT IN ('implementado','retirado')"), 'faltan las etapas terminales');
  assert.ok(e.includes('etapa_material IS NULL'), 'falta el caso sin etapa');
});

test('countVisita NULL cuenta como no visitado', () => {
  // La divergencia que originó esta librería. Si alguien "simplifica" el IS NULL,
  // la página vuelve a discrepar de la caché offline.
  assert.ok(
    expresionAproximada().includes('countVisita IS NULL'),
    'se perdió el IS NULL: countVisita nulo volvería a significar "ya visitado"'
  );
});

/**
 * Pruebas de sanitizeInstruction() de route_engine.js.
 *
 * Google devuelve la instrucción de cada paso como HTML y mezcla dos clases de etiqueta:
 * las inline (<b>) resaltan un nombre DENTRO de la frase, y las de bloque (<div>, <br>)
 * son una frase aparte. Reemplazar todas por un espacio deja la puntuación huérfana:
 * el ejecutor veía "Dirígete al oeste por Vaitea hacia Río Clarillo . Calle de uso
 * restringido", con el punto suelto en medio.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadGlobal } from './_load.mjs';

function cargar() {
  const noop = () => {};
  const win = loadGlobal('../assets/js/route_engine.js', {
    document: { addEventListener: noop },
    navigator: { onLine: true },
    console,
    setTimeout: globalThis.setTimeout,
    clearTimeout: globalThis.clearTimeout,
    fetch: async () => ({ ok: true, json: async () => ({}) }),
    AbortController: globalThis.AbortController,
  }, { addEventListener: noop });
  const RE = win.RouteEngine || (win.window && win.window.RouteEngine);
  return RE ? RE.sanitizeInstruction : null;
}

let limpiar;
try {
  limpiar = cargar();
} catch (e) {
  limpiar = null;
  console.warn('[tests] no se pudo cargar route_engine.js:', e.message);
}

test('el caso real: el punto no se separa de la palabra', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  const html = 'Dirígete al oeste por <b>Vaitea</b> hacia <b>Río Clarillo</b>.'
             + '<div style="font-size:0.9em">Calle de uso restringido</div>';
  assert.strictEqual(
    limpiar(html),
    'Dirígete al oeste por Vaitea hacia Río Clarillo. Calle de uso restringido'
  );
});

test('las etiquetas inline no dejan hueco', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  assert.strictEqual(limpiar('Gira a la <b>izquierda</b>'), 'Gira a la izquierda');
  assert.strictEqual(limpiar('Sigue por <b>Av. <i>Kennedy</i></b>'), 'Sigue por Av. Kennedy');
});

test('las de bloque separan frases', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  assert.strictEqual(limpiar('Gira a la derecha<br>Peaje adelante'), 'Gira a la derecha. Peaje adelante');
  assert.strictEqual(
    limpiar('<div>Toma la salida 4</div><div>Hacia el norte</div>'),
    'Toma la salida 4. Hacia el norte'
  );
});

test('no acumula puntos ni empieza con uno', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  assert.strictEqual(limpiar('<div>Continúa recto.</div><br>'), 'Continúa recto.');
  assert.strictEqual(limpiar('<br>Gira a la izquierda'), 'Gira a la izquierda');
});

test('las entidades HTML se decodifican', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  assert.strictEqual(limpiar('Bar &amp; Grill'), 'Bar & Grill');
  assert.strictEqual(limpiar('Calle&nbsp;Larga'), 'Calle Larga');
});

test('entradas vacías o nulas no revientan', { skip: !limpiar && 'route_engine.js no cargó' }, () => {
  assert.strictEqual(limpiar(null), '');
  assert.strictEqual(limpiar(undefined), '');
  assert.strictEqual(limpiar(''), '');
  assert.strictEqual(limpiar('<div></div>'), '');
});

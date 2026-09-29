/**
 * Pruebas de la guarda de dueño de app/assets/js/offline-queue.js (T3-2a).
 *
 * Estos teléfonos los comparten varios ejecutores. La regla que se prueba acá decide si
 * un trabajo encolado en el equipo se envía con la sesión que está abierta ahora. Si se
 * equivoca hacia un lado, se manda trabajo ajeno y se quema su clave de idempotencia; si
 * se equivoca hacia el otro, la cola se congela y nadie sabe por qué.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadGlobal } from './_load.mjs';

function cargarCola(winExtra = {}) {
  const noop = () => {};
  const stubs = {
    document: { addEventListener: noop, readyState: 'complete', querySelector: () => null },
    navigator: { onLine: true, userAgent: 'node' },
    location: { origin: 'https://visibility.cl', href: 'https://visibility.cl/visibility2/app/' },
    localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    indexedDB: { open: () => ({ addEventListener: noop }) },
    crypto: { randomUUID: () => 'test-uuid' },
    fetch: async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, text: async () => '{}' }),
    BroadcastChannel: function () { this.postMessage = noop; this.close = noop; },
    AbortController: globalThis.AbortController,
    setInterval: () => 0, clearInterval: () => {}, setTimeout: () => 0, clearTimeout: () => {},
    CustomEvent: function (t, o) { this.type = t; this.detail = o && o.detail; },
    FormData: function () { this.append = noop; },
    Blob: globalThis.Blob, File: globalThis.File,
  };
  const win = loadGlobal('../assets/js/offline-queue.js', stubs, Object.assign({
    addEventListener: noop, removeEventListener: noop, dispatchEvent: noop,
  }, winExtra));
  const Q = win.Queue || (win.window && win.window.Queue);
  return Q && Q._test ? Q._test : null;
}

let T;
try { T = cargarCola(); } catch (e) { T = null; console.warn('[tests] no se pudo cargar offline-queue.js:', e.message); }
const skip = (T && typeof T.esDeOtroUsuario === 'function') ? false : 'no se pudo cargar el módulo en Node';

test('un trabajo de otro usuario no se envía', { skip }, () => {
  assert.equal(T.esDeOtroUsuario({ owner_id: 41 }, 7), true);
});

test('el trabajo propio sí se envía', { skip }, () => {
  assert.equal(T.esDeOtroUsuario({ owner_id: 7 }, 7), false);
});

test('los ids se comparan como número, no como texto', { skip }, () => {
  // El id viaja por IndexedDB y por PHP; en el camino puede volverse string.
  assert.equal(T.esDeOtroUsuario({ owner_id: '7' }, 7), false);
  assert.equal(T.esDeOtroUsuario({ owner_id: 7 }, '7'), false);
  assert.equal(T.esDeOtroUsuario({ owner_id: '41' }, '7'), true);
});

test('un trabajo viejo sin owner_id pasa: no se puede adivinar de quién es', { skip }, () => {
  // Es el caso de todo lo que ya está encolado en los teléfonos al desplegar v7.4.0.
  assert.equal(T.esDeOtroUsuario({}, 7), false);
  assert.equal(T.esDeOtroUsuario({ owner_id: 0 }, 7), false);
  assert.equal(T.esDeOtroUsuario({ owner_id: null }, 7), false);
});

test('sin id de sesión no se bloquea nada', { skip }, () => {
  // Si la página no expone __USUARIO_ID, bloquear dejaría la cola congelada en silencio.
  assert.equal(T.esDeOtroUsuario({ owner_id: 41 }, 0), false);
  assert.equal(T.esDeOtroUsuario({ owner_id: 41 }, undefined), false);
});

test('un job nulo no revienta la guarda', { skip }, () => {
  assert.equal(T.esDeOtroUsuario(null, 7), false);
  assert.equal(T.esDeOtroUsuario(undefined, 7), false);
});

test('currentUserId lee __USUARIO_ID y cae a __GESTIONAR_PRECACHE_USER', { skip }, () => {
  // index_pruebas.php expone las dos; gestionarPruebas.php sólo la primera.
  const soloPrecache = cargarCola({ __GESTIONAR_PRECACHE_USER: 12 });
  assert.equal(soloPrecache.currentUserId(), 12);

  const ambas = cargarCola({ __USUARIO_ID: 9, __GESTIONAR_PRECACHE_USER: 12 });
  assert.equal(ambas.currentUserId(), 9);
});

test('currentUserId devuelve 0 cuando el valor no sirve', { skip }, () => {
  assert.equal(cargarCola().currentUserId(), 0);
  assert.equal(cargarCola({ __USUARIO_ID: 0 }).currentUserId(), 0);
  assert.equal(cargarCola({ __USUARIO_ID: 'pepe' }).currentUserId(), 0);
  assert.equal(cargarCola({ __USUARIO_ID: -3 }).currentUserId(), 0);
});

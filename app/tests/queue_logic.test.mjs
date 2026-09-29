/**
 * Pruebas de la clasificación de fallos de app/assets/js/offline-queue.js.
 *
 * Es la lógica que decide si una gestión se reintenta, se descarta o se da por buena.
 * Un error acá no se ve en pantalla: se ve como trabajo que desaparece.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadGlobal } from './_load.mjs';

/**
 * offline-queue.js se registra en eventos del navegador y abre IndexedDB al cargarse.
 * Estos stubs son lo mínimo para que el archivo se ejecute; no simulan comportamiento,
 * sólo evitan que reviente antes de exponer sus funciones.
 */
function cargarCola() {
  const noop = () => {};
  const stubs = {
    document: { addEventListener: noop, readyState: 'complete', querySelector: () => null },
    // Sin las claves serviceWorker/storage: el módulo pregunta con `'serviceWorker' in
    // navigator`, que da verdadero aunque el valor sea undefined, y entonces intenta
    // suscribirse a los mensajes del service worker.
    navigator: { onLine: true, userAgent: 'node' },
    location: { origin: 'https://visibility.cl', href: 'https://visibility.cl/visibility2/app/' },
    localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    indexedDB: { open: () => ({ addEventListener: noop }) },
    crypto: { randomUUID: () => 'test-uuid' },
    fetch: async () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, text: async () => '{}' }),
    BroadcastChannel: function () { this.postMessage = noop; this.close = noop; },
    AbortController: globalThis.AbortController,
    // El módulo arranca heartbeat y refresco de CSRF con setInterval. Con los timers
    // reales, el proceso de pruebas nunca termina: se neutralizan.
    setInterval: () => 0,
    clearInterval: () => {},
    setTimeout: () => 0,
    clearTimeout: () => {},
    CustomEvent: function (t, o) { this.type = t; this.detail = o && o.detail; },
    FormData: function () { this.append = noop; },
    Blob: globalThis.Blob,
    File: globalThis.File,
  };
  // El `window` lo crea loadGlobal; acá se le siembran los métodos de eventos que el
  // módulo usa nada más cargarse (window.addEventListener('online', ...)).
  const win = loadGlobal('../assets/js/offline-queue.js', stubs, {
    addEventListener: noop, removeEventListener: noop, dispatchEvent: noop,
  });
  const Q = win.Queue || (win.window && win.window.Queue);
  // Las funciones internas se exponen en Queue._test, que ya existía en el módulo.
  return Q && Q._test ? Object.assign({}, Q._test, { Queue: Q }) : null;
}

let Queue;
try { Queue = cargarCola(); } catch (e) { Queue = null; console.warn('[tests] no se pudo cargar offline-queue.js:', e.message); }

const disponible = !!(Queue && typeof Queue.classifyFailure === 'function');

test('offline-queue.js expone classifyFailure', { skip: disponible ? false : 'no se pudo cargar el módulo en Node' }, () => {
  assert.equal(typeof Queue.classifyFailure, 'function');
});

test('un 500 es reintentable', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 500 }, parsed: { json: {}, isHtml: false } });
  assert.equal(r.retryable, true, 'un error de servidor tiene que reintentarse');
});

test('un 422 de validación NO es reintentable', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 422 }, parsed: { json: { error_code: 'VALIDATION' }, isHtml: false } });
  assert.equal(r.retryable, false, 'reintentar una validación fallida sólo gasta batería');
});

test('un 401 bloquea la cola por sesión', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 401 }, parsed: { json: {}, isHtml: false } });
  assert.equal(r.blocked, 'auth');
  assert.equal(r.retryable, false);
});

test('DUPLICATE_PHOTO es terminal y NO cuenta como éxito', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'DUPLICATE_PHOTO' }, isHtml: false } });
  assert.equal(r.retryable, false, 'la misma foto en otro local nunca va a ser aceptada');
  assert.notEqual(r.success, true, 'la foto NO se guardó: darla por buena la borra de la cola');
});

test('REQUEST_IN_PROGRESS se reintenta', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'REQUEST_IN_PROGRESS', retry_after: 5 }, isHtml: false } });
  assert.equal(r.retryable, true);
});

/**
 * HALLAZGO V-12 — pendiente hasta la tarea T4-2 del plan.
 *
 * Hasta v7.4.0 un 409 sin error_code se clasificaba como { success: true } y drain()
 * borraba el trabajo de IndexedDB aunque el servidor nunca lo hubiera guardado. Corregido
 * en T4-2 (v7.5.0): ahora va a 'conflict' y espera decisión de una persona.
 */
test('un 409 SIN error_code no se da por bueno (V-12)', { skip: !disponible }, () => {
  const r = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: {}, isHtml: false } });
  assert.notEqual(r.success, true, 'sin error_code no se sabe si quedó guardado: no se puede borrar el trabajo');
  assert.equal(r.conflict, true, 'debe quedar como conflicto, no como error reintentable');
  assert.equal(r.retryable, false, 'reintentar el mismo 409 daría lo mismo');
});

test('un 409 que SÍ dice DUPLICATE se sigue dando por bueno', { skip: !disponible }, () => {
  // Es el caso legítimo: el servidor confirma que el registro ya existe.
  for (const code of ['DUPLICATE', 'ALREADY_EXISTS']) {
    const r = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: code }, isHtml: false } });
    assert.equal(r.success, true, code + ' debe contar como éxito lógico');
  }
});

test('los 409 con significado propio conservan su clasificación', { skip: !disponible }, () => {
  const enCurso = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'REQUEST_IN_PROGRESS' }, isHtml: false } });
  assert.equal(enCurso.retryable, true, 'otro proceso lo está guardando: hay que reintentar');

  const mismatch = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'IDEMPOTENCY_PAYLOAD_MISMATCH' }, isHtml: false } });
  assert.equal(mismatch.retryable, false);
  assert.notEqual(mismatch.success, true);

  const foto = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'DUPLICATE_PHOTO' }, isHtml: false } });
  assert.equal(foto.code, 'DUPLICATE_PHOTO', 'la foto duplicada tiene su propio camino en la UI');
  assert.notEqual(foto.success, true);
});

test('un 409 con un código desconocido conserva el código del servidor', { skip: !disponible }, () => {
  // Para que el centro de recuperación pueda traducirlo a una frase.
  const r = Queue.classifyFailure({ response: { status: 409 }, parsed: { json: { error_code: 'ASSIGNMENT_CHANGED' }, isHtml: false } });
  assert.equal(r.code, 'ASSIGNMENT_CHANGED');
  assert.equal(r.conflict, true);
});

// --- isLogicalSuccess: la validación funcional de la respuesta ------------------

const tieneILS = !!(Queue && typeof Queue.isLogicalSuccess === 'function');

test('isLogicalSuccess rechaza respuestas que no confirman nada', { skip: tieneILS ? false : 'no exportado' }, () => {
  assert.equal(Queue.isLogicalSuccess({}), false);
  assert.equal(Queue.isLogicalSuccess({ ok: false }), false);
  assert.equal(Queue.isLogicalSuccess({ status: 'error' }), false);
  assert.equal(Queue.isLogicalSuccess(null), false);
});

test('isLogicalSuccess acepta las formas que usan los endpoints', { skip: tieneILS ? false : 'no exportado' }, () => {
  assert.equal(Queue.isLogicalSuccess({ ok: true }), true);
  assert.equal(Queue.isLogicalSuccess({ status: 'success' }), true);
  assert.equal(Queue.isLogicalSuccess({ status: 'ok' }), true);
});

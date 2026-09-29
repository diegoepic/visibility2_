/**
 * C-2: guardar la ruta planificada en `ruta` / `ruta_parada`.
 *
 * QUE SE MIDIO ANTES DE ESCRIBIR NADA (volcado del 2026-09-03)
 * -----------------------------------------------------------
 *   ruta          2 filas      ruta_parada   2 filas
 *
 * Las dos tablas existian, bien disenadas, y NADIE las leia ni las escribia. Las 2 filas son
 * del 2025-08-26, del mismo usuario y la misma fecha, ambas 'en_progreso' y nunca
 * finalizadas: un experimento abandonado. En el historial de git la cadena "ruta_parada" solo
 * aparece dentro de los propios volcados, o sea que nunca hubo un escritor versionado.
 *
 * LA IDEA QUE ORDENA TODO ESTE CODIGO
 * -----------------------------------
 * El plan de ruta es DATO DERIVADO, no trabajo de campo. Si se pierde, el siguiente recalculo
 * lo vuelve a escribir. De ahi salen las tres reglas que estas pruebas defienden:
 *
 *   1. No pasa por la cola offline. La cola es para lo que no se puede perder, y su contador
 *      de pendientes es algo que el ejecutor mira todos los dias.
 *   2. Nada de esto puede afectar a que el ejecutor vea su ruta: el cliente es silencioso y
 *      el servidor responde 200 incluso cuando falla.
 *   3. Replanificar NO puede borrar lo que ya paso en el terreno.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadGlobal } from './_load.mjs';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const APP = join(RAIZ, 'app');
const EP = readFileSync(join(APP, 'api', 'ruta_plan_guardar.php'), 'utf8');
const MIG = readFileSync(join(RAIZ, 'scripts', '38_ruta_plan.sql'), 'utf8');
const IDX = readFileSync(join(APP, 'index_pruebas.php'), 'utf8');

/** Quita comentarios: si no, la prueba encuentra el literal en el comentario que lo explica. */
function sinComentarios(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/)
    .map((l) => l.replace(/(^|\s)\/\/.*$/, '$1'))
    .map((l) => l.replace(/^\s*--.*$/, ''))
    .join('\n');
}

/* ══════════════════════════════ el cliente, ejecutado de verdad ══════════════════════════ */

const noop = () => {};

/**
 * Carga ruta_mapa.js entero en una vm con jQuery y el DOM apenas simulados, y devuelve
 * { win, llamadas } donde `llamadas` va acumulando cada fetch que haga el archivo.
 *
 * Es preferible a buscar texto: comprueba lo que el codigo HACE. El archivo se puede cargar
 * asi porque, salvo un $(document).ready, todo lo demas son definiciones.
 */
function cargarCliente({ onLine = true, url = '/api/x.php', respuesta = { ok: true, guardado: true } } = {}) {
  const llamadas = [];
  const nodo = {};
  Object.assign(nodo, {
    on: () => nodo, ready: (f) => { try { f(); } catch (_) { /* el DOM no existe */ } return nodo; },
    val: () => '', length: 0, text: () => nodo, html: () => nodo, empty: () => nodo,
    prop: () => nodo, is: () => false, stop: () => nodo, fadeOut: () => nodo, show: () => nodo,
    hide: () => nodo, each: () => nodo, find: () => nodo, addClass: () => nodo,
    removeClass: () => nodo, attr: () => '', data: () => '', append: () => nodo,
    remove: () => nodo, closest: () => nodo, trigger: () => nodo, css: () => nodo,
  });
  const jq = () => nodo;
  jq.ajax = noop;
  jq.fn = {};

  const win = loadGlobal('../assets/js/ruta_mapa.js', {
    $: jq,
    jQuery: jq,
    fetch: async (u, opts) => {
      llamadas.push({ url: u, opts });
      if (respuesta === 'error') throw new Error('sin red');
      return { ok: true, json: async () => respuesta };
    },
    navigator: { onLine, geolocation: { watchPosition: noop, getCurrentPosition: noop } },
    document: {
      addEventListener: noop, getElementById: () => null, querySelector: () => null,
      querySelectorAll: () => [], createElement: () => ({ style: {}, setAttribute: noop }),
      visibilityState: 'visible',
    },
    localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    URLSearchParams,
    location: { href: '', origin: '' },
  }, {
    addEventListener: noop,
    matchMedia: () => ({ matches: false, addEventListener: noop }),
    __RUTA_PLAN_URL: url,
    CSRF_TOKEN: 'tok-123',
  });
  return { win, llamadas };
}

const PARADAS = [
  { idLocal: 11, lat: -33.44, lng: -70.66 },
  { idLocal: 22, lat: -33.45, lng: -70.67 },
];
const ORIGEN = { lat: -33.4489, lng: -70.6693 };
const vaciarCola = () => new Promise((r) => setTimeout(r, 0));

test('guarda el plan con el token, el modo y las paradas ordenadas', async () => {
  const { win, llamadas } = cargarCliente();
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'reag');
  await vaciarCola();

  assert.equal(llamadas.length, 1, 'no salió la petición');
  const b = llamadas[0].opts.body;
  assert.equal(b.get('csrf_token'), 'tok-123');
  assert.equal(b.get('fecha'), '2026-09-09');
  assert.equal(b.get('modo'), 'reag');
  assert.equal(b.get('origen_lat'), String(ORIGEN.lat));
  // El orden importa: es la secuencia que calculó el planificador y se guarda como `seq`.
  assert.deepStrictEqual(JSON.parse(b.get('paradas')).map((p) => p.local_id), [11, 22]);
  assert.equal(llamadas[0].opts.method, 'POST');
});

test('sin red no se intenta siquiera', async () => {
  const { win, llamadas } = cargarCliente({ onLine: false });
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  assert.equal(llamadas.length, 0,
    'intentó guardar sin conexión. El plan es dato derivado: sin red no se encola ni se ' +
    'reintenta, se descarta y ya lo escribirá el siguiente recálculo.');
});

test('con __RUTA_PLAN_URL vacía queda apagado, sin desplegar código', async () => {
  const { win, llamadas } = cargarCliente({ url: '' });
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  assert.equal(llamadas.length, 0, 'la URL vacía tiene que ser el interruptor de apagado');
});

test('no repite la misma escritura', async () => {
  const { win, llamadas } = cargarCliente();
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  assert.equal(llamadas.length, 1, 'escribió dos veces el mismo plan');

  // Cambiar de modo SÍ es otra ruta: prog y reag del mismo día son planes distintos.
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'reag');
  await vaciarCola();
  assert.equal(llamadas.length, 2, 'cambiar de modo tiene que guardar una ruta aparte');
});

test('descarta paradas sin coordenadas usables', async () => {
  const { win, llamadas } = cargarCliente();
  win.guardarPlanEnServidor([
    { idLocal: 11, lat: -33.44, lng: -70.66 },
    { idLocal: 12, lat: null, lng: -70.67 },
    { idLocal: 13, lat: NaN, lng: NaN },
  ], ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  assert.deepStrictEqual(
    JSON.parse(llamadas[0].opts.body.get('paradas')).map((p) => p.local_id), [11]);
});

test('un fallo de red no lanza y no marca el plan como guardado', async () => {
  const { win, llamadas } = cargarCliente({ respuesta: 'error' });
  assert.doesNotThrow(() => win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog'));
  await vaciarCola();
  assert.equal(llamadas.length, 1);
  // Como no se confirmó, el siguiente intento vuelve a mandarlo.
  win.guardarPlanEnServidor(PARADAS, ORIGEN, '2026-09-09', 'prog');
  await vaciarCola();
  assert.equal(llamadas.length, 2, 'un fallo tiene que dejar el plan pendiente de reescribir');
});

test('el plan NO entra en la cola offline', () => {
  // La cola es para trabajo de campo que no se puede perder, y su contador de pendientes es
  // algo que el ejecutor mira. Un plan de ruta ahí dentro sería ruido sobre una señal que
  // usan todos los días.
  const src = sinComentarios(readFileSync(join(APP, 'assets', 'js', 'ruta_mapa.js'), 'utf8'));
  const bloque = src.slice(src.indexOf('function guardarPlanEnServidor'),
                           src.indexOf('window.planRouteFromSelection'));
  for (const cola of ['OfflineQueue', 'Queue.', 'enqueue', 'smartPost']) {
    assert.ok(!bloque.includes(cola),
      'guardarPlanEnServidor usa ' + cola + ': el plan de ruta no va a la cola offline.');
  }
});

/* ═══════════════════════════════ el endpoint y la migración ══════════════════════════════ */

test('el endpoint nunca responde con error después de autenticar', () => {
  // Que no se pueda guardar el plan jamás puede romperle la ruta al ejecutor. Los únicos
  // no-200 permitidos son los tres de la puerta: sin sesión, método equivocado y CSRF.
  const limpio = sinComentarios(EP);
  const codigos = [...limpio.matchAll(/http_response_code\((\d+)\)/g)].map((m) => m[1]);
  assert.deepStrictEqual(codigos.sort(), ['401', '403', '405'],
    'apareció un código de error nuevo: ' + codigos.join(', ') +
    '. Todo fallo posterior a la autenticación tiene que ser rp_salir(true, false, ...).');
});

test('el usuario sale de la sesión, nunca del cuerpo de la petición', () => {
  const limpio = sinComentarios(EP);
  assert.ok(/\$usuarioId\s*=\s*\(int\)\$_SESSION\['usuario_id'\]/.test(limpio),
    'el id de usuario tiene que salir de $_SESSION');
  assert.ok(!/\$_POST\[['"](usuario_id|usuarioId|uid)['"]\]/.test(limpio),
    'el endpoint lee el usuario del cuerpo: eso deja que cualquiera escriba la ruta de otro');
});

test('está guardado contra la migración pendiente', () => {
  // Los despliegues y las migraciones van por separado en este proyecto.
  assert.ok(/SHOW COLUMNS FROM `ruta` LIKE 'modo'/.test(EP),
    'falta la guarda de la columna `modo`: sin scripts/38 el endpoint reventaría');
  assert.ok(/SHOW TABLES LIKE 'ruta_parada'/.test(EP), 'falta la guarda de la tabla');
});

test('replanificar no borra lo que ya pasó en el terreno', () => {
  // ruta_parada tiene UNIQUE(ruta_id, seq) y el orden cambia en cada recálculo, así que las
  // paradas se rehacen enteras. Sin rescatar status/visited_at/nota, un recálculo a media
  // jornada borraría las visitas ya marcadas.
  const limpio = sinComentarios(EP);
  assert.ok(/SELECT local_id, status, visited_at, nota FROM ruta_parada/.test(limpio),
    'ya no se lee el estado previo de las paradas antes de rehacerlas');
  const iLee = limpio.indexOf('SELECT local_id, status, visited_at, nota');
  const iBorra = limpio.indexOf('DELETE FROM ruta_parada');
  assert.ok(iLee > 0 && iBorra > iLee,
    'el DELETE de las paradas quedó ANTES de leer su estado: se pierden las visitas');
  assert.ok(/\$ant\['visited_at'\]/.test(limpio) && /\$ant\['status'\]/.test(limpio),
    'el estado previo se lee pero no se reaplica al insertar');
});

test('el estado de la ruta no retrocede al replanificar', () => {
  const limpio = sinComentarios(EP);
  // El UPDATE de una ruta existente sólo toca el origen; 'planeada' se escribe únicamente
  // en el INSERT. Si alguien mete estado en el UPDATE, una jornada empezada volvería atrás.
  const upd = limpio.slice(limpio.indexOf('UPDATE ruta SET'), limpio.indexOf('} else {'));
  assert.ok(!/estado/.test(upd),
    'el UPDATE de ruta toca `estado`: replanificar devolvería a "planeada" una jornada ya ' +
    'empezada o cerrada.');
  assert.ok(/INSERT INTO ruta[\s\S]{0,200}'planeada'/.test(limpio),
    "'planeada' sólo debe escribirse al crear la ruta");
});

test('la migración es guardada y su único borrado es el que documenta', () => {
  assert.ok(MIG.includes('information_schema.COLUMNS'), 'falta la guarda del ADD COLUMN');
  assert.ok(MIG.includes('information_schema.STATISTICS'), 'falta la guarda del índice único');
  const sql = sinComentarios(MIG);
  // Esta es la única migración del plan que borra filas, y sólo las duplicadas que impiden
  // crear la clave única. Cualquier otro borrado tiene que saltar aquí.
  const borrados = [...sql.matchAll(/DELETE FROM (\w+)/g)].map((m) => m[1]).sort();
  assert.deepStrictEqual(borrados, ['ruta', 'ruta_parada'],
    'aparecieron borrados nuevos en scripts/38: ' + borrados.join(', '));
  assert.ok(/estado <> 'finalizada'/.test(sql),
    'el borrado ya no protege las rutas finalizadas');
  assert.ok(!/DROP TABLE/i.test(sql) && !/DROP COLUMN/i.test(sql),
    'scripts/38 hace algo destructivo que no estaba previsto');
});

test('la página entrega el token y la URL que el cliente necesita', () => {
  assert.ok(/window\.CSRF_TOKEN\s*=/.test(IDX), 'index_pruebas.php no emite CSRF_TOKEN');
  assert.ok(/window\.__RUTA_PLAN_URL\s*=/.test(IDX), 'index_pruebas.php no emite __RUTA_PLAN_URL');
  assert.ok(/require_once __DIR__ \. '\/lib\/csrf\.php'/.test(IDX),
    'index_pruebas.php usa csrf_token() sin cargar lib/csrf.php');
});

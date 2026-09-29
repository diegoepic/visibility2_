/**
 * V-27: ¿clusterizar antes de partir en bloques gana kilómetros? MEDIDO: no.
 *
 * QUE ES ESTE ARCHIVO
 * -------------------
 * El plan de la Tanda 6 pedía reemplazar el corte secuencial de `chunkPoints` por un
 * clustering geográfico (k-means), con el argumento de que "la partición en bloques destruye
 * la optimalidad global". Se implementó, se midió, y salió al revés.
 *
 * Este archivo es la evidencia, y es reproducible: el k-means vive acá abajo —NO en
 * producción, porque no se activó— y las pruebas comparan las dos estrategias.
 *
 *     trazado                  corte actual   k-means
 *     nube uniforme, 30           143,0 km    150,1 km    +5,0%
 *     nube uniforme, 60           239,0 km    280,8 km   +17,5%
 *     nube uniforme, 120          368,0 km    463,0 km   +25,8%
 *     3 comunas x 20               68,3 km     76,7 km   +12,4%
 *     2 comunas lejanas x 30       59,6 km     72,2 km   +21,2%
 *     4 comunas x 13               97,0 km    142,7 km   +47,2%
 *
 * POR QUE PIERDE, que es lo que la auditoría no vio
 * -------------------------------------------------
 * Lo que `chunkPoints` corta no es una lista cualquiera: es la salida de
 * nearestNeighbor + twoOpt, o sea un recorrido ya optimizado. **Partirlo en tramos contiguos
 * no cuesta un solo metro** — el encadenado (destino de un bloque = origen del siguiente)
 * reproduce exactamente el mismo camino. Hay una prueba abajo que lo verifica.
 *
 * Clusterizar, en cambio, REORDENA los puntos: los agrupa por cercanía y luego salta de
 * grupo en grupo. Eso rompe el orden global, y lo que se gana —que Google reordene dentro de
 * bloques más compactos— no lo compensa ni de lejos.
 *
 * Contexto: sobre 25.925 rutas (usuario × día) del volcado del 2026-09-03, la mediana es de
 * 13 locales y el p90 de 23. El 94,4% de las jornadas ni siquiera llega a partirse.
 *
 * Si alguien vuelve a proponer esto, que corra estas pruebas primero.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadGlobal } from './_load.mjs';

const RP = loadGlobal('../assets/js/route_planner.js').RoutePlanner;

const ORIGEN = { lat: -33.4489, lng: -70.6693 };
const TAM = RP.SAFE_CHUNK_WAYPOINTS;          // 23 → bloques de 24 puntos

/* ------------------------------------------------------------------------------------
 * El k-means que NO se llevó a producción. Se conserva acá para que la comparación sea
 * reproducible y para que nadie tenga que reimplementarlo si quiere volver a discutirlo.
 * Incluye las dos correcciones que hacían falta para que fuera una comparación justa:
 * escala de longitud por cos(lat), y rebalanceo por capacidad (k-means no controla el
 * tamaño de los grupos, y acá el tamaño es un límite duro de la API de Google).
 * ---------------------------------------------------------------------------------- */
function clusterPoints(points, chunkSize) {
  chunkSize = chunkSize || TAM;
  const capacidad = chunkSize + 1;
  const pts = (points || []).filter((p) => p && Number.isFinite(p.lat) && Number.isFinite(p.lng));
  if (pts.length <= capacidad) return pts.length ? [pts.slice()] : [];

  const k = Math.ceil(pts.length / capacidad);
  const latRef = pts.reduce((s, p) => s + p.lat, 0) / pts.length;
  const kx = Math.cos((latRef * Math.PI) / 180);
  const xy = pts.map((p) => ({ x: p.lng * kx, y: p.lat }));
  const d2 = (a, b) => { const dx = a.x - b.x, dy = a.y - b.y; return dx * dx + dy * dy; };

  // Semillas deterministas: siempre el punto más lejano, nunca al azar.
  const centros = [xy[0]];
  while (centros.length < k) {
    let mejor = 0, mejorD = -1;
    for (let i = 0; i < xy.length; i++) {
      let dm = Infinity;
      for (const c of centros) dm = Math.min(dm, d2(xy[i], c));
      if (dm > mejorD) { mejorD = dm; mejor = i; }
    }
    centros.push(xy[mejor]);
  }

  const asig = new Array(xy.length).fill(0);
  for (let iter = 0; iter < 25; iter++) {
    let cambio = false;
    for (let i = 0; i < xy.length; i++) {
      let mejor = 0, mejorD = Infinity;
      for (let c = 0; c < centros.length; c++) {
        const dd = d2(xy[i], centros[c]);
        if (dd < mejorD) { mejorD = dd; mejor = c; }
      }
      if (asig[i] !== mejor) { asig[i] = mejor; cambio = true; }
    }
    for (let c = 0; c < centros.length; c++) {
      let sx = 0, sy = 0, cn = 0;
      for (let i = 0; i < xy.length; i++) if (asig[i] === c) { sx += xy[i].x; sy += xy[i].y; cn++; }
      if (cn) centros[c] = { x: sx / cn, y: sy / cn };
    }
    if (!cambio) break;
  }

  const grupos = centros.map(() => []);
  for (let i = 0; i < xy.length; i++) grupos[asig[i]].push(i);

  for (let vuelta = 0; vuelta < 200; vuelta++) {
    const lleno = grupos.findIndex((g) => g.length > capacidad);
    if (lleno === -1) break;
    let peor = grupos[lleno][0], peorD = -1;
    for (const i of grupos[lleno]) {
      const dd = d2(xy[i], centros[lleno]);
      if (dd > peorD) { peorD = dd; peor = i; }
    }
    let destino = -1, destinoD = Infinity;
    for (let c = 0; c < grupos.length; c++) {
      if (c === lleno || grupos[c].length >= capacidad) continue;
      const dd = d2(xy[peor], centros[c]);
      if (dd < destinoD) { destinoD = dd; destino = c; }
    }
    if (destino === -1) break;
    grupos[lleno] = grupos[lleno].filter((i) => i !== peor);
    grupos[destino].push(peor);
  }

  return grupos
    .filter((g) => g.length)
    .map((g) => g.slice().sort((a, b) => a - b))
    .sort((a, b) => a[0] - b[0])
    .map((g) => g.map((i) => pts[i]));
}

/** n puntos alrededor de un centro. Determinista: una comparación de distancias no puede depender del azar. */
function nube(centro, n, radio, semilla) {
  let s = semilla || 1;
  const rnd = () => { s = (s * 1103515245 + 12345) & 0x7fffffff; return s / 0x7fffffff; };
  const out = [];
  for (let i = 0; i < n; i++) {
    out.push({
      idLocal: (semilla || 1) * 1000 + i,
      lat: centro.lat + (rnd() - 0.5) * radio,
      lng: centro.lng + (rnd() - 0.5) * radio,
    });
  }
  return out;
}

/** El preordenamiento que hace planFull antes de partir. */
const preordenar = (pts) => RP.twoOpt(ORIGEN, RP.nearestNeighbor(ORIGEN, pts));

function comparar(pts) {
  const ordenados = preordenar(pts);
  return {
    corte: RP.distanciaDeBloques(ORIGEN, RP.chunkPoints(ordenados, TAM)),
    cluster: RP.distanciaDeBloques(ORIGEN, clusterPoints(ordenados, TAM)),
  };
}

const km = (m) => (m / 1000).toFixed(1) + ' km';

/* ============================ la propiedad que hace innecesario el clustering ========= */

test('partir una secuencia optimizada en bloques contiguos NO cuesta kilómetros', () => {
  // Esta es LA razón por la que V-27 no aplica. Si esta prueba se pusiera en rojo, entonces
  // sí habría algo que arreglar en la partición.
  for (const n of [30, 60, 97, 150]) {
    const ordenados = preordenar(nube(ORIGEN, n, 0.3, n));
    const enteroComoUnBloque = RP.distanciaDeBloques(ORIGEN, [ordenados]);
    const partido = RP.distanciaDeBloques(ORIGEN, RP.chunkPoints(ordenados, TAM));
    assert.strictEqual(Math.round(partido), Math.round(enteroComoUnBloque),
      `con n=${n}, partir cambió la distancia: ${km(enteroComoUnBloque)} -> ${km(partido)}`);
  }
});

test('el helper de comparación encadena bien', () => {
  const a = { lat: -33.4489, lng: -70.6693 };
  const b = { lat: -33.4589, lng: -70.6693 };
  const c = { lat: -33.4689, lng: -70.6693 };
  assert.strictEqual(
    Math.round(RP.distanciaDeBloques(a, [[b, c]])),
    Math.round(RP.distanciaDeBloques(a, [[b], [c]])),
    'partir en dos bloques no debe cambiar la distancia: el encadenado está roto');
  assert.strictEqual(
    Math.round(RP.distanciaDeBloques(a, [[b, c]])),
    Math.round(RP.haversine(a, b) + RP.haversine(b, c)));
});

/* ==================================== la medición que decidió no activar el clustering == */

test('clusterizar pierde en trazados dispersos', () => {
  for (const [n, semilla] of [[30, 11], [60, 11], [120, 11]]) {
    const r = comparar(nube(ORIGEN, n, 0.35, semilla));
    assert.ok(r.cluster > r.corte,
      `con n=${n} el clustering ganó (${km(r.cluster)} vs ${km(r.corte)}). ` +
      'Si esto pasa de verdad, vale la pena reabrir V-27 con estos números.');
  }
});

test('clusterizar pierde TAMBIÉN con comunas separadas, que era su caso fuerte', () => {
  // La auditoría suponía que acá ganaría: bolsones bien separados, como una jornada que
  // cruza varias comunas. Pierde por 12%, 21% y 47%.
  const casos = {
    '3 comunas x 20': [
      ...nube({ lat: -33.44, lng: -70.65 }, 20, 0.04, 1),
      ...nube({ lat: -33.52, lng: -70.58 }, 20, 0.04, 2),
      ...nube({ lat: -33.38, lng: -70.75 }, 20, 0.04, 3),
    ],
    '2 comunas lejanas x 30': [
      ...nube({ lat: -33.44, lng: -70.65 }, 30, 0.05, 4),
      ...nube({ lat: -33.60, lng: -70.58 }, 30, 0.05, 5),
    ],
    '4 comunas x 13': [
      ...nube({ lat: -33.44, lng: -70.65 }, 13, 0.03, 6),
      ...nube({ lat: -33.52, lng: -70.90 }, 13, 0.03, 7),
      ...nube({ lat: -33.30, lng: -70.60 }, 13, 0.03, 8),
      ...nube({ lat: -33.60, lng: -70.75 }, 13, 0.03, 9),
    ],
  };
  for (const [nombre, pts] of Object.entries(casos)) {
    const r = comparar(pts);
    assert.ok(r.cluster > r.corte,
      `"${nombre}": el clustering ganó (${km(r.cluster)} vs ${km(r.corte)}). ` +
      'Reabrir V-27 con este dato.');
  }
});

/* ============================== invariantes del corte actual, que sí está en producción == */

test('ningún bloque supera el límite de la API', () => {
  for (const n of [25, 40, 60, 97, 240]) {
    for (const b of RP.chunkPoints(preordenar(nube(ORIGEN, n, 0.25, n)), TAM)) {
      assert.ok(b.length <= TAM + 1,
        `bloque de ${b.length} puntos con n=${n}: la API acepta ${TAM + 1} como máximo`);
    }
  }
});

test('no se pierde ni se duplica ninguna parada al partir', () => {
  const ordenados = preordenar(nube(ORIGEN, 97, 0.3, 7));
  const planos = RP.chunkPoints(ordenados, TAM).flat();
  assert.strictEqual(planos.length, ordenados.length, 'cambió la cantidad de paradas');
  assert.strictEqual(new Set(planos.map((p) => p.idLocal)).size, ordenados.length,
    'hay paradas repetidas entre bloques');
});

test('las rutas cortas no se parten: el 94% de las jornadas', () => {
  for (const n of [1, 5, 13, TAM + 1]) {
    assert.strictEqual(RP.chunkPoints(preordenar(nube(ORIGEN, n, 0.1, n)), TAM).length, 1,
      `n=${n} debería dar un solo bloque`);
  }
});

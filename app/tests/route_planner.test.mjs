/**
 * Pruebas de app/assets/js/route_planner.js — geometría pura del planificador.
 *
 * Ejecutar:  node --test app/tests/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadGlobal, DateAHora, pt, largoRuta, ids } from './_load.mjs';

const RP = loadGlobal('../assets/js/route_planner.js').RoutePlanner;

// Coordenadas reales de Santiago para que las distancias sean del orden que se ve en terreno.
const ORIGEN = { lat: -33.4489, lng: -70.6693 };

test('haversine mide en metros y es simétrico', () => {
  const a = { lat: -33.4489, lng: -70.6693 };
  const b = { lat: -33.4589, lng: -70.6693 };   // ~1,1 km al sur
  const d = RP.haversine(a, b);
  assert.ok(d > 1000 && d < 1200, `esperaba ~1,1 km, obtuve ${Math.round(d)} m`);
  assert.equal(Math.round(d), Math.round(RP.haversine(b, a)));
});

test('twoOpt deshace un cruce y NO cierra el ciclo', () => {
  // Cuatro puntos en línea, entregados con los dos del medio invertidos: hay un cruce.
  const enOrden = [
    pt(1, -33.44, -70.66),
    pt(2, -33.45, -70.66),
    pt(3, -33.46, -70.66),
    pt(4, -33.47, -70.66),
  ];
  const cruzado = [enOrden[0], enOrden[2], enOrden[1], enOrden[3]];

  const arreglado = RP.twoOpt(ORIGEN, cruzado);

  assert.ok(
    largoRuta(RP, ORIGEN, arreglado) <= largoRuta(RP, ORIGEN, cruzado),
    'twoOpt no debería empeorar la ruta'
  );
  assert.deepEqual(ids(arreglado), [1, 2, 3, 4]);

  // La ruta es ABIERTA: el ejecutor no vuelve al origen. Un 2-opt cíclico (el que había
  // antes) optimizaría también la arista de cierre y devolvería otro orden.
  assert.equal(arreglado.length, cruzado.length, 'no se agregan ni pierden paradas');
});

test('twoOpt no toca rutas de menos de 3 paradas', () => {
  const dos = [pt(1, -33.44, -70.66), pt(2, -33.45, -70.66)];
  assert.deepEqual(ids(RP.twoOpt(ORIGEN, dos)), [1, 2]);
  assert.equal(RP.twoOpt(ORIGEN, []).length, 0);
});

test('nearestNeighbor arranca por la parada más cercana al ejecutor', () => {
  const puntos = [
    pt(1, -33.50, -70.66),   // lejos
    pt(2, -33.4495, -70.6695), // pegado al origen
    pt(3, -33.47, -70.66),
  ];
  const orden = RP.nearestNeighbor(ORIGEN, puntos);
  assert.equal(orden[0].idLocal, 2);
  assert.equal(orden.length, 3);
});

test('chunkPoints respeta SAFE_CHUNK_WAYPOINTS y no pierde paradas', () => {
  const puntos = Array.from({ length: 60 }, (_, i) => pt(i, -33.4 - i * 0.001, -70.6));
  const bloques = RP.chunkPoints(puntos, RP.SAFE_CHUNK_WAYPOINTS);

  for (const b of bloques) {
    assert.ok(b.length <= RP.SAFE_CHUNK_WAYPOINTS + 1,
      `un bloque trae ${b.length} puntos, el tope de la API es ${RP.SAFE_CHUNK_WAYPOINTS + 1}`);
  }
  assert.equal(bloques.flat().length, 60, 'la partición perdió paradas');
  assert.deepEqual(ids(bloques.flat()), ids(puntos));
});

test('buildGoogleMapsExportChunks encadena bloques sin perder paradas', () => {
  // seq[0] es el GPS del ejecutor; las 20 siguientes son locales.
  const seq = [ORIGEN, ...Array.from({ length: 20 }, (_, i) => pt(i, -33.44 - i * 0.002, -70.66))];
  const links = RP.buildGoogleMapsExportChunks(seq, 'mobile');

  assert.ok(links.length > 1, 'con 21 puntos debería partirse en más de un enlace');

  for (const l of links) {
    assert.ok(l.waypoints.length <= RP.EXPORT_MAX_WAYPOINTS_MOBILE,
      `un enlace lleva ${l.waypoints.length} waypoints, el tope de Maps URLs es ${RP.EXPORT_MAX_WAYPOINTS_MOBILE}`);
    assert.ok(l.url.startsWith('https://www.google.com/maps/dir/?api=1'));
  }

  // El destino de cada bloque tiene que ser el origen del siguiente: si no, se pierde el tramo.
  for (let i = 0; i < links.length - 1; i++) {
    assert.deepEqual(links[i].destination, links[i + 1].origin,
      `el bloque ${i + 1} no engancha con el ${i + 2}`);
  }

  // Recomponer la secuencia completa y comprobar que están las 21 paradas.
  const recompuesta = [links[0].origin];
  for (const l of links) recompuesta.push(...l.waypoints, l.destination);
  assert.equal(recompuesta.length, seq.length, 'el export perdió o duplicó paradas');
});

test('buildGoogleMapsExportChunks descarta coordenadas inválidas', () => {
  const seq = [ORIGEN, pt(1, NaN, -70.6), pt(2, -33.45, -70.66)];
  const links = RP.buildGoogleMapsExportChunks(seq, 'mobile');
  assert.equal(links.length, 1);
  assert.equal(links[0].waypoints.length, 0);
  assert.equal(links[0].destination.idLocal, 2);
});

test('routeStateHash cambia al moverse 300 m y no cambia al moverse 10 m', () => {
  const base = { ids: [1, 2, 3], mode: 'preview', optimize: true, traffic: false, fecha: '2026-09-03', modoLocal: 'prog' };

  const quieto  = RP.routeStateHash({ ...base, origin: ORIGEN });
  const casi    = RP.routeStateHash({ ...base, origin: { lat: ORIGEN.lat + 0.00008, lng: ORIGEN.lng } }); // ~9 m
  const movido  = RP.routeStateHash({ ...base, origin: { lat: ORIGEN.lat + 0.0027,  lng: ORIGEN.lng } }); // ~300 m

  assert.equal(quieto, casi,   'un movimiento de 10 m no debería forzar recálculo');
  assert.notEqual(quieto, movido, 'un movimiento de 300 m sí debería forzar recálculo');
});

test('routeStateHash cambia si cambian las paradas o el modo', () => {
  const base = { origin: ORIGEN, mode: 'preview', optimize: true, traffic: false, fecha: '2026-09-03', modoLocal: 'prog' };
  const a = RP.routeStateHash({ ...base, ids: [1, 2, 3] });
  assert.notEqual(a, RP.routeStateHash({ ...base, ids: [1, 2] }));
  assert.notEqual(a, RP.routeStateHash({ ...base, ids: [1, 2, 3], traffic: true }));
  assert.notEqual(a, RP.routeStateHash({ ...base, ids: [1, 2, 3], modoLocal: 'reag' }));
  // El orden de los ids no importa: es el mismo conjunto de paradas.
  assert.equal(a, RP.routeStateHash({ ...base, ids: [3, 1, 2] }));
});

// ---------------------------------------------------------------------------
// Regla de negocio: botillerías (hallazgo V-13)
// ---------------------------------------------------------------------------

/** Carga el planner con la hora congelada y un RouteEngine de mentira. */
function plannerAHora(hora) {
  const win = loadGlobal('../assets/js/route_planner.js', { Date: DateAHora(hora) });
  win.RouteEngine = {
    async computeRouteUnified() {
      return { distanceMeters: 1000, duration: '600s', legs: [], optimizedIntermediateWaypointIndex: [] };
    }
  };
  // El IIFE ya corrió y capturó `window`; se le cuelga el stub al mismo objeto.
  win.window.RouteEngine = win.RouteEngine;
  return win;
}

test('antes de las 13:00 las botillerías van al final Y ordenadas entre sí', async () => {
  const win = plannerAHora(9);

  // Tres botillerías entregadas en el peor orden posible: la más lejana primero.
  const puntos = [
    pt(10, -33.4500, -70.6700),
    pt(11, -33.4510, -70.6700),
    pt(90, -33.4900, -70.6700, { isBotilleria: true }), // la más lejana
    pt(91, -33.4600, -70.6700, { isBotilleria: true }), // la más cercana al tramo normal
    pt(92, -33.4750, -70.6700, { isBotilleria: true }),
  ];

  const plan = await win.window.RoutePlanner.planFull({
    origin: ORIGEN, points: puntos, optimize: false, mode: 'preview'
  });
  const orden = ids(plan.orderedPts);

  // 1. Las botillerías siguen yendo al final (la regla de negocio no cambió).
  assert.deepEqual(orden.slice(0, 2).sort(), [10, 11], 'los locales normales van primero');
  assert.deepEqual(orden.slice(2).slice().sort(), [90, 91, 92], 'las botillerías van al final');

  // 2. Y ahora además están ordenadas: la primera del tramo es la más cercana al punto
  //    donde terminó el tramo normal, no la que venía primera en la consulta SQL.
  //    Con el código anterior este assert fallaba: el orden salía 90, 91, 92.
  assert.deepEqual(orden.slice(2), [91, 92, 90], `el tramo de botillerías salió sin ordenar: ${orden.slice(2)}`);
});

test('después de las 13:00 las botillerías se mezclan con el resto', async () => {
  const win = plannerAHora(15);

  const puntos = [
    pt(10, -33.4900, -70.6700),                          // lejos
    pt(91, -33.4500, -70.6700, { isBotilleria: true }),   // cerca del origen
  ];

  const plan = await win.window.RoutePlanner.planFull({
    origin: ORIGEN, points: puntos, optimize: false, mode: 'preview'
  });

  // Pasada la hora de apertura no hay restricción: manda la geografía.
  assert.equal(plan.orderedPts[0].idLocal, 91, 'a las 15:00 la botillería cercana debería ir primero');
});

test('una jornada de sólo botillerías también se ordena', async () => {
  const win = plannerAHora(9);
  const puntos = [
    pt(90, -33.4900, -70.6700, { isBotilleria: true }),
    pt(91, -33.4500, -70.6700, { isBotilleria: true }),
    pt(92, -33.4700, -70.6700, { isBotilleria: true }),
  ];

  const plan = await win.window.RoutePlanner.planFull({
    origin: ORIGEN, points: puntos, optimize: false, mode: 'preview'
  });

  // Sin tramo normal previo, el origen del tramo es la posición del ejecutor.
  assert.deepEqual(ids(plan.orderedPts), [91, 92, 90]);
});

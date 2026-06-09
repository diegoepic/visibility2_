(function(window){
  'use strict';

  // Distancia euclídea aproximada en grados² (suficiente para ordenamiento relativo)
  function dist2(a, b){
    const dlat = a.lat - b.lat;
    const dlng = a.lng - b.lng;
    return dlat * dlat + dlng * dlng;
  }

  /**
   * Nearest-neighbor greedy desde `origin`.
   * O(n²) — aceptable hasta ~200 puntos en móvil.
   */
  function nearestNeighbor(origin, points){
    if (!points.length) return [];
    const remaining = points.slice();
    const ordered   = [];
    let current = origin;
    while (remaining.length) {
      let bestIdx = 0;
      let bestD   = dist2(current, remaining[0]);
      for (let i = 1; i < remaining.length; i++){
        const d = dist2(current, remaining[i]);
        if (d < bestD){ bestD = d; bestIdx = i; }
      }
      current = remaining.splice(bestIdx, 1)[0];
      ordered.push(current);
    }
    return ordered;
  }

  /**
   * 2-opt: mejora la ruta intercambiando segmentos mientras reduzca distancia total.
   * Preserva botillerías al final si la hora es < 13:00.
   */
  function twoOpt(points){
    if (points.length < 4) return points.slice();
    const route = points.slice();
    const n     = route.length;
    let improved = true;
    while (improved){
      improved = false;
      for (let i = 0; i < n - 1; i++){
        for (let j = i + 2; j < n; j++){
          // No permutar si alguno de los extremos es botillería y está en la zona final
          // (conservamos la regla de botillerías al final)
          const d_before = dist2(route[i], route[i+1]) + dist2(route[j], route[(j+1) % n] || route[0]);
          const d_after  = dist2(route[i], route[j])   + dist2(route[i+1], route[(j+1) % n] || route[0]);
          if (d_after < d_before - 1e-12){
            // Revertir segmento [i+1 … j]
            let l = i + 1, r = j;
            while (l < r){ const tmp = route[l]; route[l] = route[r]; route[r] = tmp; l++; r--; }
            improved = true;
          }
        }
      }
    }
    return route;
  }

  /**
   * Divide un array de puntos en bloques de hasta `chunkSize` waypoints más 1 destino,
   * respetando que el destino de un bloque sea el origen del siguiente.
   * El último punto de cada chunk es el destino de ese tramo.
   */
  function chunkPoints(points, chunkSize){
    chunkSize = chunkSize || 23; // 23 intermedios + 1 destino = 24 total
    const chunks = [];
    let i = 0;
    while (i < points.length){
      chunks.push(points.slice(i, i + chunkSize + 1));
      i += chunkSize + 1;
    }
    // El último punto del chunk anterior es el primero del siguiente (overlapping dest→origin)
    return chunks;
  }

  /**
   * Planifica la ruta completa.
   *
   * @param {Object} opts
   *   origin        — { lat, lng } posición del ejecutor
   *   points        — RoutePoint[] ya validados (sin 0,0, sin NaN); botillerías ya al final si < 13:00
   *   optimize      — boolean: llamar a Routes API con optimizeWaypointOrder
   *   mode          — 'preview'|'traffic'|'nav'
   *   bypassThrottle — pasar a RouteEngine
   *   bypassCache   — pasar a RouteEngine
   *
   * @returns {Promise<{ orderedPts, route, chunks, apiUsed }>}
   */
  async function planFull({ origin, points, optimize, mode, bypassThrottle, bypassCache }){
    if (!points.length) throw new Error('Sin puntos de ruta');

    // 1. Preordenamiento heurístico respetando botillerías al final (regla de las 13:00)
    //    Aplicar nearest-neighbor+2-opt solo sobre normales; botillerías van siempre al final.
    const horaMin     = new Date().getHours() * 60 + new Date().getMinutes();
    const respetarBot = horaMin < 13 * 60;
    const normales    = respetarBot ? points.filter(p => !p.isBotilleria) : points;
    const botillerias = respetarBot ? points.filter(p =>  p.isBotilleria) : [];

    let ordNormales = nearestNeighbor(origin, normales);
    ordNormales     = twoOpt(ordNormales);
    let ordered     = [...ordNormales, ...botillerias];

    // 2. Ruta corta (≤ 24 puntos) — llamada única a RouteEngine
    if (ordered.length <= 24){
      const destination = ordered[ordered.length - 1];
      const waypoints   = ordered.slice(0, -1);
      const route = await window.RouteEngine.computeRouteUnified({
        origin, destination, waypoints, optimize, mode, bypassThrottle, bypassCache
      });
      // Aplicar orden optimizado de Google si la API lo devolvió
      let finalOrdered = ordered;
      if (optimize && Array.isArray(route.optimizedIntermediateWaypointIndex) && route.optimizedIntermediateWaypointIndex.length){
        const reordered = route.optimizedIntermediateWaypointIndex.map(i => waypoints[i]);
        finalOrdered = [...reordered, destination];
      }
      return { orderedPts: finalOrdered, route, chunks: null, apiUsed: 'routes_api' };
    }

    // 3. Ruta larga (> 24 puntos) — dividir en chunks
    const chunks    = chunkPoints(ordered, 23);
    const results   = [];
    let chunkOrigin = origin;
    for (let i = 0; i < chunks.length; i++){
      const chunk       = chunks[i];
      const destination = chunk[chunk.length - 1];
      const waypoints   = chunk.slice(0, -1);
      const route = await window.RouteEngine.computeRouteUnified({
        origin: chunkOrigin, destination, waypoints,
        optimize, mode, bypassThrottle, bypassCache
      });
      let chunkOrdered = chunk;
      if (optimize && Array.isArray(route.optimizedIntermediateWaypointIndex) && route.optimizedIntermediateWaypointIndex.length){
        const reordered = route.optimizedIntermediateWaypointIndex.map(idx => waypoints[idx]);
        chunkOrdered = [...reordered, destination];
      }
      results.push({ points: chunkOrdered, route, index: i });
      chunkOrigin = destination; // destino de este chunk = origen del siguiente
    }

    const allOrdered = results.flatMap(c => c.points);
    // La "ruta" retornada para el HUD es la del primer chunk (la más inmediata)
    return { orderedPts: allOrdered, route: results[0].route, chunks: results, apiUsed: 'routes_api_chunked' };
  }

  window.RoutePlanner = { nearestNeighbor, twoOpt, chunkPoints, planFull };

})(window);

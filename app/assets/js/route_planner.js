(function(window){
  'use strict';

  // Límite seguro por llamada a Routes API: 23 waypoints intermedios + 1 destino = 24 puntos.
  // El planner es el ÚNICO dueño del límite (route_selection ya no recorta). Las rutas con más
  // paradas se dividen en bloques (chunks) que se calculan encadenados.
  const SAFE_CHUNK_WAYPOINTS = 23;

  // Límites de waypoints por enlace al EXPORTAR a Google Maps (distintos del límite de la API).
  // 9 es el tope que documenta Google para las Maps URLs (/maps/dir/?api=1), así que ninguno de
  // los dos puede subir más. El móvil estuvo en 3 por precaución (la app de Maps recortaba deep
  // links largos); se igualó a 9 el 02-09-2026 para que una ruta de 20 locales salga en 2 bloques
  // y no en 5. Si vuelve a aparecer una ruta a la que la app le come paradas, bajar SÓLO este
  // valor: el resto del export se acomoda solo y el aviso de index_pruebas.php lo lee de acá.
  const EXPORT_MAX_WAYPOINTS_MOBILE  = 9; // + destino
  const EXPORT_MAX_WAYPOINTS_DESKTOP = 9; // + destino

  // Distancia en metros (haversine). Métrica real para ordenar paradas; sustituye a la antigua
  // distancia euclídea en grados², que distorsionaba el orden lejos del ecuador.
  function haversine(a, b){
    const R = 6371000;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const aa = Math.sin(dLat / 2) ** 2 +
               Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(aa));
  }

  /**
   * Nearest-neighbor greedy desde `origin`. Incluye el origen en el costo (la 1.ª parada es la
   * más cercana al ejecutor). O(n²) — aceptable hasta ~200 puntos en móvil.
   */
  function nearestNeighbor(origin, points){
    if (!points.length) return [];
    const remaining = points.slice();
    const ordered   = [];
    let current = origin;
    while (remaining.length) {
      let bestIdx = 0;
      let bestD   = haversine(current, remaining[0]);
      for (let i = 1; i < remaining.length; i++){
        const d = haversine(current, remaining[i]);
        if (d < bestD){ bestD = d; bestIdx = i; }
      }
      current = remaining.splice(bestIdx, 1)[0];
      ordered.push(current);
    }
    return ordered;
  }

  /**
   * 2-opt para ruta ABIERTA (no es un TSP cerrado: el ejecutor no regresa al origen).
   * `origin` es un nodo fijo previo al índice 0; las aristas son (k,k+1) para k=-1..n-1, SIN arista
   * de cierre (n-1 → 0). Al invertir el segmento [i..j] cambian las aristas (i-1,i) y (j,j+1); si
   * j es la última parada, no existe (j,j+1) y ese término se omite. Esto corrige el 2-opt cíclico
   * anterior (`% n` / `|| route[0]`), que asumía un ciclo y empeoraba rutas abiertas.
   */
  function twoOpt(origin, points){
    if (!points || points.length < 3) return (points || []).slice();
    const route = points.slice();
    const n = route.length;
    const prevNode = i => (i === 0 ? origin : route[i - 1]);
    let improved = true;
    while (improved){
      improved = false;
      for (let i = 0; i < n - 1; i++){
        for (let j = i + 1; j < n; j++){
          const a = prevNode(i);
          const hasNext = (j + 1) < n;
          const before = haversine(a, route[i]) + (hasNext ? haversine(route[j], route[j + 1]) : 0);
          const after  = haversine(a, route[j]) + (hasNext ? haversine(route[i], route[j + 1]) : 0);
          if (after < before - 1e-6){
            let l = i, r = j;
            while (l < r){ const tmp = route[l]; route[l] = route[r]; route[r] = tmp; l++; r--; }
            improved = true;
          }
        }
      }
    }
    return route;
  }

  /**
   * Divide un array de puntos en bloques de hasta `chunkSize` waypoints + 1 destino (= chunkSize+1
   * puntos por bloque). Cada bloque se calcula con su propio origen encadenado en planFull.
   *
   * POR QUE SIGUE SIENDO UN CORTE SECUENCIAL Y NO UN CLUSTERING (hallazgo V-27)
   * --------------------------------------------------------------------------
   * El plan de la Tanda 6 pedia reemplazar esto por k-means sobre las coordenadas, con el
   * argumento de que "la particion en bloques destruye la optimalidad global". Se implemento
   * y se midio, y resulto ser al reves. Los numeros estan en app/tests/route_clusters.test.mjs:
   *
   *     trazado                  corte actual   k-means
   *     nube uniforme, 60           239,0 km    280,8 km   +17,5%
   *     3 comunas x 20               68,3 km     76,7 km   +12,4%
   *     2 comunas lejanas x 30       59,6 km     72,2 km   +21,2%
   *     4 comunas x 13               97,0 km    142,7 km   +47,2%
   *
   * El motivo: lo que se corta aqui NO es una lista cualquiera, es la salida de
   * nearestNeighbor + twoOpt, o sea un recorrido ya bueno. Partirlo en tramos CONTIGUOS no
   * cuesta un solo metro —el encadenado reproduce exactamente el mismo camino, y hay una
   * prueba que lo verifica—, mientras que clusterizar REORDENA los puntos y ahi si se pierde.
   *
   * Lo unico que se gana clusterizando es que Google reordene dentro de bloques mas compactos,
   * y eso no compensa romper el orden global.
   *
   * Contexto, sobre 25.925 rutas (usuario x dia) del volcado del 2026-09-03: la mediana es de
   * 13 locales y el p90 de 23, asi que el 94% de las jornadas ni siquiera llega a partirse.
   */
  function chunkPoints(points, chunkSize){
    chunkSize = chunkSize || SAFE_CHUNK_WAYPOINTS;
    const chunks = [];
    let i = 0;
    while (i < points.length){
      chunks.push(points.slice(i, i + chunkSize + 1));
      i += chunkSize + 1;
    }
    return chunks;
  }

  /**
   * Distancia total de recorrer una lista de bloques encadenados desde `origin`.
   * Existe para poder COMPARAR estrategias de particion en las pruebas, que es lo que
   * decidio activar clusterPoints en vez de darlo por bueno.
   */
  function distanciaDeBloques(origin, bloques){
    let total = 0;
    let desde = origin;
    for (const b of (bloques || [])){
      for (const p of b){ total += haversine(desde, p); desde = p; }
    }
    return total;
  }

  // Segundos a partir de la duración interna ("123s" | número).
  function durationToSeconds(d){
    if (typeof d === 'number') return d;
    if (typeof d === 'string' && d.endsWith('s')) return parseFloat(d) || 0;
    return 0;
  }

  /**
   * Construye los enlaces de exportación a Google Maps respetando el límite de waypoints por URL.
   * FUNCIÓN PURA (testeable). `points` es la secuencia ordenada COMPLETA, con points[0] como origen
   * de partida (GPS del ejecutor inyectado por el llamador). Encadena bloques: el destino de un
   * bloque es el origen del siguiente, de modo que no se pierde ninguna parada.
   *
   * @param {{lat:number,lng:number}[]} points  secuencia ordenada (incluye el origen en [0])
   * @param {'mobile'|'desktop'} environment
   * @returns {{origin,waypoints,destination,url}[]}
   */
  function buildGoogleMapsExportChunks(points, environment){
    const seq = (points || []).filter(p => p && Number.isFinite(p.lat) && Number.isFinite(p.lng));
    if (seq.length < 2) return [];
    const maxWp = environment === 'mobile' ? EXPORT_MAX_WAYPOINTS_MOBILE : EXPORT_MAX_WAYPOINTS_DESKTOP;

    const fmt = p => `${p.lat},${p.lng}`;
    const urlFor = (origin, waypoints, destination) => {
      const ways = waypoints.map(fmt).join('|');
      return 'https://www.google.com/maps/dir/?api=1' +
             `&origin=${fmt(origin)}` +
             `&destination=${fmt(destination)}` +
             '&travelmode=driving' +
             (ways ? `&waypoints=${encodeURIComponent(ways)}` : '');
    };

    const links = [];
    let i = 0; // índice del origen del bloque actual dentro de seq
    while (i < seq.length - 1){
      const origin    = seq[i];
      const endIdx    = Math.min(i + maxWp + 1, seq.length - 1); // destino del bloque
      const waypoints = seq.slice(i + 1, endIdx);                // ≤ maxWp intermedios
      const destination = seq[endIdx];
      links.push({ origin, waypoints, destination, url: urlFor(origin, waypoints, destination) });
      i = endIdx; // encadenar: el destino pasa a ser el origen del siguiente bloque
    }
    return links;
  }

  // Cuantiza una coordenada a una grilla ~50 m (0.0005° ≈ 55 m) para el hash de estado de ruta.
  function bucketCoord(v){ return Math.round((v || 0) / 0.0005); }

  /**
   * Hash de estado de ruta — FUNCIÓN PURA (testeable). Cambia cuando cambia cualquier entrada que
   * afecte el cálculo: paradas, origen (en bucket de ~50 m), modo, optimización, tráfico, fecha y
   * modo local. Reemplaza al antiguo selectionHash (que solo miraba ids y nunca el origen GPS).
   */
  function routeStateHash(parts){
    const p = parts || {};
    const ids = (p.ids || []).slice().sort((a, b) => a - b).join(',');
    const ori = p.origin ? `${bucketCoord(p.origin.lat)}:${bucketCoord(p.origin.lng)}` : '0:0';
    return [
      ids,
      ori,
      p.mode || 'preview',
      p.optimize ? '1' : '0',
      p.traffic ? '1' : '0',
      p.fecha || '',
      p.modoLocal || ''
    ].join('::');
  }

  /**
   * Planifica la ruta completa.
   *
   * @param {Object} opts
   *   origin         — { lat, lng } posición del ejecutor
   *   points         — RoutePoint[] ya validados (sin 0,0, sin NaN)
   *   optimize       — boolean: pedir a Routes API optimizeWaypointOrder
   *   mode           — 'preview'|'traffic'|'nav'
   *   bypassThrottle — pasar a RouteEngine
   *   bypassCache    — pasar a RouteEngine
   *
   * @returns {Promise<{ orderedPts, route, chunks, totals, apiUsed }>}
   */
  async function planFull({ origin, points, optimize, mode, bypassThrottle, bypassCache }){
    if (!points.length) throw new Error('Sin puntos de ruta');

    // 1. Preordenamiento heurístico. Regla de negocio: las botillerías abren a las 13:00, así que
    //    antes de esa hora se fuerzan al final. (Extensión futura: ventanas horarias / prioridades
    //    se modelarían como penalizaciones de costo o reordenamientos post-NN; la botillería es el
    //    primer ejemplo de esa familia de restricciones.)
    const horaMin     = new Date().getHours() * 60 + new Date().getMinutes();
    const respetarBot = horaMin < 13 * 60;
    const normales    = respetarBot ? points.filter(p => !p.isBotilleria) : points;
    const botillerias = respetarBot ? points.filter(p =>  p.isBotilleria) : [];

    let ordNormales = nearestNeighbor(origin, normales);
    ordNormales     = twoOpt(origin, ordNormales);

    // Las botillerías van al final por su horario de apertura, pero ENTRE ELLAS también se
    // ordenan. Antes se concatenaban tal como venían de la consulta, así que en una jornada con
    // varias botillerías todo ese tramo final quedaba sin optimizar. El origen del tramo es la
    // última parada normal (o la posición del ejecutor si toda la ruta son botillerías).
    let ordBotillerias = [];
    if (botillerias.length) {
      const origenTramo = ordNormales.length ? ordNormales[ordNormales.length - 1] : origin;
      ordBotillerias = twoOpt(origenTramo, nearestNeighbor(origenTramo, botillerias));
    }
    const ordered = [...ordNormales, ...ordBotillerias];

    // 2. Ruta corta (≤ 24 puntos) — una sola llamada a RouteEngine.
    if (ordered.length <= SAFE_CHUNK_WAYPOINTS + 1){
      const destination = ordered[ordered.length - 1];
      const waypoints   = ordered.slice(0, -1);
      const route = await window.RouteEngine.computeRouteUnified({
        origin, destination, waypoints, optimize, mode, bypassThrottle, bypassCache
      });
      let finalOrdered = ordered;
      if (optimize && Array.isArray(route.optimizedIntermediateWaypointIndex) && route.optimizedIntermediateWaypointIndex.length){
        const reordered = route.optimizedIntermediateWaypointIndex.map(i => waypoints[i]);
        finalOrdered = [...reordered, destination];
      }
      const totals = {
        distanceMeters: route.distanceMeters || 0,
        durationSeconds: durationToSeconds(route.duration)
      };
      return { orderedPts: finalOrdered, route, chunks: null, totals, apiUsed: 'routes_api' };
    }

    // 3. Ruta larga (> 24 puntos) — dividir en bloques encadenados.
    const chunks    = chunkPoints(ordered, SAFE_CHUNK_WAYPOINTS);
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
    // Totales = suma agregada de distancia/duración de TODOS los bloques (no solo el primero).
    const totals = results.reduce((acc, c) => {
      acc.distanceMeters  += (c.route.distanceMeters || 0);
      acc.durationSeconds += durationToSeconds(c.route.duration);
      return acc;
    }, { distanceMeters: 0, durationSeconds: 0 });

    // La "ruta" para el HUD es la del primer bloque (la más inmediata).
    return { orderedPts: allOrdered, route: results[0].route, chunks: results, totals, apiUsed: 'routes_api_chunked' };
  }

  window.RoutePlanner = {
    nearestNeighbor,
    twoOpt,
    chunkPoints,
    distanciaDeBloques,
    planFull,
    haversine,
    buildGoogleMapsExportChunks,
    routeStateHash,
    SAFE_CHUNK_WAYPOINTS,
    EXPORT_MAX_WAYPOINTS_MOBILE,
    EXPORT_MAX_WAYPOINTS_DESKTOP
  };

})(window);

(function(window){
  'use strict';

  // Seguridad: Routes API se llama a través de un proxy backend (app/api/route_compute.php) cuando
  // window.__ROUTES_PROXY_URL está definido, para que la API key viva en el servidor. Si esa variable
  // está vacía, se llama a Google directamente (rollback). El fallback DirectionsService (Maps JS)
  // actúa si el proxy o la red fallan. La key del frontend (Maps JS) sigue siendo pública por diseño;
  // restringirla por referrer/dominio.

  const ROUTE_CACHE_TTL_MS = 5 * 60 * 1000; // 5 min
  const MIN_ROUTE_RECALC_MS = 30 * 1000;
  const GOOGLE_ROUTES_ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';
  const DB_NAME = 'v2_route_cache';
  const STORE_NAME = 'routes';
  const DB_VERSION = 1;

  const memoryCache = new Map();
  let lastRequestKey = null;
  let lastRequestTime = 0;
  let dbPromise = null;
  // AbortController de la navegación activa: al iniciar un reroute (mode 'nav') se cancela el
  // request anterior para no pintar respuestas viejas. Los chunks de 'preview'/'traffic' son
  // secuenciales y NO se abortan entre sí.
  let navController = null;

  const stats = {
    route_requests_total: 0,
    routes_api_requests: 0,
    directions_fallback_requests: 0,
    cache_hits_memory: 0,
    cache_hits_idb: 0,
    reroutes_triggered: 0
  };

  function notifyStats(){
    window.dispatchEvent(new CustomEvent('route-engine-stats', { detail: { ...stats } }));
  }

  function quantizeCoord(value){
    return Math.round(value * 1e5) / 1e5;
  }

  function quantizePoint(pt){
    if(!pt) return null;
    return { lat: quantizeCoord(pt.lat), lng: quantizeCoord(pt.lng) };
  }

  function coordsHash(pt){
    return pt ? `${pt.lat.toFixed(5)},${pt.lng.toFixed(5)}` : '';
  }

  function buildCacheKey({ origin, destination, waypoints, optimize, mode }){
    const wpHashes = (waypoints || []).map(coordsHash);
    // Cuando optimize=true el orden enviado no importa — mismos puntos = misma key
    const wps = optimize ? [...wpHashes].sort().join('|') : wpHashes.join('|');
    return [coordsHash(origin), coordsHash(destination), wps, optimize ? '1':'0', mode || 'preview'].join('::');
  }

  function openDb(){
    if(dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject)=>{
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = ()=>{
        const db = req.result;
        if(!db.objectStoreNames.contains(STORE_NAME)){
          db.createObjectStore(STORE_NAME);
        }
      };
      req.onsuccess = ()=>resolve(req.result);
      req.onerror = ()=>reject(req.error || new Error('IDB error'));
    });
    return dbPromise;
  }

  async function idbGet(key){
    try{
      const db = await openDb();
      return await new Promise((resolve, reject)=>{
        const tx = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).get(key);
        req.onsuccess = ()=>resolve(req.result || null);
        req.onerror = ()=>reject(req.error || new Error('IDB get error'));
      });
    }catch(err){ return null; }
  }

  async function idbSet(key, value){
    try{
      const db = await openDb();
      await new Promise((resolve, reject)=>{
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).put(value, key);
        tx.oncomplete = ()=>resolve();
        tx.onerror = ()=>reject(tx.error || new Error('IDB set error'));
      });
    }catch(err){ /* silent */ }
  }

  // Fix #17: eliminar entradas expiradas del IDB
  async function idbDelete(key){
    try{
      const db = await openDb();
      await new Promise((resolve, reject)=>{
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(key);
        tx.oncomplete = ()=>resolve();
        tx.onerror = ()=>reject(tx.error);
      });
    }catch(err){}
  }

  async function idbCleanExpired(){
    try{
      const db = await openDb();
      const now = Date.now();
      const keys = await new Promise((resolve, reject)=>{
        const tx = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).getAllKeys();
        req.onsuccess = ()=>resolve(req.result || []);
        req.onerror = ()=>reject(req.error);
      });
      for(const key of keys){
        const item = await idbGet(key);
        if(!item || (item.expires && item.expires < now)){
          await idbDelete(key);
        }
      }
    }catch(e){}
  }

  // Limpiar entradas expiradas una vez por sesión al arrancar
  setTimeout(idbCleanExpired, 5000);

  // Convierte una location de Routes API ({latLng:{latitude,longitude}}) o ya-normalizada
  // ({lat,lng}) a {lat,lng}. Devuelve {lat:0,lng:0} si no hay datos.
  function llToObj(loc){
    if(!loc) return { lat:0, lng:0 };
    if(loc.latLng) return { lat: loc.latLng.latitude, lng: loc.latLng.longitude };
    if(typeof loc.lat === 'number') return { lat: loc.lat, lng: loc.lng };
    return { lat:0, lng:0 };
  }

  // Quita HTML/entidades de las instrucciones (el fallback DirectionsService las entrega en HTML)
  // para poder renderizarlas como texto plano sin inyectar markup.
  function sanitizeInstruction(html){
    return String(html == null ? '' : html)
      .replace(/<[^>]*>/g, ' ')
      .replace(/&nbsp;/gi, ' ')
      .replace(/&amp;/gi, '&')
      .replace(/&lt;/gi, '<')
      .replace(/&gt;/gi, '>')
      .replace(/&#39;|&apos;/gi, "'")
      .replace(/&quot;/gi, '"')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function durationToSeconds(d){
    if(typeof d === 'number') return d;
    if(typeof d === 'string' && d.endsWith('s')) return parseFloat(d) || 0;
    return 0;
  }

  // Forma interna ÚNICA que consumen NavEngine / index_pruebas / buildTrafficPolylines:
  //   { distanceMeters, durationSeconds, duration, polyline:{encodedPolyline}, travelAdvisory,
  //     legs:[{ ..., steps:[{ navigationInstruction:{instructions,maneuver}, distanceMeters,
  //             staticDuration, startLocation:{lat,lng}, endLocation:{lat,lng},
  //             polyline:{encodedPolyline} }] }], optimizedIntermediateWaypointIndex }
  // Tanto la Routes API como el fallback DirectionsService deben producir EXACTAMENTE esta forma.
  function normalizeRoutesApiResponse(json){
    if(!json || !json.routes || !json.routes.length) throw new Error('Respuesta vacía');
    const r = json.routes[0];
    const legs = (r.legs || []).map(l => ({
      distanceMeters: l.distanceMeters || 0,
      duration: l.duration || '0s',
      durationSeconds: durationToSeconds(l.duration),
      startLocation: llToObj(l.startLocation),
      endLocation: llToObj(l.endLocation),
      steps: (l.steps || []).map(st => ({
        distanceMeters: st.distanceMeters || 0,
        staticDuration: st.staticDuration || '0s',
        navigationInstruction: {
          instructions: sanitizeInstruction((st.navigationInstruction || {}).instructions || ''),
          maneuver: (st.navigationInstruction || {}).maneuver || ''
        },
        startLocation: llToObj(st.startLocation),
        endLocation: llToObj(st.endLocation),
        polyline: (st.polyline && st.polyline.encodedPolyline) ? { encodedPolyline: st.polyline.encodedPolyline } : null
      }))
    }));
    return {
      distanceMeters: r.distanceMeters || 0,
      duration: r.duration || '0s',
      durationSeconds: durationToSeconds(r.duration),
      polyline: { encodedPolyline: (r.polyline && r.polyline.encodedPolyline) || '' },
      travelAdvisory: r.travelAdvisory || {},
      legs,
      optimizedIntermediateWaypointIndex: Array.isArray(r.optimizedIntermediateWaypointIndex) ? r.optimizedIntermediateWaypointIndex : []
    };
  }

  // Fix #6: null checks en el fallback
  function normalizeDirectionsFallback(res){
    if(!res || !res.routes || !res.routes.length || !res.routes[0] || !res.routes[0].legs){
      throw new Error('Respuesta vacía del fallback');
    }
    const r0 = res.routes[0];
    const legs = r0.legs.map(l=>({
      distanceMeters: l.distance.value,
      duration: l.duration.value + 's',
      durationSeconds: l.duration.value,
      startLocation: { lat: l.start_location.lat(), lng: l.start_location.lng() },
      endLocation: { lat: l.end_location.lat(), lng: l.end_location.lng() },
      steps: (l.steps||[]).map(st=>({
        distanceMeters: st.distance.value,
        staticDuration: st.duration.value + 's',
        navigationInstruction: { instructions: sanitizeInstruction(st.instructions || ''), maneuver: st.maneuver || '' },
        startLocation: { lat: st.start_location.lat(), lng: st.start_location.lng() },
        endLocation: { lat: st.end_location.lat(), lng: st.end_location.lng() },
        polyline: st.path ? { encodedPolyline: google.maps.geometry.encoding.encodePath(st.path) } : null
      }))
    }));
    const totalMeters = r0.legs.reduce((a,l)=>a+l.distance.value,0);
    const totalSecs   = r0.legs.reduce((a,l)=>a+l.duration.value,0);
    return {
      distanceMeters: totalMeters,
      duration: totalSecs + 's',
      durationSeconds: totalSecs,
      polyline:{ encodedPolyline: google.maps.geometry.encoding.encodePath(r0.overview_path) },
      travelAdvisory:{},
      legs,
      optimizedIntermediateWaypointIndex: Array.isArray(r0.waypoint_order) ? r0.waypoint_order : []
    };
  }

  // Fix #4: eliminar campos duplicados en modo traffic
  function fieldMaskForMode(mode, optimize){
    const base = ['routes.distanceMeters','routes.duration'];
    if(mode === 'nav'){
      base.push(
        'routes.polyline.encodedPolyline',
        'routes.legs.steps.navigationInstruction',
        'routes.legs.steps.distanceMeters',
        'routes.legs.steps.staticDuration',
        'routes.legs.steps.startLocation',
        'routes.legs.steps.endLocation',
        'routes.legs.steps.polyline.encodedPolyline'
      );
    } else if(mode === 'traffic'){
      base.push('routes.polyline.encodedPolyline', 'routes.travelAdvisory.speedReadingIntervals');
    } else {
      base.push('routes.polyline.encodedPolyline');
    }
    if(optimize){
      base.push('routes.optimizedIntermediateWaypointIndex');
    }
    return base;
  }

  async function fetchRoutesApi(payload, mode, signal){
    const fieldMask = fieldMaskForMode(mode, payload.optimizeWaypointOrder).join(',');
    const proxyUrl = window.__ROUTES_PROXY_URL || '';
    let res;
    if (proxyUrl){
      // Proxy backend: la API key vive en el servidor (no se envía X-Goog-Api-Key desde el cliente).
      res = await fetch(proxyUrl, {
        method:'POST',
        headers:{ 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
        body: JSON.stringify({ payload, fieldMask, mode }),
        credentials:'same-origin',
        signal
      });
    } else {
      // Llamada directa a Google (modo legado / rollback): la key va en el header.
      res = await fetch(GOOGLE_ROUTES_ENDPOINT, {
        method:'POST',
        headers:{ 'Content-Type':'application/json', 'X-Goog-Api-Key': window.__GOOGLE_MAPS_API_KEY || '', 'X-Goog-FieldMask': fieldMask },
        body: JSON.stringify(payload),
        signal
      });
    }
    if(!res.ok){
      // Leer el cuerpo de error (Google { error:{code,message,status} } o el proxy { message }) para
      // diagnóstico, sin propagar el detalle al usuario final (la orquestación muestra genérico).
      let detail = '';
      try{
        const ej = await res.json();
        if(ej && ej.error) detail = `${ej.error.status||''} ${ej.error.message||''}`.trim();
        else if(ej && ej.message) detail = ej.message;
      }catch(_){ }
      console.warn('[RouteEngine] Routes API HTTP', res.status, detail || '(sin detalle)');
      const e = new Error('Routes API error'); e.httpStatus = res.status; e.googleDetail = detail; throw e;
    }
    const json = await res.json();
    stats.routes_api_requests++; notifyStats();
    return normalizeRoutesApiResponse(json);
  }

  async function fetchDirectionsFallback(req){
    return new Promise((resolve, reject)=>{
      try{
        const svc = new google.maps.DirectionsService();
        svc.route(req, (res, st)=>{
          if(st === google.maps.DirectionsStatus.OK){
            stats.directions_fallback_requests++; notifyStats();
            resolve(normalizeDirectionsFallback(res));
          } else reject(new Error('Directions fallback error'));
        });
      }catch(err){ reject(err); }
    });
  }

  // Construye el payload de Routes API según el MODO. FUNCIÓN PURA (testeable).
  //   preview → TRAFFIC_UNAWARE (permite optimizar el orden).
  //   traffic → TRAFFIC_AWARE + departureTime (permite optimizar el orden).
  //   nav     → TRAFFIC_AWARE_OPTIMAL + trafficModel + departureTime, con optimizeWaypointOrder=false.
  // Google PROHÍBE combinar optimizeWaypointOrder con TRAFFIC_AWARE_OPTIMAL; por eso "optimizar el
  // orden de paradas" (planificación: preview/traffic) y "navegar el tramo con tráfico óptimo" (nav,
  // que respeta el orden ya planificado) son modos separados a propósito.
  function buildRoutesPayload({origin, destination, waypoints, optimize, mode}){
    const cfg = (window.RoutePreferences && window.RoutePreferences.getRouteConfig)
      ? (window.RoutePreferences.getRouteConfig() || {}) : {};
    const intermediate = (waypoints||[]).map(p=>({ location:{ latLng:{ latitude:p.lat, longitude:p.lng } } }));
    const isNav    = mode === 'nav';
    const travelMode = cfg.travelMode || 'DRIVE';
    // routingPreference/departureTime/trafficModel solo aplican a vehículos motorizados.
    const motorized = (travelMode === 'DRIVE' || travelMode === 'TWO_WHEELER');

    const payload = {
      origin:{ location:{ latLng:{ latitude:origin.lat, longitude:origin.lng } } },
      destination:{ location:{ latLng:{ latitude:destination.lat, longitude:destination.lng } } },
      travelMode,
      languageCode: cfg.languageCode || 'es-CL',
      units: 'METRIC',
      polylineEncoding: 'ENCODED_POLYLINE',
      polylineQuality: isNav ? 'HIGH_QUALITY' : 'OVERVIEW',
      optimizeWaypointOrder: isNav ? false : !!optimize
    };

    if(motorized){
      const departureNow = new Date(Date.now() + 1000).toISOString();
      if(isNav){
        payload.routingPreference = 'TRAFFIC_AWARE_OPTIMAL';
        payload.departureTime = departureNow;
        if(cfg.trafficModel) payload.trafficModel = cfg.trafficModel;
      } else if(mode === 'traffic'){
        payload.routingPreference = 'TRAFFIC_AWARE';
        payload.departureTime = (cfg.departureTime && cfg.departureTime !== 'now') ? cfg.departureTime : departureNow;
        if(cfg.trafficModel) payload.trafficModel = cfg.trafficModel;
      } else {
        payload.routingPreference = 'TRAFFIC_UNAWARE';
      }
    }

    if(cfg.avoidTolls || cfg.avoidHighways || cfg.avoidFerries){
      payload.routeModifiers = {
        avoidTolls: !!cfg.avoidTolls,
        avoidHighways: !!cfg.avoidHighways,
        avoidFerries: !!cfg.avoidFerries
      };
    }
    if(intermediate.length){ payload.intermediates = intermediate; }
    return payload;
  }

  // bypassThrottle: salta el cooldown de 30 s (p.ej. Recalcular manual)
  // bypassCache:    salta memoria + IDB (fuerza llamada real a la API)
  // force:          alias legacy — equivale a bypassThrottle=true, bypassCache=false
  async function computeRouteUnified({origin, destination, waypoints, optimize=false, mode='preview', force=false, bypassThrottle, bypassCache}){
    const _skipThrottle = bypassThrottle !== undefined ? bypassThrottle : force;
    // La navegación activa (mode 'nav') siempre evita la caché para reflejar el tráfico en vivo.
    const _skipCache    = (bypassCache !== undefined ? bypassCache : false) || mode === 'nav';
    if(!origin || !destination) throw new Error('Origen/destino inválido');
    if(!navigator.onLine){
      const offlineRoute = await readIdbFresh(buildCacheKey({origin:quantizePoint(origin), destination:quantizePoint(destination), waypoints:(waypoints||[]).map(quantizePoint), optimize, mode}));
      if(offlineRoute) return offlineRoute;
      throw new Error('Sin conexión');
    }
    const key = buildCacheKey({origin:quantizePoint(origin), destination:quantizePoint(destination), waypoints:(waypoints||[]).map(quantizePoint), optimize, mode});
    const now = Date.now();
    const cached = memoryCache.get(key);
    if(!_skipCache && cached && cached.expires > now){ stats.cache_hits_memory++; notifyStats(); return cached.value; }

    const idbCached = await readIdbFresh(key);
    if(!_skipCache && idbCached){ stats.cache_hits_idb++; notifyStats(); memoryCache.set(key,{expires:Date.now()+ROUTE_CACHE_TTL_MS, value:idbCached}); return idbCached; }

    if(!_skipThrottle && lastRequestKey === key && (now - lastRequestTime) < MIN_ROUTE_RECALC_MS){
      if(cached) return cached.value;
    }

    stats.route_requests_total++; notifyStats();
    lastRequestKey = key; lastRequestTime = now;

    const payload = buildRoutesPayload({origin, destination, waypoints, optimize, mode});
    // El fallback DirectionsService usa optimizeWaypoints solo si NO es navegación (igual que el
    // payload de Routes API, que fuerza optimizeWaypointOrder=false en 'nav').
    const req = { origin, destination, waypoints: (waypoints||[]).map(w=>({ location:new google.maps.LatLng(w.lat, w.lng) })), travelMode:'DRIVING', optimizeWaypoints: mode === 'nav' ? false : !!optimize };

    // Navegación activa: cancelar el reroute anterior para ignorar respuestas viejas.
    let effSignal;
    if(mode === 'nav'){
      try{ if(navController) navController.abort(); }catch(_){ }
      navController = new AbortController();
      effSignal = navController.signal;
    }

    const promise = (async ()=>{
      try{
        const route = await fetchRoutesApi(payload, mode, effSignal);
        await idbSet(key, { value: route, expires: Date.now() + ROUTE_CACHE_TTL_MS, mode });
        return route;
      }catch(err){
        if(err && err.name === 'AbortError') throw err; // cancelado por un request más nuevo
        // Fix #5: también cachear el resultado del fallback en IDB
        const fallback = await fetchDirectionsFallback(req);
        await idbSet(key, { value: fallback, expires: Date.now() + ROUTE_CACHE_TTL_MS, mode });
        return fallback;
      }
    })();

    memoryCache.set(key, { expires: now + ROUTE_CACHE_TTL_MS, value: promise });
    try{
      const route = await promise;
      memoryCache.set(key, { expires: Date.now() + ROUTE_CACHE_TTL_MS, value: route });
      return route;
    }catch(err){ memoryCache.delete(key); throw err; }
  }

  async function readIdbFresh(key){
    const item = await idbGet(key);
    if(!item) return null;
    if(item.expires && item.expires < Date.now()) return null;
    return item.value || null;
  }

  // Fix #9: limpiar segmentos previos antes del early return
  // Fix #10: validar índices de intervalos de tráfico
  function buildTrafficPolylines(map, route){
    if(!map) return [];
    (map.__trafficSegs||[]).forEach(s=>s.setMap(null));
    map.__trafficSegs = [];
    if(!route || !route.polyline) return [];
    const intervals = (route.travelAdvisory && route.travelAdvisory.speedReadingIntervals) || [];
    const decoded = google.maps.geometry.encoding.decodePath(route.polyline.encodedPolyline || '');
    const segs = [];
    if(!intervals.length){
      const p=new google.maps.Polyline({ path: decoded, strokeColor:'#4285F4', strokeOpacity:0.85, strokeWeight:5, map });
      map.__trafficSegs=[p];
      return map.__trafficSegs;
    }
    intervals.forEach(int=>{
      const startIdx = int.startPolylinePointIndex;
      const endIdx   = int.endPolylinePointIndex;
      if(typeof startIdx !== 'number' || typeof endIdx !== 'number' || startIdx > endIdx) return;
      const segment = decoded.slice(startIdx, endIdx + 1);
      if(segment.length < 2) return;
      const color = int.speed === 'SLOW' ? '#FBBC04' : int.speed === 'TRAFFIC_JAM' ? '#EA4335' : '#34A853';
      const p=new google.maps.Polyline({ path: segment, strokeColor: color, strokeOpacity:0.9, strokeWeight:6, map });
      segs.push(p);
    });
    map.__trafficSegs = segs;
    return segs;
  }

  function markReroute(){ stats.reroutes_triggered++; notifyStats(); }

  // Distancia (m) de un punto al segmento a→b usando proyección equirectangular local (precisa a
  // escala urbana, sin dependencia de google.maps).
  function distPointToSegMeters(p, a, b){
    const R = 6371000, toRad = x => x * Math.PI / 180;
    const lat0 = toRad((a.lat + b.lat) / 2);
    const proj = q => ({ x: R * toRad(q.lng) * Math.cos(lat0), y: R * toRad(q.lat) });
    const P = proj(p), A = proj(a), B = proj(b);
    const dx = B.x - A.x, dy = B.y - A.y;
    const len2 = dx * dx + dy * dy;
    let t = len2 > 0 ? ((P.x - A.x) * dx + (P.y - A.y) * dy) / len2 : 0;
    t = Math.max(0, Math.min(1, t));
    const cx = A.x + t * dx, cy = A.y + t * dy;
    return Math.hypot(P.x - cx, P.y - cy);
  }

  // ¿El punto está dentro de `tolMeters` de la polilínea de la ruta? Lo usa NavEngine en cada tick
  // de GPS (antes invocaba RouteEngine.isOnRoutePath, que NO existía → caía a un fallback que creaba
  // un Polyline por tick). Esta versión es O(n) en math pura, sin crear objetos de Maps.
  function isOnRoutePath(point, path, tolMeters){
    if(!point || !path || path.length < 2) return true;
    const tol = tolMeters || 50;
    for(let i = 0; i < path.length - 1; i++){
      if(distPointToSegMeters(point, path[i], path[i + 1]) <= tol) return true;
    }
    return false;
  }

  window.RouteEngine = {
    computeRouteUnified,
    buildTrafficPolylines,
    isOnRoutePath,
    getStats: ()=>({ ...stats }),
    markReroute,
    // Expuestas para pruebas unitarias (funciones puras):
    buildRoutesPayload,
    normalizeRoutesApiResponse,
    sanitizeInstruction
  };
})(window);

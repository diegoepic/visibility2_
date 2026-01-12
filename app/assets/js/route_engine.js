(function(window){
  'use strict';

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
    const wps = (waypoints || []).map(coordsHash).join('|');
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

  function normalizeRoutesApiResponse(json){
    if(!json || !json.routes || !json.routes.length) throw new Error('Respuesta vacía');
    return json.routes[0];
  }

  function normalizeDirectionsFallback(res){
    const legs = res.routes[0].legs.map(l=>({
      distanceMeters: l.distance.value,
      duration: l.duration.value + 's',
      startLocation: { lat: l.start_location.lat(), lng: l.start_location.lng() },
      endLocation: { lat: l.end_location.lat(), lng: l.end_location.lng() },
      steps: (l.steps||[]).map(st=>({
        distanceMeters: st.distance.value,
        staticDuration: st.duration.value + 's',
        navigationInstruction: { instructions: st.instructions || '' },
        startLocation: { lat: st.start_location.lat(), lng: st.start_location.lng() },
        endLocation: { lat: st.end_location.lat(), lng: st.end_location.lng() },
        polyline: st.path ? { encodedPolyline: google.maps.geometry.encoding.encodePath(st.path) } : null
      }))
    }));
    return {
      distanceMeters: res.routes[0].legs.reduce((a,l)=>a+l.distance.value,0),
      duration: res.routes[0].legs.reduce((a,l)=>a+l.duration.value,0)+'s',
      polyline:{ encodedPolyline: google.maps.geometry.encoding.encodePath(res.routes[0].overview_path) },
      travelAdvisory:{},
      legs
    };
  }

  function fieldMaskForMode(mode, optimize){
    const base = ['routes.distanceMeters','routes.duration'];
    if(mode === 'nav'){
      base.push('routes.polyline.encodedPolyline');
      base.push('routes.legs.steps.navigationInstruction','routes.legs.steps.distanceMeters','routes.legs.steps.staticDuration');
    } else if(mode === 'traffic'){
      base.push('routes.duration','routes.polyline.encodedPolyline','routes.travelAdvisory.speedReadingIntervals');
    } else {
      base.push('routes.polyline.encodedPolyline');
    }
    if(mode === 'traffic'){
      base.push('routes.travelAdvisory.speedReadingIntervals');
    }
    if(optimize){
      base.push('routes.optimizedIntermediateWaypointIndex');
    }
    return base;
  }

  async function fetchRoutesApi(payload, mode){
    const headers = {
      'Content-Type': 'application/json',
      'X-Goog-Api-Key': window.__GOOGLE_MAPS_API_KEY || '',
      'X-Goog-FieldMask': fieldMaskForMode(mode, payload.optimizeWaypointOrder).join(',')
    };
    const res = await fetch(GOOGLE_ROUTES_ENDPOINT, { method:'POST', headers, body: JSON.stringify(payload) });
    if(!res.ok) throw new Error('Routes API error');
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

  function buildRoutesPayload({origin, destination, waypoints, optimize}){
    const intermediate = (waypoints||[]).map(p=>({ location:{ latLng:{ latitude:p.lat, longitude:p.lng } } }));
    const payload = {
      origin:{ location:{ latLng:{ latitude:origin.lat, longitude:origin.lng } } },
      destination:{ location:{ latLng:{ latitude:destination.lat, longitude:destination.lng } } },
      travelMode:'DRIVE',
      polylineEncoding: 'ENCODED_POLYLINE',
      optimizeWaypointOrder: !!optimize
    };
    if(intermediate.length){ payload.intermediates = intermediate; }
    return payload;
  }

  async function computeRouteUnified({origin, destination, waypoints, optimize=false, mode='preview'}){
    if(!origin || !destination) throw new Error('Origen/destino inválido');
    if(!navigator.onLine){
      const offlineRoute = await readIdbFresh(buildCacheKey({origin:quantizePoint(origin), destination:quantizePoint(destination), waypoints:(waypoints||[]).map(quantizePoint), optimize, mode}));
      if(offlineRoute) return offlineRoute;
      throw new Error('Sin conexión');
    }
    const key = buildCacheKey({origin:quantizePoint(origin), destination:quantizePoint(destination), waypoints:(waypoints||[]).map(quantizePoint), optimize, mode});
    const now = Date.now();
    const cached = memoryCache.get(key);
    if(cached && cached.expires > now){ stats.cache_hits_memory++; notifyStats(); return cached.value; }

    const idbCached = await readIdbFresh(key);
    if(idbCached){ stats.cache_hits_idb++; notifyStats(); memoryCache.set(key,{expires:Date.now()+ROUTE_CACHE_TTL_MS, value:idbCached}); return idbCached; }

    if(lastRequestKey === key && (now - lastRequestTime) < MIN_ROUTE_RECALC_MS){
      if(cached) return cached.value;
    }

    stats.route_requests_total++; notifyStats();
    lastRequestKey = key; lastRequestTime = now;

    const payload = buildRoutesPayload({origin, destination, waypoints, optimize});
    const req = { origin, destination, waypoints: (waypoints||[]).map(w=>({ location:new google.maps.LatLng(w.lat, w.lng) })), travelMode:'DRIVING', optimizeWaypoints: !!optimize };

    const promise = (async ()=>{
      try{
        const route = await fetchRoutesApi(payload, mode);
        await idbSet(key, { value: route, expires: Date.now() + ROUTE_CACHE_TTL_MS, mode });
        return route;
      }catch(err){
        return fetchDirectionsFallback(req);
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

  function buildTrafficPolylines(map, route){
    if(!map || !route || !route.polyline) return [];
    const intervals = (route.travelAdvisory && route.travelAdvisory.speedReadingIntervals) || [];
    const decoded = google.maps.geometry.encoding.decodePath(route.polyline.encodedPolyline || '');
    const segs = [];
    (map.__trafficSegs||[]).forEach(s=>s.setMap(null));
    map.__trafficSegs = [];
    if(!intervals.length){
      const p=new google.maps.Polyline({ path: decoded, strokeColor:'#4285F4', strokeOpacity:0.85, strokeWeight:5, map });
      map.__trafficSegs=[p];
      return map.__trafficSegs;
    }
    intervals.forEach(int=>{
      const color = int.speed === 'SLOW' ? '#FBBC04' : int.speed === 'TRAFFIC_JAM' ? '#EA4335' : '#34A853';
      const p=new google.maps.Polyline({ path: decoded.slice(int.startPolylinePointIndex, int.endPolylinePointIndex+1), strokeColor: color, strokeOpacity:0.9, strokeWeight:6, map });
      segs.push(p);
    });
    map.__trafficSegs = segs;
    return segs;
  }

  function markReroute(){ stats.reroutes_triggered++; notifyStats(); }

  window.RouteEngine = {
    computeRouteUnified,
    buildTrafficPolylines,
    getStats: ()=>({ ...stats }),
    markReroute
  };
})(window);
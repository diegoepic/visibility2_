/**
 * Nucleo de la ruta del ejecutor: mapa, seleccion de locales, filtros, planificacion y HUD
 * de navegacion. Extraido del <script> inline de index_pruebas.php (T6-6).
 *
 * ERA EL BLOQUE MAS GRANDE DE LA APP: 1.282 lineas, 66 KB, que se volvian a bajar en cada
 * apertura de la pagina porque las navegaciones van por networkFirst. Aca se baja una vez
 * por version y despues sale del cache.
 *
 * QUE CAMBIO AL MOVERLO
 * ---------------------
 * Nada de logica. Tenia exactamente tres trozos de PHP incrustados y los tres eran DATOS,
 * asi que pasaron al bloque de configuracion de index_pruebas.php, que es donde ya vivian
 * __GOOGLE_MAPS_API_KEY y __ROUTES_PROXY_URL:
 *
 *     IS_TEST_MODE      <- window.__TEST_MODE
 *     coordenadasProg   <- window.__COORD_PROG   (siguen siendo const locales de initMap)
 *     coordenadasReag   <- window.__COORD_REAG
 *
 * El resto es identico, linea por linea.
 *
 * POR QUE SE PIDE SIN ?v=
 * -----------------------
 * Esta en STATIC_ASSETS de sw.js y cacheFirst() hace match sin ignoreSearch: con query
 * string no encontraria la entrada precacheada y, sin red, la pagina de ruta se quedaria
 * sin mapa ni planificador. El cache-busting lo da el nombre del cache (static-VERSION).
 *
 * Este archivo no lleva ningun dato del usuario: la agenda sigue embebida en la pagina.
 * Eso es lo que hace seguro precachearlo (comparar con V-21, que sigue abierto).
 *
 * Se carga DESPUES de route_planner.js/route_engine.js/nav_engine.js y ANTES de db.js y
 * compania. El orden entre <script> clasicos se respeta aunque sean externos, asi que la
 * secuencia de arranque es la misma que cuando estaba inline.
 */
// ============ Preferencias/estado ============
function debounce(fn, d){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(null,a),d); }; }
window.optimizeOrder = true;   // toggle "Optimizar"
window.autoRecalc    = true;   // toggle "Auto"
window.voiceEnabled  = false;  // toggle "Voz"
window.trafficEnabled = false; // toggle "Tráfico" (capa visual + modo de cálculo 'traffic')

// Toast no bloqueante reutilizable (reemplaza alerts). `actions` = [{label, cls, fn}].
// Nota: varios modales (reportar dirección / cambiar vendedor) ya invocan mostrarToast con fallback
// a alert; aquí queda definido de verdad.
window.mostrarToast = function(msg, type, duration, actions){
  const cont = document.getElementById('v2Toasts');
  if(!cont){ if(type==='error'||type==='warn') console.warn(msg); return null; }
  const el = document.createElement('div');
  el.className = 'v2-toast v2-toast--' + (type || 'info');
  const span = document.createElement('span');
  span.className = 'v2-toast__msg';
  span.textContent = msg;
  el.appendChild(span);
  (actions || []).forEach(a => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-xs ' + (a.cls || 'btn-default');
    b.textContent = a.label;
    b.addEventListener('click', () => { try { a.fn && a.fn(); } catch(_){} dismiss(); });
    el.appendChild(b);
  });
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'v2-toast__close';
  close.innerHTML = '&times;';
  close.addEventListener('click', dismiss);
  el.appendChild(close);
  cont.appendChild(el);
  let to = setTimeout(dismiss, duration || 4000);
  function dismiss(){
    if(!el.parentNode) return;
    clearTimeout(to);
    el.classList.add('v2-toast--out');
    setTimeout(() => { if(el.parentNode) el.parentNode.removeChild(el); }, 250);
  }
  return el;
};
var mostrarToast = window.mostrarToast; // alias local
function savePref(k,v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
function loadPref(k,f){ try{ const v=localStorage.getItem(k); return v?JSON.parse(v):f; }catch(e){ return f; } }
function rememberMode(m){ savePref('v2_mode', m); }
function loadMode(){ return loadPref('v2_mode','prog'); }
function hasReagendadosData(){
  if (window.markersReag && Object.keys(window.markersReag).length) return true;
  return $('#panelReagendados table tbody tr').length > 0;
}
function rememberDate(mode, date){ savePref('v2_date_'+mode, date); }  function loadDate(mode){ return loadPref('v2_date_'+mode, ''); }
// Excluidos
function exclKey(modo, fecha, id){ return `${modo}|${fecha}|${id}`; }
function loadExcluded(){ window.excluded = new Set(loadPref('v2_excluded', [])); }
function saveExcluded(){ savePref('v2_excluded', Array.from(window.excluded||[])); }
loadExcluded();

// ============ Utilidades de ruta ============
const GOOGLE_MAPS_API_KEY = window.__GOOGLE_MAPS_API_KEY || '';
// Map ID vectorial (viene de GOOGLE_MAPS_MAP_ID en app/.env). Vacío = mapa raster: la
// navegación funciona igual, sólo que sin inclinación 3D. Ver nav_camera.js.
const MAP_ID = window.__GOOGLE_MAPS_MAP_ID || '';
const MAPS_LIBRARIES = 'geometry';
const IS_TEST_MODE = window.__TEST_MODE === true;
let mapsScriptPromise = null;
let mapsRetryTimer = null;
const apiCounters = { timezone_calls: 0 };
let isMapVisible = false; // "Maps visibility guard"
window.startGeoWatch=window.startGeoWatch||function(){};
window.stopGeoWatch=window.stopGeoWatch||function(){};

// Contadores de API: solo en modo debug (?debug=1 o localStorage.v2_debug=1).
const V2_DEBUG = /[?&]debug=1/.test(location.search) || localStorage.getItem('v2_debug') === '1';
function updateApiCounters(){
  const el=document.getElementById('apiCounters');
  if(!el) return;
  el.style.display = V2_DEBUG ? '' : 'none';
  if(!V2_DEBUG) return;
  const stats = window.RouteEngine ? window.RouteEngine.getStats() : {};
  const routes = stats.routes_api_requests || 0;
  const fallback = stats.directions_fallback_requests || 0;
  const mem = stats.cache_hits_memory || 0;
  const idb = stats.cache_hits_idb || 0;
  const total = stats.route_requests_total || 0;
  const reroutes = stats.reroutes_triggered || 0;
  el.textContent=`API: Routes ${routes} | Fallback ${fallback} | Cache M ${mem} | Cache DB ${idb} | Total ${total} | Reroutes ${reroutes}`;
}
updateApiCounters();
window.addEventListener('route-engine-stats', updateApiCounters);

function scheduleMapsRetry(){
  if (mapsRetryTimer) return;
  const retry = ()=>{
    mapsRetryTimer = null;
    loadGoogleMapsSdk().then(()=>{ if(!window.mapa) initMap(); }).catch(()=>{});
  };
  if (navigator.onLine){
    mapsRetryTimer = setTimeout(retry, 5000);
  } else {
    const onBackOnline = ()=>{ window.removeEventListener('online', onBackOnline); retry(); };
    window.addEventListener('online', onBackOnline);
  }
}

function loadGoogleMapsSdk(){
  if (window.google && window.google.maps) return Promise.resolve(window.google.maps);
  if (mapsScriptPromise) return mapsScriptPromise;
  if (IS_TEST_MODE){
    window.google = {
      maps: {
        Map: function(){ this.setCenter=()=>{}; this.setZoom=()=>{}; this.fitBounds=()=>{}; this.addListener=()=>{}; },
        Marker: function(opts){ this._map = opts.map; this.setMap=(m)=>{ this._map=m; }; this.getMap=()=>this._map; this.setPosition=()=>{}; this.getPosition=()=>({ toJSON:()=>({lat:0,lng:0}) }); },
        InfoWindow: function(){ this.open=()=>{}; },
        LatLng: function(lat,lng){ this.lat=()=>lat; this.lng=()=>lng; this.toJSON=()=>({lat,lng}); },
        LatLngBounds: function(){ this.extend=()=>{}; },
        TrafficLayer: function(){ this._map=null; this.getMap=()=>this._map; this.setMap=(m)=>{ this._map=m; }; },
        geometry: { spherical: { computeDistanceBetween: ()=>0 }, encoding: { encodePath: ()=>' ', decodePath: ()=>[] } },
        DirectionsService: function(){ this.route=(req, cb)=>cb({ routes:[{ legs: [], overview_path: [] }]}, 'OK'); },
        DirectionsStatus: { OK: 'OK' },
        SymbolPath: { CIRCLE: 'CIRCLE' },
        event: { trigger: ()=>{} },
        TravelMode: { DRIVING: 'DRIVING' }
      }
    };
    return Promise.resolve(window.google.maps);
  }
  if (!GOOGLE_MAPS_API_KEY){
    return Promise.reject(new Error('Falta la Google Maps API key.'));
  }

  mapsScriptPromise = new Promise((resolve, reject)=>{
    const prev = document.querySelector('script[data-google-maps-loader="true"]');
    if (prev) prev.remove();
    const s = document.createElement('script');
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_API_KEY)}&libraries=${MAPS_LIBRARIES}`;
    s.async = true; s.defer = true; s.dataset.googleMapsLoader = 'true';
    const onError = ()=>{ cleanup(); reject(new Error('Error al cargar Google Maps.')); };
    const onLoad = ()=>{ cleanup(true); resolve(window.google.maps); };
    const cleanup = (ok)=>{ s.onload=null; s.onerror=null; if (!ok && s.parentNode) s.parentNode.removeChild(s); };
    s.onload = onLoad;
    s.onerror = onError;
    document.head.appendChild(s);
  }).catch(err=>{ mapsScriptPromise=null; scheduleMapsRetry(); throw err; });

  return mapsScriptPromise;
}

async function ensureMapReady(){
  await loadGoogleMapsSdk();
  if (!window.mapa) initMap();
}
function secondsFromDuration(d){ if (typeof d==='string' && d.endsWith('s')) return Math.round(parseFloat(d)); return 0; }
function decode(encoded){ return google.maps.geometry.encoding.decodePath(encoded).map(ll=>({lat:ll.lat(), lng:ll.lng()})); }
function fmtKm(m){ return (m>=1000) ? (m/1000).toFixed(1)+' km' : Math.round(m)+' m'; }

// Pinta polilíneas por tráfico (Routes v2)
function buildTrafficPolylines(map, route){
  return window.RouteEngine.buildTrafficPolylines(map, route);
}

// Drawer de pasos
function renderIndicacionesFromRoute(route){
  const $ol=$('#listaIndicaciones'); $ol.empty();
  if(!route || !(route.legs||[]).length){
    $ol.append('<li>Instrucciones detalladas disponibles en modo navegación.</li>');
    return;
  }
  (route.legs||[]).forEach(leg=>{
    (leg.steps||[]).forEach(st=>{
      const ins=(st.navigationInstruction && st.navigationInstruction.instructions) || '';
      const dist=(st.distanceMeters!=null)? fmtKm(st.distanceMeters):'';
      const dur =(st.staticDuration)? Math.round(secondsFromDuration(st.staticDuration)/60)+' min':'';
      const meta=[dist,dur].filter(Boolean).join(' • ');
      $ol.append(`<li>${ins || 'Sigue la vía'}<br><small>${meta}</small></li>`);
    });
  });
}

// Motor unificado (Routes v2) con fallback a DirectionsService + caché en memoria
// Fix #15: propaga el flag force para saltarse el rate limit cuando el usuario recalcula manualmente
async function computeRouteUnified({origin,destination,waypoints=[], optimize=true, mode, force=false, bypassCache=false}){
  const chosenMode = mode || (window.trafficEnabled ? 'traffic' : 'preview');
  // force salta throttle pero NO la caché; bypassCache se usa explícitamente para datos frescos
  return window.RouteEngine.computeRouteUnified({ origin, destination, waypoints, optimize, mode: chosenMode, bypassThrottle: force, bypassCache });
}

// Delegado a RouteSelection.collect() — mantiene compatibilidad con llamadas existentes
function collectCurrentPoints(){
  const modo     = window.modoLocal;
  const cont     = (modo === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId    = (modo === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId = (modo === 'prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const result   = window.RouteSelection.collect({
    modo,
    fechaSel:   $(selId).val(),
    searchTerm: $(searchId).val(),
    excluded:   window.excluded,
    cont
  });
  return result.pts;
}

// ── Numeración de marcadores en el mapa ──────────────────────────────────────

function makeNumberedIcon(num, color){
  const fs  = num > 9 ? '10' : '13';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">` +
    `<path d="M15 0C6.72 0 0 6.72 0 15c0 11.25 15 27 15 27S30 26.25 30 15C30 6.72 23.28 0 15 0z" ` +
    `fill="${color}" stroke="rgba(0,0,0,0.3)" stroke-width="1"/>` +
    `<text x="15" y="16" text-anchor="middle" dominant-baseline="middle" ` +
    `fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="${fs}" font-weight="bold">${num}</text>` +
    `</svg>`;
  return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

// Aplica números de orden a los marcadores del modo activo
function applyRouteNumbers(orderedPts){
  const markers = (window.modoLocal === 'prog') ? window.markersProg : window.markersReag;
  // Primero restaurar todos los marcadores a su ícono original
  Object.values(markers).forEach(function(m){
    if (m.originalIcon) { m.marker.setIcon(m.originalIcon); }
  });
  // Luego numerar los que están en la ruta
  orderedPts.forEach(function(pt, idx){
    const m = markers[pt.idLocal];
    if (!m) return;
    const color = m.markerColor === 'orange' ? '#e67e22' : m.markerColor === 'blue' ? '#2980b9' : '#c0392b';
    m.marker.setIcon({ url: makeNumberedIcon(idx + 1, color), scaledSize: new google.maps.Size(30, 42) });
  });
}

// Limpia todos los números y restaura íconos originales
function clearRouteNumbers(){
  [window.markersProg, window.markersReag].forEach(function(markers){
    if (!markers) return;
    Object.values(markers).forEach(function(m){
      if (m.originalIcon) { m.marker.setIcon(m.originalIcon); }
    });
  });
}

// Texto a voz. Delega en VoiceController (cola con prioridades, voz es-CL, sin cortar la frase
// anterior). La rama de abajo es el camino legacy y sólo corre si voice_controller.js no cargó:
// hace cancel() en cada llamada, que es justamente lo que cortaba los anuncios a la mitad.
function speak(text, priority){
  if(window.VoiceController && typeof window.VoiceController.isEnabled === 'function'){
    window.VoiceController.speak(text, priority || 'normal');
    return;
  }
  if(!window.voiceEnabled) return;
  try{
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-CL';
    const v=speechSynthesis.getVoices().find(v=>/es\-(CL|ES|MX)/i.test(v.lang));
    if(v) u.voice=v;
    speechSynthesis.speak(u);
  }catch(_){}
}

// Un solo lugar pinta los dos botones de voz (#btnVoz en el panel, #btnVozNav en el HUD) y
// mantiene window.voiceEnabled en sintonía con el estado real del controller.
window.addEventListener('voice-enabled-changed', function(e){
  const on = !!(e.detail && e.detail.enabled);
  window.voiceEnabled = on;
  $('#btnVoz').toggleClass('btn-info', on);
  $('#btnVozNav').find('i').attr('class', on ? 'fa fa-volume-up' : 'fa fa-volume-off');
});

// Estado inicial: la preferencia queda persistida en RoutePreferences entre sesiones.
$(function(){
  if(window.VoiceController && typeof window.VoiceController.isEnabled === 'function'){
    window.voiceEnabled = window.VoiceController.isEnabled();
    window.dispatchEvent(new CustomEvent('voice-enabled-changed', { detail:{ enabled: window.voiceEnabled } }));
  }
});

function logRouteEvent(payload){
  const now=new Date();
  console.debug('[route]', { ts: now.toISOString(), ...payload });
}

// Reordena las filas del tbody según el orden optimizado devuelto por Routes API
function renderTableOrder(orderedPts){
  const cont  = (window.modoLocal === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId = (window.modoLocal === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const fechaSel = $(selId).val();
  const $tbody = $(`${cont} table[data-fechaTabla="${fechaSel}"] tbody`);
  if (!$tbody.length) return;
  const rowMap = {};
  $tbody.find('tr').each(function(){ rowMap[parseInt($(this).data('idlocal'),10)] = $(this).detach(); });
  orderedPts.forEach(function(pt, idx){
    const $tr = rowMap[pt.idLocal];
    if (!$tr) return;
    let $badge = $tr.find('.v2-route-ord');
    if (!$badge.length) {
      $tr.find('.in-route').closest('td').prepend(
        '<span class="v2-route-ord" style="display:inline-block;background:#4285F4;color:#fff;border-radius:50%;width:18px;height:18px;text-align:center;line-height:18px;font-size:11px;font-weight:bold;margin-right:3px;vertical-align:middle;"></span>'
      );
      $badge = $tr.find('.v2-route-ord');
    }
    $badge.text(idx + 1);
    $tbody.append($tr);
  });
  // Filas no incluidas en la ruta (sin checkbox o excluidas) van al final
  Object.values(rowMap).forEach(function($tr){ if ($tr && $tr.parent().length === 0) $tbody.append($tr); });
}

window.planRouteFromSelection = async function (origen, opts={}){
  const { force=false, trigger='unknown' } = opts;
  if(!isMapVisible || !window.mapa) { logRouteEvent({trigger, skipped:'map_hidden'}); return; }

  // Recolectar mediante RouteSelection para obtener metadata cross-date
  const modo     = window.modoLocal;
  const cont     = (modo === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId    = (modo === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId = (modo === 'prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const selection = window.RouteSelection.collect({
    modo, fechaSel: $(selId).val(), searchTerm: $(searchId).val(), excluded: window.excluded, cont
  });
  const puntos = selection.pts;

  if (!puntos.length) {
    (window.mapa.__trafficSegs||[]).forEach(s=>s.setMap(null)); window.mapa.__trafficSegs=[];
    window.plannedRoute=null; window.lastComputedRoute=null; window._lastRouteStateHash=null; window._lastComputedOrigin=null;
    clearRouteNumbers();
    $('#distanciaTotal').text('0 km'); $('#duracionEstimada').text('0 min'); $('#listaIndicaciones').empty(); return;
  }

  // Aviso cross-date: si la búsqueda activa mezcla locales de varias fechas, pedir confirmación
  if (selection.isMultiDate && trigger !== 'cross_date_confirmed') {
    const ok = await window.RouteSelection.confirmCrossDate(selection.fechas);
    if (!ok) return;
    // Re-invocar con flag que indica que el usuario ya confirmó
    return window.planRouteFromSelection(origen, { ...opts, trigger: 'cross_date_confirmed' });
  }

  // routeStateHash: cambia si cambian las paradas, el ORIGEN (bucket ~50 m), el modo, la
  // optimización, el tráfico, la fecha o el modo local. Reemplaza al antiguo selectionHash (que
  // solo miraba ids y nunca el origen GPS, por lo que ignoraba que el ejecutor se hubiera movido).
  const _planMode = window.trafficEnabled ? 'traffic' : 'preview';
  const routeStateHash = window.RoutePlanner.routeStateHash({
    ids:       puntos.map(p => p.idLocal),
    origin:    origen,
    mode:      _planMode,
    optimize:  window.optimizeOrder,
    traffic:   window.trafficEnabled,
    fecha:     $(selId).val(),
    modoLocal: modo
  });

  // Triggers que SIEMPRE recalculan (no se saltan aunque el hash no cambie): recálculo manual,
  // centrar, abrir modal y reroute de navegación.
  const ALWAYS_RECALC = new Set(['manual_recalc', 'center', 'modal_open', 'navigation-reroute']);
  let shouldRecalc;
  if (force || ALWAYS_RECALC.has(trigger)) {
    shouldRecalc = true;
  } else if (trigger === 'gps_move') {
    // Movimiento GPS: solo si Auto está activo y el ejecutor se movió de forma significativa
    // (>75 m respecto al último origen calculado) o cambió el bucket de origen (hash distinto).
    if (!window.autoRecalc) {
      shouldRecalc = false;
    } else {
      const last  = window._lastComputedOrigin;
      const moved = !last || window.RoutePlanner.haversine(last, origen) > 75;
      shouldRecalc = moved || (routeStateHash !== window._lastRouteStateHash);
    }
  } else {
    shouldRecalc = (routeStateHash !== window._lastRouteStateHash);
  }

  if (!shouldRecalc) {
    if (window.lastComputedRoute) buildTrafficPolylines(window.mapa, window.lastComputedRoute.route);
    return;
  }
  window._lastRouteStateHash = routeStateHash;

  // La hora sale de cuenta.hora_apertura (scripts/37), no de un 13 escrito a mano. Si hay
  // varias cuentas con ventana, manda la MAS TARDIA: el aviso tiene que seguir siendo cierto
  // hasta que abran todas.
  const horaMinActual = new Date().getHours() * 60 + new Date().getMinutes();
  const conVentana = puntos.filter(p => p.isBotilleria);
  const aMinutos = (hhmm) => {
    const m = /^(\d{1,2}):(\d{2})$/.exec(String(hhmm || ''));
    return m ? (Number(m[1]) * 60 + Number(m[2])) : null;
  };
  const minutosApertura = conVentana.map(p => aMinutos(p.horaApertura)).filter(v => v !== null);
  const aperturaTardia = minutosApertura.length ? Math.max(...minutosApertura) : 13 * 60;
  if (conVentana.length && horaMinActual < aperturaTardia) {
    const hh = String(Math.floor(aperturaTardia / 60)).padStart(2, '0');
    const mm = String(aperturaTardia % 60).padStart(2, '0');
    const $w = $('#routeWarning');
    if ($w.length) {
      $w.html(`<i class="fa fa-clock-o"></i> Hay ${conVentana.length} local(es) que abren más tarde. Se agendaron al final (abren a las ${hh}:${mm} hrs).`).stop(true).show();
      setTimeout(() => $w.fadeOut(), 9000);
    }
  }
  const mode = window.trafficEnabled ? 'traffic' : 'preview';
  try{
    const plan = await window.RoutePlanner.planFull({
      origin:        origen,
      points:        puntos,
      optimize:      window.optimizeOrder,
      mode,
      bypassThrottle: force,
      bypassCache:    force   // recálculo manual ("Recalcular") fuerza ruta fresca, sin caché
    });
    window.plannedRoute      = plan.route;
    window.lastComputedRoute = { orderedPts: plan.orderedPts, route: plan.route, chunks: plan.chunks, totals: plan.totals };
    window._lastComputedOrigin = origen; // base para el umbral de movimiento del próximo gps_move

    // Reordenar tabla y numerar marcadores del mapa con el orden final
    renderTableOrder(plan.orderedPts);
    applyRouteNumbers(plan.orderedPts);

    // UI de chunks: banner informativo cuando la ruta fue dividida
    if (plan.chunks && plan.chunks.length > 1) {
      window._routeChunks = plan.chunks;
      const $w = $('#routeWarning');
      if ($w.length) {
        $w.html(
          `<i class="fa fa-map-signs"></i> Ruta larga dividida en <strong>${plan.chunks.length} bloques</strong> ` +
          `de cálculo (${plan.orderedPts.length} locales). Usa <strong>“Abrir en Google Maps”</strong> ` +
          `para exportarla en enlaces encadenados.`
        ).stop(true).show();
      }
    } else {
      window._routeChunks = null;
    }

    buildTrafficPolylines(window.mapa, plan.route);
    // Distancia/duración = TOTALES agregados de todos los bloques (no solo el primero).
    const totDist = (plan.totals && plan.totals.distanceMeters)  || plan.route.distanceMeters || 0;
    const totSecs = (plan.totals && plan.totals.durationSeconds) || secondsFromDuration(plan.route.duration);
    const km  = (totDist/1000).toFixed(2);
    const min = Math.round(totSecs/60);
    $('#distanciaTotal').text(`${km} km`);
    $('#duracionEstimada').text(`${min} min`);
    renderIndicacionesFromRoute(plan.route);
    if (!navigator.onLine && (trigger === 'manual_recalc' || trigger === 'modal_open')) {
      mostrarToast('Usando ruta guardada sin conexión.', 'info');
    }
    logRouteEvent({trigger, apiUsed: plan.apiUsed});
    speak(`Ruta actualizada. ${km} kilómetros, ${min} minutos.`);
  }catch(err){
    logRouteEvent({trigger, error:String(err)});
    if (err.message?.includes('Sin conexión')) {
      mostrarToast('Sin conexión y sin ruta guardada para mostrar.', 'warn');
    } else {
      const $w = $('#routeWarning');
      if ($w.length) $w.text('Error al calcular ruta: ' + (err.message || 'Error desconocido')).stop(true).show();
    }
  }
};
window.debouncedPlanRoute = debounce((pos)=>window.planRouteFromSelection(pos,{trigger:'gps_move'}), 1000);

// Estado marcadores y contadores
window.markersProg={}; window.markersReag={}; window.plannedRoute=null;
function hideAllMarkers(obj){ Object.values(obj).forEach(m => m.marker.setMap(null)); }
function ensureDateSelectedFor(mode){
  const selId = (mode === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const $sel  = $(selId); const saved = loadDate(mode);
  if (saved && $sel.find(`option[value="${saved}"]`).length) $sel.val(saved);
  if (!$sel.val()) { const first = $sel.find('option:first').val(); if (first) $sel.val(first); }
}
function setMode(mode){
  const desired = mode || 'prog';
  const finalMode = (desired === 'reag' && !hasReagendadosData()) ? 'prog' : desired;
  window.modoLocal = finalMode;
  rememberMode(finalMode);
  if (finalMode === 'prog') { $('#panelReagendados').hide(); $('#panelProgramados').show(); }
  else { $('#panelProgramados').hide(); $('#panelReagendados').show(); }
  clearRouteNumbers(); // limpiar números del modo anterior antes de ocultar marcadores
  hideAllMarkers(window.markersProg); hideAllMarkers(window.markersReag);
  window.lastComputedRoute = null; window._lastRouteStateHash = null; window._lastComputedOrigin = null;
  ensureDateSelectedFor(finalMode); applyFilters();
  const pos = window.ejecutorMarker?.getPosition();
  if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) { window.debouncedPlanRoute(pos.toJSON()); }
}
function updateCounts(){
  const mode   = window.modoLocal || 'prog';
  const panel  = (mode==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  $('#countTabla').text($(panel+' table[data-fechaTabla] tbody tr:visible:not([data-done="1"])').length);
  const markers=(mode==='prog')?window.markersProg:window.markersReag;
  const count=Object.values(markers).filter(m=>m.marker.getMap()!==null).length;
  $('#countMapa').text(count);
  // Excluidos: se cuenta fila por fila sobre las tablas VISIBLES, usando la fecha propia de
  // cada tabla. Antes se contaba sólo contra la fecha del <select>, así que en una búsqueda que
  // mezcla fechas los locales desmarcados de los otros días no aparecían y el contador decía 0.
  let excl = 0;
  $(panel + ' table[data-fechaTabla]:visible tbody tr:visible').each(function(){
    const $tr   = $(this);
    const id    = parseInt($tr.data('idlocal'), 10);
    const fecha = $tr.closest('table').data('fechatabla');
    const $chk  = $tr.find('.in-route');
    const desmarcado = $chk.length ? !$chk.prop('checked') : false;
    if (desmarcado || window.excluded.has(`${mode}|${fecha}|${id}`)) excl++;
  });
  $('#countEx').text(excl);
}

// Fix #3: shim de VoiceController que delega en speak() local
// nav_engine.js usa VoiceController; este puente lo conecta con la función speak() de esta página
if (!window.VoiceController) {
  window.VoiceController = {
    speak: function(text) { speak(text); },
    speakNavigation: function(text) { speak(text); },
    speakWaypointArrival: function(name, remaining) {
      speak(remaining > 0 ? 'Parada alcanzada. Quedan ' + remaining + '.' : 'Última parada alcanzada.');
    },
    speakArrival: function() { speak('Has llegado a tu destino.'); },
    speakReroute: function() { speak('Recalculando ruta.'); },
    pause: function() { try { window.speechSynthesis.pause(); } catch(_) {} },
    resume: function() { try { window.speechSynthesis.resume(); } catch(_) {} }
  };
}

// Fix #13: estado para evitar reconstruir el <select> de fechas si no cambiaron
const _fechasOkKeyByMode = { prog: null, reag: null };

// applyFilters: respeta campañas tachadas + fecha + excluidos + checkboxes
// opts.skipPlan: no agendar el recálculo de ruta al terminar. Lo usa quien va a pedir un
// cálculo explícito justo después (abrir el modal del mapa), para no lanzar dos en paralelo.
window.applyFilters = function(opts){
  const modo      = window.modoLocal || 'prog';
  const selId     = (modo==='prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId  = (modo==='prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const container = (modo==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const markers   = (modo==='prog') ? window.markersProg : window.markersReag;
  const other     = (modo==='prog') ? window.markersReag : window.markersProg;
  hideAllMarkers(other);
  const searchTerm = String($(searchId).val() || '').trim().toLowerCase();
  const tachadas = $('ul.todo .completed').map((i,li)=>String($(li).data('idcampana'))).get();
  const fechasOk = {};
  $(`${container} table[data-fechaTabla]`).each(function(){
    const fecha = $(this).attr('data-fechaTabla'); let tiene = false;
    $(this).find('tbody tr').each(function(){
      const camps = String($(this).data('campanas')||'').split(',');
      const ok = camps.some(c => !tachadas.includes(c));
      $(this).data('okCamp', ok);
      if (ok) tiene = true;
    });
    fechasOk[fecha] = tiene;
  });
  
  // Fix #13: reconstruir el <select> solo si el conjunto de fechas cambió
  const $sel = $(selId);
  const prev = $sel.val();
  const newFechasKey = Object.keys(fechasOk).filter(f=>fechasOk[f]).sort().join(',');
  if (newFechasKey !== _fechasOkKeyByMode[modo]) {
    _fechasOkKeyByMode[modo] = newFechasKey;
    $sel.empty();
    Object.keys(fechasOk).filter(f=>fechasOk[f]).sort().forEach(f=>{
      const [y,m,d]=f.split('-'); $sel.append(`<option value="${f}">${d}-${m}-${y}</option>`);
    });
  }
  if (prev && fechasOk[prev]) $sel.val(prev);
  if (!$sel.val()) $sel.val($sel.find('option:first').val() || '');
  const fechaSel = $sel.val(); rememberDate(modo, fechaSel);
  $(`${container} h4[data-fechaencabezado], ${container} table[data-fechaTabla]`).hide();
  if (searchTerm) {
    // Con texto: buscar en todas las fechas
    $(`${container} table[data-fechaTabla]`).each(function(){
      const fecha = $(this).attr('data-fechaTabla');
      let anyVisible = false;
      $(this).find('tbody tr').each(function(){
        const okCamp = !!$(this).data('okCamp');
        const txt = String($(this).data('busqueda') || $(this).text() || '').toLowerCase();
        const matches = txt.includes(searchTerm);
        $(this).toggle(okCamp && matches);
        if (okCamp && matches) anyVisible = true;
        const id = parseInt($(this).data('idlocal'),10);
        const $chk = $(this).find('input.in-route');
        if ($chk.length){
          const excluded = window.excluded.has(`${modo}|${fecha}|${id}`);
          $chk.prop('checked', !excluded);
        }
      });
      if (anyVisible){
        $(`${container} h4[data-fechaencabezado="${fecha}"]`).show();
        $(this).show();
      }
    });
  } else if (fechaSel){
    // Sin texto: mostrar solo la fecha seleccionada
    $(`${container} h4[data-fechaencabezado="${fechaSel}"], ${container} table[data-fechaTabla="${fechaSel}"]`).show();
    $(`${container} table[data-fechaTabla="${fechaSel}"] tbody tr`).each(function(){
      const okCamp = !!$(this).data('okCamp');
      $(this).toggle(okCamp);
      const id = parseInt($(this).data('idlocal'),10);
      const $chk = $(this).find('input.in-route');
      if ($chk.length){
        const excluded = window.excluded.has(`${modo}|${fechaSel}|${id}`);
        $chk.prop('checked', !excluded);
      }
    });
  }
  Object.entries(markers).forEach(([id,m])=>{
    // Un local puede estar programado en varias fechas; mostrar su (único) marcador si tiene
    // CUALQUIER fila visible en el contenedor, no solo en la fecha guardada en el marcador.
    const visibleRow = $(`${container} table[data-fechaTabla] tbody tr[data-idlocal="${id}"]:visible:not([data-done="1"])`).length > 0;
    m.marker.setMap(visibleRow ? window.mapa : null);
  });
  const visibles = Object.values(markers).filter(m=>m.marker.getMap()!==null);
  if (visibles.length && window.google && window.google.maps){
    const b = new google.maps.LatLngBounds(); visibles.forEach(m=>b.extend(m.marker.getPosition())); window.mapa.fitBounds(b);
  }
  updateCounts();
  if (opts && opts.skipPlan) return;
  const pos = window.ejecutorMarker?.getPosition();
  if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON());
};

// ====== INIT MAP ======
window.initMap=function(){
  if (window.mapa) {
    google.maps.event.trigger(window.mapa, 'resize');
    return;
  }
  // Siguen siendo const LOCALES de initMap, como antes; lo unico que cambia es de donde
  // salen los datos (el bloque de configuracion de mas arriba, que si es PHP).
  const coordenadasProg=window.__COORD_PROG||[];
  const coordenadasReag=window.__COORD_REAG||[];

  const mapOptions = { zoom:12, center:{lat:-33.4489, lng:-70.6693} };
  if (MAP_ID && MAP_ID !== 'YOUR_VECTOR_MAP_ID') mapOptions.mapId = MAP_ID;

  window.mapa=new google.maps.Map(document.getElementById('map'), mapOptions);

  const orangeMarkerSvg = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">' +
    '<path d="M15 0C6.72 0 0 6.72 0 15c0 11.25 15 27 15 27S30 26.25 30 15C30 6.72 23.28 0 15 0z" fill="#e67e22" stroke="#fff" stroke-width="1.5"/>' +
    '<circle cx="15" cy="15" r="6" fill="#fff"/>' +
    '</svg>'
  );

  // Marcadores Programados
  coordenadasProg.forEach(local=>{
    // Dedup por idLocal: un local puede venir repetido (varias fechas). Crear más de un marcador
    // por local deja marcadores huérfanos en el mapa (sin número y sin ocultar). Uno por local.
    if (window.markersProg[local.idLocal]) return;
    let iconUrl = 'assets/images/marker_red1.png';
    if (local.markerColor === 'orange') iconUrl = orangeMarkerSvg;
    else if (local.markerColor === 'blue') iconUrl = 'assets/images/marker_blue1.png';
    const iconObj = { url:iconUrl, scaledSize:new google.maps.Size(30,30) };
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon: iconObj
    });
    const botNote = local.is_botilleria
      ? `<span style="color:#e67e22;font-size:12px;font-weight:600;"><i class="fa fa-clock-o"></i> Abre a las ${local.horaApertura || '13:00'} hrs</span><br><br>`
      : '';
    const _snProg = local.nombre_local.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const _sdProg = (local.direccion||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:200px;"><strong>${local.nombre_local}</strong><br><br>${botNote}
       <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-bottom:5px" data-toggle="modal" data-target="#responsiveProg${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button>
       <button type="button" class="btn btn-warning btn-sm" style="width:100%" onclick="abrirModalReportarDir(${local.idLocal},'${_snProg}','${_sdProg}',${local.latitud},${local.lng})">
       <i class="fa fa-map-marker"></i> Reportar dirección</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    // originalIcon permite restaurar el ícono tras limpiar numeración
    window.markersProg[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta, markerColor: local.markerColor, originalIcon: iconObj };
  });

  // Marcadores Reagendados
  coordenadasReag.forEach(local=>{
    if (window.markersReag[local.idLocal]) return; // dedup por idLocal (ver nota en Programados)
    let iconUrl = (local.markerColor === 'orange') ? orangeMarkerSvg : 'assets/images/marker_red1.png';
    if (local.markerColor === 'blue') iconUrl = 'assets/images/marker_blue1.png';
    const iconObj = { url:iconUrl, scaledSize:new google.maps.Size(30,30) };
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon: iconObj
    });
    const botNote = local.is_botilleria
      ? `<span style="color:#e67e22;font-size:12px;font-weight:600;"><i class="fa fa-clock-o"></i> Abre a las ${local.horaApertura || '13:00'} hrs</span><br><br>`
      : '';
    const _snReag = local.nombre_local.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const _sdReag = (local.direccion||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:200px;"><strong>${local.nombre_local}</strong><br><br>${botNote}
       <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-bottom:5px" data-toggle="modal" data-target="#responsiveReag${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button>
       <button type="button" class="btn btn-warning btn-sm" style="width:100%" onclick="abrirModalReportarDir(${local.idLocal},'${_snReag}','${_sdReag}',${local.latitud},${local.lng})">
       <i class="fa fa-map-marker"></i> Reportar dirección</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    window.markersReag[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta, markerColor: local.markerColor, originalIcon: iconObj };
  });

  // Ubicación ejecutor + capa tráfico
  window.ejecutorMarker=new google.maps.Marker({
    position:{lat:-33.4489, lng:-70.6693}, map:window.mapa, title:'Tu Ubicación',
    icon:{ path:google.maps.SymbolPath.CIRCLE, scale:8, fillColor:'#4285F4', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 }
  });
  window.trafficLayer = new google.maps.TrafficLayer();

  // Geo + recálculo con umbral (solo cuando el modal está visible)
  const MIN_MOVE_METERS=60; let lastPos=null; let geoWatchId=null;
  function stopGeoWatch(){ if(geoWatchId!=null){ navigator.geolocation.clearWatch(geoWatchId); geoWatchId=null; } }
  function startGeoWatch(){
    if(geoWatchId!=null || !navigator.geolocation) return;
    geoWatchId = navigator.geolocation.watchPosition(pos=>{
      const cur=new google.maps.LatLng(pos.coords.latitude, pos.coords.longitude);
      const navegando = !!(window.navigator3D && window.navigator3D.active);
      // Durante la navegación el marcador lo anima NavCamera con la posición proyectada sobre la
      // vía e interpolada entre fixes; moverlo también desde acá con el fix crudo lo haría saltar.
      if(!navegando) window.ejecutorMarker.setPosition(cur);
      if(!lastPos || google.maps.geometry.spherical.computeDistanceBetween(lastPos, cur) > MIN_MOVE_METERS){
        lastPos=cur; const json=cur.toJSON();
        if (navegando) return;
        if (window.autoRecalc && isMapVisible) window.debouncedPlanRoute(json);
      }
    },err=>{ console.error(err); if(isMapVisible) mostrarToast('No se pudo obtener tu ubicación.', 'warn'); },
    { enableHighAccuracy:true, maximumAge:2000, timeout:10000 });
  }
  window.startGeoWatch=startGeoWatch; window.stopGeoWatch=stopGeoWatch;

  // Modal show
  $('#modalMapa').on('shown.bs.modal', function(){
    isMapVisible=true; startGeoWatch();
    google.maps.event.trigger(window.mapa, 'resize');
    ensureDateSelectedFor(window.modoLocal||'prog');
    // applyFilters() termina agendando su propio recálculo (debouncedPlanRoute). Si se deja
    // correr, al abrir el mapa se lanzan DOS cálculos de ruta: el suyo y el de acá. En rutas de
    // varias fechas eso significaba dos veces el modal de confirmación. Se suprime el de
    // applyFilters porque en seguida se pide uno explícito, inmediato y forzado.
    applyFilters({ skipPlan: true });
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{force:true, trigger:'modal_open'});
  });
  $('#modalMapa').on('hidden.bs.modal', function(){
    isMapVisible=false; stopGeoWatch();
    // Si el modal se cierra con ESC o con el backdrop mientras se navega, la clase de pantalla
    // completa quedaría pegada en <body> y descuadraría el resto de la página.
    if(window.setNavFullscreen) window.setNavFullscreen(false);
    // Barrido de backdrops sueltos: el modal de ruta cross-date se abre ENCIMA de este, y
    // cualquier desincronización entre ambos dejaba la capa oscura tapando la página.
    if(window.RouteSelection && RouteSelection.limpiarBackdropHuerfano) RouteSelection.limpiarBackdropHuerfano();
  });

  // Botones
  $('#btnActualizar').on('click', function(){ rememberMode('prog'); });
  $('#btnCentrar').on('click', ()=>{
    if(!navigator.geolocation) return;
    $('#loadingIndicator').show();
    navigator.geolocation.getCurrentPosition(p=>{
      const cur=new google.maps.LatLng(p.coords.latitude, p.coords.longitude);
      window.ejecutorMarker.setPosition(cur); window.mapa.setCenter(cur); window.mapa.setZoom(15);
      if(isMapVisible && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(cur.toJSON());
      $('#loadingIndicator').hide();
    }, ()=>{ $('#loadingIndicator').hide(); mostrarToast('No se pudo centrar tu ubicación.', 'warn'); }, { enableHighAccuracy:true, maximumAge:0, timeout:10000 });
  });
  $('#btnRecalcular').on('click', ()=>{
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{force:true, trigger:'manual_recalc'});
  });
  $('#btnIndicaciones').on('click', ()=> $('#drawerIndicaciones').toggleClass('open'));
  $('#btnCloseDrawer').on('click', ()=> $('#drawerIndicaciones').removeClass('open'));
  $('#btnTraffic').on('click', function(){
    const turningOn = !window.trafficEnabled;
    window.trafficEnabled = turningOn;
    // 1) Capa visual de tráfico de Google
    if (window.trafficLayer) window.trafficLayer.setMap(turningOn ? window.mapa : null);
    $(this).toggleClass('btn-info', turningOn);
    // 2) Recalcular en modo 'traffic' (TRAFFIC_AWARE + departureTime) — salvo navegando
    const pos = window.ejecutorMarker?.getPosition();
    if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) {
      window.planRouteFromSelection(pos.toJSON(), { force:true, trigger:'traffic_toggle' });
    }
    mostrarToast(turningOn ? 'Tráfico en vivo activado.' : 'Tráfico desactivado.', 'info');
  });
  $('#optimizeOrder').on('change', function(){ window.optimizeOrder=$(this).is(':checked'); const pos=window.ejecutorMarker?.getPosition(); if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{trigger:'optimize_toggle'}); });
  $('#autoRecalc').on('change', function(){ window.autoRecalc=$(this).is(':checked'); });
  // El repintado de ambos botones lo hace el listener de 'voice-enabled-changed'.
  // VoiceController.toggle() ya dice "Voz activada" DENTRO del click: iOS exige un gesto de
  // usuario para la primera locución, así que ese speak no se puede mover fuera del handler.
  $('#btnVoz').on('click', function(){
    if(window.VoiceController && typeof window.VoiceController.toggle === 'function'){
      window.VoiceController.toggle();
      return;
    }
    window.voiceEnabled = !window.voiceEnabled;
    $(this).toggleClass('btn-info', window.voiceEnabled);
    if (window.voiceEnabled) speak('Voz activada.'); else { try{ speechSynthesis.cancel(); }catch(_){} }
  });
  // ---- Export a Google Maps por bloques (respeta el límite de waypoints por URL) ----
  // El límite lo manda route_planner.js (EXPORT_MAX_WAYPOINTS_*); acá NO se repite el número,
  // que es como el aviso terminaba diciendo uno distinto del que usaban los enlaces.
  function detectExportEnv(){
    return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '') ? 'mobile' : 'desktop';
  }
  /** Paradas que entra en un enlace = waypoints intermedios + el destino. */
  function exportStopsPerLink(env){
    const RP = window.RoutePlanner || {};
    const wp = (env === 'mobile') ? RP.EXPORT_MAX_WAYPOINTS_MOBILE : RP.EXPORT_MAX_WAYPOINTS_DESKTOP;
    return (Number.isFinite(wp) ? wp : 9) + 1;
  }
  function buildExportLinks(){
    const pos = window.ejecutorMarker?.getPosition();
    if (!pos) return { error: 'No se pudo obtener tu ubicación.' };
    // Orden optimizado si existe; si no, recolectar desde la tabla. El GPS encabeza la secuencia.
    const pts = (window.lastComputedRoute?.orderedPts?.length ? window.lastComputedRoute.orderedPts : collectCurrentPoints());
    if (!pts.length) return { error: 'Selecciona al menos un local en la columna Ruta.' };
    const seq   = [pos.toJSON(), ...pts];
    const links = window.RoutePlanner.buildGoogleMapsExportChunks(seq, detectExportEnv());
    return { links };
  }
  $('#btnExportar').on('click', function(){
    const { links, error } = buildExportLinks();
    if (error){ mostrarToast(error, 'warn'); return; }
    if (!links || !links.length){ mostrarToast('No hay paradas para exportar.', 'warn'); return; }
    if (links.length === 1){ window.open(links[0].url, '_blank'); return; }
    // Varios bloques: mostrar los enlaces encadenados (no abrir muchas pestañas a la vez → el
    // navegador bloquearía los popups). Cada bloque continúa donde terminó el anterior.
    window._mapsExportLinks = links;
    const porEnlace = exportStopsPerLink(detectExportEnv());
    const $w = $('#routeWarning');
    if ($w.length){
      $w.html(
        `<i class="fa fa-external-link"></i> La ruta supera el límite de Google Maps (${porEnlace} paradas por enlace). ` +
        `Ábrela en <strong>${links.length} bloques</strong> encadenados:<br>` +
        links.map((l, i) =>
          `<button type="button" class="btn btn-xs ${i===0?'btn-success':'btn-default'} v2-maps-block" style="margin:2px" data-idx="${i}">` +
          `Bloque ${i+1}/${links.length} (${l.waypoints.length+1} paradas)</button>`
        ).join(' ')
      ).stop(true).show();
    } else {
      window.open(links[0].url, '_blank');
    }
  });
  $(document).on('click', '.v2-maps-block', function(){
    const idx = parseInt($(this).data('idx'), 10);
    const l = (window._mapsExportLinks || [])[idx];
    if (l && l.url) window.open(l.url, '_blank');
    $(this).removeClass('btn-success btn-default').addClass('btn-primary'); // marcar bloque abierto
  });

  // Modo inicial — fix #2: listeners de btnVer* movidos a document.ready (evita doble binding)
  $(document).on('change', 'table input.in-route', function(){
    const $tr=$(this).closest('tr'); const id=parseInt($tr.data('idlocal'),10); const modo=window.modoLocal;
    // Usar la fecha real de la fila (no el filtro activo) para soportar búsqueda cross-date
    const fecha = $tr.closest('table').data('fechatabla') || ((modo==='prog')?$('#filtroFechaProg').val():$('#filtroFechaReag').val());
    const key=`${modo}|${fecha}|${id}`;
    if ($(this).is(':checked')) window.excluded.delete(key); else window.excluded.add(key); saveExcluded(); updateCounts();
    const pos = window.ejecutorMarker?.getPosition(); if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON()); logRouteEvent({trigger:'checkbox'});
  });

 setMode(window.modoLocal || loadMode() || 'prog'); // arranca con modo recordado
  setTimeout(()=>$('#filtroFechaProg').trigger('change'), 200);
};

// ======= Navegación 3D compacta + HUD =======
(function(){
    let navigator3D=null; let navSteps=[]; let navLastPos=null;

    function escapeHtmlLite(s){ return String(s==null?'':s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // Etiqueta legible del local (código · cadena) a partir de su fila en la tabla.
    function localLabel(idLocal){
      const $tr = $(`tr[data-idlocal="${idLocal}"]`).first();
      if(!$tr.length) return null;
      const $tds = $tr.find('td');
      const codigo = $tds.eq(0).text().trim();
      const cadena = $tds.eq(1).clone().children().remove().end().text().trim(); // solo texto, sin iconos/badges
      return [codigo, cadena].filter(Boolean).join(' · ') || null;
    }

    function setChip($el, html, cls){
      if(!$el.length) return;
      $el.removeClass('nav-chip--ok nav-chip--warn nav-chip--bad');
      if(cls) $el.addClass(cls);
      $el.html(html);
    }

    // Actualiza solo los nodos de texto del HUD (sin reconstruir innerHTML por tick).
    function renderNavHud(){
      const nav = window.navigator3D;
      if(!nav || !nav.active) return;
      const U = NavEngine.utils;
      const step = nav.getCurrentStep();
      const next = nav.getNextStep();

      $('#navPrimary').text(step ? (step.text || 'Sigue la vía') : 'Calculando…');
      $('#navIcon').html(`<i class="fa ${step ? U.getManeuverIcon(step.maneuver) : 'fa-location-arrow'}"></i>`);

      // Distancia a la maniobra medida SOBRE LA VÍA. La versión vieja usaba la línea recta hasta
      // el fin del paso, que en una curva se queda corta por decenas de metros.
      // El typeof es la red de seguridad para el caso en que el service worker sirva un
      // nav_engine.js viejo junto a esta página nueva: se degrada en vez de reventar.
      let distStep = null;
      if(step){
        if(typeof nav.distToStepEnd === 'function') distStep = nav.distToStepEnd();
        else if(navLastPos) distStep = U.haversine(navLastPos, step.end);
      }
      $('#navSecondary').text(distStep === null ? '—' : U.formatDistance(distStep));

      if(next){ $('#navNextNext').show().text('Después: ' + (next.text || '')); }
      else { $('#navNextNext').hide(); }

      renderNavStats();

      if(typeof nav.getProgress === 'function'){
        $('#hudProgress').css('width', nav.getProgress().toFixed(1) + '%');
      }

      const wp = nav.waypoints && nav.waypoints[nav.waypointIdx];
      let stopTxt = 'Destino final';
      if(wp){ stopTxt = (wp.idLocal && localLabel(wp.idLocal)) || ('Parada ' + (nav.waypointIdx + 1) + '/' + nav.waypoints.length); }
      setChip($('#navNextStop'), `<i class="fa fa-flag-checkered"></i> ${escapeHtmlLite(stopTxt)}`, '');
    }
    window.renderNavHud = renderNavHud;

    /**
     * Panel de datos: llegada / distancia / tiempo a la PRÓXIMA parada.
     * Al ejecutor le sirve saber cuándo llega al local siguiente; el total de la jornada ya está
     * en el bottom sheet (#distanciaTotal / #duracionEstimada).
     * Se separa de renderNavHud porque además se repinta solo cada 15 s: si el vehículo está
     * detenido no llegan fixes, y sin esto la hora de llegada quedaría congelada en una hora
     * que ya pasó.
     */
    function renderNavStats(){
      const nav = window.navigator3D;
      if(!nav || !nav.active) return;
      const U = NavEngine.utils;

      let dist, secs;
      if(typeof nav.getStopMetrics === 'function'){
        const m = nav.getStopMetrics();
        dist = m.dist; secs = m.sec;
      }else{
        dist = nav.getRemainingDistance(); secs = nav.getRemainingDuration();
      }

      $('#hudEta').text(new Date(Date.now() + secs * 1000)
        .toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' }));
      $('#hudRemain').text(U.formatDistance(dist));
      $('#hudTime').text(U.formatDuration(secs));
    }
    let navStatsTimer = null;

    /**
     * Muestra/oculta el HUD, colapsa el bottom sheet y pasa el mapa a pantalla completa.
     * El fullscreen es por clase CSS en <body>, no con la Fullscreen API: esta última pelea con
     * los z-index de los controles de Google Maps y exige un gesto de usuario cada vez.
     * Tras cambiar el tamaño hay que avisarle al mapa o queda renderizado al tamaño viejo.
     */
    function showHud(show){
      $('#navHud').css('display', show ? 'block' : 'none');
      $('#panelInfoRuta').toggleClass('sheet-collapsed', !!show);
      setNavFullscreen(!!show);

      clearInterval(navStatsTimer); navStatsTimer = null;
      if(show){
        // Detenido no llegan fixes de GPS: sin este repintado la hora de llegada se congela.
        navStatsTimer = setInterval(renderNavStats, 15000);
      }
    }

    function setNavFullscreen(on){
      const yaEsta = $('body').hasClass('nav-fullscreen');
      if(yaEsta === !!on) return;
      $('body').toggleClass('nav-fullscreen', !!on);
      // 350 ms > la transición del modal; el mapa recalcula su viewport con el tamaño nuevo.
      setTimeout(()=>{
        if(window.mapa && window.google && google.maps && google.maps.event){
          google.maps.event.trigger(window.mapa, 'resize');
        }
      }, 350);
    }
    window.setNavFullscreen = setNavFullscreen;

    /**
     * Mantiene la pantalla encendida mientras se navega (solo dentro del APK Android).
     *
     * Con la pantalla apagada el WebView se congela: se detiene el watchPosition, no avanza el
     * paso siguiente y no se detecta el desvío. O sea, el ejecutor maneja con la app "abierta"
     * que dejó de seguirlo. En el navegador esto no hace nada.
     */
    function navKeepAwake(on){
      try{
        if(window.AndroidNav && typeof window.AndroidNav.keepAwake === 'function'){
          window.AndroidNav.keepAwake(!!on);
        }
      }catch(_){ /* sin puente nativo: navegador */ }
    }

    function ensureNav(){
      if(!navigator3D){
        navigator3D = new NavEngine.Navigator3D(window.mapa, {
          onRoute:(route, steps, isReroute)=>{
            window.plannedRoute = route; navSteps = steps||[];
            buildTrafficPolylines(window.mapa, route);
            renderIndicacionesFromRoute(route);
            renderNavHud();
            if(isReroute) speak('Ruta recalculada');
          },
          // `cur` ya viene proyectado sobre la vía por nav_engine: el punto azul no aparece
          // sobre la vereda ni dentro de una manzana aunque el GPS traiga error lateral.
          onPosition:(cur)=>{ navLastPos = cur; renderNavHud(); },
          // Sin voz acá: el único dueño de los anuncios es _announcePreventive del motor, que
          // sabe la distancia a la maniobra. Antes hablaban tres lugares a la vez y se pisaban.
          onStep:()=>{ renderNavHud(); },
          onGpsStatus:(status)=>{
            const $g = $('#navGps');
            if(status==='weak') setChip($g, '<i class="fa fa-exclamation-triangle"></i> GPS débil', 'nav-chip--warn');
            else setChip($g, '<i class="fa fa-location-arrow"></i> GPS OK', 'nav-chip--ok');
          },
          // cameraTracking de nav_engine es la única fuente: alterna el botón Recentrar.
          onCameraTrackingChanged:(tracking)=>{
            $('#btnRecenter').toggleClass('show', !tracking);
            if(window.NavCamera) NavCamera.setTracking(tracking);
          },
          onStop:()=>{
            showHud(false);
            $('#btnRecenter').removeClass('show');
            if(window.NavCamera) NavCamera.stop();
            navKeepAwake(false);   // devolver el control de la pantalla al teléfono
            window.navigator3D = navigator3D; // active=false tras stop
          },
          onArrival:()=>{ if(window.NavCamera) NavCamera.setTracking(false); },
          onError:(err)=>{ console.warn('[nav] error', err); },
          // Toda la cámara vive en NavCamera: interpola entre fixes para que el mapa no salte,
          // y decide sola si puede inclinar (mapa vectorial) o sólo recentrar (raster).
          onCamera:(cur, speed, heading)=>{ if(window.NavCamera) NavCamera.update(cur, speed, heading); }
        });
        window.navigator3D = navigator3D; // exponer para guards externos
      }
      return navigator3D;
    }

    $('#btnStartNav').on('click', async ()=>{
      if(!window.mapa){ mostrarToast('El mapa aún no está listo.', 'warn'); return; }
      const nav=ensureNav();
      const pts = (window.lastComputedRoute?.orderedPts?.length ? window.lastComputedRoute.orderedPts : collectCurrentPoints());
      const pos=window.ejecutorMarker?.getPosition();
      if(!pos || !pts.length){ mostrarToast('Necesitas al menos 1 parada y tu ubicación.', 'warn'); return; }
      const origin=pos.toJSON(); const destination=pts[pts.length-1]; const waypoints=pts.slice(0,-1);
      try{
        await nav.startFromSelection({ origin, destination, waypoints, optimize:window.optimizeOrder });
        navLastPos = origin;
        navKeepAwake(true);    // la pantalla no se apaga hasta salir de la navegación
        if(window.NavCamera) NavCamera.start(window.mapa, window.ejecutorMarker);
        showHud(true);
        $('#btnRecenter').removeClass('show');
        renderNavHud();
      }
      catch(_){ mostrarToast('No se pudo iniciar la navegación.', 'error'); }
    });
    $('#btnExitNav').on('click', ()=>{ const nav=ensureNav(); nav.stop(); });
    $('#btnRecenter').on('click', ()=>{ const nav=window.navigator3D; if(nav) nav.recenter(); $('#btnRecenter').removeClass('show'); });
    $('#btnVozNav').on('click', function(){
      if(window.VoiceController && typeof window.VoiceController.toggle === 'function'){
        window.VoiceController.toggle();   // el listener de voice-enabled-changed repinta ambos botones
        return;
      }
      window.voiceEnabled = !window.voiceEnabled;
      $(this).find('i').attr('class', window.voiceEnabled ? 'fa fa-volume-up' : 'fa fa-volume-off');
      $('#btnVoz').toggleClass('btn-info', window.voiceEnabled);
      if(window.voiceEnabled) speak('Voz activada.'); else { try{ speechSynthesis.cancel(); }catch(_){} }
    });
    $('#btnSkipStop').on('click', ()=>{ const nav=window.navigator3D; if(nav && nav.active){ nav.skipCurrentStop(); } });

    // ---- Eventos de navegación → HUD + mensajes no bloqueantes ----
    window.addEventListener('nav:rerouting', ()=>{ setChip($('#navNet'), '<i class="fa fa-refresh fa-spin"></i> Recalculando…', 'nav-chip--warn'); mostrarToast('Recalculando ruta…', 'info'); });
    window.addEventListener('nav:rerouted', ()=>{ setChip($('#navNet'), '<i class="fa fa-road"></i> Ruta lista', ''); renderNavHud(); });
    window.addEventListener('nav:off_route', ()=>{ mostrarToast('Te saliste de la ruta. Recalculando si continúas…', 'warn'); });
    window.addEventListener('nav:gps_weak', ()=>{ mostrarToast('GPS débil: la posición puede ser imprecisa.', 'warn'); });
    window.addEventListener('nav:gps_denied', (e)=>{ mostrarToast((e.detail && e.detail.message) || 'Activa la ubicación para navegar.', 'error', 6000); });
    window.addEventListener('nav:arrived_destination', ()=>{ mostrarToast('Has llegado a tu destino final.', 'success', 6000); });
    window.addEventListener('nav:arrived_waypoint', (e)=>{
      const wp = e.detail && e.detail.waypoint;
      const id = wp && wp.idLocal;
      const lbl = id ? (localLabel(id) || ('local ' + id)) : 'la parada';
      const actions = [];
      if(id){
        const modo = window.modoLocal || 'prog';
        const modalId = (modo==='prog' ? '#responsiveProg' : '#responsiveReag') + id;
        actions.push({ label:'Gestionar', cls:'btn-info', fn:()=>{
          // Salir de pantalla completa antes de abrir la gestión: Bootstrap 3 maneja un solo
          // backdrop y una sola clase modal-open, así que apilar sobre el mapa a pantalla
          // completa deja el scroll bloqueado o un backdrop huérfano.
          setNavFullscreen(false);
          $(modalId).modal('show');
        } });
      }
      actions.push({ label:'Saltar', cls:'btn-default', fn:()=>{ const nav=window.navigator3D; if(nav) nav.skipCurrentStop(); } });
      mostrarToast('Llegaste a ' + lbl + '.', 'success', 9000, actions);
    });

    // Recálculo fallido: antes sólo quedaba un console.error y el chip se quedaba en
    // "Recalculando…" para siempre. El motor reintenta con backoff (5s/15s/45s).
    window.addEventListener('nav:reroute_failed', (e)=>{
      const intento = (e.detail && e.detail.attempt) || 1;
      setChip($('#navNet'), '<i class="fa fa-exclamation-triangle"></i> Sin recalcular', 'nav-chip--bad');
      if(intento === 1){
        mostrarToast('No se pudo recalcular la ruta. Se reintentará solo; puedes seguir con la ruta actual.', 'warn', 8000,
          [{ label:'Reintentar', cls:'btn-info', fn:()=>{ const nav=window.navigator3D; if(nav && nav.active) nav._reroute(nav.lastPos); } }]);
      }
    });

    // Se recuperó la ruta tras un desvío.
    window.addEventListener('nav:on_route', ()=>{ setChip($('#navNet'), '<i class="fa fa-road"></i> En ruta', ''); });

    // Vista del mapa: 3D sólo si el mapa es vectorial (hay Map ID y el dispositivo soporta WebGL).
    window.addEventListener('nav:camera_mode', (e)=>{
      const tres = !!(e.detail && e.detail.is3D);
      setChip($('#navVista'), tres ? '<i class="fa fa-cube"></i> 3D' : '2D', tres ? 'nav-chip--ok' : '');
      $('#navVista').attr('title', tres
        ? 'Mapa vectorial: la vista se inclina y rota con el avance'
        : 'Mapa plano: falta configurar el Map ID vectorial (GOOGLE_MAPS_MAP_ID)');
    });
  })();


// ======= Bottom sheet del panel de ruta =======
(function(){
  const sheet  = document.getElementById('panelInfoRuta');
  const handle = document.getElementById('sheetHandle');
  if(!sheet || !handle) return;
  const SNAPS = ['sheet-peek','sheet-half','sheet-full'];
  function curSnap(){ const i = SNAPS.findIndex(c=>sheet.classList.contains(c)); return i<0 ? 1 : i; }
  function setSnap(i){ i=Math.max(0,Math.min(SNAPS.length-1,i)); SNAPS.forEach(c=>sheet.classList.remove(c)); sheet.classList.add(SNAPS[i]); }
  setSnap(1); // "media" por defecto
  let startY=null, moved=0;
  handle.addEventListener('pointerdown', e=>{ startY=e.clientY; moved=0; try{ handle.setPointerCapture(e.pointerId); }catch(_){} });
  handle.addEventListener('pointermove', e=>{ if(startY!=null) moved=e.clientY-startY; });
  function end(){
    if(startY==null) return;
    const d=moved; startY=null; const i=curSnap();
    if(d < -25)      setSnap(i+1);                 // arrastrar arriba → expandir
    else if(d > 25)  setSnap(i-1);                 // arrastrar abajo → contraer
    else             setSnap((i+1)%SNAPS.length);  // tap → ciclar peek/half/full
  }
  handle.addEventListener('pointerup', end);
  handle.addEventListener('pointercancel', ()=>{ startY=null; });
})();

/* =====================================================================
   PANEL DE CAMPAÑAS PROGRAMADAS — filtros y acciones masivas
   Los filtros (texto / división) son SOLO visuales: ocultan <li> del panel
   para ayudar a ENCONTRAR una campaña entre muchas. Lo que oculta locales
   de la tabla y del mapa sigue siendo el tachado (clase .completed), que es
   lo que lee applyFilters(). Mantener esa separación es lo que hace el
   comportamiento predecible: un solo mecanismo decide qué se visita.
   Solo aplica a programadas; el panel de complementarias queda igual.
   ===================================================================== */
const CAMPS_PREF_KEY = 'v2_camps_ocultas';

function normCampTxt(s){
  // Minusculas y sin tildes, para que "promocion" encuentre "PROMOCION".
  // NFD separa la letra de su tilde; luego se descartan las marcas
  // diacriticas por rango (U+0300..U+036F). Se filtra por codigo en vez de
  // usar un regex con esos caracteres para que el archivo quede 100% ASCII
  // y no dependa de la codificacion con que se suba por FTP.
  return String(s || '').toLowerCase().normalize('NFD')
    .split('')
    .filter(function (ch) {
      var c = ch.charCodeAt(0);
      return c < 0x0300 || c > 0x036F;
    })
    .join('');
}
function $campsProg(){ return $('li.camp-prog'); }
function $campsProgVisibles(){ return $('li.camp-prog:not(.camp-filtrada)'); }

function guardarCampsOcultas(){
  savePref(CAMPS_PREF_KEY,
    $campsProg().filter('.completed').map((i, li) => String($(li).data('idcampana'))).get());
}

function restaurarCampsOcultas(){
  const ids = loadPref(CAMPS_PREF_KEY, []);
  if (!Array.isArray(ids) || !ids.length) return;

  // Se descartan IDs de campañas que ya no están en la ruta, para que la
  // preferencia no acumule basura de campañas terminadas.
  const vivos   = new Set($campsProg().map((i, li) => String($(li).data('idcampana'))).get());
  const limpios = ids.map(String).filter(id => vivos.has(id));

  $campsProg().each(function(){
    if (!limpios.includes(String($(this).data('idcampana')))) return;
    $(this).addClass('completed')
           .find('i').first().removeClass('fa-square-o').addClass('fa-check-square-o');
  });
  if (limpios.length !== ids.length) savePref(CAMPS_PREF_KEY, limpios);
}

function actualizarContadorCamps(){
  const total    = $campsProg().length;
  const visibles = $campsProgVisibles().length;
  const ocultas  = $campsProg().filter('.completed').length;

  $('#txtOcultarCamps').text(visibles === total ? 'Ocultar todas' : ('Ocultar ' + visibles));

  let txt = (visibles === total) ? (total + ' campañas') : (visibles + ' de ' + total);
  if (ocultas > 0) txt += ' · ' + ocultas + ' sin mostrar';
  $('#campContador').text(txt);
}

function filtrarPanelCampanas(){
  const q   = normCampTxt($('#filtroCampanaTexto').val()).trim();
  const div = String($('#filtroCampanaDivision').val() || '');

  $campsProg().each(function(){
    const $li   = $(this);
    const okTxt = !q   || normCampTxt($li.data('busqueda')).includes(q);
    const okDiv = !div || String($li.data('division')) === div;
    $li.toggleClass('camp-filtrada', !(okTxt && okDiv));
  });

  $('#campSinResultados').remove();
  // Solo si hay campañas pero ninguna calza: si el ejecutor no tiene campañas,
  // ya se muestra "No hay campañas programadas" y sobraría este mensaje.
  if ($campsProg().length > 0 && $campsProgVisibles().length === 0) {
    $('#campanasCollapse ul.todo').append(
      '<li id="campSinResultados" class="camp-sin-resultados">Ninguna campaña coincide con el filtro.</li>');
  }
  actualizarContadorCamps();
}

/** Aplica o quita el tachado a un conjunto de campañas. */
function setTachadoCamps($lis, tachar){
  $lis.each(function(){
    const $i = $(this).find('i').first();
    $(this).toggleClass('completed', tachar);
    if (tachar) $i.removeClass('fa-square-o').addClass('fa-check-square-o');
    else        $i.removeClass('fa-check-square-o').addClass('fa-square-o');
  });
  guardarCampsOcultas();
  actualizarContadorCamps();
  applyFilters();   // una sola vez al final: dentro del bucle serían N pasadas
}

// ======= Wire-up básico =======
$(document).ready(function(){
  setTimeout(()=>$('#success-alert').fadeOut('slow'),3000);

  /* Aviso del cambio de acceso a RUT. El modal sólo existe en el HTML cuando
     corresponde mostrarlo, así que el largo del selector es toda la condición.
     Con retardo para que no compita con el render inicial de la ruta. */
  if ($('#modalAvisoRut').length) {
    setTimeout(function(){ $('#modalAvisoRut').modal('show'); }, 900);
  }

  $('#filtroFechaProg, #filtroFechaReag').off('change').on('change', function(){ applyFilters(); });
  $(document).on('click', '.todo-actions', function(){
    const $li = $(this).closest('li');
    // .find('i').first() sobre el propio <a>: antes se tomaban TODOS los <i> del
    // <li>, así que en campañas con "Recepción de materiales" el ícono de cubos
    // también recibía las clases de checkbox y se veía mal.
    const $i = $(this).find('i').first();
    $li.toggleClass('completed');
    $i.toggleClass('fa-square-o fa-check-square-o');
    if ($li.hasClass('camp-prog')) { guardarCampsOcultas(); actualizarContadorCamps(); }
    applyFilters();
  });

  // Panel de campañas programadas: restaurar preferencia + filtros + masivos
  restaurarCampsOcultas();
  $('#filtroCampanaTexto').on('input', debounce(filtrarPanelCampanas, 150));
  $('#filtroCampanaDivision').on('change', filtrarPanelCampanas);
  $('#btnOcultarCamps').on('click', function(){ setTachadoCamps($campsProgVisibles(), true); });
  $('#btnMostrarCamps').on('click', function(){ setTachadoCamps($campsProg(), false); });
  filtrarPanelCampanas();
  // Fix #2: listeners de modo solo aquí — eliminados los duplicados que estaban dentro de initMap()
  $('#btnVerReagendados').on('click', function(){ $('#filtroLocalesReag').val(''); setMode('reag'); });
  $('#btnVerProgramados').on('click', function(){ $('#filtroLocalesProg').val(''); setMode('prog'); });
  $('#modalMapa').on('show.bs.modal', function(){
    ensureMapReady().catch(()=>{
      mostrarToast('No se pudo cargar Google Maps. Reintentaremos cuando haya conexión.', 'error', 6000);
    });
  });
  // filtros de texto -> aplica filtros completos
  $('#filtroLocalesProg, #filtroLocalesReag').on('input', debounce(()=>applyFilters(),200));
  // inicia
  window.modoLocal='prog'; setTimeout(applyFilters, 500);
});


    
    

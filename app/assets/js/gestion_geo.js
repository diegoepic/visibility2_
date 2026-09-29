/**
 * Gestion: geolocalizacion obligatoria. Toma de posicion, frescura del fix, pintado del
 * indicador y las coordenadas que despues se sellan en cada foto.
 *
 * Extraido del <script> inline de gestionarPruebas.php (T6-6). Era lo ultimo que quedaba
 * con PHP dentro: una sola linea, window.USER_ID, que paso al bloque de configuracion.
 *
 * Declara initMap(), que es el callback con el que se carga la API de Google Maps al final
 * de la pagina. Como este script bloquea el parseo, queda definido mucho antes de que el
 * parser llegue a esa etiqueta, igual que cuando estaba inline.
 *
 * Declara tambien cachedPhotoCoords en el nivel superior: al ser un <script> clasico, ese
 * binding vive en el ambito lexico global y gestion_form.js lo sigue viendo.
 *
 * Precacheado en sw.js y pedido SIN ?v= (ver gestion_offline.js). Sin datos del usuario.
 */
/* === Geolocalización obligatoria === */
window.Geo = { ready: false, lastFixMs: 0, minFreshMs: 120000 };
let cachedPhotoCoords = { lat:'', lng:'' };

function setGeo(lat, lng){
  const latStr = Number(lat).toFixed(6), lngStr = Number(lng).toFixed(6);
  $('#latGestion').val(latStr);
  $('#lngGestion').val(lngStr);
  window.Geo.ready = true;
  window.Geo.lastFixMs = Date.now();
  cachedPhotoCoords.lat = latStr;
  cachedPhotoCoords.lng = lngStr;
  updateGeoUI();
}

function updateGeoUI(){
  const ok = !!(window.Geo.ready && $('#latGestion').val() && $('#lngGestion').val());
  const $badge = $('#geoStatusBadge');
  if ($badge.length){
    $badge.toggleClass('ok', ok).text(ok ? 'GPS activo' : 'GPS requerido');
  }
  $('#geoBlocker').toggle(!ok);
}

function showGeoError(err){
  let msg = 'No pudimos obtener tu ubicación.';
  if (err && err.code === 1) msg = 'Permiso de ubicación denegado. Actívalo para continuar.';
  else if (err && err.code === 2) msg = 'No se pudo determinar la posición (señal/servicio).';
  else if (err && err.code === 3) msg = 'Tiempo de espera agotado. Intenta de nuevo.';
  $('#geoErrorMsg').text(msg);
  updateGeoUI();
}

function getPositionOnce(opts={}){
  return new Promise((resolve, reject)=>{
    if (!navigator.geolocation) return reject({ code:'NO_GEO', message:'Geolocalización no soportada' });
    navigator.geolocation.getCurrentPosition(
      pos => resolve(pos),
      err => reject(err),
      Object.assign({ enableHighAccuracy:true, timeout:15000, maximumAge:0 }, opts)
    );
  });
}

async function ensureFreshGeo(){
  const now = Date.now();
  const hasRecent = window.Geo.ready &&
                    (now - window.Geo.lastFixMs < window.Geo.minFreshMs) &&
                    $('#latGestion').val() && $('#lngGestion').val();
  if (hasRecent){
    return { lat: parseFloat($('#latGestion').val()), lng: parseFloat($('#lngGestion').val()) };
  }
  const pos = await getPositionOnce({ maximumAge:0, timeout:15000, enableHighAccuracy:true });
  setGeo(pos.coords.latitude, pos.coords.longitude);
  return { lat: pos.coords.latitude, lng: pos.coords.longitude };
}

document.addEventListener('DOMContentLoaded', async ()=>{
  try{ await ensureFreshGeo(); }
  catch(err){ showGeoError(err); }
  finally{ updateGeoUI(); }

  document.getElementById('geoRetry')?.addEventListener('click', async ()=>{
    $('#geoErrorMsg').text('Intentando obtener ubicación…');
    try{ await ensureFreshGeo(); }
    catch(err){ showGeoError(err); }
  });
});

function initMap(){ /* no-op */ }
    
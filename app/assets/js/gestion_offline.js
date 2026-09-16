/**
 * Gestion: capa offline. Blobs en IndexedDB, sesion de visita, GUID de cliente,
 * claves de idempotencia, encolado de create_visita y los avisos al ejecutor.
 *
 * Extraido del <script> inline de gestionarPruebas.php (T6-6): 438 lineas, 16 KB que se
 * volvian a bajar cada vez que el ejecutor abria una gestion (la mediana son 13 al dia).
 *
 * Todo lo que PHP le tiene que contar a este archivo viaja en window.__GESTION, que se
 * declara en el <head> de gestionarPruebas.php. No hay ningun otro acoplamiento.
 *
 * Va PRECACHEADO en sw.js y por eso se pide SIN ?v=: cacheFirst() hace match sin
 * ignoreSearch, asi que con query string no encontraria la entrada precacheada y sin red
 * la pagina de gestion se quedaria sin funcionar. El cache-busting lo da el nombre del
 * cache (static-VERSION). Este archivo no lleva ningun dato del usuario.
 *
 * Se carga ANTES de gestion_form.js, que usa V2Blobs, pendingUploads y VISIT_SESSION_KEY.
 * El orden entre <script> clasicos se respeta aunque sean externos.
 */
function normalizeAppUrl(u) {
  if (!u) return '';
  if (/^https?:\/\//i.test(u)) return u;                   // ya es absoluta con dominio
  u = String(u).replace(/\\/g,'/').replace(/\/+/g,'/');    // colapsa //
  if (u.startsWith('/visibility2/app/uploads/')) return u; // absoluta bajo app
  if (u.startsWith('/uploads/')) return '/visibility2/app' + u;
  if (u.startsWith('uploads/')) return '/visibility2/app/' + u;
  return u;
}

/* ============================================================================
   CICLO DE VIDA DE LAS FOTOS EN MEMORIA
   ----------------------------------------------------------------------------
   URL.createObjectURL() no crea una "ruta a la foto": deja la imagen COMPLETA
   retenida en memoria hasta que alguien llame a revokeObjectURL(). No basta con
   quitar el <img> del DOM ni con cambiarle el src — el navegador no puede saber
   que ya nadie la va a pedir.

   En esta página cada foto pasa por compressFile() y termina en un blob de
   ~150-400 KB. Una jornada normal son 20 locales con varias fotos cada uno, y
   antes ninguna se liberaba al reemplazar la miniatura por la URL del servidor:
   quedaban todas vivas hasta recargar la página. En un teléfono de gama baja eso
   es lo que termina matando el proceso del WebView a media tarde.

   Todas las fotos de esta página se crean y se liberan por acá, para que exista
   un solo lugar donde mirar cuando algo quede retenido. V2Blobs.vivas() dice
   cuántas hay en pie en cualquier momento (útil desde la consola).
   ========================================================================== */
const V2Blobs = (function () {
  const vivos = new Set();

  function crear(blob) {
    const url = URL.createObjectURL(blob);
    vivos.add(url);
    return url;
  }

  /** Libera una URL concreta. Es idempotente: llamarla dos veces no rompe nada. */
  function liberar(url) {
    if (!url || !vivos.has(url)) return;
    try { URL.revokeObjectURL(url); } catch (_) {}
    vivos.delete(url);
  }

  /**
   * Cambia la miniatura a la URL definitiva del servidor y suelta el blob.
   * Es el momento exacto en que la foto deja de necesitar estar en memoria: ya
   * está subida, y el service worker cachea /uploads/ para verla sin conexión.
   */
  function reemplazarPorUrlServidor(img, urlServidor) {
    if (!img) return;
    const anterior = img.src;
    img.src = normalizeAppUrl(urlServidor);
    liberar(anterior);
  }

  function liberarTodo() {
    vivos.forEach(function (url) { try { URL.revokeObjectURL(url); } catch (_) {} });
    vivos.clear();
  }

  return { crear, liberar, reemplazarPorUrlServidor, liberarTodo, vivas: () => vivos.size };
})();
window.V2Blobs = V2Blobs;

/* Red de seguridad al salir de la página. 'pagehide' y no 'unload' porque es el
   único que dispara de forma confiable en móvil cuando el sistema descarta la
   pestaña; igual el navegador libera al destruir el documento, pero esto ayuda
   cuando el WebView reutiliza el proceso para la página siguiente.

   OJO con e.persisted: significa que la página se fue al bfcache y PUEDE volver
   con el botón atrás. Si se liberan los blobs ahí, el ejecutor vuelve a una
   gestión con todas las miniaturas rotas. */
window.addEventListener('pagehide', function (e) {
  if (e && e.persisted) return;
  V2Blobs.liberarTodo();
});

const START_VISITA_TIMEOUT_MS = 8000;
const MAX_FOTOS_POR_MATERIAL = 10;    
let pendingUploads = 0;

function updateFinalizeButton() {
  const $btn = $('#btnFinalizar');
  if (!$btn.length) return;
  if (pendingUploads > 0) {
    $btn.prop('disabled', true).text('Subiendo fotos…');
  } else {
    $btn.prop('disabled', false).text('Finalizar Gestión');
  }
}

function mcConfirm({ title="Confirmar", message="¿Seguro?", confirmText="Aceptar", confirmClass="btn-primary" }={}) {
  return new Promise(resolve => {
    const $modal = $('#mcConfirmModal');
    $modal.find('#mcConfirmTitle').text(title);
    $modal.find('#mcConfirmMessage').html(message);
    const $ok = $modal.find('#mcConfirmOk');
    $ok.text(confirmText).attr('class', 'btn ' + confirmClass);

    let handled = false;
    const done = (val) => { if (!handled) { handled = true; resolve(val); $modal.modal('hide'); } };

    $ok.off('click').on('click', () => done(true));
    $modal.off('hidden.bs.modal').on('hidden.bs.modal', () => done(false));
    $modal.modal('show');
  });
}

function mcToast(type, title, msg, timeout=3000) {
  const $wrap = $('#mcToasts');
  const $t = $(`
    <div class="alert alert-${type} mc-toast" role="alert">
      <button type="button" class="close" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      <strong>${title}</strong>${msg ? '<div style="margin-top:4px;">'+msg+'</div>' : ''}
    </div>
  `);
  $t.find('.close').on('click', () => $t.fadeOut(200, () => $t.remove()));
  $wrap.append($t);
  setTimeout(() => $t.fadeOut(300, () => $t.remove()), timeout);
}

function mcAlert(title, message, { type = 'warning', okText = 'Aceptar' } = {}) {
  return new Promise(resolve => {
    const $modal = $('#mcConfirmModal');
    $modal.find('#mcConfirmTitle').text(title);
    $modal.find('#mcConfirmMessage').html(message);
    const $ok     = $modal.find('#mcConfirmOk');
    const $cancel = $modal.find('#mcConfirmCancel');
    const btnClass = type === 'danger' ? 'btn-danger' : type === 'success' ? 'btn-success' : 'btn-warning';
    $ok.text(okText).attr('class', 'btn ' + btnClass);
    $cancel.hide();
    let handled = false;
    const done = () => { if (!handled) { handled = true; $cancel.show(); resolve(); $modal.modal('hide'); } };
    $ok.off('click').on('click', done);
    $modal.off('hidden.bs.modal').on('hidden.bs.modal', done);
    $modal.modal('show');
  });
}

function resetFileInput(el) {
  if (!el) return;
  try {
    el.value = '';
    if (el.value) { el.type = ''; el.type = 'file'; }
  } catch (e) {
    const clone = el.cloneNode(true);
    el.parentNode.replaceChild(clone, el);
  }
}

async function isReallyOnline() {
  if (!navigator.onLine) return false;
  try {
    const r = await fetch('/visibility2/app/ping.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    if (!r.ok) return false;
    const js = await r.json().catch(()=> ({}));
    if (js && js.csrf_token) {
      window.CSRF_TOKEN = js.csrf_token;
      const hidden = document.querySelector('input[name="csrf_token"]');
      if (hidden) hidden.value = js.csrf_token;
    }
    return true;
  } catch {
    return false;
  }
}

// Anuncio: este local quedó habilitado para trabajar offline en esta sesión
(function showOfflineReadyHint(){
  try {
    const key = `offline_ready_${window.__GESTION.idLocal}_${window.__GESTION.idCampana}`;
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');

    const localName    = window.__GESTION.nombreLocal;
    const campaignName = window.__GESTION.nombreCampana;
    mcToast(
      'info',
      'Modo offline listo',
      `Este local quedó marcado para seguir gestionando sin conexión. Si pierdes internet, las fotos y formularios quedarán en cola y se sincronizarán al volver la red.`,
      3000
    );
  } catch(_){ /* silencioso */ }
})();

function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c=>{
    const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}
function makeIdempKey() { return 'idemp-' + Date.now() + '-' + Math.random().toString(16).slice(2); }
function clientGuidKey() {
  const uid  = (window.USER_ID || window.__GESTION.usuarioId);
  const form = window.__GESTION.idCampana;
  const loc  = window.__GESTION.idLocal;
  return `vguid:${uid}:${form}:${loc}`;
}

const VISIT_SESSION_KEY = `visit_session:${window.__GESTION.usuarioId}:${window.__GESTION.idLocal}:${window.__GESTION.idCampana}`;
function readVisitSession(){
  try { return JSON.parse(localStorage.getItem(VISIT_SESSION_KEY) || '{}'); }
  catch(_) { return {}; }
}
function persistVisitSession(data){
  try { localStorage.setItem(VISIT_SESSION_KEY, JSON.stringify(data||{})); }
  catch(_){ /* noop */ }
}
function clearVisitSession(){
  persistVisitSession({});
  const h = document.getElementById('client_guid');
  if (h) h.value = '';
  const v = document.getElementById('visita_id');
  if (v) v.value = '';
  localStorage.removeItem(clientGuidKey());
}
// Compatibilidad hacia atrás: algunos flujos aún invocan clearClientGuid()
// luego de encolar o enviar la gestión. Mantenerlo evita ReferenceError
// cuando se trabaja 100% offline y el enqueue falla.
function clearClientGuid(){
  clearVisitSession();
}
function setClientGuid(g) {
  const h = document.getElementById('client_guid');
  if (h) h.value = g;
}
function ensureVisitSession(){
  const session = readVisitSession();
  if (!session.client_guid) {
    session.client_guid = uuidv4();
    session.createdAt = Date.now();
  }
  persistVisitSession(session);
  localStorage.setItem(clientGuidKey(), session.client_guid);
  setClientGuid(session.client_guid);
  if (session.visita_id) {
    const v = document.getElementById('visita_id');
    if (v && !v.value) v.value = session.visita_id;
  }
  return session;
}
function updateVisitSession(patch){
  const sess = Object.assign({}, ensureVisitSession(), patch || {});
  persistVisitSession(sess);
  if (sess.client_guid) setClientGuid(sess.client_guid);
  if (sess.visita_id) {
    const v = document.getElementById('visita_id');
    if (v) v.value = sess.visita_id;
  }
  return sess;
}
function ensureClientGuid() {
  const sess = ensureVisitSession();
  return sess.client_guid;
}
function getCSRF() {
  return document.querySelector('input[name="csrf_token"]')?.value || '';
}
function loadCompletedDeps(){
  try { return JSON.parse(localStorage.getItem('v2_completed_deps') || '[]'); }
  catch(_) { return []; }
}
function isCreateVisitaResolved(guid){
  if (!guid) return false;
  return loadCompletedDeps().includes(`create:${guid}`);
}
async function hasPendingCreateVisita(guid){
  if (!guid) return false;

  let jobs = [];
  try {
    if (window.Queue) {
      if (typeof window.Queue.listPending === "function") {
        const pending = await window.Queue.listPending();
        if (Array.isArray(pending)) jobs = jobs.concat(pending);
      }
      if (typeof window.Queue.listRunning === "function") {
        const running = await window.Queue.listRunning();
        if (Array.isArray(running)) jobs = jobs.concat(running);
      }
    }
    if (!jobs.length && window.AppDB && typeof window.AppDB.listByStatus === "function") {
      const lists = await Promise.all([
        window.AppDB.listByStatus('queued'),
        window.AppDB.listByStatus('running'),
        window.AppDB.listByStatus('blocked_auth'),
        window.AppDB.listByStatus('blocked_csrf')
      ]);
      jobs = [].concat(...lists);
    }
  } catch(_) {
    jobs = [];
  }

  return Array.isArray(jobs) && jobs.some(t => {
    if (!t) return false;
    const cg = t.client_guid || (t.fields && t.fields.client_guid);
    return t.type === 'create_visita' && cg && cg === guid;
  });
}
function resolveVisitaIdFromMappings(guid){
  if (!guid) return null;
  try {
    if (window.Queue && window.Queue.LocalByGuid) {
      const mapped = window.Queue.LocalByGuid.getSync(guid);
      if (mapped) return mapped;
    }
  } catch(_){}
  return null;
}
async function ensureRealVisitaId(reason=''){ // reason para debugging
  const session = ensureVisitSession();
  const guid = session.client_guid;
  const visitaInput = document.getElementById('visita_id');
  const currentVal = (visitaInput?.value || '').trim();
  if (currentVal && !currentVal.startsWith('local-')) {
    updateVisitSession({ visita_id: currentVal });
    return currentVal;
  }

  const mapped = resolveVisitaIdFromMappings(guid);
  if (mapped && !String(mapped).startsWith('local-')) {
    updateVisitSession({ visita_id: mapped });
    return mapped;
  }

  try {
    if (await hasPendingCreateVisita(guid) && window.Queue && typeof window.Queue.flushNow === 'function') {
      await window.Queue.flushNow();
      const mappedAfter = resolveVisitaIdFromMappings(guid);
      if (mappedAfter && !String(mappedAfter).startsWith('local-')) {
        updateVisitSession({ visita_id: mappedAfter });
        return mappedAfter;
      }
    }
  } catch(_){ /* noop */ }

  // Último recurso: intentar reusar/crear visita online para obtener visita_id real
  try {
    const params = new URLSearchParams();
    params.append('id_formulario', String(window.__GESTION.idCampana));
    params.append('id_local',      String(window.__GESTION.idLocal));
    params.append('client_guid',   String(guid || ''));
    params.append('csrf_token',    getCSRF());
    if (session.startPayload) {
      if (session.startPayload.lat) params.append('lat', String(session.startPayload.lat));
      if (session.startPayload.lng) params.append('lng', String(session.startPayload.lng));
    }
    const r = await fetch('/visibility2/app/create_visita_pruebas.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-Token': getCSRF() },
      body: params.toString()
    });
    const js = await r.json().catch(() => ({}));
    if (r.ok && js && js.visita_id) {
      updateVisitSession({ visita_id: js.visita_id, client_guid: js.client_guid || guid });
      return js.visita_id;
    }
  } catch(_){ /* noop */ }

  return null;
}
const QUEUE_META_BASE = {
  local_name: window.__GESTION.nombreLocal,
  local_direccion: window.__GESTION.direccionLocal,
  campaign_name: window.__GESTION.nombreCampana,
  local_id: window.__GESTION.idLocal
};
async function queueCreateVisita(payload, idempKey) {
  const session = ensureVisitSession();
  const visitaLocal = session.visita_local_id || ('local-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now()));

  updateVisitSession({
    visita_local_id: visitaLocal,
    startPayload: {
      lat: String(payload.lat || ''),
      lng: String(payload.lng || '')
    }
  });

  document.getElementById('visita_id').value = visitaLocal;

  const cleanPayload = {
    id_formulario: Number(payload.id_formulario || 0),
    id_local: Number(payload.id_local || 0),
    lat: String(payload.lat || ''),
    lng: String(payload.lng || ''),
    client_guid: String(payload.client_guid || ''),
    visita_local_id: String(visitaLocal),
    csrf_token: String(getCSRF() || '')
  };

  const cleanMeta = {
    local_name: String(QUEUE_META_BASE.local_name || ''),
    local_direccion: String(QUEUE_META_BASE.local_direccion || ''),
    campaign_name: String(QUEUE_META_BASE.campaign_name || ''),
    local_id: Number(QUEUE_META_BASE.local_id || 0)
  };

  // IMPORTANT: No usar smartPost/enqueueJSON aquí porque reintentan online y añaden
  // hasta 32 segundos extra de espera con timeouts encadenados (heartbeat 6s + CSRF 6s +
  // processTask 20s), dejando el botón bloqueado en "Iniciando visita…" durante ~40s.
  // Encolamos directamente en IDB; drain() intentará enviar en background sin bloquear UI.
  const task = {
    url: '/visibility2/app/create_visita_pruebas.php',
    type: 'create_visita',
    fields: cleanPayload,
    sendCSRF: true,
    id: String(idempKey || (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()))),
    dedupeKey: cleanPayload.client_guid ? `create_visita:${cleanPayload.client_guid}` : undefined,
    client_guid: String(cleanPayload.client_guid || ''),
    meta: cleanMeta
  };
  return window.Queue.enqueue(task);
}
async function queueProcesarGestion(formEl, meta={}) {
  const cg = document.getElementById('client_guid').value || ensureClientGuid();
  // Asegura que el hidden exista con valor
  const h = formEl.querySelector('#client_guid');
  if (h) h.value = cg; else {
    const hid = document.createElement('input');
    hid.type = 'hidden'; hid.name = 'client_guid'; hid.id = 'client_guid'; hid.value = cg;
    formEl.appendChild(hid);
  }
  return OfflineQueue.enqueueForm('/visibility2/app/procesar_gestion_pruebas.php', formEl, {
    type: 'procesar_gestion',
    client_guid: cg,
    dependsOn: `create:${cg}`,
    timeoutMs: 120000,
    meta: Object.assign({}, QUEUE_META_BASE, meta)
  });
}
    
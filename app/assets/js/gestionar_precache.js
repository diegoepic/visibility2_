// Precarga de páginas gestionarPruebas por URL exacta (sin SPA).
(() => {
  const APP_SCOPE   = '/visibility2/app';
  const GEST_PATH   = `${APP_SCOPE}/gestionarPruebas.php`;
  const API_SYNC    = `${APP_SCOPE}/api/sync_bundle.php`;
  const MAX_PAGES   = 3;   // cuántos gestionar extra intentamos precachear
  const DAYS_BACK   = 0;   // ventana hacia atrás
  const DAYS_FWD    = 1;   // ventana hacia adelante
  const EVICT_ON_LEAVE = true; // si true: al salir de la página, pedimos eliminar esta URL del cache

  function paramsFromLocation() {
    const u  = new URL(location.href);
    const qs = u.searchParams;
    return {
      idCampana:  Number(qs.get('idCampana') || 0),
      idLocal:    Number(qs.get('idLocal')   || 0),
      nombreCamp: qs.get('nombreCampana') || ''
    };
  }

  function ymd(d){ return d.toISOString().slice(0,10); }

  async function ensureController() {
    // Si ya hay controller, ok
    if (navigator.serviceWorker?.controller) return;
    // Intentar registrar si no hay
    try {
      await navigator.serviceWorker.register(`${APP_SCOPE}/sw.js`, { scope: APP_SCOPE + '/' });
      await navigator.serviceWorker.ready;
    } catch (_) { /* silent */ }
  }

  function buildGestionarUrl(formId, formName, localId) {
    const n = encodeURIComponent(formName || '');
    return `${GEST_PATH}?idCampana=${formId}&nombreCampana=${n}&idLocal=${localId}`;
  }

  async function fetchAgendaBundle(from, to) {
    const u = new URL(API_SYNC, location.origin);
    u.searchParams.set('from', from);
    u.searchParams.set('to',   to);
    // Nota: api/sync_bundle.php exige sesión; aquí vamos con credenciales
    const resp = await fetch(u.toString(), { credentials: 'include' });
    if (!resp.ok) throw new Error('sync_bundle failed');
    return resp.json();
  }

  function pickNeighbors(bundle, currentForm, currentLocal, currentName) {
    const urls = [];
    // bundle.agenda puede venir como objeto { key -> item } o arreglo; normalizamos:
    const raw = bundle.agenda || bundle.route || [];
    const list = Array.isArray(raw) ? raw : Object.values(raw || {});
    // Heurística: tomar entradas de la misma campaña (form == currentForm), distintos locales.
    for (const it of list) {
      const form  = Number(it.form || it.id_formulario || 0);
      const local = Number(it.local || it.id_local || 0);
      if (!form || !local) continue;
      if (form !== currentForm) continue;
      if (local === currentLocal) continue;
      urls.push(buildGestionarUrl(form, currentName, local));
      if (urls.length >= MAX_PAGES) break;
    }
    return urls;
  }

  async function precacheNeighbors(opts = {}) {
    const ctx = paramsFromLocation();
    if (!ctx.idCampana || !ctx.idLocal) return;

    // Asegurar que el SW está controlando esta página
    await ensureController();
    if (!navigator.serviceWorker?.controller) return;

    // Ventana de fechas
    const now  = new Date();
    const from = new Date(now); from.setDate(now.getDate() - DAYS_BACK);
    const to   = new Date(now); to.setDate(now.getDate() + DAYS_FWD);

    let bundle;
    try {
      bundle = await fetchAgendaBundle(ymd(from), ymd(to));
    } catch (_) {
      return; // sin red/no bundle: no pasa nada
    }

    const urls = pickNeighbors(bundle, ctx.idCampana, ctx.idLocal, ctx.nombreCamp);
    if (!urls.length) return;

    try {
      navigator.serviceWorker.controller.postMessage({
        type: 'PRECACHE_GESTIONAR_PAGES',
        urls,
        max: MAX_PAGES
      });
    } catch (_) { /* silent */ }
  }

  function setupEvictionOnLeave() {
    if (!EVICT_ON_LEAVE) return;
    const thisUrl = location.href;
    const evict = () => {
      try {
        if (navigator.serviceWorker?.controller) {
          navigator.serviceWorker.controller.postMessage({
            type: 'EVICT_GESTIONAR',
            urls: [thisUrl]
          });
        }
      } catch (_) {}
    };
    // Evict cuando se cierra/abandona (no bloquea navegación)
    addEventListener('pagehide', evict);
    addEventListener('beforeunload', evict);
  }

  // API pública mínima por si quieres llamarlo manualmente
  window.V2GestionarPrecache = {
    bootstrap: async () => {
      try { await precacheNeighbors(); } catch (_) {}
      setupEvictionOnLeave();
    },
    precacheNeighbors
  };
})();
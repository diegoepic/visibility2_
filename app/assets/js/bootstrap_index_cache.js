/* /visibility2/app/assets/js/bootstrap_index_cache.js
 * Arranque del Index (modo online/offline):
 * - Rehidrata desde IndexedDB (V2Cache) para mostrar "Programados/Reagendados" del día.
 * - Sincroniza bundle con If-None-Match (ETag) contra api/sync_bundle.php.
 * - Registra y activa el Service Worker; le envía assets GET del bundle para precache.
 * - Actualiza badges de red y "last sync" si existen en el DOM.
 * Requiere: window.V2Cache (v2_cache.js) ya cargado.
 */

(() => {
  'use strict';

  const APP_SCOPE        = '/visibility2/app';
  const SW_PATH          = `${APP_SCOPE}/sw.js`;
  const ETAG_KEY         = 'v2_bundle_etag';
  const LASTSYNC_KEY     = 'v2_last_sync_iso';
  const AGENDA_FLAG_KEY  = 'v2_agenda_needs_refresh';

  const $ = (sel) => document.querySelector(sel);

  function todayYMD() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
  }

  function agendaNeedsRefresh() {
    try {
      return localStorage.getItem(AGENDA_FLAG_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function clearAgendaRefreshFlag() {
    try {
      localStorage.removeItem(AGENDA_FLAG_KEY);
    } catch (_) {}
  }

  function setLastSync(iso) {
    try {
      localStorage.setItem(LASTSYNC_KEY, iso);
      const node = $('#last-sync, #lastSync, [data-last-sync]');
      if (node) node.textContent = new Date(iso).toLocaleString();
    } catch {}
  }

  function updateNetworkBadge() {
    const el = $('#badge-network, .badge-network, [data-badge-network]');
    if (!el) return;
    const online = navigator.onLine;
    el.textContent = online ? 'Online' : 'Offline';
    el.classList.toggle('bg-success', online);
    el.classList.toggle('bg-secondary', !online);
  }
  window.addEventListener('online',  updateNetworkBadge);
  window.addEventListener('offline', updateNetworkBadge);

  async function registerSW() {
    if (!('serviceWorker' in navigator)) return null;
    try {
      const reg = await navigator.serviceWorker.register(SW_PATH);
      const sw = reg.installing || reg.waiting || reg.active;
      if (sw) {
        sw.postMessage({ type: 'SKIP_WAITING' });
        sw.postMessage({ type: 'CLIENTS_CLAIM' });
      }
      reg.addEventListener('updatefound', () => {
        const nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', () => {
          if (nw.state === 'installed') {
            (reg.waiting || reg.active)?.postMessage({ type: 'SKIP_WAITING' });
            (reg.waiting || reg.active)?.postMessage({ type: 'CLIENTS_CLAIM' });
          }
        });
      });
      return reg;
    } catch (e) {
      console.warn('[SW] register failed', e);
      return null;
    }
  }

  async function ensureSWActiveAndPrecache(bundle) {
    const reg = await registerSW();
    if (!reg || !bundle) return;
    if (Array.isArray(bundle.assets) && bundle.assets.length) {
      const urls = bundle.assets
        .map(a => a && a.url)
        .filter(u => typeof u === 'string' && u.startsWith(APP_SCOPE));
      (reg.active || reg.waiting || reg.installing)?.postMessage({
        type: 'PRECACHE_ASSETS',
        assets: urls
      });
    }
  }

  async function fetchBundle(fromYMD, toYMD) {
    const prev = (localStorage.getItem(ETAG_KEY) || '').trim();
    const url  = `${APP_SCOPE}/api/sync_bundle.php?from=${encodeURIComponent(fromYMD)}&to=${encodeURIComponent(toYMD)}&reagendados_days=7`;
    const headers = {};
    if (prev) headers['If-None-Match'] = prev;

    const res = await fetch(url, { credentials: 'include', headers });
    if (res.status === 304) return { bundle: null, etag: prev };
    if (!res.ok) throw new Error(`sync_bundle ${res.status}`);
    const etag = res.headers.get('ETag') || '';
    const data = await res.json();
    if (etag) localStorage.setItem(ETAG_KEY, etag);
    setLastSync(new Date().toISOString());
    return { bundle: data, etag: etag || '' };
  }

  // ---- NUEVO: leer del Journal qué locales ya tienen gestión (procesar_gestion) para ese día ----
  async function getLocalsWithGestionForDay(ymd) {
    const result = {
      locals: new Set(), // locales con alguna gestion
      pairs:  new Set()  // local|form para ser más precisos cuando podamos
    };

    try {
      if (!window.JournalDB || typeof JournalDB.listByYMD !== 'function') {
        return result;
      }

      const rows = await JournalDB.listByYMD(ymd);
      for (const r of rows) {
        const kind = (r.kind || '').toLowerCase();
        // Sólo nos interesan las tareas de cierre de gestión
        if (!kind.includes('procesar_gestion')) continue;

        const lid = r.local_id || r.visita_local_id;
        if (!lid) continue;
        const localKey = String(lid);
        result.locals.add(localKey);

        if (r.form_id) {
          const pairKey = localKey + '|' + String(r.form_id);
          result.pairs.add(pairKey);
        }
      }
    } catch (e) {
      console.warn('[Index] getLocalsWithGestionForDay error', e);
    }

    return result;
  }

  async function renderTodayFromCache(ymd) {
    try {
      const empty = { items: [], programados: [], reagendados: [] };
      if (!window.V2Cache || typeof V2Cache.listToday !== 'function') return empty;

      const rawItems = await V2Cache.listToday(ymd) || [];

      // ---- NUEVO: filtrar locales que ya tienen una gestión (offline u online) registrada en el Journal ----
      let items = rawItems;
      const gestionInfo = await getLocalsWithGestionForDay(ymd);

      if (gestionInfo.locals.size || gestionInfo.pairs.size) {
        items = rawItems.filter(row => {
          const local = row.local || {};
          const camp  = row.camp  || {};

          const localId =
            local.id_local != null ? local.id_local :
            local.id       != null ? local.id       :
            null;

          // intentamos detectar id del formulario/campaña si viene
          const formId =
            camp.id_formulario != null ? camp.id_formulario :
            camp.id_campana    != null ? camp.id_campana    :
            camp.id            != null ? camp.id            :
            null;

          if (!localId) return true;

          const localKey = String(localId);
          const pairKey  = formId != null ? (localKey + '|' + String(formId)) : null;

          // Si tenemos match exacto local+form, lo ocultamos
          if (pairKey && gestionInfo.pairs.has(pairKey)) return false;

          // Si sólo sabemos que el local ya tiene gestión, también lo ocultamos
          if (gestionInfo.locals.has(localKey)) return false;

          return true;
        });
      }

      const programados = items.filter(x => !x.reagendado);
      const reagendados = items.filter(x => !!x.reagendado);

      // UI nueva (IndexUI) si existe
      if (window.IndexUI && typeof IndexUI.renderDay === 'function') {
        IndexUI.renderDay(ymd, items);
        return { items, programados, reagendados };
      }
      // UI legacy
      if (typeof window.renderProgramadosDeHoy === 'function') {
        window.renderProgramadosDeHoy(items);
        return { items, programados, reagendados };
      }

      const boxProg = document.querySelector('#programados, #programados-list, [data-programados]');
      const boxReag = document.querySelector('#reagendados, #reagendados-list, [data-reagendados]');
      if (!boxProg && !boxReag) return { items, programados, reagendados };
      const card = (row) => {
        const local = row.local || {};
        const camp  = row.camp  || {};
        const name  = local.nombre || `Local #${local.id_local || local.id || ''}`;
        const addr  = local.direccion || '';
        const comuna= local.comuna || '';
        const campN = camp.nombre || '';
        const pill  = row.reagendado ? '<span class="badge bg-warning ms-2">Reagendado</span>' : '';
        return `
          <div class="card mb-2 shadow-sm">
            <div class="card-body p-2">
              <div class="d-flex justify-content-between align-items-center">
                <strong>${name}</strong>
                ${pill}
              </div>
              <div class="small text-muted">${addr}${addr && comuna ? ' — ' : ''}${comuna}</div>
              <div class="small">${campN}</div>
            </div>
          </div>`;
      };

      if (boxProg) boxProg.innerHTML = programados.map(card).join('') || '<div class="text-muted">Sin programados</div>';
      if (boxReag) boxReag.innerHTML = reagendados.map(card).join('') || '<div class="text-muted">Sin reagendados</div>';

      return { items, programados, reagendados };
    } catch (e) {
      console.warn('[Index] renderTodayFromCache error', e);
      return { items: [], programados: [], reagendados: [] };
    }
  }

  async function pingSession() {
    try {
      const res = await fetch(`${APP_SCOPE}/ping.php`, { credentials: 'include' });
      if (res.status === 401) return { ok: false, csrf_token: null };
      if (!res.ok) throw new Error(`ping ${res.status}`);
      const js = await res.json();
      if (js && js.csrf_token) window.CSRF_TOKEN = js.csrf_token;
      return { ok: true, csrf_token: js?.csrf_token || null };
    } catch (e) {
      console.warn('[Ping] error', e);
      return { ok: false, csrf_token: null };
    }
  }

  // ---- función central para sincronizar agenda + re-render ----
  async function syncAgendaForToday(reason) {
    const ymd = todayYMD();
    if (!navigator.onLine) {
      // Si no hay red, sólo re-render desde cache
      return await renderTodayFromCache(ymd);
    }

    try {
      const { bundle } = await fetchBundle(ymd, ymd);
      if (bundle) {
        if (window.V2Cache && typeof V2Cache.upsertBundle === 'function') {
          await V2Cache.upsertBundle(bundle);
        }
        await ensureSWActiveAndPrecache(bundle);
      }
      // Si llegamos aquí sin excepción, damos por refrescada la agenda
      clearAgendaRefreshFlag();
    } catch (e) {
      console.warn(`[Index] fetchBundle error (${reason || 'boot'}) , se mantiene cache local`, e);
    }

    return await renderTodayFromCache(ymd);
  }

  async function boot() {
    updateNetworkBadge();

    const ymd = todayYMD();
    // 1) Mostrar rápido lo que haya en cache (para que el index no se vea vacío)
    const cachedRender = await renderTodayFromCache(ymd);
    // 2) Ping de sesión en paralelo
    void pingSession();

    // 3) Si hay flag de que la agenda cambió (gestiones offline subidas),
    //    forzamos un sync explícito. Si no, también sincronizamos, pero esto
    //    permite que otros componentes sepan que hubo cambios importantes.
    const needs = agendaNeedsRefresh();
    const syncedRender = needs
      ? await syncAgendaForToday('agenda_flag')
      : await syncAgendaForToday('normal_boot');

    void syncedRender;
  }

  document.addEventListener('DOMContentLoaded', boot);

  // ---- si el Index está abierto mientras la cola sube una gestión,
  //      nos enganchamos al evento queue:gestion_success para refrescar en caliente ----
  window.addEventListener('queue:gestion_success', () => {
    try {
      // por si offline-queue.js aún no escribió el flag
      localStorage.setItem(AGENDA_FLAG_KEY, '1');
    } catch (_) {}
    // si hay red, refrescamos inmediatamente el bundle para que el local desaparezca
    if (navigator.onLine) {
      syncAgendaForToday('gestion_success_event');
    }
  });
  

})();
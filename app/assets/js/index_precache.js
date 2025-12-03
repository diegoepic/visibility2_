(() => {
  'use strict';

  const APP_SCOPE = '/visibility2/app';
  const limit = Number(window.__GESTIONAR_PRECACHE_LIMIT || 10) || 10;
  const userId = Number(window.__GESTIONAR_PRECACHE_USER || 0) || 0;
  const raw = Array.isArray(window.__GESTIONAR_PRECACHE_TARGETS) ? window.__GESTIONAR_PRECACHE_TARGETS : [];

  const btnOpen   = document.getElementById('btnOpenPrecache');
  const modal     = document.getElementById('modalPrecacheGestionar');
  const tbody     = document.getElementById('precacheList');
  const badge     = document.getElementById('precacheBadge');
  const counter   = document.getElementById('precacheCounter');
  const statusBox = document.getElementById('precacheStatus');
  const btnDo     = document.getElementById('btnDoPrecache');
  const limitLbl  = document.getElementById('precacheLimitLabel');

  if (limitLbl) limitLbl.textContent = limit;

  const seen = new Set();
  const targets = raw
    .map((t) => {
      const idLocal   = Number(t.idLocal || t.id_local || 0) || 0;
      const idCamp    = Number(t.idCampana || t.id_campana || 0) || 0;
      const ownerId   = Number(t.idUsuario || t.id_usuario || userId || 0) || 0;
      const localName = t.nombreLocal || t.nombre_local || '';
      const dir       = t.direccionLocal || t.direccion_local || '';
      const campName  = t.nombreCampana || t.nombre_campana || '';
      if (!idLocal || !idCamp) return null;
      const url = `${APP_SCOPE}/gestionarPruebas.php`
        + `?idCampana=${encodeURIComponent(idCamp)}`
        + `&nombreCampana=${encodeURIComponent(campName)}`
        + `&idLocal=${encodeURIComponent(idLocal)}`
        + (ownerId ? `&idUsuario=${encodeURIComponent(ownerId)}` : '');
      return { idLocal, idCamp, localName, dir, campName, url };
    })
    .filter(Boolean)
    .filter((t) => {
      if (seen.has(t.url)) return false;
      seen.add(t.url);
      return true;
    });

  if (!btnOpen || !modal || !tbody || !btnDo) return;
  if (!targets.length) {
    btnOpen.style.display = 'none';
    return;
  }

  function setStatus(message, kind) {
    if (!statusBox) return;
    statusBox.textContent = message;
    statusBox.className = `alert alert-${kind || 'info'}`;
    statusBox.style.display = message ? 'block' : 'none';
  }

  function updateBadge(count) {
    if (!badge) return;
    const val = count != null ? count : targets.length;
    badge.textContent = val ? val : '';
    badge.style.display = val ? 'inline-block' : 'none';
  }

  function updateCounter() {
    if (!counter) return;
    const selected = tbody.querySelectorAll('input[type="checkbox"]:checked').length;
    counter.textContent = `${selected} seleccionados (máx ${limit})`;
    btnDo.disabled = !selected;
  }

  function renderRows() {
    tbody.innerHTML = '';
    targets.forEach((t, idx) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><label class="checkbox"><input type="checkbox" data-url="${t.url}" data-idx="${idx}"> ${idx + 1}</label></td>
        <td>${t.campName || 'Campaña'}</td>
        <td>${t.localName || 'Local ' + t.idLocal}</td>
        <td>${t.dir || ''}</td>`;
      tbody.appendChild(tr);
    });
    updateCounter();
  }

  function getSelectedUrls() {
    const urls = [];
    tbody.querySelectorAll('input[type="checkbox"]:checked').forEach((chk) => {
      const url = chk.getAttribute('data-url');
      if (url) urls.push(url);
    });
    return urls.slice(0, limit);
  }

  async function ensureSW() {
    if (!('serviceWorker' in navigator)) return { worker: null, controlled: false, reason: 'unsupported' };
    try {
      const reg = await navigator.serviceWorker.getRegistration(APP_SCOPE + '/');
      const worker = reg && (reg.active || reg.waiting || reg.installing);
      return { worker, controlled: !!navigator.serviceWorker.controller, reason: worker ? null : 'no-registration' };
    } catch (_) {
      return { worker: null, controlled: false, reason: 'error' };
    }
  }

  async function precacheSelected() {
    const urls = getSelectedUrls();
    if (!urls.length) {
      setStatus('Selecciona al menos un local/campaña para precargar.', 'warning');
      return;
    }
    setStatus(`Enviando ${urls.length} páginas a la caché…`, 'info');
    btnDo.disabled = true;
    const { worker, controlled } = await ensureSW();
    if (!worker) {
      setStatus('No hay un Service Worker registrado para esta página. Refresca o verifica tu conexión.', 'danger');
      btnDo.disabled = false;
      return;
    }
    if (!controlled) {
      setStatus('El Service Worker aún no controla esta pestaña. Recarga la página e inténtalo nuevamente.', 'warning');
      btnDo.disabled = false;
      return;
    }
    try {
      worker.postMessage({ type: 'PRECACHE_GESTIONAR_PAGES', urls, max: limit });
      setStatus('Precarga solicitada. Las páginas quedarán disponibles offline al completarse.', 'success');
    } catch (e) {
      setStatus('No se pudo precargar. Verifica que el Service Worker esté registrado y que haya conexión.', 'danger');
    } finally {
      btnDo.disabled = false;
    }
  }

  tbody.addEventListener('change', (ev) => {
    if (ev.target && ev.target.matches('input[type="checkbox"]')) {
      const checked = tbody.querySelectorAll('input[type="checkbox"]:checked');
      if (checked.length > limit) {
        ev.target.checked = false;
        setStatus(`Sólo puedes seleccionar ${limit} páginas a la vez.`, 'warning');
      } else {
        setStatus('', 'info');
      }
      updateCounter();
    }
  });

  btnDo.addEventListener('click', precacheSelected);

  btnOpen.addEventListener('click', () => {
    if (typeof $ === 'function' && $('#modalPrecacheGestionar').modal) {
      $('#modalPrecacheGestionar').modal('show');
    } else {
      modal.style.display = 'block';
    }
  });

  renderRows();
  updateBadge(targets.length);
})();
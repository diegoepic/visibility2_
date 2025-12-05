/* Gestionar SPA (online-first con fallback a cola)
 * - Usa Queue.smartPost (offline-queue.js) para TODAS las operaciones críticas:
 *   - create_visita
 *   - procesar_gestion
 *   - agregarMaterial
 *   - fotos de preguntas
 * - Maneja client_guid y visita_id "local-*" para que el backend pueda
 *   reconciliar visitas offline.
 * - Emite eventos de DOM para que tu HTML/otros scripts reaccionen:
 *   - visit:started
 *   - visit:finished
 *   - material:added
 *   - qphoto:uploaded
 *   - qphoto:deleted
 *
 * Requiere: offline-queue.js (Queue.*) y v2_cache.js si quieres leer bundle.
 */

(() => {
  'use strict';

  const APP_SCOPE = '/visibility2/app';
  const ENDPOINTS = {
    createVisit: `${APP_SCOPE}/create_visita_pruebas.php`,
    finishVisit: `${APP_SCOPE}/procesar_gestion_pruebas.php`,
    photoQuestion: `${APP_SCOPE}/procesar_pregunta_foto_pruebas.php`,
    deletePhotoQuestion: `${APP_SCOPE}/eliminar_pregunta_foto_pruebas.php`,
    addMaterial: `${APP_SCOPE}/agregarMaterial_pruebas.php`,
    ping: `${APP_SCOPE}/ping.php`,
  };

  // --- Utils básicos ---
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  function genIdempoKey(prefix = 'op') {
    const bytes = new Uint8Array(16);
    if (window.crypto && crypto.getRandomValues) {
      crypto.getRandomValues(bytes);
      const hex = Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
      return `${prefix}-${Date.now()}-${hex}`;
    }
    return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  // --- Geolocalización (mejor esfuerzo) ---
  async function getGeo(opts = {}) {
    if (!('geolocation' in navigator)) return null;
    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        () => resolve(null),
        Object.assign({ enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }, opts)
      );
    });
  }

  // --- Estado de visita en sessionStorage (para reusar en fotos y cierre) ---
  function setVisitState({ visita_id, client_guid, id_local, id_formulario, closedPendingSync }) {
    const state = {
      visita_id: visita_id || null,
      client_guid: client_guid || null,
      id_local: id_local != null ? Number(id_local) : null,
      id_formulario: id_formulario != null ? Number(id_formulario) : null,
      closedPendingSync: !!closedPendingSync,
      t: Date.now()
    };
    sessionStorage.setItem('v2_visit_state', JSON.stringify(state));
    return state;
  }

  function getVisitState() {
    try {
      const js = JSON.parse(sessionStorage.getItem('v2_visit_state') || 'null');
      return js && typeof js === 'object' ? js : null;
    } catch {
      return null;
    }
  }

  // Usa/crea un client_guid consistente con el hidden del formulario
  function getOrCreateClientGuid() {
    const input = document.querySelector('input[name="client_guid"]');
    let guid = (input && input.value && String(input.value).trim()) || null;
    if (!guid) {
      guid = (crypto.randomUUID ? crypto.randomUUID() : `cg-${Date.now()}-${Math.random().toString(16).slice(2)}`);
      if (input) input.value = guid;
    }
    return guid;
  }

  // ---------- ACCIONES DE NEGOCIO ----------

  /**
   * Inicia una visita (create_visita_pruebas.php) usando Queue.smartPost.
   * - Online: obtiene visita_id real del backend.
   * - Offline: genera visita_id "local-*" que luego será mapeado por offline-queue.
   */
  async function startVisit({ id_local, id_formulario }) {
    if (!window.Queue || typeof Queue.smartPost !== 'function') {
      throw new Error('Queue.smartPost no disponible (offline-queue.js no cargado).');
    }

    // Si ya tenemos una visita abierta para ese local/campaña, la reutilizamos
    const current = getVisitState();
    if (
      current &&
      current.id_local === Number(id_local) &&
      current.id_formulario === Number(id_formulario) &&
      !current.closedPendingSync
    ) {
      return current;
    }

    const geo = await getGeo();
    const client_guid = getOrCreateClientGuid();
    const visitaLocalId = 'local-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now());

    const fields = {
      id_local: id_local,
      id_formulario: id_formulario,
      latitud: geo?.lat ?? '',
      longitud: geo?.lng ?? '',
      client_guid,
      visita_local_id: visitaLocalId
    };

    const result = await Queue.smartPost(ENDPOINTS.createVisit, fields, {
      type: 'create_visita',
      id: genIdempoKey('visit-start'),
      client_guid
    });

    let state;
    if (result.queued) {
      // Offline: usamos el visitaLocalId como visita_id local (Queue lo mapeará al real al sincronizar)
      state = setVisitState({
        visita_id: visitaLocalId,
        client_guid,
        id_local,
        id_formulario,
        closedPendingSync: false
      });
    } else {
      const js = result.response || {};
      state = setVisitState({
        visita_id: js.visita_id || visitaLocalId,
        client_guid: js.client_guid || client_guid,
        id_local,
        id_formulario,
        closedPendingSync: false
      });
    }

    return state;
  }

  /**
   * Finaliza la visita (procesar_gestion_pruebas.php) usando Queue.smartPost.
   * - Pasa client_guid y depende de create:<client_guid> para respetar el orden.
   * - Devuelve el JSON del backend o { queued:true, status:'queued' } si quedó en cola.
   */
  async function finishVisit(formEl) {
    if (!window.Queue || typeof Queue.smartPost !== 'function') {
      throw new Error('Queue.smartPost no disponible (offline-queue.js no cargado).');
    }

    const fd = new FormData(formEl);
    const st = getVisitState() || {};

    // client_guid: desde el form, estado o lo creamos
    let client_guid = fd.get('client_guid');
    if (!client_guid) {
      client_guid = st.client_guid || getOrCreateClientGuid();
      fd.set('client_guid', client_guid);
    } else {
      client_guid = String(client_guid);
    }

    // visita_id: si no viene en el form, usamos el del estado local
    let hasVisita = !!(fd.get('visita_id') || fd.get('idVisita'));
    if (!hasVisita && st.visita_id) {
      fd.set('visita_id', st.visita_id);
    }

    const id_local =
      fd.get('id_local') ||
      fd.get('idLocal') ||
      (st.id_local != null ? String(st.id_local) : null);

    const id_formulario =
      fd.get('id_formulario') ||
      fd.get('idCampana') ||
      (st.id_formulario != null ? String(st.id_formulario) : null);

    const result = await Queue.smartPost(ENDPOINTS.finishVisit, fd, {
      type: 'procesar_gestion',
      id: genIdempoKey('visit-finish'),
      client_guid,
      dependsOn: client_guid ? `create:${client_guid}` : undefined
    });

    if (result.queued) {
      // Offline: marcamos la visita como cerrada pendiente de sync
      setVisitState({
        visita_id: st.visita_id || null,
        client_guid,
        id_local,
        id_formulario,
        closedPendingSync: true
      });
      return { queued: true, status: 'queued' };
    } else {
      const js = result.response || {};
      if (js.closed === true || js.status === 'success') {
        sessionStorage.removeItem('v2_visit_state');
      } else {
        setVisitState({
          visita_id: st.visita_id || null,
          client_guid,
          id_local,
          id_formulario,
          closedPendingSync: false
        });
      }
      return js;
    }
  }

  /**
   * Agregar material (agregarMaterial_pruebas.php)
   * - Online: devuelve JSON del backend.
   * - Offline: { queued:true, status:'queued' }.
   */
  async function addMaterial(formEl) {
    const fd = new FormData(formEl);

    if (window.Queue && typeof Queue.smartPost === 'function') {
      const result = await Queue.smartPost(ENDPOINTS.addMaterial, fd, {
        type: 'add_material',
        id: genIdempoKey('material-add')
      });

      if (result.queued) {
        return {
          queued: true,
          status: 'queued',
          message: 'Material encolado; se creará al recuperar conexión.'
        };
      }
      return result.response || { status: 'ok' };
    }

    // Fallback online-only (por si algún día usan esto sin offline-queue)
    const bodyObj = Object.fromEntries(fd.entries());
    const body = new URLSearchParams(bodyObj).toString();
    const res = await fetch(ENDPOINTS.addMaterial, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      },
      body
    });
    if (!res.ok) throw new Error(`http_${res.status}`);
    return await res.json();
  }

  /**
   * Subir foto de pregunta (procesar_pregunta_foto_pruebas.php)
   */
  async function uploadQuestionPhoto({ inputEl, id_form_question, id_local }) {
    if (!inputEl || !inputEl.files || !inputEl.files.length) {
      throw new Error('no_file');
    }

    const st = getVisitState() || {};
    const geo = await getGeo();

    const fd = new FormData();
    fd.set('id_form_question', String(id_form_question));
    fd.set('id_local', String(id_local || st.id_local || ''));
    if (st.visita_id) fd.set('visita_id', String(st.visita_id));
    if (st.client_guid) fd.set('client_guid', st.client_guid);
    if (geo?.lat != null) fd.set('lat', String(geo.lat));
    if (geo?.lng != null) fd.set('lng', String(geo.lng));
    fd.set('capture_source', 'camera'); // o 'gallery' según tu UI
    fd.set('fotoPregunta', inputEl.files[0]);

    if (window.Queue && typeof Queue.smartPost === 'function') {
      const result = await Queue.smartPost(ENDPOINTS.photoQuestion, fd, {
        type: 'foto_pregunta',
        id: genIdempoKey('qphoto'),
        client_guid: st.client_guid || null
      });
      if (result.queued) return { queued: true, status: 'queued' };
      return result.response || { status: 'ok' };
    }

    // Fallback fetch directo
    const res = await fetch(ENDPOINTS.photoQuestion, {
      method: 'POST',
      credentials: 'include',
      body: fd
    });
    if (!res.ok) throw new Error(`http_${res.status}`);
    return await res.json();
  }

  /**
   * Eliminar foto de pregunta (eliminar_pregunta_foto_pruebas.php)
   */
  async function deleteQuestionPhoto({ resp_id, id_form_question, visita_id }) {
    const fd = new FormData();
    fd.set('resp_id', String(resp_id));
    fd.set('id_form_question', String(id_form_question));
    fd.set('visita_id', String(visita_id));

    if (window.Queue && typeof Queue.smartPost === 'function') {
      const result = await Queue.smartPost(ENDPOINTS.deletePhotoQuestion, fd, {
        type: 'foto_pregunta_delete',
        id: genIdempoKey('qphoto-del')
      });
      if (result.queued) return { queued: true, status: 'queued' };
      return result.response || { status: 'ok' };
    }

    const bodyObj = Object.fromEntries(fd.entries());
    const body = new URLSearchParams(bodyObj).toString();
    const res = await fetch(ENDPOINTS.deletePhotoQuestion, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': window.CSRF_TOKEN || ''
      },
      body
    });
    if (!res.ok) throw new Error(`http_${res.status}`);
    return await res.json();
  }

  // ---------- BINDINGS POR data-* (no rompe tu HTML actual) ----------

  function bindActions(root = document) {
    // Iniciar visita
    root.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-action="start-visit"]');
      if (!btn) return;
      ev.preventDefault();
      btn.disabled = true;
      try {
        const id_local = parseInt(btn.dataset.idLocal || '0', 10);
        const id_formulario = parseInt(btn.dataset.idFormulario || '0', 10);
        if (!id_local || !id_formulario) throw new Error('bad_params');
        const st = await startVisit({ id_local, id_formulario });
        if (!st) throw new Error('visit_fail');
        btn.dispatchEvent(new CustomEvent('visit:started', { bubbles: true, detail: st }));
      } catch (e) {
        console.warn('[start-visit] error', e);
        alert('No fue posible iniciar la visita (online u offline).');
      } finally {
        btn.disabled = false;
      }
    });

    // Finalizar visita (enviar formulario principal)
    root.addEventListener('submit', async (ev) => {
      const form = ev.target.closest('form[data-action="finish-visit"]');
      if (!form) return;
      ev.preventDefault();
      const submitBtn = form.querySelector('[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const res = await finishVisit(form);
        // Para compatibilidad: el detalle del evento es el JSON (o marcador queued)
        form.dispatchEvent(new CustomEvent('visit:finished', { bubbles: true, detail: res }));

        if (res && res.queued) {
          alert('Gestión encolada. Se enviará automáticamente al recuperar conexión.');
        } else {
          alert('Gestión enviada correctamente.');
        }
      } catch (e) {
        console.warn('[finish-visit] error', e);
        alert('No fue posible enviar la gestión. Si estás offline, revisa el Journal y reintenta.');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });

    // Agregar material
    root.addEventListener('submit', async (ev) => {
      const form = ev.target.closest('form[data-action="add-material"]');
      if (!form) return;
      ev.preventDefault();
      const submitBtn = form.querySelector('[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const res = await addMaterial(form);
        form.reset();
        form.dispatchEvent(new CustomEvent('material:added', { bubbles: true, detail: res }));
        if (res && res.queued) {
          alert(res.message || 'Material encolado; se creará al recuperar conexión.');
        } else {
          alert(res?.message || 'Material agregado (o reutilizado).');
        }
      } catch (e) {
        console.warn('[add-material] error', e);
        alert('No fue posible agregar el material.');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });

    // Subir foto de pregunta
    root.addEventListener('change', async (ev) => {
      const input = ev.target.closest('input[type="file"][data-action="upload-question-photo"]');
      if (!input) return;
      const id_form_question = parseInt(input.dataset.idFormQuestion || '0', 10);
      const id_local         = parseInt(input.dataset.idLocal || '0', 10);
      if (!id_form_question || !id_local) {
        alert('Faltan parámetros para la foto.');
        return;
      }
      try {
        const res = await uploadQuestionPhoto({ inputEl: input, id_form_question, id_local });
        input.value = '';
        input.dispatchEvent(new CustomEvent('qphoto:uploaded', { bubbles: true, detail: res }));
        if (res && res.queued) {
          alert('Foto encolada. Se subirá automáticamente al recuperar conexión.');
        }
      } catch (e) {
        console.warn('[upload-question-photo] error', e);
        alert('No fue posible subir la foto. Si estás offline, quedó encolada o reinténtalo.');
      }
    });

    // Eliminar foto de pregunta
    root.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-action="delete-question-photo"]');
      if (!btn) return;
      ev.preventDefault();
      try {
        const resp_id = parseInt(btn.dataset.respId || '0', 10);
        const id_form_question = parseInt(btn.dataset.idFormQuestion || '0', 10);
        const visita_id = parseInt(btn.dataset.visitaId || '0', 10);
        if (!resp_id || !id_form_question || !visita_id) throw new Error('bad_params');
        const res = await deleteQuestionPhoto({ resp_id, id_form_question, visita_id });
        btn.dispatchEvent(new CustomEvent('qphoto:deleted', { bubbles: true, detail: res }));
        alert('Foto eliminada (o encolada para eliminar).');
      } catch (e) {
        console.warn('[delete-question-photo] error', e);
        alert('No fue posible eliminar la foto (online u offline).');
      }
    });
  }

  // ---------- Arranque ----------
  async function boot() {
    // Best-effort: ping para refrescar CSRF y validar sesión, pero la cola igual se encarga.
    try {
      const res = await fetch(ENDPOINTS.ping, { credentials: 'include' });
      if (res.ok) {
        const js = await res.json();
        if (js && js.csrf_token) window.CSRF_TOKEN = js.csrf_token;
      }
    } catch {
      // ignoramos, Queue se encargará del CSRF en los POST
    }

    bindActions(document);
  }

  document.addEventListener('DOMContentLoaded', boot);

  // Exponer API mínima por si la UI la llama explícitamente
  window.GestionarSPA = {
    startVisit,
    finishVisit,
    addMaterial,
    uploadQuestionPhoto,
    deleteQuestionPhoto,
    getVisitState,
    setVisitState
  };
})();
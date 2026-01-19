(function(){
  'use strict';

  const CHANNEL_NAME = 'v2-events';
  let broadcastChannel = null;

  function ensureBroadcastChannel(){
    if (broadcastChannel || typeof BroadcastChannel === 'undefined') return;
    broadcastChannel = new BroadcastChannel(CHANNEL_NAME);
    broadcastChannel.addEventListener('message', function(ev){
      if (ev && ev.data && ev.data.type === 'gestion_completed') {
        handleLiveSuccess(ev.data.payload || {});
      }
    });
  }

  function showSuccessAlert(payload){
    const msg = (payload && payload.message) || 'La gestión se subió correctamente.';
    let alertEl = document.getElementById('success-alert');
    if (!alertEl){
      alertEl = document.createElement('div');
      alertEl.id = 'success-alert';
      alertEl.className = 'alert alert-success';
      alertEl.setAttribute('role', 'alert');
      const anchor = document.body.firstElementChild;
      if (anchor) document.body.insertBefore(alertEl, anchor);
      else document.body.appendChild(alertEl);
    }
    alertEl.textContent = msg;
    alertEl.style.display = 'block';
    setTimeout(()=>{
      try { alertEl.style.display = 'none'; } catch(_){}
    }, 4000);
  }

  function persistSuccessForReload(payload){
    try { sessionStorage.setItem('v2_gestion_success', JSON.stringify(payload)); } catch(_){ }
  }

  function handleLiveSuccess(payload){
    if (!payload) return;
    persistSuccessForReload(payload);
    try { localStorage.setItem('v2_gestion_success_pending', JSON.stringify(payload)); } catch(_){ }
    window.location.reload();
  }

  function readPendingSuccess(){
    let data = null;
    try {
      const rawLs = localStorage.getItem('v2_gestion_success_pending');
      if (rawLs) {
        data = JSON.parse(rawLs);
        localStorage.removeItem('v2_gestion_success_pending');
        persistSuccessForReload(data);
      }
    } catch(_){ }

    if (!data) {
      try {
        const raw = sessionStorage.getItem('v2_gestion_success');
        if (raw) data = JSON.parse(raw);
      } catch(_){ }
    }

    if (data) {
      showSuccessAlert(data);
      try { sessionStorage.removeItem('v2_gestion_success'); } catch(_){ }
    }
  }

  async function hideDoneRows(){
    if (!window.V2Cache || typeof V2Cache.listDoneForDate !== 'function') return;

    const tables = Array.from(document.querySelectorAll('table[data-fechaTabla]'));
    const doneByDate = {};

    for (const tbl of tables){
      const ymd = tbl.getAttribute('data-fechaTabla') || '';
      if (!ymd) continue;
      if (!doneByDate[ymd]) {
        try { doneByDate[ymd] = await V2Cache.listDoneForDate(ymd); }
        catch(_){ doneByDate[ymd] = []; }
      }

      const rows = tbl.querySelectorAll('tbody tr[data-idlocal]');
      const doneRows = doneByDate[ymd] || [];
      const map = new Map();
      doneRows.forEach(r=>{
        const lid = Number(r.local_id || r.id_local || 0);
        const fid = String(r.form_id || r.formulario_id || r.id_formulario || r.campana_id || '0');
        if (!lid) return;
        const set = map.get(lid) || new Set();
        set.add(fid);
        map.set(lid, set);
      });

      rows.forEach(tr => {
        const localId = Number(tr.getAttribute('data-idlocal') || 0);
        const camps = (tr.getAttribute('data-campanas') || '').split(',').filter(Boolean);
        const doneSet = map.get(localId);
        if (!doneSet) return;
        const allDone = camps.length ? camps.every(c => doneSet.has(c) || doneSet.has(String(Number(c)))) : doneSet.size > 0;
        if (allDone) tr.style.display = 'none';
      });
    }
  }

  function storageListener(ev){
    if (!ev) return;
    if (ev.key === 'v2_gestion_success_broadcast' || ev.key === 'v2_gestion_success_pending') {
      if (!ev.newValue) return;
      try {
        const data = JSON.parse(ev.newValue);
        handleLiveSuccess(data);
      } catch(_){ }
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    ensureBroadcastChannel();
    window.addEventListener('storage', storageListener);
    readPendingSuccess();
    hideDoneRows();
  });

  // =========================================================================
  // PATCH 4: Escuchar eventos de éxito de cola para actualizar UI sin reload
  // =========================================================================

  /**
   * Oculta una fila específica con animación suave
   */
  function hideRowWithAnimation(tr, delay = 0) {
    setTimeout(() => {
      tr.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
      tr.style.opacity = '0';
      tr.style.transform = 'translateX(-20px)';
      setTimeout(() => {
        tr.style.display = 'none';
        // Actualizar contador si existe
        updatePendingCount();
      }, 300);
    }, delay);
  }

  /**
   * Actualiza el contador de pendientes en la UI si existe
   */
  function updatePendingCount() {
    const countEl = document.querySelector('.agenda-count, .pending-count, [data-pending-count]');
    if (!countEl) return;

    // Contar filas visibles
    const visibleRows = document.querySelectorAll('tbody tr[data-idlocal]:not([style*="display: none"])');
    const count = visibleRows.length;
    countEl.textContent = String(count);

    // Si no hay más pendientes, mostrar mensaje
    if (count === 0) {
      const emptyMsg = document.querySelector('.empty-agenda-message, [data-empty-message]');
      if (emptyMsg) emptyMsg.style.display = 'block';
    }
  }

  /**
   * Maneja el éxito de una gestión desde la cola - actualización incremental
   */
  function handleQueueSuccess(detail) {
    const job = detail.job || {};
    const response = detail.response || {};

    // Solo procesar gestiones completadas
    const isGestion =
      job.type === 'procesar_gestion' ||
      job.type === 'procesar_gestion_pruebas' ||
      (job.url && (job.url.includes('procesar_gestion_pruebas.php') || job.url.includes('procesar_gestion.php')));

    if (!isGestion) return;

    // Obtener IDs del job o response
    const formId = Number(
      job.meta?.form_id ||
      job.fields?.idCampana ||
      job.fields?.id_formulario ||
      response.id_formulario ||
      0
    );
    const localId = Number(
      job.meta?.local_id ||
      job.fields?.idLocal ||
      job.fields?.id_local ||
      response.id_local ||
      0
    );

    if (!localId) return;

    console.log('[bootstrap_index_cache] Ocultando local tras sync:', localId, 'form:', formId);

    // Buscar y ocultar la fila correspondiente
    const rows = document.querySelectorAll(`tr[data-idlocal="${localId}"]`);
    let hidden = false;

    rows.forEach(tr => {
      // Si hay formId, verificar que la fila corresponde a esa campaña
      if (formId) {
        const camps = (tr.getAttribute('data-campanas') || '').split(',').filter(Boolean);
        const matchesCampaign = camps.length === 0 || camps.includes(String(formId));
        if (matchesCampaign) {
          hideRowWithAnimation(tr);
          hidden = true;
        }
      } else {
        // Sin formId específico, ocultar todas las filas de ese local
        hideRowWithAnimation(tr);
        hidden = true;
      }
    });

    // Mostrar alerta de éxito
    if (hidden) {
      const localName = rows[0]?.querySelector('.local-name, [data-local-name]')?.textContent || `Local ${localId}`;
      showSuccessAlert({
        message: `Gestión enviada: ${localName}`,
        local_id: localId,
        form_id: formId
      });
    }
  }

  // Escuchar evento de éxito individual de la cola
  window.addEventListener('queue:dispatch:success', function(ev) {
    try {
      handleQueueSuccess(ev.detail || {});
    } catch(e) {
      console.warn('[bootstrap_index_cache] Error en queue:dispatch:success:', e);
    }
  });

  // También escuchar el evento personalizado queue:done
  window.addEventListener('queue:done', function(ev) {
    try {
      const detail = ev.detail || {};
      if (detail.type === 'procesar_gestion' || detail.type === 'procesar_gestion_pruebas') {
        handleQueueSuccess({ job: detail, response: detail.response || {} });
      }
    } catch(e) {
      console.warn('[bootstrap_index_cache] Error en queue:done:', e);
    }
  });

  // Escuchar evento específico de gestión completada
  window.addEventListener('queue:gestion_success', function(ev) {
    try {
      handleQueueSuccess(ev.detail || {});
    } catch(e) {
      console.warn('[bootstrap_index_cache] Error en queue:gestion_success:', e);
    }
  });

  // Re-ejecutar hideDoneRows cuando la página vuelve a ser visible (por si hubo cambios en background)
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
      // Pequeño delay para dar tiempo a que se procesen otras actualizaciones
      setTimeout(hideDoneRows, 500);
    }
  });

})();
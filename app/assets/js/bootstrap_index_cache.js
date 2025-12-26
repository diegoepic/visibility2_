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
})();

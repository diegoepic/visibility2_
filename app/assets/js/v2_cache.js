(function(){
  'use strict';
  window.SW_VERSION = window.SW_VERSION || 'v3.1.2';

  const DB_NAME    = 'visibility2-v2';
  const DB_VERSION = 6; 
  
  let _db = null;
  let _useLS = false;
  function openDB(){
    if (_db) return Promise.resolve(_db);
    if (!('indexedDB' in window)) {
      _useLS = true;
      return Promise.resolve(null);
    }
    return new Promise((resolve)=>{
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = ()=>{
        const db = req.result;

        // v1
        if (!db.objectStoreNames.contains('meta')) {
          db.createObjectStore('meta', { keyPath:'key' });
        }
        if (!db.objectStoreNames.contains('profile')) {
          db.createObjectStore('profile', { keyPath:'key' });
        }
        if (!db.objectStoreNames.contains('agenda')) {
          const os = db.createObjectStore('agenda', { keyPath:'key' });
          os.createIndex('by_date','fechaPropuesta',{unique:false});
        }

        // v2: nuevos stores
        if (!db.objectStoreNames.contains('campaigns')) {
          db.createObjectStore('campaigns', { keyPath:'id' });
        }
        if (!db.objectStoreNames.contains('locals')) {
          db.createObjectStore('locals',    { keyPath:'id' });
        }
        if (!db.objectStoreNames.contains('questions_by_form')) {
          db.createObjectStore('questions_by_form', { keyPath:'formulario_id' });
        }
      };
      req.onsuccess = ()=>{
        _db = req.result;
        resolve(_db);
      };
      req.onerror = ()=>{
        _useLS = true;
        resolve(null);
      };
    });
  }


  function withStore(store, mode, fn){
    return openDB().then(db=>{
      if (!db || _useLS) return fn(null);
      return new Promise((resolve, reject)=>{
        const tx = db.transaction(store, mode);
        const os = tx.objectStore(store);
        let res;
        try {
          res = fn(os);
        } catch(e){
          reject(e);
          return;
        }
        tx.oncomplete = ()=> resolve(res);
        tx.onerror    = ()=> reject(tx.error||new Error('tx error'));
        tx.onabort    = ()=> reject(tx.error||new Error('tx abort'));
      });
    });
  }

  // ---------- LS fallback ----------
  function lsGet(ns, key){
    try {
      const raw = localStorage.getItem(`v2_${ns}`);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      return key == null ? obj : (obj ? obj[key] : null);
    } catch(_){
      return null;
    }
  }

  function lsPut(ns, key, value){
    try {
      const obj = lsGet(ns, null) || {};
      obj[key] = value;
      localStorage.setItem(`v2_${ns}`, JSON.stringify(obj));
      return true;
    } catch(_){
      return false;
    }
  }

  function lsDel(ns, key){
    try {
      const obj = lsGet(ns, null) || {};
      delete obj[key];
      localStorage.setItem(`v2_${ns}`, JSON.stringify(obj));
      return true;
    } catch(_){
      return false;
    }
  }

  // ---------- API base ----------
  async function get(store, key){
    if (_useLS) return lsGet(store, key);
    return withStore(store,'readonly', os=> new Promise((resolve)=>{
      const r = os.get(key);
      r.onsuccess = ()=> resolve(r.result||null);
      r.onerror   = ()=> resolve(null);
    }));
  }

  async function put(store, key, value){
    if (_useLS){
      lsPut(store, key, value);
      return true;
    }
    const obj = (store === 'campaigns' || store === 'locals' || store === 'questions_by_form')
      ? value // ya trae su keyPath (id/formulario_id)
      : Object.assign({}, value, { key });

    return withStore(store,'readwrite', os=> new Promise((resolve)=>{
      const r = os.put(obj);
      r.onsuccess = ()=> resolve(true);
      r.onerror   = ()=> resolve(false);
    }));
  }

  async function del(store, key){
    if (_useLS){
      lsDel(store, key);
      return true;
    }
    return withStore(store,'readwrite', os=> new Promise((resolve)=>{
      const r = os.delete(key);
      r.onsuccess = ()=> resolve(true);
      r.onerror   = ()=> resolve(false);
    }));
  }

  // ---------- Limpieza agenda por rango ----------
  async function _cleanupAgendaOutside(fromYmd, toYmd){
    if (_useLS) {
      const all  = lsGet('agenda', null) || {};
      const kept = {};
      Object.keys(all).forEach(k=>{
        const it = all[k];
        const f  = it && it.fechaPropuesta || '';
        if (f >= fromYmd && f <= toYmd) {
          kept[k] = it;
        }
      });
      try{
        localStorage.setItem('v2_agenda', JSON.stringify(kept));
      }catch(_){}
      return true;
    }
    return withStore('agenda','readwrite', os=> new Promise((resolve)=>{
      const req = os.openCursor();
      req.onsuccess = ()=>{
        const cur = req.result;
        if (!cur){
          resolve(true);
          return;
        }
        const val = cur.value;
        const f   = (val && val.fechaPropuesta) || '';
        if (!(f >= fromYmd && f <= toYmd)) cur.delete();
        cur.continue();
      };
      req.onerror = ()=> resolve(false);
    }));
  }

  // ---------- Lectura por fecha (tarjetas del index) ----------
  async function listToday(ymd){
    if (!ymd) return [];
    if (_useLS){
      const all = lsGet('agenda', null) || {};
      const out = [];
      Object.keys(all).forEach(k=>{
        const it = all[k];
        if (it && it.fechaPropuesta === ymd) out.push(it);
      });
      out.sort(_cmpAgenda);
      return out;
    }
    return withStore('agenda','readonly', os=> new Promise((resolve)=>{
      const idx   = os.index('by_date');
      const range = IDBKeyRange.only(ymd);
      const out   = [];
      const req   = idx.openCursor(range);
      req.onsuccess = ()=>{
        const c = req.result;
        if (!c){
          out.sort(_cmpAgenda);
          resolve(out);
          return;
        }
        out.push(c.value);
        c.continue();
      };
      req.onerror = ()=> resolve([]);
    }));
  }

  function _cmpAgenda(a,b){
    const la = (a.local?.cadena || '') + (a.local?.direccion || '');
    const lb = (b.local?.cadena || '') + (b.local?.direccion || '');
    return la.localeCompare(lb);
  }

  // ---------- Preguntas por formulario ----------
  async function getQuestions(formulario_id){
    if (!formulario_id) return { items:[], optsByQ:{} };
    const rec = await get('questions_by_form', formulario_id);
    return rec || { formulario_id, items:[], optsByQ:{} };
  }

  // ---------- Asignaciones de materiales ----------
  // Nota: si en tu bundle futuro incluyes asignaciones por (local, formulario),
  // aquí las proveemos. Por ahora se devuelve vacío (Step2 muestra “Sin materiales asignados.”)
  async function getAssignments(id_local, id_formulario){
    // Hook para el futuro: podrías almacenar en store 'assigns' con key `${id_local}|${id_formulario}`
    // y retornarlo aquí. De momento devolvemos [] sin romper el flujo.
    return [];
  }

  // ---------- Upsert bundle (acepta formato nuevo de sync_bundle.php) ----------
  async function upsertBundle(bundle){
    if (!bundle || typeof bundle!=='object') return false;

    // 1) Meta/manifest
    let etag = null;
    let from = null;
    let to   = null;
    if (bundle.manifest && bundle.manifest.etag){
      etag = bundle.manifest.etag;
      const routeDate = bundle.manifest.date_range?.route_date || bundle.date || '';
      const days      = parseInt(bundle.manifest.date_range?.reagendados_days||'7',10) || 7;
      // Definimos rango [routeDate - days, routeDate]
      if (routeDate){
        const d  = new Date(routeDate+'T00:00:00');
        const d0 = new Date(d);
        d0.setDate(d.getDate()-days);
        from = d0.toISOString().slice(0,10);
        to   = routeDate;
      }
      await put('meta','manifest', { etag, from, to, saved_at: Date.now() });
    } else if (bundle.etag || bundle.from || bundle.to){
      etag = bundle.etag || null;
      from = bundle.from || null;
      to   = bundle.to   || null;
      await put('meta','manifest', { etag, from, to, saved_at: Date.now() });
    }

    // 2) Campaigns
    if (Array.isArray(bundle.campaigns)){
      await Promise.all(bundle.campaigns.map(c=>{
        // asegura mínimo: id, nombre
        const rec = {
          id:           Number(c.id),
          nombre:       c.nombre || '',
          tipo:         c.tipo,
          estado:       c.estado,
          fechaInicio:  c.fechaInicio,
          fechaTermino: c.fechaTermino
        };
        return put('campaigns', rec.id, rec);
      }));
    }

    // 3) Locals
    if (Array.isArray(bundle.locales)){
      await Promise.all(bundle.locales.map(l=>{
        const rec = {
          id:        Number(l.id),
          codigo:    l.codigo    || '',
          nombre:    l.nombre    || '',
          direccion: l.direccion || '',
          lat:       Number(l.lat || 0),
          lng:       Number(l.lng || 0),
          comuna:    l.comuna    || '',
          id_comuna: l.id_comuna || null
        };
        return put('locals', rec.id, rec);
      }));
    }

    // 4) Questions (grouped)
    if (Array.isArray(bundle.questions)){
      await Promise.all(bundle.questions.map(group=>{
        const fid       = Number(group.formulario_id);
        const items     = [];
        const optsByQ   = {};
        (group.preguntas||[]).forEach(q=>{
          const qid = Number(q.id);
          // Normalizamos nombres esperados por gestionar_spa.js
          items.push({
            id_form_question: qid,
            id_formulario:    fid,
            question_text:    q.question_text || '',
            id_question_type: Number(q.id_question_type)||0,
            is_required:      Number(q.is_required)||0,
            is_valued:        Number(q.is_valued)||0
          });
          const ops = (q.options||[]).map(o=>({
            id:              Number(o.id),
            id_form_question: qid,
            option_text:     o.option_text || '',
            sort_order:      Number(o.sort_order)||0,
            reference_image: o.reference_image || null
          }));
          optsByQ[qid] = ops;
        });
        return put('questions_by_form', fid, { formulario_id: fid, items, optsByQ });
      }));
    }

    // 5) Agenda (desde bundle.agenda o desde bundle.route.*)
    let agendaIn = Array.isArray(bundle.agenda) ? bundle.agenda : null;
    if (!agendaIn && bundle.route){
      const prog = Array.isArray(bundle.route.programados) ? bundle.route.programados : [];
      const reag = Array.isArray(bundle.route.reagendados) ? bundle.route.reagendados : [];
      agendaIn   = [
        ...prog.map(r=> ({ ..._mkAgendaFromRoute(r, false) })),
        ...reag.map(r=> ({ ..._mkAgendaFromRoute(r, true ) }))
      ];
    }

    if (Array.isArray(agendaIn)){
      await Promise.all(agendaIn.map(item=>{
        const fecha = String(item.fechaPropuesta||'').slice(0,10);
        const idF   = item.camp?.id_formulario ?? item.id_formulario ?? 0;
        const idL   = item.local?.id_local    ?? item.id_local      ?? 0;
        const key   = `${fecha}|${idF}|${idL}`;
        const rec   = {
          key,
          fechaPropuesta: fecha,
          reagendado:     !!item.reagendado,
          local: {
            id_local:  idL,
            nombre:    (item.local && item.local.nombre)    || item.nombreLocal    || '',
            direccion: (item.local && item.local.direccion) || item.direccionLocal || '',
            comuna:    (item.local && item.local.comuna)    || item.comuna         || '',
            cadena:    (item.local && item.local.cadena)    || item.cadena         || ''
          },
          camp: {
            id_formulario: idF,
            nombre: (item.camp && item.camp.nombre) || item.nombreCampana || ''
          }
        };
        return put('agenda', key, rec);
      }));

      // limpieza por rango si lo conocemos
      if (from && to) await _cleanupAgendaOutside(from, to);
    }

    return true;
  }

  function _mkAgendaFromRoute(r, isReag){
    // r: { id_formulario, nombre_campana, id_local, fechaPropuesta, ... }
    const fecha = (r.fechaPropuesta||'').slice(0,10);
    return {
      fechaPropuesta: fecha,
      reagendado:     !!isReag,
      id_formulario:  Number(r.id_formulario||0),
      id_local:       Number(r.id_local||0),
      nombreLocal:    r.local_nombre || '',
      direccionLocal: r.direccion    || '',
      cadena:         r.cadena       || '',
      camp: {
        id_formulario: Number(r.id_formulario||0),
        nombre:        r.nombre_campana || ''
      },
      local:{
        id_local:   Number(r.id_local||0),
        nombre:     r.local_nombre || '',
        direccion:  r.direccion    || '',
        comuna:     r.comuna       || '',
        cadena:     r.cadena       || ''
      }
    };
  }

  // ---------- Export ----------
  window.V2Cache = {
    get,
    put,
    del,
    upsertBundle,
    listToday,
    getQuestions,
    getAssignments,

    /**
     * Devuelve contadores de agenda para un día:
     *  - total: todos los locales asignados para esa fecha
     *  - programados: no reagendados
     *  - reagendados: marcados como reagendado
     *
     * Esto lo vamos a usar en el Panel de Avance junto con el journal,
     * para dibujar barras tipo "X de Y gestionados".
     */
    async statsForDay(ymd){
      const items = await listToday(ymd) || [];
      const total = items.length;
      let reagendados = 0;

      for (const it of items){
        if (it && it.reagendado) reagendados++;
      }

      return {
        ymd,
        total,
        programados: total - reagendados,
        reagendados
      };
    }
  };
})();
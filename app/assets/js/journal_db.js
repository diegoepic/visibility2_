
(function(){
  'use strict';

  const DB_NAME = 'v2_journal';
  const DB_VER  = 8;
  const STORE   = 'journal';

  function ymdLocal(d){
    const dt = d instanceof Date ? d : new Date();
    const y  = dt.getFullYear();
    const m  = ('0' + (dt.getMonth() + 1)).slice(-2);
    const da = ('0' + dt.getDate()).slice(-2);
    return `${y}-${m}-${da}`;
  }

  function openDB(){
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = (e) => {
        const db = e.target.result;
        const os = db.createObjectStore(STORE, { keyPath:'id' });
        os.createIndex('by_ymd',     'ymd',         { unique:false });
        os.createIndex('by_status',  'status',      { unique:false });
        os.createIndex('by_created', 'created',     { unique:false });
        os.createIndex('by_kind',    'kind',        { unique:false });
        os.createIndex('by_guid',    'client_guid', { unique:false });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
  }

  async function tx(store, mode, fn){
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const t  = db.transaction(store, mode);
      const os = t.objectStore(store);
      let res;
      try {
        res = fn(os);
      } catch (e) {
        reject(e);
        return;
      }
      t.oncomplete = () => resolve(res);
      t.onerror    = () => reject(t.error || new Error('tx error'));
      t.onabort    = () => reject(t.error || new Error('tx abort'));
    });
  }

  function now(){ return Date.now(); }

  function normalizeFromQueue(job, patch){
    const meta   = job.meta   || {};
    const fields = job.fields || {};
    const rec = {
      id: job.id,
      created : now(),
      updated : now(),
      ymd     : ymdLocal(new Date()),
      status  : 'pending',
      progress: 0,
      attempts: job.attempts || 0,
      nextTryAt: job.nextTry || null,
      lastTryAt: job.lastTryAt || null,
      kind    : meta.kind || job.type || 'generic',

      local_id : meta.local_id || fields.id_local      || fields.idLocal     || null,
      form_id  : meta.form_id  || fields.id_formulario || fields.idCampana   || null,
      client_guid    : meta.client_guid || job.client_guid || fields.client_guid || null,
      visita_local_id: fields.visita_local_id || null,
      visita_id      : null,

      http_status: (patch && patch.http_status) || null,
      request_id : (patch && patch.request_id)  || null,
      last_error : (patch && patch.last_error)  || null,
      last_error_code: (patch && patch.last_error_code) || null,

      counts: { photos:0, answers:0 },

      names: {
         local   : meta.local_name      || meta.local      || fields.nombre_local    || null,
        codigo   : meta.local_codigo    || meta.codigo     || fields.codigo_local    || null,
        direccion: meta.local_direccion || meta.direccion  || fields.direccionLocal  || fields.direccion_local || null,
        comuna   : meta.local_comuna    || fields.comuna   || fields.comunaLocal     || null,
        campaign : meta.campaign_name   || meta.nombre_campana || fields.nombreCampana || null
      },

      vars : {},
      error: null,
      lastResponseSnippet: patch && patch.lastResponseSnippet || null
    };

    return Object.assign(rec, (patch || {}));
  }

  async function upsert(obj){
    return tx(STORE, 'readwrite', os => new Promise((resolve, reject) => {
      const g = os.get(obj.id);
      g.onsuccess = () => {
        const cur = g.result;
        const val = cur
          ? Object.assign({}, cur, obj, { updated: now(), created: cur.created || now() })
          : Object.assign({}, obj,      { created: now(), updated: now() });
        const put = os.put(val);
        put.onsuccess = () => resolve(val);
        put.onerror   = () => reject(put.error);
      };
      g.onerror = () => reject(g.error);
    }));
  }
  
  

  async function remove(id){
    return tx(STORE, 'readwrite', os => os.delete(id));
  }

  async function get(id){
    return tx(STORE, 'readonly', os => new Promise((resolve) => {
      const r = os.get(id);
      r.onsuccess = () => resolve(r.result || null);
      r.onerror   = () => resolve(null);
    }));
  }

  async function listByYMD(ymd){
    return tx(STORE, 'readonly', os => new Promise((resolve) => {
      const idx = os.index('by_ymd');
      const req = idx.getAll(ymd);
      req.onsuccess = () =>
        resolve((req.result || []).sort((a,b) => (a.created || 0) - (b.created || 0)));
      req.onerror   = () => resolve([]);
    }));
  }

  async function listRange(fromYmd, toYmd){
    const db = await openDB();
    return new Promise((resolve) => {
      const t   = db.transaction(STORE, 'readonly');
      const os  = t.objectStore(STORE);
      const idx = os.index('by_ymd');
      const range = IDBKeyRange.bound(fromYmd, toYmd);
      const out   = [];
      const cur   = idx.openCursor(range);

      cur.onsuccess = () => {
        const c = cur.result;
        if (!c){
          resolve(out.sort((a,b) => (a.created || 0) - (b.created || 0)));
          return;
        }
        out.push(c.value);
        c.continue();
      };
      cur.onerror = () => resolve(out);
    });
  }

  async function statsFor(records){
    let pending = 0, running = 0, success = 0, error = 0;
    records.forEach(r => {
      if      (['pending','queued','retry'].includes(r.status)) pending++;
      else if (r.status === 'running') running++;
      else if (r.status === 'success') success++;
      else if (['error','fatal','auth_paused'].includes(r.status))   error++;
    });
    return { pending, running, success, error, total: records.length };
  }

  async function onEnqueue(job){
    const base = normalizeFromQueue(job, { status: job.status || 'pending', progress:0 });
    if (Array.isArray(job.files)) base.counts.photos = job.files.length;
    return upsert(base);
  }

  async function onStart(job){
    const cur = await get(job.id);
    const rec = normalizeFromQueue(
      job,
      Object.assign({}, cur || {}, {
        status:'running',
        progress:50,
        attempts: (job.attempts || (cur && cur.attempts) || 0),
        lastTryAt: job.lastTryAt || Date.now()
      })
    );
    return upsert(rec);
  }

  async function onProgress(job, pctVal){
    const cur = await get(job.id);
    const pct = Math.max(1, Math.min(99, pctVal || 60));

    if (!cur){
      return upsert(
        normalizeFromQueue(job, { status:'running', progress:pct })
      );
    }

    cur.status   = 'running';
    cur.progress = Math.max(cur.progress || 0, pct);
    cur.updated  = now();
    return upsert(cur);
  }

  async function onSuccess(job, response, httpStatus){
    const cur = await get(job.id);

    // Base: marcar éxito y visita_id
    const base = Object.assign({}, cur || {}, {
      status   : 'success',
      progress : 100,
      error    : null,
      last_error: null,
      http_status: httpStatus || 200,
      request_id : (response && (response.request_id || response.req_id)) || null,
      visita_id: (response && response.visita_id) ||
                 (cur && cur.visita_id) ||
                 null
    });

    // NUEVO: persistir estado_final y fecha_reagendada (u otra fecha relevante)
    const estadoFinal =
      (response && (response.estado_final || response.estado_gestion)) ||
      (job.meta && job.meta.estado_final) ||
      null;

    const fechaReag = response &&
      (response.fecha_propuesta ||
       response.fecha_reagendada ||
       response.fecha_visita) || null;

    if (!base.vars) base.vars = {};
    if (estadoFinal) base.vars.estado_final     = estadoFinal;
    if (fechaReag)   base.vars.fecha_reagendada = fechaReag;

    const rec = normalizeFromQueue(job, base);
    return upsert(rec);
  }

  async function onError(job, errorMessage, httpStatus){
    const cur = await get(job.id);
    const rec = normalizeFromQueue(
      job,
      Object.assign({}, cur || {}, {
        status  : job.status || 'error',
        progress: (cur && cur.progress > 50) ? cur.progress : 50,
        error   : String(errorMessage || 'Error'),
        last_error: String(errorMessage || 'Error'),
        last_error_code: job && job.lastErrorCode || null,
        http_status: httpStatus || (cur && cur.http_status) || null
      })
    );
    return upsert(rec);
  }

  async function clearUploadedFor(ymd){
    const rows = await listByYMD(ymd);
    await Promise.all(
      rows
        .filter(r => r.status === 'success')
        .map(r => remove(r.id))
    );
    return true;
  }

  // ---- Resolver nombres extendiendo agenda a todo el rango cacheado ----
  async function _scanAgendaAnyDay(localId){
    try{
      if (!window.V2Cache || !V2Cache.get || !V2Cache.listToday) return null;
      const mf   = await V2Cache.get('meta','manifest');
      const from = mf && mf.from;
      const to   = mf && mf.to;
      if (!from || !to) return null;

      let d   = new Date(from + 'T00:00:00');
      const end = new Date(to + 'T00:00:00');

      while (d <= end){
        const ymd  = d.toISOString().slice(0,10);
        const list = await V2Cache.listToday(ymd);
        const hit  = (list || []).find(a => Number(a?.local?.id_local) === Number(localId));
        if (hit && hit.local){
          return {
            nombre   : hit.local.nombre    || null,
            direccion: hit.local.direccion || null,
            comuna   : hit.local.comuna    || null
          };
        }
        d.setDate(d.getDate() + 1);
      }
    }catch(_){}
    return null;
  }

  async function resolveNamesIfPossible(rec){
    let changed = false;

    try{
      if (window.V2Cache){
        // 1) Intentar locals (trae nombre + código + dirección/comuna si están)
        if (rec.local_id &&
            (!rec.names || !rec.names.local || !rec.names.codigo || !rec.names.direccion)){
          const l = await V2Cache.get('locals', Number(rec.local_id));
          if (l){
            rec.names = rec.names || {};
            rec.names.local     = l.nombre    || l.codigo || ('Local #'+rec.local_id);
            rec.names.codigo    = l.codigo    || rec.names.codigo    || null;
            rec.names.comuna    = l.comuna    || rec.names.comuna    || null;
            rec.names.direccion = l.direccion || rec.names.direccion || null;
            changed = true;
          }
        }

        // 2) Fallback: agenda del MISMO día
        if ((!rec.names || !rec.names.local || !rec.names.direccion) &&
            rec.ymd && rec.local_id){
          const agenda = await V2Cache.listToday(rec.ymd);
          const hit = (agenda || [])
            .find(a => Number(a?.local?.id_local) === Number(rec.local_id));

          if (hit){
            rec.names = rec.names || {};
            rec.names.local     = hit.local?.nombre    || rec.names.local || ('Local #'+rec.local_id);
            rec.names.direccion = hit.local?.direccion || rec.names.direccion || null;
            rec.names.comuna    = hit.local?.comuna    || rec.names.comuna    || null;
            changed = true;
          }
        }

        // 3) Fallback ampliado: agenda de CUALQUIER día del rango cacheado
        if ((!rec.names || !rec.names.local || !rec.names.direccion) && rec.local_id){
          const best = await _scanAgendaAnyDay(rec.local_id);
          if (best){
            rec.names = rec.names || {};
            rec.names.local     = best.nombre    || rec.names.local || ('Local #'+rec.local_id);
            rec.names.direccion = best.direccion || rec.names.direccion || null;
            rec.names.comuna    = best.comuna    || rec.names.comuna    || null;
            changed = true;
          }
        }

        // 4) Campaña
        if (rec.form_id && (!rec.names || !rec.names.campaign)){
          const c = await V2Cache.get('campaigns', Number(rec.form_id));
          if (c){
            rec.names = rec.names || {};
            rec.names.campaign = c.nombre || ('Campaña #'+rec.form_id);
            changed = true;
          }
        }

        if (changed) await upsert(rec);
      }
    }catch(_){}

    return rec;
  }

  window.JournalDB = {
    ymdLocal,
    openDB,
    upsert,
    get,
    remove,
    listByYMD,
    listRange,
    statsFor,
    onEnqueue,
    onStart,
    onProgress,
    onSuccess,
    onError,
    clearUploadedFor,
    resolveNamesIfPossible
  };
})();
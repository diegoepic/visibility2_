
(function(){
  'use strict';

  const DB_NAME = 'v2_journal';
  const DB_VER  = 9;
  const STORE   = 'journal';
  const STORE_EVENTS = 'events';

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
        let os;
        if (!db.objectStoreNames.contains(STORE)) {
          os = db.createObjectStore(STORE, { keyPath:'id' });
        } else {
          os = e.currentTarget.transaction.objectStore(STORE);
        }
        if (!os.indexNames.contains('by_ymd'))     os.createIndex('by_ymd',     'ymd',         { unique:false });
        if (!os.indexNames.contains('by_status'))  os.createIndex('by_status',  'status',      { unique:false });
        if (!os.indexNames.contains('by_created')) os.createIndex('by_created', 'created',     { unique:false });
        if (!os.indexNames.contains('by_kind'))    os.createIndex('by_kind',    'kind',        { unique:false });
        if (!os.indexNames.contains('by_guid'))    os.createIndex('by_guid',    'client_guid', { unique:false });
        if (!db.objectStoreNames.contains(STORE_EVENTS)) {
          const ev = db.createObjectStore(STORE_EVENTS, { keyPath:'id' });
          ev.createIndex('by_job', 'job_id', { unique:false });
          ev.createIndex('by_created', 'created', { unique:false });
        }
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
      status  : 'queued',
      progress: 0,
      kind    : meta.kind || job.type || 'generic',

      local_id : meta.local_id || fields.id_local      || fields.idLocal     || null,
      form_id  : meta.form_id  || fields.id_formulario || fields.idCampana   || null,
      client_guid    : meta.client_guid || job.client_guid || fields.client_guid || null,
      visita_local_id: fields.visita_local_id || null,
      visita_id      : null,

      http_status: (patch && patch.http_status) || null,
      request_id : (patch && patch.request_id)  || null,
      last_error : (patch && patch.last_error)  || null,
      attempts   : (patch && patch.attempts)    || job.attempts || 0,
      next_try_at: (patch && patch.next_try_at) || job.nextTryAt || job.nextTry || null,
      started_at : (patch && patch.started_at)  || job.startedAt || null,
      finished_at: (patch && patch.finished_at) || job.finishedAt || null,

      counts: { photos:0, answers:0 },

      names: {
         local   : meta.local_name      || meta.local      || fields.nombre_local    || null,
        codigo   : meta.local_codigo    || meta.codigo     || fields.codigo_local    || null,
        direccion: meta.local_direccion || meta.direccion  || fields.direccionLocal  || fields.direccion_local || null,
        comuna   : meta.local_comuna    || fields.comuna   || fields.comunaLocal     || null,
        campaign : meta.campaign_name   || meta.nombre_campana || fields.nombreCampana || null
      },

      vars : {},
      error: null
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

  async function addEvent(ev){
    return tx(STORE_EVENTS, 'readwrite', os => {
      os.put(ev);
      return true;
    });
  }

  function buildEvent(job, payload){
    const nowTs = now();
    return Object.assign({
      id: `${job.id}:${nowTs}:${Math.random().toString(16).slice(2)}`,
      job_id: job.id,
      created: nowTs,
      type: payload && payload.type ? payload.type : 'attempt',
      status: payload && payload.status ? payload.status : null,
      http_status: payload && payload.http_status ? payload.http_status : null,
      error: payload && payload.error ? payload.error : null,
      message: payload && payload.message ? payload.message : null,
      attempts: payload && payload.attempts ? payload.attempts : null,
      url: payload && payload.url ? payload.url : null
    }, payload || {});
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
    let pending = 0, running = 0, success = 0, error = 0, blocked = 0;
    records.forEach(r => {
      if      (r.status === 'queued') pending++;
      else if (r.status === 'running') running++;
      else if (r.status === 'success') success++;
      else if (r.status === 'error')   error++;
      else if (r.status === 'blocked_auth' || r.status === 'blocked_csrf') blocked++;
    });
    return { pending, running, success, error, blocked, total: records.length };
  }

  async function onEnqueue(job){
    const base = normalizeFromQueue(job, { status:'queued', progress:0 });
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
        attempts: job.attempts || (cur && cur.attempts) || 0,
        started_at: job.startedAt || Date.now()
      })
    );
    await addEvent(buildEvent(job, {
      type: 'attempt_start',
      status: 'running',
      attempts: rec.attempts,
      url: job.url || null
    }));
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

  /**
   * Limpia registros stale (error/blocked) relacionados con un job exitoso.
   * Usa client_guid, visita_local_id o local_id+form_id+kind como criterio.
   */
  async function cleanupStaleRecords(successJob){
    try {
      const meta = successJob.meta || {};
      const fields = successJob.fields || {};
      const clientGuid = meta.client_guid || successJob.client_guid || fields.client_guid;
      const visitaLocalId = fields.visita_local_id;
      const localId = meta.local_id || fields.id_local || fields.idLocal;
      const formId = meta.form_id || fields.id_formulario || fields.idCampana;
      const kind = (meta.kind || successJob.type || '').toLowerCase();
      const successId = successJob.id;

      // Obtener todos los registros del mismo día
      const ymd = ymdLocal(new Date());
      const rows = await listByYMD(ymd);

      // Buscar registros relacionados en estado error/blocked
      const staleIds = [];
      for (const r of rows){
        if (r.id === successId) continue; // No eliminar el registro exitoso
        if (r.status !== 'error' && r.status !== 'blocked_auth' && r.status !== 'blocked_csrf') continue;

        let isRelated = false;
        // Criterio 1: Mismo client_guid
        if (clientGuid && r.client_guid === clientGuid) {
          isRelated = true;
        }
        // Criterio 2: Mismo visita_local_id y kind
        if (!isRelated && visitaLocalId && r.visita_local_id === visitaLocalId) {
          const rKind = (r.kind || '').toLowerCase();
          if (rKind === kind) isRelated = true;
        }
        // Criterio 3: Mismo local_id + form_id + kind
        if (!isRelated && localId && formId) {
          const rLocalId = r.local_id;
          const rFormId = r.form_id;
          const rKind = (r.kind || '').toLowerCase();
          if (rLocalId == localId && rFormId == formId && rKind === kind) {
            isRelated = true;
          }
        }

        if (isRelated) staleIds.push(r.id);
      }

      // Eliminar registros stale
      await Promise.all(staleIds.map(id => remove(id)));
      if (staleIds.length) {
        console.log('[JournalDB] Cleaned up', staleIds.length, 'stale records for job', successId);
      }
    } catch(err){
      console.warn('[JournalDB] Error cleaning stale records:', err);
    }
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
      finished_at: Date.now(),
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
    await addEvent(buildEvent(job, {
      type: 'attempt_success',
      status: 'success',
      http_status: httpStatus || 200,
      attempts: rec.attempts,
      url: job.url || null
    }));

    // MEJORA: Limpiar registros stale relacionados
    await cleanupStaleRecords(job);

    return upsert(rec);
  }

  // Máximo de caracteres para el response truncado
  const MAX_RESPONSE_SNIPPET = 500;

  /**
   * Trunca un texto a un máximo de caracteres
   */
  function truncateText(text, maxLen = MAX_RESPONSE_SNIPPET) {
    if (!text || typeof text !== 'string') return null;
    if (text.length <= maxLen) return text;
    return text.slice(0, maxLen) + '... [truncated]';
  }

  async function onError(job, errorMessage, httpStatus, responseBody){
    const cur = await get(job.id);
    const errObj = (errorMessage && typeof errorMessage === 'object')
      ? errorMessage
      : { message: String(errorMessage || 'Error') };

    // MEJORA: Guardar response truncado para diagnóstico
    let responseSnippet = null;
    if (responseBody) {
      if (typeof responseBody === 'string') {
        responseSnippet = truncateText(responseBody);
      } else if (typeof responseBody === 'object') {
        try {
          responseSnippet = truncateText(JSON.stringify(responseBody));
        } catch(_) {}
      }
    } else if (errObj.responseSnippet) {
      responseSnippet = truncateText(errObj.responseSnippet);
    }

    // MEJORA: Extraer error_id del servidor si viene
    const error_id = errObj.error_id ||
                     (responseBody && responseBody.error_id) ||
                     null;

    const rec = normalizeFromQueue(
      job,
      Object.assign({}, cur || {}, {
        status  : job.status || 'error',
        progress: (cur && cur.progress > 50) ? cur.progress : 50,
        error   : errObj.message || String(errorMessage || 'Error'),
        last_error: Object.assign({}, errObj, {
          responseSnippet: responseSnippet,
          error_id: error_id,
          timestamp: Date.now()
        }),
        http_status: httpStatus || (cur && cur.http_status) || errObj.httpStatus || null,
        next_try_at: job.nextTryAt || (cur && cur.next_try_at) || null,
        attempts: job.attempts || (cur && cur.attempts) || 0
      })
    );

    await addEvent(buildEvent(job, {
      type: 'attempt_error',
      status: rec.status,
      http_status: rec.http_status,
      error: errObj.code || errObj.message || 'ERROR',
      error_id: error_id,
      message: errObj.message || String(errorMessage || 'Error'),
      responseSnippet: responseSnippet,
      attempts: rec.attempts,
      url: job.url || null
    }));
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

  async function cleanup({ maxDays = 7, maxEvents = 1000, maxSuccessDays = 3 } = {}){
    const cutoff = Date.now() - (maxDays * 24 * 60 * 60 * 1000);
    const successCutoff = Date.now() - (maxSuccessDays * 24 * 60 * 60 * 1000);
    const rows = await listRange('2000-01-01', '2999-12-31');
    await Promise.all(rows.map(async (r) => {
      if (r.created && r.created < cutoff) {
        await remove(r.id);
      }
      if (r.status === 'success' && r.created && r.created < successCutoff) {
        await remove(r.id);
      }
    }));

    const events = await listEvents(maxEvents + 200);
    if (events.length > maxEvents) {
      const overflow = events.slice(0, events.length - maxEvents);
      await tx(STORE_EVENTS, 'readwrite', os => {
        overflow.forEach(ev => os.delete(ev.id));
      });
    }
  }

  async function listEvents(limit = 200){
    const db = await openDB();
    return new Promise((resolve) => {
      const t   = db.transaction(STORE_EVENTS, 'readonly');
      const os  = t.objectStore(STORE_EVENTS);
      const idx = os.index('by_created');
      const out = [];
      const req = idx.openCursor(null, 'prev');
      req.onsuccess = () => {
        const cur = req.result;
        if (!cur || out.length >= limit) {
          resolve(out);
          return;
        }
        out.push(cur.value);
        cur.continue();
      };
      req.onerror = () => resolve(out);
    });
  }

  async function listEventsForJob(jobId, limit = 50){
    const db = await openDB();
    return new Promise((resolve) => {
      const t   = db.transaction(STORE_EVENTS, 'readonly');
      const os  = t.objectStore(STORE_EVENTS);
      const idx = os.index('by_job');
      const range = IDBKeyRange.only(jobId);
      const out = [];
      const req = idx.openCursor(range, 'prev');
      req.onsuccess = () => {
        const cur = req.result;
        if (!cur || out.length >= limit) {
          resolve(out);
          return;
        }
        out.push(cur.value);
        cur.continue();
      };
      req.onerror = () => resolve(out);
    });
  }

  async function exportRecent(limit = 200){
    const events = await listEvents(limit);
    return { exported_at: new Date().toISOString(), events };
  }

  async function buildDiagnosticPayload(limit = 200){
    const nowIso   = new Date().toISOString();
    const ua       = (typeof navigator !== 'undefined' && navigator && navigator.userAgent) ? navigator.userAgent : null;
    const online   = (typeof navigator !== 'undefined' && navigator) ? navigator.onLine : null;
    const swVer    = (typeof window !== 'undefined' && window) ? (window.SW_VERSION || null) : null;

    const todayYmd   = ymdLocal(new Date());
    const todayRows  = await listByYMD(todayYmd);
    const todayStats = await statsFor(todayRows);

    const allRows    = await listRange('2000-01-01', '2999-12-31');
    const totalStats = await statsFor(allRows);

    const events     = await listEvents(limit);

    const queueSnapshot = {
      total_records: allRows.length,
      last_created_at: allRows.length ? Math.max(...allRows.map(r => r.created || 0)) : null,
      last_updated_at: allRows.length ? Math.max(...allRows.map(r => r.updated || r.created || 0)) : null
    };

    return {
      exported_at : nowIso,
      navigator_onLine: online,
      user_agent  : ua,
      sw_version  : swVer,
      stats: {
        today: Object.assign({ ymd: todayYmd }, todayStats),
        overall: totalStats
      },
      queue: queueSnapshot,
      recent_events: events
    };
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
    cleanup,
    listEvents,
    listEventsForJob,
    exportRecent,
    buildDiagnosticPayload,
    resolveNamesIfPossible
  };
})();
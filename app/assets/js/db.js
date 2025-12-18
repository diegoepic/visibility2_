
// Simple wrapper IndexedDB para cola offline (v2)
// Ahora incluye máquina de estados y campos extendidos para diagnóstico.
(function(){
  const DB_NAME = 'v2_offline';
  const DB_VER  = 8;
  const STORE   = 'queue';

  function openDB(){
    return new Promise((res, rej) => {
      const req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = e => {
        const db = e.target.result;
        let os;
        if (!db.objectStoreNames.contains(STORE)) {
          os = db.createObjectStore(STORE, { keyPath: 'id' });
        } else {
          os = e.currentTarget.transaction.objectStore(STORE);
        }

        // Índices para nueva máquina de estados
        if (!os.indexNames.contains('status'))    os.createIndex('status', 'status', { unique: false });
        if (!os.indexNames.contains('created'))   os.createIndex('created', 'created', { unique: false });
        if (!os.indexNames.contains('type'))      os.createIndex('type', 'type', { unique: false });
        if (!os.indexNames.contains('dedupeKey')) os.createIndex('dedupeKey', 'dedupeKey', { unique: false });
        if (!os.indexNames.contains('nextTry'))   os.createIndex('nextTry', 'nextTry', { unique: false });

        // Migración ligera: normalizar registros existentes a nuevo esquema
        os.openCursor().onsuccess = ev => {
          const cur = ev.target.result;
          if (!cur) return;
          const val = normalizeTask(cur.value || {});
          cur.update(val);
          cur.continue();
        };
      };
      req.onsuccess = () => res(req.result);
      req.onerror   = () => rej(req.error);
    });
  }

  function normalizeTask(raw){
    const now = Date.now();
    const statusMap = {
      pending: 'queued',
      running: 'retry',
      done:    'success'
    };
    const normStatus = raw.status && statusMap[raw.status] ? statusMap[raw.status] : (raw.status || 'queued');

    return Object.assign({
      id: raw.id || (crypto.randomUUID ? crypto.randomUUID() : String(now)),
      type: raw.type || 'generic',
      url: raw.url || '',
      method: raw.method || 'POST',
      headers: raw.headers || {},
      body: raw.body || null,
      fields: raw.fields || {},
      files: raw.files || [],
      status: normStatus,
      attempts: raw.attempts || 0,
      nextTry: raw.nextTry || 0,
      created: raw.created || now,
      lastTryAt: raw.lastTryAt || raw.lastTry || null,
      lastHttpStatus: raw.lastHttpStatus || null,
      lastErrorCode: raw.lastErrorCode || null,
      lastErrorMessage: raw.lastErrorMessage || raw.lastError || null,
      lastResponseSnippet: raw.lastResponseSnippet || null,
      durationMs: raw.durationMs || null,
      bytesSent: raw.bytesSent || null,
      progressPct: raw.progressPct || 0,
      idempotencyKey: raw.idempotencyKey || raw.id || null,
      dedupeKey: raw.dedupeKey || null,
      dependsOn: raw.dependsOn || [],
      meta: raw.meta || {},
      client_guid: raw.client_guid || null
    }, raw);
  }

  async function add(task){
    const db = await openDB();
    return new Promise((res, rej) => {
      const tx = db.transaction(STORE, 'readwrite');
      const os = tx.objectStore(STORE);

      const doAdd = () => {
        const rec = normalizeTask(task);
        os.add(rec);
      };

      if (task.dedupeKey) {
        const idx = os.index('dedupeKey');
        const r = idx.getAll(task.dedupeKey);
        r.onsuccess = () => {
          const dup = (r.result || []).find(it => ['pending','running'].includes(it.status));
          if (dup) { res(dup.id); try { tx.abort(); } catch(_e){} return; }
          doAdd();
        };
        r.onerror = () => rej(r.error);
      } else {
        doAdd();
      }

      tx.oncomplete = () => res(task.id);
      tx.onabort    = () => { /* abort esperado en dedupe */ };
      tx.onerror    = () => rej(tx.error);
    });
  }

  async function listByStatus(status='queued'){
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readonly');
      const os = t.objectStore(STORE);
      const idx = os.index('status');
      const req = idx.getAll(status);
      req.onsuccess = () => {
        const out = (req.result || []).map(normalizeTask).sort((a,b)=>a.created - b.created);
        res(out);
      };
      req.onerror = () => rej(req.error);
    });
  }

  async function get(id){
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readonly');
      const os = t.objectStore(STORE);
      const req = os.get(id);
      req.onsuccess = () => res(req.result ? normalizeTask(req.result) : null);
      req.onerror   = () => rej(req.error);
    });
  }

  // Update “estricto”: si no existe → resolve(null), sin lanzar excepción
  async function update(id, patch) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      let done = false;
      let result = null;

      const tx = db.transaction(STORE, 'readwrite');
      const os = tx.objectStore(STORE);
      const req = os.get(id);

      req.onerror = () => { if (!done) { done = true; reject(req.error); } };

      req.onsuccess = () => {
        const current = req.result;
        if (!current) {
          // No existe -> abortamos transacción y devolvemos null
          done = true;
          try { tx.abort(); } catch (_) {}
          return resolve(null);
        }
        result = normalizeTask({ ...current, ...patch, updated: Date.now() });
        const putReq = os.put(result);
        putReq.onerror = () => { if (!done) { done = true; reject(putReq.error); } };
        // Importante: resolvemos en tx.oncomplete para garantizar persistencia
      };

      tx.oncomplete = () => { if (!done) { done = true; resolve(result); } };
      tx.onabort    = () => { if (!done) { done = true; resolve(null); } };
      tx.onerror    = () => { if (!done) { done = true; reject(tx.error || new Error('tx error')); } };
    });
  }

  async function remove(id){
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readwrite');
      t.objectStore(STORE).delete(id);
      t.oncomplete = () => res();
      t.onerror    = () => rej(t.error);
    });
  }

  window.AppDB = { add, listByStatus, get, update, remove };
})();


// Wrapper IndexedDB para cola offline (estado robusto)
(function(){
  const DB_NAME = 'v2_offline';
  const DB_VER  = 9;
  const STORE   = 'queue';

  const STATUS_ALIASES = {
    pending: 'queued',
    done: 'success'
  };

  function normalizeStatus(status){
    const s = String(status || '').toLowerCase();
    return STATUS_ALIASES[s] || s || 'queued';
  }

  function normalizeJob(job){
    if (!job || typeof job !== 'object') return null;
    const normalized = {
      id: job.id || (crypto.randomUUID ? crypto.randomUUID() : String(Date.now())),
      type: job.type || 'generic',
      createdAt: job.createdAt || job.created || Date.now(),
      updatedAt: job.updatedAt || job.updated || Date.now(),
      status: normalizeStatus(job.status || 'queued'),
      attempts: Number.isFinite(job.attempts) ? job.attempts : 0,
      maxAttempts: Number.isFinite(job.maxAttempts) ? job.maxAttempts : 8,
      nextTryAt: job.nextTryAt || job.nextTry || 0,
      startedAt: job.startedAt || job.lastTry || null,
      finishedAt: job.finishedAt || null,
      lastError: job.lastError || null,
      fields: job.fields || null,
      files: job.files || null,
      url: job.url || '',
      method: job.method || 'POST',
      timeoutMs: job.timeoutMs || null,
      sendCSRF: job.sendCSRF !== false,
      dedupeKey: job.dedupeKey || (job.meta && job.meta.dedupeKey) || null,
      dependsOn: job.dependsOn || (job.meta && job.meta.dependsOn) || null,
      client_guid: job.client_guid || null,
      meta: job.meta || {}
    };

    if (!normalized.meta) normalized.meta = {};
    if (!normalized.meta.idempotencyKey && job.id) normalized.meta.idempotencyKey = job.id;
    if (!normalized.meta.dedupeKey && normalized.dedupeKey) normalized.meta.dedupeKey = normalized.dedupeKey;
    if (!normalized.meta.dependsOn && normalized.dependsOn) normalized.meta.dependsOn = normalized.dependsOn;
    return normalized;
  }

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
        if (!os.indexNames.contains('status'))    os.createIndex('status', 'status', { unique: false });
        if (!os.indexNames.contains('createdAt')) os.createIndex('createdAt', 'createdAt', { unique: false });
        if (!os.indexNames.contains('type'))      os.createIndex('type', 'type', { unique: false });
        if (!os.indexNames.contains('dedupeKey')) os.createIndex('dedupeKey', 'dedupeKey', { unique: false });
        if (!os.indexNames.contains('nextTryAt')) os.createIndex('nextTryAt', 'nextTryAt', { unique: false });
        if (!os.indexNames.contains('updatedAt')) os.createIndex('updatedAt', 'updatedAt', { unique: false });
      };
      req.onsuccess = () => res(req.result);
      req.onerror   = () => rej(req.error);
    });
  }

  async function add(task){
    const db = await openDB();
    return new Promise((res, rej) => {
      const tx = db.transaction(STORE, 'readwrite');
      const os = tx.objectStore(STORE);

      const rec = normalizeJob(task);
      rec.status = normalizeStatus(rec.status || 'queued');
      rec.createdAt = rec.createdAt || Date.now();
      rec.updatedAt = Date.now();
      rec.attempts = rec.attempts || 0;
      rec.nextTryAt = rec.nextTryAt || 0;

      const doAdd = () => {
        os.add(rec);
      };

      if (rec.dedupeKey) {
        const idx = os.index('dedupeKey');
        const r = idx.getAll(rec.dedupeKey);
        r.onsuccess = () => {
          const dup = (r.result || []).find(it => ['queued','running'].includes(normalizeStatus(it.status)));
          if (dup) { res(dup.id); try { tx.abort(); } catch(_e){} return; }
          doAdd();
        };
        r.onerror = () => rej(r.error);
      } else {
        doAdd();
      }

      tx.oncomplete = () => res(rec.id);
      tx.onabort    = () => { /* abort esperado en dedupe */ };
      tx.onerror    = () => rej(tx.error);
    });
  }

  async function listByStatus(status='queued'){
    const db = await openDB();
    const normalizedStatus = normalizeStatus(status);
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readonly');
      const os = t.objectStore(STORE);
      const idx = os.index('status');
      const req = idx.getAll(normalizedStatus);
      req.onsuccess = async () => {
        const out = (req.result || [])
          .map(normalizeJob)
          .filter(Boolean)
          .sort((a,b)=>a.createdAt - b.createdAt);
        res(out);
      };
      req.onerror = () => rej(req.error);
    });
  }

  async function listAll(){
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readonly');
      const os = t.objectStore(STORE);
      const req = os.getAll();
      req.onsuccess = () => {
        const out = (req.result || []).map(normalizeJob).filter(Boolean);
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
      req.onsuccess = () => res(normalizeJob(req.result || null));
      req.onerror   = () => rej(req.error);
    });
  }

  // Update "tolerante": si no existe → resolve con flag __notFound, no lanza excepción
  // Esto evita que drain() se congele cuando un job desaparece en paralelo
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
        const current = normalizeJob(req.result);
        if (!current) {
          done = true;
          try { tx.abort(); } catch (_) {}
          // PATCH 1: Resolve con flag en vez de reject para no crashear drain()
          console.warn('[AppDB] Job no encontrado para update:', id);
          return resolve({ __notFound: true, id: id });
        }
        result = { ...current, ...patch, updatedAt: Date.now() };
        if (patch && patch.status) result.status = normalizeStatus(patch.status);
        const putReq = os.put(result);
        putReq.onerror = () => { if (!done) { done = true; reject(putReq.error); } };
      };

      tx.oncomplete = () => { if (!done) { done = true; resolve(result); } };
      tx.onabort    = () => { if (!done) { done = true; resolve({ __notFound: true, id: id }); } };
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

  window.AppDB = {
    add,
    listByStatus,
    listAll,
    get,
    update,
    remove,
    normalizeJob
  };
})();

// Simple wrapper IndexedDB para cola offline
(function(){
  const DB_NAME = 'v2_offline';
  const DB_VER  = 6;
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
        if (!os.indexNames.contains('status'))    os.createIndex('status', 'status', { unique: false });
        if (!os.indexNames.contains('created'))   os.createIndex('created', 'created', { unique: false });
        if (!os.indexNames.contains('type'))      os.createIndex('type', 'type', { unique: false });
        if (!os.indexNames.contains('dedupeKey')) os.createIndex('dedupeKey', 'dedupeKey', { unique: false });
        if (!os.indexNames.contains('nextTry'))   os.createIndex('nextTry', 'nextTry', { unique: false });
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

      const doAdd = () => {
        if (!task.id) task.id = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random());
        task.created  = Date.now();
        task.status   = task.status || 'pending';
        task.attempts = task.attempts || 0;
        task.nextTry  = task.nextTry || 0;
        os.add(task);
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

  async function listByStatus(status='pending'){
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction(STORE, 'readonly');
      const os = t.objectStore(STORE);
      const idx = os.index('status');
      const req = idx.getAll(status);
      req.onsuccess = () => {
        const out = (req.result || []).sort((a,b)=>a.created - b.created);
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
      req.onsuccess = () => res(req.result || null);
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
        result = { ...current, ...patch, updated: Date.now() };
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

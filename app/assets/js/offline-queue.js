/* /visibility2/app/assets/js/offline-queue.js
   Cola offline: reintentos con backoff, idempotencia, refresco CSRF,
   encadenamiento de create_visita -> procesar_gestion, y
   EMISIÓN DE EVENTOS para UI de "Avance" (queue:enqueue, :start, :progress, :success, :error).
*/
(function(){
  'use strict';

  const BASE          = '/visibility2/app';
  const CSRF_ENDPOINT = BASE + '/csrf_refresh.php';
  const PING_ENDPOINT = BASE + '/ping.php';

  // --------------------------------------------------------------------------------
  // Event bus (para UI Avance)
  // --------------------------------------------------------------------------------
  const QueueEvents = {
    emit(name, detail){ try{ window.dispatchEvent(new CustomEvent(name, { detail })); }catch(_){/* noop */} },
    on(name, handler){ window.addEventListener(name, handler); }
  };

  // Atajo para llamar a JournalDB.* si existe
  function jr(fnName, ...args){
    try {
      if (window.JournalDB && typeof JournalDB[fnName] === 'function') {
        // fire-and-forget
        JournalDB[fnName](...args);
      }
    } catch(_){}
  }

  // --------------------------------------------------------------------------------
  // Utilidades de sesión/CSRF
  // --------------------------------------------------------------------------------
  async function refreshCSRF(){
    try {
      const r  = await fetch(CSRF_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' });
      if (r.status === 401) return null; // sin sesión
      const js = await r.json();
      if (js && js.csrf_token) {
        window.CSRF_TOKEN = js.csrf_token;
        return js.csrf_token;
      }
    } catch(_) {}
    return null;
  }

  async function heartbeat(){
    try {
      const r = await fetch(PING_ENDPOINT, { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return false;
      const js = await r.json();
      if (js && js.csrf_token) window.CSRF_TOKEN = js.csrf_token;
      return !!(js && js.status === 'ok');
    } catch(_) { return false; }
  }

  // --------------------------------------------------------------------------------
  // Construcción segura de FormData
  // --------------------------------------------------------------------------------
  function buildFormData(task){
    const fd = new FormData();

    if (task.fields) {
      for (const [k,v] of Object.entries(task.fields)) {
        if (Array.isArray(v)) v.forEach(val => fd.append(k, String(val)));
        else if (v !== undefined && v !== null) fd.append(k, String(v));
      }
    }

    if (Array.isArray(task.files)) {
      for (const f of task.files) {
        const name = f.filename || 'file';
        const blob = f.blob instanceof Blob ? f.blob : new Blob([f.blob], { type: f.type || 'application/octet-stream' });
        fd.append(f.field, new File([blob], name, { type: blob.type || f.type || 'application/octet-stream' }));
      }
    }

    if (task.sendCSRF !== false) {
      const token = window.CSRF_TOKEN || '';
      if (token) {
        fd.append('csrf_token', token);
      }
    }

    // Copia del idempotency key en el cuerpo (por si el backend lo mira aquí)
    fd.append('X_Idempotency_Key', task.id || (crypto.randomUUID ? crypto.randomUUID() : String(Date.now())));
    return fd;
  }

  // --------------------------------------------------------------------------------
  // POST con idempotencia + refresco CSRF
  // --------------------------------------------------------------------------------
  async function httpPost(task){
    if (!task || !task.url) throw new Error('Task sin URL');

    // SIEMPRE refrescamos CSRF para evitar drift
    await refreshCSRF();

    let url = task.url;
    if (!/^https?:\/\//i.test(url)) {
      url = url.startsWith('/') ? url : `${BASE.replace(/\/$/,'')}/${url.replace(/^\//,'')}`;
    }

    const fd = buildFormData(task);
    const headers = {};
    if (task.id) headers['X-Idempotency-Key'] = task.id;
    if (window.CSRF_TOKEN) headers['X-CSRF-Token'] = window.CSRF_TOKEN;

    let r;
    try {
      QueueEvents.emit('queue:dispatch:progress', { job: task, progress: 50 });
      r = await fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'include',      // ← más robusto que 'same-origin'
        cache: 'no-store',
        headers
      });
      QueueEvents.emit('queue:dispatch:progress', { job: task, progress: 90 });
    } catch (err) {
      throw new Error(`Network error calling ${url}: ${err && err.message ? err.message : err}`);
    }

    // Si el servidor dice CSRF inválido, reintenta una sola vez con token fresco
    if (r.status === 419 || r.status === 403) {
      const ok = await refreshCSRF();
      if (ok) {
        const fd2 = buildFormData(task);
        const headers2 = { ...headers, 'X-CSRF-Token': window.CSRF_TOKEN || '' };
        return fetch(url, { method:'POST', body: fd2, credentials:'include', cache:'no-store', headers: headers2 });
      }
    }
    return r;
  }

  // --------------------------------------------------------------------------------
  // Mapas auxiliares en localStorage
  // --------------------------------------------------------------------------------
  const Visits = {
    key: 'v2_visit_map', // { local-uuid: visita_id_real }
    load(){ try { return JSON.parse(localStorage.getItem(this.key) || '{}'); } catch(_) { return {}; } },
    save(m){ localStorage.setItem(this.key, JSON.stringify(m)); },
    set(localId, realId){ const m = this.load(); m[localId]=realId; this.save(m); },
    get(localId){ const m = this.load(); return m[localId] || null; }
  };

  const LocalByGuid = {
    key: 'v2_local_by_guid', // { client_guid: 'local-uuid' }
    load(){ try { return JSON.parse(localStorage.getItem(this.key) || '{}'); } catch(_) { return {}; } },
    save(m){ localStorage.setItem(this.key, JSON.stringify(m)); },
    set(g, lid){ const m=this.load(); m[g]=lid; this.save(m); },
    get(g){ return this.load()[g] || null; },
    del(g){ const m=this.load(); delete m[g]; this.save(m); }
  };

  const CompletedDeps = {
    key: 'v2_completed_deps', // ['create:<client_guid>', ...]
    all(){ try{ return JSON.parse(localStorage.getItem(this.key)||'[]'); }catch(_){ return []; } },
    has(tag){ return this.all().includes(tag); },
    add(tag){
      const m = this.all();
      if(!m.includes(tag)){ m.push(tag); localStorage.setItem(this.key, JSON.stringify(m)); }
    }
  };

  // --------------------------------------------------------------------------------
  // Helpers varios
  // --------------------------------------------------------------------------------
  function addField(fields, k, v){
    if (Object.prototype.hasOwnProperty.call(fields, k)) {
      if (Array.isArray(fields[k])) fields[k].push(v);
      else fields[k] = [fields[k], v];
    } else fields[k] = v;
  }

  function inferMetaFromTask(task){
    // meta mínima para UI Avance
    const f = task.fields || {};
    const meta = Object.assign(
      {
        // tipo lógico (kind): por default el "type" de la tarea
        kind: task.type || 'generic',
        client_guid: task.client_guid || f.client_guid || null,
        form_id: f.id_formulario || f.idCampana || null,
        local_id: f.id_local || f.idLocal || null,
        idFQ: f.idFQ || f.id_form_question || null
      },
      task.meta || {}
    );
    return meta;
  }

  // --------------------------------------------------------------------------------
  // Ejecución de tareas
  // --------------------------------------------------------------------------------
  async function processTask(task){
    // Resolver visita local desde GUID si aplica
    if (task.fields && !task.fields.visita_id && task.fields.client_guid) {
      const lid = LocalByGuid.get(task.fields.client_guid);
      if (lid) task.fields.visita_id = lid;
    }

    // Si la tarea hace referencia a una visita "local-*" -> traducir a real si ya existe
    if (task.fields && task.fields.visita_id && String(task.fields.visita_id).startsWith('local-')) {
      const real = Visits.get(String(task.fields.visita_id));
      if (real) task.fields.visita_id = real;
    }

    const r = await httpPost(task);
    if (!r.ok) throw new Error('HTTP ' + r.status);

    const js = await r.json().catch(()=> ({}));

    // Si es la creación de visita, persistir mapping y marcar dependencia como resuelta
    if (task.type === 'create_visita' && js && js.visita_id) {
      const localId = task.fields.visita_local_id;
      if (localId) Visits.set(String(localId), js.visita_id);

      if (task.fields.client_guid) {
        const depTag = `create:${task.fields.client_guid}`;
        CompletedDeps.add(depTag);
        LocalByGuid.set(task.fields.client_guid, js.visita_id);
      }
    }

    // Si es una tarea de PROCESAR GESTIÓN, marcamos que la agenda debe refrescarse
    //    Esto se usará en bootstrap_index_cache.js para forzar un sync_bundle()
    const typeStr = String(task.type || '');
    const urlStr  = String(task.url || '').toLowerCase();
    const isGestionTask =
      typeStr === 'procesar_gestion' ||
      typeStr === 'procesar_gestion_pruebas' ||
      urlStr.includes('procesar_gestion_pruebas.php') ||
      urlStr.includes('procesar_gestion.php');

    if (isGestionTask) {
      try {
        localStorage.setItem('v2_agenda_needs_refresh', '1');
      } catch(_) {}
      // Evento específico por si queremos enganchar UI o métricas
      QueueEvents.emit('queue:gestion_success', { job: task, response: js });
    }

    return js;
  }

  // --------------------------------------------------------------------------------
  // Backoff simple con jitter
  // --------------------------------------------------------------------------------
  async function backoff(ms){ return new Promise(r=>setTimeout(r, ms)); }

  // --------------------------------------------------------------------------------
  // Motor de drenaje
  // --------------------------------------------------------------------------------
  let _draining = false;
  async function drain(){
    if (_draining) return;
    if (!navigator.onLine) return;

    _draining = true;
    try {
      // (1) validar sesión, (2) traer CSRF si está disponible
      const alive = await heartbeat();
      if (!alive) { _draining = false; return; }

      const pendings = await AppDB.listByStatus('pending');
      const now = Date.now();
      // Para UI: badge/contador
      QueueEvents.emit('queue:update', { pending: pendings.length });

      for (const t of pendings) {
        if (t.nextTry && t.nextTry > now) continue;

        // Dependencias (ej. cerrar_gestion depende de create:<guid>)
        if (t.dependsOn && !CompletedDeps.has(t.dependsOn)) continue;

        // Rellenar meta si no existe
        t.meta = t.meta || inferMetaFromTask(t);

        // Marca running y anuncia inicio
        await AppDB.update(t.id, { status: 'running', attempts: (t.attempts||0)+1, lastTry: now });
        QueueEvents.emit('queue:dispatch:start', { job: t });
        jr('onStart', t);

        try {
          const js = await processTask(t);
          // Éxito → eliminar y anunciar
          await AppDB.remove(t.id);
          QueueEvents.emit('queue:dispatch:success', { job: t, responseStatus: 200, response: js });
          jr('onSuccess', t, js);
          // Back-compat del evento antiguo:
          window.dispatchEvent(new CustomEvent('queue:done', { detail: { id: t.id, type: t.type, response: js } }));
        } catch (err) {
          // Error → reprogramar con backoff y anunciar
          const attempts = (t.attempts||0) + 1;
          const base = Math.min(60000, 1000 * Math.pow(2, attempts));
          const jitter = Math.floor(Math.random() * 300);
          const wait = base + jitter;
          await AppDB.update(t.id, { status: 'pending', attempts, nextTry: now + wait, lastError: String(err) });
          QueueEvents.emit('queue:dispatch:error', { job: t, error: String(err) });
          jr('onError', t, String(err));
          await backoff(200);
        }
      }

      // Actualiza contador al final del ciclo
      const left = await AppDB.listByStatus('pending');
      QueueEvents.emit('queue:update', { pending: left.length });
    } finally {
      _draining = false;
    }
  }

  // --------------------------------------------------------------------------------
  // API pública de la cola
  // --------------------------------------------------------------------------------
  const Queue = {
    /**
     * Encola una tarea genérica.
     * @param {{url:string,type:string,fields?:Object,files?:Array,sendCSRF?:boolean,id?:string,dedupeKey?:string,dependsOn?:string,client_guid?:string,meta?:Object}} task
     */
    async enqueue(task){
      task.method   = 'POST';
      task.url      = task.url || '';
      task.type     = task.type || 'generic';
      task.sendCSRF = (task.sendCSRF !== false);

      if (!task.id) task.id = crypto.randomUUID ? crypto.randomUUID() : String(Date.now());

      // Tareas especiales: create_visita (genera id local y mapea por client_guid)
      if (task.type === 'create_visita') {
        if (!task.fields) task.fields = {};
        if (!task.fields.visita_local_id) {
          task.fields.visita_local_id = 'local-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now());
        }
        if (task.fields.client_guid) {
          LocalByGuid.set(task.fields.client_guid, task.fields.visita_local_id);
        }
      }

      // Meta para UI
      task.meta = task.meta || inferMetaFromTask(task);

      const id = await AppDB.add(task);  // (usa dedupe si viene dedupeKey)
      QueueEvents.emit('queue:enqueue', { job: task, id });
      jr('onEnqueue', task);
      // Evento histórico que ya usabas:
      window.dispatchEvent(new CustomEvent('queue:enqueued', { detail:{ id, type: task.type, url: task.url }}));

      if (navigator.onLine) drain();
      return id;
    },

    /**
     * Encola a partir de un <form> o un FormData
     */
    async enqueueFromForm(url, formOrFD, type='generic'){
      let fd;
      if (formOrFD instanceof FormData) fd = formOrFD;
      else if (formOrFD && formOrFD.tagName === 'FORM') fd = new FormData(formOrFD);
      else throw new TypeError('enqueueFromForm: expected HTMLFormElement or FormData');

      const fields = {}; const files = [];
      for (const [k, v] of fd.entries()) {
        if (v instanceof File) {
          if (v.size > 0) files.push({ field: k, blob: v, filename: v.name, type: v.type });
        } else {
          addField(fields, k, v);
        }
      }

      if (!fields['visita_id'] && fields['client_guid']) {
        const lid = LocalByGuid.get(fields['client_guid']);
        if (lid) fields['visita_id'] = lid;
      }

      const task = { url, type, fields, files, sendCSRF: true };
      task.meta = inferMetaFromTask(task);
      return this.enqueue(task);
    },

    /**
     * Intenta enviar inmediatamente; si falla (offline o error), encola.
     */
    smartPost: async function(url, fdOrFields, options = {}) {
      const type      = options.type || 'generic';
      const needCSRF  = options.sendCSRF !== false;

      let fields = {}, files = [];
      if (fdOrFields instanceof FormData) {
        for (const [k, v] of fdOrFields.entries()) {
          if (v instanceof File) files.push({ field: k, blob: v, filename: v.name, type: v.type });
          else addField(fields, k, v);
        }
      } else {
        fields = fdOrFields || {};
      }

      const task = {
        url,
        type,
        fields,
        files,
        sendCSRF: needCSRF,
        id: options.id || options.idempotencyKey || undefined,
        dedupeKey: options.dedupeKey || undefined,
        dependsOn: options.dependsOn || undefined,
        client_guid: options.client_guid || fields.client_guid || undefined
      };

      if (!task.id) {
        task.id = crypto.randomUUID ? crypto.randomUUID() : String(Date.now());
      }

      task.meta = task.meta || inferMetaFromTask(task);

      // Registrar en Journal también para gestiones ONLINE
      jr('onEnqueue', task);

      if (navigator.onLine) {
        try {
          await heartbeat();
          // Ahora el job SIEMPRE tiene id
          QueueEvents.emit('queue:dispatch:start', { job: task });
          jr('onStart', task);
          const js = await processTask(task);

          QueueEvents.emit('queue:dispatch:success', {
            job: task,
            responseStatus: 200,
            response: js
          });
          jr('onSuccess', task, js);
          return { queued:false, ok:true, response: js };
        } catch (e) {
          QueueEvents.emit('queue:dispatch:error', { job: task, error: String(e) });
          jr('onError', task, String(e));
        }
      }

      const id = await this.enqueue(task);
      return { queued:true, ok:true, id };
    },

    async listPending(){
      return AppDB.listByStatus('pending');
    },

    /**
     * Fuerza un intento de drenaje ahora (útil para botón "Reintentar ahora")
     */
    async flushNow(){
      await drain();
      return true;
    },

    /**
     * Suscripción a eventos de la cola (atajo)
     */
    on: QueueEvents.on,

    drain
  };

  window.addEventListener('online', () => setTimeout(drain, 250));
  document.addEventListener('DOMContentLoaded', () => setTimeout(drain, 600));

  // Export
  window.Queue = Queue;

  // --------------------------------------------------------------------------------
  // Fallback de AppDB (IndexedDB) por si no viene provisto en assets/js/db.js
  // Si ya existe window.AppDB, no hacemos nada.
  // --------------------------------------------------------------------------------
  if (!window.AppDB){
    const QDB_NAME = 'visibility2-queue';
    const QDB_VER  = 1;
    let _qdb = null;

    function qOpen(){
      if (_qdb) return Promise.resolve(_qdb);
      if (!('indexedDB' in window)) {
        // Fallback ultra simple a localStorage
        const lsKey='v2_queue_tasks';
        const mem = {
          all(){ try{ return JSON.parse(localStorage.getItem(lsKey)||'[]'); }catch(_){ return []; } },
          save(arr){ localStorage.setItem(lsKey, JSON.stringify(arr)); }
        };
        window.AppDB = {
          async add(task){
            const arr = mem.all();
            // dedupe opcional
            if (task.dedupeKey){
              const found = arr.find(t => t.dedupeKey===task.dedupeKey && t.status!=='done');
              if (found) return found.id;
            }
            const id = task.id || (crypto.randomUUID?.() || String(Date.now()));
            arr.push({ id, status:'pending', attempts:0, createdAt:Date.now(), ...task });
            mem.save(arr);
            // actualizar badge
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='pending').length });
            return id;
          },
          async update(id, patch){
            const arr = mem.all();
            const i = arr.findIndex(t=>t.id===id);
            if (i>=0){ arr[i] = Object.assign({}, arr[i], patch, { updatedAt: Date.now() }); mem.save(arr); }
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='pending').length });
            return true;
          },
          async listByStatus(status){
            const out = mem.all().filter(t=>t.status===status).sort((a,b)=> (a.createdAt||0)-(b.createdAt||0));
            return out;
          },
          async remove(id){
            const arr = mem.all().filter(t=>t.id!==id);
            mem.save(arr);
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='pending').length });
            return true;
          }
        };
        return Promise.resolve(null);
      }
      return new Promise((resolve)=>{
        const req = indexedDB.open(QDB_NAME, QDB_VER);
        req.onupgradeneeded = ()=>{
          const db = req.result;
          const os = db.createObjectStore('tasks', { keyPath:'id' });
          os.createIndex('by_status', 'status', { unique:false });
          os.createIndex('by_dedupe', 'dedupeKey', { unique:false });
          os.createIndex('by_nextTry', 'nextTry', { unique:false });
        };
        req.onsuccess = ()=>{ _qdb = req.result; defineAppDB(_qdb); resolve(_qdb); };
        req.onerror   = ()=>{ defineAppDB(null); resolve(null); };
      });
    }

    function defineAppDB(db){
      if (!db){
        // si falló IDB, cae al LS ya creado arriba (no redefine)
        return;
      }
      window.AppDB = {
        async add(task){
          const id = task.id || (crypto.randomUUID?.() || String(Date.now()));
          const rec = Object.assign({
            id,
            status: 'pending',
            attempts: 0,
            createdAt: Date.now()
          }, task);
          // dedupe opcional
          if (task.dedupeKey){
            const dup = await this._findByDedupe(task.dedupeKey);
            if (dup) return dup.id;
          }
          return txPut('tasks', rec).then(()=>{
            QueueEvents.emit('queue:update', { pending: true }); // UI actualizará con listPending
            return id;
          });
        },
        async update(id, patch){
          const cur = await txGet('tasks', id);
          if (!cur) return false;
          const upd = Object.assign({}, cur, patch, { updatedAt: Date.now() });
          return txPut('tasks', upd).then(()=> true);
        },
        async listByStatus(status){
          return txIndex('tasks','by_status', IDBKeyRange.only(status));
        },
        async remove(id){
          return txDel('tasks', id).then(()=> true);
        },
        async _findByDedupe(key){
          const all = await txIndex('tasks','by_dedupe', IDBKeyRange.only(key));
          // devolvemos el primero que no esté done (si hubiera)
          return all.find(t=>t.status!=='done') || null;
        }
      };

      function tx(store, mode, fn){
        return new Promise((resolve, reject)=>{
          const t  = db.transaction(store, mode);
          const os = t.objectStore(store);
          let res;
          try{ res = fn(os); }catch(e){ reject(e); return; }
          t.oncomplete = ()=> resolve(res);
          t.onerror    = ()=> reject(t.error||new Error('tx error'));
          t.onabort    = ()=> reject(t.error||new Error('tx abort'));
        });
      }
      function txPut(store, val){ return tx(store,'readwrite', os => os.put(val)); }
      function txGet(store, key){ return tx(store,'readonly', os => os.get(key)).then(r=>r && r.result || null); }
      function txDel(store, key){ return tx(store,'readwrite', os => os.delete(key)); }
      function txIndex(store, idx, range){
        return tx(store,'readonly', os => {
          const out=[]; return new Promise((resolve)=>{
            const i = os.index(idx);
            const req = i.openCursor(range);
            req.onsuccess = ()=>{ const c = req.result; if(!c){ resolve(out); return; } out.push(c.value); c.continue(); };
            req.onerror = ()=> resolve(out);
          });
        });
      }
    }

    // inicializa fallback/IDB
    qOpen();
  }

})();
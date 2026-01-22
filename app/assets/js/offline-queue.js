
(function(){
  'use strict';

  const BASE          = '/visibility2/app';
  const CSRF_ENDPOINT = BASE + '/csrf_refresh.php';
  const PING_ENDPOINT = BASE + '/ping.php';
  // Tiempo máximo permitido para un job en estado "running" antes de re-encolarlo.
  // Se usa 5 minutos para dar margen suficiente a uploads de fotos grandes en
  // conexiones lentas, evitando re-encolar prematuramente y crear duplicados.
  const STALE_RUNNING_MS = 5 * 60 * 1000;
  const HEARTBEAT_INTERVAL_MS = 60 * 1000;
  const AUTH_RECOVERY_INTERVAL_MS = 10 * 1000;
  const CSRF_REFRESH_INTERVAL_MS = 10 * 60 * 1000; // Refrescar CSRF cada 10 minutos proactivamente
  const MAX_RESPONSE_SNIPPET = 500;
  const LOCK_LEASE_MS = 30 * 1000; // Lease de 30 segundos para locks cross-tab
  const LOCK_CHANNEL_NAME = 'v2-queue-locks';

  // --------------------------------------------------------------------------------
  // Sistema de Locks Cross-Tab para evitar duplicados
  // --------------------------------------------------------------------------------
  const CrossTabLock = {
    _locks: new Map(),
    _channel: null,
    _tabId: crypto.randomUUID ? crypto.randomUUID() : String(Date.now() + Math.random()),

    init() {
      if (this._channel) return;
      try {
        if ('BroadcastChannel' in window) {
          this._channel = new BroadcastChannel(LOCK_CHANNEL_NAME);
          this._channel.onmessage = (e) => this._handleMessage(e.data);
        }
      } catch(_) {}
      // Limpiar locks expirados cada 10 segundos
      setInterval(() => this._cleanup(), 10000);
    },

    _handleMessage(msg) {
      if (!msg || msg.tabId === this._tabId) return;
      if (msg.type === 'lock_acquired') {
        this._locks.set(msg.jobId, { tabId: msg.tabId, expires: msg.expires });
      } else if (msg.type === 'lock_released') {
        const lock = this._locks.get(msg.jobId);
        if (lock && lock.tabId === msg.tabId) {
          this._locks.delete(msg.jobId);
        }
      }
    },

    _cleanup() {
      const now = Date.now();
      for (const [jobId, lock] of this._locks.entries()) {
        if (lock.expires < now) {
          this._locks.delete(jobId);
        }
      }
    },

    _broadcast(msg) {
      try {
        if (this._channel) {
          this._channel.postMessage(msg);
        }
      } catch(_) {}
    },

    // Intenta adquirir lock. Retorna true si se obtuvo, false si otro tab lo tiene
    tryAcquire(jobId) {
      const now = Date.now();
      const existing = this._locks.get(jobId);

      // Si existe un lock válido de otra pestaña, no podemos adquirir
      if (existing && existing.tabId !== this._tabId && existing.expires > now) {
        return false;
      }

      const expires = now + LOCK_LEASE_MS;
      this._locks.set(jobId, { tabId: this._tabId, expires });
      this._broadcast({ type: 'lock_acquired', jobId, tabId: this._tabId, expires });
      return true;
    },

    // Libera el lock de un job
    release(jobId) {
      const lock = this._locks.get(jobId);
      if (lock && lock.tabId === this._tabId) {
        this._locks.delete(jobId);
        this._broadcast({ type: 'lock_released', jobId, tabId: this._tabId });
      }
    },

    // Renueva el lease de un lock existente
    renew(jobId) {
      const lock = this._locks.get(jobId);
      if (lock && lock.tabId === this._tabId) {
        lock.expires = Date.now() + LOCK_LEASE_MS;
        this._broadcast({ type: 'lock_acquired', jobId, tabId: this._tabId, expires: lock.expires });
        return true;
      }
      return false;
    },

    // Verifica si tenemos el lock
    hasLock(jobId) {
      const lock = this._locks.get(jobId);
      return lock && lock.tabId === this._tabId && lock.expires > Date.now();
    }
  };

  // Inicializar sistema de locks
  CrossTabLock.init();

  // --------------------------------------------------------------------------------
  // Event bus (para UI Avance)
  // --------------------------------------------------------------------------------
  const QueueEvents = {
    emit(name, detail){ try{ window.dispatchEvent(new CustomEvent(name, { detail })); }catch(_){/* noop */} },
    on(name, handler){ window.addEventListener(name, handler); }
  };

  function broadcastGestionSuccess(payload){
    const body = Object.assign({
      message: 'La gestión se subió correctamente.',
      ts: Date.now()
    }, payload || {});

    try { sessionStorage.setItem('v2_gestion_success', JSON.stringify(body)); } catch(_) {}
    try { localStorage.setItem('v2_gestion_success_pending', JSON.stringify(body)); } catch(_) {}
    try { localStorage.setItem('v2_gestion_success_broadcast', JSON.stringify(body)); } catch(_) {}

    try {
      if ('BroadcastChannel' in window) {
        const bc = new BroadcastChannel('v2-events');
        bc.postMessage({ type:'gestion_completed', payload: body });
        bc.close();
      }
    } catch(_) {}
  }

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
      const r  = await withTimeout((signal) => fetch(CSRF_ENDPOINT, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Offline-Queue': '1'
        },
        signal
      }), 6000, 'E_CSRF_TIMEOUT');


      if (r.status === 401) {
        queueState.csrfStale = true;
        queueState.csrfRefreshAttempts++;
        return { ok:false, blocked:'auth' };
      }
      const parsed = await parseResponseSafe(r);
      const js = parsed.json;
      if (js && js.csrf_token) {
        window.CSRF_TOKEN = js.csrf_token;
        // Reset estado de CSRF
        queueState.csrfStale = false;
        queueState.lastCsrfRefresh = Date.now();
        queueState.csrfRefreshAttempts = 0;
        return { ok:true, token: js.csrf_token };
      }
      if (parsed.isHtml) {
        queueState.csrfStale = true;
        queueState.csrfRefreshAttempts++;
        return { ok:false, blocked:'auth' };
      }
    } catch(err) {
      // Marcar CSRF como stale si falla el refresh
      queueState.csrfStale = true;
      queueState.csrfRefreshAttempts++;
      console.warn('[Queue] CSRF refresh falló:', err.message || err);
    }
    return { ok:false };
  }

  // Verificar si necesitamos refrescar CSRF antes de un POST
  function needsCsrfRefresh() {
    // Si está marcado como stale
    if (queueState.csrfStale) return true;
    // Si no tenemos token
    if (!window.CSRF_TOKEN) return true;
    // Si el último refresh fue hace más de 9 minutos (antes del intervalo de 10)
    if (Date.now() - queueState.lastCsrfRefresh > 9 * 60 * 1000) return true;
    return false;
  }

   async function heartbeat(){
    try {
      const r = await withTimeout((signal) => fetch(PING_ENDPOINT, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Offline-Queue': '1'
        },
        signal
      }), 6000, 'E_PING_TIMEOUT');
      const parsed = await parseResponseSafe(r);
      const js = parsed.json;
      if (js && js.csrf_token) window.CSRF_TOKEN = js.csrf_token;
      if (r.status === 401 || parsed.isHtml) return { ok:false, blocked:'auth' };
      if (!r.ok) return { ok:false };
      return { ok: !!(js && (js.status === 'ok' || js.ok === true)), data: js };
    } catch(_) { return { ok:false }; }
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

    const timeoutMs = typeof task.timeoutMs === 'number' ? task.timeoutMs : 20000;

    // Refresca CSRF si es necesario o está marcado como stale
    if (task.sendCSRF !== false && needsCsrfRefresh()) {
      const csrfResult = await refreshCSRF();
      // Si el CSRF falla y hay demasiados intentos, abortar
      if (!csrfResult.ok && queueState.csrfRefreshAttempts >= 3) {
        const err = new Error('CSRF refresh falló múltiples veces');
        err.status = 419;
        err.csrfFailed = true;
        throw err;
      }
    }

    let url = task.url;
    if (!/^https?:\/\//i.test(url)) {
      url = url.startsWith('/') ? url : `${BASE.replace(/\/$/,'')}/${url.replace(/^\//,'')}`;
    }

    const fd = buildFormData(task);
        const headers = {
      'X-Offline-Queue': '1'
    };
    if (task.id) headers['X-Idempotency-Key'] = task.id;
    if (window.CSRF_TOKEN) headers['X-CSRF-Token'] = window.CSRF_TOKEN;

    let r;
    try {
      QueueEvents.emit('queue:dispatch:progress', { job: task, progress: 50 });
      r = await withTimeout((signal) => fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'include',      // ← más robusto que 'same-origin'
        cache: 'no-store',
        headers,
        signal
      }), timeoutMs, 'E_POST_TIMEOUT');
      QueueEvents.emit('queue:dispatch:progress', { job: task, progress: 90 });
    } catch (err) {
      throw new Error(`Network error calling ${url}: ${err && err.message ? err.message : err}`);
    }

    const parsed = await parseResponseSafe(r);
    const isCsrfInvalid = r.status === 419 || (parsed.json && /csrf/i.test(parsed.json.error_code || parsed.json.error || parsed.json.message || ''));

    // Si el servidor dice CSRF inválido, reintenta una sola vez con token fresco
    if (isCsrfInvalid && !task.__csrfRetried) {
      const ok = await refreshCSRF();
      if (ok && ok.ok) {
        const fd2 = buildFormData(task);
        const headers2 = { ...headers, 'X-CSRF-Token': window.CSRF_TOKEN || '' };
        task.__csrfRetried = true;
        r = await withTimeout((signal) => fetch(url, { method:'POST', body: fd2, credentials:'include', cache:'no-store', headers: headers2, signal }), timeoutMs, 'E_POST_TIMEOUT');
        const parsedRetry = await parseResponseSafe(r);
        return { response: r, parsed: parsedRetry };
      }
    }
    return { response: r, parsed };
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
  function withTimeout(promiseFactory, ms, code){
    const controller = new AbortController();
    const tid = setTimeout(() => controller.abort(code || 'timeout'), ms || 20000);
    let p;
    try {
      p = promiseFactory(controller.signal);
    } catch (e) {
      clearTimeout(tid);
      throw e;
    }

    const timeoutPromise = new Promise((_, reject) => {
      controller.signal.addEventListener('abort', () => {
        // Usar Error en vez de DOMException porque DOMException.code es read-only
        const err = new Error(code || 'timeout');
        err.name = 'AbortError';
        err.timeoutCode = code || 'timeout';
        reject(err);
      }, { once:true });
    });

    return Promise.race([p, timeoutPromise]).finally(() => clearTimeout(tid));
  }

  function isNetworkishError(err){
    if (!err) return false;
    if (err.name === 'AbortError' || err.name === 'TimeoutError') return true;
    const msg = (err && err.message) ? String(err.message) : '';
    return /Failed to fetch/i.test(msg) || /NetworkError/i.test(msg) || /timeout/i.test(msg);
  }

  async function parseResponseSafe(response){
    let text = '';
    let contentType = '';
    try {
      contentType = response.headers.get('content-type') || '';
    } catch(_) {}
    try {
      text = await response.text();
    } catch (_) {
      return { json: null, text: '', isJson: false, isHtml: false, contentType };
    }
    const hasHtml = /<html[\s>]/i.test(text || '') || /<!doctype html/i.test(text || '');
    if (!text) return { json: {}, text: '', isJson: false, isHtml: hasHtml, contentType };
    try {
      const js = JSON.parse(text);
      return { json: js, text, isJson: true, isHtml: false, contentType };
    } catch (_) {
      return { json: { raw: text }, text, isJson: false, isHtml: hasHtml || !/application\/json/i.test(contentType), contentType };
    }
  }

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

  function isLogicalSuccess(js){
    if (!js || typeof js !== 'object') return false;

    const okVal = js.ok;
    if (okVal === true) return true;
    if (typeof okVal === 'string' && okVal.toLowerCase() === 'ok') return true;

    const statusVal = js.status;
    if (statusVal === true) return true;
    if (typeof statusVal === 'string' && ['ok', 'success'].includes(statusVal.toLowerCase())) return true;
    if (typeof statusVal === 'number' && [0, 200].includes(statusVal)) return true;

    const successVal = js.success;
    if (successVal === true) return true;
    if (typeof successVal === 'string' && ['ok', 'success', 'true'].includes(successVal.toLowerCase())) return true;

    const codeVal = js.code || js.codigo || js.status_code;
    if (typeof codeVal === 'number' && [0, 200].includes(codeVal)) return true;
    if (typeof codeVal === 'string' && ['0', '200', 'ok', 'success'].includes(codeVal.toLowerCase())) return true;

    return false;
  }

  function logicalErrorMessage(js){
    if (!js || typeof js !== 'object') return 'Respuesta sin confirmar éxito';
    return js.message || js.error || js.msg || js.detail || 'Respuesta sin confirmar éxito';
  }

  async function handleGestionTaskSuccess(task, response){
    try {
      const f    = task.fields || {};
      const meta = task.meta   || {};
      const formId  = Number(meta.form_id || f.id_formulario || f.idCampana || f.id_campana || f.campana || 0);
      const localId = Number(meta.local_id || f.id_local || f.idLocal || f.local_id || 0);
      if (!formId || !localId) return;

      let ymd = (response?.fecha_propuesta || response?.fecha_visita || response?.fecha_reagendada || f.fechaPropuesta || f.fecha_visita || f.fecha_reagendada || '').slice(0,10);
      if ((!ymd || ymd.length < 8) && window.V2Cache && typeof V2Cache.findAgendaDate === 'function') {
        try { ymd = await V2Cache.findAgendaDate(formId, localId); } catch(_) {}
      }
      if (!ymd) ymd = new Date().toISOString().slice(0,10);

      const visitaId = response?.visita_id || response?.id_visita || f.visita_id || null;
      const payload = {
        form_id: formId,
        local_id: localId,
        ymd,
        visita_id: visitaId,
        client_guid: task.client_guid || f.client_guid || null,
        estado: response?.estado_final || response?.estado_gestion || null
      };

      if (window.V2Cache && typeof V2Cache.markDone === 'function') {
        try { await V2Cache.markDone(formId, localId, ymd, payload); } catch(_) {}
      }

      broadcastGestionSuccess(payload);
    } catch(_) {}
  }

  function buildLastError(params){
    const {
      code,
      message,
      httpStatus,
      url,
      responseSnippet
    } = params || {};
    return {
      code: code || 'UNKNOWN',
      message: message || 'Error',
      httpStatus: httpStatus || null,
      url: url || null,
      responseSnippet: responseSnippet ? String(responseSnippet).slice(0, MAX_RESPONSE_SNIPPET) : null
    };
  }

  // Configuración de reintentos por tipo de error
  const RETRY_CONFIG = {
    'NETWORK':       { maxAttempts: 12, baseDelayMs: 1000, maxDelayMs: 60000 },
    'TIMEOUT':       { maxAttempts: 10, baseDelayMs: 2000, maxDelayMs: 120000 },
    'HTTP_502':      { maxAttempts: 8,  baseDelayMs: 3000, maxDelayMs: 180000 },
    'HTTP_503':      { maxAttempts: 8,  baseDelayMs: 5000, maxDelayMs: 300000 },
    'HTTP_504':      { maxAttempts: 8,  baseDelayMs: 5000, maxDelayMs: 300000 },
    'HTTP_500':      { maxAttempts: 6,  baseDelayMs: 5000, maxDelayMs: 300000 },
    'HTTP_408':      { maxAttempts: 8,  baseDelayMs: 2000, maxDelayMs: 60000 },
    'HTTP_429':      { maxAttempts: 6,  baseDelayMs: 10000, maxDelayMs: 300000 },
    'LOGICAL_ERROR': { maxAttempts: 3,  baseDelayMs: 5000, maxDelayMs: 30000 },
    'DUPLICATE':     { maxAttempts: 1,  baseDelayMs: 0, maxDelayMs: 0 }, // No reintentar, es éxito lógico
    'DEFAULT':       { maxAttempts: 8,  baseDelayMs: 2000, maxDelayMs: 300000 }
  };

  function getRetryConfig(errorCode) {
    // Buscar configuración específica o usar default
    if (RETRY_CONFIG[errorCode]) return RETRY_CONFIG[errorCode];
    // Para códigos HTTP genéricos, buscar por prefijo
    if (errorCode && errorCode.startsWith('HTTP_')) {
      const status = parseInt(errorCode.replace('HTTP_', ''), 10);
      if (status >= 500) return RETRY_CONFIG['HTTP_500'];
    }
    return RETRY_CONFIG['DEFAULT'];
  }

  function classifyFailure({ response, parsed, error }){
    if (error && isNetworkishError(error)) {
      return { code: 'NETWORK', retryable: true, retryConfig: RETRY_CONFIG['NETWORK'] };
    }
    if (error && error.name === 'AbortError') {
      const code = error.timeoutCode || 'TIMEOUT';
      return { code, retryable: true, retryConfig: RETRY_CONFIG['TIMEOUT'] };
    }
    if (response) {
      const status = response.status;
      const isHtml = parsed && parsed.isHtml;

      // 401 = sesión expirada, bloquear cola y esperar re-login
      if (status === 401 || isHtml) {
        return { code: 'AUTH_EXPIRED', retryable: false, blocked: 'auth' };
      }

      // 403 = permiso denegado permanentemente (no tiene acceso a este recurso)
      // No bloquear la cola, simplemente marcar como error final
      if (status === 403) {
        return { code: 'FORBIDDEN', retryable: false };
      }

      // 409 = Conflict (idempotencia duplicada) - tratar como éxito lógico
      if (status === 409) {
        return { code: 'DUPLICATE', retryable: false, success: true, retryConfig: RETRY_CONFIG['DUPLICATE'] };
      }

      if (status === 419) {
        return { code: 'CSRF_INVALID', retryable: false, blocked: 'csrf' };
      }

      // 408 Request Timeout
      if (status === 408) {
        return { code: 'HTTP_408', retryable: true, retryConfig: RETRY_CONFIG['HTTP_408'] };
      }

      // 429 Too Many Requests - backoff más largo
      if (status === 429) {
        return { code: 'HTTP_429', retryable: true, retryConfig: RETRY_CONFIG['HTTP_429'] };
      }

      // 502/503/504 - errores de gateway, reintentar con backoff
      if ([502, 503, 504].includes(status)) {
        const code = `HTTP_${status}`;
        return { code, retryable: true, retryConfig: RETRY_CONFIG[code] || RETRY_CONFIG['HTTP_502'] };
      }

      // Otros 5xx
      if (status >= 500) {
        return { code: `HTTP_${status}`, retryable: true, retryConfig: RETRY_CONFIG['HTTP_500'] };
      }

      // 4xx genéricos (excepto los ya manejados)
      if (status >= 400) {
        return { code: `HTTP_${status}`, retryable: false };
      }

      if (!isLogicalSuccess(parsed && parsed.json)) {
        return { code: 'LOGICAL_ERROR', retryable: false, retryConfig: RETRY_CONFIG['LOGICAL_ERROR'] };
      }
    }
    return { code: 'UNKNOWN', retryable: false };
  }

  // --------------------------------------------------------------------------------
  // Ejecución de tareas
  // --------------------------------------------------------------------------------
  async function processTask(task){
    // PATCH 2: Resolver visita_id desde múltiples fuentes de forma robusta
    if (task.fields && !task.fields.visita_id) {
      // 1. Intentar desde client_guid → visita_id real (mapping actualizado)
      if (task.fields.client_guid) {
        const mapped = LocalByGuid.get(task.fields.client_guid);
        // Solo usar si es numérico (visita_id real, no "local-xxx")
        if (mapped && !String(mapped).startsWith('local-')) {
          task.fields.visita_id = mapped;
        }
      }

      // 2. Fallback: buscar en Visits map si tenemos visita_local_id
      if (!task.fields.visita_id && task.fields.visita_local_id) {
        const real = Visits.get(String(task.fields.visita_local_id));
        if (real) task.fields.visita_id = real;
      }
    }

    // Si todavía tenemos un ID local, intentar traducir o limpiar
    if (task.fields && task.fields.visita_id && String(task.fields.visita_id).startsWith('local-')) {
      const real = Visits.get(String(task.fields.visita_id));
      if (real) {
        task.fields.visita_id = real;
      } else {
        // PATCH 2: No enviar ID local al servidor - dejar que lo resuelva por client_guid
        console.warn('[Queue] visita_id local sin mapping real:', task.fields.visita_id, '- se enviará sin visita_id para que servidor lo resuelva');
        delete task.fields.visita_id;
      }
    }

    const { response: r, parsed } = await httpPost(task);
    const js = parsed ? parsed.json : null;
    if (!r.ok || !isLogicalSuccess(js) || (parsed && parsed.isHtml)) {
      const err = new Error(`HTTP ${r.status}`);
      err.status = r.status;
      err.response = js;
      err.parsed = parsed;
      throw err;
    }

    // Si es la creación de visita, persistir mapping y marcar dependencia como resuelta
    if (task.type === 'create_visita' && js && js.visita_id) {
      const localId = task.fields.visita_local_id;
      const realVisitaId = js.visita_id;

      // Mapear local-uuid → visita_id real
      if (localId) {
        Visits.set(String(localId), realVisitaId);
      }

      // PATCH 2: Mapear client_guid → visita_id REAL (no el local)
      if (task.fields.client_guid) {
        const depTag = `create:${task.fields.client_guid}`;
        CompletedDeps.add(depTag);
        // Guardar el ID REAL, no el local
        LocalByGuid.set(task.fields.client_guid, realVisitaId);
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
      await handleGestionTaskSuccess(task, js);
    }

    return { data: js, status: r.status, parsed };
  }

  // --------------------------------------------------------------------------------
  // Backoff simple con jitter
  // --------------------------------------------------------------------------------
  async function backoff(ms){ return new Promise(r=>setTimeout(r, ms)); }

  // --------------------------------------------------------------------------------
  // Motor de drenaje
  // --------------------------------------------------------------------------------
  const queueState = {
    blocked: null,
    blockedAt: null,
    authRecoveryTimer: null,
    csrfStale: false,         // Flag para indicar que el CSRF necesita refresh
    lastCsrfRefresh: 0,       // Timestamp del último refresh exitoso
    csrfRefreshAttempts: 0    // Contador de intentos fallidos
  };
  let _drainPromise = null;

  async function recoverStaleRunning(){
    const running = await AppDB.listByStatus('running');
    const now = Date.now();
    await Promise.all(running.map(async (raw) => {
      const job = AppDB.normalizeJob ? AppDB.normalizeJob(raw) : raw;
      const startedAt = job.startedAt || job.updatedAt || job.createdAt || 0;
      if (startedAt && now - startedAt > STALE_RUNNING_MS) {
        // PATCH: Verificar si otra pestaña tiene el lock antes de re-encolar
        // Si no podemos adquirir el lock, otra pestaña está procesando este job
        if (!CrossTabLock.tryAcquire(job.id)) {
          console.log('[Queue] Job', job.id, 'tiene lock activo en otra pestaña, no re-encolar');
          return;
        }

        const attempts = (job.attempts || 0) + 1;
        const lastError = buildLastError({
          code: 'STALE_RUNNING',
          message: 'Job estaba en ejecución demasiado tiempo.',
          httpStatus: null,
          url: job.url || null
        });
        await AppDB.update(job.id, {
          status: 'queued',
          attempts,
          nextTryAt: now + 2000,
          startedAt: null,
          lastError,
          updatedAt: now
        });
        QueueEvents.emit('queue:dispatch:error', { job, error: 'STALE_RUNNING' });
        jr('onError', job, 'STALE_RUNNING');

        // Liberar lock después de re-encolar
        CrossTabLock.release(job.id);
      }
    }));
  }

  function stopAuthRecovery(){
    if (queueState.authRecoveryTimer){
      clearInterval(queueState.authRecoveryTimer);
      queueState.authRecoveryTimer = null;
    }
  }

  async function requeueBlocked(reason){
    const status = reason === 'auth' ? 'blocked_auth' : reason === 'csrf' ? 'blocked_csrf' : null;
    if (!status || !window.AppDB) return;
    const blocked = await AppDB.listByStatus(status);
    const now = Date.now();
    await Promise.all(blocked.map(job => AppDB.update(job.id, {
      status: 'queued',
      startedAt: null,
      nextTryAt: now + 1500,
      updatedAt: now,
      lastError: buildLastError({ code: 'RECOVERED', message: 'Reintento tras recuperación de autenticación/CSRF', url: job.url })
    })));
    QueueEvents.emit('queue:update', { pending: blocked.length });
  }

  function blockQueue(reason, detail){
    queueState.blocked = reason;
    queueState.blockedAt = Date.now();
    QueueEvents.emit('queue:blocked', { reason, detail });
    if (reason === 'auth') {
      stopAuthRecovery();
      attemptAuthRecovery().catch(()=>{});
      queueState.authRecoveryTimer = setInterval(() => {
        try { attemptAuthRecovery(); } catch(_){}
      }, AUTH_RECOVERY_INTERVAL_MS);
    }
  }

  function unblockQueue(){
    if (!queueState.blocked) return;
    const reason = queueState.blocked;
    stopAuthRecovery();
    queueState.blocked = null;
    queueState.blockedAt = null;
    QueueEvents.emit('queue:unblocked', {});
    if (reason === 'auth' || reason === 'csrf') {
      requeueBlocked(reason).catch(()=>{});
    }
    setTimeout(() => { try { drain(); } catch(_){} }, 200);
  }

  async function attemptAuthRecovery(){
    if (queueState.blocked !== 'auth') return;
    const hb = await heartbeat();
    if (hb && hb.ok) {
      await requeueBlocked('auth');
      unblockQueue();
      await drain();
      return;
    }
    const refreshed = await refreshCSRF();
    if (refreshed && refreshed.ok) {
      await requeueBlocked('auth');
      unblockQueue();
      await drain();
    }
  }

  function computeBackoff(attempts, errorCode){
    const config = getRetryConfig(errorCode);
    const baseDelay = config.baseDelayMs || 2000;
    const maxDelay = config.maxDelayMs || 300000;
    const base = Math.min(maxDelay, baseDelay * Math.pow(2, attempts));
    const jitter = Math.floor(Math.random() * Math.min(750, base * 0.1));
    return base + jitter;
  }

  function getMaxAttemptsForError(errorCode, defaultMax) {
    const config = getRetryConfig(errorCode);
    return config.maxAttempts || defaultMax || 8;
  }

  async function resetQueuedBackoff(reason){
    if (!window.AppDB || typeof AppDB.listByStatus !== 'function') return 0;
    try {
      const queued = await AppDB.listByStatus('queued');
      const now = Date.now();
      let touched = 0;
      await Promise.all(queued.map(async (job) => {
        const next = job.nextTryAt || job.nextTry || 0;
        if (next && next > now) {
          touched++;
          await AppDB.update(job.id, { nextTryAt: now, updatedAt: now });
        }
      }));
      if (touched) {
        QueueEvents.emit('queue:retry:reset', { reason: reason || 'reconnect', updated: touched });
      }
      return touched;
    } catch (_) {
      return 0;
    }
  }

  async function drain(){
    if (_drainPromise) return _drainPromise;

    _drainPromise = (async () => {
      try {
        // Siempre recupera trabajos "running" estancados aunque estemos offline para
        // que queden listos cuando vuelva la conexión.
        await recoverStaleRunning();

        if (!navigator.onLine) return;
        if (queueState.blocked) return;

        const hb = await heartbeat();
        if (!hb.ok) {
          if (hb.blocked === 'auth') blockQueue('auth', hb.data || null);
          return;
        }

        const pending = await AppDB.listByStatus('queued');
        const now = Date.now();
        QueueEvents.emit('queue:update', { pending: pending.length });

        for (const raw of pending) {
          const t = AppDB.normalizeJob ? AppDB.normalizeJob(raw) : raw;
          const nextTryAt = t.nextTryAt || t.nextTry || 0;
          if (nextTryAt && nextTryAt > now) continue;
          if (t.dependsOn && !CompletedDeps.has(t.dependsOn)) continue;

          // PATCH: Adquirir lock cross-tab antes de procesar
          if (!CrossTabLock.tryAcquire(t.id)) {
            console.log('[Queue] Job', t.id, 'está siendo procesado por otra pestaña');
            continue;
          }

          t.meta = t.meta || inferMetaFromTask(t);

          const attempts = (t.attempts || 0) + 1;

          // PATCH 1 complemento: Manejar caso de job eliminado en paralelo
          const updateResult = await AppDB.update(t.id, {
            status: 'running',
            attempts,
            startedAt: now,
            updatedAt: now
          });

          // Si el job ya no existe, continuar con el siguiente
          if (updateResult && updateResult.__notFound) {
            console.warn('[Queue] Job desapareció durante drain:', t.id);
            CrossTabLock.release(t.id);
            continue;
          }

          QueueEvents.emit('queue:dispatch:start', { job: t });
          jr('onStart', t);

          try {
            const result = await processTask(t);
            await AppDB.remove(t.id);
            CrossTabLock.release(t.id); // Liberar lock tras éxito
            QueueEvents.emit('queue:dispatch:success', { job: t, responseStatus: result.status || 200, response: result.data });
            jr('onSuccess', t, result.data, result.status);
            window.dispatchEvent(new CustomEvent('queue:done', { detail: { id: t.id, type: t.type, response: result.data } }));
          } catch (err) {
            const parsed = err && err.parsed ? err.parsed : null;
            const responseStub = err && err.status ? { status: err.status } : null;
            const failure = classifyFailure({ response: responseStub, parsed, error: err });

            // Si es duplicado (409), tratar como éxito
            if (failure.success && failure.code === 'DUPLICATE') {
              console.log('[Queue] Job', t.id, 'es duplicado (409), marcando como completado');
              await AppDB.remove(t.id);
              CrossTabLock.release(t.id);
              QueueEvents.emit('queue:dispatch:success', { job: t, responseStatus: 409, response: { duplicate: true } });
              jr('onSuccess', t, { duplicate: true }, 409);
              continue;
            }

            // Usar maxAttempts específico para el tipo de error
            const maxAttempts = getMaxAttemptsForError(failure.code, t.maxAttempts || 8);
            const exhausted = attempts >= maxAttempts;
            const finalRetryable = failure.retryable && !exhausted;
            const status = failure.blocked === 'auth' ? 'blocked_auth' : failure.blocked === 'csrf' ? 'blocked_csrf' : finalRetryable ? 'queued' : 'error';
            const wait = finalRetryable ? computeBackoff(attempts, failure.code) : 0;
            const lastError = buildLastError({
              code: failure.code,
              message: err && err.message ? err.message : 'Error',
              httpStatus: err && err.status ? err.status : null,
              url: t.url || null,
              responseSnippet: parsed && parsed.text ? parsed.text.slice(0, MAX_RESPONSE_SNIPPET) : null
            });
            await AppDB.update(t.id, {
              status,
              attempts,
              nextTryAt: status === 'queued' ? now + wait : null,
              finishedAt: status === 'error' ? now : null,
              lastError,
              updatedAt: now
            });
            CrossTabLock.release(t.id); // Liberar lock tras error
            t.status = status;
            t.attempts = attempts;
            t.nextTryAt = status === 'queued' ? now + wait : null;
            QueueEvents.emit('queue:dispatch:error', { job: t, error: lastError });
            jr('onError', t, lastError);
            if (failure.blocked === 'auth') {
              blockQueue('auth', lastError);
              break;
            }
            if (failure.blocked === 'csrf') {
              blockQueue('csrf', lastError);
              break;
            }
            await backoff(200);
          }
        }

        const left = await AppDB.listByStatus('queued');
        QueueEvents.emit('queue:update', { pending: left.length });
      } finally {
        _drainPromise = null;
      }
    })();
    return _drainPromise;
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

      // PATCH P1-3: Dedupe semántico automático para gestiones
      // Evita duplicar tareas idénticas por tipo+campaña+local
      if (!task.dedupeKey && task.type && task.fields) {
        const f = task.fields;
        const formId = f.id_formulario || f.idCampana || f.id_campana || f.campana || '';
        const localId = f.id_local || f.idLocal || f.local_id || '';
        const isGestion = ['procesar_gestion', 'procesar_gestion_pruebas', 'upload_material_foto', 'upload_material_foto_pruebas'].includes(task.type) ||
          (task.url && (task.url.includes('procesar_gestion') || task.url.includes('upload_material_foto')));

        if (isGestion && formId && localId) {
          task.dedupeKey = `${task.type}:${formId}:${localId}`;
        }
      }

      // Tareas especiales: create_visita (genera id local y mapea por client_guid)
      if (task.type === 'create_visita') {
        if (!task.fields) task.fields = {};

        // PATCH: Dedupe por client_guid para evitar doble create_visita
        // Si ya existe una tarea create_visita para este client_guid, no crear otra
        if (!task.dedupeKey && task.fields.client_guid) {
          task.dedupeKey = `create_visita:${task.fields.client_guid}`;
        }

        if (!task.fields.visita_local_id) {
          task.fields.visita_local_id = 'local-' + (crypto.randomUUID ? crypto.randomUUID() : Date.now());
        }
        if (task.fields.client_guid) {
          LocalByGuid.set(task.fields.client_guid, task.fields.visita_local_id);
        }
      }

      // Meta para UI
      task.meta = task.meta || inferMetaFromTask(task);

      task.status = 'queued';
      task.createdAt = Date.now();
      task.updatedAt = Date.now();
      task.attempts = task.attempts || 0;
      task.maxAttempts = task.maxAttempts || 8;
      task.nextTryAt = task.nextTryAt || 0;
      task.startedAt = null;
      task.finishedAt = null;

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
    async enqueueFromForm(url, formOrFD, type='generic', extraOpts={}){
      const opts = (typeof type === 'object' && type !== null)
        ? Object.assign({}, type, extraOpts)
        : Object.assign({}, extraOpts, { type });

      const finalType = opts.type || 'generic';
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

      const task = {
        url,
        type: finalType,
        fields,
        files,
        sendCSRF: opts.sendCSRF !== false,
        id: opts.id || opts.idempotencyKey || undefined,
        dedupeKey: opts.dedupeKey || undefined,
        dependsOn: opts.dependsOn || undefined,
        client_guid: opts.client_guid || fields.client_guid || undefined
      };

      task.meta = Object.assign({}, inferMetaFromTask(task), opts.meta || {});
      return this.enqueue(task);
    },

    /**
     * Intenta enviar inmediatamente; si falla (offline o error), encola.
     */
    smartPost: async function(url, fdOrFields, options = {}) {
      const type          = options.type || 'generic';
      const needCSRF      = options.sendCSRF !== false;
      const timeoutMs     = typeof options.timeoutMs === 'number' ? options.timeoutMs : 20000;
      const enqueueOnFail = options.enqueueOnFail !== false;

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
        client_guid: options.client_guid || fields.client_guid || undefined,
        timeoutMs
      };

      if (!task.id) {
        task.id = crypto.randomUUID ? crypto.randomUUID() : String(Date.now());
      }

      task.meta = Object.assign({}, inferMetaFromTask(task), options.meta || {});

      // Registrar en Journal también para gestiones ONLINE
      jr('onEnqueue', task);

      const shouldEnqueue = enqueueOnFail !== false && !!task.meta && !!task.meta.kind;

      if (!navigator.onLine) {
        if (shouldEnqueue) {
          const id = await this.enqueue(task);
          return { queued:true, ok:true, id };
        }
        throw new Error('Offline');
      }

      if (navigator.onLine) {
        try {
          await heartbeat();
          if (needCSRF) {
            const csrfOk = await refreshCSRF();
            if (!csrfOk && shouldEnqueue) {
              const id = await this.enqueue(task);
              return { queued:true, ok:true, id };
            }
          }
          // Ahora el job SIEMPRE tiene id
          QueueEvents.emit('queue:dispatch:start', { job: task });
          jr('onStart', task);
          const result = await withTimeout(() => processTask(task), timeoutMs, 'E_SMART_TIMEOUT');

          QueueEvents.emit('queue:dispatch:success', {
            job: task,
            responseStatus: result.status || 200,
            response: result.data
          });
          jr('onSuccess', task, result.data, result.status || 200);
          return { queued:false, ok:true, response: result.data };
        } catch (e) {
          QueueEvents.emit('queue:dispatch:error', { job: task, error: String(e) });
          const parsed = e && e.parsed ? e.parsed : null;
          const failure = classifyFailure({ response: e && e.status ? { status: e.status } : null, parsed, error: e });
          jr('onError', task, buildLastError({
            code: failure.code,
            message: String(e),
            httpStatus: e && e.status ? e.status : null,
            url: task.url || null,
            responseSnippet: parsed && parsed.text ? parsed.text.slice(0, MAX_RESPONSE_SNIPPET) : null
          }), e && e.status ? e.status : null);
          if (failure.blocked === 'auth') {
            blockQueue('auth', failure);
            return { queued:false, ok:false, blocked:'auth' };
          }
          if (failure.blocked === 'csrf') {
            blockQueue('csrf', failure);
            return { queued:false, ok:false, blocked:'csrf' };
          }
          if (shouldEnqueue && (failure.retryable || isNetworkishError(e))) {
            const id = await this.enqueue(task);
            return { queued:true, ok:true, id };
          }
          if (shouldEnqueue && e && e.name === 'AbortError') {
            const id = await this.enqueue(task);
            return { queued:true, ok:true, id };
          }
          if (shouldEnqueue && enqueueOnFail) {
            const id = await this.enqueue(task);
            return { queued:true, ok:true, id };
          }
        }
      }

      const id = await this.enqueue(task);
      return { queued:true, ok:true, id };
    },

    /**
     * Cancela y elimina una tarea encolada (por ejemplo, foto borrada).
     * También limpia el Journal para que la UI refleje el cambio.
     */
    async cancel(id, opts = {}){
      if (!id) return false;
      const keepRecord = opts.keepRecord === true;

      try {
        if (keepRecord) {
          await AppDB.update(id, { status: 'canceled', finishedAt: Date.now(), updatedAt: Date.now() });
          if (window.JournalDB && typeof JournalDB.onError === 'function') {
            await JournalDB.onError({ id, status: 'canceled' }, { code:'CANCELED', message:'Cancelado por usuario' });
          }
        } else {
          await AppDB.remove(id);
          if (window.JournalDB && typeof JournalDB.remove === 'function') {
            await JournalDB.remove(id);
          }
        }
      } catch (err) {
        QueueEvents.emit('queue:dispatch:error', { job: { id }, error: String(err) });
        jr('onError', { id }, String(err));
        return false;
      }

      try {
        const pend = await AppDB.listByStatus('queued');
        QueueEvents.emit('queue:update', { pending: pend.length });
      } catch(_) {
        QueueEvents.emit('queue:update', {});
      }

      QueueEvents.emit('queue:cancelled', { job: { id } });
      jr('onCancel', { id });
      return true;
    },

    async listPending(){
      return AppDB.listByStatus('queued');
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

    drain,
    state: queueState,
    unblock: unblockQueue
  };

  async function resumeFromBackground(){
    if (!navigator.onLine) return;
    await resetQueuedBackoff('resume');
    const hb = await heartbeat();
    if (!hb.ok) {
      if (hb.blocked === 'auth') blockQueue('auth', hb.data || null);
      return;
    }
    unblockQueue();
    await refreshCSRF();
    await drain();
  }

  let _heartbeatTimer = null;
  let _csrfRefreshTimer = null;

  // PATCH P1-1: Refresco proactivo de CSRF para evitar tokens expirados
  function ensureCSRFRefresh(){
    if (_csrfRefreshTimer) return;
    _csrfRefreshTimer = setInterval(async () => {
      try {
        if (!navigator.onLine || queueState.blocked) return;
        const result = await refreshCSRF();
        if (result && result.ok) {
          console.log('[Queue] CSRF refrescado proactivamente');
        }
      } catch(_) {}
    }, CSRF_REFRESH_INTERVAL_MS);
  }

  function ensureHeartbeat(){
    if (_heartbeatTimer) return;
    _heartbeatTimer = setInterval(async () => {
      try {
        // Recupera "running" cada latido aunque no haya trabajos encolados
        // para evitar colas congeladas con cero pendientes.
        const running = await AppDB.listByStatus('running');
        if (running.length) {
          await recoverStaleRunning();
        }

        if (!navigator.onLine || queueState.blocked) return;

        const queued = await AppDB.listByStatus('queued');
        if (!queued.length && !running.length) return;

        const hb = await heartbeat();
        if (!hb.ok && hb.blocked === 'auth') blockQueue('auth', hb.data || null);
        else if (hb.ok) {
          // Si acabamos de limpiar trabajos, intenta drenar sin esperar
          if (running.length || queued.length) drain();
        }
      } catch(_) {}
    }, HEARTBEAT_INTERVAL_MS);
  }

  window.addEventListener('online', () => {
    resetQueuedBackoff('online').finally(() => {
      if (queueState.blocked === 'auth') attemptAuthRecovery();
      else drain();
    });
  });
  window.addEventListener('focus', () => { resumeFromBackground(); });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') resumeFromBackground();
  });
  document.addEventListener('DOMContentLoaded', () => {
    ensureHeartbeat();
    ensureCSRFRefresh();
    setTimeout(drain, 600);
  });

  // Export
  window.Queue = Queue;
  window.Queue._test = {
    classifyFailure,
    buildLastError,
    computeBackoff,
    parseResponseSafe,
    recoverStaleRunning,
    isDraining: () => !!_drainPromise,
    getRetryConfig,
    getMaxAttemptsForError,
    needsCsrfRefresh,
    CrossTabLock
  };
  window.Queue.CompletedDeps = CompletedDeps;
  window.Queue.LocalByGuid = LocalByGuid;
  window.Queue.CrossTabLock = CrossTabLock;

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
            arr.push({ id, status:'queued', attempts:0, createdAt:Date.now(), updatedAt:Date.now(), ...task });
            mem.save(arr);
            // actualizar badge
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='queued').length });
            return id;
          },
          async update(id, patch){
            const arr = mem.all();
            const i = arr.findIndex(t=>t.id===id);
            if (i>=0){ arr[i] = Object.assign({}, arr[i], patch, { updatedAt: Date.now() }); mem.save(arr); }
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='queued').length });
            return true;
          },
          async listByStatus(status){
            const out = mem.all().filter(t=>t.status===status).sort((a,b)=> (a.createdAt||0)-(b.createdAt||0));
            return out;
          },
          async remove(id){
            const arr = mem.all().filter(t=>t.id!==id);
            mem.save(arr);
            QueueEvents.emit('queue:update', { pending: arr.filter(t=>t.status==='queued').length });
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
            status: 'queued',
            attempts: 0,
            createdAt: Date.now(),
            updatedAt: Date.now()
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
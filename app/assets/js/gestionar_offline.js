/* assets/js/gestionar_offline.js
 * App-shell offline para gestionarPruebas.php
 * - Sin jQuery.
 * - Carga v2_cache.js, db.js y offline-queue.js si están disponibles (desde el SW cache).
 * - Guarda progreso en IndexedDB (V2Cache) o localStorage (fallback).
 * - Encola un bundle a /api/sync_bundle.php al finalizar.
 */

(() => {
  // ---------- Constantes / Helpers ----------
  const APP_SCOPE = (typeof window.__APP_SCOPE__ === 'string' && window.__APP_SCOPE__) || '/visibility2/app';
  const STORE_KEY = 'gest_offline'; // nombre de "store" para V2Cache
  const QS = new URLSearchParams(location.search);
  const idCampana = parseInt(QS.get('idCampana') || '0', 10);
  const idLocal   = parseInt(QS.get('idLocal') || '0', 10);
  const nombreCampana = QS.get('nombreCampana') || '(campaña)';
  const KEY = `camp:${idCampana}:local:${idLocal}`;

  // Elementos del DOM del shell embebido por el SW
  const $header   = document.getElementById('headerInfo');
  const $step1    = document.getElementById('step1');
  const $step2    = document.getElementById('step2');
  const $step3    = document.getElementById('step3');
  const $step1B   = document.getElementById('step1Body');
  const $step2B   = document.getElementById('step2Body');
  const $step3B   = document.getElementById('step3Body');
  const $btnBack  = document.getElementById('btnBack');
  const $btnNext  = document.getElementById('btnNext');
  const $btnFin   = document.getElementById('btnFinish');

  let currentStep = 1;
  let bootedLibs  = false;

  // Estructura de trabajo en memoria
  let state = {
    meta: {
      idCampana, idLocal, nombreCampana,
      startedAt: Date.now(),
      lastUpdateAt: Date.now(),
    },
    // Paso 1: estado general
    status: {
      implementado: null, // true/false/null
      motivo: '',         // texto si no implementado o nota general
      fechaPropuesta: '', // opcional (YYYY-MM-DD)
    },
    // Paso 2: materiales (capturas simples)
    materials: [
      // { nombre: 'Sticker Puerta', cantidad: 2, fotos: [ {name, type, b64} ] }
    ],
    // Paso 3: encuesta (respuestas simples)
    survey: {
      // answers: { [questionId]: value }
      answers: {}
    }
  };

  // ---------- Utilidades DOM ----------
  const el = (html) => {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  };

  const escapeHTML = (s) => (s || '').replace(/[&<>"']/g, (m) => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));

  const badge = (txt, cls='label-default') =>
    `<span class="label ${cls} net-badge">${escapeHTML(txt)}</span>`;

  // ---------- Carga condicional de librerías locales (desde cache del SW) ----------
  function loadScript(url) {
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = url;
      s.async = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Failed ' + url));
      document.head.appendChild(s);
    });
  }

  async function ensureLibs() {
    if (bootedLibs) return true;
    try {
      // Orden recomendado: db.js -> offline-queue.js -> v2_cache.js
      await loadScript(`${APP_SCOPE}/assets/js/db.js`);
    } catch(_) {}
    try {
      await loadScript(`${APP_SCOPE}/assets/js/offline-queue.js`);
    } catch(_) {}
    try {
      await loadScript(`${APP_SCOPE}/assets/js/v2_cache.js`);
    } catch(_) {}
    bootedLibs = true;
    return true;
  }

  // ---------- KV (V2Cache / localStorage) ----------
  async function kvGet(store, key) {
    await ensureLibs();
    if (window.V2Cache && typeof V2Cache.get === 'function') {
      try { return await V2Cache.get(store, key); } catch(_) {}
    }
    try {
      const raw = localStorage.getItem(`${store}:${key}`);
      return raw ? JSON.parse(raw) : null;
    } catch(_) { return null; }
  }

  async function kvSet(store, key, value) {
    await ensureLibs();
    if (window.V2Cache && typeof V2Cache.put === 'function') {
      try { return await V2Cache.put(store, key, value); } catch(_) {}
    }
    try {
      localStorage.setItem(`${store}:${key}`, JSON.stringify(value));
      return true;
    } catch(_) { return false; }
  }

  // ---------- Cola offline (OfflineQueue / fallback) ----------
  async function enqueueRequest(url, options) {
    await ensureLibs();
    const task = {
      url,
      method: (options && options.method) || 'POST',
      headers: (options && options.headers) || {},
      body: (options && options.body) || null,
      createdAt: Date.now(),
      key: `idempo:${KEY}:${Date.now()}`
    };

    if (window.OfflineQueue && typeof OfflineQueue.enqueue === 'function') {
      try { await OfflineQueue.enqueue(task.url, task); return true; } catch(_) {}
    }
    if (window.Queue && typeof Queue.enqueue === 'function') {
      try { await Queue.enqueue(task.url, task); return true; } catch(_) {}
    }
    // Fallback localStorage (se procesará cuando haya UI/JS que lo lea)
    try {
      const lsKey = 'offlineQueue:fallback';
      const arr = JSON.parse(localStorage.getItem(lsKey) || '[]');
      arr.push(task);
      localStorage.setItem(lsKey, JSON.stringify(arr));
      return true;
    } catch(_) {}
    return false;
  }

  // ---------- Online badge ----------
  function updateHeader() {
    const online = navigator.onLine;
    const net = online ? badge('Online','label-success') : badge('Offline','label-warning');
    $header.innerHTML = `
      <strong>Gestionar local #${idLocal}</strong> &middot; <em>${escapeHTML(nombreCampana)}</em> ${net}
      <div style="margin-top:6px;font-size:12px;color:#777">Trabajando en modo app-shell offline.
        Tus cambios se guardan localmente y se enviarán cuando vuelva la conexión.
      </div>`;
  }

  window.addEventListener('online',  updateHeader);
  window.addEventListener('offline', updateHeader);

  // ---------- Paso 1 ----------
  function renderStep1() {
    $step1B.innerHTML = '';
    $step1B.appendChild(el(`
      <div>
        <p class="text-muted">Selecciona el estado general de la gestión para este local.</p>
        <div class="radio">
          <label><input type="radio" name="impl" value="1"${state.status.implementado === true  ? ' checked':''}> Implementado</label>
        </div>
        <div class="radio">
          <label><input type="radio" name="impl" value="0"${state.status.implementado === false ? ' checked':''}> No implementado</label>
        </div>
        <div class="form-group" style="margin-top:10px;">
          <label>Motivo / Observación</label>
          <textarea id="motivo" class="form-control" placeholder="Escribe un comentario...">${escapeHTML(state.status.motivo || '')}</textarea>
        </div>
        <div class="form-group">
          <label>Fecha propuesta (opcional)</label>
          <input id="fprop" type="date" class="form-control" value="${escapeHTML(state.status.fechaPropuesta || '')}">
        </div>
      </div>
    `));

    const radios = $step1B.querySelectorAll('input[name="impl"]');
    radios.forEach(r => r.addEventListener('change', async () => {
      state.status.implementado = (r.value === '1');
      state.meta.lastUpdateAt = Date.now();
      await persistState();
      refreshNavButtons();
    }));

    $step1B.querySelector('#motivo').addEventListener('input', async (e) => {
      state.status.motivo = e.target.value;
      state.meta.lastUpdateAt = Date.now();
      await persistState();
    });
    $step1B.querySelector('#fprop').addEventListener('change', async (e) => {
      state.status.fechaPropuesta = e.target.value;
      state.meta.lastUpdateAt = Date.now();
      await persistState();
    });
  }

  // ---------- Paso 2 ----------
  function renderStep2() {
    $step2B.innerHTML = '';

    const $list = el(`<div class="list-group" id="matList" style="margin-bottom:10px;"></div>`);
    const $add = el(`
      <div class="panel panel-default">
        <div class="panel-heading"><strong>Agregar material</strong></div>
        <div class="panel-body">
          <div class="form-inline">
            <input id="matNombre" type="text" class="form-control" placeholder="Nombre del material" style="width:48%;margin-right:2%">
            <input id="matCant" type="number" class="form-control" placeholder="Cantidad" style="width:20%;margin-right:2%">
            <button id="btnAddMat" class="btn btn-primary">Agregar</button>
          </div>
        </div>
      </div>
    `);

    $step2B.appendChild($list);
    $step2B.appendChild($add);

    function repaintList() {
      $list.innerHTML = '';
      if (!state.materials || !state.materials.length) {
        $list.appendChild(el(`<div class="list-group-item text-muted">Sin materiales agregados.</div>`));
        return;
      }
      state.materials.forEach((m, idx) => {
        const $it = el(`
          <div class="list-group-item">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div><strong>${escapeHTML(m.nombre)}</strong> &middot; Cant: ${m.cantidad || 0}</div>
              <div>
                <label class="btn btn-default btn-xs" style="margin-right:6px;">
                  Adjuntar fotos<input type="file" accept="image/*" multiple style="display:none" data-idx="${idx}">
                </label>
                <button class="btn btn-danger btn-xs" data-del="${idx}"><i class="fa fa-trash"></i></button>
              </div>
            </div>
            <div style="margin-top:6px" id="fotos-${idx}">${(m.fotos && m.fotos.length) ? `${m.fotos.length} foto(s) adjunta(s).` : '<span class="text-muted">Sin fotos</span>'}</div>
          </div>
        `);
        $list.appendChild($it);
      });

      // Bind deletes
      $list.querySelectorAll('button[data-del]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const i = parseInt(btn.getAttribute('data-del'),10);
          state.materials.splice(i,1);
          state.meta.lastUpdateAt = Date.now();
          await persistState();
          repaintList();
        });
      });

      // Bind file pickers
      $list.querySelectorAll('input[type="file"][data-idx]').forEach(inp => {
        inp.addEventListener('change', async (e) => {
          const i = parseInt(inp.getAttribute('data-idx'),10);
          const files = Array.from(e.target.files || []);
          if (!files.length) return;
          const fotos = state.materials[i].fotos || [];
          for (const f of files) {
            const b64 = await fileToBase64(f);
            fotos.push({ name: f.name, type: f.type, b64 });
          }
          state.materials[i].fotos = fotos;
          state.meta.lastUpdateAt = Date.now();
          await persistState();
          repaintList();
        });
      });
    }

    $add.querySelector('#btnAddMat').addEventListener('click', async () => {
      const nombre = ($add.querySelector('#matNombre').value || '').trim();
      const cantidad = parseInt($add.querySelector('#matCant').value || '0', 10);
      if (!nombre) {
        alert('Especifica el nombre del material');
        return;
      }
      state.materials.push({ nombre, cantidad: isFinite(cantidad) ? cantidad : 0, fotos: [] });
      $add.querySelector('#matNombre').value = '';
      $add.querySelector('#matCant').value = '';
      state.meta.lastUpdateAt = Date.now();
      await persistState();
      repaintList();
    });

    repaintList();
  }

  // ---------- Paso 3 ----------
  function renderStep3() {
    $step3B.innerHTML = '';
    // Nota: sin conocer el set de preguntas, ofrecemos captura genérica (clave/valor) + textarea
    $step3B.appendChild(el(`
      <div>
        <p class="text-muted">Captura simple de respuestas para encuesta (clave → valor). Si existe una versión cacheada de la encuesta, se mapeará en servidor durante la sincronización.</p>
        <div id="kv"></div>
        <div class="form-inline" style="margin-top:10px;">
          <input id="k" type="text" class="form-control" placeholder="clave" style="width:38%;margin-right:2%">
          <input id="v" type="text" class="form-control" placeholder="valor" style="width:38%;margin-right:2%">
          <button id="addKV" class="btn btn-default">Agregar</button>
        </div>
        <div class="form-group" style="margin-top:12px;">
          <label>Observaciones (opcional)</label>
          <textarea id="obs" class="form-control" placeholder="Notas adicionales"></textarea>
        </div>
      </div>
    `));

    const $kv = $step3B.querySelector('#kv');
    const $obs = $step3B.querySelector('#obs');

    function repaintKV() {
      $kv.innerHTML = '';
      const keys = Object.keys(state.survey.answers || {});
      if (!keys.length) {
        $kv.appendChild(el(`<div class="text-muted">Sin respuestas.</div>`));
        return;
      }
      keys.forEach(k => {
        const val = state.survey.answers[k];
        const $row = el(`
          <div class="well well-sm" style="display:flex;justify-content:space-between;align-items:center;">
            <div><strong>${escapeHTML(k)}</strong>: ${escapeHTML(String(val))}</div>
            <button class="btn btn-xs btn-danger" data-del="${escapeHTML(k)}"><i class="fa fa-trash"></i></button>
          </div>
        `);
        $kv.appendChild($row);
      });
      $kv.querySelectorAll('button[data-del]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const key = btn.getAttribute('data-del');
          delete state.survey.answers[key];
          state.meta.lastUpdateAt = Date.now();
          await persistState();
          repaintKV();
        });
      });
    }

    $step3B.querySelector('#addKV').addEventListener('click', async () => {
      const k = ($step3B.querySelector('#k').value || '').trim();
      const v = ($step3B.querySelector('#v').value || '').trim();
      if (!k) return;
      state.survey.answers[k] = v;
      $step3B.querySelector('#k').value = '';
      $step3B.querySelector('#v').value = '';
      state.meta.lastUpdateAt = Date.now();
      await persistState();
      repaintKV();
    });

    // persistir obs como answer especial
    $obs.addEventListener('input', async (e) => {
      state.survey.answers.__obs__ = e.target.value;
      state.meta.lastUpdateAt = Date.now();
      await persistState();
    });

    // Prefill obs si existía
    if (state.survey.answers && typeof state.survey.answers.__obs__ === 'string') {
      $obs.value = state.survey.answers.__obs__;
    }
    repaintKV();
  }

  // ---------- Persistencia del estado ----------
  async function persistState() {
    await kvSet(STORE_KEY, KEY, state);
  }

  async function loadState() {
    const saved = await kvGet(STORE_KEY, KEY);
    if (saved && typeof saved === 'object') {
      state = saved;
    }
  }

  // ---------- Navegación ----------
  function refreshNavButtons() {
    // Habilitar next si en Paso 1 eligieron implementado (true/false)
    if (currentStep === 1) {
      const ok = (state.status.implementado === true || state.status.implementado === false);
      $btnNext.disabled = !ok;
      $btnBack.disabled = true;
      $btnFin.style.display = 'none';
    } else if (currentStep === 2) {
      $btnNext.disabled = false;
      $btnBack.disabled = false;
      $btnFin.style.display = 'none';
    } else {
      $btnNext.disabled = true;
      $btnBack.disabled = false;
      $btnFin.style.display = '';
    }
  }

  function showStep(n) {
    currentStep = n;
    $step1.style.display = (n === 1) ? '' : 'none';
    $step2.style.display = (n === 2) ? '' : 'none';
    $step3.style.display = (n === 3) ? '' : 'none';
    refreshNavButtons();
  }

  // ---------- Terminar / Encolar Sync ----------
  async function finishAndQueue() {
    try {
      // bundle compacto
      const bundle = {
        type: 'gestionar_local',
        version: 1,
        meta: state.meta,
        ids: { idCampana, idLocal },
        status: state.status,
        materials: state.materials,
        survey: state.survey,
        // pista para idempotencia desde el server
        idempotency_key: `bundle:${idCampana}:${idLocal}:${state.meta.startedAt}`
      };

      // Guardar copia local
      await kvSet(STORE_KEY, KEY, state);

      // Intentar encolar a /api/sync_bundle.php (JSON)
      const ok = await enqueueRequest(`${APP_SCOPE}/api/sync_bundle.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(bundle)
      });

      if (ok) {
        alert('Gestión guardada localmente y encolada para sincronización.');
        // dejar rastro ligero pero no vaciar fotos por si hay retry; sólo marcamos "queuedAt"
        state.meta.queuedAt = Date.now();
        await persistState();
      } else {
        alert('No se pudo encolar la sincronización. Queda guardada localmente.');
      }
    } catch (err) {
      console.error(err);
      alert('Ocurrió un error al preparar el envío offline. Reintenta más tarde.');
    }
  }

  // ---------- Utilidad archivos ----------
  function fileToBase64(file) {
    return new Promise((resolve, reject) => {
      const fr = new FileReader();
      fr.onload = () => resolve(String(fr.result || '').split(',')[1] || '');
      fr.onerror = reject;
      fr.readAsDataURL(file);
    });
  }

  // ---------- Boot ----------
  async function boot() {
    // Validación mínima de parámetros
    if (!idCampana || !idLocal) {
      $header.innerHTML = `<strong>Faltan parámetros</strong> ${badge('Offline','label-warning')}<br><span class="text-danger">No se encontró idCampana/idLocal en la URL.</span>`;
      [$step1, $step2, $step3].forEach(s => (s.style.display = 'none'));
      return;
    }

    updateHeader();

    // Mostrar paneles
    $step1.style.display = '';
    $step2.style.display = 'none';
    $step3.style.display = 'none';

    await loadState();

    // Render contenido
    renderStep1();
    renderStep2();
    renderStep3();

    // Bind navegación
    $btnBack.addEventListener('click', () => {
      if (currentStep > 1) showStep(currentStep - 1);
    });
    $btnNext.addEventListener('click', () => {
      if (currentStep < 3) showStep(currentStep + 1);
    });
    $btnFin.addEventListener('click', finishAndQueue);

    refreshNavButtons();
  }

  // Iniciar
  try { boot(); } catch (e) { console.error(e); }
})();

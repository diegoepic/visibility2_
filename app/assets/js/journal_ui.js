(function(){
  'use strict';

  const BASE                     = '/visibility2/app';
  const JOURNAL_SERVER_ENDPOINT  = BASE + '/api/journal_server.php';
  const JOURNAL_DETAIL_ENDPOINT  = BASE + '/api/journal_server_detalle.php';
  const EXPORT_DIAGNOSTIC_ENDPOINT = BASE + '/api/export_diagnostico.php';
  const CSRF_ENDPOINT            = BASE + '/csrf_refresh.php';

  const qs  = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));

  const $today = qs('#jr-list-today');
  const $week  = qs('#jr-list-week');
  const $pg    = qs('#jr-global-progress');

  let GROUPS_TODAY   = {};
  let GROUPS_WEEK    = {};
  let OFFLINE_GUIDS  = new Set();

  let _calYear        = null;
  let _calMonth       = null;
  let _calSelectedYmd = null;

  // ========== MEJORA: Debounce para evitar race conditions en renderizado ==========
  const RENDER_DEBOUNCE_MS = 150;
  const PROGRESS_DEBOUNCE_MS = 50; // Más rápido para actualizaciones de progreso
  let _renderTodayTimer = null;
  let _renderWeekTimer = null;
  let _isRenderingToday = false;
  let _isRenderingWeek = false;

  function debounce(fn, wait) {
    let timer = null;
    return function(...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  // ========== MEJORA: Actualizaciones parciales de progreso (sin re-render completo) ==========

  function chipClassForStatus(st) {
    if (st === 'success')      return 'jr-chip--ok';
    if (st === 'running')      return 'jr-chip--run';
    if (st === 'blocked_auth') return 'jr-chip--block';
    if (st === 'blocked_csrf') return 'jr-chip--block';
    if (st === 'error')        return 'jr-chip--err';
    if (st === 'canceled')     return 'jr-chip--cancel';
    return 'jr-chip--pend';
  }

  function chipTextForStatus(st) {
    if (st === 'success')      return 'Subida OK';
    if (st === 'running')      return 'Enviando';
    if (st === 'blocked_auth') return 'Requiere reloguear';
    if (st === 'blocked_csrf') return 'CSRF inválido';
    if (st === 'error')        return 'Error terminal';
    if (st === 'canceled')     return 'Cancelado';
    return 'Pendiente';
  }

  // Actualizar solo el progreso de un job específico (sin re-render completo)
  function updateJobProgressOnly(jobId, progress, status) {
    if (!jobId) return false;

    // Buscar en detalles expandidos
    const detailRow = document.querySelector(`.jr-detail-row[data-job-id="${jobId}"]`);
    if (detailRow) {
      const bar = detailRow.querySelector('.progress-bar');
      if (bar) {
        const p = pct(progress);
        animateProgressBar(bar, p);
        if (status === 'success' || p >= 100) {
          bar.classList.add('progress-bar-success');
        }
      }
      const chip = detailRow.querySelector('.jr-chip');
      if (chip && status) {
        chip.className = `jr-chip ${chipClassForStatus(status)}`;
        chip.textContent = chipTextForStatus(status);
      }
      return true;
    }
    return false;
  }

  // Generar groupKey desde un job (para actualización parcial)
  function getGroupKeyFromJobInternal(job) {
    if (!job) return null;
    const fields = job.fields || {};
    const meta = job.meta || {};
    const localId = meta.local_id || fields.id_local || fields.idLocal || fields.local_id || job.local_id || 0;
    const ymd = (window.JournalDB && JournalDB.ymdLocal) ? JournalDB.ymdLocal(new Date()) : new Date().toISOString().slice(0,10);
    return `${ymd}|${localId}`;
  }

  // Actualizar progreso de un grupo (local) sin re-render completo
  function updateGroupProgressOnly(groupKey, progress, status) {
    if (!groupKey) return false;

    const groupEl = document.querySelector(`.jr-group[data-key="${groupKey}"]`);
    if (!groupEl) return false;

    const bar = groupEl.querySelector('.progress-bar');
    if (bar) {
      const p = pct(progress);
      animateProgressBar(bar, p);
      if (status === 'success' || p >= 100) {
        bar.classList.add('progress-bar-success');
      }
    }

    const chip = groupEl.querySelector('.jr-chip');
    if (chip && status) {
      chip.className = `jr-chip ${chipClassForStatus(status)}`;
      chip.textContent = chipTextForStatus(status);
    }

    return true;
  }

  // Animar barra de progreso suavemente con requestAnimationFrame
  function animateProgressBar(element, targetPct, durationMs = 150) {
    if (!element) return;

    // Obtener valor actual
    const currentWidth = parseFloat(element.style.width) || 0;
    const targetWidth = Math.max(0, Math.min(100, targetPct));

    // Si la diferencia es pequeña, actualizar directo
    if (Math.abs(targetWidth - currentWidth) < 2 || !window.requestAnimationFrame) {
      element.style.width = targetWidth + '%';
      element.textContent = Math.round(targetWidth) + '%';
      return;
    }

    const start = performance.now();

    function frame(now) {
      const elapsed = now - start;
      const progress = Math.min(elapsed / durationMs, 1);

      // Easing suave (ease-out cubic)
      const eased = 1 - Math.pow(1 - progress, 3);
      const currentPct = currentWidth + (targetWidth - currentWidth) * eased;

      element.style.width = currentPct + '%';
      element.textContent = Math.round(currentPct) + '%';

      if (progress < 1) {
        requestAnimationFrame(frame);
      }
    }

    requestAnimationFrame(frame);
  }

  // Actualizar badges sin re-render completo
  async function updateBadgesOnly() {
    try {
      const ymd = (window.JournalDB && JournalDB.ymdLocal) ? JournalDB.ymdLocal(new Date()) : new Date().toISOString().slice(0,10);
      const rows = await JournalDB.listByYMD(ymd);
      const stats = await JournalDB.statsFor(rows);

      badge(qs('#jr-badge-pending'), stats.pending, 'Pendientes');
      badge(qs('#jr-badge-running'), stats.running, 'Enviando');
      badge(qs('#jr-badge-success'), stats.success, 'Subidas');
      badge(qs('#jr-badge-error'),   stats.error,   'Errores');
      badge(qs('#jr-badge-blocked'), stats.blocked, 'Bloqueadas');

      const total = stats.pending + stats.running + stats.error + stats.success + stats.blocked;
      setGlobalProgress(stats.success, total);
    } catch(_) {}
  }

  // ========== MEJORA: Persistir OFFLINE_GUIDS en localStorage ==========
  const OFFLINE_GUIDS_STORAGE_KEY = 'jr_offline_guids';

  function loadOfflineGuidsFromStorage() {
    try {
      const stored = localStorage.getItem(OFFLINE_GUIDS_STORAGE_KEY);
      if (stored) {
        const arr = JSON.parse(stored);
        if (Array.isArray(arr)) {
          OFFLINE_GUIDS = new Set(arr);
        }
      }
    } catch(_) {}
  }

  function saveOfflineGuidsToStorage() {
    try {
      const arr = Array.from(OFFLINE_GUIDS).slice(-500); // Limitar a 500 más recientes
      localStorage.setItem(OFFLINE_GUIDS_STORAGE_KEY, JSON.stringify(arr));
    } catch(_) {}
  }

  // Cargar al inicio
  loadOfflineGuidsFromStorage();

  // ========== MEJORA: Indicador de modo offline ==========
  function createOfflineIndicator() {
    if (document.getElementById('jr-offline-indicator')) return;
    const indicator = document.createElement('div');
    indicator.id = 'jr-offline-indicator';
    indicator.className = 'jr-offline-banner';
    indicator.style.cssText = `
      display: none;
      background: linear-gradient(90deg, #f39c12, #e67e22);
      color: white;
      padding: 8px 16px;
      text-align: center;
      font-weight: 500;
      font-size: 13px;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    `;
    indicator.innerHTML = '<i class="fa fa-wifi" style="margin-right:8px;"></i> Sin conexión - Los datos se sincronizarán automáticamente cuando vuelvas a estar online';

    // Insertar al inicio del contenedor principal
    const container = qs('#jr-list-today')?.parentElement || document.body;
    container.insertBefore(indicator, container.firstChild);
  }

  function updateOfflineIndicator() {
    const indicator = document.getElementById('jr-offline-indicator');
    if (!indicator) return;

    if (navigator.onLine) {
      indicator.style.display = 'none';
    } else {
      indicator.style.display = 'block';
    }
  }

  // Escuchar cambios de conectividad
  window.addEventListener('online', updateOfflineIndicator);
  window.addEventListener('offline', updateOfflineIndicator);

  // Sanitización mejorada usando DOM API (previene XSS más efectivamente)
  function esc(str){
    if (str == null) return '';
    // Usar createElement + textContent es más seguro que regex manual
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  function showMessage(title, msg, type){
    if (window.swal) {
      window.swal(title, msg, type);
    } else if (window.alert) {
      window.alert(title ? `${title}: ${msg}` : msg);
    }
  }

  async function ensureCsrfToken(){
    if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
    try {
      const res = await fetch(CSRF_ENDPOINT, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      if (res.ok) {
        const js = await res.json();
        if (js && js.csrf_token) {
          window.CSRF_TOKEN = js.csrf_token;
          return js.csrf_token;
        }
      }
    } catch(_){}
    return window.CSRF_TOKEN || null;
  }


  function fmtTime(ts){
    const d = ts ? new Date(ts) : new Date();
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' });
  }

  function fmtRetry(ts){
    if (!ts) return '';
    const d = new Date(ts);
    if (Number.isNaN(d.getTime())) return '';

    // MEJORA: Si el tiempo de reintento ya pasó, mostrar mensaje diferente
    const now = Date.now();
    if (d.getTime() < now) {
      return 'Reintentando pronto...';
    }

    // Si es en menos de 1 minuto
    const diffMs = d.getTime() - now;
    if (diffMs < 60000) {
      const secs = Math.ceil(diffMs / 1000);
      return `Reintentará en ${secs} segundo${secs !== 1 ? 's' : ''}`;
    }

    return `Reintentará a las ${fmtTime(d)}`;
  }


  function fmtDate(ts){
    const d = ts ? new Date(ts) : new Date();
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('es-CL', {
      weekday:'short', day:'2-digit', month:'2-digit', year:'numeric'
    });
  }

  function fmtDuration(seg){
    let s = Number(seg || 0);
    if (!Number.isFinite(s) || s <= 0) return '';
    const min = Math.floor(s / 60);
    const rem = s % 60;
    if (min && rem) return `${min} min ${rem} s`;
    if (min)        return `${min} min`;
    return `${rem} s`;
  }

  function badge(el, n, txt){
    if (!el) return;
    el.textContent = `${txt}: ${n}`;
  }

  function pct(n){
    return Math.max(0, Math.min(100, Math.round(n || 0)));
  }

  function setGlobalProgress(done, total){
    const p = total > 0 ? Math.round((done / total) * 100) : 0;
    if (!$pg) return;
    $pg.style.width = p + '%';
    $pg.textContent = p + '%';
    $pg.className   = 'progress-bar ' + (p >= 100 ? 'progress-bar-success' : '');
  }

  async function fetchServerJournal(fromYmd, toYmd){
    try {
      if (typeof fetch !== 'function') return [];
      if (typeof navigator !== 'undefined' && navigator && navigator.onLine === false){
        // Navegador reporta offline → no intentamos
        return [];
      }

      const params = new URLSearchParams();
      if (fromYmd) params.set('from', fromYmd);
      if (toYmd)   params.set('to',   toYmd);

      const res = await fetch(JOURNAL_SERVER_ENDPOINT + '?' + params.toString(), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) return [];
      const data = await res.json();
      if (!data || data.status !== 'ok' || !Array.isArray(data.items)) return [];
      return data.items;
    } catch(_){
      return [];
    }
  }

  // ---------- OFFLINE GUIDS (para marcar subidas offline) ----------

  function collectOfflineGuids(rows){
    // MEJORA: Mantener GUIDs existentes y agregar nuevos
    if (!Array.isArray(rows)) {
      return;
    }
    rows.forEach(r => {
      const g =
        (r && (r.guid || r.client_guid)) ||
        (r && r.vars && (r.vars.client_guid || r.vars.guid)) ||
        null;
      if (g) OFFLINE_GUIDS.add(String(g));
    });
    // Persistir en localStorage
    saveOfflineGuidsToStorage();
  }

  // ---------- Agrupación: DÍA + LOCAL (para cola local) ----------

  function groupKey(r){
    const localKey = r.local_id || r.visita_local_id || 0;
    return `${r.ymd || ''}|${localKey}`;
  }

  /**
   * Crea una clave de agrupación para detectar items relacionados.
   * Usa client_guid si existe, sino visita_local_id+kind, sino local_id+form_id+kind.
   */
  function itemGroupingKey(it){
    const kind = (it.kind || '').toLowerCase();
    if (it.client_guid) return `guid:${it.client_guid}:${kind}`;
    if (it.visita_local_id) return `visita:${it.visita_local_id}:${kind}`;
    if (it.local_id && it.form_id) return `local:${it.local_id}:${it.form_id}:${kind}`;
    return null; // No agrupable
  }

  /**
   * Filtra items stale: si hay un item exitoso para una agrupación,
   * descarta items de la misma agrupación que estén en error/blocked.
   * Esto evita mostrar errores de intentos anteriores cuando ya hubo éxito.
   */
  function filterStaleItems(items){
    if (!items || !items.length) return items;

    // Agrupar por clave de agrupación
    const byKey = {};
    const noKey = [];

    for (const it of items){
      const key = itemGroupingKey(it);
      if (key){
        (byKey[key] = byKey[key] || []).push(it);
      } else {
        noKey.push(it);
      }
    }

    const filtered = [...noKey];

    // Para cada grupo, verificar si hay éxitos
    const isStale = (st) => (st === 'error' || st === 'blocked_auth' || st === 'blocked_csrf');

    for (const key of Object.keys(byKey)){
      const group = byKey[key];
      const hasSuccess = group.some(x => x.status === 'success');

      if (hasSuccess){
        // Si hay éxito, ocultar solo errores/bloqueos stale, pero mantener queued/running.
        const keep = group.filter(x => !isStale(x.status));
        filtered.push(...(keep.length ? keep : group.filter(x => x.status === 'success')));
      } else {
        // Sin éxitos, mantener todo (incluyendo errores que necesitan atención)
        filtered.push(...group);
      }
    }

    return filtered;
  }

  /**
   * Calcula el estado agregado del grupo.
   * MEJORA: Si todos los errores/blocked son de items que tienen un equivalente exitoso
   * del mismo tipo/kind, considerar el grupo como exitoso.
   */
  function aggStatus(items){
    // Primero filtrar items stale
    const filtered = filterStaleItems(items);

    // Si después de filtrar todos son success, retornar success
    if (filtered.every(x => x.status === 'success')) return 'success';

    // Orden de prioridad de estados problemáticos
    if (filtered.some(x => x.status === 'blocked_auth')) return 'blocked_auth';
    if (filtered.some(x => x.status === 'blocked_csrf')) return 'blocked_csrf';
    if (filtered.some(x => x.status === 'error'))        return 'error';
    if (filtered.some(x => x.status === 'running'))      return 'running';
    if (filtered.some(x => x.status === 'queued'))       return 'queued';
    return 'success';
  }

  function aggProgress(items){
    const filtered = filterStaleItems(items);
    if (!filtered.length) return 0;
    const sum = filtered.reduce((a, x) => a + (x.status === 'success' ? 100 : (x.progress || 0)), 0);
    return Math.round(sum / filtered.length);
  }

  function countByKind(items){
    const filtered = filterStaleItems(items);
    const c = { materialPhotos:0, surveyPhotos:0, answers:0, create:0, other:0 };
    for (const it of filtered){
      const kind = (it.kind || '').toLowerCase();
      const phts = (it.counts && it.counts.photos) ? Number(it.counts.photos) : 0;

      if (kind.includes('upload_material'))        c.materialPhotos += (phts || 1);
      else if (kind.includes('pregunta_foto'))     c.surveyPhotos   += (phts || 1);
      else if (kind.includes('procesar_gestion'))  c.answers        += 1;
      else if (kind.includes('create_visita'))     c.create         += 1;
      else                                         c.other          += 1;
    }
    return c;
  }

  const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;

  function explFromCounts(c){
    const parts = [];
    if (c.materialPhotos) parts.push(plural(c.materialPhotos, 'foto de material', 'fotos de materiales'));
    if (c.surveyPhotos)   parts.push(plural(c.surveyPhotos,   'foto de encuesta', 'fotos de encuesta'));
    if (c.answers)        parts.push(plural(c.answers,        'gestión/respuesta', 'gestiones/respuestas'));
    if (c.create)         parts.push(plural(c.create,         'creación de visita', 'creaciones de visita'));
    if (c.other)          parts.push(plural(c.other,          'tarea', 'tareas'));
    return parts.join(', ') || '—';
  }

  function popoverHTML(gr){
    const c = gr.kindCounts;
    const bullets = [
      c.materialPhotos ? `<li><b>Fotos de materiales</b>: ${c.materialPhotos}</li>` : '',
      c.surveyPhotos   ? `<li><b>Fotos de encuesta</b>: ${c.surveyPhotos}</li>`     : '',
      c.answers        ? `<li><b>Gestión/Respuestas</b>: ${c.answers}</li>`         : '',
      c.create         ? `<li><b>Creación de visita</b>: ${c.create}</li>`          : '',
      c.other          ? `<li><b>Otras tareas</b>: ${c.other}</li>`                 : ''
    ].join('');

    return `<div style="min-width:220px">
      <div><b>¿Qué se está cargando?</b></div>
      <ul style="padding-left:18px;margin:6px 0">${bullets || '<li>Sin tareas</li>'}</ul>
      <small>La barra resume el avance total de este local hoy.</small>
    </div>`;
  }

  function uniq(arr){
    return Array.from(new Set(arr.filter(Boolean)));
  }

  function groupFromRows(rows){
    const g = {};
    for (const r of rows || []){
      const k = groupKey(r);
      (g[k] = g[k] || {
        key: k,
        items: [],
        ymd: r.ymd,
        local_id: r.local_id || r.visita_local_id || null,
        names: {},
        created: r.created,
        campaigns: new Set()
      });
      g[k].items.push(r);
      if (!g[k].created || r.created < g[k].created) g[k].created = r.created;
      if (r.names && r.names.campaign) g[k].campaigns.add(r.names.campaign);
      // merge names si vienen resueltos
      g[k].names = Object.assign({}, g[k].names, r.names || {});
    }

    Object.values(g).forEach(gr => {
      // MEJORA: Filtrar items stale para renderizado y stats
      gr.filteredItems = filterStaleItems(gr.items);
      gr.status     = aggStatus(gr.items);
      gr.progress   = aggProgress(gr.items);
      gr.kindCounts = countByKind(gr.items);
      gr.expl       = explFromCounts(gr.kindCounts);
      gr.counts     = {
        total:   gr.filteredItems.length,
        pending: gr.filteredItems.filter(x => x.status === 'queued').length
      };
      gr.campaigns = uniq(Array.from(gr.campaigns));

      // merge de vars (estado_final, fecha_reagendada, etc.)
      gr.vars = gr.items.reduce((acc, it) => {
        if (it.vars){
          Object.keys(it.vars).forEach(function(k){
            if (it.vars[k] != null) acc[k] = it.vars[k];
          });
        }
        return acc;
      }, {});
    });

    return g;
  }

  // ---------- HTML para grupos (cola local) ----------

  function chipForStatus(st){
    if (st === 'success')      return '<span class="jr-chip jr-chip--ok">Subida OK</span>';
    if (st === 'running')      return '<span class="jr-chip jr-chip--run">Enviando</span>';
    if (st === 'blocked_auth') return '<span class="jr-chip jr-chip--block">Requiere reloguear</span>';
    if (st === 'blocked_csrf') return '<span class="jr-chip jr-chip--block">CSRF inválido</span>';
    if (st === 'error')        return '<span class="jr-chip jr-chip--err">Error terminal</span>';
    if (st === 'canceled')     return '<span class="jr-chip jr-chip--cancel">Cancelado</span>';
    return '<span class="jr-chip jr-chip--pend">Pendiente</span>';
  }

  function groupHTML(gr){
    const lName  = gr.names && gr.names.local ? esc(gr.names.local) : (gr.local_id ? `Local #${gr.local_id}` : '—');
    const codigo = gr.names && gr.names.codigo ? ` (${esc(gr.names.codigo)})` : '';
    const comuna = gr.names && gr.names.comuna ? ` · ${esc(gr.names.comuna)}` : '';
    const dir    = gr.names && gr.names.direccion ? esc(gr.names.direccion) : '';
    const campTxt= gr.campaigns && gr.campaigns.length
      ? esc(gr.campaigns.join(' · '))
      : (gr.names && gr.names.campaign ? esc(gr.names.campaign) : '');

    const content = popoverHTML(gr).replace(/"/g, '&quot;');
    const canRetry = (gr.status !== 'success' && gr.status !== 'blocked_auth' && gr.status !== 'blocked_csrf'); // si todo ok, se desactiva
    const displayItems = gr.filteredItems || gr.items;
    const nextTryAt = displayItems
      .map(it => it.next_try_at || it.nextTryAt || null)
      .filter(Boolean)
      .sort()[0] || null;
    const retryLine = nextTryAt ? `<div class="jr-subline jr-retry-line">${esc(fmtRetry(nextTryAt))}</div>` : '';
    const errCodes = displayItems
      .map(it => it.last_error && it.last_error.code ? it.last_error.code : null)
      .filter(Boolean);
    const errCode = errCodes.length ? errCodes[0] : null;
    const errHttp = displayItems
      .map(it => it.http_status || (it.last_error && it.last_error.httpStatus))
      .filter(Boolean)[0] || null;
    const errLine = (gr.status === 'error' || gr.status === 'blocked_auth' || gr.status === 'blocked_csrf')
      ? `<div class="jr-error">Fallo: ${esc(errCode || 'ERROR')}${errHttp ? ` · HTTP ${esc(String(errHttp))}` : ''}</div>`
      : '';

    // chip de estado_final (resultado de la gestión, si viene en vars)
    const estadoFinal = gr.vars && gr.vars.estado_final
      ? String(gr.vars.estado_final)
      : null;

    let estadoChip = '';
    if (estadoFinal){
      const lower = estadoFinal.toLowerCase();
      let cls = 'jr-chip--ok';
      if (/pendiente/.test(lower)) cls = 'jr-chip--pend';
      else if (/cancelado|no implementado|rechazado/.test(lower)) cls = 'jr-chip--err';
      estadoChip = `<span class="jr-chip ${cls} jr-chip--estado">${esc(estadoFinal)}</span>`;
    }

    return `
      <div class="jr-item jr-group" data-key="${esc(gr.key)}">
        <div class="jr-left">
          <div class="jr-time">${fmtTime(gr.created)}</div>
          <div class="jr-what">
            <div class="jr-title">${lName}${codigo}<small>${comuna}</small>
              <i class="fa fa-info-circle jr-help" tabindex="0"
                 data-toggle="popover" data-trigger="focus" data-html="true"
                 data-container="body" data-placement="left" title="Detalle de subidas"
                 data-content="${content}"></i>
            </div>
            ${dir ? `<div class="jr-subline">${dir}</div>` : ''}
            <div class="jr-sub">${campTxt ? `${campTxt} · ` : ''}${esc(gr.expl)}</div>
            ${retryLine}
          </div>
        </div>
        <div class="jr-right">
          ${chipForStatus(gr.status)}
          ${estadoChip}
          <div class="progress jr-progress">
            <div class="progress-bar ${gr.progress >= 100 ? 'progress-bar-success' : ''}"
                 style="width:${pct(gr.progress)}%">
              ${pct(gr.progress)}%
            </div>
          </div>
          <div class="jr-actions">
            <button class="btn btn-xs btn-default jr-view">
              <i class="fa fa-eye"></i> Ver tareas
            </button>
            <button class="btn btn-xs btn-default jr-retry" ${canRetry ? '' : 'disabled title="Todo subido"'}>
              <i class="fa fa-refresh"></i> Reintentar${gr.counts.pending ? ` (${gr.counts.pending})` : ''}
            </button>
          </div>
          ${errLine}
        </div>
      </div>
      <div class="jr-details" data-for="${esc(gr.key)}" hidden>
        ${(gr.filteredItems || gr.items).map(it => {
          const p = (it.status === 'success' ? 100 : (it.progress || 0));
          const kind =
            (it.kind || '').includes('upload_material') ? 'Fotos de materiales' :
            (it.kind || '').includes('pregunta_foto')   ? 'Fotos de encuesta'   :
            (it.kind || '').includes('procesar_gestion')? 'Gestión / respuestas' :
            (it.kind || '').includes('create_visita')   ? 'Creación de visita'  :
            (it.kind || 'Tarea');
          const extra = it.counts && it.counts.photos
            ? ` (${it.counts.photos} foto${it.counts.photos > 1 ? 's' : ''})`
            : '';
          const debugParts = [];
          // Se ocultan identificadores técnicos que no aportan al ejecutor
          if (it.visita_local_id) debugParts.push(`local:${esc(it.visita_local_id)}`);
          const errCode = it.last_error && it.last_error.code ? it.last_error.code : null;
          const errMsg  = it.last_error && it.last_error.message ? it.last_error.message : null;
          const errHttp = it.http_status || (it.last_error && it.last_error.httpStatus) || null;
          if (errCode) debugParts.push(`code:${esc(errCode)}`);
          if (errHttp) debugParts.push(`HTTP:${esc(String(errHttp))}`);
          const retryHint = it.next_try_at || it.nextTryAt ? ` · ${esc(fmtRetry(it.next_try_at || it.nextTryAt))}` : '';
          const debugLine = (debugParts.length || errMsg)
            ? `<div class="jr-debug">${debugParts.join(' · ')}${errMsg ? ` · ${esc(errMsg)}` : ''}${retryHint}</div>`
            : '';

          // MEJORA: Botones de acción individual por job
          const canRetryJob = it.status !== 'success' && it.status !== 'running';
          const canCancelJob = it.status !== 'success' && it.status !== 'canceled';
          const jobActions = `
            <button class="btn btn-xs btn-link jr-job-detail" data-job-id="${esc(it.id)}" title="Ver detalle">
              <i class="fa fa-eye"></i>
            </button>
            ${canRetryJob ? `<button class="btn btn-xs btn-link jr-job-retry-inline" data-job-id="${esc(it.id)}" title="Reintentar ahora">
              <i class="fa fa-refresh"></i>
            </button>` : ''}
            ${canCancelJob ? `<button class="btn btn-xs btn-link jr-job-cancel-inline text-danger" data-job-id="${esc(it.id)}" title="Cancelar">
              <i class="fa fa-times"></i>
            </button>` : ''}
          `;

          return `
            <div class="jr-detail-row" data-job-id="${esc(it.id)}">
              <div class="jr-d-left">
                ${chipForStatus(it.status)}
                <span class="jr-d-kind">${esc(kind)}${extra}</span>
              </div>
              <div class="jr-d-right">
                <div class="progress jr-progress">
                  <div class="progress-bar ${p >= 100 ? 'progress-bar-success' : ''}"
                       style="width:${pct(p)}%">
                    ${pct(p)}%
                  </div>
                </div>
                <div class="jr-d-actions">
                  ${jobActions}
                </div>
                ${debugLine}
              </div>
            </div>`;
        }).join('')}
      </div>
    `;
  }

  // ---------- Modal de detalle servidor ----------

  function ensureDetailModal(){
    if (document.getElementById('jr-detail-modal')) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
      <div class="modal fade" id="jr-detail-modal" tabindex="-1" role="dialog" aria-labelledby="jr-detail-title">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                <span aria-hidden="true">&times;</span>
              </button>
              <h4 class="modal-title" id="jr-detail-title">Detalle de visita</h4>
            </div>
            <div class="modal-body" id="jr-detail-body">
              Cargando detalle...
            </div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(wrapper.firstElementChild);
  }

  function ensureJobModal(){
    if (document.getElementById('jr-job-modal')) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
      <div class="modal fade" id="jr-job-modal" tabindex="-1" role="dialog" aria-labelledby="jr-job-title">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                <span aria-hidden="true">&times;</span>
              </button>
              <h4 class="modal-title" id="jr-job-title">Detalle de tarea</h4>
            </div>
            <div class="modal-body" id="jr-job-body">
              Cargando detalle...
            </div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(wrapper.firstElementChild);
  }

  function setJobModalTitle(txt){
    ensureJobModal();
    const el = qs('#jr-job-title');
    if (el) el.textContent = txt || 'Detalle de tarea';
  }

  function setJobModalBody(html){
    ensureJobModal();
    const el = qs('#jr-job-body');
    el.innerHTML = html;
  }

  function setDetailModalTitle(txt){
    ensureDetailModal();
    const el = qs('#jr-detail-title');
    if (el) el.textContent = txt || 'Detalle de visita';
  }

  function setDetailModalBody(html){
    ensureDetailModal();
    const el = qs('#jr-detail-body');
    el.innerHTML = html;
  }

  /**
   * Etiqueta del tipo de gestión.
   * Las campañas complementarias (formulario.tipo = 2) son encuestas: su
   * modalidad no describe lo que hizo el ejecutor, así que se rotulan como
   * "Encuesta IW" en vez de heredar etiquetas de implementación/auditoría.
   */
  function tipoGestionDesdeModalidad(modalidad, tipoFormulario){
    if (Number(tipoFormulario) === 2) return 'Encuesta IW';

    const m = (modalidad || '').toLowerCase();
    if (m === 'solo_auditoria') return 'Auditoría';
    if (m === 'solo_implementacion') return 'Implementación';
    if (m === 'implementacion_auditoria' || m === 'complementaria') return 'Implementación + auditoría';
    if (m === 'retiro') return 'Retiro';
    if (m === 'entrega') return 'Entrega';
    return '';
  }

  /* ============================================================================
     DETALLE DE UNA GESTIÓN (modal "Ver detalle")
     ----------------------------------------------------------------------------
     Se ve casi siempre en un teléfono: nada de tablas de 4 columnas ni de fotos
     con tamaños distintos. Todo se arma con tarjetas que apilan solo, y las
     miniaturas van en una grilla de cuadrados (object-fit:cover) para que una
     foto vertical y una horizontal ocupen lo mismo. Los estilos viven en
     assets/css/journal.css bajo el prefijo .jr-vd-*.
     ========================================================================== */

  /**
   * MySQL manda "2026-08-04 08:42:13". `new Date()` con ese string funciona en
   * Chrome pero NO es estándar (en iOS da Invalid Date), así que se normaliza el
   * espacio a "T" antes de parsear.
   */
  function parseDT(s){
    if (!s) return null;
    const d = new Date(String(s).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function fmtFechaHora(s){
    const d = parseDT(s);
    if (!d) return '';
    return d.toLocaleDateString('es-CL', { day:'2-digit', month:'2-digit', year:'numeric' })
         + ' · '
         + d.toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' });
  }

  function fmtHora(s){
    const d = parseDT(s);
    if (!d) return '';
    return d.toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' });
  }

  /**
   * Las URLs de fotoVisita están guardadas de tres formas distintas según la
   * época ("uploads/x.webp", "/visibility2/app/uploads/x.webp" y absolutas).
   * Todas se dejan absolutas acá para que el modal no dependa de desde qué
   * página se abrió.
   */
  function fotoUrl(u){
    const s = String(u || '').trim();
    if (!s) return '';
    if (/^(https?:)?\/\//i.test(s) || s.startsWith('data:')) return s;
    if (s.startsWith('/')) return s;
    return BASE + '/' + s.replace(/^\.?\//, '');
  }

  /** estado_gestion → etiqueta legible + color. Ver procesar_gestion_pruebas.php. */
  function estadoGestionInfo(estado){
    const e = String(estado || '').toLowerCase().trim();
    switch (e){
      case 'implementado_auditado': return { label:'Implementado y auditado', cls:'is-ok'   };
      case 'implementado':          return { label:'Implementado',            cls:'is-ok'   };
      case 'retirado':              return { label:'Retirado',                cls:'is-ok'   };
      case 'solo_auditoria':        return { label:'Sólo auditoría',          cls:'is-info' };
      case 'armado':                return { label:'Armado',                  cls:'is-info' };
      case 'entregado':             return { label:'Entregado',               cls:'is-info' };
      case 'no_implementado':       return { label:'No implementado',         cls:'is-err'  };
      case 'pendiente':             return { label:'Pendiente',               cls:'is-warn' };
      case 'cancelado':             return { label:'Cancelado',               cls:'is-mute' };
      case '':                      return null;
      default:                      return { label: e.replace(/_/g, ' '), cls:'is-mute' };
    }
  }

  /** Tipo de pregunta por id (1 Sí/No … 7 Foto); el nombre queda de respaldo. */
  function tipoPreguntaLabel(qt){
    const id = qt && qt.id != null ? Number(qt.id) : null;
    switch (id){
      case 1: return 'Sí/No';
      case 2: return 'Selección única';
      case 3: return 'Selección múltiple';
      case 4: return 'Texto';
      case 5: return 'Numérico';
      case 6: return 'Fecha';
      case 7: return 'Foto';
    }
    return qt && qt.name ? String(qt.name) : '';
  }

  function esPreguntaFoto(qt){
    if (qt && Number(qt.id) === 7) return true;
    const n = (qt && qt.name ? String(qt.name) : '').toLowerCase();
    return n === 'photo' || n === 'foto';
  }

  /** Grilla de miniaturas cuadradas. Abre la foto completa en otra pestaña. */
  function thumbsHtml(list){
    const arr = (Array.isArray(list) ? list : []).filter(p => p && p.url);
    if (!arr.length) return '';
    const items = arr.map(p => {
      const u = fotoUrl(p.url);
      return `
        <a class="jr-vd-thumb" href="${esc(u)}" target="_blank" rel="noopener" title="Ver foto${p.id ? ' #' + esc(String(p.id)) : ''}">
          <img src="${esc(u)}" alt="Foto${p.id ? ' ' + esc(String(p.id)) : ''}" loading="lazy">
        </a>`;
    }).join('');
    return `<div class="jr-vd-thumbs">${items}</div>`;
  }

  function kpiHtml(valor, etiqueta){
    const v = (valor === null || valor === undefined || valor === '') ? '—' : valor;
    return `
      <div class="jr-vd-kpi">
        <span class="jr-vd-kpi-n">${esc(String(v))}</span>
        <span class="jr-vd-kpi-l">${esc(etiqueta)}</span>
      </div>`;
  }

  function factHtml(etiqueta, valor){
    const v = String(valor == null ? '' : valor).trim();
    if (!v) return '';
    return `
      <div class="jr-vd-fact">
        <span class="jr-vd-fact-k">${esc(etiqueta)}</span>
        <span class="jr-vd-fact-v">${esc(v)}</span>
      </div>`;
  }

  /**
   * Texto de una respuesta. `option_text` (opción elegida) y `answer_text` (lo
   * tecleado) vienen a veces duplicados: cuando dicen lo mismo se muestra uno.
   */
  function textoRespuesta(a, esFoto){
    const opt = String(a.option_text || '').trim();
    const txt = String(a.answer_text || '').trim();
    if (esFoto) return opt || '';
    if (opt && (!txt || opt === txt)) return opt;
    if (opt && txt) return opt + ' — ' + txt;
    return txt || opt || '';
  }

  function renderServerDetail(payload, fromOffline){
    const basic  = payload.basic || {};
    const loc    = basic.local || {};
    const form   = basic.formulario || {};
    const usr    = basic.usuario || {};
    const t      = basic.tiempos || {};
    const resumen= payload.summary || {};
    const mats   = Array.isArray(payload.materials) ? payload.materials : [];
    const survey = payload.survey || {};
    const qsArr  = Array.isArray(survey.questions) ? survey.questions : [];
    const photosMisc = Array.isArray(payload.photos_misc) ? payload.photos_misc : [];

    const modalidad   = form.modalidad || '';
    const tipoGestion = tipoGestionDesdeModalidad(modalidad, form.tipo);

    // Duración aproximada si tenemos inicio/fin
    let durTxt = '';
    const dIni = parseDT(t.fecha_inicio);
    const dFin = parseDT(t.fecha_fin);
    if (dIni && dFin){
      const diff = (dFin.getTime() - dIni.getTime()) / 1000;
      if (diff > 0) durTxt = fmtDuration(Math.round(diff));
    }

    const ejecutor = [usr.nombre, usr.apellido].filter(Boolean).join(' ').trim();

    let html = '<div class="jr-vd">';

    /* ---------------------------- RESUMEN ---------------------------- */
    html += `
      <section class="jr-vd-sec">
        ${fromOffline ? '<p class="jr-vd-offline"><i class="fa fa-plane"></i> Esta visita se envió desde modo offline</p>' : ''}
        <div class="jr-vd-kpis">
          ${kpiHtml(resumen.materials_count, 'Materiales')}
          ${kpiHtml(resumen.questions_answered, 'Respuestas')}
          ${kpiHtml(resumen.photos_total, 'Fotos')}
          ${durTxt ? kpiHtml(durTxt, 'Duración') : ''}
        </div>
        <div class="jr-vd-facts">
          ${factHtml('Local', (loc.nombre || '') + (loc.codigo ? ' (' + loc.codigo + ')' : ''))}
          ${factHtml('Dirección', loc.direccion)}
          ${factHtml('Distrito', loc.distrito && loc.distrito.nombre ? loc.distrito.nombre : '')}
          ${factHtml('Campaña', form.nombre)}
          ${factHtml('Tipo de gestión', tipoGestion || modalidad)}
          ${factHtml('Ejecutor', ejecutor)}
          ${factHtml('Inicio', fmtFechaHora(t.fecha_inicio))}
          ${factHtml('Término', fmtFechaHora(t.fecha_fin))}
        </div>
      </section>`;

    /* ------------------------ IMPLEMENTACIÓN ------------------------- */
    if (mats.length){
      html += `
        <section class="jr-vd-sec">
          <h4 class="jr-vd-h">Implementación de materiales <span class="jr-vd-count">${mats.length}</span></h4>`;

      mats.forEach(mat => {
        const gsts = Array.isArray(mat.gestiones) ? mat.gestiones : [];
        const phs  = Array.isArray(mat.photos) ? mat.photos : [];

        // El estado que vale es el último registrado; va en la cabecera de la
        // tarjeta para leerlo sin abrir la lista de movimientos.
        const ultimo = gsts.length ? gsts[gsts.length - 1] : null;
        const estUlt = ultimo ? estadoGestionInfo(ultimo.estado_gestion) : null;

        html += `
          <article class="jr-vd-card">
            <header class="jr-vd-card-h">
              <span class="jr-vd-card-t">${esc(mat.material_nombre || 'Sin material')}</span>
              ${estUlt ? `<span class="jr-vd-badge ${estUlt.cls}">${esc(estUlt.label)}</span>` : ''}
            </header>
            <div class="jr-vd-card-b">`;

        if (gsts.length){
          html += `<ul class="jr-vd-evs">`;
          gsts.forEach(g => {
            const est = estadoGestionInfo(g.estado_gestion);
            const obs = [g.motivo_no_implementacion, g.observacion]
              .map(x => String(x || '').trim()).filter(Boolean).join(' · ');
            const valor = (g.valor_real === null || g.valor_real === undefined || g.valor_real === '')
              ? '' : String(g.valor_real);
            html += `
              <li class="jr-vd-ev">
                <div class="jr-vd-ev-top">
                  <span class="jr-vd-ev-date">${esc(fmtFechaHora(g.fecha_visita))}</span>
                  ${est ? `<span class="jr-vd-badge ${est.cls}">${esc(est.label)}</span>` : ''}
                </div>
                ${valor !== '' ? `<div class="jr-vd-ev-meta">Valor real: <b>${esc(valor)}</b></div>` : ''}
                ${obs ? `<p class="jr-vd-ev-obs">${esc(obs)}</p>` : ''}
              </li>`;
          });
          html += `</ul>`;
        } else {
          html += `<p class="jr-vd-empty">Sin registros de gestión.</p>`;
        }

        if (phs.length){
          html += `<div class="jr-vd-photos">
                     <span class="jr-vd-photos-t">Fotos del material (${phs.length})</span>
                     ${thumbsHtml(phs)}
                   </div>`;
        }

        html += `</div></article>`;
      });

      html += `</section>`;
    }

    /* ---------------------------- ENCUESTA --------------------------- */
    if (qsArr.length){
      html += `
        <section class="jr-vd-sec">
          <h4 class="jr-vd-h">Encuesta / Auditoría <span class="jr-vd-count">${qsArr.length}</span></h4>`;

      qsArr.forEach((q, i) => {
        const ans    = Array.isArray(q.answers) ? q.answers : [];
        const qph    = Array.isArray(q.photos) ? q.photos : [];
        const esFoto = esPreguntaFoto(q.question_type);
        const tipo   = tipoPreguntaLabel(q.question_type);

        // Fotos: las de cada respuesta + las colgadas de la pregunta, todas en la
        // misma grilla. Antes la "principal" iba suelta y las demás como enlaces
        // "Foto #123", que en el teléfono se veía como texto roto.
        // Se descarta el repetido por id Y por URL ya normalizada: la misma foto
        // llega a veces como "uploads/x.webp" desde answer_text y como
        // "/visibility2/app/uploads/x.webp" desde fotoVisita.
        const fotos = [];
        const vistosId  = new Set();
        const vistosUrl = new Set();
        const pushFoto = p => {
          if (!p || !p.url) return;
          const id = p.id ? String(p.id) : '';
          const u  = fotoUrl(p.url);
          if ((id && vistosId.has(id)) || vistosUrl.has(u)) return;
          if (id) vistosId.add(id);
          vistosUrl.add(u);
          fotos.push(p);
        };
        ans.forEach(a => {
          (Array.isArray(a.photos) ? a.photos : []).forEach(pushFoto);
          // Preguntas de foto antiguas guardaban la ruta dentro de answer_text.
          if (esFoto && a.answer_text && /uploads\//.test(String(a.answer_text))){
            pushFoto({ url: a.answer_text, id: a.foto_visita_id || 0 });
          }
        });
        qph.forEach(pushFoto);

        // Si todas las respuestas se guardaron en el mismo instante (el caso
        // normal de una selección múltiple) la hora se muestra UNA vez abajo, en
        // vez de repetirla en cada opción.
        const tiempos = Array.from(new Set(ans.map(a => String(a.created_at || '')).filter(Boolean)));
        const horaUnica = tiempos.length === 1 ? tiempos[0] : '';

        html += `
          <article class="jr-vd-card jr-vd-q">
            <header class="jr-vd-card-h">
              <span class="jr-vd-q-n">${i + 1}</span>
              <span class="jr-vd-card-t">${esc(q.question_text || '')}</span>
              ${tipo ? `<span class="jr-vd-badge is-type">${esc(tipo)}</span>` : ''}
              ${q.is_required ? '<span class="jr-vd-badge is-info">Requerida</span>' : ''}
            </header>
            <div class="jr-vd-card-b">`;

        // Se arma primero la lista y recién después se decide si hay <ul>: en una
        // pregunta de foto todas las "respuestas" se saltan y quedaría una lista
        // vacía colgando entre la cabecera y las miniaturas.
        const items = [];
        ans.forEach(a => {
          const txt = textoRespuesta(a, esFoto);
          const valorizada = (q.is_valued && a.valor != null && a.valor !== '')
            ? `<span class="jr-vd-ans-val">valor ${esc(String(a.valor))}</span>` : '';
          const hora = (!horaUnica && a.created_at)
            ? `<span class="jr-vd-ans-t">${esc(fmtHora(a.created_at))}</span>` : '';
          if (!txt && !valorizada && esFoto) return; // respuesta que es sólo la foto
          items.push(`
              <li class="jr-vd-ans-i">
                <span class="jr-vd-ans-v">${esc(txt || '—')}</span>
                ${valorizada}
                ${hora}
              </li>`);
        });

        if (items.length){
          html += `<ul class="jr-vd-ans">${items.join('')}</ul>`;
        } else if (!fotos.length){
          html += `<p class="jr-vd-empty">Sin respuesta registrada.</p>`;
        }

        if (fotos.length) html += thumbsHtml(fotos);

        if (horaUnica){
          html += `<div class="jr-vd-when">Respondida ${esc(fmtFechaHora(horaUnica))}</div>`;
        }

        html += `</div></article>`;
      });

      html += `</section>`;
    }

    /* -------------------------- FOTOS SUELTAS ------------------------ */
    if (photosMisc.length){
      html += `
        <section class="jr-vd-sec">
          <h4 class="jr-vd-h">Otras fotos de la visita <span class="jr-vd-count">${photosMisc.length}</span></h4>
          ${thumbsHtml(photosMisc)}
        </section>`;
    }

    html += '</div>';
    return html;
  }

  async function showServerDetail(visitaId, fromOffline, domTitle){
    visitaId = parseInt(visitaId, 10);
    if (!visitaId) return;

    ensureDetailModal();
    setDetailModalTitle(domTitle || `Detalle de visita #${visitaId}`);
    setDetailModalBody('<p>Cargando detalle de la visita...</p>');

    if (window.jQuery && jQuery.fn && jQuery.fn.modal){
      jQuery('#jr-detail-modal').modal('show');
    }

    try {
      const params = new URLSearchParams();
      params.set('visita_id', String(visitaId));

      const res = await fetch(JOURNAL_DETAIL_ENDPOINT + '?' + params.toString(), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!data || data.status !== 'ok') throw new Error(data && data.message ? data.message : 'Error desconocido');

      const basic = data.basic || {};
      const loc   = basic.local || {};
      const form  = basic.formulario || {};
      const titlePieces = [];
      if (loc.nombre) titlePieces.push(loc.nombre);
      if (form.nombre) titlePieces.push(form.nombre);
      const modalTitle = titlePieces.length ? titlePieces.join(' · ') : `Visita #${visitaId}`;

      setDetailModalTitle(modalTitle);
      setDetailModalBody(renderServerDetail(data, fromOffline));

    } catch (err){
      setDetailModalBody(
        `<div class="alert alert-danger">
           No se pudo cargar el detalle de la visita.<br>
           <small>${esc(err && err.message ? err.message : String(err))}</small>
         </div>`
      );
    }
  }

  async function showJobDetail(jobId){
    if (!jobId) return;
    ensureJobModal();
    setJobModalTitle('Detalle de tarea');
    setJobModalBody('<p>Cargando detalle de la tarea...</p>');
    if (window.jQuery && jQuery.fn && jQuery.fn.modal){
      jQuery('#jr-job-modal').modal('show');
    }

    try {
      const job = await window.AppDB?.get?.(jobId);
      const jr  = await JournalDB.get(jobId);
      const events = await JournalDB.listEventsForJob(jobId, 20);
      const lastError = (jr && jr.last_error) ? jr.last_error : null;

      const title = jr && jr.names && jr.names.local
        ? `Tarea · ${jr.names.local}`
        : `Tarea #${jobId}`;
      setJobModalTitle(title);

      const errLine = lastError
        ? `<tr><th>Error</th><td>${esc(lastError.code || 'ERROR')} · ${esc(lastError.message || '')}</td></tr>`
        : '';
      const retryLine = (jr && jr.next_try_at)
        ? `<tr><th>Reintento</th><td>${esc(fmtRetry(jr.next_try_at))}</td></tr>`
        : '';
      const meta = (job && job.meta) ? job.meta : {};

      const attemptsList = events.length
        ? `<ul class="list-unstyled">${events.map(ev => `
            <li>
              <small>${esc(fmtTime(ev.created))}</small>
              · ${esc(ev.type || '')}
              ${ev.http_status ? `· HTTP ${esc(String(ev.http_status))}` : ''}
              ${ev.error ? `· ${esc(String(ev.error))}` : ''}
            </li>
          `).join('')}</ul>`
        : '<span class="text-muted">Sin historial</span>';

      const html = `
        <table class="table table-condensed">
          <tbody>
            <tr><th>Status</th><td>${esc(String(jr?.status || job?.status || '—'))}</td></tr>
            <tr><th>Endpoint</th><td>${esc(String(job?.url || '—'))}</td></tr>
            <tr><th>HTTP</th><td>${esc(String(jr?.http_status || '—'))}</td></tr>
            <tr><th>Intentos</th><td>${esc(String(jr?.attempts || job?.attempts || 0))}</td></tr>
            ${errLine}
            ${retryLine}
            <tr><th>Idempotency</th><td>${esc(String(meta.idempotencyKey || job?.id || '—'))}</td></tr>
            <tr><th>client_guid</th><td>${esc(String(meta.client_guid || jr?.client_guid || '—'))}</td></tr>
            <tr><th>Dependencias</th><td>${esc(String(meta.dependsOn || job?.dependsOn || '—'))}</td></tr>
          </tbody>
        </table>
        <h5>Historial reciente</h5>
        ${attemptsList}
        <div class="jr-job-actions">
          <button class="btn btn-xs btn-default jr-job-retry" data-job-id="${esc(jobId)}"><i class="fa fa-refresh"></i> Reintentar ahora</button>
          <button class="btn btn-xs btn-danger jr-job-cancel" data-job-id="${esc(jobId)}"><i class="fa fa-ban"></i> Cancelar</button>
        </div>
      `;
      setJobModalBody(html);
    } catch (err) {
      setJobModalBody(
        `<div class="alert alert-danger">
           No se pudo cargar el detalle de la tarea.<br>
           <small>${esc(err && err.message ? err.message : String(err))}</small>
         </div>`
      );
    }
  }
    function hasEndDate(item){
    return Boolean(item && item.tiempos && item.tiempos.fecha_fin);
  }
  
  
  // ---------- HTML para items venidos del SERVIDOR ----------

  function serverItemHTML(item){
    const local    = item.local       || {};
    const form     = item.formulario  || {};
    const tiempos  = item.tiempos     || {};
    const conteos  = item.conteos     || {};
    const distrito = (local.distrito && local.distrito.nombre) ? local.distrito.nombre : '';

    // Las campañas complementarias que no requieren local llegan sin sala
    // asociada: se rotula la actividad en vez de dejar un guión suelto.
    const sinLocal = !local.id && !local.nombre;
    const lName  = sinLocal
      ? 'Actividad complementaria'
      : (local.nombre ? esc(local.nombre) : `Local #${local.id}`);
    const codigo = local.codigo ? ` (${esc(local.codigo)})` : '';
    const comuna = distrito     ? ` · ${esc(distrito)}`      : '';
    const dir    = local.direccion ? esc(local.direccion)   : '';

    const timeRef = tiempos.fecha_inicio || tiempos.ultima_gestion_at || '';
    const timeStr = timeRef ? fmtTime(timeRef) : '';

    const campName   = form.nombre ? esc(form.nombre) : '';
    const modalidad  = (form.modalidad || '').toLowerCase();

    const fotos = Number(conteos.fotos_totales        || 0);
    const mats  = Number(conteos.materiales_distintos || 0);
    const preg  = Number(conteos.preguntas_respondidas || 0);

    // Tipo de gestión: las complementarias (tipo 2) se rotulan "Encuesta IW"
    const tipoGestion = tipoGestionDesdeModalidad(modalidad, form.tipo);

    const detailParts = [];

    // Solo mostramos materiales si la modalidad NO es solo auditoría
    if (tipoGestion !== 'Auditoría' && mats > 0){
      detailParts.push(plural(mats, 'material implementado', 'materiales implementados'));
    }

    // Siempre podemos mostrar preguntas respondidas si hay
    if (preg > 0){
      detailParts.push(plural(preg, 'pregunta respondida', 'preguntas respondidas'));
    }

    let summary = '';
    if (tipoGestion && detailParts.length){
      summary = `${tipoGestion} · ${detailParts.join(' · ')}`;
    } else if (tipoGestion){
      summary = tipoGestion;
    } else if (detailParts.length){
      summary = detailParts.join(' · ');
    } else {
      summary = 'Sin detalle registrado';
    }

    const durTxt  = fmtDuration(tiempos.duracion_seg);
    const durLine = durTxt
      ? `<div class="jr-subline">Duración: ${esc(durTxt)}</div>`
      : '';

    const visitaId   = item.visita_id != null ? Number(item.visita_id) : null;
    const clientGuid = item.client_guid ? String(item.client_guid) : '';
    const fromOffline = clientGuid && OFFLINE_GUIDS.has(clientGuid);
    const offlineChip = fromOffline
      ? '<span class="jr-chip jr-chip--offline">Offline</span>'
      : '';

    return `
      <div class="jr-item jr-item--server"
           data-visita-id="${visitaId != null ? esc(String(visitaId)) : ''}"
           data-client-guid="${esc(clientGuid)}"
           data-offline="${fromOffline ? '1' : '0'}">
        <div class="jr-left">
          <div class="jr-time">${esc(timeStr)}</div>
          <div class="jr-what">
            <div class="jr-title">${lName}${codigo}<small>${comuna}</small></div>
            ${dir ? `<div class="jr-subline">${dir}</div>` : ''}
            <div class="jr-sub">
              ${campName ? campName + ' · ' : ''}${esc(summary)}
            </div>
            ${durLine}
          </div>
        </div>
        <div class="jr-right">
          <span class="jr-chip jr-chip--ok">Subida</span>
          ${offlineChip}
          <div class="progress jr-progress">
            <div class="progress-bar progress-bar-success" style="width:100%">
              100%
            </div>
          </div>
          ${visitaId ? `
          <div class="jr-actions">
            <button class="btn btn-xs btn-default jr-view" data-visita-id="${esc(String(visitaId))}">
              <i class="fa fa-eye"></i> Ver detalle
            </button>
          </div>` : ''}
        </div>
      </div>
    `;
  }

  // ---------- Render Hoy (local + servidor) ----------

  // MEJORA: Wrapper con protección contra race conditions
  async function _renderTodayInternal(){
    if (!$today) return;
    if (_isRenderingToday) return; // Evitar renders simultáneos
    _isRenderingToday = true;

    try {
      const ymd  = JournalDB.ymdLocal(new Date());
      const rows = await JournalDB.listByYMD(ymd);

    // GUIDs offline para marcar subidas
    collectOfflineGuids(rows);

    // resolver nombres (incluye fallback a agenda + dirección/código)
    await Promise.all(rows.map(r => JournalDB.resolveNamesIfPossible(r)));

    const allGroups = groupFromRows(rows);
    GROUPS_TODAY    = allGroups;

    // Sólo dejamos en "Pendiente por subir" los grupos que NO estén 100% subidos
    const pendingGroups = Object.values(allGroups).filter(gr => gr.status !== 'success');

    const offlineHtml = pendingGroups.length
      ? pendingGroups
          .sort((a, b) => (a.created || 0) - (b.created || 0))
          .map(groupHTML).join('')
      : '';

    // Stats + progreso global (todas las tareas, incluidas subidas)
    const s = await JournalDB.statsFor(rows);
    badge(qs('#jr-badge-pending'), s.pending, 'Pendientes');
    badge(qs('#jr-badge-running'), s.running, 'Enviando');
    badge(qs('#jr-badge-success'), s.success, 'Subidas');
    badge(qs('#jr-badge-error'),   s.error,   'Errores');
    badge(qs('#jr-badge-blocked'), s.blocked, 'Bloqueadas');
    setGlobalProgress(s.success, s.pending + s.running + s.error + s.success + s.blocked);

    // Complementar con datos del servidor (visitas ya grabadas)
    let serverSection = '';
    let localSection  = '';

    try {
    const serverItems = await fetchServerJournal(ymd, ymd);
      const completedServerItems = (serverItems || []).filter(hasEndDate);
      if (completedServerItems.length){
        const serverHtml = completedServerItems.map(serverItemHTML).join('');
        if (serverHtml){
          serverSection = `
            <div class="jr-section jr-section--server">
              <h4 class="jr-section-title">Subido al servidor</h4>
              ${serverHtml}
            </div>
          `;
        }
      }
    } catch(_) {
      // silencioso; si falla, sólo veremos la cola local
    }

    if (offlineHtml){
      localSection = `
        <div class="jr-section jr-section--local">
          <h4 class="jr-section-title">Pendiente por subir</h4>
          ${offlineHtml}
        </div>
      `;
    }

    let finalHtml = serverSection + localSection;
    if (!finalHtml){
      finalHtml = `<div class="jr-empty"><i class="fa fa-cloud-download"></i> Sin gestiones hoy</div>`;
    }

    $today.innerHTML = finalHtml;
    initPopovers();
    } finally {
      _isRenderingToday = false;
    }
  }

  // MEJORA: Versión con debounce para llamadas frecuentes
  const renderToday = debounce(_renderTodayInternal, RENDER_DEBOUNCE_MS);

  // Versión inmediata para init
  async function renderTodayImmediate() {
    return _renderTodayInternal();
  }

  // ---------- Render Semana (local + servidor) ----------

  // MEJORA: Wrapper con protección contra race conditions
  async function _renderWeekInternal(){
    if (!$week) return;
    if (_isRenderingWeek) return;
    _isRenderingWeek = true;

    try {

    const to   = new Date();
    const from = new Date();
    from.setDate(to.getDate() - 6);

    const fromYmd = JournalDB.ymdLocal(from);
    const toYmd   = JournalDB.ymdLocal(to);

    const rows = await JournalDB.listRange(fromYmd, toYmd);
    await Promise.all(rows.map(r => JournalDB.resolveNamesIfPossible(r)));

    // GUIDs offline para toda la semana (para marcar OFFLINE en server)
    collectOfflineGuids(rows);

    const byDate = {};
    rows.forEach(r => { (byDate[r.ymd] = byDate[r.ymd] || []).push(r); });

    GROUPS_WEEK = {};

    // Traer también visitas desde el servidor en el mismo rango
    let serverItems = [];
    try {
      serverItems = await fetchServerJournal(fromYmd, toYmd);
    } catch(_) {
      serverItems = [];
    }

    const srvByDate = {};
    if (Array.isArray(serverItems)){
      serverItems
        .filter(hasEndDate)
        .forEach(it => {
        let ymd = null;
        if (it.tiempos && it.tiempos.fecha_inicio){
          ymd = String(it.tiempos.fecha_inicio).slice(0, 10);
        } else if (it.tiempos && it.tiempos.ultima_gestion_at){
          ymd = String(it.tiempos.ultima_gestion_at).slice(0, 10);
        } else if (it.ymd){
          ymd = String(it.ymd);
        }
        if (!ymd) return;
        (srvByDate[ymd] = srvByDate[ymd] || []).push(it);
      });
    }

    // Unión de días donde haya algo (local o servidor)
    const allDaysSet = new Set([
      ...Object.keys(byDate),
      ...Object.keys(srvByDate)
    ]);
    const allDays = Array.from(allDaysSet).sort();

    const daysHtml = allDays.map(ymd => {
      const rowsForDay = byDate[ymd] || [];
      let offlineHtml = '';

      if (rowsForDay.length){
        const g = groupFromRows(rowsForDay);
        GROUPS_WEEK[ymd] = g;

        const pendingGroups = Object.values(g).filter(gr => gr.status !== 'success');
        if (pendingGroups.length){
          offlineHtml = pendingGroups
            .sort((a, b) => (a.created || 0) - (b.created || 0))
            .map(groupHTML)
            .join('');
        }
      }

      const srvList    = srvByDate[ymd] || [];
      const serverHtml = srvList.map(serverItemHTML).join('');

      let dayContent = '';
      if (serverHtml){
        dayContent += `
          <div class="jr-section jr-section--server">
            <h5 class="jr-section-title">Gestiones subidas</h5>
            ${serverHtml}
          </div>
        `;
      }
      if (offlineHtml){
        dayContent += `
          <div class="jr-section jr-section--local">
            <h5 class="jr-section-title">Pendiente por subir</h5>
            ${offlineHtml}
          </div>
        `;
      }

      if (!dayContent){
        dayContent = `<div class="jr-empty"><i class="fa fa-calendar-o"></i> Sin registros</div>`;
      }

      const f = new Date(ymd + 'T00:00:00');
      const title = f.toLocaleDateString('es-CL', {
        weekday: 'short', day: '2-digit', month: '2-digit'
      });

      return `<h4 class="jr-date">${title}</h4><div class="jr-day">${dayContent}</div>`;
    }).join('');

    $week.innerHTML = daysHtml ||
      `<div class="jr-empty"><i class="fa fa-calendar-o"></i> Semana sin registros</div>`;

    initPopovers();
    } finally {
      _isRenderingWeek = false;
    }
  }

  // MEJORA: Versión con debounce para llamadas frecuentes
  const renderWeek = debounce(_renderWeekInternal, RENDER_DEBOUNCE_MS);

  // Versión inmediata para init
  async function renderWeekImmediate() {
    return _renderWeekInternal();
  }

  // ---------- Calendario mensual ----------

  async function renderMonth(year, month) {
    const grid  = document.getElementById('jr-cal-grid');
    const title = document.getElementById('jr-cal-title');
    if (!grid || !title) return;

    const firstDay = new Date(year, month, 1);
    const lastDay  = new Date(year, month + 1, 0);
    const pad      = n => String(n).padStart(2, '0');
    const fromYmd  = `${year}-${pad(month + 1)}-01`;
    const toYmd    = `${year}-${pad(month + 1)}-${pad(lastDay.getDate())}`;
    const todayYmd = JournalDB.ymdLocal(new Date());

    let localRows = [], serverItems = [];
    try {
      [localRows, serverItems] = await Promise.all([
        JournalDB.listRange(fromYmd, toYmd),
        fetchServerJournal(fromYmd, toYmd).catch(() => [])
      ]);
    } catch(_) {}

    const localByDay = {};
    localRows.forEach(r => { (localByDay[r.ymd] = localByDay[r.ymd] || []).push(r); });

    const serverByDay = {};
    if (Array.isArray(serverItems)) {
      serverItems.filter(hasEndDate).forEach(it => {
        let ymd = null;
        if (it.tiempos && it.tiempos.fecha_inicio) ymd = String(it.tiempos.fecha_inicio).slice(0, 10);
        else if (it.tiempos && it.tiempos.ultima_gestion_at) ymd = String(it.tiempos.ultima_gestion_at).slice(0, 10);
        else if (it.ymd) ymd = String(it.ymd);
        if (ymd) (serverByDay[ymd] = serverByDay[ymd] || []).push(it);
      });
    }

    title.textContent = firstDay.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });

    const DAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    let html = DAYS.map(d =>
      `<div style="text-align:center;font-weight:bold;font-size:11px;color:#888;padding:2px 0;">${d}</div>`
    ).join('');

    const startDow = (firstDay.getDay() + 6) % 7;
    for (let i = 0; i < startDow; i++) html += '<div></div>';

    for (let d = 1; d <= lastDay.getDate(); d++) {
      const ymd        = `${year}-${pad(month + 1)}-${pad(d)}`;
      const srv        = serverByDay[ymd] || [];
      const loc        = localByDay[ymd]  || [];
      const isFuture   = ymd > todayYmd;
      const isToday    = ymd === todayYmd;
      const isSelected = ymd === _calSelectedYmd;
      const hasSrv     = srv.length > 0;
      const hasPending = loc.some(r => r.status !== 'success');
      const hasError   = loc.some(r => r.status === 'error');
      const hasActivity = hasSrv || hasPending || hasError;

      let dots = '';
      if (hasSrv)     dots += '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#3c763d;margin:0 1px;"></span>';
      if (hasPending) dots += '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f0ad4e;margin:0 1px;"></span>';
      if (hasError)   dots += '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#a94442;margin:0 1px;"></span>';

      const bg     = isSelected ? '#d0e8ff' : (isToday ? '#fffbe6' : '#fff');
      const border = isSelected ? '2px solid #337ab7' : (isToday ? '2px solid #f0ad4e' : '1px solid #ddd');
      const cursor = (!isFuture && hasActivity) ? 'pointer' : 'default';

      html += `<div class="jr-cal-cell" data-ymd="${ymd}"
        style="padding:4px 2px;min-height:46px;background:${bg};border:${border};border-radius:4px;
               text-align:center;opacity:${isFuture ? '0.4' : '1'};cursor:${cursor};">
        <div style="font-size:12px;font-weight:${isToday ? 'bold' : 'normal'};">${d}</div>
        <div style="margin-top:3px;">${dots}</div>
      </div>`;
    }

    grid.innerHTML = html;
  }

  async function _showDayDetail(ymd) {
    const detail = document.getElementById('jr-cal-detail');
    if (!detail) return;

    if (!_calSelectedYmd) {
      detail.style.display = 'none';
      detail.innerHTML = '';
      return;
    }

    detail.style.display = 'block';
    detail.innerHTML = '<div class="jr-empty"><i class="fa fa-spinner fa-spin"></i> Cargando...</div>';

    let rows = [], serverItems = [];
    try {
      [rows, serverItems] = await Promise.all([
        JournalDB.listByYMD(ymd),
        fetchServerJournal(ymd, ymd).catch(() => [])
      ]);
    } catch(_) {}

    await Promise.all(rows.map(r => JournalDB.resolveNamesIfPossible(r)));
    collectOfflineGuids(rows);

    const f     = new Date(ymd + 'T00:00:00');
    const label = f.toLocaleDateString('es-CL', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });

    let serverHtml = '';
    if (Array.isArray(serverItems)) {
      const srvForDay = serverItems.filter(hasEndDate);
      if (srvForDay.length) {
        serverHtml = `<div class="jr-section jr-section--server">
          <h5 class="jr-section-title">Gestiones subidas <span class="label label-success" style="font-size:11px;vertical-align:middle;">${srvForDay.length}</span></h5>
          ${srvForDay.map(serverItemHTML).join('')}
        </div>`;
      }
    }

    let offlineHtml = '';
    if (rows.length) {
      const g = groupFromRows(rows);
      const pendingGroups = Object.values(g).filter(gr => gr.status !== 'success');
      if (pendingGroups.length) {
        offlineHtml = `<div class="jr-section jr-section--local">
          <h5 class="jr-section-title">Pendiente por subir</h5>
          ${pendingGroups.sort((a, b) => (a.created || 0) - (b.created || 0)).map(groupHTML).join('')}
        </div>`;
      }
    }

    let dayContent = serverHtml + offlineHtml;
    if (!dayContent) {
      dayContent = '<div class="jr-empty"><i class="fa fa-calendar-o"></i> Sin registros para este día</div>';
    }

    detail.innerHTML = `<h4 class="jr-date" style="text-transform:capitalize;">${label}</h4>${dayContent}`;
    initPopovers();
  }

  // Click en celda del calendario
  document.addEventListener('click', async (e) => {
    const cell = e.target.closest('.jr-cal-cell');
    if (!cell) return;
    const ymd      = cell.getAttribute('data-ymd');
    const todayYmd = JournalDB.ymdLocal(new Date());
    if (ymd > todayYmd) return;

    _calSelectedYmd = (_calSelectedYmd === ymd) ? null : ymd;
    renderMonth(_calYear, _calMonth);
    await _showDayDetail(_calSelectedYmd);
  });

  // Flechas prev/next mes
  const $calPrev = document.getElementById('jr-cal-prev');
  const $calNext = document.getElementById('jr-cal-next');

  $calPrev && $calPrev.addEventListener('click', () => {
    _calMonth--;
    if (_calMonth < 0) { _calMonth = 11; _calYear--; }
    _calSelectedYmd = null;
    const det = document.getElementById('jr-cal-detail');
    if (det) { det.style.display = 'none'; det.innerHTML = ''; }
    renderMonth(_calYear, _calMonth);
  });

  $calNext && $calNext.addEventListener('click', () => {
    const now = new Date();
    if (_calYear > now.getFullYear() || (_calYear === now.getFullYear() && _calMonth >= now.getMonth())) return;
    _calMonth++;
    if (_calMonth > 11) { _calMonth = 0; _calYear++; }
    _calSelectedYmd = null;
    const det = document.getElementById('jr-cal-detail');
    if (det) { det.style.display = 'none'; det.innerHTML = ''; }
    renderMonth(_calYear, _calMonth);
  });

  // ---------- Interacciones ----------

  // Reintentar grupo (cola local)
  document.addEventListener('click', async (e) => {
    const grp = e.target.closest('.jr-group');
    if (!grp) return;

    if (e.target.closest('.jr-retry')){
      const btn = grp.querySelector('.jr-retry');
      if (btn && btn.hasAttribute('disabled')){
        e.preventDefault();
        return;
      }
      try{
        const key = grp.getAttribute('data-key');
        const g   = GROUPS_TODAY[key]; // reservado por si más adelante usamos algo del grupo
        void g; // evita warning de variable sin uso

        const old = btn.innerHTML;
        btn.innerHTML = `<i class="fa fa-refresh"></i> Reintentando...`;
        await window.Queue?.flushNow?.();
        btn.innerHTML = old;
      } catch(_){}
      e.preventDefault();
      return;
    }
  });

  // Ver detalle de tareas (cola local) y detalle servidor
  document.addEventListener('click', (e) => {
    // COLA LOCAL: ver detalle de tareas internas
    const grp = e.target.closest('.jr-group');
    if (grp && e.target.closest('.jr-view')){
      const key = grp.getAttribute('data-key');
      const det = document.querySelector(`.jr-details[data-for="${key}"]`);
      if (det){ det.hidden = !det.hidden; }
      e.preventDefault();
      return;
    }

    // Detalle de tarea específica
    if (e.target.closest('.jr-job-detail')) {
      const jobId = e.target.closest('.jr-job-detail').getAttribute('data-job-id');
      showJobDetail(jobId);
      e.preventDefault();
      return;
    }

    // SERVIDOR: ver detalle profundo de la visita
    const srv = e.target.closest('.jr-item--server');
    if (srv && e.target.closest('.jr-view')){
      const visitaId   = srv.getAttribute('data-visita-id');
      const offlineFlg = srv.getAttribute('data-offline') === '1';
      const titleDom   = srv.querySelector('.jr-title');
      const domTitle   = titleDom ? titleDom.textContent.trim() : null;
      showServerDetail(visitaId, offlineFlg, domTitle);
      e.preventDefault();
      return;
    }
  });

  // Acciones dentro del modal de tarea y botones inline
  document.addEventListener('click', async (e) => {
    // MEJORA: Retry desde modal o inline
    const retryBtn = e.target.closest('.jr-job-retry, .jr-job-retry-inline');
    if (retryBtn){
      const jobId = retryBtn.getAttribute('data-job-id');
      if (jobId && window.AppDB?.update) {
        // Feedback visual
        const icon = retryBtn.querySelector('i');
        if (icon) icon.className = 'fa fa-spinner fa-spin';

        try {
          await AppDB.update(jobId, { status: 'queued', nextTryAt: Date.now(), updatedAt: Date.now() });
          await window.Queue?.flushNow?.();
          await renderToday();
          showMessage('Reintentando', 'La tarea se ha puesto en cola para reintentar.', 'info');
        } catch(err) {
          showMessage('Error', 'No se pudo reintentar la tarea.', 'error');
        } finally {
          if (icon) icon.className = 'fa fa-refresh';
        }
      }
      e.preventDefault();
      return;
    }

    // MEJORA: Cancel desde modal o inline
    const cancelBtn = e.target.closest('.jr-job-cancel, .jr-job-cancel-inline');
    if (cancelBtn){
      const jobId = cancelBtn.getAttribute('data-job-id');
      if (jobId) {
        // Confirmar cancelación
        const confirmed = window.confirm('¿Estás seguro de cancelar esta tarea? Los datos no sincronizados se perderán.');
        if (!confirmed) {
          e.preventDefault();
          return;
        }

        // Feedback visual
        const icon = cancelBtn.querySelector('i');
        if (icon) icon.className = 'fa fa-spinner fa-spin';

        try {
          if (window.Queue?.cancel) {
            await window.Queue.cancel(jobId, { keepRecord: true });
          }
          await renderToday();
          showMessage('Cancelado', 'La tarea ha sido cancelada.', 'warning');
        } catch(err) {
          showMessage('Error', 'No se pudo cancelar la tarea.', 'error');
        } finally {
          if (icon) icon.className = 'fa fa-times';
        }
      }
      e.preventDefault();
      return;
    }
  });

  // Botones globales
  const $btnFlush = qs('#jr-btn-flush');
  const $btnClear = qs('#jr-btn-clear-today');
  const $btnExport = qs('#jr-btn-export');

  $btnFlush && $btnFlush.addEventListener('click', async () => {
    try { await window.Queue?.flushNow?.(); } catch(_){}
  });

  $btnClear && $btnClear.addEventListener('click', async () => {
    const ymd = JournalDB.ymdLocal(new Date());
    await JournalDB.clearUploadedFor(ymd);
    await renderToday();
    await renderWeek();
    if (_calYear !== null) await renderMonth(_calYear, _calMonth);
  });

  $btnExport && $btnExport.addEventListener('click', async () => {
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      showMessage('Sin conexión', 'Sin conexión, no se puede exportar diagnóstico', 'warning');
      return;
    }

    if (typeof fetch !== 'function') {
      showMessage('Error', 'No se puede exportar diagnóstico en este navegador.', 'error');
      return;
    }

    try {
      const payload = (JournalDB.buildDiagnosticPayload)
        ? await JournalDB.buildDiagnosticPayload(300)
        : await JournalDB.exportRecent(300);

      const csrfToken = await ensureCsrfToken();
      if (csrfToken) payload.csrf_token = csrfToken;

      const headers = { 'Content-Type': 'application/json' };
      if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

      const res = await fetch(EXPORT_DIAGNOSTIC_ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify(payload)
      });

      const js = await res.json().catch(() => null);
      if (res.ok && js && js.ok) {
        const msg = js.saved_path ? `Guardado en servidor: ${js.saved_path}` : 'Diagnóstico guardado en servidor.';
        showMessage('Diagnóstico exportado', msg, 'success');
      } else {
        const msg = (js && (js.message || js.error || js.error_code)) || 'No se pudo exportar el diagnóstico.';
        showMessage('Error', msg, 'error');
      }
    } catch(_) {
      showMessage('Error', 'No se pudo exportar el diagnóstico.', 'error');
    }
  });

  // Eventos de la cola (actualizan vista en caliente)
  function safeJob(e){
    return (e && e.detail && e.detail.job)
      ? e.detail.job
      : (e && e.detail)
        ? e.detail
        : null;
  }

  window.addEventListener('queue:enqueue',           async (e) => {
    const job = safeJob(e);
    if (!job) return;
    await JournalDB.onEnqueue(job);
    await renderToday();
  });

  window.addEventListener('queue:enqueued',          async () => {
    await renderToday();
  });

  window.addEventListener('queue:dispatch:start',    async (e) => {
    const job = safeJob(e);
    if (!job) return;
    await JournalDB.onStart(job);

    // Intentar actualización parcial primero
    const groupKey = getGroupKeyFromJobInternal(job);
    const updated = updateJobProgressOnly(job.id, 50, 'running') ||
                    updateGroupProgressOnly(groupKey, 50, 'running');

    if (!updated) {
      // Si no existe el elemento, hacer render completo
      await renderToday();
    } else {
      // Actualizar badges sin re-render
      updateBadgesOnly();
    }
  });

  window.addEventListener('queue:dispatch:progress', async (e) => {
    const job = safeJob(e);
    const p   = (e.detail && e.detail.progress) || 60;
    if (!job) return;
    await JournalDB.onProgress(job, p);

    // Intentar actualización parcial primero (más fluido)
    const groupKey = getGroupKeyFromJobInternal(job);
    const updated = updateJobProgressOnly(job.id, p, 'running') ||
                    updateGroupProgressOnly(groupKey, p, 'running');

    if (!updated) {
      // Si no existe el elemento, hacer render completo
      await renderToday();
    }
    // No actualizamos badges en cada progress (demasiado frecuente)
  });

  window.addEventListener('queue:dispatch:success',  async (e) => {
    const job  = safeJob(e);
    const resp = (e.detail && e.detail.response) || null;
    const httpStatus = (e.detail && e.detail.responseStatus) || null;
    if (!job) return;

    // Actualizar visualmente ANTES de persistir (feedback inmediato)
    const groupKey = getGroupKeyFromJobInternal(job);
    updateJobProgressOnly(job.id, 100, 'success');
    updateGroupProgressOnly(groupKey, 100, 'success');

    await JournalDB.onSuccess(job, resp, httpStatus);

    // Actualizar badges inmediatamente
    updateBadgesOnly();

    // Re-render completo con pequeño delay para que se vea la animación
    setTimeout(async () => {
      await renderToday();
      await renderWeek();
      if (_calYear !== null) await renderMonth(_calYear, _calMonth);
    }, 300);

    // Mensaje cuando una gestión (procesar_gestion) se sube correctamente
    try {
      const kind  = ((job.kind || job.type || '') + '').toLowerCase();
      const okJson = !resp || !resp.status || resp.status === 'ok';
      if (okJson && kind.indexOf('procesar_gestion') !== -1){
        const msg = 'La gestión del local se subió correctamente al servidor.';
        if (window.swal) {
          window.swal('Gestión enviada', msg, 'success');
        } else if (window.alert) {
          window.alert(msg);
        }
      }
    } catch(_){}
  });

  window.addEventListener('queue:dispatch:error',    async (e) => {
    const job = safeJob(e);
    const msg = (e.detail && e.detail.error) || 'Error';
    if (!job) return;
    await JournalDB.onError(job, msg);
    await renderToday();
  });

  window.addEventListener('queue:update',            async () => {
    await renderToday();
  });

  // Popovers (Bootstrap)
  function initPopovers(){
    if (window.jQuery && jQuery.fn && jQuery.fn.popover){
      jQuery('[data-toggle="popover"]').popover();
    }
  }

  // tabs → render diferido (legacy, mantenido por compatibilidad)
  qsa('a[href="#jr-semana"]').forEach(a =>
    a.addEventListener('shown.bs.tab', renderWeek)
  );

  // Init
  (async function init(){
    // MEJORA: Crear indicador offline
    createOfflineIndicator();
    updateOfflineIndicator();

    await JournalDB.openDB();
    await JournalDB.cleanup({ maxDays: 90, maxEvents: 1000, maxSuccessDays: 90 });

    // Inicializar calendario del mes actual
    const now = new Date();
    _calYear  = now.getFullYear();
    _calMonth = now.getMonth();
    await renderMonth(_calYear, _calMonth);

    // Render legacy (contenedores ocultos, necesarios para eventos de cola)
    await renderTodayImmediate();
    await renderWeekImmediate();
  })();

})();

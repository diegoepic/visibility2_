/**
 * gestion_draft.js
 *
 * Módulo de autosave y restauración de gestión en progreso.
 * Guarda el estado completo del formulario en IndexedDB (AppDB.putDraft)
 * y sincroniza periódicamente con el servidor cuando hay conexión.
 *
 * Expuesto como window.GestionDraft
 */
(function () {
  'use strict';

  const SCHEMA_VERSION            = 1;
  const AUTOSAVE_DEBOUNCE_MS      = 500;
  const SERVER_SYNC_DEBOUNCE_MS   = 30_000;
  const SERVER_SYNC_ENDPOINT      = '/visibility2/app/api/gestion_draft_save.php';
  const SNAPSHOT_ENDPOINT         = '/visibility2/app/api/gestion_snapshot.php';

  // Estado interno del módulo
  let _userId     = 0;
  let _formId     = 0;
  let _localId    = 0;
  let _clientGuid = '';
  let _currentDraft = null;     // último draft guardado en memoria
  let _initialized  = false;

  // Timers de debounce
  let _saveTimer       = null;
  let _serverSyncTimer = null;

  // Flag para suprimir autosave durante restauración de UI
  let _restoring = false;

  // ── Helpers ───────────────────────────────────────────────────────────────

  function _draftKey() {
    return [_userId, _formId, _localId, _clientGuid];
  }

  function _blobDraftKey() {
    return JSON.stringify([_userId, _formId, _localId, _clientGuid]);
  }

  function _getCSRF() {
    return window.CSRF_TOKEN
      || document.querySelector('input[name="csrf_token"]')?.value
      || '';
  }

  function _updateSaveIndicator(_status) { /* indicador desactivado */ }

  function _extractBlobKeys(draft) {
    if (!draft) return [];
    const keys = [];
    const collectFromFotos = (fotosObj) => {
      if (!fotosObj) return;
      Object.values(fotosObj).forEach(entries => {
        const arr = Array.isArray(entries) ? entries : [entries];
        arr.forEach(f => { if (f && f.blob_key) keys.push(f.blob_key); });
      });
    };
    collectFromFotos(draft.fotos_materiales);
    collectFromFotos(draft.fotos_preguntas);
    collectFromFotos(draft.fotos_especiales);
    return keys;
  }

  // ── Inicialización ────────────────────────────────────────────────────────

  function init(userId, formId, localId, clientGuid) {
    _userId     = Number(userId)  || 0;
    _formId     = Number(formId)  || 0;
    _localId    = Number(localId) || 0;
    _clientGuid = String(clientGuid || '');
    _initialized = true;
  }

  // ── Guardar draft ─────────────────────────────────────────────────────────

  async function _doSave(patch) {
    if (!_initialized || !_clientGuid) return;
    _updateSaveIndicator('saving');

    const base = _currentDraft || {
      user_id:      _userId,
      form_id:      _formId,
      local_id:     _localId,
      client_guid:  _clientGuid,
      schema_version: SCHEMA_VERSION,
      created_at:   Date.now(),
      status:       'draft',
      visita_id:    null,
      // Form state defaults
      estadoGestion: null,
      motivo:        null,
      comentario:    null,
      lat_gestion:   null,
      lng_gestion:   null,
      materiales:    {},
      fotos_materiales: {},
      respuestas:    {},
      fotos_preguntas:  {},
      fotos_especiales: {},
      submit_idempotency_key: null,
      last_server_sync_at:    null,
      server_sync_error:      null,
    };

    // Deep merge: los objetos anidados se fusionan campo a campo
    const merged = { ...base };
    if (patch) {
      for (const [k, v] of Object.entries(patch)) {
        if (v !== null && typeof v === 'object' && !Array.isArray(v) && typeof merged[k] === 'object' && merged[k] !== null) {
          merged[k] = { ...merged[k], ...v };
        } else {
          merged[k] = v;
        }
      }
    }
    merged.updated_at = Date.now();

    try {
      if (!window.AppDB) throw new Error('AppDB no disponible');
      await window.AppDB.putDraft(merged);
      _currentDraft = merged;
      _updateSaveIndicator('saved');
    } catch (err) {
      console.warn('[GestionDraft] Error al guardar draft:', err);
      _updateSaveIndicator('error');
    }

    // Disparar sync al servidor (debounced)
    _scheduleServerSync();
  }

  function saveDraft(patch, immediate = false) {
    if (!_initialized || _restoring) return;
    if (immediate) {
      clearTimeout(_saveTimer);
      _doSave(patch);
    } else {
      clearTimeout(_saveTimer);
      _saveTimer = setTimeout(() => _doSave(patch), AUTOSAVE_DEBOUNCE_MS);
    }
  }

  // ── Cargar draft ──────────────────────────────────────────────────────────

  async function loadDraft(userId, formId, localId, clientGuid) {
    if (!window.AppDB) return null;
    const uid = Number(userId)  || _userId;
    const fid = Number(formId)  || _formId;
    const lid = Number(localId) || _localId;
    const gid = String(clientGuid || _clientGuid);
    if (!uid || !fid || !lid || !gid) return null;
    try {
      const draft = await window.AppDB.getDraft(uid, fid, lid, gid);
      if (draft && draft.status !== 'submitted') {
        _currentDraft = draft;
      }
      return draft;
    } catch (err) {
      console.warn('[GestionDraft] Error al cargar draft:', err);
      return null;
    }
  }

  // ── Eliminar draft (solo tras confirmación del servidor) ──────────────────

  async function clearDraft(userId, formId, localId, clientGuid) {
    if (!window.AppDB) return;
    const uid = Number(userId)  || _userId;
    const fid = Number(formId)  || _formId;
    const lid = Number(localId) || _localId;
    const gid = String(clientGuid || _clientGuid);

    try {
      // Limpiar blobs asociados
      const draftToClean = await window.AppDB.getDraft(uid, fid, lid, gid);
      if (draftToClean) {
        const blobKeys = _extractBlobKeys(draftToClean);
        await Promise.allSettled(blobKeys.map(k => window.AppDB.deleteBlob(k)));
      }
      await window.AppDB.deleteDraft(uid, fid, lid, gid);
      _currentDraft = null;
    } catch (err) {
      console.warn('[GestionDraft] Error al limpiar draft:', err);
    }
  }

  // ── Marcar draft como enviado (sin borrarlo aún) ─────────────────────────

  async function markSubmitted(visitaId) {
    if (!_currentDraft) return;
    await _doSave({ status: 'submitted', visita_id: visitaId || _currentDraft.visita_id });
  }

  // ── Actualizar visita_id ──────────────────────────────────────────────────

  async function updateVisitaId(visitaId) {
    if (!visitaId) return;
    saveDraft({ visita_id: Number(visitaId) }, true);
  }

  // ── Marcar foto como subida ───────────────────────────────────────────────

  async function markFotoMatUpload(fqId, localTempId, serverFotoId, url) {
    if (!_currentDraft) return;
    const fotos = { ...(_currentDraft.fotos_materiales || {}) };
    const fqKey = String(fqId);
    if (!fotos[fqKey]) fotos[fqKey] = [];
    const entry = fotos[fqKey].find(f => f.local_temp_id === localTempId);
    if (entry) {
      entry.status         = 'uploaded';
      entry.server_foto_id = Number(serverFotoId);
      entry.url            = url;
      entry.blob_key       = null;   // ya no se necesita el blob
    } else {
      // La foto llegó del servidor pero no teníamos registro local
      fotos[fqKey].push({
        local_temp_id: localTempId || ('server-' + serverFotoId),
        server_foto_id: Number(serverFotoId),
        url,
        blob_key: null,
        status: 'uploaded',
        queue_job_id: null,
        idempotency_key: '',
        error: null,
      });
    }
    saveDraft({ fotos_materiales: fotos }, true);
  }

  async function markFotoPregUpload(qId, localTempId, serverRespId, url) {
    if (!_currentDraft) return;
    const fotos = { ...(_currentDraft.fotos_preguntas || {}) };
    const qKey  = String(qId);
    fotos[qKey] = {
      local_temp_id:  localTempId || ('server-resp-' + serverRespId),
      server_resp_id: Number(serverRespId),
      url,
      blob_key: null,
      status: 'uploaded',
      queue_job_id: null,
      idempotency_key: '',
      error: null,
    };
    saveDraft({ fotos_preguntas: fotos }, true);
  }

  async function markFotoError(type, id, localTempId, errorMsg) {
    if (!_currentDraft) return;
    const key   = type === 'material' ? 'fotos_materiales' : 'fotos_preguntas';
    const fotos = { ...(_currentDraft[key] || {}) };
    const idKey = String(id);
    if (type === 'material') {
      if (!fotos[idKey]) return;
      const entry = fotos[idKey].find(f => f.local_temp_id === localTempId);
      if (entry) { entry.status = 'error'; entry.error = errorMsg; }
    } else {
      if (fotos[idKey]) { fotos[idKey].status = 'error'; fotos[idKey].error = errorMsg; }
    }
    saveDraft({ [key]: fotos }, true);
  }

  // ── Serializar estado del formulario → objeto ─────────────────────────────

  function collectFormState() {
    const materiales = {};
    document.querySelectorAll('[data-id-material]').forEach(checkbox => {
      const fqId = String(checkbox.dataset.idMaterial);
      const section = document.getElementById('implementa_section_' + fqId);
      const noSection = document.getElementById('no_implementa_section_' + fqId);
      materiales[fqId] = {
        implementado:  checkbox.checked,
        valor_real:    section?.querySelector('[name="valor[' + fqId + ']"]')?.value ?? null,
        observacion:   section?.querySelector('[name="observacion[' + fqId + ']"]')?.value ?? null,
        motivo_select: noSection?.querySelector('[name="motivoSelect[' + fqId + ']"]')?.value ?? null,
        motivo_detalle:noSection?.querySelector('[name="motivoNoImplementado[' + fqId + ']"]')?.value ?? null,
      };
    });

    const respuestas = {};
    document.querySelectorAll('[data-question-id]').forEach(block => {
      const qId  = block.dataset.questionId;
      const type = parseInt(block.dataset.questionType || '0', 10);
      if (!qId || !type) return;
      if (type === 1 || type === 2) {
        const radio    = block.querySelector('input[type=radio]:checked');
        const valInps  = block.querySelectorAll(`input[name^="valorRespuesta[${qId}]"]`);
        const valores  = {};
        valInps.forEach(inp => {
          const m = inp.name.match(/\[(\d+)\]$/);
          if (m && inp.value !== '') valores[m[1]] = inp.value;
        });
        respuestas[qId] = {
          type,
          id_option:    radio ? Number(radio.value) : null,
          option_valor: radio?.dataset.valor ?? null,
          valores:      Object.keys(valores).length ? valores : null,
        };
      } else if (type === 3) {
        const checked  = [...block.querySelectorAll('input[type=checkbox]:checked')];
        const valInps  = block.querySelectorAll(`input[name^="valorRespuesta[${qId}]"]`);
        const valores  = {};
        valInps.forEach(inp => {
          const m = inp.name.match(/\[(\d+)\]$/);
          if (m && inp.value !== '') valores[m[1]] = inp.value;
        });
        respuestas[qId] = {
          type,
          id_options: checked.map(c => ({ id: Number(c.value), valor: c.dataset.valor ?? null })),
          valores:    Object.keys(valores).length ? valores : null,
        };
      } else if (type === 4) {
        const inp = block.querySelector('input[type=text]:not([type=hidden]), textarea');
        respuestas[qId] = { type, answer_text: inp?.value ?? null };
      } else if (type === 5) {
        const inp = block.querySelector('input[type=number]');
        respuestas[qId] = { type, answer_number: inp?.value ?? null };
      } else if (type === 6) {
        const inp = block.querySelector('input[type=date]');
        respuestas[qId] = { type, answer_date: inp?.value ?? null };
      }
      // Tipo 7 (foto): gestionado via fotos_preguntas, no aquí
    });

    const estadoSel = document.getElementById('estadoGestion');
    const motivoSel = document.getElementById('motivo');
    const comentTa  = document.getElementById('comentario');

    return {
      estadoGestion: estadoSel?.value ?? null,
      motivo:        motivoSel?.value ?? null,
      comentario:    comentTa?.value  ?? null,
      lat_gestion:   parseFloat(document.getElementById('latGestion')?.value) || null,
      lng_gestion:   parseFloat(document.getElementById('lngGestion')?.value) || null,
      materiales,
      respuestas,
    };
  }

  // ── Aplicar draft a la UI ─────────────────────────────────────────────────

  // Los handlers del formulario usan delegación jQuery: el evento debe burbujear
  // para que $(document).on('change', '.selector') los reciba.
  function _triggerChange(el) {
    if (window.$ && el) {
      $(el).trigger('change');
    } else if (el) {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function _restoreValores(block, qId, valores) {
    if (!valores) return;
    for (const [optId, val] of Object.entries(valores)) {
      const inp = block.querySelector(`input[name="valorRespuesta[${qId}][${optId}]"]`);
      if (inp && val != null) inp.value = val;
    }
  }

  function applyDraftToUI(draft) {
    if (!draft) return;
    _restoring = true;
    try {

    // Paso 1: estado general
    const estadoSel = document.getElementById('estadoGestion');
    if (estadoSel && draft.estadoGestion) {
      estadoSel.value = draft.estadoGestion;
      _triggerChange(estadoSel);
    }
    const motivoSel = document.getElementById('motivo');
    if (motivoSel && draft.motivo) {
      motivoSel.value = draft.motivo;
      _triggerChange(motivoSel);
    }
    const comentTa = document.getElementById('comentario');
    if (comentTa && draft.comentario) {
      comentTa.value = draft.comentario;
    }

    // Paso 2: materiales
    Object.entries(draft.materiales || {}).forEach(([fqId, mat]) => {
      const checkbox = document.querySelector(
        `.implementa-material[data-id-material="${fqId}"]`
      );
      if (!checkbox) return;

      if (mat.implementado !== undefined) {
        checkbox.checked = mat.implementado;
        // jQuery delegation requiere bubbling; trigger('change') lo garantiza
        _triggerChange(checkbox);
      }

      // Valores de texto se restauran DESPUÉS del change para que la sección esté visible
      const section   = document.getElementById('implementa_section_' + fqId);
      const noSection = document.getElementById('no_implementa_section_' + fqId);

      if (section) {
        if (mat.valor_real !== null && mat.valor_real !== undefined) {
          const inp = section.querySelector('[name="valor[' + fqId + ']"]');
          if (inp) inp.value = mat.valor_real;
        }
        if (mat.observacion !== null && mat.observacion !== undefined) {
          const ta = section.querySelector('[name="observacion[' + fqId + ']"]');
          if (ta) ta.value = mat.observacion;
        }
      }
      if (noSection) {
        if (mat.motivo_select !== null && mat.motivo_select !== undefined) {
          const sel = noSection.querySelector('[name="motivoSelect[' + fqId + ']"]');
          if (sel) sel.value = mat.motivo_select;
        }
        if (mat.motivo_detalle !== null && mat.motivo_detalle !== undefined) {
          const ta = noSection.querySelector('[name="motivoNoImplementado[' + fqId + ']"]');
          if (ta) ta.value = mat.motivo_detalle;
        }
      }
    });

    // Paso 3: respuestas encuesta
    Object.entries(draft.respuestas || {}).forEach(([qId, resp]) => {
      const block = document.querySelector(`[data-question-id="${qId}"]`);
      if (!block || !resp) return;

      if (resp.type === 1 || resp.type === 2) {
        if (resp.id_option != null) {
          const radio = block.querySelector(`input[type=radio][value="${resp.id_option}"]`);
          if (radio) { radio.checked = true; _triggerChange(radio); }
        }
        _restoreValores(block, qId, resp.valores);
      } else if (resp.type === 3) {
        (resp.id_options || []).forEach(opt => {
          const cb = block.querySelector(`input[type=checkbox][value="${opt.id}"]`);
          if (cb) { cb.checked = true; _triggerChange(cb); }
        });
        _restoreValores(block, qId, resp.valores);
      } else if (resp.type === 4 && resp.answer_text != null) {
        const inp = block.querySelector('input[type=text]:not([type=hidden]), textarea');
        if (inp) inp.value = resp.answer_text;
      } else if (resp.type === 5 && resp.answer_number != null) {
        const inp = block.querySelector('input[type=number]');
        if (inp) inp.value = resp.answer_number;
      } else if (resp.type === 6 && resp.answer_date != null) {
        const inp = block.querySelector('input[type=date]');
        if (inp) inp.value = resp.answer_date;
      }
    });

    // Recalcular visibilidad de preguntas condicionales si existe la función
    if (typeof window.recalcConditionalVisibility === 'function') {
      window.recalcConditionalVisibility();
    } else {
      // Fallback: re-disparar change en radios marcados para que las preguntas
      // condicionales se muestren/oculten correctamente
      document.querySelectorAll('input[type=radio]:checked').forEach(r => _triggerChange(r));
    }

    } finally {
      _restoring = false;
      // Save final: captura el estado completo de la UI incluyendo valorRespuesta
      _doSave(collectFormState());
    }
  }

  // ── Merge: snapshot servidor + draft local ────────────────────────────────

  function mergeStates(snapshot, localDraft) {
    if (!snapshot && !localDraft) return null;
    if (!snapshot) return localDraft;
    if (!localDraft) return _buildDraftFromSnapshot(snapshot);

    const merged = { ...localDraft };

    // Fotos materiales: servidor gana para confirmadas, local suma pendientes
    const fotosMat = {};
    Object.entries(localDraft.fotos_materiales || {}).forEach(([k, v]) => {
      fotosMat[k] = Array.isArray(v) ? [...v] : [v];
    });
    Object.entries(snapshot.fotos_materiales || {}).forEach(([fqId, serverFotos]) => {
      if (!fotosMat[fqId]) fotosMat[fqId] = [];
      (Array.isArray(serverFotos) ? serverFotos : [serverFotos]).forEach(sf => {
        const tracked = fotosMat[fqId].find(
          f => f.server_foto_id === sf.id || f.url === sf.url
        );
        if (!tracked) {
          fotosMat[fqId].push({
            local_temp_id:  'server-' + sf.id,
            server_foto_id: sf.id,
            url:            sf.url,
            blob_key:       null,
            status:         'uploaded',
            queue_job_id:   null,
            idempotency_key:'',
            error:          null,
          });
        } else {
          // Actualizar confirmación
          tracked.status         = 'uploaded';
          tracked.server_foto_id = sf.id;
          tracked.url            = sf.url;
        }
      });
    });
    merged.fotos_materiales = fotosMat;

    // Fotos preguntas
    const fotosPreg = { ...(localDraft.fotos_preguntas || {}) };
    Object.entries(snapshot.fotos_preguntas || {}).forEach(([qId, sf]) => {
      if (!fotosPreg[qId]) {
        fotosPreg[qId] = {
          local_temp_id:  'server-resp-' + sf.resp_id,
          server_resp_id: sf.resp_id,
          url:            sf.url,
          blob_key:       null,
          status:         'uploaded',
          queue_job_id:   null,
          idempotency_key:'',
          error:          null,
        };
      } else {
        fotosPreg[qId].status         = 'uploaded';
        fotosPreg[qId].server_resp_id = sf.resp_id;
        fotosPreg[qId].url            = sf.url;
      }
    });
    merged.fotos_preguntas = fotosPreg;

    // Si el servidor tiene draft con form_state_json más reciente, usar esos campos
    const serverDraft = snapshot.draft_server;
    if (serverDraft && serverDraft.form_state_json) {
      const serverTs = serverDraft.form_state_updated_at
        ? new Date(serverDraft.form_state_updated_at).getTime()
        : 0;
      if (serverTs > (localDraft.updated_at || 0)) {
        const ss = serverDraft.form_state_json;
        if (ss.estadoGestion) merged.estadoGestion = ss.estadoGestion;
        if (ss.materiales)    merged.materiales    = { ...ss.materiales,  ...merged.materiales  };
        if (ss.respuestas)    merged.respuestas    = { ...ss.respuestas,  ...merged.respuestas  };
      }
    }

    return merged;
  }

  function _buildDraftFromSnapshot(snapshot) {
    if (!snapshot) return null;
    const draft = {
      user_id:        _userId,
      form_id:        _formId,
      local_id:       _localId,
      client_guid:    _clientGuid,
      schema_version: SCHEMA_VERSION,
      created_at:     Date.now(),
      updated_at:     Date.now(),
      status:         'draft',
      visita_id:      snapshot.visita?.id ?? null,
      estadoGestion:  null,
      motivo:         null,
      comentario:     null,
      lat_gestion:    null,
      lng_gestion:    null,
      materiales:     {},
      fotos_materiales: {},
      respuestas:     {},
      fotos_preguntas: {},
      fotos_especiales:{},
      submit_idempotency_key: null,
      last_server_sync_at:    null,
      server_sync_error:      null,
    };

    // Poblar fotos desde snapshot
    Object.entries(snapshot.fotos_materiales || {}).forEach(([fqId, fotos]) => {
      draft.fotos_materiales[fqId] = fotos.map(sf => ({
        local_temp_id:  'server-' + sf.id,
        server_foto_id: sf.id,
        url:            sf.url,
        blob_key:       null,
        status:         'uploaded',
        queue_job_id:   null,
        idempotency_key:'',
        error:          null,
      }));
    });

    Object.entries(snapshot.fotos_preguntas || {}).forEach(([qId, sf]) => {
      draft.fotos_preguntas[qId] = {
        local_temp_id:  'server-resp-' + sf.resp_id,
        server_resp_id: sf.resp_id,
        url:            sf.url,
        blob_key:       null,
        status:         'uploaded',
        queue_job_id:   null,
        idempotency_key:'',
        error:          null,
      };
    });

    // Campos del draft servidor si existen
    if (snapshot.draft_server?.form_state_json) {
      const ss = snapshot.draft_server.form_state_json;
      if (ss.estadoGestion) draft.estadoGestion = ss.estadoGestion;
      if (ss.materiales)    draft.materiales    = ss.materiales;
      if (ss.respuestas)    draft.respuestas    = ss.respuestas;
    }

    return draft;
  }

  // ── Thumbnail helpers ─────────────────────────────────────────────────────

  function buildFotoThumbHtml({ tempId, fotoId, url, status, error, deletable }) {
    const escapedError = (error || '').replace(/"/g, '&quot;');
    const statusMap = {
      uploaded:  '<span class="draft-foto-badge badge-uploaded">✓ Subida</span>',
      pending:   '<span class="draft-foto-badge badge-pending">⏳ Pendiente</span>',
      uploading: '<span class="draft-foto-badge badge-uploading">⬆ Subiendo…</span>',
      error:     `<span class="draft-foto-badge badge-error" title="${escapedError}">✕ Error</span>`,
    };
    const badge = statusMap[status] || '';

    const PLACEHOLDER = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'%3E%3Crect width=\'80\' height=\'80\' fill=\'%23eee\'/%3E%3Ctext x=\'50%25\' y=\'55%25\' text-anchor=\'middle\' fill=\'%23999\' font-size=\'11\'%3E⏳%3C/text%3E%3C/svg%3E';
    const imgSrc = url || PLACEHOLDER;

    const delBtn = (deletable && fotoId)
      ? `<button class="draft-foto-del-btn" data-foto-id="${fotoId}" data-temp-id="${tempId}" type="button" title="Eliminar foto">×</button>`
      : '';

    return `<div class="draft-foto-thumb" data-temp-id="${tempId}" data-foto-id="${fotoId || ''}">
  <img src="${imgSrc}" loading="lazy" class="draft-foto-img">
  ${badge}
  ${delBtn}
</div>`;
  }

  function renderSnapshotThumbs(snapshot, {
    isClosed = false,
    urlPrefix = '/visibility2/app/'
  } = {}) {
    if (!snapshot) return;

    // Normalizar URL: asegurar que sea absoluta
    const normalizeUrl = url => {
      if (!url) return url;
      if (url.startsWith('http') || url.startsWith('/')) return url;
      return urlPrefix + url;
    };

    // Fotos de materiales
    Object.entries(snapshot.fotos_materiales || {}).forEach(([fqId, fotos]) => {
      const container = document.getElementById('previewContainer_' + fqId);
      if (!container) return;

      (Array.isArray(fotos) ? fotos : [fotos]).forEach(foto => {
        const tempId = 'server-' + foto.id;
        // Evitar duplicados
        if (container.querySelector(`[data-temp-id="${tempId}"]`)) return;

        const absUrl = normalizeUrl(foto.url);
        container.insertAdjacentHTML('beforeend', buildFotoThumbHtml({
          tempId,
          fotoId:   foto.id,
          url:      absUrl,
          status:   'uploaded',
          error:    null,
          deletable: !isClosed,
        }));

        // Inyectar hidden input para que validarMateriales() reconozca la foto
        const hiddenContainer = document.getElementById('hiddenUploadContainer_' + fqId);
        if (hiddenContainer && !hiddenContainer.querySelector(`input[data-foto-id="${foto.id}"]`)) {
          const h = document.createElement('input');
          h.type  = 'hidden';
          h.name  = 'fotos[' + fqId + '][]';
          h.value = absUrl;
          h.dataset.fotoId = String(foto.id);
          hiddenContainer.appendChild(h);
        }
      });
    });

    // Fotos de preguntas tipo 7
    Object.entries(snapshot.fotos_preguntas || {}).forEach(([qId, foto]) => {
      const container =
        document.getElementById('previewFoto_' + qId) ||
        document.querySelector(`.foto-pregunta-preview[data-question-id="${qId}"]`) ||
        document.querySelector(`[data-question-id="${qId}"] .foto-pregunta-preview`);
      if (!container) return;

      const tempId = 'server-resp-' + foto.resp_id;
      if (container.querySelector(`[data-temp-id="${tempId}"]`)) return;

      const absUrl = normalizeUrl(foto.url);
      container.insertAdjacentHTML('beforeend', buildFotoThumbHtml({
        tempId,
        fotoId:   foto.resp_id,
        url:      absUrl,
        status:   'uploaded',
        error:    null,
        deletable: !isClosed,
      }));

      // Actualizar flag hidden de foto si existe (usa URL normalizada para consistencia)
      const flag = document.getElementById('flagFoto_' + qId);
      if (flag && !flag.value) flag.value = absUrl;
    });
  }

  function renderPendingThumbs(draft) {
    if (!draft) return;

    Object.entries(draft.fotos_materiales || {}).forEach(([fqId, fotos]) => {
      const container = document.getElementById('previewContainer_' + fqId);
      if (!container) return;

      (Array.isArray(fotos) ? fotos : [fotos]).forEach(foto => {
        if (foto.status === 'uploaded') return; // ya renderizado desde snapshot
        if (container.querySelector(`[data-temp-id="${foto.local_temp_id}"]`)) return;

        container.insertAdjacentHTML('beforeend', buildFotoThumbHtml({
          tempId:   foto.local_temp_id,
          fotoId:   null,
          url:      foto.url || null,
          status:   foto.status,
          error:    foto.error,
          deletable: false,
        }));
      });
    });
  }

  // ── Sincronización con servidor ───────────────────────────────────────────

  function _scheduleServerSync() {
    clearTimeout(_serverSyncTimer);
    _serverSyncTimer = setTimeout(_doServerSync, SERVER_SYNC_DEBOUNCE_MS);
  }

  async function _doServerSync() {
    if (!_initialized || !_clientGuid || !_currentDraft) return;
    if (!navigator.onLine) return;

    const csrf = _getCSRF();
    if (!csrf) return;

    try {
      const formState = {
        estadoGestion: _currentDraft.estadoGestion,
        materiales:    _currentDraft.materiales,
        respuestas:    _currentDraft.respuestas,
      };

      const body = new URLSearchParams({
        client_guid:      _clientGuid,
        form_id:          String(_formId),
        local_id:         String(_localId),
        visita_id:        String(_currentDraft.visita_id || ''),
        form_state_json:  JSON.stringify(formState),
        schema_version:   String(SCHEMA_VERSION),
        csrf_token:       csrf,
      });

      const res = await fetch(SERVER_SYNC_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
        body: body.toString(),
        signal: AbortSignal.timeout(10_000),
      });

      if (res.ok) {
        saveDraft({ last_server_sync_at: Date.now(), server_sync_error: null }, true);
      }
    } catch (err) {
      // Silencioso en offline — se reintentará en el próximo ciclo
      saveDraft({ server_sync_error: String(err.message || err) }, true);
    }
  }

  function forceSyncNow() {
    clearTimeout(_serverSyncTimer);
    return _doServerSync();
  }

  // ── Buscar snapshot en servidor ───────────────────────────────────────────

  async function fetchServerSnapshot(formId, localId, clientGuid, visitaId) {
    const fid  = Number(formId)    || _formId;
    const lid  = Number(localId)   || _localId;
    const guid = String(clientGuid || _clientGuid);
    const vid  = Number(visitaId)  || 0;

    if (!fid || !lid) return null;

    const csrf = _getCSRF();
    try {
      const body = new URLSearchParams({
        form_id:     String(fid),
        local_id:    String(lid),
        client_guid: guid,
        visita_id:   vid ? String(vid) : '',
      });
      if (csrf) body.set('csrf_token', csrf);

      const res = await fetch(SNAPSHOT_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf || '' },
        credentials: 'same-origin',
        body: body.toString(),
        signal: AbortSignal.timeout(8_000),
        cache: 'no-store',
      });

      if (!res.ok) return null;
      const data = await res.json();
      return data.ok ? data : null;
    } catch (err) {
      console.warn('[GestionDraft] fetchServerSnapshot falló:', err);
      return null;
    }
  }

  // ── Wiring de autosave ────────────────────────────────────────────────────

  function wireAutosave() {
    // Texto / número / textarea: debounced
    document.querySelectorAll(
      '#gestionarForm input[type=text]:not([data-no-draft]), ' +
      '#gestionarForm input[type=number]:not([data-no-draft]), ' +
      '#gestionarForm textarea:not([data-no-draft])'
    ).forEach(el => {
      el.addEventListener('input', () => saveDraft(collectFormState()));
    });

    // Select / radio / checkbox: inmediato
    document.querySelectorAll(
      '#gestionarForm select:not([data-no-draft]), ' +
      '#gestionarForm input[type=radio]:not([data-no-draft]), ' +
      '#gestionarForm input[type=checkbox]:not([data-no-draft])'
    ).forEach(el => {
      el.addEventListener('change', () => saveDraft(collectFormState(), true));
    });
  }

  // ── Contar fotos en draft ─────────────────────────────────────────────────

  function countTotalFotos(draft) {
    if (!draft) return 0;
    let count = 0;
    Object.values(draft.fotos_materiales || {}).forEach(arr => {
      (Array.isArray(arr) ? arr : [arr]).forEach(f => {
        if (f && f.status === 'uploaded') count++;
      });
    });
    Object.values(draft.fotos_preguntas || {}).forEach(f => {
      if (f && f.status === 'uploaded') count++;
    });
    return count;
  }

  // ── API pública ───────────────────────────────────────────────────────────

  window.GestionDraft = {
    init,
    saveDraft,
    loadDraft,
    clearDraft,
    markSubmitted,
    updateVisitaId,
    markFotoMatUpload,
    markFotoPregUpload,
    markFotoError,
    collectFormState,
    applyDraftToUI,
    mergeStates,
    fetchServerSnapshot,
    renderSnapshotThumbs,
    renderPendingThumbs,
    wireAutosave,
    countTotalFotos,
    forceSyncNow,
    buildFotoThumbHtml,
    // Getter del draft en memoria
    getCurrentDraft: () => _currentDraft,
  };

})();

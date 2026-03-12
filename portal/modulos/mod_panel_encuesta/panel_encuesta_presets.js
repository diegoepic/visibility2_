(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // shared Map reference
  const USER_ID  = window.PEConfig.USER_ID;

  // Factory presets: resueltos por PHP con metadata embebida.
  const FACTORY_PRESETS = window.PEConfig.FACTORY_PRESETS || [];

  // ========= PRESETS de usuario (localStorage) =========
  const PRESETS_KEY = 'panel_encuesta_qpresets';

  function listPresets(){
    try { return JSON.parse(localStorage.getItem(PRESETS_KEY) || '[]'); }
    catch(e){ return []; }
  }

  function savePresets(arr){
    try {
      localStorage.setItem(PRESETS_KEY, JSON.stringify(arr));
    } catch(e) {
      alert('No se pudo guardar el preset: el almacenamiento local está lleno o no disponible.');
    }
  }

  // Captura todos los filtros actuales (preguntas + scope completo)
  function buildPresetFromState(){
    const items = [];
    QFILTERS.forEach(({meta, values}) => { items.push({ meta, values }); });
    return {
      version: 2,
      created_at: new Date().toISOString(),
      name: null,
      scope: {
        division:    $('#f_division').val()    || '0',
        subdivision: $('#f_subdivision').val() || '0',
        tipo:        $('#f_tipo').val()        || '0',
        form_id:     $('#f_form').val()        || '0',
        desde:       $('#f_desde').val()       || '',
        hasta:       $('#f_hasta').val()       || '',
        distrito:    $('#f_distrito').val()    || '0',
        jv:          $('#f_jv').val()          || '0',
        usuario:     $('#f_usuario').val()     || '0',
        codigo:      $('#f_codigo').val()      || '',
      },
      items
    };
  }
  PE.buildPresetFromState = buildPresetFromState;

  // ========= applyPreset =========
  function applyPreset(preset){
    // 1. Limpiar selección actual
    PE._applyingPreset = true;
    try { $('#f_preguntas').val(null).trigger('change'); } finally { PE._applyingPreset = false; }
    QFILTERS.clear();

    const items = preset.items || [];

    // 2. Aplicar scope completo (version 2) o defaultScope (factory / versión 1)
    const scope = preset.scope || preset.defaultScope || null;
    if (scope) {
      if (scope.subdivision != null) $('#f_subdivision').val(String(scope.subdivision));
      if (scope.tipo        != null) $('#f_tipo').val(String(scope.tipo));
      if (scope.form_id     != null) $('#f_form').val(String(scope.form_id));
      // Campos extra en version 2
      if (scope.desde   != null && scope.desde   !== '') $('#f_desde').val(scope.desde);
      if (scope.hasta   != null && scope.hasta   !== '') $('#f_hasta').val(scope.hasta);
      if (scope.distrito != null && scope.distrito !== '0') $('#f_distrito').val(String(scope.distrito));
      if (scope.jv       != null && scope.jv       !== '0') $('#f_jv').val(String(scope.jv));
      if (scope.usuario  != null && scope.usuario  !== '0') $('#f_usuario').val(String(scope.usuario));
      if (scope.codigo   != null && scope.codigo   !== '') $('#f_codigo').val(scope.codigo);
      // MC: división
      if (scope.division != null && scope.division !== '0' && $('#f_division').length) {
        $('#f_division').val(String(scope.division)).trigger('change');
      }
    }

    if (!items.length){ PE.syncQFiltersUI(); return Promise.resolve(); }

    const resolved   = items.filter(it => it._resolved === true);
    const unresolved = items.filter(it => it._resolved !== true);

    resolved.forEach(item => {
      const key = 'set:' + item.id;
      const optId = String(item.id);
      const opt = new Option(item.label, optId, true, true);
      $('#f_preguntas').append(opt);
      QFILTERS.set(key, {
        meta: {
          id:          item.id,
          mode:        'set',
          tipo:        item.tipo,
          tipo_texto:  item.tipo_texto,
          label:       item.label,
          has_options: !!item.has_options,
          options:     item.options || [],
        },
        values: item.values || {}
      });
    });

    const normalized = unresolved.map(it => {
      if (it.meta && it.meta.id != null) {
        return { mode: it.meta.mode || 'set', id: it.meta.id, label: it.meta.label || ('Pregunta ' + it.meta.id), values: it.values || {} };
      } else if (it.qset_id != null) {
        return { mode: it.mode || 'set', id: it.qset_id, label: it.label || ('Pregunta ' + it.qset_id), values: it.values || {} };
      }
      return null;
    }).filter(Boolean);

    if (!normalized.length) {
      PE._applyingPreset = true;
      try { $('#f_preguntas').trigger('change'); } finally { PE._applyingPreset = false; }
      PE.syncQFiltersUI();
      return Promise.resolve();
    }

    return Promise.allSettled(
      normalized.map(n =>
        PE.fetchPreguntaMeta(n.mode, n.id, true)
          .then(freshMeta => ({ ...n, freshMeta }))
      )
    ).then(results => {
      const loaded  = [];
      const skipped = [];

      results.forEach((r, i) => {
        if (r.status === 'fulfilled') {
          const { freshMeta, label, values } = r.value;
          const keyMode    = freshMeta.mode || r.value.mode;
          const finalLabel = label || ('Pregunta ' + freshMeta.id);

          const optId = (keyMode === 'vset') ? ('v:' + freshMeta.id) : String(freshMeta.id);
          const opt   = new Option(finalLabel, optId, true, true);
          $('#f_preguntas').append(opt);

          const key = (keyMode === 'set' ? 'set:' : (keyMode === 'vset' ? 'vset:' : 'exact:')) + freshMeta.id;
          QFILTERS.set(key, {
            meta: {
              id:          freshMeta.id,
              mode:        keyMode,
              tipo:        freshMeta.tipo,
              tipo_texto:  freshMeta.tipo_texto,
              label:       finalLabel,
              has_options: !!freshMeta.has_options,
              options:     freshMeta.options || []
            },
            values
          });
          loaded.push(finalLabel);
        } else {
          skipped.push(normalized[i].label);
        }
      });

      PE._applyingPreset = true;
      try { $('#f_preguntas').trigger('change'); } finally { PE._applyingPreset = false; }
      PE.syncQFiltersUI();

      if (skipped.length > 0 && loaded.length > 0) {
        PE.showError('Preset parcial',
          `Se cargaron <strong>${loaded.length}</strong> de ${normalized.length} preguntas.<br>` +
          `<strong>No disponibles en este ámbito:</strong><br>` +
          skipped.map(s => '&bull; ' + PE.escapeHtml(s)).join('<br>'),
          true);
      } else if (skipped.length > 0 && loaded.length === 0) {
        PE.showError('Preset no disponible',
          `Ninguna de las ${normalized.length} preguntas del preset está disponible en el ámbito actual.<br><br>` +
          `<strong>Preguntas no encontradas:</strong><br>` +
          skipped.map(s => '&bull; ' + PE.escapeHtml(s)).join('<br>'),
          true);
      }
    });
  }
  PE.applyPreset = applyPreset;

  // ========= Menú de presets =========
  function refreshPresetsMenu(){
    const userPresets = listPresets();
    const $m = $('#presetsMenu').empty();
    const items = [];

    FACTORY_PRESETS.forEach((p, idx) => {
      items.push({ kind: 'factory', idx, name: p.name || ('Preset ' + (idx + 1)) });
    });

    userPresets.forEach((p, idx) => {
      items.push({ kind: 'user', idx, name: p.name || ('Preset ' + (idx + 1)) });
    });

    if (!items.length){
      $m.append('<span class="dropdown-item-text text-muted">Sin presets aún</span>');
      return;
    }

    items.forEach(item => {
      const $row  = $('<div class="d-flex align-items-center px-3 py-1"></div>');
      const $link = $('<a href="#" class="mr-2 preset-load text-truncate" style="max-width:200px"></a>')
        .text(item.name)
        .attr('data-kind', item.kind)
        .attr('data-idx',  item.idx);
      $row.append($link);

      if (item.kind === 'user') {
        const $del = $('<a href="#" class="text-danger ml-auto preset-del flex-shrink-0" title="Eliminar"><i class="fa fa-times"></i></a>')
          .attr('data-idx', item.idx);
        $row.append($del);
      }
      $m.append($row);
    });
  }
  PE.refreshPresetsMenu = refreshPresetsMenu;

  $(document).on('click', '.preset-load', function(e){
    e.preventDefault();
    const kind = $(this).data('kind');
    const idx  = parseInt($(this).data('idx'), 10);

    if (kind === 'factory') {
      const preset = FACTORY_PRESETS[idx];
      if (preset) applyPreset(preset);
    } else {
      const arr = listPresets();
      if (arr[idx]) applyPreset(arr[idx]);
    }
  });

  $(document).on('click', '.preset-del', function(e){
    e.preventDefault();
    const idx = parseInt($(this).data('idx'), 10);
    const arr = listPresets();
    if (arr[idx]){ arr.splice(idx, 1); savePresets(arr); refreshPresetsMenu(); }
  });

  $('#btnSavePreset').on('click', function(){
    const name = prompt('Nombre para el preset:');
    if (!name) return;
    const arr    = listPresets();
    const preset = buildPresetFromState();
    preset.name  = name;
    arr.push(preset);
    savePresets(arr);
    refreshPresetsMenu();
  });

  $('#btnClearPreset').on('click', function(){
    $('#f_preguntas').val(null).trigger('change');
    QFILTERS.clear();
    PE.syncQFiltersUI();
  });

  // ========= COMPARTIR: generar URL con estado completo =========
  $('#btnShareLink').on('click', function(){
    try {
      const preset = buildPresetFromState();
      const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(preset))));
      const url = location.origin + location.pathname + '?state=' + encodeURIComponent(encoded);
      if (navigator.clipboard) {
        navigator.clipboard.writeText(url)
          .then(() => alert('Enlace copiado al portapapeles.\nPégalo en el navegador para restaurar exactamente estos filtros.'))
          .catch(() => prompt('Copia este enlace:', url));
      } else {
        prompt('Copia este enlace:', url);
      }
    } catch(e) {
      alert('No se pudo generar el enlace.');
    }
  });

  // ========= AUTOFILL (por usuario) =========
  function hasURLOverrides(){
    const params = new URLSearchParams(location.search);
    params.delete('state');
    return params.toString().length > 0;
  }

  function runRedBullAutofill(){
    const preset = FACTORY_PRESETS.find(p => p.autofillUser === USER_ID);

    if (!preset || hasURLOverrides() ||
        QFILTERS.size > 0 ||
        ($('#f_preguntas').val() || []).length) {
      return Promise.resolve(false);
    }

    return applyPreset(preset).then(() => true);
  }
  PE.runRedBullAutofill = runRedBullAutofill;

  // ========= RESTAURAR estado desde URL ?state= =========
  PE.restoreStateFromURL = function(){
    const stateParam = new URLSearchParams(location.search).get('state');
    if (!stateParam) return Promise.resolve(false);

    try {
      const decoded = decodeURIComponent(escape(atob(decodeURIComponent(stateParam))));
      const preset  = JSON.parse(decoded);
      if (!preset || typeof preset !== 'object') return Promise.resolve(false);

      // Limpiar el parámetro state de la URL sin recargar
      const clean = new URLSearchParams(location.search);
      clean.delete('state');
      const newUrl = location.pathname + (clean.toString() ? '?' + clean.toString() : '');
      history.replaceState(null, '', newUrl);

      return applyPreset(preset).then(() => true);
    } catch(e) {
      return Promise.resolve(false);
    }
  };

})(jQuery);

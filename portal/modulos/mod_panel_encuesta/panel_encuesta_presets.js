(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // shared Map reference
  const USER_ID  = window.PEConfig.USER_ID;

  // Factory presets: resueltos por PHP con metadata embebida.
  // Cada item tiene _resolved:true → no necesitan AJAX de validación.
  const FACTORY_PRESETS = window.PEConfig.FACTORY_PRESETS || [];

  // ========= PRESETS de usuario (localStorage) =========
  const PRESETS_KEY = 'panel_encuesta_qpresets';

  function listPresets(){
    try { return JSON.parse(localStorage.getItem(PRESETS_KEY) || '[]'); }
    catch(e){ return []; }
  }

  function savePresets(arr){
    localStorage.setItem(PRESETS_KEY, JSON.stringify(arr));
  }

  function buildPresetFromState(){
    const items = [];
    QFILTERS.forEach(({meta, values}) => { items.push({ meta, values }); });
    return {
      version: 1,
      created_at: new Date().toISOString(),
      form_id: $('#f_form').val(),
      items
    };
  }

  // ========= applyPreset =========
  // Soporta dos rutas:
  //   A) Factory preset (_resolved:true) → aplica metadata embebida directamente, sin AJAX.
  //      Primero setea defaultScope en el formulario.
  //   B) User preset (guardado en localStorage) → fetchPreguntaMeta() como antes.

  function applyPreset(preset){
    // 1. Limpiar selección actual
    PE._applyingPreset = true;
    try { $('#f_preguntas').val(null).trigger('change'); } finally { PE._applyingPreset = false; }
    QFILTERS.clear();

    const items = preset.items || [];
    if (!items.length){ PE.syncQFiltersUI(); return Promise.resolve(); }

    // 2. Si el preset tiene defaultScope, aplicarlo al formulario ANTES de popular QFILTERS
    if (preset.defaultScope) {
      const ds = preset.defaultScope;
      if (ds.subdivision != null) $('#f_subdivision').val(String(ds.subdivision));
      if (ds.tipo       != null) $('#f_tipo').val(String(ds.tipo));
      if (ds.form_id    != null) $('#f_form').val(String(ds.form_id));
    }

    // 3. Separar items resueltos (factory) de items sin resolver (user presets)
    const resolved   = items.filter(it => it._resolved === true);
    const unresolved = items.filter(it => it._resolved !== true);

    // 3a. Aplicar items resueltos directamente (fast-apply, sin AJAX)
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

    // 3b. Resolver items de user-presets vía AJAX (normalizar formato heredado)
    const normalized = unresolved.map(it => {
      if (it.meta && it.meta.id != null) {
        return {
          mode:   it.meta.mode  || 'set',
          id:     it.meta.id,
          label:  it.meta.label || ('Pregunta ' + it.meta.id),
          values: it.values || {}
        };
      } else if (it.qset_id != null) {
        return {
          mode:   it.mode  || 'set',
          id:     it.qset_id,
          label:  it.label || ('Pregunta ' + it.qset_id),
          values: it.values || {}
        };
      }
      return null;
    }).filter(Boolean);

    // Si solo había items resueltos, actualizar UI y salir
    if (!normalized.length) {
      PE._applyingPreset = true;
      try { $('#f_preguntas').trigger('change'); } finally { PE._applyingPreset = false; }
      PE.syncQFiltersUI();
      return Promise.resolve();
    }

    // Fetch meta en paralelo para los items no resueltos
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
        const msg = `Se cargaron <strong>${loaded.length}</strong> de ${normalized.length} preguntas.<br>` +
                    `<strong>No disponibles en este ámbito:</strong><br>` +
                    skipped.map(s => '&bull; ' + PE.escapeHtml(s)).join('<br>');
        PE.showError('Preset parcial', msg);
      } else if (skipped.length > 0 && loaded.length === 0) {
        const msg = `Ninguna de las ${normalized.length} preguntas del preset está disponible en el ámbito actual.<br><br>` +
                    `<strong>Preguntas no encontradas:</strong><br>` +
                    skipped.map(s => '&bull; ' + PE.escapeHtml(s)).join('<br>');
        PE.showError('Preset no disponible', msg);
      }
    });
  }

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
      const $link = $('<a href="#" class="mr-2 preset-load"></a>')
        .text(item.name)
        .attr('data-kind', item.kind)
        .attr('data-idx',  item.idx);
      $row.append($link);

      if (item.kind === 'user') {
        const $del = $('<a href="#" class="text-danger ml-auto preset-del" title="Eliminar"><i class="fa fa-times"></i></a>')
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

  // ========= AUTOFILL (por usuario) =========
  function hasURLOverrides(){
    return (location.search && location.search.length > 1);
  }

  function runRedBullAutofill(){
    // Buscar preset que tenga autofillUser coincidiendo con el usuario actual
    const preset = FACTORY_PRESETS.find(p => p.autofillUser === USER_ID);

    if (!preset || hasURLOverrides() ||
        QFILTERS.size > 0 ||
        ($('#f_preguntas').val() || []).length) {
      return Promise.resolve(false);
    }

    // applyPreset setea el scope y popula QFILTERS con metadata embebida (sin AJAX)
    return applyPreset(preset).then(() => true);
  }
  PE.runRedBullAutofill = runRedBullAutofill;

})(jQuery);

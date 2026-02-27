(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // Map reference — mutations visible to all modules

  // ========= QFILTERS UI =========
  function renderQFilterControl(key, entry){
    const { meta } = entry;
    const tipoBadge  = `<span class="badge badge-secondary">${PE.escapeHtml(meta.tipo_texto || '')}</span>`;
    const scopeBadge = meta.mode === 'set'
      ? ' <span class="badge badge-info">Global</span>'
      : (meta.mode === 'vset' ? ' <span class="badge badge-warning">Global (huérfana)</span>' : '');

    const base = $(`
      <div class="qf-card" data-key="${key}">
        <div class="d-flex align-items-center">
          <div class="qf-title">${PE.escapeHtml(meta.label || ('Pregunta '+meta.id))}</div>
          <div class="ml-2 qf-badges">${tipoBadge}${scopeBadge}</div>
          <button class="btn btn-sm btn-link text-danger ml-auto qf-remove" data-key="${key}" title="Quitar"><i class="fa fa-times"></i></button>
        </div>
        <div class="mt-2 qf-controls" data-key="${key}"></div>
        <div class="mt-2">
          <div class="d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary qf-stats-refresh" data-key="${key}">
              <i class="fa fa-chart-bar"></i> Ver conteos
            </button>
            <div class="ml-2 small text-muted">Resumen rápido con el ámbito actual</div>
          </div>
          <div class="qf-stats mt-2" data-key="${key}"></div>
        </div>
      </div>
    `);

    const $ctrl = base.find('.qf-controls');
    const vals  = entry.values || {};

    if (meta.tipo === 1) {
      const boolVals = Array.isArray(vals.bool) ? vals.bool : [];
      const siChk = boolVals.includes(1) ? 'checked' : '';
      const noChk = boolVals.includes(0) ? 'checked' : '';
      $ctrl.html(`
        <div>
          <label class="mr-2 mb-0">Filtrar:</label>
          <label class="mr-2"><input type="checkbox" class="qf-bool" data-key="${key}" value="1" ${siChk}> Sí</label>
          <label class="mr-2"><input type="checkbox" class="qf-bool" data-key="${key}" value="0" ${noChk}> No</label>
        </div>
      `);
    } else if (meta.tipo === 2) {
      const selIds   = Array.isArray(vals.opts_ids)   ? vals.opts_ids.map(x => parseInt(x,10)) : [];
      const selTexts = Array.isArray(vals.opts_texts)  ? vals.opts_texts : [];
      const opts = (meta.options||[]).map(o => {
        const v   = (o.id != null) ? String(o.id) : ('text:'+o.text);
        const sel = (o.id != null && selIds.includes(o.id)) || (o.id == null && selTexts.includes(o.text)) ? 'selected' : '';
        return `<option value="${PE.escapeHtml(v)}" ${sel}>${PE.escapeHtml(o.text)}</option>`;
      }).join('');
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Es igual a:</label>
          <select multiple class="form-control form-control-sm qf-opts" data-key="${key}" style="max-width:420px">${opts}</select>
        </div>
      `);
    } else if (meta.tipo === 3) {
      const selIds   = Array.isArray(vals.opts_ids)   ? vals.opts_ids.map(x => parseInt(x,10)) : [];
      const selTexts = Array.isArray(vals.opts_texts)  ? vals.opts_texts : [];
      const matchMode = vals.match || 'any';
      const opts = (meta.options||[]).map(o => {
        const v   = (o.id != null) ? String(o.id) : ('text:'+o.text);
        const sel = (o.id != null && selIds.includes(o.id)) || (o.id == null && selTexts.includes(o.text)) ? 'selected' : '';
        return `<option value="${PE.escapeHtml(v)}" ${sel}>${PE.escapeHtml(o.text)}</option>`;
      }).join('');
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Contiene</label>
          <select multiple class="form-control form-control-sm qf-opts" data-key="${key}" style="max-width:420px">${opts}</select>
          <div class="ml-2">
            <label class="mr-2"><input type="radio" name="qfmode-${key}" class="qf-mode" data-key="${key}" value="any" ${matchMode==='any'?'checked':''}> alguna</label>
            <label><input type="radio" name="qfmode-${key}" class="qf-mode" data-key="${key}" value="all" ${matchMode==='all'?'checked':''}> todas</label>
          </div>
        </div>
      `);
    } else if (meta.tipo === 4) {
      const textOp  = vals.op   || 'contains';
      const textVal = vals.text || '';
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Respuesta</label>
          <select class="form-control form-control-sm qf-text-op" data-key="${key}">
            <option value="contains" ${textOp==='contains'?'selected':''}>contiene</option>
            <option value="equals"   ${textOp==='equals'  ?'selected':''}>igual a</option>
            <option value="prefix"   ${textOp==='prefix'  ?'selected':''}>empieza con</option>
            <option value="suffix"   ${textOp==='suffix'  ?'selected':''}>termina con</option>
          </select>
          <input class="form-control form-control-sm ml-2 qf-text-val" data-key="${key}" placeholder="texto…" value="${PE.escapeHtml(textVal)}">
        </div>
      `);
    } else if (meta.tipo === 5) {
      const numMin = (vals.min != null && !isNaN(vals.min)) ? vals.min : '';
      const numMax = (vals.max != null && !isNaN(vals.max)) ? vals.max : '';
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Entre</label>
          <input type="number" step="any" class="form-control form-control-sm qf-num-min" data-key="${key}" style="width:120px" placeholder="min" value="${numMin}">
          <span class="mx-2">y</span>
          <input type="number" step="any" class="form-control form-control-sm qf-num-max" data-key="${key}" style="width:120px" placeholder="max" value="${numMax}">
        </div>
      `);
    } else if (meta.tipo === 7) {
      $ctrl.html(`<div class="text-muted small">Sin filtro de contenido específico (usa conteo con/sin foto).</div>`);
    } else {
      $ctrl.html(`<div class="text-muted small">Sin controles para este tipo.</div>`);
    }

    return base;
  }

  function syncQFiltersUI(){
    const $box = $('#qfilters');
    // Destruir Select2 existentes antes de vaciar el contenedor
    $box.find('.qf-opts').each(function(){
      if ($(this).data('select2')) { $(this).select2('destroy'); }
    });
    $box.empty();

    if (QFILTERS.size === 0){
      $box.html('<small class="text-muted">Añade una o más preguntas y configura el filtro de su respuesta aquí…</small>');
      PE.renderActiveFilters();
      return;
    }
    QFILTERS.forEach((entry, key) => {
      $box.append(renderQFilterControl(key, entry));
    });

    // Inicializar Select2 en los selects de opciones para mejor UX
    $box.find('.qf-opts').each(function(){
      $(this).select2({
        placeholder: 'Seleccionar opciones…',
        allowClear: true,
        width: 'resolve',
        dropdownAutoWidth: true
      });
    });

    PE.renderActiveFilters();
    PE.updateZipFotosVisibility();
  }
  PE.syncQFiltersUI = syncQFiltersUI;

  $(document).on('click', '.qf-remove', function(){
    const key = $(this).data('key');
    QFILTERS.delete(key);
    const id = String(key).split(':')[1];
    const possibleIds = [''+id, 'v:'+id];
    possibleIds.forEach(pid => {
      const $opt = $('#f_preguntas').find(`option[value="${pid}"]`);
      if ($opt.length){ $opt.prop('selected', false); }
    });
    $('#f_preguntas').trigger('change');
    syncQFiltersUI();
  });

  function fetchPreguntaMeta(mode, id, silent){
    return new Promise((resolve, reject) => {
      $.getJSON('ajax_pregunta_meta.php', {
        mode: mode,
        id: id,
        division: $('#f_division').val(),
        subdivision: $('#f_subdivision').val(),
        tipo: $('#f_tipo').val() || 0,
        form_id: $('#f_form').val(),
        csrf_token: $('#csrf_token').val()
      }, resp => {
        const payload = resp && resp.data ? resp.data : resp;
        if (payload && payload.id){ resolve(payload); } else { reject(); }
      }).fail(xhr => {
        if (!silent) PE.showError('No se pudo cargar metadatos', xhr.responseText||'Error inesperado');
        reject();
      });
    });
  }
  PE.fetchPreguntaMeta = fetchPreguntaMeta;

  function fetchQStats(key, meta){
    const scope = {
      division:    $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      form_id:     $('#f_form').val(),
      clase_tipo:  $('#f_tipo').val() || 0,
      desde:       $('#f_desde').val(),
      hasta:       $('#f_hasta').val(),
      distrito:    $('#f_distrito').val(),
      jv:          $('#f_jv').val(),
      usuario:     $('#f_usuario').val(),
      codigo:      $('#f_codigo').val()
    };
    const $box = $(`.qf-stats[data-key="${key}"]`).html('<span class="text-muted">Cargando…</span>');

    $.getJSON('ajax_pregunta_stats.php', {
      mode: meta.mode, id: meta.id, tipo: meta.tipo, csrf_token: $('#csrf_token').val(), ...scope
    }, stat => {
      const payload = stat && stat.data ? stat.data : stat;
      if (!payload){ $box.html('<span class="text-danger">Sin datos</span>'); return; }
      if (meta.tipo === 5 && payload.numeric){
        $box.html(`
          <div class="small">
            <strong>Total:</strong> ${payload.numeric.count} ·
            <strong>Min:</strong> ${payload.numeric.min ?? '-'} ·
            <strong>Max:</strong> ${payload.numeric.max ?? '-'} ·
            <strong>Prom:</strong> ${payload.numeric.avg ?? '-'}
          </div>
        `);
        return;
      }
      const buckets = (payload.buckets || []);
      if (!buckets.length){ $box.html('<span class="text-muted">Sin buckets</span>'); return; }
      const rows = buckets.map(b => `
        <div class="d-flex align-items-center mb-1">
          <div class="flex-grow-1">${PE.escapeHtml(b.label)}</div>
          <div class="text-monospace">${b.count.toLocaleString('es-CL')}</div>
        </div>
      `).join('');
      $box.html(`<div class="small">${rows}</div>`);
    }).fail(xhr => {
      $box.html('<span class="text-danger">Error al cargar</span>');
      PE.showError('No se pudo cargar stats', xhr.responseText||'Error inesperado');
    });
  }

  $(document).on('click', '.qf-stats-refresh', function(){
    const key   = $(this).data('key');
    const entry = QFILTERS.get(key); if (!entry) return;
    fetchQStats(key, entry.meta);
  });

  // Refrescar stats en vivo cuando cambia el ámbito
  $('#f_division, #f_subdivision, #f_form, #f_tipo, #f_desde, #f_hasta, #f_distrito, #f_jv, #f_usuario, #f_codigo')
    .on('change', function(){
      QFILTERS.forEach(({meta}, key) => { fetchQStats(key, meta); });
      PE.renderActiveFilters();
    });

  $('#f_codigo').on('keyup', function(){ PE.renderActiveFilters(); });
  $('#f_qfilters_match').on('change', function(){ PE.renderActiveFilters(); });

  $('#btnResetFilters').on('click', function(){
    window.location.href = 'panel_encuesta.php';
  });

  // ===== Value change handlers =====
  $(document).on('change', '.qf-bool', function(){
    const key  = $(this).data('key');
    const picked = $(`.qf-bool[data-key="${key}"]:checked`).map((_,el) => el.value).get();
    const ent = QFILTERS.get(key); if (!ent) return;
    ent.values = { bool: picked.map(x => parseInt(x,10)) };
  });

  $(document).on('change', '.qf-opts', function(){
    const key  = $(this).data('key');
    const vals = $(this).val() || [];
    const ids = [], texts = [];
    vals.forEach(v => { if(String(v).startsWith('text:')) texts.push(v.slice(5)); else ids.push(parseInt(v,10)); });
    const ent = QFILTERS.get(key); if (!ent) return;
    ent.values = Object.assign({}, ent.values, { opts_ids: ids, opts_texts: texts });
  });

  $(document).on('change', '.qf-mode', function(){
    const key = $(this).data('key');
    const ent = QFILTERS.get(key); if (!ent) return;
    ent.values = Object.assign({}, ent.values, { match: this.value });
  });

  $(document).on('change keyup', '.qf-text-op,.qf-text-val', function(){
    const key = $(this).data('key');
    const ent = QFILTERS.get(key); if (!ent) return;
    const op  = $(`.qf-text-op[data-key="${key}"]`).val();
    const val = $(`.qf-text-val[data-key="${key}"]`).val();
    ent.values = { op, text: val };
  });

  $(document).on('change keyup', '.qf-num-min,.qf-num-max', function(){
    const key = $(this).data('key');
    const ent = QFILTERS.get(key); if (!ent) return;
    const min = parseFloat(($(`.qf-num-min[data-key="${key}"]`).val()||'').replace(',','.'));
    const max = parseFloat(($(`.qf-num-max[data-key="${key}"]`).val()||'').replace(',','.'));
    ent.values = { min: isNaN(min)?null:min, max: isNaN(max)?null:max };
  });

  // ========= collectQFilters =========
  function collectQFilters(){
    const out = [];
    QFILTERS.forEach(({meta, values}) => {
      out.push({ mode: meta.mode, id: meta.id, tipo: meta.tipo, values: values || {} });
    });
    return out;
  }
  PE.collectQFilters = collectQFilters;

})(jQuery);

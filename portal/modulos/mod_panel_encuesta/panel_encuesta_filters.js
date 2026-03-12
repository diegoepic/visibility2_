(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // Map reference — mutations visible to all modules

  // XHR activos por key de pregunta — para cancelar requests obsoletos (A2)
  const statsXhrMap = new Map();

  // Charts activos por key — para destruir antes de redibujar (C1)
  PE.chartMap = PE.chartMap || new Map();

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
      const selIds   = Array.isArray(vals.opts_ids)  ? vals.opts_ids.map(x => parseInt(x,10)) : [];
      const selTexts = Array.isArray(vals.opts_texts) ? vals.opts_texts : [];
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
      const selIds   = Array.isArray(vals.opts_ids)  ? vals.opts_ids.map(x => parseInt(x,10)) : [];
      const selTexts = Array.isArray(vals.opts_texts) ? vals.opts_texts : [];
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
    // Destruir Select2 y Charts existentes antes de vaciar el contenedor
    $box.find('.qf-opts').each(function(){
      if ($(this).data('select2')) { $(this).select2('destroy'); }
    });
    PE.chartMap.forEach((chart) => { try { chart.destroy(); } catch(e){} });
    PE.chartMap.clear();
    $box.empty();

    if (QFILTERS.size === 0){
      $box.html('<small class="text-muted">Añade una o más preguntas y configura el filtro de su respuesta aquí…</small>');
      // Actualizar summary del details
      const $summary = $('#qfilters-summary');
      if ($summary.length) $summary.text('Filtros de preguntas (0)');
      PE.renderActiveFilters();
      return;
    }
    QFILTERS.forEach((entry, key) => {
      $box.append(renderQFilterControl(key, entry));
    });

    // Inicializar Select2 en los selects de opciones
    $box.find('.qf-opts').each(function(){
      $(this).select2({
        placeholder: 'Seleccionar opciones…',
        allowClear: true,
        width: 'resolve',
        dropdownAutoWidth: true
      });
    });

    // Actualizar summary del details
    const $summary = $('#qfilters-summary');
    if ($summary.length) $summary.text(`Filtros de preguntas (${QFILTERS.size})`);

    PE.renderActiveFilters();
    PE.updateZipFotosVisibility();
    $(document).trigger('pe:qfilters-changed'); // B9: notify dirty-filter tracker
  }
  PE.syncQFiltersUI = syncQFiltersUI;

  $(document).on('click', '.qf-remove', function(){
    const key = $(this).data('key');
    QFILTERS.delete(key);
    // Cancelar XHR de stats si estaba en vuelo
    const xhr = statsXhrMap.get(key);
    if (xhr) { xhr.abort(); statsXhrMap.delete(key); }
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

  // ========= STATS con abort de requests anteriores (A2) =========
  function fetchQStats(key, meta){
    // Cancelar request anterior para esta key si existe
    const prevXhr = statsXhrMap.get(key);
    if (prevXhr) { prevXhr.abort(); }

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
    const $box = $(`.qf-stats[data-key="${key}"]`).html('<span class="text-muted"><i class="fa fa-circle-notch fa-spin"></i> Cargando…</span>');

    const xhr = $.ajax({
      url: 'ajax_pregunta_stats.php',
      type: 'GET',
      dataType: 'json',
      data: { mode: meta.mode, id: meta.id, tipo: meta.tipo, csrf_token: $('#csrf_token').val(), ...scope },
      success: function(stat){
        statsXhrMap.delete(key);
        const payload = stat && stat.data ? stat.data : stat;
        if (!payload){ $box.html('<span class="text-danger">Sin datos</span>'); return; }

        // Destruir chart anterior para esta key si existe
        const prevChart = PE.chartMap.get(key);
        if (prevChart) { try { prevChart.destroy(); } catch(e){} PE.chartMap.delete(key); }

        if (meta.tipo === 5 && payload.numeric){
          $box.html(`
            <div class="d-flex flex-wrap gap-2 small">
              <span class="badge badge-light border">Total: <strong>${(+payload.numeric.count).toLocaleString('es-CL')}</strong></span>
              <span class="badge badge-light border">Mín: <strong>${payload.numeric.min ?? '–'}</strong></span>
              <span class="badge badge-light border">Máx: <strong>${payload.numeric.max ?? '–'}</strong></span>
              <span class="badge badge-light border">Prom: <strong>${payload.numeric.avg != null ? (+payload.numeric.avg).toFixed(2) : '–'}</strong></span>
            </div>
          `);
          return;
        }

        const buckets = payload.buckets || [];
        if (!buckets.length){ $box.html('<span class="text-muted">Sin buckets</span>'); return; }

        // Renderizar filas clickeables (C2)
        const rows = buckets.map(b => `
          <div class="d-flex align-items-center mb-1 qf-bucket" data-key="${key}" data-val="${PE.escapeHtml(String(b.val ?? b.label))}" style="cursor:pointer" title="Click para filtrar por este valor">
            <div class="flex-grow-1">${PE.escapeHtml(b.label)}</div>
            <div class="text-monospace text-muted small mr-2">${(+b.count).toLocaleString('es-CL')}</div>
            <i class="fa fa-filter small text-muted"></i>
          </div>
        `).join('');
        $box.html(`<div class="small">${rows}</div>`);

        // Dibujar gráfico inline con Chart.js si disponible (C1)
        if (typeof Chart !== 'undefined' && (meta.tipo === 1 || meta.tipo === 2 || meta.tipo === 3)) {
          const canvasId = 'chart-' + key.replace(/[^a-z0-9]/gi, '_');
          $box.append(`<canvas id="${canvasId}" style="max-height:160px;margin-top:8px"></canvas>`);
          const labels  = buckets.map(b => b.label);
          const data    = buckets.map(b => +b.count);
          const colors  = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'];

          try {
            const chart = new Chart(document.getElementById(canvasId), {
              type: meta.tipo === 1 ? 'doughnut' : 'bar',
              data: {
                labels,
                datasets: [{
                  data,
                  backgroundColor: meta.tipo === 1
                    ? ['#4e79a7','#e15759']
                    : colors.slice(0, data.length),
                  borderWidth: 1,
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: meta.tipo !== 1 ? 'y' : undefined,
                plugins: {
                  legend: { display: meta.tipo === 1, position: 'bottom' }
                },
                scales: meta.tipo !== 1 ? {
                  x: { beginAtZero: true, ticks: { precision: 0 } }
                } : undefined,
              }
            });
            PE.chartMap.set(key, chart);
          } catch(e) { /* Chart.js no disponible */ }
        }
      },
      error: function(xhr, status){
        if (status === 'abort') return; // ignorar aborts
        statsXhrMap.delete(key);
        $box.html('<span class="text-danger">Error al cargar</span>');
        PE.showError('No se pudo cargar stats', xhr.responseText||'Error inesperado');
      }
    });

    statsXhrMap.set(key, xhr);
  }

  $(document).on('click', '.qf-stats-refresh', function(){
    const key   = $(this).data('key');
    const entry = QFILTERS.get(key); if (!entry) return;
    fetchQStats(key, entry.meta);
  });

  // ========= Drill-down: click en bucket aplica filtro (C2) =========
  $(document).on('click', '.qf-bucket', function(){
    const key = $(this).data('key');
    const val = String($(this).data('val'));
    const ent = QFILTERS.get(key);
    if (!ent) return;

    const tipo = ent.meta.tipo;

    if (tipo === 1) {
      // Bool: toggle el valor 0 o 1
      const v = parseInt(val, 10);
      const current = Array.isArray(ent.values.bool) ? ent.values.bool : [];
      const idx = current.indexOf(v);
      ent.values = { bool: idx >= 0 ? current.filter(x => x !== v) : [...current, v] };
    } else if (tipo === 2 || tipo === 3) {
      // Opciones: toggle el option_id o text:valor
      const curIds   = Array.isArray(ent.values.opts_ids)   ? [...ent.values.opts_ids]   : [];
      const curTexts = Array.isArray(ent.values.opts_texts) ? [...ent.values.opts_texts] : [];
      if (val.startsWith('text:')) {
        const t = val.slice(5);
        const i = curTexts.indexOf(t);
        ent.values = Object.assign({}, ent.values, { opts_texts: i >= 0 ? curTexts.filter(x=>x!==t) : [...curTexts, t] });
      } else {
        const id = parseInt(val, 10);
        const i = curIds.indexOf(id);
        ent.values = Object.assign({}, ent.values, { opts_ids: i >= 0 ? curIds.filter(x=>x!==id) : [...curIds, id] });
      }
    }

    syncQFiltersUI();
    PE.renderActiveFilters();
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

(function($){
  // ========= Shared namespace (must load first) =========
  window.PE = {
    QFILTERS: new Map(),
    _applyingPreset: false
  };
  const PE = window.PE;

  const isMC      = window.PEConfig.isMC;
  const USER_ID   = window.PEConfig.USER_ID;
  const USER_DIV  = window.PEConfig.USER_DIV;
  const isRedBull = (USER_DIV === 14);

  const ABS_BASE = window.PEConfig.ABS_BASE || (window.location.origin || (location.protocol + '//' + location.host));
  const DEFAULT_RANGE_DAYS = window.PEConfig.DEFAULT_RANGE_DAYS || 7;
  const EXPORT_LIMITS = { csv: 50000, fotosPdf: 250, fotosHtml: 4000 };

  // Expose config for other modules
  PE.isMC = isMC;
  PE.USER_ID = USER_ID;
  PE.USER_DIV = USER_DIV;
  PE.isRedBull = isRedBull;
  PE.ABS_BASE = ABS_BASE;
  PE.DEFAULT_RANGE_DAYS = DEFAULT_RANGE_DAYS;
  PE.EXPORT_LIMITS = EXPORT_LIMITS;

  // ========= Utilities =========
  PE.escapeHtml = function(s){
    return (s||'').toString().replace(/[&<>"'`=\/]/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[c])
    );
  };

  PE.showError = function(title, msg, isHtml){
    $('#errorModal .modal-title').text(title||'Error');
    if (isHtml) { $('#errorModalMsg').html(msg||'Ha ocurrido un error.'); }
    else         { $('#errorModalMsg').text(msg||'Ha ocurrido un error.'); }
    $('#errorModal').modal('show');
  };

  function showLoading(){
    $('#panel-encuesta-loading').removeClass('d-none');
    $('#panel-encuesta-table-wrapper').addClass('is-loading');
    $('#btnBuscar, #btnCSV, #btnCSVRaw, #btnPDF, #btnFotosHTML, #btnZIPFotos').prop('disabled', true);
  }

  function hideLoading(){
    $('#panel-encuesta-loading').addClass('d-none');
    $('#panel-encuesta-table-wrapper').removeClass('is-loading');
    $('#btnBuscar, #btnCSV, #btnCSVRaw, #btnPDF, #btnFotosHTML, #btnZIPFotos').prop('disabled', false);
  }

  // Prefill suave: últimos DEFAULT_RANGE_DAYS días (solo si están vacías)
  function setDefaultDates(){
    const hoy = new Date();
    const hasta = hoy.toISOString().slice(0,10);
    const d = new Date(hoy);
    d.setDate(d.getDate() - (DEFAULT_RANGE_DAYS - 1));
    const desde = d.toISOString().slice(0,10);
    if(!$('#f_desde').val()) $('#f_desde').val(desde);
    if(!$('#f_hasta').val()) $('#f_hasta').val(hasta);
  }
  setDefaultDates();

  // ========= Cascada de filtros de ámbito =========
  $('#f_division').on('change', function(){
    const div = $(this).val();

    $.getJSON('ajax_subdivisiones.php',{ division: div }, function(rows){
      const $s = $('#f_subdivision').empty().append('<option value="0">-- Todas --</option>');
      rows.forEach(r => $s.append(`<option value="${r.id}">${PE.escapeHtml(r.nombre)}</option>`));
    }).fail(xhr => PE.showError('No se pudo cargar subdivisiones', xhr.responseText||'Error inesperado'))
      .always(() => loadCampanas(true));

    $.getJSON('ajax_jefes_por_division.php',{ division: div }, function(rows){
      const $j = $('#f_jv').empty().append('<option value="0">-- Todos --</option>');
      rows.forEach(r => $j.append(`<option value="${r.id}">${PE.escapeHtml(r.nombre)}</option>`));
    }).fail(xhr => PE.showError('No se pudo cargar jefes', xhr.responseText||'Error inesperado'));

    $.getJSON('ajax_distritos_por_division.php',{ division: div }, function(rows){
      const $d = $('#f_distrito').empty().append('<option value="0">-- Todos --</option>');
      rows.forEach(r => $d.append(`<option value="${r.id}">${PE.escapeHtml(r.nombre_distrito)}</option>`));
    }).fail(xhr => PE.showError('No se pudo cargar distritos', xhr.responseText||'Error inesperado'));
  });

  $('#f_subdivision').on('change', () => loadCampanas(true));
  $('#f_tipo').on('change', () => loadCampanas(true));

  function loadCampanas(resetQuestions){
    $.getJSON('ajax_campanas_por_div_sub.php', {
      division: $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      tipo: $('#f_tipo').val()
    }, function(rows){
      const $c = $('#f_form').empty().append('<option value="0">-- Todas --</option>');
      rows.forEach(r => $c.append(`<option value="${r.id}">${PE.escapeHtml(r.nombre)}</option>`));
    }).fail(xhr => PE.showError('No se pudo cargar campañas', xhr.responseText||'Error inesperado'))
      .always(() => {
        if (resetQuestions) {
          $('#f_preguntas').val(null).trigger('change');
          PE.QFILTERS.clear(); PE.syncQFiltersUI();
          PE.initPreguntaSelect2();
        }
      });
  }
  PE.loadCampanas = loadCampanas;

  function isGlobalMode(){
    const v = $('#f_form').val();
    return (v === '0' || v === 0);
  }
  PE.isGlobalMode = isGlobalMode;

  // ========= Select2 Preguntas =========
  function initPreguntaSelect2(){
    if ($('#f_preguntas').data('select2')) { $('#f_preguntas').select2('destroy'); }
    $('#f_preguntas').empty();

    $('#f_preguntas').select2({
      placeholder: 'Buscar preguntas…',
      allowClear: true,
      minimumInputLength: 1,
      width: 'resolve',
      ajax: {
        url: 'ajax_preguntas_lookup.php',
        dataType: 'json',
        delay: 250,
        data: params => ({
          q: params.term || '',
          division: $('#f_division').val(),
          subdivision: $('#f_subdivision').val(),
          tipo: $('#f_tipo').val() || 0,
          form_id: $('#f_form').val(),
          global: isGlobalMode() ? 1 : 0,
          csrf_token: $('#csrf_token').val()
        }),
        processResults: data => ({
          results: (data && data.data ? data.data : data).map(r => ({
            id: r.id,
            text: r.text,
            campana: r.campana || null,
            count: r.count,
            tipo: r.tipo || null,
            mode: r.mode || (isGlobalMode() ? 'set' : 'exact')
          }))
        })
      },
      templateResult: item => {
        if (!item.id) return item.text;
        const c = item.campana ? ` <span class="text-muted">· ${PE.escapeHtml(item.campana)}</span>` : '';
        const k = (item.count!=null) ? ` <span class="text-muted">(${item.count})</span>` : '';
        return $(`<span>${PE.escapeHtml(item.text)}${c}${k}</span>`);
      }
    })
    .on('select2:select', function(e){
      if (PE._applyingPreset) return;
      const d = e.params.data;
      const mode = d.mode || (isGlobalMode() ? 'set' : 'exact');

      let key, metaId;
      if (mode === 'vset') {
        const hash = String(d.id).split(':')[1];
        key = 'vset:'+hash; metaId = hash;
      } else if (mode === 'set') {
        key = 'set:'+d.id; metaId = d.id;
      } else {
        key = 'exact:'+d.id; metaId = d.id;
      }
      if (PE.QFILTERS.has(key)) return;

      PE.fetchPreguntaMeta(mode, metaId).then(meta => {
        PE.QFILTERS.set(key, {
          meta: {
            id: meta.id,
            mode: mode,
            tipo: meta.tipo,
            tipo_texto: meta.tipo_texto,
            label: d.text,
            has_options: meta.has_options,
            options: meta.options || []
          },
          values: {}
        });
        PE.syncQFiltersUI();
      }).catch(() => {
        const $opt = $('#f_preguntas').find(`option[value="${d.id}"]`);
        $opt.prop('selected', false);
        $('#f_preguntas').trigger('change');
      });
    })
    .on('select2:unselect', function(e){
      if (PE._applyingPreset) return;
      const id = String(e.params.data.id);
      PE.QFILTERS.delete('exact:'+id);
      PE.QFILTERS.delete('set:'+id);
      if (id.startsWith('v:')) PE.QFILTERS.delete('vset:'+id.slice(2));
      PE.syncQFiltersUI();
    });

    $(document).trigger('preguntas-ready');
  }
  PE.initPreguntaSelect2 = initPreguntaSelect2;

  // ========= Core data =========
  let page = 1;
  let currentXhr = null;
  let dirtyFilters = false; // B9: track unsaved filter changes
  PE.currentView = 'responses'; // C3: view mode
  PE.lastTotal = 0; // C6: last total for export warnings
  PE.chartMap = new Map(); // C1: chart instances (defined here for cross-module access)

  function buildParams(){
    const formId = $('#f_form').val();

    let qids=[], qset_ids=[], vset_ids=[];
    PE.QFILTERS.forEach(({meta}) => {
      if (meta.mode === 'vset') {
        vset_ids.push(String(meta.id));
      } else if (meta.mode === 'set') {
        qset_ids.push(parseInt(meta.id, 10));
      } else {
        qids.push(parseInt(meta.id, 10));
      }
    });

    return {
      division: $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      form_id: formId,
      tipo: $('#f_tipo').val(),
      desde: $('#f_desde').val(),
      hasta: $('#f_hasta').val(),
      distrito: $('#f_distrito').val(),
      jv: $('#f_jv').val(),
      usuario: $('#f_usuario').val(),
      codigo: $('#f_codigo').val(),
      qids: qids,
      qset_ids: qset_ids,
      vset_ids: vset_ids,
      qfilters: JSON.stringify(PE.collectQFilters()),
      qfilters_match: $('#f_qfilters_match').is(':checked') ? 'any' : 'all',
      csrf_token: $('#csrf_token').val(),
      page: page,
      limit: $('#f_limit').val(),
      facets: 1,
      geo_lat: $('#f_geo_lat').val(),
      geo_lng: $('#f_geo_lng').val(),
      radius_km: $('#f_radius_km').val()
    };
  }
  PE.buildParams = buildParams;

  function renderActiveFilters(){
    const chips = [];
    const getLabel = $sel => $sel.find('option:selected').text();

    if ($('#f_division').length && $('#f_division').val() !== '0') {
      chips.push(`División: ${PE.escapeHtml(getLabel($('#f_division')))}`);
    }
    if ($('#f_subdivision').val() !== '0') {
      chips.push(`Subdivisión: ${PE.escapeHtml(getLabel($('#f_subdivision')))}`);
    }
    if ($('#f_tipo').val() !== '0') {
      chips.push(`Tipo: ${PE.escapeHtml(getLabel($('#f_tipo')))}`);
    }
    if ($('#f_form').val() !== '0') {
      chips.push(`Campaña: ${PE.escapeHtml(getLabel($('#f_form')))}`);
    }

    const preguntaCount = ($('#f_preguntas').val() || []).length;
    if (preguntaCount > 0) {
      chips.push(`Preguntas: ${preguntaCount}`);
    }
    if (PE.QFILTERS.size > 0) {
      const matchLabel = $('#f_qfilters_match').is(':checked') ? 'parciales' : 'todas';
      chips.push(`Filtros: ${PE.QFILTERS.size} (${matchLabel})`);
    }

    const desde = $('#f_desde').val();
    const hasta = $('#f_hasta').val();
    if (desde || hasta) {
      chips.push(`Rango: ${PE.escapeHtml(desde || '...')} → ${PE.escapeHtml(hasta || '...')}`);
    }

    if ($('#f_distrito').val() !== '0') {
      chips.push(`Distrito: ${PE.escapeHtml(getLabel($('#f_distrito')))}`);
    }
    if ($('#f_jv').val() !== '0') {
      chips.push(`Jefe: ${PE.escapeHtml(getLabel($('#f_jv')))}`);
    }
    if ($('#f_usuario').val() !== '0') {
      chips.push(`Usuario: ${PE.escapeHtml(getLabel($('#f_usuario')))}`);
    }
    if ($('#f_codigo').val()) {
      chips.push(`Cód. Local: ${PE.escapeHtml($('#f_codigo').val())}`);
    }

    if (!chips.length) {
      $('#activeFilters').html('Sin filtros activos.');
      return;
    }

    $('#activeFilters').html(chips.map(c => `<span class="filter-chip">${c}</span>`).join(''));
  }
  PE.renderActiveFilters = renderActiveFilters;

  function toQuery(obj){
    const u = new URLSearchParams();
    Object.keys(obj).forEach(k => {
      const v = obj[k];
      if (Array.isArray(v)) {
        v.forEach(x => u.append(k+'[]', x));
      } else if (v !== '' && v != null) {
        u.append(k, v);
      }
    });
    return u.toString();
  }

  function uniqueOptions(rows, idKey, nameKey){
    const map = new Map();
    rows.forEach(r => {
      const id = r[idKey];
      const name = r[nameKey];
      if (id && name) map.set(String(id), name);
    });
    return [...map.entries()].sort((a,b) => a[1].localeCompare(b[1], 'es'));
  }

  function buildPhotoCandidates(path){
    const raw = (path || '').toString().trim();
    if (!raw) return [];
    if (/^https?:\/\//i.test(raw)) return [raw];
    const noSlash = raw.replace(/^\/+/, '');
    const withSlash = '/' + noSlash;
    const base = ABS_BASE;
    const out = [];
    const add = u => { if (u && !out.includes(u)) out.push(u); };

    if (noSlash.startsWith('uploads/')) {
      add(base + '/visibility2/app/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }
    if (noSlash.startsWith('app/')) {
      add(base + '/visibility2/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }
    if (noSlash.startsWith('portal/')) {
      add(base + '/visibility2/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }
    if (noSlash.startsWith('visibility2/')) {
      add(base + '/' + noSlash);
      return out;
    }
    add(base + withSlash);
    return out;
  }

  function groupPhotoRows(rows){
    const out = [];
    const groups = new Map();
    rows.forEach(r => {
      if (r.tipo === 7){
        // A3: null visita_id → each row is unique, don't collapse under a shared "0" key
        const key = r.visita_id
          ? (r.visita_id + '|' + (r.pregunta_id||'0') + '|' + (r.local_id||'0'))
          : ('nv:' + r.id);
        let item = groups.get(key);
        if (!item){
          item = {...r, fotos: []};
          groups.set(key, item);
          out.push(item);
        }
        if (r.respuesta){ item.fotos.push(r.respuesta); }
      } else {
        out.push(r);
      }
    });
    return out;
  }

  const lazyObserver = ('IntersectionObserver' in window)
    ? new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if(entry.isIntersecting){
            const img = entry.target;
            const src = img.getAttribute('data-src');
            if (src){ img.src = src; img.removeAttribute('data-src'); }
            lazyObserver.unobserve(img);
          }
        });
      }, { rootMargin: '200px 0px' })
    : null;

  function renderThumbs(urls){
    if(!urls || !urls.length) return '';
    const badge = (urls.length>1) ? `<span class="thumb-count">${urls.length}</span>` : '';
    const imgs = urls.map(u => {
      const candidates = buildPhotoCandidates(u);
      if (!candidates.length) return '';
      const primary = candidates[0];
      const fallbacks = candidates.slice(1);
      return `<img class="thumb" data-src="${PE.escapeHtml(primary)}" data-full="${PE.escapeHtml(primary)}" data-fallbacks="${PE.escapeHtml(JSON.stringify(fallbacks))}" alt="foto" loading="lazy">`;
    }).join('');
    return `<div class="thumb-wrap">${badge}${imgs}</div>`;
  }

  function populateSelect($sel, options, keepValue=true){
    const isUsuario = $sel.is('#f_usuario');
    // B8: destroy Select2 before modifying options
    if (isUsuario && $sel.data('select2')) { $sel.select2('destroy'); }

    const current = keepValue ? $sel.val() : null;
    $sel.empty().append('<option value="0">-- Todos --</option>');
    options.forEach(([id, name]) => $sel.append(`<option value="${id}">${PE.escapeHtml(name)}</option>`));
    if (keepValue && current && $sel.find(`option[value="${current}"]`).length) $sel.val(current);

    // B2: indicate when list was truncated at 1000 entries
    if (options.length >= 1000) {
      $sel.append('<option disabled>… (lista recortada a 1000)</option>');
    }

    // B8: reinitialize Select2 for user filter
    if (isUsuario) {
      $sel.select2({ placeholder: '-- Todos --', allowClear: true, width: '200px' });
    }
  }

  function buildPager(cur, per, total){
    const $p = $('#pager').empty();
    const pages = Math.max(1, Math.ceil(total/per));
    function li(n, label, disabled, active){
      return `<li class="page-item ${disabled?'disabled':''} ${active?'active':''}">
                <a class="page-link" href="#" data-p="${n}">${label}</a>
              </li>`;
    }
    // B1: first/last + window of pages + "go to" input
    $p.append(li(1, '«', cur<=1, false));
    $p.append(li(Math.max(1,cur-1), '‹', cur<=1, false));
    if (cur > 3) $p.append(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
    const start = Math.max(1, cur-2), end = Math.min(pages, cur+2);
    for(let i=start;i<=end;i++) $p.append(li(i, i, false, i===cur));
    if (cur < pages-2) $p.append(`<li class="page-item disabled"><span class="page-link">…</span></li>`);
    if (pages > 1 && end < pages) $p.append(li(pages, pages, false, false));
    $p.append(li(Math.min(pages,cur+1), '›', cur>=pages, false));
    $p.append(li(pages, '»', cur>=pages, false));

    // "Go to page" input — remove old one first, then inject fresh
    $('#pager-goto').remove();
    if (pages > 5) {
      const $goto = $(`<div id="pager-goto" class="d-inline-flex align-items-center ml-2">
        <input type="number" min="1" max="${pages}" class="form-control form-control-sm" style="width:60px" placeholder="Ir…" id="pagerGoInput">
        <button class="btn btn-sm btn-outline-secondary ml-1" id="pagerGoBtn">Ir</button>
      </div>`);
      $p.after($goto);
      $('#pagerGoBtn').on('click', function(){
        const v = parseInt($('#pagerGoInput').val(), 10);
        if (!isNaN(v) && v >= 1 && v <= pages){ page = v; loadData(); }
      });
      $('#pagerGoInput').on('keydown', function(e){
        if (e.key === 'Enter') { $('#pagerGoBtn').trigger('click'); }
      });
    }
  }

  function validateDates(){
    const d = $('#f_desde').val();
    const h = $('#f_hasta').val();
    if (d && h && d > h) {
      PE.showError('Rango inválido', 'La fecha "Desde" no puede ser mayor a "Hasta".');
      return false;
    }
    return true;
  }

  // C3: view toggle
  $(document).on('click', '#view-toggle button', function(){
    PE.currentView = $(this).data('view');
    $('#view-toggle button').removeClass('active');
    $(this).addClass('active');
  });

  // Render rows in default "per respuesta" mode (B4: data-* for lightbox context)
  function renderTable(rows, tb){
    if (rows.length === 0) {
      tb.html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
      return;
    }
    const frag = document.createDocumentFragment();
    rows.forEach(r => {
      const isFoto = r.tipo === 7;
      const respCell = isFoto
        ? (r.fotos && r.fotos.length ? renderThumbs(r.fotos) : (r.respuesta ? renderThumbs([r.respuesta]) : ''))
        : `<span class="resp-text" title="${PE.escapeHtml(r.respuesta||'')}">${PE.escapeHtml(r.respuesta||'')}</span>`;
      const tr = document.createElement('tr');
      // B4: data-* for lightbox caption
      tr.dataset.localCodigo = r.local_codigo || '';
      tr.dataset.usuario     = r.usuario || '';
      tr.dataset.fecha       = r.fecha || '';
      tr.dataset.pregunta    = r.pregunta || '';
      // A1: escape r.fecha and r.tipo_texto
      tr.innerHTML = `
        <td>${PE.escapeHtml(r.fecha)}</td>
        <td>${PE.escapeHtml(r.campana)}</td>
        <td>${PE.escapeHtml(r.pregunta)}</td>
        <td>${PE.escapeHtml(r.tipo_texto)}</td>
        <td class="resp-cell">${respCell}</td>
        <td>${r.valor!==null ? PE.escapeHtml(String(r.valor)) : ''}</td>
        <td>${PE.escapeHtml(r.local_codigo||'')}</td>
        <td><a href="#" class="local-detalle-link" data-local-id="${r.local_id||0}" data-form-id="${r.form_id||0}" title="Ver detalle del local">${PE.escapeHtml(r.local_nombre||'')}</a></td>
        <td>${PE.escapeHtml(r.direccion||'')}</td>
        <td>${PE.escapeHtml(r.cadena||'')}</td>
        <td>${PE.escapeHtml(r.jefe_venta||'')}</td>
        <td>${PE.escapeHtml(r.usuario||'')}</td>
      `;
      frag.appendChild(tr);
    });
    tb[0].appendChild(frag);
    noteThumbFallbacks();
    $('#resultsTable img.thumb').each(function(){
      if(lazyObserver) lazyObserver.observe(this);
      else { this.src = this.getAttribute('data-src'); this.removeAttribute('data-src'); }
    });
  }

  // C3: render rows grouped by local
  function renderTableGrouped(rows, tb){
    if (rows.length === 0) {
      tb.html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
      return;
    }
    const byLocal = new Map();
    rows.forEach(r => {
      const lid = r.local_id || 0;
      if (!byLocal.has(lid)) {
        byLocal.set(lid, {
          local_id: lid,
          local_codigo: r.local_codigo || '',
          local_nombre: r.local_nombre || '',
          form_id: r.form_id || 0,
          cadena: r.cadena || '',
          direccion: r.direccion || '',
          jefe_venta: r.jefe_venta || '',
          ultima_fecha: r.fecha || '',
          count: 0
        });
      }
      const g = byLocal.get(lid);
      g.count++;
      if ((r.fecha || '') > g.ultima_fecha) { g.ultima_fecha = r.fecha; }
    });
    const frag = document.createDocumentFragment();
    byLocal.forEach(g => {
      const tr = document.createElement('tr');
      tr.style.cursor = 'pointer';
      tr.innerHTML = `
        <td>${PE.escapeHtml(g.local_codigo)}</td>
        <td colspan="2"><a href="#" class="local-detalle-link" data-local-id="${g.local_id}" data-form-id="${g.form_id}">${PE.escapeHtml(g.local_nombre)}</a></td>
        <td colspan="2">${PE.escapeHtml(g.cadena)}</td>
        <td colspan="2">${PE.escapeHtml(g.direccion)}</td>
        <td colspan="2">${PE.escapeHtml(g.jefe_venta)}</td>
        <td>${PE.escapeHtml(g.ultima_fecha)}</td>
        <td colspan="2"><span class="badge badge-secondary">${g.count} respuestas</span></td>
      `;
      frag.appendChild(tr);
    });
    tb[0].appendChild(frag);
  }
  PE.renderTable = renderTable;
  PE.renderTableGrouped = renderTableGrouped;

  // B9: mark filters dirty when user changes any filter (warn before search)
  function markDirty(){
    dirtyFilters = true;
    $('#btnBuscar').removeClass('btn-primary').addClass('btn-warning').html('<i class="fa fa-exclamation-triangle"></i> Buscar');
  }
  function markClean(){
    dirtyFilters = false;
    $('#btnBuscar').removeClass('btn-warning').addClass('btn-primary').html('<i class="fa fa-search"></i> Buscar');
  }
  PE.markDirty = markDirty;

  function loadData(){
    markClean();
    const p = buildParams();
    renderActiveFilters();
    $('#resultsTable tbody').html('<tr><td colspan="12" class="text-center text-muted">Cargando…</td></tr>');
    $('#infoTotal').text('');
    showLoading();

    if (currentXhr) { currentXhr.abort(); }

    currentXhr = $.ajax({
      url: 'panel_encuesta_data.php',
      data: p,
      dataType: 'json',
      timeout: 60000,
      success: function(resp, textStatus, jqXHR){
        if (resp && resp.status && resp.status !== 'ok') {
          const msg = resp.message || 'Error al cargar resultados.';
          PE.showError('No se pudo cargar', msg);
          $('#resultsTable tbody').html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
          hideLoading();
          return;
        }
        PE.lastTotal = resp.total || 0; // C6 + export warnings

        const tb = $('#resultsTable tbody').empty();
        let rows = resp && resp.data ? resp.data : [];
        rows = groupPhotoRows(rows);

        if (PE.currentView === 'locals') {
          renderTableGrouped(rows, tb);
        } else {
          renderTable(rows, tb);
        }

        if (resp.facets) {
          populateSelect($('#f_usuario'),  resp.facets.usuarios.map(x => [x.id, x.nombre]));
          populateSelect($('#f_jv'),       resp.facets.jefes.map(x => [x.id, x.nombre]));
          populateSelect($('#f_distrito'), resp.facets.distritos.map(x => [x.id, x.nombre]));
        } else {
          populateSelect($('#f_usuario'),  uniqueOptions(rows, 'usuario_id', 'usuario'));
          populateSelect($('#f_jv'),       uniqueOptions(rows, 'jefe_venta_id', 'jefe_venta'));
          populateSelect($('#f_distrito'), uniqueOptions(rows, 'distrito_id', 'distrito'));
        }

        buildPager(resp.page, resp.per_page, resp.total);

        const qtime = jqXHR.getResponseHeader('X-QueryTime-ms');
        const meta = resp && resp.meta ? resp.meta : {};
        const extras = [];

        if (qtime) extras.push(`${qtime} ms`);

        if (meta.truncated_total) {
          const maxRows = meta.max_total_rows || 30000;
          extras.push(`cortado a ${Number(maxRows).toLocaleString('es-CL')} registros`);
        }

        if (meta.default_range && Number(meta.default_range.applied || 0) === 1) {
          const days = meta.default_range.days || DEFAULT_RANGE_DAYS;
          extras.push(`⚠️ Rango automático: últimos ${days} días (selecciona una campaña o ajusta fechas para ver más datos)`);
          if (!$('#f_desde').val() && !$('#f_hasta').val()) {
            setDefaultDates();
          }
        }

        if (resp.total > EXPORT_LIMITS.csv) {
          extras.push(`CSV limitado a ${EXPORT_LIMITS.csv.toLocaleString('es-CL')} filas`);
        }

        // B11: include unique visitas/locales from meta.uniq
        const uniq = (meta && meta.uniq) ? meta.uniq : null;
        let infoBase = `Total: ${Number(resp.total).toLocaleString('es-CL')} registros`;
        if (uniq && (uniq.visitas || uniq.locales)) {
          const parts = [];
          if (uniq.visitas) parts.push(`${Number(uniq.visitas).toLocaleString('es-CL')} visitas`);
          if (uniq.locales) parts.push(`${Number(uniq.locales).toLocaleString('es-CL')} locales`);
          infoBase += ' · ' + parts.join(' · ');
        }
        const info = extras.length ? infoBase + ' · ' + extras.join(' · ') : infoBase;
        $('#infoTotal').text(info);

        const qAll = toQuery(p);
        history.replaceState(null, '', location.pathname + '?' + qAll);
        $(document).trigger('pe:data-loaded'); // C5: mapa puede actualizarse
      },
      error: function(xhr, textStatus){
        let msg;
        if (textStatus === 'timeout' || xhr.status === 504) {
          msg = 'El servidor demoró demasiado en responder (timeout). ' +
                'Prueba acotar el rango de fechas, filtrar por campaña específica, ' +
                'usar menos preguntas a la vez o bajar el número de registros por página.';
        } else if (xhr.status === 0) {
          msg = 'No se pudo contactar con el servidor. ' +
                'Puede ser un problema de red o que se haya perdido la conexión.';
        } else {
          msg = xhr.responseText || 'Error al cargar datos';
        }
        $('#resultsTable tbody').html(`<tr><td colspan="12" class="text-center text-danger">${PE.escapeHtml(msg)}</td></tr>`);
        PE.showError('No se pudo cargar datos', msg);
      },
      complete: function(){
        hideLoading();
        currentXhr = null;
      }
    });
  }

  $('#pager').on('click','a', function(e){
    e.preventDefault();
    const p = parseInt($(this).data('p'),10);
    if(!isNaN(p)){ page=p; loadData(); }
  });

  function parseFallbacks(el){
    const raw = el.getAttribute('data-fallbacks');
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch(e){ return []; }
  }

  function applyNextFallback(img){
    const fallbacks = parseFallbacks(img);
    if (!fallbacks.length) return false;
    const idx = parseInt(img.getAttribute('data-fallback-idx') || '0', 10);
    if (idx >= fallbacks.length) return false;
    img.setAttribute('data-fallback-idx', String(idx + 1));
    img.src = fallbacks[idx];
    return true;
  }

  function noteThumbFallbacks(){
    $('#resultsTable img.thumb').each(function(){
      if (this.dataset.fallbackReady === '1') return;
      this.dataset.fallbackReady = '1';
      this.addEventListener('error', () => { applyNextFallback(this); });
    });
  }

  // B9: dirty filter listeners — any change outside a preset apply marks dirty
  const dirtySelectors = '#f_division,#f_subdivision,#f_tipo,#f_form,#f_desde,#f_hasta,#f_distrito,#f_jv,#f_usuario,#f_codigo,#f_qfilters_match';
  $(document).on('change', dirtySelectors, function(){
    if (!PE._applyingPreset) markDirty();
  });
  $('#f_codigo').on('input', function(){ if (!PE._applyingPreset) markDirty(); });
  // Also mark dirty when q-filters change
  $(document).on('pe:qfilters-changed', function(){ if (!PE._applyingPreset) markDirty(); });

  $('#panel-encuesta-filtros').on('submit', function(e){
    e.preventDefault();
    if (!validateDates()) return;
    page = 1;
    loadData();
  });

  $('#f_limit').on('change', function(){ page=1; loadData(); });
  $('#f_codigo').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); page=1; loadData(); } });

  $('#f_thumbsize').on('change', function(){
    const v = $(this).val();
    document.body.classList.remove('thumb-small','thumb-medium','thumb-large');
    document.body.classList.add('thumb-'+v);
  });

  // ====== Lightbox ======
  const LB = { list: [], idx: 0 };
  function openLightbox(items, start){
    LB.list = items || [];
    LB.idx = Math.max(0, Math.min(start||0, LB.list.length-1));
    updateLB();
    $('#photoModal').modal('show');
  }
  function updateLB(){
    if (!LB.list.length) return;
    const item = LB.list[LB.idx] || {};
    const img = $('#photoModalImg');
    img.attr('src', item.primary || '');
    img.attr('data-fallbacks', JSON.stringify(item.fallbacks || []));
    img.attr('data-fallback-idx', '0');
    // B4: show context caption (index + metadata if available)
    let caption = (LB.idx+1)+' / '+LB.list.length;
    if (item.caption) { caption += ' · ' + item.caption; }
    $('#photoModalCaption').text(caption);
    $('#photoModalOpen')
      .attr('href', item.primary || '')
      .toggleClass('d-none', !item.primary);
  }
  function lbPrev(){ if(LB.list.length){ LB.idx = (LB.idx-1+LB.list.length)%LB.list.length; updateLB(); } }
  function lbNext(){ if(LB.list.length){ LB.idx = (LB.idx+1)%LB.list.length; updateLB(); } }

  $('#photoModalImg').on('error', function(){
    const swapped = applyNextFallback(this);
    if (swapped) { $('#photoModalOpen').attr('href', this.src || ''); }
  });

  $(document).on('click', '.thumb', function(){
    const $wrap = $(this).closest('.thumb-wrap');
    // B4: build caption from parent tr data-*
    const $tr = $(this).closest('tr');
    const captionParts = [];
    if ($tr.data('localCodigo') || $tr.attr('data-local-codigo')) captionParts.push($tr.attr('data-local-codigo') || $tr.data('localCodigo'));
    if ($tr.attr('data-usuario')) captionParts.push($tr.attr('data-usuario'));
    if ($tr.attr('data-fecha')) captionParts.push($tr.attr('data-fecha'));
    if ($tr.attr('data-pregunta')) captionParts.push($tr.attr('data-pregunta'));
    const caption = captionParts.filter(Boolean).join(' · ');

    const items = $wrap.find('.thumb').map((i,el) => {
      const fallbacks = parseFallbacks(el);
      return { primary: $(el).data('full'), fallbacks, caption };
    }).get();
    const idx = $wrap.find('.thumb').index(this);
    openLightbox(items, idx);
  });

  // B10: download current lightbox image
  $('#lbDownload').on('click', function(){
    const url = LB.list[LB.idx] && LB.list[LB.idx].primary;
    if (!url) return;
    fetch(url)
      .then(r => r.blob())
      .then(blob => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = url.split('/').pop() || 'foto.jpg';
        a.click();
        URL.revokeObjectURL(a.href);
      })
      .catch(() => window.open(url, '_blank'));
  });
  $('#lbPrev').on('click', lbPrev);
  $('#lbNext').on('click', lbNext);
  $(document).on('keydown', function(e){
    if (!$('#photoModal').hasClass('show')) return;
    if (e.key === 'ArrowLeft') lbPrev();
    if (e.key === 'ArrowRight') lbNext();
    if (e.key === 'Escape') $('#photoModal').modal('hide');
  });

  // C6: restore state from ?state= URL param (after other modules loaded)
  $(document).on('preguntas-ready', function(){
    if (typeof PE.restoreStateFromURL === 'function') {
      PE.restoreStateFromURL().then(restored => {
        if (restored) { page = 1; loadData(); }
        else {
          if (typeof PE.runRedBullAutofill === 'function') {
            PE.runRedBullAutofill().then(applied => { if (applied) { page=1; loadData(); } });
          }
        }
      });
    }
  });

  // PE.initPreguntaSelect2 se llama desde $(function(){}) en panel.php
  // DESPUÉS de cargar todos los módulos, para que 'preguntas-ready' encuentre
  // PE.restoreStateFromURL (presets.js) ya definido.

})(jQuery);

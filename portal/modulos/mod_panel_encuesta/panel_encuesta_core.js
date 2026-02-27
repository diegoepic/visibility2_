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

  const ABS_BASE = (window.location.origin || (location.protocol + '//' + location.host));
  const DEFAULT_RANGE_DAYS = 7;
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

  PE.showError = function(title, msg){
    $('#errorModal .modal-title').text(title||'Error');
    $('#errorModalMsg').html(msg||'Ha ocurrido un error.');
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
        const key = (r.visita_id||'0') + '|' + (r.pregunta_id||'0') + '|' + (r.local_id||'0');
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
    const current = keepValue ? $sel.val() : null;
    $sel.empty().append('<option value="0">-- Todos --</option>');
    options.forEach(([id, name]) => $sel.append(`<option value="${id}">${PE.escapeHtml(name)}</option>`));
    if (keepValue && current && $sel.find(`option[value="${current}"]`).length) $sel.val(current);
  }

  function buildPager(cur, per, total){
    const $p = $('#pager').empty();
    const pages = Math.max(1, Math.ceil(total/per));
    function li(n, label, disabled, active){
      return `<li class="page-item ${disabled?'disabled':''} ${active?'active':''}">
                <a class="page-link" href="#" data-p="${n}">${label}</a>
              </li>`;
    }
    $p.append(li(Math.max(1,cur-1),'Anterior', cur<=1, false));
    const start = Math.max(1, cur-2), end = Math.min(pages, cur+2);
    for(let i=start;i<=end;i++) $p.append(li(i, i, false, i===cur));
    $p.append(li(Math.min(pages,cur+1),'Siguiente', cur>=pages, false));
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

  function loadData(){
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
        const tb = $('#resultsTable tbody').empty();
        let rows = resp && resp.data ? resp.data : [];
        rows = groupPhotoRows(rows);

        if(rows.length===0){
          tb.html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
        } else {
          const frag = document.createDocumentFragment();
          rows.forEach(r => {
            const isFoto = r.tipo === 7;
            const respCell = isFoto
              ? (r.fotos && r.fotos.length ? renderThumbs(r.fotos) : (r.respuesta ? renderThumbs([r.respuesta]) : ''))
              : PE.escapeHtml(r.respuesta || '');
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${r.fecha}</td>
              <td>${PE.escapeHtml(r.campana)}</td>
              <td>${PE.escapeHtml(r.pregunta)}</td>
              <td>${r.tipo_texto}</td>
              <td>${respCell}</td>
              <td>${r.valor!==null ? r.valor : ''}</td>
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
            else {
              this.src = this.getAttribute('data-src');
              this.removeAttribute('data-src');
            }
          });
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

        const infoBase = `Total: ${Number(resp.total).toLocaleString('es-CL')} registros`;
        const info = extras.length ? infoBase + ' · ' + extras.join(' · ') : infoBase;
        $('#infoTotal').text(info);

        const qAll = toQuery(p);
        history.replaceState(null, '', location.pathname + '?' + qAll);
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
    $('#photoModalCaption').text((LB.idx+1)+' / '+LB.list.length);
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
    const items = $wrap.find('.thumb').map((i,el) => {
      const fallbacks = parseFallbacks(el);
      return { primary: $(el).data('full'), fallbacks };
    }).get();
    const idx = $wrap.find('.thumb').index(this);
    openLightbox(items, idx);
  });
  $('#lbPrev').on('click', lbPrev);
  $('#lbNext').on('click', lbNext);
  $(document).on('keydown', function(e){
    if (!$('#photoModal').hasClass('show')) return;
    if (e.key === 'ArrowLeft') lbPrev();
    if (e.key === 'ArrowRight') lbNext();
    if (e.key === 'Escape') $('#photoModal').modal('hide');
  });

})(jQuery);

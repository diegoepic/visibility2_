(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // shared Map reference

  // Límites de exportación (deben coincidir con ExportController)
  const LIMITS = { csv: 50000, fotosHtml: 800, fotosPdf: 250, zip: 500 };

  // ========= Export por POST =========
  function postExport(url, params){
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.target = '_blank';

    Object.entries(params).forEach(([k, v]) => {
      if (Array.isArray(v)) {
        v.forEach(val => {
          const input = document.createElement('input');
          input.type = 'hidden'; input.name = k+'[]'; input.value = String(val);
          form.appendChild(input);
        });
      } else {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = k; input.value = String(v);
        form.appendChild(input);
      }
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  function buildExportParams(){
    const p = PE.buildParams();
    delete p.page;
    delete p.limit;
    delete p.facets; // innecesario en exportación
    return p;
  }

  function warnIfExceeds(limit, label, cb){
    const total = PE.lastTotal || 0;
    if (total > limit) {
      if (!confirm(`Hay ${total.toLocaleString('es-CL')} registros pero el límite de ${label} es ${limit.toLocaleString('es-CL')}.\nSolo se exportarán los primeros ${limit.toLocaleString('es-CL')}.\n¿Continuar?`)) return;
    }
    cb();
  }

  function btnStart($btn, label){
    $btn.prop('disabled', true).data('txt', $btn.html()).html('<i class="fa fa-circle-notch fa-spin"></i> ' + label);
  }
  function btnEnd($btn){
    setTimeout(() => { $btn.prop('disabled', false).html($btn.data('txt')); }, 5000);
  }

  $('#btnCSV').on('click', function(){
    const $btn = $(this);
    warnIfExceeds(LIMITS.csv, 'CSV', () => {
      btnStart($btn, 'Generando…');
      postExport('export_csv_panel_encuesta.php', buildExportParams());
      btnEnd($btn);
    });
  });

  $('#btnCSVRaw').on('click', function(){
    const $btn = $(this);
    warnIfExceeds(LIMITS.csv, 'CSV Raw', () => {
      btnStart($btn, 'Generando…');
      const params = buildExportParams();
      params.raw   = 1;
      postExport('export_csv_panel_encuesta.php', params);
      btnEnd($btn);
    });
  });

  $('#btnFotosHTML').on('click', function(){
    const $btn = $(this);
    warnIfExceeds(LIMITS.fotosHtml, 'Fotos HTML', () => {
      btnStart($btn, 'Generando HTML…');
      const params  = buildExportParams();
      params.output = 'html';
      postExport('export_pdf_panel_encuesta.php', params);
      btnEnd($btn);
    });
  });

  $('#btnPDF').on('click', function(){
    const $btn = $(this);
    warnIfExceeds(LIMITS.fotosPdf, 'Fotos PDF', () => {
      btnStart($btn, 'Generando PDF…');
      const params  = buildExportParams();
      params.output = 'pdf';
      postExport('export_pdf_panel_encuesta_fotos.php', params);
      btnEnd($btn);
    });
  });

  $('#btnZIPFotos').on('click', function(){
    const $btn = $(this);
    warnIfExceeds(LIMITS.zip, 'ZIP Fotos', () => {
      btnStart($btn, 'Generando ZIP…');
      postExport('export_zip_fotos_panel_encuesta.php', buildExportParams());
      btnEnd($btn);
    });
  });

  // Geolocalización: rellenar lat/lng con posición actual del navegador
  $('#btnGeolocate').on('click', function(){
    if (!navigator.geolocation){
      alert('Geolocalización no disponible en este navegador.');
      return;
    }
    const $btn = $(this).prop('disabled', true);
    navigator.geolocation.getCurrentPosition(
      pos => {
        $('#f_geo_lat').val(pos.coords.latitude.toFixed(6));
        $('#f_geo_lng').val(pos.coords.longitude.toFixed(6));
        $btn.prop('disabled', false);
      },
      err => {
        alert('No se pudo obtener la ubicación: ' + err.message);
        $btn.prop('disabled', false);
      },
      { timeout: 10000 }
    );
  });

  // Limpiar filtro geográfico
  $('#btnGeoClear').on('click', function(){
    $('#f_geo_lat, #f_geo_lng').val('');
    $('#f_radius_km').val('');
  });

  // Mostrar/ocultar ZIP Fotos según si hay preguntas tipo foto seleccionadas
  function updateZipFotosVisibility(){
    const hasFoto = Array.from(QFILTERS.values()).some(e => e.meta && e.meta.tipo === 7);
    $('#btnZIPFotos').toggleClass('d-none', !hasFoto);
  }
  PE.updateZipFotosVisibility = updateZipFotosVisibility;

})(jQuery);

(function($){
  const PE       = window.PE;
  const QFILTERS = PE.QFILTERS; // shared Map reference

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
    return p;
  }

  $('#btnCSV').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando...');
    postExport('export_csv_panel_encuesta.php', buildExportParams());
    setTimeout(() => { $btn.prop('disabled', false).text($btn.data('txt')); }, 4000);
  });

  $('#btnFotosHTML').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando HTML...');
    const params  = buildExportParams();
    params.output = 'html';
    postExport('export_pdf_panel_encuesta.php', params);
    setTimeout(() => { $btn.prop('disabled', false).text($btn.data('txt')); }, 4000);
  });

  $('#btnPDF').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando PDF...');
    const params  = buildExportParams();
    params.output = 'pdf';
    postExport('export_pdf_panel_encuesta_fotos.php', params);
    setTimeout(() => { $btn.prop('disabled', false).text($btn.data('txt')); }, 6000);
  });

  $('#btnCSVRaw').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando...');
    const params = buildExportParams();
    params.raw   = 1;
    postExport('export_csv_panel_encuesta.php', params);
    setTimeout(() => { $btn.prop('disabled', false).text($btn.data('txt')); }, 4000);
  });

  $('#btnZIPFotos').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando ZIP...');
    postExport('export_zip_fotos_panel_encuesta.php', buildExportParams());
    setTimeout(() => { $btn.prop('disabled', false).text($btn.data('txt')); }, 8000);
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

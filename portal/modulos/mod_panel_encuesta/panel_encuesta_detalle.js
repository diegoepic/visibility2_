(function($){
  // ── Detalle local modal ─────────────────────────────────────────────
  $(document).on('click', '.local-detalle-link', function(e){
    e.preventDefault();
    const localId = $(this).data('local-id');
    const formId  = $(this).data('form-id');
    const nombre  = $(this).text();
    if (!localId || !formId) return;

    $('#localDetalleModalTitle').text('Detalle: ' + nombre);
    $('#localDetalleModalBody').html('<div class="text-center text-muted py-4"><i class="fa fa-spinner fa-spin"></i> Cargando…</div>');
    $('#localDetalleModal').modal('show');

    $.getJSON('ajax_detalle_local_panel.php', {
      local_id   : localId,
      form_id    : formId,
      csrf_token : $('#csrf_token').val()
    })
    .done(function(resp){
      if (!resp || resp.status !== 'ok' || !resp.data) {
        $('#localDetalleModalBody').html('<div class="alert alert-warning">No se encontró información para este local.</div>');
        return;
      }
      $('#localDetalleModalBody').html(renderLocalDetalle(resp.data, formId));
    })
    .fail(function(){
      $('#localDetalleModalBody').html('<div class="alert alert-danger">Error al cargar el detalle del local.</div>');
    });
  });

  function renderLocalDetalle(d, formId){
    const local = d.local || {};
    const resumen = d.resumen || {};
    const visitas = d.visitas || [];

    let html = `
      <div class="row mb-3">
        <div class="col-md-6">
          <table class="table table-sm table-borderless mb-0">
            <tr><th class="text-muted" style="width:130px">Código</th><td>${escHtml(local.codigo)}</td></tr>
            <tr><th class="text-muted">Nombre</th><td>${escHtml(local.nombre)}</td></tr>
            <tr><th class="text-muted">Dirección</th><td>${escHtml(local.direccion)}</td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <table class="table table-sm table-borderless mb-0">
            <tr><th class="text-muted" style="width:130px">Visitas</th><td>${resumen.visitas_totales||0}</td></tr>
            <tr><th class="text-muted">Último usuario</th><td>${escHtml(resumen.ultima_usuario)}</td></tr>
            <tr><th class="text-muted">Última visita</th><td>${escHtml(resumen.ultima_fecha)}</td></tr>
            ${resumen.distancia_metros!=null?`<tr><th class="text-muted">Distancia GPS</th><td>${resumen.distancia_metros}m</td></tr>`:''}
          </table>
        </div>
      </div>
    `;

    if (visitas.length === 0) {
      html += '<p class="text-muted">Sin visitas registradas en esta campaña.</p>';
      return html;
    }

    html += '<h6 class="border-bottom pb-1 mb-2">Historial de visitas</h6>';
    visitas.forEach(function(v){
      const secuencia = v.secuencia || '';
      const fechaI = v.fecha_inicio ? v.fecha_inicio.substring(0,16) : '—';
      const fechaF = v.fecha_fin   ? v.fecha_fin.substring(0,16)    : '—';
      const estadoLabel = (v.estado_local||[]).map(e=>escHtml(e.estado_gestion)).join(', ') || '—';

      html += `<div class="card mb-2">
        <div class="card-header py-1 px-2 d-flex justify-content-between align-items-center">
          <span><strong>Visita #${secuencia}</strong> &nbsp;
            <small class="text-muted">${fechaI} → ${fechaF}</small>
          </span>
          <span class="badge badge-secondary">${escHtml(estadoLabel)}</span>
        </div>
        <div class="card-body py-1 px-2">
          <small class="text-muted">Usuario: ${escHtml(v.usuario||'—')}</small>`;

      // Respuestas
      const respuestas = v.respuestas || [];
      if (respuestas.length) {
        html += '<ul class="list-unstyled mt-1 mb-0 small">';
        respuestas.forEach(function(r){
          html += `<li><strong>${escHtml(r.question_text)}</strong>: ${escHtml(r.answer_text||'')}</li>`;
        });
        html += '</ul>';
      }

      html += '</div></div>';
    });

    // Link a gestión completa
    html += `<div class="text-right mt-2">
      <a href="../mod_formulario/gestion.php?local_id=${local.id}&form_id=${formId}" target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="fa fa-external-link-alt"></i> Ver gestión completa
      </a>
    </div>`;

    return html;
  }

  function escHtml(s){
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})(jQuery);
(function(){
  const placeholder = '<div class="modal-header bg-primary text-white"><h5 class="modal-title">Detalle</h5><button class="close text-white" data-dismiss="modal">&times;</button></div><div class="modal-body text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Cargando detalle…</p></div>';

  function renderDetalle(data){
    if (!data || data.error) {
      return '<div class="alert alert-danger m-3">No fue posible cargar el detalle.</div>';
    }
    const d = data.detalle || {};
    const loc = d.local || {};
    const resumen = d.resumen || {};
    const impls = d.implementaciones || [];
    const hist = d.historial || [];

    const implRows = impls.map(i=>`
      <tr>
        <td>${i.fechaVisita || ''}</td>
        <td>${i.usuario || ''}</td>
        <td>${i.material || ''}</td>
        <td>${i.estado_gestion || ''}</td>
        <td>${i.valor_propuesto || ''}</td>
        <td>${i.valor_real || ''}</td>
      </tr>`).join('');

    const histRows = hist.map(i=>`
      <tr>
        <td>${i.fechaVisita || ''}</td>
        <td>${i.usuario || ''}</td>
        <td>${i.estado_gestion || ''}</td>
        <td>${i.material || ''}</td>
      </tr>`).join('');

    return `
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">${data.campanaNombre || ''} · ${loc.codigo || ''}</h5>
        <button class="close text-white" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6">
            <p class="mb-1"><strong>Local:</strong> ${loc.nombre || ''}</p>
            <p class="mb-1"><strong>Dirección:</strong> ${loc.direccion || ''}</p>
            <p class="mb-1"><strong>Modo:</strong> ${d.modo || 'sin_datos'}</p>
          </div>
          <div class="col-md-6">
            <p class="mb-1"><strong>Última visita:</strong> ${resumen.ultima_fecha || '—'} (${resumen.ultima_usuario || '—'})</p>
            <p class="mb-1"><strong>Visitas:</strong> ${resumen.visitas_totales || 0}</p>
            <p class="mb-1"><strong>Distancia última gestión:</strong> ${resumen.distancia_metros != null ? resumen.distancia_metros + ' m' : '—'}</p>
          </div>
        </div>
        <hr>
        <h6>Implementaciones</h6>
        <div class="table-responsive"><table class="table table-sm table-striped">
          <thead><tr><th>Fecha</th><th>Usuario</th><th>Material</th><th>Estado</th><th>Plan</th><th>Real</th></tr></thead>
          <tbody>${implRows || '<tr><td colspan="6" class="text-center">Sin implementaciones.</td></tr>'}</tbody>
        </table></div>
        <h6>Historial</h6>
        <div class="table-responsive"><table class="table table-sm table-striped">
          <thead><tr><th>Fecha</th><th>Usuario</th><th>Estado</th><th>Material</th></tr></thead>
          <tbody>${histRows || '<tr><td colspan="4" class="text-center">Sin gestiones.</td></tr>'}</tbody>
        </table></div>
      </div>`;
  }

  window.DetalleLocalModal = {
    open(campanaId, localId){
      $('#detalleLocalContent').html(placeholder);
      $('#detalleLocalModal').modal('show');
      const params = new URLSearchParams({ idCampana: campanaId, idLocal: localId, format:'json' });
      fetch('detalle_local.php?'+params.toString(), { headers: { 'X-CSRF-TOKEN': MAPA_CONFIG.csrf }})
        .then(r=>r.json())
        .then(data=> $('#detalleLocalContent').html(renderDetalle(data)))
        .catch(()=> $('#detalleLocalContent').html('<div class="alert alert-danger m-3">Error cargando detalle.</div>'));
    }
  };
})();

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
    const visitas = d.visitas || [];

    const esc = (str='')=>String(str).replace(/[&<>"']/g, s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]));
    const formatImg = url => {
      if(!url) return '';
      const src = url.startsWith('http') ? url : '/visibility2/app/' + url.replace(/^\/+/, '');
      return `<img src="${esc(src)}" class="rounded mr-1 mb-1" style="width:48px;height:48px;object-fit:cover;cursor:pointer" onclick="$('#photoModalImg').attr('src','${esc(src)}');$('#photoModal').modal('show');">`;
    };
    const isImage = val => /\.(jpe?g|png|gif|webp)$/i.test(val || '');

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

    const visitasCards = visitas.map(v=>{
      const estadoLocal = (v.estado_local||[]).map(c=>`
        <div class="mb-2">
          <strong>${esc(c.estado_gestion || '')}</strong><br>
          ${esc(c.observacion || '')}<br>
          ${c.foto_url ? formatImg(c.foto_url) : ''}
        </div>
      `).join('') || '<p class="text-muted">Sin cambios de estado.</p>';

      const implOkRows = (v.implementaciones_ok||[]).map(imp=>{
        const fotos = (imp.fotos||[]).map(f=>formatImg(f.url)).join('');
        return `<tr>
          <td>${imp.id || ''}</td>
          <td>${esc(imp.material || '')}</td>
          <td>${esc(imp.valor_real || '')}</td>
          <td>${esc(imp.observacion || '')}</td>
          <td>${fotos || '<span class="text-muted">Sin fotos</span>'}</td>
        </tr>`;
      }).join('');

      const implNoRows = (v.implementaciones_no||[]).map(ni=>`
        <tr>
          <td>${esc(ni.material || '')}</td>
          <td>${esc(ni.observacion_no_impl || 'Sin observación')}</td>
        </tr>
      `).join('');

      const respRows = (v.respuestas||[]).map(r=>{
        const ans = r.answer_text || '';
        const ansHtml = isImage(ans)
          ? formatImg(ans.startsWith('/') ? ans : ans)
          : esc(ans).replace(/\n/g,'<br>');
        return `<tr>
          <td>${r.id || ''}</td>
          <td>${esc(r.question_text || '')}</td>
          <td>${ansHtml || '<span class="text-muted">—</span>'}</td>
          <td>${esc(r.created_at || '')}</td>
        </tr>`;
      }).join('');

      const fechaInicio = v.fecha_inicio ? new Date(v.fecha_inicio.replace(' ', 'T')) : null;
      const fechaFin = v.fecha_fin ? new Date(v.fecha_fin.replace(' ', 'T')) : null;
      const fmt = d => d ? d.toLocaleString('es-CL') : '—';

      return `<div class="card mb-3">
        <div class="card-header">
          <strong>Visita #${v.secuencia || ''}</strong> · ${fmt(fechaInicio)} — ${v.fecha_fin ? fmt(fechaFin) : 'Sin fecha de término'}<br>
          <small>Usuario: ${esc(v.usuario || '')}</small> · <small>Coordenadas: ${esc(v.latitud ?? '—')}, ${esc(v.longitud ?? '—')}</small>
        </div>
        <div class="card-body">
          <h6>Estado del local</h6>
          ${estadoLocal}
          <h6>Materiales implementados</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light"><tr><th>ID</th><th>Material</th><th>Valor real</th><th>Obs.</th><th>Fotos</th></tr></thead>
              <tbody>${implOkRows || '<tr><td colspan="5" class="text-center text-muted">No se implementó material.</td></tr>'}</tbody>
            </table>
          </div>
          <h6>Materiales no implementados</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light"><tr><th>Material</th><th>Observación</th></tr></thead>
              <tbody>${implNoRows || '<tr><td colspan="2" class="text-center text-muted">Sin registros.</td></tr>'}</tbody>
            </table>
          </div>
          <h6>Encuesta</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered">
              <thead class="thead-light"><tr><th>ID</th><th>Pregunta</th><th>Respuesta</th><th>Fecha</th></tr></thead>
              <tbody>${respRows || '<tr><td colspan="4" class="text-center text-muted">Sin respuestas.</td></tr>'}</tbody>
            </table>
          </div>
        </div>
      </div>`;
    }).join('');

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
        <h6>Visitas</h6>
        ${visitasCards || '<p class="text-muted">Sin visitas registradas.</p>'}
        <div class="modal fade" id="photoModal" tabindex="-1" role="dialog">
          <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content bg-dark p-2">
              <img id="photoModalImg" class="img-fluid rounded" src="">
            </div>
          </div>
        </div>
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

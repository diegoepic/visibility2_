(function(){
  const placeholder = `
    <div class="modal-header bg-primary text-white">
      <h5 class="modal-title">Detalle</h5>
      <button class="close text-white" data-dismiss="modal" aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body text-center p-5" aria-live="polite">
      <div class="spinner-border text-primary" role="status" aria-label="Cargando"></div>
      <p class="mt-3 mb-0">Cargando detalle…</p>
    </div>`;

  const estadoConfig = {
    implementado_auditado: { label: 'Implementado y auditado', class: 'success', icon: 'check-double' },
    solo_implementado: { label: 'Solo implementado', class: 'warning', icon: 'check' },
    solo_auditoria: { label: 'Solo auditoría', class: 'info', icon: 'search' },
    sin_datos: { label: 'Sin gestiones', class: 'secondary', icon: 'minus' },
  };

  function injectStyles(){
    if (document.getElementById('detalleLocalStyles')) return;
    const style = document.createElement('style');
    style.id = 'detalleLocalStyles';
    style.textContent = `
      .summary-chip { border-radius: 10px; padding: 8px 12px; background:#f5f7fb; }
      .summary-icon { width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; }
      .timeline { position: relative; padding-left: 24px; margin: 0; list-style:none; }
      .timeline::before { content:''; position:absolute; top:0; left:8px; width:2px; height:100%; background:#e9ecef; }
      .timeline-item { position:relative; padding: 0 0 16px 16px; }
      .timeline-point { position:absolute; left:-2px; top:4px; width:10px; height:10px; border-radius:50%; background:#0d6efd; }
      .material-card table img { transition:transform .2s ease; }
      .material-card table img:hover { transform:scale(1.05); }
      .map-shell { min-height:240px; background:linear-gradient(135deg,#f5f7fb,#fff); }
      .section-toggle { cursor:pointer; }
    `;
    document.head.appendChild(style);
  }

  const esc = (str='') => String(str).replace(/[&<>"']/g, s => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'
  }[s]));

  const isImage = val => /\.(jpe?g|png|gif|webp)$/i.test(val || '');

  const formatDistance = meters => {
    if (meters === null || meters === undefined) return '—';
    if (meters >= 1000) return (meters / 1000).toFixed(1) + ' km';
    return meters + ' m';
  };

  const formatDate = (str='') => str || '—';
  const formatValor = valor => {
    if (valor === null || valor === undefined || valor === '') {
      return { html: '', hasValor: false };
    }
    return {
      html: `<span class="badge badge-pill badge-primary">${esc(valor)}</span>`,
      hasValor: true
    };
  };

  const normalizeImgUrl = rawUrl => {
    const trimmed = String(rawUrl || '').trim();
    if (!trimmed) return '';
    if (/^https?:\/\//i.test(trimmed)) return trimmed;

    let path = trimmed
      .replace(/^\.+\//, '') // remove leading ./ or ../
      .replace(/^\/+/, '');

    path = path.replace(/^visibility2\//i, '');

    if (path.startsWith('app/')) return '/visibility2/' + path;

    return '/visibility2/app/' + path;
  };

  const formatImg = url => {
    const src = normalizeImgUrl(url);
    if (!src) return '';
    return `<img src="${esc(src)}" class="rounded mr-1 mb-1" style="width:60px;height:60px;object-fit:cover;cursor:pointer" aria-label="Abrir foto" role="button" onclick="$('#photoModalImg').attr('src','${esc(src)}');$('#photoModal').modal('show');">`;
  };

  function badgeForModo(modo){
    const cfg = estadoConfig[modo] || estadoConfig.sin_datos;
    return `<span class="badge badge-${cfg.class} align-middle" aria-label="Modo: ${esc(cfg.label)}"><i class="fas fa-${cfg.icon} mr-1"></i>${cfg.label}</span>`;
  }

  function buildSummary(resumen, loc, modo){
    return `
      <div class="bg-light border-bottom p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
          <div>
            <p class="text-muted mb-1">${esc(loc.codigo || '')}</p>
            <h5 class="mb-1 d-flex align-items-center">${esc(loc.nombre || '')} <span class="ml-2">${badgeForModo(modo)}</span></h5>
            <p class="mb-1"><i class="fas fa-map-marker-alt text-primary mr-1"></i> ${esc(loc.direccion || '')}</p>
            <p class="mb-0 small text-muted"><i class="fas fa-user-clock mr-1"></i> Última visita: ${esc(resumen.ultima_fecha || '—')} · ${esc(resumen.ultima_usuario || '—')}</p>
          </div>
          <div class="text-right">
            <div class="summary-chip mb-2">
              <div class="d-flex align-items-center">
                <span class="summary-icon bg-primary text-white mr-2"><i class="fas fa-route"></i></span>
                <div>
                  <div class="small text-muted">Distancia última gestión</div>
                  <div data-toggle="tooltip" title="Distancia Haversine entre el local y la última gestión">${formatDistance(resumen.distancia_metros)}</div>
                </div>
              </div>
            </div>
            <div class="summary-chip">
              <div class="d-flex align-items-center">
                <span class="summary-icon bg-secondary text-white mr-2"><i class="fas fa-history"></i></span>
                <div>
                  <div class="small text-muted">Total visitas</div>
                  <div>${resumen.visitas_totales || 0}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row mt-3">
          ${['Local','Dirección','Modo','Última visita','Distancia'].map((label, idx) => {
            const values = [loc.nombre || '—', loc.direccion || '—', (estadoConfig[modo]?.label)||modo, resumen.ultima_fecha || '—', formatDistance(resumen.distancia_metros)];
            const icons = ['store','map-signs','layer-group','calendar-day','ruler-combined'];
            const colors = ['primary','secondary','info','success','warning'];
            return `<div class="col-6 col-md-4 col-lg-2 mb-2">
              <div class="summary-chip h-100">
                <div class="d-flex align-items-center">
                  <span class="summary-icon bg-${colors[idx]} text-white mr-2"><i class="fas fa-${icons[idx]}"></i></span>
                  <div>
                    <div class="small text-muted">${label}</div>
                    <div class="font-weight-semibold">${esc(values[idx])}</div>
                  </div>
                </div>
              </div>
            </div>`;
          }).join('')}
        </div>
      </div>`;
  }

  function buildTables(implementaciones, historial){
    const implRows = implementaciones.map(i=>`
      <tr>
        <td>${formatDate(i.fechaVisita)}</td>
        <td>${esc(i.usuario || '')}</td>
        <td>${esc(i.material || '')}</td>
        <td><span class="badge badge-light">${esc(i.estado_gestion || '')}</span></td>
        <td>${esc(i.valor_propuesto ?? '')}</td>
        <td>${esc(i.valor_real ?? '')}</td>
      </tr>`).join('');

    const histRows = historial.map(i=>`
      <tr>
        <td>${formatDate(i.fechaVisita)}</td>
        <td>${esc(i.usuario || '')}</td>
        <td><span class="badge badge-info">${esc(i.estado_gestion || '')}</span></td>
        <td>${esc(i.material || '')}</td>
      </tr>`).join('');

    const accordionId = 'implAccordion';

    return `
      <div class="card mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center"><i class="fas fa-toolbox text-primary mr-2"></i><span>Implementaciones e historial</span></div>
          <div>
            <button class="btn btn-outline-secondary btn-sm" data-toggle="collapse" data-target="#implSection" aria-expanded="true" aria-controls="implSection">Mostrar/ocultar</button>
          </div>
        </div>
        <div id="implSection" class="collapse show" data-parent="#${accordionId}">
          <div class="card-body" id="${accordionId}">
            <div class="accordion" id="implHistAccordion">
              <div class="card">
                <div class="card-header" id="headingImpl">
                  <h2 class="mb-0">
                    <button class="btn btn-link" type="button" data-toggle="collapse" data-target="#implTabla" aria-expanded="true" aria-controls="implTabla">Implementaciones</button>
                  </h2>
                </div>
                <div id="implTabla" class="collapse show" aria-labelledby="headingImpl" data-parent="#implHistAccordion">
                  <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:220px;overflow:auto;">
                      <table class="table table-sm table-striped mb-0">
                        <thead class="thead-light"><tr><th>Fecha</th><th>Usuario</th><th>Material</th><th>Estado</th><th>Plan</th><th>Real</th></tr></thead>
                        <tbody>${implRows || '<tr><td colspan="6" class="text-center text-muted">Sin implementaciones.</td></tr>'}</tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
              <div class="card">
                <div class="card-header" id="headingHist">
                  <h2 class="mb-0">
                    <button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#histTabla" aria-expanded="false" aria-controls="histTabla">Historial</button>
                  </h2>
                </div>
                <div id="histTabla" class="collapse" aria-labelledby="headingHist" data-parent="#implHistAccordion">
                  <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:220px;overflow:auto;">
                      <table class="table table-sm table-striped mb-0">
                        <thead class="thead-light"><tr><th>Fecha</th><th>Usuario</th><th>Estado</th><th>Material</th></tr></thead>
                        <tbody>${histRows || '<tr><td colspan="4" class="text-center text-muted">Sin gestiones.</td></tr>'}</tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`;
  }

  function buildTimeline(historial){
    if (!historial.length) return '<p class="text-muted mb-0">Sin cambios de estado.</p>';
    return `<ul class="timeline mb-0">
      ${historial.slice(0,8).map(item=>`<li class="timeline-item">
        <span class="timeline-point"></span>
        <div class="small text-muted">${formatDate(item.fechaVisita)}</div>
        <div class="font-weight-semibold">${esc(item.estado_gestion || '')}</div>
        <div class="text-muted">${esc(item.usuario || '—')} · ${esc(item.material || '')}</div>
      </li>`).join('')}
    </ul>`;
  }

  function buildMaterials(visitas){
    const ok = []; const no = [];
    visitas.forEach(v => {
      (v.implementaciones_ok || []).forEach(imp => ok.push({
        material: imp.material,
        valor_real: imp.valor_real,
        observacion: imp.observacion,
        fotos: imp.fotos || [],
        visita: v.secuencia
      }));
      (v.implementaciones_no || []).forEach(imp => no.push({
        material: imp.material,
        observacion: imp.observacion_no_impl,
        visita: v.secuencia
      }));
    });

    const total = ok.length + no.length;
    const progress = total ? Math.round((ok.length / total) * 100) : 0;

    const okRows = ok.map(imp=>`<tr>
      <td>${esc(imp.material || '')}</td>
      <td>${esc(imp.valor_real ?? '')}</td>
      <td>${esc(imp.observacion || '')}</td>
      <td>${esc(imp.visita || '')}</td>
      <td>${imp.fotos.length ? imp.fotos.map(f=>formatImg(f.url)).join('') : '<span class="text-muted">Sin fotos</span>'}</td>
    </tr>`).join('');

    const noRows = no.map(imp=>`<tr>
      <td>${esc(imp.material || '')}</td>
      <td>${esc(imp.observacion || '')}</td>
      <td>${esc(imp.visita || '')}</td>
    </tr>`).join('');

    return `
      <div class="card material-card mb-3">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
          <div><i class="fas fa-box-open text-primary mr-2"></i>Materiales</div>
          <div class="w-50" aria-label="Progreso de materiales">
            <div class="small text-muted">Implementados ${ok.length}/${total || 0}</div>
            <div class="progress" style="height:8px;" role="progressbar" aria-valuenow="${progress}" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar bg-success" style="width:${progress}%"></div>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <h6>Implementados</h6>
              <div class="table-responsive" style="max-height:260px;overflow:auto;">
                <table class="table table-sm table-bordered mb-0">
                  <thead class="thead-light"><tr><th>Material</th><th>Valor</th><th>Obs.</th><th>Visita</th><th>Fotos</th></tr></thead>
                  <tbody>${okRows || '<tr><td colspan="5" class="text-center text-muted">Sin implementaciones.</td></tr>'}</tbody>
                </table>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <h6>No implementados</h6>
              <div class="table-responsive" style="max-height:260px;overflow:auto;">
                <table class="table table-sm table-bordered mb-0">
                  <thead class="thead-light"><tr><th>Material</th><th>Observación</th><th>Visita</th></tr></thead>
                  <tbody>${noRows || '<tr><td colspan="3" class="text-center text-muted">Sin pendientes.</td></tr>'}</tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>`;
  }

  function buildEncuesta(visitas){
    const rows = [];
    visitas.forEach(v => {
      (v.respuestas || []).forEach(r => {
        const ans = r.answer_text || '';
        const isYesNo = ['SI','NO','Si','No','sí','no'].includes(ans);
        const badge = isYesNo ? `<span class="badge badge-${ans.toLowerCase().startsWith('s') ? 'success' : 'danger'}">${esc(ans)}</span>` : '';
        const ansHtml = isImage(ans) ? formatImg(ans.startsWith('/') ? ans : ans) : (badge || esc(ans).replace(/\n/g,'<br>'));
        const valor = formatValor(r.valor);
        rows.push({
          id: r.id,
          question: r.question_text,
          answer: ansHtml || '<span class="text-muted">—</span>',
          valorHtml: valor.html,
          hasValor: valor.hasValor,
          fecha: r.created_at,
          visita: v.secuencia
        });
      });
    });

    const renderTable = (dataRows, opts = {}) => {
      const { scrollable = true } = opts;
      const tableBody = dataRows.map(r=>`<tr>
        <td>${r.visita}</td>
        <td>${esc(r.question || '')}</td>
        <td>
          <div class="d-flex flex-column flex-sm-row flex-wrap">
            <div class="mr-sm-2 mb-1 mb-sm-0">${r.answer}</div>
            ${r.hasValor ? `<div class="d-inline-flex align-items-center">${r.valorHtml}</div>` : ''}
          </div>
        </td>
        <td>${esc(r.fecha || '')}</td>
      </tr>`).join('');

      const wrapperStyle = scrollable ? 'style="max-height:60vh;overflow:auto;"' : '';
      return `
        <div class="table-responsive" ${wrapperStyle}>
          <table class="table table-sm table-striped mb-0">
            <thead class="thead-light"><tr><th>Visita</th><th>Pregunta</th><th>Respuesta</th><th>Fecha</th></tr></thead>
            <tbody>${tableBody || '<tr><td colspan="4" class="text-center text-muted">Sin respuestas.</td></tr>'}</tbody>
          </table>
        </div>`;
    };

    const compactTable = renderTable(rows, { scrollable: true });
    const fullTable = renderTable(rows, { scrollable: false });
    const collapseId = 'encuestaCollapse';
    const fullModalId = 'encuestaFullModal';

    return `
      <div class="card mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <div><i class="fas fa-clipboard-check text-primary mr-2"></i>Encuesta</div>
          <div class="btn-group" role="group" aria-label="Acciones de encuesta">
            <button class="btn btn-outline-secondary btn-sm" data-toggle="collapse" data-target="#${collapseId}" aria-expanded="true" aria-controls="${collapseId}">Mostrar / ocultar</button>
            <button class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#${fullModalId}" ${rows.length ? '' : 'disabled'}>
              Ver en pantalla completa
            </button>
          </div>
        </div>
        <div id="${collapseId}" class="collapse show">
          <div class="card-body p-0">
            ${compactTable}
          </div>
        </div>
      </div>
      <div class="modal fade" id="${fullModalId}" tabindex="-1" role="dialog" aria-labelledby="${fullModalId}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="${fullModalId}Label"><i class="fas fa-clipboard-check text-primary mr-2"></i>Encuesta completa</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              ${fullTable}
            </div>
          </div>
        </div>
      </div>`;
  }

  function buildVisitas(visitas){
    if (!visitas.length) return '<p class="text-muted">Sin visitas registradas.</p>';

    return visitas.map((v, idx)=>{
      const estadoLocal = (v.estado_local||[]).map(c=>`
        <div class="mb-2">
          <strong>${esc(c.estado_gestion || '')}</strong><br>
          ${esc(c.observacion || '')}<br>
          ${c.foto_url ? formatImg(c.foto_url) : ''}
        </div>`).join('') || '<p class="text-muted">Sin cambios de estado.</p>';

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
        </tr>`).join('');

      const fechaInicio = v.fecha_inicio ? new Date(v.fecha_inicio.replace(' ', 'T')) : null;
      const fechaFin = v.fecha_fin ? new Date(v.fecha_fin.replace(' ', 'T')) : null;
      const fmt = d => d ? d.toLocaleString('es-CL') : '—';
      const collapseId = `visitDetails-${idx}`;

      return `<div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div>
            <strong>Visita #${v.secuencia || ''}</strong> · ${fmt(fechaInicio)} — ${v.fecha_fin ? fmt(fechaFin) : 'Sin fecha de término'}<br>
            <small>Usuario: ${esc(v.usuario || '')}</small> · <small>Coordenadas: ${esc(v.latitud ?? '—')}, ${esc(v.longitud ?? '—')}</small>
          </div>
          <button class="btn btn-outline-primary btn-sm" data-toggle="collapse" data-target="#${collapseId}" aria-expanded="false" aria-controls="${collapseId}">Ver más</button>
        </div>
        <div id="${collapseId}" class="collapse ${idx === 0 ? 'show' : ''}">
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
          </div>
        </div>
      </div>`;
    }).join('');
  }

  function buildMiniMapCard(resumen){
    return `
      <div class="card mb-3" aria-label="Mapa de local y última gestión">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <div><i class="fas fa-map-marked-alt text-primary mr-2"></i>Mapa</div>
          <span class="badge badge-light" data-toggle="tooltip" title="Se muestran el local y la última gestión">2 marcadores</span>
        </div>
        <div class="map-shell" id="detalleLocalMap"></div>
        <div class="card-body py-2">
          <small class="text-muted">Arrastra para mover el mapa, usa scroll para zoom.</small>
        </div>
      </div>`;
  }

  function buildLayout(data){
    const d = data.detalle || {};
    const loc = d.local || {};
    const resumen = d.resumen || {};
    const impls = d.implementaciones || [];
    const hist = d.historial || [];
    const visitas = d.visitas || [];
    const visitasFinalizadas = visitas.filter(v => !!v.fecha_fin);
    const flags = d.flags || {};

    const soloImplementacion = flags.has_impl_any && !flags.has_audit;
    const soloAuditoria = flags.has_audit && !flags.has_impl_any;

    const mostrarImplementacion = !soloAuditoria;
    const mostrarEncuesta = !soloImplementacion;

    injectStyles();

    const columnaMapa = `
      ${buildMiniMapCard(resumen)}
      <div class="card mb-3">
        <div class="card-header bg-white"><i class="fas fa-stream text-primary mr-2"></i>Estado del local</div>
        <div class="card-body">
          ${buildTimeline(hist)}
        </div>
      </div>`;

    const columnaImplementacion = `
      ${buildTables(impls, hist)}
      <h5 class="mt-4 mb-3 text-center"><i class="fas fa-walking text-primary mr-2"></i>Visitas finalizadas</h5>
      ${buildVisitas(visitasFinalizadas)}
      ${buildMaterials(visitasFinalizadas)}
    `;

    const columnaEncuesta = `
      <h5 class="mt-4 mb-3"><i class="fas fa-walking text-primary mr-2"></i>Visitas finalizadas</h5>
      ${buildVisitas(visitasFinalizadas)}
      ${buildEncuesta(visitasFinalizadas)}
    `;

    let contenido;

    if (soloImplementacion) {
      contenido = `
        <div class="row">
          <div class="col-12">
            ${columnaImplementacion}
          </div>
        </div>`;
    } else if (soloAuditoria) {
      contenido = `
        <div class="row">
          <div class="col-lg-8">
            ${columnaEncuesta}
          </div>
          <div class="col-lg-4">
            ${columnaMapa}
          </div>
        </div>`;
    } else {
      contenido = `
        <div class="row">
          <div class="col-lg-8">
            ${columnaImplementacion}
            ${mostrarEncuesta ? buildEncuesta(visitasFinalizadas) : ''}
          </div>
          <div class="col-lg-4">
            ${columnaMapa}
            ${mostrarImplementacion ? buildMaterials(visitasFinalizadas) : ''}
          </div>
        </div>`;
    }

    return `
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">${esc(data.campanaNombre || '')} · ${esc(loc.codigo || '')}</h5>
        <button class="close text-white" data-dismiss="modal" aria-label="Cerrar">&times;</button>
      </div>
      <div class="modal-body p-0">
        ${buildSummary(resumen, loc, d.modo || 'sin_datos')}
        <div class="p-3">
          ${contenido}
        </div>
        <div class="modal fade" id="photoModal" tabindex="-1" role="dialog" aria-label="Visor de fotos">
          <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content bg-dark p-2">
              <img id="photoModalImg" class="img-fluid rounded" src="" alt="Foto de gestión">
            </div>
          </div>
        </div>
      </div>`;
  }

  function renderMiniMap(detalle){
    const mapEl = document.getElementById('detalleLocalMap');
    if (!mapEl) return;
    const locLat = parseFloat(detalle?.local?.lat);
    const locLng = parseFloat(detalle?.local?.lng);
    const lastLat = parseFloat(detalle?.resumen?.ultima_lat);
    const lastLng = parseFloat(detalle?.resumen?.ultima_lng);

    if (Number.isNaN(locLat) || Number.isNaN(locLng) || typeof google === 'undefined' || !google.maps) {
      mapEl.innerHTML = '<div class="p-3 text-center text-muted">No hay coordenadas o el mapa no está disponible.</div>';
      return;
    }

    const bounds = new google.maps.LatLngBounds();
    const map = new google.maps.Map(mapEl, {
      center: { lat: locLat, lng: locLng },
      zoom: 14,
      gestureHandling: 'greedy',
      streetViewControl: false,
      mapTypeControl: false,
    });

    const localMarker = new google.maps.Marker({
      position: { lat: locLat, lng: locLng },
      map,
      label: 'L',
      title: 'Local',
    });
    bounds.extend(localMarker.getPosition());

    if (!Number.isNaN(lastLat) && !Number.isNaN(lastLng)) {
      const lastMarker = new google.maps.Marker({
        position: { lat: lastLat, lng: lastLng },
        map,
        label: 'G',
        title: 'Última gestión',
        icon: { url: 'https://maps.gstatic.com/mapfiles/ms2/micons/green-dot.png' }
      });
      bounds.extend(lastMarker.getPosition());

      new google.maps.Polyline({
        path: [localMarker.getPosition(), lastMarker.getPosition()],
        map,
        strokeColor: '#0d6efd',
        strokeOpacity: 0.7,
        strokeWeight: 3,
      });
    }

    map.fitBounds(bounds);
  }

  function activateInteractions(data){
    $('[data-toggle="tooltip"]').tooltip();
    setTimeout(()=> renderMiniMap(data.detalle || {}), 200);
  }

  window.DetalleLocalModal = {
    open(campanaId, localId){
      injectStyles();
      $('#detalleLocalContent').html(placeholder);
      $('#detalleLocalModal').modal('show');
      const params = new URLSearchParams({ idCampana: campanaId, idLocal: localId, format:'json' });
      fetch('detalle_local.php?'+params.toString(), { headers: { 'X-CSRF-TOKEN': MAPA_CONFIG.csrf }})
        .then(r=>r.json())
        .then(data=> {
          $('#detalleLocalContent').html(buildLayout(data));
          activateInteractions(data);
        })
        .catch(()=> $('#detalleLocalContent').html('<div class="alert alert-danger m-3">Error cargando detalle.</div>'));
    }
  };
})();

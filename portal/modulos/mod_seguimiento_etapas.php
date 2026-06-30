<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit();
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf       = $_SESSION['csrf_token'];
$perfilUser = (string)($_SESSION['perfil_nombre'] ?? '');
$esEditor   = strtolower($perfilUser) === 'editor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Seguimiento por Etapas</title>
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

  <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="../dist/css/adminlte.min.css">
  <link rel="stylesheet" href="../plugins/select2/css/select2.min.css">
  <link rel="stylesheet" href="../plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
  <link rel="stylesheet" href="../plugins/sweetalert2-theme-bootstrap-4/bootstrap-4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">

  <style>
    body { background:#f4f6f9; }
    .se-wrap { padding:14px; }
    .kpi-card { border:none; border-radius:10px; color:#fff; }
    .kpi-card .inner { padding:14px 16px; }
    .kpi-card h3 { font-size:1.9rem; margin:0; font-weight:700; }
    .kpi-card p { margin:0; opacity:.9; font-size:.85rem; }
    .kpi-blue { background:#3c8dbc; } .kpi-green{ background:#28a745; }
    .kpi-yellow{ background:#f39c12; } .kpi-red{ background:#dc3545; }
    .kpi-purple{ background:#6f42c1; } .kpi-teal{ background:#20c997; }
    .badge-estado { font-size:.8rem; padding:.35em .6em; }
    .se-thumb { width:90px; height:90px; object-fit:cover; border-radius:6px; border:1px solid #ccc; margin:3px; cursor:pointer; }
    .se-foto-wrap { position:relative; display:inline-block; }
    .se-foto-del { position:absolute; top:4px; right:6px; }
    .timeline-item { border-left:3px solid #3c8dbc; padding:6px 12px; margin-bottom:8px; background:#fff; border-radius:0 6px 6px 0; }
    .etapa-group-title { font-weight:600; margin-top:8px; }
    #tablaEtapas tbody tr { cursor:pointer; }
    .mat-row { display:flex; gap:8px; margin-bottom:6px; }
    .mat-row select { flex:1; }
    .mat-row input { width:110px; }
    /* filtros por columna */
    table tfoot input { width:100%; font-size:.78rem; padding:2px 4px; }
    table tfoot th { padding:4px; }
    /* visor de fotos (lightbox) propio */
    .se-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:20000; display:none; align-items:center; justify-content:center; }
    .se-lightbox img { max-width:92%; max-height:92%; border-radius:6px; box-shadow:0 0 30px rgba(0,0,0,.6); }
    .se-lb-close { position:absolute; top:14px; right:24px; color:#fff; font-size:34px; cursor:pointer; line-height:1; }
    .foto-badge { display:block; font-size:10px; text-align:center; padding:1px 3px; border-radius:3px; margin-top:2px; }
    .foto-badge.ok  { background:#d4edda; color:#155724; }
    .foto-badge.bad { background:#f8d7da; color:#721c24; }
    .view-btn.active { font-weight:600; }
    #mapaEjecucion { width:100%; height:560px; border-radius:8px; }
  </style>
</head>
<body class="hold-transition">
<div class="se-wrap">

  <div class="card card-outline card-primary">
    <div class="card-header">
      <h3 class="card-title"><i class="fas fa-layer-group mr-1"></i> Seguimiento por Etapas</h3>
    </div>
    <div class="card-body">
      <div class="form-row align-items-end">
        <div class="form-group col-md-3">
          <label for="selDivision">División</label>
          <select id="selDivision" class="form-control"><option value="">Todas las divisiones</option></select>
        </div>
        <div class="form-group col-md-6">
          <label for="selCampania">Campaña (Implementación por Etapas)</label>
          <select id="selCampania" class="form-control"><option value="">— Selecciona una campaña —</option></select>
        </div>
        <div class="form-group col-md-3 text-right">
          <a id="btnExcel" class="btn btn-success btn-block disabled" target="_blank" href="#">
            <i class="fas fa-file-excel mr-1"></i> Descargar Excel
          </a>
        </div>
      </div>

      <div class="row" id="kpiRow" style="display:none;">
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-blue"><div class="inner"><h3 id="kpiMateriales">0</h3><p>Materiales</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-teal"><div class="inner"><h3 id="kpiLocales">0</h3><p>Locales</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-blue"><div class="inner"><h3 id="kpiArmados">0</h3><p>Armados</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-teal"><div class="inner"><h3 id="kpiEntregados">0</h3><p>Entregados</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-green"><div class="inner"><h3 id="kpiImplementados">0</h3><p>Implementados</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-green"><div class="inner"><h3 id="kpiRetirados">0</h3><p>Retirados</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-yellow"><div class="inner"><h3 id="kpiParciales">0</h3><p>Parciales</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-red"><div class="inner"><h3 id="kpiPendientes">0</h3><p>Pendientes</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-purple"><div class="inner"><h3 id="kpiApoyos">0</h3><p>Apoyos</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-teal"><div class="inner"><h3 id="kpiCobertura">0%</h3><p>Cobertura uds</p></div></div></div>
        <div class="col-md-2 col-4 mb-2"><div class="kpi-card kpi-red"><div class="inner"><h3 id="kpiFotosFuera">0</h3><p>Fotos fuera de sala</p></div></div></div>
      </div>

      <div id="viewControls" class="btn-group btn-group-sm mb-2" role="group" style="display:none;">
        <button type="button" class="btn btn-primary view-btn active" data-view="material"><i class="fas fa-th-list mr-1"></i>Por material</button>
        <button type="button" class="btn btn-outline-primary view-btn" data-view="sala"><i class="fas fa-store mr-1"></i>Por sala</button>
        <button type="button" class="btn btn-outline-primary view-btn" data-view="mapa"><i class="fas fa-map-marked-alt mr-1"></i>Mapa</button>
        <?php if ($esEditor): ?>
        <button id="btnAgregarLocal" type="button" class="btn btn-outline-success ml-2" disabled><i class="fas fa-store-alt mr-1"></i>Agregar local</button>
        <?php endif; ?>
      </div>

      <!-- Vista por material -->
      <div id="viewMaterial">
        <div class="table-responsive">
          <table id="tablaEtapas" class="table table-bordered table-hover table-sm" style="width:100%">
            <thead class="thead-light">
              <tr>
                <th>Código</th><th>Local</th><th>Comuna</th><th>Material</th><th>Categoría</th><th>Marca</th><th>Ejecutor</th>
                <th>Etapa</th><th>Prop.</th><th>Impl.</th><th>Falta</th><th>Estado</th>
                <th>Fotos</th><th>Apoyos</th><th>Acción</th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot><tr>
              <th>Código</th><th>Local</th><th>Comuna</th><th>Material</th><th>Categoría</th><th>Marca</th><th>Ejecutor</th>
              <th>Etapa</th><th>Prop.</th><th>Impl.</th><th>Falta</th><th>Estado</th>
              <th>Fotos</th><th>Apoyos</th><th></th>
            </tr></tfoot>
          </table>
        </div>
      </div>

      <!-- Vista por sala -->
      <div id="viewSala" style="display:none;">
        <div class="table-responsive">
          <table id="tablaSalas" class="table table-bordered table-hover table-sm" style="width:100%">
            <thead class="thead-light">
              <tr>
                <th>Código</th><th>Local</th><th>Comuna</th><th>Ejecutor(es)</th><th>Materiales</th>
                <th>Completos</th><th>Parciales</th><th>Pendientes</th><th>% Avance</th><th>Estado</th><th>Última gestión</th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot><tr>
              <th>Código</th><th>Local</th><th>Comuna</th><th>Ejecutor(es)</th><th>Materiales</th>
              <th>Completos</th><th>Parciales</th><th>Pendientes</th><th>% Avance</th><th>Estado</th><th>Última gestión</th>
            </tr></tfoot>
          </table>
        </div>
      </div>

      <!-- Vista mapa -->
      <div id="viewMapa" style="display:none;">
        <div id="mapaEjecucion"></div>
        <small class="text-muted d-block mt-1">
          <span class="badge badge-success">&nbsp;&nbsp;</span> Completo &nbsp;
          <span class="badge badge-warning">&nbsp;&nbsp;</span> En avance &nbsp;
          <span class="badge badge-danger">&nbsp;&nbsp;</span> Pendiente
        </small>
      </div>
    </div>
  </div>
</div>

<!-- Visor de fotos (lightbox) -->
<div id="seLightbox" class="se-lightbox"><span class="se-lb-close">&times;</span><img id="seLightboxImg" src="" alt="Foto"></div>

<!-- Modal detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detTitulo">Detalle de material</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body" id="detBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php if ($esEditor): ?>
<!-- Modal agregar local -->
<div class="modal fade" id="modalAgregarLocal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Agregar local a la campaña</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label>Local</label>
          <select id="alLocal" class="form-control"></select>
        </div>
        <div class="form-group">
          <label>Ejecutor</label>
          <select id="alEjecutor" class="form-control"></select>
        </div>
        <div class="form-group">
          <label>Fecha propuesta</label>
          <input type="date" id="alFecha" class="form-control">
        </div>
        <label>Materiales</label>
        <div id="alMateriales"></div>
        <button type="button" class="btn btn-link btn-sm" id="alAddMat"><i class="fas fa-plus"></i> Agregar material</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="alGuardar">Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../plugins/jquery/jquery.min.js"></script>
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../dist/js/adminlte.min.js"></script>
<script src="../plugins/select2/js/select2.full.min.js"></script>
<script src="../plugins/sweetalert2/sweetalert2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script>
const CSRF      = document.querySelector('meta[name="csrf-token"]').content;
const ES_EDITOR = <?php echo $esEditor ? 'true' : 'false'; ?>;
const ETAPAS    = ['armado','entregado','implementado','retirado'];
const ETAPA_LABEL = { armado:'Armado', entregado:'Entregado', implementado:'Implementado', retirado:'Retirado' };
let CATALOGOS = { ejecutores:[], materiales:[], locales:[] };
let CAMPANIA_ACTUAL = 0;
let tabla = null;
let tablaSalas = null;
let CAMPANIAS_ALL = [];
let RESUMEN_DATA = [];
let mapEjecucion = null, mapMarkers = [], mapsLoaded = false, mapInfo = null;
const GMAPS_KEY = 'AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw';

function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function estadoBadge(e){
  const map = { 'Completo':'success','Parcial':'warning','En proceso':'info','Sin iniciar':'secondary' };
  return `<span class="badge badge-${map[e]||'secondary'} badge-estado">${escapeHtml(e)}</span>`;
}
async function postAccion(payload){
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  Object.keys(payload).forEach(k => {
    const v = payload[k];
    if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
    else if (v !== undefined && v !== null) fd.append(k, v);
  });
  const r = await fetch('mod_seguimiento_etapas/ajax_acciones.php', { method:'POST', body:fd, credentials:'same-origin' });
  const js = await r.json().catch(() => ({ ok:false, message:'Respuesta inválida del servidor.' }));
  return js;
}

/* ---------- Carga de campañas + filtro de división ---------- */
async function cargarCampanas(){
  const r = await fetch('mod_seguimiento_etapas/ajax_campanas.php', { credentials:'same-origin' });
  const js = await r.json();
  CAMPANIAS_ALL = (js.ok && Array.isArray(js.data)) ? js.data : [];

  // Construir el filtro de división con las divisiones distintas presentes.
  const divs = {};
  CAMPANIAS_ALL.forEach(c => { if (c.id_division) divs[c.id_division] = c.division_nombre || ('División ' + c.id_division); });
  const $div = $('#selDivision');
  Object.keys(divs).sort((a,b) => (divs[a] > divs[b] ? 1 : -1)).forEach(id => {
    $div.append(`<option value="${id}">${escapeHtml(divs[id])}</option>`);
  });

  renderCampaniaOptions('');
  $div.select2({ theme:'bootstrap4', width:'100%' });
  $('#selCampania').select2({ theme:'bootstrap4', width:'100%' });
}
function renderCampaniaOptions(divId){
  const $sel = $('#selCampania');
  $sel.empty().append('<option value="">— Selecciona una campaña —</option>');
  CAMPANIAS_ALL
    .filter(c => !divId || String(c.id_division) === String(divId))
    .forEach(c => {
      const emp = c.empresa ? `[${escapeHtml(c.empresa)}] ` : '';
      $sel.append(`<option value="${c.id}">${emp}${escapeHtml(c.nombre)} — ${c.pct_completado}% (${c.total_locales} locales)</option>`);
    });
  $sel.val('').trigger('change.select2');
}

/* ---------- Resumen + tablas + mapa ---------- */
async function cargarResumen(idForm){
  CAMPANIA_ACTUAL = idForm;
  if (!idForm) {
    $('#kpiRow').hide(); $('#viewControls').hide();
    if (tabla) tabla.clear().draw();
    if (tablaSalas) tablaSalas.clear().draw();
    $('#btnExcel').addClass('disabled').attr('href','#');
    $('#btnAgregarLocal').prop('disabled', true);
    RESUMEN_DATA = [];
    return;
  }

  const r = await fetch('mod_seguimiento_etapas/ajax_resumen.php?id_formulario=' + encodeURIComponent(idForm), { credentials:'same-origin' });
  const js = await r.json();
  if (!js.ok) { Swal.fire('Error', js.message || 'No se pudo cargar', 'error'); return; }

  const k = js.kpis;
  $('#kpiMateriales').text(k.materiales);
  $('#kpiLocales').text(k.locales);
  $('#kpiArmados').text(k.armados);
  $('#kpiEntregados').text(k.entregados);
  $('#kpiImplementados').text(k.implementados);
  $('#kpiRetirados').text(k.retirados);
  $('#kpiParciales').text(k.parciales);
  $('#kpiPendientes').text(k.pendientes);
  $('#kpiApoyos').text(k.apoyos);
  $('#kpiCobertura').text(`${k.pct_unidades}% (${k.uds_impl}/${k.uds_prop})`);
  $('#kpiFotosFuera').text(k.fotos_fuera);
  $('#kpiRow').show();
  $('#viewControls').show();

  $('#btnExcel').removeClass('disabled').attr('href', '../informes/descargar_excel_seguimiento_etapas.php?id=' + encodeURIComponent(idForm));
  $('#btnAgregarLocal').prop('disabled', false);

  RESUMEN_DATA = js.data || [];

  const rows = RESUMEN_DATA.map(d => ([
    escapeHtml(d.codigo_local), escapeHtml(d.local_nombre), escapeHtml(d.comuna || ''),
    escapeHtml(d.material), escapeHtml(d.categoria || ''), escapeHtml(d.marca || ''), escapeHtml(d.ejecutor || ''),
    escapeHtml(d.etapa_label), d.propuesto, d.implementado, d.faltante,
    estadoBadge(d.estado), d.n_fotos, d.n_apoyos,
    `<button class="btn btn-xs btn-primary btn-ver" data-id="${d.idFQ}"><i class="fas fa-eye"></i> Ver</button>`
  ]));

  if (!tabla) {
    tabla = $('#tablaEtapas').DataTable({
      data: rows, pageLength: 25, order: [[0,'asc']], dom: 'lrtip',
      language: { url:'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
      initComplete: function(){ initColumnFilters(this.api(), '#tablaEtapas', [14]); }
    });
  } else {
    tabla.clear().rows.add(rows).draw();
  }

  renderSalas();
  if ($('#viewMapa').is(':visible')) ensureMapa();
}

/* Filtro de texto por columna: mueve la fila del tfoot al thead y enlaza la búsqueda. */
function initColumnFilters(api, tableSel, skipCols){
  skipCols = skipCols || [];
  const $foot = $(tableSel + ' tfoot tr');
  if ($foot.length && !$(tableSel + ' thead tr.filtros').length) {
    const $clone = $foot.clone().addClass('filtros');
    $clone.find('th').each(function(i){
      if (skipCols.indexOf(i) >= 0) { $(this).html(''); return; }
      $(this).html('<input type="text" placeholder="Filtrar..." />');
    });
    $(tableSel + ' thead').append($clone);
    $(tableSel + ' tfoot').remove();
  }
  api.columns().every(function(){
    const col = this;
    const $inp = $(tableSel + ' thead tr.filtros th').eq(col.index()).find('input');
    if (!$inp.length) return;
    $inp.off('keyup change click')
        .on('click', e => e.stopPropagation())
        .on('keyup change', function(){ if (col.search() !== this.value) col.search(this.value).draw(); });
  });
}

/* ---------- Vista por sala (agregado en cliente) ---------- */
function buildSalasData(){
  const byLocal = {};
  RESUMEN_DATA.forEach(d => {
    const key = d.id_local;
    if (!byLocal[key]) byLocal[key] = {
      codigo:d.codigo_local, local:d.local_nombre, comuna:d.comuna || '',
      lat:d.lat, lng:d.lng, ejecutores:new Set(),
      total:0, completos:0, parciales:0, enProceso:0, sinIniciar:0, ultima:''
    };
    const o = byLocal[key];
    o.total++;
    if (d.ejecutor) o.ejecutores.add(d.ejecutor);
    if (d.estado === 'Completo') o.completos++;
    else if (d.estado === 'Parcial') o.parciales++;
    else if (d.estado === 'En proceso') o.enProceso++;
    else o.sinIniciar++;
    if (d.fechaVisita && d.fechaVisita > o.ultima) o.ultima = d.fechaVisita;
  });
  return Object.values(byLocal).map(o => {
    const pct = o.total > 0 ? Math.round(o.completos * 100 / o.total) : 0;
    let estado = 'Pendiente';
    if (o.completos === o.total && o.total > 0) estado = 'Completo';
    else if (o.completos + o.parciales + o.enProceso > 0) estado = 'En avance';
    return Object.assign(o, { pct, estado, ejecutoresStr:[...o.ejecutores].join(', ') });
  });
}
function salaBadge(e){
  const map = { 'Completo':'success','En avance':'warning','Pendiente':'danger' };
  return `<span class="badge badge-${map[e]||'secondary'} badge-estado">${escapeHtml(e)}</span>`;
}
function renderSalas(){
  const data = buildSalasData();
  const rows = data.map(o => ([
    escapeHtml(o.codigo), escapeHtml(o.local), escapeHtml(o.comuna), escapeHtml(o.ejecutoresStr),
    o.total, o.completos, o.parciales, (o.total - o.completos), o.pct + '%',
    salaBadge(o.estado), escapeHtml(o.ultima || '')
  ]));
  if (!tablaSalas) {
    tablaSalas = $('#tablaSalas').DataTable({
      data: rows, pageLength: 25, order: [[0,'asc']], dom: 'lrtip',
      language: { url:'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json' },
      initComplete: function(){ initColumnFilters(this.api(), '#tablaSalas', []); }
    });
  } else {
    tablaSalas.clear().rows.add(rows).draw();
  }
}

/* ---------- Mapa de ejecución ---------- */
function setView(view){
  $('.view-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
  $(`.view-btn[data-view="${view}"]`).addClass('active btn-primary').removeClass('btn-outline-primary');
  $('#viewMaterial').toggle(view === 'material');
  $('#viewSala').toggle(view === 'sala');
  $('#viewMapa').toggle(view === 'mapa');
  if (view === 'material' && tabla) tabla.columns.adjust();
  if (view === 'sala' && tablaSalas) tablaSalas.columns.adjust();
  if (view === 'mapa') ensureMapa();
}
function ensureMapa(){
  if (window.google && window.google.maps) { mapsLoaded = true; initMapa(); return; }
  if (document.getElementById('gmaps-seg')) return; // ya cargando
  window.__segMapInit = function(){ mapsLoaded = true; initMapa(); };
  const s = document.createElement('script');
  s.id = 'gmaps-seg';
  s.async = true; s.defer = true;
  s.src = 'https://maps.googleapis.com/maps/api/js?key=' + GMAPS_KEY + '&callback=__segMapInit';
  document.body.appendChild(s);
}
function initMapa(){
  const el = document.getElementById('mapaEjecucion');
  if (!el || !window.google) return;
  if (!mapEjecucion) {
    mapEjecucion = new google.maps.Map(el, { center:{lat:-33.45, lng:-70.66}, zoom:11 });
    mapInfo = new google.maps.InfoWindow();
  }
  mapMarkers.forEach(m => m.setMap(null)); mapMarkers = [];
  const color = { 'Completo':'#28a745', 'En avance':'#f39c12', 'Pendiente':'#dc3545' };
  const bounds = new google.maps.LatLngBounds(); let any = false;
  buildSalasData().forEach(o => {
    const lat = parseFloat(o.lat), lng = parseFloat(o.lng);
    if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;
    const pos = { lat, lng };
    const mk = new google.maps.Marker({
      position: pos, map: mapEjecucion, title: `${o.codigo} ${o.local}`,
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 8, fillColor: color[o.estado] || '#6c757d', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 }
    });
    mk.addListener('click', () => {
      mapInfo.setContent(`<div style="min-width:180px"><b>${escapeHtml(o.codigo)} — ${escapeHtml(o.local)}</b><br>${escapeHtml(o.comuna)}<br>Avance: <b>${o.pct}%</b> (${o.completos}/${o.total})<br>Estado: ${escapeHtml(o.estado)}</div>`);
      mapInfo.open(mapEjecucion, mk);
    });
    mapMarkers.push(mk); bounds.extend(pos); any = true;
  });
  google.maps.event.trigger(mapEjecucion, 'resize');
  if (any) { mapEjecucion.fitBounds(bounds); if (mapMarkers.length === 1) mapEjecucion.setZoom(15); }
}

/* ---------- Visor de fotos (lightbox) ---------- */
function verFotoSeguimiento(url){
  $('#seLightboxImg').attr('src', url);
  $('#seLightbox').css('display', 'flex');
}

/* ---------- Catálogos ---------- */
async function cargarCatalogos(idForm){
  const r = await fetch('mod_seguimiento_etapas/ajax_catalogos.php?id_formulario=' + encodeURIComponent(idForm), { credentials:'same-origin' });
  const js = await r.json();
  if (js.ok) CATALOGOS = { ejecutores:js.ejecutores, materiales:js.materiales, locales:js.locales };
}
function ejecutorOptions(selId){ return CATALOGOS.ejecutores.map(e => `<option value="${e.id}" ${String(e.id)===String(selId)?'selected':''}>${escapeHtml(e.nombre)}</option>`).join(''); }
function materialOptions(){ return CATALOGOS.materiales.map(m => `<option value="${escapeHtml(m.nombre)}">${escapeHtml(m.nombre)}</option>`).join(''); }

/* ---------- Detalle ---------- */
async function abrirDetalle(idFQ){
  const r = await fetch('mod_seguimiento_etapas/ajax_detalle_material.php?idFQ=' + encodeURIComponent(idFQ), { credentials:'same-origin' });
  const js = await r.json();
  if (!js.ok) { Swal.fire('Error', js.message || 'No se pudo cargar el detalle', 'error'); return; }

  const m = js.material;
  $('#detTitulo').html(`${escapeHtml(m.material)} — ${escapeHtml(m.local_nombre)} (${escapeHtml(m.codigo_local)})`);

  let acciones = '';
  if (ES_EDITOR) {
    acciones = `
      <div class="btn-group btn-group-sm flex-wrap mb-3" role="group">
        <button class="btn btn-outline-primary act" data-a="editar_gestion" data-toggle="tooltip" title="Corrige etapa, cantidad implementada u observación. El ejecutor verá el material en la etapa/cantidad que dejes; si queda parcial, sigue pendiente en su ruta."><i class="fas fa-edit"></i> Editar gestión</button>
        <button class="btn btn-outline-success act" data-a="forzar_cierre" data-toggle="tooltip" title="Marca el material como completado (100%). Desaparece de la ruta del ejecutor."><i class="fas fa-check-double"></i> Forzar cierre</button>
        <button class="btn btn-outline-warning act" data-a="recargar_material" data-toggle="tooltip" title="Devuelve el material a pendiente y lo reprograma. Vuelve a aparecer en la ruta del ejecutor para gestionarlo otra vez."><i class="fas fa-redo"></i> Reactivar</button>
        <button class="btn btn-outline-info act" data-a="reasignar_usuario" data-toggle="tooltip" title="Cambia el ejecutor responsable. Sale de la ruta del ejecutor actual y aparece en la del nuevo."><i class="fas fa-user"></i> Reasignar</button>
        <button class="btn btn-outline-info act" data-a="reprogramar_fecha" data-toggle="tooltip" title="Cambia la fecha propuesta. El material aparecerá en la ruta del ejecutor en la nueva fecha."><i class="fas fa-calendar"></i> Reprogramar</button>
        <button class="btn btn-outline-purple act" data-a="editar_apoyos" style="color:#6f42c1;border-color:#6f42c1" data-toggle="tooltip" title="Registra o quita ejecutores de apoyo. No cambia la ruta del ejecutor; es informativo y aparece en los reportes."><i class="fas fa-users"></i> Apoyos</button>
        <button class="btn btn-outline-secondary act" data-a="editar_motivo_parcial" data-toggle="tooltip" title="Edita el motivo de implementación parcial (queda en historial/reportes). No cambia lo que ve el ejecutor en su ruta."><i class="fas fa-comment"></i> Motivo</button>
        <button class="btn btn-outline-primary act" data-a="agregar_material" data-toggle="tooltip" title="Agrega un material nuevo a este local para el ejecutor; le aparecerá como pendiente en su ruta."><i class="fas fa-plus"></i> + Material (local)</button>
        <button class="btn btn-outline-danger act" data-a="eliminar_gestion" data-toggle="tooltip" title="Elimina el material de la campaña. Desaparece de la ruta del ejecutor (acción irreversible)."><i class="fas fa-trash"></i> Eliminar</button>
      </div>`;
  }

  // Fotos por etapa
  let fotosHtml = '';
  ETAPAS.forEach(et => {
    const arr = js.fotos_por_etapa[et] || [];
    if (!arr.length) return;
    fotosHtml += `<div class="etapa-group-title">Fotos de ${ETAPA_LABEL[et]}</div><div>`;
    arr.forEach(f => {
      const del = ES_EDITOR ? `<button class="btn btn-xs btn-danger se-foto-del act-foto" data-id="${f.id}"><i class="fas fa-times"></i></button>` : '';
      let badge = '';
      if (f.dist_m !== null && f.dist_m !== undefined) {
        badge = f.fuera_rango
          ? `<span class="foto-badge bad" title="Foto tomada a ${f.dist_m} m del local">⚠ a ${f.dist_m} m</span>`
          : `<span class="foto-badge ok" title="Foto tomada dentro del rango del local">✓ en sala</span>`;
      }
      fotosHtml += `<span class="se-foto-wrap"><img src="${escapeHtml(f.url)}" class="se-thumb" data-full="${escapeHtml(f.url)}">${del}${badge}</span>`;
    });
    fotosHtml += `</div>`;
  });
  if (!fotosHtml) fotosHtml = '<p class="text-muted">Sin fotos registradas.</p>';

  // Apoyos
  let apoyosHtml = js.apoyos.length
    ? '<ul class="list-unstyled mb-0">' + js.apoyos.map(a => `<li><i class="fas fa-user-friends text-muted"></i> ${escapeHtml(a.nombre)} <small class="text-muted">(${escapeHtml(ETAPA_LABEL[a.etapa]||a.etapa||'-')})</small></li>`).join('') + '</ul>'
    : '<p class="text-muted">Sin apoyos registrados.</p>';

  // Timeline
  let tlHtml = js.timeline.length
    ? js.timeline.map(t => `
        <div class="timeline-item">
          <strong>${escapeHtml(ETAPA_LABEL[t.etapa]||t.etapa||'-')}</strong>
          ${t.valor!=null && t.valor!=='' ? ` — cantidad: ${escapeHtml(t.valor)}` : ''}
          <div><small class="text-muted">${escapeHtml(t.fecha)} · ${escapeHtml(t.ejecutor||'')}</small></div>
          ${t.motivo ? `<div><small><b>Motivo:</b> ${escapeHtml(t.motivo)}</small></div>` : ''}
          ${t.observacion ? `<div><small><b>Obs:</b> ${escapeHtml(t.observacion)}</small></div>` : ''}
        </div>`).join('')
    : '<p class="text-muted">Sin historial.</p>';

  $('#detBody').html(`
    ${acciones}
    <div class="row">
      <div class="col-md-4">
        <p><b>Categoría:</b> ${escapeHtml(m.categoria||'-')} · <b>Marca:</b> ${escapeHtml(m.marca||'-')}</p>
        <p><b>Ejecutor:</b> ${escapeHtml(m.ejecutor||'-')}</p>
        <p><b>Etapa actual:</b> ${escapeHtml(ETAPA_LABEL[m.etapa_material]||'Sin iniciar')}</p>
        <p><b>Propuesto:</b> ${m.propuesto} · <b>Implementado:</b> ${m.implementado} · <b>Falta:</b> ${m.faltante}</p>
        <hr><h6>Apoyos</h6>${apoyosHtml}
      </div>
      <div class="col-md-4"><h6>Fotos por etapa</h6>${fotosHtml}</div>
      <div class="col-md-4"><h6>Historial</h6>${tlHtml}</div>
    </div>`);

  $('#detBody').data('material', m);
  $('#detBody [data-toggle="tooltip"]').tooltip({ boundary:'window', trigger:'hover' });
  $('#modalDetalle').modal('show');
}

/* ---------- Acciones (editor) ---------- */
async function ejecutarAccion(a, m){
  const idFQ = m.id;
  if (a === 'recargar_material') {
    const hoy = new Date().toISOString().slice(0,10);
    const { value: vals } = await Swal.fire({
      title:'Reactivar material',
      html:`<p class="text-muted" style="font-size:13px">Volverá a aparecer pendiente para el ejecutor.</p>
            <label class="d-block text-left">Reactivar como</label>
            <select id="sw_et" class="form-control">
              <option value="">Sin iniciar</option>
              <option value="armado">Armado</option>
              <option value="entregado">Entregado</option>
            </select>
            <label class="d-block text-left mt-2">Fecha propuesta</label>
            <input id="sw_fp" type="date" class="form-control" value="${hoy}">`,
      showCancelButton:true, confirmButtonText:'Reactivar',
      preConfirm: () => ({ et: document.getElementById('sw_et').value, fp: document.getElementById('sw_fp').value })
    });
    if (!vals) return;
    return finalizar(await postAccion({ action:'recargar_material', idFQ, etapa_destino:vals.et, fechaPropuesta:vals.fp }));
  }
  if (a === 'forzar_cierre') {
    const { value: et } = await Swal.fire({
      title:'Forzar cierre',
      html:`<label class="d-block text-left">Cerrar como</label>
            <select id="sw_fc" class="form-control"><option value="implementado">Implementado</option><option value="retirado">Retirado</option></select>`,
      showCancelButton:true, confirmButtonText:'Cerrar material',
      preConfirm: () => document.getElementById('sw_fc').value
    });
    if (!et) return;
    return finalizar(await postAccion({ action:'forzar_cierre', idFQ, etapa:et }));
  }
  if (a === 'reprogramar_fecha') {
    const hoy = new Date().toISOString().slice(0,10);
    const { value: vals } = await Swal.fire({
      title:'Reprogramar fecha',
      html:`<label class="d-block text-left">Nueva fecha propuesta</label><input id="sw_fp" type="date" class="form-control" value="${hoy}">`,
      showCancelButton:true, confirmButtonText:'Guardar',
      preConfirm: () => ({ fp: document.getElementById('sw_fp').value })
    });
    if (!vals || !vals.fp) return;
    return finalizar(await postAccion({ action:'reprogramar_fecha', idFQ, fechaPropuesta:vals.fp }));
  }
  if (a === 'reasignar_usuario') {
    const opts = CATALOGOS.ejecutores.map(e => `<option value="${e.id}" ${String(e.id)===String(m.id_usuario)?'selected':''}>${escapeHtml(e.nombre)}</option>`).join('');
    const { value: u } = await Swal.fire({
      title:'Reasignar ejecutor',
      html:`<label class="d-block text-left">Nuevo ejecutor</label><select id="sw_u" class="form-control">${opts}</select>`,
      showCancelButton:true, confirmButtonText:'Reasignar',
      preConfirm: () => document.getElementById('sw_u').value
    });
    if (!u) return;
    return finalizar(await postAccion({ action:'reasignar_usuario', idFQ, id_usuario:u }));
  }
  if (a === 'editar_motivo_parcial') {
    const { value: mt } = await Swal.fire({
      title:'Motivo de parcial',
      html:`<label class="d-block text-left">Motivo</label>
            <select id="sw_mt" class="form-control">
              <option value="">(vaciar)</option>
              <option value="No permitieron implementar todo">No permitieron implementar todo</option>
              <option value="Material pendiente queda en bodega">Material pendiente queda en bodega</option>
            </select>`,
      showCancelButton:true, confirmButtonText:'Guardar',
      preConfirm: () => document.getElementById('sw_mt').value
    });
    if (mt === undefined) return;
    return finalizar(await postAccion({ action:'editar_motivo_parcial', idFQ, motivo:mt }));
  }
  if (a === 'editar_gestion') {
    const etOpts = `<option value="">Sin iniciar</option>` + ETAPAS.map(e => `<option value="${e}" ${m.etapa_material===e?'selected':''}>${ETAPA_LABEL[e]}</option>`).join('');
    const { value: vals } = await Swal.fire({
      title:'Editar gestión',
      html:`<label class="d-block text-left">Etapa</label><select id="sw_etapa" class="form-control">${etOpts}</select>
            <label class="d-block text-left mt-2">Implementado (acumulado)</label><input id="sw_valor" type="number" min="0" class="form-control" value="${m.implementado}">
            <label class="d-block text-left mt-2">Observación</label><textarea id="sw_obs" class="form-control">${escapeHtml(m.observacion||'')}</textarea>`,
      showCancelButton:true, confirmButtonText:'Guardar',
      preConfirm: () => ({ etapa: document.getElementById('sw_etapa').value, valor: document.getElementById('sw_valor').value, obs: document.getElementById('sw_obs').value })
    });
    if (!vals) return;
    return finalizar(await postAccion({ action:'editar_gestion', idFQ, etapa_material:vals.etapa, valor:vals.valor, observacion:vals.obs }));
  }
  if (a === 'editar_apoyos') {
    const r = await fetch('mod_seguimiento_etapas/ajax_detalle_material.php?idFQ=' + idFQ, { credentials:'same-origin' });
    const js = await r.json();
    const actuales = new Set((js.apoyos||[]).map(x => String(x.id_usuario)));
    const checks = CATALOGOS.ejecutores.map(e => `<div class="text-left"><label><input type="checkbox" class="sw_ap" value="${e.id}" ${actuales.has(String(e.id))?'checked':''}> ${escapeHtml(e.nombre)}</label></div>`).join('');
    const { value: ok } = await Swal.fire({
      title:'Editar apoyos', html:`<div style="max-height:300px;overflow:auto">${checks||'<p>No hay ejecutores.</p>'}</div>`,
      showCancelButton:true, confirmButtonText:'Guardar',
      preConfirm: () => {
        const sel = new Set(Array.from(document.querySelectorAll('.sw_ap:checked')).map(c => c.value));
        const agregar = [...sel].filter(x => !actuales.has(x));
        const quitar  = [...actuales].filter(x => !sel.has(x));
        return { agregar, quitar };
      }
    });
    if (!ok) return;
    return finalizar(await postAccion({ action:'editar_apoyos', idFQ, agregar:ok.agregar, quitar:ok.quitar }));
  }
  if (a === 'agregar_material') {
    const { value: vals } = await Swal.fire({
      title:'Agregar material a este local',
      html:`<label class="d-block text-left">Material</label><select id="sw_mat" class="form-control">${materialOptions()}</select>
            <label class="d-block text-left mt-2">Ejecutor</label><select id="sw_ej" class="form-control">${ejecutorOptions(m.id_usuario)}</select>
            <label class="d-block text-left mt-2">Valor propuesto</label><input id="sw_vp" type="number" min="0" class="form-control" value="1">`,
      showCancelButton:true, confirmButtonText:'Agregar',
      preConfirm: () => ({ material:document.getElementById('sw_mat').value, id_usuario:document.getElementById('sw_ej').value, vp:document.getElementById('sw_vp').value })
    });
    if (!vals) return;
    return finalizar(await postAccion({ action:'agregar_material', id_formulario:CAMPANIA_ACTUAL, id_local:m.id_local, id_usuario:vals.id_usuario, material:vals.material, valor_propuesto:vals.vp }));
  }
  if (a === 'eliminar_gestion') {
    const c = await Swal.fire({ title:'¿Eliminar gestión?', text:'Se eliminará la fila del material en esta campaña.', icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar', confirmButtonColor:'#dc3545', input:'checkbox', inputPlaceholder:'También borrar fotos' });
    if (!c.isConfirmed) return;
    return finalizar(await postAccion({ action:'eliminar_gestion', idFQ, borrar_fotos: c.value ? '1':'0' }), true);
  }
}
async function finalizar(js, cerrarModal){
  if (js.ok) {
    Swal.fire({ icon:'success', title:'Listo', text:js.message, timer:1600, showConfirmButton:false });
    if (cerrarModal) $('#modalDetalle').modal('hide');
    await cargarResumen(CAMPANIA_ACTUAL);
    if (!cerrarModal && $('#modalDetalle').hasClass('show')) {
      const m = $('#detBody').data('material'); if (m) abrirDetalle(m.id);
    }
  } else {
    Swal.fire('Error', js.message || 'No se pudo completar la acción.', 'error');
  }
}

/* ---------- Agregar local (modal) ---------- */
function alAddMatRow(){
  const $row = $(`<div class="mat-row"><select class="form-control alMat">${materialOptions()}</select><input type="number" min="0" class="form-control alVp" placeholder="Valor" value="1"><button type="button" class="btn btn-sm btn-outline-danger alDel"><i class="fas fa-times"></i></button></div>`);
  $('#alMateriales').append($row);
}

/* ---------- Eventos ---------- */
$(function(){
  cargarCampanas();

  $('#selDivision').on('change', function(){
    renderCampaniaOptions($(this).val());
    cargarResumen(0);
  });
  $('#selCampania').on('change', async function(){
    const id = $(this).val();
    if (id) { await cargarCatalogos(id); }
    cargarResumen(id);
  });

  $('.view-btn').on('click', function(){ setView($(this).data('view')); });

  $('#tablaEtapas tbody').on('click', '.btn-ver', function(e){ e.stopPropagation(); abrirDetalle($(this).data('id')); });

  // Visor de fotos (lightbox)
  $('#detBody').on('click', '.se-thumb', function(){ verFotoSeguimiento($(this).data('full')); });
  $('#seLightbox').on('click', function(e){ if (e.target.id !== 'seLightboxImg') { $(this).hide(); $('#seLightboxImg').attr('src',''); } });
  $(document).on('keydown', function(e){ if (e.key === 'Escape') $('#seLightbox').hide(); });

  $('#detBody').on('click', '.act', function(){
    const a = $(this).data('a');
    const m = $('#detBody').data('material');
    ejecutarAccion(a, m);
  });
  $('#detBody').on('click', '.act-foto', async function(){
    const id = $(this).data('id');
    const c = await Swal.fire({ title:'¿Eliminar foto?', icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar', confirmButtonColor:'#dc3545' });
    if (!c.isConfirmed) return;
    finalizar(await postAccion({ action:'eliminar_foto', id_foto:id }));
  });

  <?php if ($esEditor): ?>
  $('#btnAgregarLocal').on('click', function(){
    $('#alMateriales').empty(); alAddMatRow();
    $('#alLocal').html(CATALOGOS.locales.map(l => `<option value="${l.id}">${escapeHtml(l.codigo)} — ${escapeHtml(l.nombre)}</option>`).join(''));
    $('#alEjecutor').html(ejecutorOptions(''));
    $('#alFecha').val(new Date().toISOString().slice(0,10));
    $('#alLocal').select2({ theme:'bootstrap4', dropdownParent:$('#modalAgregarLocal'), width:'100%' });
    $('#alEjecutor').select2({ theme:'bootstrap4', dropdownParent:$('#modalAgregarLocal'), width:'100%' });
    $('#modalAgregarLocal').modal('show');
  });
  $('#alAddMat').on('click', alAddMatRow);
  $('#alMateriales').on('click', '.alDel', function(){ $(this).closest('.mat-row').remove(); });
  $('#alGuardar').on('click', async function(){
    const materiales = [];
    $('#alMateriales .mat-row').each(function(){
      const material = $(this).find('.alMat').val();
      const vp = $(this).find('.alVp').val();
      if (material && vp !== '') materiales.push({ material, valor_propuesto: vp });
    });
    if (!materiales.length) { Swal.fire('Atención','Agrega al menos un material.','warning'); return; }
    const js = await postAccion({
      action:'agregar_local', id_formulario:CAMPANIA_ACTUAL, id_local:$('#alLocal').val(),
      id_usuario:$('#alEjecutor').val(), fechaPropuesta:$('#alFecha').val(),
      'materiales_json': JSON.stringify(materiales)
    });
    if (!js.ok) { Swal.fire('Error', js.message, 'error'); return; }
    $('#modalAgregarLocal').modal('hide');
    Swal.fire({ icon:'success', title:'Listo', text:js.message, timer:1600, showConfirmButton:false });
    cargarResumen(CAMPANIA_ACTUAL);
  });
  <?php endif; ?>
});
</script>
</body>
</html>

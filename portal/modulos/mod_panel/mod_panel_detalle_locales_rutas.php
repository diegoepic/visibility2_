<!DOCTYPE html>
<?php include 'mapa_data.php'; ?>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de Locales - Rutas IPT</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="../../css/mapa.css">  
  <link rel="stylesheet" type="text/css" href="../../assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="../../assets/css/dataTable.css">
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>
<style>
:root {
  --routes-page: #f5f8fd;
  --routes-surface: #ffffff;
  --routes-soft: #f0f5ff;
  --routes-ink: #1e3c6b;
  --routes-muted: #647c9f;
  --routes-border: #d4e1fa;
  --routes-blue: #456edc;
  --routes-teal: #25b3c3;
  --routes-shadow: 0 14px 36px rgba(37, 72, 133, .08);
}

body {
  min-height: 100vh;
  background: linear-gradient(180deg, #edf4ff 0, var(--routes-page) 480px, #fff 100%);
  color: var(--routes-ink);
  font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
  color-scheme: light;
}

/* Mapa sin capas que oscurezcan los puntos ni los controles. */
#map {
  position: relative;
  height: 430px;
  background: #eaf2f7;
  border-radius: 0 0 24px 24px;
  overflow: hidden;
  box-shadow: 0 8px 28px rgba(37, 72, 133, .08);
}
#map::after { content: none; }
#map::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--routes-teal), #5482f4);
  pointer-events: none;
  z-index: 2;
}

.card-panel {
  position: relative;
  z-index: 30;
  max-width: 1280px;
  margin-top: -48px;
  padding-left: 16px !important;
  padding-right: 16px !important;
}
#panel-filtros { background: transparent; }
.map-filters-card {
  background: var(--routes-surface);
  border: 1px solid var(--routes-border);
  border-radius: 22px;
  box-shadow: var(--routes-shadow);
}
.map-filters-body { padding: 28px 26px 18px; }
.filter-group { margin-bottom: 20px; }
.filter-label {
  display: block;
  margin-bottom: 8px;
  color: var(--routes-ink);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .35px;
}
.filter-control {
  height: 44px;
  padding: 8px 12px;
  border-radius: 13px;
  background: var(--routes-surface);
  border: 1px solid #c8d9f8;
  color: var(--routes-ink);
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 4px 12px rgba(45, 79, 143, .025);
  transition: border-color .2s ease, box-shadow .2s ease;
}
.filter-control:hover:not(:disabled) { border-color: #93b1f2; }
.filter-control:focus {
  background: var(--routes-surface);
  border-color: #5e87ec;
  color: var(--routes-ink);
  box-shadow: 0 0 0 .2rem rgba(78, 123, 238, .13);
}
.filter-control:disabled {
  background: #f2f5fa;
  color: #7687a0;
  border-color: #e0e7f2;
  opacity: 1;
  cursor: not-allowed;
}
.filter-control option { background: #fff; color: var(--routes-ink); }
#panel-filtros .help { display: none; }
.filter-search-wrap { text-align: center; padding: 4px 0 6px; }
.filter-search-btn {
  min-width: 168px;
  height: 46px;
  border: 0 !important;
  border-radius: 14px;
  background: linear-gradient(110deg, #4775e3, #456ad8) !important;
  color: #fff !important;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: .35px;
  box-shadow: 0 10px 22px rgba(71, 112, 223, .22);
  transition: transform .2s ease, box-shadow .2s ease;
}
.filter-search-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 12px 26px rgba(71, 112, 223, .28);
}
.filter-search-btn:focus-visible {
  outline: 3px solid #a9c1fc;
  outline-offset: 3px;
}
.card-panel > .btn-outline-secondary {
  width: 36px;
  height: 36px;
  padding: 0;
  border-radius: 50%;
  background: #fff !important;
  border: 1px solid var(--routes-border) !important;
  color: var(--routes-muted) !important;
  box-shadow: 0 5px 16px rgba(37, 72, 133, .1);
}
.card-panel > .btn-outline-secondary:hover { color: var(--routes-blue) !important; }

/* Listado y controles de DataTables. */
.seccion-tabla {
  width: calc(100% - 48px);
  max-width: 1480px;
  margin: 32px auto;
  padding: 26px;
  background: var(--routes-surface);
  border: 1px solid #e5ecf8;
  border-radius: 24px;
  box-shadow: var(--routes-shadow);
}
.seccion-tabla > hr { display: none; }
.table-header-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
.table-title { color: var(--routes-ink); font-size: 25px; font-weight: 700; letter-spacing: -.35px; }
.results-badge, .badge-secondary {
  background: var(--routes-soft) !important;
  color: var(--routes-blue) !important;
  border: 1px solid var(--routes-border);
  border-radius: 999px;
  padding: 7px 11px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .3px;
  vertical-align: middle;
}
.seccion-tabla .btn-outline-info {
  padding: 10px 14px;
  background: #fff;
  border: 1px solid var(--routes-border);
  border-radius: 13px;
  color: var(--routes-blue);
  font-size: 12px;
  font-weight: 600;
}
.seccion-tabla .btn-outline-info:hover { background: var(--routes-soft); border-color: #9bb7f3; }
.table-responsive {
  background: var(--routes-surface);
  border: 1px solid var(--routes-border);
  border-radius: 17px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
#example, #example.no-footer {
  color: var(--routes-ink);
  background: #fff;
  border-bottom: none !important;
  margin-top: 0 !important;
  margin-bottom: 0 !important;
}
#example_wrapper { padding: 16px; }
#example_wrapper::after { content: ''; display: table; clear: both; }
#example_wrapper .row:first-child { padding: 0 0 14px; margin: 0; }
#example_wrapper .row:last-child { padding: 14px 0 2px; color: var(--routes-muted); font-size: 12px; }
#example thead th {
  background-color: #f0f5ff !important;
  color: #365782 !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  letter-spacing: .35px;
  border-bottom: 1px solid var(--routes-border) !important;
  padding: 16px 14px !important;
  white-space: nowrap;
}
#example tbody tr, #example tbody tr > td {
  background: #fff !important;
  color: var(--routes-ink) !important;
  box-shadow: none !important;
  transition: background .18s ease;
}
#example tbody tr:nth-child(even) > td { background: #f9fbff !important; }
#example tbody tr:hover, #example tbody tr:hover > td { background: #edf4ff !important; }
#example tbody td {
  font-size: 12px !important;
  font-weight: 500;
  border-top: 1px solid #e9eff8 !important;
  padding: 16px 14px !important;
  vertical-align: middle;
  white-space: nowrap;
}
#example tbody td:first-child { font-weight: 700; }
#example.dtr-inline.collapsed > tbody > tr:not(.child) > td:first-child { padding-left: 34px !important; }
#example.dtr-inline.collapsed > tbody > tr:not(.child) > td:first-child::before { top: 50%; left: 8px; margin-top: -8px; }
#example tbody td:nth-child(2) { min-width: 210px; }
.local-cell { display: flex; flex-direction: column; line-height: 1.4; min-width: 210px; }
.local-name { color: var(--routes-ink); font-size: 12px; font-weight: 700; margin-bottom: 4px; white-space: normal; }
.local-address { color: var(--routes-muted); font-size: 12px; font-weight: 400; white-space: normal; }
#example_wrapper .dataTables_length label, #example_wrapper .dataTables_filter label { color: var(--routes-muted); font-size: 12px; font-weight: 600; }
#example_wrapper .dataTables_filter label { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 14px; }
#example_wrapper .dataTables_length select, #example_wrapper .dataTables_filter input {
  background: #fff !important;
  border: 1px solid #c8d9f8 !important;
  color: var(--routes-ink) !important;
  border-radius: 12px !important;
  min-height: 40px;
  padding: 8px 12px;
}
#example_wrapper .dataTables_filter input { width: 210px; margin-left: 0 !important; }
#example_wrapper .dataTables_length select:focus, #example_wrapper .dataTables_filter input:focus {
  outline: none;
  border-color: #5e87ec !important;
  box-shadow: 0 0 0 .2rem rgba(78, 123, 238, .13);
}
.dt-buttons { margin-bottom: 14px; }
.dt-button {
  background: #fff !important;
  color: var(--routes-blue) !important;
  border: 1px solid var(--routes-border) !important;
  border-radius: 12px !important;
  padding: 10px 14px !important;
  font-size: 12px !important;
  font-weight: 600 !important;
  box-shadow: none !important;
  text-shadow: none !important;
}
.dt-button:hover, .dt-button.active { background: var(--routes-soft) !important; border-color: #9bb7f3 !important; }
.dt-button-collection { background: #fff !important; border: 1px solid var(--routes-border) !important; border-radius: 14px !important; box-shadow: var(--routes-shadow) !important; }
#example_wrapper .dataTables_paginate .paginate_button {
  background: transparent !important;
  border: 1px solid transparent !important;
  color: var(--routes-muted) !important;
  border-radius: 10px !important;
  font-size: 12px;
  font-weight: 600;
  box-shadow: none !important;
}
#example_wrapper .dataTables_paginate .paginate_button.current,
#example_wrapper .dataTables_paginate .paginate_button.current:hover {
  background: var(--routes-soft) !important;
  border-color: var(--routes-border) !important;
  color: var(--routes-blue) !important;
}
#example_wrapper .dataTables_paginate .paginate_button:hover { background: #edf4ff !important; color: var(--routes-ink) !important; }
#example_wrapper .dataTables_paginate .paginate_button.disabled { color: #7c8ba4 !important; cursor: default; }
#example_wrapper .dataTables_info { color: var(--routes-muted) !important; font-size: 12px; padding-top: 18px; }
#example tbody td.dataTables_empty { color: var(--routes-muted) !important; text-align: center; padding: 36px 14px !important; }

.status-pill { display: inline-flex; align-items: center; gap: 7px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; background: currentColor; }
.status-ok { color: #277343; }
.status-pending { color: #697b96; }
.status-progress { color: #906313; }
.badge-custom { border-radius: 999px; padding: 7px 12px; font-size: 11px; font-weight: 700; }
.badge-completados { background: #eff8de; color: #417015; border: 1px solid #dfedc4; }
.badge-pendientes { background: #f1f4f9; color: #62738c; border: 1px solid #e0e7f1; }

/* El detalle conserva la misma paleta que filtros y listado. */
#modalDetalle .modal-dialog { max-width: 1080px; }
#modalDetalle .modal-content {
  width: 100%;
  max-width: 100%;
  padding: 0;
  background: #fff;
  color: var(--routes-ink);
  border: 1px solid var(--routes-border);
  border-radius: 22px;
  box-shadow: 0 24px 70px rgba(30, 60, 107, .18);
  overflow: hidden;
}
#modalDetalle .modal-header {
  background: linear-gradient(115deg, #f0f9fc, #f2f6ff);
  border-bottom: 1px solid var(--routes-border);
  padding: 26px 28px 20px;
}
#modalDetalle .modal-title { color: var(--routes-ink); font-size: 26px; font-weight: 700; letter-spacing: -.3px; margin-right: 40px; }
#modalHeaderExtra { color: var(--routes-muted); font-size: 13px !important; line-height: 1.55 !important; }
#modalHeaderExtra strong { color: var(--routes-blue); font-weight: 700; }
#modalDetalle .close { color: var(--routes-muted); opacity: 1; text-shadow: none; }
#modalDetalle .close:hover { color: var(--routes-ink); }
#modalDetalle .modal-body { background: #fff; padding: 24px 28px 28px; }
.modal-kpi-card {
  background: linear-gradient(140deg, #fff, #f5f8ff);
  border: 1px solid var(--routes-border);
  border-radius: 18px;
  padding: 18px;
  min-height: 108px;
  box-shadow: 0 8px 22px rgba(37, 72, 133, .045);
}
.modal-kpi-label { color: var(--routes-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
.modal-kpi-value { color: var(--routes-ink); font-size: 22px; font-weight: 700; line-height: 1.2; }
.modal-kpi-subvalue { color: var(--routes-muted); font-size: 13px; margin-top: 4px; }
.modal-section-card { background: #fff; border: 1px solid var(--routes-border); border-radius: 18px; overflow: hidden; }
.modal-section-header { padding: 15px 18px; background: #f1f7ff; border-bottom: 1px solid var(--routes-border); color: var(--routes-ink); font-size: 12px; font-weight: 700; letter-spacing: .4px; }
.modal-section-body { padding: 18px; color: var(--routes-ink); font-size: 14px; }
.modal-status-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; line-height: 1.2; }
.modal-status-pill::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.modal-status-ok { background: #eff8de; color: #417015; border: 1px solid #dfedc4; }
.modal-status-bad { background: #fff0f1; color: #af3d4d; border: 1px solid #f3d6dc; }
.modal-status-neutral { background: #edf3ff; color: #4067b0; border: 1px solid #d8e4fa; }
.export-only { display: none; }

.detalle-ayuda, .detalle-foto-meta { color: var(--routes-muted); font-size: 12px; }
.detalle-evidencias { margin-top: 24px; }
.detalle-seccion-titulo { color: var(--routes-ink); font-size: 16px; font-weight: 700; margin-bottom: 14px; }
.detalle-fotos-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.detalle-foto { margin: 0; border: 1px solid var(--routes-border); border-radius: 14px; overflow: hidden; background: #fff; }
.detalle-foto-enlace { display: flex; align-items: center; justify-content: center; height: 210px; background: #f0f5fa; }
.detalle-foto-enlace:focus-visible { outline: 3px solid var(--routes-blue); outline-offset: -3px; }
.detalle-foto-imagen { display: block; width: 100%; height: 100%; object-fit: contain; }
.detalle-foto-texto { padding: 14px; overflow-wrap: anywhere; }
.detalle-foto-tipo { color: var(--routes-blue); text-transform: uppercase; font-weight: 700; font-size: 10px; }
.detalle-foto-etiqueta { display: block; margin: 5px 0 10px; font-size: 14px; }
.detalle-foto-observacion { margin: 10px 0 0; font-size: 12px; white-space: pre-wrap; }
.detalle-foto-error { padding: 18px; font-size: 12px; text-align: center; }
.detalle-cargando { padding: 35px 0; text-align: center; color: var(--routes-muted); }
@media (max-width: 991px) { .detalle-fotos-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 575px) { .detalle-fotos-grid { grid-template-columns: minmax(0, 1fr); } }

@media (max-width: 991px) {
  #map { height: 360px; }
  .card-panel { margin-top: -32px; }
  .map-filters-body { padding: 24px 20px 16px; }
  .seccion-tabla { width: calc(100% - 32px); padding: 22px 18px; }
  .table-title { font-size: 22px; }
  #example thead th, #example tbody td { padding: 14px 10px !important; }
}
@media (max-width: 767px) {
  #map { height: 300px; border-radius: 0 0 18px 18px; }
  .card-panel { margin-top: -20px; padding-left: 12px !important; padding-right: 12px !important; }
  .map-filters-body { padding: 28px 18px 18px; }
  .filter-search-btn { width: 100%; }
  .seccion-tabla { width: calc(100% - 24px); margin: 24px auto; padding: 20px 14px; border-radius: 20px; }
  .table-title { font-size: 21px; line-height: 1.5; }
  #example_wrapper { padding: 12px; }
  #example tbody td:nth-child(2) { min-width: 145px; }
  .local-cell { min-width: 145px; max-width: 190px; }
  #example_wrapper .dt-buttons, #example_wrapper .dataTables_filter { float: none; text-align: left; }
  #example_wrapper .dataTables_filter label { width: 100%; }
  #example_wrapper .dataTables_filter input { flex: 1; min-width: 0; width: 140px; }
  #modalDetalle .modal-dialog { margin: .75rem; }
  #modalDetalle .modal-header, #modalDetalle .modal-body { padding: 20px 18px; }
  #modalDetalle .modal-title { font-size: 21px; }
  .modal-kpi-value { font-size: 19px; }
}
@media (prefers-reduced-motion: reduce) {
  .filter-control, .filter-search-btn, #example tbody tr, #example tbody td { transition: none; }
}
</style>
</head>
<body>
  <!-- Mapa -->
  <div id="map"></div>
<div class="container card-panel position-relative">

  <!-- Botón de Collapse -->
  <button class="btn btn-outline-secondary btn-sm position-absolute"
          style="top: 8px; right: 12px; z-index: 10;"
          data-toggle="collapse"
          data-target="#panel-filtros"
          aria-expanded="true"
          aria-controls="panel-filtros">
    <i class="fa fa-chevron-up"></i>
  </button>

  <!-- PANEL -->
  <div id="panel-filtros" class="collapse show">

<!-- Filtros -->
<div class="map-filters-card mt-3">
  <div class="map-filters-body">
    <form method="GET" action="">

      <!-- FILA 1 -->
      <div class="form-row">
        <?php if ($isMC): ?>
          <div class="form-group col-md-3 filter-group">
            <label for="id_division" class="filter-label">DIVISIÓN</label>
            <select class="form-control filter-control" id="id_division" name="id_division"
                    onchange="document.getElementById('id_subdivision').value = 0; document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0;">
              <option value="0">SELECCIONE DIVISIÓN</option>
              <?php foreach($divisiones as $div): ?>
                <option value="<?= (int)$div['id'] ?>" <?= $div['id']==$filter_division?'selected':'' ?>>
                  <?= htmlspecialchars($div['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="id_division" value="<?= (int)$filter_division ?>">
        <?php endif; ?>

        <div class="form-group col-md-3 filter-group">
          <label for="id_subdivision" class="filter-label">SUB DIVISIÓN</label>
          <select class="form-control filter-control" id="id_subdivision" name="id_subdivision" disabled>
            <option value="0" selected>TODAS</option>
            <option value="-1">SIN SUB DIVISIÓN</option>
          </select>
          <small class="help">FILTRA CAMPAÑAS POR SUB DIVISIÓN (SI APLICA).</small>
        </div>

        <div class="form-group col-md-2 filter-group">
          <label for="tipo_gestion" class="filter-label">TIPO DE GESTIÓN</label>
          <select class="form-control filter-control" id="tipo_gestion" name="tipo_gestion">
            <option value="0" <?= ($tipoCampana==0)?'selected':'' ?>>TODAS</option>
            <option value="1" <?= ($tipoCampana==1)?'selected':'' ?>>CAMPAÑA</option>
            <option value="3" <?= ($tipoCampana==3)?'selected':'' ?>>RUTA</option>
          </select>
          <small class="help">FILTRA POR TIPO DE GESTIÓN.</small>
        </div>

        <div class="form-group col-md-2 filter-group">
          <label for="estado" class="filter-label">ESTADO CAMPAÑA</label>
          <select class="form-control filter-control" id="estado" name="estado"
                  onchange="document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0;">
            <option value="0" <?= $filter_estado==0?'selected':'' ?>>AMBOS</option>
            <option value="1" <?= $filter_estado==1?'selected':'' ?>>EN CURSO</option>
            <option value="3" <?= $filter_estado==3?'selected':'' ?>>FINALIZADAS</option>
          </select>
        </div>
      </div>

      <!-- FILA 2 -->
      <div class="form-row">
        <div class="form-group col-md-4 filter-group" id="campo-campana" style="display:none">
          <label for="id_campana" class="filter-label">CAMPAÑA</label>
          <select class="form-control filter-control" id="id_campana" name="id_campana" disabled>
            <option value="0" selected>SELECCIONE CAMPAÑA</option>
          </select>
          <small class="help">PUEDES ESCOGER CAMPAÑA O IR DIRECTO AL EJECUTOR</small>
        </div>

        <div class="form-group col-md-3 filter-group">
          <label for="id_ejecutor" class="filter-label">EJECUTOR</label>
          <select class="form-control filter-control" id="id_ejecutor" name="id_ejecutor">
            <option value="0" selected>TODOS LOS EJECUTORES</option>
          </select>
<small class="help">SI NO ELIGES CAMPAÑA O RUTA, SE MOSTRARÁN TODOS LOS EJECUTORES DISPONIBLES.</small>
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-desde">
          <label for="desde" class="filter-label">DESDE</label>
          <input type="date" class="form-control filter-control" id="desde" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-hasta">
          <label for="hasta" class="filter-label">HASTA</label>
          <input type="date" class="form-control filter-control" id="hasta" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
        </div>
      </div>

      <!-- FILA 3: BOTÓN -->
      <div class="form-row">
        <div class="form-group col-12 filter-search-wrap mb-0">
          <button type="submit" name="buscar" value="1" class="btn filter-search-btn px-4">
            BUSCAR <i class="fa fa-search ml-2"></i>
          </button>
        </div>
      </div>

      <!-- Persistencia -->
      <input type="hidden" id="val_division" value="<?= (int)$filter_division ?>">
      <input type="hidden" id="val_subdivision" value="<?= (int)$filter_subdivision ?>">
      <input type="hidden" id="val_campana" value="<?= (int)$filter_campana ?>">
      <input type="hidden" id="val_ejecutor" value="<?= (int)$id_ejecutor ?>">
      <input type="hidden" id="val_tipo" value="<?= (int)$tipoCampana ?>">

    </form>
  </div>
</div>

  </div> <!-- collapse -->
</div><!-- container -->
<div class="seccion-tabla">
  <!-- Tabla -->
  <hr>
    <div class="table-header-bar mb-3 d-flex flex-wrap align-items-center justify-content-between">
      <h5 class="table-title mb-0">
        Listado de Locales <?= ($id_ejecutor > 0 && !empty($nombreEjec)) ? 'para ' . htmlspecialchars($nombreEjec) : '' ?>
        <?php if (!empty($locales)): ?>
          <span class="badge results-badge ml-2"><?= count($locales) ?> RESULTADOS</span>
        <?php endif; ?>
      </h5>
      <a class="btn btn-sm btn-outline-info mt-2 mt-md-0" href="mod_visualizar_ruta_excel.php">
        <i class="fa fa-map-marked-alt mr-1"></i> Visualizar Excel en mapa
      </a>
    </div>
<div class="table-responsive">
  <table id="example" class="display nowrap modern-dark-table" width="100%">
    <thead>
      <tr>
        <th>CODIGO</th>
        <th>LOCAL</th>
        <th class="export-only">DIRECCION</th>
        <th>REGION / COMUNA</th>
        <th class="export-only">REGION</th>
        <th class="export-only">COMUNA</th>
        <th class="export-only">LAT</th>
        <th class="export-only">LNG</th>
        <th>MERCHAN</th>          
        <th>ESTADO VISITA</th>
        <th>FECHA PLANIFICADA</th>
        <th>FECHA VISITA</th>
        <th>ESTADO GESTION</th>
        <th>OBSERVACION</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($locales): foreach($locales as $loc):
        $idLocalRaw   = $loc['id_local'] ?? '';
        $idLocal      = htmlspecialchars((string)$idLocalRaw);
        $nombreCamp   = htmlspecialchars($loc['nombre_campana']);
        $codigoRaw    = $loc['codigo'] ?? '';
        $codigoLocal  = htmlspecialchars((string)$codigoRaw);
        $nombreLoc    = htmlspecialchars($loc['nombre_local']);
        $dirLoc       = htmlspecialchars($loc['direccion_local']);
        $comunaLoc    = htmlspecialchars($loc['comuna_local']);
        $regionLoc    = htmlspecialchars($loc['region_local']);
        $coordLocal   = (isset($coordenadas_locales[$idLocalRaw]) && is_array($coordenadas_locales[$idLocalRaw]))
          ? $coordenadas_locales[$idLocalRaw]
          : [];
        if (empty($coordLocal) && isset($coordenadas_locales[$codigoRaw]) && is_array($coordenadas_locales[$codigoRaw])) {
          $coordLocal = $coordenadas_locales[$codigoRaw];
        }
        $latLoc       = htmlspecialchars((string)($loc['lat'] ?? $loc['latitud'] ?? $loc['latitude'] ?? $coordLocal['lat'] ?? $coordLocal['latitud'] ?? ''));
        $lngLoc       = htmlspecialchars((string)($loc['lng'] ?? $loc['longitud'] ?? $loc['longitude'] ?? $coordLocal['lng'] ?? $coordLocal['longitud'] ?? ''));
        $usuarioLoc   = htmlspecialchars($loc['usuario_local']);
        $fechaP       = $loc['fechaPropuesta'] ? date('d-m-Y', strtotime($loc['fechaPropuesta'])) : '-';
        $fechaV       = $loc['fechaVisita'] ? date('d-m-Y', strtotime($loc['fechaVisita'])) : '-';
        $prgRaw       = ($loc['pregunta'] !== '' && $loc['pregunta'] !== null) ? $loc['pregunta'] : '-';
        $observacion  = (isset($loc['observacion']) && trim((string)$loc['observacion']) !== '')
          ? htmlspecialchars($loc['observacion'])
          : '-';

        $visitado = !empty($loc['fechaVisita']) && $loc['fechaVisita'] !== '0000-00-00 00:00:00';

        $badgeEstado = $visitado
          ? '<span class="status-pill status-ok"><span class="status-dot"></span>VISITADO</span>'
          : '<span class="status-pill status-pending"><span class="status-dot"></span>PENDIENTE</span>';

        if (!empty($prgRaw)) {
          if (in_array($prgRaw, ['AUDITORIA','IMPLEMENTACION','IMPL/AUD'])) {
            $prg = 'GESTIONADO';
          } else {
            $prg = strtoupper(str_replace('_',' ', $prgRaw));
          }
        } else {
          $prg = '-';
        }
      ?>
      <tr data-local="<?= (int)$loc['id_local'] ?>" data-campana="<?= (int)$loc['id_formulario'] ?>" data-usuario="<?= (int)$loc['id_usuario'] ?>">
         
        <td><?= $codigoLocal ?></td>

        <!-- Visible solo front -->
        <td>
          <div class="local-cell">
            <div class="local-name"><?= $nombreLoc ?></div>
            <div class="local-address"><?= $dirLoc ?></div>
          </div>
        </td>        

        <!-- Solo export -->
        <td class="export-only"><?= $dirLoc ?></td>

        <!-- Visible solo front -->
        <td>
          <div class="local-cell">
            <div class="local-name"><?= $regionLoc ?></div>
            <div class="local-address"><?= $comunaLoc ?></div>
          </div>
        </td>

        <!-- Solo export -->
        <td class="export-only"><?= $regionLoc ?></td>
        <td class="export-only"><?= $comunaLoc ?></td>
        <td class="export-only"><?= $latLoc ?></td>
        <td class="export-only"><?= $lngLoc ?></td>

        <td><?= $usuarioLoc ?></td>
        <td class="text-center"><?= $badgeEstado ?></td>
        <td class="text-center"><?= $fechaP ?></td>
        <td class="text-center"><?= $fechaV ?></td>
        <td><?= htmlspecialchars($prg) ?></td>
        <td><?= $observacion ?></td>
      </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="14" class="text-center">SIN RESULTADOS.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<!-- Modal Detalle Local -->
<div class="modal fade" id="modalDetalle" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">

      <!-- HEADER -->
        <div class="modal-header flex-column align-items-start">
          <h5 class="modal-title w-100" id="modalTituloLocal"></h5>
          <div id="modalHeaderExtra" class="w-100 mt-2" style="font-size:14px; line-height:1.3;"></div>
          <button type="button" class="close position-absolute" style="right:15px; top:15px;"
                  data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

      <!-- BODY -->
      <div class="modal-body" id="modalBodyDetalle">
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="../../assets/js/datatables.min.js"></script>
<script src="../../js/mapa_exportar.js?v=20260914-2"></script>
<script>
$(document).ready(async function() {
  await window.prepararColumnasFotos(document.getElementById("example"));
  $('#example').DataTable({
    dom: 'Bfrtip',
    responsive: true,
    language: { emptyTable: 'SIN RESULTADOS.' },
    buttons: [
      {
        extend: 'colvis',
        text: 'Mostrar columnas',
        columns: function(idx, data, node) {
          return true;
        }
      },

          {
            extend: 'copyHtml5',
            exportOptions: {
              columns: function(idx, data, node) {
                return idx !== 3;
              },
              format: {
                body: function(data, row, column, node) {
                  if (column === 1) {
                    return $(node).find('.local-name').first().text().trim();
                  }

                  const foto = $(node).find('[data-foto-url]').first();
                  if (foto.length) return foto.attr('data-foto-url');
                  return $('<div>').html(data).text().trim();
                }
              }
            }
          },
          {
            extend: 'excelHtml5',
            text: 'Descargar Excel',
            title: null,
            action: function(e, dt, button, config) {
              window.exportarLocalesConFotos.call(this, e, dt, button, config);
            },
            exportOptions: {
              columns: function(idx, data, node) {
                return idx !== 3;
              },
              format: {
                body: function(data, row, column, node) {
                  if (column === 1) {
                    return $(node).find('.local-name').first().text().trim();
                  }

                  const foto = $(node).find('[data-foto-url]').first();
                  if (foto.length) return foto.attr('data-foto-url');
                  return $('<div>').html(data).text().trim();
                }
              }
            }
          },
          {
            extend: 'pdfHtml5',
            exportOptions: {
              columns: function(idx, data, node) {
                return idx !== 3;
              },
              format: {
                body: function(data, row, column, node) {
                  if (column === 1) {
                    return $(node).find('.local-name').first().text().trim();
                  }

                  const foto = $(node).find('[data-foto-url]').first();
                  if (foto.length) return foto.attr('data-foto-url');
                  return $('<div>').html(data).text().trim();
                }
              }
            }
          }
    ]
  });
});
</script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    window.isMC = <?= $isMC ? 'true' : 'false' ?>;
    window.divisionUsuario = <?= (int)$filter_division ?>;
</script>

<script>
const isMC = window.isMC;
const divisionUsuario = window.divisionUsuario;
</script>

<script>
  const coordenadasLocales = <?= json_encode($coordenadas_locales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  const idEjecutor = <?= (int)$id_ejecutor ?>;
</script>
<script src="../../js/mapa.js?v=20260911-evidencias-1"></script>

<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  /* ============================================
     🔁 AUTO-RELOAD SI LA PÁGINA FALLA AL CARGAR
  ============================================ */
  const contenido = document.body.innerText.toLowerCase();
  const erroresDetectados = [
    "es posible que la página web",
    "temporalmente inactiva",
    "trasladado definitivamente",
    "error 500",
    "error 404",
    "no se pudo establecer conexión"
  ];
  const hayError = erroresDetectados.some(msg => contenido.includes(msg));
  if (hayError || document.body.innerText.trim().length < 50) {
    console.warn("⚠️ Página con error o vacía. Recargando en 5 segundos...");
    setTimeout(() => location.reload(), 5000);
  }

  /* ============================================
     🧩 MOSTRAR / OCULTAR CAMPOS SEGÚN TIPO GESTIÓN
  ============================================ */
  const tipoGestion = document.getElementById("tipo_gestion");
  const campoCampana = document.getElementById("campo-campana");
  const campoDesde = document.getElementById("campo-desde");
  const campoHasta = document.getElementById("campo-hasta");

  function actualizarVisibilidad() {
    const valor = parseInt(tipoGestion?.value || 0, 10);
    if (!campoCampana || !campoDesde || !campoHasta) return;

    campoCampana.style.display = (valor === 0 || valor === 1) ? "block" : "none";
    campoDesde.style.display = "block";
    campoHasta.style.display = "block";
  }

  if (tipoGestion) {
    actualizarVisibilidad();
    tipoGestion.addEventListener("change", actualizarVisibilidad);
  }

  /* ============================================
     🔹 FUNCIONES COMUNES
  ============================================ */
  function resetSelect(sel, placeholder) {
    if (!sel) return;
    sel.innerHTML = `<option value="0">${placeholder}</option>`;
  }

  function setOptions(select, options, selectedVal = null) {
    if (!select) return;
    while (select.firstChild) select.removeChild(select.firstChild);

    options.forEach(o => {
      const opt = new Option(o.nombre, o.id);
      if (selectedVal !== null && String(selectedVal) === String(o.id)) {
        opt.selected = true;
      }
      select.add(opt);
    });
  }

  /* ============================================
     🧩 ELEMENTOS DEL FORMULARIO
  ============================================ */
  const $division = document.getElementById('id_division');
  const divisionIsHidden = !$division;
  const $subdivision = document.getElementById('id_subdivision');
  const $campana = document.getElementById('id_campana');
  const $ejecutor = document.getElementById('id_ejecutor');
  const $estado = document.getElementById('estado');
  const $tipo = document.getElementById('tipo_gestion');
  const $distrito = document.getElementById('id_distrito');
  const $empresa = document.querySelector('input[name="empresa_id"]') || { value: '<?= $id_empresa ?>' };

  // Valores guardados desde PHP
  const val_division = document.getElementById('val_division')?.value || 0;
  const val_subdivision = document.getElementById('val_subdivision')?.value || 0;
  const val_campana = document.getElementById('val_campana')?.value || 0;
  const val_ejecutor = document.getElementById('val_ejecutor')?.value || 0;
  const val_tipo = document.getElementById('val_tipo')?.value || 0;

  let xhrEjecutores = null;
  let firmaEjecutores = '';

  /* ============================================
     🧩 SUBDIVISIONES DINÁMICAS
  ============================================ */
  function cargarSubdivisionesAuto(idDivision, restaurar = false) {
    if (!$subdivision) return;

    $subdivision.disabled = false;
    resetSelect($subdivision, 'Cargando...');

    fetch(`../mod_cargar/cargar_subdivisiones.php?id_division=${idDivision}`)
      .then(r => r.json())
      .then(data => {
        const items = (data.ok && Array.isArray(data.subdivisiones))
          ? data.subdivisiones
          : [];

        setOptions($subdivision, [
          { id: 0, nombre: 'TODAS' },
          { id: -1, nombre: 'SIN SUB DIVISIÓN' },
          ...items
        ]);

        if (
          restaurar &&
          val_subdivision !== "0" &&
          $subdivision.querySelector(`option[value="${val_subdivision}"]`)
        ) {
          $subdivision.value = val_subdivision;
        }

        $subdivision.disabled = false;
      })
      .catch(err => {
        console.error('Error cargando subdivisiones:', err);
        resetSelect($subdivision, 'ERROR AL CARGAR');
        $subdivision.disabled = false;
      });
  }

  /* ============================================
     🧩 CAMPAÑAS DINÁMICAS
  ============================================ */
  function cargarCampanasMapa(restaurar = false) {
    if (!$campana || !$subdivision) return;

    const division = $division ? ($division.value || 0) : divisionUsuario;
    const subdiv = $subdivision.value || 0;
    const estado = $estado?.value || 0;
    const tipoG = $tipo?.value || 0;

    resetSelect($campana, 'Cargando campañas...');
    $campana.disabled = true;

    fetch(`../mod_cargar/cargar_campanas2.php?id_empresa=${$empresa.value}&id_division=${division}&id_subdivision=${subdiv}&estado=${estado}&tipo_gestion=${tipoG}`)
      .then(r => r.json())
      .then(data => {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');

        if (data.ok && Array.isArray(data.campanas)) {
          data.campanas.forEach(c => {
            const opt = new Option(c.nombre, c.id);
            $campana.add(opt);
          });
        }

        if (
          restaurar &&
          val_campana !== "0" &&
          $campana.querySelector(`option[value="${val_campana}"]`)
        ) {
          $campana.value = val_campana;
        }

        $campana.disabled = false;
      })
      .catch(err => {
        console.error('Error cargando campañas:', err);
        resetSelect($campana, 'ERROR AL CARGAR');
        $campana.disabled = false;
      });
  }

  /* ============================================
     🧩 EJECUTORES DINÁMICOS
  ============================================ */
  function recargarEjecutoresMapa(restaurar = false) {
    if (!$ejecutor) return;

    const empresa = $empresa?.value || 0;
    const division = $division ? ($division.value || 0) : divisionUsuario;
    const subdivision = $subdivision?.value || 0;
    const distrito = $distrito?.value || 0;
    const tipoG = $tipo?.value || 0;
    const idCampana = $campana?.value || 0;
    const estado = $estado?.value || 0;

    const firmaActual = [empresa, division, subdivision, distrito, tipoG, idCampana, estado].join('|');
    if (!restaurar && firmaActual === firmaEjecutores) return;
    firmaEjecutores = firmaActual;

    if (xhrEjecutores && xhrEjecutores.abort) {
      xhrEjecutores.abort();
    }

    resetSelect($ejecutor, 'Cargando ejecutores...');
    $ejecutor.disabled = true;

    const params = new URLSearchParams({
      id_empresa: empresa,
      id_division: division,
      id_subdivision: subdivision,
      id_distrito: distrito,
      tipo_gestion: tipoG,
      id_campana: idCampana,
      estado: estado
    });

    xhrEjecutores = new AbortController();

    fetch(`../mod_cargar/cargar_ejecutores.php?${params.toString()}`, {
      signal: xhrEjecutores.signal
    })
      .then(r => r.json())
      .then(data => {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');

        if (data.ok && Array.isArray(data.ejecutores)) {
          data.ejecutores.forEach(e => {
            const opt = new Option(`${e.nombre} ${e.apellido}`, e.id);
            $ejecutor.add(opt);
          });
        }

        if (
          restaurar &&
          val_ejecutor &&
          $ejecutor.querySelector(`option[value="${val_ejecutor}"]`)
        ) {
          $ejecutor.value = val_ejecutor;
        } else {
          $ejecutor.value = "0";
        }

        $ejecutor.disabled = false;
      })
      .catch(err => {
        if (err.name === 'AbortError') return;
        console.error('Error cargando ejecutores:', err);
        resetSelect($ejecutor, 'ERROR AL CARGAR');
        $ejecutor.disabled = false;
      });
  }

  /* ============================================
     🔥 LÓGICA INICIAL SEGÚN TIPO DE USUARIO
  ============================================ */
  if (!isMC || divisionIsHidden) {
    if ($subdivision) {
      $subdivision.disabled = false;
      cargarSubdivisionesAuto(divisionUsuario, true);
    }
  } else if ($division) {
    $division.addEventListener('change', function () {
      const idDivision = parseInt($division.value, 10) || 0;

      resetSelect($campana, 'SELECCIONE CAMPAÑA');
      resetSelect($ejecutor, 'TODOS LOS EJECUTORES');

      if (idDivision <= 0) {
        resetSelect($subdivision, 'SIN SUBDIVISIÓN');
        $subdivision.disabled = true;
        return;
      }

      cargarSubdivisionesAuto(idDivision, false);

      setTimeout(() => {
        if ($subdivision) $subdivision.dispatchEvent(new Event('change'));
      }, 100);
    });

    if (parseInt(val_division, 10) > 0) {
      $division.value = val_division;
      cargarSubdivisionesAuto(val_division, true);
    }
  }

  /* ============================================
     🔄 SINCRONIZACIÓN ENTRE FILTROS
  ============================================ */
  if ($subdivision) {
    $subdivision.addEventListener('change', function () {
      const tipoG = parseInt($tipo?.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
      }

      if (tipoG === 0 || tipoG === 1) {
        cargarCampanasMapa(false);
      } else if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
        $campana.disabled = true;
      }

      recargarEjecutoresMapa(false);
    });
  }

  if ($campana) {
    $campana.addEventListener('change', function () {
      recargarEjecutoresMapa(false);
    });
  }

  if ($tipo) {
    $tipo.addEventListener('change', function () {
      const tipoG = parseInt($tipo.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
        $ejecutor.disabled = false;
      }

      if (tipoG === 3) {
        if ($campana) {
          $campana.disabled = true;
        }
        recargarEjecutoresMapa(false);
        return;
      }

      if (tipoG === 0 || tipoG === 1) {
        if ($campana) {
          $campana.disabled = false;
        }

        if ($subdivision) {
          $subdivision.dispatchEvent(new Event('change'));
        } else {
          recargarEjecutoresMapa(false);
        }
      }
    });
  }

  if ($estado) {
    $estado.addEventListener('change', function () {
      const tipoG = parseInt($tipo?.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
      }

      if (tipoG === 0 || tipoG === 1) {
        cargarCampanasMapa(false);
      }

      recargarEjecutoresMapa(false);
    });
  }

  if ($distrito) {
    $distrito.addEventListener('change', function () {
      recargarEjecutoresMapa(false);
    });
  }

  /* ============================================
     🔁 RESTAURACIÓN AUTOMÁTICA
  ============================================ */
  function restaurarEstadoInicial() {
    const tipoG = parseInt(val_tipo || 0, 10);

    if ($tipo) {
      $tipo.value = val_tipo || 0;
    }

    actualizarVisibilidad();

    if (!isMC || divisionIsHidden) {
      setTimeout(() => {
        if ($subdivision && val_subdivision) {
          $subdivision.value = val_subdivision;
        }

        if (tipoG === 0 || tipoG === 1) {
          cargarCampanasMapa(true);
        } else if ($campana) {
          resetSelect($campana, 'SELECCIONE CAMPAÑA');
          $campana.disabled = true;
        }

        recargarEjecutoresMapa(true);
      }, 250);
    } else {
      setTimeout(() => {
        if ($subdivision && val_subdivision) {
          $subdivision.value = val_subdivision;
        }

        if (tipoG === 0 || tipoG === 1) {
          cargarCampanasMapa(true);
        } else if ($campana) {
          resetSelect($campana, 'SELECCIONE CAMPAÑA');
          $campana.disabled = true;
        }

        recargarEjecutoresMapa(true);
      }, 250);
    }
  }

  restaurarEstadoInicial();
});

const panel = document.getElementById('panel-filtros');
const boton = document.querySelector('[data-target="#panel-filtros"]');

if (panel && boton) {
  const icono = boton.querySelector('i');

  panel.addEventListener('hide.bs.collapse', () => {
    icono?.classList.replace('fa-chevron-up', 'fa-chevron-down');
  });

  panel.addEventListener('show.bs.collapse', () => {
    icono?.classList.replace('fa-chevron-down', 'fa-chevron-up');
  });
}
</script>


</body>
</html>

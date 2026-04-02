<!DOCTYPE html>
<?php include 'mapa_data.php'; ?>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Locales - Rutas IPT</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="../../css/mapa.css">  
  <link rel="stylesheet" type="text/css" href="../../assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="../../assets/css/dataTable.css">
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>
<style>
  body {
    background:
      linear-gradient(180deg, rgba(3,10,30,0.88), rgba(2,8,24,0.96)),
      url('../../images/world-map-bg.jpg') center top / cover no-repeat;
    color: #eaf4ff;
    min-height: 100vh;
  }

  #map {
    height: 360px;
    border-radius: 0 0 18px 18px;
    overflow: hidden;
    box-shadow: inset 0 1px 0 rgba(0,255,255,0.15);
  }

  .card-panel {
    margin-top: -90px;
    z-index: 20;
    position: relative;
    max-width: 1280px;
  }

  #panel-filtros {
    background: transparent;
  }

  .map-filters-card {
    background: rgba(5, 16, 45, 0.92);
    border: 1px solid rgba(74, 116, 180, 0.18);
    border-radius: 16px;
    box-shadow:
      0 10px 30px rgba(0,0,0,0.35),
      inset 0 1px 0 rgba(120, 180, 255, 0.05);
    backdrop-filter: blur(6px);
  }

  .map-filters-body {
    padding: 24px 22px 14px;
  }
  
  .filter-group {
    margin-bottom: 18px;
  }

  .filter-label {
    display: block;
    margin-bottom: 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.8px;
    color: #16d7ff;
    text-transform: uppercase;
  }

  .filter-control {
    height: 42px;
    border-radius: 8px;
    background: #16233f;
    border: 1px solid rgba(102, 144, 205, 0.18);
    color: #dbe9ff;
    font-size: 13px;
    font-weight: 500;
    box-shadow: none !important;
    transition: all .2s ease;
  }

  .filter-control:focus {
    background: #1a2949;
    border-color: #1fd6ff;
    color: #ffffff;
    box-shadow: 0 0 0 0.15rem rgba(31, 214, 255, 0.15) !important;
  }

  .filter-control:disabled {
    background: #0f1930;
    color: #7f91b5;
    opacity: 1;
    cursor: not-allowed;
  }

  .filter-control option {
    background: #16233f;
    color: #dbe9ff;
  }

  .help {
    display: none;
  }

  .filter-search-wrap {
    text-align: center;
    padding-top: 2px;
    padding-bottom: 8px;
  }

  .filter-search-btn {
    min-width: 160px;
    height: 48px;
    border: none !important;
    border-radius: 12px;
    background: linear-gradient(90deg, #11c7f3, #1fd6ff) !important;
    color: #041225 !important;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: 1px;
    box-shadow: 0 8px 18px rgba(17, 199, 243, 0.30);
    transition: transform .2s ease, box-shadow .2s ease;
  }

  .filter-search-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(17, 199, 243, 0.36);
  }

  .filter-search-btn:focus {
    outline: none;
    box-shadow: 0 0 0 0.18rem rgba(31,214,255,0.18);
  }

  .btn-outline-secondary.btn-sm.position-absolute {
    background: rgba(8, 20, 44, 0.92) !important;
    border: 1px solid rgba(75, 117, 184, 0.25) !important;
    color: #9ddfff !important;
    border-radius: 10px;
    width: 36px;
    height: 36px;
    padding: 0;
  }


  .badge-secondary {
    background: rgba(18, 215, 255, 0.16) !important;
    color: #1fd6ff !important;
    border: 1px solid rgba(31,214,255,0.22);
  }

  @media (max-width: 991px) {
    .card-panel {
      margin-top: -50px;
    }

    .map-filters-body {
      padding: 18px 16px 10px;
    }

    .filter-search-btn {
      width: 100%;
    }
  }

  @media (max-width: 767px) {
    #map {
      height: 280px;
      border-radius: 0 0 14px 14px;
    }

    .card-panel {
      margin-top: -30px;
      padding-left: 12px;
      padding-right: 12px;
    }
  }
/* ==============================
   TABLA ESTILO DARK PREMIUM
============================== */

.table-header-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}

.table-title {
  color: #ffffff;
  font-size: 32px;
  font-weight: 800;
  letter-spacing: -0.3px;
}

.results-badge {
  background: rgba(18, 215, 255, 0.14) !important;
  color: #16d7ff !important;
  border: 1px solid rgba(22, 215, 255, 0.22);
  border-radius: 6px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .7px;
  padding: 6px 10px;
  vertical-align: middle;
}

.table-responsive {
  background: rgba(5, 16, 45, 0.78);
  border: 1px solid rgba(74, 116, 180, 0.14);
  border-radius: 16px;
  overflow: hidden;
  box-shadow:
    0 10px 30px rgba(0, 0, 0, 0.28),
    inset 0 1px 0 rgba(120, 180, 255, 0.04);
}

/* Tabla base */
table.dataTable.modern-dark-table,
table.dataTable.modern-dark-table.no-footer {
  border-bottom: none !important;
  margin-top: 0 !important;
  margin-bottom: 0 !important;
  color: #d9e6ff;
  background: transparent;
}

#example_wrapper {
  padding: 1%;
}


#example_wrapper .row:last-child {
  padding: 10px 18px 16px;
  color: #7f97c6;
  font-size: 12px;
}

/* Header */
#example thead th {
  background: transparent !important;
  color: #14d7ff !important;
  font-size: 11px !important;
  font-weight: 800 !important;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  border-bottom: 1px solid rgba(77, 106, 161, 0.24) !important;
  padding: 18px 14px !important;
  white-space: nowrap;
}

/* Filas */
#example tbody tr {
  background: transparent !important;
  transition: background .18s ease;
}

#example tbody tr:hover {
  background: rgba(20, 40, 86, 0.35) !important;
}

#example tbody td {
  color: #dce8ff !important;
  font-size: 0.7rem !important;
  font-weight: 500;
  border-top: 1px solid rgba(77, 106, 161, 0.14) !important;
  padding: 18px 14px !important;
  vertical-align: middle;
  white-space: nowrap;
}

/* Primera columna un poco más marcada */
#example tbody td:first-child {
  color: #ffffff !important;
  font-weight: 700;
}

/* Cabecera de controles */
.dataTables_length label,
.dataTables_filter label {
  color: #89a4d4 !important;
  font-size: 12px;
  font-weight: 600;
}

.dataTables_length select,
.dataTables_filter input {
  background: #16233f !important;
  border: 1px solid rgba(102, 144, 205, 0.18) !important;
  color: #dbe9ff !important;
  border-radius: 8px !important;
  min-height: 38px;
}

.dataTables_length select:focus,
.dataTables_filter input:focus {
  outline: none !important;
  box-shadow: 0 0 0 0.12rem rgba(31, 214, 255, 0.12) !important;
  border-color: #1fd6ff !important;
}

/* Botones DataTables */
.dt-buttons {
  margin-bottom: 10px;
}

.dt-button {
  background: #13213c !important;
  color: #9edfff !important;
  border: 1px solid rgba(82, 124, 189, 0.20) !important;
  border-radius: 10px !important;
  padding: 8px 12px !important;
  font-size: 12px !important;
  font-weight: 700 !important;
  transition: all .18s ease;
}

.dt-button:hover {
  background: #18294a !important;
  color: #d8f8ff !important;
  border-color: rgba(31, 214, 255, 0.28) !important;
}

/* Paginación */
.dataTables_paginate .paginate_button {
  background: transparent !important;
  border: none !important;
  color: #7f97c6 !important;
  font-size: 12px !important;
  font-weight: 700;
  border-radius: 8px !important;
}

.dataTables_paginate .paginate_button.current,
.dataTables_paginate .paginate_button.current:hover {
  background: rgba(18, 215, 255, 0.14) !important;
  color: #16d7ff !important;
  border: 1px solid rgba(22, 215, 255, 0.18) !important;
}

.dataTables_paginate .paginate_button:hover {
  background: rgba(20, 40, 86, 0.35) !important;
  color: #cfeaff !important;
}

/* Info inferior */
.dataTables_info {
  color: #6f88b9 !important;
  font-size: 11px !important;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* Empty state */
#example tbody td.dataTables_empty {
  text-align: center;
  color: #89a4d4 !important;
  padding: 30px 14px !important;
}

/* Estado tipo pill */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 0.7rem;
  font-weight: 600;
  white-space: nowrap;
}

.status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  display: inline-block;
}

.status-ok {
  color: #37e7b2;
}

.status-ok .status-dot {
  background: #37e7b2;
  box-shadow: 0 0 8px rgba(55, 231, 178, 0.45);
}

.status-pending {
  color: #7f97c6;
}

.status-pending .status-dot {
  background: #4c648e;
}

.status-progress {
  color: #ffcf4d;
}

.status-progress .status-dot {
  background: #ffcf4d;
  box-shadow: 0 0 8px rgba(255, 207, 77, 0.35);
}

/* Badges viejos, por si siguen usándose */
.badge-custom {
  border-radius: 999px;
  padding: 7px 12px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .2px;
}

.badge-completados {
  background: rgba(55, 231, 178, 0.12);
  color: #37e7b2;
  border: 1px solid rgba(55, 231, 178, 0.16);
}

.badge-pendientes {
  background: rgba(127, 151, 198, 0.12);
  color: #8aa0c7;
  border: 1px solid rgba(127, 151, 198, 0.16);
}

/* Responsive */
@media (max-width: 991px) {
  .table-title {
    font-size: 24px;
  }

  #example thead th,
  #example tbody td {
    padding: 14px 10px !important;
  }

  #example_wrapper .row:first-child,
  #example_wrapper .row:last-child {
    padding-left: 12px;
    padding-right: 12px;
  }
} 

#example tbody td:nth-child(2) {
  min-width: 260px;
}
.local-cell {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
  min-width: 220px;
}

.local-name {
  color: #ffffff;
  font-size: 0.7rem;
  font-weight: 700;
  margin-bottom: 4px;
  white-space: normal;
}

.local-address {
  color: #89a4d4;
  font-size: 0.7rem;
  font-weight: 500;
  white-space: normal;
  opacity: 0.95;
}

/* Más aire en la franja superior de controles */
#example_wrapper .row:first-child {
  padding: 18px 20px 8px !important;
  margin: 0 !important;
}

/* Que las columnas internas no queden pegadas */
#example_wrapper .row:first-child > div {
  padding-left: 10px !important;
  padding-right: 10px !important;
}

/* Buscador alineado y con margen */
#example_wrapper .dataTables_filter {
  margin-right: 6px;
  text-align: right !important;
}

#example_wrapper .dataTables_filter label {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 0;
}

/* Input buscar */
#example_wrapper .dataTables_filter input {
  width: 150px;
  margin-left: 0 !important;
  padding-left: 12px;
  padding-right: 12px;
}

/* =========================================
   OVERRIDE DATATABLES sorting_1 / hover
========================================= */

/* Estado normal de columnas ordenadas */
table.dataTable.display tbody tr > .sorting_1,
table.dataTable.display tbody tr > .sorting_2,
table.dataTable.display tbody tr > .sorting_3,
table.dataTable.order-column tbody tr > .sorting_1,
table.dataTable.order-column tbody tr > .sorting_2,
table.dataTable.order-column tbody tr > .sorting_3,
table.dataTable.order-column.stripe tbody tr.odd > .sorting_1,
table.dataTable.order-column.stripe tbody tr.odd > .sorting_2,
table.dataTable.order-column.stripe tbody tr.odd > .sorting_3,
table.dataTable.order-column.stripe tbody tr.even > .sorting_1,
table.dataTable.order-column.stripe tbody tr.even > .sorting_2,
table.dataTable.order-column.stripe tbody tr.even > .sorting_3 {
  background: transparent !important;
  background-color: transparent !important;
  color: #dce8ff !important;
  box-shadow: none !important;
}

/* Si quieres que la primera columna siga más fuerte en color */
#example tbody tr > .sorting_1:first-child,
#example tbody tr > td:first-child {
  color: #ffffff !important;
  font-weight: 700 !important;
}

/* Hover general de fila */
table.dataTable.display tbody tr:hover > td,
table.dataTable.order-column.hover tbody tr:hover > td,
table.dataTable.display tbody tr.odd:hover > td,
table.dataTable.display tbody tr.even:hover > td {
  background: rgba(20, 40, 86, 0.35) !important;
  background-color: rgba(20, 40, 86, 0.35) !important;
  color: #dce8ff !important;
}

/* Hover sobre columnas ordenadas */
table.dataTable.display tbody tr:hover > .sorting_1,
table.dataTable.display tbody tr:hover > .sorting_2,
table.dataTable.display tbody tr:hover > .sorting_3,
table.dataTable.order-column.hover tbody tr:hover > .sorting_1,
table.dataTable.order-column.hover tbody tr:hover > .sorting_2,
table.dataTable.order-column.hover tbody tr:hover > .sorting_3,
table.dataTable.order-column.stripe tbody tr.odd:hover > .sorting_1,
table.dataTable.order-column.stripe tbody tr.odd:hover > .sorting_2,
table.dataTable.order-column.stripe tbody tr.odd:hover > .sorting_3,
table.dataTable.order-column.stripe tbody tr.even:hover > .sorting_1,
table.dataTable.order-column.stripe tbody tr.even:hover > .sorting_2,
table.dataTable.order-column.stripe tbody tr.even:hover > .sorting_3 {
  background: rgba(20, 40, 86, 0.35) !important;
  background-color: rgba(20, 40, 86, 0.35) !important;
  color: #dce8ff !important;
}

/* Que no aparezcan stripes claras heredadas */
table.dataTable.stripe tbody tr.odd,
table.dataTable.display tbody tr.odd,
table.dataTable.stripe tbody tr.even,
table.dataTable.display tbody tr.even {
  background: transparent !important;
  background-color: transparent !important;
}

/* =========================================
   AJUSTE CONTROLES SUPERIORES
========================================= */


#example_wrapper .row:first-child > div:last-child {
  padding-right: 14px !important;
}

#example_wrapper .dataTables_filter {
  text-align: right !important;
  margin-right: 0 !important;
}

#example_wrapper .dataTables_filter label {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 0;
}

#example_wrapper .dataTables_filter input {
  width: 150px;
  margin-left: 0 !important;
  padding: 8px 12px !important;
}

/* =========================
   MAPA ESTILO DARK PREMIUM
========================= */

#map {
  position: relative;
  height: 430px;
  border-radius: 0 0 22px 22px;
  overflow: hidden;
  box-shadow:
    inset 0 1px 0 rgba(0,255,255,0.10),
    0 8px 30px rgba(0,0,0,0.30);
}

/* Capa azul oscura encima del mapa */
#map::after {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    linear-gradient(180deg, rgba(2, 10, 28, 0.18), rgba(3, 12, 34, 0.38)),
    radial-gradient(circle at 20% 25%, rgba(0, 214, 255, 0.10), transparent 22%),
    radial-gradient(circle at 78% 35%, rgba(0, 153, 255, 0.08), transparent 24%),
    radial-gradient(circle at 58% 65%, rgba(0, 214, 255, 0.06), transparent 28%);
  z-index: 2;
}

/* Línea superior glow */
#map::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 2px;
  z-index: 2;
  background: linear-gradient(90deg, transparent, rgba(0, 225, 255, 0.75), transparent);
  pointer-events: none;
}

/* El panel de filtros flotando sobre el mapa */
.card-panel {
  margin-top: -110px;
  z-index: 30;
  position: relative;
  max-width: 1280px;
}

/* Ajuste responsive */
@media (max-width: 991px) {
  #map {
    height: 360px;
  }

  .card-panel {
    margin-top: -60px;
  }
}

@media (max-width: 767px) {
  #map {
    height: 300px;
    border-radius: 0 0 16px 16px;
  }

  .card-panel {
    margin-top: -35px;
  }
}
#map {
  position: relative;
  height: 430px;
  border-radius: 0 0 22px 22px;
  overflow: hidden;
  box-shadow:
    inset 0 1px 0 rgba(0,255,255,0.10),
    0 8px 30px rgba(0,0,0,0.30);
}

#map::after {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    linear-gradient(180deg, rgba(2, 10, 28, 0.10), rgba(3, 12, 34, 0.26)),
    radial-gradient(circle at 20% 25%, rgba(0, 214, 255, 0.08), transparent 22%),
    radial-gradient(circle at 78% 35%, rgba(0, 153, 255, 0.06), transparent 24%),
    radial-gradient(circle at 58% 65%, rgba(0, 214, 255, 0.05), transparent 28%);
  z-index: 2;
}

#map::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 2px;
  z-index: 2;
  background: linear-gradient(90deg, transparent, rgba(0, 225, 255, 0.75), transparent);
  pointer-events: none;
}
/* =========================
   MODAL DETALLE DARK
========================= */

#modalDetalle .modal-dialog {
  max-width: 1080px;
}

#modalDetalle .modal-content {
  background: linear-gradient(180deg, rgba(6,18,46,0.98), rgba(4,14,36,0.98));
  border: 1px solid rgba(74, 116, 180, 0.20);
  border-radius: 18px;
  box-shadow:
    0 20px 60px rgba(0,0,0,0.45),
    inset 0 1px 0 rgba(120, 180, 255, 0.05);
  color: #dce8ff;
  overflow: hidden;
}

#modalDetalle .modal-header {
  border-bottom: 1px solid rgba(77, 106, 161, 0.22);
  padding: 24px 28px 18px;
  background:
    linear-gradient(180deg, rgba(10,24,58,0.95), rgba(6,18,46,0.95));
}

#modalDetalle .modal-title {
  color: #f5fbff;
  font-size: 28px;
  font-weight: 800;
  letter-spacing: -0.3px;
  margin-right: 40px;
}

#modalHeaderExtra {
  color: #8fb0db;
  font-size: 13px !important;
  line-height: 1.55 !important;
}

#modalHeaderExtra strong {
  color: #16d7ff;
  font-weight: 800;
}

#modalDetalle .close {
  color: #cfe7ff;
  opacity: 0.8;
  text-shadow: none;
}

#modalDetalle .close:hover {
  color: #ffffff;
  opacity: 1;
}

#modalDetalle .modal-body {
  padding: 24px 28px 28px;
  background: transparent;
}

/* Grid de resumen */
.modal-kpi-card {
  background: rgba(12, 27, 61, 0.82);
  border: 1px solid rgba(89, 124, 184, 0.20);
  border-radius: 14px;
  padding: 18px 16px;
  min-height: 108px;
  box-shadow: inset 0 1px 0 rgba(120, 180, 255, 0.03);
}

.modal-kpi-label {
  color: #16d7ff;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  margin-bottom: 10px;
}

.modal-kpi-value {
  color: #f4f9ff;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.2;
}

.modal-kpi-subvalue {
  color: #89a4d4;
  font-size: 13px;
  font-weight: 600;
  margin-top: 4px;
}

/* Tarjeta de gestión */
.modal-section-card {
  background: rgba(12, 27, 61, 0.82);
  border: 1px solid rgba(89, 124, 184, 0.20);
  border-radius: 14px;
  overflow: hidden;
}

.modal-section-header {
  padding: 14px 18px;
  border-bottom: 1px solid rgba(77, 106, 161, 0.18);
  color: #16d7ff;
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  background: rgba(8, 20, 48, 0.75);
}

.modal-section-body {
  padding: 18px;
  color: #dce8ff;
  font-size: 14px;
}

/* Chips de estado */
.modal-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
  line-height: 1;
}

.modal-status-pill::before {
  content: "";
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}

.modal-status-ok {
  background: rgba(34,197,94,0.14);
  color: #4ade80;
  border: 1px solid rgba(34,197,94,0.18);
}
.modal-status-ok::before {
  background: #4ade80;
  box-shadow: 0 0 8px rgba(74,222,128,0.35);
}

.modal-status-bad {
  background: rgba(255,77,79,0.14);
  color: #ff6b6d;
  border: 1px solid rgba(255,77,79,0.18);
}
.modal-status-bad::before {
  background: #ff6b6d;
  box-shadow: 0 0 8px rgba(255,107,109,0.35);
}

.modal-status-neutral {
  background: rgba(59,130,246,0.14);
  color: #60a5fa;
  border: 1px solid rgba(59,130,246,0.18);
}
.modal-status-neutral::before {
  background: #60a5fa;
  box-shadow: 0 0 8px rgba(96,165,250,0.35);
}

@media (max-width: 767px) {
  #modalDetalle .modal-header {
    padding: 18px 18px 14px;
  }

  #modalDetalle .modal-title {
    font-size: 20px;
  }

  #modalDetalle .modal-body {
    padding: 18px;
  }

  .modal-kpi-value {
    font-size: 17px;
  }
}

.export-only {
  display: none;
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
            <option value="0" selected>SELECCIONE EJECUTOR</option>
          </select>
          <small class="help">SI NO ELIGES CAMPAÑA, VERÁS TODAS SUS RUTAS.</small>
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-desde" style="display:none">
          <label for="desde" class="filter-label">DESDE</label>
          <input type="date" class="form-control filter-control" id="desde" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-hasta" style="display:none">
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
    <div class="table-header-bar mb-3">
      <h5 class="table-title mb-0">
        Listado de Locales <?= ($id_ejecutor > 0 && !empty($nombreEjec)) ? 'para ' . htmlspecialchars($nombreEjec) : '' ?>
        <?php if (!empty($locales)): ?>
          <span class="badge results-badge ml-2"><?= count($locales) ?> RESULTADOS</span>
        <?php endif; ?>
      </h5>
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
        $idLocal      = htmlspecialchars($loc['id_local']);
        $nombreCamp   = htmlspecialchars($loc['nombre_campana']);
        $codigoLocal  = htmlspecialchars($loc['codigo']);
        $nombreLoc    = htmlspecialchars($loc['nombre_local']);
        $dirLoc       = htmlspecialchars($loc['direccion_local']);
        $comunaLoc    = htmlspecialchars($loc['comuna_local']);
        $regionLoc    = htmlspecialchars($loc['region_local']);
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
      <tr>
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

        <td><?= $usuarioLoc ?></td>
        <td class="text-center"><?= $badgeEstado ?></td>
        <td class="text-center"><?= $fechaP ?></td>
        <td class="text-center"><?= $fechaV ?></td>
        <td><?= htmlspecialchars($prg) ?></td>
        <td><?= $observacion ?></td>
      </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="12" class="text-center">SIN RESULTADOS.</td></tr>
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
<script src="../../assets/js/dataTable.js"></script>
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
  const coordenadasLocales = <?= json_encode($coordenadas_locales, JSON_UNESCAPED_UNICODE) ?>;
  const idEjecutor = <?= (int)$id_ejecutor ?>;
</script>
<script src="../../js/mapa.js"></script>
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
  const valor = parseInt(tipoGestion?.value || 0);
  if (!campoCampana || !campoDesde || !campoHasta) return;


  campoCampana.style.display = (valor === 0 || valor === 1) ? "block" : "none";

  
  const mostrarFechas = (valor === 1 || valor === 3);
  campoDesde.style.display = mostrarFechas ? "block" : "none";
  campoHasta.style.display = mostrarFechas ? "block" : "none";
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
      if (selectedVal !== null && String(selectedVal) === String(o.id)) opt.selected = true;
      select.add(opt);
    });
  }
 

  /* ============================================
     🧩 ELEMENTOS DEL FORMULARIO
  ============================================ */
  const $division = document.getElementById('id_division');
  const divisionIsHidden = ! $division;  
  const $subdivision = document.getElementById('id_subdivision');
  const $campana = document.getElementById('id_campana');
  const $ejecutor = document.getElementById('id_ejecutor');
  const $estado = document.getElementById('estado');
  const $tipo = document.getElementById('tipo_gestion');
  const $empresa = document.querySelector('input[name="empresa_id"]') || { value: '<?= $id_empresa ?>' };
  const $distrito = document.getElementById('id_distrito');

  // 🔹 Valores guardados desde PHP
  const val_division = document.getElementById('val_division')?.value || 0;
  const val_subdivision = document.getElementById('val_subdivision')?.value || 0;
  const val_campana = document.getElementById('val_campana')?.value || 0;
  const val_ejecutor = document.getElementById('val_ejecutor')?.value || 0;
  const val_tipo = document.getElementById('val_tipo')?.value || 0;

/* ============================================
   🧩 SUBDIVISIONES DINÁMICAS + RESTAURACIÓN
============================================ */

function cargarSubdivisionesAuto(idDivision) {

    $subdivision.disabled = false; // SIEMPRE habilitado para no MC
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

            // Restaurar selección si aplica
            if (
                val_subdivision !== "0" &&
                $subdivision.querySelector(`option[value="${val_subdivision}"]`)
            ) {
                $subdivision.value = val_subdivision;
                $subdivision.dispatchEvent(new Event('change'));
            }
        })
        .catch(err => {
            console.error('Error cargando subdivisiones:', err);
            resetSelect($subdivision, 'ERROR AL CARGAR');
            $subdivision.disabled = false;
        });
}


/* ============================================
   🔥 LÓGICA PARA USUARIO NO MC
============================================ */
if (!isMC || divisionIsHidden) {

    // Siempre habilitado para NO MC
    $subdivision.disabled = false;

    // Cargar subdivisiones automáticamente usando su división nativa
    cargarSubdivisionesAuto(divisionUsuario);

} else {

    /* ============================================
       ✔ LÓGICA PARA MC (con select visible)
    ============================================ */
    $division.addEventListener('change', function () {
        const idDivision = parseInt($division.value, 10) || 0;

        resetSelect($campana, 'SELECCIONE CAMPAÑA');
        resetSelect($ejecutor, 'SELECCIONE EJECUTOR');

        if (idDivision <= 0) {
            resetSelect($subdivision, 'SIN SUBDIVISIÓN');
            $subdivision.disabled = true;
            return;
        }

        $subdivision.disabled = false;
        cargarSubdivisionesAuto(idDivision);
    });

    if (parseInt(val_division) > 0) {
        $division.value = val_division;
        cargarSubdivisionesAuto(val_division);
    }
}
  /* ============================================
     🧩 CAMPANAS DINÁMICAS
  ============================================ */
  if ($subdivision) {
    $subdivision.addEventListener('change', function () {
      const division = $division ? $division.value : divisionUsuario;
      const subdiv = $subdivision?.value || 0;
      const estado = $estado?.value || 1;
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

          $campana.disabled = false;

          // ✅ Restaurar selección si aplica
          if (val_campana !== "0" && $campana.querySelector(`option[value="${val_campana}"]`)) {
            $campana.value = val_campana;
            $campana.dispatchEvent(new Event('change'));
          }
        })
        .catch(err => {
          console.error('Error cargando campañas:', err);
          resetSelect($campana, 'ERROR AL CARGAR');
          $campana.disabled = false;
        });
    });
  }

  /* ============================================
     🧩 EJECUTORES DINÁMICOS + RESTAURACIÓN
  ============================================ */
function cargarEjecutores({ idCampana = 0 } = {}) {
  const empresa = $empresa.value || 0;
  const division = $division?.value || 0;
  const tipoG = $tipo?.value || 0;
  const distrito = $distrito?.value || 0;

  resetSelect($ejecutor, 'Cargando ejecutores...');
  $ejecutor.disabled = true;

  fetch(`../mod_cargar/cargar_ejecutores.php?id_empresa=${empresa}&id_campana=${idCampana}&id_division=${division}&id_distrito=${distrito}&tipo_gestion=${tipoG}`)
    .then(r => r.json())
    .then(data => {
      resetSelect($ejecutor, 'SELECCIONE EJECUTOR');
      if (data.ok && Array.isArray(data.ejecutores)) {
        data.ejecutores.forEach(e => {
          const opt = new Option(`${e.nombre} ${e.apellido}`, e.id);
          $ejecutor.add(opt);
        });
      }

      // ✅ Restaurar ejecutor solo si coincide con el tipo actual
      if (
        val_ejecutor &&
        $ejecutor.querySelector(`option[value="${val_ejecutor}"]`) &&
        parseInt($tipo.value) === parseInt(val_tipo)
      ) {
        $ejecutor.value = val_ejecutor;
      }

      $ejecutor.disabled = false;
    })
    .catch(err => {
      console.error('Error cargando ejecutores:', err);
      resetSelect($ejecutor, 'ERROR AL CARGAR');
      $ejecutor.disabled = false;
    });
}

/* =========================================================
   🔄 SINCRONIZACIÓN ENTRE CAMPANA / RUTA / EJECUTOR
========================================================= */
// Cuando cambia campaña → cargar ejecutores
if ($campana) {
  $campana.addEventListener('change', function () {
    const idCampana = parseInt($campana.value, 10) || 0;
    resetSelect($ejecutor, 'Cargando ejecutores...');
    cargarEjecutores({ idCampana });
  });
}

// Cuando cambia tipo de gestión
if ($tipo) {
  $tipo.addEventListener('change', function () {
    const tipoG = parseInt($tipo.value, 10);

    // Limpia selects dependientes
    resetSelect($campana, 'SELECCIONE CAMPAÑA');
    resetSelect($ejecutor, 'SELECCIONE EJECUTOR');
    $campana.disabled = true;
    $ejecutor.disabled = true;

    if (tipoG === 3) { 
      cargarEjecutores({ idCampana: 0 });
      $ejecutor.disabled = false;
    }
    
    if (tipoG === 0 || tipoG === 1) {
      $subdivision.dispatchEvent(new Event('change'));
      $campana.disabled = false;
    }
  });
}

/* =========================================================
   🔁 RESTAURACIÓN AUTOMÁTICA TRAS "BUSCAR"
========================================================= */
if (parseInt(val_tipo) === 1 && parseInt(val_campana) > 0) {
  // Modo campaña
  cargarEjecutores({ idCampana: val_campana });
} else if (parseInt(val_tipo) === 3) {
  // Modo ruta
  cargarEjecutores({ idCampana: 0 });
}

  // ✅ Carga según tipo de gestión
  if ($campana) {
    $campana.addEventListener('change', function () {
      const idCampana = parseInt($campana.value, 10) || 0;
      cargarEjecutores({ idCampana });
    });
  }

  if ($tipo) {
    $tipo.addEventListener('change', function () {
      const tipoG = parseInt($tipo.value, 10);
      if (tipoG === 3) { // Ruta
        cargarEjecutores({ idCampana: 0 });
      }
    });
  }

  // ✅ Restauración automática al cargar tras "Buscar"
  if (parseInt(val_tipo) === 1 && parseInt(val_campana) > 0) {
    cargarEjecutores({ idCampana: val_campana });
  } else if (parseInt(val_tipo) === 3) {
    cargarEjecutores({ idCampana: 0 });
  }
  
  
if ($estado) {
  $estado.addEventListener('change', function () {
    const tipoG = parseInt($tipo?.value || 0, 10);

    resetSelect($campana, 'SELECCIONE CAMPAÑA');
    resetSelect($ejecutor, 'SELECCIONE EJECUTOR');

    if (tipoG === 3) {
      $campana.disabled = true;
      cargarEjecutores({ idCampana: 0 });
      return;
    }

    $ejecutor.disabled = false;

    if ($subdivision) {
      $subdivision.dispatchEvent(new Event('change'));
    }
  });
}
  
});



const panel = document.getElementById('panel-filtros');
const boton = document.querySelector('[data-target="#panel-filtros"]');
const icono = boton.querySelector('i');

panel.addEventListener('hide.bs.collapse', () => {
  icono.classList.replace('fa-chevron-up', 'fa-chevron-down');
});

panel.addEventListener('show.bs.collapse', () => {
  icono.classList.replace('fa-chevron-down', 'fa-chevron-up');
});
</script>


</body>
</html>

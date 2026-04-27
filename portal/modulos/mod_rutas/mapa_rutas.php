<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');

header('Content-Type: text/html; charset=UTF-8');

if (isset($con) && $con instanceof mysqli) {
    mysqli_set_charset($con, 'utf8mb4');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planificador de Rutas - Visibility 2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
    :root{
        --bg: #f3f6fb;
        --card: #ffffff;
        --stroke: #dbe6f5;
        --stroke-strong: #c9d9ef;
        --text: #183153;
        --muted: #6b7a90;
        --primary: #1e6df0;
        --primary-dark: #0f4fbe;
        --primary-soft: #eaf2ff;
        --teal: #1fc7b6;
        --teal-soft: #e8fbf8;
        --green: #13b981;
        --green-soft: #e8fbf2;
        --danger: #ef4444;
        --danger-soft: #fdecec;
        --warning: #f59e0b;
        --warning-soft: #fff7e6;
        --shadow: 0 10px 30px rgba(30, 57, 102, .08);
        --radius-xl: 24px;
        --radius-lg: 18px;
        --radius-md: 14px;
    }

    body {
        background: linear-gradient(180deg, #f6f8fc 0%, #eef3fb 100%);
        font-family: 'Segoe UI', Tahoma, sans-serif;
        color: var(--text);
    }

    .container {
        margin-top: 34px;
        margin-bottom: 40px;
        max-width: 1360px;
    }

    .page-header{
        text-align:center;
        margin-bottom: 26px;
    }

    .page-title{
        font-size: 2.3rem;
        font-weight: 800;
        color: #12376b;
        margin-bottom: 10px;
        letter-spacing: -.4px;
    }

    .page-title i{
        color: var(--primary);
        margin-right: 8px;
    }

    .page-subtitle{
        max-width: 930px;
        margin: 0 auto;
        color: var(--muted);
        font-size: 1.08rem;
        line-height: 1.65;
    }

    .card-modern{
        background: var(--card);
        border: 1px solid rgba(209, 222, 242, .9);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .section-header{
        background: linear-gradient(90deg, #1e6df0 0%, #45c4e9 100%);
        color: #fff;
        padding: 16px 22px;
        font-weight: 700;
        font-size: 1.05rem;
        display:flex;
        align-items:center;
        gap:10px;
    }

    .upload-card{
        padding: 22px;
        margin-bottom: 18px;
    }

    .upload-label{
        display:block;
        font-weight: 700;
        font-size: 1.05rem;
        margin-bottom: 12px;
        color: #1d3355;
    }

    .upload-row{
        display:grid;
        grid-template-columns: 1fr 340px;
        gap:18px;
        align-items:center;
    }

    .file-field-wrap{
        border: 1.5px solid var(--stroke-strong);
        border-radius: 16px;
        background:#fff;
        padding: 12px 14px;
    }

    .custom-file-shell{
        display:flex;
        align-items:center;
        gap:12px;
    }

    .custom-file-shell i{
        color: var(--muted);
        font-size: 1.1rem;
        flex: 0 0 auto;
    }

    .custom-file-shell input[type=file]{
        width:100%;
        border:none;
        outline:none;
        background:transparent;
        color: var(--text);
    }

    .helper-text{
        color: var(--muted);
        font-size: .98rem;
        margin-top: 10px;
    }

    .btn-modern-primary,
    .btn-modern-success{
        border:none;
        border-radius: 16px;
        padding: 16px 22px;
        font-weight: 700;
        font-size: 1.05rem;
        box-shadow: none;
        transition: .2s ease;
    }

    .btn-modern-primary{
        background: linear-gradient(135deg, #1e6df0 0%, #2563eb 100%);
        color:#fff;
    }

    .btn-modern-primary:hover{
        transform: translateY(-1px);
        background: linear-gradient(135deg, #135fdc 0%, #1d56d0 100%);
        color:#fff;
    }

    .btn-modern-success{
        background: linear-gradient(135deg, #10b981 0%, #0f9f73 100%);
        color:#fff;
    }

    .btn-modern-success:hover{
        transform: translateY(-1px);
        color:#fff;
    }

    .plan-card{
        margin-bottom: 22px;
    }

    .plan-body{
        padding: 14px;
        background: linear-gradient(180deg, #fdfefe 0%, #f8fbff 100%);
    }

    .route-layout{
        display:grid;
        grid-template-columns: 1.35fr .95fr;
        gap:16px;
        align-items:stretch;
    }

    .map-panel{
        background:#fff;
        border: 1px solid var(--stroke);
        border-radius: 18px;
        padding: 10px;
        display:flex;
        flex-direction:column;
        margin-top: 2.2%;    
    }

    #map {
        height: 510px;
        border-radius: 16px;
        background: #e9eef7;
        overflow:hidden;
    }

    .map-legend{
        display:flex;
        flex-wrap:wrap;
        gap:10px 18px;
        align-items:center;
        padding: 14px 4px 2px;
        color: var(--muted);
        font-size: .95rem;
    }

    .legend-dot{
        width:10px;
        height:10px;
        border-radius:50%;
        display:inline-block;
        margin-right:6px;
    }

    .side-panel{
        display:grid;
        grid-template-rows: auto auto;
        gap:14px;
    }

    .preview-card,
    .kpi-row,
    .planner-box{
        background:#fff;
        border: 1px solid var(--stroke);
        border-radius: 18px;
    }

    .preview-card{
        padding: 16px;
    }

    .preview-head{
        display:flex;
        align-items:center;
        gap:10px;
        color: var(--primary);
        font-size: 1.05rem;
        font-weight: 800;
        margin-bottom: 14px;
    }

    .route-preview-grid{
        display:grid;
        grid-template-columns: 1fr 300px;
        gap:14px;
        align-items:start;
    }

    .route-list{
        min-height: 278px;
        max-height: 278px;
        overflow:auto;
        padding-right: 6px;
    }

    .route-item{
        display:flex;
        align-items:center;
        gap:12px;
        margin-bottom: 12px;
    }

    .route-item:last-child{
        margin-bottom:0;
    }

    .route-num{
        width:34px;
        height:34px;
        min-width:34px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        background: linear-gradient(135deg, #1fc7b6 0%, #17b4aa 100%);
        color:#fff;
        font-weight:800;
        box-shadow: 0 4px 10px rgba(31, 199, 182, .18);
    }

    .route-line{
        position:relative;
    }

    .route-line:not(:last-child)::after{
        content:'';
        position:absolute;
        left:16px;
        top:36px;
        width:2px;
        height:18px;
        background: #bfeae4;
    }

    .route-text{
        flex:1;
        min-width:0;
    }

    .route-code{
        font-weight:700;
        color:#274268;
        margin-bottom:4px;
    }

    .route-meta{
        height:8px;
        background:#e8f0fb;
        border-radius:999px;
        width:100%;
        overflow:hidden;
    }

    .route-meta::before{
        content:'';
        display:block;
        height:100%;
        width:72%;
        border-radius:999px;
        background: linear-gradient(90deg, #c8d9f7 0%, #dce9ff 100%);
    }

    .mini-map{
        border:1px solid var(--stroke);
        border-radius:16px;
        height:278px;
        position:relative;
        overflow:hidden;
        background:
            linear-gradient(0deg, rgba(255,255,255,.70), rgba(255,255,255,.70)),
            url('data:image/svg+xml;utf8,\
            <svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">\
                <rect width="400" height="300" fill="%23eef4fb"/>\
                <path d="M40 20 L170 160 L250 80 L340 230" stroke="%23c9d8ea" stroke-width="18" fill="none" stroke-linecap="round"/>\
                <path d="M10 120 L380 60" stroke="%23d9e5f2" stroke-width="12" fill="none" stroke-linecap="round"/>\
                <path d="M60 280 L300 40" stroke="%23dce7f5" stroke-width="10" fill="none" stroke-linecap="round"/>\
                <path d="M0 200 L400 200" stroke="%23dfe9f7" stroke-width="14" fill="none" stroke-linecap="round"/>\
                <path d="M220 0 L220 300" stroke="%23e4edf9" stroke-width="12" fill="none" stroke-linecap="round"/>\
                <path d="M100 0 L100 300" stroke="%23edf3fb" stroke-width="8" fill="none" stroke-linecap="round"/>\
                <path d="M0 80 L400 260" stroke="%23e9f1fb" stroke-width="8" fill="none" stroke-linecap="round"/>\
                <rect x="300" y="220" width="80" height="80" fill="%23cde8ff" opacity=".45"/>\
            </svg>');
        background-size: cover;
        background-position:center;
    }

    .mini-map svg{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
    }

    .kpi-row{
        padding: 0;
        overflow:hidden;
    }

    .kpi-grid{
        display:grid;
        grid-template-columns: repeat(3, 1fr);
        gap:0;
    }

    .kpi-card{
        padding: 18px 16px;
        border-right:1px solid var(--stroke);
        min-height: 140px;
        display:flex;
        align-items:center;
        gap:14px;
    }

    .kpi-card:last-child{
        border-right:none;
    }

    .kpi-icon{
        width:58px;
        height:58px;
        min-width:58px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size: 1.5rem;
    }

    .kpi-card.time .kpi-icon{
        background:#e8f1ff;
        color:#1f6fe5;
    }

    .kpi-card.distance .kpi-icon{
        background:#e7fbf7;
        color:#0fa893;
    }

    .kpi-card.fuel .kpi-icon{
        background:#f3eaff;
        color:#7a44dd;
    }

    .kpi-label{
        color:var(--muted);
        font-weight:600;
        font-size:.98rem;
        margin-bottom:2px;
    }

    .kpi-value{
        color:#183153;
        font-size: 2rem;
        line-height:1.05;
        font-weight:800;
        margin-bottom:6px;
    }

    .kpi-foot{
        color: var(--green);
        font-weight:700;
        font-size:.98rem;
    }

    .planner-box{
        margin-top: 18px;
        padding: 18px;
    }

    .planner-box .row > div{
        margin-bottom: 14px;
    }

    .planner-title{
        font-weight: 800;
        color:#15345c;
        margin-bottom: 12px;
        display:flex;
        align-items:center;
        gap:8px;
    }

    .planner-help{
        font-size:.92rem;
        color:var(--muted);
        margin-top:8px;
        line-height:1.45;
    }

    .planner-summary{
        border:1px dashed #cfe0f6;
        border-radius:16px;
        background:#f7fbff;
        padding:16px;
        color:#3d5476;
        min-height:100%;
    }

    .summary-item{
        display:flex;
        justify-content:space-between;
        gap:16px;
        font-size:.95rem;
        padding:7px 0;
        border-bottom:1px dashed #dce8f7;
    }

    .summary-item:last-child{
        border-bottom:none;
    }

    .summary-item strong{
        color:#16345f;
    }

    .hidden-soft{
        display:none !important;
    }

    .table-card{
        margin-top: 18px;
    }

    .table-card .card-header{
        border:none;
        padding: 16px 20px;
        font-weight:800;
        border-radius: 18px 18px 0 0 !important;
    }

    .header-primary{
        background: linear-gradient(90deg, #1e6df0 0%, #45c4e9 100%);
        color:#fff;
    }

    .header-danger{
        background: linear-gradient(90deg, #ef4444 0%, #f97316 100%);
        color:#fff;
    }

    .table-card .card-body{
        padding: 0;
        background:#fff;
    }

    .table-responsive {
        max-height: 420px;
        overflow-y: auto;
        border-radius: 0 0 18px 18px;
    }

    table{
        margin:0;
    }

    table thead {
        background-color: #edf4ff;
        color: #1e4274;
        position: sticky;
        top: 0;
        z-index: 2;
    }

    table thead th{
        font-weight:800;
        font-size:.92rem;
        border-bottom:1px solid #dbe7f7 !important;
        padding: 14px 16px !important;
        white-space:nowrap;
    }

    table tbody td{
        padding: 14px 16px !important;
        vertical-align: middle;
        border-color:#edf2fa !important;
    }

    table tbody tr:hover {
        background-color: #f7fbff;
    }

    .row-clickable {
        cursor: pointer;
    }

    .badge-soft-success,
    .badge-soft-warning,
    .badge-soft-danger{
        border-radius:999px;
        padding:8px 12px;
        font-weight:700;
        font-size:.84rem;
        display:inline-flex;
        align-items:center;
        gap:6px;
    }

    .badge-soft-success {
        background: #dcfce7;
        color: #166534;
    }

    .badge-soft-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-soft-danger{
        background:#fee2e2;
        color:#991b1b;
    }

    .highlight-row {
        background-color: #eaf4ff !important;
    }

    .empty-box {
        padding: 28px 20px;
        text-align: center;
        color: #6c757d;
    }

    .resumen-grid{
        display:grid;
        grid-template-columns: repeat(4, 1fr);
        gap:16px;
        margin-bottom:18px;
    }

    .stat-box {
        border-radius: 18px;
        padding: 20px;
        background: #fff;
        box-shadow: var(--shadow);
        border:1px solid var(--stroke);
        text-align: left;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom:8px;
    }

    .stat-label {
        font-size: 0.96rem;
        color: #6c757d;
        margin-top: 6px;
        line-height:1.35;
    }

    .text-primary-strong{ color:#1f6fe5; }
    .text-success-strong{ color:#0f9f73; }
    .text-info-strong{ color:#00a7d1; }
    .text-danger-strong{ color:#e23c3c; }

    @media (max-width: 1199px){
        .route-layout{
            grid-template-columns: 1fr;
        }

        .route-preview-grid{
            grid-template-columns: 1fr;
        }

        .mini-map{
            height:240px;
        }

        .kpi-grid{
            grid-template-columns: 1fr;
        }

        .kpi-card{
            border-right:none;
            border-bottom:1px solid var(--stroke);
        }

        .kpi-card:last-child{
            border-bottom:none;
        }
    }

    @media (max-width: 991px){
        .upload-row{
            grid-template-columns: 1fr;
        }

        .resumen-grid{
            grid-template-columns: repeat(2, 1fr);
        }

        #map{
            height:420px;
        }
    }

    @media (max-width: 575px){
        .page-title{
            font-size: 1.8rem;
        }

        .resumen-grid{
            grid-template-columns: 1fr;
        }

        .container{
            margin-top: 22px;
        }

        .upload-card,
        .plan-body,
        .preview-card,
        .planner-box{
            padding-left:14px;
            padding-right:14px;
        }

        #map{
            height:340px;
        }
    }
    .btn-cargar{
    margin-bottom: 10%;    
    }    
</style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-map-location-dot"></i> Planificador de Rutas
        </h1>
        <p class="page-subtitle">
            Carga un archivo CSV con codigos de locales para validar su existencia, georreferencia
            y generar una propuesta de ruta optima con restriccion de distancia entre puntos consecutivos.
        </p>
    </div>

    <!-- FORMULARIO SUPERIOR -->
    <div class="card-modern upload-card">
        <form id="formCSV" enctype="multipart/form-data" method="POST">
            <label for="csvFile" class="upload-label">Archivo CSV</label>

            <div class="upload-row">
                <div>
                    <div class="file-field-wrap">
                        <div class="custom-file-shell">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" id="csvFile" name="csvFile" accept=".csv" required>
                        </div>
                    </div>
                    <div class="helper-text">
                        Debe contener al menos una columna con el codigo del local.
                    </div>
                </div>

                <div class="btn-cargar">
                    <button type="submit" class="btn btn-modern-primary w-100" id="btnSubmitCsv">
                        <i class="fa-solid fa-upload"></i> Cargar locales
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- RESUMEN -->
    <div class="resumen-grid hidden-soft" id="resumenBloques">
        <div class="stat-box">
            <div class="stat-number text-primary-strong" id="statTotalCsv">0</div>
            <div class="stat-label">Codigos cargados desde CSV</div>
        </div>

        <div class="stat-box">
            <div class="stat-number text-success-strong" id="statEncontrados">0</div>
            <div class="stat-label">Locales encontrados en el sistema</div>
        </div>

        <div class="stat-box">
            <div class="stat-number text-info-strong" id="statConCoords">0</div>
            <div class="stat-label">Locales con coordenadas validas</div>
        </div>

        <div class="stat-box">
            <div class="stat-number text-danger-strong" id="statNoEncontrados">0</div>
            <div class="stat-label">Codigos no encontrados</div>
        </div>
    </div>

    <!-- PROPUESTA DE RUTA -->
    <div class="card-modern plan-card hidden-soft" id="cardPlanificacionRuta">
        <div class="section-header">
            <i class="fa-solid fa-route"></i>
            <span>Propuesta de Ruta</span>
        </div>

        <div class="plan-body">
            <div class="route-layout">
                <!-- MAPA -->
                <div class="map-panel">
                    <div id="map"></div>

                    <div class="map-legend">
                        <span><span class="legend-dot" style="background:#ef4444;"></span> Local disponible</span>
                        <span><span class="legend-dot" style="background:#22c55e;"></span> Local seleccionado</span>
                        <span><span class="legend-dot" style="background:#1e6df0;"></span> Ruta visual</span>
                    </div>
                </div>

                <!-- PANEL DERECHO -->
                <div class="side-panel">
                    <div class="planner-box h-100">
                        <div class="planner-title">
                            <i class="fa-solid fa-sliders"></i> Configuracion de planificacion
                        </div>
                
                        <div class="row g-3 align-items-stretch">
                            <div class="col-12">
                                <label for="cantidadPorDia" class="form-label fw-semibold">Cantidad objetivo por dia</label>
                                <div class="input-group">
                                    <span class="input-group-text">Locales</span>
                                    <input type="number" min="1" step="1" value="10" class="form-control" id="cantidadPorDia">
                                </div>
                                <div class="planner-help">
                                    El sistema intentara respetar esta cantidad al distribuir las rutas.
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="minLocalesRuta" class="form-label fw-semibold">Mínimo de locales por ruta</label>
                                <div class="input-group">
                                    <span class="input-group-text">Mínimo</span>
                                    <input type="number" min="1" step="1" value="7" class="form-control" id="minLocalesRuta">
                                </div>
                                <div class="planner-help">
                                    Solo las rutas que tengan esta cantidad o más de locales entrarán a la planificación final.
                                </div>
                            </div>
                
                            <div class="col-12">
                                <label for="maxKmRuta" class="form-label fw-semibold">Distancia maxima entre puntos</label>
                                <div class="input-group">
                                    <input type="number" min="1" step="1" value="80" class="form-control" id="maxKmRuta">
                                    <span class="input-group-text">KM</span>
                                </div>
                                <div class="planner-help">
                                    Si un salto supera este limite, la ruta debera separarse.
                                </div>
                            </div>
                
                            <div class="col-12">
                                <label for="fechaInicioRuta" class="form-label fw-semibold">Fecha de inicio</label>
                                <input type="date" class="form-control" id="fechaInicioRuta">
                                <div class="planner-help">
                                    La primera ruta comenzara desde esta fecha.
                                </div>
                            </div>
                
                            <div class="col-12">
                                <label class="form-label fw-semibold">Resumen de planificacion</label>
                                <div class="planner-summary" id="resumenPlanificacionRuta">
                                    Aún no hay datos para planificar.
                                </div>
                            </div>
                
                            <div class="col-12">
                                <button type="button" class="btn btn-modern-success w-100" id="btnProcesarPlanificacion">
                                    <i class="fa-solid fa-file-excel"></i> Generar Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLAS -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card table-card hidden-soft" id="tablaEncontradosContainer">
                <div class="card-header header-primary">
                    <i class="fa-solid fa-store"></i> Locales encontrados en el sistema
                </div>

                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaEncontrados">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Codigo</th>
                                <th>Direccion</th>
                                <th>Comuna</th>
                                <th style="width: 170px;">Estado</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                    <div id="tablaEncontradosVacia" class="empty-box" style="display:none;">
                        No se encontraron locales existentes en el sistema.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card table-card hidden-soft" id="tablaNoEncontradosContainer">
                <div class="card-header header-danger">
                    <i class="fa-solid fa-circle-exclamation"></i> Codigos no encontrados
                </div>

                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaNoEncontrados">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Codigo</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                    <div id="tablaNoEncontradosVacia" class="empty-box" style="display:none;">
                        Todos los codigos existen en el sistema.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LIBRERiAS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>

<script>
let mapa;
let infoWindow;
let markers = [];
let markerByCodigo = {};
let localesEncontrados = [];
let localesNoEncontrados = [];
let localesSeleccionados = [];
let routePolyline = null;

function initMap() {
    mapa = new google.maps.Map(document.getElementById('map'), {
        zoom: 5,
        center: { lat: -33.45, lng: -70.66 },
        mapTypeId: 'roadmap',
        streetViewControl: true,
        mapTypeControl: true,
        fullscreenControl: true
    });

    infoWindow = new google.maps.InfoWindow();
}

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function localTieneCoords(local) {
    return (
        local &&
        local.lat !== null && local.lat !== '' &&
        local.lng !== null && local.lng !== '' &&
        !isNaN(parseFloat(local.lat)) &&
        !isNaN(parseFloat(local.lng))
    );
}

function limpiarMapa() {
    if (infoWindow) infoWindow.close();

    markers.forEach(marker => marker.setMap(null));
    markers = [];
    markerByCodigo = {};

    if (routePolyline) {
        routePolyline.setMap(null);
        routePolyline = null;
    }
}

function getLocalesObjetivo() {
    if (localesSeleccionados.length > 0) {
        return localesEncontrados.filter(local => localesSeleccionados.includes(local.codigo));
    }
    return [...localesEncontrados];
}

function getPlanStats() {
    const cantidadPorDia = Math.max(parseInt($('#cantidadPorDia').val(), 10) || 1, 1);
    const maxKmRuta = Math.max(parseFloat($('#maxKmRuta').val()) || 80, 1);

    const localesObjetivo = getLocalesObjetivo();
    const localesConCoords = localesObjetivo.filter(localTieneCoords);
    const localesSinCoords = localesObjetivo.filter(local => !localTieneCoords(local));
    const diasPlanificados = localesConCoords.length > 0
        ? Math.ceil(localesConCoords.length / cantidadPorDia)
        : 0;

    const promedioReal = diasPlanificados > 0
        ? (localesConCoords.length / diasPlanificados).toFixed(2)
        : '0.00';

    return {
        cantidadPorDia,
        maxKmRuta,
        localesObjetivo,
        localesConCoords,
        localesSinCoords,
        diasPlanificados,
        promedioReal
    };
}

function actualizarResumen(totalCsv, encontrados, noEncontrados, conCoords) {
    $('#statTotalCsv').text(totalCsv);
    $('#statEncontrados').text(encontrados);
    $('#statNoEncontrados').text(noEncontrados);
    $('#statConCoords').text(conCoords);
    $('#resumenBloques').removeClass('hidden-soft');
}

function setMarkerSelectionState(codigo) {
    const marker = markerByCodigo[codigo];
    if (!marker) return;

    const seleccionado = localesSeleccionados.includes(codigo);

    marker.setIcon(
        seleccionado
            ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
            : 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
    );
}

function abrirInfoLocal(local, marker) {
    if (!infoWindow || !marker) return;

    infoWindow.setContent(`
        <div style="min-width:220px;">
            <div><strong>Codigo:</strong> ${escapeHtml(local.codigo)}</div>
            <div><strong>Nombre:</strong> ${escapeHtml(local.nombre || '-')}</div>
            <div><strong>Direccion:</strong> ${escapeHtml(local.direccion || '-')}</div>
            <div><strong>Comuna:</strong> ${escapeHtml(local.comuna || '-')}</div>
            <div><strong>Estado:</strong> ${localTieneCoords(local) ? 'Con coordenadas validas' : 'Sin coordenadas validas'}</div>
        </div>
    `);

    infoWindow.open(mapa, marker);
}

function toggleSeleccion(codigo) {
    const idx = localesSeleccionados.indexOf(codigo);

    if (idx === -1) {
        localesSeleccionados.push(codigo);
    } else {
        localesSeleccionados.splice(idx, 1);
    }

    setMarkerSelectionState(codigo);
    renderTablaEncontrados();
    actualizarResumenPlanificacion();
    renderRoutePreview();
}

function calcularDistanciaKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;

    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) *
        Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLng / 2) * Math.sin(dLng / 2);

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function construirRutaSimple(locales) {
    if (!locales.length) return [];

    const puntos = [...locales].filter(localTieneCoords).map(local => ({
        ...local,
        latNum: parseFloat(local.lat),
        lngNum: parseFloat(local.lng)
    }));

    if (puntos.length <= 2) return puntos;

    const usados = [];
    const restantes = [...puntos];

    restantes.sort((a, b) => a.latNum - b.latNum || a.lngNum - b.lngNum);
    usados.push(restantes.shift());

    while (restantes.length) {
        const ultimo = usados[usados.length - 1];
        let mejorIndex = 0;
        let mejorDist = Infinity;

        restantes.forEach((item, index) => {
            const dist = calcularDistanciaKm(
                ultimo.latNum, ultimo.lngNum,
                item.latNum, item.lngNum
            );
            if (dist < mejorDist) {
                mejorDist = dist;
                mejorIndex = index;
            }
        });

        usados.push(restantes.splice(mejorIndex, 1)[0]);
    }

    return usados;
}

function renderMapa(locales) {
    if (!window.google || !window.google.maps || !mapa) return;

    limpiarMapa();

    const conCoordenadas = locales.filter(localTieneCoords);

    if (conCoordenadas.length === 0) {
        mapa.setCenter({ lat: -33.45, lng: -70.66 });
        mapa.setZoom(5);
        return;
    }

    const bounds = new google.maps.LatLngBounds();

    conCoordenadas.forEach(local => {
        const pos = {
            lat: parseFloat(local.lat),
            lng: parseFloat(local.lng)
        };

        const marker = new google.maps.Marker({
            position: pos,
            map: mapa,
            title: local.codigo,
            icon: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
        });

        marker.addListener('click', () => {
            toggleSeleccion(local.codigo);
            abrirInfoLocal(local, marker);
        });

        markers.push(marker);
        markerByCodigo[local.codigo] = marker;
        bounds.extend(pos);
    });

    mapa.fitBounds(bounds);

    google.maps.event.addListenerOnce(mapa, 'bounds_changed', function() {
        if (mapa.getZoom() > 16) mapa.setZoom(16);
    });

    renderRoutePreview();
}

function renderTablaEncontrados() {
    const tbody = $('#tablaEncontrados tbody').empty();

    if (localesEncontrados.length === 0) {
        $('#tablaEncontradosVacia').show();
        return;
    }

    $('#tablaEncontradosVacia').hide();

    localesEncontrados.forEach((local, index) => {
        const tieneCoords = localTieneCoords(local);
        const seleccionado = localesSeleccionados.includes(local.codigo);

        tbody.append(`
            <tr class="row-clickable ${seleccionado ? 'highlight-row' : ''}" data-codigo="${escapeHtml(local.codigo)}">
                <td>${index + 1}</td>
                <td class="fw-semibold">${escapeHtml(local.codigo)}</td>
                <td>${escapeHtml(local.direccion || '-')}</td>
                <td>${escapeHtml(local.comuna || '-')}</td>
                <td>
                    ${
                        tieneCoords
                        ? '<span class="badge-soft-success"><i class="fa-solid fa-circle-check"></i> Planificable</span>'
                        : '<span class="badge-soft-warning"><i class="fa-solid fa-triangle-exclamation"></i> Sin coordenadas</span>'
                    }
                </td>
            </tr>
        `);
    });
}

function renderTablaNoEncontrados() {
    const tbody = $('#tablaNoEncontrados tbody').empty();

    if (localesNoEncontrados.length === 0) {
        $('#tablaNoEncontradosVacia').show();
        return;
    }

    $('#tablaNoEncontradosVacia').hide();

    localesNoEncontrados.forEach((codigo, index) => {
        tbody.append(`
            <tr>
                <td>${index + 1}</td>
                <td class="fw-semibold">${escapeHtml(codigo)}</td>
            </tr>
        `);
    });
}

function renderMiniMap(route) {
    const svgPoints = [];
    const pointsGroup = $('#miniRoutePoints');
    const poly = $('#miniRouteLine');

    pointsGroup.empty();

    if (!route.length) {
        poly.attr('points', '');
        return;
    }

    const lngs = route.map(r => parseFloat(r.lng));
    const lats = route.map(r => parseFloat(r.lat));

    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);

    const mapX = lng => {
        const width = 260;
        const left = 20;
        return left + ((lng - minLng) / ((maxLng - minLng) || 1)) * width;
    };

    const mapY = lat => {
        const height = 220;
        const top = 25;
        return top + (1 - ((lat - minLat) / ((maxLat - minLat) || 1))) * height;
    };

    route.forEach((p, i) => {
        const x = mapX(parseFloat(p.lng));
        const y = mapY(parseFloat(p.lat));
        svgPoints.push(`${x},${y}`);

        pointsGroup.append(`
            <g>
                <circle cx="${x}" cy="${y}" r="13" fill="#1fc7b6"></circle>
                <circle cx="${x}" cy="${y}" r="9.5" fill="#26b7ad"></circle>
                <text x="${x}" y="${y + 4}" text-anchor="middle" font-size="10" font-weight="700" fill="#ffffff">${i + 1}</text>
            </g>
        `);
    });

    poly.attr('points', svgPoints.join(' '));
}

function renderRouteOnMap(route) {
    if (!window.google || !mapa) return;

    if (routePolyline) {
        routePolyline.setMap(null);
        routePolyline = null;
    }

    if (route.length < 2) return;

    routePolyline = new google.maps.Polyline({
        path: route.map(r => ({ lat: parseFloat(r.lat), lng: parseFloat(r.lng) })),
        geodesic: true,
        strokeColor: '#1e6df0',
        strokeOpacity: 0.95,
        strokeWeight: 4
    });

    routePolyline.setMap(mapa);
}

function actualizarKpis(route) {
    if (!route.length) {
        $('#kpiTiempo').text('0 h');
        $('#kpiDistancia').text('0 km');
        $('#kpiCombustible').text('0 L');
        $('#kpiTiempoFoot').text('Sin calculo todavia');
        $('#kpiDistanciaFoot').text('Sin calculo todavia');
        $('#kpiCombustibleFoot').text('Valor referencial');
        return;
    }

    let totalKm = 0;

    for (let i = 1; i < route.length; i++) {
        totalKm += calcularDistanciaKm(
            parseFloat(route[i - 1].lat),
            parseFloat(route[i - 1].lng),
            parseFloat(route[i].lat),
            parseFloat(route[i].lng)
        );
    }

    const tiempoHoras = (route.length * 0.45) + (totalKm / 35);
    const litros = totalKm / 12;

    const horas = Math.floor(tiempoHoras);
    const minutos = Math.round((tiempoHoras - horas) * 60);

    $('#kpiTiempo').text(`${horas} h ${minutos} min`);
    $('#kpiDistancia').text(`${Math.round(totalKm)} km`);
    $('#kpiCombustible').text(`${litros.toFixed(1)} L`);
    $('#kpiTiempoFoot').text(`Estimado sobre ${route.length} paradas`);
    $('#kpiDistanciaFoot').text(`Recorrido referencial actual`);
    $('#kpiCombustibleFoot').text(`Consumo estimado aproximado`);
}

function renderRoutePreview() {
    const stats = getPlanStats();
    const baseRoute = construirRutaSimple(stats.localesConCoords);
    const cont = $('#routePreviewList');

    cont.empty();

    if (!baseRoute.length) {
        cont.html('<div class="text-muted">Aún no hay una vista previa disponible.</div>');
        renderMiniMap([]);
        renderRouteOnMap([]);
        actualizarKpis([]);
        return;
    }

    baseRoute.forEach((local, index) => {
        cont.append(`
            <div class="route-item route-line">
                <div class="route-num">${index + 1}</div>
                <div class="route-text">
                    <div class="route-code">${escapeHtml(local.codigo)}</div>
                    <div class="small text-muted mb-1">${escapeHtml(local.comuna || '-')}${local.direccion ? ' · ' + escapeHtml(local.direccion) : ''}</div>
                    <div class="route-meta"></div>
                </div>
            </div>
        `);
    });

    renderMiniMap(baseRoute);
    renderRouteOnMap(baseRoute);
    actualizarKpis(baseRoute);
}

function actualizarResumenPlanificacion() {
    const stats = getPlanStats();
    const haySeleccion = localesSeleccionados.length > 0;
    const tienePlanificables = stats.localesConCoords.length > 0;

    $('#resumenPlanificacionRuta').html(`
        <div class="summary-item"><span>Cantidad objetivo por dia</span><strong>${stats.cantidadPorDia}</strong></div>
        <div class="summary-item"><span>Distancia maxima entre puntos</span><strong>${stats.maxKmRuta} KM</strong></div>
        <div class="summary-item"><span>Locales considerados</span><strong>${stats.localesObjetivo.length}</strong></div>
        <div class="summary-item"><span>Locales planificables</span><strong>${stats.localesConCoords.length}</strong></div>
        <div class="summary-item"><span>Excluidos por georreferencia</span><strong>${stats.localesSinCoords.length}</strong></div>
        <div class="summary-item"><span>Dias estimados</span><strong>${stats.diasPlanificados}</strong></div>
        <div class="summary-item"><span>Promedio real estimado</span><strong>${stats.promedioReal} locales/dia</strong></div>
        <div class="mt-2 small ${tienePlanificables ? 'text-muted' : 'text-danger'}">
            ${
                haySeleccion
                    ? 'Se usaran solo los locales seleccionados en mapa o tabla.'
                    : 'Se usaran todos los locales encontrados.'
            }
            <br>
            ${
                tienePlanificables
                    ? 'Los locales sin coordenadas validas no ingresaran a la ruta.'
                    : 'No hay locales con coordenadas validas para generar una ruta.'
            }
        </div>
    `);

    $('#btnProcesarPlanificacion').prop('disabled', !tienePlanificables);
}

function cargarTablas(data) {
    localesEncontrados = Array.isArray(data.encontrados) ? data.encontrados : [];
    localesNoEncontrados = Array.isArray(data.no_encontrados) ? data.no_encontrados : [];
    localesSeleccionados = [];

    $('#tablaEncontradosContainer').removeClass('hidden-soft');
    $('#tablaNoEncontradosContainer').removeClass('hidden-soft');

    renderTablaEncontrados();
    renderTablaNoEncontrados();
    renderMapa(localesEncontrados);

    const totalConCoords = localesEncontrados.filter(localTieneCoords).length;

    actualizarResumen(
        Number(data.total_csv || 0),
        localesEncontrados.length,
        localesNoEncontrados.length,
        totalConCoords
    );

    if (localesEncontrados.length > 0) {
        $('#cardPlanificacionRuta').removeClass('hidden-soft');
        actualizarResumenPlanificacion();
        renderRoutePreview();
    } else {
        $('#cardPlanificacionRuta').addClass('hidden-soft');
    }
}

$('#formCSV').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $('#btnSubmitCsv');
    const originalText = btn.html();

    btn.html('<i class="fa-solid fa-spinner fa-spin"></i> Cargando...').prop('disabled', true);

    $.ajax({
        url: 'mod_cargar_locales.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            btn.html(originalText).prop('disabled', false);

            if (!resp || resp.success !== true) {
                alert(resp?.message || '❌ No fue posible procesar el archivo.');
                return;
            }

            cargarTablas(resp);

            if ((resp.encontrados || []).length === 0 && (resp.no_encontrados || []).length > 0) {
                alert('⚠️ Ninguno de los codigos del CSV fue encontrado en el sistema.');
            }
        },
        error: function(xhr) {
            btn.html(originalText).prop('disabled', false);
            alert('❌ Error al cargar los locales.');
            console.error(xhr.responseText || xhr.statusText);
        }
    });
});

$(document).on('click', '#tablaEncontrados tbody tr', function() {
    const codigo = $(this).data('codigo');
    if (!codigo) return;

    toggleSeleccion(codigo);

    const marker = markerByCodigo[codigo];
    const local = localesEncontrados.find(l => l.codigo === codigo);

    if (marker) {
        mapa.panTo(marker.getPosition());
        mapa.setZoom(Math.max(mapa.getZoom(), 14));
        if (local) abrirInfoLocal(local, marker);
    }
});

$('#cantidadPorDia, #maxKmRuta').on('input change', function() {
    actualizarResumenPlanificacion();
    renderRoutePreview();
});

$('#btnProcesarPlanificacion').on('click', function() {
    const stats = getPlanStats();
    const fechaInicio = $('#fechaInicioRuta').val();

    if (!stats.localesObjetivo.length) {
        alert('⚠️ No hay locales para planificar.');
        return;
    }

    if (!stats.localesConCoords.length) {
        alert('⚠️ No hay locales con coordenadas validas para generar la ruta.');
        return;
    }

    if (!fechaInicio) {
        alert('⚠️ Debes indicar una fecha de inicio.');
        return;
    }

    const registrosPlanificar = stats.localesObjetivo.map(local => ({
        codigo: local.codigo,
        usuario_input: local.usuario_input || '',
        usuario_id: local.usuario_id || '',
        usuario_login: local.usuario_login || '',
        usuario_nombre: local.usuario_nombre || ''
    }));

    const btn = $(this);
    const originalText = btn.html();
    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Generando...');

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'mod_generar_propuesta_ruta.php';
    form.style.display = 'none';

    const appendHidden = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    appendHidden('cantidad_por_dia', stats.cantidadPorDia);
    appendHidden('max_km_ruta', stats.maxKmRuta);
    appendHidden('fecha_inicio', fechaInicio);
    appendHidden('registros_json', JSON.stringify(registrosPlanificar));

    document.body.appendChild(form);
    form.submit();
    form.remove();

    setTimeout(() => {
        btn.prop('disabled', false).html(originalText);
    }, 2000);
});
</script>

</body>
</html>
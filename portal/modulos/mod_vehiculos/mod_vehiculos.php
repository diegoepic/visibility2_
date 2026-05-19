<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Vehículos - Visibility</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">    

    <style>
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', sans-serif;
        }

        .page-header {
            background: linear-gradient(135deg, #1f2937, #111827);
            color: white;
            border-radius: 22px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .18);
        }

        .page-header h3 {
            margin: 0;
            font-weight: 700;
        }

        .page-header p {
            margin: 8px 0 0;
            color: #d1d5db;
        }

        .card-modern {
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }

        .btn-main {
            border-radius: 14px;
            padding: 10px 18px;
            font-weight: 600;
        }

        .table thead th {
            background: #111827;
            color: white;
            font-size: 13px;
            border: none;
        }

        .table tbody td {
            vertical-align: middle;
            font-size: 14px;
        }

        .badge-soft {
            padding: 7px 10px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-activo {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactivo {
            background: #fee2e2;
            color: #991b1b;
        }

        .vehicle-plate {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 6px 12px;
            border-radius: 10px;
            font-weight: 800;
            letter-spacing: .7px;
        }

        .modal-content {
            border: none;
            border-radius: 22px;
        }

        .modal-header {
            background: #111827;
            color: white;
            border-radius: 22px 22px 0 0;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #374151;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
        }

        .history-item {
            border-left: 4px solid #111827;
            background: #f9fafb;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 12px;
        }

        .history-date {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 12px;
            min-height: 38px;
            border-color: #dee2e6;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding-left: 0;
            font-size: 14px;
        }
        
        .select2-container--bootstrap-5 .select2-dropdown {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .select2-container {
            width: 100% !important;
        }
        
        .select2-dropdown {
            z-index: 9999;
        }
.report-answer-box {
    max-height: 180px;
    overflow-y: auto;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 10px;
    font-size: 13px;
}

.report-answer-item {
    padding: 7px 0;
    border-bottom: 1px dashed #d1d5db;
}

.report-answer-item:last-child {
    border-bottom: none;
}

.report-question {
    font-weight: 700;
    color: #111827;
    margin-bottom: 2px;
}

.report-response {
    color: #374151;
}

.report-date {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}

.report-table thead th {
    white-space: nowrap;
    font-size: 12px;
    vertical-align: middle;
}

.report-table tbody td {
    vertical-align: top;
    font-size: 13px;
    min-width: 150px;
}

.report-cell-text {
    max-width: 260px;
    max-height: 120px;
    overflow-y: auto;
    white-space: pre-wrap;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px;
    color: #374151;
}

.report-photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 230px;
}

.report-photo-thumb {
    width: 58px;
    height: 58px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #f3f4f6;
    cursor: pointer;
    transition: .15s ease;
}

.report-photo-thumb:hover {
    transform: scale(1.05);
}

.report-photo-more {
    width: 58px;
    height: 58px;
    border-radius: 10px;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

#modalReporte .modal-reporte-dialog {
    width: calc(100vw - 28px);
    max-width: calc(100vw - 28px);
    margin: 14px auto;
}

#modalReporte .modal-content {
    height: calc(100vh - 28px);
}

#modalReporte .modal-body {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.report-table-scroll {
    flex: 1;
    overflow: auto;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
}

.report-table {
    min-width: 1500px;
    margin-bottom: 0;
}

.report-table thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    white-space: nowrap;
    font-size: 12px;
    vertical-align: middle;
    background: #111827 !important;
    color: #fff;
}

.report-table tbody td {
    vertical-align: top;
    font-size: 13px;
    min-width: 150px;
}

.report-cell-text {
    max-width: 260px;
    max-height: 90px;
    overflow-y: auto;
    white-space: pre-wrap;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px;
    color: #374151;
}

.report-photo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 240px;
}

.report-photo-thumb {
    width: 62px;
    height: 62px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #f3f4f6;
    cursor: pointer;
    transition: .15s ease;
}

.report-photo-thumb:hover {
    transform: scale(1.06);
    box-shadow: 0 6px 14px rgba(15, 23, 42, .18);
}

.report-photo-more {
    width: 62px;
    height: 62px;
    border-radius: 10px;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
}

.report-photo-large {
    max-width: 100%;
    max-height: calc(100vh - 95px);
    object-fit: contain;
    border-radius: 14px;
}

@media (min-width: 1200px) {
    .modal-xl {
        --bs-modal-width: 95%!important;
    }
}

.visor-foto-reporte {
    position: fixed;
    inset: 10px;
    background: rgba(3, 7, 18, .96);
    z-index: 30000;
    display: none;
    flex-direction: column;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .45);
}

.visor-foto-reporte.show {
    display: flex;
}

.visor-foto-reporte-header {
    height: 58px;
    padding: 0 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: white;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
}

.visor-foto-reporte-title {
    font-weight: 700;
    font-size: 15px;
}

.visor-foto-reporte-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 34px;
    line-height: 1;
    cursor: pointer;
}

.visor-foto-reporte-body {
    flex: 1;
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
}

.visor-foto-reporte-body img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    border-radius: 14px;
}

#modalReporte .modal-reporte-dialog {
    width: calc(100vw - 20px);
    max-width: calc(100vw - 20px);
    margin: 10px auto;
}

#modalReporte .modal-content {
    height: calc(100vh - 20px);
}

#modalReporte .modal-body {
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.report-table-scroll {
    flex: 1;
    overflow: auto;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
}

.report-table {
    min-width: 1700px;
    margin-bottom: 0;
}
    </style>
</head>

<body>

<div class="container-fluid p-4">

    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h3><i class="fa-solid fa-car-side me-2"></i> Gestión de Vehículos</h3>
            <p>Registro, asignación actual e historial de movimientos por vehículo.</p>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-outline-light btn-main" onclick="abrirModalReporte()">
                <i class="fa-solid fa-chart-table me-1"></i> Reporte
            </button>
        
            <a href="mod_vehiculos_carga_masiva.php" class="btn btn-outline-light btn-main">
                <i class="fa-solid fa-file-arrow-up me-1"></i> Carga masiva
            </a>
        
            <button class="btn btn-light btn-main" onclick="abrirModalNuevo()">
                <i class="fa-solid fa-plus me-1"></i> Nuevo vehículo
            </button>
        </div>
            </div>

    <div class="card card-modern">
        <div class="card-body">

            <div class="row mb-3 g-2">
                <div class="col-md-4">
                    <input type="text" id="buscarVehiculo" class="form-control" placeholder="Buscar por patente, modelo, merchan, división...">
                </div>

                <div class="col-md-2">
                    <button class="btn btn-dark w-100 btn-main" onclick="cargarVehiculos()">
                        <i class="fa-solid fa-rotate me-1"></i> Actualizar
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Patente</th>
                        <th>Modelo</th>
                        <th>Combustible</th>
                        <th>Origen</th>
                        <th>Empresa</th>
                        <th>División</th>
                        <th>Subdivisión</th>
                        <th>Merchan actual</th>
                        <th>Desde</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody id="tbodyVehiculos">
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">Cargando vehículos...</td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<!-- MODAL CREAR / EDITAR -->
<div class="modal fade" id="modalVehiculo" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="tituloModalVehiculo">Nuevo vehículo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form id="formVehiculo">
                <div class="modal-body">

                    <input type="hidden" name="id" id="id">

                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label">Patente</label>
                            <input type="text" name="patente" id="patente" class="form-control" required placeholder="AAAA-11">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Modelo vehículo</label>
                            <input type="text" name="modelo" id="modelo" class="form-control" placeholder="Toyota Hilux, Peugeot Partner, etc.">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Combustible / Octanaje</label>
                            <select name="tipo_combustible" id="tipo_combustible" class="form-select">
                                <option value="">Seleccione...</option>
                                <option value="93">BENCINA 93</option>
                                <option value="95">BENCINA 95</option>
                                <option value="97">BENCINA 97</option>
                                <option value="DIESEL">DIESEL</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Dirección de origen / punto de partida</label>
                            <input type="text" name="direccion_origen" id="direccion_origen" class="form-control" placeholder="Ej: Av. Providencia 1234, Santiago">
                        </div>
                        
                        <input type="hidden" name="lat_origen" id="lat_origen">
                        <input type="hidden" name="lng_origen" id="lng_origen">

                        <div class="col-md-6">
                            <label class="form-label">Empresa</label>
                            <select name="id_empresa" id="id_empresa" class="form-select" required onchange="filtrarDivisiones()">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">División</label>
                            <select name="id_division" id="id_division" class="form-select" required onchange="filtrarSubdivisiones(); filtrarMerchans();">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Subdivisión</label>
                            <select name="id_subdivision" id="id_subdivision" class="form-select" onchange="filtrarMerchans()">
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Merchan asignado</label>
                            <select name="id_merchan" id="id_merchan" class="form-select" required>
                                <option value="">Seleccione...</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Fecha inicio asignación</label>
                            <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Estado vehículo</label>
                            <select name="estado" id="estado" class="form-select">
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Observación del movimiento</label>
                            <textarea name="observacion" id="observacion" class="form-control" rows="2" placeholder="Ej: asignación inicial, cambio por reemplazo, devolución, etc."></textarea>
                        </div>

                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Si cambias el merchan, empresa, división o subdivisión, el sistema cerrará la asignación anterior y creará un nuevo registro histórico.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-main" data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="submit" class="btn btn-dark btn-main">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- MODAL HISTORIAL -->
<div class="modal fade" id="modalHistorial" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-clock-rotate-left me-2"></i> Historial del vehículo
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="contenedorHistorial">
                    <p class="text-muted">Cargando historial...</p>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- MODAL REPORTE -->
<div class="modal fade" id="modalReporte" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fa-solid fa-chart-table me-2"></i> Reporte
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="row mb-3 g-2">
                    <div class="col-md-5">
                        <input 
                            type="text" 
                            id="buscarReporteVehiculo" 
                            class="form-control" 
                            placeholder="Buscar por patente, RUT, nombre o respuesta..."
                        >
                    </div>

                    <div class="col-md-2">
                        <button class="btn btn-dark w-100 btn-main" onclick="cargarReporteVehiculos()">
                            <i class="fa-solid fa-rotate me-1"></i> Actualizar
                        </button>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Este reporte muestra la respuesta más actualizada por trabajador para la campaña/formulario ID 138.
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle report-table" id="tablaReporteVehiculos">
                        <thead id="theadReporteVehiculos">
                            <tr>
                                <th>Placa patente</th>
                                <th>RUT usuario</th>
                                <th>Nombre completo</th>
                                <th>Última respuesta</th>
                            </tr>
                        </thead>
                
                        <tbody id="tbodyReporteVehiculos">
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Presiona actualizar para cargar el reporte.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- MODAL FOTO REPORTE -->
<div class="modal fade" id="modalReporte" tabindex="-1">
    <div class="modal-dialog modal-reporte-dialog modal-dialog-scrollable">
        <div class="modal-content bg-dark">

            <div class="modal-header border-0 text-white">
                <h5 class="modal-title" id="tituloFotoReporte">Foto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-2 d-flex align-items-center justify-content-center">
                <img id="imgFotoReporte" src="" class="report-photo-large" alt="Foto reporte">
            </div>

        </div>
    </div>
</div>

<!-- VISOR FOTO REPORTE -->
<div id="visorFotoReporte" class="visor-foto-reporte">
    <div class="visor-foto-reporte-header">
        <div id="visorFotoReporteTitulo" class="visor-foto-reporte-title">Foto</div>

        <button type="button" class="visor-foto-reporte-close" onclick="cerrarFotoReporte()">
            &times;
        </button>
    </div>

    <div class="visor-foto-reporte-body">
        <img id="visorFotoReporteImg" src="" alt="Foto reporte">
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
let vehiculos = [];

let catalogos = {
    empresas: [],
    divisiones: [],
    subdivisiones: [],
    merchans: []
};

let modalVehiculo = null;
let modalHistorial = null;
let modalReporte = null;

let reporteVehiculos = [];
let reportePreguntas = [];

$(document).ready(function () {
    modalVehiculo = new bootstrap.Modal(document.getElementById('modalVehiculo'));
    modalHistorial = new bootstrap.Modal(document.getElementById('modalHistorial'));
    const modalReporteEl = document.getElementById('modalReporte');
    
    if (modalReporteEl) {
        modalReporte = new bootstrap.Modal(modalReporteEl);
    }

    cargarCatalogos();
    cargarVehiculos();

    $('#buscarVehiculo').on('keyup', function () {
        renderVehiculos();
    });

    $('#buscarReporteVehiculo').on('keyup', function () {
        renderReporteVehiculos();
    });

    $('#formVehiculo').on('submit', function (e) {
        e.preventDefault();
        guardarVehiculo();
    });

    $(document).on('click', '.js-report-photo', function () {
        const url = $(this).attr('data-url') || '';
        const title = $(this).attr('data-title') || 'Foto';
    
        abrirFotoReporte(url, title);
    });

    inicializarSelect2Merchan();
});

function escapeHtml(text) {
    if (text === null || text === undefined) return '';

    return String(text)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function inicializarSelect2Merchan() {
    const $merchan = $('#id_merchan');

    if (!$merchan.length) {
        return;
    }

    if (typeof $.fn.select2 === 'undefined') {
        return;
    }

    if ($merchan.hasClass('select2-hidden-accessible')) {
        $merchan.select2('destroy');
    }

    $merchan.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Buscar merchan...',
        allowClear: true,
        dropdownParent: $('#modalVehiculo')
    });
}

function destruirSelect2Merchan() {
    const $merchan = $('#id_merchan');

    if (
        $merchan.length &&
        typeof $.fn.select2 !== 'undefined' &&
        $merchan.hasClass('select2-hidden-accessible')
    ) {
        $merchan.select2('destroy');
    }
}

function cargarCatalogos() {
    $.getJSON('ajax_vehiculos_catalogos.php', function (resp) {
        if (!resp || !resp.ok) {
            alert(resp?.msg || 'No se pudieron cargar los catálogos.');
            return;
        }

        catalogos = resp.data || {
            empresas: [],
            divisiones: [],
            subdivisiones: [],
            merchans: []
        };

        llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre');
        filtrarDivisiones();
        filtrarSubdivisiones();
        filtrarMerchans();
    }).fail(function () {
        alert('Error al cargar los catálogos.');
    });
}

function llenarSelect(selector, data, valueField, textField, selectedValue = '') {
    const $select = $(selector);

    if (!$select.length) {
        return;
    }

    $select.empty();
    $select.append('<option value="">Seleccione...</option>');

    if (!Array.isArray(data)) {
        data = [];
    }

    data.forEach(item => {
        const value = item[valueField] ?? '';
        const text = item[textField] ?? '';
        const selected = String(value) === String(selectedValue) ? 'selected' : '';

        $select.append(`
            <option value="${escapeHtml(value)}" ${selected}>
                ${escapeHtml(text)}
            </option>
        `);
    });
}

function filtrarDivisiones(selectedValue = '') {
    const idEmpresa = $('#id_empresa').val();

    const divisiones = catalogos.divisiones.filter(d => {
        return !idEmpresa || String(d.id_empresa) === String(idEmpresa);
    });

    llenarSelect('#id_division', divisiones, 'id', 'nombre', selectedValue);

    if (selectedValue !== '') {
        $('#id_division').val(selectedValue);
    }
}

function filtrarSubdivisiones(selectedValue = '') {
    const idDivision = $('#id_division').val();

    const subdivisiones = catalogos.subdivisiones.filter(s => {
        return !idDivision || String(s.id_division) === String(idDivision);
    });

    llenarSelect('#id_subdivision', subdivisiones, 'id', 'nombre', selectedValue);

    if (selectedValue !== '') {
        $('#id_subdivision').val(selectedValue);
    }
}

function filtrarMerchans(selectedValue = '') {
    const idDivision = $('#id_division').val();
    const idSubdivision = $('#id_subdivision').val();

    destruirSelect2Merchan();

    const merchans = catalogos.merchans.filter(u => {
        const matchDivision =
            !idDivision ||
            !u.id_division ||
            String(u.id_division) === String(idDivision);

        const matchSubdivision =
            !idSubdivision ||
            !u.id_subdivision ||
            String(u.id_subdivision) === String(idSubdivision);

        return matchDivision && matchSubdivision;
    });

    llenarSelect('#id_merchan', merchans, 'id', 'nombre_completo', selectedValue);

    $('#id_merchan').val(selectedValue || '').trigger('change');

    inicializarSelect2Merchan();
}

function cargarVehiculos() {
    $('#tbodyVehiculos').html(`
        <tr>
            <td colspan="11" class="text-center text-muted py-4">
                Cargando vehículos...
            </td>
        </tr>
    `);

    $.getJSON('ajax_vehiculos_listar.php', function (resp) {
        if (!resp || !resp.ok) {
            $('#tbodyVehiculos').html(`
                <tr>
                    <td colspan="11" class="text-center text-danger py-4">
                        ${escapeHtml(resp?.msg || 'Error al cargar vehículos.')}
                    </td>
                </tr>
            `);
            return;
        }

        vehiculos = Array.isArray(resp.data) ? resp.data : [];
        renderVehiculos();
    }).fail(function () {
        $('#tbodyVehiculos').html(`
            <tr>
                <td colspan="11" class="text-center text-danger py-4">
                    Error inesperado al cargar vehículos.
                </td>
            </tr>
        `);
    });
}

function renderVehiculos() {
    const filtro = $('#buscarVehiculo').val().toLowerCase().trim();

    const data = vehiculos.filter(v => {
        const texto = [
            v.patente,
            v.modelo,
            v.tipo_combustible,
            v.direccion_origen,
            v.empresa,
            v.division,
            v.subdivision,
            v.merchan,
            v.usuario_merchan
        ]
            .map(valor => valor || '')
            .join(' ')
            .toLowerCase();

        return texto.includes(filtro);
    });

    if (data.length === 0) {
        $('#tbodyVehiculos').html(`
            <tr>
                <td colspan="11" class="text-center text-muted py-4">
                    No se encontraron vehículos.
                </td>
            </tr>
        `);
        return;
    }

    let html = '';

    data.forEach(v => {
        const estadoBadge = Number(v.estado) === 1
            ? '<span class="badge-soft badge-activo">Activo</span>'
            : '<span class="badge-soft badge-inactivo">Inactivo</span>';

        html += `
            <tr>
                <td>
                    <span class="vehicle-plate">
                        <i class="fa-solid fa-car"></i>
                        ${escapeHtml(v.patente)}
                    </span>
                </td>

                <td>${escapeHtml(v.modelo || '-')}</td>
                <td>${escapeHtml(v.tipo_combustible || '-')}</td>
                <td>${escapeHtml(v.direccion_origen || '-')}</td>
                <td>${escapeHtml(v.empresa || '-')}</td>
                <td>${escapeHtml(v.division || '-')}</td>
                <td>${escapeHtml(v.subdivision || '-')}</td>
                <td>${escapeHtml(v.merchan || '-')}</td>
                <td>${escapeHtml(v.fecha_inicio || '-')}</td>
                <td>${estadoBadge}</td>

                <td class="text-end">
                    <button 
                        type="button"
                        class="btn btn-sm btn-outline-dark"
                        onclick="abrirModalEditar(${Number(v.id)})"
                        title="Editar vehículo"
                    >
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>

                    <button 
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        onclick="verHistorial(${Number(v.id)})"
                        title="Ver historial"
                    >
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    $('#tbodyVehiculos').html(html);
}

function abrirModalNuevo() {
    $('#formVehiculo')[0].reset();

    $('#id').val('');
    $('#patente').val('');
    $('#modelo').val('');
    $('#tipo_combustible').val('');
    $('#direccion_origen').val('');
    $('#lat_origen').val('');
    $('#lng_origen').val('');
    $('#estado').val('1');
    $('#fecha_inicio').val('<?= date('Y-m-d') ?>');
    $('#observacion').val('');

    $('#tituloModalVehiculo').text('Nuevo vehículo');

    llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre');
    $('#id_empresa').val('');

    filtrarDivisiones();
    $('#id_division').val('');

    filtrarSubdivisiones();
    $('#id_subdivision').val('');

    filtrarMerchans();
    $('#id_merchan').val('').trigger('change');

    modalVehiculo.show();
}

function abrirModalEditar(id) {
    const v = vehiculos.find(item => Number(item.id) === Number(id));

    if (!v) {
        alert('No se encontró el vehículo seleccionado.');
        return;
    }

    $('#formVehiculo')[0].reset();

    $('#id').val(v.id || '');
    $('#patente').val(v.patente || '');
    $('#modelo').val(v.modelo || '');
    $('#tipo_combustible').val(v.tipo_combustible || '');
    $('#direccion_origen').val(v.direccion_origen || '');
    $('#lat_origen').val(v.lat_origen || '');
    $('#lng_origen').val(v.lng_origen || '');
    $('#estado').val(v.estado ?? '1');
    $('#fecha_inicio').val('<?= date('Y-m-d') ?>');
    $('#observacion').val('');

    llenarSelect('#id_empresa', catalogos.empresas, 'id', 'nombre', v.id_empresa);
    $('#id_empresa').val(v.id_empresa || '');

    filtrarDivisiones(v.id_division);
    $('#id_division').val(v.id_division || '');

    filtrarSubdivisiones(v.id_subdivision);
    $('#id_subdivision').val(v.id_subdivision || '');

    filtrarMerchans(v.id_merchan);
    $('#id_merchan').val(v.id_merchan || '').trigger('change');

    $('#tituloModalVehiculo').text('Editar vehículo');

    modalVehiculo.show();
}

function guardarVehiculo() {
    const form = document.getElementById('formVehiculo');
    const formData = new FormData(form);

    $.ajax({
        url: 'ajax_vehiculos_guardar.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        beforeSend: function () {
            $('#formVehiculo button[type="submit"]')
                .prop('disabled', true)
                .html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
        },
        success: function (resp) {
            if (!resp || !resp.ok) {
                alert(resp?.msg || 'No se pudo guardar el vehículo.');
                return;
            }

            modalVehiculo.hide();
            cargarVehiculos();
        },
        error: function (xhr) {
            console.error(xhr.responseText);
            alert('Error inesperado al guardar el vehículo.');
        },
        complete: function () {
            $('#formVehiculo button[type="submit"]')
                .prop('disabled', false)
                .html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
        }
    });
}

function verHistorial(idVehiculo) {
    $('#contenedorHistorial').html(`
        <p class="text-muted">Cargando historial...</p>
    `);

    modalHistorial.show();

    $.getJSON('ajax_vehiculos_historial.php', { id_vehiculo: idVehiculo }, function (resp) {
        if (!resp || !resp.ok) {
            $('#contenedorHistorial').html(`
                <p class="text-danger">
                    ${escapeHtml(resp?.msg || 'Error al cargar historial.')}
                </p>
            `);
            return;
        }

        const historial = Array.isArray(resp.data) ? resp.data : [];

        if (historial.length === 0) {
            $('#contenedorHistorial').html(`
                <p class="text-muted">Este vehículo aún no tiene historial.</p>
            `);
            return;
        }

        let html = '';

        historial.forEach(h => {
            html += `
                <div class="history-item">
                    <div class="d-flex justify-content-between gap-3 flex-wrap">
                        <strong>${escapeHtml(h.merchan || 'Sin merchan')}</strong>

                        <span class="history-date">
                            ${escapeHtml(h.fecha_inicio || '-')} hasta ${escapeHtml(h.fecha_termino || 'Actual')}
                        </span>
                    </div>

                    <div class="mt-2 text-muted">
                        Empresa: ${escapeHtml(h.empresa || '-')} |
                        División: ${escapeHtml(h.division || '-')} |
                        Subdivisión: ${escapeHtml(h.subdivision || '-')}
                    </div>

                    ${
                        h.observacion
                            ? `<div class="mt-2">${escapeHtml(h.observacion)}</div>`
                            : ''
                    }
                </div>
            `;
        });

        $('#contenedorHistorial').html(html);
    }).fail(function () {
        $('#contenedorHistorial').html(`
            <p class="text-danger">Error inesperado al cargar historial.</p>
        `);
    });
}

function abrirModalReporte() {
    if (!modalReporte) {
        alert('No se encontró el modal de reporte. Revisa que exista el HTML con id="modalReporte".');
        return;
    }

    $('#buscarReporteVehiculo').val('');

    $('#tbodyReporteVehiculos').html(`
        <tr>
            <td colspan="5" class="text-center text-muted py-4">
                Cargando reporte...
            </td>
        </tr>
    `);

    modalReporte.show();
    cargarReporteVehiculos();
}

function cargarReporteVehiculos() {
    $('#tbodyReporteVehiculos').html(`
        <tr>
            <td colspan="4" class="text-center text-muted py-4">
                <i class="fa-solid fa-spinner fa-spin me-1"></i> Cargando reporte...
            </td>
        </tr>
    `);

    $.getJSON('/visibility2/portal/modulos/mod_vehiculos/ajax_vehiculos_reporte.php', function (resp) {
        if (!resp || !resp.ok) {
            $('#tbodyReporteVehiculos').html(`
                <tr>
                    <td colspan="4" class="text-center text-danger py-4">
                        ${escapeHtml(resp?.msg || 'No se pudo cargar el reporte.')}
                    </td>
                </tr>
            `);
            return;
        }

        reportePreguntas = Array.isArray(resp.questions) ? resp.questions : [];
        reporteVehiculos = Array.isArray(resp.data) ? resp.data : [];

        renderReporteVehiculos();

    }).fail(function (xhr) {
        console.error(xhr.responseText);

        $('#tbodyReporteVehiculos').html(`
            <tr>
                <td colspan="4" class="text-center text-danger py-4">
                    Error inesperado al cargar el reporte.
                </td>
            </tr>
        `);
    });
}

function renderReporteVehiculos() {
    renderHeaderReporte();

    const filtro = $('#buscarReporteVehiculo').val().toLowerCase().trim();

    const data = reporteVehiculos.filter(item => {
        const texto = obtenerTextoBusquedaReporte(item);
        return texto.includes(filtro);
    });

    const colspan = 4 + reportePreguntas.length;

    if (data.length === 0) {
        $('#tbodyReporteVehiculos').html(`
            <tr>
                <td colspan="${colspan}" class="text-center text-muted py-4">
                    No se encontraron datos para el reporte.
                </td>
            </tr>
        `);
        return;
    }

    let html = '';

    data.forEach(item => {
        html += `
            <tr>
                <td>
                    <span class="vehicle-plate">
                        <i class="fa-solid fa-car"></i>
                        ${escapeHtml(item.patente || '-')}
                    </span>
                </td>

                <td>${escapeHtml(item.rut_usuario || '-')}</td>

                <td>
                    <strong>${escapeHtml(item.nombre_completo || '-')}</strong>
                    ${
                        item.usuario_merchan
                            ? `<div class="text-muted small">${escapeHtml(item.usuario_merchan)}</div>`
                            : ''
                    }
                </td>

                <td>
                    <div>${escapeHtml(item.fecha_ultima_respuesta || '-')}</div>
                    <div class="report-date">${escapeHtml(item.hora_ultima_respuesta || '')}</div>
                </td>
        `;

        reportePreguntas.forEach(pregunta => {
            const key = 'q_' + pregunta.id;
            const respuesta = item.answers ? item.answers[key] : null;

            html += `
                <td>
                    ${renderCeldaRespuestaReporte(respuesta, pregunta)}
                </td>
            `;
        });

        html += `</tr>`;
    });

    $('#tbodyReporteVehiculos').html(html);
}

function renderHeaderReporte() {
    let html = `
        <tr>
            <th>Placa patente</th>
            <th>RUT usuario</th>
            <th>Nombre completo</th>
            <th>Última respuesta</th>
    `;

    reportePreguntas.forEach(p => {
        html += `
            <th title="${escapeHtml(p.question_text || '')}">
                ${escapeHtml(p.question_text || '-')}
            </th>
        `;
    });

    html += `</tr>`;

    $('#theadReporteVehiculos').html(html);
}

function renderCeldaRespuestaReporte(respuesta, pregunta) {
    if (!respuesta) {
        return `<span class="text-muted small">-</span>`;
    }

    const esFoto = Number(pregunta.id_question_type) === 7 || respuesta.type === 'photo';

    if (esFoto) {
        const fotos = Array.isArray(respuesta.photos) ? respuesta.photos : [];

        if (fotos.length === 0) {
            return `<span class="text-muted small">Sin foto</span>`;
        }

        let html = `<div class="report-photo-grid">`;

        fotos.slice(0, 5).forEach(foto => {
            html += `
                <a href="${escapeHtml(foto.url)}" target="_blank" title="${escapeHtml(foto.name || 'Foto')}">
                    <img 
                        src="${escapeHtml(foto.url)}" 
                        class="report-photo-thumb" 
                        alt="${escapeHtml(foto.name || 'Foto')}"
                        loading="lazy"
                    >
                </a>
            `;
        });

        if (fotos.length > 5) {
            html += `
                <div class="report-photo-more">
                    +${fotos.length - 5}
                </div>
            `;
        }

        html += `</div>`;

        return html;
    }

    const valor = respuesta.value || '';

    if (!valor) {
        return `<span class="text-muted small">-</span>`;
    }

    return `
        <div class="report-cell-text">
            ${escapeHtml(valor)}
        </div>
    `;
}

function obtenerTextoBusquedaReporte(item) {
    let partes = [
        item.patente,
        item.rut_usuario,
        item.nombre_completo,
        item.usuario_merchan,
        item.fecha_ultima_respuesta,
        item.hora_ultima_respuesta
    ];

    if (item.answers) {
        Object.values(item.answers).forEach(resp => {
            if (!resp) return;

            if (resp.value) {
                partes.push(resp.value);
            }

            if (Array.isArray(resp.photos)) {
                resp.photos.forEach(foto => {
                    partes.push(foto.name || '');
                    partes.push(foto.url || '');
                });
            }
        });
    }

    return partes
        .map(v => v || '')
        .join(' ')
        .toLowerCase();
}

function abrirFotoReporte(url, title) {
    if (!url) {
        return;
    }

    $('#visorFotoReporteImg').attr('src', url);
    $('#visorFotoReporteTitulo').text(title || 'Foto');

    $('#visorFotoReporte').addClass('show');
}

function cerrarFotoReporte() {
    $('#visorFotoReporte').removeClass('show');
    $('#visorFotoReporteImg').attr('src', '');
}

$(document).on('keydown', function (e) {
    if (e.key === 'Escape') {
        cerrarFotoReporte();
    }
});

$(document).on('click', '#visorFotoReporte', function (e) {
    if (e.target.id === 'visorFotoReporte') {
        cerrarFotoReporte();
    }
});

</script>

</body>
</html>
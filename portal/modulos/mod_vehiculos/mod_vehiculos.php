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

$(document).ready(function () {
    modalVehiculo = new bootstrap.Modal(document.getElementById('modalVehiculo'));
    modalHistorial = new bootstrap.Modal(document.getElementById('modalHistorial'));

    cargarCatalogos();
    cargarVehiculos();

    $('#buscarVehiculo').on('keyup', function () {
        renderVehiculos();
    });

    $('#formVehiculo').on('submit', function (e) {
        e.preventDefault();
        guardarVehiculo();
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
</script>

</body>
</html>
<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Planificador de Rutas - Visibility 2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        .container {
            margin-top: 40px;
            margin-bottom: 40px;
        }

        #map {
            height: 520px;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.10);
            background: #e9ecef;
        }

        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }

        .card-header {
            font-weight: 600;
            border-top-left-radius: 14px !important;
            border-top-right-radius: 14px !important;
        }

        .btn-primary {
            background-color: #004AAD;
            border-color: #004AAD;
        }

        .btn-primary:hover {
            background-color: #003C8F;
            border-color: #003C8F;
        }

        table thead {
            background-color: #004AAD;
            color: #fff;
        }

        table tbody tr:hover {
            background-color: #f1f5ff;
        }

        .row-clickable {
            cursor: pointer;
        }

        .table-responsive {
            max-height: 420px;
            overflow-y: auto;
        }

        .badge-soft-success {
            background: #d1f7df;
            color: #0f7b3a;
            font-weight: 600;
        }

        .badge-soft-warning {
            background: #fff3cd;
            color: #8a6d00;
            font-weight: 600;
        }

        .stat-box {
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            text-align: center;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            color: #6c757d;
            margin-top: 6px;
        }

        .highlight-row {
            background-color: #dbeafe !important;
        }

        .empty-box {
            padding: 20px;
            text-align: center;
            color: #6c757d;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary">
            <i class="fa-solid fa-map-location-dot"></i> Planificador de Rutas
        </h2>
        <p class="text-muted mb-0">
            Carga un archivo CSV con códigos de locales para visualizar los puntos georreferenciados y validar cuáles existen en el sistema.
        </p>
    </div>

    <!-- FORMULARIO -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="formCSV" enctype="multipart/form-data" method="POST" class="row g-3 align-items-center">
                <div class="col-md-8">
                    <label for="csvFile" class="form-label fw-semibold">Archivo CSV</label>
                    <input type="file" class="form-control" id="csvFile" name="csvFile" accept=".csv" required>
                    <small class="text-muted">Debe contener al menos una columna con el código del local.</small>
                </div>
                <div class="col-md-4 text-end">
                    <label class="form-label d-block invisible">Acción</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-upload"></i> Cargar Locales
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESUMEN -->
    <div class="row g-3 mb-4" id="resumenBloques" style="display:none;">
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-number text-primary" id="statTotalCsv">0</div>
                <div class="stat-label">Códigos cargados desde CSV</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-number text-success" id="statEncontrados">0</div>
                <div class="stat-label">Locales encontrados en sistema</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <div class="stat-number text-danger" id="statNoEncontrados">0</div>
                <div class="stat-label">Códigos no encontrados</div>
            </div>
        </div>
    </div>
    
    <!-- PROPUESTA DE RUTA -->
    <div class="card mb-4" id="cardPlanificacionRuta" style="display:none;">
        <div class="card-header bg-dark text-white">
            <i class="fa-solid fa-route"></i> Armar propuesta de ruta
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="frecuenciaSemanas" class="form-label fw-semibold">
                        Frecuencia objetivo
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">1 visita cada</span>
                        <input type="number" min="1" step="1" value="2" class="form-control" id="frecuenciaSemanas">
                        <span class="input-group-text">semana(s)</span>
                    </div>
                    <small class="text-muted">
                        Se consideran 5 días laborales por semana.
                    </small>
                </div>
    
                <div class="col-md-5">
                    <div class="border rounded p-3 bg-light" id="resumenPlanificacionRuta">
                        Aún no hay datos para planificar.
                    </div>
                </div>
    
                <div class="col-md-3">
                    <button type="button" class="btn btn-success w-100" id="btnProcesarPlanificacion">
                        <i class="fa-solid fa-file-excel"></i> Procesar planificación
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MAPA -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fa-solid fa-map"></i> Mapa de Locales
        </div>
        <div class="card-body">
            <div id="map"></div>
            <div class="mt-3 small text-muted">
                <i class="fa-solid fa-circle text-danger"></i> Punto disponible en mapa
                &nbsp;&nbsp;
                <i class="fa-solid fa-circle text-success"></i> Punto seleccionado
            </div>
        </div>
    </div>

    <!-- TABLAS -->
    <div class="row g-4">
        <!-- ENCONTRADOS -->
        <div class="col-lg-8">
            <div class="card" id="tablaEncontradosContainer" style="display:none;">
                <div class="card-header bg-primary text-white">
                    <i class="fa-solid fa-store"></i> Locales encontrados en el sistema
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaEncontrados">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Código</th>
                                <th>Dirección</th>
                                <th>Comuna</th>
                                <th style="width: 130px;">Mapa</th>
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

        <!-- NO ENCONTRADOS -->
        <div class="col-lg-4">
            <div class="card" id="tablaNoEncontradosContainer" style="display:none;">
                <div class="card-header bg-danger text-white">
                    <i class="fa-solid fa-circle-exclamation"></i> Códigos no encontrados
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaNoEncontrados">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Código</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div id="tablaNoEncontradosVacia" class="empty-box" style="display:none;">
                        Todos los códigos existen en el sistema.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LIBRERÍAS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- OJO: idealmente esta API KEY no debiera quedar expuesta en código público -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>

<script>
let mapa;
let infoWindow;
let markers = [];
let markerByCodigo = {};
let localesEncontrados = [];
let localesNoEncontrados = [];
let localesSeleccionados = [];

function initMap() {
    mapa = new google.maps.Map(document.getElementById('map'), {
        zoom: 5,
        center: { lat: -33.45, lng: -70.66 },
        mapTypeId: 'roadmap'
    });

    infoWindow = new google.maps.InfoWindow();
}

function limpiarMapa() {
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    markerByCodigo = {};
    localesSeleccionados = [];
}

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function actualizarResumen(totalCsv, encontrados, noEncontrados) {
    $('#statTotalCsv').text(totalCsv);
    $('#statEncontrados').text(encontrados);
    $('#statNoEncontrados').text(noEncontrados);
    $('#resumenBloques').fadeIn();
}

function renderMapa(locales) {
    limpiarMapa();

    const conCoordenadas = locales.filter(local =>
        local.lat !== null && local.lat !== '' &&
        local.lng !== null && local.lng !== '' &&
        !isNaN(parseFloat(local.lat)) &&
        !isNaN(parseFloat(local.lng))
    );

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
            icon: "https://maps.google.com/mapfiles/ms/icons/red-dot.png"
        });

        marker.addListener('click', () => {
            toggleSeleccion(local.codigo);

            infoWindow.setContent(`
                <div style="min-width:220px;">
                    <div><strong>Código:</strong> ${escapeHtml(local.codigo)}</div>
                    <div><strong>Nombre:</strong> ${escapeHtml(local.nombre || '-')}</div>
                    <div><strong>Dirección:</strong> ${escapeHtml(local.direccion || '-')}</div>
                    <div><strong>Comuna:</strong> ${escapeHtml(local.comuna || '-')}</div>
                </div>
            `);
            infoWindow.open(mapa, marker);
        });

        markers.push(marker);
        markerByCodigo[local.codigo] = marker;
        bounds.extend(pos);
    });

    mapa.fitBounds(bounds);

    google.maps.event.addListenerOnce(mapa, 'bounds_changed', function() {
        if (mapa.getZoom() > 16) {
            mapa.setZoom(16);
        }
    });
}

function renderTablaEncontrados() {
    const tbody = $('#tablaEncontrados tbody').empty();

    if (localesEncontrados.length === 0) {
        $('#tablaEncontradosVacia').show();
        return;
    }

    $('#tablaEncontradosVacia').hide();

    localesEncontrados.forEach((local, index) => {
        const tieneCoords = (
            local.lat !== null && local.lat !== '' &&
            local.lng !== null && local.lng !== '' &&
            !isNaN(parseFloat(local.lat)) &&
            !isNaN(parseFloat(local.lng))
        );

        const seleccionado = localesSeleccionados.includes(local.codigo);

        tbody.append(`
            <tr class="row-clickable ${seleccionado ? 'highlight-row' : ''}" data-codigo="${escapeHtml(local.codigo)}">
                <td>${index + 1}</td>
                <td class="fw-semibold">${escapeHtml(local.codigo)}</td>
                <td>${escapeHtml(local.direccion || '-')}</td>
                <td>${escapeHtml(local.comuna || '-')}</td>
                <td>
                    ${tieneCoords
                        ? '<span class="badge badge-soft-success">Con coordenadas</span>'
                        : '<span class="badge badge-soft-warning">Sin coordenadas</span>'
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

function toggleSeleccion(codigo) {
    const idx = localesSeleccionados.indexOf(codigo);

    if (idx === -1) {
        localesSeleccionados.push(codigo);
    } else {
        localesSeleccionados.splice(idx, 1);
    }

    const marker = markerByCodigo[codigo];
    if (marker) {
        const seleccionado = localesSeleccionados.includes(codigo);
        marker.setIcon(
            seleccionado
                ? "https://maps.google.com/mapfiles/ms/icons/green-dot.png"
                : "https://maps.google.com/mapfiles/ms/icons/red-dot.png"
        );
    }

    renderTablaEncontrados();
    actualizarResumenPlanificacion();
}

function cargarTablas(data) {
    localesEncontrados = Array.isArray(data.encontrados) ? data.encontrados : [];
    localesNoEncontrados = Array.isArray(data.no_encontrados) ? data.no_encontrados : [];

    $('#tablaEncontradosContainer').show();
    $('#tablaNoEncontradosContainer').show();

    renderTablaEncontrados();
    renderTablaNoEncontrados();
    renderMapa(localesEncontrados);

    actualizarResumen(
        Number(data.total_csv || 0),
        localesEncontrados.length,
        localesNoEncontrados.length
    );

if (localesEncontrados.length > 0) {
    $('#cardPlanificacionRuta').fadeIn();
    actualizarResumenPlanificacion();
} else {
    $('#cardPlanificacionRuta').hide();
}
}

$('#formCSV').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $(this).find('button[type="submit"]');
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
                alert('⚠️ Ninguno de los códigos del CSV fue encontrado en el sistema.');
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
    if (marker) {
        mapa.panTo(marker.getPosition());
        mapa.setZoom(Math.max(mapa.getZoom(), 14));
        google.maps.event.trigger(marker, 'click');
    }
});

function actualizarResumenPlanificacion() {
    const semanas = Math.max(parseInt($('#frecuenciaSemanas').val()) || 1, 1);
    const diasLaborales = semanas * 5;

    const codigosPlanificar = (localesSeleccionados.length > 0)
        ? localesSeleccionados
        : localesEncontrados.map(l => l.codigo);

    const totalLocales = codigosPlanificar.length;
    const promedioPorDia = diasLaborales > 0
        ? Math.ceil(totalLocales / diasLaborales)
        : totalLocales;

    $('#resumenPlanificacionRuta').html(`
        <div><strong>Ciclo:</strong> ${semanas} semana(s)</div>
        <div><strong>Días laborales del ciclo:</strong> ${diasLaborales}</div>
        <div><strong>Locales a planificar:</strong> ${totalLocales}</div>
        <div><strong>Promedio estimado por día:</strong> ${promedioPorDia}</div>
        <div class="small text-muted mt-2">
            ${localesSeleccionados.length > 0
                ? 'Se usarán solo los locales seleccionados en mapa/tabla.'
                : 'Se usarán todos los locales encontrados.'}
        </div>
    `);
}

$('#frecuenciaSemanas').on('input change', function() {
    actualizarResumenPlanificacion();
});

$('#btnProcesarPlanificacion').on('click', function() {
    const semanas = Math.max(parseInt($('#frecuenciaSemanas').val()) || 1, 1);

    const codigosPlanificar = (localesSeleccionados.length > 0)
        ? [...localesSeleccionados]
        : localesEncontrados.map(l => l.codigo);

    if (!codigosPlanificar.length) {
        alert('⚠️ No hay locales para planificar.');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'mod_generar_propuesta_ruta.php';
    form.style.display = 'none';

    const inputSemanas = document.createElement('input');
    inputSemanas.type = 'hidden';
    inputSemanas.name = 'frecuencia_semanas';
    inputSemanas.value = semanas;
    form.appendChild(inputSemanas);

    const inputCodigos = document.createElement('input');
    inputCodigos.type = 'hidden';
    inputCodigos.name = 'codigos_json';
    inputCodigos.value = JSON.stringify(codigosPlanificar);
    form.appendChild(inputCodigos);

    document.body.appendChild(form);
    form.submit();
    form.remove();
});
</script>

</body>
</html>
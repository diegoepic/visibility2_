<?php
session_start();
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visualizador de Rutas Planificadas - Visibility 2</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        .container-fluid {
            padding: 28px;
        }

        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.08);
        }

        .card-header {
            border-top-left-radius: 14px !important;
            border-top-right-radius: 14px !important;
            font-weight: 600;
        }

        .stat-card {
            padding: 18px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,.08);
            height: 100%;
        }

        .stat-number {
            font-size: 1.7rem;
            font-weight: 700;
            color: #004AAD;
            line-height: 1;
        }

        .stat-label {
            color: #6c757d;
            margin-top: 8px;
            font-size: .95rem;
        }

        .nav-tabs .nav-link {
            font-weight: 600;
        }

        #mapRutas {
            height: 620px;
            border-radius: 14px;
            background: #e9ecef;
        }

        .table thead th {
            background: #004AAD;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .table-responsive {
            max-height: 420px;
            overflow: auto;
        }

        .table-sm td, .table-sm th {
            vertical-align: middle;
        }

        .clickable-row {
            cursor: pointer;
        }

        .clickable-row:hover {
            background: #eef4ff !important;
        }

        .route-color-box {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }

        .badge-soft {
            background: #e8f1ff;
            color: #004AAD;
            font-weight: 600;
            padding: .45rem .7rem;
            border-radius: 999px;
        }

        .empty-box {
            background: #fff;
            border: 1px dashed #ced4da;
            border-radius: 14px;
            padding: 30px;
            color: #6c757d;
            text-align: center;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: 18px;
            margin-bottom: 8px;
        }

        .legend-line {
            width: 26px;
            height: 4px;
            border-radius: 999px;
            display: inline-block;
        }

        .mini-note {
            font-size: .9rem;
            color: #6c757d;
        }

        .summary-pill {
            display: inline-block;
            margin-right: 8px;
            margin-bottom: 8px;
            padding: .5rem .75rem;
            border-radius: 999px;
            background: #eef4ff;
            color: #004AAD;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary mb-2">
            <i class="fa-solid fa-route"></i> Visualizador de Rutas Planificadas
        </h2>
        <p class="text-muted mb-0">
            Sube el archivo Excel generado por la planificación y revisa los grupos de rutas directamente en el mapa.
        </p>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <ul class="nav nav-tabs" id="tabsRutas" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-carga-btn" data-bs-toggle="tab" data-bs-target="#tab-carga" type="button" role="tab">
                        <i class="fa-solid fa-upload"></i> Cargar archivo
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-mapa-btn" data-bs-toggle="tab" data-bs-target="#tab-mapa" type="button" role="tab">
                        <i class="fa-solid fa-map-location-dot"></i> Mapa de rutas
                    </button>
                </li>
            </ul>

            <div class="tab-content pt-4">
                <!-- TAB 1 -->
                <div class="tab-pane fade show active" id="tab-carga" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <i class="fa-solid fa-file-excel"></i> Subir archivo de planificación
                                </div>
                                <div class="card-body">
                                    <form id="formUploadPlanificacion" enctype="multipart/form-data">
                                        <div class="mb-3">
                                            <label for="archivoPlanificacion" class="form-label fw-semibold">Archivo Excel</label>
                                            <input
                                                type="file"
                                                class="form-control"
                                                id="archivoPlanificacion"
                                                name="archivoPlanificacion"
                                                accept=".xlsx,.xls"
                                                required
                                            >
                                        </div>

                                        <div class="mb-3 mini-note">
                                            El sistema leerá la hoja <strong>Planificacion</strong> del archivo exportado.
                                        </div>

                                        <button type="submit" class="btn btn-success" id="btnCargarPlanificacion">
                                            <i class="fa-solid fa-cloud-arrow-up"></i> Procesar archivo
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="card">
                                <div class="card-header bg-dark text-white">
                                    <i class="fa-solid fa-circle-info"></i> Qué espera esta vista
                                </div>
                                <div class="card-body">
                                    <div class="mini-note">
                                        Esta pantalla toma el Excel generado por tu módulo de propuesta de ruta y utiliza la hoja <strong>Planificacion</strong>.
                                    </div>
                                    <hr>
                                    <div class="mb-2"><strong>Campos esperados:</strong></div>
                                    <div class="summary-pill">Código Local</div>
                                    <div class="summary-pill">Lat</div>
                                    <div class="summary-pill">Lng</div>
                                    <div class="summary-pill">Grupo Ruta</div>
                                    <div class="summary-pill">Orden Visita</div>
                                    <div class="summary-pill">Día Semana</div>
                                    <div class="summary-pill">Semana Plan</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="uploadResultBox" class="mt-4" style="display:none;"></div>
                </div>

                <!-- TAB 2 -->
                <div class="tab-pane fade" id="tab-mapa" role="tabpanel">
                    <div id="bloqueResumen" style="display:none;">
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalGrupos">0</div>
                                    <div class="stat-label">Grupos de ruta</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalPuntos">0</div>
                                    <div class="stat-label">Puntos georreferenciados</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalKm">0</div>
                                    <div class="stat-label">KM estimados</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statFilasIgnoradas">0</div>
                                    <div class="stat-label">Filas ignoradas</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="emptyMapState" class="empty-box">
                        <i class="fa-solid fa-map-location-dot fa-2x mb-3"></i>
                        <div class="fw-semibold mb-1">Todavía no hay rutas cargadas</div>
                        <div>Sube el archivo desde la pestaña <strong>Cargar archivo</strong>.</div>
                    </div>

                    <div id="bloqueMapa" style="display:none;">
                        <div class="row g-4">
                            <div class="col-lg-3">
                                <div class="card mb-4">
                                    <div class="card-header bg-primary text-white">
                                        <i class="fa-solid fa-filter"></i> Filtros
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="filtroGrupoRuta" class="form-label fw-semibold">Grupo de ruta</label>
                                            <select id="filtroGrupoRuta" class="form-select">
                                                <option value="__ALL__">Todas las rutas</option>
                                            </select>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <button class="btn btn-outline-primary" id="btnAjustarMapa" type="button">
                                                <i class="fa-solid fa-expand"></i> Ajustar mapa
                                            </button>
                                            <button class="btn btn-outline-secondary" id="btnVerTodas" type="button">
                                                <i class="fa-solid fa-layer-group"></i> Ver todas las rutas
                                            </button>
                                        </div>

                                        <hr>

                                        <div class="mini-note mb-2">
                                            Al seleccionar una ruta, el mapa mostrará la secuencia de visita según el campo <strong>Orden Visita</strong>.
                                        </div>

                                        <div id="leyendaRutas"></div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header bg-dark text-white">
                                        <i class="fa-solid fa-list"></i> Resumen por grupo
                                    </div>
                                    <div class="card-body table-responsive" style="max-height:350px;">
                                        <table class="table table-sm table-hover mb-0" id="tablaGruposResumen">
                                            <thead>
                                                <tr>
                                                    <th>Ruta</th>
                                                    <th>Paradas</th>
                                                    <th>KM</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="card mb-4">
                                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                        <span><i class="fa-solid fa-map"></i> Mapa de rutas</span>
                                        <span class="badge bg-light text-dark" id="badgeRutaActiva">Todas las rutas</span>
                                    </div>
                                    <div class="card-body">
                                        <div id="mapRutas"></div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <i class="fa-solid fa-location-dot"></i> Detalle de puntos
                                    </div>
                                    <div class="card-body table-responsive">
                                        <table class="table table-hover table-sm mb-0" id="tablaDetalleRuta">
                                            <thead>
                                                <tr>
                                                    <th>Grupo</th>
                                                    <th>Orden</th>
                                                    <th>Código</th>
                                                    <th>Nombre</th>
                                                    <th>Dirección</th>
                                                    <th>Comuna</th>
                                                    <th>Día</th>
                                                    <th>Semana</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div> <!-- fin tab mapa -->
            </div>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Reemplaza TU_API_KEY por tu key real -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMapRutas"></script>

<script>
let mapaRutas;
let infoWindowRutas;
let markersRutas = [];
let polylinesRutas = [];

let planRows = [];
let planGroups = [];
let planSummary = {};

const routePalette = [
    '#d32f2f', '#1976d2', '#388e3c', '#f57c00', '#7b1fa2',
    '#0097a7', '#5d4037', '#c2185b', '#455a64', '#7cb342',
    '#8e24aa', '#039be5', '#fb8c00', '#43a047', '#e53935',
    '#6d4c41', '#546e7a', '#00acc1', '#8bc34a', '#ff7043'
];

function initMapRutas() {
    mapaRutas = new google.maps.Map(document.getElementById('mapRutas'), {
        center: { lat: -33.45, lng: -70.66 },
        zoom: 5,
        mapTypeId: 'roadmap'
    });

    infoWindowRutas = new google.maps.InfoWindow();
}

function getRouteColor(groupName) {
    const idx = Math.abs(hashCode(groupName)) % routePalette.length;
    return routePalette[idx];
}

function hashCode(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function clearMapRutas() {
    markersRutas.forEach(marker => marker.setMap(null));
    polylinesRutas.forEach(poly => poly.setMap(null));
    markersRutas = [];
    polylinesRutas = [];
}

function getRowsByGroup(groupName) {
    if (groupName === '__ALL__') {
        return [...planRows];
    }

    return planRows.filter(row => row.grupo_ruta === groupName);
}

function renderSummary() {
    const totalKm = (planGroups || []).reduce((acc, g) => acc + Number(g.distancia_total_ruta_km || 0), 0);

    $('#statTotalGrupos').text(planGroups.length);
    $('#statTotalPuntos').text(planRows.length);
    $('#statTotalKm').text(totalKm.toFixed(2));
    $('#statFilasIgnoradas').text(planSummary.filas_ignoradas || 0);

    $('#bloqueResumen').show();
}

function renderGroupSelect() {
    const select = $('#filtroGrupoRuta').empty();
    select.append('<option value="__ALL__">Todas las rutas</option>');

    planGroups.forEach(group => {
        select.append(`
            <option value="${escapeHtml(group.grupo_ruta)}">
                ${escapeHtml(group.grupo_ruta)} · ${group.total_paradas} paradas
            </option>
        `);
    });
}

function renderLegend() {
    const container = $('#leyendaRutas').empty();

    planGroups.slice(0, 12).forEach(group => {
        const color = getRouteColor(group.grupo_ruta);
        container.append(`
            <div class="legend-item">
                <span class="legend-line" style="background:${color};"></span>
                <span class="mini-note">${escapeHtml(group.grupo_ruta)}</span>
            </div>
        `);
    });

    if (planGroups.length > 12) {
        container.append('<div class="mini-note mt-2">La paleta se reutiliza si hay muchos grupos.</div>');
    }
}

function renderGroupSummaryTable() {
    const tbody = $('#tablaGruposResumen tbody').empty();

    planGroups.forEach(group => {
        const color = getRouteColor(group.grupo_ruta);

        tbody.append(`
            <tr class="clickable-row" data-group="${escapeHtml(group.grupo_ruta)}">
                <td>
                    <span class="route-color-box" style="background:${color};"></span>
                    ${escapeHtml(group.grupo_ruta)}
                </td>
                <td>${group.total_paradas}</td>
                <td>${Number(group.distancia_total_ruta_km || 0).toFixed(2)}</td>
            </tr>
        `);
    });
}

function renderDetalleTable(groupName = '__ALL__') {
    const rows = getRowsByGroup(groupName)
        .sort((a, b) => {
            if (a.grupo_ruta !== b.grupo_ruta) {
                return a.grupo_ruta.localeCompare(b.grupo_ruta);
            }
            return Number(a.orden_visita || 0) - Number(b.orden_visita || 0);
        });

    const tbody = $('#tablaDetalleRuta tbody').empty();

    rows.forEach(row => {
        tbody.append(`
            <tr class="clickable-row" data-group="${escapeHtml(row.grupo_ruta)}" data-codigo="${escapeHtml(row.codigo_local)}">
                <td>${escapeHtml(row.grupo_ruta)}</td>
                <td>${row.orden_visita ?? ''}</td>
                <td>${escapeHtml(row.codigo_local)}</td>
                <td>${escapeHtml(row.nombre || '')}</td>
                <td>${escapeHtml(row.direccion || '')}</td>
                <td>${escapeHtml(row.comuna || '')}</td>
                <td>${escapeHtml(row.dia_semana || '')}</td>
                <td>${escapeHtml(row.semana_plan || '')}</td>
            </tr>
        `);
    });
}

function createMarker(row, color) {
    const marker = new google.maps.Marker({
        position: { lat: Number(row.lat), lng: Number(row.lng) },
        map: mapaRutas,
        label: {
            text: String(row.orden_visita ?? ''),
            color: '#ffffff',
            fontSize: '11px',
            fontWeight: '700'
        },
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 12,
            fillColor: color,
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2
        },
        title: `${row.grupo_ruta} - ${row.codigo_local}`
    });

    marker.addListener('click', () => {
        infoWindowRutas.setContent(`
            <div style="min-width:240px;">
                <div><strong>Grupo:</strong> ${escapeHtml(row.grupo_ruta)}</div>
                <div><strong>Orden:</strong> ${escapeHtml(row.orden_visita)}</div>
                <div><strong>Código:</strong> ${escapeHtml(row.codigo_local)}</div>
                <div><strong>Nombre:</strong> ${escapeHtml(row.nombre || '')}</div>
                <div><strong>Dirección:</strong> ${escapeHtml(row.direccion || '')}</div>
                <div><strong>Comuna:</strong> ${escapeHtml(row.comuna || '')}</div>
                <div><strong>Día:</strong> ${escapeHtml(row.dia_semana || '')}</div>
                <div><strong>Semana:</strong> ${escapeHtml(row.semana_plan || '')}</div>
            </div>
        `);
        infoWindowRutas.open(mapaRutas, marker);
    });

    markersRutas.push(marker);
    return marker;
}

function drawGroup(groupName, fitBounds = false) {
    const rows = getRowsByGroup(groupName)
        .filter(row => !isNaN(Number(row.lat)) && !isNaN(Number(row.lng)))
        .sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));

    const color = getRouteColor(groupName);
    const bounds = new google.maps.LatLngBounds();
    const path = [];

    rows.forEach(row => {
        const position = { lat: Number(row.lat), lng: Number(row.lng) };
        path.push(position);
        bounds.extend(position);
        createMarker(row, color);
    });

    if (path.length > 1) {
        const polyline = new google.maps.Polyline({
            path,
            geodesic: true,
            strokeColor: color,
            strokeOpacity: 0.9,
            strokeWeight: 4,
            map: mapaRutas
        });

        polylinesRutas.push(polyline);
    }

    if (fitBounds && !bounds.isEmpty()) {
        mapaRutas.fitBounds(bounds);

        google.maps.event.addListenerOnce(mapaRutas, 'bounds_changed', function() {
            if (mapaRutas.getZoom() > 15) {
                mapaRutas.setZoom(15);
            }
        });
    }
}

function renderMap(groupName = '__ALL__') {
    clearMapRutas();

    if (!planRows.length) {
        return;
    }

    $('#badgeRutaActiva').text(groupName === '__ALL__' ? 'Todas las rutas' : groupName);

    if (groupName === '__ALL__') {
        const allBounds = new google.maps.LatLngBounds();

        planGroups.forEach(group => {
            const rows = getRowsByGroup(group.grupo_ruta)
                .filter(row => !isNaN(Number(row.lat)) && !isNaN(Number(row.lng)))
                .sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));

            const color = getRouteColor(group.grupo_ruta);
            const path = [];

            rows.forEach(row => {
                const position = { lat: Number(row.lat), lng: Number(row.lng) };
                path.push(position);
                allBounds.extend(position);
                createMarker(row, color);
            });

            if (path.length > 1) {
                const polyline = new google.maps.Polyline({
                    path,
                    geodesic: true,
                    strokeColor: color,
                    strokeOpacity: 0.85,
                    strokeWeight: 4,
                    map: mapaRutas
                });

                polylinesRutas.push(polyline);
            }
        });

        if (!allBounds.isEmpty()) {
            mapaRutas.fitBounds(allBounds);
        }

    } else {
        drawGroup(groupName, true);
    }

    renderDetalleTable(groupName);
}

function activateMapView() {
    $('#emptyMapState').hide();
    $('#bloqueMapa').show();

    const tabMapa = new bootstrap.Tab(document.querySelector('#tab-mapa-btn'));
    tabMapa.show();

    setTimeout(() => {
        if (mapaRutas) {
            google.maps.event.trigger(mapaRutas, 'resize');
            renderMap($('#filtroGrupoRuta').val() || '__ALL__');
        }
    }, 250);
}

$('#formUploadPlanificacion').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $('#btnCargarPlanificacion');
    const original = btn.html();

    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...');

    $.ajax({
        url: 'mod_cargar_rutas_excel.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            btn.prop('disabled', false).html(original);

            if (!resp || resp.success !== true) {
                $('#uploadResultBox')
                    .show()
                    .html(`<div class="alert alert-danger mb-0">${escapeHtml(resp?.message || 'No se pudo procesar el archivo.')}</div>`);
                return;
            }

            planRows = Array.isArray(resp.rows) ? resp.rows : [];
            planGroups = Array.isArray(resp.groups) ? resp.groups : [];
            planSummary = resp.summary || {};

            renderSummary();
            renderGroupSelect();
            renderLegend();
            renderGroupSummaryTable();
            renderDetalleTable('__ALL__');

            $('#uploadResultBox')
                .show()
                .html(`
                    <div class="alert alert-success mb-0">
                        <strong>Archivo procesado correctamente.</strong><br>
                        Se cargaron ${planRows.length} puntos distribuidos en ${planGroups.length} grupo(s) de ruta.
                    </div>
                `);

            activateMapView();
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(original);

            const msg = xhr.responseJSON?.message
                || xhr.responseText
                || 'Ocurrió un error al procesar el archivo.';

            $('#uploadResultBox')
                .show()
                .html(`<div class="alert alert-danger mb-0">${escapeHtml(msg)}</div>`);
        }
    });
});

$('#filtroGrupoRuta').on('change', function() {
    const selected = $(this).val();
    renderMap(selected);
});

$('#btnVerTodas').on('click', function() {
    $('#filtroGrupoRuta').val('__ALL__');
    renderMap('__ALL__');
});

$('#btnAjustarMapa').on('click', function() {
    renderMap($('#filtroGrupoRuta').val() || '__ALL__');
});

$(document).on('click', '#tablaGruposResumen tbody tr', function() {
    const group = $(this).data('group');
    if (!group) return;

    $('#filtroGrupoRuta').val(group);
    renderMap(group);
});

$(document).on('click', '#tablaDetalleRuta tbody tr', function() {
    const group = $(this).data('group');
    const codigo = $(this).data('codigo');

    if (group && $('#filtroGrupoRuta').val() !== group) {
        $('#filtroGrupoRuta').val(group);
        renderMap(group);
    }

    const marker = markersRutas.find(m => m.getTitle() && m.getTitle().includes(codigo));
    if (marker) {
        mapaRutas.panTo(marker.getPosition());
        mapaRutas.setZoom(Math.max(mapaRutas.getZoom(), 14));
        google.maps.event.trigger(marker, 'click');
    }
});
</script>

</body>
</html>
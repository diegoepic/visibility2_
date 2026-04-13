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
            Carga un archivo CSV con códigos de locales para validar cuáles existen, cuáles tienen georreferencia válida
            y generar una propuesta de ruta con restricción máxima de distancia entre puntos consecutivos.
        </p>
    </div>

    <!-- FORMULARIO -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="formCSV" enctype="multipart/form-data" method="POST" class="row g-3 align-items-center">
                <div class="col-md-8">
                    <label for="csvFile" class="form-label fw-semibold">Archivo CSV</label>
                    <input type="file" class="form-control" id="csvFile" name="csvFile" accept=".csv" required>
                    <small class="text-muted">
                        Debe contener al menos una columna con el código del local.
                    </small>
                </div>
                <div class="col-md-4 text-end">
                    <label class="form-label d-block invisible">Acción</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-upload"></i> Cargar locales
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESUMEN -->
    <div class="row g-3 mb-4" id="resumenBloques" style="display:none;">
        <div class="col-lg-3 col-md-6">
            <div class="stat-box">
                <div class="stat-number text-primary" id="statTotalCsv">0</div>
                <div class="stat-label">Códigos cargados desde CSV</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-box">
                <div class="stat-number text-success" id="statEncontrados">0</div>
                <div class="stat-label">Locales encontrados en sistema</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-box">
                <div class="stat-number text-info" id="statConCoords">0</div>
                <div class="stat-label">Locales con coordenadas válidas</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="stat-box">
                <div class="stat-number text-danger" id="statNoEncontrados">0</div>
                <div class="stat-label">Códigos no encontrados</div>
            </div>
        </div>
    </div>

    <!-- PROPUESTA DE RUTA -->
    <div class="card mb-4" id="cardPlanificacionRuta" style="display:none;">
        <div class="card-header bg-dark text-white">
            <i class="fa-solid fa-route"></i> Generar propuesta de ruta
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label for="cantidadPorDia" class="form-label fw-semibold">
                        Cantidad objetivo por día
                    </label>
                    <div class="input-group">
                        <span class="input-group-text">Locales/día</span>
                        <input type="number" min="1" step="1" value="10" class="form-control" id="cantidadPorDia">
                    </div>
                    <small class="text-muted">
                        El sistema intentará respetar esta cantidad para distribuir las rutas.
                    </small>
                </div>

                <div class="col-lg-3 col-md-6">
                    <label for="maxKmRuta" class="form-label fw-semibold">
                        Distancia máxima entre puntos
                    </label>
                    <div class="input-group">
                        <input type="number" min="1" step="1" value="80" class="form-control" id="maxKmRuta">
                        <span class="input-group-text">KM</span>
                    </div>
                    <small class="text-muted">
                        Si un salto supera este límite, el sistema separará la ruta o moverá el local a otra.
                    </small>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="fechaInicioRuta" class="form-label fw-semibold">
                        Fecha de inicio
                    </label>
                    <input type="date" class="form-control" id="fechaInicioRuta">
                    <small class="text-muted">
                        La ruta 1 partirá desde esta fecha y las siguientes se asignarán en secuencia.
                    </small>
                </div>                

                <div class="col-lg-4 col-md-8">
                    <div class="border rounded p-3 bg-light" id="resumenPlanificacionRuta">
                        Aún no hay datos para planificar.
                    </div>
                </div>

                <div class="col-lg-2 col-md-4">
                    <button type="button" class="btn btn-success w-100" id="btnProcesarPlanificacion">
                        <i class="fa-solid fa-file-excel"></i> Generar Excel
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
                <i class="fa-solid fa-circle text-danger"></i> Local disponible
                &nbsp;&nbsp;
                <i class="fa-solid fa-circle text-success"></i> Local seleccionado
                &nbsp;&nbsp;
                <span>Solo se muestran en el mapa los locales con coordenadas válidas.</span>
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
                                <th style="width: 150px;">Estado</th>
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
    if (infoWindow) {
        infoWindow.close();
    }

    markers.forEach(marker => marker.setMap(null));
    markers = [];
    markerByCodigo = {};
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
    $('#resumenBloques').fadeIn();
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
            <div><strong>Código:</strong> ${escapeHtml(local.codigo)}</div>
            <div><strong>Nombre:</strong> ${escapeHtml(local.nombre || '-')}</div>
            <div><strong>Dirección:</strong> ${escapeHtml(local.direccion || '-')}</div>
            <div><strong>Comuna:</strong> ${escapeHtml(local.comuna || '-')}</div>
            <div><strong>Estado:</strong> ${localTieneCoords(local) ? 'Con coordenadas válidas' : 'Sin coordenadas válidas'}</div>
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
}

function renderMapa(locales) {
    if (!window.google || !window.google.maps || !mapa) {
        return;
    }

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
        const tieneCoords = localTieneCoords(local);
        const seleccionado = localesSeleccionados.includes(local.codigo);

        tbody.append(`
            <tr class="row-clickable ${seleccionado ? 'highlight-row' : ''}" data-codigo="${escapeHtml(local.codigo)}">
                <td>${index + 1}</td>
                <td class="fw-semibold">${escapeHtml(local.codigo)}</td>
                <td>${escapeHtml(local.direccion || '-')}</td>
                <td>${escapeHtml(local.comuna || '-')}</td>
                <td>
                    ${tieneCoords
                        ? '<span class="badge badge-soft-success">Planificable</span>'
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

function actualizarResumenPlanificacion() {
    const stats = getPlanStats();
    const haySeleccion = localesSeleccionados.length > 0;
    const tienePlanificables = stats.localesConCoords.length > 0;

    $('#resumenPlanificacionRuta').html(`
        <div><strong>Cantidad objetivo por día:</strong> ${stats.cantidadPorDia}</div>
        <div><strong>Distancia máxima entre puntos:</strong> ${stats.maxKmRuta} KM</div>
        <div><strong>Locales considerados:</strong> ${stats.localesObjetivo.length}</div>
        <div><strong>Locales planificables:</strong> ${stats.localesConCoords.length}</div>
        <div><strong>Locales excluidos por georreferencia:</strong> ${stats.localesSinCoords.length}</div>
        <div><strong>Días estimados:</strong> ${stats.diasPlanificados}</div>
        <div><strong>Promedio real estimado:</strong> ${stats.promedioReal} locales/día</div>
        <div class="small text-muted mt-2">
            ${haySeleccion
                ? 'Se usarán solo los locales seleccionados en mapa o tabla.'
                : 'Se usarán todos los locales encontrados.'
            }
        </div>
        <div class="small ${tienePlanificables ? 'text-muted' : 'text-danger'} mt-1">
            ${tienePlanificables
                ? 'Los locales sin coordenadas válidas no entran en la ruta, pero deben quedar informados en la hoja de observación del Excel.'
                : 'No hay locales con coordenadas válidas para generar una ruta.'
            }
        </div>
    `);

    $('#btnProcesarPlanificacion').prop('disabled', !tienePlanificables);
}

function cargarTablas(data) {
    localesEncontrados = Array.isArray(data.encontrados) ? data.encontrados : [];
    localesNoEncontrados = Array.isArray(data.no_encontrados) ? data.no_encontrados : [];
    localesSeleccionados = [];

    $('#tablaEncontradosContainer').show();
    $('#tablaNoEncontradosContainer').show();

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
    const local = localesEncontrados.find(l => l.codigo === codigo);

    if (marker) {
        mapa.panTo(marker.getPosition());
        mapa.setZoom(Math.max(mapa.getZoom(), 14));
        if (local) {
            abrirInfoLocal(local, marker);
        }
    }
});

$('#cantidadPorDia, #maxKmRuta').on('input change', function() {
    actualizarResumenPlanificacion();
});

$('#btnProcesarPlanificacion').on('click', function() {
    const stats = getPlanStats();
    const fechaInicio = $('#fechaInicioRuta').val();

    if (!stats.localesObjetivo.length) {
        alert('⚠️ No hay locales para planificar.');
        return;
    }

    if (!stats.localesConCoords.length) {
        alert('⚠️ No hay locales con coordenadas válidas para generar la ruta.');
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
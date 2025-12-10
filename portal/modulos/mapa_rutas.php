<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
date_default_timezone_set('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mapa de Rutas - Visibility 2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .container { margin-top: 40px; }
        #map { height: 500px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        table thead { background-color: #004AAD; color: #fff; }
        table tbody tr:hover { background-color: #f1f5ff; cursor: pointer; }
        .table-container { display: flex; gap: 20px; }
        .card { flex: 1; border-radius: 12px; box-shadow: 0 3px 8px rgba(0,0,0,0.08); }
        .btn-primary { background-color: #004AAD; border-color: #004AAD; }
        .btn-primary:hover { background-color: #003C8F; }
        .highlight { background-color: #d1e7ff !important; }
    </style>
</head>
<body>

<div class="container">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary"><i class="fa-solid fa-map-location-dot"></i> Planificador de Rutas</h2>
        <p class="text-muted">Carga un archivo CSV con códigos de locales, selecciónalos y arma tu ruta.</p>
    </div>

    <!-- FORMULARIO DE CARGA -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="formCSV" enctype="multipart/form-data" method="POST" class="row g-3 align-items-center">
                <div class="col-md-8">
                    <input type="file" class="form-control" id="csvFile" name="csvFile" accept=".csv" required>
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-upload"></i> Cargar Locales
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MAPA -->
    <div id="map" class="mb-4"></div>

    <!-- TABLAS -->
    <div class="table-container">
        <!-- TABLA IZQUIERDA -->
        <div class="card" id="tablaContainerIzq" style="display:none;">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-store"></i> Locales Disponibles
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle" id="tablaLocalesIzq">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- TABLA DERECHA -->
        <div class="card" id="tablaContainerDer" style="display:none;">
            <div class="card-header bg-success text-white">
                <i class="fa-solid fa-route"></i> Locales Seleccionados
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle" id="tablaLocalesDer">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Dirección</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- LIBRERÍAS -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>

<script>
let mapa;
let markers = [];
let localesDisponibles = [];
let localesSeleccionados = [];

function initMap() {
    mapa = new google.maps.Map(document.getElementById('map'), {
        zoom: 5,
        center: { lat: -33.45, lng: -70.66 },
        mapTypeId: 'roadmap'
    });
}

// Cargar CSV
$('#formCSV').on('submit', function(e){
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $(this).find('button');
    const originalText = btn.html();

    btn.html('<i class="fa-solid fa-spinner fa-spin"></i> Cargando...').prop('disabled', true);

    $.ajax({
        url: 'mod_cargar_locales.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(data) {
            btn.html(originalText).prop('disabled', false);

            // Resetear mapa y tablas
            markers.forEach(m => m.setMap(null));
            markers = [];
            localesSeleccionados = [];
            localesDisponibles = data;
            $('#tablaLocalesIzq tbody, #tablaLocalesDer tbody').empty();

            if (data.length === 0) {
                $('#tablaContainerIzq, #tablaContainerDer').hide();
                alert("⚠️ No se encontraron coordenadas válidas para los códigos cargados.");
                return;
            }

            // Mostrar tablas
            $('#tablaContainerIzq, #tablaContainerDer').fadeIn();

            const bounds = new google.maps.LatLngBounds();

            data.forEach((local, i) => {
                // Crear marcador
                const pos = { lat: parseFloat(local.lat), lng: parseFloat(local.lng) };
                const marker = new google.maps.Marker({
                    position: pos,
                    map: mapa,
                    title: local.nombre || local.codigo,
                    icon: "https://maps.google.com/mapfiles/ms/icons/red-dot.png"
                });

                // Click en marcador
                marker.addListener('click', () => toggleSeleccion(local, marker));

                markers.push(marker);
                bounds.extend(pos);

                // Agregar fila a tabla izquierda
                $('#tablaLocalesIzq tbody').append(`
                    <tr data-codigo="${local.codigo}">
                        <td>${i+1}</td>
                        <td>${local.codigo}</td>
                        <td>${local.nombre ?? '-'}</td>
                        <td>${local.direccion ?? '-'}</td>
                    </tr>
                `);
            });

            // Click en filas
            $('#tablaLocalesIzq tbody').on('click', 'tr', function() {
                const codigo = $(this).data('codigo');
                const local = localesDisponibles.find(l => l.codigo === codigo);
                toggleSeleccion(local);
            });

            mapa.fitBounds(bounds);
        },
        error: function() {
            btn.html(originalText).prop('disabled', false);
            alert("❌ Error al cargar los locales.");
        }
    });
});

// Mover local entre tablas
function toggleSeleccion(local, marker = null) {
    const idx = localesSeleccionados.findIndex(l => l.codigo === local.codigo);

    if (idx === -1) {
        // ➕ Mover a tabla derecha
        localesSeleccionados.push(local);
        localesDisponibles = localesDisponibles.filter(l => l.codigo !== local.codigo);
        if (marker) marker.setIcon("https://maps.google.com/mapfiles/ms/icons/green-dot.png");
    } else {
        // 🔁 Devolver a izquierda
        localesSeleccionados.splice(idx, 1);
        localesDisponibles.push(local);
        if (marker) marker.setIcon("https://maps.google.com/mapfiles/ms/icons/red-dot.png");
    }

    renderTablas();
}

function renderTablas() {
    const izqBody = $('#tablaLocalesIzq tbody').empty();
    const derBody = $('#tablaLocalesDer tbody').empty();

    localesDisponibles.forEach((l, i) => {
        izqBody.append(`
            <tr data-codigo="${l.codigo}">
                <td>${i+1}</td>
                <td>${l.codigo}</td>
                <td>${l.nombre ?? '-'}</td>
                <td>${l.direccion ?? '-'}</td>
            </tr>
        `);
    });

    localesSeleccionados.forEach((l, i) => {
        derBody.append(`
            <tr data-codigo="${l.codigo}">
                <td>${i+1}</td>
                <td>${l.codigo}</td>
                <td>${l.nombre ?? '-'}</td>
                <td>${l.direccion ?? '-'}</td>
            </tr>
        `);
    });
}
</script>
</body>
</html>

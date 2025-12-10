<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir el archivo de conexión a la base de datos y datos de la sesión
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Obtener el ID de la empresa del usuario
$empresa_id = $_SESSION['empresa_id'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Visibility 2 | Dashboard</title>

    <!-- Mantener los mismos enlaces CSS que en tu código original -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&amp;display=fallback">
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="dist/css/adminlte.min.css">

    <style>
        /* Estilo para los campos de texto */
        .input-group {
            margin-bottom: 15px;
        }
        #map {
            height: 500px;
            width: 100%;
        }
    </style>
</head>
<body>

<div class="card card-widget mt-4">
    <div class="card-header">
        <div class="user-block">
            <img class="img-circle" src="dist/img/mentecreativa.png" alt="User Image">
            <span class="username"><a href="#">Mapa</a></span>
            <span class="description">Cálculo de rutas</span>
        </div>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Formulario para ingresar origen y destinos -->
        <div class="input-group">
            <label>Origen - Latitud:</label>
            <input type="text" id="origenLat" class="form-control" placeholder="Ej: -33.45694">
        </div>
        <div class="input-group">
            <label>Origen - Longitud:</label>
            <input type="text" id="origenLng" class="form-control" placeholder="Ej: -70.64827">
        </div>
        <div class="input-group">
            <label>Direcciones de Destino (una por línea):</label>
            <textarea id="destinos" class="form-control" rows="5" placeholder="Ej: -42.470068 -73.774328&#10;-42.381308 -73.655490"></textarea>
        </div>
        <button class="btn btn-primary" onclick="generarRuta()">Generar Ruta</button>
        <button class="btn btn-success" onclick="exportarCSV()">Exportar a CSV</button>

        <!-- Mapa de Google Maps -->
        <div id="map"></div>
    </div>
</div>  

<!-- Mantener los mismos scripts JS que en tu código original -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>

<!-- Script de Google Maps -->
<script>
    function loadGoogleMapsAPI() {
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyAkWMIwHuWxwVkC-1Tk208gNRUBbwqZYIQ&callback=initMap&libraries=geometry';
        script.async = true;
        document.head.appendChild(script);
    }
    window.addEventListener('load', loadGoogleMapsAPI);
</script>

<script>
    let map;
    let directionsService;
    let directionsRenderer;
    let ubicaciones = [];
    let markers = []; // Array global para guardar los marcadores
    
    function initMap() {
        // Configuración inicial del mapa
        map = new google.maps.Map(document.getElementById("map"), {
            zoom: 7,
            center: { lat: -33.45694, lng: -70.64827 } // Santiago, Chile
        });
        
        // Inicializando los servicios de rutas
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer();
        directionsRenderer.setMap(map);
    }

function generarRuta() {
    // Limpiar el mapa de marcadores y rutas anteriores
    limpiarMapa();

    // Obtener valores de origen
    const lat = parseFloat(document.getElementById("origenLat").value);
    const lng = parseFloat(document.getElementById("origenLng").value);

    if (isNaN(lat) || isNaN(lng)) {
        alert("Por favor ingresa una latitud y longitud válidas para el origen.");
        return;
    }

    const origen = { lat: lat, lng: lng };

    // Obtener destinos del textarea
    const destinosText = document.getElementById("destinos").value;
    const lineas = destinosText.split('\n');

    // Reiniciar ubicaciones para evitar datos duplicados
    ubicaciones = [];

    // Crear array de waypoints para múltiples destinos
    const waypoints = [];
    const infoWindow = new google.maps.InfoWindow();

    lineas.forEach((linea, index) => {
        const coordenadas = linea.trim().split(/\s+/);
        if (coordenadas.length === 2) {
            const destLat = parseFloat(coordenadas[0]);
            const destLng = parseFloat(coordenadas[1]);

            if (!isNaN(destLat) && !isNaN(destLng)) {
                waypoints.push({
                    location: { lat: destLat, lng: destLng },
                    stopover: true
                });

                // Añadir a ubicaciones para exportación
                ubicaciones.push({
                    lat: destLat,
                    lng: destLng
                });

                // Crear un marcador para el punto
                const marker = new google.maps.Marker({
                    position: { lat: destLat, lng: destLng },
                    map: map,
                    label: String.fromCharCode(65 + index), // A, B, C, ...
                    title: `Destino ${index + 1}`
                });

                // Obtener dirección usando Geocoding API
                const geocodeUrl = `https://maps.googleapis.com/maps/api/geocode/json?latlng=${destLat},${destLng}&key=AIzaSyAkWMIwHuWxwVkC-1Tk208gNRUBbwqZYIQ`;

                fetch(geocodeUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'OK') {
                            const direccion = data.results[0].formatted_address;
                            const comuna = data.results[0].address_components.find(component => component.types.includes('administrative_area_level_3'))?.long_name || 'No encontrada';
                            const region = data.results[0].address_components.find(component => component.types.includes('administrative_area_level_1'))?.long_name || 'No encontrada';
                            
                            // Contenido del InfoWindow con Latitud y Longitud
                            const contentString = `
                                <div>
                                    <h4>Destino ${index + 1}</h4>
                                    <p><strong>Dirección:</strong> ${direccion}</p>
                                    <p><strong>Comuna:</strong> ${comuna}</p>
                                    <p><strong>Región:</strong> ${region}</p>
                                    <p><strong>Latitud:</strong> ${destLat}</p>
                                    <p><strong>Longitud:</strong> ${destLng}</p>
                                </div>
                            `;

                            // Agregar evento de clic para mostrar InfoWindow
                            marker.addListener('click', () => {
                                infoWindow.setContent(contentString);
                                infoWindow.open(map, marker);
                            });
                        }
                    });

                // Guardar el marcador en el array
                markers.push(marker);
            }
        }
    });

    if (waypoints.length === 0) {
        alert("No se encontraron destinos válidos.");
        return;
    }

    // Configuración de la solicitud de ruta
    const request = {
        origin: origen,
        destination: waypoints[waypoints.length - 1].location,
        waypoints: waypoints.slice(0, -1),
        travelMode: 'DRIVING'
    };

    // Trazar la ruta en el mapa
    directionsService.route(request, (result, status) => {
        if (status === 'OK') {
            directionsRenderer.setDirections(result);
        } else {
            alert("No se pudo mostrar la ruta: " + status);
        }
    });
}

// Función para limpiar el mapa de marcadores y rutas anteriores
function limpiarMapa() {
    // Limpiar marcadores
    markers.forEach(marker => marker.setMap(null));
    markers = [];

    // Limpiar ruta anterior
    directionsRenderer.set('directions', null);
}

function exportarCSV() {
    if (ubicaciones.length === 0) {
        alert("No hay ubicaciones para exportar. Genera la ruta primero.");
        return;
    }

    $.post('exportar_rutas.php', { ubicaciones: JSON.stringify(ubicaciones) }, function(response) {
        if (response.success) {
            window.location.href = response.file;
        } else {
            alert("Error al generar el archivo.");
        }
    }, 'json');
}
   
</script>

</body>
</html>

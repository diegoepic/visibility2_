<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
$empresa_id = $_SESSION['empresa_id'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Visibility 2 | Dashboard</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&amp;display=fallback">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <style>
    .input-group, .form-group { margin-bottom: 15px; }
    #map { height: 500px; width: 100%; }
    #listaLocales { max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; }
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
    <!-- Origen -->
    <div class="input-group">
      <label>Origen - Latitud:</label>
      <input type="text" id="origenLat" class="form-control" placeholder="Ej: -33.45694">
    </div>
    <div class="input-group">
      <label>Origen - Longitud:</label>
      <input type="text" id="origenLng" class="form-control" placeholder="Ej: -70.64827">
    </div>
    <!-- Opcional: Destinos manuales -->
    <div class="input-group">
      <label>Direcciones de Destino (una por línea, opcional):</label>
      <textarea id="destinos" class="form-control" rows="5" placeholder="Ej: -42.470068 -73.774328&#10;-42.381308 -73.655490"></textarea>
    </div>
    <!-- Filtros -->
    <div class="form-group">
      <label for="selectCanal">Canal:</label>
      <select id="selectCanal" class="form-control">
        <option value="">Todos los canales</option>
      </select>
    </div>
    <div class="form-group">
      <label for="selectDistrito">Distrito:</label>
      <select id="selectDistrito" class="form-control">
        <option value="">Todos los distritos</option>
      </select>
    </div>
    <div class="form-group">
      <label for="selectComuna">Comuna:</label>
      <select id="selectComuna" class="form-control">
        <option value="">Todas las comunas</option>
      </select>
    </div>
    <div class="form-group">
      <label for="selectUsuario">Usuario:</label>
      <select id="selectUsuario" class="form-control">
        <option value="">Todos los usuarios</option>
      </select>
    </div>
    <!-- Lista de locales filtrados -->
    <div class="form-group">
      <label>Locales disponibles (seleccione máximo 23):</label>
      <div id="listaLocales"></div>
    </div>
    <button class="btn btn-primary" onclick="generarRuta()">Generar Ruta</button>
    <button class="btn btn-success" onclick="exportarCSV()">Exportar a CSV</button>
    <div id="map"></div>
  </div>
</div>

<!-- Scripts -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>
<script>
  // Cargar Google Maps API de forma asíncrona (reemplaza YOUR_API_KEY con tu clave)
  function loadGoogleMapsAPI() {
    const script = document.createElement('script');
    script.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyAkWMIwHuWxwVkC-1Tk208gNRUBbwqZYIQ&callback=initMap&libraries=geometry';
    script.async = true;
    document.head.appendChild(script);
  }
  window.addEventListener('load', loadGoogleMapsAPI);
  
  let map, directionsService, directionsRenderer;
  let ubicaciones = [];
  let markers = [];
  
  function initMap() {
    map = new google.maps.Map(document.getElementById("map"), {
      zoom: 7,
      center: { lat: -33.45694, lng: -70.64827 }
    });
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer();
    directionsRenderer.setMap(map);
  }
  
  // Función para limpiar marcadores y ruta anterior
  function limpiarMapa() {
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    directionsRenderer.set('directions', null);
  }
  
  // Cargar select de Canal
  function cargarCanales() {
    $.ajax({
      url: 'modulos/mod_cargar/cargar_canales.php',
      type: 'GET',
      dataType: 'json',
      success: function(data) {
        let opciones = '<option value="">Todos los canales</option>';
        data.forEach(item => {
          opciones += `<option value="${item.id}">${item.nombre_canal}</option>`;
        });
        $("#selectCanal").html(opciones);
      },
      error: function() {
        $("#selectCanal").html('<option value="">Error al cargar canales</option>');
      }
    });
  }
  
  // Cargar select de Usuario
  function cargarUsuarios() {
    const empresaId = <?php echo json_encode($empresa_id); ?>;
    $.ajax({
      url: 'modulos/mod_cargar/cargar_usuarios.php',
      type: 'GET',
      dataType: 'json',
      data: { id_empresa: empresaId },
      success: function(data) {
        let opciones = '<option value="">Todos los usuarios</option>';
        data.forEach(item => {
          opciones += `<option value="${item.id}">${item.nombre_completo}</option>`;
        });
        $("#selectUsuario").html(opciones);
      },
      error: function() {
        $("#selectUsuario").html('<option value="">Error al cargar usuarios</option>');
      }
    });
  }
  
  // Cargar distritos basados en Canal
  function cargarDistritosPorCanal() {
    const id_canal = $("#selectCanal").val();
    const empresaId = <?php echo json_encode($empresa_id); ?>;
    if (id_canal) {
      $.ajax({
        url: 'modulos/mod_cargar/cargar_distritos_por_canal.php',
        type: 'GET',
        dataType: 'json',
        data: { id_canal: id_canal, id_empresa: empresaId },
        success: function(data) {
          let opciones = '<option value="">Todos los distritos</option>';
          data.forEach(item => {
            opciones += `<option value="${item.id}">${item.nombre_distrito}</option>`;
          });
          $("#selectDistrito").html(opciones);
        },
        error: function() {
          $("#selectDistrito").html('<option value="">Error al cargar distritos</option>');
        }
      });
    } else {
      $("#selectDistrito").html('<option value="">Todos los distritos</option>');
    }
    // Limpiar comuna al cambiar canal
    $("#selectComuna").html('<option value="">Todas las comunas</option>');
  }
  
  // Cargar comunas basadas en Canal y Distrito
  function cargarComunasPorCanalDistrito() {
    const id_canal = $("#selectCanal").val();
    const id_distrito = $("#selectDistrito").val();
    const empresaId = <?php echo json_encode($empresa_id); ?>;
    if (id_canal && id_distrito) {
      $.ajax({
        url: 'modulos/mod_cargar/cargar_comunas_por_canal_distrito.php',
        type: 'GET',
        dataType: 'json',
        data: { id_canal: id_canal, id_distrito: id_distrito, id_empresa: empresaId },
        success: function(data) {
          let opciones = '<option value="">Todas las comunas</option>';
          data.forEach(item => {
            opciones += `<option value="${item.id}">${item.comuna}</option>`;
          });
          $("#selectComuna").html(opciones);
        },
        error: function() {
          $("#selectComuna").html('<option value="">Error al cargar comunas</option>');
        }
      });
    } else {
      $("#selectComuna").html('<option value="">Todas las comunas</option>');
    }
  }
  
  // Cargar locales filtrados según los selects
  function cargarLocalesFiltrados() {
    const id_canal = $("#selectCanal").val();
    const id_distrito = $("#selectDistrito").val();
    const id_comuna = $("#selectComuna").val();
    const empresaId = <?php echo json_encode($empresa_id); ?>;
    
    $.ajax({
      url: 'modulos/cargar_locales_filtrados.php',
      type: 'GET',
      dataType: 'json',
      data: { 
        id_empresa: empresaId,
        id_canal: id_canal,
        id_distrito: id_distrito,
        id_comuna: id_comuna
      },
      success: function(response) {
        $("#listaLocales").empty();
        if (response.success && response.locales.length > 0) {
          response.locales.forEach(function(local) {
            const checkbox = `
              <div class="checkbox-local">
                <label>
                  <input type="checkbox" class="local-checkbox" value="${local.id}" data-lat="${local.lat}" data-lng="${local.lng}">
                  ${local.codigo} - ${local.nombre} (${local.direccion}) [${local.lat}, ${local.lng}]
                </label>
              </div>
            `;
            $("#listaLocales").append(checkbox);
          });
        } else {
          $("#listaLocales").html("<p>No se encontraron locales con esos filtros.</p>");
        }
      },
      error: function() {
        $("#listaLocales").html("<p>Error al cargar locales.</p>");
      }
    });
  }
  
  // Eventos de cambio en los selects
  $(document).ready(function(){
    cargarCanales();
    cargarUsuarios();
    
    $("#selectCanal").on("change", function(){
      cargarDistritosPorCanal();
      cargarLocalesFiltrados();
    });
    $("#selectDistrito").on("change", function(){
      cargarComunasPorCanalDistrito();
      cargarLocalesFiltrados();
    });
    $("#selectComuna, #selectUsuario").on("change", cargarLocalesFiltrados);
  });
  
  // Limitar selección a máximo 23 locales
  $(document).on('change', '.local-checkbox', function() {
    const maxSeleccion = 23;
    const seleccionados = $(".local-checkbox:checked").length;
    if (seleccionados > maxSeleccion) {
      alert("Solo se pueden seleccionar máximo " + maxSeleccion + " locales.");
      $(this).prop("checked", false);
    }
  });
  
        function generarRuta() {
          limpiarMapa();
          const origenLat = parseFloat($("#origenLat").val());
          const origenLng = parseFloat($("#origenLng").val());
          if (isNaN(origenLat) || isNaN(origenLng)) {
            alert("Por favor, ingresa una latitud y longitud válidas para el origen.");
            return;
          }
          const origen = { lat: origenLat, lng: origenLng };
        
          const destinosSeleccionados = [];
            $(".local-checkbox:checked").each(function() {
              const lat = parseFloat($(this).data("lat"));
              const lng = parseFloat($(this).data("lng"));
              if (!isNaN(lat) && !isNaN(lng)) {
                destinosSeleccionados.push({ lat: lat, lng: lng });
              }
            });
            
            // Asegurarse de que hay al menos un destino
            if (destinosSeleccionados.length === 0) {
              alert("Seleccione al menos un local para generar la ruta.");
              return;
            }
            
            // Convertir todos los destinos excepto el último en waypoints con el formato adecuado
            const waypoints = destinosSeleccionados.slice(0, destinosSeleccionados.length - 1)
                              .map(destino => ({ location: destino, stopover: true }));
            
            // Definir el objeto request para directionsService
            const request = {
              origin: { lat: parseFloat($("#origenLat").val()), lng: parseFloat($("#origenLng").val()) },
              destination: destinosSeleccionados[destinosSeleccionados.length - 1],
              waypoints: waypoints,
              travelMode: 'DRIVING'
            };
        
          console.log("Request de ruta:", request);
          
          directionsService.route(request, function(result, status) {
            if (status === 'OK') {
              directionsRenderer.setDirections(result);
              console.log("Ruta generada correctamente");
            } else {
              alert("No se pudo mostrar la ruta: " + status);
              console.error("Error en directionsService.route:", status);
            }
          });
        }
  
  // Función para limpiar marcadores y ruta previa
  function limpiarMapa() {
    markers.forEach(marker => marker.setMap(null));
    markers = [];
    directionsRenderer.set('directions', null);
  }
  
  // Función para exportar las ubicaciones a CSV
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

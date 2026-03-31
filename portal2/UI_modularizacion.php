<?php
// ui_dashboard.php
ini_set('display_errors', 0);
error_reporting(0);

// Iniciar sesión si aún no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Establecer la codificación interna a UTF-8 para las funciones multibyte
mb_internal_encoding('UTF-8');

// Incluir el archivo de conexión a la base de datos y datos de la sesión
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Obtener el ID de la empresa del usuario
$empresa_id = $_SESSION['empresa_id'];
$division_id = $_SESSION['division_id']; 
$perfilUser = $_SESSION['perfil_nombre'];

// Obtener el nombre de la empresa del usuario
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
$stmt_empresa->bind_param("i", $empresa_id);
$stmt_empresa->execute();
$stmt_empresa->bind_result($nombre_empresa);
if (!$stmt_empresa->fetch()) {
    echo "Empresa no encontrada.";
    $stmt_empresa->close();
    exit();
}
$stmt_empresa->close();

// Determinar si el usuario pertenece a "Mentecreativa"
$es_mentecreativa = false;
$nombre_empresa_limpio = mb_strtolower(trim($nombre_empresa), 'UTF-8');
if ($nombre_empresa_limpio === 'mentecreativa') {
    $es_mentecreativa = true;
}

// Obtener todas las empresas si el usuario es de "Mentecreativa"
if ($es_mentecreativa) {
    // Obtener todas las empresas para el filtro
    $stmt_all_empresas = $conn->prepare("SELECT id, nombre FROM empresa ORDER BY nombre ASC");
    if ($stmt_all_empresas) {
        $stmt_all_empresas->execute();
        $result_all_empresas = $stmt_all_empresas->get_result();
        $empresas_all = $result_all_empresas->fetch_all(MYSQLI_ASSOC);
        $stmt_all_empresas->close();
    } else {
        $empresas_all = [];
        // No mostrar mensaje de error al no poder obtener empresas
    }
} else {
    // Usuarios normales: Solo tienen acceso a su propia empresa
    $empresas_all = [
        ['id' => $empresa_id, 'nombre' => $nombre_empresa]
    ];
}


$sql_comp = "
    SELECT id AS id_campana, nombre AS nombre_campana, estado
    FROM formulario
    WHERE tipo = 2
    ORDER BY nombre ASC
";
$stmt_comp = $conn->prepare($sql_comp);
if ($stmt_comp === false) {
    die("Error en la preparación de la consulta de actividades complementarias: " . htmlspecialchars($conn->error));
}
$stmt_comp->execute();
$result_comp = $stmt_comp->get_result();
$compCampanas = [];
if ($result_comp->num_rows > 0) {
    while ($row = $result_comp->fetch_assoc()) {
        $compCampanas[] = [
            'id_campana'     => (int)$row['id_campana'],
            'nombre_campana' => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
            'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8')
        ];
    }
}
$stmt_comp->close();


// Inicializar variables para filtros
$empresa_seleccionada = isset($_GET['empresa']) ? intval($_GET['empresa']) : ($es_mentecreativa ? 0 : $empresa_id);
$division_seleccionada = isset($_GET['division']) ? intval($_GET['division']) : 0;


// Obtener las divisiones basadas en la empresa seleccionada
if ($es_mentecreativa && $empresa_seleccionada > 0) {
    $divisiones = obtenerDivisionesPorEmpresa($empresa_seleccionada);
} elseif (!$es_mentecreativa) {
    // Para usuarios no "Mentecreativa", obtener divisiones de su propia empresa
    $divisiones = obtenerDivisionesPorEmpresa($empresa_id);
} else {
    // Si el usuario es "Mentecreativa" pero no ha seleccionado una empresa, no mostrar divisiones
    $divisiones = [];
}

// Construir la consulta SQL para obtener los formularios
$parametros = [];
$tipos_param = '';
$filtros_sql = '';

if ($es_mentecreativa) {
    if ($empresa_seleccionada > 0) {
        // Filtrar por empresa específica seleccionada en el filtro
        $filtros_sql .= " AND f.id_empresa = ?";
        $parametros[] = $empresa_seleccionada;
        $tipos_param .= 'i';
    }
} else {
    // Usuarios no "Mentecreativa": Filtrar siempre por su propia empresa
    $filtros_sql .= " AND f.id_empresa = ?";
    $parametros[] = $empresa_id;
    $tipos_param .= 'i';
}

if ($division_seleccionada > 0) {
    $filtros_sql .= " AND f.id_division = ?";
    $parametros[] = $division_seleccionada;
    $tipos_param .= 'i';
}

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
  <link rel="stylesheet" href="dist/css/stylesUI.css">  

</head>
<body>
<div class="card card-widget">
  <div class="card-header">
    <div class="user-block">
      <img class="img-circle" src="dist/img/mentecreativa.png" alt="User Image">
      <span class="username"><a href="#">ACTIVIDADES PROGRAMADAS</a></span>
      <span class="description">Última actividad cargada el 7:30 PM 10-10-2024</span>
    </div>
    <div class="card-tools">
      <button type="button" class="btn btn-tool" data-card-widget="collapse">
        <i class="fas fa-minus"></i>
      </button>
    </div>
  </div>
  <div class="card-body">
    <div class="container mt-4">
      <!-- Filtro de Empresa y División -->
      <form id="filterForm" class="form-inline mb-3">
            <?php if ($division_id == 1): ?>
                <label class="mr-2" for="empresa_filter">Empresa:</label>
                <select name="empresa" id="empresa_filter" class="form-control mr-2">
                    <option value="0">-- Todas las Empresas --</option>
                    <?php foreach ($empresas_all as $empresa): ?>
                        <option value="<?php echo htmlspecialchars($empresa['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($empresa['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <!-- Para usuarios cuya división es diferente a 103, se autoasigna la empresa y no se muestra el filtro -->
                <input type="hidden" name="empresa" value="<?php echo htmlspecialchars($empresa_id, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>

            <?php if ($division_id == 1): ?>
                <label class="mr-2" for="division_filter">División:</label>
                <select name="division" id="division_filter" class="form-control mr-2">
                    <?php if (!empty($divisiones)): ?>
                        <option value="0">-- Todas las Divisiones --</option>
                        <?php foreach ($divisiones as $division): ?>
                            <option value="<?php echo htmlspecialchars($division['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($division['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="0">No hay divisiones</option>
                    <?php endif; ?>
                </select>
            <?php else: ?>
                <!-- Si la división del usuario no es 103, se fija en la de la sesión -->
                <input type="hidden" name="division" value="<?php echo htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
      </form>

      <!-- Contenedor de las Tarjetas de Actividades Programadas -->
      <div class="row" id="campaignCardsContainer">
          <!-- Aquí se cargarán las tarjetas vía AJAX -->
      </div>
    </div>
  </div>
</div>


  <div class="card-footer"></div>
    
    <!-- Mantener los mismos scripts JS que en tu código original -->
    <script src="plugins/jquery/jquery.min.js"></script>
    
    <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="dist/js/adminlte.min.js"></script>

<script>
// Función para renderizar cada tarjeta a partir de los datos de la campaña
function renderCampaignCard(campaign) {
  // Traducción del estado
  var estadoDescripcion = '';
  switch (parseInt(campaign.estado)) {
    case 1: estadoDescripcion = 'En Curso'; break;
    case 2: estadoDescripcion = 'En Proceso'; break;
    case 3: estadoDescripcion = 'Finalizado'; break;
    case 4: estadoDescripcion = 'Cancelado'; break;
    default: estadoDescripcion = 'Estado Desconocido';
  }

  // Construcción de enlaces (descarga, galería, informe)
  var downloadLink = "informes/descargar_excel.php?id=" + encodeURIComponent(campaign.id);
  var galleryLink  = "modulos/mod_galeria.php?id=" + encodeURIComponent(campaign.id);
  var informeLink  = "informes/UI_informe.php?id=" + encodeURIComponent(campaign.id);

  // Retornar la tarjeta con template literals
  return `
    <div class="col-12 col-sm-6 col-md-4 d-flex align-items-stretch">
      <div class="card card-widget widget-user shadow w-100">
        <div class="widget-user-header bg-info position-relative">
          <a href="${downloadLink}" target="_self" title="Descargar Excel" class="position-absolute download-link" style="top: 10px; right: 10px;">
            <img src="images/icon/download_excel.png" alt="Download" style="width: 40px; cursor: pointer;">
          </a>
          <div class="progress" style="position: absolute; top: 60px; right: 10px; width: 120px; display: none; background-color: #e9ecef; border-radius: 5px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; transition: width 0.4s ease; background-color: #28a745;" aria-valuemin="0" aria-valuemax="100">0%</div>
          </div>
          <h3 class="widget-user-username text-truncate">${campaign.nombre.toUpperCase()}</h3>
          <h5 class="widget-user-desc">${estadoDescripcion}</h5>
        </div>
        <div class="widget-user-image">
          <img class="elevation-2 zoom" src="dist/img/visibility2Logo.png" alt="User Avatar">
        </div>
        <div class="card-footer">
          <div class="row">
            <div class="col-sm-4 border-right">
              <div class="description-block">
                <h5 class="description-header">${campaign.locales_programados}</h5>
                <span class="description-text" style="font-size: 10px!important;">Programados</span>
              </div>
            </div>
            <div class="col-sm-4 border-right">
              <div class="description-block">
                <h5 class="description-header">${campaign.locales_visitados}</h5>
                <span class="description-text" style="font-size: 10px!important;">Visitados</span>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="description-block">
                <h5 class="description-header">${campaign.locales_implementados}</h5>
                <span class="description-text" style="font-size: 10px!important;">Ejecutados</span>
              </div>
            </div>
          </div>
          <div class="row mt-2 text-center">
            <div class="col-sm-4">
              <a class="btn btn-app" href="#" target="_self">
                <i class="fas fa-play"></i> En línea
              </a>
            </div>
            <div class="col-sm-4">
              <a class="btn btn-app" href="${galleryLink}" target="_self">
                <i class="fas fa-image"></i> Galería
              </a>
            </div>
            <div class="col-sm-4">
              <a class="btn btn-app" href="${informeLink}" target="_self">
                <i class="fas fa-bars"></i> Informe
              </a>
            </div>
          </div>
          <div class="row text-center">
            <div class="col-sm-6 border-right">
              <div class="inner">
                <h3 class="description-header">
                  <b style="color: yellowgreen;font-size: 20px;">${campaign.porcentaje_visitado}%</b>
                </h3>
                <p style="font-size:13px!important; font-weight: bold;">SALAS VISITADAS</p>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="inner">
                <h3 class="description-header">
                  <b style="color: yellowgreen;font-size: 20px;">${campaign.porcentaje_completado}%</b>
                </h3>
                <p style="font-size:13px!important; font-weight: bold;">SALAS IMPLEMENTADAS</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

// Función para cargar las campañas usando AJAX
function loadCampaigns() {
  // Recopilar parámetros del formulario
  var empresa  = $('#empresa_filter').val() || $('input[name="empresa"]').val();
  var division = $('#division_filter').val();
  
  $.ajax({
    url: 'obtener_campanas.php',
    method: 'GET',
    data: { empresa: empresa, division: division },
    dataType: 'json',
    success: function(response) {
      // Vaciar el contenedor de tarjetas
      $('#campaignCardsContainer').empty();
      if (response && response.length > 0) {
        $.each(response, function(index, campaign) {
          $('#campaignCardsContainer').append(renderCampaignCard(campaign));
        });
      } else {
        $('#campaignCardsContainer').html(
          '<div class="col-12">' +
            '<div class="alert alert-warning" role="alert">No hay campañas programadas disponibles.</div>' +
          '</div>'
        );
      }
    },
    error: function(xhr, status, error) {
      console.error("Error al cargar las campañas:", error);
      $('#campaignCardsContainer').html(
        '<div class="col-12">' +
          '<div class="alert alert-danger" role="alert">Error al cargar las campañas.</div>' +
        '</div>'
      );
    }
  });
}

$(document).ready(function() {
  // Cargar campañas al iniciar la página
  loadCampaigns();

  // Cuando se cambie alguno de los filtros, recargar las campañas sin refrescar la página
  $('#filterForm').on('change', function(e) {
    e.preventDefault();
    loadCampaigns();
  });
});
</script>

<script>
$(document).on('click', '.download-link', function(e) {
    e.preventDefault(); // Evita la navegación por defecto

    var url = $(this).attr('href');
    // Obtener el contenedor de la barra de progreso relativo al link clickeado
    var $header = $(this).closest('.widget-user-header');
    var $progressContainer = $header.find('.progress');
    var $progressBar = $progressContainer.find('.progress-bar');

    // Mostrar la barra de progreso
    $progressContainer.show();
    $progressBar.css('width', '0%').text('0%');

    $.ajax({
        url: url,
        type: 'GET',
        xhrFields: {
            responseType: 'blob' // La respuesta será un Blob (archivo binario)
        },
        // Usamos la función xhr para capturar el evento de progreso
        xhr: function() {
            var xhr = new XMLHttpRequest();
            xhr.responseType = 'blob';

            // Asignación directa (compatibilidad)
            xhr.onprogress = function(e) {
                if (e.lengthComputable) {
                    console.log("Progreso:", e.loaded, "/", e.total);
                    var percentComplete = Math.round((e.loaded / e.total) * 100);
                    $progressBar.css('width', percentComplete + '%').text(percentComplete + '%');
                }
            };

            // Y también mediante addEventListener
            xhr.addEventListener("progress", function(e) {
                if (e.lengthComputable) {
                    var percentComplete = Math.round((e.loaded / e.total) * 100);
                    $progressBar.css('width', percentComplete + '%').text(percentComplete + '%');
                }
            }, false);

            return xhr;
        },
        success: function(data, status, xhr) {
            // Extraer el nombre del archivo desde el encabezado Content-Disposition (si está disponible)
            var filename = "";
            var disposition = xhr.getResponseHeader('Content-Disposition');
            if (disposition && disposition.indexOf('attachment') !== -1) {
                var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                var matches = filenameRegex.exec(disposition);
                if (matches !== null && matches[1]) {
                    filename = matches[1].replace(/['"]/g, '');
                }
            }
            
            // Crear el objeto Blob y un enlace temporal para la descarga
            var blob = new Blob([data], { type: xhr.getResponseHeader('Content-Type') });
            var link = document.createElement('a');
            link.href = window.URL.createObjectURL(blob);
            link.download = filename || 'descarga.xlsx';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Ocultar la barra de progreso y reiniciar
            $progressContainer.hide();
            $progressBar.css('width', '0%').text('0%');
        },
        error: function() {
            alert('Error al descargar el archivo.');
            $progressContainer.hide();
            $progressBar.css('width', '0%').text('0%');
        }
    });
    
});
</script>

    <script>
      $(document).ready(function() {
          // Evento para actualizar las divisiones al cambiar la empresa en el filtro
          $('#empresa_filter').change(function() {
              var empresaId = $(this).val();
              var divisionSelect = $('#division_filter');

              if (empresaId === "0" || empresaId === "") {
                  // Si no se selecciona una empresa, limpiar las divisiones y mostrar "No hay divisiones"
                  divisionSelect.empty();
                  divisionSelect.append('<option value="0">No hay divisiones</option>');
              } else {
                  // Realizar una solicitud AJAX para obtener las divisiones
                  $.ajax({
                      url: 'obtener_divisiones_dashboard.php',
                      type: 'GET',
                      data: { id_empresa: empresaId },
                      dataType: 'json',
                      success: function(response) {
                          divisionSelect.empty();
                          if (response.length > 0) {
                              divisionSelect.append('<option value="0">-- Todas las Divisiones --</option>');
                              $.each(response, function(index, division) {
                                  divisionSelect.append('<option value="' + division.id + '">' + division.nombre + '</option>');
                              });
                          } else {
                              // Si no hay divisiones, mostrar "No hay divisiones"
                              divisionSelect.append('<option value="0">No hay divisiones</option>');
                          }
                      },
                      error: function(xhr, status, error) {
                          console.error('Error al obtener las divisiones:', error);
                          // Mostrar "No hay divisiones" en caso de error
                          divisionSelect.empty();
                          divisionSelect.append('<option value="0">No hay divisiones</option>');
                      }
                  });
              }
              // Enviar el formulario automáticamente al cambiar la empresa
              $('#filterForm').submit();
          });

          // Evento para enviar el formulario automáticamente al cambiar la división
          $('#division_filter').change(function() {
              $('#filterForm').submit();
          });
      });
    </script>
</body>
</html>


<?php
foreach ($_GET as $key => $value) {
    $_GET[$key] = trim($value);
}

session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

/* ================================
   VARIABLES SESIÓN
================================ */
$id_coordinador = intval($_SESSION['usuario_id']);
$nombreCoord    = $_SESSION['usuario_nombre'];
$apellidoCoord  = $_SESSION['usuario_apellido'];
$id_empresa     = intval($_SESSION['empresa_id']);

/* ================================
   VALIDAR EJECUTOR
================================ */
if (!isset($_GET['id_ejecutor'])) {
    die("Parámetro inválido: falta id_ejecutor.");
}

$id_ejecutor = intval($_GET['id_ejecutor']);

/* ================================
   FILTROS
================================ */
$division_formulario = isset($_GET['division_formulario']) ? intval($_GET['division_formulario']) : 0;
$subdivision_filtro  = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;
$id_campana          = isset($_GET['id_campana']) ? intval($_GET['id_campana']) : 0;
$verModo             = $_GET['ver'] ?? 'campana';

/* ================================
   VALIDAR EJECUTOR EMPRESA
================================ */
$sql_val_eje = "
    SELECT id_division, nombre, apellido
    FROM usuario
    WHERE id = ?
      AND id_perfil = 3
      AND activo = 1
      AND id_empresa = ?
";

$stmt_eje = $conn->prepare($sql_val_eje);
$stmt_eje->bind_param("ii", $id_ejecutor, $id_empresa);
$stmt_eje->execute();
$row_eje = $stmt_eje->get_result()->fetch_assoc();
$stmt_eje->close();

if (!$row_eje) {
    die("El ejecutor no existe o no pertenece a la empresa.");
}

$nombreEjec = $row_eje['nombre'] . ' ' . $row_eje['apellido'];

/* ================================
   QUERY PRINCIPAL (SIN GROUP BY)
================================ */
$sql_locales = "
SELECT 
    fq.id,
    f.nombre AS nombreCampana,
    l.id AS id_local,
    UPPER(l.nombre) AS nombre_local,
    UPPER(l.direccion) AS direccion_local,
    l.lat,
    l.lng,
    fq.material,
    fq.valor,
    CASE
        WHEN fq.pregunta = 'cancelado' THEN 'CANCELADO'
        WHEN fq.pregunta = 'completado' THEN 'COMPLETADO'
    
        WHEN fq.pregunta IN ('solo_implementado','solo_auditoria','solo_retirado','implementado_auditado') THEN
            CASE
                WHEN IFNULL(fq.valor,0) = 0 THEN 'NO IMPLEMENTADO'
                ELSE
                    CASE
                        WHEN fq.pregunta = 'solo_implementado' THEN 'IMPLEMENTADO'
                        WHEN fq.pregunta = 'solo_auditoria' THEN 'AUDITADO'
                        WHEN fq.pregunta = 'solo_retirado' THEN 'RETIRADO'
                        WHEN fq.pregunta = 'implementado_auditado' THEN 'IMPLE/AUDI'
                        ELSE 'EN PROCESO'
                    END
            END
    
        WHEN fq.pregunta = 'no_implementado' THEN 'NO IMPLEMENTADO'
        WHEN fq.pregunta = 'en proceso' OR fq.pregunta IS NULL OR fq.pregunta = '' THEN 'EN PROCESO'
        ELSE 'EN PROCESO'
    END AS estado_texto,

    fq.pregunta AS estado_raw,

    UPPER(
        CASE 
            WHEN fq.motivo IS NOT NULL AND fq.motivo <> '' THEN fq.motivo
            WHEN fq.observacion IS NOT NULL AND fq.observacion <> ''
                THEN 
                    CASE 
                        WHEN LOCATE('-', fq.observacion) > 0 
                            THEN TRIM(SUBSTRING_INDEX(fq.observacion, '-', 1))
                        ELSE fq.observacion
                    END
            ELSE ''
        END
    ) AS motivo_final,

    fq.fechaVisita,
    UPPER(fq.observacion) AS observacion,

    (
        SELECT GROUP_CONCAT(
            DISTINCT CONCAT(
                'https://visibility.cl/visibility2/app/',
                fv2.url
            )
            SEPARATOR '||'
        )
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id
    ) AS urls_fotos

FROM formularioQuestion fq
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN formulario f ON f.id = fq.id_formulario

WHERE fq.id_usuario = ?
  AND f.id_empresa = ?
";

$params = [$id_ejecutor, $id_empresa];
$types  = "ii";

if ($division_formulario > 0) {
    $sql_locales .= " AND f.id_division = ?";
    $types .= "i";
    $params[] = $division_formulario;
}

if ($verModo === 'campana' && $id_campana > 0) {
    $sql_locales .= " AND fq.id_formulario = ?";
    $types .= "i";
    $params[] = $id_campana;
}

if ($subdivision_filtro > 0) {
    $sql_locales .= " AND f.id_subdivision = ?";
    $types .= "i";
    $params[] = $subdivision_filtro;
}

$sql_locales .= " ORDER BY l.nombre ASC";

$stmt_loc = $conn->prepare($sql_locales);
$stmt_loc->bind_param($types, ...$params);
$stmt_loc->execute();
$res_loc = $stmt_loc->get_result();

$locales = [];
while ($row = $res_loc->fetch_assoc()) {
    $locales[] = $row;
}

$stmt_loc->close();
$conn->close();

$nombreCampana = $locales[0]['nombreCampana'] ?? '';

/* ================================
   MAPA
================================ */
$coordenadas_locales = [];

foreach ($locales as $loc) {

    if (empty($loc['lat']) || empty($loc['lng'])) {
        continue;
    }

    $estadoRaw = $loc['estado_raw'];
    $markerColor = 'orange';

    if (in_array($estadoRaw, [
        'completado',
        'implementado_auditado',
        'solo_auditoria',
        'solo_implementado',
        'solo_retirado'
    ])) {
        $markerColor = 'green';
    }
    elseif (in_array($estadoRaw, [
        'cancelado',
        'no_implementado'
    ])) {
        $markerColor = 'red';
    }

    $coordenadas_locales[] = [
        'idLocal' => (int)$loc['id_local'],
        'nombre_local' => $loc['nombre_local'] . ' - ' . $loc['direccion_local'],
        'latitud' => (float)$loc['lat'],
        'longitud' => (float)$loc['lng'],
        'markerColor' => $markerColor
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title><?php echo ($verModo==='campana') ? 'Locales de Campaña' : 'Todos los Locales'; ?></title>
  <!-- Bootstrap -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- FontAwesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
   
    
<style>
.carousel-item {
    height: 450px; /* tamaño medio elegante */
}

.carousel-item img {
    max-height: 100%;
    max-width: 100%;
    object-fit: contain; /* muestra completa sin recorte */
}
</style>

</head>
<body>
<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title">
      <i class="fas fa-map-marker-alt"></i>
      <?php echo ($verModo==='campana') 
        ? 'Locales de la Campaña'
        : 'Todos los Locales del Ejecutor'; ?>
    </h1>
    <p class="mb-0">Coordinador: <?php echo htmlspecialchars($nombreCoord.' '.$apellidoCoord); ?></p>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h4 style="font-size:0.9rem;">Ejecutor: <?php echo htmlspecialchars($nombreEjec); ?></h4>
            <h5>Campaña: <?php echo htmlspecialchars($nombreCampana); ?></h5>

      <div class="my-3">
        <!-- Botones de modo -->
        <?php if ($verModo==='campana'): ?>
          <a hidden href="mod_panel_detalle_locales.php?id_ejecutor=<?php echo $id_ejecutor; ?>&ver=todos"
             class="btn btn-info">
            <i   class="fas fa-globe"></i> Ver TODOS los Locales
          </a>
        <?php else: ?>
          <?php if ($id_campana > 0): ?>
            <a href="mod_panel_detalle_locales.php?id_ejecutor=<?php echo $id_ejecutor; ?>&id_campana=<?php echo $id_campana; ?>&ver=campana"
               class="btn btn-primary">
              <i class="fas fa-filter"></i> Ver sólo esta Campaña
            </a>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Botón volver -->
        <?php if ($verModo==='campana'): ?>
          <a href="mod_panel_detalle.php?id_ejecutor=<?php echo $id_ejecutor; ?>"
             class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Campañas
          </a>
        <?php else: ?>
          <a href="mod_panel_detalle.php?id_ejecutor=<?php echo $id_ejecutor; ?>"
             class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver a Campañas
          </a>
        <?php endif; ?>
      </div>

      <!-- Mapa -->
      <div id="map"></div>

      <hr>
      <h5>Listado de Locales</h5>
        <?php if (!empty($locales)): ?>
          <div class="table-responsive">
            <table id="tablaLocales" class="table table-striped table-bordered">
              <thead>
                <tr>
                  <?php if ($verModo==='todos'): ?>
                    <th>Campaña</th>
                  <?php endif; ?>
                  <th>Local</th>
                  <th>Dirección</th>
                  <th>Material</th>
                  <th>Estado</th>
                  <th>Motivo</th>
                  <th>Fecha Visita</th>
                  <th>Observación</th>
                  <th>Fotos</th>
                </tr>
              </thead>
              <tbody>
                  
              <?php foreach ($locales as $loc):
                $nombreLoc = htmlspecialchars($loc['nombre_local']);
                $dirLoc    = htmlspecialchars($loc['direccion_local']);
                $estadoTexto = $loc['estado_texto'];
                $estadoRaw   = $loc['estado_raw'];
                $motivo   = $loc['motivo_final'];
                $urls = [];
                if (!empty($loc['urls_fotos'])) {
                    $urls = explode('||', $loc['urls_fotos']);
                }
                $fechaV    = $loc['fechaVisita'] ? date('d-m-Y', strtotime($loc['fechaVisita'])) : '-';
                $horaV    = $loc['fechaVisita'] ? date('H:i', strtotime($loc['fechaVisita'])) : '-';                
                $obs       = !empty($loc['observacion']) ? htmlspecialchars($loc['observacion']) : '-';
                $campana   = isset($loc['nombre_campana']) ? htmlspecialchars($loc['nombre_campana']) : '';
                $material  = !empty($loc['material']) ? htmlspecialchars($loc['material']) : '-';

                // Badge para estado
                $badgeEstado = '';
                
                switch ($estadoTexto) {
                    case 'COMPLETADO':
                    case 'IMPLEMENTADO':
                    case 'AUDITADO':
                    case 'RETIRADO':
                    case 'IMPLE/AUDI':
                        $badgeEstado = '<span class="badge badge-completados">'.$estadoTexto.'</span>';
                        break;
                
                    case 'NO IMPLEMENTADO':
                    case 'CANCELADO':
                        $badgeEstado = '<span class="badge badge-cancelados">'.$estadoTexto.'</span>';
                        break;
                
                    default:
                        $badgeEstado = '<span class="badge badge-pendientes">EN PROCESO</span>';
                }
              ?>
                <tr>
                  <?php if ($verModo==='todos'): ?>
                    <td><?php echo $campana ?: '-'; ?></td>
                  <?php endif; ?>
                  <td><?php echo $nombreLoc; ?></td>
                  <td><?php echo $dirLoc; ?></td>
                  <td><?php echo $material; ?></td>
                  <td class="text-center"><?php echo $badgeEstado; ?></td>
                  <td class="text-center"><?php echo $motivo; ?></td> 
                  <td class="text-center"><?php echo $fechaV; ?></td>
                  <td><?php echo $obs; ?></td>
                    <td class="text-center">
                    <?php if (!empty($urls)): ?>
                    <button 
                        type="button"
                        class="btn btn-sm btn-primary btn-ver-fotos"
                        data-fotos='<?= htmlspecialchars(json_encode($urls), ENT_QUOTES, 'UTF-8') ?>'
                        data-local="<?= htmlspecialchars($nombreLoc) ?>"
                        
                    ><i class="fas fa-images"></i> Ver Fotos</button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-secondary" disabled>
                            <i class="fas fa-images"></i> Sin Fotos
                        </button>
                    <?php endif; ?>
                    </td>                  
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p>No se encontraron registros en esta vista.</p>
        <?php endif; ?>

      </form>

    </div>
  </div>
</div>

<div class="modal fade" id="modalFotos" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Fotos del Local</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body">

        <div id="carouselFotos" class="carousel slide" data-ride="carousel">
          <div class="carousel-inner" id="carouselInner"></div>

          <a class="carousel-control-prev" href="#carouselFotos" role="button" data-slide="prev">
            <span class="carousel-control-prev-icon"></span>
          </a>

          <a class="carousel-control-next" href="#carouselFotos" role="button" data-slide="next">
            <span class="carousel-control-next-icon"></span>
          </a>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- jQuery / Bootstrap -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<!-- Google Maps API con callback initMap -->
<script async defer
  src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap">
</script>

<script>
$(document).ready(function() {
    $('#tablaLocales').DataTable({
        order: [[0, "asc"]], // orden inicial por primera columna
        pageLength: 25,
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json"
        },
        columnDefs: [
            { orderable: false, targets: 7 } // bloquea orden columna 7
        ]
    });
});
</script>

<script>
var coordenadasLocales = <?php echo json_encode($coordenadas_locales); ?>;
var mapa;
var markers = [];

function initMap() {
  mapa = new google.maps.Map(document.getElementById('map'), {
    zoom: 6,
    center: { lat: -33.4489, lng: -70.6693 }
  });

  coordenadasLocales.forEach(function(local) {
    var iconUrl = 'https://maps.google.com/mapfiles/ms/icons/red-dot.png';
    if (local.markerColor === 'orange') {
      iconUrl = 'https://maps.google.com/mapfiles/ms/icons/orange-dot.png';
    } else if (local.markerColor === 'green') {
      iconUrl = 'https://maps.google.com/mapfiles/ms/icons/green-dot.png';
    }

    var marker = new google.maps.Marker({
      position: { lat: local.latitud, lng: local.longitud },
      map: mapa,
      title: local.nombre_local,
      icon: iconUrl
    });

    var infoWindow = new google.maps.InfoWindow({
      content: '<strong>' + local.nombre_local + '</strong>'
    });
    marker.addListener('click', function() {
      infoWindow.open(mapa, marker);
    });
    markers.push(marker);
  });

  if (markers.length > 0) {
    var bounds = new google.maps.LatLngBounds();
    markers.forEach(function(m) {
      bounds.extend(m.getPosition());
    });
    mapa.fitBounds(bounds);
  }
    let ejecutorMarker = null;

  function pollUbicacionEjecutor(id_ejecutor) {
    fetch('poll_ubicacion.php?id_ejecutor=' + id_ejecutor)
      .then(r => r.json())
      .then(data => {
        if (!data) {
          console.log("Sin ubicación del ejecutor");
          return;
        }

        // Mover o crear marker
        const latLng = { lat: data.lat, lng: data.lng };
        if (!ejecutorMarker) {
          // Crear por primera vez
          ejecutorMarker = new google.maps.Marker({
            position: latLng,
            map: mapa, // asumiendo que 'mapa' es la variable global
            title: "Ubicación Actual del Ejecutor",
            icon: {
              url: "../../images/icon/marker_user.png"
            }
          });
        } else {
          // Actualizar posición
          ejecutorMarker.setPosition(latLng);
        }
      })
      .catch(err => console.error("Error pollUbicacion:", err));
  }

  // Llamamos la primera vez
  pollUbicacionEjecutor(<?php echo $id_ejecutor; ?>);

  // Luego cada 30 segundos
    setInterval(function() {
        pollUbicacionEjecutor(<?= $id_ejecutor ?>);
    }, 30000); // 30 segundos
}
</script>


<script>
    $(document).on('click', '.btn-ver-fotos', function() {

    const fotos = $(this).data('fotos');
    const nombreLocal = $(this).data('local');

    $('#modalFotos .modal-title').text("Fotos - " + nombreLocal);

    let html = '';

    fotos.forEach((url, index) => {
        html += `
            <div class="carousel-item ${index === 0 ? 'active' : ''}">
                <img src="${url}" class="d-block w-100 rounded">
            </div>
        `;
    });

    $('#carouselInner').html(html);
    $('#modalFotos').modal('show');
});
</script>
</body>
</html>

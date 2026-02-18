<?php
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
$id_division    = intval($_SESSION['division_id']);
$id_empresa     = intval($_SESSION['empresa_id']);

/* ================================
   FILTROS GET
================================ */
$division_filtro = isset($_GET['division']) ? intval($_GET['division']) : $id_division;
$subdivision_filtro = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

$hoy = date('Y-m-d');
if (empty($fecha_desde) && empty($fecha_hasta)) {
    $fecha_desde = $hoy;
    $fecha_hasta = $hoy;
}

if (!isset($_GET['id_ejecutor'])) {
    die("Parámetro inválido: falta id_ejecutor.");
}
$id_ejecutor = intval($_GET['id_ejecutor']);

$id_campana = isset($_GET['id_campana']) ? intval($_GET['id_campana']) : 0;
$verModo    = $_GET['ver'] ?? 'campana';

/* ================================
   VALIDAR EJECUTOR
================================ */
$sql_val_eje = "
    SELECT COUNT(*) AS cnt
    FROM usuario
    WHERE id = ?
      AND id_perfil = 3
      AND activo = 1
      AND id_division = ?
      AND id_empresa = ?
";
$stmt_eje = $conn->prepare($sql_val_eje);
$stmt_eje->bind_param("iii", $id_ejecutor, $division_filtro, $id_empresa);
$stmt_eje->execute();
$row_eje = $stmt_eje->get_result()->fetch_assoc();
$stmt_eje->close();

if ($row_eje['cnt'] == 0) {
    die("El ejecutor no pertenece a la división seleccionada.");
}

/* ================================
   NOMBRE EJECUTOR
================================ */
$stmt_n = $conn->prepare("SELECT nombre, apellido FROM usuario WHERE id = ?");
$stmt_n->bind_param("i", $id_ejecutor);
$stmt_n->execute();
$rowN = $stmt_n->get_result()->fetch_assoc();
$stmt_n->close();
$nombreEjec = $rowN ? $rowN['nombre'].' '.$rowN['apellido'] : 'Ejecutor';

/* ================================
   QUERY PRINCIPAL
================================ */
$sql_locales = "
    SELECT 
      fq.id,
      l.id AS id_local,
      upper(l.nombre) as nombre_local,
      upper(l.direccion) as direccion_local,
      l.lat,
      l.lng,
      fq.estado,
      fq.fechaVisita,
      upper(fq.observacion),
      fq.countVisita,
      fq.material,
      fq.is_priority,
      f.nombre AS nombre_campana
    FROM formularioQuestion fq
    INNER JOIN local l ON l.id = fq.id_local
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND f.id_division = ?
";

$params = [$id_ejecutor, $id_empresa, $division_filtro];
$types  = "iii";

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

$inicio = $fecha_desde . " 00:00:00";
$fin    = $fecha_hasta . " 23:59:59";

$sql_locales .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) BETWEEN ? AND ?";
$types .= "ss";
$params[] = $inicio;
$params[] = $fin;

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


/* ================================
   CONSTRUIR COORDENADAS PARA MAPA
================================ */

$coordenadas_locales = [];

foreach ($locales as $loc) {

    if (empty($loc['lat']) || empty($loc['lng'])) {
        continue;
    }

    $markerColor = 'orange';

    if ((int)$loc['countVisita'] === 0) {
        $markerColor = 'red';
    } elseif ((int)$loc['estado'] === 1) {
        $markerColor = 'green';
    }

    $coordenadas_locales[] = [
        'idLocal' => (int)$loc['id_local'],
        'nombre_local' => $loc['nombre'] . ' - ' . $loc['direccion'],
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
      <?php if ($verModo==='campana'): ?>
        <h5>Campaña: <?php echo htmlspecialchars($nombreCampana); ?></h5>
      <?php endif; ?>

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

      <!-- Iniciamos formulario para marcar prioridad -->
      <form method="POST" action="procesar_prioridad.php">
        <!-- Parámetros ocultos para volver aquí con el modo/campaña/ejecutor -->
        <input type="hidden" name="id_ejecutor" value="<?php echo $id_ejecutor; ?>">
        <input type="hidden" name="id_campana" value="<?php echo $id_campana; ?>">
        <input type="hidden" name="ver" value="<?php echo htmlspecialchars($verModo); ?>">

        <?php if (!empty($locales)): ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered">
              <thead>
                <tr>
                  <?php if ($verModo==='todos'): ?>
                    <th>Campaña</th>
                  <?php endif; ?>
                  <th>Local</th>
                  <th>Dirección</th>
                  <th>Material</th>
                  <th>Estado</th>
                  <th>Fecha Visita</th>
                  <th>Observación</th>
                  <th>Prioridad</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($locales as $loc):
                $nombreLoc = htmlspecialchars($loc['nombre_local']);
                $dirLoc    = htmlspecialchars($loc['direccion_local']);
                $estado    = (int)$loc['estado_local'];
                $fechaV    = $loc['fechaVisita'] ? date('d-m-Y', strtotime($loc['fechaVisita'])) : '-';
                $horaV    = $loc['fechaVisita'] ? date('H:i', strtotime($loc['fechaVisita'])) : '-';                
                $obs       = !empty($loc['observacion']) ? htmlspecialchars($loc['observacion']) : '-';
                $campana   = isset($loc['nombre_campana']) ? htmlspecialchars($loc['nombre_campana']) : '';
                $material  = !empty($loc['material']) ? htmlspecialchars($loc['material']) : '-';

                // is_priority
                // Nota: Ajusta si tu campo se llama distinto
                $isPrio = isset($loc['is_priority']) ? (int)$loc['is_priority'] : 0;

                // Badge para estado
                $badgeEstado = '';
                if ($estado === 0) {
                    $badgeEstado = '<span class="badge badge-custom badge-pendientes">Pend.</span>';
                } elseif ($estado === 1) {
                    $badgeEstado = '<span class="badge badge-custom badge-completados">Compl.</span>';
                } else {
                    $badgeEstado = '<span class="badge badge-custom badge-cancelados">Cancel.</span>';
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
                  <td class="text-center"><?php echo $fechaV; ?></td>
                  <td><?php echo $obs; ?></td>
                  <!-- Checkbox de prioridad -->
                  <td class="text-center">
                    <input type="checkbox" class="chk-prio"
                           name="priority[<?php echo $loc['id_local']; ?>]"
                           value="1"
                           <?php if ($isPrio===1) echo 'checked'; ?>>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Guardar Prioridad
          </button>
        <?php else: ?>
          <p>No se encontraron registros en esta vista.</p>
        <?php endif; ?>

      </form>

    </div>
  </div>
</div>

<!-- jQuery / Bootstrap -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- Google Maps API con callback initMap -->
<script async defer
  src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap">
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
</body>
</html>

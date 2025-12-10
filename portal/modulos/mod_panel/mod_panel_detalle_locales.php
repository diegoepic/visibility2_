<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

/*
  Código base que:
   - Muestra locales de UNA campaña (ver=campana) o TODOS (ver=todos).
   - Usa la lógica:
       * ROJO ("no visitado") => sum(countVisita)=0
       * VERDE ("completado") => todos en estado=1 (completados)
       * AMARILLO ("pendientes") => caso intermedio
   - En la TABLA, se muestra 'material' en vez de 'countVisita'.
   - NUEVO: Añadimos la columna "Prioridad" con un checkbox para is_priority.
*/

// ========================= Verificación de sesión y perfil =========================
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$id_coordinador = intval($_SESSION['usuario_id']);
$nombreCoord    = $_SESSION['usuario_nombre'] ;
$apellidoCoord  = $_SESSION['usuario_apellido'] ;
$id_perfil      = intval($_SESSION['usuario_perfil']);
$id_division    = intval($_SESSION['division_id']);
$id_empresa     = intval($_SESSION['empresa_id'] );


// ========================= Parámetros GET =========================
if (!isset($_GET['id_ejecutor'])) {
    die("Parámetro inválido: falta id_ejecutor.");
}
$id_ejecutor = intval($_GET['id_ejecutor']);

$id_campana = isset($_GET['id_campana']) ? intval($_GET['id_campana']) : 0;
$verModo    = isset($_GET['ver']) ? $_GET['ver'] : 'campana';

// ========================= Validar Ejecutor =========================
$sql_val_eje = "
    SELECT COUNT(*) AS cnt
    FROM usuario
    WHERE id = ?
      AND id_perfil = 3
      AND id_division = ?
      AND id_empresa = ?
";
$stmt_eje = $conn->prepare($sql_val_eje);
if (!$stmt_eje) {
    die("Error preparando (valida ejecutor): " . htmlspecialchars($conn->error));
}
$stmt_eje->bind_param("iii", $id_ejecutor, $id_division, $id_empresa);
$stmt_eje->execute();
$res_eje = $stmt_eje->get_result();
$row_eje = $res_eje->fetch_assoc();
$stmt_eje->close();

if ($row_eje['cnt'] == 0) {
    die("El ejecutor no pertenece a tu división o no existe.");
}

// Obtener nombre del ejecutor
$sql_nombre_ej = "SELECT nombre, apellido FROM usuario WHERE id = ?";
$stmt_n = $conn->prepare($sql_nombre_ej);
$stmt_n->bind_param("i", $id_ejecutor);
$stmt_n->execute();
$res_n = $stmt_n->get_result();
$rowN = $res_n->fetch_assoc();
$stmt_n->close();
$nombreEjec = $rowN ? ($rowN['nombre'].' '.$rowN['apellido']) : 'Ejecutor desconocido';

// ========================= Validar campaña si ver=campana =========================
$nombreCampana = '';
if ($verModo === 'campana') {
    if ($id_campana <= 0) {
        die("Parámetro id_campana inválido (0 o ausente).");
    }
    $sql_val_cam = "
        SELECT nombre
        FROM formulario
        WHERE id = ?
          AND id_empresa = ?
        LIMIT 1
    ";
    $stmt_cam = $conn->prepare($sql_val_cam);
    if (!$stmt_cam) {
        die("Error (valida campaña): " . htmlspecialchars($conn->error));
    }
    $stmt_cam->bind_param("ii", $id_campana, $id_empresa);
    $stmt_cam->execute();
    $res_cam = $stmt_cam->get_result();
    $row_cam = $res_cam->fetch_assoc();
    $stmt_cam->close();

    if (!$row_cam) {
        die("La campaña no existe o no pertenece a tu empresa.");
    }
    $nombreCampana = $row_cam['nombre'];
}

// ========================= Consulta principal =========================
// Agregamos "fq.is_priority" para manejar la prioridad
if ($verModo === 'campana') {
    // SOLO locales de ESA campaña
    $sql_locales = "
        SELECT 
          fq.id AS id_form_question,
          l.id AS id_local,
          l.nombre AS nombre_local,
          l.direccion AS direccion_local,
          l.lat AS latitud,
          l.lng AS longitud,
          fq.estado AS estado_local,
          fq.fechaVisita,
          fq.observacion,
          fq.countVisita,
          fq.material,
          fq.is_priority
        FROM formularioQuestion fq
        INNER JOIN local l ON l.id = fq.id_local
        WHERE fq.id_usuario = ?
          AND fq.id_formulario = ?
    ";
    $stmt_loc = $conn->prepare($sql_locales);
    $stmt_loc->bind_param("ii", $id_ejecutor, $id_campana);
} else {
    // ver "todos" los locales del ejecutor
    $sql_locales = "
        SELECT 
          fq.id AS id_form_question,
          l.id AS id_local,
          l.nombre AS nombre_local,
          l.direccion AS direccion_local,
          l.lat AS latitud,
          l.lng AS longitud,
          fq.estado AS estado_local,
          fq.fechaVisita,
          fq.observacion,
          fq.countVisita,
          fq.material,
          fq.is_priority,
          f.nombre AS nombre_campana
        FROM formularioQuestion fq
        INNER JOIN local l ON l.id = fq.id_local
        INNER JOIN formulario f ON f.id = fq.id_formulario
        WHERE fq.id_usuario = ?
          AND f.id_empresa = ?
    ";
    $stmt_loc = $conn->prepare($sql_locales);
    $stmt_loc->bind_param("ii", $id_ejecutor, $id_empresa);
}

$stmt_loc->execute();
$res_loc = $stmt_loc->get_result();

$locales = [];
while ($rowL = $res_loc->fetch_assoc()) {
    $locales[] = $rowL;
}
$stmt_loc->close();
$conn->close();

/**
 * Reagrupamos para el MAPA (suma countVisita, ver completados, etc.)
 *   - ROJO => sum(countVisita)=0
 *   - VERDE => todos en estado=1
 *   - AMARILLO => caso intermedio
 */
$infoLocales = [];
foreach ($locales as $fila) {
    $idLocal = (int)$fila['id_local'];
    if (!isset($infoLocales[$idLocal])) {
        $infoLocales[$idLocal] = [
            'id_local'   => $idLocal,
            'nombre_local' => $fila['nombre_local'],
            'direccion_local' => $fila['direccion_local'],
            'latitud'    => (float)$fila['latitud'],
            'longitud'   => (float)$fila['longitud'],
            'total_mat'  => 0,
            'completados'=> 0,
            'sum_visita' => 0,
        ];
    }
    $infoLocales[$idLocal]['total_mat']++;
    $infoLocales[$idLocal]['sum_visita'] += (int)$fila['countVisita'];
    if ((int)$fila['estado_local'] === 1) {
        $infoLocales[$idLocal]['completados']++;
    }
}

$coordenadas_locales = [];
foreach ($infoLocales as $loc) {
    $sumV = $loc['sum_visita'];
    $totM = $loc['total_mat'];
    $comp = $loc['completados'];

    // ROJO => sum_visita=0
    // VERDE => completados == total_mat
    // AMARILLO => caso intermedio
    $markerColor = 'orange';
    if ($sumV === 0) {
        $markerColor = 'red';
    } elseif ($comp === $totM) {
        $markerColor = 'green';
    }

    $markerTitle = $loc['nombre_local'].' - '.$loc['direccion_local'];
    $coordenadas_locales[] = [
      'idLocal' => $loc['id_local'],
      'nombre_local' => $markerTitle,
      'latitud' => $loc['latitud'],
      'longitud' => $loc['longitud'],
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
  <style>
    body {
      background-color: #f4f6f9; 
    }
    th{
        font-size: 0.85rem
    }
    td{
        font-size: 0.8rem;
    }    
    .card-panel {
      margin-top: 30px;
    }
    .panel-header {
      background: #6c757d; 
      color: #fff;
      padding: 15px;
      border-radius: 3px 3px 0 0;
    }
    h1.panel-title {
      margin: 0;
      font-size: 24px;
    }
    .table th {
      background: #f1f1f1;
    }
    /* Badge */
    .badge-custom {
      font-size: 90%;
      padding: .4em .6em;
    }
    .badge-pendientes {
      background-color: #f39c12; 
    }
    .badge-completados {
      background-color: #00a65a; 
    }
    .badge-cancelados {
      background-color: #dd4b39;
    }
    #map {
      width: 100%;
      height: 300px;
      margin-bottom: 20px;
    }

    /* Checkbox de prioridad */
    .chk-prio {
      transform: scale(1.2);
      margin: 0 8px;
      cursor: pointer;
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
                $fechaV    = $loc['fechaVisita'] ? date('d-m-Y H:i', strtotime($loc['fechaVisita'])) : '-';
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
    pollUbicacionEjecutor(<?php echo $id_ejecutor; ?>);
  }, 100);
}
</script>
</body>
</html>

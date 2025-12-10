<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$id_empresa        = intval($_SESSION['empresa_id']);
$user_division_id  = intval($_SESSION['division_id']);   // división del usuario
$isMC              = ($user_division_id === 1);          // regla: 1 => MC

// ---------- Filtros ----------
$filter_division    = $isMC ? intval($_GET['id_division'] ?? 0) : $user_division_id; // si no es MC, fija su división
$filter_subdivision = isset($_GET['id_subdivision']) ? intval($_GET['id_subdivision']) : 0; // 0=todas, -1=sin subdivisión
$filter_estado      = isset($_GET['estado']) ? intval($_GET['estado']) : 1;                 // 1=en curso, 3=finalizadas
$filter_campana     = intval($_GET['id_campana'] ?? 0);                                      // campaña (opcional)
$id_ejecutor        = intval($_GET['id_ejecutor'] ?? 0);
$filter_distrito    = intval($_GET['id_distrito'] ?? 0);
$fecha_desde        = trim($_GET['desde'] ?? '');  // YYYY-MM-DD
$fecha_hasta        = trim($_GET['hasta'] ?? '');  // YYYY-MM-DD
$tipoCampana        = 1; // CAMPANAS PROGRAMADAS

// ---------- Catálogo: Divisiones (solo si MC) ----------
$divisiones = [];
if ($isMC) {
  $sql_divisiones = "
    SELECT de.id, de.nombre
    FROM division_empresa de
    WHERE de.id_empresa = ? AND de.estado = 1
    ORDER BY de.nombre
  ";
  $stmt = $conn->prepare($sql_divisiones);
  $stmt->bind_param("i", $id_empresa);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $divisiones[] = $r; }
  $stmt->close();
}

// ---------- Catálogo: Subdivisiones ----------
$subdivisiones = [];
if ($filter_division > 0) {
  $sql_sub = "SELECT id, nombre FROM subdivision WHERE id_division = ? ORDER BY nombre";
  $stSub = $conn->prepare($sql_sub);
  $stSub->bind_param("i", $filter_division);
  $stSub->execute();
  $rsSub = $stSub->get_result();
  while ($r = $rsSub->fetch_assoc()) { $subdivisiones[] = $r; }
  $stSub->close();
}

// ---------- Catálogo: Campañas por división + estado + tipo (+ subdivisión) ----------
$campanas = [];
if ($filter_division > 0) {
  $sql_camp = "
    SELECT f.id, f.nombre
    FROM formulario f
    WHERE f.id_empresa = ?
      AND f.id_division = ?
      AND f.tipo = ?
      AND f.estado = ?
  ";
  $paramsCamp = [$id_empresa, $filter_division, $tipoCampana, $filter_estado];
  $typesCamp  = "iiii";

  // Subdivisión
  if ($filter_subdivision === -1) {
    $sql_camp .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0) ";
  } elseif ($filter_subdivision > 0) {
    $sql_camp .= " AND f.id_subdivision = ? ";
    $paramsCamp[] = $filter_subdivision; $typesCamp .= "i";
  }

  $sql_camp .= " ORDER BY COALESCE(f.fechaInicio, '1970-01-01') DESC, f.id DESC";

  $stmt = $conn->prepare($sql_camp);
  $stmt->bind_param($typesCamp, ...$paramsCamp);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $campanas[] = $r; }
  $stmt->close();
}

// ---------- Catálogo: Distritos ----------
$distritos = [];
$sql_distritos = "
  SELECT DISTINCT d.id, d.nombre_distrito
  FROM local l
  INNER JOIN distrito d ON l.id_distrito = d.id
  WHERE l.id_empresa = ?
  ORDER BY d.nombre_distrito
";
$stmt = $conn->prepare($sql_distritos);
$stmt->bind_param("i", $id_empresa);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $distritos[] = $r; }
$stmt->close();

// ---------- Catálogo: Ejecutores (SIN depender de campaña) ----------
$ejecutores = [];
if ($filter_division > 0) {
  $sql_ejs = "
    SELECT DISTINCT u.id, u.nombre, u.apellido
    FROM usuario u
    JOIN formularioQuestion fq ON fq.id_usuario = u.id
    JOIN formulario f          ON f.id = fq.id_formulario
    JOIN local l               ON l.id = fq.id_local
    WHERE u.id_perfil = 3
      AND f.id_empresa = ?
      AND f.tipo       = ?
      AND f.estado     = ?
      AND f.id_division = ?
  ";

  $typesE  = "iiii";
  $paramsE = [$id_empresa, $tipoCampana, $filter_estado, $filter_division];

  // Subdivisión
  if ($filter_subdivision === -1) {
    $sql_ejs .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0) ";
  } elseif ($filter_subdivision > 0) {
    $sql_ejs .= " AND f.id_subdivision = ? ";
    $typesE  .= "i";
    $paramsE[] = $filter_subdivision;
  }

  // Distrito (opcional)
  if ($filter_distrito > 0) {
    $sql_ejs .= " AND l.id_distrito = ? ";
    $typesE  .= "i";
    $paramsE[] = $filter_distrito;
  }

  // Rango fechas (opcional) — usa fechaPropuesta para ruta/planificación
  if ($fecha_desde !== '') {
    $sql_ejs .= " AND fq.fechaPropuesta >= ? ";
    $typesE  .= "s";
    $paramsE[] = $fecha_desde . " 00:00:00";
  }
  if ($fecha_hasta !== '') {
    $sql_ejs .= " AND fq.fechaPropuesta <= ? ";
    $typesE  .= "s";
    $paramsE[] = $fecha_hasta . " 23:59:59";
  }

  $sql_ejs .= " ORDER BY u.nombre, u.apellido ";

  $stmt = $conn->prepare($sql_ejs);
  $stmt->bind_param($typesE, ...$paramsE);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $ejecutores[] = $r; }
  $stmt->close();
}

// ---------- Consulta de Locales (SI hay ejecutor; campaña opcional) ----------
$locales = [];
$nombreEjec = "";
if ($id_ejecutor > 0) {
  // Nombre ejecutor
  $st = $conn->prepare("SELECT nombre, apellido FROM usuario WHERE id = ?");
  $st->bind_param("i", $id_ejecutor);
  $st->execute(); $res = $st->get_result();
  if ($row = $res->fetch_assoc()) { $nombreEjec = $row['nombre'].' '.$row['apellido']; }
  $st->close();

  // Base: todas las campañas programadas del ejecutor, según filtros (campaña opcional)
  $sql = "
    SELECT DISTINCT
      f.nombre AS Actividad,
      l.id        AS id_local,
      l.nombre    AS nombre_local,
      l.direccion AS direccion_local,
      l.lat       AS latitud,
      l.lng       AS longitud,
      DATE(fq.fechaVisita)  AS fechaVisita,
      fq.fechaPropuesta     AS fechaPropuesta,
      CASE
        WHEN fq.pregunta IN ('en proceso','cancelado')
          THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'), '-', 1))
        ELSE fq.pregunta
      END AS pregunta,
      fq.countVisita,
      f.nombre AS nombre_campana
    FROM formularioQuestion fq
    INNER JOIN local l      ON l.id = fq.id_local
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND f.tipo       = ?
      AND f.estado     = ?
      AND f.id_division = ?
  ";

  $types  = "iiiii";
  $params = [$id_ejecutor, $id_empresa, $tipoCampana, $filter_estado, $filter_division];

  // Subdivisión
  if ($filter_subdivision === -1) {
    $sql .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0) ";
  } elseif ($filter_subdivision > 0) {
    $sql .= " AND f.id_subdivision = ? ";
    $types .= "i"; $params[] = $filter_subdivision;
  }

  // Distrito
  if ($filter_distrito > 0) {
    $sql .= " AND l.id_distrito = ? ";
    $types .= "i"; $params[] = $filter_distrito;
  }

  // Campaña específica (opcional)
  if ($filter_campana > 0) {
    $sql .= " AND f.id = ? ";
    $types .= "i"; $params[] = $filter_campana;
  }

  // Rango de fechas por propuesta (plan/ruta)
  if ($fecha_desde !== '') {
    $sql .= " AND fq.fechaPropuesta >= ? ";
    $types .= "s"; $params[] = $fecha_desde . " 00:00:00";
  }
  if ($fecha_hasta !== '') {
    $sql .= " AND fq.fechaPropuesta <= ? ";
    $types .= "s"; $params[] = $fecha_hasta . " 23:59:59";
  }

  $sql .= " ORDER BY fq.fechaPropuesta DESC, l.nombre ASC ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) { $locales[] = $row; }
  $stmt->close();
}

// ---------- Procesamiento para Mapa ----------
$infoLocales = [];
foreach ($locales as $fila) {
  $idLocal = (int)$fila['id_local'];
  if (!isset($infoLocales[$idLocal])) {
    $infoLocales[$idLocal] = [
      'id_local'        => $idLocal,
      'nombre_local'    => $fila['nombre_local'],
      'direccion_local' => $fila['direccion_local'],
      'latitud'         => is_null($fila['latitud']) ? null : (float)$fila['latitud'],
      'longitud'        => is_null($fila['longitud']) ? null : (float)$fila['longitud'],
      'preguntas'       => []
    ];
  }
  $infoLocales[$idLocal]['preguntas'][] = $fila['pregunta'];
}

$coordenadas_locales = [];
foreach ($infoLocales as $loc) {
  if ($loc['latitud'] === null || $loc['longitud'] === null) { continue; }
  $preguntas = $loc['preguntas'];
  $complValues = ['solo_auditoria','solo_implementado','implementado_auditado'];
  if (count(array_intersect($preguntas, $complValues)) > 0) $markerColor='green';
  elseif (count(array_unique($preguntas)) === 1 && $preguntas[0] === '-') $markerColor='red';
  else $markerColor='orange';

  $coordenadas_locales[] = [
    'idLocal'      => $loc['id_local'],
    'nombre_local' => $loc['nombre_local'].' - '.$loc['direccion_local'],
    'latitud'      => $loc['latitud'],
    'longitud'     => $loc['longitud'],
    'markerColor'  => $markerColor
  ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Locales - Campañas</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="../../assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="../../assets/css/dataTable.css">
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>
  <style>
    body { background-color: #f4f6f9; }
    th { font-size: 0.85rem; } td { font-size: 0.8rem; }
    .card-panel { margin-top: 30px; }
    .panel-header { background:#6c757d; color:#fff; padding:15px; border-radius:3px 3px 0 0; }
    h1.panel-title { margin:0; font-size:24px; }
    .table th { background:#f1f1f1; }
    .badge-custom { font-size:90%; padding:.35em .55em; }
    .badge-pendientes { background-color:#f39c12; }
    .badge-completados{ background-color:#00a65a; }
    .badge-cancelados { background-color:#dd4b39; }
    #map { width:100%; height:300px; margin-bottom:20px; }
    small.help { display:block; color:#6c757d; margin-top:2px; }
  </style>
</head>
<body>
<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title"><i class="fas fa-map-marker-alt"></i> PANEL DE CAMPAÑAS</h1>
  </div>

  <!-- Filtros -->
  <div class="card mt-3">
    <div class="card-body">
      <form method="GET" action="">
        <div class="form-row">
          <?php if ($isMC): ?>
            <div class="form-group col-md-3">
              <label for="id_division">División</label>
              <select class="form-control" id="id_division" name="id_division"
                      onchange="document.getElementById('id_subdivision').value = 0; document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0; this.form.submit();">
                <option value="0">Seleccione división</option>
                <?php foreach($divisiones as $div): ?>
                  <option value="<?= (int)$div['id'] ?>" <?= $div['id']==$filter_division?'selected':'' ?>>
                    <?= htmlspecialchars($div['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="id_division" value="<?= $filter_division ?>">
          <?php endif; ?>

          <div class="form-group col-md-3">
            <label for="id_subdivision">Subdivisión</label>
            <select class="form-control" id="id_subdivision" name="id_subdivision"
                    <?= ($filter_division > 0 ? '' : 'disabled') ?>
                    onchange="document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0; this.form.submit();">
              <option value="0"  <?= $filter_subdivision==0  ? 'selected' : '' ?>>Todas</option>
              <option value="-1" <?= $filter_subdivision==-1 ? 'selected' : '' ?>>Sin subdivisión</option>
              <?php if ($filter_division > 0 && !empty($subdivisiones)): ?>
                <?php foreach ($subdivisiones as $sd): ?>
                  <option value="<?= (int)$sd['id'] ?>" <?= ($sd['id']==$filter_subdivision ? 'selected' : '') ?>>
                    <?= htmlspecialchars($sd['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              <?php elseif ($filter_division > 0): ?>
                <option value="" disabled>Esta división no tiene subdivisiones</option>
              <?php endif; ?>
            </select>
            <small class="help">Filtra campañas por subdivisión (si aplica).</small>
          </div>

          <div class="form-group col-md-2">
            <label for="estado">Estado</label>
            <select class="form-control" id="estado" name="estado" onchange="this.form.submit();">
              <option value="1" <?= $filter_estado==1?'selected':'' ?>>En curso</option>
              <option value="3" <?= $filter_estado==3?'selected':'' ?>>Finalizadas</option>
            </select>
          </div>

          <div class="form-group col-md-4">
            <label for="id_campana">Campaña</label>
            <select class="form-control" id="id_campana" name="id_campana"
                    onchange="this.form.submit()"
                    <?= $filter_division>0 ? '' : 'disabled' ?>>
              <option value="0">Todas</option>
              <?php foreach($campanas as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $c['id']==$filter_campana?'selected':'' ?>>
                  <?= htmlspecialchars($c['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small class="help">Campaña <b>opcional</b> para acotar resultados.</small>
          </div>

          <div class="form-group col-md-3">
            <label for="id_ejecutor">Ejecutor</label>
            <select class="form-control" id="id_ejecutor" name="id_ejecutor" <?= $filter_division>0 ? '' : 'disabled' ?>>
              <option value="0">Seleccione ejecutor</option>
              <?php foreach($ejecutores as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $e['id']==$id_ejecutor?'selected':'' ?>>
                  <?= htmlspecialchars($e['nombre'].' '.$e['apellido']) ?>
                </option>
              <?php endforeach; ?>
            </select>

          </div>

          <div class="form-group col-md-2">
            <label for="desde">Desde</label>
            <input type="date" class="form-control" id="desde" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
          </div>
          <div class="form-group col-md-2">
            <label for="hasta">Hasta</label>
            <input type="date" class="form-control" id="hasta" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
          </div>

          <div class="form-group col-md-3">
            <label for="id_distrito">Distrito</label>
            <select class="form-control" id="id_distrito" name="id_distrito"
                    onchange="document.getElementById('id_ejecutor').value = 0; this.form.submit();">
              <option value="0">Todos</option>
              <?php foreach($distritos as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $d['id']==$filter_distrito?'selected':'' ?>>
                  <?= htmlspecialchars($d['nombre_distrito']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group col-md-2 align-self-end">
            <button type="submit" class="btn btn-primary btn-block">Buscar</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Mapa -->
  <div id="map"></div>

  <!-- Tabla -->
  <hr>
  <h5 class="mb-3">
    Listado de Locales <?= ($id_ejecutor>0 && $nombreEjec) ? 'para '.htmlspecialchars($nombreEjec) : '' ?>
    <?php if (!empty($locales)): ?>
      <span class="badge badge-secondary ml-2"><?= count($locales) ?> resultado(s)</span>
    <?php endif; ?>
  </h5>
  <div class="table-responsive">
    <table id="example" class="display nowrap" width="100%">
      <thead>
        <tr>
          <th>Actividad</th>
          <th>Local</th>
          <th>Dirección</th>
          <th>Estado</th>
          <th>Fecha Visita</th>
          <th>Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($locales): foreach($locales as $loc):
          $nombreAct = htmlspecialchars($loc['Actividad']);
          $nombreLoc = htmlspecialchars($loc['nombre_local']);
          $dirLoc    = htmlspecialchars($loc['direccion_local']);
          $fechaV    = $loc['fechaVisita'] ? date('d-m-Y', strtotime($loc['fechaVisita'])) : '-';
          $prg       = ($loc['pregunta'] !== '' && $loc['pregunta'] !== null) ? htmlspecialchars($loc['pregunta']) : '-';
          $badgeEstado = (in_array($prg, ['solo_auditoria','solo_implementado','implementado_auditado']))
              ? '<span class="badge badge-custom badge-completados">Compl.</span>'
              : (($prg === '-')
                    ? '<span class="badge badge-custom badge-pendientes">Pend.</span>'
                    : '<span class="badge badge-custom badge-cancelados">Cancel.</span>');
        ?>
        <tr>
          <td><?= $nombreAct ?></td>
          <td><?= $nombreLoc ?></td>
          <td><?= $dirLoc ?></td>
          <td class="text-center"><?= $badgeEstado ?></td>
          <td class="text-center"><?= $fechaV ?></td>
          <td><?= $prg ?></td>
        </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6" class="text-center">Sin resultados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="../../assets/js/datatables.min.js"></script>
<script src="../../assets/js/dataTable.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>
<script>
var coordenadasLocales = <?= json_encode($coordenadas_locales, JSON_UNESCAPED_UNICODE) ?>;
var mapa, markers=[];

function initMap(){
  mapa = new google.maps.Map(document.getElementById('map'), {
    zoom: 6, center: {lat:-33.4489, lng:-70.6693}
  });

  coordenadasLocales.forEach(function(local){
    if (local.latitud==null || local.longitud==null) return;
    var iconUrl = "../../images/icon/marker_red1.png";
    if (local.markerColor==='orange') iconUrl = "../../images/icon/orange-dot.png";
    else if (local.markerColor==='green') iconUrl = "../../images/icon/green-dot.png";

    var m = new google.maps.Marker({
      position:{lat:local.latitud, lng:local.longitud},
      map:mapa,
      title:local.nombre_local, icon:iconUrl
    });
    var iw = new google.maps.InfoWindow({content:'<strong>'+local.nombre_local+'</strong>'});
    m.addListener('click', function(){ iw.open(mapa, m); });
    markers.push(m);
  });

  if (markers.length){
    var b = new google.maps.LatLngBounds();
    markers.forEach(m => b.extend(m.getPosition()));
    mapa.fitBounds(b);
  }

  <?php if ($id_ejecutor > 0): ?>
  let ejecutorMarker=null, pollHandle=null;
  function pollUbicacionEjecutor(id_ejecutor){
    fetch('poll_ubicacion.php?id_ejecutor='+id_ejecutor)
      .then(r=>r.json())
      .then(data=>{
        if (!data) return;
        const latLng = {lat:data.lat, lng:data.lng};
        if (!ejecutorMarker){
          ejecutorMarker = new google.maps.Marker({
            position:latLng, map:mapa, title:"Ubicación actual", icon:{url:"../../images/icon/marker_user.png"}
          });
        } else { ejecutorMarker.setPosition(latLng); }
      }).catch(()=>{});
  }
  pollUbicacionEjecutor(<?= $id_ejecutor ?>);
  pollHandle = setInterval(()=>pollUbicacionEjecutor(<?= $id_ejecutor ?>), 30000);
  document.addEventListener('visibilitychange', ()=> {
    if (document.hidden && pollHandle){ clearInterval(pollHandle); pollHandle=null; }
    else if (!document.hidden && !pollHandle){
      pollUbicacionEjecutor(<?= $id_ejecutor ?>);
      pollHandle = setInterval(()=>pollUbicacionEjecutor(<?= $id_ejecutor ?>), 30000);
    }
  });
  <?php endif; ?>
}
</script>
</body>
</html>

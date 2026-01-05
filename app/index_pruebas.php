<?php

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}
$nombre         = htmlspecialchars($_SESSION['usuario_nombre'], ENT_QUOTES, 'UTF-8');
$apellido       = htmlspecialchars($_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8');
$empresa_id     = intval($_SESSION['empresa_id']);
$usuario_id     = intval($_SESSION['usuario_id']);
$division_id    = intval($_SESSION['division_id']);
$appScope       = '/visibility2/app';
$precacheLimit  = isset($_ENV['GESTIONAR_PRECACHE_LIMIT']) ? (int)$_ENV['GESTIONAR_PRECACHE_LIMIT'] : 10;
$precacheLimit  = $precacheLimit > 0 ? $precacheLimit : 10;
$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY');
$googleMapsApiKey = is_string($googleMapsApiKey) ? trim($googleMapsApiKey) : '';

$TEST_MODE = getenv('V2_TEST_MODE') === '1';
if ($TEST_MODE) {
    $today = date('Y-m-d');
    $campanas = [[
        'id_campana' => 1,
        'nombre_campana' => 'Campaña Test',
        'estado' => '1',
        'fechaInicio' => $today,
        'fechaTermino' => $today
    ]];
    $compCampanas = [];
    $locales = [[
        'fechaPropuesta' => $today,
        'codigoLocal'    => 'T-001',
        'cadena'         => 'Cadena Test',
        'direccionLocal' => 'Dirección Test 123',
        'nombreLocal'    => 'Local Test',
        'vendedor'       => 'Tester',
        'idLocal'        => 1,
        'latitud'        => -33.4489,
        'lng'            => -70.6693,
        'totalCampanas'  => 1,
        'campanasIds'    => ['1'],
        'is_priority'    => 0
    ]];
    $locales_reag = [[
        'fechaPropuesta' => $today,
        'codigoLocal'    => 'T-002',
        'cadena'         => 'Cadena Test',
        'direccionLocal' => 'Dirección Test 456',
        'nombreLocal'    => 'Local Reag',
        'vendedor'       => 'Tester',
        'idLocal'        => 2,
        'latitud'        => -33.4495,
        'lng'            => -70.6702,
        'totalCampanas'  => 1,
        'campanasIds'    => ['1'],
        'is_priority'    => 1
    ]];
    $locales_por_dia = [$today => $locales];
    $locales_reag_por_dia = [$today => $locales_reag];
    $coordenadas_locales_programados = [[
        'idLocal'        => 1,
        'nombre_local'   => 'Cadena Test - Dirección Test 123',
        'latitud'        => -33.4489,
        'lng'            => -70.6693,
        'visitado'       => false,
        'markerColor'    => 'red',
        'fechaPropuesta' => $today
    ]];
    $coordenadas_locales_reag = [[
        'idLocal'        => 2,
        'nombre_local'   => 'Cadena Test - Dirección Test 456',
        'latitud'        => -33.4495,
        'lng'            => -70.6702,
        'visitado'       => false,
        'markerColor'    => 'blue',
        'fechaPropuesta' => $today
    ]];
} else {

$sql_campaigns = "
    SELECT DISTINCT 
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado,
        f.fechaInicio,
        f.fechaTermino
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND fq.estado = 0
      AND f.tipo in (3,1)
      AND fq.countVisita = 0
      AND f.estado = 1
    ORDER BY f.fechaInicio DESC
";
$stmt_campaigns = $conn->prepare($sql_campaigns);
$stmt_campaigns->bind_param('ii', $usuario_id, $empresa_id);
$stmt_campaigns->execute();
$result_campaigns = $stmt_campaigns->get_result();
$campanas = [];

while ($row = $result_campaigns->fetch_assoc()) {
    $campanas[] = [
        'id_campana'     => (int)$row['id_campana'],
        'nombre_campana' => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
        'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'),
        'fechaInicio'    => $row['fechaInicio'],
        'fechaTermino'   => $row['fechaTermino']
    ];
}
$stmt_campaigns->close();


if ($usuario_id === 2) {
  $sql_comp = "
      SELECT 
        id AS id_campana,
        nombre AS nombre_campana,
        estado
      FROM formulario
      WHERE tipo = 2
        AND (id_division = 1 OR id_division = ?)
        AND estado = 1 
      ORDER BY nombre ASC
  ";
  $stmt_comp = $conn->prepare($sql_comp);
  $stmt_comp->bind_param('i', $division_id);
} else {
  $sql_comp = "
      SELECT 
        id AS id_campana,
        nombre AS nombre_campana,
        estado
      FROM formulario
      WHERE tipo = 2
        AND (id_division = 1 OR id_division = ?)
        AND estado = 1
        AND id <> 2037
      ORDER BY nombre ASC
  ";
  $stmt_comp = $conn->prepare($sql_comp);
  $stmt_comp->bind_param('i', $division_id);
}

$stmt_comp->execute();
$result_comp = $stmt_comp->get_result();
$compCampanas = [];
while ($row = $result_comp->fetch_assoc()) {
    $compCampanas[] = [
        'id_campana'     => (int)$row['id_campana'],
        'nombre_campana' => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
        'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8')
    ];
}
$stmt_comp->close();


// 3) Locales Programados (no visitados)
$sql = "
    SELECT
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()) AS fechaPropuesta,
    l.codigo    AS codigoLocal,
    c.nombre    AS cadena,
    l.direccion AS direccionLocal,
    l.nombre    AS nombreLocal,
    IFNULL(v.nombre_vendedor, '') AS vendedor,
    IFNULL(co.comuna, '') AS comuna,
    l.id        AS idLocal,
    l.lat       AS latitud,
    l.lng       AS lng,
    COUNT(CASE WHEN fq.countVisita = 0 THEN 1 END)        AS totalCampanas,
    GROUP_CONCAT(DISTINCT CASE WHEN fq.countVisita = 0 THEN f.id END) AS campanasIds,
    MAX(fq.is_priority)         AS is_priority
FROM formularioQuestion fq
INNER JOIN formulario f ON f.id        = fq.id_formulario
INNER JOIN local      l ON l.id        = fq.id_local
INNER JOIN cadena     c ON c.id        = l.id_cadena
INNER JOIN vendedor   v ON v.id = l.id_vendedor
LEFT JOIN comuna     co ON co.id = l.id_comuna
WHERE fq.id_usuario    = ?
  AND f.id_empresa      = ?
  AND f.tipo           IN (3,1)
  AND f.estado         = 1
GROUP BY
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()),
    l.codigo, c.nombre, l.direccion, l.nombre, co.comuna,
    l.id, l.lat, l.lng, v.nombre_vendedor
HAVING SUM(CASE WHEN fq.countVisita = 0 THEN 1 ELSE 0 END) > 0
ORDER BY fechaPropuesta ASC, c.nombre, l.direccion
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $usuario_id, $empresa_id);
$stmt->execute();
$result = $stmt->get_result();

$locales = [];
while ($row = $result->fetch_assoc()) {
    $row['campanasIds'] = explode(',', $row['campanasIds']);
    $locales[] = [
        'fechaPropuesta' => $row['fechaPropuesta'],
        'codigoLocal'    => htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'),
        'cadena'         => htmlspecialchars($row['cadena'], ENT_QUOTES, 'UTF-8'),
        'direccionLocal' => htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8'),
        'nombreLocal'    => htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8'),
        'vendedor'       => htmlspecialchars($row['vendedor'], ENT_QUOTES, 'UTF-8'),
        'idLocal'        => (int)$row['idLocal'],
        'latitud'        => (float)$row['latitud'],
        'lng'            => (float)$row['lng'],
        'totalCampanas'  => (int)$row['totalCampanas'],
        'campanasIds'    => $row['campanasIds'],
        'is_priority'    => (int)$row['is_priority'],
        'comuna'         => htmlspecialchars($row['comuna'], ENT_QUOTES, 'UTF-8')
    ];
}
$stmt->close();

$locales_por_dia = [];
foreach ($locales as $local) {
    $fecha = $local['fechaPropuesta'];
    if (!isset($locales_por_dia[$fecha])) {
        $locales_por_dia[$fecha] = [];
    }
    $locales_por_dia[$fecha][] = $local;
}

// 4) Locales Reagendados
$sql_reagendados = "
SELECT
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()) AS fechaPropuesta,
    l.codigo  AS codigoLocal,
    c.nombre  AS cadena,
    l.direccion AS direccionLocal,
    l.nombre AS nombreLocal,
    IFNULL(v.nombre_vendedor, '') AS vendedor,
    l.id AS idLocal,
    l.lat AS latitud,
    l.lng AS lng,
    COUNT(DISTINCT f.id) AS totalCampanas,
    GROUP_CONCAT(DISTINCT f.id) AS campanasIds,
    MAX(fq.is_priority) AS is_priority
FROM formularioQuestion fq
INNER JOIN formulario   f ON f.id = fq.id_formulario
INNER JOIN local        l ON l.id = fq.id_local
INNER JOIN cadena       c ON c.id = l.id_cadena
INNER JOIN vendedor     v ON v.id = l.id_vendedor
WHERE fq.id_usuario = ?
  AND f.id_empresa  = ?
  AND f.tipo        IN (3,1)
  AND f.estado      = 1
  AND fq.pregunta   = 'en proceso'
GROUP BY
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()),
    l.codigo, c.nombre, l.direccion, l.nombre,
    l.id, l.lat, l.lng, v.nombre_vendedor
ORDER BY
    fechaPropuesta ASC,
    c.nombre,
    l.direccion
";

$stmt_reag = $conn->prepare($sql_reagendados);
$stmt_reag->bind_param('ii', $usuario_id, $empresa_id);
$stmt_reag->execute();
$result_reag = $stmt_reag->get_result();

$locales_reag = [];
while ($row = $result_reag->fetch_assoc()) {
    $row['campanasIds'] = explode(',', $row['campanasIds']);
    $locales_reag[] = [
        'fechaPropuesta' => $row['fechaPropuesta'],
        'codigoLocal'    => htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'),
        'cadena'         => htmlspecialchars($row['cadena'], ENT_QUOTES, 'UTF-8'),
        'direccionLocal' => htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8'),
        'nombreLocal'    => htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8'),
        'vendedor'       => htmlspecialchars($row['vendedor'], ENT_QUOTES, 'UTF-8'),
        'idLocal'        => (int)$row['idLocal'],
        'latitud'        => (float)$row['latitud'],
        'lng'            => (float)$row['lng'],
        'totalCampanas'  => (int)$row['totalCampanas'],
        'campanasIds'    => $row['campanasIds'],
        'is_priority'    => (int)$row['is_priority']
    ];
}
$stmt_reag->close();


$locales_reag_por_dia = [];
foreach ($locales_reag as $local) {
    $fecha = $local['fechaPropuesta'];
    if (!isset($locales_reag_por_dia[$fecha])) {
        $locales_reag_por_dia[$fecha] = [];
    }
    $locales_reag_por_dia[$fecha][] = $local;
}

// Preparar datos para el mapa
$coordenadas_locales_programados = [];
foreach ($locales as $local) {
    $markerColor = ($local['is_priority'] === 1) ? 'blue' : 'red';
    $coordenadas_locales_programados[] = [
        'idLocal'        => $local['idLocal'],
        'nombre_local'   => $local['cadena'] . ' - ' . $local['direccionLocal'],
        'latitud'        => $local['latitud'],
        'lng'            => $local['lng'],
        'visitado'       => false,
        'markerColor'    => $markerColor,
        'fechaPropuesta' => $local['fechaPropuesta']
    ];
}
$coordenadas_locales_reag = [];
foreach ($locales_reag as $local) {
    $markerColor = ($local['is_priority'] === 1) ? 'blue' : 'red';
    $coordenadas_locales_reag[] = [
        'idLocal'        => $local['idLocal'],
        'nombre_local'   => $local['cadena'] . ' - ' . $local['direccionLocal'],
        'latitud'        => $local['latitud'],
        'lng'            => $local['lng'],
        'visitado'       => false,
        'markerColor'    => $markerColor,
        'fechaPropuesta' => $local['fechaPropuesta']
    ];
}
}
?>
<!DOCTYPE html>
<html lang="es" class="no-js">
<head>
    <title>Visibility 2</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- CSS -->
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <link rel="stylesheet" href="assets/css/offline.css">
     <link rel="stylesheet" href="assets/css/journal.css">
    <style>
    @media (max-width: 480px) {
      .table > thead > tr > th,
      .table > tbody > tr > td {
        padding: 4px 4px;
        font-size: 0.9rem;
      }
    }
    #filtroLocalesProg, #filtroLocalesReag { width: 100%; }
    #success-alert {
      position: fixed; top: 60px; left: 0; width: 100%; z-index: 9999; margin: 0; text-align: center; padding: 10px;
    }
    
    @media (max-width: 768px) { #success-alert { font-size: 1em; } }
    .completed .desc { text-decoration: line-through; color: gray; }
    .circulo {
      display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px;
      background-color: #28a745; color: #fff; border-radius: 50%; font-weight: bold; font-size: 14px;
    }
    
    #panelInstruccionesModal {
      position: absolute; top: 60px; right: 10px; width: 300px; max-height: 80%; overflow-y: auto;
      background-color: rgba(255, 255, 255, 0.95); padding: 15px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1);
      z-index: 1000; transition: all 0.3s ease; height: 40px;
    }
    
    #panelInstruccionesModal.expanded { height: 500px; }
    
    #panelInstruccionesModal .panel-header { display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    
    #panelInstruccionesModal .toggle-button { background: none; border: none; font-size: 16px; cursor: pointer; }
    @media (max-width: 768px) {
      #panelInfoRuta { position: fixed; bottom: 10px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 400px; z-index: 1001; }
      #panelInstruccionesModal { right: 50%; transform: translateX(50%); width: 90%; left: 5%; top: auto; bottom: 60px; }
    }
    .custom-map-control-button {
      background-color: #fff; border: 2px solid #fff; border-radius: 3px; box-shadow: 0 2px 6px rgba(0,0,0,0.3);
      cursor: pointer; margin: 10px; padding: 10px; font-size: 16px; font-family: 'Roboto, Arial, sans-serif';
      display: flex; align-items: center; transition: background-color 0.3s;
    }
    .custom-map-control-button:hover { background-color: #e6e6e6; }
    .precache-fab {
      position: fixed;
      right: 16px;
      bottom: 82px;
      z-index: 1050;
      box-shadow: 0 6px 18px rgba(0,0,0,0.2);
    }
    .precache-fab .badge {
      background: #fff;
      color: #c0392b;
      margin-left: 6px;
    }
    #loadingIndicator {
      position: absolute; top: 10px; left: 50%; transform: translateX(-50%); background-color: rgba(255, 255, 255, 0.8);
      padding: 5px 10px; border-radius: 3px; display: none; z-index: 1001;
    }
    .visitado { background-color: #d4edda !important; }
    .priority-row { background-color: #fff3cd !important; }
    .priority-icon { color: #ff9800; margin-right: 5px; }
    .nav-hud{ pointer-events:none; position:absolute; inset:0; z-index:1003; }
    .nav-hud .nav-banner{
      pointer-events:auto; position:absolute; top:10px; left:10px; right:10px;
      background:#ffffff; border:1px solid #d9dee6; border-radius:12px;
      box-shadow:0 6px 24px rgba(0,0,0,.12); padding:10px 12px;
      display:flex; align-items:center; gap:10px;
    }
    .nav-banner .nav-ic{ width:28px; height:28px; display:inline-grid; place-items:center;
      border-radius:999px; background:#eef3f8; color:#2563eb; font-size:16px; }
    .nav-banner .nav-main{ font-weight:700; color:#0f172a; }
    .nav-banner .nav-sub{ color:#64748b; font-size:12px; margin-top:2px; }
    .nav-hud .nav-nextnext{
      position:absolute; top:64px; left:12px; background:rgba(0,0,0,.6); color:#fff;
      padding:4px 8px; border-radius:8px; font-size:12px;
    }
    .nav-hud .nav-bottom{
      pointer-events:auto; position:absolute; left:10px; right:10px; bottom:10px;
      display:flex; gap:10px; align-items:center; justify-content:space-between;
    }
    .nav-stats{
      flex:1; background:#ffffff; border:1px solid #d9dee6; border-radius:12px;
      box-shadow:0 6px 24px rgba(0,0,0,.12); padding:8px 12px;
      display:flex; gap:14px; align-items:center; justify-content:space-between;
      font-weight:700; color:#0f172a;
    }
    .nav-stats small{ display:block; font-weight:600; color:#64748b; }
    #btnRecenter, #btnExitNav{
      pointer-events:auto; border-radius:12px; border:1px solid #d9dee6; background:#ffffff;
      box-shadow:0 6px 24px rgba(0,0,0,.12); padding:8px 10px; font-weight:700;
    }
    #btnRecenter{ display:none; }
    #btnRecenter.show{ display:inline-block; }

    /* Segmentos por tráfico */
    .poly-normal{ stroke-color:#4c8fbd; stroke-opacity:.95; stroke-weight:6; }
    .poly-slow{   stroke-color:#ffa722; stroke-opacity:.95; stroke-weight:7; }
    .poly-jam{    stroke-color:#d74d3a; stroke-opacity:.95; stroke-weight:7; }

    /* Drawer de indicaciones */
    .route-drawer{
      position:absolute; top:60px; right:10px; width:320px; max-height:75%; overflow-y:auto;
      background:#fff; border-radius:10px; box-shadow:0 6px 24px rgba(0,0,0,.15);
      z-index:1002; display:none;
    }
    .route-drawer.open{ display:block; }
    .route-drawer .drawer-header{ display:flex; align-items:center; justify-content:space-between;
      padding:10px 12px; border-bottom:1px solid #eee; }
    .route-drawer .drawer-title{ font-weight:600; font-size:14px; margin:0; }
    .route-drawer .drawer-body{ padding:10px 12px; }
    .steps-list{ counter-reset:step; margin:0; padding-left:0; list-style:none; }
    .steps-list li{ margin:8px 0; padding-left:28px; position:relative; font-size:13px; line-height:1.35; }
    .steps-list li::before{ counter-increment:step; content:counter(step) "."; position:absolute; left:0; top:0; font-weight:700; }
    @media (max-width: 768px){
      .route-drawer{ left:5%; right:5%; width:auto; top:auto; bottom:65px; max-height:50%; }
    }
    @media (prefers-color-scheme: dark){
      .nav-banner{ background:#0f1216; border-color:#1e293b; }
      .nav-stats{ background:#0f1216; border-color:#1e293b; color:#e2e8f0; }
      .nav-banner .nav-main{ color:#e2e8f0; } .nav-banner .nav-sub{ color:#94a3b8; }
    }

    #journalPanel .panel-heading{
      align-items:flex-start;
      flex-wrap:wrap;
      row-gap:6px;
      padding-bottom:8px;
    }
  
    #journalPanel .panel-heading .label{
      display:inline-block;
      white-space:nowrap;     
      margin:0 6px 3px 0;
    }
    

    #journalPanel .panel-heading > div:last-child{
      margin-left:auto;
      display:flex;
      gap:6px;
      flex-wrap:wrap;
      flex-basis:100%;
      justify-content:flex-end;
    }

    #journalPanel .panel-heading + .panel-body{
      padding-top:85px;
    }
    
    #journalPanel .nav { position: static !important; }
    
    #journalPanel .nav-tabs{
      display:flex !important;
      align-items:flex-end;
      flex-wrap:nowrap;
      gap:0;
      border-bottom:1px solid #ddd !important;
      margin:12px 0 8px 0;
      padding-left:0;
    }
    
    #journalPanel .nav-tabs > li{
      float:none !important;
      display:inline-block !important;
      position:static !important;
      margin:0;
      margin-bottom:-1px;              
      list-style:none;
    }
    
    #journalPanel .nav-tabs > li > a{
      display:block !important;
      position:static !important;
      white-space:nowrap;
      padding:6px 10px;
      text-decoration:none !important;
      background:#fff !important;
      border:1px solid #ddd !important;
      border-bottom-color:transparent !important;
      border-radius:4px 4px 0 0 !important;
    }
    
    #journalPanel .nav-tabs > li > a:hover,
    #journalPanel .nav-tabs > li > a:focus{
      text-decoration:none !important;
      background:#fafafa !important;
    }
    
    #journalPanel .nav-tabs > li.active > a,
    #journalPanel .nav-tabs > li.active > a:focus,
    #journalPanel .nav-tabs > li.active > a:hover{
      background:#f7f7f7 !important;
      border-color:#ddd !important;
      border-bottom-color:#f7f7f7 !important;
    }
    
    #journalPanel .tab-content{
      clear:both;
      padding-top:8px;
    }
    
    /* por si algún tema intenta convertirlos en dropdown */
    #journalPanel .nav-tabs .dropdown-menu{ display:none !important; }

    @media (max-width: 768px){
      #journalPanel .panel-heading > div:last-child{
        flex-basis:100%;
        justify-content:flex-start;
      }
    }
     @media (max-width: 480px){
      #journalPanel .nav-tabs{ flex-wrap:wrap; }
    } 
   
    </style>
</head>
<body>
<?php
if (isset($_SESSION['success'])) {
    echo '<div id="success-alert" class="alert alert-success" role="alert">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
?>
<!-- Navbar -->
<div class="navbar navbar-inverse navbar-fixed-top">
   <div class="container">
      <div class="navbar-header" style="background-color: white;">
         <button data-target=".navbar-collapse" data-toggle="collapse" class="navbar-toggle" type="button">
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
         </button>
         <a class="navbar-brand" href="#">
            VISIBILITY 2
         </a>
      </div>
      <div class="navbar-tools" style="background-color: white;">
         <div class="nickname"><?php echo $nombre . ' ' . $apellido; ?></div>
         <ul class="nav navbar-right">
            <li class="dropdown current-user">
               <a data-toggle="dropdown" data-hover="dropdown" class="dropdown-toggle" data-close-others="true" href="#">
                  <i class="fa fa-chevron-down"></i>
               </a>
               <ul class="dropdown-menu">
                  <li><a href="perfil.php"><i class="fa fa-user"></i> &nbsp;Perfil</a></li>
                  <li><a href="logout.php"><i class="fa fa-sign-out"></i> &nbsp;Cerrar sesión</a></li>
               </ul>
            </li>
         </ul>
      </div>
   </div>
</div>

<!-- Contenido principal -->
<div class="main-container">
   <div class="main-content">
      <div class="container">
         <div class="row">
            <div class="col-sm-12">
               <div class="page-header">
                  <h1>Gestor de Actividades <small>Campañas en curso &amp; campañas IW</small></h1>
               </div>
            </div>
         </div>

         <div class="row" style="margin-bottom: 15px;">
            <button type="button" class="btn btn-info" id="btnActualizar" style="margin-left: 3.5%;" onclick="window.location.reload();">
                <i class="fa fa-refresh"></i> Actualizar
            </button>
            <!-- Sidebar: Campañas Programadas -->
            <div class="col-sm-5">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-check-square-o"></i> Campañas Programadas
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#campanasCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="campanasCollapse" class="panel-body panel-scroll collapse in">
                        <ul class="todo list-group">
                            <?php
                            if (count($campanas) > 0) {
                                foreach ($campanas as $campana) {
                                    $id_campana    = $campana['id_campana'];
                                    $nombre_camp   = $campana['nombre_campana'];
                                    $fechaInicio   = date('d-m-Y', strtotime($campana['fechaInicio']));
                                    $fechaTermino  = date('d-m-Y', strtotime($campana['fechaTermino']));
                                    echo '<li class="list-group-item" data-idcampana="' . $id_campana . '">';
                                    echo ' <a class="todo-actions" href="javascript:void(0)">';
                                    echo '   <i class="fa fa-square-o"></i> ';
                                    echo '   <span class="desc">' . $nombre_camp . ' (' . $fechaInicio . ' - ' . $fechaTermino . ')</span>';
                                    echo ' </a>';
                                    echo '</li>';
                                }
                            } else {
                                echo '<li class="list-group-item">No hay campañas programadas.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Botón para ver el mapa y selector de fecha para programados -->
            <div class="col-sm-2">
               <button class="btn btn-primary btn-block" style="margin-bottom: 10px;" data-toggle="modal" data-target="#modalMapa">
                  <i class="fa fa-map-marker"></i> Ver Mapa
               </button>
               <button class="btn btn-info btn-block" style="margin-bottom: 10px;" data-toggle="modal" data-target="#modalAyudaFuncionamiento">
                  <i class="fa fa-question-circle"></i> ¿Cómo funciona?
               </button>
               <div class="form-group">
                 <label for="filtroFechaProg">Seleccionar fecha:</label>
                 <select id="filtroFechaProg" class="form-control">
                    <?php
                      foreach ($locales_por_dia as $fecha => $localesDia) {
                          $selected = ($fecha == date('Y-m-d')) ? 'selected' : '';
                          echo '<option value="'.$fecha.'" '.$selected.'>'.date('d-m-Y', strtotime($fecha)).'</option>';
                      }
                    ?>
                 </select>
               </div>
               <div id="contadorLocales" class="text-center" style="margin-top:5px;">
                 <small>Tabla: <span id="countTabla">0</span> | Mapa: <span id="countMapa">0</span> | Excluidos: <span id="countEx">0</span></small>
               </div>
            </div>

            <!-- Botón para cambiar al panel de locales reagendados -->
            <div class="col-sm-5 text-right">
                <button id="btnVerReagendados" class="btn btn-warning">
                    Ver Locales Reagendados
                </button>
            </div>
         </div><!-- /.row -->
         

         <!-- Panel de Locales Programados -->
         <div id="panelProgramados">
         <div class="row">
            <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-users"></i> Locales Programados
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#localesProgCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="localesProgCollapse" class="panel-body collapse in">
                        <!-- Filtro de búsqueda -->
                        <div class="form-group" style="max-width: 300px;">
                          <input type="text" id="filtroLocalesProg" class="form-control" placeholder="Filtrar por código, cadena, comuna o dirección...">
                        </div>
                        <?php if (!empty($locales_por_dia)): ?>
                            <?php foreach ($locales_por_dia as $fecha => $localesDia): ?>
                                <h4 data-fechaencabezado="<?php echo $fecha; ?>">
                                    <?php echo date("d-m-Y", strtotime($fecha)); ?>
                                </h4>
                                <table class="table table-striped table-hover" data-fechaTabla="<?php echo $fecha; ?>">
                                    <thead>
                                        <tr>
                                            <th class="center">Código</th>
                                            <th class="center">Cadena</th>
                                            <th>Comuna</th>
                                            <th>Dirección</th>
                                            <th class="center">Ruta</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($localesDia as $row): 
                                            $cadena         = $row['cadena'];
                                            $direccionLocal = $row['direccionLocal'];
                        $totalCamp      = $row['totalCampanas'];
                                            $idLocal        = $row['idLocal'];
                                            $campanasIds    = $row['campanasIds'];
                                            $esPrioridad    = ($row['is_priority'] === 1);
                                            $trClass        = $esPrioridad ? 'priority-row' : '';
                                        ?>
                                          <?php
                                          $busquedaProg = trim(strtolower(
                                            $row['codigoLocal'] . ' ' .
                                            $cadena . ' ' .
                                            ($row['comuna'] ?? '') . ' ' .
                                            $direccionLocal . ' ' .
                                            ($row['nombreLocal'] ?? '')
                                          ));
                                        ?>
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            data-busqueda="<?php echo htmlspecialchars($busquedaProg, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="<?php echo $trClass; ?>">
                                            <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo $row['comuna']; ?></td>
                                            <td><?php echo htmlspecialchars($direccionLocal, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="center">
                                              <input type="checkbox" class="in-route" checked title="Incluir este local en la ruta">
                                            </td>
                                            <td class="center">
                                                <div style="display: flex; align-items: center; justify-content: center;">
                                                    <span class="circulo"><?php echo $totalCamp; ?></span>
                                                    <div style="margin-left: 10px;">
                                                        <div class="btn-group">
                                                            <a class="btn btn-primary dropdown-toggle btn-sm" data-toggle="dropdown" href="#">
                                                                <i class="fa fa-cog"></i> <span class="caret"></span>
                                                            </a>
                                                            <ul role="menu" class="dropdown-menu pull-right">
                                                                <li role="presentation">
                                                                    <a role="menuitem" tabindex="-1" href="#responsive<?php echo $idLocal; ?>" data-toggle="modal">
                                                                        <i class="fa fa-edit"></i> Campañas
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="center">No se encontraron campañas programadas.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
         </div><!-- /.row -->
         </div><!-- /#panelProgramados -->

        

         <!-- Panel de Locales Reagendados -->
         <div id="panelReagendados" style="display:none;">
<p class="text-muted">Última sincronización: <span id="lastSyncBadge">-</span></p>
         <div class="row">
            <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-calendar"></i> Locales Reagendados
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#localesReagCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                        <div class="pull-right">
                          <button id="btnVerProgramados" class="btn btn-warning btn-xs">Ver Programados</button>
                        </div>
                    </div>
                    <div id="localesReagCollapse" class="panel-body collapse in">
                        <div class="form-group" style="max-width: 300px;">
                          <input type="text" id="filtroLocalesReag" class="form-control" placeholder="Filtrar por código, nombre o dirección...">
                        </div>
                        <div class="form-group">
                          <label for="filtroFechaReag">Seleccionar fecha:</label>
                          <select id="filtroFechaReag" class="form-control">
                            <?php 
                              foreach ($locales_reag_por_dia as $fecha => $localesDia) {
                                  $selected = ($fecha == date('Y-m-d')) ? 'selected' : '';
                                  echo '<option value="'.$fecha.'" '.$selected.'>'.date('d-m-Y', strtotime($fecha)).'</option>';
                              }
                            ?>
                          </select>
                        </div>
                        <?php if (!empty($locales_reag_por_dia)): ?>
                            <?php foreach ($locales_reag_por_dia as $fecha => $localesDia): ?>
                                <h4 data-fechaencabezado="<?php echo $fecha; ?>">
                                    <?php echo date("d-m-Y", strtotime($fecha)); ?>
                                </h4>
                                <table class="table table-striped table-hover" data-fechaTabla="<?php echo $fecha; ?>">
                                    <thead>
                                        <tr>
                                            <th class="center">Código</th>
                                            <th class="center">Cadena</th>
                                            <th>Dirección</th>
                                            <th class="center">Ruta</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($localesDia as $row): 
                                            $cadena         = $row['cadena'];
                                            $direccionLocal = $row['direccionLocal'];
                                            $totalCamp      = $row['totalCampanas'];
                                            $idLocal        = $row['idLocal'];
                                            $campanasIds    = $row['campanasIds'];
                                            $esPrioridad    = ($row['is_priority'] === 1);
                                            $trClass        = $esPrioridad ? 'priority-row' : '';
                                        ?>
                                        <?php
                                          $busquedaReag = trim(strtolower(
                                            $row['codigoLocal'] . ' ' .
                                            $cadena . ' ' .
                                            $direccionLocal . ' ' .
                                            ($row['nombreLocal'] ?? '')
                                          ));
                                        ?>
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            data-busqueda="<?php echo htmlspecialchars($busquedaReag, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="<?php echo $trClass; ?>">
                                             <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                             <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($direccionLocal, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="center">
                                              <input type="checkbox" class="in-route" checked title="Incluir este local en la ruta">
                                            </td>
                                            <td class="center">
                                                <div style="display: flex; align-items: center; justify-content: center;">
                                                    <span class="circulo"><?php echo $totalCamp; ?></span>
                                                    <div style="margin-left: 10px;">
                                                        <div class="btn-group">
                                                            <a class="btn btn-primary dropdown-toggle btn-sm" data-toggle="dropdown" href="#">
                                                                <i class="fa fa-cog"></i> <span class="caret"></span>
                                                            </a>
                                                            <ul role="menu" class="dropdown-menu pull-right">
                                                                <li role="presentation">
                                                                    <a role="menuitem" tabindex="-1" href="#responsive<?php echo $idLocal; ?>" data-toggle="modal">
                                                                        <i class="fa fa-edit"></i> Campañas
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="center">No se encontraron locales reagendados.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
         </div>
         </div><!-- /#panelReagendados -->

         <!-- Actividades complementarias -->
         <div class="row" style="margin-top: 20px;">
             <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-question-circle"></i> Actividades complementarias
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#compCampanasCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="compCampanasCollapse" class="panel-body panel-scroll collapse in">
                      <ul class="todo list-group">
                        <?php
                        if (count($compCampanas) > 0) {
                            $gestionarBase = ($usuario_id === 2) ? 'gestionarIW.php' : 'gestionarIW.php';
                            foreach ($compCampanas as $campana) {
                                $idCamp = (int)$campana['id_campana'];
                                $nomCamp = $campana['nombre_campana'];
                                echo '<li class="list-group-item" data-idcampana="'.$idCamp.'">';
                                echo '  <a class="todo-actions" href="javascript:void(0)">';
                                echo '      <i class="fa fa-circle"></i> ';
                                echo '      <span class="desc">'.$nomCamp.'</span>';
                                echo '  </a>';
                                echo '  <a href="'.$gestionarBase.'?idCampana='.urlencode($idCamp).'" class="btn btn-primary btn-sm" title="Gestionar Campaña">';
                                echo '      <i class="fa fa-cog"></i> Gestionar';
                                echo '  </a>';
                                echo '</li>';
                            }
                        } else {
                            echo '<li class="list-group-item">No hay actividades complementarias.</li>';
                        }
                        ?>
                      </ul>
                    </div>
                </div>
             </div>
         </div>
         
        
<i class="fa fa-list-alt"></i> Gestiones
        <div class="row" id="journalRow">
          <div class="col-sm-12">
            <div class="panel panel-default" id="journalPanel">
              <div class="panel-heading" style="display:flex; align-items:left; flex-wrap:wrap; gap:6px;">
                <span class="label label-default" id="jr-badge-pending">Pendientes: 0</span>
                <span class="label label-warning" id="jr-badge-running">Enviando: 0</span>
                <span class="label label-success" id="jr-badge-success">Subidas: 0</span>
                <span class="label label-danger"  id="jr-badge-error">Errores: 0</span>
                <span class="label label-warning" id="jr-badge-blocked">Bloqueadas: 0</span>
                <div style="margin-left:auto; display:flex; gap:6px; flex-wrap:wrap;">
                  <button class="btn btn-xs btn-default" id="jr-btn-flush"><i class="fa fa-upload"></i> Reintentar ahora</button>
                  <button class="btn btn-xs btn-default" id="jr-btn-clear-today"><i class="fa fa-eraser"></i> Limpiar subidas (hoy)</button>
                  <button class="btn btn-xs btn-default" id="jr-btn-export"><i class="fa fa-download"></i> Exportar diagnóstico</button>
                </div>
              </div>
              <div class="panel-body">
                <ul class="nav nav-tabs" role="tablist">
                  <li class="active"><a href="#jr-hoy" aria-controls="jr-hoy" role="tab" data-toggle="tab">Hoy</a></li>
                  <li><a href="#jr-semana" aria-controls="jr-semana" role="tab" data-toggle="tab">Semana</a></li>
                </ul>
        
                <!-- Progreso global -->
                <div class="progress" style="margin:10px 0 15px;">
                  <div id="jr-global-progress" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
                </div>
        
                <div class="tab-content">
                  <div role="tabpanel" class="tab-pane active" id="jr-hoy">
                    <div id="jr-list-today" class="jr-list"></div>
                  </div>
                  <div role="tabpanel" class="tab-pane" id="jr-semana">
                    <div id="jr-list-week" class="jr-list"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
         
         
      </div><!-- /.container -->
   </div><!-- /.main-content -->
</div><!-- /.main-container -->

<!-- Modal de ayuda "¿Cómo funciona?" -->
<div id="modalAyudaFuncionamiento" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalAyudaFuncionamientoLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title" id="modalAyudaFuncionamientoLabel">¿Cómo funciona?</h4>
         </div>
         <div class="modal-body">
            <p>Este panel resume todo lo que puedes hacer en la aplicación con palabras simples pensadas para ejecutores en terreno.</p>

            <h4>1. Tablas de locales programados y reagendados</h4>
            <ul>
               <li><strong>Programados:</strong> es la tabla principal. Se agrupa por fecha y muestra código, cadena, comuna, dirección y opciones de ruta.</li>
               <li><strong>Reagendados:</strong> se abre con el botón <em>Ver Locales Reagendados</em>. Tiene el mismo formato, pero solo con los locales tipificados como pendiente</li>
               <li><strong>Filtro de texto:</strong> sobre cada tabla hay un cuadro para buscar por código, cadena, comuna o dirección. Escribe cualquier palabra y la tabla filtra al instante.</li>
               <li><strong>Campañas tachadas:</strong> en el panel de campañas (izquierda) puedes tocar el nombre para tacharlo. Las campañas tachadas se ocultan de la tabla y del mapa para evitar visitas equivocadas.</li>
        
            </ul>

            <h4>2. Gestionar un local y registrar campañas</h4>
            <ul>
               <li><strong>Botón Gestionar Local:</strong> cada local tiene el botón azul con un engranaje. Al presionarlo abre el modal del local y desde ahí eliges <em>Gestionar</em> para entrar a la seccion de gestionar Local.</li>
   
               <li><strong>Actividades complementarias:</strong>abajo de la tabla de locales aparecen las gestiones complementarias, como pueden ser gestiones adicionales, kilometrajes etc.</li>
            </ul>

            <h4>3. Guardar locales para trabajar sin conexión</h4>
            <ul>
               <li><strong>Guardar locales:</strong> Para guardar locales y asi poder gestionarlos sin conexión, simplemente tienes que ingresar al menos una vez a la seccion de gestionar local de manera online, ahi ya queda guardado para gestionarlo offline, ya sea en el momento o mas tarde</li>
            </ul>

            <h4>4. Ruta y navegación</h4>
            <ul>
               <li><strong>Ver Mapa:</strong> abre el mapa con todos los locales visibles según la fecha y campañas activas.</li>
               <li><strong>Armar ruta:</strong> usa los checks de cada fila (columna Ruta) para incluir o excluir locales. Los tachados o excluidos desaparecen del mapa.</li>
               <li><strong>Recalcular:</strong> el botón <em>Recalcular</em> ordena la ruta. Puedes activar <em>Optimizar</em> para que Google proponga el mejor orden.</li>
               <li><strong>Exportar a Google Maps:</strong> en el mapa usa <em>Abrir en Google Maps</em> para abrir la ruta en la app de google maps del teléfono.</li>
               <li><strong>Indicaciones paso a paso:</strong> el botón <em>Indicaciones</em> abre el panel lateral con cada giro, distancia y tiempo estimado.</li>
               <li><strong>Modo navegación:</strong> <em>Iniciar navegación</em> activa la vista 3D con flecha en vivo; <em>Centrar</em> devuelve el mapa a tu posición.(funcionalidad en progreso)</li>
            </ul>

            <h4>5. Extras útiles</h4>
            <ul>
               <li><strong>Contadores rápidos:</strong> bajo el selector de fecha verás cuántos locales están en la tabla, en el mapa y cuántos excluiste.</li>
               <li><strong>Estado de red:</strong> el mensaje <em>Online/Offline</em> te avisa si puedes sincronizar. Cuando vuelve la señal, las gestiones pendientes se envían solas.</li>
               <li><strong>Bitácora:</strong> la sección <em>Gestiones</em> muestra lo enviado hoy y en la semana con progreso total y errores a reintentar.</li>
            </ul>

            <p class="text-muted">Si algo no funciona como esperas, refresca la página o revisa que tengas señal. Todo lo cacheado permanece guardado para que no pierdas tu trabajo.</p>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
         </div>
      </div>
   </div>
</div>

<!-- Modal Mapa -->
<div id="modalMapa" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalMapaLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title" id="modalMapaLabel">Mapa de Ruta en Tiempo Real</h4>
         </div>
         <div class="modal-body" style="position: relative;">
            <div id="map" style="height: 500px; width: 100%;"></div>

            <div id="panelInfoRuta" class="well well-sm"
                 style="background: rgba(255,255,255,0.95); padding:10px; border-radius:8px; z-index:1001;">
              <button id="btnCentrar" class="btn btn-default btn-sm">
                <i class="fa fa-crosshairs"></i> Centrar
              </button>
              <button id="btnRecalcular" class="btn btn-primary btn-sm">
                <i class="fa fa-refresh"></i> Recalcular
              </button>
              <button id="btnIndicaciones" class="btn btn-info btn-sm">
                <i class="fa fa-list-ol"></i> Indicaciones
              </button>
              <div class="btn-group" role="group" style="margin-left:6px;">
                <label class="btn btn-default btn-sm">
                  <input type="checkbox" id="optimizeOrder" autocomplete="off" checked> Optimizar
                </label>
                <label class="btn btn-default btn-sm">
                  <input type="checkbox" id="autoRecalc"  autocomplete="off" checked> Auto
                </label>
                <button id="btnTraffic" class="btn btn-default btn-sm" title="Tráfico">
                  <i class="fa fa-car"></i> Tráfico
                </button>
                <button id="btnVoz" class="btn btn-default btn-sm" title="Leer instrucciones">
                  <i class="fa fa-volume-up"></i> Voz
                </button>
                <button id="btnExportar" class="btn btn-success btn-sm" title="Abrir en Google Maps">
                  <i class="fa fa-external-link"></i> Abrir en Google Maps
                </button>
                <button id="btnStartNav" class="btn btn-success btn-sm" title="Modo navegación 3D">
                  <i class="fa fa-location-arrow"></i> Iniciar navegación
                </button>
              </div>
              <span class="label label-default" style="margin-left:8px;">Distancia: <span id="distanciaTotal">0 km</span></span>
              <span class="label label-default" style="margin-left:6px;">Duración: <span id="duracionEstimada">0 min</span></span>
            </div>

            <!-- HUD de Navegación 
            <div id="navHud" class="nav-hud" hidden style="display:none;">
              <div class="nav-banner">
                <div class="nav-ic" id="navIcon"><i class="fa fa-arrow-right"></i></div>
                <div style="flex:1;">
                  <div class="nav-main" id="navPrimary">Preparando navegación…</div>
                  <div class="nav-sub" id="navSecondary">—</div>
                </div>
              </div>
              <div class="nav-nextnext" id="navNextNext" style="display:none;">Próxima después: —</div>
              <div class="nav-bottom">
                <button id="btnExitNav" class="btn btn-default btn-sm"><i class="fa fa-times"></i> Salir</button>
                <div class="nav-stats">
                  <div><small>Hora est. Llegada</small><span id="hudEta">—</span></div>
                  <div><small>Arribo</small><span id="hudArrival">—</span></div>
                  <div><small>Restante</small><span id="hudRemain">—</span></div>
                </div>
                <button id="btnRecenter" class="btn btn-default btn-sm"><i class="fa fa-crosshairs"></i> Recentrar</button>
              </div>
            </div>
            -->
            
            
            <!-- Drawer Indicaciones -->
            <div id="drawerIndicaciones" class="route-drawer">
              <div class="drawer-header">
                <h5 class="drawer-title">Indicaciones paso a paso</h5>
                <button type="button" class="btn btn-xs btn-default" id="btnCloseDrawer">
                  <i class="fa fa-times"></i>
                </button>
              </div>
              <div class="drawer-body">
                <ol id="listaIndicaciones" class="steps-list"></ol>
              </div>
            </div>

            <div id="loadingIndicator">
               <i class="fa fa-spinner fa-spin"></i> Obteniendo ubicación...
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
         </div>
      </div>
   </div>
</div>

<?php
// Modales de gestión por local (programados + reagendados)
$localesTotales    = array_merge($locales, $locales_reag);
$idsGenerados      = [];
$precacheTargets   = [];
$precacheTargetsId = [];
foreach ($localesTotales as $row) {
    if (in_array($row['idLocal'], $idsGenerados)) continue;
    $idsGenerados[] = $row['idLocal'];
    $idLocal        = (int)$row['idLocal'];
    $codigoLocal    = $row['codigoLocal'];
    $nombreLocal    = $row['nombreLocal'];
    $direccionLocal = $row['direccionLocal'];
    $vendedor       = $row['vendedor'];

$sql_campanas = "
  SELECT DISTINCT
      f.id AS idCampana,
      f.nombre AS nombreCampana,
      f.fechaInicio,
      f.fechaTermino,
      f.estado
  FROM formularioQuestion fq
  INNER JOIN formulario AS f ON f.id = fq.id_formulario
  WHERE fq.id_usuario  = ?
    AND fq.id_local = ?
    AND f.id_empresa = ?
    AND f.tipo IN (3,1)
    AND f.estado = 1
    AND ( fq.countVisita = 0 OR fq.pregunta = 'en proceso' )
  ORDER BY f.fechaInicio DESC
";

    $stmt_campanas = $conn->prepare($sql_campanas);
    $stmt_campanas->bind_param('iii', $usuario_id, $idLocal, $empresa_id);
    $stmt_campanas->execute();
    $result_campanas = $stmt_campanas->get_result();

    echo "
    <div id='responsive{$idLocal}' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='myModalLabel{$idLocal}' aria-hidden='true'>
      <div class='modal-dialog'>
        <div class='modal-content'>
          <div class='modal-header'>
            <button type='button' class='close' data-dismiss='modal' aria-hidden='true'>&times;</button>
            <h4 class='modal-title' id='myModalLabel{$idLocal}'>
              Local: {$codigoLocal} - {$nombreLocal}<br>
              Dirección: {$direccionLocal}<br>
              Vendedor: {$vendedor}
            </h4>
          </div>
          <div class='modal-body'>
            <table class='table table-bordered'>
                <thead>
                    <tr>
                        <th>Nombre de la Campaña</th>
                        <th>Gestionar</th>
                    </tr>
                </thead>
                <tbody>
    ";

        if ($result_campanas->num_rows > 0) {
            while ($campana = $result_campanas->fetch_assoc()) {
                $idCampana     = (int)$campana['idCampana'];
                $nombreCampana = htmlspecialchars($campana['nombreCampana'], ENT_QUOTES, 'UTF-8');
                $gestionarUrl  = $appScope . '/gestionarPruebas.php'
                    . '?idCampana=' . urlencode($idCampana)
                    . '&nombreCampana=' . urlencode($nombreCampana)
                    . '&idLocal=' . urlencode($idLocal)
                    . '&idUsuario=' . urlencode($usuario_id);
                $gestionarUrlAttr = htmlspecialchars($gestionarUrl, ENT_QUOTES, 'UTF-8');

                $precacheKey = $idLocal . '|' . $idCampana;
                if (!isset($precacheTargetsId[$precacheKey])) {
                    $precacheTargetsId[$precacheKey] = true;
                    $precacheTargets[] = [
                    'idLocal'        => $idLocal,
                    'nombreLocal'    => $nombreLocal,
                    'direccionLocal' => $direccionLocal,
                    'idUsuario'      => $usuario_id,
                    'idCampana'      => $idCampana,
                    'nombreCampana'  => $nombreCampana
                ];
            }
            echo "
                <tr data-idcampana='{$idCampana}'>
                    <td>{$nombreCampana}</td>
                    <td class='center'>
                      <div class='btn-group btn-group-sm'>
                        <a href='{$gestionarUrlAttr}' class='btn btn-info'>
                          <i class='fa fa-pencil'></i> Gestionar
                        </a>
                      </div>
                    </td>
                </tr>
            ";
        }
    } else {
        echo "
            <tr>
                <td colspan='2' class='center'>No hay campañas asociadas a este local.</td>
            </tr>
        ";
    }

    echo "
                </tbody>
            </table>
          </div>
          <div class='modal-footer'>
            <button type='button' class='btn btn-default' data-dismiss='modal'>Cerrar</button>
          </div>
        </div>
      </div>
    </div>
    ";
    $stmt_campanas->close();
}
?>

<div class="footer clearfix">
   <div class="footer-inner">
      2025 &copy; Visibility 2 por Mentecreativa.
   </div>
   <div class="footer-items">
      <span class="go-top"><i class='fa fa-chevron-up'></i></span>
   </div>
</div>


<!-- Scripts -->
<script src="assets/plugins/jquery/jquery-3.6.0.min.js"></script>
<script src="assets/plugins/bootstrap/js/bootstrap.min.js" defer></script>
<script>
  window.__GOOGLE_MAPS_API_KEY = "<?php echo htmlspecialchars($googleMapsApiKey, ENT_QUOTES, 'UTF-8'); ?>";
</script>

<script>
// ============ Preferencias/estado ============
function debounce(fn, d){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(null,a),d); }; }
window.optimizeOrder = true;   // toggle "Optimizar"
window.autoRecalc    = true;   // toggle "Auto"
window.voiceEnabled  = false;  // toggle "Voz"
function savePref(k,v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
function loadPref(k,f){ try{ const v=localStorage.getItem(k); return v?JSON.parse(v):f; }catch(e){ return f; } }
function rememberMode(m){ savePref('v2_mode', m); }
function loadMode(){ return loadPref('v2_mode','prog'); }
function hasReagendadosData(){
  if (window.markersReag && Object.keys(window.markersReag).length) return true;
  return $('#panelReagendados table tbody tr').length > 0;
}
function rememberDate(mode, date){ savePref('v2_date_'+mode, date); }  function loadDate(mode){ return loadPref('v2_date_'+mode, ''); }
// Excluidos
function exclKey(modo, fecha, id){ return `${modo}|${fecha}|${id}`; }
function loadExcluded(){ window.excluded = new Set(loadPref('v2_excluded', [])); }
function saveExcluded(){ savePref('v2_excluded', Array.from(window.excluded||[])); }
loadExcluded();

// ============ Utilidades de ruta ============
const GOOGLE_MAPS_API_KEY = window.__GOOGLE_MAPS_API_KEY || '';
const MAP_ID = "YOUR_VECTOR_MAP_ID"; 
const MAPS_LIBRARIES = 'geometry';
const IS_TEST_MODE = <?php echo $TEST_MODE ? 'true' : 'false'; ?>;
let mapsScriptPromise = null;
let mapsRetryTimer = null;

function scheduleMapsRetry(){
  if (mapsRetryTimer) return;
  const retry = ()=>{
    mapsRetryTimer = null;
    loadGoogleMapsSdk().then(()=>{ if(!window.mapa) initMap(); }).catch(()=>{});
  };
  if (navigator.onLine){
    mapsRetryTimer = setTimeout(retry, 5000);
  } else {
    const onBackOnline = ()=>{ window.removeEventListener('online', onBackOnline); retry(); };
    window.addEventListener('online', onBackOnline);
  }
}

function loadGoogleMapsSdk(){
  if (window.google && window.google.maps) return Promise.resolve(window.google.maps);
  if (mapsScriptPromise) return mapsScriptPromise;
  if (IS_TEST_MODE){
    window.google = {
      maps: {
        Map: function(){ this.setCenter=()=>{}; this.setZoom=()=>{}; this.fitBounds=()=>{}; this.addListener=()=>{}; },
        Marker: function(opts){ this._map = opts.map; this.setMap=(m)=>{ this._map=m; }; this.getMap=()=>this._map; this.setPosition=()=>{}; this.getPosition=()=>({ toJSON:()=>({lat:0,lng:0}) }); },
        InfoWindow: function(){ this.open=()=>{}; },
        LatLng: function(lat,lng){ this.lat=()=>lat; this.lng=()=>lng; this.toJSON=()=>({lat,lng}); },
        LatLngBounds: function(){ this.extend=()=>{}; },
        TrafficLayer: function(){ this._map=null; this.getMap=()=>this._map; this.setMap=(m)=>{ this._map=m; }; },
        geometry: { spherical: { computeDistanceBetween: ()=>0 }, encoding: { encodePath: ()=>' ', decodePath: ()=>[] } },
        DirectionsService: function(){ this.route=(req, cb)=>cb({ routes:[{ legs: [], overview_path: [] }]}, 'OK'); },
        DirectionsStatus: { OK: 'OK' },
        SymbolPath: { CIRCLE: 'CIRCLE' },
        event: { trigger: ()=>{} },
        TravelMode: { DRIVING: 'DRIVING' }
      }
    };
    return Promise.resolve(window.google.maps);
  }
  if (!GOOGLE_MAPS_API_KEY){
    return Promise.reject(new Error('Falta la Google Maps API key.'));
  }

  mapsScriptPromise = new Promise((resolve, reject)=>{
    const prev = document.querySelector('script[data-google-maps-loader="true"]');
    if (prev) prev.remove();
    const s = document.createElement('script');
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_API_KEY)}&libraries=${MAPS_LIBRARIES}`;
    s.async = true; s.defer = true; s.dataset.googleMapsLoader = 'true';
    const onError = ()=>{ cleanup(); reject(new Error('Error al cargar Google Maps.')); };
    const onLoad = ()=>{ cleanup(true); resolve(window.google.maps); };
    const cleanup = (ok)=>{ s.onload=null; s.onerror=null; if (!ok && s.parentNode) s.parentNode.removeChild(s); };
    s.onload = onLoad;
    s.onerror = onError;
    document.head.appendChild(s);
  }).catch(err=>{ mapsScriptPromise=null; scheduleMapsRetry(); throw err; });

  return mapsScriptPromise;
}

async function ensureMapReady(){
  await loadGoogleMapsSdk();
  if (!window.mapa) initMap();
}
function secondsFromDuration(d){ if (typeof d==='string' && d.endsWith('s')) return Math.round(parseFloat(d)); return 0; }
function decode(encoded){ return google.maps.geometry.encoding.decodePath(encoded).map(ll=>({lat:ll.lat(), lng:ll.lng()})); }
function fmtKm(m){ return (m>=1000) ? (m/1000).toFixed(1)+' km' : Math.round(m)+' m'; }

// Cache sencillo para rutas (clave: origen|destino|paradas|optimizar)
const ROUTE_CACHE_TTL_MS = 5*60*1000;
const routeCache = new Map();
function routeCacheKey({origin,destination,waypoints,optimize}){
  const fmt = (p)=>`${p.lat.toFixed(6)},${p.lng.toFixed(6)}`;
  const ways = (waypoints||[]).map(fmt).join(';');
  return `${fmt(origin)}|${fmt(destination)}|${ways}|${optimize?'1':'0'}`;
}

// Pinta polilíneas por tráfico (Routes v2)
function buildTrafficPolylines(map, route){
  (map.__trafficSegs||[]).forEach(s=>s.setMap(null));
  map.__trafficSegs=[];
  const pts = decode(route.polyline.encodedPolyline);
  const intervals = (route.travelAdvisory && route.travelAdvisory.speedReadingIntervals) || [];
  if(!intervals.length){
    const poly=new google.maps.Polyline({ path: pts, map, strokeOpacity:.95, strokeWeight:6, strokeColor:'#4c8fbd' });
    map.__trafficSegs.push(poly);
    const b=new google.maps.LatLngBounds(); pts.forEach(p=>b.extend(p)); if(!b.isEmpty()) map.fitBounds(b); return;
  }
  intervals.forEach(int=>{
    const start=int.startPolylinePointIndex||0;
    const end  =int.endPolylinePointIndex||Math.max(1, pts.length-1);
    const path = pts.slice(start, end+1);
    const col  =(int.speed==='SLOW') ? '#ffa722' : (int.speed==='TRAFFIC_JAM' ? '#d74d3a' : '#4c8fbd');
    const w=(int.speed==='NORMAL')?6:7;
    const poly=new google.maps.Polyline({ path, map, strokeOpacity:.95, strokeWeight:w, strokeColor:col });
    map.__trafficSegs.push(poly);
  });
  const b=new google.maps.LatLngBounds(); pts.forEach(p=>b.extend(p)); if(!b.isEmpty()) map.fitBounds(b);
}

// Drawer de pasos
function renderIndicacionesFromRoute(route){
  const $ol=$('#listaIndicaciones'); $ol.empty();
  (route.legs||[]).forEach(leg=>{
    (leg.steps||[]).forEach(st=>{
      const ins=(st.navigationInstruction && st.navigationInstruction.instructions) || '';
      const dist=(st.distanceMeters!=null)? fmtKm(st.distanceMeters):'';
      const dur =(st.staticDuration)? Math.round(secondsFromDuration(st.staticDuration)/60)+' min':'';
      const meta=[dist,dur].filter(Boolean).join(' • ');
      $ol.append(`<li>${ins || 'Sigue la vía'}<br><small>${meta}</small></li>`);
    });
  });
}

// Motor unificado (Routes v2) con fallback a DirectionsService + caché en memoria
async function computeRouteUnified({origin,destination,waypoints=[], optimize=true}){
  const key=routeCacheKey({origin,destination,waypoints,optimize});
  const now=Date.now();
  const cached=routeCache.get(key);
  if(cached && cached.expires>now) return cached.value;

  const computePromise = (async()=>{
    const body={
      origin:{ location:{ latLng:{ latitude: origin.lat, longitude: origin.lng } } },
      destination:{ location:{ latLng:{ latitude: destination.lat, longitude: destination.lng } } },
      intermediates:(waypoints||[]).map(w=>({ location:{ latLng:{ latitude:w.lat, longitude:w.lng } } })),
      travelMode:"DRIVE", routingPreference:"TRAFFIC_AWARE", optimizeWaypointOrder: !!optimize,
      polylineQuality:"HIGH_QUALITY", polylineEncoding:"ENCODED_POLYLINE",
      departureTime:{ seconds: Math.floor(Date.now()/1000) + 30 }, computeAlternativeRoutes: true
    };
    const fields=[
      "routes.distanceMeters","routes.duration","routes.optimizedIntermediateWaypointIndex",
      "routes.polyline.encodedPolyline",
      "routes.legs.distanceMeters","routes.legs.duration","routes.legs.polyline.encodedPolyline",
      "routes.legs.steps.distanceMeters","routes.legs.steps.staticDuration",
      "routes.legs.steps.polyline.encodedPolyline","routes.legs.steps.navigationInstruction",
      "routes.travelAdvisory.speedReadingIntervals"
    ].join(",");
    try{
      const r = await fetch("https://routes.googleapis.com/directions/v2:computeRoutes",{
        method:"POST",
        headers:{ "Content-Type":"application/json", "X-Goog-Api-Key": GOOGLE_MAPS_API_KEY, "X-Goog-FieldMask": fields },
        body: JSON.stringify(body)
      });
      if(!r.ok) throw new Error(`Routes API ${r.status}`);
      const json=await r.json();
      json.routes.sort((a,b)=>secondsFromDuration(a.duration)-secondsFromDuration(b.duration));
      return json.routes[0];
    }catch(e){
      // Fallback a api directions
      return await new Promise((resolve, reject)=>{
        const svc = new google.maps.DirectionsService();
        const req = {
          origin: new google.maps.LatLng(origin.lat,origin.lng),
          destination: new google.maps.LatLng(destination.lat,destination.lng),
          waypoints: (waypoints||[]).map(w=>({location:new google.maps.LatLng(w.lat,w.lng), stopover:true})),
          optimizeWaypoints: !!optimize, travelMode: google.maps.TravelMode.DRIVING
        };
        svc.route(req,(res,st)=>{
          if(st!==google.maps.DirectionsStatus.OK) return reject(new Error('Directions fallback '+st));
          const legs = res.routes[0].legs.map(l=>({
            distanceMeters:l.distance.value, duration: l.duration.value+'s',
            steps: l.steps.map(s=>({
              distanceMeters:s.distance.value, staticDuration:s.duration.value+'s',
              navigationInstruction:{ instructions: s.instructions.replace(/<[^>]+>/g,'') },
              polyline:{ encodedPolyline: google.maps.geometry.encoding.encodePath(s.path) }
            })),
            polyline:{ encodedPolyline: google.maps.geometry.encoding.encodePath(res.routes[0].overview_path) }
          }));
          resolve({
            distanceMeters: res.routes[0].legs.reduce((a,l)=>a+l.distance.value,0),
            duration:       res.routes[0].legs.reduce((a,l)=>a+l.duration.value,0)+'s',
            polyline:{ encodedPolyline: google.maps.geometry.encoding.encodePath(res.routes[0].overview_path) },
            travelAdvisory:{},
            legs
          });
        });
      });
    }
  })();

  routeCache.set(key,{expires: now + ROUTE_CACHE_TTL_MS, value: computePromise});
  try{
    const res=await computePromise;
    routeCache.set(key,{expires: Date.now() + ROUTE_CACHE_TTL_MS, value: Promise.resolve(res)});
    return res;
  }catch(err){
    routeCache.delete(key);
    throw err;
  }
}

// Toma puntos desde la tabla activa/visible
function collectCurrentPoints(){
  const cont  = (window.modoLocal === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId = (window.modoLocal==='prog')?'#filtroFechaProg':'#filtroFechaReag';
  const fechaSel = $(selId).val(); const modo = window.modoLocal;
  const filas = $(`${cont} table[data-fechaTabla="${fechaSel}"]:visible tbody tr:visible`);
  const pts=[]; filas.each(function(){
    const idLocal = parseInt($(this).data('idlocal'),10);
    const lat = parseFloat($(this).data('lat')); const lng = parseFloat($(this).data('lng'));
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    const hasCheck = $(this).find('.in-route').length > 0;
    const include = hasCheck
      ? $(this).find('.in-route').prop('checked')
      : !window.excluded.has(`${modo}|${fechaSel}|${idLocal}`);
    if (!include) return;
    pts.push({ idLocal, lat, lng });
  });
  if (pts.length>24){ pts.length=24; } // límite técnico (destino+23 paradas)
  return pts;
}

// Texto a voz (toggleable)
function speak(text){
  if(!window.voiceEnabled) return;
  try{
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-CL';
    const v=speechSynthesis.getVoices().find(v=>/es\-(CL|ES|MX)/i.test(v.lang));
    if(v) u.voice=v;
    speechSynthesis.speak(u);
  }catch(_){}
}

// Planifica/actualiza la ruta en el mapa (polilíneas + drawer + HUD stats)
window.planRouteFromSelection = async function (origen){
  const puntos = collectCurrentPoints();
  if (!puntos.length) {
    (window.mapa.__trafficSegs||[]).forEach(s=>s.setMap(null)); window.mapa.__trafficSegs=[];
    window.plannedRoute=null; $('#distanciaTotal').text('0 km'); $('#duracionEstimada').text('0 min'); $('#listaIndicaciones').empty(); return;
  }
  const destination = puntos[puntos.length - 1];
  const waypoints   = puntos.slice(0, -1);
  try{
    const route = await computeRouteUnified({origin:origen, destination, waypoints, optimize:window.optimizeOrder});
    window.plannedRoute = route;
    buildTrafficPolylines(window.mapa, route);
    const km  = ((route.distanceMeters||0)/1000).toFixed(2);
    const min = Math.round(secondsFromDuration(route.duration)/60);
    $('#distanciaTotal').text(`${km} km`);
    $('#duracionEstimada').text(`${min} min`);
    renderIndicacionesFromRoute(route);
    speak(`Ruta actualizada. ${km} kilómetros, ${min} minutos.`);
  }catch(err){  }
};
window.debouncedPlanRoute = debounce(window.planRouteFromSelection, 1000);

// Estado marcadores y contadores
window.markersProg={}; window.markersReag={}; window.plannedRoute=null;
function hideAllMarkers(obj){ Object.values(obj).forEach(m => m.marker.setMap(null)); }
function ensureDateSelectedFor(mode){
  const selId = (mode === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const $sel  = $(selId); const saved = loadDate(mode);
  if (saved && $sel.find(`option[value="${saved}"]`).length) $sel.val(saved);
  if (!$sel.val()) { const first = $sel.find('option:first').val(); if (first) $sel.val(first); }
}
function setMode(mode){
  const desired = mode || 'prog';
  const finalMode = (desired === 'reag' && !hasReagendadosData()) ? 'prog' : desired;
  window.modoLocal = finalMode;
  rememberMode(finalMode);
  if (finalMode === 'prog') { $('#panelReagendados').hide(); $('#panelProgramados').show(); }
  else { $('#panelProgramados').hide(); $('#panelReagendados').show(); }
  hideAllMarkers(window.markersProg); hideAllMarkers(window.markersReag);
  ensureDateSelectedFor(finalMode); applyFilters();
  const pos = window.ejecutorMarker?.getPosition();
  if (pos && !(window.navigator3D && window.navigator3D.active)) { window.debouncedPlanRoute(pos.toJSON()); }
}
function updateCounts(){
  const mode   = window.modoLocal || 'prog';
  const panel  = (mode==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  $('#countTabla').text($(panel+' table[data-fechaTabla] tbody tr:visible').length);
  const markers=(mode==='prog')?window.markersProg:window.markersReag;
  const count=Object.values(markers).filter(m=>m.marker.getMap()!==null).length;
  $('#countMapa').text(count);
  const selId = (mode==='prog')?'#filtroFechaProg':'#filtroFechaReag'; const fechaSel = $(selId).val();
  let excl=0; Object.keys(markers).forEach(id=>{ if (window.excluded.has(`${mode}|${fechaSel}|${id}`)) excl++; }); $('#countEx').text(excl);
}

// applyFilters: respeta campañas tachadas + fecha + excluidos + checkboxes
window.applyFilters = function(){
  const modo      = window.modoLocal || 'prog';
  const selId     = (modo==='prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId  = (modo==='prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const container = (modo==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const markers   = (modo==='prog') ? window.markersProg : window.markersReag;
  const other     = (modo==='prog') ? window.markersReag : window.markersProg;
  hideAllMarkers(other);
  const searchTerm = String($(searchId).val() || '').trim().toLowerCase();
  const tachadas = $('ul.todo .completed').map((i,li)=>String($(li).data('idcampana'))).get();
  const fechasOk = {};
  $(`${container} table[data-fechaTabla]`).each(function(){
    const fecha = $(this).attr('data-fechaTabla'); let tiene = false;
    $(this).find('tbody tr').each(function(){
      const camps = String($(this).data('campanas')||'').split(',');
      const ok = camps.some(c => !tachadas.includes(c));
      $(this).data('okCamp', ok);
      if (ok) tiene = true;
    });
    fechasOk[fecha] = tiene;
  });
  
  const $sel = $(selId), prev = $sel.val(); $sel.empty();
  Object.keys(fechasOk).filter(f=>fechasOk[f]).sort().forEach(f=>{
    const [y,m,d]=f.split('-'); $sel.append(`<option value="${f}">${d}-${m}-${y}</option>`);
  });
  if (prev && fechasOk[prev]) $sel.val(prev);
  if (!$sel.val()) $sel.val($sel.find('option:first').val() || '');
  const fechaSel = $sel.val(); rememberDate(modo, fechaSel);
  $(`${container} h4[data-fechaencabezado], ${container} table[data-fechaTabla]`).hide();
 if (fechaSel){
    $(`${container} h4[data-fechaencabezado="${fechaSel}"], ${container} table[data-fechaTabla="${fechaSel}"]`).show();
    $(`${container} table[data-fechaTabla="${fechaSel}"] tbody tr`).each(function(){
      const okCamp = !!$(this).data('okCamp');
      const txt = String($(this).data('busqueda') || $(this).text() || '').toLowerCase();
      const matches = !searchTerm || txt.includes(searchTerm);
      $(this).toggle( okCamp && matches );
      const id = parseInt($(this).data('idlocal'),10);
      const $chk = $(this).find('input.in-route');
      if ($chk.length){
        const excluded = window.excluded.has(`${modo}|${fechaSel}|${id}`);
        $chk.prop('checked', !excluded);
      }
    });
  }
  Object.entries(markers).forEach(([id,m])=>{
    const sameDate = (m.fechaPropuesta === fechaSel);
    const visibleRow = $(`${container} table[data-fechaTabla="${fechaSel}"] tbody tr[data-idlocal="${id}"]:visible`).length > 0;
    m.marker.setMap( (sameDate && visibleRow) ? window.mapa : null );
  });
  const visibles = Object.values(markers).filter(m=>m.marker.getMap()!==null);
  if (visibles.length){
    const b = new google.maps.LatLngBounds(); visibles.forEach(m=>b.extend(m.marker.getPosition())); window.mapa.fitBounds(b);
  }
  updateCounts();
  const pos = window.ejecutorMarker?.getPosition();
  if (pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON());
};

// ====== INIT MAP ======
window.initMap=function(){
  if (window.mapa) {
    google.maps.event.trigger(window.mapa, 'resize');
    return;
  }
  const coordenadasProg=<?php echo json_encode($coordenadas_locales_programados); ?>;
  const coordenadasReag=<?php echo json_encode($coordenadas_locales_reag); ?>;

  const mapOptions = { zoom:12, center:{lat:-33.4489, lng:-70.6693} };
  if (MAP_ID && MAP_ID !== 'YOUR_VECTOR_MAP_ID') mapOptions.mapId = MAP_ID;

  window.mapa=new google.maps.Map(document.getElementById('map'), mapOptions);

  // Marcadores Programados
  coordenadasProg.forEach(local=>{
    const iconUrl=(local.markerColor==='blue') ? 'assets/images/marker_blue1.png' : 'assets/images/marker_red1.png';
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon:{ url:iconUrl, scaledSize:new google.maps.Size(30,30) }
    });
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:180px;"><strong>${local.nombre_local}</strong><br><br>
       <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#responsive${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    window.markersProg[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta };
  });

  // Marcadores Reagendados
  coordenadasReag.forEach(local=>{
    const iconUrl=(local.markerColor==='blue') ? 'assets/images/marker_blue1.png' : 'assets/images/marker_red1.png';
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon:{ url:iconUrl, scaledSize:new google.maps.Size(30,30) }
    });
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:180px;"><strong>${local.nombre_local}</strong><br><br>
       <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#responsive${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    window.markersReag[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta };
  });

  // Ubicación ejecutor + capa tráfico
  window.ejecutorMarker=new google.maps.Marker({
    position:{lat:-33.4489, lng:-70.6693}, map:window.mapa, title:'Tu Ubicación',
    icon:{ path:google.maps.SymbolPath.CIRCLE, scale:8, fillColor:'#4285F4', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 }
  });
  window.trafficLayer = new google.maps.TrafficLayer();

  // Geo + recálculo con umbral
  const MIN_MOVE_METERS=60; let lastPos=null;
  if(navigator.geolocation){
    navigator.geolocation.watchPosition(pos=>{
      const cur=new google.maps.LatLng(pos.coords.latitude, pos.coords.longitude);
      window.ejecutorMarker.setPosition(cur);
      // if(!(window.navigator3D && window.navigator3D.active)){ window.mapa.panTo(cur); }
      if(!lastPos ||
         google.maps.geometry.spherical.computeDistanceBetween(lastPos, cur) > MIN_MOVE_METERS){
        lastPos=cur; const json=cur.toJSON();
        if (window.navigator3D && window.navigator3D.active) return;
        if (window.autoRecalc) window.debouncedPlanRoute(json);
      }
    },err=>{ console.error(err); alert('No se pudo obtener tu ubicación.'); },
    { enableHighAccuracy:true, maximumAge:2000, timeout:10000 });
  } else { alert('Tu navegador no soporta geolocalización.'); }

  // Modal show
  $('#modalMapa').on('shown.bs.modal', function(){
    google.maps.event.trigger(window.mapa, 'resize');
    ensureDateSelectedFor(window.modoLocal||'prog'); applyFilters();
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON());
  });

  // Botones
  $('#btnActualizar').on('click', function(){ rememberMode('prog'); });
  $('#btnCentrar').on('click', ()=>{
    if(!navigator.geolocation) return;
    $('#loadingIndicator').show();
    navigator.geolocation.getCurrentPosition(p=>{
      const cur=new google.maps.LatLng(p.coords.latitude, p.coords.longitude);
      window.ejecutorMarker.setPosition(cur); window.mapa.setCenter(cur); window.mapa.setZoom(15);
      if(!(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(cur.toJSON());
      $('#loadingIndicator').hide();
    }, ()=>{ $('#loadingIndicator').hide(); alert('No se pudo centrar.'); }, { enableHighAccuracy:true, maximumAge:0, timeout:10000 });
  });
  $('#btnRecalcular').on('click', ()=>{
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON());
  });
  $('#btnIndicaciones').on('click', ()=> $('#drawerIndicaciones').toggleClass('open'));
  $('#btnCloseDrawer').on('click', ()=> $('#drawerIndicaciones').removeClass('open'));
  $('#btnTraffic').on('click', function(){ const isOn=!!window.trafficLayer.getMap(); window.trafficLayer.setMap(isOn?null:window.mapa); });
  $('#optimizeOrder').on('change', function(){ window.optimizeOrder=$(this).is(':checked'); const pos=window.ejecutorMarker?.getPosition(); if (pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON()); });
  $('#autoRecalc').on('change', function(){ window.autoRecalc=$(this).is(':checked'); });
  $('#btnVoz').on('click', function(){ window.voiceEnabled=!window.voiceEnabled; $(this).toggleClass('btn-info', window.voiceEnabled); if (window.voiceEnabled) speak('Voz activada.'); else { try{ speechSynthesis.cancel(); }catch(_){}} });
  $('#btnExportar').on('click', function(){
    const pos = window.ejecutorMarker?.getPosition(); if (!pos){ alert('No se pudo obtener tu ubicación.'); return; }
    const pts = collectCurrentPoints(); if (!pts.length){ alert('Selecciona al menos un local en la columna Ruta.'); return; }
    const origin=pos.toJSON(); const dest=pts[pts.length-1]; const ways=pts.slice(0,-1).map(p=>`${p.lat},${p.lng}`).join('|');
    const url = `https://www.google.com/maps/dir/?api=1&origin=${origin.lat},${origin.lng}&destination=${dest.lat},${dest.lng}&travelmode=driving` + (ways ? `&waypoints=${encodeURIComponent(ways)}` : ``);
    window.open(url, '_blank');
  });

  // Modo inicial y filtros
  $('#btnVerReagendados').on('click', function(){ $('#filtroLocalesReag').val(''); setMode('reag'); });
  $('#btnVerProgramados').on('click', function(){ $('#filtroLocalesProg').val(''); setMode('prog'); });
  $(document).on('change', 'table input.in-route', function(){
    const $tr=$(this).closest('tr'); const id=parseInt($tr.data('idlocal'),10); const modo=window.modoLocal;
    const fecha=(modo==='prog')?$('#filtroFechaProg').val():$('#filtroFechaReag').val(); const key=`${modo}|${fecha}|${id}`;
    if ($(this).is(':checked')) window.excluded.delete(key); else window.excluded.add(key); saveExcluded(); updateCounts();
    const pos = window.ejecutorMarker?.getPosition(); if (pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON());
  });

 setMode(window.modoLocal || loadMode() || 'prog'); // arranca con modo recordado
  setTimeout(()=>$('#filtroFechaProg').trigger('change'), 200);
};

// ======= Navegación 3D compacta =======
(function(){
  function bearing(a,b){
    const φ1=a.lat*Math.PI/180, φ2=b.lat*Math.PI/180, Δλ=(b.lng-a.lng)*Math.PI/180;
    const y=Math.sin(Δλ)*Math.cos(φ2), x=Math.cos(φ1)*Math.sin(φ2)-Math.sin(φ1)*Math.cos(φ2)*Math.cos(Δλ);
    const θ=Math.atan2(y,x)*180/Math.PI; return (θ+360)%360;
  }
  function dist(a,b){
    const p1=new google.maps.LatLng(a.lat,a.lng), p2=new google.maps.LatLng(b.lat,b.lng);
    return google.maps.geometry.spherical.computeDistanceBetween(p1,p2);
  }
  function speedToZoom(kmh){ if (kmh<=20) return 18; if (kmh<=60) return 17; return 16; }
  const seconds=(d)=> (typeof d==='string' && d.endsWith('s')) ? Math.round(parseFloat(d)) : 0;
  const TIMEZONE_CACHE_TTL_MS=10*60*1000;
  const timezoneCache=new Map();
  const tzKey=(lat,lng,epochSecs)=>{
    const ts=Math.round(epochSecs/60); // redondeo a minutos
    return `${lat.toFixed(4)},${lng.toFixed(4)}|${ts}`;
  };
  async function getArrivalLocalTime(lat,lng, epochSecs){
    const key=tzKey(lat,lng,epochSecs);
    const now=Date.now();
    const cached=timezoneCache.get(key);
    if(cached && cached.expires>now) return cached.value;

    const fetchPromise=(async()=>{
      const url=`https://maps.googleapis.com/maps/api/timezone/json?location=${lat},${lng}&timestamp=${epochSecs}&key=${GOOGLE_MAPS_API_KEY}`;
      const r=await fetch(url); const j=await r.json();
      const offset=(j.rawOffset||0)+(j.dstOffset||0); const d=new Date((epochSecs+offset)*1000);
      return d.toLocaleTimeString('es-CL',{hour:'2-digit',minute:'2-digit'});
    })();

    timezoneCache.set(key,{expires: now + TIMEZONE_CACHE_TTL_MS, value: fetchPromise});
    try{
      const res=await fetchPromise;
      timezoneCache.set(key,{expires: Date.now() + TIMEZONE_CACHE_TTL_MS, value: Promise.resolve(res)});
      return res;
    }catch(_){
      timezoneCache.delete(key);
      return new Date(epochSecs*1000).toLocaleTimeString('es-CL',{hour:'2-digit',minute:'2-digit'});
    }
  }
  function stepsFromRoute(route){
    const steps=[]; (route.legs||[]).forEach(leg=>{
      (leg.steps||[]).forEach(s=>{
        const instr=(s.navigationInstruction && s.navigationInstruction.instructions)||"";
        const end  = google.maps.geometry.encoding.decodePath(s.polyline.encodedPolyline).slice(-1)[0];
        const endC = { lat: end.lat(), lng: end.lng() };
        steps.push({ text: instr, end: endC, poly: google.maps.geometry.encoding.decodePath(s.polyline.encodedPolyline).map(ll=>({lat:ll.lat(), lng:ll.lng()})) });
      });
    }); return steps;
  }
  function iconForText(t){
    const s=t||''; if(/derecha/i.test(s)) return 'fa-arrow-right';
    if(/izquierda/i.test(s)) return 'fa-arrow-left';
    if(/u\-?turn|retorno/i.test(s)) return 'fa-undo';
    if(/rotonda|glorieta/i.test(s)) return 'fa-circle-o';
    if(/incorp|salga|salida/i.test(s)) return 'fa-sign-out';
    if(/continúe|recto|siga/i.test(s)) return 'fa-long-arrow-up';
    return 'fa-location-arrow';
  }
  class Navigator {
    constructor(map){ this.map=map; this.active=false; this.stepIdx=0; this.steps=[]; this.route=null; this.geoWatch=null;
      this.track=true; this.lastPos=null; this.lastHeading=0; this.offRouteSince=null; this.lastRerouteAt=0; this.minRerouteGap=2000;
      map.addListener('dragstart', ()=>{ if(!this.active) return; this.track=false; $('#btnRecenter').addClass('show'); });
    }
    async startFromSelection(){
      const pts = collectCurrentPoints(); const pos = window.ejecutorMarker?.getPosition();
      if(!pos || !pts.length){ alert('Necesitas al menos 1 parada y la ubicación actual.'); return; }
      const origin = pos.toJSON(); const destination = pts[pts.length-1]; const waypoints=pts.slice(0,-1);
      await this.start({origin,destination,waypoints});
    }
    async start({origin,destination,waypoints}){
      try{
        $('#navHud').show(); this.active=true; this.track=true; this.stepIdx=0; this.offRouteSince=null; $('#btnRecenter').removeClass('show');
        const route = await computeRouteUnified({origin,destination,waypoints, optimize:window.optimizeOrder});
        this.route=route; this.steps=stepsFromRoute(route); this.updateHudOverview(); buildTrafficPolylines(this.map, route);
        this.moveCamera(origin, this.lastHeading||0, 17, 55, true); this.watchGps(); this.listenDeviceOrientation();
      }catch(e){ alert('No se pudo iniciar navegación.'); $('#navHud').hide(); this.active=false; }
    }
    stop(){ this.active=false; this.unwatchGps(); $('#navHud').hide(); (this.map.__trafficSegs||[]).forEach(p=>p.setMap(null)); this.route=null; this.steps=[]; this.stepIdx=0; this.track=true; $('#btnRecenter').removeClass('show'); }
    updateHudOverview(){
      const r=this.route; if(!r) return; const etaMin=Math.max(1, Math.round(seconds(r.duration)/60)); const distM=r.distanceMeters||0;
      $('#hudEta').text(`${etaMin} min`); $('#hudRemain').text(fmtKm(distM));
      const arrEpoch=Math.floor(Date.now()/1000)+seconds(r.duration);
      const lastLegEnd=google.maps.geometry.encoding.decodePath((r.legs||[]).slice(-1)[0].polyline.encodedPolyline).slice(-1)[0];
      getArrivalLocalTime(lastLegEnd.lat(), lastLegEnd.lng(), arrEpoch).then(t=>$('#hudArrival').text(t));
      this.renderStepBanner();
    }
    renderStepBanner(){
      const s=this.steps[this.stepIdx]; if(!s){ $('#navPrimary').text('Navegación'); $('#navSecondary').text('—'); $('#navNextNext').hide(); return; }
      $('#navPrimary').text(s.text || 'Sigue la vía'); $('#navSecondary').text('Próximo giro'); $('#navIcon').html(`<i class="fa ${iconForText(s.text)}"></i>`);
      const n2=this.steps[this.stepIdx+1]; if(n2){ $('#navNextNext').text('Luego: '+(n2.text||'—')).show(); } else { $('#navNextNext').hide(); }
    }
    watchGps(){
      this.unwatchGps();
      this.geoWatch=navigator.geolocation.watchPosition(p=>{
        const cur={lat:p.coords.latitude, lng:p.coords.longitude}; const now=Date.now();
        const spd=(this.lastPos? (dist(this.lastPos,cur)/((now-(this._lastTime||now))/1000))*3.6 : 0); this._lastTime=now;
        this.lastPos=cur; if(this.track) this.moveCamera(cur, this.lastHeading, speedToZoom(spd), 55, false);
        this.advanceStepIfNeeded(cur);
        if(!this.isOnRoute(cur, 40)){ if(!this.offRouteSince) this.offRouteSince=now; if(now-this.offRouteSince>3000) this.tryReroute(cur); }
        else this.offRouteSince=null;
      }, ()=>{}, { enableHighAccuracy:true, maximumAge:1000, timeout:10000 });
    }
    unwatchGps(){ if(this.geoWatch!=null){ navigator.geolocation.clearWatch(this.geoWatch); this.geoWatch=null; } }
    moveCamera(center, heading, zoom, tilt, instant){ if(instant){ this.map.moveCamera({center, heading, tilt, zoom}); return; }
      const curH=this.map.getHeading()||0; const delta=((heading - curH + 540)%360)-180; const frames=12; let i=0;
      const tick=()=>{ if(i>=frames) return; const t=(i+1)/frames; this.map.moveCamera({center, heading: curH + delta*t, tilt, zoom}); i++; requestAnimationFrame(tick); }; tick();
    }
    listenDeviceOrientation(){
      const on=(e)=>{ let hdg=e.alpha; if(hdg==null) return; const cur=this.lastHeading||hdg; const d=((hdg - cur + 540)%360)-180; this.lastHeading=(cur + d*0.2 + 360)%360; };
      try{
        if(typeof DeviceOrientationEvent!=='undefined' && typeof DeviceOrientationEvent.requestPermission==='function'){
          DeviceOrientationEvent.requestPermission().then(state=>{ if(state==='granted') window.addEventListener('deviceorientation', on, true); }).catch(()=>{});
        } else { window.addEventListener('deviceorientationabsolute', on, true); window.addEventListener('deviceorientation', on, true); }
      }catch(_){}
    }
    isOnRoute(point, tolMeters){
      if(!this.route) return true; const path=google.maps.geometry.decodePath(this.route.polyline.encodedPolyline);
      const poly=new google.maps.Polyline({ path }); const tol=(tolMeters||40)/6378137; const gll=new google.maps.LatLng(point.lat, point.lng);
      return google.maps.geometry.poly.isLocationOnEdge(gll, poly, tol);
    }
    advanceStepIfNeeded(cur){
      const s=this.steps[this.stepIdx]; if(!s) return; const d=dist(cur, s.end);
      if(d<12){ this.stepIdx++; this.renderStepBanner(); try{ navigator.vibrate && navigator.vibrate(120);}catch(_){}
        const nx=this.steps[this.stepIdx]; if(nx && window.voiceEnabled){ window.speechSynthesis.cancel(); speak(nx.text); } }
    }
    async tryReroute(cur){
      const now=Date.now(); if(now - this.lastRerouteAt < this.minRerouteGap) return; this.lastRerouteAt=now;
      const remain=this.steps.slice(this.stepIdx).map(s=>s.end); const destination=remain.length?remain.slice(-1)[0]:cur; const waypoints=remain.slice(0,-1);
      try{
        const newRoute=await computeRouteUnified({origin:cur, destination, waypoints, optimize:window.optimizeOrder});
        this.route=newRoute; this.steps=stepsFromRoute(newRoute); this.stepIdx=0;
        buildTrafficPolylines(this.map, newRoute); this.updateHudOverview(); speak('Ruta recalculada');
      }catch(_){ /* silencioso */ }
    }
  }
  window.navigator3D=null;
  function ensureNav(){ if(!window.navigator3D) window.navigator3D=new Navigator(window.mapa); return window.navigator3D; }
  $('#btnStartNav').on('click', async ()=>{ if(!window.mapa){ alert('Mapa no listo.'); return; } const nav=ensureNav(); await nav.startFromSelection(); $('#btnRecenter').removeClass('show'); });
  $('#btnExitNav').on('click', ()=>{ if(window.navigator3D) window.navigator3D.stop(); });
  $('#btnRecenter').on('click', ()=>{ if(!window.navigator3D) return; window.navigator3D.track=true; $('#btnRecenter').removeClass('show'); });
})();


// ======= Wire-up básico =======
$(document).ready(function(){
  setTimeout(()=>$('#success-alert').fadeOut('slow'),3000);
  $('#filtroFechaProg, #filtroFechaReag').off('change').on('change', function(){ applyFilters(); });
  $(document).on('click', '.todo-actions', function(){ const $li=$(this).closest('li'), $i=$li.find('i'); $li.toggleClass('completed'); $i.toggleClass('fa-square-o fa-check-square-o'); applyFilters(); });
  // modos
  $('#btnVerReagendados').on('click', ()=> setMode('reag'));
  $('#btnVerProgramados').on('click', ()=> setMode('prog'));
  $('#modalMapa').on('show.bs.modal', function(){
    ensureMapReady().catch(()=>{
      alert('No se pudo cargar Google Maps. Reintentaremos cuando haya conexión.');
    });
  });
  // filtros de texto -> aplica filtros completos
  $('#filtroLocalesProg, #filtroLocalesReag').on('input', debounce(()=>applyFilters(),200));
  // inicia
  window.modoLocal='prog'; setTimeout(applyFilters, 500);
});


    
    
</script>

<script src="assets/js/db.js"></script>
<script src="assets/js/offline-queue.js"></script>
<script src="assets/js/v2_cache.js"></script>
<script src="assets/js/bootstrap_index_cache.js"></script>
<script src="assets/js/journal_db.js"></script>
<script src="assets/js/journal_ui.js"></script>
<script>
  window.__GESTIONAR_PRECACHE_TARGETS = <?php echo json_encode($precacheTargets, JSON_UNESCAPED_UNICODE); ?>;
  window.__GESTIONAR_PRECACHE_LIMIT   = <?php echo (int)$precacheLimit; ?>;
  window.__GESTIONAR_PRECACHE_USER    = <?php echo (int)$usuario_id; ?>;
</script>

<script>


(function(){
    
  async function applyGestionOutcomeLocally(ymd, localId, estadoFinal, fechaReagendada) {
    if (!window.V2Cache || !V2Cache.route) return;

    var estado = String(estadoFinal || '').toLowerCase();
    var id     = Number(localId);
    if (!id) return;

    var esCerrado =
      /implementado/.test(estado) ||
      /auditado/.test(estado) ||
      /cancelado/.test(estado);

    var esPendiente = /pendiente/.test(estado);

    try {
      if (esCerrado) {
        // Desaparece de la agenda local
        await (V2Cache.route.hideLocalForDate?.(ymd, id) || Promise.resolve());
      } else if (esPendiente) {
        // Va a reagendados (usa fecha nueva si viene del servidor)
        var nuevaFecha = fechaReagendada || ymd;
        await (V2Cache.route.markReagendadoForDate?.(ymd, nuevaFecha, id) || Promise.resolve());
      }
    } catch(_){}
  }

  window.addEventListener('queue:dispatch:success', async function(e){
    var job  = (e && e.detail && (e.detail.job || e.detail)) || null;
    var resp = (e && e.detail && e.detail.response) || {};
    if (!job) return;

    var f      = job.fields || {};
    var lid    = job.meta?.local_id || f.id_local || f.idLocal || null;
    var estado = (job.meta && job.meta.estado_final) ||
                 resp.estado_final ||
                 resp.estado_gestion || '';

    if (!lid) return;

    var ymd = (new Date()).toISOString().slice(0,10);

    // Aplica el mismo comportamiento histórico que tenía online
    await applyGestionOutcomeLocally(
      ymd,
      lid,
      estado,
      resp.fecha_propuesta || resp.fecha_reagendada || resp.fecha_visita || null
    );
    try {
      if (window.BootstrapIndex?.refreshToday) {
        BootstrapIndex.refreshToday();
      } else if (typeof window.renderProgramadosHoy === 'function') {
        renderProgramadosHoy();
      }
    } catch(_){}
  });
})();





(function(){
  // --- 0) Guardas de entorno ---
  if (!window.V2DB) { console.warn('V2DB no disponible aún (db.js). El modo offline del index se activará cuando esté.'); }

  // --- 1) Badges de red ---
  function setNetBadge(){
    var b = document.getElementById('netBadge');
    if(!b) return;
    if(navigator.onLine){ b.textContent='Online'; b.classList.add('is-online'); b.classList.remove('is-offline'); }
    else { b.textContent='Offline'; b.classList.add('is-offline'); b.classList.remove('is-online'); }
  }
  window.addEventListener('online', setNetBadge);
  window.addEventListener('offline', setNetBadge);
  setNetBadge();

  // --- 2) Pintado de listas desde IndexedDB ---
  function paintList(sel, rows){
    const cont = document.querySelector(sel);
    if(!cont) return;
    if(!rows || !rows.length){
      cont.innerHTML = '<div class="empty-state"><i class="fa fa-cloud-download"></i> No hay datos locales.</div>';
      return;
    }
    cont.innerHTML = '<div class="route-list"></div>';
    const list = cont.querySelector('.route-list');

    rows.forEach(r=>{
      // r esperado: { id_local, nombre_local, direccion, comuna, campanasIds:[], estado, hoy }
      const idCampana = (r.campanasIds && r.campanasIds[0]) ? r.campanasIds[0] : null;
      const href = idCampana
        ? '/visibility2/app/gestionarPruebas.php'
          + '?idCampana=' + encodeURIComponent(idCampana)
          + '&nombreCampana=' + encodeURIComponent(r.nombre_campana || '')
          + '&idLocal=' + encodeURIComponent(r.id_local)
          + '&idUsuario=' + encodeURIComponent(<?php echo (int)$usuario_id; ?>)
        : 'javascript:void(0)';

      const a = document.createElement('a');
      a.className='route-card';
      a.href = href;
      if (!idCampana) { a.classList.add('disabled'); a.style.pointerEvents='none'; }

      a.innerHTML = `
        <h4 class="route-card__title">${r.nombre_local || ('Local #'+r.id_local)}</h4>
        <p class="route-card__subtitle">${[r.direccion, r.comuna].filter(Boolean).join(' · ')}</p>
        <div class="route-card__meta">
          <span class="chip chip--${r.estado === 'reagendado' ? 'reagendado':'programado'}">
            <i class="fa fa-calendar"></i>${r.estado || 'programado'}
          </span>
          ${r.hoy ? '<span class="chip chip--hoy"><i class="fa fa-bolt"></i>Hoy</span>':''}
          <span class="chip chip--teal"><span class="badge-dot badge-dot--cached"></span>cache</span>
        </div>`;
      list.appendChild(a);
    });
  }

  async function renderRouteFromIDB(){
    try{
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();
      const programados = await V2DB.route.getAll('programados');
      const reagendados = await V2DB.route.getAll('reagendados');
      paintList('#routeProgramados', programados);
      paintList('#routeReagendados', reagendados);
      const meta = await V2DB.meta.get('lastSync');
      const el = document.getElementById('lastSyncBadge');
      if (el) el.textContent = meta?.value || '-';
    }catch(e){ console.warn('renderRouteFromIDB error:', e); }
  }

  // --- 3) Sembrado inicial (tus datos PHP del render actual → IDB) ---
  async function seedFromPHP(){
    try{
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();

      // PHP → JS
      const phpProgramados = <?php echo json_encode($locales, JSON_UNESCAPED_UNICODE); ?>;
      const phpReagendados = <?php echo json_encode($locales_reag, JSON_UNESCAPED_UNICODE); ?>;

      // Normalización mínima al formato esperado por IDB
      const norm = (rows, estado) => (rows||[]).map(r=>({
        id_local: r.idLocal,
        nombre_local: r.nombreLocal || (r.cadena ? (r.cadena+' - '+(r.direccionLocal||'')) : ''),
        direccion: r.direccionLocal || '',
        comuna: r.comuna || '',
        lat: r.latitud, lng: r.lng,
        campanasIds: r.campanasIds || [],
        estado: estado,
        hoy: r.fechaPropuesta === (new Date().toISOString().slice(0,10))
      }));

      const prog = norm(phpProgramados, 'programado');
      const reag = norm(phpReagendados, 'reagendado');

      // Insert/merge en IDB (no borra si ya existe; deja que sync los reemplace)
      if (prog.length) await V2DB.route.putMany('programados', prog);
      if (reag.length) await V2DB.route.putMany('reagendados', reag);
      await V2DB.meta.set('lastSync', new Date().toLocaleString('es-CL'));

    }catch(e){ console.warn('seedFromPHP error:', e); }
  }

  // --- 4) Sincronización con servidor (cuando hay red) ---
  async function syncBundle(){
    if(!navigator.onLine) return;
    try{
      const res = await fetch('/visibility2/app/api/sync_bundle.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({
          empresa_id: <?php echo (int)$empresa_id; ?>,
          usuario_id: <?php echo (int)$usuario_id; ?>
        })
      });
      if(!res.ok) throw new Error('HTTP '+res.status);
      const bundle = await res.json();
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();

      await V2DB.tx(async t=>{
        await V2DB.route.clear('programados');
        await V2DB.route.clear('reagendados');
        await V2DB.route.putMany('programados', bundle.route?.programados || []);
        await V2DB.route.putMany('reagendados', bundle.route?.reagendados || []);
        // opcional: guardar otros catálogos si tu db.js los expone (locales, campanas, materiales, preguntas)
        await V2DB.meta.set('lastSync', new Date().toLocaleString('es-CL'));
      });

      await renderRouteFromIDB();
    }catch(e){ console.warn('sync_bundle falló:', e); }
  }

  // --- 5) Arranque ---
  (async function init(){
    // 1) Pintamos lo último guardado (si hubiese)
    await renderRouteFromIDB();
    // 2) Sembramos con lo que ya vino del server en este render
    await seedFromPHP();
    await renderRouteFromIDB();
    // 3) Si hay red, sincronizamos contra API (y repintamos)
    await syncBundle();
  })();

  // Re-sincroniza cuando vuelve la conectividad
  window.addEventListener('online', ()=>{ setNetBadge(); syncBundle(); });

})();

</script>

<!-- OFFLINE-FIRST: registro de Service Worker -->
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/visibility2/app/sw.js', { scope: '/visibility2/app/' })
    .then(function(reg){
      // Forzar activación inmediata de la nueva versión
      if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      reg.addEventListener('updatefound', function(){
        var nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', function(){
          if (nw.state === 'installed' && navigator.serviceWorker.controller) {
            reg.waiting && reg.waiting.postMessage({ type: 'SKIP_WAITING' });
          }
        });
      });
    })
    .catch(function(err){ console.error('SW register error:', err); });
}
</script>



</body>
</html>
<?php
$conn->close();
?>
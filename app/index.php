<?php
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Datos de sesion
$nombre     = htmlspecialchars($_SESSION['usuario_nombre'], ENT_QUOTES, 'UTF-8');
$apellido   = htmlspecialchars($_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8');
$empresa_id = intval($_SESSION['empresa_id']);
$usuario_id = intval($_SESSION['usuario_id']);
$division_id = intval($_SESSION['division_id']); 

// 1) Campañas programadas
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

// 2) Actividades complementarias
if ($usuario_id === 2) {
  // Usuario 2 ve todo (incluida 2037)
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
  // Resto de usuarios: ocultar id=2037
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

// 3) Locales Programados: aquellos con countVisita = 0 (no visitados)
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
LEFT JOIN vendedor   v ON v.id        = l.id_vendedor
LEFT JOIN comuna     co ON co.id       = l.id_comuna
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

// 4) Locales Reagendados: donde fq.pregunta = 'en proceso' y observacion contenga 'sin_material' o 'no_permitieron'
$sql_reagendados = "
SELECT
    IFNULL(DATE(fq.fechaPropuesta), CURDATE())                         AS fechaPropuesta,
    l.codigo                                                           AS codigoLocal,
    c.nombre                                                           AS cadena,
    l.direccion                                                        AS direccionLocal,
    l.nombre                                                           AS nombreLocal,
    IFNULL(v.nombre_vendedor, '')                                       AS vendedor,
    l.id                                                               AS idLocal,
    l.lat                                                              AS latitud,
    l.lng                                                              AS lng,
    -- Solo contamos las campañas que cumplan la condición
    COUNT(DISTINCT f.id)                                              AS totalCampanas,
    GROUP_CONCAT(DISTINCT f.id)                                       AS campanasIds,
    MAX(fq.is_priority)                                               AS is_priority
FROM formularioQuestion fq
INNER JOIN formulario   f ON f.id = fq.id_formulario
INNER JOIN local        l ON l.id = fq.id_local
INNER JOIN cadena       c ON c.id = l.id_cadena
LEFT JOIN vendedor     v ON v.id = l.id_vendedor
WHERE fq.id_usuario = ?
  AND f.id_empresa  = ?
  AND f.tipo        IN (3,1)
  AND f.estado      = 1
  -- Aquí filtramos SOLO los registros 'reagendados'
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

// Agrupar locales reagendados por fecha
$locales_reag_por_dia = [];
foreach ($locales_reag as $local) {
    $fecha = $local['fechaPropuesta'];
    if (!isset($locales_reag_por_dia[$fecha])) {
        $locales_reag_por_dia[$fecha] = [];
    }
    $locales_reag_por_dia[$fecha][] = $local;
}

// Preparar datos para el mapa: dos arrays distintos
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
    <style>
    
    @media (max-width: 480px) {
  .table > thead > tr > th,
  .table > tbody > tr > td {
    padding: 4px 4px;
    font-size: 0.9rem;
  }
}
    
        #filtroLocalesProg, #filtroLocalesReag {
      width: 100%;
    }
        
      #success-alert {
          position: fixed;
          top: 60px;
          left: 0;
          width: 100%;
          z-index: 9999;
          margin: 0;
          text-align: center;
          padding: 10px;
      }
      @media (max-width: 768px) {
          #success-alert {
              font-size: 1em;
          }
      }
      .completed .desc {
          text-decoration: line-through;
          color: gray;
      }
      .circulo {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 30px;
          height: 30px;
          background-color: #28a745;
          color: #fff;
          border-radius: 50%;
          font-weight: bold;
          font-size: 14px;
      }
      #panelInstruccionesModal {
          position: absolute;
          top: 60px;
          right: 10px;
          width: 300px;
          max-height: 80%;
          overflow-y: auto;
          background-color: rgba(255, 255, 255, 0.95);
          padding: 15px;
          border-radius: 5px;
          box-shadow: 0 0 10px rgba(0,0,0,0.1);
          z-index: 1000;
          transition: all 0.3s ease;
          height: 40px;
      }
      #panelInstruccionesModal.expanded {
          height: 500px;
      }
      #panelInstruccionesModal .panel-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          cursor: pointer;
      }
      #panelInstruccionesModal .toggle-button {
          background: none;
          border: none;
          font-size: 16px;
          cursor: pointer;
      }
      @media (max-width: 768px) {
          #panelInfoRuta {
              position: fixed;
              bottom: 10px;
              left: 50%;
              transform: translateX(-50%);
              width: 90%;
              max-width: 400px;
              z-index: 1001;
          }
          #panelInstruccionesModal {
              right: 50%;
              transform: translateX(50%);
              width: 90%;
              left: 5%;
              top: auto;
              bottom: 60px;
          }
      }
      .custom-map-control-button {
          background-color: #fff;
          border: 2px solid #fff;
          border-radius: 3px;
          box-shadow: 0 2px 6px rgba(0,0,0,0.3);
          cursor: pointer;
          margin: 10px;
          padding: 10px;
          font-size: 16px;
          font-family: 'Roboto, Arial, sans-serif';
          display: flex;
          align-items: center;
          transition: background-color 0.3s;
      }
      .custom-map-control-button:hover {
          background-color: #e6e6e6;
      }
      #loadingIndicator {
          position: absolute;
          top: 10px;
          left: 50%;
          transform: translateX(-50%);
          background-color: rgba(255, 255, 255, 0.8);
          padding: 5px 10px;
          border-radius: 3px;
          display: none;
          z-index: 1001;
      }
      .visitado {
          background-color: #d4edda !important;
      }
      .priority-row {
          background-color: #fff3cd !important;
      }
      .priority-icon {
          color: #ff9800;
          margin-right: 5px;
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
            <button type="button" class="btn btn-info" style="margin-left: 3.5%;" onclick="window.location.reload();">
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
                <small>Tabla: <span id="countTabla">0</span> | Mapa: <span id="countMapa">0</span></small>
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
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            class="<?php echo $trClass; ?>">
                                                <td class="center">
                                                    <?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                            <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo $row['comuna']; ?></td>
                                            <td><?php echo htmlspecialchars($direccionLocal, ENT_QUOTES, 'UTF-8'); ?></td>
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

         <!-- Panel de Locales Reagendados (inicialmente oculto) -->
         <div id="panelReagendados" style="display:none;">
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
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            class="<?php echo $trClass; ?>">
                                             <td class="center">
                                                <?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($direccionLocal, ENT_QUOTES, 'UTF-8'); ?></td>
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
        // base de gestión según usuario
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
      </div><!-- /.container -->
   </div><!-- /.main-content -->
</div><!-- /.main-container -->

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
            <div id="panelInfoRuta" style="background: rgba(255,255,255,0.95); padding: 10px; border-radius: 5px; z-index: 1001;">
<!--                
               <p><strong>Distancia Total:</strong> <span id="distanciaTotal">0 km</span></p>
               <p><strong>Tiempo Estimado:</strong> <span id="duracionEstimada">0 min</span></p>
               <button id="btnIniciarRuta" class="btn btn-success">Iniciar Ruta</button>
               <button id="btnDetenerRuta" class="btn btn-danger" style="display: none; margin-top: 10px;">Detener Ruta</button> -->
            </div>
            <div id="infoRuta" style="margin-top: 20px; display: none;">
               <h5>Detalles de la Ruta:</h5>
               <p><strong>Distancia Total:</strong> <span id="distanciaTotalRuta"></span></p>
               <p><strong>Duración Estimada:</strong> <span id="duracionEstimadaRuta"></span></p>
            </div>
            <div id="panelInstruccionesModal" class="minimized" hidden>
               <div class="panel-header">
                  <span>Instrucciones de Navegación</span>
                  <button class="toggle-button"><i class="fa fa-chevron-down"></i></button>
               </div>
               <ul id="listaInstrucciones"></ul>
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
// Para generar los modals de gestión, combinamos los locales programados y reagendados
$localesTotales = array_merge($locales, $locales_reag);
// Para evitar duplicados por idLocal
$idsGenerados = [];
foreach ($localesTotales as $row) {
    if (in_array($row['idLocal'], $idsGenerados)) {
        continue;
    }
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
  WHERE fq.id_usuario    = ?
    AND fq.id_local      = ?
    AND f.id_empresa     = ?
    AND f.tipo IN (3,1)
    AND f.estado = 1
    -- aquí incluimos ambos casos:
    AND ( fq.countVisita = 0
       OR fq.pregunta   = 'en proceso'
    )
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
            echo "
                <tr data-idcampana='{$idCampana}'>
                    <td>{$nombreCampana}</td>
                    <td class='center'>
                      <a href='gestionar.php?idCampana=" . urlencode($idCampana) . "&nombreCampana=" . urlencode($nombreCampana) . "&idLocal=" . urlencode($idLocal) . "&idUsuario=" . urlencode($usuario_id) . "'
                         class='btn btn-info btn-sm'>
                        <i class='fa fa-pencil'></i> Gestionar
                      </a>
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script>
// Debounce
function debounce(func, delay) {
    let timeout;
    return function() {
        const context = this;
        const args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

window.ultimaPosicionCentrada = null;

function calcularDistancia(lat1, lng1, lat2, lng2) {
    function toRad(x) { return x * Math.PI / 180; }
    let R = 6371;
    let dLat = toRad(lat2 - lat1);
    let dLng = toRad(lng2 - lng1);
    let a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
    let c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function debeCentrarMapa(nuevaPosicion, umbral_km = 0.1) {
    if (!window.ultimaPosicionCentrada) {
        window.ultimaPosicionCentrada = nuevaPosicion;
        return true;
    }
    let distancia = calcularDistancia(
        window.ultimaPosicionCentrada.lat,
        window.ultimaPosicionCentrada.lng,
        nuevaPosicion.lat,
        nuevaPosicion.lng
    );
    if (distancia > umbral_km) {
        window.ultimaPosicionCentrada = nuevaPosicion;
        return true;
    }
    return false;
}

function estaEnRuta(ubicacionActual, ruta) {
    if (!ruta || !ruta.routes || ruta.routes.length === 0) return false;
    let polyline = new google.maps.Polyline({ path: ruta.routes[0].overview_path });
    let lat = parseFloat(ubicacionActual.lat), lng = parseFloat(ubicacionActual.lng);
    if (isNaN(lat) || isNaN(lng)) {
        console.error('ubicacionActual no es válido:', ubicacionActual);
        return false;
    }
    let punto = new google.maps.LatLng(lat, lng);
    let distanciaMinima = google.maps.geometry.spherical.computeDistanceBetween(punto, polyline.getPath());
    return distanciaMinima < 50;
}

// Variables para marcadores separados
window.markersProg = {};
window.markersReag = {};
window.rutaActual = null;
window.ultimaRuta = null;

window.initMap = function() {
    let usarRutas = false;
    let coordenadasProg = <?php echo json_encode($coordenadas_locales_programados); ?>;
    let coordenadasReag = <?php echo json_encode($coordenadas_locales_reag); ?>;
    window.mapa = new google.maps.Map(document.getElementById('map'), {
        zoom: 12,
        center: { lat: -33.4489, lng: -70.6693 }
    });
    window.directionsService = new google.maps.DirectionsService();
    window.directionsRenderer = new google.maps.DirectionsRenderer({
        suppressMarkers: true,
        preserveViewport: true,
        polylineOptions: {
            strokeColor: '#FF0000',
            strokeOpacity: 0.7,
            strokeWeight: 5
        }
    });
    window.directionsRenderer.setMap(window.mapa);

    // Crear marcadores para programados
    coordenadasProg.forEach(function(local) {
        let iconUrl = 'assets/images/marker_red1.png';
        if(local.markerColor === 'blue') iconUrl = 'assets/images/marker_blue1.png';
        let position = { lat: local.latitud, lng: local.lng };
        let marker = new google.maps.Marker({
            position: position,
            map: window.mapa,
            title: local.nombre_local,
            icon: {
                url: iconUrl,
                scaledSize: new google.maps.Size(30, 30)
            }
        });
        let contentString = '<div style="min-width:180px;">' +
                            '<strong>' + local.nombre_local + '</strong><br><br>' +
                            '<button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#responsive' + local.idLocal + '"><i class="fa fa-cog"></i> Gestionar Local</button>' +
                            '</div>';
        let infoWindow = new google.maps.InfoWindow({ content: contentString });
        marker.addListener('click', function() {
            infoWindow.open(window.mapa, marker);
        });
        window.markersProg[local.idLocal] = {
            marker: marker,
            fechaPropuesta: local.fechaPropuesta
        };
    });

    // Crear marcadores para reagendados
    coordenadasReag.forEach(function(local) {
        let iconUrl = 'assets/images/marker_blue1.png';
        let position = { lat: local.latitud, lng: local.lng };
        let marker = new google.maps.Marker({
            position: position,
            map: window.mapa,
            title: local.nombre_local,
            icon: {
                url: iconUrl,
                scaledSize: new google.maps.Size(30, 30)
            }
        });
        let contentString = '<div style="min-width:180px;">' +
                            '<strong>' + local.nombre_local + '</strong><br><br>' +
                            '<button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#responsive' + local.idLocal + '"><i class="fa fa-cog"></i> Gestionar Local</button>' +
                            '</div>';
        let infoWindow = new google.maps.InfoWindow({ content: contentString });
        marker.addListener('click', function() {
            infoWindow.open(window.mapa, marker);
        });
        window.markersReag[local.idLocal] = {
            marker: marker,
            fechaPropuesta: local.fechaPropuesta
        };
    });

    // Por defecto, establecer modo programados y ocultar marcadores de reagendados
    window.modoLocal = 'prog';
    for (let id in window.markersReag) {
        if (window.markersReag.hasOwnProperty(id)) {
            window.markersReag[id].marker.setMap(null);
        }
    }

    window.ejecutorMarker = new google.maps.Marker({
        position: { lat: -33.4489, lng: -70.6693 },
        map: window.mapa,
        title: 'Tu Ubicación Actual',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 8,
            fillColor: '#4285F4',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2
        }
    });

    let botonCentrarUbicacion = crearBotonCentrarUbicacion(window.mapa);
    window.mapa.controls[google.maps.ControlPosition.TOP_RIGHT].push(botonCentrarUbicacion);

    window.calcularRutaOptima = function(origen) {
        if (!usarRutas) {
            console.log('⚠️ Directions API está DESACTIVADA (usarRutas = false)');
            return;
        }
        let waypoints = [];
        if (window.modoLocal === 'prog') {
            $('#localesProgCollapse table[data-fechaTabla]:visible tbody tr').each(function() {
                let lat = parseFloat($(this).attr('data-lat'));
                let lng = parseFloat($(this).attr('data-lng'));
                if (!isNaN(lat) && !isNaN(lng)) {
                    waypoints.push({ location: { lat: lat, lng: lng }, stopover: true });
                }
            });
        } else if (window.modoLocal === 'reag') {
            $('#localesReagCollapse table[data-fechaTabla]:visible tbody tr').each(function() {
                let lat = parseFloat($(this).attr('data-lat'));
                let lng = parseFloat($(this).attr('data-lng'));
                if (!isNaN(lat) && !isNaN(lng)) {
                    waypoints.push({ location: { lat: lat, lng: lng }, stopover: true });
                }
            });
        }
        if (waypoints.length === 0) {
            window.directionsRenderer.set('directions', null);
            $('#infoRuta').hide();
            $('#panelInstruccionesModal').removeClass('expanded').addClass('minimized');
            window.ultimaRuta = null;
            window.rutaActual = null;
            return;
        }
        let request = {
            origin: origen,
            destination: origen,
            waypoints: waypoints,
            optimizeWaypoints: true,
            travelMode: google.maps.TravelMode.DRIVING
        };
        window.directionsService.route(request, function(result, status) {
            if (status === google.maps.DirectionsStatus.OK) {
                window.rutaActual = result;
                let nuevaRuta = JSON.stringify(result);
                if (window.ultimaRuta === nuevaRuta) {
                    return;
                }
                window.directionsRenderer.setDirections(result);
                window.ultimaRuta = nuevaRuta;
                let route = result.routes[0];
                let totalDistancia = 0;
                let totalDuracion  = 0;
                route.legs.forEach(function(leg) {
                    totalDistancia += leg.distance.value;
                    totalDuracion  += leg.duration.value;
                });
                $('#distanciaTotal').text((totalDistancia / 1000).toFixed(2) + ' km');
                $('#duracionEstimada').text(Math.floor(totalDuracion / 60) + ' min');
                $('#infoRuta').show();
                generarInstrucciones(route);
            } else if (status === google.maps.DirectionsStatus.OVER_QUERY_LIMIT) {
                console.warn('Límite de consultas de Directions API excedido.');
                alert('Demasiadas solicitudes de ruta. Inténtalo de nuevo más tarde.');
            }
        });
    };

    window.debouncedCalcularRutaOptima = debounce(window.calcularRutaOptima, 1000);

    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(
            function (position) {
                let ubicacionActual = { lat: position.coords.latitude, lng: position.coords.longitude };
                window.ejecutorMarker.setPosition(ubicacionActual);
                window.ejecutorMarker.setMap(window.mapa);
                if (debeCentrarMapa(ubicacionActual)) {
                    window.mapa.panTo(ubicacionActual);
                }
                if (window.rutaActual && !estaEnRuta(ubicacionActual, window.rutaActual)) {
                    window.calcularRutaOptima(ubicacionActual);
                } else {
                    window.debouncedCalcularRutaOptima(ubicacionActual);
                }
            },
            function (error) {
                console.error('Error al obtener la ubicación:', error);
                alert('No se pudo obtener tu ubicación.');
            },
            {
                enableHighAccuracy: true,
                maximumAge: 0,
                timeout: 10000
            }
        );
    } else {
        alert('Tu navegador no soporta geolocalización.');
    }

    $('#modalMapa').on('shown.bs.modal', function () {
        google.maps.event.trigger(window.mapa, 'resize');
    });
    

   
   
    // Por defecto, modo programados
    
    setTimeout(function(){
        $('#filtroFechaProg').trigger('change');
    }, 500);
};

function applyFilters() {
  const modo       = window.modoLocal;                       // 'prog' o 'reag'
  const selId      = modo === 'prog' ? '#filtroFechaProg' : '#filtroFechaReag';
  const container  = modo === 'prog' ? '#localesProgCollapse' : '#localesReagCollapse';

  // 1) Campañas tachadas
  const tachadas = $('.todo .completed')
    .map((i, li) => String($(li).data('idcampana')))
    .get();

  // 2) Recalcular OK/NO-OK para **todas** las filas de **todas** las fechas
  const fechasOk = {};
  $(`${container} table[data-fechaTabla]`).each(function() {
    const fecha = $(this).attr('data-fechaTabla');
    let tieneOk = false;

    $(this).find('tbody tr').each(function() {
      const camps = String($(this).data('campanas')||'').split(',');
      const ok    = camps.some(c => !tachadas.includes(c));
      $(this).data('ok', ok);     // ← persistimos el estado
      if (ok) tieneOk = true;
    });

    fechasOk[fecha] = tieneOk;
  });

  // 3) Reconstruir select de fecha con solo las fechas válidas
  const $sel = $(selId);
  const previo = $sel.val();
  $sel.empty();
  Object.keys(fechasOk).forEach(fecha => {
    if (!fechasOk[fecha]) return;
    const [y,m,d] = fecha.split('-');
    $sel.append(`<option value="${fecha}">${d}-${m}-${y}</option>`);
  });
  // intentar mantener selección anterior; si ya no existe, tomar la primera
  if (previo && fechasOk[previo]) $sel.val(previo);
  if (!$sel.val()) $sel.val($sel.find('option:first').val());

  const fechaSel = $sel.val();
  if (!fechaSel) {
    // No hay fechas válidas: ocultar todo y limpiar mapa/contadores
    $(`${container} h4[data-fechaencabezado], ${container} table[data-fechaTabla]`).hide();
    const markers = modo === 'prog' ? window.markersProg : window.markersReag;
    Object.values(markers).forEach(m => m.marker.setMap(null));
    $('#countTabla').text(0); $('#countMapa').text(0);
    return;
  }

  // 4) Mostrar solo la fecha seleccionada
  $(`${container} h4[data-fechaencabezado], ${container} table[data-fechaTabla]`).hide();
  const $tablaSel = $(`${container} table[data-fechaTabla="${fechaSel}"]`).show();
  $(`${container} h4[data-fechaencabezado="${fechaSel}"]`).show();

  // 5) Dentro de la fecha seleccionada, mostrar solo filas OK
  $tablaSel.find('tbody tr').each(function () {
    $(this).toggle( !!$(this).data('ok') );
  });

  // 6) Actualizar marcadores: fecha Y OK
  const markers = modo === 'prog' ? window.markersProg : window.markersReag;
  Object.entries(markers).forEach(([id, m]) => {
    const sameFecha = m.fechaPropuesta === fechaSel;
    const filaOK = $tablaSel.find(`tbody tr[data-idlocal="${id}"]`).data('ok');
    m.marker.setMap( (sameFecha && filaOK) ? window.mapa : null );
  });

  // 7) Contadores y ruta
  const countTabla = $tablaSel.find('tbody tr:visible').length;
  const countMapa  = Object.values(markers).filter(m => m.marker.getMap() !== null).length;
  $('#countTabla').text(countTabla);
  $('#countMapa').text(countMapa);

  if (window.debouncedCalcularRutaOptima && window.ejecutorMarker) {
    const pos = window.ejecutorMarker.getPosition();
    if (pos) window.debouncedCalcularRutaOptima(pos.toJSON());
  }
}


function generarInstrucciones(route) {
    let lista = $('#listaInstrucciones');
    lista.empty();
    route.legs.forEach(function(leg, index) {
        leg.steps.forEach(function(step, stepIndex) {
            let instruction = step.instructions.replace(/<[^>]+>/g, '');
            let distancia   = step.distance.text;
            let duracion    = step.duration.text;
            lista.append('<li><strong>' + (index + 1) + '.' + (stepIndex + 1) + ':</strong> ' +
                         instruction + '<br><strong>Distancia:</strong> ' + distancia +
                         ', <strong>Duración:</strong> ' + duracion + '</li>');
        });
    });
    let $panel = $('#panelInstruccionesModal');
    if (!$panel.hasClass('expanded')) {
        $panel.removeClass('minimized').addClass('expanded');
        $panel.find('.toggle-button i').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    }
}

function crearBotonCentrarUbicacion(map) {
    let controlDiv = document.createElement('div');
    let controlUI  = document.createElement('div');
    controlUI.className = 'custom-map-control-button';
    controlUI.title     = 'Centrar en tu ubicación actual';
    controlDiv.appendChild(controlUI);
    let controlText = document.createElement('div');
    controlText.innerHTML = '📍 Centrar Ubicación';
    controlUI.appendChild(controlText);
    controlUI.addEventListener('click', function() {
        $('#loadingIndicator').show();
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                let ubicacionActual = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                window.ejecutorMarker.setPosition(ubicacionActual);
                window.ejecutorMarker.setMap(window.mapa);
                window.mapa.setCenter(ubicacionActual);
                window.mapa.setZoom(15);
                if (typeof window.debouncedCalcularRutaOptima === 'function') {
                    window.debouncedCalcularRutaOptima(ubicacionActual);
                }
                $('#loadingIndicator').hide();
            }, function(error) {
                console.error('Error al obtener la ubicación:', error);
                alert('No se pudo centrar la ubicación.');
                $('#loadingIndicator').hide();
            }, {
                enableHighAccuracy: true,
                maximumAge: 0,
                timeout: 10000
            });
        } else {
            alert('Tu navegador no soporta geolocalización.');
        }
    });
    return controlDiv;
}

$(document).ready(function(){
    
    setTimeout(function(){
      $('#success-alert').fadeOut('slow');
    }, 3000);
    
    
$('#filtroFechaProg, #filtroFechaReag')
  .off('change')
  .on('change', function () {
    // Si hay texto en el filtro de búsqueda, preferimos ese flujo
    if (window.modoLocal === 'prog') {
      const q = ($('#filtroLocalesProg').val() || '').trim();
      if (q) { filtrarLocalesTabla('prog'); return; }
    } else {
      const q = ($('#filtroLocalesReag').val() || '').trim();
      if (q) { filtrarLocalesTabla('reag'); return; }
    }
    applyFilters(); // ← único lugar donde cambiamos tablas + marcadores + contadores
  });

  $(document).on('click', '.todo-actions', function(){
    const $li = $(this).closest('li'),
          $i  = $li.find('i');
    $li.toggleClass('completed');
    $i.toggleClass('fa-square-o fa-check-square');
    applyFilters();
  });

  // 3) Al cambiar de panel:
  $('#btnVerReagendados').click(function(){
    window.modoLocal = 'reag';
    $('#panelProgramados').hide();
    $('#panelReagendados').show();
    applyFilters();
  });
  $('#btnVerProgramados').click(function(){
    window.modoLocal = 'prog';
    $('#panelReagendados').hide();
    $('#panelProgramados').show();
    applyFilters();
  });

  // 4) Inicialización
  window.modoLocal = 'prog';
  setTimeout(applyFilters, 500);
});


function updateCounts() {
  // Cuenta todas las filas visibles en todas las tablas de la vista activa
  let panelSelector = window.modoLocal === 'prog'
    ? '#localesProgCollapse'
    : '#localesReagCollapse';

  let countTabla = $(
    panelSelector + ' table[data-fechaTabla] tbody tr:visible'
  ).length;
  $('#countTabla').text(countTabla);

  // Cuenta los marcadores visibles en el mapa (sin importar fecha)
  let markers = window.modoLocal === 'prog'
    ? window.markersProg
    : window.markersReag;

  let countMapa = Object.values(markers).filter(m => (
    m.marker.getMap() !== null
  )).length;
  $('#countMapa').text(countMapa);
}



// Modifica tu updateLocales para que al final dispare también el recuento
window.updateLocales = function() {
  // … tu lógica actual de tachar campañas …

  // Después de actualizar tabla y marcadores:
  updateCounts();

  // Y si recalculas ruta, lo mantienes sincronizado:
  if (typeof window.debouncedCalcularRutaOptima === 'function' && window.ejecutorMarker) {
    let posicion = window.ejecutorMarker.getPosition();
    if (posicion) window.debouncedCalcularRutaOptima(posicion.toJSON());
  }
};



function filtrarLocalesTabla(modo) {
  // modo = 'prog' o 'reag'
  const filtro = (modo === 'prog')
      ? ($('#filtroLocalesProg').val() || '').toLowerCase()
      : ($('#filtroLocalesReag').val() || '').toLowerCase();

  // Si no hay texto en el buscador, delega SIEMPRE en applyFilters()
  // para que se apliquen las campañas tachadas + fecha + marcadores.
  if (!filtro) { 
    applyFilters(); 
    return; 
  }

  const container = (modo === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const $fechasEncabezado = $(`${container} h4[data-fechaencabezado]`);
  const $tablas = $(`${container} table[data-fechaTabla]`);

  // campañas tachadas
  const tachadas = $('.todo .completed')
    .map((i, li) => String($(li).data('idcampana')))
    .get();

  // Oculta todo por defecto
  $fechasEncabezado.hide();
  $tablas.hide();

  // Recorremos TODAS las fechas y, por fila, exigimos:
  //  - que NO estén todas sus campañas tachadas (ok=true)
  //  - que el texto coincida con el filtro
  let hayAlgunaVisible = 0;

  $tablas.each(function(){
    let visiblesEnEstaTabla = 0;
    const $tbodyRows = $(this).find('tbody tr');

    $tbodyRows.each(function(){
      const $tds = $(this).find('td');

      const codigo    = ($tds.eq(0).text() || '').toLowerCase();
      const cadena    = ($tds.eq(1).text() || '').toLowerCase();
      const comuna    = ($tds.eq(2).text() || '').toLowerCase();
      const direccion = ($tds.eq(3).text() || '').toLowerCase();

      const campanas = String($(this).data('campanas') || '').split(',');
      const okCamp   = campanas.some(c => !tachadas.includes(c)); // ← respeta tachadas

      const matchTxt = (
        codigo.includes(filtro) ||
        cadena.includes(filtro) ||
        comuna.includes(filtro) ||
        direccion.includes(filtro)
      );

      const visible = okCamp && matchTxt;
      $(this).toggle(visible);
      if (visible) { visiblesEnEstaTabla++; hayAlgunaVisible++; }
    });

    if (visiblesEnEstaTabla > 0) {
      const fecha = $(this).attr('data-fechaTabla');
      $(`${container} h4[data-fechaencabezado="${fecha}"]`).show();
      $(this).show();
    }
  });

  // Actualiza marcadores SOLO para las filas visibles del modo activo
  const markers = (modo === 'prog') ? window.markersProg : window.markersReag;
  Object.entries(markers).forEach(([id, m]) => {
    const visible = $(`${container} table[data-fechaTabla] tbody tr[data-idlocal="${id}"]:visible`).length > 0;
    m.marker.setMap(visible ? window.mapa : null);
  });

  updateCounts();
}
// Conecta el input a la función de filtrado (usa debounce para eficiencia)
$('#filtroLocalesProg').on('input', debounce(function() {
    filtrarLocalesTabla('prog');
}, 200));

$('#filtroLocalesReag').on('input', debounce(function() {
    filtrarLocalesTabla('reag');
}, 200));



</script>

<!-- Google Maps API -->
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAkWMIwHuWxwVkC-1Tk208gNRUBbwqZYIQ&&callback=initMap&libraries=geometry"
    onerror="alert('Error al cargar Google Maps. Verifica tu conexión o la clave de API.')">
</script>
</body>
</html>
<?php
$conn->close();
?>

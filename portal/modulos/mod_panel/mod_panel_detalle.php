<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$id_coordinador = intval($_SESSION['usuario_id']);
$nombreCoord    = $_SESSION['usuario_nombre'];
$apellidoCoord  = $_SESSION['usuario_apellido'];
$id_perfil      = intval($_SESSION['usuario_perfil']);
$id_division    = intval($_SESSION['division_id']);
$id_empresa     = intval($_SESSION['empresa_id']);



// Verificar GET param
if (!isset($_GET['id_ejecutor'])) {
    die("Parámetro inválido. Falta id_ejecutor.");
}
$id_ejecutor = intval($_GET['id_ejecutor']);

// Validar que el ejecutor pertenece a la misma empresa/división
$sql_valida = "
    SELECT COUNT(*) AS cnt
    FROM usuario
    WHERE id = ?
      AND id_perfil = 3
      AND id_division = ?
      AND id_empresa = ?
";
$stmt_val = $conn->prepare($sql_valida);
if (!$stmt_val) {
    die("Error en la preparación de la validación: " . htmlspecialchars($conn->error));
}
$stmt_val->bind_param("iii", $id_ejecutor, $id_division, $id_empresa);
$stmt_val->execute();
$res_val = $stmt_val->get_result();
$row_val = $res_val->fetch_assoc();
$stmt_val->close();

if ($row_val['cnt'] == 0) {
    die("El ejecutor no pertenece a tu división o no existe.");
}

// Consultar nombre del ejecutor para mostrar en la cabecera
$sql_nombre_ejecutor = "SELECT nombre, apellido FROM usuario WHERE id = ?";
$stmt_n = $conn->prepare($sql_nombre_ejecutor);
$stmt_n->bind_param("i", $id_ejecutor);
$stmt_n->execute();
$res_n = $stmt_n->get_result();
$rowN = $res_n->fetch_assoc();
$stmt_n->close();
$nombreEjec = $rowN ? $rowN['nombre'].' '.$rowN['apellido'] : 'Ejecutor desconocido';

// Consulta de campañas y su avance (pendientes, completados, cancelados)
$sql_detalle = "
   SELECT 
     f.id AS id_campana,
     f.nombre AS nombre_campana,
     COUNT(DISTINCT fq.id_local) AS total_locales,
     SUM(CASE WHEN fq.estado = 1 THEN 1 ELSE 0 END) AS completados,
     SUM(CASE WHEN fq.estado = 0 THEN 1 ELSE 0 END) AS pendientes,
     SUM(CASE WHEN fq.estado = 2 THEN 1 ELSE 0 END) AS cancelados,
     MIN(f.fechaInicio) AS fecha_inicio,
     MAX(f.fechaTermino) AS fecha_termino
   FROM formularioQuestion fq
   INNER JOIN formulario f ON f.id = fq.id_formulario
   WHERE fq.id_usuario = ?
     AND f.id_empresa = ?
   GROUP BY f.id, f.nombre
   ORDER BY f.fechaInicio DESC
";
$stmt_det = $conn->prepare($sql_detalle);
if (!$stmt_det) {
    die("Error en la preparación de la consulta de detalle: " . htmlspecialchars($conn->error));
}
$stmt_det->bind_param("ii", $id_ejecutor, $id_empresa);
$stmt_det->execute();
$res_det = $stmt_det->get_result();

$campanias = [];
while ($r = $res_det->fetch_assoc()) {
    $campanias[] = $r;
}
$stmt_det->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Detalle del Ejecutor</title>
  <!-- Bootstrap 4 (CDN) -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- FontAwesome para íconos -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <style>
    body {
      background-color: #f4f6f9; 
    }
    .card-panel {
      margin-top: 30px;
    }
    .panel-header {
      background: #17a2b8;
      color: #fff;
      padding: 15px;
      border-radius: 3px 3px 0 0;
    }
    h1.panel-title {
      margin: 0;
      font-size: 24px;
    }
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
    .table th {
      background: #f1f1f1;
    }
    /* Botón para ver todos los locales directamente */
    .btn-ver-todos {
      margin-bottom: 15px;
    }
  </style>
</head>
<body>
<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title">
      <i class="fas fa-user-cog"></i>
      Detalle de Campañas - Ejecutor
    </h1>
    <p class="mb-0">Coordinador: <?php echo htmlspecialchars($nombreCoord.' '.$apellidoCoord); ?></p>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h4>Campañas para: <?php echo htmlspecialchars($nombreEjec); ?></h4>
      <hr>
      <!-- Nuevo: Botón para ver TODOS los locales asignados al ejecutor directamente -->
      <div class="mb-3" >
        <a href="mod_panel_detalle_locales.php?id_ejecutor=<?php echo $id_ejecutor; ?>&ver=todos" class="btn btn-warning btn-ver-todos">
          <i class="fas fa-globe" ></i> Ver TODOS los locales asignados
        </a>
      </div>

      <?php if (count($campanias) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-bordered">
            <thead>
              <tr>
                <th>Campaña</th>
                <th>Fecha Inicio</th>
                <th>Fecha Término</th>
                <th>Locales</th>
                <th>Implementaciones Pendientes</th>
                <th>Implementaciones Completadas</th>
                <th>Implementaciones Canceladas</th>
                <th style="width:120px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($campanias as $c):
                $idCamp   = (int)$c['id_campana'];
                $nombreC  = htmlspecialchars($c['nombre_campana']);
                $fechaI   = (!empty($c['fecha_inicio'])) ? date('d-m-Y', strtotime($c['fecha_inicio'])) : '-';
                $fechaT   = (!empty($c['fecha_termino'])) ? date('d-m-Y', strtotime($c['fecha_termino'])) : '-';
                $totLoc   = (int)$c['total_locales'];
                $p        = (int)$c['pendientes'];
                $comp     = (int)$c['completados'];
                $canc     = (int)$c['cancelados'];
              ?>
                <tr>
                  <td><?php echo $nombreC; ?></td>
                  <td class="text-center"><?php echo $fechaI; ?></td>
                  <td class="text-center"><?php echo $fechaT; ?></td>
                  <td class="text-center"><?php echo $totLoc; ?></td>
                  <td class="text-center">
                    <?php if($p > 0): ?>
                      <span class="badge badge-custom badge-pendientes"><?php echo $p; ?></span>
                    <?php else: ?>
                      0
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if($comp > 0): ?>
                      <span class="badge badge-custom badge-completados"><?php echo $comp; ?></span>
                    <?php else: ?>
                      0
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php if($canc > 0): ?>
                      <span class="badge badge-custom badge-cancelados"><?php echo $canc; ?></span>
                    <?php else: ?>
                      0
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <!-- Enlace para ver los locales de esa campaña -->
                    <a href="mod_panel_detalle_locales.php?id_campana=<?php echo $idCamp; ?>&id_ejecutor=<?php echo $id_ejecutor; ?>" class="btn btn-info btn-sm">
                      <i class="fas fa-map-marker-alt"></i> Locales
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p>No se encontraron campañas para este ejecutor.</p>
      <?php endif; ?>

      <div class="mt-3">
        <!-- Botón para regresar al panel principal -->
        <a href="mod_panel.php" class="btn btn-secondary">
          <i class="fas fa-arrow-left"></i> Volver al Panel
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Scripts Bootstrap / jQuery (CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>

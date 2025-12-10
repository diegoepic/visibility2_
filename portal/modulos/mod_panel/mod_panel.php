<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$usuario_id  = intval($_SESSION['usuario_id']);
$nombre      = $_SESSION['usuario_nombre'];
$apellido    = $_SESSION['usuario_apellido'];
$id_perfil   = intval($_SESSION['usuario_perfil']);
$id_division = intval($_SESSION['division_id']);
$id_empresa  = intval($_SESSION['empresa_id']);



/**
 * Consulta: 
 *  - Obtenemos la cantidad de LOCALES (COUNT DISTINCT fq.id_local) por cada ejecutor,
 *  - total_asignaciones (COUNT DISTINCT fq.id) => total de registros en formularioQuestion,
 *  - pendientes, completados, cancelados (SUM según estado).
 */
$sql = "
    SELECT 
        u.id AS id_ejecutor,
        u.nombre AS nombre_ejecutor,
        u.apellido AS apellido_ejecutor,

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita)) AS total_locales,
        COUNT(DISTINCT fq.id) AS total_asignaciones,
		COUNT(DISTINCT CASE 
			WHEN fq.countVisita = 0 
				 AND (fq.pregunta = '' OR fq.pregunta IS NULL)
			THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
		END) AS pendientes,
		COUNT(DISTINCT CASE 
			WHEN fq.countVisita >= 1 
				 AND fq.pregunta IN ('solo_auditoria', 'solo_implementado', 'implementado_auditado','completado')
			THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
		END) AS completados,
		COUNT(DISTINCT CASE 
			WHEN fq.countVisita >= 1 
				 AND fq.pregunta IN ('cancelado','en proceso')
			THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
		END) AS cancelados

    FROM usuario u
    LEFT JOIN formularioQuestion fq ON fq.id_usuario = u.id
    LEFT JOIN formulario f ON f.id = fq.id_formulario
    WHERE u.id_perfil = 3
      AND u.id_division = ?
      AND u.id_empresa = ?
    GROUP BY u.id, u.nombre, u.apellido
    ORDER BY u.nombre ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("ii", $id_division, $id_empresa);
$stmt->execute();
$result = $stmt->get_result();

$ejecutores = [];
while ($row = $result->fetch_assoc()) {
    $ejecutores[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Control - Coordinador</title>

  <!-- Bootstrap 4 (CDN) -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- FontAwesome para íconos -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <!-- Estilos personalizados -->
  <style>
    body {
      background-color: #f4f6f9; /* un tono gris claro, similar a AdminLTE */
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
      background: #3c8dbc;
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
    .badge-custom {
      font-size: 90%;
      padding: .4em .6em;
    }
    .badge-pendientes {
      background-color: #f39c12; /* naranja */
    }
    .badge-completados {
      background-color: #00a65a; /* verde */
    }
    .badge-cancelados {
      background-color: #dd4b39; /* rojo */
    }
  </style>
</head>
<body>

<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title">
      <i class="fas fa-user-cog"></i> 
      Panel de Control - Coordinador
    </h1>
    <p class="mb-0">Bienvenido, <?php echo htmlspecialchars($nombre.' '.$apellido); ?>.</p>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h4>Ejecutores Asignados a tu División</h4>
      <hr>
      <?php if (count($ejecutores) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-bordered">
            <thead>
              <tr>
                <th>Ejecutor</th>
                <th >Locales</th>
                <th >Asignaciones Totales</th>
                <th> Implementaciones Pendientes</th>
                <th> Implementaciones Completadas</th>
                <th>Implementaciones Canceladas</th>
                <th style="width:140px;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ejecutores as $e):
                $idEjec       = (int)$e['id_ejecutor'];
                $nombreEjec   = htmlspecialchars($e['nombre_ejecutor'].' '.$e['apellido_ejecutor']);
                $numLocales   = (int)$e['total_locales'];
                $totalAsign   = (int)$e['total_asignaciones'];
                $pend         = (int)$e['pendientes'];
                $comp         = (int)$e['completados'];
                $canc         = (int)$e['cancelados'];
              ?>
              <tr>
                <td><?php echo $nombreEjec; ?></td>
                <td class="text-center"><?php echo $numLocales; ?></td>
                <td class="text-center"><?php echo $totalAsign; ?></td>
                <td class="text-center">
                  <?php if ($pend > 0): ?>
                    <span class="badge badge-custom badge-pendientes"><?php echo $pend; ?></span>
                  <?php else: ?>
                    0
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($comp > 0): ?>
                    <span class="badge badge-custom badge-completados"><?php echo $comp; ?></span>
                  <?php else: ?>
                    0
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($canc > 0): ?>
                    <span class="badge badge-custom badge-cancelados"><?php echo $canc; ?></span>
                  <?php else: ?>
                    0
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <a href="mod_panel_detalle.php?id_ejecutor=<?php echo $idEjec; ?>"
                     class="btn btn-primary btn-sm">
                    <i class="fas fa-eye"></i> Ver Detalle
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p>No hay ejecutores asignados a tu división.</p>
      <?php endif; ?>
    </div>
  </div>
</div>


<!-- Scripts Bootstrap / jQuery (CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

</body>
</html>

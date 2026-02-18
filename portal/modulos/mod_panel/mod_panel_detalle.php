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
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$subdivision_filtro = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;
$division_filtro = isset($_GET['division']) ? intval($_GET['division']) : $id_division;

$hoy = date('Y-m-d');

if (empty($fecha_desde) && empty($fecha_hasta)) {
    $fecha_desde = $hoy;
    $fecha_hasta = $hoy;
}

/* ================================
   VALIDAR ID EJECUTOR
================================ */
if (!isset($_GET['id_ejecutor'])) {
    die("Parámetro inválido. Falta id_ejecutor.");
}
$id_ejecutor = intval($_GET['id_ejecutor']);

/* ================================
   VALIDAR QUE PERTENECE A DIVISIÓN
================================ */
$sql_valida = "
    SELECT COUNT(*) AS cnt
    FROM usuario
    WHERE id = ?
      AND id_perfil = 3
      AND activo = 1
      AND id_division = ?
      AND id_empresa = ?
";

$stmt_val = $conn->prepare($sql_valida);
$stmt_val->bind_param("iii", $id_ejecutor, $division_filtro, $id_empresa);
$stmt_val->execute();
$res_val = $stmt_val->get_result();
$row_val = $res_val->fetch_assoc();
$stmt_val->close();

if ($row_val['cnt'] == 0) {
    die("El ejecutor no pertenece a la división seleccionada o no existe.");
}

/* ================================
   NOMBRE EJECUTOR
================================ */
$sql_nombre = "SELECT nombre, apellido FROM usuario WHERE id = ?";
$stmt_n = $conn->prepare($sql_nombre);
$stmt_n->bind_param("i", $id_ejecutor);
$stmt_n->execute();
$res_n = $stmt_n->get_result();
$rowN = $res_n->fetch_assoc();
$stmt_n->close();

$nombreEjec = $rowN ? $rowN['nombre'].' '.$rowN['apellido'] : 'Ejecutor desconocido';

/* ================================
   QUERY DETALLE CAMPAÑAS
================================ */
$sql_detalle = "
   SELECT 
     f.id AS id_campana,
     f.nombre AS nombre_campana,

     COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_locales,

     COUNT(DISTINCT CASE 
         WHEN fq.countVisita > 0 
         THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita)
     END) AS visitados,

     COUNT(DISTINCT CASE 
         WHEN fq.countVisita >= 1 
              AND fq.pregunta IN ('solo_auditoria','solo_implementado','implementado_auditado','completado')
         THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita)
     END) AS completados,

     MIN(f.fechaInicio) AS fecha_inicio,
     MAX(f.fechaTermino) AS fecha_termino

   FROM formularioQuestion fq
   INNER JOIN formulario f ON f.id = fq.id_formulario

   WHERE fq.id_usuario = ?
     AND f.id_empresa = ?
     AND f.id_division = ?
";

$params = [$id_ejecutor, $id_empresa, $division_filtro];
$types = "iii";

/* SUBDIVISION */
if ($subdivision_filtro > 0) {
    $sql_detalle .= " AND f.id_subdivision = ?";
    $types .= "i";
    $params[] = $subdivision_filtro;
}

/* FILTRO FECHA */
if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $inicio = $fecha_desde . " 00:00:00";
    $fin    = $fecha_hasta . " 23:59:59";

    $sql_detalle .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) BETWEEN ? AND ?";
    $types .= "ss";
    $params[] = $inicio;
    $params[] = $fin;
}

$sql_detalle .= "
   GROUP BY f.id, f.nombre
   ORDER BY f.fechaInicio DESC
";

$stmt_det = $conn->prepare($sql_detalle);
$stmt_det->bind_param($types, ...$params);
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

<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
</head>
<body>

<div class="container card-panel">

<div class="panel-header">
<h4><i class="fas fa-user"></i> Detalle de Campañas</h4>
<p class="mb-0">
Coordinador: <?= htmlspecialchars($nombreCoord.' '.$apellidoCoord) ?>
</p>
</div>

<div class="card mt-3">
<div class="card-body">

<h5>Ejecutor: <?= htmlspecialchars($nombreEjec) ?></h5>
<hr>

<?php if (count($campanias) > 0): ?>
<div class="table-responsive">
<table class="table table-striped table-bordered">
<thead>
<tr>
<th>Campaña</th>
<th>Inicio</th>
<th>Término</th>
<th>Locales</th>
<th>Pendientes</th>
<th>Completadas</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>

<?php foreach($campanias as $c):

$totLoc = (int)$c['total_locales'];
$comp   = (int)$c['completados'];
$pend   = $totLoc - $comp;

?>

<tr>
<td><?= htmlspecialchars($c['nombre_campana']) ?></td>
<td class="text-center"><?= date('d-m-Y', strtotime($c['fecha_inicio'])) ?></td>
<td class="text-center"><?= date('d-m-Y', strtotime($c['fecha_termino'])) ?></td>
<td class="text-center"><?= $totLoc ?></td>
<td class="text-center">
<?php if($pend>0): ?>
<span class="badge badge-pendientes"><?= $pend ?></span>
<?php else: ?>0<?php endif; ?>
</td>
<td class="text-center">
<?php if($comp>0): ?>
<span class="badge badge-completados"><?= $comp ?></span>
<?php else: ?>0<?php endif; ?>
</td>
<td class="text-center">
<a href="mod_panel_detalle_locales.php?id_campana=<?= $c['id_campana'] ?>&id_ejecutor=<?= $id_ejecutor ?>&division=<?= $division_filtro ?>&subdivision=<?= $subdivision_filtro ?>&fecha_desde=<?= $fecha_desde ?>&fecha_hasta=<?= $fecha_hasta ?>"
class="btn btn-info btn-sm">
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
<a href="mod_panel.php?division=<?= $division_filtro ?>&subdivision=<?= $subdivision_filtro ?>&fecha_desde=<?= $fecha_desde ?>&fecha_hasta=<?= $fecha_hasta ?>"
class="btn btn-secondary">
<i class="fas fa-arrow-left"></i> Volver al Panel
</a>
</div>

</div>
</div>
</div>

</body>
</html>

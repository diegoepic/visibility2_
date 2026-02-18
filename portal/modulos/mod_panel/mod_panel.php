<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

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

$anio = isset($_GET['anio']) ? intval($_GET['anio']) : 0;
$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : 0;

$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

$division_filtro = isset($_GET['division']) 
    ? intval($_GET['division']) 
    : $id_division;
    
$subdivision_filtro = isset($_GET['subdivision']) 
    ? intval($_GET['subdivision']) 
    : 0;    

$anioActual   = date('Y');
$anioAnterior = $anioActual - 1;


$hoy = date('Y-m-d');

if (empty($fecha_desde) && empty($fecha_hasta)) {
    $fecha_desde = $hoy;
    $fecha_hasta = $hoy;
}

if ($anio !== 0 && $anio !== $anioActual && $anio !== $anioAnterior) {
    $anio = 0;
}

// --------------------------------------------------
// Cargar divisiones con ejecutores activos
// --------------------------------------------------
$sqlDiv = "
    SELECT DISTINCT d.id, d.nombre
    FROM division_empresa d
    INNER JOIN usuario u ON u.id_division = d.id
    WHERE d.estado = 1
      AND u.id_perfil = 3
      AND u.id_empresa = ?
      AND u.activo = 1
    ORDER BY d.nombre
";

$stmtDiv = $conn->prepare($sqlDiv);
$stmtDiv->bind_param("i", $id_empresa);
$stmtDiv->execute();
$resDiv = $stmtDiv->get_result();

$divisiones = [];
while ($row = $resDiv->fetch_assoc()) {
    $divisiones[] = $row;
}
$stmtDiv->close();

// --------------------------------------------------
// QUERY PRINCIPAL
// --------------------------------------------------
$sql = "
    SELECT 
        u.id AS id_ejecutor,
        UPPER(u.nombre) AS nombre_ejecutor,
        UPPER(u.apellido) AS apellido_ejecutor,
        UPPER(u.usuario) AS usuario,        

        COUNT(DISTINCT CONCAT(fq.id_local, '-', fq.id_formulario)) AS total_locales,
        COUNT(DISTINCT fq.id_formulario) AS total_asignaciones,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0 
            THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
        END) AS visitados,        

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita >= 1 
                 AND fq.pregunta IN ('solo_auditoria', 'solo_implementado', 'implementado_auditado','completado')
            THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
        END) AS completados

    FROM usuario u
    LEFT JOIN formularioQuestion fq ON fq.id_usuario = u.id
    LEFT JOIN formulario f ON f.id = fq.id_formulario

    WHERE u.id_perfil = 3
      AND u.activo = 1
      AND u.id_division = ?
      AND u.id_empresa = ?
";

$params = [$division_filtro, $id_empresa];
$types  = "ii";

// Filtro por subdivision si viene seleccionada
if ($subdivision_filtro > 0) {
    $sql .= " AND f.id_subdivision = ?";
    $types .= "i";
    $params[] = $subdivision_filtro;
}

// --------------------------------------------------
// FILTRO POR FECHA (fechaVisita -> fallback fechaPropuesta)
// --------------------------------------------------

if (!empty($fecha_desde) && !empty($fecha_hasta)) {

    $inicio = $fecha_desde . " 00:00:00";
    $fin    = $fecha_hasta . " 23:59:59";

    $sql .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) BETWEEN ? AND ?";
    $types .= "ss";
    $params[] = $inicio;
    $params[] = $fin;

} elseif (!empty($fecha_desde)) {

    $inicio = $fecha_desde . " 00:00:00";

    $sql .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) >= ?";
    $types .= "s";
    $params[] = $inicio;

} elseif (!empty($fecha_hasta)) {

    $fin = $fecha_hasta . " 23:59:59";

    $sql .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) <= ?";
    $types .= "s";
    $params[] = $fin;

} else {

    $inicio = $fecha_desde . " 00:00:00";
    $fin    = $fecha_hasta . " 23:59:59";
    
    $sql .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) BETWEEN ? AND ?";
    $types .= "ss";
    $params[] = $inicio;
    $params[] = $fin;
}
// --------------------------------------------------
// GROUP BY
// --------------------------------------------------
$sql .= "
    GROUP BY u.id, u.nombre, u.apellido, u.usuario
    ORDER BY u.nombre ASC
";


// --------------------------------------------------
// RESUMEN VISITAS POR FECHA
// --------------------------------------------------

$sqlMatriz = "
    SELECT 
        u.id AS id_ejecutor,
        UPPER(u.nombre) AS nombre,
        UPPER(u.apellido) AS apellido,
        UPPER(u.usuario) AS usuario,
        DATE(COALESCE(fq.fechaVisita, fq.fechaPropuesta)) AS fecha,
        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0 
            THEN CONCAT(fq.id_local, '-', fq.id_formulario,'-',fq.fechaVisita) 
        END) AS total 
    FROM formularioQuestion fq
    LEFT JOIN formulario f ON f.id = fq.id_formulario
    LEFT JOIN usuario u ON u.id = fq.id_usuario
    WHERE u.id_perfil = 3
      AND u.activo = 1
      AND u.id_division = ?
      AND u.id_empresa = ?
";

$paramsMatriz = [$division_filtro, $id_empresa];
$typesMatriz  = "ii";

if ($subdivision_filtro > 0) {
    $sqlMatriz .= " AND f.id_subdivision = ?";
    $typesMatriz .= "i";
    $paramsMatriz[] = $subdivision_filtro;
}

if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $inicio = $fecha_desde . " 00:00:00";
    $fin    = $fecha_hasta . " 23:59:59";

    $sqlMatriz .= " AND COALESCE(fq.fechaVisita, fq.fechaPropuesta) BETWEEN ? AND ?";
    $typesMatriz .= "ss";
    $paramsMatriz[] = $inicio;
    $paramsMatriz[] = $fin;
}

$sqlMatriz .= "
    GROUP BY u.id, fecha
    ORDER BY u.nombre, fecha
";

$stmtMatriz = $conn->prepare($sqlMatriz);
$stmtMatriz->bind_param($typesMatriz, ...$paramsMatriz);
$stmtMatriz->execute();
$resultMatriz = $stmtMatriz->get_result();

$fechas = [];
$matriz = [];

$totalesColumnas = [];
$totalGeneral = 0;

while ($row = $resultMatriz->fetch_assoc()) {

    $id = $row['id_ejecutor'];
    $fecha = $row['fecha'];

    $fechas[$fecha] = $fecha;

    if (!isset($matriz[$id])) {
        $matriz[$id] = [
            'nombre' => $row['nombre'] . ' ' . $row['apellido'],
            'usuario' => $row['usuario'],
            'datos' => []
        ];
    }

    $matriz[$id]['datos'][$fecha] = $row['total'];
}

$stmtMatriz->close();
ksort($fechas);
// --------------------------------------------------
// GESTIONES ADICIONALES (FORM 1671,1653)
// --------------------------------------------------

$sqlGestiones = "
    SELECT
        r.id_usuario,
        COUNT(DISTINCT CONCAT(DATE(r.created_at), '-', COALESCE(r.id_local, v.id_local, 0))) AS total_gestiones
    FROM form_question_responses AS r
    JOIN form_questions AS q ON q.id = r.id_form_question
    JOIN usuario AS u ON u.id = r.id_usuario
    JOIN formulario AS f ON f.id = q.id_formulario
    LEFT JOIN visita AS v ON v.id = r.visita_id
    WHERE q.id_formulario IN (1671,1653)
      AND q.id_question_type <> 7
      AND u.id_division = ?
      AND u.id_empresa = ?
";

$paramsGest = [$division_filtro, $id_empresa];
$typesGest  = "ii";

// Subdivision
if ($subdivision_filtro > 0) {
    $sqlGestiones .= " AND f.id_subdivision = ?";
    $typesGest .= "i";
    $paramsGest[] = $subdivision_filtro;
}

// MISMO FILTRO FECHA
if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    $inicio = $fecha_desde . " 00:00:00";
    $fin    = $fecha_hasta . " 23:59:59";

    $sqlGestiones .= " AND r.created_at BETWEEN ? AND ?";
    $typesGest .= "ss";
    $paramsGest[] = $inicio;
    $paramsGest[] = $fin;
}

$sqlGestiones .= "
    GROUP BY r.id_usuario
";

$stmtGest = $conn->prepare($sqlGestiones);
$stmtGest->bind_param($typesGest, ...$paramsGest);
$stmtGest->execute();
$resultGest = $stmtGest->get_result();

$gestionesPorUsuario = [];

while ($row = $resultGest->fetch_assoc()) {
    $gestionesPorUsuario[$row['id_usuario']] = (int)$row['total_gestiones'];
}

$stmtGest->close();

// --------------------------------------------------
// Ejecutar
// --------------------------------------------------
if (!$stmt = $conn->prepare($sql)) {
    die("Error en prepare: " . $conn->error);
}

if (!$stmt->bind_param($types, ...$params)) {
    die("Error en bind_param: " . $stmt->error);
}
$stmt->execute();
$result = $stmt->get_result();

$ejecutores = [];
while ($row = $result->fetch_assoc()) {
    $ejecutores[] = $row;
}

$stmt->close();
$conn->close();

function iconRatio($ratio) {
    if ($ratio >= 80) {
        return '<i class="fas fa-check-circle text-success"></i>';
    }
    if ($ratio >= 50) {
        return '<i class="fas fa-exclamation-triangle text-warning"></i>';
    }
    return '<i class="fas fa-times-circle text-danger"></i>';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panel de Control - Coordinador</title>

  <!-- Bootstrap 4 (CDN) -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- FontAwesome para Ã­conos -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
</head>
<body>

<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title">
      <i class="fas fa-user-cog"></i> 
      Panel de Control - Merchandising
    </h1>
    <p class="mb-0">Bienvenido, <?php echo htmlspecialchars($nombre.' '.$apellido); ?>.</p>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <form method="GET" class="form-row align-items-end mb-3">
          <div class="col-md-4">
            <label><strong>Division</strong></label>
            <select name="division" class="form-control">
              <?php foreach ($divisiones as $d): ?>
                <option value="<?= $d['id'] ?>"
                  <?= $d['id'] == $division_filtro ? 'selected' : '' ?>>
                  <?= htmlspecialchars($d['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

            <div class="col-md-3">
              <label><strong>Subdivision</strong></label>
              <select name="subdivision" id="subdivision" class="form-control">
                <option value="0">Todas</option>
              </select>
            </div>

            <div class="col-md-2">
              <label><strong>Desde</strong></label>
              <input type="date" 
                     name="fecha_desde" 
                     class="form-control"
                     value="<?= htmlspecialchars($fecha_desde) ?>">
            </div>
            
            <div class="col-md-2">
              <label><strong>Hasta</strong></label>
              <input type="date" 
                     name="fecha_hasta" 
                     class="form-control"
                     value="<?= htmlspecialchars($fecha_hasta) ?>">
            </div>
         
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-success w-100 btn-filtrar">
                <i class="fas fa-filter"></i> Filtrar
              </button>
            </div>
        </form>
      <hr>
      <?php $totalEjecutores = count($ejecutores); ?>
        <?php
        $totalLocalesAsignados = 0;
        $totalLocalesVisitados = 0;
        $totalLocalesGestionados = 0;
        
        foreach ($ejecutores as $e) {
            $gestionesExtra = $gestionesPorUsuario[$e['id_ejecutor']] ?? 0;
            
            $totalLocalesAsignados += (int)$e['total_locales'] + $gestionesExtra;
            $totalLocalesVisitados += (int)$e['visitados'] + $gestionesExtra;
            $totalLocalesGestionados += (int)$e['completados'] + $gestionesExtra;
        }
            $ratioVisitadosTotal = $totalLocalesAsignados > 0 
                ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1)
                : 0;
            
            $ratioGestionadosTotal = $totalLocalesAsignados > 0 
                ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1)
                : 0;        
        ?>
        <div class="row mb-4">
        
            <!-- Ejecutores -->
            <div class="col-md-3">
                <div class="kpi-card">
                    <i class="fas fa-users"></i>
                    <div>
                        <div class="kpi-title">Ejecutores</div>
                        <div class="kpi-value"><?= $totalEjecutores ?></div>
                    </div>
                </div>
            </div>
        
            <!-- Locales Asignados -->
            <div class="col-md-3">
                <div class="kpi-card">
                    <i class="fas fa-store"></i>
                    <div>
                        <div class="kpi-title">Locales Asignados</div>
                        <div class="kpi-value"><?= $totalLocalesAsignados ?></div>
                    </div>
                </div>
            </div>
        
            <!-- Locales Visitados -->
            <div class="col-md-3">
                <div class="kpi-card">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <div class="kpi-title">
                            Locales Visitados
                        </div>
                        <div class="kpi-main">
                            <span class="kpi-value">
                                <?= $totalLocalesVisitados ?>
                            </span>
                            <span class="kpi-ratio-inline">
                                <?= iconRatio($ratioVisitadosTotal) ?>
                                <?= $ratioVisitadosTotal ?>%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        
            <!-- Locales Gestionados -->
            <div class="col-md-3">
                <div class="kpi-card">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <div class="kpi-title">
                            Locales Gestionados
                        </div>
                        <div class="kpi-main">
                            <span class="kpi-value">
                                <?= $totalLocalesGestionados ?>
                            </span>
                            <span class="kpi-ratio-inline">
                                <?= iconRatio($ratioGestionadosTotal) ?>
                                <?= $ratioGestionadosTotal ?>%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>        
      <?php if (count($ejecutores) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-bordered">
            <thead>
              <tr>
                <th>Ejecutor</th>
                <th>Usuario</th>
                <th>Actividades asignadas</th>
                <th>Locales asignados</th>
                <th>Locales visitados</th>                 
                <th>Locales gestionados</th>
                <th>% Visitados</th>
                <th>% Gestionados</th>                
                <th style="width:140px;">Detalle</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ejecutores as $e):
                $idEjec       = (int)$e['id_ejecutor'];
                $nombreEjec   = htmlspecialchars($e['nombre_ejecutor'].' '.$e['apellido_ejecutor']);
                $usuario   = htmlspecialchars($e['usuario']);                
                $totalAsign   = (int)$e['total_asignaciones'];
                $gestionesExtra = $gestionesPorUsuario[$idEjec] ?? 0;
                
                $numLocales   = (int)$e['total_locales'] + $gestionesExtra;
                $numVisitados = (int)$e['visitados'] + $gestionesExtra;
                $comp         = (int)$e['completados'] + $gestionesExtra;
                
                $ratioVisitados = $numLocales > 0 
                    ? round(($numVisitados / $numLocales) * 100, 1) 
                    : 0;
                
                $ratioGestionados = $numLocales > 0 
                    ? round(($comp / $numLocales) * 100, 1) 
                    : 0;                
              ?>
              <tr>
                <td><?php echo $nombreEjec; ?></td>
                <td><?php echo $usuario; ?></td>                
                <td class="text-center"><?php echo $totalAsign; ?></td>
                <td class="text-center"><?php echo $numLocales; ?></td>
                <td class="text-center"><?php echo $numVisitados; ?></td>
                <td class="text-center"><?php echo $comp; ?></td>
                <td class="text-center">
                    <span class="ratio-cell">
                        <?= iconRatio($ratioVisitados) ?>
                        <span class="ratio-text"><?= $ratioVisitados ?>%</span>
                    </span>
                </td>
                
                <td class="text-center">
                    <span class="ratio-cell">
                        <?= iconRatio($ratioGestionados) ?>
                        <span class="ratio-text"><?= $ratioGestionados ?>%</span>
                    </span>
                </td>
                <td class="text-center">
                  <a href="mod_panel_detalle.php?id_ejecutor=<?= $idEjec ?>
                    &division=<?= $division_filtro ?>
                    &subdivision=<?= $subdivision_filtro ?>
                    &fecha_desde=<?= urlencode($fecha_desde) ?>
                    &fecha_hasta=<?= urlencode($fecha_hasta) ?>"
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
        <p>No hay ejecutores asignados a tu division.</p>
      <?php endif; ?>
      
        <?php if (!empty($matriz)): ?>
        
        <div class="card mt-4">
            <div class="card-body">
                <h5 class="mb-3">
                    <i class="fas fa-calendar-alt"></i>
                    Visitas por Fecha (Matriz)
                </h5>
        
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>Ejecutor</th>
                                <th>Usuario</th>
        
                                <?php foreach ($fechas as $f): ?>
                                    <th class="text-center">
                                        <?= date("d/m/y", strtotime($f)) ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-center">Total</th>
                            </tr>
                        </thead>
                            <tbody>
                            <?php foreach ($matriz as $m): ?>
                                <?php 
                                    $totalFila = 0;
                                ?>
                                <tr>
                                    <td><?= $m['nombre'] ?></td>
                                    <td><?= $m['usuario'] ?></td>
                                    <?php foreach ($fechas as $f): ?>
                                        <?php
                                            $valor = $m['datos'][$f] ?? 0;
                                            $totalFila += $valor;
                                            $totalesColumnas[$f] = ($totalesColumnas[$f] ?? 0) + $valor;
                                            $totalGeneral += $valor;
                                        ?>
                                        <td class="text-center">
                                            <?= $valor ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <!-- Total fila -->
                                    <td class="text-center font-weight-bold">
                                        <?= $totalFila ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Fila totales -->
                            <tr class="table-secondary font-weight-bold">
                                <td colspan="2">TOTAL</td>
                                <?php foreach ($fechas as $f): ?>
                                    <td class="text-center">
                                        <?= $totalesColumnas[$f] ?? 0 ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <?= $totalGeneral ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
      
    </div>
  </div>
</div>


<!-- Scripts Bootstrap / jQuery (CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
$(document).ready(function() {

    function cargarSubdivisiones(idDivision, selected = 0) {
        if (!idDivision) {
            $('#subdivision').html('<option value="0">Todas</option>');
            return;
        }

        $.ajax({
            url: 'ajax_subdivisiones.php',
            type: 'GET',
            data: { division: idDivision },
            success: function(response) {

                let options = '<option value="0">Todas</option>';

                response.forEach(function(sub) {
                    let selectedAttr = (sub.id == selected) ? 'selected' : '';
                    options += `<option value="${sub.id}" ${selectedAttr}>${sub.nombre}</option>`;
                });

                $('#subdivision').html(options);
            }
        });
    }

    // Cuando cambia divisi¨®n
    $('[name="division"]').on('change', function() {
        let divisionId = $(this).val();
        cargarSubdivisiones(divisionId);
    });

    // Si viene subdivision seleccionada al cargar p¨¢gina
    let divisionInicial = $('[name="division"]').val();
    let subdivisionInicial = <?= intval($subdivision_filtro) ?>;

    if (divisionInicial) {
        cargarSubdivisiones(divisionInicial, subdivisionInicial);
    }

});
</script>


</body>
</html>

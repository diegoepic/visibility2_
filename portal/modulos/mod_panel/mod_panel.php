<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$nombre      = $_SESSION['usuario_nombre'];
$apellido    = $_SESSION['usuario_apellido'];
$id_division = intval($_SESSION['division_id']);
$id_empresa  = intval($_SESSION['empresa_id']);

$division_filtro = isset($_GET['division']) 
    ? intval($_GET['division']) 
    : $id_division;

$subdivision_filtro = isset($_GET['subdivision']) 
    ? intval($_GET['subdivision']) 
    : 0;

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

$inicio = $fecha_desde . " 00:00:00";
$fin    = $fecha_hasta . " 23:59:59";


/* ======================================================
   CARGAR DIVISIONES
====================================================== */
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


/* ======================================================
   1️⃣ UNIVERSO ACTIVO (NO DEPENDE DE FECHA)
====================================================== */
$sqlUniverso = "
    SELECT
        u.id AS id_ejecutor,
        UPPER(u.nombre) AS nombre,
        UPPER(u.apellido) AS apellido,
        UPPER(u.usuario) AS usuario,

        COUNT(DISTINCT CONCAT(fq.id_local,'-',fq.id_formulario)) AS locales_activos,
        COUNT(DISTINCT f.id) AS campanas_activas,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
            THEN CONCAT(fq.id_local,'-',fq.id_formulario)
        END) AS visitados_historico,
        
        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
            AND fq.pregunta in ('solo_auditoria', 'solo_implementado', 'implementado_auditado','completado')
            THEN CONCAT(fq.id_local,'-',fq.id_formulario)
        END) AS gestionados_historico

    FROM usuario u
    LEFT JOIN formularioQuestion fq ON fq.id_usuario = u.id
    LEFT JOIN formulario f ON f.id = fq.id_formulario

    WHERE u.id_perfil = 3
      AND u.activo = 1
      AND u.id_division = ?
      AND u.id_empresa = ?
      AND f.estado = 1
";

$paramsU = [$division_filtro, $id_empresa];
$typesU  = "ii";

if ($subdivision_filtro > 0) {
    $sqlUniverso .= " AND f.id_subdivision = ? ";
    $typesU .= "i";
    $paramsU[] = $subdivision_filtro;
}

$sqlUniverso .= "
    GROUP BY u.id
    ORDER BY u.nombre ASC
";

$stmtU = $conn->prepare($sqlUniverso);
$stmtU->bind_param($typesU, ...$paramsU);
$stmtU->execute();
$resU = $stmtU->get_result();

$universo = [];
while ($row = $resU->fetch_assoc()) {
    $universo[] = $row;
}
$stmtU->close();


/* ======================================================
   2️⃣ PRODUCTIVIDAD DIARIA (TOTAL REAL DEL EJECUTOR)
====================================================== */

$sqlProd = "
    SELECT
        u.id AS id_ejecutor,
        UPPER(u.nombre) AS nombre,
        UPPER(u.apellido) AS apellido,
        UPPER(u.usuario) AS usuario,
        DATE(v.fecha) AS fecha,
        COUNT(DISTINCT CONCAT(v.id_local,'-',v.id_formulario,'-',DATE(v.fecha))) AS total
    FROM vw_gestiones_unificadas v
    JOIN usuario u ON u.id = v.id_usuario
    WHERE u.id_perfil = 3
      AND u.activo = 1
      AND u.id_division = ?
      AND u.id_empresa = ?
      AND v.fecha BETWEEN ? AND ?
";

$paramsP = [$division_filtro, $id_empresa, $inicio, $fin];
$typesP  = "iiss";

$sqlProd .= "
    GROUP BY u.id, DATE(v.fecha)
    ORDER BY u.nombre, DATE(v.fecha)
";

$stmtP = $conn->prepare($sqlProd);
$stmtP->bind_param($typesP, ...$paramsP);
$stmtP->execute();
$resP = $stmtP->get_result();
$fechas = [];
$matriz = [];

/* Primero inicializamos matriz con universo */
foreach ($universo as $u) {

    $id = $u['id_ejecutor'];

    $matriz[$id] = [
        'nombre'  => $u['nombre'] . ' ' . $u['apellido'],
        'usuario' => $u['usuario'],
        'datos'   => []
    ];
}

/* Luego cargamos productividad diaria */
while ($row = $resP->fetch_assoc()) {

    $id = $row['id_ejecutor'];
    $fecha = $row['fecha'];
    $valor = (int)$row['total'];

    $fechas[$fecha] = $fecha;

    if (!isset($matriz[$id])) {
        continue;
    }

    $matriz[$id]['datos'][$fecha] = $valor;
}
$stmtP->close();
$conn->close();

ksort($fechas);


/* ======================================================
   CALCULO KPI
====================================================== */
$totalEjecutores = count($universo);
$totalLocalesAsignados = 0;
$totalLocalesVisitados = 0;
$totalLocalesGestionados = 0;

foreach ($universo as $e) {
    $totalLocalesAsignados += (int)$e['locales_activos'];
    $totalLocalesVisitados += (int)$e['visitados_historico'];
    $totalLocalesGestionados += (int)$e['gestionados_historico'];    
}

$ratioTotalVisitados = $totalLocalesAsignados > 0
    ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1)
    : 0;

$ratioTotalGestionados = $totalLocalesAsignados > 0
    ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1)
    : 0;

function iconRatio($ratio) {
    if ($ratio >= 80) return '<i class="fas fa-check-circle text-success"></i>';
    if ($ratio >= 50) return '<i class="fas fa-exclamation-triangle text-warning"></i>';
    return '<i class="fas fa-times-circle text-danger"></i>';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panel de Control - Coordinador</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">    
</head>
<body>

<div class="container card-panel">
  <div class="panel-header">
    <h1 class="panel-title">
      <i class="fas fa-user-cog"></i>
      Panel de Control - Merchandising
    </h1>
    <p class="mb-0">Bienvenido, <?= htmlspecialchars($nombre.' '.$apellido); ?>.</p>
  </div>

  <div class="card mt-3">
    <div class="card-body">

      <form method="GET" class="form-row align-items-end mb-3">
        <div class="col-md-4">
          <label><strong>Division</strong></label>
          <select name="division" class="form-control">
            <?php foreach ($divisiones as $d): ?>
              <option value="<?= $d['id'] ?>" <?= ($d['id'] == $division_filtro ? 'selected' : '') ?>>
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
          <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
        </div>

        <div class="col-md-2">
          <label><strong>Hasta</strong></label>
          <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
        </div>

        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-success w-100 btn-filtrar">
            <i class="fas fa-filter"></i> Filtrar
          </button>
        </div>
      </form>

      <hr>

      <?php
        $totalEjecutores = count($universo);
        $totalLocalesAsignados = 0;
        $totalLocalesVisitados = 0;
        $totalLocalesGestionados = 0;

        foreach ($universo as $e) {
            $totalLocalesAsignados  += (int)$e['locales_activos'];
            $totalLocalesVisitados  += (int)$e['visitados_historico'];
            $totalLocalesGestionados+= (int)$e['gestionados_historico'];
        }

        $ratioVisitadosTotal = $totalLocalesAsignados > 0 ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1) : 0;
        $ratioGestionadosTotal = $totalLocalesAsignados > 0 ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1) : 0;
      ?>

      <div class="row mb-4">
        <div class="col-md-3">
          <div class="kpi-card">
            <i class="fas fa-users"></i>
            <div>
              <div class="kpi-title">Ejecutores</div>
              <div class="kpi-value"><?= $totalEjecutores ?></div>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="kpi-card">
            <i class="fas fa-store"></i>
            <div>
              <div class="kpi-title">Locales Asignados</div>
              <div class="kpi-value"><?= $totalLocalesAsignados ?></div>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="kpi-card">
            <i class="fas fa-truck"></i>
            <div>
              <div class="kpi-title">Locales Visitados</div>
              <div class="kpi-main">
                <span class="kpi-value"><?= $totalLocalesVisitados ?></span>
                <span class="kpi-ratio-inline">
                  <?= iconRatio($ratioVisitadosTotal) ?>
                  <?= $ratioVisitadosTotal ?>%
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-3">
          <div class="kpi-card">
            <i class="fas fa-clipboard-check"></i>
            <div>
              <div class="kpi-title">Locales Gestionados</div>
              <div class="kpi-main">
                <span class="kpi-value"><?= $totalLocalesGestionados ?></span>
                <span class="kpi-ratio-inline">
                  <?= iconRatio($ratioGestionadosTotal) ?>
                  <?= $ratioGestionadosTotal ?>%
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if (!empty($universo)): ?>
        <div class="table-responsive">
          <table id="tablaTotal" class="table table-striped table-bordered">
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
              <?php foreach ($universo as $e):
                $idEjec     = (int)$e['id_ejecutor'];
                $nombreEjec = htmlspecialchars($e['nombre'].' '.$e['apellido']);
                $usuario    = htmlspecialchars($e['usuario']);
                $totalAsign = (int)$e['campanas_activas'];
                $numLocales = (int)$e['locales_activos'];
                $numVisitados = (int)$e['visitados_historico'];
                $comp = (int)$e['gestionados_historico'];

                $ratioVisitados = $numLocales > 0 ? round(($numVisitados / $numLocales) * 100, 1) : 0;
                $ratioGestionados = $numLocales > 0 ? round(($comp / $numLocales) * 100, 1) : 0;
              ?>
              <tr>
                <td><?= $nombreEjec ?></td>
                <td><?= $usuario ?></td>
                <td class="text-center"><?= $totalAsign ?></td>
                <td class="text-center"><?= $numLocales ?></td>
                <td class="text-center"><?= $numVisitados ?></td>
                <td class="text-center"><?= $comp ?></td>
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

      <?php if (!empty($matriz) && !empty($fechas)): ?>
        <div class="card mt-4">
          <div class="card-body">
            <h5 class="mb-3">
              <i class="fas fa-calendar-alt"></i>
              Visitas por Fecha (Matriz)
            </h5>

            <div class="table-responsive">
              <table id="tablaDiario" class="table table-sm table-bordered">
                <thead>
                  <tr>
                    <th>Ejecutor</th>
                    <th>Usuario</th>
                    <?php foreach ($fechas as $f): ?>
                      <th class="text-center"><?= date("d/m/y", strtotime($f)) ?></th>
                    <?php endforeach; ?>
                    <th class="text-center">Total</th>
                    <th style="width:140px;">Gestiones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $totalesColumnas = [];
                    $totalGeneral = 0;
                  ?>
                  <?php foreach ($matriz as $idEjecutor => $m): ?>
                    <?php $totalFila = 0; ?>
                    <tr>
                      <td><?= htmlspecialchars($m['nombre']) ?></td>
                      <td><?= htmlspecialchars($m['usuario']) ?></td>
                      <?php foreach ($fechas as $f):
                        $valor = (int)($m['datos'][$f] ?? 0);
                        $totalFila += $valor;
                        $totalesColumnas[$f] = ($totalesColumnas[$f] ?? 0) + $valor;
                        $totalGeneral += $valor;
                      ?>
                        <td class="text-center"><?= $valor ?></td>
                      <?php endforeach; ?>
                      <td class="text-center font-weight-bold"><?= $totalFila ?></td>
                            <td class="text-center">
                              <a href="mod_panel_gestiones_diarias.php?id_ejecutor=<?= $idEjecutor ?>
                                &division=<?= $division_filtro ?>
                                &fecha_desde=<?= urlencode($fecha_desde) ?>
                                &fecha_hasta=<?= urlencode($fecha_hasta) ?>"
                                class="btn btn-info btn-sm">
                                <i class="fas fa-search"></i> Ver Gestiones
                              </a>
                            </td>                       
                    </tr>
                  <?php endforeach; ?>

                  <tr class="table-secondary font-weight-bold">
                    <td colspan="2">TOTAL</td>
                    <?php foreach ($fechas as $f): ?>
                      <td class="text-center"><?= (int)($totalesColumnas[$f] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="text-center"><?= (int)$totalGeneral ?></td>
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

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaTotal').DataTable({
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

    $('[name="division"]').on('change', function() {
        let divisionId = $(this).val();
        cargarSubdivisiones(divisionId);
    });

    let divisionInicial = $('[name="division"]').val();
    let subdivisionInicial = <?= intval($subdivision_filtro) ?>;

    if (divisionInicial) {
        cargarSubdivisiones(divisionInicial, subdivisionInicial);
    }

});
</script>
<script>
(function () {
  function ymd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function fixFechas() {
    const desde = document.querySelector('input[name="fecha_desde"]');
    const hasta = document.querySelector('input[name="fecha_hasta"]');
    if (!desde || !hasta) return;

    const hoy = new Date();

    // Opción A: desde = hoy
    const defDesde = ymd(hoy);

    // Opción B: desde = ayer (descomenta si quieres esta lógica)
    // const ayer = new Date(hoy);
    // ayer.setDate(hoy.getDate() - 1);
    // const defDesde = ymd(ayer);

    const defHasta = ymd(hoy);

    if (!desde.value) desde.value = defDesde;
    if (!hasta.value) hasta.value = defHasta;
  }

  // Se ejecuta normal
  document.addEventListener('DOMContentLoaded', fixFechas);

  // 🔥 Se ejecuta también al volver con el botón "atrás" (bfcache)
  window.addEventListener('pageshow', function (e) {
    fixFechas();
  });
})();
</script>
</body>
</html>
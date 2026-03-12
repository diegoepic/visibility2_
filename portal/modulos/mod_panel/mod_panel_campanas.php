<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$nombreU      = $_SESSION['usuario_nombre'];
$apellido    = $_SESSION['usuario_apellido'];
$id_division = intval($_SESSION['division_id']);
$id_empresa  = intval($_SESSION['empresa_id']);

$division_filtro = isset($_GET['division']) 
    ? intval($_GET['division']) 
    : $id_division;

$subdivision_filtro = isset($_GET['subdivision']) 
    ? intval($_GET['subdivision']) 
    : 0;


/* ======================================================
   CARGAR DIVISIONES DESDE FORMULARIO
====================================================== */

$sqlDiv = "
    SELECT DISTINCT d.id, d.nombre
    FROM formulario f
    INNER JOIN division_empresa d ON d.id = f.id_division
    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND d.estado = 1
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
   UNIVERSO CAMPAÑAS ACTIVAS
====================================================== */

$sqlCampanas = "
    SELECT
        f.id,
        UPPER(f.nombre) AS nombre_campana,
        f.fechaInicio,
        f.fechaTermino,

        COUNT(DISTINCT fq.id_local) AS locales_asignados,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
            THEN fq.id_local
        END) AS locales_visitados,

        COUNT(DISTINCT CASE 
            WHEN fq.countVisita > 0
             AND fq.pregunta IN ('solo_auditoria','solo_implementado','implementado_auditado','completado')
            THEN fq.id_local
        END) AS locales_gestionados

    FROM formulario f
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id

    WHERE f.estado = 1
      AND f.id_empresa = ?
      AND f.id_division = ?
";

$paramsC = [$id_empresa, $division_filtro];
$typesC  = "ii";

if ($subdivision_filtro > 0) {
    $sqlCampanas .= " AND f.id_subdivision = ? ";
    $typesC .= "i";
    $paramsC[] = $subdivision_filtro;
}

$sqlCampanas .= "
    GROUP BY f.id
    ORDER BY f.fechaInicio DESC
";

$stmtC = $conn->prepare($sqlCampanas);
$stmtC->bind_param($typesC, ...$paramsC);
$stmtC->execute();
$resC = $stmtC->get_result();

$campanas = [];
while ($row = $resC->fetch_assoc()) {
    $campanas[] = $row;
}
$stmtC->close();


/* ======================================================
   CALCULO KPI GENERALES
====================================================== */

$totalCampanas = count($campanas);
$totalLocalesAsignados = 0;
$totalLocalesVisitados = 0;
$totalLocalesGestionados = 0;

foreach ($campanas as $c) {
    $totalLocalesAsignados  += (int)$c['locales_asignados'];
    $totalLocalesVisitados  += (int)$c['locales_visitados'];
    $totalLocalesGestionados+= (int)$c['locales_gestionados'];
}

$ratioVisitadosTotal = $totalLocalesAsignados > 0
    ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1)
    : 0;

$ratioGestionadosTotal = $totalLocalesAsignados > 0
    ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1)
    : 0;


function iconRatio($ratio) {
    if ($ratio >= 80) return '<i class="fas fa-check-circle text-success"></i>';
    if ($ratio >= 50) return '<i class="fas fa-exclamation-triangle text-warning"></i>';
    return '<i class="fas fa-times-circle text-danger"></i>';
}

// ---------------------------
// DATA PARA GRAFICO (Chart.js)
// ---------------------------
$labelsChart = [];
$dataVisitados = [];
$dataGestionados = [];

foreach ($campanas as $c) {
    $nombre = (string)$c['nombre_campana'];
    $locales = (int)$c['locales_asignados'];
    $visitados = (int)$c['locales_visitados'];
    $gestionados = (int)$c['locales_gestionados'];

    $ratioV = $locales > 0 ? round(($visitados / $locales) * 100, 1) : 0;
    $ratioG = $locales > 0 ? round(($gestionados / $locales) * 100, 1) : 0;

    // Opcional: acortar nombre si es muy largo (para que no reviente el eje Y)
    if (mb_strlen($nombre) > 45) {
        $nombre = mb_substr($nombre, 0, 45) . '...';
    }

    $labelsChart[] = $nombre;
    $dataVisitados[] = $ratioV;
    $dataGestionados[] = $ratioG;
}

// Para JS seguro
$labelsChartJson = json_encode($labelsChart, JSON_UNESCAPED_UNICODE);
$dataVisitadosJson = json_encode($dataVisitados);
$dataGestionadosJson = json_encode($dataGestionados);

$conn->close();
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
      Panel de Control - Campañas
    </h1>
    <p class="mb-0">Bienvenido, <?= htmlspecialchars($nombreU.' '.$apellido); ?>.</p>
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

        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-success w-100 btn-filtrar">
            <i class="fas fa-filter"></i> Filtrar
          </button>
        </div>
      </form>

      <hr>

      <?php
        $totalCampanas = count($campanas);
        $totalLocalesAsignados = 0;
        $totalLocalesVisitados = 0;
        $totalLocalesGestionados = 0;
        
        foreach ($campanas as $c) {
            $totalLocalesAsignados  += (int)$c['locales_asignados'];
            $totalLocalesVisitados  += (int)$c['locales_visitados'];
            $totalLocalesGestionados+= (int)$c['locales_gestionados'];
        }
        
        $ratioVisitadosTotal = $totalLocalesAsignados > 0
            ? round(($totalLocalesVisitados / $totalLocalesAsignados) * 100, 1)
            : 0;
        
        $ratioGestionadosTotal = $totalLocalesAsignados > 0
            ? round(($totalLocalesGestionados / $totalLocalesAsignados) * 100, 1)
            : 0;
      ?>

      <div class="row mb-4">
        <div class="col-md-3">
          <div class="kpi-card">
            <i class="fas fa-bullhorn"></i>
            <div>
              <div class="kpi-title">Campañas Activas</div>
              <div class="kpi-value"><?= $totalCampanas ?></div>
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

<?php if (!empty($campanas)): ?>
<div class="table-responsive">
  <table id="tablaCampanas" class="table table-striped table-bordered table-hover">
    <thead class="thead-dark">
      <tr>
        <th>Campaña</th>
        <th>Inicio</th>
        <th>Término</th>
        <th class="text-center">Locales Asignados</th>
        <th class="text-center">Visitados</th>
        <th class="text-center">Gestionados</th>
        <th class="text-center">% Visitados</th>
        <th class="text-center">% Gestionados</th>
        <th class="text-center" style="width:140px;">Dashboard</th>
      </tr>
    </thead>
    <tbody>

        <?php foreach ($campanas as $c):
    
            $idCamp = (int)$c['id'];
            $nombreCamp = htmlspecialchars($c['nombre_campana']);
            $fechaInicio = date('d-m-Y', strtotime($c['fechaInicio']));
            $fechaTermino = date('d-m-Y', strtotime($c['fechaTermino']));
    
            $locales = (int)$c['locales_asignados'];
            $visitados = (int)$c['locales_visitados'];
            $gestionados = (int)$c['locales_gestionados'];
    
            $ratioV = $locales > 0 ? round(($visitados / $locales) * 100, 1) : 0;
            $ratioG = $locales > 0 ? round(($gestionados / $locales) * 100, 1) : 0;
        ?>
    
          <tr>
            <td class="font-weight-bold"><?= $nombreCamp ?></td>
            <td><?= $fechaInicio ?></td>
            <td><?= $fechaTermino ?></td>
    
            <td class="text-center"><?= $locales ?></td>
            <td class="text-center"><?= $visitados ?></td>
            <td class="text-center"><?= $gestionados ?></td>
    
            <td class="text-center" data-order="<?= $ratioV ?>">
              <span class="ratio-cell">
                <?= iconRatio($ratioV) ?>
                <span class="ratio-text"><?= number_format($ratioV, 1, ',', '.') ?>%</span>
              </span>
            </td>
            
            <td class="text-center" data-order="<?= $ratioG ?>">
              <span class="ratio-cell">
                <?= iconRatio($ratioG) ?>
                <span class="ratio-text"><?= number_format($ratioG, 1, ',', '.') ?>%</span>
              </span>
            </td>
    
            <td class="text-center">
              <a href="dashboard_classic.php?id=<?= $idCamp ?>" 
                 class="btn btn-primary btn-sm">
                <i class="fas fa-chart-pie"></i> Ver
              </a>
            </td>
        </tr> 
    <?php endforeach; ?>
</tbody>            
      </table>
    </div>
    <?php else: ?>
      <div class="alert alert-warning">
        No existen campañas activas para esta división.
      </div>
    <?php endif; ?>
    
        <?php if (!empty($campanas)): ?>
          <div class="card mt-4">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="mb-0">
                  <i class="fas fa-chart-bar"></i> Avance por campaña
                </h5>
                <small class="text-muted">(% sobre locales asignados)</small>
              </div>
        
              <?php
                // Altura dinámica: mientras más campañas, más alto el canvas
                $altura = max(220, count($campanas) * 28);
              ?>
        
              <div style="height: <?= $altura ?>px;">
                <canvas id="chartCampanas"></canvas>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
$(document).ready(function() {

    // 🔹 Plugin para ordenar fechas formato dd-mm-yyyy
    $.fn.dataTable.ext.type.order['date-eu-pre'] = function (d) {
        if (!d) return 0;
        var parts = d.split('-');
        return new Date(parts[2], parts[1] - 1, parts[0]).getTime();
    };

    $('#tablaCampanas').DataTable({
        order: [[0, "asc"]], // orden inicial por Campaña
        pageLength: 25,
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json"
        },
        columnDefs: [
            { type: 'date-eu', targets: [1,2] },  // columnas Inicio y Término
            { type: 'num', targets: [6,7] },      // % Visitados y % Gestionados
            { orderable: false, targets: 8 }      // bloquea Dashboard
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
(function() {

  const labels = <?= $labelsChartJson ?? '[]' ?>;
  const dataVisitados = <?= $dataVisitadosJson ?? '[]' ?>;
  const dataGestionados = <?= $dataGestionadosJson ?? '[]' ?>;

  const el = document.getElementById('chartCampanas');
  if (!el || !labels.length) return;

  // -------- PLUGIN PARA MOSTRAR % AL FINAL --------
  const valueLabelsPlugin = {
    id: 'valueLabelsPlugin',
    afterDatasetsDraw(chart, args, pluginOptions) {

      const { ctx } = chart;
      ctx.save();

      const fontSize = pluginOptions?.fontSize ?? 12;
      ctx.font = `${fontSize}px Arial`;
      ctx.fillStyle = pluginOptions?.color ?? '#2b2b2b';
      ctx.textBaseline = 'middle';

      chart.data.datasets.forEach((dataset, datasetIndex) => {

        const meta = chart.getDatasetMeta(datasetIndex);
        if (meta.hidden) return;

        meta.data.forEach((bar, index) => {

          const value = dataset.data[index];
          if (value === null || value === undefined) return;

          const x = bar.x + 8;  // separación desde la barra
          const y = bar.y;

          ctx.fillText(value + '%', x, y);
        });
      });

      ctx.restore();
    }
  };

  // -------- CREACIÓN DEL GRÁFICO --------
  new Chart(el, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: '% Visitados',
          data: dataVisitados,
          backgroundColor: '#76B82A', // Verde MC
          borderWidth: 0
        },
        {
          label: '% Gestionados',
          data: dataGestionados,
          backgroundColor: '#8B5E3C', // Café
          borderWidth: 0
        }
      ]
    },
    plugins: [valueLabelsPlugin],
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,

      layout: {
        padding: {
          right: 40   // 👈 espacio para que no se corten los %
        }
      },

      scales: {
        x: {
          min: 0,
          max: 100,
          ticks: {
            callback: (v) => v + '%'
          }
        },
        y: {
          ticks: {
            autoSkip: false
          }
        }
      },

      plugins: {
        legend: {
          position: 'top'
        },
        tooltip: {
          callbacks: {
            label: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.x + '%'
          }
        },
        valueLabelsPlugin: {
          fontSize: 12,
          color: '#333'
        }
      }
    }
  });

})();
</script>
</body>
</html>
<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($conn) || $conn->connect_error) {
    die('Error BD: ' . $conn->connect_error);
}

// 🔹 Validar ID campaña
$idCampana = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($idCampana <= 0) {
    die('ID de campaña inválido');
}

// 🔹 Validar división (opcional pero recomendable)
$division = isset($_GET['division']) ? intval($_GET['division']) : 0;

// 🔹 Seguridad extra: validar que campaña pertenece a empresa sesión
$idEmpresa = intval($_SESSION['empresa_id'] ?? 0);

$sqlValida = "
    SELECT id, nombre
    FROM formulario
    WHERE id = ?
      AND id_empresa = ?
      AND estado IN (1,3)
    LIMIT 1
";

$stmtVal = $conn->prepare($sqlValida);
$stmtVal->bind_param("ii", $idCampana, $idEmpresa);
$stmtVal->execute();
$resVal = $stmtVal->get_result();
$campana = $resVal->fetch_assoc();
$stmtVal->close();

if (!$campana) {
    die('Campaña no válida o no pertenece a su empresa.');
}

$nombreCampana = $campana['nombre'];

$sqlHeader = "
SELECT 
    MAX(fq.fechaVisita) AS ultimaGestion
FROM formularioQuestion fq
WHERE fq.id_formulario = ?
";

$stmt = $conn->prepare($sqlHeader);
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$res = $stmt->get_result();
$headerData = $res->fetch_assoc();
$stmt->close();

$ultimaGestion = $headerData['ultimaGestion'] ?? null;

$fechaActualizacion = $ultimaGestion 
    ? date('d/m/Y H:i', strtotime($ultimaGestion)) 
    : 'Sin gestión registrada';

$sqlIndicadores = "
SELECT 
    f.id,
    f.nombre,
    CASE f.estado 
        WHEN 1 THEN 'Activo'
        WHEN 3 THEN 'Finalizado'
        ELSE 'Otro'
    END AS estado,
    DATE(f.fechaInicio) AS fechaInicio,
    DATE(f.fechaTermino) AS fechaTermino,
    e.nombre AS nombre_empresa,

    COUNT(DISTINCT fq.id_usuario) AS usuarios,
    COUNT(DISTINCT r.id) AS regiones,
    COUNT(DISTINCT c.id) AS comunas,

    COUNT(DISTINCT fq.id_local) AS totalLocales,

    COUNT(DISTINCT CASE
        WHEN fq.countVisita > 0
        THEN fq.id_local
    END) AS cantidadVisitados,
    
    COUNT(DISTINCT CASE
        WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado')
             AND CAST(NULLIF(fq.valor,'') AS DECIMAL(10,2)) > 0
        THEN fq.id_local
    END) AS cantidadImplementados,
    
    COUNT(DISTINCT CASE
        WHEN fq.countVisita > 0
             AND (
                 CAST(NULLIF(fq.valor,'') AS DECIMAL(10,2)) = 0
                 OR fq.observacion LIKE '%no_permitieron%'
                 OR fq.observacion LIKE '%sin_material%'
                 OR fq.observacion LIKE '%local_cerrado%'
             )
        THEN fq.id_local
    END) AS noEjecutado

FROM formulario f
JOIN empresa e ON e.id = f.id_empresa
JOIN formularioQuestion fq ON fq.id_formulario = f.id
JOIN local l ON l.id = fq.id_local
JOIN comuna c ON c.id = l.id_comuna
JOIN region r ON r.id = c.id_region

WHERE f.id = ?
GROUP BY 
    f.id,
    f.nombre,
    f.estado,
    f.fechaInicio,
    f.fechaTermino,
    e.nombre
";

$stmt = $conn->prepare($sqlIndicadores);
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$result = $stmt->get_result();
$indicadores = $result->fetch_assoc();
$stmt->close();

$total = (int)$indicadores['totalLocales'];
$visitados = (int)$indicadores['cantidadVisitados'];
$implementados = (int)$indicadores['cantidadImplementados'];
$noEjecutado = (int)$indicadores['noEjecutado'];
$noImplementados = max(0, $visitados - $implementados);

$pendientes = $total - $visitados;
$noVisitados = max(0, $total - $visitados);

$visitadoRatio = $total > 0 ? round(($visitados * 100) / $total) : 0;
$implementacionRatio = $total > 0 ? round(($implementados * 100) / $total) : 0;
$pendientesRatio = $total > 0 ? round(($pendientes * 100) / $total) : 0;

$colorVisitado = $visitadoRatio >= 80 ? '#2E7D32' : '#C62828';


// Pendientes por visitar (countVisita = 0)
$sqlPendientes = "
SELECT
  COUNT(DISTINCT CASE WHEN fq.countVisita = 0 THEN fq.id_local END) AS total_pendientes
FROM formularioQuestion fq
WHERE fq.id_formulario = ?
";

$stmtP = $conn->prepare($sqlPendientes);
$stmtP->bind_param('i', $idCampana);
$stmtP->execute();
$resP = $stmtP->get_result();
$p = $resP->fetch_assoc();
$stmtP->close();

$totalPendientes = (int)($p['total_pendientes'] ?? 0);

$enProceso = $totalPendientes;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Esqueleto Dashboard</title>
<link rel="stylesheet" href="/visibility2/portal/css/dashboard_styles.css">
<link rel="stylesheet" href="/visibility2/portal/css/dashboard_graficos.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
	
</head>
<body>

<div class="container">

    <!-- CABECERA -->
    <div class="cabecera-pro">
    
        <div class="cabecera-logo">
            <img src="/visibility2/portal/images/logo/Logo_MENTE CREATIVA-03.png" alt="Logo">
        </div>
    
        <div class="cabecera-texto">
            <div class="cabecera-titulo-principal">
                <?php echo strtoupper($nombreCampana); ?>
            </div>
    
            <div class="cabecera-subtitulo">
                DASHBOARD EJECUCIÓN
            </div>
    
            <div class="cabecera-actualizacion">
                Información actualizada al <?php echo $fechaActualizacion; ?>
            </div>
        </div>
    
    </div>

        <!-- KPI -->
        <div class="kpi-horizontal">
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS PROGRAMADAS</div>
                    <div class="kpi-valor" ><?php echo number_format($total); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-route"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS VISITADAS</div>
                    <div class="kpi-valor"><?php echo number_format($visitados); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS EJECUTADAS</div>
                    <div class="kpi-valor"><?php echo number_format($implementados); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS PENDIENTES</div>
                    <div class="kpi-valor"><?php echo number_format($pendientes); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">% VISITADAS</div>
                    <div class="kpi-valor" style="color:<?php echo $colorVisitado; ?>"><?php echo $visitadoRatio; ?>%</div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">% EJECUTADAS</div>
                    <div class="kpi-valor" style="color:<?php echo $colorVisitado; ?>"><?php echo $implementacionRatio; ?>%</div>
                </div>
            </div>
        
        </div>

<!-- KPI + GRÁFICOS -->
<div class="bloque-superior">

    <div class="graficos">

        <!-- 1 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LAS SALAS PROGRAMADAS: 
                <strong><?php echo $total; ?></strong>
                <div class="grafico-subtitulo">
                    (Estado del total de locales planificados)
                </div>                
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoVisitados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaVisitados"></div>
            </div>
        </div>

        <!-- 2 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES PENDIENTES POR VISITAR: 
                <strong><?php echo $totalPendientes; ?></strong>
                <div class="grafico-subtitulo">
                    (Total de locales pendientes por ser visitados)
                </div>                  
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoPendientes"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaPendientes"></div>
            </div>
        </div>

        <!-- 3 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES VISITADOS: 
                <strong><?php echo $visitados; ?></strong>
                <div class="grafico-subtitulo">
                    (Estado del total de locales implementados y no implementados)
                </div>                  
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoImplementados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaImplementados"></div>
            </div>
        </div>

        <!-- 4 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES QUE NO PUDIERON SER IMPLEMENTADOS: 
                <strong><?php echo $noEjecutado; ?></strong>
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoNoImplementados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaNoImplementados"></div>
            </div>
        </div>

    </div>
</div>

    <!-- MATERIALES -->
	<div class="materiales">

		<div class="grafico-titulo">
			MATERIALES IMPLEMENTADOS
		</div>

		<div class="materiales-chart">
			<canvas id="graficoMateriales"></canvas>
		</div>

	</div>

    <!-- MATRIZ -->
    <div class="matriz">
        DIV MATRIZ
    </div>

    <!-- IMÁGENES -->
    <div class="imagenes">
        DIV IMÁGENES
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

<!--LEYENDAS-->
<script>
function crearLeyenda(idContenedor, labels, colores) {
    const contenedor = document.getElementById(idContenedor);
    contenedor.innerHTML = '';

    labels.forEach((label, index) => {
        const item = document.createElement('div');
        item.className = 'leyenda-item';

        const dot = document.createElement('span');
        dot.className = 'leyenda-dot';
        dot.style.backgroundColor = colores[index];

        const texto = document.createElement('span');
        texto.textContent = label;

        item.appendChild(dot);
        item.appendChild(texto);
        contenedor.appendChild(item);
    });
}
</script>

<!--VISITADOS-->
<script>
const labelsVisitados = ['Visitados', 'No visitados'];
const coloresVisitados = ['#94C23D', '#D0D0D0'];

const visitados = <?= (int)$visitados ?>;
const noVisitados = <?= (int)$noVisitados ?>;
const totalReal = visitados + noVisitados; // asegura consistencia del donut

const ctxVisitados = document.getElementById('graficoVisitados').getContext('2d');

new Chart(ctxVisitados, {
  type: 'doughnut',
  data: {
    labels: labelsVisitados,
    datasets: [{
      data: [visitados, noVisitados],
      backgroundColor: coloresVisitados,
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#fff',
        font: { weight: 'bold', size: 13 },
        formatter: (value) => {
          if (!totalReal) return '';
          const porcentaje = Math.round((value * 100) / totalReal);
          return porcentaje + '%';
        }
      },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const value = ctx.raw ?? 0;
            const porcentaje = totalReal ? Math.round((value * 100) / totalReal) : 0;
            return `${ctx.label}: ${value} (${porcentaje}%)`;
          }
        }
      }
    }
  },
  plugins: [ChartDataLabels]
});

crearLeyenda('leyendaVisitados', labelsVisitados, coloresVisitados);
</script>

<!--NO VISITADOS-->
<script>
const labelsPendientes = ['En proceso'];
const coloresPendientes = ['#D0D0D0'];

const cantPend = <?= (int)$enProceso ?>; // cantidad real (ej: 17)

// Si hay pendientes, el donut debe ser 100%. Si no, 0.
const dataPendPorc = (cantPend > 0) ? [100] : [0];

const ctxPendientes = document.getElementById('graficoPendientes').getContext('2d');

new Chart(ctxPendientes, {
  type: 'doughnut',
  data: {
    labels: labelsPendientes,
    datasets: [{
      data: dataPendPorc,
      backgroundColor: coloresPendientes,
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#fff',
        font: { weight: 'bold', size: 13 },
        // Si no hay pendientes, no mostramos etiqueta
        formatter: (value) => (cantPend > 0 ? value + '%' : '')
      },
      tooltip: {
        callbacks: {
          label: () => `En proceso: ${cantPend}`
        }
      }
    }
  },
  plugins: [ChartDataLabels]
});

// Leyenda (solo si hay pendientes, si quieres siempre visible quita el if)
if (cantPend > 0) {
  crearLeyenda('leyendaPendientes', labelsPendientes, coloresPendientes);
} else {
  document.getElementById('leyendaPendientes').innerHTML = '<span style="color:#777;">Sin pendientes</span>';
}
</script>

<!--IMPLEMENTADOS-->
<script>
const labelsImplementados = ['Implementado', 'No implementado'];
const coloresImplementados = ['#94C23D', '#D0D0D0'];

const implementados = <?= (int)$implementados ?>;
const noImplementados = <?= (int)$noImplementados ?>;

const totalVisitados = implementados + noImplementados;

// Si no hay visitados, dibujamos un gris vacío
const dataFinal = totalVisitados > 0 
    ? [implementados, noImplementados] 
    : [1];

const labelsFinal = totalVisitados > 0 
    ? labelsImplementados 
    : ['Sin datos'];

const coloresFinal = totalVisitados > 0 
    ? coloresImplementados 
    : ['#E0E0E0'];

const ctx = document.getElementById('graficoImplementados');

if (ctx) {
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labelsFinal,
      datasets: [{
        data: dataFinal,
        backgroundColor: coloresFinal,
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: {
        legend: { display: false },
        datalabels: {
          color: '#fff',
          font: { weight: 'bold', size: 13 },
          formatter: (value) => {
            if (!totalVisitados) return '';
            const porcentaje = Math.round((value * 100) / totalVisitados);
            return porcentaje + '%';
          }
        },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              if (!totalVisitados) return 'Sin datos';
              const value = ctx.raw ?? 0;
              const porcentaje = Math.round((value * 100) / totalVisitados);
              return `${ctx.label}: ${value} (${porcentaje}%)`;
            }
          }
        }
      }
    },
    plugins: [ChartDataLabels]
  });

  if (totalVisitados > 0) {
    crearLeyenda('leyendaImplementados', labelsImplementados, coloresImplementados);
  } else {
    document.getElementById('leyendaImplementados').innerHTML = 
      '<span style="color:#777;">Sin locales visitados</span>';
  }
}
</script>

<script>
const labelsNoImplementados = [
  'No permitieron',
  'Sin productos',
  'Local no existe'
];

const coloresNoImplementados = [
  '#9E9E9E', // gris oscuro - No permitieron
  '#D0D0D0', // gris claro  - Sin productos
  '#B0B0B0'  // gris medio  - Local no existe
];

const ctxNoImplementados = document
  .getElementById('graficoNoImplementados')
  .getContext('2d');

new Chart(ctxNoImplementados, {
  type: 'doughnut',
  data: {
    labels: labelsNoImplementados,
    datasets: [{
      data: [45, 35, 20], // <-- ajusta % aquí (deben sumar 100)
      backgroundColor: coloresNoImplementados,
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#ffffff',
        font: { weight: 'bold', size: 13 },
        formatter: (value) => value + '%'
      }
    }
  },
  plugins: [ChartDataLabels]
});

// Leyenda externa
crearLeyenda(
  'leyendaNoImplementados',
  labelsNoImplementados,
  coloresNoImplementados
);
</script>

<script>
const ctxMateriales = document.getElementById('graficoMateriales').getContext('2d');

new Chart(ctxMateriales, {
    type: 'bar',
    data: {
        labels: [
            'Payloaer',
            'Flejera',
            'Vibrin',
            'Stopper',
            'Afiche',
            'Bandera tradicional',
            'Bandera ruta'
        ],
        datasets: [{
            label: 'Cantidad',
            data: [120, 95, 80, 60, 150, 70, 55], // ← AJUSTA VALORES
            backgroundColor: '#2E7D32',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            datalabels: {
                anchor: 'end',
                align: 'top',
                color: '#2E7D32',
                font: {
                    weight: 'bold',
                    size: 12
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: '#555',
                    font: {
                        size: 11
                    }
                },
                grid: {
                    display: false
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#555'
                },
                grid: {
                    color: '#eee'
                }
            }
        }
    },
    plugins: [ChartDataLabels]
});
</script>


</body>
</html>

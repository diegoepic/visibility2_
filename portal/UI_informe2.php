<?php
// Habilitar la visualización de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar la sesión
session_start();

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

// Incluir la conexión a la base de datos
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php'; 

// Asegurarse de tener definida la variable $idForm (puede venir de GET, POST o de otro origen)
$idForm = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Definir la consulta de indicadores
$Indicadores = "
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
    COUNT(DISTINCT c.id_region) AS regiones,
    COUNT(DISTINCT l.id_comuna) AS comunas,
        COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita))) AS locales,
        COUNT(DISTINCT CASE 
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','cancelado')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
        ) AS cantidadVisitados,
    SUM(
        CASE 
            WHEN fq.fechaVisita = '0000-00-00 00:00:00'
            THEN 1 
            ELSE 0 
        END
    ) AS cantidadPendientes,    
        COUNT(DISTINCT CASE
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
        ) AS cantidadImplementados,
        COUNT(DISTINCT CASE 
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','cancelado')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
    ) * 100 / COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita))) AS visitado_ratio,
    
    COUNT(DISTINCT CASE 
            WHEN fq.fechaVisita = '0000-00-00 00:00:00'
            THEN CONCAT(l.codigo, fq.fechaVisita) 
            ELSE 0 
        END
    ) * 100 / COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita))) AS pendientes_ratio,  
    
        COUNT(DISTINCT CASE
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
    ) * 100 / COUNT(DISTINCT CONCAT(fq.id_local, DATE(fq.fechaVisita))) AS implementacion_ratio,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%local_no_existe%' 
              OR fq.observacion LIKE '%sin_material%'
              OR fq.observacion LIKE '%no_permitieron%'
              OR fq.observacion LIKE '%local_cerrado%'
            THEN CONCAT(l.codigo, fq.fechaVisita)
            ELSE 0
        END
    ) AS NoEjecutado,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%local_no_existe%'
            THEN 1
            ELSE 0
        END
    ) AS NoExiste,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%sin_material%'
            THEN CONCAT(l.codigo, fq.fechaVisita)
            ELSE 0
        END
    ) AS SinMaterial,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%no_permitieron%'
            THEN CONCAT(l.codigo, fq.fechaVisita)
            ELSE 0
        END
    ) AS NoPermitieron,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%local_cerrado%'
            THEN CONCAT(l.codigo, fq.fechaVisita)
            ELSE 0
        END
    ) AS Cerrado    
FROM formulario f
INNER JOIN empresa e ON e.id = f.id_empresa
INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN comuna c ON c.id = l.id_comuna
INNER JOIN region r ON r.id = c.id_region
WHERE f.id = $idForm
GROUP BY 
    f.id, 
    f.nombre, 
    f.estado,    
    f.fechaInicio, 
    f.fechaTermino, 
    e.nombre
ORDER BY f.fechaInicio ASC;
";

// Ejecutar la consulta (la función "consulta" debe estar definida en tu conexión o en otro archivo incluido)
$IndicadoresResult = consulta($Indicadores);

// Ejecutar la query para el gráfico de barras

// Ejecutar la query para el gráfico de barras
$queryBar = "
SELECT 
    r.region,
        COUNT(DISTINCT CASE 
            WHEN fq.observacion LIKE '%local_no_existe%' 
              OR fq.observacion LIKE '%sin_material%'
              OR fq.observacion LIKE '%no_permitieron%'
              OR fq.observacion LIKE '%local_cerrado%'
            THEN CONCAT(l.codigo, fq.fechaVisita)
            ELSE 0
        END
    ) AS NoEjecutado,    
        COUNT(DISTINCT CASE
             WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria')
             THEN CONCAT(l.codigo, fq.fechaVisita)
             END
        ) AS cantidadImplementados    
FROM formulario f
INNER JOIN empresa e ON e.id = f.id_empresa
INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN comuna c ON c.id = l.id_comuna
INNER JOIN region r ON r.id = c.id_region
WHERE f.id = $idForm
GROUP BY r.region
";

// Reemplaza temporalmente consulta() por mysqli_query()
$barChartResult = mysqli_query($conn, $queryBar);
if (!$barChartResult) {
    die("Error en la consulta: " . mysqli_error($conn));
}

$regions = [];
$noEjecutadoData = [];
$implementadosData = [];

while($row = mysqli_fetch_assoc($barChartResult)) {
    $regions[] = $row['region'];
    $noEjecutadoData[] = $row['NoEjecutado'];
    $implementadosData[] = $row['cantidadImplementados'];
}


$queryTable = "
SELECT 
    l.codigo AS codigo_local,
    f.nombre AS nombreCampaña,
    cu.nombre AS cuenta,
    ca.nombre AS cadena,            
    DATE(f.fechaInicio) AS fechaInicio,
    DATE(f.fechaTermino) AS fechaTermino,
    DATE(fq.fechaVisita) AS fechaVisita,
    TIME(fq.fechaVisita) AS hora,            
    l.nombre AS nombre_local,
    l.direccion AS direccion_local,
    cm.comuna AS comuna,
    re.region AS region,
    CASE
        WHEN fq.pregunta IN ('en proceso', 'cancelado')
            THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion, '|', '-'), '-', 1))
        ELSE fq.pregunta
    END AS estado,
    u.usuario AS Ejecutor,            
    fq.material,
    fq.valor,            
    fq.valor_propuesto,
    fq.observacion
FROM formularioQuestion fq
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN formulario f ON f.id = fq.id_formulario
INNER JOIN usuario u ON fq.id_usuario = u.id
INNER JOIN cuenta cu ON l.id_cuenta = cu.id
INNER JOIN cadena ca ON l.id_cadena = ca.id
INNER JOIN comuna cm ON l.id_comuna = cm.id
INNER JOIN region re ON cm.id_region = re.id
WHERE f.id = $idForm
ORDER BY l.codigo, fq.fechaVisita ASC;
";

// Ejecutar la consulta
$resultTable = mysqli_query($conn, $queryTable);
if (!$resultTable) {
    die("Error en la consulta: " . mysqli_error($conn));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Visibility Web | Dashboard</title>
  
  <!-- Estilos -->
  <link rel="stylesheet" type="text/css" href="assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="assets/css/dataTable.css">
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>  
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="icon" type="image/png" href="favicon.png?v=2">
  
  <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/PptxGenJS/3.16.0/pptxgen.bundle.js"></script>  
  
  <style type="text/css">
      button {
      border-radius: 10px; /* Ajusta el valor para más o menos redondeo */
      padding: 10px 20px;
      font-size: 14px;
      cursor: pointer;
      background-color: #007bff; /* Color de fondo, cámbialo si lo deseas */
      color: #fff;
      border: none;
      /* Para posicionarlo a la derecha */
      float: right;
      margin: 10px;
    }
  </style>
</head>
<body>
  <!-- Preloader -->
  <div id="preloader">
    <div class="spinner"></div>
  </div>
  
  <div class="wrapper">
      <div id="exportArea">
    <!-- Tarjeta de reporte de avance -->
    <div class="row">
      <div class="col-12">
        <div class="card" id="cardMarketing">
          <div class="card-header">
            <h3 class="card-title">
              <i class="far fa-chart-bar"></i> REPORTE DE AVANCE 
            </h3>
              <button id="btnExportPngPdf">Exportar PNG</button>
          </div>
          <!-- /.card-header -->
          <div class="card-body">
            <div class="row" id="filaCampañas">
              <div class="col-sm-2 col-6">
                <img src="dist/img/Logo_MENTE CREATIVA-01.png" alt="User Avatar" style="width: 55%; height: 110px;">
              </div>
              <div class="col-sm-7 col-6">
                <center>
                  <div class="widget-user-header">
                    <h5 class="widget-user-desc nombre-campana" style="margin-top: 3%; margin-left: 10px;">
                      RESUMEN - <b><?= $IndicadoresResult[0]["nombre"] ?></b> / Estado: <?= $IndicadoresResult[0]["estado"] ?>
                    </h5>
                  </div>
                </center>
              </div>
            </div> <!-- /#filaCampañas -->
          </div> <!-- /.card-body -->
        </div> <!-- /.card -->
      </div> <!-- /.col-12 -->
    </div> <!-- /.row -->
    
    <!-- Sección de indicadores en el footer -->
    <div class="card-body">
      <div class="card-footer" style="background-color: rgba(0,0,0,0)!important;">
        <div class="row">
          <!-- FECHA INICIO -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">FECHA INICIO</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["fechaInicio"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- FECHA TERMINO -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">FECHA TERMINO</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["fechaTermino"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- REGIONES -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">REGIONES</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["regiones"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- COMUNAS -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">COMUNAS</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["comunas"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- MERCHANDISING -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">MERCHANDISING</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["usuarios"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- OBJETIVOS -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">OBJETIVOS</span>                        
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["locales"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- VISITADOS -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">VISITADOS</span>                  
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["cantidadVisitados"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- EJECUTADOS -->
          <div class="col-sm-1 col-6 border-right" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">EJECUTADOS</span>      
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?=$IndicadoresResult[0]["cantidadImplementados"]?>
                  </b>
                </h5>
              </span>
            </div>
          </div>
          <!-- % AVANCE LOCALES -->
          <div class="col-sm-1 col-6" style="flex: 0 0 10.333333%; max-width: 10.333333%;">
            <div class="description-block">
              <span class="description-text" style="font-size:11px;">% AVANCE LOCALES</span>    
              <span class="description-percentage text-success">
                <h5 class="description-header">
                  <b style="color: yellowgreen; font-size: 14px; top:12px; position:relative;">
                    <?= number_format($IndicadoresResult[0]["implementacion_ratio"], 0, '.', '') ?>%
                  </b>
                </h5>
              </span>
            </div>
          </div>
        </div> <!-- /.row -->
      </div> <!-- /.card-footer -->
    </div> <!-- /.card-body -->
    
    <!-- Sección de gráficos -->
    <div class="card card-primary">
      <!-- Primera fila de gráficos -->
      <div class="row" style="margin-left:15%;">
        <div class="col-lg-5 col-6 text-center border-right border-bottom">
          <div class="pie-charts">
            <div class="pieID--micro-skills pie-chart--wrapper">
              <h2 style="text-align:left; font-size:11px;">
                ESTADO DE LOS LOCALES OBJETIVO / 
                <b style="color: yellowgreen; font-size: 14px; top:1px; position:relative; left:4px;">
                  <?=$IndicadoresResult[0]["locales"]?>
                </b>
              </h2>
              <div class="porcentaje">
                <?= number_format($IndicadoresResult[0]["visitado_ratio"], 0, '.', '') ?>%
              </div>
              <div class="pie-chart">
                <div class="pie-chart__pie"></div>
                <ul class="pie-chart__legend">
                  <li><em>No Visitado</em><span><?=$IndicadoresResult[0]["cantidadPendientes"]?></span></li>
                  <li><em>Visitado</em><span><?=$IndicadoresResult[0]["cantidadVisitados"]?></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-5 col-6 text-center border-bottom" style="margin-left:0;">
          <div class="pie-charts">
            <div class="pieID--categories pie-chart--wrapper">
              <h2 style="text-align:left; font-size:11px;">
                ESTADO DE LOS LOCALES QUE ESTAN EN PROCESO DE SER VISITADOS / 
                <b style="color: yellowgreen; font-size: 14px; top:1px; position:relative; left:4px;">
                  <?=$IndicadoresResult[0]["cantidadPendientes"]?>
                </b>
              </h2>
              <div class="porcentaje">
                <?= number_format($IndicadoresResult[0]["%pendientes"], 0, '.', '') ?>%
              </div>
              <div class="pie-chart">
                <div class="pie-chart__pie"></div>
                <ul class="pie-chart__legend">
                  <li><em>Por iniciar</em><span><?=$IndicadoresResult[0]["cantidadPendientes"]?></span></li>
                  <li><em>Visitado</em><span><?=$IndicadoresResult[0]["cantidadVisitados"]?></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div> <!-- /.row -->
      
      <!-- Segunda fila de gráficos -->
      <div class="row" style="margin-left:15%;">
        <div class="col-lg-5 col-6 text-center border-right border-bottom" style="margin-left:0;">
          <div class="pie-charts">
            <div class="pieID--implementaciones pie-chart--wrapper">
              <h2 style="text-align:left; font-size:11px;">
                ESTADO DE LOS LOCALES VISITADOS / 
                <b style="color: yellowgreen; font-size: 14px; top:1px; position:relative; left:4px;">
                  <?=$IndicadoresResult[0]["cantidadVisitados"]?>
                </b>
              </h2>
              <div class="porcentaje2">
                <?= number_format($IndicadoresResult[0]["implementacion_ratio"], 0, '.', '') ?>%
              </div>
              <div class="pie-chart">
                <div class="pie-chart__pie"></div>
                <ul class="pie-chart__legend">
                  <li><em>No Ejecutado</em><span><?=$IndicadoresResult[0]["NoEjecutado"]?></span></li>
                  <li><em>Ejecutado</em><span><?=$IndicadoresResult[0]["cantidadImplementados"]?></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-5 col-6 text-center border-bottom" style="margin-left:0;">
          <?php
            $allZero = (
              (isset($IndicadoresResult[0]["Cerrado"]) && $IndicadoresResult[0]["Cerrado"] == 0) &&
              (isset($IndicadoresResult[0]["NoPermitieron"]) && $IndicadoresResult[0]["NoPermitieron"] == 0) &&
              (isset($IndicadoresResult[0]["SinMaterial"]) && $IndicadoresResult[0]["SinMaterial"] == 0) &&
              (isset($IndicadoresResult[0]["NoExiste"]) && $IndicadoresResult[0]["NoExiste"] == 0)
            );
            $classSuffix = $allZero ? 'null' : '';
            $ulStyle = $allZero ? 'style="display: none;"' : '';
          ?>
          <div class="pieID--operations pie-chart--wrapper">
            <h2 style="text-align:left; font-size:11px;">
              MOTIVOS SALAS NO IMPLEMENTADAS / 
              <b style="color: yellowgreen; font-size: 14px; top:1px; position:relative; left:4px;">
                <?=$IndicadoresResult[0]["NoEjecutado"]?>
              </b>
            </h2>
            <div class="pie-chart<?= $classSuffix ?>">
              <div class="pie-chart__pie<?= $classSuffix ?>"></div>
              <ul class="pie-chart__legend<?= $classSuffix ?>" <?= $ulStyle ?>>
                <li><em>Local cerrado</em><span><?=$IndicadoresResult[0]["Cerrado"]?></span></li>
                <li><em>No permitieron</em><span><?=$IndicadoresResult[0]["NoPermitieron"]?></span></li>
                <li><em>No hay productos</em><span><?=$IndicadoresResult[0]["SinMaterial"]?></span></li>
                <li><em>Local no existe</em><span><?=$IndicadoresResult[0]["NoExiste"]?></span></li>
              </ul>
            </div>
          </div>
        </div>
      </div> <!-- /.row -->
      <br>
      <!-- Contenedor para el gráfico de barras -->
      <div class="container" style="width: 65%;">
            <h3 class="card-title">
              AVANCE EJECUCIONES POR REGION
            </h3>          
         <div id="chart-two"></div>
      </div>
      <br>
  <table id="example" class="display nowrap" width="100%">
    <thead>
      <tr>
        <th>Código Local</th>
        <th>Fecha Visita</th>
        <th>Nombre Local</th>
        <th>Dirección Local</th>
        <th>Región</th>
        <th>Ejecutor</th>        
        <th>Estado</th>
        <th>Valor</th>        
        <th>Material</th>
        <th>Observación</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = mysqli_fetch_assoc($resultTable)): ?>
      <tr>
        <td><?= htmlspecialchars($row['codigo_local']); ?></td>
        <td><?= htmlspecialchars($row['fechaVisita']); ?></td>
        <td><?= htmlspecialchars($row['nombre_local']); ?></td>
        <td><?= htmlspecialchars($row['direccion_local']); ?></td>
        <td><?= htmlspecialchars($row['region']); ?></td>
        <td><?= htmlspecialchars($row['Ejecutor']); ?></td>        
        <td><?= htmlspecialchars($row['estado']); ?></td>
        <td><?= htmlspecialchars($row['valor']); ?></td>        
        <td><?= htmlspecialchars($row['material']); ?></td>
        <td><?= htmlspecialchars($row['observacion']); ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
  </div>
  <br>
  <iframe src="https://visibility.cl/visibility2/portal/modulos/mod_galeria/mod_galeria.php?id=<?= $idForm; ?>" width="100%" height="600" frameborder="0"></iframe>
   
    </div> <!-- /.card card-primary -->
    
  </div> <!-- /.wrapper -->
  
  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-2.2.4.min.js"></script>
  <script src="assets/js/graficoCircular.js"></script>
  <!-- dataTable -->  
<script src='https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/pdfmake.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/vfs_fonts.js'></script>
<!--<script src='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.js'> 
</script>  -->
  <script src="assets/js/datatables.min.js"></script>  
  <script src="assets/js/dataTable.js"></script>  
  
  <!-- grafico barra -->    
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>   
  <script>
    // Pasar los arrays PHP a variables JavaScript
    const regions = <?php echo json_encode($regions); ?>;
    const noEjecutadoData = <?php echo json_encode($noEjecutadoData); ?>;
    const implementadosData = <?php echo json_encode($implementadosData); ?>;
    
    // Debug: mostrar los datos en la consola
    console.log("Regiones:", regions);
    console.log("No Ejecutado:", noEjecutadoData);
    console.log("Implementados:", implementadosData);
    
    // Configurar el gráfico de barras con dos series
    const data = {
      chart: {
        type: 'bar'
      },
      series: [
        {
          name: 'No Ejecutado',
          data: noEjecutadoData
        },
        {
          name: 'Implementados',
          data: implementadosData
        }
      ],
      xaxis: {
        categories: regions
      }
    };
    
    const chartTwo = new ApexCharts(document.querySelector("#chart-two"), data);
    chartTwo.render();
  </script>
  <script>
    // Botón para exportar como PNG y PDF
    document.getElementById("btnExportPngPdf").addEventListener("click", function() {
      // Capturamos el contenedor deseado (en este ejemplo, el div con id "exportArea")
      html2canvas(document.getElementById("exportArea")).then(function(canvas) {
        // Exportar como PNG
        const pngUrl = canvas.toDataURL("image/png");
        const downloadLink = document.createElement("a");
        downloadLink.href = pngUrl;
        downloadLink.download = "export.png";
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
        
      });
    });

  </script>  
</body>
</html>

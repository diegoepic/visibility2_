<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
  header("Location: ../index.php");
  exit();
}

$id_empresa = intval($_SESSION['empresa_id']);
$idCampana  = intval($_GET['id'] ?? 0);

if ($idCampana <= 0) {
  die("Campaña inválida");
}

/**
 * Helper para escapar
 */
function e($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**Nombre campaña**/
$sqlCamp = "
  SELECT nombre
  FROM formulario
  WHERE id = ?
  AND id_empresa = ?
  LIMIT 1
";

$stmtCamp = $conn->prepare($sqlCamp);
$stmtCamp->bind_param("ii", $idCampana, $id_empresa);
$stmtCamp->execute();
$resCamp = $stmtCamp->get_result();
$camp = $resCamp->fetch_assoc();
$stmtCamp->close();

$nombreCampana = $camp['nombre'] ?? 'Campaña';

/**
 * Query matriz campaña
 * Ajusta nombres de tablas/campos si difieren
 */
$sql = "
SELECT
    l.codigo                            AS codigo_local,
    l.nombre                            AS nombre_local,
    l.direccion                         AS direccion,
    c.comuna                            AS comuna,
    r.region                            AS region,
    CONCAT(u.nombre,' ',u.apellido)     AS nombreUsuario,
    UPPER(u.usuario)                    AS usuario,    
    fq.material                         AS material,
    date(fq.fechaVisita)                AS fechaVisita,    
    CASE 
        WHEN MAX(fq.countVisita) > 0 
            THEN 'VISITADO'
        ELSE 'NO VISITADO'
    END                                 AS estado_visita,
    CASE
        -- Si la cantidad implementada es mayor a 0 y la pregunta es implementado
        WHEN 
            MAX(CASE 
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado') 
                    THEN 1 ELSE 0 
                END) = 1
            AND
            MAX(CAST(NULLIF(fq.valor,'') AS DECIMAL(10,2))) > 0
        THEN 'IMPLEMENTADO'
    
        -- Auditoría
        WHEN MAX(CASE 
                    WHEN fq.pregunta = 'solo_auditoria' 
                    THEN 1 ELSE 0 
                END) = 1
        THEN 'AUDITORIA'
    
        ELSE 'NO IMPLEMENTADO'
    END AS estado_gestion,
    UPPER(
        CASE 
            WHEN fq.motivo IS NOT NULL AND fq.motivo <> '' THEN fq.motivo
            WHEN fq.observacion IS NOT NULL AND fq.observacion <> ''
                THEN 
                    CASE 
                        WHEN LOCATE('-', fq.observacion) > 0 
                            THEN TRIM(SUBSTRING_INDEX(fq.observacion, '-', 1))
                        ELSE fq.observacion
                    END
            ELSE ''
        END
    ) AS motivo,
    MAX(
      CAST(
        NULLIF(fq.valor, '') 
        AS DECIMAL(10,2)
      )
    ) AS cantidad_implementada,
    MAX(
      CAST(
        NULLIF(fq.valor_propuesto, '') 
        AS DECIMAL(10,2)
      )
    ) AS cantidad_planificada,
    UPPER(fq.observacion) AS observacion                                        
FROM formulario f
INNER JOIN formularioQuestion fq   ON fq.id_formulario = f.id
INNER JOIN local l                 ON l.id = fq.id_local
LEFT JOIN comuna c                 ON c.id = l.id_comuna
LEFT JOIN region r                 ON r.id = c.id_region
LEFT JOIN usuario u                ON u.id = fq.id_usuario

WHERE f.id = ?
  AND f.id_empresa = ?
  AND f.estado = 1

GROUP BY
    l.codigo,
    l.nombre,
    l.direccion,
    c.comuna,
    r.region,
    u.nombre,
    u.apellido,
    fq.material

ORDER BY l.codigo ASC, fq.material ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $idCampana, $id_empresa);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
  $rows[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Campaña</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" href="<?= '/visibility2/portal/css/mod_panel.css?v=' . time(); ?>">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css"> 

</head>
<body>

<div class="container-fluid mt-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="fas fa-chart-line"></i> Matriz campaña <?= htmlspecialchars($nombreCampana) ?></h4>
    <a href="mod_panel_campanas.php" class="btn btn-outline-secondary btn-sm">
      <i class="fas fa-arrow-left"></i> Volver
    </a>
  </div>

  <div class="card">
    <div class="card-body">
    <div class="table-wrapper">
    
        <table id="tablaDetalle" class="table table-striped table-bordered nowrap" style="width:100%">
          <thead class="thead-dark">
            <tr>
              <th>Código</th>
              <th>Local</th>
              <th>Dirección</th>
              <th>Comuna</th>
              <th>Región</th>
              <th>Usuario</th>
              <th>Fecha Visita</th>              
              <th>Material</th>
              <th>Estado Visita</th>
              <th>Estado Gestión</th>
              <th>Motivo</th>
              <th class="text-center">Cantidad</th>
              <th class="text-center">Cantidad Planificada</th>   
              <th>Observacion</th>              
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>

            <?php
              $ev = $r['estado_visita'];
              $eg = $r['estado_gestion'];

              $badgeVisita = ($ev === 'VISITADO') ? 'b-ok' : 'b-bad';

              $badgeGestion = 'b-bad';
              if ($eg === 'IMPLEMENTADO') $badgeGestion = 'b-ok';
              else if ($eg === 'AUDITORIA') $badgeGestion = 'b-warn';
            ?>

            <tr>
              <td class="font-weight-bold"><?= e($r['codigo_local']) ?></td>
              <td><?= e($r['nombre_local']) ?></td>
              <td><?= e($r['direccion']) ?></td>
              <td><?= e($r['comuna']) ?></td>
              <td><?= e($r['region']) ?></td>
              <td><?= e($r['usuario']) ?></td>
              <td><?= e($r['fechaVisita']) ?></td>              
              <td><?= e($r['material']) ?></td>
              <td><span class="badge-soft <?= $badgeVisita ?>"><?= e($ev) ?></span></td>
              <td><span class="badge-soft <?= $badgeGestion ?>"><?= e($eg) ?></span></td>
              <td><?= e($r['motivo']) ?></td>
              <td class="text-center"><?= (int)$r['cantidad_implementada'] ?></td>
              <td class="text-center"><?= (int)$r['cantidad_planificada'] ?></td>
              <td><?= e($r['observacion']) ?></td>              
            </tr>
          <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>
    </div>
</div>    

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script>
$(document).ready(function() {

  DataTable.Buttons.jszip(JSZip);

  const table = $('#tablaDetalle').DataTable({
    pageLength: 25,
    scrollX: true,
    autoWidth: false,
    order: [[0,'asc']],

    dom: "<'row mb-2'<'col-md-8'B><'col-md-4'f>>" +
         "<'row'<'col-md-12'tr>>" +
         "<'row'<'col-md-5'i><'col-md-7'p>>",

    buttons: [

      // 🔹 Exportar Excel
        {
          extend: 'excelHtml5',
          text: '<i class="fas fa-file-excel"></i> Excel',
          className: 'btn btn-success btn-sm mr-2',
          title: '<?= preg_replace("/[^A-Za-z0-9_\-]/", "_", $nombreCampana) ?>_' 
                 + new Date().toISOString().slice(0,10),
        
          exportOptions: {
            columns: function (idx, data, node) {
              return true;   // exporta todas incluso ocultas
            },
            format: {
              body: function (data, row, column, node) {
                if (typeof data === 'string') {
                  return data
                    .replace(/<[^>]*>/g, '')
                    .replace(/\n/g, ' ')
                    .trim();
                }
                return data;
              }
            }
          }
        },

      // 🔹 Mostrar / ocultar columnas
      {
        extend: 'colvis',
        text: '<i class="fas fa-columns"></i> Columnas',
        className: 'btn btn-secondary btn-sm',
        columns: ':not(.noVis)' // opcional si luego quieres excluir alguna
      }

    ],

    columnDefs: [
      { targets: [2,3,4], visible: false }  // Dirección, Comuna, Región ocultas
    ],

    language: {
      url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json"
    },

    initComplete: function() {

      const scrollBody = $('.dataTables_scrollBody');
      const scrollWrapper = $('.dataTables_scroll');

      const topScroll = $('<div class="dt-scroll-top"><div></div></div>');
      scrollWrapper.prepend(topScroll);

      const updateWidth = function() {
        topScroll.find('div').width(
          scrollBody.get(0).scrollWidth
        );
      };

      updateWidth();

      topScroll.on('scroll', function() {
        scrollBody.scrollLeft($(this).scrollLeft());
      });

      scrollBody.on('scroll', function() {
        topScroll.scrollLeft($(this).scrollLeft());
      });

      $(window).on('resize', updateWidth);
    }

  });

});
</script>
</body>
</html>
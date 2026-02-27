<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$id_empresa  = intval($_SESSION['empresa_id']);
$division_session = intval($_SESSION['division_id']);

$id_ejecutor = isset($_GET['id_ejecutor']) ? intval($_GET['id_ejecutor']) : 0;
$division_filtro = isset($_GET['division']) ? intval($_GET['division']) : $division_session;

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

$inicio = $fecha_desde . " 00:00:00";
$fin    = $fecha_hasta . " 23:59:59";

if ($id_ejecutor <= 0) {
    die("Ejecutor inválido.");
}

/* ======================================================
   VALIDAR QUE EL EJECUTOR PERTENEZCA A LA DIVISIÓN
====================================================== */
$sqlVal = "
SELECT id, CONCAT(nombre,' ',apellido) AS nombre_completo
FROM usuario
WHERE id = ?
  AND id_division = ?
  AND id_empresa = ?
  AND activo = 1
  AND id_perfil = 3
";

$stmtVal = $conn->prepare($sqlVal);
$stmtVal->bind_param("iii", $id_ejecutor, $division_filtro, $id_empresa);
$stmtVal->execute();
$resVal = $stmtVal->get_result();

if ($resVal->num_rows === 0) {
    die("No autorizado.");
}

$ejecutor = $resVal->fetch_assoc();
$stmtVal->close();


/* ======================================================
   TRAER GESTIONES POR FECHA Y CAMPAÑA
====================================================== */

$sql = "
SELECT
    DATE(v.fecha) AS fecha,
    f.id,
    UPPER(f.nombre) AS nombre_campana,
    COUNT(DISTINCT v.id_local) AS total_locales
FROM vw_gestiones_unificadas v
JOIN formulario f ON f.id = v.id_formulario
JOIN usuario u ON u.id = v.id_usuario
WHERE u.id = ?
  AND u.id_empresa = ?
  AND v.fecha BETWEEN ? AND ?
GROUP BY DATE(v.fecha), f.id
ORDER BY DATE(v.fecha) DESC, f.nombre
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiss", $id_ejecutor, $id_empresa, $inicio, $fin);
$stmt->execute();
$result = $stmt->get_result();

$datos = [];
$totalGeneral = 0;

while ($row = $result->fetch_assoc()) {
    $fecha = $row['fecha'];
    $datos[$fecha][] = $row;
    $totalGeneral += (int)$row['total_locales'];
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestiones Diarias</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
<style>
body { background:#f5f6fa; }
.card-panel {
    margin-top:40px;
    padding:25px;
    border-radius:10px;
}
.fecha-header {
    background:#1f4e99;
    color:white;
    padding:8px 12px;
    border-radius:5px;
    font-weight:bold;
}
.total-dia {
    font-weight:bold;
    background:#eef3fb;
}
</style>
</head>
<body>

<div class="container">

<div class="card card-panel shadow">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4>
        <i class="fas fa-calendar-check"></i>
        Gestiones Diarias - <?= htmlspecialchars($ejecutor['nombre_completo']) ?>
    </h4>

    <a href="mod_panel.php?division=<?= $division_filtro ?>&fecha_desde=<?= urlencode($fecha_desde) ?>&fecha_hasta=<?= urlencode($fecha_hasta) ?>"
       class="btn btn-secondary btn-sm">
       <i class="fas fa-arrow-left"></i> Volver
    </a>
</div>

<p>
<strong>Periodo:</strong>
<?= date("d/m/Y", strtotime($fecha_desde)) ?>
-
<?= date("d/m/Y", strtotime($fecha_hasta)) ?>
</p>

<hr>

<?php if (!empty($datos)): ?>

<?php foreach ($datos as $fecha => $registros): ?>

<div class="mb-4">

    <div class="fecha-header mb-2">
        <?= date("d/m/Y", strtotime($fecha)) ?>
    </div>

    <table class="table table-sm table-bordered table-striped">
        <thead class="thead-light">
            <tr>
                <th>Campaña</th>
                <th class="text-center">Locales Gestionados</th>
                <th class="text-center">Detalle</th>
            </tr>
        </thead>
        <tbody>

        <?php
        $totalDia = 0;
        foreach ($registros as $r):
            $totalDia += (int)$r['total_locales'];
        ?>
            <tr>
                <td><?= htmlspecialchars($r['nombre_campana']) ?></td>
                <td class="text-center"><?= $r['total_locales'] ?></td>
                <td class="text-center">
                    <button 
                        class="btn btn-info btn-sm btn-detalle-locales"
                        data-form="<?= $r['id'] ?>"
                        data-ejecutor="<?= $id_ejecutor ?>"
                        data-fecha="<?= $fecha ?>"
                        data-campana="<?= htmlspecialchars($r['nombre_campana']) ?>">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>

        <tr class="total-dia">
            <td>Total del día</td>
            <td class="text-center"><?= $totalDia ?></td>
            <td></td>
        </tr>

        </tbody>
    </table>

</div>

<?php endforeach; ?>

<hr>
<h5 class="text-right">
    Total General del Periodo: <?= $totalGeneral ?>
</h5>

<?php else: ?>

<div class="alert alert-warning">
    No existen gestiones en el periodo seleccionado.
</div>

<?php endif; ?>

</div>
</div>

<div class="modal fade" id="modalLocales" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">
          Salas gestionadas: <span id="modalCampana"></span>
        </h5>
        <button type="button" class="close" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div id="loaderLocales" class="text-center p-3">
          <i class="fas fa-spinner fa-spin"></i> Cargando...
        </div>
        <div id="contenidoLocales" style="display:none;"></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalFotos" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark">

      <div class="modal-header border-0">
        <h5 class="modal-title text-white">Foto Evidencia</h5>
        <button type="button" class="close text-white" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body text-center">
        <img id="imagenGrande" src="" 
             style="max-width:100%; max-height:70vh; border-radius:6px;">
      </div>

      <div class="modal-footer border-0 justify-content-between">
        <button class="btn btn-light btn-sm" id="fotoPrev">
          <i class="fas fa-arrow-left"></i>
        </button>

        <button class="btn btn-light btn-sm" id="fotoNext">
          <i class="fas fa-arrow-right"></i>
        </button>
      </div>

    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
    $(document).on('click', '.btn-detalle-locales', function() {

    let idFormulario = $(this).data('form');
    let idEjecutor = $(this).data('ejecutor');
    let fecha = $(this).data('fecha');
    let nombreCampana = $(this).data('campana');

    $('#modalCampana').text(nombreCampana);
    $('#contenidoLocales').hide();
    $('#loaderLocales').show();
    $('#modalLocales').modal('show');

    $.ajax({
        url: 'ajax_detalle_locales.php',
        type: 'GET',
        data: {
            id_formulario: idFormulario,
            id_ejecutor: idEjecutor,
            fecha: fecha
        },
        success: function(response) {
            $('#loaderLocales').hide();
            $('#contenidoLocales').html(response).show();
        },
        error: function() {
            $('#loaderLocales').html('Error al cargar datos.');
        }
    });
});
    
</script>
<script>
    
    let fotosGrupo = [];
let fotoActualIndex = 0;

$(document).on('click', '.img-miniatura', function() {

    let grupo = $(this).data('grupo');

    fotosGrupo = [];

    $('.img-miniatura').each(function() {
        if ($(this).data('grupo') === grupo) {
            fotosGrupo.push($(this).data('foto'));
        }
    });

    let fotoSeleccionada = $(this).data('foto');
    fotoActualIndex = fotosGrupo.indexOf(fotoSeleccionada);

    $('#imagenGrande').attr('src', fotoSeleccionada);
    $('#modalFotos').modal('show');
});

$('#fotoNext').click(function() {
    if (fotosGrupo.length === 0) return;

    fotoActualIndex++;
    if (fotoActualIndex >= fotosGrupo.length) {
        fotoActualIndex = 0;
    }

    $('#imagenGrande').attr('src', fotosGrupo[fotoActualIndex]);
});

$('#fotoPrev').click(function() {
    if (fotosGrupo.length === 0) return;

    fotoActualIndex--;
    if (fotoActualIndex < 0) {
        fotoActualIndex = fotosGrupo.length - 1;
    }

    $('#imagenGrande').attr('src', fotosGrupo[fotoActualIndex]);
});
</script>
</body>
</html>
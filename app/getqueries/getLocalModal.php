<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';

if (!isset($_GET['idLocal'])) {
    echo "No se especificó el local.";
    exit;
}

$idLocal = intval($_GET['idLocal']);
$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);
$cache_key = "localModal_{$usuario_id}_{$idLocal}_{$empresa_id}";

if (function_exists('apcu_fetch') && ($html = apcu_fetch($cache_key)) !== false) {
    echo $html;
    exit;
}

// Consultar datos del local para el encabezado del modal
$sql_local = "SELECT l.codigo AS codigoLocal, l.nombre AS nombreLocal, l.direccion AS direccionLocal, IFNULL(v.nombre_vendedor, '') AS vendedor 
              FROM local l 
              LEFT JOIN vendedor v ON v.id = l.id_vendedor 
              WHERE l.id = ?";
$stmt_local = $conn->prepare($sql_local);
$stmt_local->bind_param("i", $idLocal);
$stmt_local->execute();
$result_local = $stmt_local->get_result();
if ($result_local->num_rows > 0) {
    $local = $result_local->fetch_assoc();
    $codigoLocal    = htmlspecialchars($local['codigoLocal'], ENT_QUOTES, 'UTF-8');
    $nombreLocal    = htmlspecialchars($local['nombreLocal'], ENT_QUOTES, 'UTF-8');
    $direccionLocal = htmlspecialchars($local['direccionLocal'], ENT_QUOTES, 'UTF-8');
    $vendedor       = htmlspecialchars($local['vendedor'], ENT_QUOTES, 'UTF-8');
} else {
    echo "Local no encontrado.";
    exit;
}
$stmt_local->close();

// Consultar campañas asociadas al local
$sql_campanas = "
    SELECT DISTINCT
        f.id AS idCampana,
        f.nombre AS nombreCampana,
        f.fechaInicio,
        f.fechaTermino,
        f.estado
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    WHERE fq.id_usuario = ?
      AND fq.id_local = ?
      AND f.id_empresa = ?
      AND fq.estado = 0
      AND f.tipo = 1
    ORDER BY f.fechaInicio DESC
";
$stmt_campanas = $conn->prepare($sql_campanas);
$stmt_campanas->bind_param('iii', $usuario_id, $idLocal, $empresa_id);
$stmt_campanas->execute();
$result_campanas = $stmt_campanas->get_result();

ob_start();
?>
<div id="responsive<?php echo $idLocal; ?>" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel<?php echo $idLocal; ?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
         <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
         <h4 class="modal-title" id="myModalLabel<?php echo $idLocal; ?>">
            Local: <?php echo $codigoLocal; ?> - <?php echo $nombreLocal; ?><br>
            Dirección: <?php echo $direccionLocal; ?><br>
            Vendedor: <?php echo $vendedor; ?>
         </h4>
      </div>
      <div class="modal-body">
         <table class="table table-bordered">
            <thead>
               <tr>
                   <th>Nombre de la Campaña</th>
                   <th>Gestionar</th>
               </tr>
            </thead>
            <tbody>
            <?php
            if ($result_campanas->num_rows > 0) {
                while ($campana = $result_campanas->fetch_assoc()) {
                    $idCampana     = (int)$campana['idCampana'];
                    $nombreCampana = htmlspecialchars($campana['nombreCampana'], ENT_QUOTES, 'UTF-8');
                    echo "<tr data-idcampana='{$idCampana}'>";
                    echo "<td>{$nombreCampana}</td>";
                    echo "<td class='center'><a href='../gestionar.php?idCampana=" . urlencode($idCampana) . "&nombreCampana=" . urlencode($nombreCampana) . "&idLocal=" . urlencode($idLocal) . "' class='btn btn-info btn-sm'><i class='fa fa-pencil'></i> Gestionar</a></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='2' class='center'>No hay campañas asociadas a este local.</td></tr>";
            }
            ?>
            </tbody>
         </table>
      </div>
      <div class="modal-footer">
         <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<?php
$html = ob_get_clean();
if (function_exists('apcu_store')) {
    apcu_store($cache_key, $html, 300);
}
echo $html;
$stmt_campanas->close();
$conn->close();
?>

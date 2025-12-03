<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/app/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status'=>'error', 'message'=>'No hay sesión']);
    exit;
}

$idCampana = isset($_GET['idCampana']) ? intval($_GET['idCampana']) : 0;
$idLocal   = isset($_GET['idLocal']) ? intval($_GET['idLocal']) : 0;
$usuario_id = intval($_SESSION['usuario_id']);

if ($idCampana <= 0 || $idLocal <= 0) {
    echo json_encode(['status'=>'error', 'message'=>'Parámetros inválidos']);
    exit;
}

$sql_materiales = "
    SELECT 
      fq.id,
      fq.material,
      fq.valor_propuesto,
      MAX(fq.valor) AS valor, 
      MAX(fq.fechaVisita) AS fechaVisita,
      MAX(fq.observacion) AS observacion,
      m.ref_image
    FROM formularioQuestion fq
    LEFT JOIN material m ON fq.material = m.nombre
    WHERE fq.id_local = ? AND fq.id_formulario = ? AND fq.id_usuario = ?
    GROUP BY fq.id, fq.material, fq.valor_propuesto
";
$stmt = $conn->prepare($sql_materiales);
$stmt->bind_param("iii", $idLocal, $idCampana, $usuario_id);
$stmt->execute();
$result_materiales = $stmt->get_result();
$materiales = [];
while ($mat = $result_materiales->fetch_assoc()){
    $materiales[] = [
         'id'             => intval($mat['id']),
         'material'       => htmlspecialchars($mat['material'], ENT_QUOTES, 'UTF-8'),
         'valor_propuesto'=> htmlspecialchars($mat['valor_propuesto'], ENT_QUOTES, 'UTF-8'),
         'valor'          => htmlspecialchars($mat['valor'], ENT_QUOTES, 'UTF-8'),
         'fechaVisita'    => htmlspecialchars($mat['fechaVisita'], ENT_QUOTES, 'UTF-8'),
         'observacion'    => htmlspecialchars($mat['observacion'], ENT_QUOTES, 'UTF-8'),
         'ref_image'      => htmlspecialchars($mat['ref_image'], ENT_QUOTES, 'UTF-8')
    ];
}
$stmt->close();

// Generar el HTML EXACTO que se muestra en el Paso 2 de gestionar.php para los materiales
ob_start();
if (!empty($materiales)):
    foreach ($materiales as $m):
        $idFQ       = $m['id'];
        $matName    = ucfirst(mb_strtolower($m['material'], 'UTF-8'));
        $valorProp  = $m['valor_propuesto'];
        $valorAct   = $m['valor'];
        $fechaImp   = $m['fechaVisita'];
        $refImage   = $m['ref_image'];
        if (!empty($refImage)) {
            $imgTag = "<img src='$refImage' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$refImage')\" title='Ver imagen completa'>";
        } else {
            $imgTag = "";
        }
        if ($valorAct !== '0' && $valorAct !== null && $valorAct !== ''):
            $fechaF = date('d-m-Y H:i', strtotime($fechaImp));
            ?>
            <div class="form-group">
                <label><?php echo $matName; ?> <?php echo $imgTag; ?> (Propuesto: <?php echo $valorProp; ?>)</label>
                <p class="form-control-static">Valor Implementado: <?php echo $valorAct; ?></p>
                <p class="form-control-static">Fecha: <?php echo $fechaF; ?></p>
                <p class="form-control-static">Observación: <?php echo $m['observacion']; ?></p>
            </div>
            <?php
        else:
            ?>
            <div class="form-group">
                <div class="checkbox">
                    <label>
                        <input type="checkbox"
                               class="implementa-material"
                               data-id-material="<?php echo $idFQ; ?>">
                        Implementar <?php echo $matName; ?> <?php echo $imgTag; ?> (Valor Propuesto: <?php echo $valorProp; ?>)
                    </label>
                </div>
            </div>
            <div class="implementa-section"
                 id="implementa_section_<?php echo $idFQ; ?>"
                 style="display:none; padding-left:20px;">
                <div class="form-group">
                    <label>Valor Implementado:</label>
                    <input type="number"
                           class="form-control valor-implementado"
                           name="valor[<?php echo $idFQ; ?>]"
                           placeholder="Ingrese el valor"
                           data-valor-propuesto="<?php echo $valorProp; ?>"
                           disabled required>
                </div>
                <div class="form-group">
                    <label>Origen de la foto:</label>
                    <select class="photo-source form-control"
                            data-target-input="fotos_input_<?php echo $idFQ; ?>"
                            disabled required>
                        <option value="gallery" selected>Elegir de la Galería</option>
                        <option value="camera">Tomar Foto</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fotos (hasta 5):</label>
                    <input type="file"
                           accept="image/*"
                           name="fotos[<?php echo $idFQ; ?>][]"
                           multiple
                           class="form-control file-input"
                           disabled
                           id="fotos_input_<?php echo $idFQ; ?>">
                    <div id="previewContainer_<?php echo $idFQ; ?>"></div>
                </div>
                <div class="form-group">
                    <label>Observación Implementación:</label>
                    <textarea class="form-control"
                              name="observacion[<?php echo $idFQ; ?>]"
                              placeholder="Observación..."></textarea>
                </div>
            </div>
            <div class="no-implementa-section"
                 id="no_implementa_section_<?php echo $idFQ; ?>"
                 style="display:block; padding-left:20px;">
                <div class="form-group">
                    <label>Motivo de NO implementación:</label>
                    <select class="form-control"
                            name="motivoSelect[<?php echo $idFQ; ?>]">
                        <option value="No, no permitieron">No, no permitieron</option>
                        <option value="No, hay otro tipo de exhibicion">No, hay otro tipo de exhibicion</option>
                        <option value="No, sin productos">No, sin productos</option>
                        <option value="No, no ha llegado el material">No, no ha llegado el material</option>
                        <option value="Sala en remodelación">Sala en remodelación</option>
                        <option value="No permite por robo">No permite por robo</option>
                        <option value="Sin bajada de la central">Sin bajada de la central</option>
                        <option value="Ya tenemos mueble adicional">Ya hay mueble adicional</option>
                        <option value="No llegó el material de implementación completo">No llegó el material de implementación completo</option>
                    </select>
                </div>
                <label>Detalle adicional:</label>
                <textarea class="form-control"
                          name="motivoNoImplementado[<?php echo $idFQ; ?>]"
                          placeholder="Explique brevemente..."></textarea>
            </div>
            <?php
        endif;
    endforeach;
else:
    echo "<p>No se encontraron materiales para este local/campaña.</p>";
endif;
$html = ob_get_clean();
echo json_encode(['status'=>'success','html'=>$html]);
$conn->close();
?>

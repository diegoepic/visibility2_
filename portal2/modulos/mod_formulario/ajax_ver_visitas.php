<?php
// File: /visibility2/portal/modulos/mod_formulario/ajax_ver_visitas.php

// ————————————————————————
// 1) EVITAR CUALQUIER SALIDA ANTES DE HTML
// ————————————————————————
if (ob_get_length()) {
    ob_clean();
}
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// ————————————————————————
// 2) SESIÓN Y CONEXIONES
// ————————————————————————

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session_data.php';

// Seguridad
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Acceso denegado.</div>";
    exit;
}

// Parámetros
$formulario_id = isset($_GET['formulario_id']) ? intval($_GET['formulario_id']) : 0;
$local_id      = isset($_GET['local_id'])      ? intval($_GET['local_id'])      : 0;
if (!$formulario_id || !$local_id) {
    echo "<div class='alert alert-danger'>Parámetros inválidos.</div>";
    exit;
}

// ————————————————————————
// 3) RECUPERAR VISITAS DE LA TABLA visita
// ————————————————————————
$stmtV = $conn->prepare("
  SELECT v.id, v.fecha_inicio, v.fecha_fin, v.latitud, v.longitud, u.usuario
    FROM visita v
    LEFT JOIN usuario u ON u.id = v.id_usuario
   WHERE v.id_formulario = ? AND v.id_local = ?
   ORDER BY v.fecha_inicio DESC
");
$stmtV->bind_param("ii", $formulario_id, $local_id);
$stmtV->execute();
$resV    = $stmtV->get_result();
$visitas = $resV->fetch_all(MYSQLI_ASSOC);
$stmtV->close();

$totalVisitas = count($visitas);

// ————————————————————————
// 4) AÑADIR “VISITAS” SOLO-AUDITORÍA
//    aquellas visitas que existen en gestion_visita 
//    pero no tienen un registro en visita
// ————————————————————————
$existing_ids = array_column($visitas, 'id');
$stmtExtra = $conn->prepare("
  SELECT DISTINCT gv.visita_id AS id, u.usuario
    FROM gestion_visita gv
    JOIN usuario u ON u.id = gv.id_usuario
   WHERE gv.id_formulario = ? AND gv.id_local = ?
     AND gv.visita_id IS NOT NULL
");
$stmtExtra->bind_param("ii", $formulario_id, $local_id);
$stmtExtra->execute();
$resE = $stmtExtra->get_result();
while ($row = $resE->fetch_assoc()) {
    $vid = intval($row['id']);
    if (!in_array($vid, $existing_ids, true)) {
        // agregar como visita “solo auditoría”
        $visitas[] = [
            'id'           => $vid,
            'fecha_inicio' => null,
            'fecha_fin'    => null,
            'latitud'      => null,
            'longitud'     => null,
            'usuario'      => $row['usuario'] . ' (solo auditoría)',
        ];
    }
}
$stmtExtra->close();

// ————————————————————————
// Ordenamos nuevamente por fecha (las nulls al final)
// ————————————————————————
usort($visitas, function($a, $b){
    $ta = $a['fecha_inicio'] ? strtotime($a['fecha_inicio']) : PHP_INT_MIN;
    $tb = $b['fecha_inicio'] ? strtotime($b['fecha_inicio']) : PHP_INT_MIN;
    return $tb <=> $ta;
});
?>
<!-- Modal header -->
<div class="modal-header bg-primary text-white">
  <h5 class="modal-title">
    Histórico de Visitas<br>
    <small>Local #<?= htmlspecialchars($local_id, ENT_QUOTES) ?> — Formulario #<?= htmlspecialchars($formulario_id, ENT_QUOTES) ?></small>
  </h5>
  <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">
  <?php if (count($visitas) === 0): ?>
    <p class="text-center text-muted">No hay visitas registradas para este local.</p>
  <?php else: ?>
    <?php foreach ($visitas as $idx => $v): 
        
        
        
        $vid = intval($v['id']);

        $stmtClass = $conn->prepare("
          SELECT gv.estado_gestion, gv.observacion, gv.foto_url
            FROM gestion_visita gv
           WHERE gv.visita_id = ?
             AND gv.id_formulario = ?
             AND gv.id_local = ?
             AND gv.id_formularioQuestion = 0
        ");
        $stmtClass->bind_param("iii", $vid, $formulario_id, $local_id);
        $stmtClass->execute();
        $classRows = $stmtClass->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtClass->close();




        // 1) Implementaciones exitosas
        $stmtImpOk = $conn->prepare("
          SELECT gv.id, gv.id_formularioQuestion, m.nombre AS material, gv.valor_real, gv.observacion
            FROM gestion_visita gv
            LEFT JOIN material m ON m.id = gv.id_material
           WHERE gv.visita_id=? AND gv.id_formulario=? AND gv.id_local=? AND gv.valor_real > 0
           ORDER BY gv.fecha_visita ASC
        ");
        $stmtImpOk->bind_param("iii", $vid, $formulario_id, $local_id);
        $stmtImpOk->execute();
        $impsOk = $stmtImpOk->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtImpOk->close();

        // 2) Implementaciones no realizadas
$stmtImpNo = $conn->prepare("
  SELECT gv.id,
         gv.id_formularioQuestion,
         m.nombre       AS material,
         gv.observacion AS observacion_no_impl
    FROM gestion_visita gv
LEFT JOIN material m ON m.id = gv.id_material
WHERE gv.visita_id=?
AND gv.id_formulario=?
AND gv.id_local=?
AND gv.valor_real = 0
AND gv.id_formularioQuestion <> 0
ORDER BY gv.fecha_visita ASC
");

        $stmtImpNo->bind_param("iii", $vid, $formulario_id, $local_id);
        $stmtImpNo->execute();
        $impsNo = $stmtImpNo->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtImpNo->close();

        // 3) Respuestas de encuesta
        $stmtR = $conn->prepare("
          SELECT fqr.id, fq.question_text, fqr.answer_text, fqr.created_at
            FROM form_question_responses fqr
            JOIN form_questions fq ON fq.id = fqr.id_form_question
           WHERE fqr.visita_id=? AND fqr.id_local=? AND fq.id_formulario=?
           ORDER BY fqr.created_at ASC
        ");
        $stmtR->bind_param("iii", $vid, $local_id, $formulario_id);
        $stmtR->execute();
        $resps = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtR->close();
    ?>
      <div class="card mb-4">
        <div class="card-header">
          <?php 
  // Secuencia: la más antigua = 1, la más reciente = $totalVisitas
  $seq = $totalVisitas - $idx;
?>
<strong>Visita #<?= $seq ?></strong>
          <?= $v['fecha_inicio']
               ? date('d/m/Y H:i', strtotime($v['fecha_inicio']))
               : '-' ?> — 
          <?= $v['fecha_fin']
               ? date('d/m/Y H:i', strtotime($v['fecha_fin']))
               : 'Sin fecha de termino' ?><br>
          <small>Usuario: <?= htmlspecialchars($v['usuario'], ENT_QUOTES) ?></small><br>
          <small>Coordenadas: 
            <?= $v['latitud']  !== null ? htmlspecialchars($v['latitud'], ENT_QUOTES)  : '-' ?>, 
            <?= $v['longitud'] !== null ? htmlspecialchars($v['longitud'], ENT_QUOTES) : '-' ?>
          </small>
        </div>
        <div class="card-body">
            <?php if (!empty($classRows)): ?>
          <h6>Estado del local</h6>
          <?php foreach ($classRows as $c): ?>
            <p>
              <strong><?= htmlspecialchars($c['estado_gestion'], ENT_QUOTES) ?></strong><br>
             <?= nl2br(htmlspecialchars($c['observacion'] ?? '', ENT_QUOTES)) ?>
            </p>
           <?php if (!empty($c['foto_url'])):
    // Prependemos el dominio completo
    $src = 'https://www.visibility.cl' . $c['foto_url'];
?>
  <img src="<?= htmlspecialchars($src, ENT_QUOTES) ?>"
       class="rounded mr-1 mb-1"
       style="width:48px;height:48px;cursor:pointer;object-fit:cover"
       onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($src,ENT_QUOTES) ?>');$('#photoModal').modal('show');">
<?php endif; ?>
            <hr>
          <?php endforeach; ?>
        <?php endif; ?>
        
          <!-- IMPLEMENTADOS -->
          <h6>Materiales implementados</h6>
          <?php if (empty($impsOk)): ?>
            <p class="text-muted">No se implementó ningún material.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered">
                <thead class="thead-light">
                  <tr>
                    <th>ID</th><th>Material</th><th>Valor real</th><th>Observación</th><th>Fotos</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($impsOk as $imp): 
                    // Fotos
                    $stmtF = $conn->prepare("
                      SELECT url FROM fotoVisita
                       WHERE visita_id=? AND id_formulario=? AND id_local=? AND id_formularioQuestion=?
                    ");
                    $stmtF->bind_param("iiii", $vid, $formulario_id, $local_id, $imp['id_formularioQuestion']);
                    $stmtF->execute();
                    $fotos = $stmtF->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmtF->close();
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($imp['id'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars($imp['material'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars($imp['valor_real'], ENT_QUOTES) ?></td>
                      <td><?= nl2br(htmlspecialchars($imp['observacion'] ?? '', ENT_QUOTES)) ?></td>
                      <td>
                        <div class="d-flex flex-wrap">
                          <?php foreach ($fotos as $f): 
                            $src = '/visibility2/app/' . ltrim($f['url'], '/');
                          ?>
                            <img src="<?= htmlspecialchars($src, ENT_QUOTES) ?>"
                                 class="rounded mr-1 mb-1"
                                 style="width:48px;height:48px;cursor:pointer;object-fit:cover"
                                 onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($src,ENT_QUOTES) ?>');$('#photoModal').modal('show');">
                          <?php endforeach; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        <!-- NO IMPLEMENTADOS -->
<h6 class="mt-4">Materiales no implementados</h6>
<?php if (empty($impsNo)): ?>
  <p class="text-muted">No hay materiales marcados como no implementados.</p>
<?php else: ?>
  <ul class="list-group">
    <?php foreach ($impsNo as $ni): ?>
      <li class="list-group-item">
        <strong><?= htmlspecialchars($ni['material'], ENT_QUOTES) ?></strong><br>
        <em>Observación:</em>
        <?= nl2br(htmlspecialchars($ni['observacion_no_impl'] ?? 'Sin observación', ENT_QUOTES)) ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

          <!-- ENCUESTA -->
          <h6 class="mt-4">Encuesta</h6>
          <?php if (empty($resps)): ?>
            <p class="text-muted">Sin respuestas de encuesta.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered">
                <thead class="thead-light">
                  <tr>
                    <th>ID</th><th>Pregunta</th><th>Respuesta</th><th>Fecha</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($resps as $r): 
                    $ans = $r['answer_text'] ?? '';
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($r['id'], ENT_QUOTES) ?></td>
                      <td><?= htmlspecialchars($r['question_text'], ENT_QUOTES) ?></td>
                      <td>
                        <?php if (preg_match('/\.(jpe?g|png|gif|webp)$/i',$ans)): 
                          $url = (strpos($ans,'/')===0 ? $ans : '/visibility2/app/'.ltrim($ans,'./'));
                        ?>
                          <img src="<?= htmlspecialchars($url,ENT_QUOTES) ?>"
                               class="rounded" style="width:48px;height:48px;cursor:pointer"
                               onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($url,ENT_QUOTES) ?>');$('#photoModal').modal('show');">
                        <?php else: ?>
                          <?= nl2br(htmlspecialchars($ans, ENT_QUOTES)) ?>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($r['created_at'] ?? '', ENT_QUOTES) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="modal-footer">
  <button class="btn btn-secondary ml-auto" data-dismiss="modal">
    <i class="fas fa-times"></i> Cerrar
  </button>
</div>

<!-- Lightbox para fotos -->
<div class="modal fade" id="photoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark p-2">
      <img id="photoModalImg" src="" class="img-fluid rounded">
    </div>
  </div>
</div>

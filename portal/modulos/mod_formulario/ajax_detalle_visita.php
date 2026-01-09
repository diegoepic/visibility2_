<?php

if (ob_get_length()) {
    ob_clean();
}
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session_data.php';
require_once __DIR__ . '/visitas_helpers.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "<div class='alert alert-danger'>Acceso denegado.</div>";
    exit;
}

$formulario_id = isset($_GET['formulario_id']) ? intval($_GET['formulario_id']) : 0;
$visita_id = isset($_GET['visita_id']) ? intval($_GET['visita_id']) : 0;

if (!$formulario_id || !$visita_id) {
    echo "<div class='alert alert-danger'>Parámetros inválidos.</div>";
    exit;
}

$detalle = obtenerDetalleVisita($conn, $formulario_id, $visita_id);
$visit = $detalle['visit'];

if (!$visit) {
    echo "<div class='alert alert-warning'>No se encontró la visita solicitada.</div>";
    exit;
}

$estado_local = $detalle['estado_local'];
$implementados = $detalle['implementados'];
$no_implementados = $detalle['no_implementados'];
$respuestas = $detalle['respuestas'];
?>
<div class="modal-header bg-primary text-white">
  <h5 class="modal-title">
    Detalle de visita #<?= htmlspecialchars((string)$visita_id, ENT_QUOTES) ?>
    <br>
    <small>
      <?= htmlspecialchars($visit['codigo'] ?? '', ENT_QUOTES) ?> -
      <?= htmlspecialchars($visit['local_nombre'] ?? '', ENT_QUOTES) ?>
    </small>
  </h5>
  <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">
  <div class="mb-3">
    <strong>Cadena:</strong> <?= htmlspecialchars($visit['cadena'] ?? 'Sin cadena', ENT_QUOTES) ?><br>
    <strong>Dirección:</strong> <?= htmlspecialchars($visit['direccion'] ?? '—', ENT_QUOTES) ?><br>
    <strong>Usuario:</strong> <?= htmlspecialchars($visit['usuario'] ?? '—', ENT_QUOTES) ?><br>
    <strong>Inicio:</strong>
    <?= $visit['fecha_inicio'] ? date('d/m/Y H:i', strtotime($visit['fecha_inicio'])) : '—' ?><br>
    <strong>Término:</strong>
    <?= $visit['fecha_fin'] ? date('d/m/Y H:i', strtotime($visit['fecha_fin'])) : '—' ?><br>
    <strong>Estado:</strong>
    <?= htmlspecialchars($visit['estado'] ?? 'Sin estado', ENT_QUOTES) ?><br>
    <strong>Coordenadas:</strong>
    <?= $visit['latitud'] !== null ? htmlspecialchars((string)$visit['latitud'], ENT_QUOTES) : '-' ?>,
    <?= $visit['longitud'] !== null ? htmlspecialchars((string)$visit['longitud'], ENT_QUOTES) : '-' ?>
  </div>

  <?php if (!empty($estado_local)): ?>
    <h6>Estado del local</h6>
    <?php foreach ($estado_local as $c): ?>
      <p>
        <strong><?= htmlspecialchars($c['estado_gestion'] ?? '', ENT_QUOTES) ?></strong><br>
        <?= nl2br(htmlspecialchars($c['observacion'] ?? '', ENT_QUOTES)) ?>
      </p>
      <?php if (!empty($c['foto_url'])):
        $src = 'https://www.visibility.cl' . $c['foto_url'];
      ?>
        <img src="<?= htmlspecialchars($src, ENT_QUOTES) ?>"
             class="rounded mr-1 mb-1"
             style="width:48px;height:48px;cursor:pointer;object-fit:cover"
             onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($src, ENT_QUOTES) ?>');$('#photoModal').modal('show');">
      <?php endif; ?>
      <hr>
    <?php endforeach; ?>
  <?php endif; ?>

  <h6>Materiales implementados</h6>
  <?php if (empty($implementados)): ?>
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
          <?php foreach ($implementados as $imp):
            $stmtFotos = $conn->prepare(
                "SELECT url FROM fotoVisita WHERE visita_id = ? AND id_formulario = ? AND id_local = ? AND id_formularioQuestion = ?"
            );
            $stmtFotos->bind_param('iiii', $visita_id, $formulario_id, $visit['id_local'], $imp['id_formularioQuestion']);
            $stmtFotos->execute();
            $fotos = $stmtFotos->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtFotos->close();
          ?>
            <tr>
              <td><?= htmlspecialchars((string)$imp['id'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($imp['material'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars((string)$imp['valor_real'], ENT_QUOTES) ?></td>
              <td><?= nl2br(htmlspecialchars($imp['observacion'] ?? '', ENT_QUOTES)) ?></td>
              <td>
                <div class="d-flex flex-wrap">
                  <?php foreach ($fotos as $f):
                    $src = '/visibility2/app/' . ltrim($f['url'], '/');
                  ?>
                    <img src="<?= htmlspecialchars($src, ENT_QUOTES) ?>"
                         class="rounded mr-1 mb-1"
                         style="width:48px;height:48px;cursor:pointer;object-fit:cover"
                         onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($src, ENT_QUOTES) ?>');$('#photoModal').modal('show');">
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h6 class="mt-4">Materiales no implementados</h6>
  <?php if (empty($no_implementados)): ?>
    <p class="text-muted">No hay materiales marcados como no implementados.</p>
  <?php else: ?>
    <ul class="list-group">
      <?php foreach ($no_implementados as $ni): ?>
        <li class="list-group-item">
          <strong><?= htmlspecialchars($ni['material'] ?? '', ENT_QUOTES) ?></strong><br>
          <em>Observación:</em>
          <?= nl2br(htmlspecialchars($ni['observacion_no_impl'] ?? 'Sin observación', ENT_QUOTES)) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h6 class="mt-4">Encuesta</h6>
  <?php if (empty($respuestas)): ?>
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
          <?php foreach ($respuestas as $r):
            $ans = $r['answer_text'] ?? '';
          ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['id'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($r['question_text'] ?? '', ENT_QUOTES) ?></td>
              <td>
                <?php if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $ans)):
                    $url = (strpos($ans, '/') === 0 ? $ans : '/visibility2/app/' . ltrim($ans, './'));
                ?>
                  <img src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
                       class="rounded" style="width:48px;height:48px;cursor:pointer"
                       onclick="$('#photoModalImg').attr('src','<?= htmlspecialchars($url, ENT_QUOTES) ?>');$('#photoModal').modal('show');">
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

<div class="modal-footer">
  <button class="btn btn-secondary ml-auto" data-dismiss="modal">
    <i class="fas fa-times"></i> Cerrar
  </button>
</div>

<div class="modal fade" id="photoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark p-2">
      <img id="photoModalImg" src="" class="img-fluid rounded">
    </div>
  </div>
</div>

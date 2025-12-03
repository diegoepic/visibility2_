<?php 
// gestionarIW.php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Campaña IW
if (!isset($_GET['idCampana'])) {
    die("ID de campaña complementaria no proporcionado.");
}
$idCampana = intval($_GET['idCampana']);

$stmt = $conn->prepare("SELECT id, nombre, id_division, id_empresa, iw_requiere_local FROM formulario WHERE id = ? AND tipo = 2");
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Campaña complementaria no encontrada o tipo inválido.");
}
$campana = $result->fetch_assoc();
$stmt->close();

$requiereLocal = (int)($campana['iw_requiere_local'] ?? 0);

// Token IW (opcional, para trazabilidad)
if (empty($_SESSION['iw_tokens'])) $_SESSION['iw_tokens'] = [];
if (empty($_SESSION['iw_tokens'][$idCampana])) {
    $_SESSION['iw_tokens'][$idCampana] = bin2hex(random_bytes(16));
}
$iw_token = $_SESSION['iw_tokens'][$idCampana];

if (empty($_SESSION['iw_visitas'])) $_SESSION['iw_visitas'] = [];
$visita_id = 0;
if (!$requiereLocal) {
  $key = $idCampana . ':0';
  $visita_id = !empty($_SESSION['iw_visitas'][$key])
      ? (int)$_SESSION['iw_visitas'][$key]
      : 0;
}


// Función: obtener pregunta padre de una opción
function getParentQuestionId($conn, $optId) {
    $stmt = $conn->prepare("SELECT id_form_question FROM form_question_options WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $optId);
    $stmt->execute();
    $stmt->bind_result($pid);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? $pid : null;
}

// Cargar preguntas
$stmt = $conn->prepare("
    SELECT 
      id AS id_form_question,
      question_text,
      id_question_type,
      id_dependency_option,
      is_valued,
      is_required
    FROM form_questions
    WHERE id_formulario = ?
    ORDER BY sort_order ASC
");
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$result = $stmt->get_result();
$preguntas = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
<title>Encuesta Complementaria: <?php echo htmlspecialchars($campana['nombre'], ENT_QUOTES); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <script src="/visibility2/app/js/heic2any.min.js"></script>

  <style>
    .question-container { margin-bottom: 1rem; }
    .conditional-question { display: none; }
    .thumbnail { max-width: 100px; max-height: 100px; cursor: pointer; }
    .modal-img { width: 100%; height: auto; }
    #processingIndicator { display: none; text-align: center; margin-top: 1rem; }
    #processingIndicator .spinner-border { width: 3rem; height: 3rem; }

    .iw-previews .iw-card {
      position: relative;
      width: 110px;
      height: 110px;
      margin: .25rem;
      border-radius: .5rem;
      overflow: hidden;
      border: 1px solid #e5e5e5;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .iw-previews .iw-card img { max-width: 100%; max-height: 100%; cursor: pointer; display: block; }
    .iw-previews .btn-del { position: absolute; top: 4px; right: 4px; }

    .iw-card .iw-progress{
      position:absolute; left:0; bottom:0; width:100%; height:6px;
      background:rgba(0,0,0,.15);
    }
    .iw-card .iw-progress > div{ width:0%; height:100%; background:#28a745; transition:width .15s linear; }
    .iw-card .iw-pct{
      position:absolute; bottom:8px; right:6px; font-size:12px;
      color:#fff; text-shadow:0 1px 2px rgba(0,0,0,.7);
    }
    .iw-uploading .btn-del{ display:none; }

    .toast-confirm.modal .modal-dialog{ position:fixed; right:1rem; bottom:1rem; margin:0; max-width:360px; }
    .toast-confirm .modal-content{ border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15); }
    .toast-confirm .thumb{ width:64px; height:64px; object-fit:cover; border-radius:.25rem; margin-right:.5rem; }

    #visitOverlay {
      position: fixed; inset: 0; background: rgba(255,255,255,.7);
      display: none; align-items: center; justify-content: center; z-index: 1050;
    }

    /* Sugerencias locales */
    #sugerenciasLocales .item { cursor:pointer; }
    #sugerenciasLocales .item:hover { background: #f2f2f2; }
  </style>
</head>
<body>
<div class="container mt-5">
  <h2 class="mb-3">Encuesta Complementaria: <?= htmlspecialchars($campana['nombre'], ENT_QUOTES) ?></h2>

  <?php if ($requiereLocal): ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="mb-3">Selecciona el local</h5>
        <input type="hidden" id="id_local" name="id_local" value="">
        <div class="form-group">
          <label for="buscarLocal">Buscar por código, nombre o direccion</label>
          <input type="text" id="buscarLocal" class="form-control" placeholder="Ej: 1234 o Plaza de Armas n° 989">
          <div id="sugerenciasLocales" class="list-group mt-2"></div>
        </div>
        <div id="localSeleccionado" class="alert alert-info d-none">
          Local seleccionado: <strong id="localEtiqueta"></strong>
          <button type="button" class="close" id="quitarLocal" aria-label="Quitar"><span aria-hidden="true">&times;</span></button>
        </div>
        <small class="text-muted">Debes elegir un local para poder crear la visita.</small>
      </div>
    </div>
  <?php endif; ?>

  <div id="visitOverlay">
    <div class="text-center">
      <div class="spinner-border text-primary mb-2" role="status" style="width:3rem; height:3rem;"></div>
      <div class="font-weight-bold">Creando visita...</div>
      <div class="small text-muted">Obteniendo tu ubicación</div>
    </div>
  </div>

  <form id="encuestaIW" method="post" action="procesar_gestionIW.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="idCampana"   value="<?= $idCampana ?>">
    <input type="hidden" name="latGestion"  id="latGestion" value="">
    <input type="hidden" name="lngGestion"  id="lngGestion" value="">
    <input type="hidden" name="iw_token"    id="iw_token"   value="<?= htmlspecialchars($iw_token, ENT_QUOTES) ?>">
    <input type="hidden" name="visita_id"   id="visita_id"  value="<?= (int)$visita_id ?>">
    <input type="hidden" name="id_local"    id="id_local_post" value="<?= $requiereLocal ? '' : '0' ?>">

    <?php foreach ($preguntas as $preg):
      $qid      = (int)$preg['id_form_question'];
      $text     = htmlspecialchars($preg['question_text'], ENT_QUOTES);
      $type     = (int)$preg['id_question_type'];
      $req      = $preg['is_required'] ? 1 : 0;
      $dep      = (int)$preg['id_dependency_option'];
      $condClass = $dep > 0 ? ' conditional-question' : '';
      $condAttr  = '';
      if ($dep > 0) {
          $parent = getParentQuestionId($conn, $dep);
          if ($parent) {
              $condAttr = "data-dependency-option=\"$dep\" data-parent-question=\"$parent\"";
          }
      }
    ?>
      <div class="form-group question-container<?= $condClass ?>"
           data-question-id="<?= $qid ?>"
           data-question-text="<?= $text ?>"
           data-required="<?= $req ?>"
           <?= $condAttr ?>
           style="<?= ($dep > 0 ? 'display:none;' : '') ?>"
      >
        <label class="font-weight-bold">
          <?= $text ?> <?= ($req ? '<span class="text-danger">*</span>' : '') ?>
        </label>

        <?php
        // Opciones para 1,2,3
        $opts = [];
        if (in_array($type, [1,2,3], true)) {
          $s = $conn->prepare("
            SELECT id, option_text, reference_image
            FROM form_question_options
            WHERE id_form_question = ?
            ORDER BY sort_order ASC
          ");
          $s->bind_param("i", $qid);
          $s->execute();
          $r = $s->get_result();
          while ($o = $r->fetch_assoc()) $opts[] = $o;
          $s->close();
        }

        if ($type === 1 || $type === 2):
          if (!empty($opts)):
            foreach ($opts as $o):
              $optId  = (int)$o['id'];
              $optTxt = htmlspecialchars($o['option_text'], ENT_QUOTES);
              $ref    = trim($o['reference_image']);
              $full   = $ref ? 'https://visibility.cl/' . ltrim($ref,'/') : '';
        ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="respuesta[<?= $qid ?>]" value="<?= $optId ?>">
              <label class="form-check-label"><?= $optTxt ?></label>
            </div>
            <?php if ($preg['is_valued']): ?>
              <input type="number" step="any" name="valorRespuesta[<?= $qid ?>][<?= $optId ?>]" placeholder="Valor" style="width:90px; margin-left:10px;">
            <?php endif; ?>
            <?php if (!empty($full)): ?>
              <img src="<?= $full ?>" class="thumbnail ml-2" onclick="verImagenGrande('<?= $full ?>')" title="Haz clic para agrandar">
            <?php endif; ?>
        <?php
            endforeach;
          else:
            echo "<p>No hay opciones disponibles.</p>";
          endif;

        elseif ($type === 3):
          if (!empty($opts)):
            foreach ($opts as $o):
              $optId  = (int)$o['id'];
              $optTxt = htmlspecialchars($o['option_text'], ENT_QUOTES);
              $ref    = trim($o['reference_image']);
              $full   = $ref ? 'https://visibility.cl/' . ltrim($ref,'/') : '';
        ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="respuesta[<?= $qid ?>][]" value="<?= $optId ?>">
              <label class="form-check-label"><?= $optTxt ?></label>
            </div>
            <?php if ($preg['is_valued']): ?>
              <input type="number" step="any" name="valorRespuesta[<?= $qid ?>][<?= $optId ?>]" placeholder="Valor" style="width:90px; margin-left:10px;">
            <?php endif; ?>
            <?php if (!empty($full)): ?>
              <img src="<?= $full ?>" class="thumbnail ml-2" onclick="verImagenGrande('<?= $full ?>')" title="Haz clic para agrandar">
            <?php endif; ?>
        <?php
            endforeach;
          else:
            echo "<p>No hay opciones disponibles.</p>";
          endif;

        elseif ($type === 4):
          echo '<input type="text" class="form-control" name="respuesta['.$qid.']">';

        elseif ($type === 5):
          echo '<input type="number" step="any" class="form-control" name="respuesta['.$qid.']">';

        elseif ($type === 6):
          echo '<input type="date" class="form-control" name="respuesta['.$qid.']">';

        elseif ($type === 7): ?>
          <div class="mb-2">
            <button type="button" class="btn btn-outline-primary btn-sm btn-gallery" data-qid="<?= $qid ?>">Elegir desde galería</button>
            <button type="button" class="btn btn-outline-success btn-sm btn-camera" data-qid="<?= $qid ?>">Tomar foto</button>
          </div>
          <input type="file"  id="fileGallery_<?= $qid ?>" accept="image/*" class="foto-iw" style="display:none;">
          <input type="file"  id="fileCamera_<?= $qid ?>"  accept="image/*" capture="environment" class="foto-iw" style="display:none;">
          <div id="previewFoto_<?= $qid ?>" class="mt-2 iw-previews d-flex flex-wrap"></div>
        <?php endif; ?>

      </div>
    <?php endforeach; ?>

    <button type="button" id="btnFinalizarIW" class="btn btn-success">
      <span id="btnText">Enviar Encuesta</span>
    </button>
    <div id="processingIndicator">
      <div class="spinner-border text-primary" role="status">
        <span class="sr-only">Procesando...</span>
      </div>
      <p class="mt-2">Enviando encuesta, por favor espere...</p>
    </div>
  </form>
</div>

<!-- Modal imagen -->
<div class="modal fade" id="modalImagenGrande" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width:90vw;">
    <div class="modal-content">
      <div class="modal-body text-center">
        <img id="imagenAmpliada" src="" class="modal-img" alt="Imagen">
      </div>
    </div>
  </div>
</div>

<!-- Modal confirm eliminación -->
<div class="modal fade toast-confirm" id="confirmDeleteModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-body p-3">
        <div class="d-flex align-items-center">
          <img id="confirmDeleteImg" class="thumb" src="" alt="foto a eliminar">
          <div class="flex-grow-1">
            <strong>¿Eliminar esta foto?</strong>
            <div class="small text-muted">Esta acción no se puede deshacer.</div>
          </div>
        </div>
        <div class="mt-2 text-right">
          <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-sm btn-danger" id="btnConfirmDelete">Eliminar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div aria-live="polite" aria-atomic="true" style="position:fixed; top:1rem; right:1rem; z-index:1080;">
  <div id="liveToast" class="toast" data-delay="2500">
    <div class="toast-header">
      <strong class="mr-auto" id="toastTitle">Aviso</strong>
      <small>Ahora</small>
      <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
    <div class="toast-body" id="toastBody"></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>

<script>
  let uploadsInProgress = 0;
  let visitReady = false;
  let creatingVisit = false;
  let qids = new Set();

  window.CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
  window.IW_TOKEN   = '<?= htmlspecialchars($iw_token, ENT_QUOTES) ?>';
  window.VISITA_ID  = parseInt('<?= (int)$visita_id ?>',10) || 0;
  window.IW_REQUIRE_LOCAL = <?= $requiereLocal ? 'true' : 'false' ?>;
  window.IW_ID_LOCAL = 0;

  function disableSubmit(){ $('#btnFinalizarIW').prop('disabled', true); }
  function enableSubmit(){ if (uploadsInProgress <= 0 && visitReady) $('#btnFinalizarIW').prop('disabled', false); }
  function checkUploads(){ if (uploadsInProgress <= 0) enableSubmit(); }

  function showToast(msg, title='Aviso'){
    $('#toastTitle').text(title);
    $('#toastBody').text(msg);
    $('#liveToast').toast('show');
  }

function verImagenGrande(url){
  $('#imagenAmpliada').attr('src', normalizePhotoUrl(url || ''));
  $('#modalImagenGrande').modal('show');
}
  function compressImage(file, maxW, maxH, callback) {
    const img = new Image();
    const reader = new FileReader();
    reader.onload = e => img.src = e.target.result;
    img.onload = () => {
      const scale = Math.min(maxW/img.width, maxH/img.height, 1);
      const w = img.width * scale, h = img.height * scale;
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      canvas.toBlob(callback, 'image/jpeg', 0.8);
    };
    reader.readAsDataURL(file);
  }

  // ======== Dependencias condicionales ========
  function clearAndHideBlock($b) {
    $b.find('input, select, textarea').each(function(){
      $(this).prop('required', false);
      if (this.type === 'radio' || this.type === 'checkbox') $(this).prop('checked', false);
      else $(this).val('');
      $(this).prop('disabled', true);
    });
    $('.conditional-question').filter(`[data-parent-question="${$b.data('question-id')}"]`)
      .each(function(){ clearAndHideBlock($(this)); $(this).hide(); });
  }

  function applyRequiredIfNeeded($block, enable){
    const isReq = +$block.data('required') === 1;
    if (!isReq) return;
    const $radios = $block.find('input[type=radio]');
    if ($radios.length) $radios.first().prop('required', enable);
    $block.find('input[type=text], input[type=number], input[type=date], select, textarea')
      .each(function(){ $(this).prop('required', enable); });
  }

  function onDependencyChange($input, isCheckbox) {
    const $parent = $input.closest('.question-container');
    const pid = $parent.data('question-id');
    $('.conditional-question').filter(function(){ return +$(this).data('parent-question') === pid; })
      .each(function(){
        const $child = $(this);
        const dep = ''+$(this).data('dependency-option');
        const show = isCheckbox
          ? $parent.find(`input[type=checkbox][value="${dep}"]`).is(':checked')
          : ($parent.find('input[type=radio]:checked').val() === dep);
        if (show) {
          $child.show().find('input,select,textarea').prop('disabled', false);
          applyRequiredIfNeeded($child, true);
        } else {
          applyRequiredIfNeeded($child, false);
          clearAndHideBlock($child);
          $child.hide();
        }
      });
  }

  $(function(){
    $('.conditional-question').each(function(){
      if (!$(this).is(':visible')){
        $(this).find('input,select,textarea').prop('disabled', true).prop('required', false);
      }
    });
  });
  $(document).on('change','.question-container input[type=radio]',function(){ onDependencyChange($(this), false); });
  $(document).on('change','.question-container input[type=checkbox]',function(){ onDependencyChange($(this), true); });

  // ======== VISITA: creación ========
  function setVisitReady(id){
    window.VISITA_ID = parseInt(id,10) || 0;
    $('#visita_id').val(window.VISITA_ID);
    visitReady = window.VISITA_ID > 0;
    creatingVisit = false;
    $('#visitOverlay').hide();

    // Habilitar botones de foto
    $('.btn-gallery, .btn-camera').prop('disabled', !visitReady);
    enableSubmit();

    if (visitReady) {
      qids.forEach(qid => fetchFotosIW(qid));
    }
  }

  async function crearVisitaIW({ idCampana, lat, lng, id_local }) {
    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('idCampana', idCampana);
    fd.append('lat', lat ?? '');
    fd.append('lng', lng ?? '');
    if (window.IW_REQUIRE_LOCAL) fd.append('id_local', id_local || '');

    const res = await fetch('crear_visitaIW.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    const data = await res.json().catch(() => null);
    if (!res.ok) throw new Error((data && data.message) || `HTTP ${res.status}`);
    window.VISITA_ID = data.visita_id;
    document.getElementById('visita_id').value = data.visita_id;
    return data.visita_id;
  }
  
    async function cambiarLocalIW({ idCampana, visita_id, id_local }) {
      const fd = new FormData();
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('idCampana', idCampana);
      fd.append('visita_id', visita_id);
      fd.append('id_local', id_local);
    
      const res = await fetch('cambiar_localIW.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json().catch(()=>null);
      if (!res.ok || !data || data.status!=='success') {
        throw new Error((data && data.message) || `HTTP ${res.status}`);
      }
      return data;
    }



  function createVisit(lat, lng) {
    if (creatingVisit) return;
    if (window.IW_REQUIRE_LOCAL && !window.IW_ID_LOCAL) {
      showToast('Selecciona un local antes de crear la visita', 'Falta local');
      return;
    }
    creatingVisit = true;
    $('#visitOverlay').show();

    crearVisitaIW({ idCampana: <?= (int)$idCampana ?>, lat, lng, id_local: window.IW_ID_LOCAL })
      .then(setVisitReady)
      .catch((err) => {
        showToast(err.message || 'Error de red al crear la visita', 'Error');
        creatingVisit = false;
        $('#visitOverlay').hide();
      });
  }

  function initVisit(){
    // Si requiere local, esperamos a que el usuario lo elija
    if (window.IW_REQUIRE_LOCAL) {
      $('.btn-gallery, .btn-camera').prop('disabled', true);
      disableSubmit();
      return;
    }

    // Si el servidor ya metió un visita_id (reutilizado), úsalo
    const existing = parseInt($('#visita_id').val() || '0', 10);
    if (existing > 0) { setVisitReady(existing); return; }

    // Sin local requerido: creamos de inmediato
    if (navigator.geolocation){
      navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        $('#latGestion').val(lat.toFixed(6));
        $('#lngGestion').val(lng.toFixed(6));
        createVisit(lat, lng);
      }, () => createVisit(null, null), { enableHighAccuracy:true, timeout:10000 });
    } else {
      createVisit(null, null);
    }
  }

  // ======== Envío del formulario ========
  $('#btnFinalizarIW').click(function(e){
    if (window.IW_REQUIRE_LOCAL && !window.IW_ID_LOCAL) {
      showToast('Debes seleccionar un local', 'Falta local');
      return;
    }
    if (!visitReady) {
      showToast('Aún estamos creando la visita. Espera un momento.', 'Creando visita');
      return;
    }
    if (uploadsInProgress > 0) {
      showToast('Hay fotos que todavía se están subiendo. Espera un momento.', 'Subiendo fotos');
      return;
    }

    let ok = true;

    $('.question-container:visible').each(function(){
      const $div  = $(this);
      const qText = $div.data('question-text');
      const req   = +$div.data('required') === 1;
      if (!req) return;

      let answered = false;

      $div.find('input[type=radio],input[type=checkbox]').each(function(){
        if (this.checked) { answered = true; return false; }
      });

      if (!answered){
        $div.find('input:not([type=radio]):not([type=checkbox]), select, textarea').each(function(){
          if ($.trim($(this).val()) !== '') { answered = true; return false; }
        });
      }

      if (!answered && $div.find('.iw-previews').length){
        const qid = String($div.data('question-id'));
        if (hasSavedFotos(qid)) answered = true;
      }

      if (!answered){
        $div.addClass('border border-danger');
        setTimeout(()=> $div.removeClass('border border-danger'), 1500);
        showToast('Debes responder: ' + qText, 'Falta información');
        $div[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        ok = false;
        return false;
      }
    });

    if (!ok) return;

    const $btn = $(this);
    $btn.prop('disabled', true);
    $('#btnText').text('Procesando...');
    $('#processingIndicator').show();

    // Pasamos id_local al POST final
    $('#id_local_post').val(window.IW_ID_LOCAL || 0);

    e.preventDefault();
    if (navigator.geolocation){
      navigator.geolocation.getCurrentPosition(pos=>{
        $('#latGestion').val(pos.coords.latitude.toFixed(6));
        $('#lngGestion').val(pos.coords.longitude.toFixed(6));
        $('#encuestaIW').submit();
      }, ()=>$('#encuestaIW').submit(), { enableHighAccuracy:true, timeout:10000 });
    } else {
      $('#encuestaIW').submit();
    }
  });

  // ======== Fotos IW ========
  function setupFotoIW(qid) {
    const gallery = document.getElementById(`fileGallery_${qid}`);
    const camera  = document.getElementById(`fileCamera_${qid}`);

    [gallery,camera].forEach(input=>{
      if (!input) return;
      input.addEventListener('change', ()=> {
        if (!visitReady) {
          showToast('Aún no está lista la visita. Intenta en unos segundos.', 'Creando visita');
          input.value = '';
          return;
        }
        if (!input.files.length) return;
        const file = input.files[0];

        uploadsInProgress++;
        disableSubmit();

        const isHEIC = /\.(heic|heif)$/i.test(file.name) ||
                       file.type === 'image/heic' ||
                       file.type === 'image/heif';

        const doProcess = blob => {
          const wrap  = document.getElementById(`previewFoto_${qid}`);
          const objUrl = URL.createObjectURL(blob);

          const temp = document.createElement('div');
          temp.className = 'iw-card iw-uploading';
          temp.innerHTML = `
            <img src="${objUrl}" title="Subiendo...">
            <div class="iw-progress"><div></div></div>
            <div class="iw-pct">0%</div>
          `;
          wrap.appendChild(temp);

          temp.querySelector('img').addEventListener('click', ()=> verImagenGrande(objUrl));

          compressImage(blob, 1024, 1024, compressedBlob => {
            const fd = new FormData();
            
            fd.append('csrf_token', window.CSRF_TOKEN);
            fd.append('id_form_question', qid);
            fd.append('fotoPregunta', compressedBlob, file.name.replace(/\.[^/.]+$/,'.jpg'));
            fd.append('iw_token', window.IW_TOKEN);
            fd.append('visita_id', window.VISITA_ID);
            fd.append('capture_source', input === camera ? 'camera' : 'gallery');

            const xhr = new XMLHttpRequest();
            xhr.open('POST','procesar_pregunta_fotoIW.php',true);

            xhr.upload.onprogress = e => {
              if (!e.lengthComputable) return;
              const pct = Math.round(e.loaded/e.total*100);
              const bar = temp.querySelector('.iw-progress > div');
              const lbl = temp.querySelector('.iw-pct');
              if (bar) bar.style.width = pct + '%';
              if (lbl) lbl.textContent = pct + '%';
            };

            const finishUpload = () => { uploadsInProgress--; checkUploads(); };

            xhr.onload = () => {
              let res;
              try { res = JSON.parse(xhr.responseText); } catch { res = {status:'error'}; }
              if (temp && temp.parentNode) temp.parentNode.removeChild(temp);
              resetFileInputs(qid);

              if (res.status==='success'){
                const wrap2 = document.getElementById(`previewFoto_${qid}`);
                wrap2.appendChild(buildPreviewItem(qid, res.resp_id, res.fotoUrl));
              } else {
                showToast(res.message || 'Error al subir la foto', 'Error');
              }
              finishUpload();
            };

            xhr.onerror = () => {
              if (temp && temp.parentNode) temp.parentNode.removeChild(temp);
              resetFileInputs(qid);
              showToast('Error de red al subir la foto','Error');
              finishUpload();
            };

            xhr.send(fd);
          });
        };

        if (isHEIC && window.heic2any) {
          heic2any({ blob:file, toType:"image/jpeg", quality:0.8 })
            .then(output => {
              const blob = Array.isArray(output) ? output[0] : output;
              doProcess(blob);
            })
            .catch(err => {
              showToast('No se pudo procesar HEIC: ' + err.message, 'Error');
              uploadsInProgress--; checkUploads();
            });
        } else {
          doProcess(file);
        }
      });
    });
  }

function buildPreviewItem(qid, respId, url) {
  const card = document.createElement('div');
  card.className = 'iw-card';
  card.dataset.respId = respId;

  const safeUrl = normalizePhotoUrl(url);

  const img = document.createElement('img');
  img.src = safeUrl;
  img.title = 'Click para ampliar';
  img.addEventListener('click', () => verImagenGrande(safeUrl));

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-sm btn-danger btn-del';
  btn.textContent = '×';
  btn.title = 'Eliminar esta foto';
  btn.addEventListener('click', () => deleteFotoIW(qid, respId, card));

  card.appendChild(img);
  card.appendChild(btn);
  return card;
}

  let pendingDelete = null;

  function hasSavedFotos(qid){
    return document.querySelectorAll(`#previewFoto_${qid} .iw-card:not(.iw-uploading)`).length > 0;
  }

  function resetFileInputs(qid){
    const g = document.getElementById(`fileGallery_${qid}`);
    const c = document.getElementById(`fileCamera_${qid}`);
    if (g) g.value = '';
    if (c) c.value = '';
  }

function deleteFotoIW(qid, respId, cardEl) {
  const imgUrl = cardEl.querySelector('img') ? cardEl.querySelector('img').getAttribute('src') : '';
  pendingDelete = { qid, respId, cardEl };
  $('#confirmDeleteImg').attr('src', normalizePhotoUrl(imgUrl));
  $('#confirmDeleteModal').modal('show');
}

  $('#btnConfirmDelete').on('click', function(){
    if (!pendingDelete) return;
    const { qid, respId, cardEl } = pendingDelete;

    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('resp_id', respId);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'eliminar_pregunta_fotoIW.php', true);
    xhr.onload = () => {
      let res;
      try { res = JSON.parse(xhr.responseText); } catch { res = {status:'error'}; }
      if (res.status === 'success') {
        if (cardEl && cardEl.parentNode) cardEl.parentNode.removeChild(cardEl);
        $('#confirmDeleteModal').modal('hide');
        showToast('Foto eliminada','OK');
        const qidStr = String(qid);
        if (!hasSavedFotos(qidStr)) resetFileInputs(qidStr);
      } else {
        $('#confirmDeleteModal').modal('hide');
        showToast(res.message || 'No se pudo eliminar la foto','Error');
      }
      pendingDelete = null;
    };
    xhr.onerror = () => {
      $('#confirmDeleteModal').modal('hide');
      pendingDelete = null;
      showToast('Error de red al eliminar la foto','Error');
    };
    xhr.send(fd);
  });

  function fetchFotosIW(qid) {
    if (!visitReady) return;
    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('id_form_question', qid);
    fd.append('visita_id', window.VISITA_ID);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'listar_fotos_preguntaIW.php', true);
    xhr.onload = () => {
      let res;
      try { res = JSON.parse(xhr.responseText); } catch { res = {status:'error'}; }
      if (res.status === 'success' && Array.isArray(res.fotos)) {
        const wrap = document.getElementById(`previewFoto_${qid}`);
        res.fotos.forEach(f => wrap.appendChild(buildPreviewItem(qid, f.resp_id, f.fotoUrl)));
      }
    };
    xhr.send(fd);
  }

  // Botones para disparar inputs
  $(document).on('click', '.btn-gallery', function (e) {
    e.preventDefault();
    if (!visitReady) { showToast('Aún no está lista la visita.', 'Creando visita'); return; }
    const qid = $(this).data('qid');
    const input = document.getElementById(`fileGallery_${qid}`);
    if (input) input.click();
  });
  $(document).on('click', '.btn-camera', function (e) {
    e.preventDefault();
    if (!visitReady) { showToast('Aún no está lista la visita.', 'Creando visita'); return; }
    const qid = $(this).data('qid');
    const input = document.getElementById(`fileCamera_${qid}`);
    if (input) input.click();
  });

  // ======== Buscar/seleccionar local (solo si requiere) ========
  function pintarSugerencias(items){
    const box = $('#sugerenciasLocales');
    box.empty();
    if (!items || !items.length) return;
    items.forEach(it => {
      const a = $('<a class="list-group-item list-group-item-action item"></a>');
      a.text(it.etiqueta);
      a.data('id', it.id);
      a.data('etiqueta', it.etiqueta);
      box.append(a);
    });
  }

  let buscarTimeout = null;
  $('#buscarLocal').on('input', function(){
    if (!window.IW_REQUIRE_LOCAL) return;
    const q = this.value.trim();
    clearTimeout(buscarTimeout);
    if (q.length < 2) { $('#sugerenciasLocales').empty(); return; }
    buscarTimeout = setTimeout(() => {
      $.getJSON('buscar_localesIW.php', {
        idCampana: <?= (int)$idCampana ?>,
        q: q
      }).done(resp => {
        if (resp && resp.status === 'success') {
          pintarSugerencias(resp.locales || []);
        } else {
          $('#sugerenciasLocales').empty();
        }
      }).fail(() => $('#sugerenciasLocales').empty());
    }, 250);
  });

  $(document).on('click', '#sugerenciasLocales .item', async function(){
  const id = $(this).data('id');
  const et = $(this).data('etiqueta');
  $('#sugerenciasLocales').empty();
  $('#buscarLocal').val('');

  try {
    if (!visitReady) {
      // crear visita normal (como ya lo haces)
      window.IW_ID_LOCAL = parseInt(id,10) || 0;
      $('#id_local').val(window.IW_ID_LOCAL);
      $('#id_local_post').val(window.IW_ID_LOCAL);
      $('#localEtiqueta').text(et);
      $('#localSeleccionado').removeClass('d-none');

      // crear visita con el local elegido
      if (navigator.geolocation){
        navigator.geolocation.getCurrentPosition(pos => {
          const lat = pos.coords.latitude, lng = pos.coords.longitude;
          $('#latGestion').val(lat.toFixed(6));
          $('#lngGestion').val(lng.toFixed(6));
          createVisit(lat, lng);
        }, () => createVisit(null, null), { enableHighAccuracy:true, timeout:10000 });
      } else {
        createVisit(null, null);
      }

    } else {
      // cambiar local de la visita existente
      $('#visitOverlay .font-weight-bold').text('Cambiando local...');
      $('#visitOverlay').show();

      const data = await cambiarLocalIW({
        idCampana: <?= (int)$idCampana ?>,
        visita_id: window.VISITA_ID,
        id_local: parseInt(id,10) || 0
      });

      window.IW_ID_LOCAL = data.id_local;
      $('#id_local').val(window.IW_ID_LOCAL);
      $('#id_local_post').val(window.IW_ID_LOCAL);
      $('#localEtiqueta').text(et);
      $('#localSeleccionado').removeClass('d-none');

      showToast('Local actualizado en la visita','OK');
    }
  } catch (err) {
    showToast(err.message || 'No se pudo cambiar el local','Error');
  } finally {
    $('#visitOverlay').hide();
  }
});

$('#quitarLocal').on('click', function(){
  // No ponemos IW_ID_LOCAL = 0 ya que dessactivariamos la visita.
  $('#localSeleccionado').addClass('d-none');
  $('#buscarLocal').val('').focus();
});

  // ======== Init ========
  document.addEventListener('DOMContentLoaded', () => {
    // Recolectar QIDs de preguntas con foto
    document.querySelectorAll('.foto-iw').forEach(input => {
      const parts = input.id.split('_');
      const qid   = parts[1];
      if (qid) qids.add(qid);
    });

    // Deshabilitar mientras no exista visita
    if (!(parseInt(document.getElementById('visita_id').value || '0',10) > 0)) {
      $('.btn-gallery, .btn-camera').prop('disabled', true);
      disableSubmit();
    }

    // Set listeners para inputs de foto
    qids.forEach(qid => setupFotoIW(qid));

    // Crear/reusar visita (si no requiere local)
    initVisit();
  });
  
  
  function normalizePhotoUrl(u){
  if (!u) return '';
  // Absolutas o blobs/data
  if (/^(?:https?:)?\/\//i.test(u) || u.startsWith('blob:') || u.startsWith('data:')) return u;
  // Ya anclada a la raíz
  if (u[0] === '/') return u;
  // Casos comunes que te llegan desde el backend
  if (u.startsWith('visibility2/')) return '/' + u;                 // "visibility2/app/uploads/..."
  if (u.startsWith('uploads/'))     return '/visibility2/app/' + u; // "uploads/fotos_IW/..."
  // Cualquier otra relativa -> anclar a raíz
  return '/' + u;
}
</script>
</body>
</html>
<?php $conn->close(); ?>

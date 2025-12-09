<?php
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
//verificacon de campaña y local

if (isset($_GET['idCampana']) && isset($_GET['nombreCampana']) && isset($_GET['idLocal'])) {
    $idCampana      = intval($_GET['idCampana']);
    $nombreCampana  = htmlspecialchars($_GET['nombreCampana'], ENT_QUOTES, 'UTF-8');
    $idLocal        = intval($_GET['idLocal']);
} else {
    die("Parámetros inválidos.");
}
// Datos de sesión
$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);

// Validar que la campaña y el local pertenezcan a ese usuario
$sql_validar = "
    SELECT
        f.nombre AS nombreCampanaDB,
        f.id_division AS idDivision,
        f.modalidad AS modalidadCampana,
        l.nombre AS nombreLocal,
        l.direccion AS direccionLocal,
        l.lat AS lat,
        l.lng AS lng
    FROM formularioQuestion fq
    INNER JOIN formulario AS f ON f.id = fq.id_formulario
    INNER JOIN local AS l ON l.id = fq.id_local
    WHERE fq.id_formulario = ?
      AND fq.id_local = ?
      AND fq.id_usuario = ?
      AND f.id_empresa = ?
    LIMIT 1
";
$stmt_validar = $conn->prepare($sql_validar);
if ($stmt_validar === false) {
    die("Error preparando la consulta: " . htmlspecialchars($conn->error));
}
$stmt_validar->bind_param("iiii", $idCampana, $idLocal, $usuario_id, $empresa_id);
$stmt_validar->execute();
$result_validar = $stmt_validar->get_result();

if ($result_validar->num_rows === 0) {
    die("No tienes permisos para gestionar esta campaña o la campaña no existe.");
}

$row = $result_validar->fetch_assoc();
$modalidad = $row['modalidadCampana'];
$isRetiro      = ($modalidad === 'retiro');
$actionLabel   = $isRetiro ? 'Retirar'     : 'Implementar';
$sectionLabel  = $isRetiro ? 'Retiro'      : 'Implementación';
$nombreCampanaDB = htmlspecialchars($row['nombreCampanaDB'], ENT_QUOTES, 'UTF-8');
$idDivision    = intval($row['idDivision']);
$nombreLocal   = htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8');
$direccionLocal= htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8');
$latitud       = floatval($row['lat']);
$longitud      = floatval($row['lng']);
$stmt_validar->close();

// ¿Encuesta pendiente?
$sql_respuestas = "
    SELECT COUNT(*) as cnt
    FROM form_question_responses AS fqr
    INNER JOIN form_questions AS fq ON fqr.id_form_question = fq.id
    WHERE fqr.id_local = ?
      AND fqr.id_usuario = ?
      AND fq.id_formulario = ?
";
$stmt_res = $conn->prepare($sql_respuestas);
$stmt_res->bind_param("iii", $idLocal, $usuario_id, $idCampana);
$stmt_res->execute();
$result_res = $stmt_res->get_result();
$row_res = $result_res->fetch_assoc();
$respuestasCount = intval($row_res['cnt']);
$stmt_res->close();

$encuestaPendiente = true;


// Preguntas
$preguntas = [];
if ($encuestaPendiente) {
    $sql_encuesta = "
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
    ";
    $stmt_encuesta = $conn->prepare($sql_encuesta);
    $stmt_encuesta->bind_param("i", $idCampana);
    $stmt_encuesta->execute();
    $result_encuesta = $stmt_encuesta->get_result();
    while ($rowE = $result_encuesta->fetch_assoc()) {
        $preguntas[] = $rowE;
    }
    $stmt_encuesta->close();
}

$parentPreguntas = [];
$conditionalPreguntas = [];
foreach ($preguntas as $preg) {
    $tipo = intval($preg['id_question_type']);
    $dep  = isset($preg['id_dependency_option']) ? intval($preg['id_dependency_option']) : 0;
    if ($dep > 0 && ($tipo === 2 || $tipo === 3)) {
        $conditionalPreguntas[] = $preg;
    } else {
        $parentPreguntas[] = $preg;
    }
}

// Mensajes
$status  = isset($_GET['status']) ? $_GET['status'] : '';
$mensaje = isset($_GET['mensaje']) ? htmlspecialchars($_GET['mensaje'], ENT_QUOTES, 'UTF-8') : '';

// Materiales
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
$stmt_materiales = $conn->prepare($sql_materiales);
if ($stmt_materiales) {
    $stmt_materiales->bind_param("iii", $idLocal, $idCampana, $usuario_id);
    $stmt_materiales->execute();
    $result_materiales = $stmt_materiales->get_result();
    $materiales = [];
    while ($mat = $result_materiales->fetch_assoc()) {
        $materiales[] = [
            'id'              => intval($mat['id']),
            'material'        => htmlspecialchars($mat['material'] ?? '', ENT_QUOTES, 'UTF-8'),
            'valor_propuesto' => htmlspecialchars($mat['valor_propuesto'] ?? '', ENT_QUOTES, 'UTF-8'),
            'valor'           => htmlspecialchars($mat['valor'] ?? '', ENT_QUOTES, 'UTF-8'),
            'fechaVisita'     => htmlspecialchars($mat['fechaVisita'] ?? '', ENT_QUOTES, 'UTF-8'),
            'observacion'     => htmlspecialchars($mat['observacion'] ?? '', ENT_QUOTES, 'UTF-8'),
            'ref_image'       => htmlspecialchars($mat['ref_image'] ?? '', ENT_QUOTES, 'UTF-8'),
        ];
    }
    $stmt_materiales->close();
} else {
    die("Error al preparar la consulta de materiales: " . htmlspecialchars($conn->error));
}

$totalSteps = $encuestaPendiente ? 3 : 2;

function getParentQuestionId($conn, $id_dependency_option) {
    $sql = "SELECT id_form_question FROM form_question_options WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $id_dependency_option);
        $stmt->execute();
        $stmt->bind_result($parentId);
        if ($stmt->fetch()) {
            $stmt->close();
            return $parentId;
        }
        $stmt->close();
    }
    return null;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Gestionar Campaña - <?php echo $nombreCampanaDB; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <!-- Bootstrap / Font Awesome -->
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <script src="https://cdn.jsdelivr.net/npm/browser-image-compression@latest/dist/browser-image-compression.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
    <!-- Offline libs -->
    <script src="/visibility2/app/assets/js/db.js"></script>
    <script src="assets/js/journal_db.js"></script>
    <script src="/visibility2/app/assets/js/offline-queue.js"></script>
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
navigator.serviceWorker.register('/visibility2/app/sw.js', { scope: '/visibility2/app/' }).catch(()=>{});
        });
      }
    </script>

   <script>
  window.OfflineQueue = Object.assign({}, window.Queue, {
    enqueueJSON: (url, data, opts={}) => {
      const options = {
        type: opts.type || 'generic',
        sendCSRF: opts.sendCSRF !== false,
        id: opts.idempotencyKey || opts.id || undefined,   // <-- pasa la key
        dedupeKey: opts.dedupeKey || undefined,
        dependsOn: opts.dependsOn || undefined,
        client_guid: opts.client_guid || undefined
      };
      return window.Queue.smartPost(url, data, options);
    },
    enqueueForm: (url, formEl, opts={}) =>
      window.Queue.enqueueFromForm(url, formEl, opts.type || 'generic')
  });
  
  
  
  
</script>
    

<style>
input[type=file][id^="fotoPregunta_"] {
  position:absolute !important;
  width:1px;height:1px;padding:0;margin:-1px;border:0;overflow:hidden;clip:rect(0,0,0,0);
}
/* Controles bonitos */
.mc-file-actions{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.mc-btn{
  --mc-bg: #e9eef5; --mc-border: rgba(0,0,0,.08); --mc-shadow: rgba(0,0,0,.10);
  display:inline-flex; align-items:center; gap:.5rem;
  padding:.55rem .9rem; border-radius:9999px; border:1px solid var(--mc-border);
  background: linear-gradient(180deg, var(--mc-bg), #fff);
  box-shadow: 0 2px 6px var(--mc-shadow);
  font-weight:600; letter-spacing:.2px;
  transition: transform .08s ease, box-shadow .15s ease, background .15s ease;
  cursor:pointer; user-select:none;
}
.mc-btn i{ font-size:1rem; opacity:.9 }
.mc-btn--gallery{ --mc-bg:#e6f0ff; color:#0b5ed7; }
.mc-btn--camera { --mc-bg:#e8f7ee; color:#198754; }
.mc-btn:hover{ transform: translateY(-1px); box-shadow:0 6px 16px rgba(0,0,0,.14); }
.mc-btn:active{ transform: translateY(0); box-shadow:0 2px 8px rgba(0,0,0,.18) inset; }
.mc-btn:focus-visible{ outline: none; box-shadow:0 0 0 3px rgba(13,110,253,.25), 0 2px 6px rgba(0,0,0,.10); }
.mc-btn[disabled]{ opacity:.6; cursor:not-allowed; transform:none; }
.mc-btn.is-loading{ position:relative; pointer-events:none; }
.mc-btn.is-loading::after{
  content:""; width:1em; height:1em; border-radius:50%;
  border:2px solid currentColor; border-right-color:transparent;
  animation: mc-spin .7s linear infinite; margin-left:.25rem;
}
@keyframes mc-spin{ to{ transform:rotate(360deg); } }

/* Toasts */
.mc-toasts { position: fixed; top: 16px; right: 16px; z-index: 1060; pointer-events: none; }
.mc-toast { min-width: 280px; max-width: 360px; margin-bottom: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15); border-radius: 6px; pointer-events: all; }
.mc-toast .close { float: right; font-size: 18px; line-height: 1; opacity: .6; }
.mc-toast .close:hover { opacity: .9; }

/* A) Preview fotos encuesta */
[id^="previewFoto_"] { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
[id^="previewFoto_"] .upload-instance { display: flex; flex-direction: column; align-items: center; width: 100px; }
[id^="previewFoto_"] .upload-instance img.thumbnail { width: 100px; height: 100px; object-fit: cover; border: 1px solid #ccc; border-radius: 4px; margin-top: 4px; }
[id^="previewFoto_"] .upload-instance .upload-bar { width: 0%; height: 4px; background: #4caf50; transition: width 0.2s ease; border-radius: 2px; }
[id^="previewFoto_"] .upload-instance .upload-bar.error { background: #f44336; }
[id^="previewFoto_"] .upload-instance img.thumbnail:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
[id^="previewFoto_"] .upload-instance .alert { width: 100px; padding: 4px 6px; font-size: 0.85rem; text-align: center; margin-top: 4px; box-sizing: border-box; }

/* B) Preview fotos materiales */
.preview-container { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.preview-container .upload-instance{ display:flex; flex-direction:column; align-items:center; width:100px; }
.preview-container .upload-instance img.thumbnail{ width:100px; height:100px; object-fit:cover; border:1px solid #ccc; border-radius:4px; margin-top:4px; }
.preview-container .upload-instance .upload-bar{ width:0%; height:4px; background:#4caf50; transition:width .2s ease; border-radius:2px; }
.preview-container .upload-instance .upload-bar.error{ background:#f44336; }
.preview-container .upload-instance img.thumbnail:hover{ box-shadow:0 2px 8px rgba(0,0,0,.2); }
.preview-container .upload-instance .alert{ width:100px; padding:4px 6px; font-size:.85rem; text-align:center; margin-top:4px; box-sizing:border-box; }

/* Otros */
.form-group { border: 1px solid #d0d7de; border-radius: 8px; padding: 12px; background: #fff; }
.modal-img { width: 300px!important; height: 500px!important; }
.upload-bar { width:0%; height:4px; background:#4caf50; margin:4px 0; transition:width .2s ease; }
.upload-bar.error { background:#f44336; }
.progress { margin-bottom: 20px; }
.progress-bar-striped { background-color: #337ab7; }
.wizard-step { display: none; }
.question-container { margin-bottom: 15px; }
.conditional-question { display: none; }
.img-container { position: relative; display: inline-block; margin: 5px; }
.delete-button { position: absolute; top: 5px; right: 5px; background-color: red; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; }
.thumbnail { max-width: 100px; max-height: 100px; }
.photo-source { margin-bottom: 5px; width: 60%; max-width: 200px; }
.coords-foto { display: none; }
.modal-img { width: 100%; height: auto; }

/* Geolocalización obligatoria */
.geo-blocker{
  position: fixed; inset: 0;
  background: rgba(0,0,0,.65);
  z-index: 99999;
  display: none;
  align-items: center; justify-content: center;
}
.geo-card{
  background:#fff; border-radius:8px; padding:20px;
  max-width: 440px; width: 92%;
  box-shadow: 0 10px 24px rgba(0,0,0,.25);
  text-align:center;
}
.geo-card h4{ margin-top:0; font-weight:700 }
#geoErrorMsg{ margin-top:.5rem }
.gps-badge{
  display:inline-block; margin-left:10px; padding:2px 8px;
  border-radius:16px; font-size:.85rem;
  background:#f8d7da; color:#721c24;
}
.gps-badge.ok{ background:#d4edda; color:#155724; }
</style>
</head>
<body>
    
    <!-- navbar -->
    <div class="navbar navbar-inverse navbar-fixed-top">
       <div class="container">
          <div class="navbar-header">
             <button data-target=".navbar-collapse" data-toggle="collapse" class="navbar-toggle" type="button">
               <span class="icon-bar"></span>
               <span class="icon-bar"></span>
               <span class="icon-bar"></span>
             </button>
             <a class="navbar-brand" href="#">VISIBILITY 2</a>
          </div>
          <div class="navbar-tools">
             <div class="nickname">
                <?php echo htmlspecialchars($_SESSION['usuario_nombre'].' '.$_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8'); ?>
                <span id="geoStatusBadge" class="gps-badge">GPS requerido</span>
             </div>
             <ul class="nav navbar-right">
                <li class="dropdown current-user">
                   <a data-toggle="dropdown" data-hover="dropdown" class="dropdown-toggle" data-close-others="true" href="#">
                     <i class="fa fa-chevron-down"></i>
                   </a>
                   <ul class="dropdown-menu">
                      <li><a href="perfil.php"><i class="fa fa-user"></i> &nbsp;Perfil</a></li>
                      <li><a href="logout.php"><i class="fa fa-sign-out"></i> &nbsp;Cerrar sesión</a></li>
                   </ul>
                </li>
             </ul>
          </div>
       </div>
    </div>

    <div class="main-container" style="margin-top:70px;">
       <div class="main-content">
          <div class="container">
             <div class="row">
                <div class="col-sm-12">
                   <div class="page-header">
                      <h2 style="font-size: 1.5rem;">
                        Gestionar Campaña: <strong><?php echo $nombreCampanaDB; ?></strong><br>
                        en Local: <strong><?php echo $nombreLocal; ?></strong>
                      </h2>
                      <p>Dirección: <strong><?php echo $direccionLocal; ?></strong></p>
                   </div>
                </div>
             </div>

             <!-- Mensajes -->
             <?php
             if ($status === 'success') {
                 echo '<div class="alert alert-success">La gestión se ha realizado correctamente.</div>';
             } elseif ($status === 'error') {
                 if (!empty($mensaje)) {
                     echo '<div class="alert alert-danger">' . $mensaje . '</div>';
                 } else {
                     echo '<div class="alert alert-danger">Hubo un error al realizar la gestión. Por favor, intenta nuevamente.</div>';
                 }
             }
             ?>

             <!-- Progreso -->
             <div class="progress">
               <div class="progress-bar progress-bar-striped active" id="wizardProgress"
                    role="progressbar" aria-valuenow="33" aria-valuemin="0" aria-valuemax="100"
                    style="width: <?php echo (100 / $totalSteps); ?>%;">
                  Paso 1 de <?php echo $totalSteps; ?>
               </div>
             </div>

             <!-- FORM -->
             <form id="gestionarForm" method="post" action="/visibility2/app/procesar_gestion_pruebas.php" enctype="multipart/form-data">
                 <!-- Hidden -->
                 <input type="hidden" id="visita_id" name="visita_id" value="">
                 <input type="hidden" id="latGestion" name="latGestion" value="">
                 <input type="hidden" id="lngGestion" name="lngGestion" value="">
                 <!-- Offline/Idempotencia -->
                 <input type="hidden" id="client_guid" name="client_guid" value="">
                 <input type="hidden" id="idemp_create" value="">

                 <!-- Paso 1 -->
                 <div class="wizard-step" id="step-1">
                     <h4>Paso 1: Estado de la Gestión</h4>
                     <div class="form-group">
                       <label for="estadoGestion">1. Estado Gestión:</label>
                       <select class="form-control" id="estadoGestion" name="estadoGestion" required>
                         <option value="" disabled selected>Seleccione una opción</option>
                         <?php if ($modalidad === 'implementacion_auditoria'): ?>
                           <option value="implementado_auditado">Implementado y Auditado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'solo_implementacion'): ?>
                           <option value="solo_implementado">Solo Implementado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'retiro'): ?>
                           <option value="solo_retirado">Solo Retirado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'solo_auditoria'): ?>
                           <option value="solo_auditoria">Solo Auditoría</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php endif; ?>
                       </select>
                     </div>
                     <div class="form-group" id="motivoContainer" style="display:none;">
                       <label for="motivo">2. Motivo:</label>
                       <select class="form-control" id="motivo" name="motivo">
                         <option value="" disabled selected>Seleccione una opción</option>
                       </select>
                     </div>
                     <br>
                     <button type="button" class="btn btn-primary" id="btnNext1" disabled>Siguiente &raquo;</button>
                     <a href="index_pruebas.php">&laquo; Volver</a>
                 </div>

                 <!-- Paso 2 -->
                 <div class="wizard-step" id="step-2">
                     <h4 id="tituloPaso2">Paso 2</h4>
                     <div id="materialesContainer" style="display:none;">
                        <label>Materiales y Valores:</label><br>
                        <?php
                        if (count($materiales) === 1 && isset($materiales[0]['material']) && $materiales[0]['material'] === '-') {
                            echo "<p>No hay materiales cargados. Use el botón <strong>Agregar Material</strong> para añadir nuevos materiales.</p>";
                        } elseif (!empty($materiales)) {
                            foreach ($materiales as $m) {
                                $idFQ       = $m['id'];
                                $matName    = ucfirst(mb_strtolower($m['material'], 'UTF-8'));
                                $valorProp  = $m['valor_propuesto'];
                                $valorAct   = $m['valor'];
                                $fechaImp   = $m['fechaVisita'];
                                $refImage   = $m['ref_image'] ?? '';
                                $imgTag = "";
                                if (!empty($refImage)) {
                                    $imgTag = "<img src='$refImage' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$refImage')\" title='Ver imagen completa'>";
                                }

                                if ($valorAct !== '0' && $valorAct !== null && $valorAct !== '') {
                                    $fechaF = date('d-m-Y H:i', strtotime($fechaImp));
                                    echo "
                                    <div class='form-group'>
                                      <label>{$matName} {$imgTag} (Propuesto: {$valorProp})</label>
                                      <p class='form-control-static'>Valor Implementado: {$valorAct}</p>
                                      <p class='form-control-static'>Fecha: {$fechaF}</p>
                                      <p class='form-control-static'>Observación: {$m['observacion']}</p>
                                    </div>";
                                } else {
                                    echo "
                                    <div class='form-group'>
                                      <div class='checkbox'>
                                        <label>
                                          <input type='checkbox'
                                                 class='implementa-material'
                                                 data-id-material='{$idFQ}'>
                                          {$actionLabel} {$matName} {$imgTag} (Valor Propuesto: {$valorProp})
                                        </label>
                                      </div>
                                    </div>";

                                    echo "
                                    <div class='implementa-section'
                                         id='implementa_section_{$idFQ}'
                                         style='display:none; padding-left:20px;'>
                                      <div class='form-group'>
                                        <label>Valor {$actionLabel}:</label>
                                        <input type='number'
                                               class='form-control valor-implementado'
                                               name='valor[{$idFQ}]'
                                               placeholder='Ingrese el valor'
                                               data-valor-propuesto='{$valorProp}'
                                               disabled>
                                      </div>
                                      <div class='form-group'>
                                        <label>Origen de la foto:</label>
                                        <select class='photo-source form-control'
                                                data-target-input='fotos_input_{$idFQ}'
                                                disabled>
                                          <option value='gallery' selected>Elegir de la Galería</option>
                                          <option value='camera'>Tomar Foto</option>
                                        </select>
                                      </div>
                                      <div class='form-group'>
                                        <label>Fotos (hasta 10):</label>
                                        <input type='file'
                                               accept='image/*'
                                               name='fotos[{$idFQ}][]'
                                               multiple
                                               class='form-control file-input'
                                               disabled
                                               id='fotos_input_{$idFQ}'>
                                        <div id='previewContainer_{$idFQ}'></div>
                                      </div>
                                      <div class='form-group'>
                                         <label>Observación {$sectionLabel}:</label>
                                        <textarea class='form-control'
                                                  name='observacion[{$idFQ}]'
                                                  placeholder='Observación...'></textarea>
                                      </div>
                                    </div>";

                                    echo "
                                    <div class='no-implementa-section'
                                         id='no_implementa_section_{$idFQ}'
                                         style='display:block; padding-left:20px;'>
                                      <div class='form-group'>
                                        <label>Motivo de NO {$sectionLabel}:</label>
                                        <select class='form-control' name='motivoSelect[{$idFQ}]'>
                                          <option value='No, no permitieron'>No, no permitieron</option>
                                          <option value='No, hay otro tipo de exhibicion'>No, hay otro tipo de exhibicion</option>
                                          <option value='No, sin productos'>No, sin productos</option>
                                          <option value='No, no ha llegado el material'>No, no ha llegado el material</option>
                                          <option value='Sala en remodelación'>Sala en remodelación</option>
                                          <option value='No permite por robo'>No permite por robo</option>
                                          <option value='Sin bajada de la central'>Sin bajada de la central</option>
                                          <option value='Ya hay mueble adicional'>Ya hay mueble adicional</option>
                                          <option value='No llegó el material de implementación completo'>No llegó el material de implementación completo</option>
                                          <option value='El mueble no se encuentra en la sala'>El mueble no se encuentra en la sala</option>
                                          <option value=' Material entregado'> Material entregado</option>
                                        </select>
                                      </div>
                                      <div class='form-group'>
                                        <label>Detalle adicional:</label>
                                        <textarea class='form-control'
                                                  name='motivoNoImplementado[{$idFQ}]'
                                                  placeholder='Explique brevemente...'></textarea>
                                      </div>
                                    </div>";
                                }
                            }
                        } else {
                            echo "<p>No se encontraron materiales para este local/campaña.</p>";
                        }
                        ?>
                    </div>
                     <button type="button" class="btn btn-success" id="btnAgregarMaterial" style="display:none;">Agregar Material</button>
                     
                     <div class="form-group" id="comentarioContainer" style="display:none;">
                       <label for="comentario">Comentarios Generales:</label>
                       <textarea class="form-control" id="comentario" name="comentario"
                                 placeholder="Ingrese sus comentarios" required ></textarea>
                     </div>

                    <!-- Evidencias específicas/genéricas -->
                    <div class="form-group" id="fotoLocalCerradoContainer" style="display: none;">
                        <label for="fotoLocalCerrado">Subir foto de fachada (Local Cerrado):</label>
                        <input type="file" class="form-control" id="fotoLocalCerrado" name="fotoLocalCerrado" accept="image/*">
                    </div>
                    <div class="form-group" id="fotoLocalNoExisteContainer" style="display: none;">
                        <label for="fotoLocalNoExiste">Subir foto del lugar (Local no existe):</label>
                        <input type="file" class="form-control" id="fotoLocalNoExiste" name="fotoLocalNoExiste" accept="image/*">
                    </div>
                    <div class="form-group" id="fotoMuebleNoSalaContainer" style="display: none;">
                      <label for="fotoMuebleNoSala">Subir foto del mueble (no está en la sala):</label>
                      <input type="file"
                             class="form-control"
                             id="fotoMuebleNoSala"
                             name="fotoMuebleNoSala"
                             accept="image/*">
                    </div>
                    <div class="form-group" id="fotoPendienteGenericaContainer" style="display: none;">
                      <label for="fotoPendienteGenerica">Subir foto (Evidencia estado Pendiente):</label>
                      <input type="file" class="form-control" id="fotoPendienteGenerica" name="fotoPendienteGenerica" accept="image/*">
                    </div>
                    <div class="form-group" id="fotoCanceladoGenericaContainer" style="display: none;">
                      <label for="fotoCanceladoGenerica">Subir foto (Evidencia estado Cancelado):</label>
                      <input type="file" class="form-control" id="fotoCanceladoGenerica" name="fotoCanceladoGenerica" accept="image/*">
                    </div>

                     <br>
                     <button type="button" class="btn btn-default" id="btnBack1">&laquo; Anterior</button>
                     <?php if ($encuestaPendiente): ?>
                         <button type="button" class="btn btn-primary" id="btnNext2">&raquo; Siguiente</button>
                     <?php else: ?>
                         <button type="button" class="btn btn-success" id="btnFinalizar">Finalizar Gestión</button>
                     <?php endif; ?>
                 </div>

                 <!-- Paso 3 -->
                 <?php if ($encuestaPendiente): ?>
                 <div class="wizard-step" id="step-3">
                    <h4>Paso 3: Responder la Encuesta</h4>
                    <?php if (!empty($preguntas)): ?>
                        <?php
                        function obtenerOpciones($conn, $id_form_question) {
                            $sql_opciones = "
                                SELECT 
                                    id,
                                    option_text,
                                    reference_image
                                FROM form_question_options
                                WHERE id_form_question = ?
                                ORDER BY sort_order ASC
                            ";
                            $stmt_opciones = $conn->prepare($sql_opciones);
                            if ($stmt_opciones === false) { return []; }
                            $stmt_opciones->bind_param("i", $id_form_question);
                            $stmt_opciones->execute();
                            $result_opciones = $stmt_opciones->get_result();
                            $opciones = [];
                            while ($row_opc = $result_opciones->fetch_assoc()) {
                                $opciones[] = [
                                    'id'             => $row_opc['id'],
                                    'text'           => htmlspecialchars($row_opc['option_text'], ENT_QUOTES, 'UTF-8'),
                                    'reference_image'=> $row_opc['reference_image']
                                ];
                            }
                            $stmt_opciones->close();
                            return $opciones;
                        }
                        ?>
                        <?php foreach ($preguntas as $preg): 
                            $q_id            = intval($preg['id_form_question']);
                            $questionText    = htmlspecialchars($preg['question_text'], ENT_QUOTES, 'UTF-8');
                            $tipo            = intval($preg['id_question_type']);
                            $dependencyOption= isset($preg['id_dependency_option']) ? intval($preg['id_dependency_option']) : 0;
                            $isValued        = isset($preg['is_valued']) ? intval($preg['is_valued']) : 0;
                            $isRequired      = intval($preg['is_required']); 
                            $conditionalClass = "";
                            $conditionalAttr  = "";
                            if ($dependencyOption > 0) {
                                $parentId = getParentQuestionId($conn, $dependencyOption);
                                if ($parentId) {
                                    $conditionalClass = " conditional-question";
                                    $conditionalAttr  = ' data-dependency-option="' . $dependencyOption . '" data-parent-question="' . $parentId . '" style="display:none;"';
                                }
                            }
                        ?>
                            <div class="form-group question-container<?php echo $conditionalClass; ?>"
                                 data-question-id="<?php echo $q_id; ?>"
                                 data-question-text="<?php echo $questionText; ?>"
                                 data-required="<?php echo $isRequired; ?>"
                                 <?php echo $conditionalAttr; ?>>
                                <label>
                                    <?php echo $questionText; ?>
                                    <span style="font-style: italic; color: #999;"> <?php echo ($isRequired==1 ? "*obl" : "*opc"); ?></span>
                                </label>
                                <?php
                                if ($tipo === 1 || $tipo === 2) {
                                    $opciones = obtenerOpciones($conn, $q_id);
                                    if (!empty($opciones)) {
                                        foreach ($opciones as $opcion) {
                                            $optId   = $opcion['id'];
                                            $optText = $opcion['text'];
                                            $refImg  = $opcion['reference_image'];
                                            $fullUrl = (!empty($refImg)) ? 'https://visibility.cl/' . ltrim($refImg, '/') : '';
                                            echo "<div style='margin-bottom:5px;'>";
                                            echo "  <label style='margin-right:10px;'>";
                                            echo "    <input type='radio' name='respuesta[$q_id]' value='$optId' " . ($isRequired==1 ? "required" : "") . "> $optText";
                                            echo "  </label>";
                                            if ($isValued === 1) {
                                                echo " <input type='number' name='valorRespuesta[$q_id][$optId]' placeholder='Valor' style='width:70px; margin-left:10px;'>";
                                            }
                                            if (!empty($refImg)) {
                                                echo "  <img src='$fullUrl' alt='Ref' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$fullUrl')\" title='Haz clic para agrandar'>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        echo "<p>No hay opciones disponibles para esta pregunta.</p>";
                                    }
                                } elseif ($tipo === 3) {
                                    $opciones = obtenerOpciones($conn, $q_id);
                                    if (!empty($opciones)) {
                                        foreach ($opciones as $opcion) {
                                            $optId   = $opcion['id'];
                                            $optText = $opcion['text'];
                                            $refImg  = $opcion['reference_image'];
                                            $fullUrl = (!empty($refImg)) ? 'https://visibility.cl/' . ltrim($refImg, '/') : '';
                                            echo "<div style='margin-bottom:5px;'>";
                                            echo "  <label style='margin-right:10px;'>";
                                            echo "    <input type='checkbox' name='respuesta[$q_id][]' value='$optId'> $optText";
                                            echo "  </label>";
                                            if ($isValued === 1) {
                                                echo " <input type='number' name='valorRespuesta[$q_id][$optId]' placeholder='Valor' style='width:70px; margin-left:10px;'>";
                                            }
                                            if (!empty($refImg)) {
                                                echo "  <img src='$fullUrl' alt='Ref' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$fullUrl')\" title='Haz clic para agrandar'>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        echo "<p>No hay opciones disponibles para esta pregunta.</p>";
                                    }
                                } elseif ($tipo === 4) {
                                    echo "<input type='text' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 5) {
                                    echo "<input type='number' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 6) {
                                    echo "<input type='date' class='form-control' name='respuesta[$q_id]' value='' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 7) {
                                    ?>
                                    <div class="form-group">
                                        <input type="file" id="fotoPregunta_<?php echo $q_id; ?>" accept="image/*">
                                        <div class="mc-file-actions">
                                          <button type="button" class="mc-btn mc-btn--gallery" onclick="chooseFromGallery(<?php echo $q_id; ?>)">
                                            <i class="fa fa-image"></i> Elegir desde galería
                                          </button>
                                          <button type="button" class="mc-btn mc-btn--camera" onclick="takeWithCamera(<?php echo $q_id; ?>)">
                                            <i class="fa fa-camera"></i> Tomar foto
                                          </button>
                                        </div>
                                        <div id="previewFoto_<?php echo $q_id; ?>" style="margin-top:10px;"></div>
                                        <input type="hidden" id="flagFoto_<?php echo $q_id; ?>" value="" data-question="7" data-idquestion="<?php echo $q_id; ?>">
                                    </div>
                                    <?php
                                } else {
                                    echo "<input type='text' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                }
                                ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No hay preguntas pendientes.</p>
                    <?php endif; ?>
                    <button type="button" class="btn btn-default" id="btnBack2">&laquo; Anterior</button>
                    <button type="button" class="btn btn-success" id="btnFinalizar">Finalizar Gestión</button>
                 </div>
                 <?php endif; ?>

                 <!-- Inputs ocultos fijos -->
                 <input type="hidden" name="idCampana" value="<?php echo $idCampana; ?>">
                 <input type="hidden" name="nombreCampana" value="<?php echo htmlspecialchars($nombreCampanaDB, ENT_QUOTES, 'UTF-8'); ?>">
                 <input type="hidden" name="idLocal" value="<?php echo $idLocal; ?>">
                 <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                 <input type="hidden" name="latitudLocal" value="<?php echo $latitud; ?>">
                 <input type="hidden" name="longitudLocal" value="<?php echo $longitud; ?>">
                 <input type="hidden" name="division_id" value="<?php echo $idDivision; ?>">
             </form>
          </div>
       </div>
    </div>

    <div class="footer clearfix">
       <div class="footer-inner">
          2024 &copy; Visibility 2 por Mentecreativa.
       </div>
       <div class="footer-items">
          <span class="go-top"><i class='fa fa-chevron-up'></i></span>
       </div>
    </div>

    <!-- MODAL IMG -->
    <div class="modal fade" id="modalImagenGrande" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document" style="max-width:90vw;">
        <div class="modal-content">
          <div class="modal-body" style="text-align:center;">
            <img id="imagenAmpliada" src="" class="modal-img" alt="Imagen Referencia">
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Agregar Material -->
    <div class="modal fade" id="modalAgregarMaterial" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form id="formAgregarMaterial">
            <div class="modal-header">
              <h5 class="modal-title">Agregar Material</h5>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label for="nombreMaterial">Seleccione Material:</label>
                <select class="form-control" id="nombreMaterial" name="nombreMaterial" required>
                  <option value="" disabled selected>-- Elegir --</option>
                  <?php
                  $sqlCat = "SELECT id, nombre, ref_image FROM material WHERE id_division = ? ORDER BY nombre";
                  $stmtCat = $conn->prepare($sqlCat);
                  $stmtCat->bind_param("i", $idDivision);
                  $stmtCat->execute();
                  $resCat = $stmtCat->get_result();
                  while ($rowM = $resCat->fetch_assoc()) {
                      $matName  = htmlspecialchars($rowM['nombre'], ENT_QUOTES, 'UTF-8');
                      $refImage = htmlspecialchars($rowM['ref_image'], ENT_QUOTES, 'UTF-8');
                      echo '<option value="'.$matName.'" data-ref="'.$refImage.'">'.$matName.'</option>';
                  }
                  $stmtCat->close();
                  ?>
                </select>
              </div>
              <div class="form-group" id="refImageContainer" style="display:none;">
                  <label>Imagen de Referencia:</label>
                  <img id="refPreview" src="" alt="Imagen de Referencia" style="max-width:200px; max-height:200px; margin-top:10px;">
              </div>
              <div class="form-group">
                <label for="valorImplementado">Valor a <?php echo htmlspecialchars($actionLabel, ENT_QUOTES,'UTF-8'); ?> (0 a 9):</label>
                <input type="number" class="form-control" id="valorImplementado" name="valorImplementado" min="0" max="9" required>
              </div>
              <input type="hidden" name="idCampana" value="<?php echo $idCampana; ?>">
              <input type="hidden" name="idLocal" value="<?php echo $idLocal; ?>">
              <input type="hidden" name="division_id" value="<?php echo $idDivision; ?>">
              
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary">Agregar</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Overlays -->
    <div id="loadingOverlay" style="
        display: none;
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 9999; text-align: center;">
      <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
          <div class="spinner-border text-light" role="status">
            <span class="sr-only">Cargando...</span>
          </div>
          <p class="text-light mt-2">Procesando, por favor espere...</p>
      </div>
    </div>

    <!-- Geolocal blocker -->
    <div id="geoBlocker" class="geo-blocker">
      <div class="geo-card">
        <h4>Necesitamos tu ubicación</h4>
        <p>Para gestionar este local debes <strong>activar el GPS</strong> y <strong>conceder el permiso de ubicación</strong>.</p>
        <button id="geoRetry" class="btn btn-primary" type="button">Intentar nuevamente</button>
        <p id="geoErrorMsg" class="small text-muted"></p>
      </div>
    </div>

    <div id="mcToasts" class="mc-toasts" aria-live="polite" aria-atomic="true"></div>

    <div class="modal fade" id="mcConfirmModal" tabindex="-1" role="dialog" aria-labelledby="mcConfirmTitle">
      <div class="modal-dialog" role="document" style="max-width:420px;">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
              <span aria-hidden="true">&times;</span>
            </button>
            <h4 class="modal-title" id="mcConfirmTitle">Confirmar</h4>
          </div>
          <div class="modal-body" id="mcConfirmMessage">
            ¿Seguro que desea continuar?
          </div>
          <div class="modal-footer">
            <button type="button" id="mcConfirmCancel" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <button type="button" id="mcConfirmOk" class="btn btn-danger">Sí, eliminar</button>
          </div>
        </div>
      </div>
    </div>

    <!-- jQuery & Bootstrap -->
<script src="assets/plugins/jquery/jquery-3.6.0.min.js"></script>
    
    <script src="assets/plugins/bootstrap/js/bootstrap.min.js"></script>

    <!-- Helpers Geolocalización -->
    <script>
window.USER_ID = <?php echo (int)$usuario_id; ?>;

/* === Geolocalización obligatoria === */
window.Geo = { ready: false, lastFixMs: 0, minFreshMs: 120000 };
let cachedPhotoCoords = { lat:'', lng:'' };

function setGeo(lat, lng){
  const latStr = Number(lat).toFixed(6), lngStr = Number(lng).toFixed(6);
  $('#latGestion').val(latStr);
  $('#lngGestion').val(lngStr);
  window.Geo.ready = true;
  window.Geo.lastFixMs = Date.now();
  cachedPhotoCoords.lat = latStr;
  cachedPhotoCoords.lng = lngStr;
  updateGeoUI();
}

function updateGeoUI(){
  const ok = !!(window.Geo.ready && $('#latGestion').val() && $('#lngGestion').val());
  const $badge = $('#geoStatusBadge');
  if ($badge.length){
    $badge.toggleClass('ok', ok).text(ok ? 'GPS activo' : 'GPS requerido');
  }
  $('#geoBlocker').toggle(!ok);
}

function showGeoError(err){
  let msg = 'No pudimos obtener tu ubicación.';
  if (err && err.code === 1) msg = 'Permiso de ubicación denegado. Actívalo para continuar.';
  else if (err && err.code === 2) msg = 'No se pudo determinar la posición (señal/servicio).';
  else if (err && err.code === 3) msg = 'Tiempo de espera agotado. Intenta de nuevo.';
  $('#geoErrorMsg').text(msg);
  updateGeoUI();
}

function getPositionOnce(opts={}){
  return new Promise((resolve, reject)=>{
    if (!navigator.geolocation) return reject({ code:'NO_GEO', message:'Geolocalización no soportada' });
    navigator.geolocation.getCurrentPosition(
      pos => resolve(pos),
      err => reject(err),
      Object.assign({ enableHighAccuracy:true, timeout:15000, maximumAge:0 }, opts)
    );
  });
}

async function ensureFreshGeo(){
  const now = Date.now();
  const hasRecent = window.Geo.ready &&
                    (now - window.Geo.lastFixMs < window.Geo.minFreshMs) &&
                    $('#latGestion').val() && $('#lngGestion').val();
  if (hasRecent){
    return { lat: parseFloat($('#latGestion').val()), lng: parseFloat($('#lngGestion').val()) };
  }
  const pos = await getPositionOnce({ maximumAge:0, timeout:15000, enableHighAccuracy:true });
  setGeo(pos.coords.latitude, pos.coords.longitude);
  return { lat: pos.coords.latitude, lng: pos.coords.longitude };
}

document.addEventListener('DOMContentLoaded', async ()=>{
  try{ await ensureFreshGeo(); }
  catch(err){ showGeoError(err); }
  finally{ updateGeoUI(); }

  document.getElementById('geoRetry')?.addEventListener('click', async ()=>{
    $('#geoErrorMsg').text('Intentando obtener ubicación…');
    try{ await ensureFreshGeo(); }
    catch(err){ showGeoError(err); }
  });
});

function initMap(){ /* no-op */ }
    </script>

    <!-- Offline + UI helpers -->
    
    
    <script>
function normalizeAppUrl(u) {
  if (!u) return '';
  if (/^https?:\/\//i.test(u)) return u;                   // ya es absoluta con dominio
  u = String(u).replace(/\\/g,'/').replace(/\/+/g,'/');    // colapsa //
  if (u.startsWith('/visibility2/app/uploads/')) return u; // absoluta bajo app
  if (u.startsWith('/uploads/')) return '/visibility2/app' + u;
  if (u.startsWith('uploads/')) return '/visibility2/app/' + u;
  return u;
}

const START_VISITA_TIMEOUT_MS = 8000;
const MAX_FOTOS_POR_MATERIAL = 10;    
let pendingUploads = 0;

function updateFinalizeButton() {
  const $btn = $('#btnFinalizar');
  if (!$btn.length) return;
  if (pendingUploads > 0) {
    $btn.prop('disabled', true).text('Subiendo fotos…');
  } else {
    $btn.prop('disabled', false).text('Finalizar Gestión');
  }
}

function mcConfirm({ title="Confirmar", message="¿Seguro?", confirmText="Aceptar", confirmClass="btn-primary" }={}) {
  return new Promise(resolve => {
    const $modal = $('#mcConfirmModal');
    $modal.find('#mcConfirmTitle').text(title);
    $modal.find('#mcConfirmMessage').html(message);
    const $ok = $modal.find('#mcConfirmOk');
    $ok.text(confirmText).attr('class', 'btn ' + confirmClass);

    let handled = false;
    const done = (val) => { if (!handled) { handled = true; resolve(val); $modal.modal('hide'); } };

    $ok.off('click').on('click', () => done(true));
    $modal.off('hidden.bs.modal').on('hidden.bs.modal', () => done(false));
    $modal.modal('show');
  });
}

function mcToast(type, title, msg, timeout=3000) {
  const $wrap = $('#mcToasts');
  const $t = $(`
    <div class="alert alert-${type} mc-toast" role="alert">
      <button type="button" class="close" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      <strong>${title}</strong>${msg ? '<div style="margin-top:4px;">'+msg+'</div>' : ''}
    </div>
  `);
  $t.find('.close').on('click', () => $t.fadeOut(200, () => $t.remove()));
  $wrap.append($t);
  setTimeout(() => $t.fadeOut(300, () => $t.remove()), timeout);
}

function resetFileInput(el) {
  if (!el) return;
  try {
    el.value = '';
    if (el.value) { el.type = ''; el.type = 'file'; }
  } catch (e) {
    const clone = el.cloneNode(true);
    el.parentNode.replaceChild(clone, el);
  }
}

async function isReallyOnline() {
  if (!navigator.onLine) return false;
  try {
    const r = await fetch('/visibility2/app/ping.php', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    if (!r.ok) return false;
    const js = await r.json().catch(()=> ({}));
    if (js && js.csrf_token) {
      window.CSRF_TOKEN = js.csrf_token;
      const hidden = document.querySelector('input[name="csrf_token"]');
      if (hidden) hidden.value = js.csrf_token;
    }
    return true;
  } catch {
    return false;
  }
}

function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c=>{
    const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}
function makeIdempKey() { return 'idemp-' + Date.now() + '-' + Math.random().toString(16).slice(2); }
function clientGuidKey() {
  const uid  = (window.USER_ID || <?php echo (int)$usuario_id; ?>);
  const form = <?php echo (int)$idCampana; ?>;
  const loc  = <?php echo (int)$idLocal; ?>;
  return `vguid:${uid}:${form}:${loc}`;
}

function setClientGuid(g) {
  document.getElementById('client_guid').value = g;
}
function rotateClientGuid() {
  const g = uuidv4();
  localStorage.setItem(clientGuidKey(), g);
  setClientGuid(g);
  return g;
}
function getOrCreateClientGuid() {
  const k = clientGuidKey();
  let g = localStorage.getItem(k);
  if (!g) g = rotateClientGuid();
  else setClientGuid(g);
  return g;
}

function clearClientGuid() {
  localStorage.removeItem(clientGuidKey());
  const h = document.getElementById('client_guid');
  if (h) h.value = '';
}
function ensureClientGuid() {
  const k = clientGuidKey();
  let g = localStorage.getItem(k);
  if (!g) { g = uuidv4(); localStorage.setItem(k, g); }
  document.getElementById('client_guid').value = g;
  return g;
}
function getCSRF() {
  return document.querySelector('input[name="csrf_token"]')?.value || '';
}
async function queueCreateVisita(payload, idempKey) {
  return OfflineQueue.enqueueJSON('/visibility2/app/create_visita_pruebas.php', {
    ...payload,
    csrf_token: getCSRF()
  }, {
    type: 'create_visita',
    idempotencyKey: idempKey,
    client_guid: payload.client_guid,
    dedupeKey: `create:${payload.client_guid}`
  });
}
async function queueProcesarGestion(formEl, meta={}) {
  const cg = document.getElementById('client_guid').value || ensureClientGuid();
  // Asegura que el hidden exista con valor
  const h = formEl.querySelector('#client_guid');
  if (h) h.value = cg; else {
    const hid = document.createElement('input');
    hid.type = 'hidden'; hid.name = 'client_guid'; hid.id = 'client_guid'; hid.value = cg;
    formEl.appendChild(hid);
  }
  return OfflineQueue.enqueueForm('/visibility2/app/procesar_gestion_pruebas.php', formEl, {
    type: 'procesar_gestion',
    client_guid: cg,
    dependsOn: `create:${cg}`
  });
}
    </script>

    <!-- Lógica principal -->
    <script>

/* === Pregunta tipo foto === */
async function subirFotoPregunta(id_form_question, id_local) {
  const inputFile = document.getElementById('fotoPregunta_' + id_form_question);
  const file      = inputFile.files[0];
  if (!file) { alert("Debes seleccionar una imagen primero."); return; }

  // === Metadatos/coords ===
  let meta = await extractPhotoMeta(file);
  meta.capture_source = inputFile.dataset.captureSource || 'unknown';
  const coords = { lat: cachedPhotoCoords.lat || '', lng: cachedPhotoCoords.lng || '' };

  // === Compresión ligera en cliente ===
  const options = { maxSizeMB: 0.2, maxWidthOrHeight: 800, useWebWorker: true };
  let compressedFile;
  try { compressedFile = await imageCompression(file, options); }
  catch { compressedFile = file; }

  // === UI de carga ===
  const preview = document.getElementById('previewFoto_' + id_form_question);
  const uploadInstance = document.createElement('div');
  uploadInstance.className = 'upload-instance';
  preview.appendChild(uploadInstance);
  const bar = document.createElement('div');
  bar.className = 'upload-bar';
  uploadInstance.appendChild(bar);

  // === Construcción del FormData ===
  const formData = new FormData();
  formData.append('visita_id', $('#visita_id').val());
  formData.append('id_form_question', id_form_question);
  formData.append('id_local', id_local);
  formData.append('fotoPregunta', new File([compressedFile], file.name, { type: compressedFile.type }));
  formData.append('csrf_token', window.CSRF_TOKEN);
  formData.append('client_guid', document.getElementById('client_guid')?.value || '');
  formData.append('lat', coords.lat);
  formData.append('lng', coords.lng);
  formData.append('exif_datetime', meta.exif_datetime);
  formData.append('exif_lat', meta.exif_lat);
  formData.append('exif_lng', meta.exif_lng);
  formData.append('exif_altitude', meta.exif_altitude);
  formData.append('exif_img_direction', meta.exif_img_direction);
  formData.append('exif_make', meta.exif_make);
  formData.append('exif_model', meta.exif_model);
  formData.append('exif_software', meta.exif_software);
  formData.append('exif_lens_model', meta.exif_lens_model);
  formData.append('exif_fnumber', meta.exif_fnumber);
  formData.append('exif_exposure_time', meta.exif_exposure_time);
  formData.append('exif_iso', meta.exif_iso);
  formData.append('exif_focal_length', meta.exif_focal_length);
  formData.append('exif_orientation', meta.exif_orientation);
  formData.append('capture_source', meta.capture_source);
  formData.append('meta_json', meta.meta_json);

  // === Idempotencia (header + body) ===
  const idempo = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
  formData.append('X_Idempotency_Key', idempo);

  // === Intento online primero (si hay red real) ===
  const online = await isReallyOnline().catch(() => false);

  // Helper para pintar tarjeta (éxito u “en cola”)
  function renderThumb({ url, queuedId=null }) {
    uploadInstance.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'img-container';
    wrapper.dataset.qid = id_form_question;

    const img = document.createElement('img');
    img.src = url;                    // en cola: blob URL, online: URL servidor
    img.className = 'thumbnail';
    wrapper.appendChild(img);

    if (queuedId) {
      // Badge “en cola”
      const badge = document.createElement('div');
      badge.className = 'label label-info';
      badge.style.display = 'inline-block';
      badge.style.marginTop = '6px';
      badge.textContent = 'En cola';
      wrapper.appendChild(badge);

      // Botón cancelar (elimina tarea de IndexedDB y la miniatura)
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn btn-xs btn-warning';
      cancel.style.marginLeft = '8px';
      cancel.textContent = 'Cancelar';
      cancel.onclick = async () => {
        try {
          const cancelled = await (window.Queue && typeof Queue.cancel === 'function'
            ? Queue.cancel(queuedId)
            : AppDB.remove(queuedId));
          if (!cancelled) throw new Error('cancel_failed');
          $(wrapper).fadeOut(150, () => wrapper.remove());
          mcToast('info', 'Cancelado', 'Se eliminó la foto de la cola.');
          // Limpia flag si no quedan fotos
          const container = document.getElementById('previewFoto_' + id_form_question);
          if (container && container.querySelectorAll('.img-container').length === 0) {
            const flag = document.getElementById('flagFoto_' + id_form_question);
            if (flag) flag.value = '';
          }
        } catch {
          mcToast('danger', 'Error', 'No se pudo cancelar la tarea en cola.');
        }
      };
      wrapper.appendChild(cancel);

      // Marca hidden para que el flujo sepa que existe contenido
      const flag = document.getElementById('flagFoto_' + id_form_question);
      if (flag) flag.value = 'queued:' + queuedId;
    } else {
      // Botón eliminar (online)
      const delBtn = document.createElement('button');
      delBtn.className = 'delete-button';
      delBtn.type = 'button';
      delBtn.title = 'Eliminar foto';
      delBtn.textContent = '×';
      delBtn.onclick = async function () {
        const imgUrl = img.src || '';
        const ok = await mcConfirm({
          title: 'Eliminar foto',
          message: `
            <div class="text-center">
              <p>¿Seguro que desea eliminar <strong>esta foto</strong> de la pregunta?</p>
              <img src="${imgUrl}" alt="Foto a eliminar"
                   class="img-thumbnail"
                   style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;">
            </div>
          `,
          confirmText: 'Sí, eliminar',
          confirmClass: 'btn-danger'
        });
        if (!ok) return;

        delBtn.disabled = true;
        try {
          const form = new FormData();
          form.append('csrf_token', window.CSRF_TOKEN);
          form.append('resp_id', wrapper.dataset.respId);
          form.append('id_form_question', wrapper.dataset.qid);
          form.append('visita_id', document.getElementById('visita_id').value);
          const r  = await fetch('/visibility2/app/eliminar_pregunta_foto_pruebas.php', { method: 'POST', body: form });
          const js = await r.json();
          if (js.status === 'success') {
            $(wrapper).fadeOut(150, () => {
              wrapper.remove();
              const container = document.getElementById('previewFoto_' + id_form_question);
              const quedan = container.querySelectorAll('.img-container').length;
              if (quedan === 0) {
                const flag = document.getElementById('flagFoto_' + id_form_question);
                if (flag) flag.value = '';
              }
            });
            mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
          } else {
            mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
            delBtn.disabled = false;
          }
        } catch (e) {
          console.error(e);
          mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
          delBtn.disabled = false;
        }
      };
      wrapper.appendChild(delBtn);
    }

    uploadInstance.appendChild(wrapper);
  }

  // --- Rama ONLINE: XHR con progreso + headers de seguridad ---
  if (online) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/visibility2/app/procesar_pregunta_foto_pruebas.php');
    xhr.withCredentials = true;   
    xhr.setRequestHeader('X-Idempotency-Key', idempo);
    // (Opcional) si el endpoint acepta CSRF por header:
    try { xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || ''); } catch(_) {}

    xhr.upload.onprogress = e => {
      if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
    };
    xhr.onload = () => {
      let data;
      try { data = JSON.parse(xhr.responseText); }
      catch {
        uploadInstance.innerHTML = `<div class="alert alert-danger" style="margin-top:8px;">Respuesta inválida del servidor.</div>`;
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300 && data.status === 'success') {
        // Pintar miniatura con URL definitiva y botón de borrar
        renderThumb({ url: normalizeAppUrl(data.fotoUrl), queuedId: null });
        // Guardar resp_id para eliminar
        const wrapper = uploadInstance.querySelector('.img-container');
        if (wrapper) wrapper.dataset.respId = data.resp_id;

        resetFileInput(inputFile);
        mcToast('success', 'Foto subida', '¡Se cargó correctamente!');
        const flag = document.getElementById('flagFoto_' + id_form_question);
        if (flag) flag.value = data.fotoUrl;
      } else {
        // Si el server respondió error, caemos a encolar
        enqueueFallback();
      }
    };
    xhr.onerror = enqueueFallback;
    xhr.send(formData);
    return;
  }
  
  
 
  

  // --- Rama OFFLINE o fallo online → encolar con Queue.smartPost ---
  await enqueueFallback();

  // ===== Helper: encolar y marcar UI “en cola” =====
  async function enqueueFallback() {
  try {
    // Llenamos la barra de “progreso” local para indicar que quedó en cola
    bar.style.width = '100%';

    // Verificamos que exista la cola offline
    const QueueObj =
      (window.Queue && typeof window.Queue.smartPost === 'function') ? window.Queue :
      (window.OfflineQueue && typeof window.OfflineQueue.smartPost === 'function') ? window.OfflineQueue :
      null;

    if (!QueueObj) {
      mcToast('danger', 'Queue no disponible', 'Revisa que /visibility2/app/assets/js/offline-queue.js esté cargado antes de este script.');
      // Render igual la miniatura local, pero sin queuedId (no habrá sincronización automática)
      const blobUrlFallback = URL.createObjectURL(compressedFile);
      renderThumb({ url: blobUrlFallback, queuedId: null });
      resetFileInput(inputFile);
      return;
    }

    // Encolamos el POST completo (incluye el archivo) para que se procese cuando haya red
    const res = await QueueObj.smartPost(
      '/visibility2/app/procesar_pregunta_foto_pruebas.php',
      formData,
      { type: 'pregunta_foto', sendCSRF: true }
    );

    // Pintamos miniatura local (blob) y badge “En cola” si corresponde
    const blobUrl = URL.createObjectURL(compressedFile);
    renderThumb({ url: blobUrl, queuedId: (res && res.queued ? res.id : null) });

    // Limpia el input file (ya quedó persistido en IndexedDB por la cola)
    resetFileInput(inputFile);

    if (res && res.queued) {
      // Caso típico OFFLINE: quedó en cola
      mcToast('info', 'Offline', 'La foto se subirá al recuperar conexión.');

      // Nos suscribimos al evento global que emite la cola cuando esa tarea se procese
      window.addEventListener('queue:done', async (ev) => {
        try {
          if (!ev || !ev.detail) return;
          const { id, type, response } = ev.detail;

          // Aseguramos que sea el MISMO job y del MISMO tipo
          if (id !== res.id || type !== 'pregunta_foto') return;

          // Validamos respuesta del backend
          if (!response || response.status !== 'success' || !response.fotoUrl) return;

          // Reemplazamos el blob URL por la URL final del servidor
          const thumb = uploadInstance.querySelector('img.thumbnail');
          if (thumb) thumb.src = normalizeAppUrl(response.fotoUrl);

          // Actualizamos contenedor de la miniatura: quitamos “en cola”, agregamos botón eliminar
          const w = uploadInstance.querySelector('.img-container');
          if (w) {
            // Limpia metadata de cola
            w.removeAttribute('data-queued-id');
            if (response.resp_id) w.dataset.respId = response.resp_id;

            // Quitar badge “En cola”
            const lab = w.querySelector('.label.label-info');
            if (lab) lab.remove();

            // Quitar botón “Cancelar” (si lo tenías)
            const cancelBtn = w.querySelector('button.btn-warning');
            if (cancelBtn) cancelBtn.remove();

            // Añadir botón eliminar (online)
            const delBtn = document.createElement('button');
            delBtn.className = 'delete-button';
            delBtn.type = 'button';
            delBtn.title = 'Eliminar foto';
            delBtn.textContent = '×';
            delBtn.onclick = async () => {
              const ok = await mcConfirm({
                title: 'Eliminar foto',
                message: `
                  <div class="text-center">
                    <p>¿Seguro que desea eliminar <strong>esta foto</strong> de la pregunta?</p>
                    <img src="${thumb?.src || ''}" alt="Foto a eliminar"
                         class="img-thumbnail"
                         style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;">
                  </div>
                `,
                confirmText: 'Sí, eliminar',
                confirmClass: 'btn-danger'
              });
              if (!ok) return;

              try {
                const form = new FormData();
                form.append('csrf_token', window.CSRF_TOKEN || '');
                form.append('resp_id', w.dataset.respId || '');
                form.append('id_form_question', w.dataset.qid || String(id_form_question));
                form.append('visita_id', document.getElementById('visita_id')?.value || '');
                const r  = await fetch('/visibility2/app/eliminar_pregunta_foto_pruebas.php', { method: 'POST', body: form });
                const js = await r.json().catch(() => ({}));
                if (js.status === 'success') {
                  $(w).fadeOut(150, () => w.remove());
                  mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
                  const flag = document.getElementById('flagFoto_' + id_form_question);
                  if (flag) flag.value = '';
                } else {
                  mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
                }
              } catch (err) {
                console.error(err);
                mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
              }
            };
            w.appendChild(delBtn);
          }

          // Actualizamos flag hidden con la URL definitiva
          const flag = document.getElementById('flagFoto_' + id_form_question);
          if (flag) flag.value = response.fotoUrl;

          mcToast('success', 'Foto sincronizada', '¡Tu foto se subió correctamente!');
        } catch (err) {
          console.error(err);
        }
      }, { once: false });

    } else if (res && res.ok && res.response && res.response.fotoUrl) {
      // Caso menos común: smartPost logró subirla online directamente
      const flag = document.getElementById('flagFoto_' + id_form_question);
      if (flag) flag.value = res.response.fotoUrl;

    } else {
      // No tenemos confirmación; igual la miniatura quedó y la cola intentará reenviar
      mcToast('warning', 'Aviso', 'No se pudo confirmar la subida; quedó en cola local.');
    }
  } catch (e) {
    console.error(e);
    uploadInstance.innerHTML = `<div class="alert alert-danger" style="margin-top:8px;">No se pudo encolar la foto.</div>`;
  }
}
}

function verImagenGrande(url) {
  let modalImg = document.getElementById('imagenAmpliada');
  modalImg.src = url;
  $('#modalImagenGrande').modal('show');
}

/* ====== utilitarios EXIF ====== */
function pad2(n){ return n<10 ? '0'+n : ''+n; }
function toMySQLDateTime(d){
  if (!(d instanceof Date)) return '';
  return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate())+' '+pad2(d.getHours())+':'+pad2(d.getMinutes())+':'+pad2(d.getSeconds());
}
function toFixedOrEmpty(v, decimals){ if (v==null || isNaN(v)) return ''; return Number(v).toFixed(decimals); }
function exposureToStr(x){ if (!x || isNaN(x)) return ''; if (x >= 1) return x.toFixed(3); const den = Math.round(1/x); return `1/${den}`; }

async function extractPhotoMeta(file){
  try{
    const ex = await exifr.parse(file, { gps: true, tiff: true, ifd0: true, exif: true, xmp: true, jfif: true, iptc: true }) || {};
    const exifDate = ex.DateTimeOriginal || ex.CreateDate || ex.ModifyDate || null;
    const dt = exifDate ? toMySQLDateTime(exifDate) : '';
    return {
      exif_datetime      : dt,
      exif_lat           : toFixedOrEmpty(ex.latitude, 7),
      exif_lng           : toFixedOrEmpty(ex.longitude, 7),
      exif_altitude      : toFixedOrEmpty(ex.altitude, 2),
      exif_img_direction : toFixedOrEmpty(ex.GPSImgDirection, 2),
      exif_make          : ex.Make    || '',
      exif_model         : ex.Model   || '',
      exif_software      : ex.Software|| '',
      exif_lens_model    : ex.LensModel || '',
      exif_fnumber       : toFixedOrEmpty(ex.FNumber, 2),
      exif_exposure_time : exposureToStr(ex.ExposureTime),
      exif_iso           : (ex.ISO && !isNaN(ex.ISO)) ? parseInt(ex.ISO,10) : '',
      exif_focal_length  : toFixedOrEmpty(ex.FocalLength, 1),
      exif_orientation   : (ex.Orientation && !isNaN(ex.Orientation)) ? parseInt(ex.Orientation,10) : '',
      capture_source     : 'unknown',
      meta_json          : JSON.stringify({
        DateTimeOriginal : ex.DateTimeOriginal || null,
        CreateDate       : ex.CreateDate || null,
        SubSecTimeOriginal: ex.SubSecTimeOriginal || null,
        GPSImgDirectionRef: ex.GPSImgDirectionRef || null,
      })
    };
  } catch(e){
    console.warn('No se pudieron leer metadatos EXIF:', e);
    return {
      exif_datetime:'', exif_lat:'', exif_lng:'', exif_altitude:'', exif_img_direction:'',
      exif_make:'', exif_model:'', exif_software:'', exif_lens_model:'',
      exif_fnumber:'', exif_exposure_time:'', exif_iso:'', exif_focal_length:'',
      exif_orientation:'', capture_source:'unknown', meta_json: '{}'
    };
  }
}

async function compressFile(file) {
  const options = { maxSizeMB: 1, maxWidthOrHeight: 1024, useWebWorker: true };
  const blob = await imageCompression(file, options);
  return new File([blob], file.name, { type: blob.type, lastModified: Date.now() });
}

async function uploadFile(file, url, idFQ, onProgress, meta = {}, extra = {}) {
  pendingUploads++;
  updateFinalizeButton();

  try {
    const resp = await new Promise((res, rej) => {
      const fd = new FormData();
      fd.append('visita_id', $('#visita_id').val());
      fd.append('client_guid', document.getElementById('client_guid')?.value || '');
      fd.append('foto', file);
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('idFQ', idFQ);
      fd.append('idCampana', document.querySelector('input[name="idCampana"]').value);
      fd.append('idLocal', document.querySelector('input[name="idLocal"]').value);
      fd.append('division_id', window.campaignDivision);
      if (extra.lat) fd.append('lat', extra.lat);
      if (extra.lng) fd.append('lng', extra.lng);

      fd.append('exif_datetime', meta.exif_datetime || '');
      fd.append('exif_lat', meta.exif_lat || '');
      fd.append('exif_lng', meta.exif_lng || '');
      fd.append('exif_altitude', meta.exif_altitude || '');
      fd.append('exif_img_direction', meta.exif_img_direction || '');
      fd.append('exif_make', meta.exif_make || '');
      fd.append('exif_model', meta.exif_model || '');
      fd.append('exif_software', meta.exif_software || '');
      fd.append('exif_lens_model', meta.exif_lens_model || '');
      fd.append('exif_fnumber', meta.exif_fnumber || '');
      fd.append('exif_exposure_time', meta.exif_exposure_time || '');
      fd.append('exif_iso', meta.exif_iso || '');
      fd.append('exif_focal_length', meta.exif_focal_length || '');
      fd.append('exif_orientation', meta.exif_orientation || '');
      fd.append('capture_source', meta.capture_source || extra.capture_source || 'unknown');
      fd.append('meta_json', meta.meta_json || '{}');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); };
      xhr.onload  = () => xhr.status < 300 ? res(JSON.parse(xhr.response)) : rej(xhr.responseText);
      xhr.onerror = () => rej(xhr.statusText);
      try { xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || ''); } catch(_) {}
        const _idem = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
        try { xhr.setRequestHeader('X-Idempotency-Key', _idem); } catch(_) {}
        xhr.send(fd);
    });
    return resp;
  } finally {
    pendingUploads--;
    updateFinalizeButton();
  }
}

function syncOfflineMetaInputs(container, idFQ, metas = []) {
  if (!container) return;
  container.innerHTML = '';
  metas.forEach(meta => {
    const metaInput = document.createElement('input');
    metaInput.type = 'hidden';
    metaInput.name = `fotos_meta[${idFQ}][]`;
    metaInput.value = JSON.stringify({
      capture_source: meta.capture_source || 'unknown',
      lat: meta.lat || '',
      lng: meta.lng || ''
    });
    container.appendChild(metaInput);
  });
}

/* === FileInput materiales con soporte OFFLINE === */
function setupFileInput(inputElem) {
  const idFQ = inputElem.id.split('_').pop();
  const previewContainer = document.createElement('div');
  previewContainer.classList.add('preview-container');
  inputElem.parentNode.appendChild(previewContainer);

  const hiddenContainer = document.createElement('div');
  hiddenContainer.id = 'hiddenUploadContainer_' + idFQ;
  inputElem.parentNode.appendChild(hiddenContainer);

  const metaContainer = document.createElement('div');
  metaContainer.id = 'hiddenMetaContainer_' + idFQ;
  inputElem.parentNode.appendChild(metaContainer);

  inputElem._retainedFilesDT = inputElem._retainedFilesDT || new DataTransfer();
  inputElem._retainedMeta = inputElem._retainedMeta || [];

  inputElem.addEventListener('change', async () => {
    ensureClientGuid(); // asegura que exista el GUID antes de procesar

    const previousSelection = new DataTransfer();
    Array.from(inputElem.files || []).forEach(f => previousSelection.items.add(f));
    let files = Array.from(previousSelection.files || []);
    if (files.length === 0) return;

    const yaSubidas = document.querySelectorAll(
      `#hiddenUploadContainer_${idFQ} input[type="hidden"][name^="fotos[${idFQ}]"]`
    ).length;

    const offlineCount = (inputElem._retainedFilesDT && inputElem._retainedFilesDT.files)
      ? inputElem._retainedFilesDT.files.length
      : 0;

    if (yaSubidas + offlineCount + files.length > MAX_FOTOS_POR_MATERIAL) {
      alert(`Máximo ${MAX_FOTOS_POR_MATERIAL} fotos por material. Ya tienes ${yaSubidas + offlineCount}.`);
      return;
    }

    const sourceSelect = document.querySelector(`select.photo-source[data-target-input="fotos_input_${idFQ}"]`);
    const captureSource = (sourceSelect && sourceSelect.value) || 'gallery';

    const tieneVisita = !!$('#visita_id').val();
    const online = await isReallyOnline();
    const cg = document.getElementById('client_guid')?.value || '';
    const allowOnline = online && (tieneVisita || cg);

    if (!cg) { ensureClientGuid(); }

    const coords = { lat: cachedPhotoCoords.lat || '', lng: cachedPhotoCoords.lng || '' };
    const retainedDT = inputElem._retainedFilesDT || new DataTransfer();
    const retainedMeta = Array.isArray(inputElem._retainedMeta) ? inputElem._retainedMeta : [];

    for (const rawFile of files) {
      const meta = await extractPhotoMeta(rawFile);
      meta.capture_source = captureSource;
      const compressed = await compressFile(rawFile);

      const img = document.createElement('img');
      img.src = URL.createObjectURL(compressed);
      img.classList.add('thumbnail');
      previewContainer.appendChild(img);
      const bar = document.createElement('div');
      bar.classList.add('upload-bar');
      previewContainer.appendChild(bar);

      if (allowOnline) {
        try {
          const resp = await uploadFile(
            compressed,
            '/visibility2/app/upload_material_foto_pruebas.php',
            idFQ,
            pct => { bar.style.width = pct + '%'; },
            meta,
            coords
          );
          const hiddenInput = document.createElement('input');
          hiddenInput.type = 'hidden';
          hiddenInput.name = `fotos[${idFQ}][]`;
          hiddenInput.value = resp.url;
          hiddenContainer.appendChild(hiddenInput);

          const ok = document.createElement('div');
          ok.className = 'alert alert-success';
          ok.style.marginTop = '8px';
          ok.textContent = '¡Foto subida exitosamente!';
          previewContainer.appendChild(ok);
          setTimeout(() => { $(ok).fadeOut(300, () => ok.remove()); }, 2000);
        } catch (err) {
          bar.classList.add('error');
          console.error('Upload error', err);
          alert('Error al subir la foto: ' + err);
        }
      } else {
        mcToast('info', 'Offline', 'La foto se subirá al recuperar conexión.');
        bar.style.width = '100%';
        retainedDT.items.add(compressed);
        retainedMeta.push({
          capture_source: captureSource,
          lat: coords.lat || '',
          lng: coords.lng || ''
        });
        // Importante: NO limpiamos el input; mantenemos los files para que viajen con el form en la cola
      }
    }

    if (allowOnline) {
      const onlineAgain = await isReallyOnline();
      if (onlineAgain) inputElem.value = '';
    } else {
      inputElem._retainedFilesDT = retainedDT;
      inputElem._retainedMeta = retainedMeta;
      const mergedDT = new DataTransfer();
      Array.from(retainedDT.files || []).forEach(f => mergedDT.items.add(f));
      inputElem.files = mergedDT.files;
      syncOfflineMetaInputs(metaContainer, idFQ, retainedMeta);
    }
  });
}

function bindCompressionTo(inputElem) {
  inputElem.addEventListener('change', async function(e) {
    if (!this.files || !this.files.length) return;
    const files = Array.from(this.files);
    const compressed = await Promise.all(files.map(f => compressFile(f)));
    const dt = new DataTransfer();
    compressed.forEach(f => dt.items.add(f));
    this.files = dt.files;
  });
}

/* === DOM Ready inicializaciones === */
document.addEventListener('DOMContentLoaded', ()=>{
  window.CSRF_TOKEN = document.querySelector('input[name="csrf_token"]').value;
  document.querySelectorAll('.file-input').forEach(input=>{ setupFileInput(input); });
});

$(document).ready(function(){
  function canAddMaterial() { return ![6, 9].includes(Number(window.campaignDivision)); }
  $('.conditional-question').each(function(){ if ($(this).css('display') === 'none') { $(this).find('input, select, textarea').removeAttr('required'); } });

  let currentStep = 1;
  let totalSteps  = <?php echo $totalSteps; ?>;
  function showStep(step){
      $('.wizard-step').hide();
      $('#step-' + step).show();
      let percent = (step / totalSteps) * 100;
      $('#wizardProgress').css('width', percent + '%');
      $('#wizardProgress').text('Paso ' + step + ' de ' + totalSteps);
  }
  showStep(currentStep);

  $('#btnNext1').prop('disabled', true).text('Siguiente »');

  // === Paso 1: iniciar visita con soporte OFFLINE ===
$('#btnNext1').off('click').on('click', async function(){
  const $btn = $(this);
  $btn.prop('disabled', true).text('Obteniendo ubicación…');

  // 1) Siempre exigimos GPS fresco
  try {
    await ensureFreshGeo();
  } catch (err) {
    showGeoError(err);
    $btn.prop('disabled', false).text('Siguiente »');
    return;
  }

  // 2) Preparamos payload e idempotencia
  const client_guid = rotateClientGuid();
  const idempKey    = makeIdempKey();
  $('#idemp_create').val(idempKey);

  const payload = {
    id_formulario: <?php echo (int)$idCampana; ?>,
    id_local:      <?php echo (int)$idLocal; ?>,
    lat:           String($('#latGestion').val() || ''),
    lng:           String($('#lngGestion').val() || ''),
    client_guid:   String(client_guid || '')
  };

  $btn.text('Iniciando visita…');

  try {
    const params = new URLSearchParams();
    params.append('id_formulario', String(payload.id_formulario));
    params.append('id_local',      String(payload.id_local));
    params.append('lat',           String(payload.lat ?? ''));
    params.append('lng',           String(payload.lng ?? ''));
    params.append('client_guid',   String(payload.client_guid || ''));
    params.append('csrf_token',    getCSRF());

    // 3) Promesa de timeout
    const timeoutPromise = new Promise((_, reject) => {
      setTimeout(() => reject(new Error('START_VISITA_TIMEOUT')), START_VISITA_TIMEOUT_MS);
    });

    // 4) Competimos: fetch vs timeout
    const r = await Promise.race([
      fetch('/visibility2/app/create_visita_pruebas.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-Token': getCSRF(),
          'X-Idempotency-Key': idempKey
        },
        body: params.toString()
      }),
      timeoutPromise
    ]);

    // Si llegó acá es porque ganó el fetch (no el timeout)
    const js = await r.json().catch(() => ({}));

    if (r.ok && js && js.status === 'success' && js.visita_id) {
      // Online OK
      $('#visita_id').val(js.visita_id);
      currentStep = 2;
      showStep(currentStep);
    } else {
      // Respuesta rara o error de negocio -> nos vamos a cola igualmente
      await queueCreateVisita(payload, idempKey);
      mcToast(
        'warning',
        'Servidor lento',
        'No se pudo confirmar el inicio online. La visita quedó en cola y puedes continuar en modo offline.'
      );
      currentStep = 2;
      showStep(currentStep);
    }

  } catch (e) {
    // 5) Timeout o error de red -> tratamos como offline
    await queueCreateVisita(payload, idempKey);
    const msg = (e && e.message === 'START_VISITA_TIMEOUT')
      ? 'El servidor tardó demasiado en responder. Continuamos en modo offline; la visita se sincronizará después.'
      : 'No se pudo iniciar la visita online. Continuamos en modo offline.';
    mcToast('danger', 'Modo offline', msg);
    currentStep = 2;
    showStep(currentStep);
  } finally {
    $btn.prop('disabled', false).text('Siguiente »');
  }
});


  $('#btnBack1').on('click', function(){
    currentStep = 1; showStep(currentStep);
    $('#btnNext1').prop('disabled', false).text('Siguiente »');
  });

  <?php if ($encuestaPendiente): ?>
  $('#btnNext2').on('click', function(e) {
      e.preventDefault();
      if (!validarMateriales()) return;
      let estado = $('#estadoGestion').val();
      if (estado === 'implementado_auditado' || estado === 'solo_auditoria'  ) {
          currentStep = 3;
          showStep(currentStep);
      } else {
          $('#btnFinalizar').click();
      }
  });
  $('#btnBack2').on('click', function(){ currentStep = 2; showStep(currentStep); });
  <?php endif; ?>

  let estadoSelect    = $('#estadoGestion');
  let motivoContainer = $('#motivoContainer');
  let motivoSelect    = $('#motivo');
  let materialesDiv   = $('#materialesContainer');
  let comentarioDiv   = $('#comentarioContainer');
  let btnNext1        = $('#btnNext1');
  let tituloPaso2     = $('#tituloPaso2');

  let motivosPendiente = [
      {value:'local_cerrado', text:'Local cerrado'},
      {value:'no_permitieron', text:'No permitieron'},
      {value:'sin_material', text:'Sin material'},
      {value:'sin_productos', text:'Sin productos'},
      {value:'local_no_existe', text:'Local no existe'}
  ];
  let motivosCancelado = [
      {value:'no_permitieron', text:'No permitieron'},
      {value:'local_no_existe', text:'Local no existe'},
      {value:'mueble_no_esta_en_sala', text:'Mueble no está en la sala'},
      {value:'sin_productos', text:'Sin productos'},
      {value:'local_cerrado', text:'Local cerrado'}
  ];

  function resetFotoContainers() {
    $('#fotoLocalCerradoContainer').hide(); $('#fotoLocalCerrado').val('');
    $('#fotoLocalNoExisteContainer').hide(); $('#fotoLocalNoExiste').val('');
    $('#fotoMuebleNoSalaContainer').hide(); $('#fotoMuebleNoSala').val('');
    $('#fotoPendienteGenericaContainer').hide(); $('#fotoPendienteGenerica').val('');
    $('#fotoCanceladoGenericaContainer').hide(); $('#fotoCanceladoGenerica').val('');
  }

  estadoSelect.on('change', function(){
      let val = $(this).val();
      motivoSelect.empty().append('<option value="" disabled selected>Seleccione una opción</option>');
      motivoContainer.hide(); materialesDiv.hide(); comentarioDiv.hide();
      btnNext1.prop('disabled', false);
      tituloPaso2.text('Paso 2');
      resetFotoContainers();

      if (val === 'implementado_auditado') {
        materialesDiv.show(); if (canAddMaterial()) { $('#btnAgregarMaterial').show(); }
        tituloPaso2.text('Paso 2: Detalles de Materiales');
      } else if (val === 'solo_implementado') {
        materialesDiv.show(); if (canAddMaterial()) $('#btnAgregarMaterial').show();
        tituloPaso2.text('Paso 2: Solo Implementación de Materiales');
      } else if (val === 'solo_retirado') {
        materialesDiv.show(); if (canAddMaterial()) $('#btnAgregarMaterial').show();
        tituloPaso2.text('Paso 2: Solo Retiro de Materiales');
      } else if (val === 'solo_auditoria') {
        $('#btnAgregarMaterial').hide();
        tituloPaso2.text('Paso 2: -- Sin materiales --');
      } else if (val === 'pendiente') {
        motivosPendiente.forEach(function(m){ motivoSelect.append('<option value="'+m.value+'">'+m.text+'</option>'); });
        motivoContainer.show(); comentarioDiv.show();
        tituloPaso2.text('Paso 2: Motivo, Comentarios y Foto');
        $('#fotoPendienteGenericaContainer').slideDown();
      } else if (val === 'cancelado') {
        motivosCancelado.forEach(function(m){ motivoSelect.append('<option value="'+m.value+'">'+m.text+'</option>'); });
        motivoContainer.show(); comentarioDiv.show();
        tituloPaso2.text('Paso 2: Motivo, Comentarios y Foto');
        $('#fotoCanceladoGenericaContainer').slideDown();
      } else {
        btnNext1.prop('disabled', true);
      }
  });

  $(document).off('change', '.implementa-material').on('change', '.implementa-material', function () {
    const idFQ = $(this).data('id-material');
    const $yes = $('#implementa_section_' + idFQ);
    const $no  = $('#no_implementa_section_' + idFQ);

    if (this.checked) {
      $yes.show();
      $yes.find('input, textarea, select').prop('disabled', false).attr('required', 'required');
      $no.hide();
      $no.find('textarea, select').removeAttr('required');
    } else {
      $yes.hide();
      $yes.find('input, textarea, select').val('').prop('disabled', true).removeAttr('required');
      $no.show();
      $no.find('textarea, select').attr('required', 'required');
    }
  });

  $(document).on('change', '.photo-source', function() {
    let sourceOption = $(this).val();
    let inputId = $(this).data('target-input'); 
    let fileInput = document.getElementById(inputId);
    if (!fileInput) return;
    if (sourceOption === 'camera') {
        fileInput.setAttribute('capture', 'camera');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*;capture=camera');
    } else {
        fileInput.removeAttribute('capture');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*');
    }
    fileInput.value = '';
  });

  $('.photo-source').on('change', function() {
    let sourceOption = $(this).val();
    let inputId      = $(this).data('target-input'); 
    let fileInput    = document.getElementById(inputId);
    if (!fileInput) return;
    if (sourceOption === 'camera') {
        fileInput.setAttribute('capture', 'camera');
        fileInput.multiple = true;
        fileInput.setAttribute('accept', 'image/*;capture=camera');
    } else {
        fileInput.removeAttribute('capture');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*');
    }
    fileInput.value = '';
  });

  // === Validación materiales (acepta modo offline) ===
  
  function validarMateriales() {
    const estado = $('#estadoGestion').val();
    if (estado === 'pendiente' || estado === 'cancelado' || estado === 'solo_auditoria') { return true; }

    if (estado === 'implementado_auditado' || estado === 'solo_implementado' || estado === 'solo_retirado') {
      const hayImplementaciones = $('.implementa-material:checked').length > 0;
      const hayMotivos = $('.no-implementa-section textarea').filter((i, el) => $(el).val().trim() !== '').length > 0;
      if (!hayImplementaciones && !hayMotivos) {
        alert("Debe implementar al menos un material o indicar el motivo de no implementación.");
        return false;
      }
    }

    let valido = true;
    $('.implementa-material').each(function () {
      const idFQ = $(this).data('id-material');
      const implementa = $(this).is(':checked');

      if (implementa) {
        const $inputValor = $('#implementa_section_' + idFQ).find('.valor-implementado');
        const maxPropuesto = parseInt($inputValor.data('valor-propuesto'), 10);
        const valor = parseInt($inputValor.val(), 10);

        if (isNaN(valor)) { alert("Debe ingresar un valor numérico en ID " + idFQ); valido = false; return false; }
        if (valor < 0) { alert("El valor implementado no puede ser negativo (ID " + idFQ + ")."); valido = false; return false; }
        if (!isNaN(maxPropuesto) && valor > maxPropuesto) { alert("El valor implementado (" + valor + ") excede el propuesto (" + maxPropuesto + "). (ID " + idFQ + ")"); valido = false; return false; }

        const hiddenUrls = document.querySelectorAll(`#hiddenUploadContainer_${idFQ} input[type="hidden"][name^="fotos[${idFQ}]"]`);
        const fileInput  = document.getElementById(`fotos_input_${idFQ}`);
        const haveLocalFiles = fileInput && fileInput.files && fileInput.files.length > 0;

        if (hiddenUrls.length === 0 && !haveLocalFiles) {
          alert("Debes adjuntar al menos una foto para el material implementado (ID " + idFQ + ").\
 Si estás offline, basta con dejar los archivos cargados (se subirán cuando haya red).");
          valido = false; return false;
        }
      }
    });
    return valido;
  }

  // Motivos visible => select foto requerida
  motivoSelect.on('change', function(){
      let val = $(this).val();
      let estado = $('#estadoGestion').val();
      resetFotoContainers();

      if (estado === 'pendiente') {
        if (val === 'local_cerrado') { $('#fotoLocalCerradoContainer').slideDown(); }
        else if (val === 'local_no_existe') { $('#fotoLocalNoExisteContainer').slideDown(); }
        else { $('#fotoPendienteGenericaContainer').slideDown(); }
      } else if (estado === 'cancelado') {
        if (val === 'mueble_no_esta_en_sala') { $('#fotoMuebleNoSalaContainer').slideDown(); }
        else { $('#fotoCanceladoGenericaContainer').slideDown(); }
      }
  });

  // Dependencias de preguntas
  function clearAndHideBlock($block) {
    const qid = $block.data('question-id');
    $block.find('input[type=radio], input[type=checkbox]').prop('checked', false);
    $block.find('input:not([type=radio]):not([type=checkbox]), select, textarea').val('');
    $block.find('input, select, textarea').removeAttr('required disabled');
    $('.conditional-question').filter('[data-parent-question="' + qid + '"]').each(function() {
      clearAndHideBlock($(this));
      $(this).hide();
    });
  }
  function onDependencyChange($input, isCheckbox) {
    const $parent = $input.closest('.question-container');
    const qid     = $parent.data('question-id');
    const val     = $input.val();
    $('.conditional-question').filter('[data-parent-question="' + qid + '"]').each(function() {
      const $cond = $(this);
      const dep   = String($cond.data('dependency-option'));
      const show  = isCheckbox ? $parent.find(`input[type=checkbox][value="${dep}"]`).is(':checked') : val === dep;

      if (show) {
        $cond.slideDown(200, () => {
          $cond.find('input, select, textarea').removeAttr('disabled')
               .filter('[data-required="1"]').attr('required','required');
        });
      } else {
        clearAndHideBlock($cond);
        $cond.slideUp(200);
      }
    });
  }

  $(document).on('change', 'input[id^="fotoPregunta_"]', function () {
    const qId = this.id.split('_').pop();
    subirFotoPregunta(qId, <?php echo $idLocal; ?>);
  });
  
  $(document).on('change', '.question-container input[type=radio]', function(){ onDependencyChange($(this), false); });
  $(document).on('change', '.question-container input[type=checkbox]', function(){ onDependencyChange($(this), true); });
        
  updateFinalizeButton();
});


function onAddMaterialSuccess(ev){
  const d    = ev.detail || {};
  const job  = d.job || {};
  const resp = d.response || {};

  if ((job.type || d.type) !== 'add_material') return;

  const idJob = job.id || d.id;

  // Quitar placeholder “en cola”
  const temp = document.getElementById('pend-mat-' + idJob);
  if (temp) temp.remove();

  if (!resp || resp.status !== 'success' || !resp.idNuevo) {
    mcToast('danger','Error al sincronizar','El material no se pudo crear en el servidor.');
    return;
  }

  // Si ya existe un checkbox con ese id de material, NO volvemos a pintarlo
  const yaExiste = document.querySelector(
    `#materialesContainer .implementa-material[data-id-material="${resp.idNuevo}"]`
  );
  if (yaExiste) {
    // Ya se agregó desde el submit online, evitamos duplicar bloque y toast
    return;
  }

  const $cont = $('#materialesContainer');

  const newMaterial = {
    id:              resp.idNuevo,
    material:        resp.nombre || 'Material',
    valor_propuesto: String(resp.valor_prop ?? ''),
    valor:           '',
    observacion:     '',
    ref_image:       resp.ref_image || ''
  };

  if ($cont.find('p').length) { $cont.empty(); }

  const bloque = construirBloqueMaterial(newMaterial);
  $cont.append(bloque);

  const $chk = $(`#materialesContainer .implementa-material[data-id-material="${newMaterial.id}"]`);
  $chk.prop('checked', true).trigger('change');

  const input = document.getElementById('fotos_input_' + newMaterial.id);
  if (input) setupFileInput(input);

  mcToast('success','Material sincronizado','Se agregó correctamente.');
}

window.addEventListener('queue:dispatch:success', onAddMaterialSuccess);

// Compatibilidad con evento legacy “queue:done” (opcional pero recomendado):
window.addEventListener('queue:done', (ev)=>{
  const d = ev.detail || {};
  if (d.type !== 'add_material') return;
  onAddMaterialSuccess({ detail: { job: { id: d.id, type: d.type }, response: d.response } });
});

window.addEventListener('queue:dispatch:error', (ev) => {
  const d   = ev.detail || {};
  const job = d.job || {};
  if ((job.type || d.type) !== 'add_material') return;
  const id = job.id || d.id;
  const temp = document.getElementById('pend-mat-' + id);
  if (temp) temp.remove();
  mcToast('danger','No se pudo crear el material','La tarea falló al sincronizar.');
});


/* Agregar Material (modal) */
$('#btnAgregarMaterial').on('click', function(){ $('#modalAgregarMaterial').modal('show'); });

$('#formAgregarMaterial').on('submit', async function (e) {
  e.preventDefault();

  const nombre = $('#nombreMaterial').val().trim();
  const valor  = $('#valorImplementado').val().trim();
  const refImg = $('#nombreMaterial option:selected').data('ref') || '';

  if (!nombre) {
    mcToast('warning','Faltan datos','Selecciona el material');
    return;
  }
  if (!/^\d$/.test(valor)) {
    mcToast('warning','Valor inválido','Debe ser un número entre 0 y 9');
    return;
  }

  const fd = new FormData(this);
  fd.append('csrf_token', window.CSRF_TOKEN || '');

  const { queued, ok, response, id: taskId } =
    await Queue.smartPost('/visibility2/app/agregarMaterial_pruebas.php', fd, { type: 'add_material' });

  const $cont = $('#materialesContainer');

  if (queued) {
    // Placeholder “pendiente” mientras sincroniza
    const tempId = 'pend-mat-' + taskId;
    $cont.append(
      `<div id="${tempId}" class="alert alert-info" style="margin-top:8px;">
         “${nombre}” en cola… <small>(se añadirá automáticamente)</small>
       </div>`
    );
    $('#modalAgregarMaterial').modal('hide');
    this.reset();
    mcToast('info','Sin conexión','El material quedó en cola.');
    return;
  }

  // Online inmediato: dejamos que el listener onAddMaterialSuccess pinte el bloque
  if (ok && response && response.status === 'success') {
    $('#modalAgregarMaterial').modal('hide');
    this.reset();
    mcToast('success','Material agregado', response.message || 'OK');
  } else {
    mcToast('danger','Error', (response && response.message) || 'No se pudo agregar el material.');
  }
});


const ACTION_LABEL  = <?php echo json_encode($actionLabel, JSON_UNESCAPED_UNICODE); ?>;
const SECTION_LABEL = <?php echo json_encode($sectionLabel, JSON_UNESCAPED_UNICODE); ?>;

function construirBloqueMaterial(material) {
  const idFQ      = material.id;
  const matName   = material.material.charAt(0).toUpperCase() + material.material.slice(1).toLowerCase();
  const valorProp = material.valor_propuesto;
  const refImage  = material.ref_image || "";
  const imgTag    = refImage ? `<img src="${refImage}" style="max-width:50px; max-height:50px; cursor:pointer;" onclick="verImagenGrande('${refImage}')" title="Ver referencia">` : "";
  let html = "";

  html += `
    <div class="form-group">
      <div class="checkbox">
        <label>
          <input type="checkbox" class="implementa-material" data-id-material="${idFQ}">
          ${ACTION_LABEL} ${matName} ${imgTag} (Valor Propuesto: ${valorProp})
        </label>
      </div>
    </div>`;

  html += `
    <div class="implementa-section" id="implementa_section_${idFQ}" style="display:none; padding-left:20px;">
      <div class="form-group">
        <label>Valor a ${ACTION_LABEL}:</label>
        <input type="number" class="form-control valor-implementado" name="valor[${idFQ}]" placeholder="Ingrese el valor" data-valor-propuesto="${valorProp}" disabled>
      </div>
      <div class="form-group">
        <label>Origen de la foto:</label>
        <select class="photo-source form-control" data-target-input="fotos_input_${idFQ}" disabled>
          <option value="gallery" selected>Elegir de la Galería</option>
          <option value="camera">Tomar Foto</option>
        </select>
      </div>
      <div class="form-group">
        <label>Fotos (hasta 10):</label>
        <input type="file" accept="image/*" name="fotos[${idFQ}][]" multiple class="form-control file-input" disabled id="fotos_input_${idFQ}">
        <div id="previewContainer_${idFQ}"></div>
      </div>
      <div class="form-group">
        <label>Observación ${SECTION_LABEL}:</label>
        <textarea class="form-control" name="observacion[${idFQ}]" placeholder="Observación..."></textarea>
      </div>
    </div>`;

  html += `
    <div class="no-implementa-section" id="no_implementa_section_${idFQ}" style="display:block; padding-left:20px;">
      <div class="form-group">
        <label>Motivo de NO ${SECTION_LABEL}:</label>
        <select class="form-control" name="motivoSelect[${idFQ}]">
          <option value="No, no permitieron">No, no permitieron</option>
          <option value="No, hay otro tipo de exhibicion">No, hay otro tipo de exhibición</option>
          <option value="No, sin productos">No, sin productos</option>
          <option value="No, no ha llegado el material">No, no ha llegado el material</option>
          <option value="Sala en remodelación">Sala en remodelación</option>
          <option value="No permite por robo">No permite por robo</option>
          <option value="Sin bajada de la central">Sin bajada de la central</option>
          <option value="Ya hay mueble adicional">Ya hay mueble adicional</option>
          <option value="No llegó el material completo">No llegó el material completo</option>
          <option value="El mueble no se encuentra en la sala">Mueble no está</option>
        </select>
      </div>
      <div class="form-group">
        <label>Detalle adicional:</label>
        <textarea class="form-control" name="motivoNoImplementado[${idFQ}]" placeholder="Explique brevemente..."></textarea>
      </div>
    </div>`;
  return html;
}

function inicializarFileInput(idFQ) {
  let fotosInput = document.getElementById('fotos_input_' + idFQ);
  if (!fotosInput) return;
  bindCompressionTo(fotosInput);
  let previewCont = document.getElementById('previewContainer_' + idFQ);
  let allFiles = [];
  let maxFotos = 5;
  fotosInput.addEventListener('change', function() {
      let newFiles = Array.from(this.files);
      if (allFiles.length + newFiles.length > maxFotos) {
          alert("Máximo de " + maxFotos + " fotos permitido.");
          this.value = '';
          return;
      }
      let pending = newFiles.length;
      newFiles.forEach((file, index) => {
          file.lat_foto = cachedPhotoCoords.lat || '';
          file.lng_foto = cachedPhotoCoords.lng || '';
          pending--;
          if (pending === 0) { processNewFiles(newFiles); }
      });
  });
  function processNewFiles(incomingFiles) {
      allFiles = allFiles.concat(incomingFiles);
      previewCont.innerHTML = '';
      allFiles.forEach((file, index) => {
          let reader = new FileReader();
          reader.onload = function(e) {
              let imgContainer = document.createElement('div');
              imgContainer.classList.add('img-container');
              let img = document.createElement('img');
              img.src = e.target.result;
              img.classList.add('thumbnail');
              let deleteBtn = document.createElement('button');
              deleteBtn.innerText = 'X';
              deleteBtn.classList.add('delete-button');
              deleteBtn.onclick = function() {
                  allFiles.splice(index, 1);
                  previewCont.removeChild(imgContainer);
                  updateFileInput(fotosInput, allFiles);
              };
              imgContainer.appendChild(img);
              imgContainer.appendChild(deleteBtn);
              let hiddenLat = document.createElement('input');
              hiddenLat.type = 'hidden';
              hiddenLat.name = 'coordsFoto[' + idFQ + '][' + index + '][lat]';
              hiddenLat.value = file.lat_foto;
              let hiddenLng = document.createElement('input');
              hiddenLng.name = 'coordsFoto[' + idFQ + '][' + index + '][lng]';
              hiddenLng.type = 'hidden';
              hiddenLng.value = file.lng_foto;
              previewCont.appendChild(hiddenLat);
              previewCont.appendChild(hiddenLng);
              if (file.lat_foto && file.lng_foto) {
                  imgContainer.title = "Lat: " + file.lat_foto + ", Lng: " + file.lng_foto;
              }
              previewCont.appendChild(imgContainer);
          };
          reader.readAsDataURL(file);
      });
      updateFileInput(fotosInput, allFiles);
  }
  function updateFileInput(input, files) {
    const dt = new DataTransfer();
    files.forEach(raw => {
      const file = raw instanceof File
        ? raw
        : new File([raw], raw.name || 'photo.jpg', { type: raw.type || 'image/jpeg', lastModified: Date.now() });
      dt.items.add(file);
    });
    input.files = dt.files;
  }
}

function agregarBloqueMaterial(idFQ, nombreMat, valorImp){
  let html = `
    <div class="form-group" data-id-fq="${idFQ}" id="material_block_${idFQ}">
      <label>Material Agregado: ${nombreMat} (Valor Implementado: ${valorImp})</label>
      <button type="button" class="btn btn-danger btn-sm" onclick="eliminarMaterial(${idFQ})">Eliminar Material</button>
      <div style="padding-left:20px;">
        <div class="form-group">
          <label>Valor Implementado:</label>
          <input type="number" class="form-control" name="valor[${idFQ}]" value="${valorImp}" placeholder="Ingrese valor" required readonly>
        </div>
        <div class="form-group">
          <label>Origen de la foto:</label>
          <select class="photo-source form-control" data-target-input="fotos_input_${idFQ}">
            <option value="gallery" selected>Elegir de la Galería</option>
            <option value="camera">Tomar Foto</option>
          </select>
        </div>
        <div class="form-group">
          <label>Fotos (hasta 10):</label>
          <input type="file" accept="image/*" multiple name="fotos[${idFQ}][]" class="form-control file-input" id="fotos_input_${idFQ}">
          <div id="previewContainer_${idFQ}" class="preview-container"></div>
          <div id="hiddenUploadContainer_${idFQ}"></div>
        </div>
        <div class="form-group">
          <label>Observación Implementación:</label>
          <textarea class="form-control" name="observacion[${idFQ}]" placeholder="Observación..."></textarea>
        </div>
      </div>
    </div>`;
  $('#materialesContainer').append(html);
}

function eliminarMaterial(idFQ){
  if(!confirm('¿Seguro que deseas eliminar este material?')) return;
  $.ajax({
    url: 'eliminarMaterial_pruebas.php',
    type: 'POST',
    data: { idFormularioQuestion: idFQ, csrf_token: window.CSRF_TOKEN },
    dataType: 'json',
    success: function(resp){
      if(resp.status === 'success'){
        $('#material_block_' + idFQ).remove();
      } else {
        alert('Error: ' + resp.message);
      }
    },
    error: function(err){
      console.error(err);
      alert('Error al conectar con el servidor');
    }
  });
}

function chooseFromGallery(qId) {
  const input = document.getElementById(`fotoPregunta_${qId}`);
  input.removeAttribute('capture');
  input.dataset.captureSource = 'gallery';
  input.click();
}
function takeWithCamera(qId) {
  const input = document.getElementById(`fotoPregunta_${qId}`);
  input.setAttribute('capture', 'environment');
  input.dataset.captureSource = 'camera';
  input.click();
}

/* === Finalizar Gestión (online u offline) === */
document.getElementById('btnFinalizar')?.addEventListener('click', async function(e){
  e.preventDefault();

  if (pendingUploads > 0) {
    alert(`Aún se están subiendo ${pendingUploads} foto(s). Espera un momento.`);
    return;
  }

  // Validación encuesta/obligatorias
  var allAnswered = true;
  $('.question-container:visible').each(function() {
    var isReq = $(this).attr('data-required');
    if (isReq === "1") {
      var answered = false;
      $(this).find("input[type=radio], input[type=checkbox]").each(function() {
        if (this.checked) { answered = true; return false; }
      });
      if (!answered) {
        $(this).find("input:not([type=radio]):not([type=checkbox]), select, textarea").each(function() {
          var val = $(this).val();
          if (!this.disabled && String(val).trim() !== "") { answered = true; return false; }
        });
      }
      if (!answered) {
        alert("Debes responder la pregunta: " + $(this).data('question-text') + ".");
        allAnswered = false;
        return false;
      }
    }
  });
  if (!allAnswered) return;

  if ($('#comentarioContainer').is(':visible')) {
    var comentario = $('#comentario').val()||"";
    if ($.trim(comentario) === "") { alert("Debes ingresar comentarios generales."); return; }
  }

  const estado = $('#estadoGestion').val();
  if (estado === 'pendiente' || estado === 'cancelado') {
    const $inputsVisibles = $(
      '#fotoLocalCerrado:visible, ' +
      '#fotoLocalNoExiste:visible, ' +
      '#fotoMuebleNoSala:visible, ' +
      '#fotoPendienteGenerica:visible, ' +
      '#fotoCanceladoGenerica:visible'
    );
    let hasFile = false;
    $inputsVisibles.each(function(){ if (this.files && this.files.length > 0) { hasFile = true; return false; } });
    if (!hasFile) { alert('Debes subir al menos una foto de evidencia.'); return; }
  }

  if (typeof validarMateriales === 'function' && !validarMateriales()) { return; }

  document.getElementById('loadingOverlay').style.display = 'block';
  this.disabled = true;

  try{
    await ensureFreshGeo();
  }catch(err){
    showGeoError(err);
    alert('Para finalizar debes activar el GPS y conceder el permiso de ubicación.');
    this.disabled = false;
    document.getElementById('loadingOverlay').style.display = 'none';
    return;
  }
  const online = await isReallyOnline();
if (online) {
  ensureClientGuid();
  document.getElementById('gestionarForm').submit();
  setTimeout(() => { try { clearClientGuid(); } catch(_){} }, 8000);
  return;
}
  // OFFLINE: encolar form completo
  try {
    ensureClientGuid();
    await queueProcesarGestion(document.getElementById('gestionarForm'), { reason: 'offline' });
    clearClientGuid();     
    mcToast('success','Gestión encolada','Se enviará automáticamente al recuperar conexión.');
    setTimeout(()=>{ window.location.href = 'index_pruebas.php'; }, 600);
  } catch (err) {
    console.error(err);
    alert('No se pudo encolar la gestión. Intenta nuevamente.');
    this.disabled = false;
    document.getElementById('loadingOverlay').style.display = 'none';
  }
});
window.campaignDivision = <?php echo json_encode($idDivision, JSON_UNESCAPED_UNICODE); ?>;

$('#nombreMaterial').on('change', function(){
  let selectedOption = $(this).find('option:selected');
  let refImage = selectedOption.data('ref');
  if (refImage && refImage.trim() !== '') {
      $('#refPreview').attr('src', refImage);
      $('#refImageContainer').show();
  } else {
      $('#refImageContainer').hide();
      $('#refPreview').attr('src', '');
  }
});
    </script>
    

    <!-- Google Maps API  -->
    <script async defer
      src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap&libraries=geometry"
      onerror="alert('Error al cargar Google Maps. Verifica tu conexión o la clave de API.')">
    </script>
    

    
</body>
</html>
<?php
$conn->close();
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) { header("Location: /visibility2/portal/login.php"); exit; }

require_once __DIR__ . '/panel_encuesta_helpers.php';
$csrf_token = panel_encuesta_get_csrf_token();

date_default_timezone_set('America/Santiago');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$user_id     = (int)($_SESSION['usuario_id'] ?? 0);
$user_div    = (int)($_SESSION['division_id'] ?? 0);
$empresa_id  = (int)($_SESSION['empresa_id'] ?? 0);
$is_mc       = ($user_div === 1);

// Conexión (si no está inicializada)
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
}

// Divisiones
$divisiones = [];
$st = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE estado=1 AND id_empresa=? ORDER BY nombre");
$st->bind_param("i", $empresa_id);
$st->execute();
$rs = $st->get_result();
while ($r = $rs->fetch_assoc()) $divisiones[] = $r;
$st->close();

$sel_div  = $is_mc ? (int)($_GET['division'] ?? 0) : $user_div;
$sel_sub  = (int)($_GET['subdivision'] ?? 0);
$sel_tipo = (int)($_GET['tipo'] ?? 0); // 0=1+3, 1=programadas, 3=ruta IPT

$subdivisiones = [];
if ($sel_div > 0) {
  $st = $conn->prepare("SELECT id, nombre FROM subdivision WHERE id_division=? ORDER BY nombre");
  $st->bind_param("i", $sel_div);
  $st->execute();
  $rs = $st->get_result();
  while($r=$rs->fetch_assoc()) $subdivisiones[]=$r;
  $st->close();
}

// campañas iniciales (solo tipo 1 y 3)
$formularios = [];
$sqlF   = "SELECT id, nombre FROM formulario WHERE id_empresa=? AND deleted_at IS NULL";
$types  = "i";
$params = [$empresa_id];

if ($sel_div > 0) { $sqlF .= " AND id_division=?";    $types.="i"; $params[]=$sel_div; }
if ($sel_sub > 0) { $sqlF .= " AND id_subdivision=?"; $types.="i"; $params[]=$sel_sub; }
if (in_array($sel_tipo, [1,3], true)) {
  $sqlF .= " AND tipo=?";
  $types.="i"; $params[]=$sel_tipo;
} else {
  $sqlF .= " AND tipo IN (1,3)";
}
$sqlF .= " ORDER BY fechaInicio DESC, id DESC";
$st = $conn->prepare($sqlF);
$st->bind_param($types, ...$params);
$st->execute();
$rs = $st->get_result();
while($r=$rs->fetch_assoc()) $formularios[]=$r;
$st->close();

$usuarios=[];
if ($is_mc && $sel_div===0) {
  $st=$conn->prepare("SELECT id, usuario FROM usuario WHERE activo=1 AND id_empresa=? ORDER BY usuario");
  $st->bind_param("i", $empresa_id);
  $st->execute(); $res=$st->get_result();
} else {
  $divRef = $sel_div>0 ? $sel_div : $user_div;
  $st=$conn->prepare("SELECT id, usuario FROM usuario WHERE activo=1 AND id_division=? AND id_empresa=? ORDER BY usuario");
  $st->bind_param("ii", $divRef, $empresa_id);
  $st->execute(); $res=$st->get_result();
}
while($r=$res->fetch_assoc()) $usuarios[]=$r;
if (isset($st)) $st->close();

// Jefes
$jefes=[];
if ($sel_div>0 || !$is_mc){
  $divRef = $sel_div>0 ? $sel_div : $user_div;
  $st=$conn->prepare("
    SELECT DISTINCT jv.id, jv.nombre
    FROM local l
    JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
    WHERE l.id_division=? AND l.id_empresa=?
    ORDER BY jv.nombre
  ");
  $st->bind_param("ii",$divRef,$empresa_id);
  $st->execute(); $rs=$st->get_result();
  while($r=$rs->fetch_assoc()) $jefes[]=$r;
  $st->close();
}

// Distritos
$distritos=[];
if ($sel_div>0 || !$is_mc){
  $divRef = $sel_div>0 ? $sel_div : $user_div;
  $st=$conn->prepare("
    SELECT DISTINCT d.id, d.nombre_distrito
    FROM local l
    JOIN distrito d ON d.id = l.id_distrito
    WHERE l.id_division=? AND l.id_empresa=?
    ORDER BY d.nombre_distrito
  ");
  $st->bind_param("ii",$divRef,$empresa_id);
  $st->execute(); $rs=$st->get_result();
  while($r=$rs->fetch_assoc()) $distritos[]=$r;
  $st->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Encuesta</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
  :root{ --thumb-size: 120px; }
  body.thumb-small{ --thumb-size: 80px; }
  body.thumb-medium{ --thumb-size: 120px; }
  body.thumb-large{ --thumb-size: 160px; }

  body{background:#f7f8fb}
  .card{border:0; box-shadow:0 8px 24px rgba(0,0,0,.06); border-radius:14px;}
  .table thead th{white-space:nowrap;}
  .sticky-toolbar{position:sticky; top:0; z-index:100; background:#fff; border-bottom:1px solid #eee; padding:.5rem 1rem; border-top-left-radius:14px; border-top-right-radius:14px;}
  #resultsTable td{vertical-align:middle;}
  .pagination{margin-bottom:0;}

  .thumb-wrap{display:flex; flex-wrap:wrap; gap:6px; position:relative; min-height: calc(var(--thumb-size) + 6px);}
  .thumb{height:var(--thumb-size); width:auto; cursor:pointer; border-radius:8px; margin:0; box-shadow:0 2px 6px rgba(0,0,0,.15);}
  .thumb-count{position:absolute; top:6px; left:6px; background:#111; color:#fff; border-radius:50%; width:26px; height:26px; font-size:12px; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(0,0,0,.3);}

  .qf-card{border:1px solid #e5e7eb; border-radius:10px; padding:.5rem .75rem; margin-bottom:.5rem; background:#fff;}
  .qf-title{font-weight:600;}
  .qf-badges .badge{margin-right:.25rem; margin-bottom:.25rem;}
  .select2-container--default .select2-selection--multiple .select2-selection__choice{max-width:98%; overflow:hidden; text-overflow:ellipsis;}
  .filter-chip{display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; border:1px solid #e5e7eb; background:#f1f5f9; color:#334155; margin:0 6px 6px 0; font-size:.75rem;}

  /* Loader / estado de carga */
  #panel-encuesta-table-wrapper.is-loading{
    opacity:.5;
    pointer-events:none;
  }
  #panel-encuesta-loading{
    font-size:.9rem;
  }

  /* límite de alto para qfilters para que no empuje tanto la tabla */
  #qfilters {
    max-height: 240px;
    overflow-y: auto;
  }
</style>
</head>
<body class="p-3 thumb-medium">
<div class="container-fluid">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">Panel de Encuesta</h3>
    <div class="ml-auto">
      <a class="btn btn-sm btn-outline-secondary" href="/visibility2/portal/home.php">Volver</a>
    </div>
  </div>

  <div class="card">
    <!-- ahora es un <form> real para poder usar submit/Enter -->
    <form class="sticky-toolbar d-flex align-items-end flex-wrap" id="panel-encuesta-filtros">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?=htmlspecialchars($csrf_token)?>">
      <?php if ($is_mc): ?>
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>División</small></label>
          <select id="f_division" name="division" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($divisiones as $d): ?>
              <option value="<?=$d['id']?>" <?=$sel_div==$d['id']?'selected':''?>><?=htmlspecialchars($d['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>Subdivisión</small></label>
          <select id="f_subdivision" name="subdivision" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($subdivisiones as $s): ?>
              <option value="<?=$s['id']?>" <?=$sel_sub==$s['id']?'selected':''?>><?=htmlspecialchars($s['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" id="f_division" name="division" value="<?=$user_div?>">
        <div class="mr-3 mb-2">
          <label class="mb-1"><small>Subdivisión</small></label>
          <select id="f_subdivision" name="subdivision" class="form-control form-control-sm" style="min-width:200px">
            <option value="0">-- Todas --</option>
            <?php foreach($subdivisiones as $s): ?>
              <option value="<?=$s['id']?>"><?=htmlspecialchars($s['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <!-- Tipo -->
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Tipo</small></label>
        <select id="f_tipo" name="tipo" class="form-control form-control-sm" style="min-width:200px">
          <option value="0"  <?=$sel_tipo===0?'selected':''?>>Programadas + Ruta IPT</option>
          <option value="1"  <?=$sel_tipo===1?'selected':''?>>Programadas</option>
          <option value="3"  <?=$sel_tipo===3?'selected':''?>>Ruta IPT</option>
        </select>
      </div>

      <!-- Campaña (PRIMERO) -->
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Campaña</small></label>
        <select id="f_form" name="form_id" class="form-control form-control-sm" style="min-width:260px">
          <option value="0">-- Todas --</option>
          <?php foreach($formularios as $f): ?>
            <option value="<?=$f['id']?>"><?=htmlspecialchars($f['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Preguntas (dependiente de Campaña) -->
      <div class="mr-3 mb-2" style="min-width:320px; max-width:420px">
        <label class="mb-1"><small>Preguntas</small></label>
        <select id="f_preguntas" class="form-control form-control-sm" multiple></select>

        <div class="d-flex align-items-center mt-2">
          <div class="dropdown mr-2">
            <button class="btn btn-light btn-sm dropdown-toggle" type="button" id="btnLoadPreset" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
              Cargar preset
            </button>
            <div class="dropdown-menu" aria-labelledby="btnLoadPreset" id="presetsMenu">
              <span class="dropdown-item-text text-muted">Sin presets aún</span>
            </div>
          </div>

          <button class="btn btn-outline-primary btn-sm mr-2" type="button" id="btnSavePreset"><i class="fa fa-save"></i> Guardar preset</button>
          <button class="btn btn-outline-danger btn-sm" type="button" id="btnClearPreset"><i class="fa fa-trash"></i> Limpiar filtros</button>
        </div>
      </div>

      <!-- Contenedor filtros por pregunta + stats -->
      <div class="mr-3 mb-2 w-100">
        <div id="qfilters" class="border rounded p-2 bg-white" style="min-height:44px">
          <small class="text-muted">Añade una o más preguntas y configura el filtro de su respuesta aquí…</small>
        </div>
        <small class="text-muted d-block mt-1">
          <strong>Importante:</strong> por defecto el panel solo muestra visitas que cumplen 
          <strong>todas</strong> las condiciones de las preguntas seleccionadas.
          Si en una visita hay al menos una pregunta del filtro que no se respondió en la visita, esa gestión no aparecerá en los resultados.
          Puedes activar <strong>Incluir parciales</strong> para ver visitas que cumplan al menos una condición.
        </small>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="f_qfilters_match" value="any">
          <label class="form-check-label small text-muted" for="f_qfilters_match">
            Incluir parciales (mostrar visitas que cumplan al menos una condición).
          </label>
        </div>
      </div>

      <div class="mr-2 mb-2">
        <label class="mb-1"><small>Desde</small></label>
        <input type="date" id="f_desde" name="desde" class="form-control form-control-sm">
      </div>
      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Hasta</small></label>
        <input type="date" id="f_hasta" name="hasta" class="form-control form-control-sm">
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Distrito</small></label>
        <select id="f_distrito" name="distrito" class="form-control form-control-sm" style="min-width:180px">
          <option value="0">-- Todos --</option>
          <?php foreach($distritos as $d): ?>
            <option value="<?=$d['id']?>"><?=htmlspecialchars($d['nombre_distrito'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Jefe de venta</small></label>
        <select id="f_jv" name="jv" class="form-control form-control-sm" style="min-width:180px">
          <option value="0">-- Todos --</option>
          <?php foreach($jefes as $j): ?>
            <option value="<?=$j['id']?>"><?=htmlspecialchars($j['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Usuario</small></label>
        <select id="f_usuario" name="usuario" class="form-control form-control-sm" style="min-width:200px">
          <option value="0">-- Todos --</option>
          <?php foreach($usuarios as $u): ?>
            <option value="<?=$u['id']?>"><?=htmlspecialchars($u['usuario'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mr-3 mb-2">
        <label class="mb-1"><small>Cód. Local</small></label>
        <input id="f_codigo" name="codigo" class="form-control form-control-sm" placeholder="Ej: L1234">
      </div>

      <!-- Botones + leyenda -->
      <div class="ml-auto mb-2 text-right">
        <button id="btnBuscar" type="submit" class="btn btn-primary btn-sm">
          <i class="fa fa-search"></i> Buscar
        </button>
        <button id="btnResetFilters" type="button" class="btn btn-outline-secondary btn-sm">
          <i class="fa fa-eraser"></i> Limpiar todo
        </button>

        <div class="btn-group btn-group-sm mt-1">
          <button id="btnCSV" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-file-csv"></i> CSV
          </button>
          <button id="btnFotosHTML" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-image"></i> Fotos HTML
          </button>
          <button id="btnPDF" type="button" class="btn btn-outline-secondary">
            <i class="fa fa-file-pdf"></i> Fotos PDF
          </button>
        </div>

        <small class="text-muted d-block mt-1">
          Exportación de fotos (HTML / PDF) incluye solo respuestas de tipo foto.
        </small>
      </div>
    </form>

    <div class="card-body">
      <div id="panel-encuesta-loading" class="text-muted d-none mb-2">
        <i class="fa fa-circle-notch fa-spin"></i> Cargando datos…
      </div>

      <div class="d-flex align-items-center mb-2 flex-wrap">
        <div class="d-flex align-items-center mr-3 mb-2">
          <div class="mr-2">Mostrar</div>
          <select id="f_limit" class="form-control form-control-sm" style="width:auto">
            <?php foreach([25,50,100,150,200] as $n): ?>
              <option value="<?=$n?>"><?=$n?></option>
            <?php endforeach; ?>
          </select>
          <div class="ml-2">registros</div>
        </div>

        <div class="d-flex align-items-center mr-3 mb-2">
          <div class="mr-2">Tamaño fotos</div>
          <select id="f_thumbsize" class="form-control form-control-sm" style="width:auto">
            <option value="small">Pequeño</option>
            <option value="medium" selected>Mediano</option>
            <option value="large">Grande</option>
          </select>
        </div>

        <div class="ml-auto text-muted mb-2" id="infoTotal"></div>
      </div>

      <div id="activeFilters" class="mb-2 small text-muted"></div>

      <div id="panel-encuesta-table-wrapper">
        <div class="table-responsive">
          <table class="table table-sm table-bordered table-hover" id="resultsTable">
            <thead class="thead-light">
              <tr>
                <th>Fecha (fin visita)</th>
                <th>Campaña</th>
                <th>Pregunta</th>
                <th>Tipo</th>
                <th>Respuesta</th>
                <th>Valor</th>
                <th>Cód. Local</th>
                <th>Local</th>
                <th>Dirección</th>
                <th>Cadena</th>
                <th>Jefe Venta</th>
                <th>Usuario</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="12" class="text-center text-muted">
                  Ajusta los filtros y presiona <strong>Buscar</strong> para cargar datos.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav>
          <ul class="pagination justify-content-center" id="pager"></ul>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- Modal fotos (lightbox) -->
<div class="modal fade" id="photoModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
    <div class="modal-content bg-dark position-relative">
      <button type="button" class="btn btn-dark position-absolute" id="lbPrev" style="left:8px; top:50%; transform:translateY(-50%); font-size:24px; z-index:2;">‹</button>
      <button type="button" class="btn btn-dark position-absolute" id="lbNext" style="right:8px; top:50%; transform:translateY(-50%); font-size:24px; z-index:2;">›</button>
      <div class="modal-body text-center p-0 position-relative">
        <img id="photoModalImg" src="" alt="" style="max-width:100%; max-height:80vh;">
        <div id="photoModalCaption" class="position-absolute text-white-50 small" style="right:10px; bottom:8px;"></div>
        <a id="photoModalOpen" class="btn btn-sm btn-light position-absolute" style="left:10px; bottom:8px;" target="_blank" rel="noopener">
          Abrir en nueva pestaña
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Modal de errores (reutilizable) -->
<div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title">Ups…</h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body"><div id="errorModalMsg" class="small text-muted"></div></div>
    </div>
  </div>
</div>

<!-- JS: jQuery + Bootstrap + Select2 -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
(function($){
  const isMC   = <?= $is_mc ? 'true':'false' ?>;
  const USER_ID  = <?=$user_id?>;
  const USER_DIV = <?=$user_div?>;
  const isRedBull = (USER_DIV === 14); // División Red Bull

  const ABS_BASE = (window.location.origin || (location.protocol + '//' + location.host));
  const DEFAULT_RANGE_DAYS = 7; // mismo criterio que el backend (últimos 7 días)
  const EXPORT_LIMITS = {
    csv: 50000,
    fotosPdf: 250,
    fotosHtml: 4000
  };

  function escapeHtml(s){
    return (s||'').toString().replace(/[&<>"'`=\/]/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[c];
    });
  }

  function showError(title, msg){
    $('#errorModal .modal-title').text(title||'Error');
    $('#errorModalMsg').html(msg||'Ha ocurrido un error.');
    $('#errorModal').modal('show');
  }

  function showLoading(){
    $('#panel-encuesta-loading').removeClass('d-none');
    $('#panel-encuesta-table-wrapper').addClass('is-loading');
    $('#btnBuscar, #btnCSV, #btnPDF, #btnFotosHTML').prop('disabled', true);
  }

  function hideLoading(){
    $('#panel-encuesta-loading').addClass('d-none');
    $('#panel-encuesta-table-wrapper').removeClass('is-loading');
    $('#btnBuscar, #btnCSV, #btnPDF, #btnFotosHTML').prop('disabled', false);
  }

  // Prefill suave: últimos DEFAULT_RANGE_DAYS días (solo si están vacías)
  function setDefaultDates(){
    const hoy = new Date();
    const hasta = hoy.toISOString().slice(0,10);
    const d = new Date(hoy);
    d.setDate(d.getDate() - (DEFAULT_RANGE_DAYS - 1));
    const desde = d.toISOString().slice(0,10);
    if(!$('#f_desde').val()) $('#f_desde').val(desde);
    if(!$('#f_hasta').val()) $('#f_hasta').val(hasta);
  }
  setDefaultDates();

  // ========= Cascada de filtros de ámbito =========
  $('#f_division').on('change', function(){
    const div = $(this).val();

    $.getJSON('ajax_subdivisiones.php',{ division: div }, function(rows){
      const $s = $('#f_subdivision').empty().append('<option value="0">-- Todas --</option>');
      rows.forEach(r => $s.append(`<option value="${r.id}">${escapeHtml(r.nombre)}</option>`));
    }).fail(xhr=>showError('No se pudo cargar subdivisiones', xhr.responseText||'Error inesperado'))
      .always(()=>loadCampanas(true));

    $.getJSON('ajax_jefes_por_division.php',{ division: div }, function(rows){
      const $j = $('#f_jv').empty().append('<option value="0">-- Todos --</option>');
      rows.forEach(r => $j.append(`<option value="${r.id}">${escapeHtml(r.nombre)}</option>`));
    }).fail(xhr=>showError('No se pudo cargar jefes', xhr.responseText||'Error inesperado'));

    $.getJSON('ajax_distritos_por_division.php',{ division: div }, function(rows){
      const $d = $('#f_distrito').empty().append('<option value="0">-- Todos --</option>');
      rows.forEach(r => $d.append(`<option value="${r.id}">${escapeHtml(r.nombre_distrito)}</option>`));
    }).fail(xhr=>showError('No se pudo cargar distritos', xhr.responseText||'Error inesperado'));
  });

  $('#f_subdivision').on('change', ()=>loadCampanas(true));
  $('#f_tipo').on('change', ()=>loadCampanas(true));

  function loadCampanas(resetQuestions){
    $.getJSON('ajax_campanas_por_div_sub.php', {
      division: $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      tipo: $('#f_tipo').val()
    }, function(rows){
      const $c = $('#f_form').empty().append('<option value="0">-- Todas --</option>');
      rows.forEach(r => $c.append(`<option value="${r.id}">${escapeHtml(r.nombre)}</option>`));
    }).fail(xhr=>showError('No se pudo cargar campañas', xhr.responseText||'Error inesperado'))
      .always(() => {
        if (resetQuestions) {
          $('#f_preguntas').val(null).trigger('change');
          QFILTERS.clear(); syncQFiltersUI();
          initPreguntaSelect2();
        }
      });
  }

  function isGlobalMode(){
    const v = $('#f_form').val();
    return (v === '0' || v === 0);
  }

  // ========= Select2 Preguntas =========
  function initPreguntaSelect2(){
    if ($('#f_preguntas').data('select2')) { $('#f_preguntas').select2('destroy'); }
    $('#f_preguntas').empty();

    $('#f_preguntas').select2({
      placeholder: 'Buscar preguntas…',
      allowClear: true,
      minimumInputLength: 1,
      width: 'resolve',
      ajax: {
        url: 'ajax_preguntas_lookup.php',
        dataType: 'json',
        delay: 250,
        data: params => ({
          q: params.term || '',
          division: $('#f_division').val(),
          subdivision: $('#f_subdivision').val(),
          tipo: $('#f_tipo').val() || 0,
          form_id: $('#f_form').val(),
          global: isGlobalMode() ? 1 : 0,
          csrf_token: $('#csrf_token').val()
        }),
        processResults: data => ({
          results: (data && data.data ? data.data : data).map(r => ({
            id: r.id,
            text: r.text,
            campana: r.campana || null,
            count: r.count,
            tipo: r.tipo || null,
            mode: r.mode || (isGlobalMode() ? 'set' : 'exact')
          }))
        })
      },
      templateResult: item => {
        if (!item.id) return item.text;
        const c = item.campana ? ` <span class="text-muted">· ${escapeHtml(item.campana)}</span>` : '';
        const k = (item.count!=null) ? ` <span class="text-muted">(${item.count})</span>` : '';
        return $(`<span>${escapeHtml(item.text)}${c}${k}</span>`);
      }
    })
    .on('select2:select', function(e){
      const d = e.params.data;
      const mode = d.mode || (isGlobalMode() ? 'set' : 'exact');

      let key, metaId;
      if (mode === 'vset') {
        const hash = String(d.id).split(':')[1];
        key = 'vset:'+hash; metaId = hash;
      } else if (mode === 'set') {
        key = 'set:'+d.id; metaId = d.id;
      } else {
        key = 'exact:'+d.id; metaId = d.id;
      }
      if (QFILTERS.has(key)) return;

      fetchPreguntaMeta(mode, metaId).then(meta => {
        QFILTERS.set(key, {
          meta: {
            id: meta.id,
            mode: mode,
            tipo: meta.tipo,
            tipo_texto: meta.tipo_texto,
            label: d.text,
            has_options: meta.has_options,
            options: meta.options || []
          },
          values: {}
        });
        syncQFiltersUI();
      }).catch(()=>{
        const $opt = $('#f_preguntas').find(`option[value="${d.id}"]`);
        $opt.prop('selected', false);
        $('#f_preguntas').trigger('change');
      });
    })
    .on('select2:unselect', function(e){
      const id = String(e.params.data.id);
      QFILTERS.delete('exact:'+id);
      QFILTERS.delete('set:'+id);
      if (id.startsWith('v:')) QFILTERS.delete('vset:'+id.slice(2));
      syncQFiltersUI();
    });

    $(document).trigger('preguntas-ready');
  }

  // ========= QFILTERS =========
  const QFILTERS = new Map();

  function renderQFilterControl(key, entry){
    const { meta } = entry;
    const tipoBadge = `<span class="badge badge-secondary">${escapeHtml(meta.tipo_texto || '')}</span>`;
    const scopeBadge = meta.mode==='set'
      ? ' <span class="badge badge-info">Global</span>'
      : (meta.mode==='vset' ? ' <span class="badge badge-warning">Global (huérfana)</span>' : '');

    const base = $(`
      <div class="qf-card" data-key="${key}">
        <div class="d-flex align-items-center">
          <div class="qf-title">${escapeHtml(meta.label || ('Pregunta '+meta.id))}</div>
          <div class="ml-2 qf-badges">${tipoBadge}${scopeBadge}</div>
          <button class="btn btn-sm btn-link text-danger ml-auto qf-remove" data-key="${key}" title="Quitar"><i class="fa fa-times"></i></button>
        </div>
        <div class="mt-2 qf-controls" data-key="${key}"></div>
        <div class="mt-2">
          <div class="d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary qf-stats-refresh" data-key="${key}">
              <i class="fa fa-chart-bar"></i> Ver conteos
            </button>
            <div class="ml-2 small text-muted">Resumen rápido con el ámbito actual</div>
          </div>
          <div class="qf-stats mt-2" data-key="${key}"></div>
        </div>
      </div>
    `);

    const $ctrl = base.find('.qf-controls');
    if (meta.tipo === 1) {
      $ctrl.html(`
        <div>
          <label class="mr-2 mb-0">Filtrar:</label>
          <label class="mr-2"><input type="checkbox" class="qf-bool" data-key="${key}" value="1"> Sí</label>
          <label class="mr-2"><input type="checkbox" class="qf-bool" data-key="${key}" value="0"> No</label>
        </div>
      `);
    } else if (meta.tipo === 2) {
      const opts = (meta.options||[]).map(o=>`<option value="${escapeHtml((o.id!=null)?String(o.id):('text:'+o.text))}">${escapeHtml(o.text)}</option>`).join('');
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Es igual a:</label>
          <select multiple class="form-control form-control-sm qf-opts" data-key="${key}" style="max-width:420px">${opts}</select>
        </div>
      `);
    } else if (meta.tipo === 3) {
      const opts = (meta.options||[]).map(o=>`<option value="${escapeHtml((o.id!=null)?String(o.id):('text:'+o.text))}">${escapeHtml(o.text)}</option>`).join('');
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Contiene</label>
          <select multiple class="form-control form-control-sm qf-opts" data-key="${key}" style="max-width:420px">${opts}</select>
          <div class="ml-2">
            <label class="mr-2"><input type="radio" name="qfmode-${key}" class="qf-mode" data-key="${key}" value="any" checked> alguna</label>
            <label><input type="radio" name="qfmode-${key}" class="qf-mode" data-key="${key}" value="all"> todas</label>
          </div>
        </div>
      `);
    } else if (meta.tipo === 4) {
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Respuesta</label>
          <select class="form-control form-control-sm qf-text-op" data-key="${key}">
            <option value="contains">contiene</option>
            <option value="equals">igual a</option>
            <option value="prefix">empieza con</option>
            <option value="suffix">termina con</option>
          </select>
          <input class="form-control form-control-sm ml-2 qf-text-val" data-key="${key}" placeholder="texto…">
        </div>
      `);
    } else if (meta.tipo === 5) {
      $ctrl.html(`
        <div class="form-inline">
          <label class="mr-2 mb-0">Entre</label>
          <input type="number" step="any" class="form-control form-control-sm qf-num-min" data-key="${key}" style="width:120px" placeholder="min">
          <span class="mx-2">y</span>
          <input type="number" step="any" class="form-control form-control-sm qf-num-max" data-key="${key}" style="width:120px" placeholder="max">
        </div>
      `);
    } else if (meta.tipo === 7) {
      $ctrl.html(`<div class="text-muted small">Sin filtro de contenido específico (usa conteo con/sin foto).</div>`);
    } else {
      $ctrl.html(`<div class="text-muted small">Sin controles para este tipo.</div>`);
    }

    return base;
  }

  function syncQFiltersUI(){
    const $box = $('#qfilters').empty();
    if (QFILTERS.size === 0){
      $box.html('<small class="text-muted">Añade una o más preguntas y configura el filtro de su respuesta aquí…</small>');
      renderActiveFilters();
      return;
    }
    QFILTERS.forEach((entry, key) => {
      $box.append( renderQFilterControl(key, entry) );
    });
    renderActiveFilters();
  }

  $(document).on('click', '.qf-remove', function(){
    const key = $(this).data('key');
    QFILTERS.delete(key);
    const id = String(key).split(':')[1];
    const possibleIds = [''+id, 'v:'+id];
    possibleIds.forEach(pid=>{
      const $opt = $('#f_preguntas').find(`option[value="${pid}"]`);
      if ($opt.length){ $opt.prop('selected', false); }
    });
    $('#f_preguntas').trigger('change');
    syncQFiltersUI();
  });

  function fetchPreguntaMeta(mode, id){
    return new Promise((resolve, reject)=>{
      $.getJSON('ajax_pregunta_meta.php', {
        mode: mode,
        id: id,
        division: $('#f_division').val(),
        subdivision: $('#f_subdivision').val(),
        tipo: $('#f_tipo').val() || 0,
        form_id: $('#f_form').val(),
        csrf_token: $('#csrf_token').val()
      }, resp => {
        const payload = resp && resp.data ? resp.data : resp;
        if (payload && payload.id){ resolve(payload); } else { reject(); }
      }).fail(xhr=>{ showError('No se pudo cargar metadatos', xhr.responseText||'Error inesperado'); reject(); });
    });
  }

  function fetchQStats(key, meta){
    const scope = {
      division: $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      form_id: $('#f_form').val(),
      clase_tipo: $('#f_tipo').val() || 0,
      desde: $('#f_desde').val(),
      hasta: $('#f_hasta').val(),
      distrito: $('#f_distrito').val(),
      jv: $('#f_jv').val(),
      usuario: $('#f_usuario').val(),
      codigo: $('#f_codigo').val()
    };
    const $box = $(`.qf-stats[data-key="${key}"]`).html('<span class="text-muted">Cargando…</span>');

    $.getJSON('ajax_pregunta_stats.php', {
      mode: meta.mode, id: meta.id, tipo: meta.tipo, csrf_token: $('#csrf_token').val(), ...scope
    }, stat => {
      const payload = stat && stat.data ? stat.data : stat;
      if (!payload) { $box.html('<span class="text-danger">Sin datos</span>'); return; }
      if (meta.tipo === 5 && payload.numeric) {
        $box.html(`
          <div class="small">
            <strong>Total:</strong> ${payload.numeric.count} ·
            <strong>Min:</strong> ${payload.numeric.min ?? '-'} ·
            <strong>Max:</strong> ${payload.numeric.max ?? '-'} ·
            <strong>Prom:</strong> ${payload.numeric.avg ?? '-'}</strong>
          </div>
        `);
        return;
      }
      const buckets = (payload.buckets || []);
      if (!buckets.length) { $box.html('<span class="text-muted">Sin buckets</span>'); return; }
      const rows = buckets.map(b => `
        <div class="d-flex align-items-center mb-1">
          <div class="flex-grow-1">${escapeHtml(b.label)}</div>
          <div class="text-monospace">${b.count.toLocaleString('es-CL')}</div>
        </div>
      `).join('');
      $box.html(`<div class="small">${rows}</div>`);
    }).fail(xhr=>{ $box.html('<span class="text-danger">Error al cargar</span>'); showError('No se pudo cargar stats', xhr.responseText||'Error inesperado'); });
  }

  $(document).on('click', '.qf-stats-refresh', function(){
    const key = $(this).data('key');
    const entry = QFILTERS.get(key); if (!entry) return;
    fetchQStats(key, entry.meta);
  });

  $('#f_division, #f_subdivision, #f_form, #f_tipo, #f_desde, #f_hasta, #f_distrito, #f_jv, #f_usuario, #f_codigo')
    .on('change', function(){
      QFILTERS.forEach(({meta}, key) => { fetchQStats(key, meta); });
      renderActiveFilters();
    });

  $('#f_codigo').on('keyup', function(){
    renderActiveFilters();
  });

  $('#f_qfilters_match').on('change', function(){
    renderActiveFilters();
  });

  $('#btnResetFilters').on('click', function(){
    window.location.href = 'panel_encuesta.php';
  });

  $(document).on('change', '.qf-bool', function(){
    const key = $(this).data('key');
    const picked = $(`.qf-bool[data-key="${key}"]:checked`).map((_,el)=>el.value).get();
    const ent = QFILTERS.get(key); if (!ent) return;
    ent.values = { bool: picked.map(x=>parseInt(x,10)) };
  });
  $(document).on('change', '.qf-opts', function(){
    const key=$(this).data('key');
    const vals=$(this).val()||[];
    const ids=[], texts=[];
    vals.forEach(v=>{ if(String(v).startsWith('text:')) texts.push(v.slice(5)); else ids.push(parseInt(v,10)); });
    const ent=QFILTERS.get(key); if(!ent) return;
    ent.values = Object.assign({}, ent.values, { opts_ids: ids, opts_texts: texts });
  });
  $(document).on('change', '.qf-mode', function(){
    const key=$(this).data('key'); const ent=QFILTERS.get(key); if(!ent) return;
    ent.values = Object.assign({}, ent.values, { match: this.value });
  });
  $(document).on('change keyup', '.qf-text-op,.qf-text-val', function(){
    const key=$(this).data('key'); const ent=QFILTERS.get(key); if(!ent) return;
    const op = $(`.qf-text-op[data-key="${key}"]`).val();
    const val= $(`.qf-text-val[data-key="${key}"]`).val();
    ent.values = { op, text: val };
  });
  $(document).on('change keyup', '.qf-num-min,.qf-num-max', function(){
    const key=$(this).data('key'); const ent=QFILTERS.get(key); if(!ent) return;
    const min = parseFloat(($(`.qf-num-min[data-key="${key}"]`).val()||'').replace(',','.'));
    const max = parseFloat(($(`.qf-num-max[data-key="${key}"]`).val()||'').replace(',','.'));
    ent.values = { min: isNaN(min)?null:min, max: isNaN(max)?null:max };
  });

  let page = 1;
  let currentXhr = null;

  function collectQFilters(){
    const out = [];
    QFILTERS.forEach(({meta, values}) => {
      out.push({ mode: meta.mode, id: meta.id, tipo: meta.tipo, values: values || {} });
    });
    return out;
  }

  function buildParams(){
    const ids = $('#f_preguntas').val() || [];
    const formId = $('#f_form').val();

    let qids=[], qset_ids=[], vset_ids=[];
    if (formId === '0' || formId === 0) {
      ids.forEach(x=>{
        const s=String(x);
        if (s.startsWith('v:')) vset_ids.push(s.slice(2));
        else qset_ids.push(parseInt(s,10));
      });
    } else {
      ids.forEach(x=>{
        const s=String(x);
        if (!s.startsWith('v:')) qids.push(parseInt(s,10));
      });
    }

    return {
      division: $('#f_division').val(),
      subdivision: $('#f_subdivision').val(),
      form_id: formId,
      tipo: $('#f_tipo').val(),
      desde: $('#f_desde').val(),
      hasta: $('#f_hasta').val(),
      distrito: $('#f_distrito').val(),
      jv: $('#f_jv').val(),
      usuario: $('#f_usuario').val(),
      codigo: $('#f_codigo').val(),
      qids: qids,
      qset_ids: qset_ids,
      vset_ids: vset_ids,
      qfilters: JSON.stringify(collectQFilters()),
      qfilters_match: $('#f_qfilters_match').is(':checked') ? 'any' : 'all',
      csrf_token: $('#csrf_token').val(),
      page: page,
      limit: $('#f_limit').val(),
      facets: 1
    };
  }

  function renderActiveFilters(){
    const chips = [];
    const getLabel = $sel => $sel.find('option:selected').text();

    if ($('#f_division').length && $('#f_division').val() !== '0') {
      chips.push(`División: ${escapeHtml(getLabel($('#f_division')))}`);
    }
    if ($('#f_subdivision').val() !== '0') {
      chips.push(`Subdivisión: ${escapeHtml(getLabel($('#f_subdivision')))}`);
    }
    if ($('#f_tipo').val() !== '0') {
      chips.push(`Tipo: ${escapeHtml(getLabel($('#f_tipo')))}`);
    }
    if ($('#f_form').val() !== '0') {
      chips.push(`Campaña: ${escapeHtml(getLabel($('#f_form')))}`);
    }

    const preguntaCount = ($('#f_preguntas').val() || []).length;
    if (preguntaCount > 0) {
      chips.push(`Preguntas: ${preguntaCount}`);
    }
    if (QFILTERS.size > 0) {
      const matchLabel = $('#f_qfilters_match').is(':checked') ? 'parciales' : 'todas';
      chips.push(`Filtros: ${QFILTERS.size} (${matchLabel})`);
    }

    const desde = $('#f_desde').val();
    const hasta = $('#f_hasta').val();
    if (desde || hasta) {
      chips.push(`Rango: ${escapeHtml(desde || '...')} → ${escapeHtml(hasta || '...')}`);
    }

    if ($('#f_distrito').val() !== '0') {
      chips.push(`Distrito: ${escapeHtml(getLabel($('#f_distrito')))}`);
    }
    if ($('#f_jv').val() !== '0') {
      chips.push(`Jefe: ${escapeHtml(getLabel($('#f_jv')))}`);
    }
    if ($('#f_usuario').val() !== '0') {
      chips.push(`Usuario: ${escapeHtml(getLabel($('#f_usuario')))}`);
    }
    if ($('#f_codigo').val()) {
      chips.push(`Cód. Local: ${escapeHtml($('#f_codigo').val())}`);
    }

    if (!chips.length) {
      $('#activeFilters').html('Sin filtros activos.');
      return;
    }

    $('#activeFilters').html(chips.map(c => `<span class="filter-chip">${c}</span>`).join(''));
  }

  function toQuery(obj){
    const u=new URLSearchParams();
    Object.keys(obj).forEach(k=>{
      const v = obj[k];
      if (Array.isArray(v)) {
        v.forEach(x => u.append(k+'[]', x));
      } else if (v!=='' && v!=null) {
        u.append(k, v);
      }
    });
    return u.toString();
  }

  function uniqueOptions(rows, idKey, nameKey){
    const map = new Map();
    rows.forEach(r=>{
      const id = r[idKey];
      const name = r[nameKey];
      if (id && name) map.set(String(id), name);
    });
    return [...map.entries()].sort((a,b)=> a[1].localeCompare(b[1], 'es'));
  }

  function buildPhotoCandidates(path){
    const raw = (path || '').toString().trim();
    if (!raw) return [];
    if (/^https?:\/\//i.test(raw)) return [raw];
    const noSlash = raw.replace(/^\/+/, '');
    const withSlash = '/' + noSlash;
    const base = ABS_BASE;
    const out = [];
    const add = (u)=>{ if (u && !out.includes(u)) out.push(u); };

    if (noSlash.startsWith('uploads/')) {
      add(base + '/visibility2/app/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }

    if (noSlash.startsWith('app/')) {
      add(base + '/visibility2/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }

    if (noSlash.startsWith('portal/')) {
      add(base + '/visibility2/' + noSlash);
      add(base + '/' + noSlash);
      return out;
    }

    if (noSlash.startsWith('visibility2/')) {
      add(base + '/' + noSlash);
      return out;
    }

    add(base + withSlash);
    return out;
  }

  function groupPhotoRows(rows){
    const out = [];
    const groups = new Map();
    rows.forEach(r=>{
      if (r.tipo === 7){
        const key = (r.visita_id||'0') + '|' + (r.pregunta_id||'0') + '|' + (r.local_id||'0');
        let item = groups.get(key);
        if (!item){
          item = {...r, fotos: []};
          groups.set(key, item);
          out.push(item);
        }
        if (r.respuesta){
          item.fotos.push(r.respuesta);
        }
      } else {
        out.push(r);
      }
    });
    return out;
  }

  const lazyObserver = ('IntersectionObserver' in window)
    ? new IntersectionObserver(entries=>{
        entries.forEach(entry=>{
          if(entry.isIntersecting){
            const img = entry.target;
            const src = img.getAttribute('data-src');
            if (src){ img.src = src; img.removeAttribute('data-src'); }
            lazyObserver.unobserve(img);
          }
        });
      }, { rootMargin: '200px 0px' })
    : null;

  function renderThumbs(urls){
    if(!urls || !urls.length) return '';
    const badge = (urls.length>1) ? `<span class="thumb-count">${urls.length}</span>` : '';
    const imgs = urls.map(u => {
      const candidates = buildPhotoCandidates(u);
      if (!candidates.length) return '';
      const primary = candidates[0];
      const fallbacks = candidates.slice(1);
      return `<img class="thumb" data-src="${escapeHtml(primary)}" data-full="${escapeHtml(primary)}" data-fallbacks="${escapeHtml(JSON.stringify(fallbacks))}" alt="foto" loading="lazy">`;
    }).join('');
    return `<div class="thumb-wrap">${badge}${imgs}</div>`;
  }

  function populateSelect($sel, options, keepValue=true){
    const current = keepValue ? $sel.val() : null;
    $sel.empty().append('<option value="0">-- Todos --</option>');
    options.forEach(([id, name])=> $sel.append(`<option value="${id}">${escapeHtml(name)}</option>`));
    if (keepValue && current && $sel.find(`option[value="${current}"]`).length) $sel.val(current);
  }

  function buildPager(cur, per, total){
    const $p = $('#pager').empty();
    const pages = Math.max(1, Math.ceil(total/per));
    function li(n, label, disabled, active){
      return `<li class="page-item ${disabled?'disabled':''} ${active?'active':''}">
                <a class="page-link" href="#" data-p="${n}">${label}</a>
              </li>`;
    }
    $p.append(li(Math.max(1,cur-1),'Anterior', cur<=1, false));
    const start = Math.max(1, cur-2), end=Math.min(pages, cur+2);
    for(let i=start;i<=end;i++) $p.append(li(i, i, false, i===cur));
    $p.append(li(Math.min(pages,cur+1),'Siguiente', cur>=pages, false));
  }

  function validateDates() {
    const d = $('#f_desde').val();
    const h = $('#f_hasta').val();
    if (d && h && d > h) {
      showError('Rango inválido', 'La fecha "Desde" no puede ser mayor a "Hasta".');
      return false;
    }
    return true;
  }

  function loadData(){
    const p = buildParams();
    renderActiveFilters();
    $('#resultsTable tbody').html('<tr><td colspan="12" class="text-center text-muted">Cargando…</td></tr>');
    $('#infoTotal').text('');
    showLoading();

    if (currentXhr) {
      currentXhr.abort();
    }

    currentXhr = $.ajax({
      url: 'panel_encuesta_data.php',
      data: p,
      dataType: 'json',
      timeout: 60000, // 60s para controlar mejor los timeouts
      success: function(resp, textStatus, jqXHR){
        if (resp && resp.status && resp.status !== 'ok') {
          const msg = resp.message || 'Error al cargar resultados.';
          showError('No se pudo cargar', msg);
          $('#resultsTable tbody').html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
          hideLoading();
          return;
        }
        const tb = $('#resultsTable tbody').empty();
        let rows = resp && resp.data ? resp.data : [];
        rows = groupPhotoRows(rows);

        if(rows.length===0){
          tb.html('<tr><td colspan="12" class="text-center text-muted">Sin resultados</td></tr>');
        } else {
          const frag = document.createDocumentFragment();
          rows.forEach(r=>{
            const isFoto = r.tipo === 7;
            const respCell = isFoto
              ? (r.fotos && r.fotos.length ? renderThumbs(r.fotos) : (r.respuesta ? renderThumbs([r.respuesta]) : ''))
              : escapeHtml(r.respuesta || '');
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${r.fecha}</td>
              <td>${escapeHtml(r.campana)}</td>
              <td>${escapeHtml(r.pregunta)}</td>
              <td>${r.tipo_texto}</td>
              <td>${respCell}</td>
              <td>${r.valor!==null ? r.valor : ''}</td>
              <td>${escapeHtml(r.local_codigo||'')}</td>
              <td>${escapeHtml(r.local_nombre||'')}</td>
              <td>${escapeHtml(r.direccion||'')}</td>
              <td>${escapeHtml(r.cadena||'')}</td>
              <td>${escapeHtml(r.jefe_venta||'')}</td>
              <td>${escapeHtml(r.usuario||'')}</td>
            `;
            frag.appendChild(tr);
          });
          tb[0].appendChild(frag);

          noteThumbFallbacks();
          $('#resultsTable img.thumb').each(function(){
            if(lazyObserver) lazyObserver.observe(this);
            else {
              this.src = this.getAttribute('data-src');
              this.removeAttribute('data-src');
            }
          });
        }

        if (resp.facets) {
          populateSelect($('#f_usuario'),  resp.facets.usuarios.map(x=>[x.id, x.nombre]));
          populateSelect($('#f_jv'),       resp.facets.jefes.map(x=>[x.id, x.nombre]));
          populateSelect($('#f_distrito'), resp.facets.distritos.map(x=>[x.id, x.nombre]));
        } else {
          populateSelect($('#f_usuario'),  uniqueOptions(rows, 'usuario_id', 'usuario'));
          populateSelect($('#f_jv'),       uniqueOptions(rows, 'jefe_venta_id', 'jefe_venta'));
          populateSelect($('#f_distrito'), uniqueOptions(rows, 'distrito_id', 'distrito'));
        }

        buildPager(resp.page, resp.per_page, resp.total);

        const qtime = jqXHR.getResponseHeader('X-QueryTime-ms');
        const meta = resp && resp.meta ? resp.meta : {};
        const extras = [];

        if (qtime) extras.push(`${qtime} ms`);

        if (meta.truncated_total) {
          const maxRows = meta.max_total_rows || 30000;
          extras.push(`cortado a ${Number(maxRows).toLocaleString('es-CL')} registros`);
        }

        if (meta.default_range && Number(meta.default_range.applied || 0) === 1) {
          const days = meta.default_range.days || DEFAULT_RANGE_DAYS;
          extras.push(`rango automático últimos ${days} días`);
          if (!$('#f_desde').val() && !$('#f_hasta').val()) {
            setDefaultDates();
          }
        }

        if (resp.total > EXPORT_LIMITS.csv) {
          extras.push(`CSV limitado a ${EXPORT_LIMITS.csv.toLocaleString('es-CL')} filas`);
        }

        const infoBase = `Total: ${Number(resp.total).toLocaleString('es-CL')} registros`;
        const info = extras.length ? infoBase + ' · ' + extras.join(' · ') : infoBase;
        $('#infoTotal').text(info);

        const qAll = toQuery(p);
        history.replaceState(null, '', location.pathname + '?' + qAll);
      },
      error: function(xhr, textStatus){
        let msg;
        if (textStatus === 'timeout' || xhr.status === 504) {
          msg = 'El servidor demoró demasiado en responder (timeout). ' +
                'Prueba acotar el rango de fechas, filtrar por campaña específica, ' +
                'usar menos preguntas a la vez o bajar el número de registros por página.';
        } else if (xhr.status === 0) {
          msg = 'No se pudo contactar con el servidor. ' +
                'Puede ser un problema de red o que se haya perdido la conexión.';
        } else {
          msg = xhr.responseText || 'Error al cargar datos';
        }
        $('#resultsTable tbody').html('<tr><td colspan="12" class="text-center text-danger">'+escapeHtml(msg)+'</td></tr>');
        showError('No se pudo cargar datos', msg);
      },
      complete: function(){
        hideLoading();
        currentXhr = null;
      }
    });
  }

  $('#pager').on('click','a', function(e){
    e.preventDefault();
    const p = parseInt($(this).data('p'),10);
    if(!isNaN(p)){ page=p; loadData(); }
  });

  function parseFallbacks(el){
    const raw = el.getAttribute('data-fallbacks');
    if (!raw) return [];
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function applyNextFallback(img){
    const fallbacks = parseFallbacks(img);
    if (!fallbacks.length) return false;
    const idx = parseInt(img.getAttribute('data-fallback-idx') || '0', 10);
    if (idx >= fallbacks.length) return false;
    img.setAttribute('data-fallback-idx', String(idx + 1));
    img.src = fallbacks[idx];
    return true;
  }

  function noteThumbFallbacks(){
    $('#resultsTable img.thumb').each(function(){
      if (this.dataset.fallbackReady === '1') return;
      this.dataset.fallbackReady = '1';
      this.addEventListener('error', () => {
        applyNextFallback(this);
      });
    });
  }

  $('#panel-encuesta-filtros').on('submit', function(e){
    e.preventDefault();
    if (!validateDates()) return;
    page = 1;
    loadData();
  });

  $('#f_limit').on('change', function(){ page=1; loadData(); });
  $('#f_codigo').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); page=1; loadData(); } });

  $('#f_thumbsize').on('change', function(){
    const v = $(this).val();
    document.body.classList.remove('thumb-small','thumb-medium','thumb-large');
    document.body.classList.add('thumb-'+v);
  });

  // ====== Lightbox ======
  const LB = { list: [], idx: 0 };
  function openLightbox(items, start){
    LB.list = items || [];
    LB.idx = Math.max(0, Math.min(start||0, LB.list.length-1));
    updateLB();
    $('#photoModal').modal('show');
  }
  function updateLB(){
    if (!LB.list.length) return;
    const item = LB.list[LB.idx] || {};
    const img = $('#photoModalImg');
    img.attr('src', item.primary || '');
    img.attr('data-fallbacks', JSON.stringify(item.fallbacks || []));
    img.attr('data-fallback-idx', '0');
    $('#photoModalCaption').text((LB.idx+1)+' / '+LB.list.length);
    $('#photoModalOpen')
      .attr('href', item.primary || '')
      .toggleClass('d-none', !item.primary);
  }
  function lbPrev(){ if(LB.list.length){ LB.idx = (LB.idx-1+LB.list.length)%LB.list.length; updateLB(); } }
  function lbNext(){ if(LB.list.length){ LB.idx = (LB.idx+1)%LB.list.length; updateLB(); } }

  $('#photoModalImg').on('error', function(){
    const swapped = applyNextFallback(this);
    if (swapped) {
      $('#photoModalOpen').attr('href', this.src || '');
    }
  });

  $(document).on('click', '.thumb', function(){
    const $wrap = $(this).closest('.thumb-wrap');
    const items = $wrap.find('.thumb').map((i,el)=>{
      const fallbacks = parseFallbacks(el);
      return { primary: $(el).data('full'), fallbacks };
    }).get();
    const idx = $wrap.find('.thumb').index(this);
    openLightbox(items, idx);
  });
  $('#lbPrev').on('click', lbPrev);
  $('#lbNext').on('click', lbNext);
  $(document).on('keydown', function(e){
    if (!$('#photoModal').hasClass('show')) return;
    if (e.key === 'ArrowLeft') lbPrev();
    if (e.key === 'ArrowRight') lbNext();
    if (e.key === 'Escape') $('#photoModal').modal('hide');
  });

  // ========= PRESETS =========
  const PRESETS_KEY = 'panel_encuesta_qpresets';

  // Presets "de fábrica" para división Red Bull (14), usando id_question_set_question
  const FACTORY_PRESETS = isRedBull ? [
    {
      name: 'RB – Cooler principal',
      items: [
        { mode:'set', qset_id:363, label:'¿El cooler Red Bull se encuentra contaminado?' },
        { mode:'set', qset_id:366, label:'Toma una foto del cooler Red Bull' }
      ]
    },
    {
      name: 'RB – Energy Checkout',
      items: [
        { mode:'set', qset_id:392, label:'¿El local cuenta con Energy Checkout?' },
        { mode:'set', qset_id:393, label:'¿El Cooler del Energy Checkout se encuentra contaminado?' },
        { mode:'set', qset_id:395, label:'Toma una foto del Energy Checkout' }
      ]
    }
  ] : [];

  function listPresets(){
    try { return JSON.parse(localStorage.getItem(PRESETS_KEY) || '[]'); }
    catch(e){ return []; }
  }
  function savePresets(arr){
    localStorage.setItem(PRESETS_KEY, JSON.stringify(arr));
  }
  function buildPresetFromState(){
    const items = [];
    QFILTERS.forEach(({meta, values}) => { items.push({ meta, values }); });
    return {
      version: 1,
      created_at: new Date().toISOString(),
      form_id: $('#f_form').val(),
      items
    };
  }

  // Aplica tanto presets de usuario (con meta) como de fábrica (con qset_id)
  function applyPreset(preset){
    $('#f_preguntas').val(null).trigger('change');
    QFILTERS.clear();

    const promises = [];

    (preset.items || []).forEach(it => {
      // Caso 1: preset de usuario, ya trae meta completa
      if (it.meta && it.meta.id != null) {
        const meta = it.meta;
        const id   = meta.id;
        const mode = meta.mode || 'set';
        const optId = (mode === 'vset') ? ('v:'+id) : String(id);
        const label = meta.label || ('Pregunta '+id);

        const opt = new Option(label, optId, true, true);
        $('#f_preguntas').append(opt);

        const key = (mode==='set'?'set:':(mode==='vset'?'vset:':'exact:')) + id;
        QFILTERS.set(key, {
          meta: {
            id: meta.id,
            mode: mode,
            tipo: meta.tipo,
            tipo_texto: meta.tipo_texto,
            label: label,
            has_options: !!meta.has_options,
            options: meta.options || []
          },
          values: it.values || {}
        });
      } else if (it.qset_id != null) {
        // Caso 2: preset de fábrica (Red Bull), usar question_set_question (set)
        const mode   = it.mode || 'set';
        const qsetId = it.qset_id;
        const label  = it.label || ('Pregunta '+qsetId);
        const optId  = (mode === 'vset') ? ('v:'+qsetId) : String(qsetId);

        const opt = new Option(label, optId, true, true);
        $('#f_preguntas').append(opt);

        // fetch de metadatos para que el filtro se comporte igual que uno armado a mano
        const p = fetchPreguntaMeta(mode, qsetId).then(meta => {
          const keyMode = meta.mode || mode;
          const key = (keyMode==='set'?'set:':(keyMode==='vset'?'vset:':'exact:')) + meta.id;
          QFILTERS.set(key, {
            meta: {
              id: meta.id,
              mode: keyMode,
              tipo: meta.tipo,
              tipo_texto: meta.tipo_texto,
              label: label,
              has_options: !!meta.has_options,
              options: meta.options || []
            },
            values: it.values || {}
          });
        }).catch(()=>{/* silencioso */});
        promises.push(p);
      }
    });

    $('#f_preguntas').trigger('change');

    return Promise.all(promises).then(()=>{ syncQFiltersUI(); });
  }

  function refreshPresetsMenu(){
    const userPresets = listPresets();
    const $m = $('#presetsMenu').empty();

    const items = [];

    // Primero presets de fábrica (solo RB)
    if (isRedBull && FACTORY_PRESETS.length){
      FACTORY_PRESETS.forEach((p,idx)=>{
        items.push({
          kind: 'factory',
          idx,
          name: p.name || ('Preset RB '+(idx+1))
        });
      });
    }

    // Luego presets del usuario
    userPresets.forEach((p,idx)=>{
      items.push({
        kind: 'user',
        idx,
        name: p.name || ('Preset '+(idx+1))
      });
    });

    if (!items.length) {
      $m.append('<span class="dropdown-item-text text-muted">Sin presets aún</span>');
      return;
    }

    items.forEach(item=>{
      const $row = $('<div class="d-flex align-items-center px-3 py-1"></div>');
      const $link = $('<a href="#" class="mr-2 preset-load"></a>')
        .text(escapeHtml(item.name))
        .attr('data-kind', item.kind)
        .attr('data-idx', item.idx);
      $row.append($link);

      if (item.kind === 'user') {
        const $del = $('<a href="#" class="text-danger ml-auto preset-del" title="Eliminar"><i class="fa fa-times"></i></a>')
          .attr('data-idx', item.idx);
        $row.append($del);
      }
      $m.append($row);
    });
  }

  $(document).on('click', '.preset-load', function(e){
    e.preventDefault();
    const kind = $(this).data('kind');
    const idx  = parseInt($(this).data('idx'),10);

    if (kind === 'factory') {
      if (!isRedBull) return;
      const preset = FACTORY_PRESETS[idx];
      if (preset) applyPreset(preset);
    } else {
      const arr = listPresets();
      if (arr[idx]) applyPreset(arr[idx]);
    }
  });

  $(document).on('click', '.preset-del', function(e){
    e.preventDefault();
    const idx = parseInt($(this).data('idx'),10);
    const arr = listPresets();
    if (arr[idx]) { arr.splice(idx,1); savePresets(arr); refreshPresetsMenu(); }
  });

  $('#btnSavePreset').on('click', function(){
    const name = prompt('Nombre para el preset:');
    if (!name) return;
    const arr = listPresets();
    const preset = buildPresetFromState();
    preset.name = name;
    arr.push(preset);
    savePresets(arr);
    refreshPresetsMenu();
  });
  $('#btnClearPreset').on('click', function(){
    $('#f_preguntas').val(null).trigger('change');
    QFILTERS.clear();
    syncQFiltersUI();
  });

  // ========= AUTOFILL Red Bull (user 103) =========
  function hasURLOverrides(){
    return (location.search && location.search.length > 1);
  }
  function runRedBullAutofill(){
    return new Promise(resolve=>{
      const should =
        (USER_ID === 103) &&         // usuario específico
        isRedBull &&                 // división Red Bull (14)
        !hasURLOverrides() &&
        (QFILTERS.size === 0) &&
        (!($('#f_preguntas').val() || []).length);

      if (!should || !isRedBull || !FACTORY_PRESETS.length) {
        resolve(false);
        return;
      }

      // Ajustes recomendados para este preset
      $('#f_subdivision').val('1');
      $('#f_tipo').val('3');
      $('#f_form').val('0');

      // Esperamos a que el Select2 de preguntas esté listo y luego aplicamos
      $(document).one('preguntas-ready', function(){
        const preset = FACTORY_PRESETS[0]; // RB – Cooler principal
        if (!preset) { resolve(false); return; }
        applyPreset(preset).then(()=>resolve(true)).catch(()=>resolve(false));
      });

      loadCampanas(true);
    });
  }

  // ========= Export por POST =========
  function postExport(url, params) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.target = '_blank';

    Object.entries(params).forEach(([k,v])=>{
      if (Array.isArray(v)) {
        v.forEach(val=>{
          const input = document.createElement('input');
          input.type = 'hidden'; input.name = k+'[]'; input.value = String(val);
          form.appendChild(input);
        });
      } else {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = k; input.value = String(v);
        form.appendChild(input);
      }
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
  }
  function buildExportParams(){
    const p = buildParams();
    delete p.page;
    delete p.limit;
    return p;
  }

  $('#btnCSV').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando...');
    postExport('export_csv_panel_encuesta.php', buildExportParams());
    setTimeout(()=>{ $btn.prop('disabled', false).text($btn.data('txt')); }, 4000);
  });

  $('#btnFotosHTML').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando HTML...');

    const params = buildExportParams();
    params.output = 'html';

    postExport('export_pdf_panel_encuesta.php', params);

    setTimeout(()=>{ $btn.prop('disabled', false).text($btn.data('txt')); }, 4000);
  });

  $('#btnPDF').on('click', function(){
    const $btn = $(this);
    $btn.prop('disabled', true).data('txt', $btn.text()).text('Generando PDF...');

    const params = buildExportParams();
    params.output = 'pdf';

    postExport('export_pdf_panel_encuesta_fotos.php', params);

    setTimeout(()=>{ $btn.prop('disabled', false).text($btn.data('txt')); }, 6000);
  });

  // ========= Inicialización =========
  initPreguntaSelect2();
  refreshPresetsMenu();
  renderActiveFilters();
  runRedBullAutofill().finally(() => {
    // dejamos que el usuario presione Buscar manualmente
  });

})(jQuery);
</script>
</body>
</html>
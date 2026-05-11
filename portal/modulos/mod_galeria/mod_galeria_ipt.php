<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

$division_id       = (int)($_SESSION['division_id'] ?? 0);
$divisionLogin     = $division_id;
$division          = (int)($_GET['division'] ?? $division_id);
$subdivision       = (int)($_GET['subdivision'] ?? 0);
$region            = (int)($_GET['region'] ?? 0);
$zona              = (int)($_GET['zona'] ?? 0);
$distrito          = (int)($_GET['distrito'] ?? 0);
$comuna            = (int)($_GET['comuna'] ?? 0);
$usuarioFiltro     = (int)($_GET['usuario'] ?? 0);
$jefeVentaFiltro   = (int)($_GET['jefe_venta'] ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local'] ?? '');
$view              = in_array(trim($_GET['view'] ?? 'implementacion'), ['implementacion','encuesta'], true)
    ? trim($_GET['view'] ?? 'implementacion')
    : 'implementacion';

$preguntaFiltro    = trim($_GET['pregunta'] ?? '');
$start_date        = trim($_GET['start_date'] ?? '');
$end_date          = trim($_GET['end_date'] ?? '');
$filtrosAplicados  = isset($_GET['filtrar']) && $_GET['filtrar'] === '1';

$puedeVerFiltroPregunta = ($view === 'encuesta' && in_array($divisionLogin, [1, 14], true));

$divisiones = [];
$resDiv = $conn->query("
    SELECT id, nombre
    FROM division_empresa
    WHERE estado = 1
    ORDER BY nombre
");
if ($resDiv) {
    while ($r = $resDiv->fetch_assoc()) {
        $divisiones[] = $r;
    }
    $resDiv->close();
}

$jefesVenta = [];
$resJV = $conn->query("
    SELECT id, nombre
    FROM jefe_venta
    ORDER BY nombre ASC
");
if ($resJV) {
    while ($rowJV = $resJV->fetch_assoc()) {
        $jefesVenta[] = $rowJV;
    }
    $resJV->close();
}

$preguntasEncuesta = [];
if ($puedeVerFiltroPregunta) {
    $sqlPreg = "
        SELECT DISTINCT UPPER(TRIM(fq.question_text)) AS question_text
        FROM form_questions fq
        INNER JOIN formulario f   ON f.id  = fq.id_formulario
        INNER JOIN form_question_responses fqr ON fqr.id_form_question = fq.id
        WHERE fq.id_question_type = 7
          AND COALESCE(TRIM(fqr.answer_text), '') <> ''
          AND fq.deleted_at IS NULL
          AND f.deleted_at  IS NULL
    ";
    if ($division > 0) {
        $sqlPreg .= " AND f.id_division = ?";
    }
    $sqlPreg .= " ORDER BY question_text ASC";

    $stmtPreg = $conn->prepare($sqlPreg);
    if ($stmtPreg) {
        if ($division > 0) {
            $stmtPreg->bind_param("i", $division);
        }
        $stmtPreg->execute();
        $resPreg = $stmtPreg->get_result();
        while ($r = $resPreg->fetch_assoc()) {
            $preguntasEncuesta[] = $r['question_text'];
        }
        $stmtPreg->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Galería Campañas Programadas</title>
  <!--<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"> -->
      <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <link rel="stylesheet"
      href="/visibility2/portal/css/mod_galeria.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/css/mod_galeria.css') ?>">
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    
.select2-container .select2-selection--single {
    height: 35px!important;
}
    
</style>    
</head>
<body class="bg-light">
<div class="container mt-4">
    
<div class="gallery-shell">

  <div class="gallery-tabs-wrap">
    <ul class="nav nav-tabs gallery-tabs" id="tabsGaleria">
      <li class="nav-item">
        <a class="nav-link <?= $view==='implementacion'?'active':'' ?>"
           href="?<?= http_build_query(array_merge($_GET,['view'=>'implementacion','page'=>1])) ?>">
          Fotos Implementación
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= $view==='encuesta'?'active':'' ?>"
           href="?view=encuesta&page=1">
          Fotos Encuesta
        </a>
      </li>
    </ul>
  </div>

  <div class="gallery-filter-card">
    <div class="gallery-filter-head">
      <div class="gallery-filter-title-wrap">
        <div class="gallery-kicker">PHOTO MANAGEMENT</div>
        <h2 class="gallery-title">Gallery Implementation Explorer</h2>
      </div>

      <a href="?view=<?= urlencode($view) ?>" class="gallery-clear-link">
        Clear All Filters
      </a>
    </div>

    <div class="gallery-filter-subhead">
      <div class="gallery-filter-subtitle">
        <i class="fas fa-sliders-h mr-2"></i>Filter Workspace
      </div>
    </div>

    <form method="GET" id="formFiltrosGaleria" class="gallery-filter-form">
      <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
      <input type="hidden" name="filtrar" value="1">

      <div class="row">
        <?php if ($division_id === 1): ?>
          <div class="col-lg-3 col-md-6 mb-4">
            <label for="divisionSelect" class="gallery-label">División</label>
            <select id="divisionSelect" name="division" class="form-control gallery-control">
              <option value="0">-- Todas --</option>
              <?php foreach($divisiones as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $d['id']==$division?'selected':'' ?>>
                  <?= htmlspecialchars($d['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="division" value="<?= $division_id ?>">
        <?php endif; ?>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="subdivisionSelect" class="gallery-label">Subdivisión</label>
          <select id="subdivisionSelect" name="subdivision" class="form-control gallery-control">
            <option value="0">-- Todas --</option>
          </select>
        </div>

        <?php if ($divisionLogin != 14): ?>
          <div class="col-lg-3 col-md-6 mb-4">
            <label for="regionSelect" class="gallery-label">Región</label>
            <select id="regionSelect" name="region" class="form-control gallery-control">
              <option value="0">-- Todas --</option>
            </select>
          </div>

          <div class="col-lg-3 col-md-6 mb-4">
            <label for="comunaSelect" class="gallery-label">Comuna</label>
            <select id="comunaSelect" name="comuna" class="form-control gallery-control">
              <option value="0">-- Todas --</option>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="zonaSelect" class="gallery-label">Zona</label>
          <select id="zonaSelect" name="zona" class="form-control gallery-control">
            <option value="0">-- Todas --</option>
          </select>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="distritoSelect" class="gallery-label">Distrito</label>
          <select id="distritoSelect" name="distrito" class="form-control gallery-control">
            <option value="0">-- Todos --</option>
          </select>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
          <label class="gallery-label">Fecha inicio</label>
          <input type="date" name="start_date" class="form-control gallery-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
          <label class="gallery-label">Fecha fin</label>
          <input type="date" name="end_date" class="form-control gallery-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="codigoLocalInput" class="gallery-label">Código local</label>
          <input
            type="text"
            name="codigo_local"
            id="codigoLocalInput"
            class="form-control gallery-control"
            placeholder="Ej: 12345"
            value="<?= htmlspecialchars($codigoLocalFiltro ?? '') ?>"
          >
        </div>

        <?php if ($puedeVerFiltroPregunta): ?>
          <div class="col-lg-3 col-md-6 mb-4">
            <label for="preguntaSelect" class="gallery-label">Pregunta</label>
            <select id="preguntaSelect" name="pregunta" class="form-control gallery-control">
              <option value="">-- Todas --</option>
              <?php foreach ($preguntasEncuesta as $preg): ?>
                <option value="<?= htmlspecialchars($preg, ENT_QUOTES) ?>"
                  <?= $preguntaFiltro === $preg ? 'selected' : '' ?>>
                  <?= htmlspecialchars($preg, ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="usuarioSelect" class="gallery-label">Usuario</label>
          <select id="usuarioSelect" name="usuario" class="form-control gallery-control">
            <option value="0">-- Todos --</option>
          </select>
        </div>

        <div class="col-lg-3 col-md-6 mb-4">
          <label for="jefe_ventaSelect" class="gallery-label">Jefe de Venta</label>
          <select name="jefe_venta" id="jefe_ventaSelect" class="form-control gallery-control">
            <option value="0">-- Todos --</option>
            <?php foreach ($jefesVenta as $jv): ?>
              <option value="<?= $jv['id'] ?>" <?= $jefeVentaFiltro == $jv['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($jv['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-3 col-md-6 mb-4 d-flex align-items-end">
          <button type="submit" id="btnFiltrar" class="btn gallery-btn-primary btn-block">
            <i class="fas fa-search mr-2"></i>Apply Refined Search
          </button>
        </div>
      </div>
    </form>
  </div>

  <div class="gallery-stats-row">
    <div class="gallery-stat-pill">
      <i class="far fa-image mr-2"></i>
      <span id="galleryTotalLabel">Total Images</span>
    </div>
    <div class="gallery-stat-pill">
      <i class="far fa-check-circle mr-2"></i>
      <span id="gallerySelectedLabel">Selected: 0</span>
    </div>
    <div class="gallery-stat-pill">
      <i class="far fa-clock mr-2"></i>
      <span>AJAX Table Mode</span>
    </div>
  </div>

  <div class="gallery-actions-row">
    <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
      <input type="hidden" name="jsonFotos" id="jsonFotos">
    </form>

    <button id="btnDownloadSelected" class="btn gallery-btn-secondary">
      Descargar seleccionadas <i class="fas fa-download ml-2"></i>
    </button>
  </div>

  <div class="gallery-table-card">
    <div id="galeriaTableContainer">
      <div class="gallery-table-placeholder">
        <div class="spinner-border text-primary mb-2" role="status">
          <span class="sr-only">Cargando...</span>
        </div>
        <div class="text-muted">Aplica filtros para cargar resultados</div>
      </div>
    </div>
  </div>

</div>
</div>

<!-- Modal visor de fotos -->
<div class="modal fade" id="fullSizeModal" tabindex="-1" aria-labelledby="fullSizeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content bg-dark border-0">

      <div class="modal-header border-0">
        <h5 class="modal-title text-white" id="fullSizeModalLabel">Vista de fotos</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-0">
        <div id="carouselFotosModal" class="carousel slide" data-interval="false">
          <div class="carousel-inner" id="modalBodyImgs"></div>

          <a class="carousel-control-prev" href="#carouselFotosModal" role="button" data-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="sr-only">Anterior</span>
          </a>

          <a class="carousel-control-next" href="#carouselFotosModal" role="button" data-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="sr-only">Siguiente</span>
          </a>

          <ol class="carousel-indicators mb-2" id="carouselIndicatorsFotos"></ol>
        </div>
      </div>

      <div class="modal-footer border-0 justify-content-between">
        <small class="text-white-50" id="contadorFotosModal"></small>
        <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>

<div id="loadingOverlayGaleria">
  <div class="spinner-border text-primary" role="status">
    <span class="sr-only">Cargando...</span>
  </div>
  <div class="loading-text">Cargando filtros, por favor espera...</div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Bootstrap 4 desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script>
window.GALERIA_AJAX_URL = '/visibility2/portal/modulos/mod_galeria/ajax_galeria_table.php';
</script>

<script>
$(function () {

  $('#usuarioSelect').select2({
    placeholder: "Buscar usuario...",
    allowClear: true,
    width: '100%'
  });
  
  $('#jefe_ventaSelect').select2({
    placeholder: "Buscar jefe de venta...",
    allowClear: true,
    width: '100%'
  });

  $('#preguntaSelect').select2({
    placeholder: "Buscar pregunta...",
    allowClear: true,
    width: '100%'
  });

  <?php if ($puedeVerFiltroPregunta): ?>
  var _preguntaTimer = null;

  function actualizarFiltroPreguntas() {
    clearTimeout(_preguntaTimer);
    _preguntaTimer = setTimeout(function () {
      var params = {
        division:     parseInt($('#divisionSelect').val())     || 0,
        subdivision:  parseInt($('#subdivisionSelect').val())  || 0,
        region:       parseInt($('#regionSelect').val())       || 0,
        comuna:       parseInt($('#comunaSelect').val())       || 0,
        zona:         parseInt($('#zonaSelect').val())         || 0,
        distrito:     parseInt($('#distritoSelect').val())     || 0,
        usuario:      parseInt($('#usuarioSelect').val())      || 0,
        jefe_venta:   parseInt($('#jefe_ventaSelect').val())   || 0,
        codigo_local: $('#codigoLocalInput').val().trim(),
        start_date:   $('input[name="start_date"]').val()  || '',
        end_date:     $('input[name="end_date"]').val()    || ''
      };

      $.getJSON('/visibility2/portal/modulos/mod_galeria/ajax_preguntas_encuesta.php',
        params,
        function (preguntas) {
          var sel    = $('#preguntaSelect');
          var actual = sel.val();
          sel.empty().append('<option value="">-- Todas --</option>');
          preguntas.forEach(function (p) {
            sel.append($('<option>', { value: p, text: p }));
          });
          if (actual && preguntas.indexOf(actual) !== -1) {
            sel.val(actual);
          } else {
            sel.val('');
          }
          sel.trigger('change.select2');
        }
      );
    }, 400);
  }

  $('#divisionSelect, #subdivisionSelect, #regionSelect, #comunaSelect, ' +
    '#zonaSelect, #distritoSelect, #usuarioSelect, #jefe_ventaSelect')
    .on('change', actualizarFiltroPreguntas);

  $('#codigoLocalInput').on('input', actualizarFiltroPreguntas);
  $('input[name="start_date"], input[name="end_date"]').on('change', actualizarFiltroPreguntas);
  <?php endif; ?>

});
    
</script>
<script>
$(function () {
  $('#example').DataTable({
    paging: true,
    searching: true,
    ordering: true,
    info: true,
    autoWidth: false,
    pageLength: 50,
    lengthMenu: [[25, 50, 100], [25, 50, 100]],
    language: {
      url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json',
      emptyTable: 'Sin registros disponibles'
    },
    columnDefs: [
      { targets: [0, 1, 2], orderable: false },
      { targets: [0, 2], searchable: false }
    ]
  });
});
</script>
<script>
window.GALERIA_FILTROS = {
  division: <?= (int)$division ?>,
  usuario: <?= (int)$usuarioFiltro ?>,
  subdivision: <?= (int)$subdivision ?>,
  region: <?= (int)($_GET['region'] ?? 0) ?>,
  comuna: <?= (int)($_GET['comuna'] ?? 0) ?>,
  zona: <?= (int)($_GET['zona'] ?? 0) ?>,
  distrito: <?= (int)($_GET['distrito'] ?? 0) ?>
};
</script>

<script>
$(function () {

  /* ===============================
     Estado inicial desde PHP
  =============================== */
  const F = window.GALERIA_FILTROS || {
    division: 0,
    subdivision: 0,
    region: 0,
    comuna: 0,
    zona: 0,
    distrito: 0
  };

  /* ===============================
     DIVISION → USUARIO
  =============================== */

$(function () {

  const currentDivision = window.GALERIA_FILTROS?.division || 0;
  const currentUsuario  = window.GALERIA_FILTROS?.usuario || 0;

  function loadUsuarios(division, selected) {
    const $user = $('#usuarioSelect');
    $user.html('<option value="0">-- Todos --</option>');

    if (!division || division == 0) return;

    $.getJSON('/visibility2/portal/modulos/mod_galeria/ajax_usuarios.php',
      { division: division },
      function (data) {

        data.forEach(function (u) {
          $user.append(
            $('<option>', {
              value: u.id,
              text: u.nombre_completo,
              selected: u.id == selected
            })
          );
        });

      });
  }

  // Cuando cambia la división
  $('#divisionSelect').on('change', function () {
    loadUsuarios($(this).val(), 0);
  });

  // Carga inicial (cuando viene por GET)
  if (currentDivision > 0) {
    loadUsuarios(currentDivision, currentUsuario);
  }

});


  /* ===============================
     DIVISIÓN → SUBDIVISIÓN
  =============================== */
  function loadSubdivisions(division, selected) {
    const $sub = $('#subdivisionSelect');
    $sub.html('<option value="0">-- Todas --</option>');

    if (!division || division == 0) return;

    $.getJSON('ajax_subdivisiones.php', { division }, function (data) {
      data.forEach(s => {
        $sub.append(
          $('<option>', {
            value: s.id,
            text: s.nombre,
            selected: s.id == selected
          })
        );
      });
    });
  }

  $('#divisionSelect').on('change', function () {
    loadSubdivisions(this.value, 0);
  });

  if (F.division > 0) {
    $('#divisionSelect').val(F.division);
    loadSubdivisions(F.division, F.subdivision);
  }

  /* ===============================
     REGIÓN → COMUNA
  =============================== */
  function loadRegiones() {
    $.getJSON('ajax_regiones.php', function (data) {
      const $r = $('#regionSelect');
      $r.html('<option value="0">-- Todas --</option>');

      data.forEach(r => {
        $r.append(
          $('<option>', {
            value: r.id,
            text: r.nombre,
            selected: r.id == F.region
          })
        );
      });

      if (F.region > 0) {
        loadComunas(F.region, F.comuna);
      }
    });
  }

  function loadComunas(region, selected) {
    const $c = $('#comunaSelect');
    $c.html('<option value="0">-- Todas --</option>');

    if (!region || region == 0) return;

    $.getJSON('ajax_comunas.php', { region }, function (data) {
      data.forEach(c => {
        $c.append(
          $('<option>', {
            value: c.id,
            text: c.nombre,
            selected: c.id == selected
          })
        );
      });
    });
  }

  $('#regionSelect').on('change', function () {
    loadComunas(this.value, 0);
  });

  loadRegiones();

  /* ===============================
     ZONA → DISTRITO
  =============================== */
  function loadZonas() {
    $.getJSON('ajax_zonas.php', function (data) {
      const $z = $('#zonaSelect');
      $z.html('<option value="0">-- Todas --</option>');

      data.forEach(z => {
        $z.append(
          $('<option>', {
            value: z.id,
            text: z.nombre,
            selected: z.id == F.zona
          })
        );
      });

      if (F.zona > 0) {
        loadDistritos(F.zona, F.distrito);
      }
    });
  }

  function loadDistritos(zona, selected) {
    const $d = $('#distritoSelect');
    $d.html('<option value="0">-- Todos --</option>');

    if (!zona || zona == 0) return;

    $.getJSON('ajax_distritos.php', { zona }, function (data) {
      data.forEach(d => {
        $d.append(
          $('<option>', {
            value: d.id,
            text: d.nombre,
            selected: d.id == selected
          })
        );
      });
    });
  }

  $('#zonaSelect').on('change', function () {
    loadDistritos(this.value, 0);
  });

  loadZonas();

});
</script>

<script>
$(function () {
    let galeriaRequest = null;
    const MAX_REINTENTOS = 2;

    function mostrarCarga() {
        $('#loadingOverlayGaleria').css('display', 'flex');
        $('#btnFiltrar')
            .prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Cargando...');
    }

    function ocultarCarga() {
        $('#loadingOverlayGaleria').hide();
        $('#btnFiltrar')
            .prop('disabled', false)
            .html('<i class="fas fa-filter"></i> Aplicar filtros');
    }

    function destruirTabla() {
        if ($.fn.DataTable.isDataTable('#example')) {
            $('#example').DataTable().destroy();
        }
    }

    function initTabla() {
        if (!$('#example').length) return;

        $('#example').DataTable({
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            autoWidth: false,
            pageLength: 50,
            lengthMenu: [[25, 50, 100], [25, 50, 100]],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json',
                emptyTable: 'Sin registros disponibles'
            },
            columnDefs: [
                { targets: [0, 1, 2], orderable: false },
                { targets: [0, 2], searchable: false }
            ],
            drawCallback: function () {
                const api = this.api();
                api.column(1, { page: 'current' }).nodes().each(function (cell, i) {
                    cell.innerHTML = api.page.info().start + i + 1;
                });
            }
        });
    }

    function cargarTablaGaleria(reintento = 0) {
        if (galeriaRequest) {
            galeriaRequest.abort();
        }

        mostrarCarga();

        galeriaRequest = $.ajax({
            url: window.GALERIA_AJAX_URL,
            type: 'GET',
            data: $('#formFiltrosGaleria').serialize() + '&_ts=' + Date.now(),
            cache: false,
            timeout: 30000
        })
        .done(function (html) {
            destruirTabla();
            $('#galeriaTableContainer').html(html);
            initTabla();
        })
        .fail(function (xhr, textStatus) {
            const esRecuperable =
                textStatus === 'timeout' ||
                textStatus === 'error' ||
                xhr.status === 0 ||
                xhr.status >= 500;

            if (esRecuperable && reintento < MAX_REINTENTOS) {
                setTimeout(function () {
                    cargarTablaGaleria(reintento + 1);
                }, 1200);
                return;
            }

            destruirTabla();
            $('#galeriaTableContainer').html(
                '<div class="alert alert-danger mb-0">No se pudo cargar la tabla. Intenta nuevamente.</div>'
            );
        })
        .always(function () {
            ocultarCarga();
        });
    }

    $('#formFiltrosGaleria').on('submit', function (e) {
        e.preventDefault();
        cargarTablaGaleria();
    });
});
</script>

<script>
$(document).ready(function () {
  $(document).on('click', '.img-click', function () {
    const urlsRaw = $(this).attr('data-urls') || '';
    const local = $(this).attr('data-local') || 'Vista de fotos';

    const fotos = urlsRaw
      .split('||')
      .map(u => u.trim())
      .filter(u => u !== '');

    abrirModalFotos(fotos, local);
  });

  function abrirModalFotos(fotos, titulo) {
    const $contenedor = $('#modalBodyImgs');
    const $indicadores = $('#carouselIndicatorsFotos');
    const $contador = $('#contadorFotosModal');
    const $titulo = $('#fullSizeModalLabel');
    const $prev = $('#carouselFotosModal .carousel-control-prev');
    const $next = $('#carouselFotosModal .carousel-control-next');

    $contenedor.html('');
    $indicadores.html('');
    $titulo.text(titulo || 'Vista de fotos');

    if (!Array.isArray(fotos) || fotos.length === 0) {
      $contenedor.html(`
        <div class="carousel-item active">
          <div class="d-flex h-100 w-100 align-items-center justify-content-center text-white">
            No hay fotos disponibles
          </div>
        </div>
      `);
      $contador.text('');
      $prev.hide();
      $next.hide();
      $indicadores.hide();
      $('#fullSizeModal').modal('show');
      return;
    }

    fotos.forEach(function (foto, index) {
      $contenedor.append(`
        <div class="carousel-item ${index === 0 ? 'active' : ''}">
          <img src="${foto}" class="d-block" alt="Foto ${index + 1}">
        </div>
      `);

      $indicadores.append(`
        <li data-target="#carouselFotosModal"
            data-slide-to="${index}"
            class="${index === 0 ? 'active' : ''}"></li>
      `);
    });

    if (fotos.length > 1) {
      $prev.show();
      $next.show();
      $indicadores.show();
    } else {
      $prev.hide();
      $next.hide();
      $indicadores.hide();
    }

    $contador.text(`1 de ${fotos.length}`);

    $('#carouselFotosModal').carousel(0);

    $('#carouselFotosModal')
      .off('slid.bs.carousel')
      .on('slid.bs.carousel', function () {
        const index = $('#carouselFotosModal .carousel-item.active').index() + 1;
        $contador.text(`${index} de ${fotos.length}`);
      });

    $('#fullSizeModal').modal('show');
  }

  $('#fullSizeModal').on('hidden.bs.modal', function () {
    $('#modalBodyImgs').html('');
    $('#carouselIndicatorsFotos').html('');
    $('#contadorFotosModal').text('');
    $('#fullSizeModalLabel').text('Vista de fotos');
  });
});
</script>


</body>
</html>

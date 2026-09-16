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

// Scoping por división: solo MC (división 1) puede consultar otras divisiones;
// el resto queda fijo a la división de su sesión aunque manipulen el GET.
if ($divisionLogin !== 1 && $divisionLogin > 0) {
    $division = $divisionLogin;
}
$subdivision       = (int)($_GET['subdivision'] ?? 0);
$region            = (int)($_GET['region'] ?? 0);
$zona              = (int)($_GET['zona'] ?? 0);
$distrito          = (int)($_GET['distrito'] ?? 0);
$comuna            = (int)($_GET['comuna'] ?? 0);
$usuarioFiltro     = (int)($_GET['usuario'] ?? 0);
$jefeVentaFiltro   = (int)($_GET['jefe_venta'] ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local'] ?? '');
$view              = in_array(trim($_GET['view'] ?? 'implementacion'), ['implementacion','encuesta','duplicados'], true)
    ? trim($_GET['view'] ?? 'implementacion')
    : 'implementacion';

$preguntaFiltro    = trim($_GET['pregunta'] ?? '');
$materialFiltro    = trim($_GET['material'] ?? '');
$start_date        = trim($_GET['start_date'] ?? '');
$end_date          = trim($_GET['end_date'] ?? '');
$filtrosAplicados  = isset($_GET['filtrar']) && $_GET['filtrar'] === '1';

$puedeVerFiltroPregunta = ($view === 'encuesta' && in_array($divisionLogin, [1, 14], true));
// El material sólo aplica a fotos de implementación: en encuesta las fotos cuelgan de una
// pregunta, no de un material.
$puedeVerFiltroMaterial = ($view === 'implementacion');

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
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
body.gallery-ipt {
  --gallery-ink: #1e3c6b;
  --gallery-muted: #647c9f;
  --gallery-blue: #456edc;
  --gallery-border: #d4e1fa;
  --gallery-shadow: 0 14px 36px rgba(37, 72, 133, .08);
  background: linear-gradient(180deg, #edf4ff 0, #f5f8fd 480px, #fff 100%);
  color: var(--gallery-ink);
  color-scheme: light;
  min-height: 100vh;
  font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.gallery-ipt > .gallery-page { width: 100%; max-width: 1480px !important; margin: 0 auto; padding: 28px; }
.gallery-ipt .gallery-shell { background: transparent; }
.gallery-ipt .gallery-tabs-wrap { margin-bottom: 24px; }
.gallery-ipt .gallery-tabs { border: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.gallery-ipt .gallery-tabs .nav-link {
  border: 1px solid var(--gallery-border); border-radius: 13px; padding: 12px 18px;
  background: #fff; color: var(--gallery-muted); font-size: 12px; font-weight: 600;
}
.gallery-ipt .gallery-tabs .nav-link.active { background: var(--gallery-blue); color: #fff; border-color: var(--gallery-blue); box-shadow: 0 8px 20px rgba(71,112,223,.16); }
.gallery-ipt .gallery-tabs .nav-link:not(.active):hover { background: #edf4ff; color: var(--gallery-ink); }
.gallery-ipt .gallery-filter-card, .gallery-ipt .gallery-table-card {
  background: #fff; border: 1px solid var(--gallery-border); border-radius: 24px;
  box-shadow: var(--gallery-shadow); padding: 26px;
}
.gallery-ipt .gallery-filter-card { max-width: 1280px; margin: 0 auto 24px; }
.gallery-ipt .gallery-filter-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
.gallery-ipt .gallery-kicker { color: var(--gallery-blue); font-size: 10px; font-weight: 700; letter-spacing: 1px; margin-bottom: 7px; }
.gallery-ipt .gallery-title { color: var(--gallery-ink); font-size: 25px; font-weight: 700; letter-spacing: -.4px; margin: 0; }
.gallery-ipt .gallery-clear-link { color: var(--gallery-blue); font-size: 12px; }
.gallery-ipt .gallery-filter-subhead { margin-bottom: 18px; }
.gallery-ipt .gallery-filter-subtitle { color: var(--gallery-muted); font-size: 12px; font-weight: 600; }
.gallery-ipt .gallery-label { display: block; color: var(--gallery-ink); font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; }
.gallery-ipt .gallery-control, .gallery-ipt .dataTables_wrapper input:not([type="checkbox"]):not([type="radio"]), .gallery-ipt .dataTables_wrapper select {
  height: 44px; background: #fff; border: 1px solid #c8d9f8; border-radius: 13px;
  color: var(--gallery-ink); padding: 8px 12px; font-size: 12px;
}
.gallery-ipt .gallery-control:focus, .gallery-ipt .dataTables_wrapper input:not([type="checkbox"]):not([type="radio"]):focus, .gallery-ipt .dataTables_wrapper select:focus {
  border-color: #5e87ec; outline: none; box-shadow: 0 0 0 .2rem rgba(78,123,238,.13);
}
.gallery-ipt .gallery-control:disabled { background: #f2f5fa; color: #7687a0; border-color: #e0e7f2; }
.gallery-ipt .select2-container { max-width: 100%; }
.gallery-ipt .select2-container .select2-selection--single { height: 44px !important; border: 1px solid #c8d9f8; border-radius: 13px; background: #fff; }
.gallery-ipt .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 42px; padding-left: 12px; padding-right: 30px; font-size: 12px; color: var(--gallery-ink); }
.gallery-ipt .select2-container--default .select2-selection--single .select2-selection__arrow { height: 42px; right: 7px; }
.gallery-ipt .select2-container--default .select2-selection__placeholder { color: var(--gallery-muted); }
.gallery-ipt .select2-container--open .select2-selection, .gallery-ipt .select2-container--focus .select2-selection { border-color: #5e87ec; box-shadow: 0 0 0 .2rem rgba(78,123,238,.13); }
.gallery-ipt .select2-dropdown { border: 1px solid var(--gallery-border); border-radius: 12px; overflow: hidden; font-size: 12px; box-shadow: var(--gallery-shadow); }
.gallery-ipt .select2-search__field { border: 1px solid #c8d9f8 !important; border-radius: 8px; min-height: 36px; }
.gallery-ipt .select2-container--default .select2-results__option--highlighted[aria-selected] { background: var(--gallery-blue); }
.gallery-ipt .btn { border-radius: 12px; padding: 10px 14px; font-size: 12px; font-weight: 600; }
.gallery-ipt .gallery-btn-primary { min-height: 44px; color: #fff; background: linear-gradient(110deg, #4775e3, #456ad8); border: 1px solid #456edc; box-shadow: 0 8px 20px rgba(71,112,223,.16); }
.gallery-ipt .gallery-btn-primary:hover { background: #365bc1; color: #fff; }
.gallery-ipt .gallery-btn-secondary { background: #fff; color: var(--gallery-blue); border: 1px solid #c8d9f8; }
.gallery-ipt .gallery-btn-secondary:hover { background: #edf4ff; border-color: #9bb7f3; }
.gallery-ipt .btn:focus-visible, .gallery-ipt .nav-link:focus-visible, .gallery-ipt .gallery-clear-link:focus-visible { outline: 3px solid #a9c1fc; outline-offset: 3px; }
.gallery-ipt .gallery-stats-row, .gallery-ipt .gallery-actions-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
.gallery-ipt .gallery-actions-row { justify-content: flex-end; }
.gallery-ipt .gallery-actions-row .btn { margin: 0 !important; }
.gallery-ipt .gallery-stat-pill { background: #fff; color: var(--gallery-muted); border: 1px solid var(--gallery-border); border-radius: 999px; padding: 9px 14px; font-size: 12px; }
.gallery-ipt .gallery-stat-pill i { color: var(--gallery-blue); }
.gallery-ipt .gallery-table-placeholder { padding: 42px 16px; text-align: center; color: var(--gallery-muted); }
.gallery-ipt .table-responsive { margin-top: 0; border: 1px solid var(--gallery-border); border-radius: 16px; }
.gallery-ipt #galeriaTableContainer { min-width: 0; overflow-x: auto; }
.gallery-ipt .table { color: var(--gallery-ink); border-color: #e5ecf8; margin-bottom: 0; }
.gallery-ipt #example thead th, .gallery-ipt .js-dup-table thead th { background: #f0f5ff; color: #365782; border-color: var(--gallery-border); padding: 15px 12px; font-size: 11px; font-weight: 700; }
.gallery-ipt .table tbody td { background: #fff; border-color: #e5ecf8; padding: 14px 12px; font-size: 12px; vertical-align: middle; }
.gallery-ipt .table-striped tbody tr:nth-of-type(odd) td { background: #f9fbff; }
.gallery-ipt .table-hover tbody tr:hover td { background: #edf4ff; }
.gallery-ipt .dataTables_wrapper { color: var(--gallery-muted); font-size: 12px; }
.gallery-ipt .dataTables_wrapper > .row { margin-left: 0; margin-right: 0; padding: 10px 0; }
.gallery-ipt .page-link { color: var(--gallery-blue); border-color: var(--gallery-border); border-radius: 9px; }
.gallery-ipt .page-item.active .page-link { background: #edf4ff; color: var(--gallery-blue); border-color: #9bb7f3; }
.gallery-ipt .thumbnail { border-radius: 12px; }
.gallery-ipt input[type="checkbox"] { width: 16px; height: 16px; padding: 0; accent-color: #456edc; vertical-align: middle; }
.gallery-ipt #fullSizeModal .modal-content { background: #fff; color: var(--gallery-ink); border: 1px solid var(--gallery-border); border-radius: 22px; overflow: hidden; box-shadow: 0 24px 70px rgba(30,60,107,.18); }
.gallery-ipt #fullSizeModal .modal-header { background: #f2f6ff; padding: 20px 24px; }
.gallery-ipt #fullSizeModal .modal-title { font-size: 20px; font-weight: 700; }
.gallery-ipt #fullSizeModal .close { color: var(--gallery-muted); opacity: 1; text-shadow: none; }
.gallery-ipt #fullSizeModal .modal-footer { padding: 16px 24px; }
.gallery-ipt #carouselFotosModal { background: #f0f5fa; }
.gallery-ipt #carouselFotosModal .carousel-item { height: min(65vh, 680px); }
.gallery-ipt #carouselFotosModal img { width: 100%; height: 100%; object-fit: contain; }
.gallery-ipt #carouselFotosModal .carousel-control-prev-icon, .gallery-ipt #carouselFotosModal .carousel-control-next-icon { background-color: #365bc1; background-size: 55%; border-radius: 50%; width: 38px; height: 38px; }
.gallery-ipt #carouselFotosModal .carousel-indicators li { background-color: #456edc; }
.gallery-ipt #contadorFotosModal { color: var(--gallery-muted); }
.gallery-ipt #loadingOverlayGaleria {
  display: none; position: fixed; top: auto; right: auto; bottom: 24px; left: 50%; transform: translateX(-50%);
  width: min(460px, calc(100% - 32px)); height: auto; z-index: 1040;
  flex-direction: row; align-items: center; gap: 16px; padding: 22px 24px;
  border: 1px solid #b7ccfa; border-radius: 20px; background: #fff; box-shadow: 0 14px 50px rgba(30,60,107,.22);
}
.gallery-ipt #loadingOverlayGaleria .spinner-border { color: var(--gallery-blue) !important; flex-shrink: 0; }
.gallery-ipt #loadingOverlayGaleria .loading-text { color: var(--gallery-ink); font-size: 13px; font-weight: 600; }
@media (max-width: 767px) {
  .gallery-ipt > .gallery-page { padding: 18px 12px; }
  .gallery-ipt .gallery-filter-card, .gallery-ipt .gallery-table-card { padding: 20px 16px; border-radius: 20px; }
  .gallery-ipt .gallery-title { font-size: 21px; }
  .gallery-ipt .gallery-tabs .nav-item { flex: 1 1 100%; }
  .gallery-ipt .gallery-tabs .nav-link { text-align: center; }
  .gallery-ipt .gallery-actions-row .btn { width: 100%; }
  .gallery-ipt .gallery-stat-pill { font-size: 11px; }
  .gallery-ipt #fullSizeModal .modal-header, .gallery-ipt #fullSizeModal .modal-footer { padding: 16px; }
  .gallery-ipt #fullSizeModal .modal-title { font-size: 17px; }
}
@media (prefers-reduced-motion: reduce) { .gallery-ipt .spinner-border { animation: none; } }
</style>    
</head>
<body class="gallery-ipt">
<div class="container gallery-page">
    
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
      <li class="nav-item">
        <a class="nav-link <?= $view==='duplicados'?'active':'' ?>"
           href="?view=duplicados&page=1"
           style="<?= $view==='duplicados' ? '' : 'color:#c0392b;' ?>">
          Duplicadas / Sospechosas
        </a>
      </li>
    </ul>
  </div>

  <div class="gallery-filter-card">
    <div class="gallery-filter-head">
      <div class="gallery-filter-title-wrap">
        <div class="gallery-kicker">GESTIÓN FOTOGRÁFICA</div>
        <h2 class="gallery-title"><?= $view === 'encuesta' ? 'Explorador de Fotos de Encuesta' : ($view === 'duplicados' ? 'Fotos Duplicadas y Sospechosas' : 'Explorador de Fotos de Implementación') ?></h2>
      </div>

      <a href="?view=<?= urlencode($view) ?>" class="gallery-clear-link">
        Limpiar todos los filtros
      </a>
    </div>

    <div class="gallery-filter-subhead">
      <div class="gallery-filter-subtitle">
        <i class="fas fa-sliders-h mr-2"></i>Área de filtros
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

        <?php if ($puedeVerFiltroMaterial): ?>
          <div class="col-lg-3 col-md-6 mb-4">
            <label for="materialSelect" class="gallery-label">Material</label>
            <select id="materialSelect" name="material" class="form-control gallery-control">
              <option value="">-- Todos --</option>
              <?php if ($materialFiltro !== ''): ?>
                <?php /* Se preselecciona el valor del GET; el AJAX repuebla la lista al cargar. */ ?>
                <option value="<?= htmlspecialchars($materialFiltro, ENT_QUOTES) ?>" selected>
                  <?= htmlspecialchars($materialFiltro, ENT_QUOTES) ?>
                </option>
              <?php endif; ?>
            </select>
          </div>
        <?php endif; ?>

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
            <i class="fas fa-search mr-2"></i>Aplicar filtros
          </button>
        </div>
      </div>
    </form>
  </div>

  <div class="gallery-stats-row">
    <div class="gallery-stat-pill">
      <i class="far fa-image mr-2"></i>
      <span id="galleryTotalLabel">Total de imágenes</span>
    </div>
    <div class="gallery-stat-pill">
      <i class="far fa-check-circle mr-2"></i>
      <span id="gallerySelectedLabel">Seleccionadas: 0</span>
    </div>
    <div class="gallery-stat-pill">
      <i class="far fa-clock mr-2"></i>
      <span>Tabla dinámica</span>
    </div>
  </div>

  <?php if ($view !== 'duplicados'): // la vista de duplicadas no tiene selección/descarga ?>
  <div class="gallery-actions-row">
    <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
      <input type="hidden" name="jsonFotos" id="jsonFotos">
    </form>

    <button id="btnDownloadSelected" class="btn gallery-btn-secondary">
      Descargar seleccionadas <i class="fas fa-download ml-2"></i>
    </button>

    <button id="btnDownloadAllFiltered" type="button" class="btn gallery-btn-primary ml-2">
      Descargar todas las filtradas <i class="fas fa-cloud-download-alt ml-2"></i>
    </button>

    <iframe id="downloadAllFrame" name="downloadAllFrame" title="Descarga de fotos" style="display:none"></iframe>
  </div>
  <?php endif; ?>

  <div class="gallery-table-card">
    <div id="galeriaTableContainer">
      <div class="gallery-table-placeholder">
        <i class="far fa-images mb-3" aria-hidden="true"></i>
        <div class="text-muted">Aplica filtros para cargar resultados</div>
      </div>
    </div>
  </div>

</div>
</div>

<!-- Modal visor de fotos -->
<div class="modal fade" id="fullSizeModal" tabindex="-1" aria-labelledby="fullSizeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">

      <div class="modal-header border-0">
        <h5 class="modal-title" id="fullSizeModalLabel">Vista de fotos</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
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
        <small id="contadorFotosModal"></small>
        <button type="button" class="btn btn-light" data-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>

<div id="loadingOverlayGaleria" role="status" aria-live="polite" aria-atomic="true">
  <div class="spinner-border text-primary" role="status">
    <span class="sr-only">Cargando...</span>
  </div>
  <div class="loading-text">Cargando galería… Estamos preparando las fotos y los resultados.</div>
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

  <?php if ($puedeVerFiltroMaterial): ?>
  /* ===============================
     MATERIAL (vista implementación)
     Lista encadenada a los demás filtros, igual que el filtro Pregunta de la vista encuesta.
     Cada opción muestra la división a la que pertenece el material: hay nombres repetidos
     entre divisiones (AFICHE existe en 6), así que sin eso no se pueden distinguir.
  =============================== */
  $('#materialSelect').select2({
    placeholder: "Buscar material...",
    allowClear: true,
    width: '100%'
  });

  var _materialTimer = null;

  function actualizarFiltroMateriales() {
    clearTimeout(_materialTimer);
    _materialTimer = setTimeout(function () {
      // El <select> de división sólo existe para MC; para el resto la división va en un hidden.
      // Ojo: no se puede usar `parseInt(...) || fallback` porque "0" (= Todas) es falsy y
      // terminaría reemplazado por la división de la URL.
      var $divSel = $('#divisionSelect');
      var divisionVal = $divSel.length
        ? (parseInt($divSel.val(), 10) || 0)
        : <?= (int)$division ?>;

      var params = {
        division:     divisionVal,
        subdivision:  parseInt($('#subdivisionSelect').val())  || 0,
        region:       parseInt($('#regionSelect').val())       || 0,
        comuna:       parseInt($('#comunaSelect').val())       || 0,
        zona:         parseInt($('#zonaSelect').val())         || 0,
        distrito:     parseInt($('#distritoSelect').val())     || 0,
        usuario:      parseInt($('#usuarioSelect').val())      || 0,
        jefe_venta:   parseInt($('#jefe_ventaSelect').val())   || 0,
        codigo_local: ($('#codigoLocalInput').val() || '').trim(),
        start_date:   $('input[name="start_date"]').val() || '',
        end_date:     $('input[name="end_date"]').val()   || ''
      };

      $.getJSON('/visibility2/portal/modulos/mod_galeria/ajax_materiales_galeria.php',
        params,
        function (materiales) {
          var sel    = $('#materialSelect');
          var actual = sel.val();
          sel.empty().append('<option value="">-- Todos --</option>');

          materiales.forEach(function (m) {
            var etiqueta = m.material;
            if (m.divisiones) etiqueta += '  ·  ' + m.divisiones;
            sel.append($('<option>', { value: m.material, text: etiqueta }));
          });

          // Conservar la selección si el material sigue existiendo con los filtros nuevos.
          var sigue = materiales.some(function (m) { return m.material === actual; });
          sel.val(sigue ? actual : '');
          sel.trigger('change.select2');
        }
      );
    }, 400);
  }

  $('#divisionSelect, #subdivisionSelect, #regionSelect, #comunaSelect, ' +
    '#zonaSelect, #distritoSelect, #usuarioSelect, #jefe_ventaSelect')
    .on('change', actualizarFiltroMateriales);

  $('#codigoLocalInput').on('input', actualizarFiltroMateriales);
  $('input[name="start_date"], input[name="end_date"]').on('change', actualizarFiltroMateriales);

  actualizarFiltroMateriales();   // carga inicial
  <?php endif; ?>

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
        $('.js-dup-table').each(function () {
            if ($.fn.DataTable.isDataTable(this)) {
                $(this).DataTable().destroy();
            }
        });
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

    // Tablas de la vista "Duplicadas / Sospechosas" (dos tablas por respuesta)
    function initDupTablas() {
        $('.js-dup-table').each(function () {
            $(this).DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                autoWidth: false,
                pageLength: 25,
                lengthMenu: [[25, 50, 100], [25, 50, 100]],
                order: [],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-ES.json',
                    emptyTable: 'Sin registros disponibles'
                },
                columnDefs: [
                    { targets: 'no-sort', orderable: false, searchable: false }
                ]
            });
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
            initDupTablas();
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

    <?php if ($view === 'duplicados'): ?>
    // La vista de duplicadas carga de inmediato (sin exigir aplicar filtros)
    cargarTablaGaleria();
    <?php endif; ?>
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
          <div class="d-flex h-100 w-100 align-items-center justify-content-center text-muted">
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

<script>
// La tabla se carga por AJAX, por eso los eventos se delegan en document.
$(document).on('change', '#selectAll', function () {
  $('.imgCheckbox').prop('checked', this.checked).trigger('change');
});

$(document).on('change', '.imgCheckbox', function () {
  const total = $('.imgCheckbox').length;
  const selected = $('.imgCheckbox:checked').length;
  $('#selectAll').prop('checked', total > 0 && total === selected);
  $('#gallerySelectedLabel').text(`Seleccionadas: ${selected}`);
});

$(document).on('click', '#btnDownloadSelected', function (event) {
  event.preventDefault();

  const fotos = [];
  $('.imgCheckbox:checked').each(function () {
    const urls = String($(this).data('urls') || '').split('||');
    const prefix = String($(this).data('prefix') || 'foto');

    urls.forEach(function (url) {
      if (!url) return;
      fotos.push({
        url: url,
        filename: `${prefix}_${url.split('/').pop() || 'foto.jpg'}`
      });
    });
  });

  if (fotos.length === 0) {
    alert('Selecciona al menos una foto.');
    return;
  }

  $.ajax({
    url: 'download_zip.php',
    method: 'POST',
    data: { jsonFotos: JSON.stringify(fotos) },
    xhrFields: { responseType: 'blob' }
  }).done(function (data, _status, xhr) {
    const disposition = xhr.getResponseHeader('Content-Disposition') || '';
    const match = disposition.match(/filename[^;=\n]*=\s*(['"]?)([^'"\n]*)/);
    const blob = new Blob([data], { type: 'application/zip' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = match && match[2] ? match[2] : 'fotos_seleccionadas.zip';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
  }).fail(function () {
    alert('No fue posible crear el archivo ZIP.');
  });
});

// Esta descarga se resuelve en el servidor usando los filtros del formulario.
// Así no queda limitada a las filas (máximo 100) que DataTables muestra en pantalla.
$(document).on('click', '#btnDownloadAllFiltered', function (event) {
  event.preventDefault();

  const form = document.getElementById('formFiltrosGaleria');
  if (!form) return;

  const params = new URLSearchParams(new FormData(form));
  params.set('action', 'all_ipt');

  const button = this;
  const originalHtml = button.innerHTML;
  button.disabled = true;
  button.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> Preparando ZIP...';

  document.getElementById('downloadAllFrame').src = 'download_zip_ipt.php?' + params.toString();

  // La descarga puede continuar en el iframe aun después de reactivar el botón.
  window.setTimeout(function () {
    button.disabled = false;
    button.innerHTML = originalHtml;
  }, 5000);
});
</script>


</body>
</html>

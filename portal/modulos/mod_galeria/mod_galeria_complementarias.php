<?php
session_start();

/* =========================================
 * Utilidades
 * ======================================= */
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $k => $v) $refs[$k] = &$arr[$k];
        return $refs;
    }
    return $arr;
}

/* =========================================
 * Includes / seguridad
 * ======================================= */
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("<div class='alert alert-danger'>ID de campaña inválido.</div>");
}

$formulario_id = (int)$_GET['id'];

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
    die("<div class='alert alert-danger'>Acceso inválido (empresa).</div>");
}

$stmt = $conn->prepare("
    SELECT 
        tipo,
        COALESCE(nombre, CONCAT('Campaña #', id)) AS campanaNombre,
        COALESCE(iw_requiere_local, 0) AS requiereLocal
    FROM formulario
    WHERE id = ? AND id_empresa = ?
    LIMIT 1
");
$stmt->bind_param("ii", $formulario_id, $empresa_id);
$stmt->execute();
$stmt->bind_result($tipoForm, $campanaNombre, $requiereLocal);

if (!$stmt->fetch()) {
    die("<div class='alert alert-danger'>Formulario no encontrado o no pertenece a tu empresa.</div>");
}
$stmt->close();

$requiereLocal = ((int)$requiereLocal === 1);

if ((int)$tipoForm !== 2) {
    die("<div class='alert alert-warning'>Este módulo es sólo para campañas complementarias (tipo 2).</div>");
}

/* =========================================
 * Valores iniciales UI
 * ======================================= */
$view_mode   = $_GET['view_mode'] ?? 'galeria';
if (!in_array($view_mode, ['galeria', 'duplicados'], true)) {
    $view_mode = 'galeria';
}

$user_id     = intval($_GET['user_id'] ?? 0);
$id_question = $_GET['id_question'] ?? '';
$local_codigo = trim($_GET['local_codigo'] ?? '');
$gap         = max(1, intval($_GET['gap'] ?? 2));
$start_date  = $_GET['start_date'] ?? '';
$end_date    = $_GET['end_date'] ?? '';
$limit       = max(1, intval($_GET['limit'] ?? 25));

$base_url = "https://visibility.cl/visibility2/app/";

/* =========================================
 * Filtros disponibles
 * ======================================= */
$usuarios = [];
$stmtU = $conn->prepare("
    SELECT DISTINCT u.id, u.usuario
    FROM form_question_responses fqr
    JOIN form_questions fq ON fq.id = fqr.id_form_question
    JOIN usuario u ON u.id = fqr.id_usuario
    WHERE fq.id_formulario = ?
      AND fq.id_question_type = 7
    ORDER BY u.usuario
");
$stmtU->bind_param("i", $formulario_id);
$stmtU->execute();
$resU = $stmtU->get_result();
while ($r = $resU->fetch_assoc()) {
    $usuarios[] = $r;
}
$stmtU->close();

$preguntasDisponibles = [];
$stmtP = $conn->prepare("
    SELECT id, question_text
    FROM form_questions
    WHERE id_formulario = ?
      AND id_question_type = 7
    ORDER BY sort_order
");
$stmtP->bind_param("i", $formulario_id);
$stmtP->execute();
$resP = $stmtP->get_result();
while ($r = $resP->fetch_assoc()) {
    $preguntasDisponibles[] = $r;
}
$stmtP->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Galería Complementaria — <?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <style>
        :root{
            --bg:#f4f6f9;
            --card:#ffffff;
            --text:#233142;
            --muted:#6b7280;
            --border:#d9e0e7;
            --primary:#1677ff;
            --primary-dark:#0f5fd4;
            --dark-head:#4c5661;
            --success:#198754;
            --warning:#f0ad4e;
            --info:#17a2b8;
            --shadow:0 6px 18px rgba(24,39,75,.08);
            --radius:16px;
        }

        body{
            background:var(--bg);
            color:var(--text);
            font-size:14px;
        }

        .gallery-shell{
            max-width:1660px;
            margin:24px auto;
            padding:0 14px 30px;
        }

        .gallery-header,
        .gallery-tabs-wrap,
        .filters-card,
        .table-card{
            background:var(--card);
            border:1px solid var(--border);
            border-radius:18px;
            box-shadow:var(--shadow);
        }

        .gallery-header{
            padding:22px 24px;
            margin-bottom:16px;
        }

        .gallery-kicker{
            font-size:12px;
            font-weight:700;
            letter-spacing:.08em;
            text-transform:uppercase;
            color:var(--primary);
            margin-bottom:6px;
        }

        .gallery-title-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
        }

        .gallery-title{
            margin:0;
            font-size:28px;
            font-weight:700;
            color:var(--text);
        }

        .gallery-subtitle{
            margin:6px 0 0;
            color:var(--muted);
            font-size:14px;
        }

        .gallery-id-badge{
            background:#eef4ff;
            color:var(--primary-dark);
            border:1px solid #cfe0ff;
            border-radius:999px;
            padding:8px 14px;
            font-weight:700;
            white-space:nowrap;
        }

        .gallery-tabs-wrap{
            padding:12px 14px 0;
            margin-bottom:16px;
        }

        .gallery-tabs-wrap .nav-tabs{
            border-bottom:none;
            gap:10px;
        }

        .gallery-tabs-wrap .nav-link{
            border:none;
            border-radius:12px 12px 0 0;
            background:transparent;
            color:#4b5563;
            font-weight:600;
            padding:12px 18px;
        }

        .gallery-tabs-wrap .nav-link.active{
            background:#fff;
            color:var(--primary);
            box-shadow:0 -1px 0 var(--border), 1px 0 0 var(--border), -1px 0 0 var(--border);
        }

        .filters-card{
            padding:20px;
            margin-bottom:16px;
        }

        .filters-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(220px,1fr));
            gap:18px 22px;
        }

        .filter-field label{
            display:block;
            margin-bottom:7px;
            font-weight:700;
            color:#334155;
        }

        .filter-field .form-control{
            height:42px;
            border-radius:10px;
            border:1px solid #bfc7d1;
            box-shadow:none;
        }

        .filter-actions{
            display:flex;
            align-items:flex-end;
            gap:12px;
            flex-wrap:wrap;
        }

        .btn-modern{
            min-width:180px;
            height:42px;
            border-radius:10px;
            font-weight:600;
        }

        .btn-apply{
            background:var(--primary);
            border-color:var(--primary);
            color:#fff;
        }

        .btn-apply:hover{
            background:var(--primary-dark);
            border-color:var(--primary-dark);
            color:#fff;
        }

        .btn-soft{
            background:#fff;
            border:1px solid #7d8896;
            color:#46515f;
        }

        .toolbar-card{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }

        .toolbar-left,
        .toolbar-right{
            display:flex;
            gap:10px;
            align-items:center;
            flex-wrap:wrap;
        }

        .table-card{
            overflow:hidden;
            min-height:180px;
        }

        .table-card .table{
            margin-bottom:0;
        }

        .table-card thead th{
            background:var(--dark-head);
            color:#fff;
            border-color:#69727d;
            font-size:12px;
            font-weight:700;
            text-transform:uppercase;
            white-space:nowrap;
            vertical-align:middle;
            padding:16px 12px;
        }

        .table-card tbody td{
            vertical-align:middle;
            padding:14px 12px;
            border-top:1px solid #e9eef3;
        }

        .table-card tbody tr:hover{
            background:#f8fbff;
        }

        .thumbnail{
            width:92px;
            height:92px;
            object-fit:cover;
            border-radius:12px;
            border:1px solid #d7dee7;
            cursor:pointer;
            transition:.2s ease;
        }

        .thumbnail:hover{
            transform:scale(1.03);
        }

        .custom-img-cell{
            width:118px;
            position:relative;
        }

        .badge-count{
            position:absolute;
            top:8px;
            right:8px;
            background:rgba(15,23,42,.78);
            color:#fff;
            font-size:12px;
            font-weight:700;
            padding:3px 8px;
            border-radius:999px;
        }

        .meta-note{
            color:var(--muted);
            font-size:12px;
        }

        .pagination-wrap{
            padding:16px 18px 20px;
            background:#fff;
        }

        .pagination{
            flex-wrap:wrap;
            justify-content:center;
            gap:6px;
            margin-bottom:0;
        }

        .page-link{
            border-radius:10px !important;
            color:#4b5563;
            border:1px solid #d4dde7;
        }

        .page-item.active .page-link{
            background:var(--primary);
            border-color:var(--primary);
        }

        .empty-state{
            padding:40px 18px;
            text-align:center;
            color:var(--muted);
            font-weight:600;
        }

        .ajax-loading{
            padding:40px 18px;
            text-align:center;
        }

        .modal-gallery{
            border-radius:16px;
            background:#0b1624;
            border:1px solid rgba(0,194,255,.15);
        }

        .gallery-main-container{
            width:100%;
            height:70vh;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#020b18;
            border-radius:12px;
            overflow:hidden;
            margin-bottom:12px;
        }

        .gallery-main-img{
            max-width:100%;
            max-height:100%;
            object-fit:contain;
            border-radius:10px;
        }

        .gallery-thumbs{
            display:flex;
            gap:10px;
            overflow-x:auto;
            padding:6px 2px;
        }

        .gallery-thumbs::-webkit-scrollbar{
            height:6px;
        }

        .gallery-thumbs::-webkit-scrollbar-thumb{
            background:rgba(0,194,255,.4);
            border-radius:999px;
        }

        .gallery-thumb{
            width:90px;
            height:90px;
            flex-shrink:0;
            border-radius:10px;
            overflow:hidden;
            cursor:pointer;
            border:2px solid transparent;
            transition:all .2s ease;
        }

        .gallery-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
        }

        .gallery-thumb.active{
            border-color:#19d4ff;
            box-shadow:0 0 10px rgba(0,194,255,.4);
        }

        .gallery-thumb:hover{
            transform:scale(1.05);
        }

        /* ── Panel metadata foto ── */
        #galleryMetaPane{
            flex:0 0 30%;
            min-width:220px;
            background:#0d1e30;
            border-left:1px solid rgba(0,194,255,.12);
            border-radius:0 16px 16px 0;
            padding:18px 16px;
            overflow-y:auto;
            max-height:calc(70vh + 120px);
        }
        #galleryPhotoPane{
            flex:1 1 70%;
            min-width:0;
            padding:16px;
        }
        .meta-row{
            display:flex;
            gap:8px;
            margin-bottom:9px;
            font-size:12.5px;
            color:#cdd6e0;
            align-items:flex-start;
        }
        .meta-icon{ font-size:13px; color:#19d4ff; flex-shrink:0; margin-top:1px; }
        .meta-label{ color:#7a9ab5; font-size:11px; display:block; }
        .meta-val{ color:#e2eaf0; }
        .meta-divider{ border:none; border-top:1px solid rgba(0,194,255,.1); margin:10px 0; }
        .meta-loading, .meta-empty{
            color:#6b8aad; font-size:12px; text-align:center;
            padding:20px 0;
        }
        @media (max-width:768px){
            #fullSizeModal .modal-body.d-flex{ flex-direction:column !important; }
            #galleryMetaPane{ flex:none; border-left:none; border-top:1px solid rgba(0,194,255,.12); border-radius:0 0 16px 16px; max-height:260px; }
        }

        @media (max-width: 1200px){
            .filters-grid{
                grid-template-columns:repeat(3,minmax(220px,1fr));
            }
        }

        @media (max-width: 992px){
            .filters-grid{
                grid-template-columns:repeat(2,minmax(220px,1fr));
            }
        }

        @media (max-width: 576px){
            .filters-grid{
                grid-template-columns:1fr;
            }

            .gallery-title{
                font-size:22px;
            }

            .btn-modern{
                width:100%;
            }
        }

        /* ── Modal Kilometrajes ── */
        .km-week-card{
            border:1px solid #dee2e6;
            border-radius:10px;
            padding:12px 14px;
            background:#fafbfd;
        }
        .km-week-header{
            margin-bottom:8px;
            font-size:13px;
        }
        .km-week-days{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            margin-bottom:8px;
        }
        .km-day-label{
            display:flex;
            align-items:center;
            gap:5px;
            cursor:pointer;
            font-size:12px;
            margin:0;
            padding:6px 11px;
            border-radius:8px;
            border:1px solid #ced4da;
            background:#fff;
            transition:.15s;
            user-select:none;
        }
        .km-day-label.active{
            background:#e8f5e9;
            border-color:#66bb6a;
            color:#2e7d32;
        }
        .km-day-label.feriado{
            background:#fbe9e7;
            border-color:#ef9a9a;
            color:#b71c1c;
            text-decoration:line-through;
        }
        .km-day-check{ display:none; }
        .km-day-text{
            display:flex;
            flex-direction:column;
            align-items:center;
            line-height:1.2;
        }
        .km-day-name{ font-weight:700; }
        .km-day-num{ font-size:11px; opacity:.8; }
        .km-week-preview{
            font-size:12px;
            padding-top:6px;
            border-top:1px solid #e9ecef;
            color:#555;
        }
    </style>
</head>
<body class="bg-light">

<div class="gallery-shell">
    <div class="gallery-header">
        <div class="gallery-kicker">Galería fotográfica</div>
        <div class="gallery-title-row">
            <div>
                <h1 class="gallery-title">Galería Complementaria</h1>
                <p class="gallery-subtitle"><?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></p>
            </div>
            <div class="gallery-id-badge">Campaña #<?= (int)$formulario_id ?></div>
        </div>
    </div>

    <div class="gallery-tabs-wrap">
        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link js-view-mode <?= $view_mode === 'galeria' ? 'active' : '' ?>" href="#" data-mode="galeria">
                    Fotos Complementarias
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link js-view-mode <?= $view_mode === 'duplicados' ? 'active' : '' ?>" href="#" data-mode="duplicados">
                    Fotos Duplicadas
                </a>
            </li>
        </ul>
    </div>

    <form id="filterForm" class="filters-card" onsubmit="return false;">
        <input type="hidden" name="id" value="<?= (int)$formulario_id ?>">
        <input type="hidden" name="view_mode" value="<?= htmlspecialchars($view_mode, ENT_QUOTES) ?>">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="limit" value="<?= (int)$limit ?>">

        <div class="filters-grid">
            <div class="filter-field">
                <label>Usuario</label>
                <select name="user_id" class="form-control">
                    <option value="0">-- Todos --</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === $user_id ? 'selected' : '') ?>>
                            <?= htmlspecialchars($u['usuario']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Pregunta</label>
                <select name="id_question" class="form-control">
                    <option value="">-- Todas --</option>
                    <?php foreach ($preguntasDisponibles as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ((string)$p['id'] === (string)$id_question ? 'selected' : '') ?>>
                            <?= htmlspecialchars($p['question_text']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($requiereLocal): ?>
                <div class="filter-field">
                    <label>Código local</label>
                    <input type="text"
                           name="local_codigo"
                           class="form-control"
                           placeholder="Ej: LOC001"
                           value="<?= htmlspecialchars($local_codigo, ENT_QUOTES) ?>">
                </div>
            <?php endif; ?>

            <div class="filter-field">
                <label>Lapso visita (min)</label>
                <input type="number"
                       min="1"
                       max="60"
                       name="gap"
                       class="form-control"
                       value="<?= (int)$gap ?>">
            </div>

            <div class="filter-field">
                <label>Desde</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date, ENT_QUOTES) ?>">
            </div>

            <div class="filter-field">
                <label>Hasta</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date, ENT_QUOTES) ?>">
            </div>

            <div class="filter-actions">
                <button type="button" id="btnApplyFilters" class="btn btn-modern btn-apply">
                    Aplicar filtros
                </button>
                <button type="button" id="btnClearFilters" class="btn btn-modern btn-soft">
                    Limpiar filtros
                </button>
            </div>
        </div>
    </form>

    <div class="toolbar-card">
        <div class="toolbar-left">
            <button id="btnDownloadSelected" class="btn btn-success btn-modern" type="button">Descargar seleccionadas</button>
            <button id="btnDownloadAll" class="btn btn-warning btn-modern" type="button">Descargar todas</button>
            <button id="btnExportCsv" class="btn btn-info btn-modern" type="button" style="display:none;">Exportar CSV duplicados</button>
            <?php if ($formulario_id === 138): ?>
            <button type="button" class="btn btn-modern" id="btnKilometrajes"
                    style="background:#217346;border-color:#217346;color:#fff;"
                    data-toggle="modal" data-target="#modalKilometrajes">
                <i class="fas fa-file-excel mr-1"></i>Informe Kilometrajes
            </button>
            <?php endif; ?>
        </div>

        <div class="toolbar-right">
            <label class="mb-0 font-weight-bold">Mostrar</label>
            <select id="limitSelect" class="form-control" style="width:90px">
                <?php foreach ([10,25,50,100] as $n): ?>
                    <option value="<?= $n ?>" <?= ($n == $limit ? 'selected' : '') ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
            <span>registros</span>
        </div>
    </div>

    <div id="galleryAjaxContainer" class="table-card">
        <div class="empty-state">
            Aplica filtros para visualizar fotos y datos.
        </div>
    </div>
</div>

<!-- Modal imágenes -->
<div class="modal fade" id="fullSizeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content modal-gallery" style="overflow:hidden;">
            <div class="modal-body p-0 d-flex" style="min-height:520px;">
                <div id="galleryPhotoPane">
                    <div class="gallery-main-container">
                        <img id="galleryMainImage" src="" class="gallery-main-img" alt="Imagen">
                    </div>
                    <div class="gallery-thumbs" id="galleryThumbs"></div>
                </div>
                <div id="galleryMetaPane">
                    <div id="galleryMetaContent">
                        <p class="meta-empty">Selecciona una foto para ver su metadata.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background:#0b1624;border-top:1px solid rgba(0,194,255,.1);">
                <button class="btn btn-secondary" data-dismiss="modal" type="button">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($formulario_id === 138): ?>
<!-- ── Modal Informe Kilometrajes ── -->
<div class="modal fade" id="modalKilometrajes" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#217346;color:#fff;">
                <h5 class="modal-title">
                    <i class="fas fa-file-excel mr-2"></i>Informe Kilometrajes
                </h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Selecciona el período a analizar y <strong>desmarca los días feriados</strong> de cada semana.
                    El sistema calculará automáticamente cuándo debieron subirse los kilometrajes.
                </p>
                <div class="form-row mb-3">
                    <div class="col">
                        <label class="font-weight-bold small mb-1">Desde</label>
                        <input type="date" id="kmInputStart" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($start_date, ENT_QUOTES) ?>">
                    </div>
                    <div class="col">
                        <label class="font-weight-bold small mb-1">Hasta</label>
                        <input type="date" id="kmInputEnd" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($end_date, ENT_QUOTES) ?>">
                    </div>
                </div>
                <div id="kmWeeksContainer"></div>
                <div class="mt-3 p-2 rounded" style="background:#f0f9f0;border:1px solid #c3e6cb;">
                    <span class="font-weight-bold small">Total días de subida esperados: </span>
                    <span id="kmTotalExpected" class="font-weight-bold" style="color:#217346;font-size:16px;">0</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm" id="btnGenKmExcel"
                        style="background:#217346;border-color:#217346;color:#fff;">
                    <i class="fas fa-download mr-1"></i>Generar Excel
                </button>
            </div>
        </div>
    </div>
</div>

<form id="formKilometrajes" method="POST"
      action="../../informes/descargar_excel_kilometrajes.php"
      target="_blank" style="display:none;">
    <input type="hidden" name="start_date" id="kmFormStart">
    <input type="hidden" name="end_date"   id="kmFormEnd">
</form>
<?php endif; ?>

<form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
</form>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function () {
    const baseUrl     = '<?= $base_url ?>';
    const formularioId = <?= (int)$formulario_id ?>;
    let galleryXhr = null;
    let lastRequestData = null;
    let retryTimer = null;
    const autoRetryMax = 2;

    function serializeFilters() {
        const data = {};
        $('#filterForm').serializeArray().forEach(function(item) {
            data[item.name] = item.value;
        });

        data.limit = $('#limitSelect').val() || data.limit || 25;
        data.page = data.page || 1;

        return data;
    }

    function hasMeaningfulFilters(data) {
        return !!(
            parseInt(data.user_id || 0, 10) > 0 ||
            (data.id_question || '') !== '' ||
            (data.local_codigo || '').trim() !== '' ||
            (data.start_date || '') !== '' ||
            (data.end_date || '') !== ''
        );
    }

    function updateCsvButton() {
        const mode = $('[name="view_mode"]').val();
        if (mode === 'duplicados') {
            $('#btnExportCsv').show();
        } else {
            $('#btnExportCsv').hide();
        }
    }

    function showInitialState() {
        $('#galleryAjaxContainer').html(`
            <div class="empty-state">
                Aplica filtros para visualizar fotos y datos.
            </div>
        `);
    }

    function showLoading() {
        $('#galleryAjaxContainer').html(`
            <div class="ajax-loading">
                <div class="spinner-border text-primary mb-3" role="status"></div>
                <div class="font-weight-bold">Cargando galería...</div>
                <div class="text-muted small">Obteniendo datos filtrados</div>
            </div>
        `);
    }

    function showRetryMessage(attempt) {
        $('#galleryAjaxContainer').html(`
            <div class="ajax-loading">
                <div class="spinner-border text-warning mb-3" role="status"></div>
                <div class="font-weight-bold">Reintentando carga...</div>
                <div class="text-muted small">Intento ${attempt} de ${autoRetryMax}</div>
            </div>
        `);
    }

    function showError() {
        $('#galleryAjaxContainer').html(`
            <div class="empty-state">
                <div class="text-danger font-weight-bold mb-2">No fue posible cargar la galería.</div>
                <div class="text-muted small mb-3">Hubo un problema al consultar el servidor.</div>
                <button type="button" id="btnRetryGallery" class="btn btn-primary">Reintentar</button>
            </div>
        `);
    }

    function updateUrl(data) {
        const url = new URL(window.location.href);
        url.search = '';

        Object.keys(data).forEach(function(key) {
            if (data[key] !== null && data[key] !== undefined && data[key] !== '') {
                url.searchParams.set(key, data[key]);
            }
        });

        history.replaceState({}, '', url.toString());
    }

    function loadGallery(customData = null, retryCount = 0) {
        const data = customData || serializeFilters();

        updateCsvButton();

        if (!hasMeaningfulFilters(data)) {
            if (galleryXhr) galleryXhr.abort();
            showInitialState();
            return;
        }

        lastRequestData = { ...data };

        if (galleryXhr) {
            galleryXhr.abort();
        }

        if (retryCount === 0) {
            showLoading();
        } else {
            showRetryMessage(retryCount);
        }

        galleryXhr = $.ajax({
            url: 'ajax_galeria_complementaria.php',
            type: 'GET',
            data: data,
            cache: false,
            timeout: 25000,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function (html) {
                $('#galleryAjaxContainer').html(html);
                updateUrl(data);
            },
            error: function (xhr, status) {
                if (status === 'abort') return;

                if (retryCount < autoRetryMax) {
                    clearTimeout(retryTimer);
                    retryTimer = setTimeout(function () {
                        loadGallery(data, retryCount + 1);
                    }, 1200);
                    return;
                }

                showError();
            }
        });
    }

    $('#btnApplyFilters').on('click', function () {
        $('[name="page"]').val(1);
        loadGallery();
    });

    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        $('[name="page"]').val(1);
        loadGallery();
    });

    $('#btnClearFilters').on('click', function () {
        $('#filterForm')[0].reset();
        $('[name="user_id"]').val('0');
        $('[name="id_question"]').val('');
        $('[name="local_codigo"]').val('');
        $('[name="start_date"]').val('');
        $('[name="end_date"]').val('');
        $('[name="gap"]').val('2');
        $('[name="page"]').val('1');
        $('[name="view_mode"]').val('galeria');
        $('#limitSelect').val('25');
        $('[name="limit"]').val('25');

        $('.js-view-mode').removeClass('active');
        $('.js-view-mode[data-mode="galeria"]').addClass('active');

        updateCsvButton();
        showInitialState();

        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('id', $('input[name="id"]').val());
        url.searchParams.set('view_mode', 'galeria');
        history.replaceState({}, '', url.toString());
    });

    $('#limitSelect').on('change', function () {
        $('[name="limit"]').val($(this).val());
        $('[name="page"]').val(1);
        loadGallery();
    });

    $(document).on('click', '.js-gallery-page', function (e) {
        e.preventDefault();
        const page = $(this).data('page') || 1;
        $('[name="page"]').val(page);
        loadGallery();
    });

    $(document).on('click', '.js-view-mode', function (e) {
        e.preventDefault();
        const mode = $(this).data('mode');

        $('.js-view-mode').removeClass('active');
        $(this).addClass('active');

        $('[name="view_mode"]').val(mode);
        $('[name="page"]').val(1);
        updateCsvButton();
        loadGallery();
    });

    $(document).on('click', '#btnRetryGallery', function () {
        if (lastRequestData) {
            loadGallery(lastRequestData, 0);
        } else {
            loadGallery();
        }
    });

    function loadMeta(respId) {
        const $pane = $('#galleryMetaContent');
        if (!respId) {
            $pane.html('<p class="meta-empty">Sin metadata disponible.</p>');
            return;
        }
        $pane.html('<p class="meta-loading"><i class="fa fa-spinner fa-spin"></i> Cargando…</p>');
        $.get('modulos/mod_galeria/ajax_foto_meta.php', { resp_id: respId, id: formularioId })
            .done(function (data) {
                if (data && data.ok) renderMeta(data.meta);
                else $pane.html('<p class="meta-empty">Metadata no disponible.</p>');
            })
            .fail(function () {
                $pane.html('<p class="meta-empty">Error al cargar metadata.</p>');
            });
    }

    function renderMeta(m) {
        const rows = [];

        function row(icon, label, val) {
            if (val === null || val === undefined || val === '' || val === '0' || val === 0) return;
            rows.push(`<div class="meta-row"><span class="meta-icon"><i class="fa ${icon}"></i></span><div><span class="meta-label">${label}</span><span class="meta-val">${val}</span></div></div>`);
        }

        const sourceMap = { camera: 'Cámara', gallery: 'Galería', unknown: 'Desconocido' };
        row('fa-camera', 'Origen', sourceMap[m.capture_source] || m.capture_source);

        if (m.subida_at) {
            const d = new Date(m.subida_at.replace(' ', 'T'));
            row('fa-upload', 'Subida', d.toLocaleString('es-CL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }));
        }
        if (m.exif_datetime) {
            row('fa-calendar', 'EXIF fecha', m.exif_datetime.replace('T', ' ').slice(0, 16));
        }

        rows.push('<hr class="meta-divider">');

        if (m.exif_lat && m.exif_lng && parseFloat(m.exif_lat) !== 0) {
            const lat = parseFloat(m.exif_lat).toFixed(6);
            const lng = parseFloat(m.exif_lng).toFixed(6);
            const mapUrl = `https://www.google.com/maps?q=${lat},${lng}`;
            rows.push(`<div class="meta-row"><span class="meta-icon"><i class="fa fa-map-marker"></i></span><div><span class="meta-label">GPS</span><span class="meta-val">${lat}, ${lng} <a href="${mapUrl}" target="_blank" style="color:#19d4ff;font-size:11px;">Ver mapa</a></span></div></div>`);
        }
        if (m.exif_altitude && parseFloat(m.exif_altitude) !== 0) {
            row('fa-arrow-up', 'Altitud', parseFloat(m.exif_altitude).toFixed(1) + ' m');
        }
        if (m.exif_img_direction && parseFloat(m.exif_img_direction) !== 0) {
            row('fa-compass', 'Dirección', parseFloat(m.exif_img_direction).toFixed(1) + '°');
        }

        rows.push('<hr class="meta-divider">');

        const makeModel = [m.exif_make, m.exif_model].filter(Boolean).join(' / ');
        row('fa-mobile', 'Dispositivo', makeModel);
        row('fa-code', 'Software', m.exif_software);
        row('fa-search', 'Lente', m.exif_lens_model);

        const aperture = m.exif_fnumber  ? 'f/' + parseFloat(m.exif_fnumber).toFixed(1)  : '';
        const shutter  = m.exif_exposure_time ? m.exif_exposure_time + 's'                 : '';
        const iso      = m.exif_iso      ? 'ISO ' + m.exif_iso                             : '';
        const exposure = [aperture, shutter, iso].filter(Boolean).join(' · ');
        row('fa-sliders', 'Exposición', exposure);

        if (m.exif_focal_length && parseFloat(m.exif_focal_length) !== 0) {
            row('fa-dot-circle-o', 'Focal', parseFloat(m.exif_focal_length).toFixed(0) + ' mm');
        }

        if (m.sha1 && m.sha1.length >= 7) {
            rows.push('<hr class="meta-divider">');
            row('fa-lock', 'SHA1', '<span title="' + m.sha1 + '" style="font-family:monospace;">' + m.sha1.slice(0, 7) + '…</span>');
        }

        // Remove trailing dividers
        while (rows.length && rows[rows.length - 1].includes('meta-divider')) rows.pop();

        $('#galleryMetaContent').html(rows.join('') || '<p class="meta-empty">Sin metadata disponible.</p>');
    }

    $(document).on('click', '.thumbnail.img-click', function () {
        const urls    = ($(this).data('urls')     || '').split('||').filter(Boolean);
        const respIds = ($(this).attr('data-resp-ids') || '').split('||');
        const mainImg = $('#galleryMainImage');
        const thumbsContainer = $('#galleryThumbs');

        thumbsContainer.empty();

        function showPhoto(index) {
            const u = urls[index];
            if (!u) return;
            const src = /^https?:\/\//.test(u) ? u : baseUrl + u.replace(/^\/+/, '');
            mainImg.attr('src', src);
            $('.gallery-thumb').removeClass('active');
            thumbsContainer.children().eq(index).addClass('active');
            loadMeta(respIds[index] || '');
        }

        urls.forEach((u, index) => {
            if (!u) return;
            const src = /^https?:\/\//.test(u) ? u : baseUrl + u.replace(/^\/+/, '');
            const thumb = $(`<div class="gallery-thumb"><img src="${src}" alt="thumb"></div>`);
            thumb.on('click', function () { showPhoto(index); });
            thumbsContainer.append(thumb);
        });

        showPhoto(0);
        $('#fullSizeModal').modal('show');
    });

    $(document).on('change', '#selectAll', function () {
        $('.imgCheckbox').prop('checked', $(this).prop('checked'));
    });

    $('#btnDownloadSelected').on('click', function () {
        let toZip = [];

        $('.imgCheckbox:checked').each(function () {
            const urls = ($(this).data('urls') || '').split('||');
            const prefix = $(this).data('prefix') || 'foto';

            urls.forEach(function (u) {
                if (!u) return;
                const name = prefix + '_' + u.split('/').pop();
                toZip.push({ url: u, filename: name });
            });
        });

        if (!toZip.length) {
            alert('Selecciona al menos una fila.');
            return;
        }

        $.ajax({
            url: 'download_zip.php',
            method: 'POST',
            data: { jsonFotos: JSON.stringify(toZip) },
            xhrFields: { responseType: 'blob' },
            success: function (data, status, xhr) {
                const disp = xhr.getResponseHeader('Content-Disposition') || '';
                const match = disp.match(/filename[^;=\n]*=\s*([\'\"]?)([^\'\"\n]*)/);
                const fname = (match && match[2]) ? match[2] : 'fotos.zip';

                const blob = new Blob([data], { type: 'application/zip' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = fname;
                document.body.appendChild(link);
                link.click();
                link.remove();
            },
            error: function (_, __, e) {
                alert('Error al crear ZIP: ' + e);
            }
        });
    });

    $('#btnDownloadAll').on('click', function () {
        const data = serializeFilters();

        if (!hasMeaningfulFilters(data)) {
            alert('Primero aplica filtros para descargar resultados.');
            return;
        }

        const params = new URLSearchParams(data);
        params.set('action', 'all');
        params.set('view', 'complementaria');

        const url = 'download_zip.php?' + params.toString();

        $.ajax({
            url: url,
            method: 'GET',
            xhrFields: { responseType: 'blob' },
            success: function (data, status, xhr) {
                let fname = 'fotos_todas.zip';
                const disp = xhr.getResponseHeader('Content-Disposition') || '';
                const match = disp.match(/filename[^;=\n]*=\s*([\'\"]?)([^\'\"\n]*)/);
                if (match && match[2]) fname = match[2];

                const blob = new Blob([data], { type: 'application/zip' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = fname;
                document.body.appendChild(link);
                link.click();
                link.remove();
            },
            error: function (_, __, e) {
                alert('Error al crear ZIP completo: ' + e);
            }
        });
    });

    $('#btnExportCsv').on('click', function () {
        const data = serializeFilters();

        if ((data.view_mode || 'galeria') !== 'duplicados') {
            alert('La exportación CSV aplica sólo para la vista de duplicados.');
            return;
        }

        if (!hasMeaningfulFilters(data)) {
            alert('Primero aplica filtros para exportar.');
            return;
        }

        const url = new URL(window.location.origin + window.location.pathname.replace('galeria_complementaria.php', 'ajax_galeria_complementaria.php'));
        Object.keys(data).forEach(function(key) {
            if (data[key] !== null && data[key] !== undefined) {
                url.searchParams.set(key, data[key]);
            }
        });
        url.searchParams.set('export', 'csv');

        window.location.href = url.toString();
    });

    updateCsvButton();
});
</script>

<?php if ($formulario_id === 138): ?>
<script>
/* ── Informe Kilometrajes — lógica del modal ── */
(function () {
    'use strict';

    var DAY_SHORT  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    var MONTHS_ABR = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    var TIPO_LBL   = { inicio: 'mañana', termino: 'tarde' };

    /* Devuelve "YYYY-W##" (clave de semana ISO) para una fecha string "YYYY-MM-DD" */
    function isoWeekKey(dateStr) {
        var d   = new Date(dateStr + 'T12:00:00');
        var tmp = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
        var dn  = tmp.getUTCDay() || 7;
        tmp.setUTCDate(tmp.getUTCDate() + 4 - dn);
        var ys  = new Date(Date.UTC(tmp.getUTCFullYear(), 0, 1));
        var wk  = Math.ceil((((tmp - ys) / 86400000) + 1) / 7);
        return tmp.getUTCFullYear() + '-W' + String(wk).padStart(2, '0');
    }

    /* Suma n días a una fecha string y devuelve "YYYY-MM-DD" */
    function addDays(dateStr, n) {
        var d = new Date(dateStr + 'T12:00:00');
        d.setDate(d.getDate() + n);
        return d.toISOString().slice(0, 10);
    }

    /* Diferencia en días entre dos fechas string */
    function diffDays(a, b) {
        return Math.round(
            (new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000
        );
    }

    /* Agrupa un array de fechas en bloques consecutivos [[d1,d2],[d4,d5,d6],...] */
    function consecutiveBlocks(dates) {
        if (!dates.length) return [];
        var sorted  = dates.slice().sort();
        var blocks  = [[sorted[0]]];
        for (var i = 1; i < sorted.length; i++) {
            if (diffDays(sorted[i - 1], sorted[i]) === 1) {
                blocks[blocks.length - 1].push(sorted[i]);
            } else {
                blocks.push([sorted[i]]);
            }
        }
        return blocks;
    }

    /* Lee los checks activos de una tarjeta de semana y devuelve
       [{date, tipo:'inicio'|'termino'}] deduplicados */
    function calcExpectedForCard(card) {
        var checked = Array.from(card.querySelectorAll('.km-day-check:checked'))
                           .map(function (c) { return c.dataset.date; });
        if (!checked.length) return [];

        var blocks  = consecutiveBlocks(checked);
        var result  = [];
        var seen    = {};

        blocks.forEach(function (block) {
            var first = block[0];
            var last  = block[block.length - 1];

            function push(date, tipo) {
                var k = date + '|' + tipo;
                if (!seen[k]) { seen[k] = true; result.push({ date: date, tipo: tipo }); }
            }

            push(first, 'inicio');
            push(last,  'termino');
        });

        return result;
    }

    /* Formatea "YYYY-MM-DD" → "14/abr" */
    function fmtShort(dateStr) {
        var d = new Date(dateStr + 'T12:00:00');
        return d.getDate() + '/' + MONTHS_ABR[d.getMonth()];
    }

    /* Actualiza la línea de preview dentro de una tarjeta */
    function updatePreview(card) {
        var preview  = card.querySelector('.km-week-preview');
        var expected = calcExpectedForCard(card);

        if (!expected.length) {
            preview.innerHTML = '<span class="text-danger">⚠ Semana sin días hábiles — no aplica</span>';
        } else {
            var parts = expected.map(function (e) {
                var d = new Date(e.date + 'T12:00:00');
                return '<strong>' + DAY_SHORT[d.getDay()] + ' ' + fmtShort(e.date) + '</strong> ' + TIPO_LBL[e.tipo];
            });
            preview.innerHTML = '<span style="color:#217346">→ ' + parts.join(' &nbsp;·&nbsp; ') + '</span>';
        }

        updateTotal();
    }

    /* Actualiza el contador global de días esperados */
    function updateTotal() {
        var total = 0;
        document.querySelectorAll('#kmWeeksContainer .km-week-card').forEach(function (card) {
            total += calcExpectedForCard(card).length;
        });
        var el = document.getElementById('kmTotalExpected');
        if (el) el.textContent = total;
    }

    /* Construye (o reconstruye) la grilla de semanas */
    function buildGrid() {
        var start     = document.getElementById('kmInputStart').value;
        var end       = document.getElementById('kmInputEnd').value;
        var container = document.getElementById('kmWeeksContainer');
        container.innerHTML = '';

        if (!start || !end || start > end) {
            container.innerHTML = '<p class="text-muted small mt-1">Selecciona un rango de fechas válido.</p>';
            updateTotal();
            return;
        }

        /* Agrupar días laborales por semana ISO */
        var byWeek    = {};
        var weekOrder = [];
        var cur       = start;

        while (cur <= end) {
            var d   = new Date(cur + 'T12:00:00');
            var dow = d.getDay();                    // 0=Dom…6=Sáb
            if (dow >= 1 && dow <= 5) {              // Lun–Vie
                var key = isoWeekKey(cur);
                if (!byWeek[key]) { byWeek[key] = []; weekOrder.push(key); }
                byWeek[key].push(cur);
            }
            cur = addDays(cur, 1);
        }

        if (!weekOrder.length) {
            container.innerHTML = '<p class="text-muted small mt-1">No hay días hábiles en el rango.</p>';
            updateTotal();
            return;
        }

        /* Crear una tarjeta por semana */
        weekOrder.forEach(function (key) {
            var days = byWeek[key];
            var wNum = parseInt(key.split('-W')[1], 10);
            var card = document.createElement('div');
            card.className = 'km-week-card mb-2';

            var html  = '<div class="km-week-header">';
            html     += '<strong>Semana ' + wNum + '</strong>';
            html     += '<span class="text-muted ml-2 small">(' + fmtShort(days[0]) + ' – ' + fmtShort(days[days.length - 1]) + ')</span>';
            html     += '</div><div class="km-week-days">';

            days.forEach(function (dateStr) {
                var d   = new Date(dateStr + 'T12:00:00');
                var id  = 'km-day-' + dateStr;
                html   += '<label class="km-day-label active" for="' + id + '">'
                        + '<input type="checkbox" class="km-day-check" id="' + id + '"'
                        + ' data-date="' + dateStr + '" checked>'
                        + '<span class="km-day-text">'
                        + '<span class="km-day-name">' + DAY_SHORT[d.getDay()] + '</span>'
                        + '<span class="km-day-num">' + d.getDate() + '</span>'
                        + '</span></label>';
            });

            html += '</div><div class="km-week-preview"></div>';
            card.innerHTML = html;

            /* Evento: toggle feriado al hacer clic en un día */
            card.addEventListener('change', function (e) {
                if (!e.target.classList.contains('km-day-check')) return;
                var lbl = e.target.closest('label');
                if (e.target.checked) {
                    lbl.classList.remove('feriado');
                    lbl.classList.add('active');
                } else {
                    lbl.classList.remove('active');
                    lbl.classList.add('feriado');
                }
                updatePreview(card);
            });

            container.appendChild(card);
            updatePreview(card);
        });
    }

    /* Wiring */
    document.getElementById('kmInputStart').addEventListener('change', buildGrid);
    document.getElementById('kmInputEnd').addEventListener('change', buildGrid);

    $('#modalKilometrajes').on('show.bs.modal', function () {
        buildGrid();
    });

    document.getElementById('btnGenKmExcel').addEventListener('click', function () {
        var start = document.getElementById('kmInputStart').value;
        var end   = document.getElementById('kmInputEnd').value;

        if (!start || !end || start > end) {
            alert('Selecciona un rango de fechas válido.');
            return;
        }
        if (parseInt(document.getElementById('kmTotalExpected').textContent || '0', 10) === 0) {
            alert('No hay días de subida esperados en el rango seleccionado.');
            return;
        }

        var form = document.getElementById('formKilometrajes');

        /* Limpiar feriados anteriores */
        form.querySelectorAll('input[name="feriados[]"]').forEach(function (el) { el.remove(); });

        document.getElementById('kmFormStart').value = start;
        document.getElementById('kmFormEnd').value   = end;

        /* Agregar un input hidden por cada día desmarcado (feriado) */
        document.querySelectorAll('#kmWeeksContainer .km-day-check:not(:checked)').forEach(function (cb) {
            var inp  = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'feriados[]';
            inp.value = cb.dataset.date;
            form.appendChild(inp);
        });

        form.submit();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>
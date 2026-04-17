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
        <div class="modal-content modal-gallery">
            <div class="modal-body p-3">
                <div class="gallery-main-container">
                    <img id="galleryMainImage" src="" class="gallery-main-img" alt="Imagen">
                </div>
                <div class="gallery-thumbs" id="galleryThumbs"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-dismiss="modal" type="button">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
</form>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function () {
    const baseUrl = '<?= $base_url ?>';
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

    $(document).on('click', '.thumbnail.img-click', function () {
        const urls = ($(this).data('urls') || '').split('||');
        const mainImg = $('#galleryMainImage');
        const thumbsContainer = $('#galleryThumbs');

        thumbsContainer.empty();

        let first = true;

        urls.forEach((u, index) => {
            if (!u) return;

            const src = /^https?:\/\//.test(u)
                ? u
                : baseUrl + u.replace(/^\/+/, '');

            if (first) {
                mainImg.attr('src', src);
                first = false;
            }

            const thumb = $(`
                <div class="gallery-thumb ${index === 0 ? 'active' : ''}">
                    <img src="${src}" alt="thumb">
                </div>
            `);

            thumb.on('click', function () {
                $('#galleryMainImage').attr('src', src);
                $('.gallery-thumb').removeClass('active');
                $(this).addClass('active');
            });

            thumbsContainer.append(thumb);
        });

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
</body>
</html>
<?php $conn->close(); ?>
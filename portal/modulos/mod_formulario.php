<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

/* =========================================================
   Helpers
========================================================= */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function obtenerTextoEstado($estado): string {
    switch ((string)$estado) {
        case '1': return 'En curso';
        case '2': return 'Proceso';
        case '3': return 'Finalizado';
        case '4': return 'Cancelado';
        default:  return 'Desconocido';
    }
}

function obtenerClaseEstado($estado): string {
    switch ((string)$estado) {
        case '1': return 'badge-status badge-status-success';
        case '2': return 'badge-status badge-status-warning';
        case '3': return 'badge-status badge-status-primary';
        case '4': return 'badge-status badge-status-danger';
        default:  return 'badge-status badge-status-secondary';
    }
}

function obtenerTextoTipo($tipo): string {
    switch ((string)$tipo) {
        case '1': return 'Actividad Programada';
        case '2': return 'Actividad IW';
        case '3': return 'Actividad IPT';
        default:  return 'Desconocido';
    }
}

function obtenerClaseTipo($tipo): string {
    switch ((string)$tipo) {
        case '1': return 'badge-soft badge-soft-success';
        case '2': return 'badge-soft badge-soft-info';
        case '3': return 'badge-soft badge-soft-purple';
        default:  return 'badge-soft badge-soft-secondary';
    }
}

function formatearFecha($fecha): string {
    return !empty($fecha) ? date('d/m/Y H:i', strtotime($fecha)) : '-';
}

/* =========================================================
   Filtros
========================================================= */
$empresa_seleccionada      = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$division_seleccionada     = isset($_GET['division']) ? intval($_GET['division']) : 0;
$estado_campana            = isset($_GET['estado_campana']) ? trim($_GET['estado_campana']) : '1';
$tipo_campana              = isset($_GET['tipo_campana']) ? intval($_GET['tipo_campana']) : 0;
$subdivision_seleccionada  = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);

/* =========================================================
   Empresa usuario
========================================================= */
try {
    $nombre_empresa = obtenerNombreEmpresa($empresa_id);
} catch (Exception $e) {
    $_SESSION['error_formulario'] = $e->getMessage();
    header("Location: mod_formulario.php");
    exit();
}

$es_mentecreativa = (strtolower(trim($nombre_empresa)) === 'mentecreativa');

/* =========================================================
   Empresas activas si es mentecreativa
========================================================= */
$empresas_all = [];
if ($es_mentecreativa) {
    try {
        $empresas_all = obtenerEmpresasActivas();
    } catch (Exception $e) {
        $_SESSION['error_formulario'] = "Error al obtener las empresas: " . $e->getMessage();
    }

    if ($empresa_seleccionada > 0) {
        $empresas_ids = array_column($empresas_all, 'id');
        if (!in_array($empresa_seleccionada, $empresas_ids)) {
            $_SESSION['error_formulario'] = "Empresa no válida.";
            header("Location: mod_formulario.php");
            exit();
        }
    }
}

/* =========================================================
   Divisiones
========================================================= */
try {
    if ($es_mentecreativa && $empresa_seleccionada > 0) {
        $divisiones = obtenerDivisionesPorEmpresa($empresa_seleccionada);
    } elseif (!$es_mentecreativa) {
        $divisiones = obtenerDivisionesPorEmpresa($empresa_id);
    } else {
        $divisiones = [];
    }
} catch (Exception $e) {
    $divisiones = [];
    $_SESSION['error_formulario'] = "Error al obtener divisiones: " . $e->getMessage();
}

/* =========================================================
   Consulta formularios
========================================================= */
$params = [];
$param_types = "";

$query = "
    SELECT
        f.id,
        f.nombre,
        f.fechaInicio,
        f.fechaTermino,
        f.estado,
        f.tipo,
        f.id_division,
        d.nombre  AS division_nombre,
        sd.nombre AS subdivision_nombre
    FROM formulario AS f
    LEFT JOIN division_empresa AS d ON d.id = f.id_division
    LEFT JOIN subdivision AS sd ON sd.id = f.id_subdivision
";

$conditions = [];

if ($es_mentecreativa) {
    if ($empresa_seleccionada > 0) {
        $conditions[] = "f.id_empresa = ?";
        $params[] = $empresa_seleccionada;
        $param_types .= "i";
    }
} else {
    $conditions[] = "f.id_empresa = ?";
    $params[] = $empresa_id;
    $param_types .= "i";
}

if ($division_seleccionada > 0) {
    $conditions[] = "f.id_division = ?";
    $params[] = $division_seleccionada;
    $param_types .= "i";
}

if ($subdivision_seleccionada > 0) {
    $conditions[] = "f.id_subdivision = ?";
    $params[] = $subdivision_seleccionada;
    $param_types .= "i";
}

if ($estado_campana !== '0' && $estado_campana !== '') {
    $conditions[] = "f.estado = ?";
    $params[] = $estado_campana;
    $param_types .= "s";
}

if ($tipo_campana > 0) {
    $conditions[] = "f.tipo = ?";
    $params[] = $tipo_campana;
    $param_types .= "i";
}

if (!empty($conditions)) {
    $query .= " WHERE " . implode(" AND ", $conditions);
}

$query .= " ORDER BY f.fechaInicio DESC, f.id DESC";

$formularios = ejecutarConsulta($query, $params, $param_types);

/* =========================================================
   Tipos de pregunta
========================================================= */
try {
    $tipos_pregunta_raw = ejecutarConsulta("SELECT id, name FROM question_type ORDER BY id ASC", [], '');
    $tipoTraduc = [
        'yes_no'          => 'Sí/No',
        'single_choice'   => 'Selección única',
        'multiple_choice' => 'Selección múltiple',
        'text'            => 'Texto',
        'numeric'         => 'Numérico',
        'date'            => 'Fecha',
        'photo'           => 'Foto',
    ];

    $tipos_pregunta = [];
    foreach ($tipos_pregunta_raw as $tp) {
        $tipos_pregunta[] = [
            'id'   => $tp['id'],
            'name' => $tipoTraduc[$tp['name']] ?? $tp['name']
        ];
    }
} catch (Exception $e) {
    $tipos_pregunta = [];
    $_SESSION['error_formulario'] = "Error al obtener tipos de pregunta: " . $e->getMessage();
}

/* =========================================================
   Materiales
========================================================= */
try {
    $materiales = ejecutarConsulta("SELECT id, nombre FROM material ORDER BY nombre ASC", [], '');
} catch (Exception $e) {
    $materiales = [];
    $_SESSION['error_formulario'] = "Error al obtener materiales: " . $e->getMessage();
}

/* =========================================================
   Divisiones modal crear
========================================================= */
if ($es_mentecreativa && $empresa_seleccionada > 0) {
    try {
        $divisiones_modal = obtenerDivisionesPorEmpresa($empresa_seleccionada);
    } catch (Exception $e) {
        $divisiones_modal = [];
        $_SESSION['error_formulario'] = "Error al obtener divisiones modal: " . $e->getMessage();
    }
} elseif (!$es_mentecreativa) {
    try {
        $divisiones_modal = obtenerDivisionesPorEmpresa($empresa_id);
    } catch (Exception $e) {
        $divisiones_modal = [];
        $_SESSION['error_formulario'] = "Error al obtener divisiones modal: " . $e->getMessage();
    }
} else {
    $divisiones_modal = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Formularios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap / DataTables / FontAwesome -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">

    <style>
        :root{
            --primary:#198754;
            --primary-dark:#157347;
            --secondary:#6c757d;
            --info:#0dcaf0;
            --warning:#ffc107;
            --danger:#dc3545;
            --dark:#212529;
            --muted:#6b7280;
            --bg:#f4f6f9;
            --card:#ffffff;
            --border:#e9ecef;
            --shadow:0 10px 30px rgba(0,0,0,.08);
            --radius:18px;
        }

        body{
            background: linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: #2d3748;
        }

        #globalSpinner{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(255,255,255,.75);
            backdrop-filter: blur(2px);
            z-index:9999;
            align-items:center;
            justify-content:center;
        }
        #globalSpinner.show{ display:flex; }

        .page-shell{
            max-width: 1450px;
            margin: 35px auto;
            padding: 0 16px;
        }

        .module-card{
            background: var(--card);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 28px;
            border: 1px solid rgba(255,255,255,.7);
        }

        .page-header{
            display:flex;
            flex-wrap:wrap;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            margin-bottom:24px;
        }

        .page-title{
            margin:0;
            font-size:2rem;
            font-weight:700;
            color:#1f2937;
        }

        .page-subtitle{
            margin:6px 0 0 0;
            color:var(--muted);
            font-size:.96rem;
        }

        .count-pill{
            background: linear-gradient(135deg, #198754 0%, #157347 100%);
            color:#fff;
            padding:12px 18px;
            border-radius:16px;
            min-width: 170px;
            box-shadow: 0 10px 24px rgba(25,135,84,.22);
        }

        .count-pill .count{
            display:block;
            font-size:1.6rem;
            font-weight:800;
            line-height:1.1;
        }

        .count-pill .label{
            font-size:.82rem;
            opacity:.9;
            letter-spacing:.3px;
        }

        .section-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:20px;
            padding:20px;
            box-shadow:0 8px 24px rgba(15,23,42,.05);
            margin-bottom:22px;
        }

        .section-title{
            display:flex;
            align-items:center;
            gap:10px;
            margin-bottom:16px;
            font-size:1.05rem;
            font-weight:700;
            color:#1f2937;
        }

        .toolbar-actions{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:22px;
        }

        .btn-modern{
            border-radius:12px;
            padding:.62rem 1rem;
            font-weight:600;
            box-shadow:0 6px 18px rgba(0,0,0,.08);
        }

        .btn-primary{
            background-color:var(--primary);
            border-color:var(--primary);
        }

        .btn-primary:hover{
            background-color:var(--primary-dark);
            border-color:var(--primary-dark);
        }

        .btn-soft-info{
            background:#e7f7fc;
            color:#0c8599;
            border:1px solid #c9edf7;
        }

        .btn-soft-secondary{
            background:#f1f3f5;
            color:#495057;
            border:1px solid #dee2e6;
        }

        .form-control,
        .custom-select{
            border-radius:12px;
            min-height:42px;
            border:1px solid #dce3ea;
            box-shadow:none !important;
        }

        .form-control:focus,
        .custom-select:focus{
            border-color:#86b7fe;
        }

        .filters-row .form-group label{
            font-weight:600;
            color:#374151;
            margin-bottom:7px;
        }

        .alert{
            border-radius:14px;
            border:none;
            box-shadow:0 8px 18px rgba(0,0,0,.06);
        }

        .table-wrap{
            background:#fff;
            border:1px solid var(--border);
            border-radius:20px;
            padding:18px;
            box-shadow:0 8px 24px rgba(15,23,42,.05);
        }

        table.dataTable{
            border-collapse:separate !important;
            border-spacing:0 8px !important;
            margin-top:10px !important;
        }

        .table thead th{
            border:none !important;
            background:#1f2937;
            color:#fff;
            font-size:.88rem;
            font-weight:700;
            vertical-align:middle;
            text-align:center;
            white-space:nowrap;
        }

        .table thead th:first-child{
            border-top-left-radius:12px;
            border-bottom-left-radius:12px;
        }

        .table thead th:last-child{
            border-top-right-radius:12px;
            border-bottom-right-radius:12px;
        }

        .table tbody tr{
            background:#fff;
            box-shadow:0 4px 14px rgba(15,23,42,.05);
        }

        .table tbody td{
            vertical-align:middle !important;
            border-top:none !important;
            border-bottom:none !important;
            text-align:center;
            padding:.9rem .7rem;
        }

        .table tbody tr td:first-child{
            border-top-left-radius:12px;
            border-bottom-left-radius:12px;
        }

        .table tbody tr td:last-child{
            border-top-right-radius:12px;
            border-bottom-right-radius:12px;
        }

        .campaign-name{
            text-align:left !important;
            font-weight:700;
            color:#1f2937;
        }

        .badge-status,
        .badge-soft{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            padding:.42rem .78rem;
            font-size:.78rem;
            font-weight:700;
            white-space:nowrap;
        }

        .badge-status-success{ background:#d1f7e1; color:#0f7a42; }
        .badge-status-warning{ background:#fff3cd; color:#8a6d00; }
        .badge-status-primary{ background:#dbeafe; color:#1d4ed8; }
        .badge-status-danger{ background:#fde2e1; color:#b42318; }
        .badge-status-secondary{ background:#e9ecef; color:#495057; }

        .badge-soft-success{ background:#e8f5e9; color:#2e7d32; }
        .badge-soft-info{ background:#e3f7fc; color:#0b7285; }
        .badge-soft-purple{ background:#efe7ff; color:#6f42c1; }
        .badge-soft-secondary{ background:#f1f3f5; color:#495057; }

        .action-group{
            display:flex;
            justify-content:center;
            gap:8px;
            flex-wrap:wrap;
        }

        .btn-icon{
            width:38px;
            height:38px;
            border-radius:10px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:0;
        }

        .table-muted{
            color:#6b7280;
            font-size:.88rem;
        }

        .modal-content{
            border:none;
            border-radius:20px;
            overflow:hidden;
            box-shadow:0 20px 50px rgba(0,0,0,.18);
        }

        .modal-header{
            background:linear-gradient(135deg,#1f2937 0%, #374151 100%);
            color:#fff;
            border-bottom:none;
            padding:18px 22px;
        }

        .modal-body{
            padding:22px;
            background:#fbfcfe;
        }

        .modal-footer{
            border-top:none;
            padding:18px 22px;
        }

        .question-row{
            border:1px solid #dfe7ef;
            background:#fff;
            border-radius:14px;
            padding:15px;
            margin-bottom:15px;
            box-shadow:0 6px 18px rgba(15,23,42,.04);
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select{
            border-radius:10px;
            border:1px solid #dce3ea;
            padding:.35rem .6rem;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button{
            padding:0 !important;
            margin:0 2px !important;
            border:none !important;
            background:transparent !important;
        }

        .dataTables_wrapper .dataTables_paginate .page-link{
            border-radius:10px !important;
        }

        @media (max-width: 767px){
            .page-title{ font-size:1.6rem; }
            .module-card{ padding:18px; }
            .toolbar-actions{ flex-direction:column; }
            .toolbar-actions .btn{ width:100%; }
        }
        .form-submit-overlay{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(3px);
            z-index:10650;
            align-items:center;
            justify-content:center;
            padding:20px;
        }
        
        .form-submit-overlay.show{
            display:flex;
        }
        
        .form-submit-box{
            width:100%;
            max-width:460px;
            background:#fff;
            border-radius:20px;
            padding:28px 24px;
            text-align:center;
            box-shadow:0 25px 60px rgba(0,0,0,.25);
            border:1px solid rgba(255,255,255,.7);
        }
        
        #csvUploadContainer .border{
            border-color:#dfe7ef !important;
            border-radius:14px !important;
        }        
    </style>

    <script>
        function toggleCSVUpload(tipo) {
            var csvContainer         = document.getElementById('csvUploadContainer');
            var empresaDivContainer  = document.getElementById('empresaDivisionContainer');
            var modalidadContainer   = document.getElementById('modalidadContainer');
            var csvInput             = document.getElementById('csvFile');

            var iwReqLocalContainer  = document.getElementById('iwReqLocalContainer');
            var iwReqLocalCheck      = document.getElementById('iw_requiere_local');

            const show = el => { if (el) el.style.display = 'block'; };
            const hide = el => { if (el) el.style.display = 'none'; };

            const subCont = document.getElementById('subdivisionContainer');
            const subSel  = document.getElementById('id_subdivision');
            const divSel  = document.getElementById('id_division');
            const empSel  = document.getElementById('empresa_form');
            const modSel  = document.getElementById('modalidad');

            if (csvInput) csvInput.required = false;

            if (tipo === '1' || tipo === '3') {
                show(csvContainer);
                if (csvInput) csvInput.required = true;

                show(empresaDivContainer);
                if (divSel)  { divSel.disabled = false; divSel.required = true; }
                if (empSel)  { empSel.disabled = false; empSel.required = true; }

                show(modalidadContainer);
                if (modSel)  { modSel.disabled = false; modSel.required = true; }

                if (subCont && subSel) {
                    subSel.disabled = false;
                }

                hide(iwReqLocalContainer);
                if (iwReqLocalCheck) iwReqLocalCheck.checked = false;

            } else if (tipo === '2') {
                hide(csvContainer);

                show(empresaDivContainer);
                if (divSel)  { divSel.disabled = false; divSel.required = false; }
                if (empSel)  { empSel.disabled = false; empSel.required = true; }

                hide(modalidadContainer);
                if (modSel)  { modSel.required = false; modSel.disabled = true; modSel.value = ''; }

                if (subCont && subSel) {
                    hide(subCont);
                    subSel.required = false;
                    subSel.disabled = true;
                    subSel.value    = '0';
                }

                show(iwReqLocalContainer);

            } else {
                hide(csvContainer);
                hide(empresaDivContainer);
                hide(modalidadContainer);

                if (divSel) { divSel.required = false; divSel.disabled = true; }
                if (empSel) { empSel.required = false; empSel.disabled = false; }

                if (subCont && subSel) {
                    hide(subCont);
                    subSel.required = false;
                    subSel.disabled = true;
                    subSel.value = '0';
                }

                hide(iwReqLocalContainer);
                if (iwReqLocalCheck) iwReqLocalCheck.checked = false;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var tipoSelect = document.getElementById('tipo');
            if (tipoSelect) {
                toggleCSVUpload(tipoSelect.value);
            }

            const divisionFiltro = document.getElementById('division_filter');
            const subdivisionPreseleccionada = <?php echo (int)$subdivision_seleccionada; ?>;

            if (divisionFiltro) {
                const dv = divisionFiltro.value;
                if (dv && dv !== '0') {
                    cargarSubdivisionesFiltro(dv, subdivisionPreseleccionada);
                } else {
                    const cont = document.getElementById('subdivision_filter_container');
                    if (cont) cont.style.display = 'none';
                }
            }
        });
    </script>
</head>
<body>

<div id="globalSpinner">
    <div class="spinner-border text-success" style="width:3rem;height:3rem;" role="status">
        <span class="sr-only">Cargando…</span>
    </div>
</div>

<div class="page-shell">
    <div class="module-card">

        <div class="page-header">
            <div>
                <h1 class="page-title">Gestión de Formularios</h1>
                <p class="page-subtitle">Administra campañas, filtros, tipos de actividad y accesos rápidos del módulo.</p>
            </div>

            <div class="count-pill">
                <span class="count"><?php echo count($formularios); ?></span>
                <span class="label">formularios encontrados</span>
            </div>
        </div>

        <?php if (isset($_SESSION['success_formulario'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                    echo e($_SESSION['success_formulario']);
                    unset($_SESSION['success_formulario']);
                ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_formulario'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php
                    echo e($_SESSION['error_formulario']);
                    unset($_SESSION['error_formulario']);
                ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($es_mentecreativa): ?>
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-filter"></i>
                    <span>Filtros de búsqueda</span>
                </div>

                <form method="get" id="filterForm">
                    <div class="form-row filters-row">
                        <div class="form-group col-md-3">
                            <label for="empresa_filter">Empresa</label>
                            <select id="empresa_filter" name="empresa" class="form-control" onchange="actualizarDivisionesFiltro()">
                                <option value="0">-- Todas las Empresas --</option>
                                <?php foreach ($empresas_all as $e_filtro): ?>
                                    <option value="<?php echo e($e_filtro['id']); ?>" <?php echo ($empresa_seleccionada === intval($e_filtro['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($e_filtro['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="division_filter">División</label>
                            <select id="division_filter" name="division" class="form-control" onchange="this.form.submit()">
                                <option value="0">-- Todas las Divisiones --</option>
                                <?php foreach ($divisiones as $d_filtro): ?>
                                    <option value="<?php echo e($d_filtro['id']); ?>" <?php echo ($division_seleccionada === intval($d_filtro['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($d_filtro['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3" id="subdivision_filter_container" style="display:none;">
                            <label for="subdivision_filter">Subdivisión</label>
                            <select id="subdivision_filter" name="subdivision" class="form-control" onchange="this.form.submit()">
                                <option value="0">-- Todas las Subdivisiones --</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="estado_filter">Estado</label>
                            <select id="estado_filter" name="estado_campana" class="form-control" onchange="this.form.submit()">
                                <option value="0" <?php echo ($estado_campana === '0') ? 'selected' : ''; ?>>-- Todos --</option>
                                <option value="1" <?php echo ($estado_campana === '1') ? 'selected' : ''; ?>>En curso</option>
                                <option value="3" <?php echo ($estado_campana === '3') ? 'selected' : ''; ?>>Finalizado</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="tipo_filter">Tipo</label>
                            <select id="tipo_filter" name="tipo_campana" class="form-control" onchange="this.form.submit()">
                                <option value="0" <?php echo ($tipo_campana === 0) ? 'selected' : ''; ?>>-- Todos --</option>
                                <option value="1" <?php echo ($tipo_campana === 1) ? 'selected' : ''; ?>>Actividad Programada</option>
                                <option value="2" <?php echo ($tipo_campana === 2) ? 'selected' : ''; ?>>Actividad IW</option>
                                <option value="3" <?php echo ($tipo_campana === 3) ? 'selected' : ''; ?>>Actividad IPT</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-dark btn-modern btn-block">
                                <i class="fas fa-search mr-1"></i> Filtrar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="toolbar-actions">
            <a href="mod_formulario/gestionar_sets.php" class="btn btn-soft-info btn-modern">
                <i class="fas fa-layer-group mr-1"></i> Gestionar Sets de Preguntas
            </a>

            <a href="mod_formulario/gestionar_materiales.php" class="btn btn-soft-secondary btn-modern">
                <i class="fas fa-boxes-stacked mr-1"></i> Gestionar Materiales
            </a>

            <button type="button" class="btn btn-primary btn-modern" data-toggle="modal" data-target="#crearFormularioModal">
                <i class="fas fa-plus-circle mr-1"></i> Crear Nuevo Formulario
            </button>
        </div>

        <!-- Overlay de procesamiento -->
        <div id="formSubmitOverlay" class="form-submit-overlay">
            <div class="form-submit-box">
                <div class="spinner-border text-success mb-3" role="status" style="width:3rem;height:3rem;">
                    <span class="sr-only">Procesando...</span>
                </div>
                <h5 class="mb-2">Procesando formulario</h5>
                <p class="mb-3 text-muted">Subiendo archivo y registrando información, por favor espera...</p>
        
                <div class="progress" style="height: 10px; border-radius: 999px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                         role="progressbar"
                         style="width: 100%">
                    </div>
                </div>
        
                <small class="d-block mt-3 text-muted">No cierres esta ventana ni recargues la página.</small>
            </div>
        </div>
        
        <!-- Modal crear formulario -->
        <div class="modal fade" id="crearFormularioModal" tabindex="-1" role="dialog" aria-labelledby="crearFormularioModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <form action="mod_formulario/procesar.php" method="post" enctype="multipart/form-data" id="formCrearFormulario">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="crearFormularioModalLabel">Crear Nuevo Formulario</h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
        
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="nombre">Nombre del Formulario</label>
                                    <input type="text" id="nombre" name="nombre" class="form-control" required>
                                </div>
        
                                <div class="form-group col-md-6">
                                    <label for="tipo">Tipo de Actividad</label>
                                    <select id="tipo" name="tipo" class="form-control" required onchange="toggleCSVUpload(this.value)">
                                        <option value="">Seleccione una opción</option>
                                        <option value="1">Actividad Programada</option>
                                        <option value="2">Actividad IW</option>
                                        <option value="3">Actividad IPT</option>
                                    </select>
                                </div>
                            </div>
        
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="fechaInicio">Fecha de Inicio</label>
                                    <input type="datetime-local" id="fechaInicio" name="fechaInicio" class="form-control">
                                </div>
        
                                <div class="form-group col-md-6">
                                    <label for="fechaTermino">Fecha de Término</label>
                                    <input type="datetime-local" id="fechaTermino" name="fechaTermino" class="form-control">
                                </div>
                            </div>
        
                            <div class="form-group">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado" class="form-control" required>
                                    <option value="">Seleccione una opción</option>
                                    <option value="1">En curso</option>
                                    <option value="2">Proceso</option>
                                    <option value="3">Finalizado</option>
                                    <option value="4">Cancelado</option>
                                </select>
                            </div>
        
                            <div class="form-group" id="csvUploadContainer" style="display:none;">
                                <label for="csvFile">Subir Archivo CSV</label>
                                <input type="file" id="csvFile" name="csvFile" class="form-control-file" accept=".csv">
        
                                <div class="mt-2 p-3 border rounded bg-light">
                                    <small class="d-block mb-2 text-muted">
                                        El archivo debe contener estas columnas:
                                        <strong>codigo</strong>,
                                        <strong>usuario</strong>,
                                        <strong>material</strong>,
                                        <strong>categoria</strong>,
                                        <strong>marca</strong>,
                                        <strong>valor_propuesto</strong>,
                                        <strong>fechapropuesta</strong>.
                                    </small>
        
                                    <small class="d-block mb-2 text-muted">
                                        Formatos válidos para fecha:
                                        <strong>31-03-2026</strong>,
                                        <strong>31/03/2026</strong>,
                                        <strong>2026-03-31</strong>
                                        o
                                        <strong>2026/03/31</strong>.
                                    </small>
        
                                    <a href="mod_formulario/templates/template_formulario.csv"
                                       class="btn btn-sm btn-outline-success"
                                       download>
                                        <i class="fas fa-download mr-1"></i> Descargar plantilla CSV
                                    </a>
                                </div>
                            </div>
        
                            <div id="empresaDivisionContainer">
                                <?php if ($es_mentecreativa): ?>
                                    <div class="form-group">
                                        <label for="empresa_form">Empresa</label>
                                        <select id="empresa_form" name="empresa_form" class="form-control" required onchange="actualizarDivisionesCrear()">
                                            <option value="">-- Seleccione una Empresa --</option>
                                            <?php foreach ($empresas_all as $empresa_crear): ?>
                                                <option value="<?php echo e($empresa_crear['id']); ?>">
                                                    <?php echo e($empresa_crear['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php else: ?>
                                    <div class="form-group">
                                        <label for="empresa_display_modal">Empresa</label>
                                        <input type="text" id="empresa_display_modal" class="form-control" value="<?php echo e($nombre_empresa); ?>" disabled>
                                    </div>
                                    <input type="hidden" id="empresa_form_hidden" name="empresa_form" value="<?php echo e($empresa_id); ?>">
                                <?php endif; ?>
        
                                <div class="form-group" id="divisionContainer">
                                    <?php
                                    if ($es_mentecreativa && $empresa_seleccionada > 0) {
                                        $empresa_actual_id = $empresa_seleccionada;
                                    } elseif (!$es_mentecreativa) {
                                        $empresa_actual_id = $empresa_id;
                                    } else {
                                        $empresa_actual_id = 0;
                                    }
        
                                    $tiene_divisiones_modal = false;
                                    if ($empresa_actual_id > 0) {
                                        try {
                                            $count_divisiones_modal = contarDivisionesPorEmpresa($empresa_actual_id);
                                            $tiene_divisiones_modal = ($count_divisiones_modal > 0);
                                        } catch (Exception $e) {
                                            $tiene_divisiones_modal = false;
                                        }
                                    }
                                    ?>
        
                                    <?php if ($tiene_divisiones_modal): ?>
                                        <label for="id_division">División</label>
                                        <select id="id_division" name="id_division" class="form-control">
                                            <option value="">-- Seleccione una División --</option>
                                            <?php foreach ($divisiones_modal as $division_modal): ?>
                                                <option value="<?php echo e($division_modal['id']); ?>">
                                                    <?php echo e($division_modal['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="0">-- Sin División --</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="id_division" value="0">
                                    <?php endif; ?>
                                </div>
        
                                <div class="form-group" id="subdivisionContainer" style="display:none;">
                                    <label for="id_subdivision">Subdivisión</label>
                                    <select id="id_subdivision" name="id_subdivision" class="form-control">
                                        <option value="">-- Seleccione una Subdivisión --</option>
                                        <option value="0">-- Sin Subdivisión --</option>
                                    </select>
                                    <small class="form-text text-muted">Opcional. Depende de la división seleccionada.</small>
                                </div>
                            </div>
        
                            <div class="form-group" id="modalidadContainer" style="display:none;">
                                <label for="modalidad">Modalidad de Campaña</label>
                                <select id="modalidad" name="modalidad" class="form-control">
                                    <option value="implementacion_auditoria">Implementación + Auditoría</option>
                                    <option value="solo_implementacion">Solo Implementación</option>
                                    <option value="solo_auditoria">Solo Auditoría</option>
                                    <option value="retiro">Retiro</option>
                                    <option value="entrega">Entrega</option>
                                </select>
                                <small class="form-text text-muted">
                                    Solo aplicable a campañas Programadas/IPT.
                                </small>
                            </div>
        
                            <div class="form-group" id="iwReqLocalContainer" style="display:none;">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="iw_requiere_local" name="iw_requiere_local" value="1">
                                    <label class="custom-control-label" for="iw_requiere_local">
                                        Esta campaña IW requiere seleccionar un local?
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    Si está activo, en la app se pedirá elegir un local antes de crear la visita IW.
                                </small>
                            </div>
                        </div>
        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light btn-modern" data-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-primary btn-modern" id="btnSubmitFormulario">
                                <i class="fas fa-save mr-1"></i> Crear Formulario
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="section-title mt-4 mb-3">
            <i class="fas fa-table"></i>
            <span>Formularios creados</span>
        </div>

        <div class="table-wrap">
            <div class="table-responsive">
                <table id="tablaFormularios" class="table table-hover w-100">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Término</th>
                            <th>Estado</th>
                            <th>Tipo</th>
                            <th>División</th>
                            <th>Subdivisión</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($formularios)): ?>
                            <?php foreach ($formularios as $row): ?>
                                <?php
                                    $estado_texto      = obtenerTextoEstado($row['estado']);
                                    $estado_clase      = obtenerClaseEstado($row['estado']);
                                    $tipo_texto        = obtenerTextoTipo($row['tipo']);
                                    $tipo_clase        = obtenerClaseTipo($row['tipo']);
                                    $division_nombre   = !empty($row['division_nombre']) ? e($row['division_nombre']) : '<span class="table-muted">Sin División</span>';
                                    $subdivision_nombre= !empty($row['subdivision_nombre']) ? e($row['subdivision_nombre']) : '<span class="table-muted">Sin Subdivisión</span>';

                                    $editar_url = ((string)$row['tipo'] === '2')
                                        ? "mod_formulario/editar_formularioIW.php?id=" . urlencode($row['id'])
                                        : "mod_formulario/editar_formulario.php?id=" . urlencode($row['id']);
                                ?>
                                <tr>
                                    <td><strong>#<?php echo e($row['id']); ?></strong></td>
                                    <td class="campaign-name"><?php echo e($row['nombre']); ?></td>
                                    <td><?php echo e(formatearFecha($row['fechaInicio'])); ?></td>
                                    <td><?php echo e(formatearFecha($row['fechaTermino'])); ?></td>
                                    <td><span class="<?php echo e($estado_clase); ?>"><?php echo e($estado_texto); ?></span></td>
                                    <td><span class="<?php echo e($tipo_clase); ?>"><?php echo e($tipo_texto); ?></span></td>
                                    <td><?php echo $division_nombre; ?></td>
                                    <td><?php echo $subdivision_nombre; ?></td>
                                    <td>
                                        <div class="action-group">
                                            <a href="<?php echo e($editar_url); ?>" class="btn btn-warning btn-sm btn-icon" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <a href="mod_formulario/mapa_campana.php?id=<?php echo urlencode($row['id']); ?>" class="btn btn-info btn-sm btn-icon" title="Ver Mapa">
                                                <i class="fas fa-map-marker-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">No se encontraron formularios.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Scripts -->
<script>
    const tiposPregunta = <?php echo json_encode($tipos_pregunta, JSON_UNESCAPED_UNICODE); ?>;
</script>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script>
function actualizarDivisionesCrear() {
    var empresaSelect = document.getElementById('empresa_form');
    var divisionContainer = document.getElementById('divisionContainer');
    var empresaSeleccionada = empresaSelect ? empresaSelect.value : '';

    if (empresaSeleccionada === "") {
        divisionContainer.style.display = 'none';
        divisionContainer.innerHTML = '';
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'id_division';
        hiddenInput.value = '0';
        divisionContainer.appendChild(hiddenInput);

        const subCont = document.getElementById('subdivisionContainer');
        const subSel  = document.getElementById('id_subdivision');
        if (subCont && subSel) {
            subCont.style.display = 'none';
            subSel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option><option value="0">-- Sin Subdivisión --</option>';
        }
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'obtener_divisiones_formulario.php?id_empresa=' + encodeURIComponent(empresaSeleccionada), true);
    xhr.onload = function() {
        if (this.status == 200) {
            try {
                var divisiones = JSON.parse(this.responseText);
                divisionContainer.innerHTML = '';

                if (divisiones.length > 0) {
                    divisionContainer.style.display = 'block';

                    var label = document.createElement('label');
                    label.setAttribute('for', 'id_division');
                    label.textContent = 'División';
                    divisionContainer.appendChild(label);

                    var select = document.createElement('select');
                    select.id = 'id_division';
                    select.name = 'id_division';
                    select.className = 'form-control';

                    const tipoVal = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
                    select.required = (tipoVal === '1' || tipoVal === '3');
                    select.disabled = false;

                    var defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = '-- Seleccione una División --';
                    select.appendChild(defaultOption);

                    for (var i = 0; i < divisiones.length; i++) {
                        var option = document.createElement('option');
                        option.value = divisiones[i].id;
                        option.textContent = divisiones[i].nombre;
                        select.appendChild(option);
                    }

                    var noDivOption = document.createElement('option');
                    noDivOption.value = '0';
                    noDivOption.textContent = '-- Sin División --';
                    select.appendChild(noDivOption);

                    divisionContainer.appendChild(select);

                    select.addEventListener('change', function(){
                        const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
                        if (t === '1' || t === '3') {
                            cargarSubdivisionesCrear(this.value);
                        } else {
                            const subCont = document.getElementById('subdivisionContainer');
                            const subSel  = document.getElementById('id_subdivision');
                            if (subCont && subSel) {
                                subCont.style.display = 'none';
                                subSel.value = '0';
                                subSel.disabled = true;
                                subSel.required = false;
                            }
                        }
                    });
                } else {
                    divisionContainer.style.display = 'none';
                    divisionContainer.innerHTML = '';
                    var hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'id_division';
                    hiddenInput.value = '0';
                    divisionContainer.appendChild(hiddenInput);

                    cargarSubdivisionesCrear('0');
                }
            } catch(e) {
                console.error("Error JSON:", e);
            }
        }
    };
    xhr.send();
}

function cargarSubdivisionesCrear(divisionId) {
    const cont = document.getElementById('subdivisionContainer');
    const sel  = document.getElementById('id_subdivision');
    if (!cont || !sel) return;

    const tipoVal = document.getElementById('tipo') ? document.getElementById('tipo').value : '';

    if (!divisionId || divisionId === '' || divisionId === '0') {
        cont.style.display = (tipoVal === '1' || tipoVal === '3') ? 'block' : 'none';
        sel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option><option value="0">-- Sin Subdivisión --</option>';
        return;
    }

    if (tipoVal === '2') {
        cont.style.display = 'none';
        sel.value = '0';
        sel.disabled = true;
        sel.required = false;
        return;
    }

    fetch('obtener_subdivisiones.php?id_division=' + encodeURIComponent(divisionId))
        .then(r => r.json())
        .then(list => {
            if (!Array.isArray(list) || list.length === 0) {
                sel.innerHTML = '<option value="0">-- Sin Subdivisión --</option>';
                cont.style.display = 'block';
                return;
            }

            sel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option>';
            list.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.nombre;
                sel.appendChild(opt);
            });

            const none = document.createElement('option');
            none.value = '0';
            none.textContent = '-- Sin Subdivisión --';
            sel.appendChild(none);

            cont.style.display = 'block';
        })
        .catch(() => {
            sel.innerHTML = '<option value="0">-- Sin Subdivisión --</option>';
            cont.style.display = 'block';
        });
}

function actualizarDivisionesFiltro() {
    var empresaId = document.getElementById('empresa_filter').value;
    var divisionSelect = document.getElementById('division_filter');

    var subCont = document.getElementById('subdivision_filter_container');
    var subSel  = document.getElementById('subdivision_filter');
    if (subCont && subSel) {
        subSel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
        subSel.value = '0';
        subCont.style.display = 'none';
    }

    if (empresaId !== "0" && empresaId !== "") {
        $.ajax({
            url: 'obtener_divisiones_formulario.php',
            type: 'GET',
            data: { id_empresa: empresaId },
            dataType: 'json',
            success: function(response) {
                divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
                if (response.length > 0) {
                    for (var i = 0; i < response.length; i++) {
                        var option = document.createElement('option');
                        option.value = response[i].id;
                        option.text = response[i].nombre;
                        divisionSelect.appendChild(option);
                    }
                }
                document.getElementById('filterForm').submit();
            },
            error: function(xhr, status, error) {
                console.error('Error al obtener divisiones:', error);
                divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
            }
        });
    } else {
        divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
        document.getElementById('filterForm').submit();
    }
}

function cargarSubdivisionesFiltro(divisionId, preselectId) {
    const cont = document.getElementById('subdivision_filter_container');
    const sel  = document.getElementById('subdivision_filter');
    if (!cont || !sel) return;

    if (!divisionId || divisionId === '0') {
        cont.style.display = 'none';
        sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
        sel.value = '0';
        return;
    }

    fetch('obtener_subdivisiones.php?id_division=' + encodeURIComponent(divisionId))
        .then(r => r.json())
        .then(list => {
            sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
            if (Array.isArray(list) && list.length) {
                list.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.nombre;
                    sel.appendChild(opt);
                });
                cont.style.display = 'block';
                if (preselectId && preselectId > 0) {
                    sel.value = String(preselectId);
                }
            } else {
                cont.style.display = 'block';
            }
        })
        .catch(() => {
            sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
            cont.style.display = 'block';
        });
}

function addQuestionRow() {
    let container = document.getElementById('questionContainer');
    let index = container.children.length;

    let row = document.createElement('div');
    row.className = 'question-row';

    row.innerHTML =
        '<div class="form-group">' +
            '<label>Texto de la Pregunta:</label>' +
            '<input type="text" class="form-control" name="formQuestions[' + index + '][question_text]" placeholder="Ej: ¿Hay stock suficiente?" required>' +
        '</div>' +
        '<div class="form-group">' +
            '<label>Tipo de Pregunta:</label>' +
            '<select class="form-control" name="formQuestions[' + index + '][id_question_type]" onchange="toggleOptions(this, ' + index + ')" required>' +
                '<option value="">-- Seleccionar Tipo --</option>' +
                tiposPregunta.map(function(tp) {
                    return '<option value="' + tp.id + '">' + tp.name + '</option>';
                }).join('') +
            '</select>' +
        '</div>' +
        '<div class="form-group">' +
            '<label>¿Requerida?</label>' +
            '<input type="checkbox" name="formQuestions[' + index + '][is_required]" value="1">' +
        '</div>' +
        '<div class="form-group" id="optionContainer_' + index + '" style="display:none;">' +
            '<label>Opciones:</label>' +
            '<div id="optionRows_' + index + '"></div>' +
            '<button type="button" class="btn btn-sm btn-secondary" onclick="addOptionRow(' + index + ')">+ Opción</button>' +
        '</div>' +
        '<button type="button" class="btn btn-danger mt-2" onclick="this.parentNode.remove()">Eliminar Pregunta</button>';

    container.appendChild(row);
}

function toggleOptions(selectElem, questionIndex) {
    const val = parseInt(selectElem.value, 10);
    const optionContainer = document.getElementById('optionContainer_' + questionIndex);
    const optionRows = document.getElementById('optionRows_' + questionIndex);
    if (!optionContainer || !optionRows) return;

    optionContainer.style.display = 'none';
    optionRows.innerHTML = '';

    switch(val) {
        case 1:
            optionContainer.style.display = 'block';
            addFixedYesNoOptions(questionIndex);
            break;
        case 2:
        case 3:
            optionContainer.style.display = 'block';
            break;
    }
}

function addFixedYesNoOptions(questionIndex) {
    const optionRows = document.getElementById('optionRows_' + questionIndex);
    if (!optionRows) return;

    optionRows.innerHTML = '';
    ['Sí', 'No'].forEach(function(texto, idx) {
        let div = document.createElement('div');
        div.className = 'input-group mb-2';
        div.innerHTML =
            '<input type="text" class="form-control" name="formQuestions[' + questionIndex + '][options][' + idx + '][option_text]" value="' + texto + '" readonly>' +
            '<div class="input-group-append">' +
                '<button type="button" class="btn btn-secondary" disabled>Fijo</button>' +
            '</div>';
        optionRows.appendChild(div);
    });
}

function addOptionRow(questionIndex) {
    const cont = document.getElementById('optionRows_' + questionIndex);
    if (!cont) return;
    const optIndex = cont.children.length;

    let div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML =
        '<input type="text" class="form-control" name="formQuestions[' + questionIndex + '][options][' + optIndex + '][option_text]" placeholder="Texto opción">' +
        '<div class="input-group-append">' +
            '<button type="button" class="btn btn-danger" onclick="this.parentNode.parentNode.remove()">X</button>' +
        '</div>';
    cont.appendChild(div);
}

document.addEventListener('DOMContentLoaded', function(){
    function showGlobalSpinner() {
        document.getElementById('globalSpinner').classList.add('show');
    }

    document.querySelectorAll('a.btn-info, a.btn-warning').forEach(function(el){
        el.addEventListener('click', showGlobalSpinner);
    });

    const form = document.getElementById('formCrearFormulario');
    if (form) {
        form.addEventListener('submit', function(e){
            const tipo = document.getElementById('tipo')?.value;
            if (tipo === '1' || tipo === '3') {
                const fIni = document.getElementById('fechaInicio')?.value;
                const fFin = document.getElementById('fechaTermino')?.value;

                if (!fIni || !fFin) {
                    alert('Para Programadas/IPT debes completar las fechas.');
                    e.preventDefault();
                    return;
                }

                const ini = new Date(fIni);
                const fin = new Date(fFin);

                if (fin < ini) {
                    alert('La fecha de término debe ser mayor o igual a la de inicio.');
                    e.preventDefault();
                    return;
                }

                const csv = document.getElementById('csvFile');
                if (!csv || !csv.files || csv.files.length === 0) {
                    alert('Debes subir un CSV para campañas Programadas/IPT.');
                    e.preventDefault();
                    return;
                }
            }
        });
    }

    const divSel = document.getElementById('id_division');
    if (divSel) {
        divSel.addEventListener('change', function(){
            const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
            if (t === '1' || t === '3') {
                cargarSubdivisionesCrear(this.value);
            } else {
                const subCont = document.getElementById('subdivisionContainer');
                const subSel  = document.getElementById('id_subdivision');
                if (subCont && subSel) {
                    subCont.style.display = 'none';
                    subSel.value = '0';
                    subSel.disabled = true;
                    subSel.required = false;
                }
            }
        });
    }

    $('#tablaFormularios').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        order: [[0, 'desc']],
        responsive: false,
        language: {
            decimal: "",
            emptyTable: "No hay datos disponibles en la tabla",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            infoEmpty: "Mostrando 0 a 0 de 0 registros",
            infoFiltered: "(filtrado de _MAX_ registros totales)",
            lengthMenu: "Mostrar _MENU_ registros",
            loadingRecords: "Cargando...",
            processing: "Procesando...",
            search: "Buscar:",
            zeroRecords: "No se encontraron coincidencias",
            paginate: {
                first: "Primero",
                last: "Último",
                next: "Siguiente",
                previous: "Anterior"
            }
        }
    });
});
</script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
    const formCrear = document.getElementById('formCrearFormulario');
    const overlay = document.getElementById('formSubmitOverlay');
    const btnSubmit = document.getElementById('btnSubmitFormulario');

    if (formCrear) {
        formCrear.addEventListener('submit', function () {
            if (overlay) {
                overlay.classList.add('show');
            }

            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Procesando...';
            }
        });
    }
});
</script>
</body>
</html>
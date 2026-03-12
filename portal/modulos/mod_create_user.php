<?php
// mod_create_user.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_user/generate_token.php';

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('upper_safe')) {
    function upper_safe($value, string $fallback = 'N/A'): string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            $text = $fallback;
        }
        return mb_strtoupper($text, 'UTF-8');
    }
}

$empresas = obtenerEmpresasActivas();
$perfiles = obtenerPerfiles();
$usuarios = obtenerUsuarios([
    'estado'   => 'todos',
    'empresa'  => 'todos',
    'perfil'   => 'todos',
    'division' => 'todos'
]);

$empresa_id_session     = $_SESSION['empresa_id'] ?? 0;
$empresa_nombre_session = $_SESSION['empresa_nombre'] ?? '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generate_csrf_token(32);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Mentecreativa | Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        :root{
            --primary:#198754;
            --primary-dark:#157347;
            --secondary:#6c757d;
            --info:#0dcaf0;
            --warning:#ffc107;
            --danger:#dc3545;
            --bg:#f4f6f9;
            --card:#ffffff;
            --border:#e8edf2;
            --shadow:0 12px 32px rgba(0,0,0,.08);
            --radius:18px;
        }

        body{
            background: linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color:#1f2937;
        }

        .page-shell{
            max-width: 1450px;
            margin: 35px auto;
            padding: 0 16px;
        }

        .module-card{
            background:#fff;
            border-radius:24px;
            box-shadow:var(--shadow);
            padding:28px;
            border:1px solid rgba(255,255,255,.7);
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
            color:#111827;
        }

        .page-subtitle{
            margin:6px 0 0;
            color:#6b7280;
            font-size:.95rem;
        }

        .count-pill{
            background: linear-gradient(135deg, #198754 0%, #157347 100%);
            color:#fff;
            padding:12px 18px;
            border-radius:16px;
            min-width:180px;
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
            opacity:.92;
        }

        .toolbar-actions{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:20px;
        }

        .btn-modern{
            border-radius:12px;
            padding:.62rem 1rem;
            font-weight:600;
            box-shadow:0 6px 18px rgba(0,0,0,.08);
        }

        .btn-create{
            background:var(--primary);
            border-color:var(--primary);
            color:#fff;
        }

        .btn-create:hover{
            background:var(--primary-dark);
            border-color:var(--primary-dark);
            color:#fff;
        }

        .alert{
            border:none;
            border-radius:14px;
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
            text-align:center;
            vertical-align:middle;
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
            padding:.85rem .7rem;
        }

        .table tbody tr td:first-child{
            border-top-left-radius:12px;
            border-bottom-left-radius:12px;
        }

        .table tbody tr td:last-child{
            border-top-right-radius:12px;
            border-bottom-right-radius:12px;
        }

        .text-strong{
            font-weight:700;
            color:#111827;
        }

        .badge-status{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            padding:.42rem .8rem;
            font-size:.78rem;
            font-weight:700;
            white-space:nowrap;
        }

        .badge-active{
            background:#d1f7e1;
            color:#0f7a42;
        }

        .badge-inactive{
            background:#fde2e1;
            color:#b42318;
        }

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
        }

        .modal-body{
            background:#fbfcfe;
            padding:22px;
        }

        .modal-footer{
            border-top:none;
        }

        .nav-tabs .nav-link{
            border-radius:12px 12px 0 0;
            font-weight:600;
        }

        .section-title{
            font-size:1rem;
            font-weight:800;
            color:#1f2937;
            margin:1rem 0 .9rem;
            text-transform:uppercase;
            letter-spacing:.4px;
        }

        .custom-file-label,
        .form-control{
            border-radius:12px;
            min-height:42px;
            border:1px solid #dce3ea;
            box-shadow:none !important;
        }

        .form-group label,
        .col-form-label{
            font-weight:600;
            color:#374151;
        }

        .modal-body-scrollable{
            max-height:70vh;
            overflow-y:auto;
        }

        .form-submit-overlay{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(15,23,42,.45);
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
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select{
            border-radius:10px;
            border:1px solid #dce3ea;
            padding:.35rem .6rem;
        }

        .dt-buttons .btn{
            border-radius:10px !important;
            margin-right:6px;
        }

        @media (max-width: 767px){
            .page-title{ font-size:1.6rem; }
            .module-card{ padding:18px; }
            .toolbar-actions{ flex-direction:column; }
            .toolbar-actions .btn{ width:100%; }
        }
    </style>
</head>
<body>

<div id="formSubmitOverlay" class="form-submit-overlay">
    <div class="form-submit-box">
        <div class="spinner-border text-success mb-3" role="status" style="width:3rem;height:3rem;">
            <span class="sr-only">Procesando...</span>
        </div>
        <h5 class="mb-2">Procesando solicitud</h5>
        <p class="mb-3 text-muted">Guardando información, por favor espera...</p>
        <div class="progress" style="height:10px;border-radius:999px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:100%"></div>
        </div>
        <small class="d-block mt-3 text-muted">No cierres esta ventana ni recargues la página.</small>
    </div>
</div>

<div class="page-shell">
    <div class="module-card">

        <div class="page-header">
            <div>
                <h1 class="page-title">Gestión de Usuarios</h1>
                <p class="page-subtitle">Administra usuarios, perfiles, empresas, divisiones y carga masiva.</p>
            </div>

            <div class="count-pill">
                <span class="count"><?php echo count($usuarios); ?></span>
                <span class="label">usuarios registrados</span>
            </div>
        </div>

        <div class="toolbar-actions">
            <button type="button" class="btn btn-create btn-modern" data-toggle="modal" data-target="#crearUsuarioModal">
                <i class="fas fa-user-plus mr-1"></i> Crear Usuario
            </button>
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

        <div class="table-wrap">
            <div class="table-responsive">
                <table id="tablaUsuarios" class="table table-hover w-100">
                    <thead>
                        <tr>
                            <th>Nombre Completo</th>
                            <th>Usuario</th>
                            <th>Empresa</th>
                            <th>División</th>
                            <th>Perfil</th>
                            <th>Estado</th>
                            <th>Opciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <?php
                                $division = !empty($usuario['nombre_division']) ? $usuario['nombre_division'] : 'N/A';
                                $activo = !empty($usuario['activo']);
                            ?>
                            <tr id="usuario-<?php echo (int)$usuario['id']; ?>">
                                <td class="text-strong"><?php echo e(upper_safe($usuario['nombre_completo'])); ?></td>
                                <td><?php echo e(upper_safe($usuario['nombre_login'])); ?></td>
                                <td><?php echo e(upper_safe($usuario['nombre_empresa'])); ?></td>
                                <td><?php echo e(upper_safe($division)); ?></td>
                                <td><?php echo e(upper_safe($usuario['nombre_perfil'])); ?></td>
                                <td>
                                    <?php if ($activo): ?>
                                        <span class="badge-status badge-active">Activo</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-inactive">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <button type="button"
                                                class="btn btn-sm btn-warning btn-icon editar-usuario-btn"
                                                data-id="<?php echo (int)$usuario['id']; ?>"
                                                title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <?php if ($activo): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-danger btn-icon eliminar-usuario-btn"
                                                    data-id="<?php echo (int)$usuario['id']; ?>"
                                                    title="Desactivar">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-secondary btn-icon reactivar-usuario-btn"
                                                    data-id="<?php echo (int)$usuario['id']; ?>"
                                                    title="Reactivar">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal crear usuario -->
<div class="modal fade" id="crearUsuarioModal" tabindex="-1" role="dialog" aria-labelledby="crearUsuarioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Gestión de Usuarios</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <ul class="nav nav-tabs" id="tabUsuarios" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="crearUsuario-tab" data-toggle="tab" href="#crearUsuario" role="tab">Crear Usuario</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="cargaMasiva-tab" data-toggle="tab" href="#cargaMasiva" role="tab">Carga Masiva</a>
                    </li>
                </ul>

                <div class="tab-content" id="tabUsuariosContent">
                    <div class="tab-pane fade show active" id="crearUsuario" role="tabpanel">
                        <form class="form-horizontal mt-3 user-submit-form" method="POST" action="mod_user/procesar.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

                            <div class="section-title">Información del Trabajador</div>

                            <div class="form-group row">
                                <label for="rut" class="col-sm-2 col-form-label">Rut:*</label>
                                <div class="col-sm-10">
                                    <input type="text" name="rut" class="form-control" id="rut" placeholder="Ingrese el RUT" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="nombre" class="col-sm-2 col-form-label">Nombre:*</label>
                                <div class="col-sm-10">
                                    <input type="text" name="nombre" class="form-control" id="nombre" placeholder="Ingrese el nombre" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="apellido" class="col-sm-2 col-form-label">Apellido:*</label>
                                <div class="col-sm-10">
                                    <input type="text" name="apellido" class="form-control" id="apellido" placeholder="Ingrese el apellido" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="telefono" class="col-sm-2 col-form-label">Teléfono:*</label>
                                <div class="col-sm-10">
                                    <input type="tel" name="telefono" class="form-control" id="telefono" placeholder="Ingrese el teléfono" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="email" class="col-sm-2 col-form-label">Correo:*</label>
                                <div class="col-sm-10">
                                    <input type="email" name="email" class="form-control" id="email" placeholder="Ingrese el correo electrónico" required>
                                </div>
                            </div>

                            <div class="section-title">Información de Usuario</div>

                            <div class="form-group row">
                                <label for="usuario" class="col-sm-2 col-form-label">Usuario:*</label>
                                <div class="col-sm-10">
                                    <input type="text" name="usuario" class="form-control" id="usuario" placeholder="Ingrese el nombre de usuario" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="password" class="col-sm-2 col-form-label">Contraseña:*</label>
                                <div class="col-sm-10">
                                    <input type="password" name="password" class="form-control" id="password" placeholder="Ingrese la contraseña" required>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="selectPerfil" class="col-sm-2 col-form-label">Perfil:*</label>
                                <div class="col-sm-10">
                                    <select class="form-control" id="selectPerfil" name="id_perfil" required>
                                        <option value="">Seleccione un perfil</option>
                                        <?php foreach ($perfiles as $perfil): ?>
                                            <option value="<?php echo e($perfil['id']); ?>"><?php echo e($perfil['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="selectEmpresas" class="col-sm-2 col-form-label">Empresa:*</label>
                                <div class="col-sm-10">
                                    <select class="form-control" id="selectEmpresas" name="id_empresa" required>
                                        <option value="">Seleccione una empresa</option>
                                        <?php foreach ($empresas as $empresa): ?>
                                            <option value="<?php echo e($empresa['id']); ?>"><?php echo e($empresa['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row" id="divisionField" style="display:none;">
                                <label for="selectDivision" class="col-sm-2 col-form-label">División:*</label>
                                <div class="col-sm-10">
                                    <select class="form-control" id="selectDivision" name="id_division">
                                        <option value="">Seleccione una división</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="fotoPerfil" class="col-sm-2 col-form-label">Foto de perfil:</label>
                                <div class="col-sm-10">
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" name="fotoPerfil" id="fotoPerfil" accept=".jpg, .jpeg, .png">
                                        <label class="custom-file-label" for="fotoPerfil">Seleccionar imagen</label>
                                    </div>
                                    <small class="form-text text-muted">Formatos permitidos: JPG, JPEG, PNG. Tamaño máximo: 2MB.</small>
                                </div>
                            </div>

                            <div class="text-right mt-4">
                                <button type="submit" class="btn btn-primary btn-modern submit-btn">
                                    <i class="fas fa-save mr-1"></i> Crear Usuario
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="cargaMasiva" role="tabpanel">
                        <form class="form-horizontal mt-3 user-submit-form" method="POST" action="mod_user/procesar_csv.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

                            <div class="section-title">Carga Masiva de Usuarios</div>

                            <div class="form-group row">
                                <label for="csvFile" class="col-sm-2 col-form-label">Archivo CSV:*</label>
                                <div class="col-sm-10">
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" name="csvFile" id="csvFile" accept=".csv" required>
                                        <label class="custom-file-label" for="csvFile">Seleccionar archivo</label>
                                    </div>
                                    <small class="form-text text-muted">Formato requerido: CSV. Asegúrate de seguir la plantilla proporcionada.</small>
                                    <a href="mod_user/descargar_plantilla.php" class="btn btn-sm btn-outline-success mt-2">
                                        <i class="fas fa-download mr-1"></i> Descargar plantilla de prueba
                                    </a>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="empresa_id_csv" class="col-sm-2 col-form-label">Empresa:</label>
                                <div class="col-sm-10">
                                    <select class="form-control" id="empresa_id_csv" name="empresa_id_csv">
                                        <option value="">Seleccione una empresa</option>
                                        <?php foreach ($empresas as $empresa): ?>
                                            <option value="<?php echo e($empresa['id']); ?>"><?php echo e($empresa['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group row">
                                <label for="division_id_csv" class="col-sm-2 col-form-label">División:</label>
                                <div class="col-sm-10">
                                    <select class="form-control" id="division_id_csv" name="division_id">
                                        <option value="">Seleccione una división</option>
                                    </select>
                                </div>
                            </div>

                            <div class="text-right mt-4">
                                <button type="submit" class="btn btn-primary btn-modern submit-btn">
                                    <i class="fas fa-upload mr-1"></i> Cargar Usuarios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal editar -->
<div class="modal fade" id="editarUsuarioModal" tabindex="-1" role="dialog" aria-labelledby="editarUsuarioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Usuario</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form id="editarUsuarioForm" class="user-submit-form" method="POST" action="mod_user/procesar_modificacion.php" enctype="multipart/form-data">
                <div class="modal-body modal-body-scrollable">
                    <input type="hidden" name="usuario_id" id="editar_usuario_id">
                    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

                    <div class="section-title">Información del Trabajador</div>

                    <div class="form-group row">
                        <label for="editar_rut" class="col-sm-2 col-form-label">Rut:</label>
                        <div class="col-sm-10">
                            <input type="text" name="rut" class="form-control" id="editar_rut" required>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_nombre" class="col-sm-2 col-form-label">Nombre:</label>
                        <div class="col-sm-10">
                            <input type="text" name="nombre" class="form-control" id="editar_nombre" required>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_apellido" class="col-sm-2 col-form-label">Apellido:</label>
                        <div class="col-sm-10">
                            <input type="text" name="apellido" class="form-control" id="editar_apellido" required>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_telefono" class="col-sm-2 col-form-label">Teléfono:</label>
                        <div class="col-sm-10">
                            <input type="tel" name="telefono" class="form-control" id="editar_telefono" required>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_email" class="col-sm-2 col-form-label">Correo:</label>
                        <div class="col-sm-10">
                            <input type="email" name="email" class="form-control" id="editar_email" required>
                        </div>
                    </div>

                    <div class="section-title">Información de Usuario</div>

                    <div class="form-group row">
                        <label for="editar_usuario" class="col-sm-2 col-form-label">Usuario:</label>
                        <div class="col-sm-10">
                            <input type="text" name="usuario" class="form-control" id="editar_usuario" required>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_perfil" class="col-sm-2 col-form-label">Perfil:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_perfil" name="id_perfil" required>
                                <option value="">Seleccione un perfil</option>
                                <?php foreach ($perfiles as $perfil): ?>
                                    <option value="<?php echo e($perfil['id']); ?>"><?php echo e($perfil['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_empresa" class="col-sm-2 col-form-label">Empresa:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_empresa" name="id_empresa" required>
                                <option value="">Seleccione una empresa</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo e($empresa['id']); ?>"><?php echo e($empresa['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group row" id="editar_divisionField" style="display:none;">
                        <label for="editar_division" class="col-sm-2 col-form-label">División:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_division" name="id_division">
                                <option value="">Seleccione una división</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">Contraseña:</label>
                        <div class="col-sm-10">
                            <button type="button" id="btnCambiarClave" class="btn btn-outline-secondary">
                                <i class="fas fa-key"></i> Cambiar contraseña
                            </button>
                        </div>
                    </div>

                    <div id="bloqueCambioClave" style="display:none;">
                        <div class="form-group row">
                            <label for="new_password" class="col-sm-2 col-form-label">Nueva contraseña:</label>
                            <div class="col-sm-10">
                                <input type="password" name="new_password" class="form-control" id="new_password">
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="confirm_password" class="col-sm-2 col-form-label">Confirmar contraseña:</label>
                            <div class="col-sm-10">
                                <input type="password" name="confirm_password" class="form-control" id="confirm_password">
                            </div>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label for="editar_fotoPerfil" class="col-sm-2 col-form-label">Foto de perfil:</label>
                        <div class="col-sm-10">
                            <div class="mb-2">
                                <img src="" alt="Foto actual" id="actual_fotoPerfil" style="max-width:150px;border-radius:12px;">
                            </div>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="fotoPerfil" id="editar_fotoPerfil" accept=".jpg, .jpeg, .png">
                                <label class="custom-file-label" for="editar_fotoPerfil">Seleccionar nueva imagen</label>
                            </div>
                            <small class="form-text text-muted">Formatos permitidos: JPG, JPEG, PNG. Máximo 2MB.</small>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-modern" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-modern submit-btn">
                        <i class="fas fa-save mr-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>

<script>
$(function () {
    bsCustomFileInput.init();

    const ROLES_REQUIEREN_DIVISION = ['coordinador', 'editor', 'ejecutor', 'visor'];

    function requiereDivision(profileText) {
        return ROLES_REQUIEREN_DIVISION.includes((profileText || '').toLowerCase().trim());
    }

    function showOverlay() {
        $('#formSubmitOverlay').addClass('show');
    }

    function hideOverlay() {
        $('#formSubmitOverlay').removeClass('show');
    }

    function bloquearSubmit($form) {
        const $btn = $form.find('.submit-btn');
        $btn.prop('disabled', true);
        $btn.data('original-html', $btn.html());
        $btn.html('<i class="fas fa-spinner fa-spin mr-1"></i> Procesando...');
    }

    function cargarDivisiones(empresaId, $select, callback) {
        if (!empresaId) {
            $select.html('<option value="">Seleccione una división</option>');
            if (typeof callback === 'function') callback(false);
            return;
        }

        $.ajax({
            url: 'mod_user/obtener_divisiones.php',
            type: 'GET',
            data: { empresa_id: empresaId },
            success: function (html) {
                $select.html(html);
                if (typeof callback === 'function') callback(true);
            },
            error: function () {
                $select.html('<option value="">Seleccione una división</option>');
                if (typeof callback === 'function') callback(false);
            }
        });
    }

    function controlarCampoDivisionCrear() {
        const perfilSeleccionado = $('#selectPerfil option:selected').text();
        const divisionsAvailable = $('#selectDivision option').length > 1;

        if (requiereDivision(perfilSeleccionado) && divisionsAvailable) {
            $('#divisionField').show();
            $('#selectDivision').attr('required', 'required');
        } else {
            $('#divisionField').hide();
            $('#selectDivision').removeAttr('required').val('');
        }
    }

    function controlarCampoDivisionEditar() {
        const perfilSeleccionado = $('#editar_perfil option:selected').text();
        const divisionsAvailable = $('#editar_division option').length > 1;

        if (requiereDivision(perfilSeleccionado) && divisionsAvailable) {
            $('#editar_divisionField').show();
            $('#editar_division').attr('required', 'required');
        } else {
            $('#editar_divisionField').hide();
            $('#editar_division').removeAttr('required').val('');
        }
    }

    $('#tablaUsuarios').DataTable({
        dom: '<"d-flex flex-wrap justify-content-between align-items-center mb-3"Bf>rt<"d-flex flex-wrap justify-content-between align-items-center mt-3"lip>',
        pageLength: 10,
        lengthMenu: [[10,25,50,100],[10,25,50,100]],
        order: [[0, 'asc']],
        autoWidth: false,
        buttons: [
            { extend: 'colvis', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'copyHtml5', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'csvHtml5', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'excelHtml5', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'pdfHtml5', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'print', className: 'btn btn-outline-secondary btn-sm' }
        ],
        language: {
            decimal: "",
            emptyTable: "No hay usuarios disponibles",
            info: "Mostrando _START_ a _END_ de _TOTAL_ usuarios",
            infoEmpty: "Mostrando 0 a 0 de 0 usuarios",
            infoFiltered: "(filtrado de _MAX_ usuarios totales)",
            lengthMenu: "Mostrar _MENU_ usuarios",
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

    $('#selectEmpresas').on('change', function () {
        const empresaId = $(this).val();
        cargarDivisiones(empresaId, $('#selectDivision'), function () {
            controlarCampoDivisionCrear();
        });
    });

    $('#selectPerfil').on('change', controlarCampoDivisionCrear);

    $('#empresa_id_csv').on('change', function () {
        cargarDivisiones($(this).val(), $('#division_id_csv'));
    });

    $('#editar_perfil').on('change', controlarCampoDivisionEditar);

    $('#editar_empresa').on('change', function () {
        const empresaId = $(this).val();
        cargarDivisiones(empresaId, $('#editar_division'), function () {
            controlarCampoDivisionEditar();
        });
    });

    $(document).on('click', '.editar-usuario-btn', function () {
        const usuarioId = $(this).data('id');

        $.ajax({
            url: 'mod_user/get_user.php',
            type: 'GET',
            data: { id: usuarioId },
            dataType: 'json',
            success: function (response) {
                if (response.status !== 'success') {
                    alert(response.message || 'No fue posible cargar el usuario.');
                    return;
                }

                const data = response.data || {};

                $('#editar_usuario_id').val(data.id || '');
                $('#editar_rut').val(data.rut || '');
                $('#editar_nombre').val(data.nombre || '');
                $('#editar_apellido').val(data.apellido || '');
                $('#editar_telefono').val(data.telefono || '');
                $('#editar_email').val(data.email || '');
                $('#editar_usuario').val(data.usuario || '');
                $('#editar_perfil').val(data.id_perfil || '');
                $('#editar_empresa').val(data.id_empresa || '');
                $('#actual_fotoPerfil').attr('src', data.fotoPerfil ? data.fotoPerfil : 'ruta_por_defecto.jpg');

                cargarDivisiones(data.id_empresa || '', $('#editar_division'), function () {
                    $('#editar_division').val(data.id_division || '');
                    controlarCampoDivisionEditar();
                    $('#editarUsuarioModal').modal('show');
                });
            },
            error: function () {
                alert('Hubo un error al obtener los datos del usuario.');
            }
        });
    });

    $('#btnCambiarClave').on('click', function () {
        $('#bloqueCambioClave').toggle();
        if ($('#bloqueCambioClave').is(':hidden')) {
            $('#new_password, #confirm_password').val('');
        }
    });

    $('#editarUsuarioModal').on('show.bs.modal', function () {
        $('#bloqueCambioClave').hide();
        $('#new_password, #confirm_password').val('');
    });

    $(document).on('click', '.eliminar-usuario-btn', function () {
        const usuarioId = $(this).data('id');
        const fila = $('#usuario-' + usuarioId);

        if (!confirm('¿Estás seguro de que deseas desactivar este usuario?')) return;

        $.ajax({
            url: 'mod_user/eliminar_usuario.php',
            type: 'POST',
            data: {
                id: usuarioId,
                csrf_token: '<?php echo e($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success') {
                    fila.find('td:nth-child(6)').html('<span class="badge-status badge-inactive">Inactivo</span>');
                    fila.find('.eliminar-usuario-btn').replaceWith(
                        '<button type="button" class="btn btn-sm btn-secondary btn-icon reactivar-usuario-btn" data-id="' + usuarioId + '" title="Reactivar"><i class="fas fa-undo"></i></button>'
                    );
                } else {
                    alert('Error: ' + (response.message || 'No fue posible desactivar el usuario.'));
                }
            },
            error: function () {
                alert('Hubo un error al desactivar el usuario.');
            }
        });
    });

    $(document).on('click', '.reactivar-usuario-btn', function () {
        const usuarioId = $(this).data('id');
        const fila = $('#usuario-' + usuarioId);

        if (!confirm('¿Estás seguro de que deseas reactivar este usuario?')) return;

        $.ajax({
            url: 'mod_user/reactivar_usuario.php',
            type: 'POST',
            data: {
                id: usuarioId,
                csrf_token: '<?php echo e($_SESSION['csrf_token']); ?>'
            },
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success') {
                    fila.find('td:nth-child(6)').html('<span class="badge-status badge-active">Activo</span>');
                    fila.find('.reactivar-usuario-btn').replaceWith(
                        '<button type="button" class="btn btn-sm btn-danger btn-icon eliminar-usuario-btn" data-id="' + usuarioId + '" title="Desactivar"><i class="fas fa-trash-alt"></i></button>'
                    );
                } else {
                    alert('Error: ' + (response.message || 'No fue posible reactivar el usuario.'));
                }
            },
            error: function () {
                alert('Hubo un error al reactivar el usuario.');
            }
        });
    });

    $('.user-submit-form').on('submit', function () {
        const $form = $(this);
        bloquearSubmit($form);
        showOverlay();
    });

    controlarCampoDivisionCrear();
});
</script>

</body>
</html>
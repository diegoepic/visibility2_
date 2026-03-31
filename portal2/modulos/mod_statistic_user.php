<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_user/generate_token.php';

// Obtener datos necesarios
$empresas = obtenerEmpresasActivas();
$perfiles = obtenerPerfiles(); // Obtener los perfiles
$usuarios = obtenerUsuariosConActividad();

$totalUsuarios = is_array($usuarios) ? count($usuarios) : 0;
// Obtener datos de sesión
$empresa_id_session = $_SESSION['empresa_id'];
$empresa_nombre_session = $_SESSION['empresa_nombre'];

// Generar el token CSRF si no existe
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
    <!-- Meta y Enlaces -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.2.2/css/buttons.bootstrap.min.css">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
       

    <style>
        .table-responsive {
          margin-top: 2%;
          border: 1px solid #dee2e6;
          border-radius: 0.25rem;
          box-shadow: 0 2px 6px rgba(0,0,0,0.05);
          overflow: hidden;
        }
        
        .card-header.bg-success {
          /*background-color: #0069d9 !important;
          border-bottom: none;*/
            background: #93C01F;
            background-image: linear-gradient(to left, #E7F4CB 0%, #CBE47B 40%, #A7D13A 75%, #93C01F 100%);
            color: #fff;
            padding: 15px;
            border-radius: 3px 3px 0 0;          
        }
        .card-header .card-title {
          color: #fff;
          font-weight: 500;
        }
        
        .table thead th {
            border: none !important;
            background: #1f2937;
            color: #fff;
            font-size:80%;
        }
                        
        .modal-body-scrollable {
            max-height: 70vh;
            overflow-y: auto;
        }
        .material-row { margin-bottom: 10px; }
        .remove-material-btn { margin-top: 32px; }
        thead input {width: 100%;padding: 3px;box-sizing: border-box;}
        .pagination>li>a, .pagination>li>span {
        position: relative;
        float: left;
        padding: 6px 12px;
        margin-left: -1px;
        line-height: 1.42857143;
        color: #337ab7;
        text-decoration: none;
        background-color: #fff;
        border: 1px solid #ddd;
    }        
        .uppercase { text-transform: uppercase; }
        .btn-default {
            color: #333;
            background-color: #fff;
            border-color: #ccc;
        }
        
        .dt-buttons { 
          float: left; 
          margin-right: 10px; 
        }
        .dataTables_filter { 
          float: right; 
        }
        .btn-default{
        background-color: #f8f9fa;
        border-color: #ddd;
        color: #444;
        }
        .table-responsive{
            margin-top:2%;
        }
        .container-fluid{
            margin-top:3%;
        }        
        .mb-2{
            margin-top:1%;
        }
        
        .bg-success-mc {
            /* background-color: #28a745 !important; */
            background: #93C01F;
            background-image: linear-gradient(to left, #E7F4CB 0%, #CBE47B 40%, #A7D13A 75%, #93C01F 100%);
            color: #fff;
            padding: 15px;
            border-radius: 3px 3px 0 0;
        } 
        div.dataTables_wrapper div.dataTables_length select {
            margin-left: 5px;
            margin-right: 5px;
        }        
        div.dataTables_wrapper div.dataTables_info {
            padding-top: 0px!important;
            margin-left: 1%;
        } 
        .wrapper {
            max-width: 1450px;
            margin: 35px auto;
            padding: 0 16px;
        }
        .module-card{
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            padding: 28px;
            border: 1px solid #e5e7eb;
        }
.stats-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    flex-wrap:wrap;
    margin-bottom:22px;
}

.stats-title{
    margin:0;
    font-size:1.8rem;
    font-weight:800;
    color:#0f172a;
    letter-spacing:.3px;
}

.stats-subtitle{
    margin:6px 0 0 0;
    color:#64748b;
    font-size:.98rem;
}

.stats-box{
    min-width:210px;
    background:linear-gradient(135deg,#198754 0%,#157347 100%);
    color:#fff;
    border-radius:18px;
    padding:16px 20px;
    box-shadow:0 12px 25px rgba(25,135,84,.18);
}

.stats-number{
    display:block;
    font-size:1.9rem;
    font-weight:800;
    line-height:1;
    margin-bottom:4px;
}

.stats-label{
    font-size:.95rem;
    opacity:.95;
}

#example{
    width:100% !important;
    border-collapse:separate !important;
    border-spacing:0 12px !important;
}

#example thead th{
    background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%) !important;
    color:#fff !important;
    border:none !important;
    padding:18px 18px !important;
    font-weight:800 !important;
    text-transform:none !important;
    position:relative;
    padding-right:34px !important;
    white-space:nowrap;
}

#example thead th:first-child{
    border-top-left-radius:16px;
    border-bottom-left-radius:16px;
}

#example thead th:last-child{
    border-top-right-radius:16px;
    border-bottom-right-radius:16px;
}

#example tbody td{
    background:#fff;
    border:none !important;
    padding:18px 16px !important;
    vertical-align:middle;
    box-shadow:0 7px 18px rgba(15,23,42,.05);
    color:#1f2937;
}

#example tbody td:first-child{
    border-top-left-radius:14px;
    border-bottom-left-radius:14px;
    font-weight:700;
    color:#0f172a;
}

#example tbody td:last-child{
    border-top-right-radius:14px;
    border-bottom-right-radius:14px;
}

#example tbody tr:hover td{
    box-shadow:0 10px 24px rgba(15,23,42,.08);
}

#example .badge{
    font-size:.85rem;
    padding:.55em .9em;
    border-radius:999px;
}

#example .badge-success{
    background:#d1fae5;
    color:#047857;
}

#example .badge-danger{
    background:#fee2e2;
    color:#c2410c;
}

/* Hacer visibles las flechas de orden */
table.dataTable thead th.sorting,
table.dataTable thead th.sorting_asc,
table.dataTable thead th.sorting_desc{
    background-image:none !important;
}

table.dataTable thead th.sorting:before,
table.dataTable thead th.sorting_asc:before,
table.dataTable thead th.sorting_desc:before{
    content:"▲";
    position:absolute;
    right:12px;
    top:calc(50% - 11px);
    font-size:10px;
    color:#cbd5e1;
    opacity:.45;
}

table.dataTable thead th.sorting:after,
table.dataTable thead th.sorting_asc:after,
table.dataTable thead th.sorting_desc:after{
    content:"▼";
    position:absolute;
    right:12px;
    top:calc(50% + 1px);
    font-size:10px;
    color:#cbd5e1;
    opacity:.45;
}

table.dataTable thead th.sorting_asc:before{
    color:#ffffff;
    opacity:1;
}

table.dataTable thead th.sorting_asc:after{
    opacity:.18;
}

table.dataTable thead th.sorting_desc:after{
    color:#ffffff;
    opacity:1;
}

table.dataTable thead th.sorting_desc:before{
    opacity:.18;
}

.dt-buttons .btn{
    border:none !important;
    border-radius:12px !important;
    padding:8px 14px !important;
    background:#6c757d !important;
    color:#fff !important;
}

.dataTables_filter label{
    font-weight:700;
    color:#0f172a;
}

.dataTables_filter input{
    border:1px solid #cbd5e1 !important;
    border-radius:12px !important;
    padding:6px 12px !important;
}

@media (max-width: 768px){
    .stats-title{
        font-size:1.35rem;
    }

    .stats-box{
        width:100%;
    }
}
.table-bordered {
    border: 0px solid #dee2e6;
}
        .page-title,
        .nav-link,
        .btn,
        .count,
        .mr-2,
        th,
        tr,
        td {
            font-size: 85% !important;
        }    
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <div class="module-card">
    <section class="content">
        <div class="container-fluid">
            <!-- Mostrar mensajes de éxito o error -->
            <?php if (isset($_SESSION['success_formulario'])): ?>
                <div class="alert alert-success">
                    <?php
                    echo $_SESSION['success_formulario'];
                    unset($_SESSION['success_formulario']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_formulario'])): ?>
                <div class="alert alert-danger">
                    <?php
                    echo $_SESSION['error_formulario'];
                    unset($_SESSION['error_formulario']);
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="stats-header">
                <div>
                    <h2 class="stats-title">ESTADÍSTICAS DE USUARIO</h2>
                    <p class="stats-subtitle">
                        Visualiza actividad de acceso, último login, cantidad de logeos y estado de cada usuario.
                    </p>
                </div>
            
                <div class="stats-box">
                    <span class="stats-number"><?php echo (int)$totalUsuarios; ?></span>
                    <span class="stats-label">usuarios analizados</span>
                </div>
            </div>            
<table id="example" class="table table-sm table-bordered table-hover" cellspacing="0" width="100%">
              <thead>
                <tr>
                  <th>Nombre Completo</th>
                  <th>Usuario</th>
                  <th>Empresa</th>
                  <th>División</th>
                  <th>Perfil</th>
                  <th>Fecha Creación</th>
                  <th>Último Login</th>
                  <th>Logeos</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($usuarios as $usuario): ?>
                  <tr>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_completo'], 'UTF-8')); ?></td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_login'], 'UTF-8')); ?></td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_empresa'], 'UTF-8')); ?></td>
                    <td class="uppercase">
                      <?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_division'] ?: 'N/A', 'UTF-8')); ?>
                    </td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_perfil'], 'UTF-8')); ?></td>
                    <td><?php echo htmlspecialchars($usuario['fechaCreacion']); ?></td>
                    <td>
                      <?php echo $usuario['UltimoLogin'] ? htmlspecialchars($usuario['UltimoLogin']) : '<span class="text-muted">Nunca</span>'; ?>
                    </td>
                    <td><?php echo (int)$usuario['logeos']; ?></td>
                    <td>
                      <?php if ($usuario['activo']): ?>
                        <span class="badge badge-success">Activo</span>
                      <?php else: ?>
                        <span class="badge badge-danger">Inactivo</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

        </div>
    </section>
</div>    
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- Bootstrap 4 desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/visibility2/portal/dist/js/jquery.dataTables.min.js"></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js'></script>
<script src='https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js'></script>
<!-- bs-custom-file-input -->
<script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>

<script>
$(document).ready(function () {
    document.title = "Estadísticas de Usuario";

    if ($.fn.DataTable.isDataTable('#example')) {
        $('#example').DataTable().destroy();
    }

    $('#example').DataTable({
        dom: '<"dt-buttons"Bf><"clear">lirtp',
        paging: true,
        autoWidth: false,
        pageLength: 10,
        order: [[7, 'desc']],
        buttons: [
            "colvis",
            "copyHtml5",
            "csvHtml5",
            "excelHtml5",
            "pdfHtml5",
            "print"
        ],
        language: {
            search: "Buscar:",
            lengthMenu: "Mostrar _MENU_ registros",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            infoEmpty: "Mostrando 0 a 0 de 0 registros",
            infoFiltered: "(filtrado de _MAX_ registros totales)",
            zeroRecords: "No se encontraron resultados",
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



</body>
</html>

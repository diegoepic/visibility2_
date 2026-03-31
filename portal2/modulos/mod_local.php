<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$perfilUser = $_SESSION['perfil_nombre'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Incluir la conexión a la base de datos y funciones necesarias
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';


// Obtener datos para poblar selects
$empresas    = obtenerEmpresasActivas();
$regiones    = obtenerRegiones();
$cuentas     = obtenerCuentas();
$subcanales  = obtenerSubcanales(); 
$canales      = obtenerCanales();
$zonas       = obtenerZonas();
$jefesVenta  = obtenerJefesVenta();
$vendedores  = obtenerVendedores();
$distritos   = obtenerDistritos();
$division   = obtenerDivision();

$empresa_id = intval($_SESSION['empresa_id']);
$stmt_div = $conn->prepare("
  SELECT id, nombre 
    FROM division_empresa 
   WHERE estado = 1 
     AND id_empresa = ? 
   ORDER BY nombre ASC
");
$stmt_div->bind_param("i", $empresa_id);
$stmt_div->execute();
$divisiones = $stmt_div->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_div->close();


?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mentecreativa | Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <!-- CSS de terceros -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- Estilos del tema AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <style>
        /* Estilos personalizados para el mapa */
        #map, #mapEdit {
            height: 400px; 
            width: 100%;
            margin-bottom: 15px;
        }
        .container-fluid{
            margin-top:2%;
        }
        .btn-secondary{
            background-color: #93C01F!important;
            border-color: #93C01F!important;
        }
        .btn {
            font-size: 80%!important;
        }
        .card-info:not(.card-outline)>.card-header{
            background-color:#F7F7F7!important;
            color:#9C9C9C;
        }
        .card-success:not(.card-outline)>.card-header {
            background-color:#F7F7F7!important;
            color:#9C9C9C;
        }
        .card-title{
            font-size:85%!important;            
        }
        .col-12,
        .nav-link,
        .form-label,
        .form-control,
        .mr-2,
        tr,
        td {
            font-size: 85% !important;
        }
    </style>
    <!-- DEFINICIÓN DE LA FUNCIÓN mostrarAlertaPrincipal -->
    <script>
    function mostrarAlertaPrincipal(tipo, mensaje) {
        var alertaHTML = '<div class="alert alert-' + tipo + ' alert-dismissible fade show" role="alert">' +
                         mensaje +
                         '<button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">' +
                         '<span aria-hidden="true">&times;</span>' +
                         '</button>' +
                         '</div>';
        document.getElementById('alertaPrincipal').innerHTML = alertaHTML;
    }
    </script>
</head>

<body class="iframe-mode">
<div class="wrapper">
    <!-- Contenedor de Alertas Principal -->
    <div id="alertaPrincipal"></div>

    <div class="content-wrapper" style="background-color: white;">
        <div class="container-fluid mt-3">
            <?php
            // Mostrar alertas (crear, editar, carga masiva)
            if (isset($_SESSION['success_crear_local'])) {
                echo "<script>mostrarAlertaPrincipal('success', '" . addslashes($_SESSION['success_crear_local']) . "');</script>";
                unset($_SESSION['success_crear_local']);
            }
            if (isset($_SESSION['error_crear_local'])) {
                echo "<script>mostrarAlertaPrincipal('danger', '" . addslashes($_SESSION['error_crear_local']) . "');</script>";
                unset($_SESSION['error_crear_local']);
            }
            if (isset($_SESSION['success_edit_local'])) {
                echo "<script>mostrarAlertaPrincipal('success', '" . addslashes($_SESSION['success_edit_local']) . "');</script>";
                unset($_SESSION['success_edit_local']);
            }
            if (isset($_SESSION['error_edit_local'])) {
                echo "<script>mostrarAlertaPrincipal('danger', '" . addslashes($_SESSION['error_edit_local']) . "');</script>";
                unset($_SESSION['error_edit_local']);
            }
            if (isset($_SESSION['success_csv_local'])) {
                echo "<script>mostrarAlertaPrincipal('success', '" . addslashes($_SESSION['success_csv_local']) . "');</script>";
                unset($_SESSION['success_csv_local']);
            }
            if (isset($_SESSION['error_csv_local'])) {
                echo "<script>mostrarAlertaPrincipal('danger', '" . addslashes($_SESSION['error_csv_local']) . "');</script>";
                unset($_SESSION['error_csv_local']);
            }
            ?>


        
                  
            <section class="content">
                <div class="container-fluid">
                    <!-- Botones para abrir los modals de creación -->
          <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>       
                    <div class="row mb-3">
                        <div class="col-12 d-flex justify-content-start">
                            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#modalCrearLocal">
                                <i class="fas fa-plus-circle"></i> Crear Local
                            </button>
                            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#modalCrearComuna">
                                <i class="fas fa-plus-circle"></i> Crear Comuna
                            </button>
                            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#modalCrearCadena">
                                <i class="fas fa-plus-circle"></i> Crear Cadena
                            </button>
                            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#modalCrearCuenta">
                                <i class="fas fa-plus-circle"></i> Crear Cuenta
                            </button>
                            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#modalCrearCanal">
                                <i class="fas fa-plus-circle"></i> Crear Canal
                            </button>
                            <!-- NUEVO: Botón para Crear Subcanal -->
                            <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#modalCrearSubcanal">
                                <i class="fas fa-plus-circle"></i> Crear Subcanal
                            </button>
                            <!-- FIN NUEVO -->
                        </div>
                    </div>
          <?php endif; ?>  
                    <!-- Navegación por Pestañas -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <ul class="nav nav-tabs" id="localTabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="lista-locales-tab" data-toggle="tab" href="#lista-locales" role="tab" aria-controls="lista-locales" aria-selected="true">Lista de Locales</a>
                                </li>
          <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>                                    
                                <li class="nav-item">
                                    <a class="nav-link" id="carga-masiva-tab" data-toggle="tab" href="#carga-masiva" role="tab" aria-controls="carga-masiva" aria-selected="false">Carga Masiva</a>
                                </li>
          <?php endif; ?>                                 
                            </ul>
<div class="tab-content" id="localTabsContent">
    <!-- Pestaña Lista de Locales -->
    <div class="tab-pane fade show active" id="lista-locales" role="tabpanel" aria-labelledby="lista-locales-tab">
        <div class="card card-info">
            <div class="card-header">
                <h3 class="card-title">Lista de Locales</h3>
            </div>

            <div class="card-body">
                <!-- 🧭 Filtros -->
                <form id="filtrosLocales" class="mb-4">
                    <div class="container-fluid">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="filtroID" class="form-label">ID Local</label>
                                <input type="number" class="form-control" id="filtroID" name="id_local" placeholder="ID del local">
                            </div>

                            <div class="col-md-3">
                                <label for="filtroCodigo" class="form-label">Código Local</label>
                                <input type="text" class="form-control" id="filtroCodigo" name="codigo" placeholder="Código del local">
                            </div>

                            <div class="col-md-3">
                                <label for="filtroCanal" class="form-label">Canal</label>
                                <select class="form-control" id="filtroCanal" name="canal_id">
                                    <option value="">Todos los Canales</option>
                                    <?php foreach ($canales as $canal): ?>
                                        <option value="<?php echo htmlspecialchars($canal['id']); ?>">
                                            <?php echo htmlspecialchars($canal['nombre_canal']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="filtroSubcanal" class="form-label">Subcanal</label>
                                <select class="form-control" id="filtroSubcanal" name="subcanal_id" disabled>
                                    <option value="">Todos los Subcanales</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="filtroNombre" class="form-label">Nombre</label>
                                <input type="text" class="form-control" id="filtroNombre" name="nombre" placeholder="Buscar por nombre">
                            </div>

                            <div class="col-md-3">
                                <label for="filtroEmpresa" class="form-label">Empresa</label>
                                <select class="form-control" id="filtroEmpresa" name="empresa_id">
                                    <option value="">Todas las Empresas</option>
                                    <?php foreach ($empresas as $empresa): ?>
                                        <option value="<?php echo htmlspecialchars($empresa['id']); ?>">
                                            <?php echo htmlspecialchars($empresa['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="filtroDivision" class="form-label">División</label>
                                <select class="form-control" id="filtroDivision" name="division_id" disabled>
                                    <option value="">Todas las Divisiones</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="filtroRegion" class="form-label">Región</label>
                                <select class="form-control" id="filtroRegion" name="region_id">
                                    <option value="">Todas las Regiones</option>
                                    <?php foreach ($regiones as $region): ?>
                                        <?php
                                            $regionName = $region['region'];
                                            if (strpos($regionName, '-') !== false) {
                                                $parts = explode('-', $regionName, 2);
                                                $regionName = trim($parts[1]);
                                            }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($region['id']); ?>">
                                            <?php echo htmlspecialchars($regionName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label for="filtroComuna" class="form-label">Comuna</label>
                                <select class="form-control" id="filtroComuna" name="comuna_id" disabled>
                                    <option value="">Todas las Comunas</option>
                                </select>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="row mt-4">
                            <div class="col text-end">
                                <button type="button" id="btnFiltrar" class="btn btn-primary me-2">
                                    <i class="fas fa-filter"></i> Filtrar
                                </button>
                                <button type="button" id="btnResetear" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Resetear
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- 🧾 Tabla -->
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="tablaLocales">
                        <thead class="thead-light">
                            <tr>
                                <th>ID</th>
                                <th>Código</th>
                                <th>Canal</th>
                                <th>Nombre</th>
                                <th>Dirección</th>
                                <th>Cuenta</th>
                                <th>Cadena</th>
                                <th>Comuna</th>
                                <th>Región</th>
                                <th>Empresa</th>
                                <th>Latitud</th>
                                <th>Longitud</th>
                                <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>
                                    <th>Opciones</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div id="pagination-controls" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pestaña Carga Masiva -->
    <div class="tab-pane fade" id="carga-masiva" role="tabpanel" aria-labelledby="carga-masiva-tab">
        <div class="card card-success">
            <div class="card-header">
                <h3 class="card-title">Carga Masiva de Locales</h3>
            </div>
            <div class="card-body">
                <form id="formCargaMasiva" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="empresaCarga" class="form-label">Empresa para la carga masiva</label>
                            <select class="form-control" id="empresaCarga" name="empresa_id" required>
                                <option value="">-- Seleccione una Empresa --</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo htmlspecialchars($empresa['id']); ?>">
                                        <?php echo htmlspecialchars($empresa['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6" id="divisionesContainer" style="display: none;">
                            <label for="divisionCarga" class="form-label">División</label>
                            <select class="form-control" id="divisionCarga" name="division_id">
                                <option value="">-- Seleccione una División --</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label for="csvFile" class="form-label">Archivo CSV</label>
                            <div class="input-group">
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="csvFile" name="csvFile" accept=".csv" disabled required>
                                    <label class="custom-file-label" for="csvFile">Selecciona un archivo CSV</label>
                                </div>
                            </div>
                            <small class="form-text text-muted">
                                El archivo CSV debe contener los siguientes encabezados: 
                                <strong>codigo, nombre local, cadena, cuenta, comuna, region, direccion</strong>.  
                                Usa <strong>;</strong> como delimitador.
                            </small>
                            <small class="form-text text-muted">
                                                        <strong>
                                                            Utilice estos nombres exactos para las regiones (incluya los números también)
                                                            <br>
                                                            01 - TARAPACA
                                                            <br>
                                                            02 - ANTOFAGASTA
                                                            <br>
                                                            03 - ATACAMA
                                                            <br>
                                                            04 - COQUIMBO
                                                            <br>
                                                            05 - VALPARAISO
                                                            <br>
                                                            06 - LIBERTADOR GENERAL BERNARDO OHIGGINS
                                                            <br>
                                                            07 - MAULE
                                                            <br>
                                                            08 - BIOBIO
                                                            <br>
                                                            09 - LA ARAUCANIA
                                                            <br>
                                                            10 - LOS LAGOS
                                                            <br>
                                                            11 - AYSEN DEL GENERAL CARLOS IBANEZ DEL CAMPO
                                                            <br>
                                                            13 - METROPOLITANA DE SANTIAGO
                                                            <br>
                                                            14 - LOS RIOS
                                                            <br>
                                                            15 - ARICA Y PARINACOTA
                                                            <br>
                                                            16 - NUBLE
                                                            <br>
                                                        </strong>
                                                    </small>
                            <a href="mod_local/descargar_plantilla_locales.php" class="mt-2 d-inline-block">
                                Descargar planilla de ejemplo
                            </a>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-upload"></i> Subir y Procesar
                        </button>
                        <div id="spinnerCargaMasiva" class="spinner-border text-primary ms-3" role="status" style="display: none;">
                            <span class="sr-only">Procesando...</span>
                        </div>
                    </div>
                </form>

                <div id="resultado" class="mt-4"></div>
            </div>
        </div>
    </div>
</div>

                        </div>
                    </div>

                </div>
            </section>
        </div>
    </div>

    <!-- Modal Crear Local -->
    <div class="modal fade" id="modalCrearLocal" tabindex="-1" role="dialog" aria-labelledby="modalCrearLocalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
            <form id="formCrearLocal" class="form-horizontal" style="font-size: 70%;">
                <div class="modal-content">
                    <div class="modal-header bg-secondary">
                        <h5 class="modal-title" id="modalCrearLocalLabel">Crear Local</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- EMPRESA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">EMPRESA:*</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="empresa_id" required>
                                    <option value="">-- Seleccione una Empresa --</option>
                                    <?php foreach ($empresas as $empresa): ?>
                                        <option value="<?php echo htmlspecialchars($empresa['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($empresa['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                          <label class="col-sm-2 col-form-label">DIVISIÓN:*</label>
                          <div class="col-sm-10">
                            <select class="form-control" name="division_id" required>
                              <option value="">-- Seleccione una División --</option>
                              <?php foreach ($divisiones as $div): ?>
                                <option value="<?= $div['id'] ?>">
                                  <?= htmlspecialchars($div['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>

                        <!-- CÓDIGO DEL LOCAL -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">CÓDIGO LOCAL:*</label>
                            <div class="col-sm-10">
                                <input type="text" id="inputCodigoLocal" name="inputCodigoLocal" class="form-control" placeholder="CÓDIGO LOCAL..." required>
                            </div>
                        </div>

                        <!-- NOMBRE DEL LOCAL -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">NOMBRE LOCAL:*</label>
                            <div class="col-sm-10">
                                <input type="text" id="inputLocal" name="inputLocal" class="form-control" placeholder="NOMBRE DEL LOCAL..." required>
                            </div>
                        </div>

                        <!-- CUENTA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">CUENTA:*</label>
                            <div class="col-sm-10">
                                <select class="form-control" id="selectCuenta" name="cuenta_id" required>
                                    <option value="">-- Seleccione una Cuenta --</option>
                                    <?php foreach ($cuentas as $cuenta): ?>
                                        <option value="<?php echo htmlspecialchars($cuenta['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($cuenta['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- CADENA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">CADENA:*</label>
                            <div class="col-sm-10">
                                <select class="form-control" id="selectCadena" name="cadena_id" required>
                                    <option value="">-- Seleccione una Cadena --</option>
                                </select>
                            </div>
                        </div>

                        <!-- SUBCANAL (NUEVO) -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">CANAL:*</label>
                            <div class="col-sm-10">
                                <select class="form-control" id="selectCanal" name="canal_id" required>
                                    <option value="">-- Seleccione un Canal --</option>
                                    <?php foreach ($canales as $canal): ?>
                                        <option value="<?php echo htmlspecialchars($canal['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($canal['nombre_canal'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- SUBCANAL (ahora cargado dinámicamente) -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">SUBCANAL:*</label>
                            <div class="col-sm-10 d-flex align-items-center">
                                <select class="form-control" id="selectSubcanal" name="subcanal_id" required disabled>
                                    <option value="">-- Seleccione un Subcanal --</option>
                                </select>
                                <!-- Enlace para abrir el modal de crear Subcanal -->
                                <a href="#" id="crearSubcanalLinkLocal" class="ml-2 text-primary" style="cursor: pointer;">
                                    <i class="fas fa-plus-circle"></i> Crear Subcanal
                                </a>
                            </div>
                        </div>
                        
                        <!-- JEFE DE VENTAS -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">JEFE DE VENTAS:</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="jefe_venta_id" id="selectJefeVenta">
                                    <option value="">-- Seleccione un Jefe de Ventas --</option>
                                    <?php foreach ($jefesVenta as $jv): ?>
                                        <option value="<?php echo $jv['id']; ?>">
                                            <?php echo htmlspecialchars($jv['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- VENDEDOR -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">VENDEDOR:</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="vendedor_id" id="selectVendedor">
                                    <option value="">-- Seleccione un Vendedor --</option>
                                    <?php foreach ($vendedores as $vend): ?>
                                        <option value="<?php echo $vend['id']; ?>">
                                            <?php echo htmlspecialchars($vend['nombre_vendedor'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <!-- RELEVANCIA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">RELEVANCIA:</label>
                            <div class="col-sm-10">
                                <input type="number" name="relevancia" id="inputRelevancia" class="form-control" placeholder="Ej: 1, 2, 3..." required>
                            </div>
                        </div>

                        <!-- DIRECCIÓN -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">DIRECCIÓN:*</label>
                            <div class="col-sm-10">
                                <input type="text" id="inputDireccion" name="inputDireccion" class="form-control" placeholder="DIRECCIÓN..." required>
                            </div>
                        </div>

                        <!-- REGIÓN -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">REGIÓN:*</label>
                            <div class="col-sm-10">
                                <select class="form-control" id="selectRegion" name="region_id" required>
                                    <option value="">-- Seleccione una Región --</option>
                                    <?php foreach ($regiones as $region): ?>
                                        <?php
                                            // Extraer solo el nombre de la región sin el número
                                            $regionName = $region['region'];
                                            if (strpos($regionName, '-') !== false) {
                                                $parts = explode('-', $regionName, 2);
                                                $regionName = trim($parts[1]);
                                            }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($region['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- COMUNA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">COMUNA:*</label>
                            <div class="col-sm-10 d-flex align-items-center">
                                <select class="form-control" id="selectComuna" name="comuna_id" required disabled>
                                    <option value="">-- Seleccione una Comuna --</option>
                                </select>
                                <a href="#" id="crearComunaLinkLocal" class="ml-2 text-primary" style="cursor: pointer;">
                                    <i class="fas fa-plus-circle"></i> Crear Comuna
                                </a>
                            </div>
                        </div>
                        <!-- ZONA -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">ZONA:</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="zona_id" id="selectZona">
                                    <option value="">-- Seleccione una Zona --</option>
                                    <?php foreach ($zonas as $zona): ?>
                                        <option value="<?php echo $zona['id']; ?>">
                                            <?php echo htmlspecialchars($zona['nombre_zona'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- DISTRITO -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">DISTRITO:</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="distrito_id" id="selectDistrito" disabled>
                                    <option value="">-- Seleccione un Distrito --</option>
                                    <!-- Se carga dinámicamente según la zona seleccionada -->
                                </select>
                            </div>
                        </div>
                        
                        
                        <!-- UBICACIÓN -->
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">UBICACIÓN:</label>
                            <div class="col-sm-10">
                                <div id="map"></div>
                                <input type="hidden" name="lat" id="lat">
                                <input type="hidden" name="lng" id="lng">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div id="spinnerCrearLocal" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                            <span class="sr-only">Procesando...</span>
                        </div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary">Crear Local</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Editar Local (CON subcanal) -->
<div class="modal fade" id="modalEditarLocal" tabindex="-1" role="dialog" aria-labelledby="modalEditarLocalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <form id="formEditarLocal" class="form-horizontal" method="POST" action="mod_local/procesar_editar_local.php" style="font-size: 70%;">
            <div class="modal-content">
                <div class="modal-header bg-secondary">
                    <h5 class="modal-title" id="modalEditarLocalLabel">Editar Local</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                
                <div class="modal-body">
                    <!-- Token CSRF -->
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <!-- ID del Local -->
                    <input type="hidden" name="local_id" id="editLocalId">

                    <!-- EMPRESA -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">EMPRESA:*</label>
                        <div class="col-sm-10">
                            <select class="form-control" name="empresa_id_edit" id="editEmpresa" required>
                                <option value="">-- Seleccione una Empresa --</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo htmlspecialchars($empresa['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($empresa['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- DIVISIÓN  -->
                    <div class="form-group row">
                      <label class="col-sm-2 col-form-label">DIVISIÓN:</label>
                      <div class="col-sm-10">
                        <select class="form-control" name="division_id_edit" id="editDivision" required disabled>
                          <option value="0">Sin división</option>
                          <!-- Se cargará por AJAX -->
                        </select>
                      </div>
                    </div>

                    <!-- CÓDIGO LOCAL -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">CÓDIGO LOCAL:*</label>
                        <div class="col-sm-10">
                            <input type="text" id="editCodigoLocal" name="inputCodigoLocalEdit" class="form-control" required>
                        </div>
                    </div>

                    <!-- NOMBRE LOCAL -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">NOMBRE LOCAL:*</label>
                        <div class="col-sm-10">
                            <input type="text" id="editLocal" name="inputLocalEdit" class="form-control" required>
                        </div>
                    </div>

                    <!-- CUENTA -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">CUENTA:*</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editCuenta" name="cuenta_id_edit" required>
                                <option value="">-- Seleccione una Cuenta --</option>
                                <?php foreach ($cuentas as $cuenta): ?>
                                    <option value="<?php echo htmlspecialchars($cuenta['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($cuenta['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- CADENA -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">CADENA:*</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editCadena" name="cadena_id_edit" required>
                                <option value="">-- Seleccione una Cadena --</option>
                            </select>
                        </div>
                    </div>

                    <!-- CANAL (NUEVO) -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">CANAL:*</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editCanal" name="canal_id_edit" required>
                                <option value="">-- Seleccione un Canal --</option>
                                <?php foreach ($canales as $canal): ?>
                                    <option value="<?php echo htmlspecialchars($canal['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($canal['nombre_canal'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- SUBCANAL (dinámico) -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">SUBCANAL:*</label>
                        <div class="col-sm-10 d-flex align-items-center">
                            <select class="form-control" id="editSubcanal" name="subcanal_id_edit" required disabled>
                                <option value="">-- Seleccione un Subcanal --</option>
                            </select>
                            <!-- Link para crear subcanal -->
                            <a href="#" id="crearSubcanalLinkEdit" class="ml-2 text-primary" style="cursor: pointer;">
                                <i class="fas fa-plus-circle"></i> Crear Subcanal
                            </a>
                        </div>
                    </div>

                    <!-- DIRECCIÓN -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">DIRECCIÓN:*</label>
                        <div class="col-sm-10">
                            <input type="text" id="editDireccion" name="inputDireccionEdit" class="form-control" required>
                        </div>
                    </div>

                    <!-- REGIÓN -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">REGIÓN:*</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editRegion" name="region_id_edit" required>
                                <option value="">-- Seleccione una Región --</option>
                                <?php foreach ($regiones as $region): ?>
                                    <?php
                                        $regionName = $region['region'];
                                        if (strpos($regionName, '-') !== false) {
                                            $parts = explode('-', $regionName, 2);
                                            $regionName = trim($parts[1]);
                                        }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($region['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- COMUNA -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">COMUNA:*</label>
                        <div class="col-sm-10 d-flex align-items-center">
                            <select class="form-control" id="editComuna" name="comuna_id_edit" required disabled>
                                <option value="">-- Seleccione una Comuna --</option>
                            </select>
                            <a href="#" id="crearComunaLinkEdit" class="ml-2 text-primary" style="cursor: pointer;">
                                <i class="fas fa-plus-circle"></i> Crear Comuna
                            </a>
                        </div>
                    </div>
                                        <!-- ZONA -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">ZONA:</label>
                        <div class="col-sm-10">
                            <select class="form-control" name="zona_id_edit" id="editZona" required>
                                <option value="">-- Seleccione una Zona --</option>
                                <?php foreach ($zonas as $zona): ?>
                                    <option value="<?php echo $zona['id']; ?>">
                                        <?php echo htmlspecialchars($zona['nombre_zona'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- DISTRITO -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">DISTRITO:</label>
                        <div class="col-sm-10">
                            <select class="form-control" name="distrito_id_edit" id="editDistrito"  required disabled>
                                <option value="">-- Seleccione un Distrito --</option>
                                <!-- Se cargará dinámicamente según la zona -->
                            </select>
                        </div>
                    </div>
                    
                    <!-- JEFE DE VENTAS -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">JEFE DE VENTAS:</label>
                        <div class="col-sm-10">
                            <select class="form-control" name="jefe_venta_id_edit" id="editJefeVenta" required>
                                <option value="">-- Seleccione un Jefe de Ventas --</option>
                                <?php foreach ($jefesVenta as $jv): ?>
                                    <option value="<?php echo $jv['id']; ?>">
                                        <?php echo htmlspecialchars($jv['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- VENDEDOR -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">VENDEDOR:</label>
                        <div class="col-sm-10">
                            <select class="form-control" name="vendedor_id_edit" id="editVendedor" required>
                                <option value="">-- Seleccione un Vendedor --</option>
                                <?php foreach ($vendedores as $vend): ?>
                                    <option value="<?php echo $vend['id']; ?>">
                                        <?php echo htmlspecialchars($vend['nombre_vendedor'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- UBICACIÓN (mapa) -->
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">UBICACIÓN:</label>
                        <div class="col-sm-10">
                            <div id="mapEdit"></div>
                            <input type="hidden" id="editLat" name="lat_edit">
                            <input type="hidden" id="editLng" name="lng_edit">
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <div id="spinnerEditarLocal" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                        <span class="sr-only">Procesando...</span>
                    </div>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-warning">Guardar Cambios</button>
                </div>
            </div>
        </form>
    </div>
</div>

    
    <!-- Modal Crear Comuna -->
    <div class="modal fade" id="modalCrearComuna" tabindex="-1" role="dialog" aria-labelledby="modalCrearComunaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <form id="formCrearComuna" class="form-horizontal" method="POST" action="mod_local/procesarComuna.php" style="font-size: 70%;">
                <div class="modal-content">
                    <div class="modal-header bg-secondary">
                        <h5 class="modal-title" id="modalCrearComunaLabel">Crear Comuna</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">NOMBRE COMUNA:*</label>
                            <div class="col-sm-9">
                                <input type="text" name="inputNombreComuna" class="form-control" placeholder="NOMBRE DE LA COMUNA..." required>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">REGIÓN:*</label>
                            <div class="col-sm-9">
                                <select class="form-control" name="region_id" required>
                                    <option value="">-- Seleccione una Región --</option>
                                    <?php foreach ($regiones as $region): ?>
                                        <?php
                                            $regionName = $region['region'];
                                            if (strpos($regionName, '-') !== false) {
                                                $parts = explode('-', $regionName, 2);
                                                $regionName = trim($parts[1]);
                                            }
                                        ?>
                                        <option value="<?php echo htmlspecialchars($region['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>   
                    </div>
                    <div class="modal-footer">
                        <div id="spinnerCrearComuna" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                            <span class="sr-only">Procesando...</span>
                        </div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-info">Crear Comuna</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Crear Cadena -->
    <div class="modal fade" id="modalCrearCadena" tabindex="-1" role="dialog" aria-labelledby="modalCrearCadenaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <form id="formCrearCadena" class="form-horizontal" method="POST" action="mod_local/procesarCadena.php" style="font-size: 70%;">
                <div class="modal-content">
                    <div class="modal-header bg-secondary">
                        <h5 class="modal-title" id="modalCrearCadenaLabel">Crear Cadena</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">NOMBRE CADENA:*</label>
                            <div class="col-sm-9">
                                <input type="text" name="inputNombreCadena" class="form-control" placeholder="NOMBRE DE LA CADENA..." required>
                            </div>
                        </div>
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">CUENTA:*</label>
                            <div class="col-sm-9">
                                <select class="form-control" name="cuenta_id" required>
                                    <option value="">-- Seleccione una Cuenta --</option>
                                    <?php foreach ($cuentas as $cuenta): ?>
                                        <option value="<?php echo htmlspecialchars($cuenta['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($cuenta['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>   
                    </div>
                    <div class="modal-footer">
                        <div id="spinnerCrearCadena" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                            <span class="sr-only">Procesando...</span>
                        </div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-warning">Crear Cadena</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal Crear Cuenta -->
    <div class="modal fade" id="modalCrearCuenta" tabindex="-1" role="dialog" aria-labelledby="modalCrearCuentaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <form id="formCrearCuenta" class="form-horizontal" method="POST" action="mod_local/procesarCuenta.php" style="font-size: 70%;">
                <div class="modal-content">
                    <div class="modal-header bg-secondary">
                        <h5 class="modal-title" id="modalCrearCuentaLabel">Crear Cuenta</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">NOMBRE CUENTA:*</label>
                            <div class="col-sm-9">
                                <input type="text" name="inputNombreCuenta" class="form-control" placeholder="NOMBRE DE LA CUENTA..." required>
                            </div>
                        </div>   
                    </div>
                    <div class="modal-footer">
                        <div id="spinnerCrearCuenta" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                            <span class="sr-only">Procesando...</span>
                        </div>
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary">Crear Cuenta</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
        <div class="modal fade" id="modalCrearCanal" tabindex="-1" role="dialog" aria-labelledby="modalCrearCanalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
                <form id="formCrearCanal" class="form-horizontal" method="POST" action="mod_local/procesarCanal.php" style="font-size: 70%;">
                    <div class="modal-content">
                        <div class="modal-header bg-secondary">
                            <h5 class="modal-title" id="modalCrearCanalLabel">Crear Canal</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        
                            <div class="form-group row">
                                <label class="col-sm-3 col-form-label">NOMBRE CANAL:*</label>
                                <div class="col-sm-9">
                                    <input type="text" name="inputNombreCanal" class="form-control" placeholder="NOMBRE DEL CANAL..." required>
                                </div>
                            </div>
        
                        </div>
                        <div class="modal-footer">
                            <div id="spinnerCrearCanal" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                                <span class="sr-only">Procesando...</span>
                            </div>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-dark">Crear Canal</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <!-- NUEVO: Modal para Crear Subcanal -->
    <div class="modal fade" id="modalCrearSubcanal" tabindex="-1" role="dialog" aria-labelledby="modalCrearSubcanalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <form id="formCrearSubcanal" class="form-horizontal" method="POST" action="mod_local/procesarSubcanal.php" style="font-size: 70%;">
            <div class="modal-content">
                <div class="modal-header bg-secondary">
                    <h5 class="modal-title" id="modalCrearSubcanalLabel">Crear Subcanal</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <!-- Nombre Subcanal -->
                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label">NOMBRE SUBCANAL:*</label>
                        <div class="col-sm-9">
                            <input type="text" name="inputNombreSubcanal" class="form-control" placeholder="NOMBRE DEL SUBCANAL..." required>
                        </div>
                    </div>

                    <!-- CANAL (relación) -->
                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label">CANAL:*</label>
                        <div class="col-sm-9">
                            <select name="id_canal" class="form-control" required>
                                <option value="">-- Seleccione un Canal --</option>
                                <?php foreach ($canales as $canal): ?>
                                    <option value="<?php echo $canal['id']; ?>">
                                        <?php echo htmlspecialchars($canal['nombre_canal'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <div id="spinnerCrearSubcanal" class="spinner-border text-primary mr-auto" role="status" style="display: none;">
                        <span class="sr-only">Procesando...</span>
                    </div>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-secondary">Crear Subcanal</button>
                </div>
            </div>
        </form>
    </div>
</div>
    <!-- FIN NUEVO -->
    
    <!-- Scripts de terceros -->
    <script src="../plugins/jquery/jquery.min.js"></script>
    <script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>
    <script src="../dist/js/adminlte.min.js"></script>
    
<script>
/* =========================================================
   MAPA CREAR LOCAL
========================================================= */
let createMap = null;
let createMarker = null;

function debounce(func, delay) {
    let debounceTimer;
    return function () {
        const context = this;
        const args = arguments;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => func.apply(context, args), delay);
    };
}

function geocodeAddress(address, map, marker, latSelector, lngSelector) {
    const geocoder = new google.maps.Geocoder();

    geocoder.geocode(
        {
            address: address,
            componentRestrictions: { country: 'CL' }
        },
        function (results, status) {
            if (status === 'OK' && results[0]) {
                map.setCenter(results[0].geometry.location);
                marker.setPosition(results[0].geometry.location);
                $(latSelector).val(results[0].geometry.location.lat());
                $(lngSelector).val(results[0].geometry.location.lng());
            }
        }
    );
}

function initMap() {
    const defaultPos = { lat: -33.4489, lng: -70.6693 };

    createMap = new google.maps.Map(document.getElementById('map'), {
        center: defaultPos,
        zoom: 13
    });

    createMarker = new google.maps.Marker({
        position: defaultPos,
        map: createMap,
        draggable: true
    });

    createMarker.addListener('dragend', function (event) {
        $('#lat').val(event.latLng.lat());
        $('#lng').val(event.latLng.lng());
    });

    const triggerGeocodingCrearLocal = debounce(function () {
        const direccion = $('#inputDireccion').val().trim();
        let regionText = $('#selectRegion option:selected').text().trim();
        const comunaText = $('#selectComuna option:selected').text().trim();

        if (regionText.includes('-')) {
            regionText = regionText.split('-', 2)[1].trim();
        }

        if (
            direccion !== '' &&
            regionText !== '' &&
            regionText !== '-- Seleccione una Región --' &&
            comunaText !== '' &&
            comunaText !== '-- Seleccione una Comuna --'
        ) {
            const fullAddress = direccion + ', ' + comunaText + ', ' + regionText + ', Chile';
            geocodeAddress(fullAddress, createMap, createMarker, '#lat', '#lng');
        }
    }, 500);

    $(document).on('blur', '#inputDireccion', triggerGeocodingCrearLocal);
    $(document).on('change', '#selectRegion', triggerGeocodingCrearLocal);
    $(document).on('change', '#selectComuna', triggerGeocodingCrearLocal);
}

/* =========================================================
   APP
========================================================= */
$(function () {
    bsCustomFileInput.init();

    const puedeEditar = <?= json_encode(strtolower($perfilUser) === 'editor' || strtolower($perfilUser) === 'coordinador') ?>;
    const colspanTabla = puedeEditar ? 13 : 12;

    let currentOffset = 0;
    let itemsPerPage = 50;
    let requestLocales = null;

    /* =========================================================
       HELPERS
    ========================================================= */
    function escapeHtml(text) {
        return $('<div>').text(text ?? '').html();
    }

    function mostrarEstadoInicialTabla(msg = 'Seleccione filtros y presione Filtrar.') {
        $('#tablaLocales tbody').html(
            '<tr><td colspan="' + colspanTabla + '" class="text-center text-muted">' + escapeHtml(msg) + '</td></tr>'
        );
        $('#pagination-controls').empty();
    }

    function getFiltrosActuales() {
        return {
            empresa_id: $('#filtroEmpresa').val(),
            division_id: $('#filtroDivision').val(),
            region_id: $('#filtroRegion').val(),
            comuna_id: $('#filtroComuna').val(),
            canal_id: $('#filtroCanal').val(),
            subcanal_id: $('#filtroSubcanal').val(),
            nombre: $('#filtroNombre').val().trim(),
            codigo: $('#filtroCodigo').val().trim(),
            id_local: $('#filtroID').val().trim()
        };
    }

    function renderFilaLocal(local) {
        let fila = '<tr>' +
            '<td>' + escapeHtml(local.id) + '</td>' +
            '<td>' + escapeHtml(local.codigo) + '</td>' +
            '<td>' + escapeHtml(local.canal) + '</td>' +
            '<td>' + escapeHtml(local.nombre) + '</td>' +
            '<td>' + escapeHtml(local.direccion) + '</td>' +
            '<td>' + escapeHtml(local.cuenta) + '</td>' +
            '<td>' + escapeHtml(local.cadena) + '</td>' +
            '<td>' + escapeHtml(local.comuna) + '</td>' +
            '<td>' + escapeHtml(local.region) + '</td>' +
            '<td>' + escapeHtml(local.empresa) + '</td>' +
            '<td>' + escapeHtml(local.lat ?? 'N/A') + '</td>' +
            '<td>' + escapeHtml(local.lng ?? 'N/A') + '</td>';

        if (puedeEditar) {
            fila += '<td>' +
                '<button type="button" class="btn btn-sm btn-primary mr-2 btnEditarLocal" data-id="' + escapeHtml(local.id) + '">' +
                '<i class="fas fa-edit"></i> Editar</button>' +
                '<a href="mod_local/eliminar_local.php?id=' + encodeURIComponent(local.id) + '" class="btn btn-sm btn-danger">' +
                '<i class="fas fa-trash-alt"></i> Eliminar</a>' +
                '</td>';
        }

        fila += '</tr>';
        return fila;
    }

    /* =========================================================
       AJAX CATALOGOS
    ========================================================= */
    function cargarDistritos(zonaId, distritoSelect) {
        return $.ajax({
            url: 'mod_local/obtener_distritos_por_zona.php',
            type: 'GET',
            data: { zona_id: zonaId },
            dataType: 'json'
        }).done(function (response) {
            distritoSelect.empty().append('<option value="">-- Seleccione un Distrito --</option>');

            if (response.success && response.data.length > 0) {
                $.each(response.data, function (_, dist) {
                    distritoSelect.append(
                        '<option value="' + escapeHtml(dist.id) + '">' + escapeHtml(dist.nombre_distrito) + '</option>'
                    );
                });
                distritoSelect.prop('disabled', false);
            } else if (!response.success) {
                mostrarAlertaPrincipal('danger', response.message || 'Error al cargar distritos.');
                distritoSelect.prop('disabled', true);
            } else {
                distritoSelect.append('<option value="">No hay distritos disponibles</option>');
                distritoSelect.prop('disabled', true);
            }
        }).fail(function () {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar los distritos.');
            distritoSelect.prop('disabled', true);
        });
    }

    function cargarCadenas(cuentaId, cadenaSelect) {
        return $.ajax({
            url: 'obtener_cadenas.php',
            type: 'GET',
            data: { cuenta_id: cuentaId },
            dataType: 'json'
        }).done(function (response) {
            cadenaSelect.empty().append('<option value="">-- Seleccione una Cadena --</option>');

            if (response.success && response.data.length > 0) {
                $.each(response.data, function (_, cadena) {
                    cadenaSelect.append(
                        '<option value="' + escapeHtml(cadena.id) + '">' + escapeHtml(cadena.nombre) + '</option>'
                    );
                });
            } else {
                cadenaSelect.append('<option value="">No hay cadenas disponibles</option>');
            }
        }).fail(function () {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar las cadenas.');
        });
    }

    function cargarSubcanales(canalId, subcanalSelect, primerTexto = '-- Seleccione un Subcanal --') {
        return $.ajax({
            url: 'mod_local/obtener_subcanales_por_canal.php',
            type: 'GET',
            data: { canal_id: canalId },
            dataType: 'json'
        }).done(function (response) {
            subcanalSelect.empty().append('<option value="">' + primerTexto + '</option>');

            if (response.success && response.data.length > 0) {
                $.each(response.data, function (_, sub) {
                    subcanalSelect.append(
                        '<option value="' + escapeHtml(sub.id) + '">' + escapeHtml(sub.nombre_subcanal) + '</option>'
                    );
                });
                subcanalSelect.prop('disabled', false);
            } else if (!response.success) {
                mostrarAlertaPrincipal('danger', response.message || 'Error al cargar subcanales.');
                subcanalSelect.prop('disabled', true);
            } else {
                subcanalSelect.append('<option value="">No hay subcanales disponibles</option>');
                subcanalSelect.prop('disabled', true);
            }
        }).fail(function () {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar los subcanales.');
            subcanalSelect.prop('disabled', true);
        });
    }

    function cargarComunas(regionId, comunaSelect, primerTexto = '-- Seleccione una Comuna --') {
        return $.ajax({
            url: 'mod_local/obtener_comunas_por_region.php',
            type: 'GET',
            data: { region_id: regionId },
            dataType: 'json'
        }).done(function (response) {
            comunaSelect.empty().append('<option value="">' + primerTexto + '</option>');

            if (response.success && response.data.length > 0) {
                $.each(response.data, function (_, comuna) {
                    comunaSelect.append(
                        '<option value="' + escapeHtml(comuna.id) + '">' + escapeHtml(comuna.comuna) + '</option>'
                    );
                });
                comunaSelect.prop('disabled', false);
            } else if (!response.success) {
                comunaSelect.append('<option value="">' + escapeHtml(response.message || 'Error al obtener comunas') + '</option>');
                comunaSelect.prop('disabled', true);
            } else {
                comunaSelect.append('<option value="">No hay comunas disponibles</option>');
                comunaSelect.prop('disabled', true);
            }
        }).fail(function () {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar las comunas.');
            comunaSelect.prop('disabled', true);
        });
    }

    function cargarDivisiones(empresaId, divisionSelect, primerTexto = '-- Seleccione una División --', incluirSinDivision = false) {
        return $.ajax({
            url: 'mod_local/cargar_divisiones.php',
            type: 'GET',
            data: { empresa_id: empresaId }
        }).done(function (response) {
            let html = '<option value="">' + primerTexto + '</option>';
            if (incluirSinDivision) {
                html += '<option value="0">Sin división</option>';
            }
            html += response;

            divisionSelect.html(html).prop('disabled', false);
        }).fail(function () {
            divisionSelect.html('<option value="">Error al cargar divisiones</option>').prop('disabled', true);
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar las divisiones.');
        });
    }

    /* =========================================================
       TABLA LOCALES
    ========================================================= */
    function cargarLocales(filtros) {
        filtros = filtros || {};
        filtros.offset = currentOffset;
        filtros.limit = itemsPerPage;

        if (requestLocales && requestLocales.readyState !== 4) {
            requestLocales.abort();
        }

        $('#tablaLocales tbody').html(
            '<tr><td colspan="' + colspanTabla + '" class="text-center">Cargando locales...</td></tr>'
        );

        requestLocales = $.ajax({
            url: 'mod_local/fetch_locales.php',
            type: 'GET',
            data: filtros,
            dataType: 'json',
            success: function (response) {
                const tbody = $('#tablaLocales tbody');
                tbody.empty();

                if (!response.success) {
                    mostrarAlertaPrincipal('danger', 'Error al obtener los locales: ' + (response.message || 'Error desconocido'));
                    mostrarEstadoInicialTabla('No fue posible cargar los locales.');
                    return;
                }

                if (!response.data || response.data.length === 0) {
                    tbody.html('<tr><td colspan="' + colspanTabla + '" class="text-center">No se encontraron locales.</td></tr>');
                    $('#pagination-controls').empty();
                    return;
                }

                $.each(response.data, function (_, local) {
                    tbody.append(renderFilaLocal(local));
                });

                actualizarPaginacion(parseInt(response.total || 0, 10));
            },
            error: function (xhr, status) {
                if (status !== 'abort') {
                    mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar los locales.');
                    mostrarEstadoInicialTabla('Ocurrió un error al cargar los locales.');
                }
            }
        });
    }

    function actualizarPaginacion(totalRecords) {
        const paginationDiv = $('#pagination-controls');
        paginationDiv.empty();

        if (!totalRecords || totalRecords <= 0) {
            return;
        }

        const totalPages = Math.ceil(totalRecords / itemsPerPage);
        const currentPage = Math.floor(currentOffset / itemsPerPage) + 1;

        const wrapper = $('<div class="d-flex flex-wrap align-items-center gap-2"></div>');

        const itemsPerPageSelect = $(
            '<select id="itemsPerPageSelect" class="form-control form-control-sm d-inline-block mr-2" style="width:auto;">' +
                '<option value="50">50</option>' +
                '<option value="100">100</option>' +
                '<option value="150">150</option>' +
                '<option value="500">500</option>' +
            '</select>'
        ).val(String(itemsPerPage));

        wrapper.append('<span class="mr-2">Mostrar</span>');
        wrapper.append(itemsPerPageSelect);
        wrapper.append('<span class="mr-3">registros</span>');

        const prevButton = $('<button type="button" class="btn btn-secondary btn-sm mr-1">Anterior</button>');
        prevButton.prop('disabled', currentPage <= 1);
        wrapper.append(prevButton);

        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);

        if (currentPage <= 3) {
            endPage = Math.min(totalPages, 5);
        }
        if (currentPage >= totalPages - 2) {
            startPage = Math.max(1, totalPages - 4);
        }

        for (let i = startPage; i <= endPage; i++) {
            const pageButton = $('<button type="button" class="btn btn-sm mr-1"></button>')
                .text(i)
                .toggleClass('btn-primary', i === currentPage)
                .toggleClass('btn-secondary', i !== currentPage)
                .data('page', i);

            wrapper.append(pageButton);
        }

        const nextButton = $('<button type="button" class="btn btn-secondary btn-sm mr-3">Siguiente</button>');
        nextButton.prop('disabled', currentPage >= totalPages);
        wrapper.append(nextButton);

        wrapper.append(
            '<span class="text-muted">Página ' + currentPage + ' de ' + totalPages + ' | Total registros: ' + totalRecords + '</span>'
        );

        paginationDiv.append(wrapper);

        prevButton.on('click', function () {
            if (currentPage > 1) {
                currentOffset = (currentPage - 2) * itemsPerPage;
                cargarLocales(getFiltrosActuales());
            }
        });

        nextButton.on('click', function () {
            if (currentPage < totalPages) {
                currentOffset = currentPage * itemsPerPage;
                cargarLocales(getFiltrosActuales());
            }
        });

        paginationDiv.find('button').filter(function () {
            return $(this).data('page');
        }).on('click', function () {
            const page = parseInt($(this).data('page'), 10);
            currentOffset = (page - 1) * itemsPerPage;
            cargarLocales(getFiltrosActuales());
        });

        itemsPerPageSelect.on('change', function () {
            itemsPerPage = parseInt($(this).val(), 10);
            currentOffset = 0;
            cargarLocales(getFiltrosActuales());
        });
    }

    /* =========================================================
       FORM CREAR LOCAL
    ========================================================= */
    $('#formCrearLocal').on('submit', function (e) {
        e.preventDefault();

        $('#spinnerCrearLocal').show();

        const formData = new FormData(this);

        $.ajax({
            url: 'mod_local/procesar.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (response) {
                $('#spinnerCrearLocal').hide();

                if (response.success) {
                    mostrarAlertaPrincipal('success', response.message);
                    $('#formCrearLocal')[0].reset();

                    $('#selectCadena').html('<option value="">-- Seleccione una Cadena --</option>');
                    $('#selectComuna').html('<option value="">-- Seleccione una Comuna --</option>').prop('disabled', true);
                    $('#selectSubcanal').html('<option value="">-- Seleccione un Subcanal --</option>').prop('disabled', true);
                    $('#selectDistrito').html('<option value="">-- Seleccione un Distrito --</option>').prop('disabled', true);

                    $('#lat').val('');
                    $('#lng').val('');

                    $('#modalCrearLocal').modal('hide');
                } else {
                    mostrarAlertaPrincipal('danger', response.message || 'No fue posible crear el local.');
                }
            },
            error: function () {
                $('#spinnerCrearLocal').hide();
                mostrarAlertaPrincipal('danger', 'Ocurrió un error inesperado al procesar la solicitud.');
            }
        });
    });

    /* =========================================================
       FORM CARGA MASIVA
    ========================================================= */
    $('#csvFile').prop('disabled', true);

    $('#empresaCarga').on('change', function () {
        const empresaId = $(this).val();

        if (empresaId) {
            cargarDivisiones(empresaId, $('#divisionCarga'), '-- Seleccione una División --', false)
                .done(function () {
                    $('#divisionesContainer').show();
                    $('#csvFile').prop('disabled', true).val('');
                    $('.custom-file-label').text('Selecciona un archivo CSV');
                });
        } else {
            $('#divisionesContainer').hide();
            $('#divisionCarga').html('<option value="">-- Seleccione una División --</option>');
            $('#csvFile').prop('disabled', true).val('');
            $('.custom-file-label').text('Selecciona un archivo CSV');
        }
    });

    $('#divisionCarga').on('change', function () {
        const tieneDivision = $(this).val() && $(this).val().trim() !== '';
        $('#csvFile').prop('disabled', !tieneDivision);

        if (!tieneDivision) {
            $('#csvFile').val('');
            $('.custom-file-label').text('Selecciona un archivo CSV');
        }
    });

    $('#csvFile').on('change', function () {
        const fileName = this.files && this.files.length ? this.files[0].name : 'Selecciona un archivo CSV';
        $(this).next('.custom-file-label').text(fileName);
    });

    $('#formCargaMasiva').on('submit', function (e) {
        e.preventDefault();

        if (!$('#empresaCarga').val()) {
            mostrarAlertaPrincipal('warning', 'Por favor selecciona una Empresa para la carga masiva.');
            return;
        }

        if (!$('#divisionCarga').val()) {
            mostrarAlertaPrincipal('warning', 'Por favor selecciona una División.');
            return;
        }

        if (!$('#csvFile').get(0).files.length) {
            mostrarAlertaPrincipal('warning', 'Selecciona un archivo CSV.');
            return;
        }

        $('#spinnerCargaMasiva').show();
        $('#resultado').empty();

        const $form = $(this);
        const formData = new FormData(this);
        const csrf = $form.find('input[name="csrf_token"]').val();

        formData.set('csrf_token', csrf);

        $.ajax({
            url: '/visibility2/portal/modulos/mod_local/procesar_csv.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (response) {
                $('#spinnerCargaMasiva').hide();

                const inserted = parseInt(response.inserted || 0, 10);
                const failed = parseInt(response.failed || 0, 10);
                const total = inserted + failed;

                if (inserted > 0 && failed === 0) {
                    mostrarAlertaPrincipal('success', 'Se subieron todos los locales (' + inserted + ' de ' + total + ').');
                    $('#formCargaMasiva')[0].reset();
                    $('#csvFile').prop('disabled', true);
                    $('.custom-file-label').text('Selecciona un archivo CSV');
                } else if (inserted > 0 && failed > 0) {
                    mostrarAlertaPrincipal('warning', 'Se subieron ' + inserted + ' de ' + total + '. ' + failed + ' no se subieron.');

                    if (response.reportUrl) {
                        $('#resultado').html(
                            '<a href="' + escapeHtml(response.reportUrl) + '" class="btn btn-sm btn-danger" target="_blank">Descargar CSV de fallidos</a>'
                        );
                    }
                } else {
                    mostrarAlertaPrincipal('danger', 'No se subió ningún local. Revisa el formato del CSV.');

                    if (response.reportUrl) {
                        $('#resultado').html(
                            '<a href="' + escapeHtml(response.reportUrl) + '" class="btn btn-sm btn-danger" target="_blank">Descargar CSV de fallidos</a>'
                        );
                    }
                }
            },
            error: function (xhr) {
                $('#spinnerCargaMasiva').hide();
                console.error(xhr.responseText);
                mostrarAlertaPrincipal('danger', 'Ocurrió un error inesperado al procesar la carga masiva.');
            }
        });
    });

    /* =========================================================
       EVENTOS DEPENDIENTES - CREAR
    ========================================================= */
    $('#selectZona').on('change', function () {
        const zonaId = $(this).val();
        const distritoSelect = $('#selectDistrito');

        if (zonaId) {
            cargarDistritos(zonaId, distritoSelect);
        } else {
            distritoSelect.html('<option value="">-- Seleccione un Distrito --</option>').prop('disabled', true);
        }
    });

    $('#selectCuenta').on('change', function () {
        const cuentaId = $(this).val();
        const cadenaSelect = $('#selectCadena');

        if (cuentaId) {
            cargarCadenas(cuentaId, cadenaSelect);
        } else {
            cadenaSelect.html('<option value="">-- Seleccione una Cadena --</option>');
        }
    });

    $('#selectCanal').on('change', function () {
        const canalId = $(this).val();
        const subcanalSelect = $('#selectSubcanal');

        if (canalId) {
            cargarSubcanales(canalId, subcanalSelect, '-- Seleccione un Subcanal --');
        } else {
            subcanalSelect.html('<option value="">-- Seleccione un Subcanal --</option>').prop('disabled', true);
        }
    });

    $('#selectRegion').on('change', function () {
        const regionId = $(this).val();
        const comunaSelect = $('#selectComuna');

        if (regionId) {
            cargarComunas(regionId, comunaSelect, '-- Seleccione una Comuna --');
        } else {
            comunaSelect.html('<option value="">-- Seleccione una Comuna --</option>').prop('disabled', true);
        }
    });

    /* =========================================================
       EVENTOS DEPENDIENTES - EDITAR
    ========================================================= */
    $('#editZona').on('change', function () {
        const zonaId = $(this).val();
        const distritoSelect = $('#editDistrito');

        if (zonaId) {
            cargarDistritos(zonaId, distritoSelect);
        } else {
            distritoSelect.html('<option value="">-- Seleccione un Distrito --</option>').prop('disabled', true);
        }
    });

    $('#editEmpresa').on('change', function () {
        const empresaId = $(this).val();
        const divisionSelect = $('#editDivision');

        if (empresaId) {
            cargarDivisiones(empresaId, divisionSelect, '-- Seleccione una División --', true);
        } else {
            divisionSelect.html('<option value="0">Sin división</option>').prop('disabled', true);
        }
    });

    $('#editCuenta').on('change', function () {
        const cuentaId = $(this).val();
        const cadenaSelect = $('#editCadena');

        if (cuentaId) {
            cargarCadenas(cuentaId, cadenaSelect);
        } else {
            cadenaSelect.html('<option value="">-- Seleccione una Cadena --</option>');
        }
    });

    $('#editCanal').on('change', function () {
        const canalId = $(this).val();
        const subcanalSelect = $('#editSubcanal');

        if (canalId) {
            cargarSubcanales(canalId, subcanalSelect, '-- Seleccione un Subcanal --');
        } else {
            subcanalSelect.html('<option value="">-- Seleccione un Subcanal --</option>').prop('disabled', true);
        }
    });

    $('#editRegion').on('change', function () {
        const regionId = $(this).val();
        const comunaSelect = $('#editComuna');

        if (regionId) {
            cargarComunas(regionId, comunaSelect, '-- Seleccione una Comuna --');
        } else {
            comunaSelect.html('<option value="">-- Seleccione una Comuna --</option>').prop('disabled', true);
        }
    });

    /* =========================================================
       FILTROS TABLA
    ========================================================= */
    $('#filtroCanal').on('change', function () {
        const canalId = $(this).val();
        const subcanalSelect = $('#filtroSubcanal');

        if (canalId) {
            cargarSubcanales(canalId, subcanalSelect, 'Todos los Subcanales');
        } else {
            subcanalSelect.html('<option value="">Todos los Subcanales</option>').prop('disabled', true);
        }
    });

    $('#filtroEmpresa').on('change', function () {
        const empresaId = $(this).val();
        const divisionSelect = $('#filtroDivision');

        if (empresaId) {
            cargarDivisiones(empresaId, divisionSelect, 'Todas las Divisiones', false);
        } else {
            divisionSelect.html('<option value="">Todas las Divisiones</option>').prop('disabled', true);
        }
    });

    $('#filtroRegion').on('change', function () {
        const regionId = $(this).val();
        const comunaSelect = $('#filtroComuna');

        if (regionId) {
            cargarComunas(regionId, comunaSelect, 'Todas las Comunas');
        } else {
            comunaSelect.html('<option value="">Todas las Comunas</option>').prop('disabled', true);
        }
    });

    $('#btnFiltrar').on('click', function () {
        currentOffset = 0;
        cargarLocales(getFiltrosActuales());
    });

    $('#btnResetear').on('click', function () {
        $('#filtrosLocales')[0].reset();

        $('#filtroComuna').html('<option value="">Todas las Comunas</option>').prop('disabled', true);
        $('#filtroSubcanal').html('<option value="">Todos los Subcanales</option>').prop('disabled', true);
        $('#filtroDivision').html('<option value="">Todas las Divisiones</option>').prop('disabled', true);

        currentOffset = 0;
        mostrarEstadoInicialTabla();
    });

    $('#filtrosLocales').on('submit', function (e) {
        e.preventDefault();
        currentOffset = 0;
        cargarLocales(getFiltrosActuales());
    });

    $('#filtroNombre, #filtroCodigo, #filtroID').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            currentOffset = 0;
            cargarLocales(getFiltrosActuales());
        }
    });

    /* =========================================================
       LINKS MODALES
    ========================================================= */
    $('#crearComunaLinkLocal, #crearComunaLinkEdit').on('click', function (e) {
        e.preventDefault();
        $('#modalCrearLocal, #modalEditarLocal').modal('hide');
        $('#modalCrearComuna').modal('show');
    });

    $('#crearSubcanalLinkLocal').on('click', function (e) {
        e.preventDefault();
        $('#modalCrearLocal').modal('hide');
        $('#modalCrearSubcanal').modal('show');
    });

    $('#crearSubcanalLinkEdit').on('click', function (e) {
        e.preventDefault();
        $('#modalEditarLocal').modal('hide');
        $('#modalCrearSubcanal').modal('show');
    });

    /* =========================================================
       EDITAR LOCAL
    ========================================================= */
    $(document).on('click', '.btnEditarLocal', function () {
        const localId = $(this).data('id');

        $.ajax({
            url: 'mod_local/get_local.php',
            type: 'GET',
            data: { id: localId },
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    mostrarAlertaPrincipal('danger', 'Error al obtener los datos del local: ' + (response.message || 'Error desconocido'));
                    return;
                }

                const local = response.data;

                $('#editLocalId').val(local.id);
                $('#editCodigoLocal').val(local.codigo);
                $('#editLocal').val(local.nombre);
                $('#editDireccion').val(local.direccion);
                $('#editEmpresa').val(local.empresa_id);
                $('#editCuenta').val(local.cuenta_id);
                $('#editRegion').val(local.region_id);
                $('#editCanal').val(local.canal_id);
                $('#editZona').val(local.zona_id);
                $('#editJefeVenta').val(local.jefe_venta_id);
                $('#editVendedor').val(local.vendedor_id);

                const promesas = [];

                if (local.empresa_id) {
                    promesas.push(
                        cargarDivisiones(local.empresa_id, $('#editDivision'), '-- Seleccione una División --', true)
                            .done(function () {
                                $('#editDivision').val(local.division_id || '0');
                            })
                    );
                }

                if (local.cuenta_id) {
                    promesas.push(
                        cargarCadenas(local.cuenta_id, $('#editCadena'))
                            .done(function () {
                                $('#editCadena').val(local.cadena_id);
                            })
                    );
                }

                if (local.region_id) {
                    promesas.push(
                        cargarComunas(local.region_id, $('#editComuna'), '-- Seleccione una Comuna --')
                            .done(function () {
                                $('#editComuna').val(local.comuna_id);
                            })
                    );
                }

                if (local.canal_id) {
                    promesas.push(
                        cargarSubcanales(local.canal_id, $('#editSubcanal'), '-- Seleccione un Subcanal --')
                            .done(function () {
                                $('#editSubcanal').val(local.subcanal_id);
                            })
                    );
                }

                if (local.zona_id) {
                    promesas.push(
                        cargarDistritos(local.zona_id, $('#editDistrito'))
                            .done(function () {
                                $('#editDistrito').val(local.distrito_id);
                            })
                    );
                }

                $.when.apply($, promesas).always(function () {
                    const lat = isNaN(parseFloat(local.lat)) ? -33.4489 : parseFloat(local.lat);
                    const lng = isNaN(parseFloat(local.lng)) ? -70.6693 : parseFloat(local.lng);

                    window.editMap = new google.maps.Map(document.getElementById('mapEdit'), {
                        center: { lat: lat, lng: lng },
                        zoom: 13
                    });

                    window.editMarker = new google.maps.Marker({
                        position: { lat: lat, lng: lng },
                        map: window.editMap,
                        draggable: true
                    });

                    $('#editLat').val(lat);
                    $('#editLng').val(lng);

                    window.editMarker.addListener('dragend', function (event) {
                        $('#editLat').val(event.latLng.lat());
                        $('#editLng').val(event.latLng.lng());
                    });

                    const triggerGeocodingEditarLocal = debounce(function () {
                        const direccion = $('#editDireccion').val().trim();
                        let regionText = $('#editRegion option:selected').text().trim();
                        const comunaText = $('#editComuna option:selected').text().trim();

                        if (regionText.includes('-')) {
                            regionText = regionText.split('-', 2)[1].trim();
                        }

                        if (
                            direccion !== '' &&
                            regionText !== '' &&
                            regionText !== '-- Seleccione una Región --' &&
                            comunaText !== '' &&
                            comunaText !== '-- Seleccione una Comuna --'
                        ) {
                            let fullAddress = direccion;
                            if (comunaText) fullAddress += ', ' + comunaText;
                            if (regionText) fullAddress += ', ' + regionText;
                            fullAddress += ', Chile';

                            geocodeAddress(fullAddress, window.editMap, window.editMarker, '#editLat', '#editLng');
                        }
                    }, 500);

                    $('#editDireccion').off('blur.geo').on('blur.geo', triggerGeocodingEditarLocal);
                    $('#editComuna').off('change.geo').on('change.geo', triggerGeocodingEditarLocal);

                    $('#modalEditarLocal').modal('show');
                });
            },
            error: function () {
                mostrarAlertaPrincipal('danger', 'Ocurrió un error al obtener los datos del local.');
            }
        });
    });

    /* =========================================================
       ESTADO INICIAL
    ========================================================= */
    mostrarEstadoInicialTabla();
});
</script>

<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap" async defer></script>
</body>
</html>
<?php
// Cerrar statement solo si existe y está inicializado
if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}

// Cerrar conexión si está activa
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>

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
        .card-info:not(.card-outline)>.card-header {
            background-color: #4b545c!important;
        }
        .card-success:not(.card-outline)>.card-header {
            background-color: #4b545c!important;
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
    
    <!-- Definir initMap para Crear Local -->
    <script>
        function initMap() {
            var defaultPos = { lat: -33.4489, lng: -70.6693 };
            var map = new google.maps.Map(document.getElementById('map'), {
                center: defaultPos,
                zoom: 13
            });
            var marker = new google.maps.Marker({
                position: defaultPos,
                map: map,
                draggable: true
            });
            marker.addListener('dragend', function(event) {
                $('#lat').val(event.latLng.lat());
                $('#lng').val(event.latLng.lng());
            });
            
            function triggerGeocodingCrearLocal() {
                var direccion = $('#inputDireccion').val().trim();
                var regionText = $('#selectRegion option:selected').text().trim();
                var comunaText = $('#selectComuna option:selected').text().trim();
                if (regionText.includes('-')) {
                    regionText = regionText.split('-', 2)[1].trim();
                }
                if (direccion !== '' && regionText !== '-- Seleccione una Región --' && comunaText !== '-- Seleccione una Comuna --') {
                    var fullAddress = direccion + ', ' + comunaText + ', ' + regionText + ', Chile';
                    geocodeAddress(fullAddress, map, marker);
                }
            }
            
            $('#inputDireccion').on('blur', debounce(triggerGeocodingCrearLocal, 500));
            $('#selectRegion').on('change', debounce(triggerGeocodingCrearLocal, 500));
            $('#selectComuna').on('change', debounce(triggerGeocodingCrearLocal, 500));
        }
        
        function debounce(func, delay) {
            let debounceTimer;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => func.apply(context, args), delay);
            };
        }
        
        function geocodeAddress(address, map, marker) {
            var geocoder = new google.maps.Geocoder();
            geocoder.geocode({ 'address': address, 'componentRestrictions': { 'country': 'CL' } }, function(results, status) {
                if (status === 'OK') {
                    map.setCenter(results[0].geometry.location);
                    marker.setPosition(results[0].geometry.location);
                    $('#lat').val(results[0].geometry.location.lat());
                    $('#lng').val(results[0].geometry.location.lng());
                } else {
                    alert('Geocoding no fue exitoso por: ' + status);
                }
            });
        }
    </script>
    
    <script>
    
    $(document).ready(function () {
    function cargarDistritos(zonaId, distritoSelect) {
    return $.ajax({
        url: 'mod_local/obtener_distritos_por_zona.php',
        type: 'GET',
        data: { zona_id: zonaId },
        dataType: 'json'
    }).done(function(response) {
        distritoSelect.empty().append('<option value="">-- Seleccione un Distrito --</option>');
        if (response.success && response.data.length > 0) {
            $.each(response.data, function(index, dist) {
                distritoSelect.append('<option value="' + dist.id + '">' + dist.nombre_distrito + '</option>');
            });
            distritoSelect.prop('disabled', false);
        } else if (!response.success) {
            alert('Error: ' + response.message);
            distritoSelect.prop('disabled', true);
        } else {
            distritoSelect.append('<option value="">No hay distritos disponibles</option>');
            distritoSelect.prop('disabled', true);
        }
    }).fail(function(xhr, status, error) {
        alert('Ocurrió un error al cargar los distritos.');
    });
}

// Al cambiar ZONA en Crear Local
$('#selectZona').change(function () {
    var zonaId = $(this).val();
    var distritoSelect = $('#selectDistrito');
    if (zonaId !== '') {
        cargarDistritos(zonaId, distritoSelect);
    } else {
        distritoSelect.empty().append('<option value="">-- Seleccione un Distrito --</option>').prop('disabled', true);
    }
});

// Al cambiar ZONA en Editar Local
$('#editZona').change(function () {
    var zonaId = $(this).val();
    var distritoSelect = $('#editDistrito');
    if (zonaId !== '') {
        cargarDistritos(zonaId, distritoSelect);
    } else {
        distritoSelect.empty().append('<option value="">-- Seleccione un Distrito --</option>').prop('disabled', true);
    }
});        


// al cambiar empresa en el mkodal de editar local
$('#editEmpresa').change(function() {
  var empresaId = $(this).val();
  var $divSel   = $('#editDivision');
  if (empresaId) {
    $.ajax({
      url: 'mod_local/cargar_divisiones.php',
      type: 'GET',
      data: { empresa_id: empresaId },
      success: function(htmlOptions) {
        $divSel
          .html('<option value="0">Sin división</option>' + htmlOptions)
          .prop('disabled', false);
      },
      error: function() {
        $divSel.html('<option value="0">Error al cargar divisiones</option>');
      }
    });
  } else {
    $divSel.html('<option value="0">Sin división</option>').prop('disabled', true);
  }
});  
        
        
        
        $('#selectCanal').change(function () {
    var canalId = $(this).val();
    var subcanalSelect = $('#selectSubcanal');
    if (canalId !== '') {
        cargarSubcanales(canalId, subcanalSelect);
    } else {
        subcanalSelect.empty().append('<option value="">-- Seleccione un Subcanal --</option>');
        subcanalSelect.prop('disabled', true);
    }
});
        
        
        $('#crearSubcanalLinkEdit').click(function(e){
        e.preventDefault();
        $('#modalEditarLocal').modal('hide');
        $('#modalCrearSubcanal').modal('show');
    });
        bsCustomFileInput.init();

        // Intercepción del formCrearLocal (CREAR LOCAL)
        $('#formCrearLocal').submit(function(e) {
            e.preventDefault(); 
            $('#spinnerCrearLocal').show();

            var formData = new FormData(this);
            $.ajax({
                url: 'mod_local/procesar.php', 
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    $('#spinnerCrearLocal').hide();
                    if(response.success) {
                        mostrarAlertaPrincipal('success', response.message);
                        $('#formCrearLocal')[0].reset();
                        $('#selectCadena').empty().append('<option value="">-- Seleccione una Cadena --</option>');
                        $('#selectComuna').empty().append('<option value="">-- Seleccione una Comuna --</option>').prop('disabled', true);
                        $('#modalCrearLocal').modal('hide');
                        cargarLocales({});
                    } else {
                        mostrarAlertaPrincipal('danger', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#spinnerCrearLocal').hide();
                    mostrarAlertaPrincipal('danger', 'Ocurrió un error inesperado al procesar la solicitud.');
                }
            });
        });

        // Cuando cambie la selección de empresa...
        $("#empresaCarga").change(function(){
            var empresa_id = $(this).val();
            if (empresa_id) {
                // Hacer la llamada AJAX para cargar divisiones
                $.ajax({
                    url: 'mod_local/cargar_divisiones.php',  // Archivo que crearemos a continuación
                    type: 'GET',
                    data: { empresa_id: empresa_id },
                    success: function(response) {
                        // Rellenar el select de divisiones y mostrar el contenedor
                        $("#divisionCarga").html(response);
                        $("#divisionesContainer").show();
                    },
                    error: function() {
                        $("#divisionCarga").html('<option value="">Error al cargar divisiones</option>');
                    }
                });
            } else {
                // Si no se selecciona una empresa, ocultamos el select de divisiones
                $("#divisionesContainer").hide();
                $("#divisionCarga").html('<option value="">-- Seleccione una División --</option>');
            }
        });

        // Intercepción del formCargaMasiva (CARGA MASIVA)
$('#formCargaMasiva').submit(function(e) {
  console.log('📤 submit de formCargaMasiva disparado');

  // 1) Validación previa: empresa seleccionada
  if (!$('#empresaCarga').val()) {
    console.log('⚠️ No hay empresa seleccionada, devolviendo antes de preventDefault');
    mostrarAlertaPrincipal('warning', 'Por favor selecciona una Empresa para la carga masiva.');
    return; // sale antes de hacer preventDefault
  }

  // 2) Validar que haya archivo
  if (!$('#csvFile').get(0).files.length) {
    console.log('⚠️ No hay archivo CSV adjunto, devolviendo antes de preventDefault');
    mostrarAlertaPrincipal('warning', 'Selecciona un archivo CSV.');
    return;
  }

  // 3) Si llegamos aquí, todo OK ⇒ cancelamos el envío nativo
  e.preventDefault();
  console.log('– after e.preventDefault()');

  // 4) Mostrar valores clave
  console.log('empresaCarga val:', $('#empresaCarga').val());
  console.log('csvFile files.length:', $('#csvFile')[0].files.length);

  // 5) justo antes de AJAX
  console.log('– a punto de llamar a $.ajax');
  $('#spinnerCargaMasiva').show();
  $('#resultado').empty();

  var $form = $(this);
  var formData = new FormData(this);
  // >> coge el hidden *dentro* de este form
  var csrf = $form.find('input[name="csrf_token"]').val();
  console.log('csrf hidden token:', csrf);
  formData.set('csrf_token', csrf);

  $.ajax({
    url: '/visibility2/portal/modulos/mod_local/procesar_csv.php',
    type: 'POST',
    data: formData,
    contentType: false,
    processData: false,
    dataType: 'json',
    beforeSend: function() {
      console.log('> beforeSend: petición AJAX iniciada');
    },
    success: function(response) {
      console.log('✅ AJAX success', response);
      $('#spinnerCargaMasiva').hide();
      var total = response.inserted + response.failed;

      if (response.inserted > 0 && response.failed === 0) {
        mostrarAlertaPrincipal('success',
          'Se subieron todos los locales (' + response.inserted + ' de ' + total + ').'
        );
        $('#formCargaMasiva')[0].reset();
        $('.custom-file-label').text('Selecciona un archivo CSV');
      }
      else if (response.inserted > 0 && response.failed > 0) {
        mostrarAlertaPrincipal('warning',
          'Se subieron ' + response.inserted + ' de ' + total +
          '. ' + response.failed + ' no se subieron.'
        );
        if (response.reportUrl) {
          $('#resultado').append(
            '<a href="' + response.reportUrl +
            '" class="btn btn-sm btn-danger" target="_blank">' +
            'Descargar CSV de fallidos</a>'
          );
        }
      }
      else {
        mostrarAlertaPrincipal('danger',
          'No se subió ningún local. Revisa el formato del CSV.'
        );
        if (response.reportUrl) {
          $('#resultado').append(
            '<a href="' + response.reportUrl +
            '" class="btn btn-sm btn-danger" target="_blank">' +
            'Descargar CSV de fallidos</a>'
          );
        }
      }
    },
    error: function(xhr, status, error) {
      console.error('❌ AJAX error', status, error);
      console.error('Respuesta completa del servidor:', xhr.responseText);
      $('#spinnerCargaMasiva').hide();
      mostrarAlertaPrincipal('danger',
        'Ocurrió un error inesperado al procesar la carga masiva. Revisa consola.'
      );
    }
  });
});
        // Funciones para cargar datos dinámicos
        function cargarCadenas(cuentaId, cadenaSelect) {
            return $.ajax({
                url: 'obtener_cadenas.php',
                type: 'GET',
                data: { cuenta_id: cuentaId },
                dataType: 'json'
            }).done(function(response) {
                cadenaSelect.empty().append('<option value="">-- Seleccione una Cadena --</option>');
                if (response.success && response.data.length > 0) {
                    $.each(response.data, function(index, cadena) {
                        cadenaSelect.append('<option value="' + cadena.id + '">' + cadena.nombre + '</option>');
                    });
                } else {
                    cadenaSelect.append('<option value="">No hay cadenas disponibles</option>');
                }
            }).fail(function(xhr, status, error) {
                alert('Ocurrió un error al cargar las cadenas.');
            });
        }
        function cargarSubcanales(canalId, subcanalSelect) {
            return $.ajax({
                url: 'mod_local/obtener_subcanales_por_canal.php',
                type: 'GET',
                data: { canal_id: canalId },
                dataType: 'json'
            }).done(function(response) {
                subcanalSelect.empty().append('<option value="">-- Seleccione un Subcanal --</option>');
                if (response.success && response.data.length > 0) {
                    $.each(response.data, function(index, sub) {
                        subcanalSelect.append('<option value="' + sub.id + '">' + sub.nombre_subcanal + '</option>');
                    });
                    subcanalSelect.prop('disabled', false);
                } else if (!response.success) {
                    alert('Error: ' + response.message);
                    subcanalSelect.prop('disabled', true);
                } else {
                    subcanalSelect.append('<option value="">No hay subcanales disponibles</option>');
                    subcanalSelect.prop('disabled', true);
                }
            }).fail(function(xhr, status, error) {
                alert('Ocurrió un error al cargar los subcanales.');
            });
        }
            function cargarSubcanalesEdit(canalId, subcanalSelect) {
                return $.ajax({
                    url: 'mod_local/obtener_subcanales_por_canal.php',
                    type: 'GET',
                    data: { canal_id: canalId },
                    dataType: 'json'
                }).done(function(response) {
                    subcanalSelect.empty().append('<option value="">-- Seleccione un Subcanal --</option>');
                    if (response.success && response.data.length > 0) {
                        $.each(response.data, function(index, sub) {
                            subcanalSelect.append('<option value="' + sub.id + '">' + sub.nombre_subcanal + '</option>');
                        });
                        subcanalSelect.prop('disabled', false);
                    } else if (!response.success) {
                        alert('Error: ' + response.message);
                        subcanalSelect.prop('disabled', true);
                    } else {
                        subcanalSelect.append('<option value="">No hay subcanales disponibles</option>');
                        subcanalSelect.prop('disabled', true);
                    }
                }).fail(function(xhr, status, error) {
                    alert('Ocurrió un error al cargar los subcanales.');
                });
            }

// Al cambiar #editCanal, cargamos subcanales
$('#editCanal').change(function() {
    var canalId = $(this).val();
    var subcanalSelect = $('#editSubcanal');
    if (canalId !== '') {
        cargarSubcanalesEdit(canalId, subcanalSelect);
    } else {
        subcanalSelect.empty().append('<option value="">-- Seleccione un Subcanal --</option>');
        subcanalSelect.prop('disabled', true);
    }
});

        function cargarComunas(regionId, comunaSelect) {
            return $.ajax({
                url: 'mod_local/obtener_comunas_por_region.php',
                type: 'GET',
                data: { region_id: regionId },
                dataType: 'json'
            }).done(function(response) {
                comunaSelect.empty().append('<option value="">-- Seleccione una Comuna --</option>');
                if (response.success && response.data.length > 0) {
                    $.each(response.data, function(index, comuna) {
                        comunaSelect.append('<option value="' + comuna.id + '">' + comuna.comuna + '</option>');
                    });
                    comunaSelect.prop('disabled', false);
                } else if (!response.success) {
                    alert('Error al obtener las comunas: ' + response.message);
                    comunaSelect.append('<option value="">' + response.message + '</option>');
                    comunaSelect.prop('disabled', true);
                } else {
                    comunaSelect.append('<option value="">No hay comunas disponibles</option>');
                    comunaSelect.prop('disabled', true);
                }
            }).fail(function(xhr, status, error) {
                // Manejo de error si es necesario
            });
        }

        function cargarLocales(filtros) {
            $.ajax({
                url: 'mod_local/fetch_locales.php',
                type: 'GET',
                data: filtros,
                dataType: 'json',
                success: function (response) {
                    var tbody = $('#tablaLocales tbody');
                    tbody.empty();
                    if (response.success) {
                        if (response.data.length > 0) {
                            $.each(response.data, function (index, local) {
                                var fila = '<tr>' +
                                    '<td>' + local.id + '</td>' +
                                    '<td>' + local.codigo + '</td>' +
                                    '<td>' + local.canal + '</td>' +
                                    '<td>' + local.nombre + '</td>' +
                                    '<td>' + local.direccion + '</td>' +
                                    '<td>' + local.cuenta + '</td>' +
                                    '<td>' + local.cadena + '</td>' +
                                    '<td>' + local.comuna + '</td>' +
                                    '<td>' + local.region + '</td>' +
                                    '<td>' + local.empresa + '</td>' +
                                    '<td>' + (local.lat ? local.lat : 'N/A') + '</td>' +
                                    '<td>' + (local.lng ? local.lng : 'N/A') + '</td>' +
                                    '<td>' +
                                        '<button type="button" class="btn btn-sm btn-primary mr-2 btnEditarLocal" data-id="' + local.id + '"><i class="fas fa-edit"></i> Editar</button>' +
                                        '<a href="mod_local/eliminar_local.php?id=' + local.id + '" class="btn btn-sm btn-danger"><i class="fas fa-trash-alt"></i> Eliminar</a>' +
                                    '</td>' +
                                '</tr>';
                                tbody.append(fila);
                            });
                        } else {
                            tbody.append('<tr><td colspan="12" class="text-center">No se encontraron locales.</td></tr>');
                        }
                    } else {
                        mostrarAlertaPrincipal('danger', 'Error al obtener los locales: ' + response.message);
                    }
                },
                error: function (xhr, status, error) {
                    mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar los locales.');
                }
            });
        }

        // Al cambiar la cuenta, cargar las cadenas
        $('#selectCuenta').change(function () {
            var cuentaId = $(this).val();
            var cadenaSelect = $('#selectCadena');
            if (cuentaId !== '') {
                cargarCadenas(cuentaId, cadenaSelect);
            } else {
                cadenaSelect.empty().append('<option value="">-- Seleccione una Cadena --</option>');
            }
        });
        $('#selectRegion').change(function () {
            var regionId = $(this).val();
            var comunaSelect = $('#selectComuna');
            if (regionId !== '') {
                cargarComunas(regionId, comunaSelect);
            } else {
                comunaSelect.empty().append('<option value="">-- Seleccione una Comuna --</option>');
                comunaSelect.prop('disabled', true);
            }
        });

        // Para Editar Local
        $('#editCuenta').change(function () {
            var cuentaId = $(this).val();
            var cadenaSelect = $('#editCadena');
            if (cuentaId !== '') {
                cargarCadenas(cuentaId, cadenaSelect);
            } else {
                cadenaSelect.empty().append('<option value="">-- Seleccione una Cadena --</option>');
            }
        });
        $('#editRegion').change(function () {
            var regionId = $(this).val();
            var comunaSelect = $('#editComuna');
            if (regionId !== '') {
                cargarComunas(regionId, comunaSelect);
            } else {
                comunaSelect.empty().append('<option value="">-- Seleccione una Comuna --</option>');
                comunaSelect.prop('disabled', true);
            }
        });

        // Filtros
        
        
     var currentOffset = 0;
var itemsPerPage = 50;

// Función para obtener los filtros actuales desde el formulario
function getFiltrosActuales() {
    return {
        empresa_id: $('#filtroEmpresa').val(),
        region_id: $('#filtroRegion').val(),
        comuna_id: $('#filtroComuna').val(),
        canal_id: $('#filtroCanal').val(),
        subcanal_id: $('#filtroSubcanal').val(),   
        division_id: $('#filtroDivision').val(),     
        nombre: $('#filtroNombre').val(),
        codigo: $('#filtroCodigo').val(),
        id_local: $('#filtroID').val() 
    };
}

// Función para cargar los locales (incluyendo offset y limit)
function cargarLocales(filtros) {
    // Añadir los parámetros de paginación a los filtros
    filtros.offset = currentOffset;
    filtros.limit = itemsPerPage;
    $.ajax({
        url: 'mod_local/fetch_locales.php',
        type: 'GET',
        data: filtros,
        dataType: 'json',
        success: function(response) {
            var tbody = $('#tablaLocales tbody');
            tbody.empty();
            if (response.success) {
                if (response.data.length > 0) {
                    $.each(response.data, function (index, local) {
                        var fila = '<tr>' +
                            '<td>' + local.id + '</td>' +
                            '<td>' + local.codigo + '</td>' +
                            '<td>' + local.canal + '</td>' +
                            '<td>' + local.nombre + '</td>' +
                            '<td>' + local.direccion + '</td>' +
                            '<td>' + local.cuenta + '</td>' +
                            '<td>' + local.cadena + '</td>' +
                            '<td>' + local.comuna + '</td>' +
                            '<td>' + local.region + '</td>' +
                            '<td>' + local.empresa + '</td>' +
                            '<td>' + (local.lat ? local.lat : 'N/A') + '</td>' +
                            '<td>' + (local.lng ? local.lng : 'N/A') + '</td>' +
                            '<td>' +
                                '<button type="button" class="btn btn-sm btn-primary mr-2 btnEditarLocal" data-id="' + local.id + '"><i class="fas fa-edit"></i> Editar</button>' +
                                '<a href="mod_local/eliminar_local.php?id=' + local.id + '" class="btn btn-sm btn-danger"><i class="fas fa-trash-alt"></i> Eliminar</a>' +
                            '</td>' +
                        '</tr>';
                        tbody.append(fila);
                    });
                } else {
                    tbody.append('<tr><td colspan="12" class="text-center">No se encontraron locales.</td></tr>');
                }
                // Actualizar controles de paginación según el total de registros
                actualizarPaginacion(response.total);
            } else {
                mostrarAlertaPrincipal('danger', 'Error al obtener los locales: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al cargar los locales.');
        }
    });
}

// Función para actualizar los controles de paginación
function actualizarPaginacion(totalRecords) {
    var totalPages = Math.ceil(totalRecords / itemsPerPage);
    var paginationDiv = $('#pagination-controls');
    paginationDiv.empty();

    // Selector para elegir la cantidad de registros por página
    var itemsPerPageSelect = $('<select id="itemsPerPageSelect" class="form-control d-inline-block" style="width: auto; margin-right: 10px;"></select>');
    [50, 100, 150, 500].forEach(function(num) {
        var option = $('<option></option>').val(num).text(num);
        if (num === itemsPerPage) {
            option.attr('selected', 'selected');
        }
        itemsPerPageSelect.append(option);
    });
    paginationDiv.append('Mostrar ');
    paginationDiv.append(itemsPerPageSelect);
    paginationDiv.append(' registros. ');

    // Botón "Anterior"
    var prevButton = $('<button class="btn btn-secondary btn-sm mr-1">Anterior</button>');
    if (currentOffset <= 0) {
        prevButton.prop('disabled', true);
    }
    paginationDiv.append(prevButton);

    // Botones de números de página
    for (var i = 1; i <= totalPages; i++) {
        var pageButton = $('<button class="btn btn-secondary btn-sm mr-1"></button>').text(i);
        if (i === (currentOffset / itemsPerPage) + 1) {
            pageButton.removeClass('btn-secondary').addClass('btn-primary');
        }
        paginationDiv.append(pageButton);
    }

    // Botón "Siguiente"
    var nextButton = $('<button class="btn btn-secondary btn-sm">Siguiente</button>');
    if (currentOffset + itemsPerPage >= totalRecords) {
        nextButton.prop('disabled', true);
    }
    paginationDiv.append(nextButton);

    // Eventos para los botones
    prevButton.click(function() {
        if (currentOffset - itemsPerPage >= 0) {
            currentOffset -= itemsPerPage;
            cargarLocales(getFiltrosActuales());
        }
    });
    nextButton.click(function() {
        if (currentOffset + itemsPerPage < totalRecords) {
            currentOffset += itemsPerPage;
            cargarLocales(getFiltrosActuales());
        }
    });
    // Eventos para los botones de números de página
    paginationDiv.find('button').not(':contains("Anterior"), :contains("Siguiente")').click(function() {
        var page = parseInt($(this).text());
        currentOffset = (page - 1) * itemsPerPage;
        cargarLocales(getFiltrosActuales());
    });
    // Evento para el selector de items por página
    itemsPerPageSelect.change(function() {
        itemsPerPage = parseInt($(this).val());
        currentOffset = 0; // reiniciar a la primera página
        cargarLocales(getFiltrosActuales());
    });
}

// Modificar los eventos de los filtros para que utilicen la paginación
$('#filtroCanal').change(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
});

$('#filtroCanal').change(function () {
    var canalId = $(this).val();
    var subcanalSelect = $('#filtroSubcanal');
    if (canalId !== '') {
        cargarSubcanales(canalId, subcanalSelect);
    } else {
        subcanalSelect.empty().append('<option value="">Todos los Subcanales</option>');
        subcanalSelect.prop('disabled', true);
    }
});

$('#filtroEmpresa').change(function () {
    var empresaId = $(this).val();
    var divisionSelect = $('#filtroDivision');
    if (empresaId) {
        $.ajax({
            url: 'mod_local/cargar_divisiones.php',  // Este endpoint debe devolver las <option> correspondientes
            type: 'GET',
            data: { empresa_id: empresaId },
            success: function(response) {
                // Asumimos que 'response' contiene las opciones en formato HTML
                divisionSelect.html(response);
                divisionSelect.prepend('<option value="">Todas las Divisiones</option>');
                divisionSelect.prop('disabled', false);
            },
            error: function() {
                divisionSelect.html('<option value="">Error al cargar divisiones</option>');
                divisionSelect.prop('disabled', true);
            }
        });
    } else {
        divisionSelect.empty().append('<option value="">Todas las Divisiones</option>');
        divisionSelect.prop('disabled', true);
    }
});

$('#filtroRegion').change(function () {
    currentOffset = 0;
    var regionId = $(this).val();
    var comunaSelect = $('#filtroComuna');
    if (regionId !== '') {
        cargarComunas(regionId, comunaSelect).done(function() {
            var filtros = getFiltrosActuales();
            cargarLocales(filtros);
        });
    } else {
        comunaSelect.empty().append('<option value="">Todas las Comunas</option>');
        comunaSelect.prop('disabled', true);
        var filtros = getFiltrosActuales();
        cargarLocales(filtros);
    }
});

$('#filtroComuna').change(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
});

$('#filtroEmpresa').change(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
});

$('#filtroNombre').on('keyup', debounce(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
}, 500));


$('#filtroCodigo').on('keyup', debounce(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
}, 500));


$('#filtroID').on('keyup', debounce(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
}, 500));

$('#btnFiltrar').click(function () {
    currentOffset = 0;
    var filtros = getFiltrosActuales();
    cargarLocales(filtros);
});

$('#btnResetear').click(function () {
    $('#filtrosLocales')[0].reset();
    $('#filtroComuna').empty().append('<option value="">Todas las Comunas</option>');
    $('#filtroComuna').prop('disabled', true);
    currentOffset = 0;
    cargarLocales({});
});

// Cargar la tabla de locales inicialmente sin filtros
cargarLocales({});

// Función de debounce (para optimizar eventos)
function debounce(func, delay) {
    let debounceTimer;
    return function() {
        const context = this;
        const args = arguments;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => func.apply(context, args), delay);
    };
}
        
        // Cargar la tabla de locales inicialmente sin filtros
        cargarLocales({});

        // Link para crear comuna desde Crear/Editar Local
        $('#crearComunaLinkLocal, #crearComunaLinkEdit').click(function (e) {
            e.preventDefault();
            $('#modalCrearLocal, #modalEditarLocal').modal('hide');
            $('#modalCrearComuna').modal('show');
        });

        // Link para crear subcanal desde el Modal de Crear Local
        $('#crearSubcanalLinkLocal').click(function(e) {
            e.preventDefault();
            $('#modalCrearLocal').modal('hide');
            $('#modalCrearSubcanal').modal('show');
        });

        // Evento para Editar Local (abre el modal)
        $(document).on('click', '.btnEditarLocal', function () {
            
    var localId = $(this).data('id');
    $.ajax({
        url: 'mod_local/get_local.php',
        type: 'GET',
        data: { id: localId },
        dataType: 'json',
        success: function (response) {
            if (response.success) {
                var local = response.data;

                // Asignar valores al formulario de edición
                $('#editLocalId').val(local.id);
                $('#editCodigoLocal').val(local.codigo);
                $('#editLocal').val(local.nombre);
                $('#editDireccion').val(local.direccion);
                $('#editEmpresa').val(local.empresa_id);
                $.ajax({
                      url: 'mod_local/cargar_divisiones.php',
                      type: 'GET',
                      data: { empresa_id: local.empresa_id },
                      success: function(htmlOptions) {
                        $('#editDivision')
                          .html('<option value="0">Sin división</option>' + htmlOptions)
                          .prop('disabled', false)
                          .val(local.division_id || 0);
                      },
                      error: function() {
                        $('#editDivision')
                          .html('<option value="0">Error al cargar divisiones</option>')
                          .prop('disabled', true);
                      }
                    });
                
                // Asignar Cuenta y cargar Cadenas
                $('#editCuenta').val(local.cuenta_id).trigger('change');
                cargarCadenas(local.cuenta_id, $('#editCadena')).done(function() {
                    $('#editCadena').val(local.cadena_id);
                });
                
                // Asignar Región y cargar Comunas
                $('#editRegion').val(local.region_id).trigger('change');
                cargarComunas(local.region_id, $('#editComuna')).done(function() {
                    $('#editComuna').val(local.comuna_id);
                });
                
                // ASIGNAR CANAL y cargar SUBCANALES
                $('#editCanal').val(local.canal_id).trigger('change');
                cargarSubcanalesEdit(local.canal_id, $('#editSubcanal')).done(function() {
                    $('#editSubcanal').val(local.subcanal_id);
                });
                $('#editZona').val(local.zona_id).trigger('change');
                cargarDistritos(local.zona_id, $('#editDistrito')).done(function() {
                    $('#editDistrito').val(local.distrito_id);
                });
                $('#editJefeVenta').val(local.jefe_venta_id);
                $('#editVendedor').val(local.vendedor_id);
                
                
               var lat = parseFloat(local.lat);
                var lng = parseFloat(local.lng);
                lat = isNaN(lat) ? -33.4489 : lat;
                lng = isNaN(lng) ? -70.6693 : lng;
                
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
                window.editMarker.addListener('dragend', function(event) {
                    $('#editLat').val(event.latLng.lat());
                    $('#editLng').val(event.latLng.lng());
                });

                // Funciones de geocodificación en edición
                window.triggerGeocodingEditarLocal = function() {
                    var direccion = $('#editDireccion').val().trim();
                    var regionText = $('#editRegion option:selected').text().trim();
                    var comunaText = $('#editComuna option:selected').text().trim();
                    
                    if (regionText.includes('-')) {
                        regionText = regionText.split('-', 2)[1].trim();
                    }
                    if (direccion !== '' && regionText !== '-- Seleccione una Región --' && comunaText !== '-- Seleccione una Comuna --') {
                        var fullAddress = direccion;
                        if (comunaText && comunaText !== '-- Seleccione una Comuna --') {
                            fullAddress += ', ' + comunaText;
                        }
                        if (regionText && regionText !== '-- Seleccione una Región --') {
                            fullAddress += ', ' + regionText;
                        }
                        fullAddress += ', Chile';
                        geocodeAddressEdit(fullAddress, window.editMap, window.editMarker);
                    }
                };

                function geocodeAddressEdit(address, map, marker) {
                    var geocoder = new google.maps.Geocoder();
                    geocoder.geocode({ 'address': address, 'componentRestrictions': { 'country': 'CL' } }, function(results, status) {
                        if (status === 'OK') {
                            map.setCenter(results[0].geometry.location);
                            marker.setPosition(results[0].geometry.location);
                            $('#editLat').val(results[0].geometry.location.lat());
                            $('#editLng').val(results[0].geometry.location.lng());
                        } else {
                            alert('Geocoding no fue exitoso por: ' + status);
                        }
                    });
                }

                $('#editDireccion').off('blur').on('blur', debounce(window.triggerGeocodingEditarLocal, 500));
                $('#editRegion').off('change').on('change', debounce(function() {
                    var newRegionId = $(this).val();
                    var comunaSelect = $('#editComuna');
                    if (newRegionId !== '') {
                        cargarComunas(newRegionId, comunaSelect).done(function() {
                            comunaSelect.val('');
                            window.triggerGeocodingEditarLocal();
                        });
                    } else {
                        comunaSelect.empty().append('<option value="">-- Seleccione una Comuna --</option>');
                        comunaSelect.prop('disabled', true);
                        window.triggerGeocodingEditarLocal();
                    }
                }, 500));
                $('#editComuna').off('change').on('change', debounce(window.triggerGeocodingEditarLocal, 500));

                // Mostrar el modal de edición
                $('#modalEditarLocal').modal('show');
            } else {
                mostrarAlertaPrincipal('danger', 'Error al obtener los datos del local: ' + response.message);
            }
        },
        error: function (xhr, status, error) {
            mostrarAlertaPrincipal('danger', 'Ocurrió un error al obtener los datos del local.');
        }
    });
});
    });
    
    </script>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const divisionSelect = document.getElementById("divisionCarga");
  const fileInput = document.getElementById("csvFile");

  // Desactivar por defecto
  fileInput.disabled = true;

  // Escuchar cambios en el select
  divisionSelect.addEventListener("change", function () {
    if (this.value && this.value.trim() !== "") {
      fileInput.disabled = false;
    } else {
      fileInput.disabled = true;
      fileInput.value = ""; // limpiar archivo si se deshabilita
    }
  });
});
</script>

    <!-- Carga la API de Google Maps -->
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

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


require_once __DIR__ . '/_session_guard.php';
require_once __DIR__ . '/includes/permisos.php';
// Verificar si el usuario ha iniciado sesi車n
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}



if ((int)($_SESSION['usuario_perfil'] ?? 0) === 3) {
    // Cierre de sesión “limpio” si intenta forzar URL internas
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

// Variables de sesi車n
$nombre     = $_SESSION['usuario_nombre'];
$apellido   = $_SESSION['usuario_apellido'];
$fotoPerfil = $_SESSION['usuario_fotoPerfil'];
$empresa    = $_SESSION['empresa_nombre'];
$perfilUser = $_SESSION['perfil_nombre'];
$usuariofc  = $_SESSION['usuario_fechaCreacion'];
$email      = $_SESSION['email'];
$telefono   = $_SESSION['telefono'];


if (!empty($_SESSION['usuario_fechaCreacion'])) {
    try {
        $dt = new DateTime($_SESSION['usuario_fechaCreacion']);

        if (class_exists('IntlDateFormatter')) {
            // Usa la locale que prefieras: 'es_ES', 'es_CL', etc.
            $fmt = new IntlDateFormatter(
                'es_ES',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                date_default_timezone_get(),
                IntlDateFormatter::GREGORIAN,
                "MMMM - yyyy"
            );
            $usuariofc = ucfirst($fmt->format($dt));
        } else {
            // Fallback si no está instalada la extensión intl
            $meses = [
                'enero','febrero','marzo','abril','mayo','junio',
                'julio','agosto','septiembre','octubre','noviembre','diciembre'
            ];
            $mes = $meses[(int)$dt->format('n') - 1] ?? '';
            $usuariofc = ucfirst($mes) . ' - ' . $dt->format('Y');
        }
    } catch (Throwable $e) {
        $usuariofc = "Fecha no disponible";
    }
} else {
    $usuariofc = "Fecha no disponible";
}


if (empty($fotoPerfil)) {
    $fotoPerfil = 'dist/img/mentecreativa.png';
}

// Obtener la divisi車n del usuario desde la sesi車n (almacenada en procesar_login.php)
$division_id = isset($_SESSION['division_id']) ? (int)$_SESSION['division_id'] : 0;
$division    = isset($_GET['division']) ? (int)$_GET['division'] : $division_id;

// si necesitas el nombre para decidir si mostrar el select MC:
$division_nombre = '';
if ($division_id > 0) {
    $stmt = $conn->prepare("SELECT nombre FROM division_empresa WHERE id = ?");
    $stmt->bind_param("i", $division_id);
    $stmt->execute();
    $stmt->bind_result($division_nombre);
    $stmt->fetch();
    $stmt->close();
}

$esUsuarioMC = strtoupper(trim($division_nombre)) === 'MC';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <base href="/visibility2/portal/">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Visibility Web / Dashboard</title>

  <!-- Google Font: Source Sans Pro -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <link rel="icon" type="image/png" href="images/logo/Logo_MENTE CREATIVA-02.png">  
  <style>
.mt-2{
    font-size: 80%;
}
.nav-header{
    font-size: 80%!important;          
}
.d-block{
    font-size: 80%!important;
}
.form-control{
    font-size: 80%!important;    
}
.d-none{
    font-size: 80%!important;      
}
.modal-title{
    font-size: 80%!important;    
}
.t2{
    font-size: 80%!important;    
}
.form-submit-overlay{
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.form-submit-box{
    width: 100%;
    max-width: 460px;
    background: #fff;
    border-radius: 18px;
    padding: 28px 24px;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
    text-align: center;
    border: 1px solid rgba(0,0,0,.05);
}

/* =========================================================
   MODAL DESCARGA VEHÍCULOS
========================================================= */

.vehicle-download-modal {
    border: 0 !important;
    border-radius: 28px !important;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.12), transparent 36%),
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96)) !important;
    box-shadow:
        0 34px 95px rgba(20,45,90,.30),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.vehicle-download-header {
    min-height: 76px;
    background:
        radial-gradient(circle at 12% 0%, rgba(95,160,255,.14), transparent 36%),
        rgba(248,251,255,.96) !important;
    border-bottom: 1px solid rgba(205,218,238,.78) !important;
    color: #172848 !important;
    align-items: center;
}

.vehicle-download-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.vehicle-download-icon {
    width: 52px;
    height: 52px;
    min-width: 52px;
    border-radius: 18px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d7eff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    box-shadow:
        0 12px 26px rgba(70,110,180,.12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.vehicle-download-header .modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 950;
    color: #172848;
}

.vehicle-download-header p {
    margin: 4px 0 0;
    font-size: 12px;
    font-weight: 700;
    color: #7285a4;
}

.vehicle-download-close {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: rgba(240,246,255,.95) !important;
    color: #536782 !important;
    text-shadow: none !important;
    opacity: 1 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.vehicle-download-body {
    padding: 22px !important;
}

.vehicle-download-option {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px;
    border-radius: 22px;
    margin-bottom: 14px;
    text-decoration: none !important;
    background: rgba(255,255,255,.86);
    border: 1px solid rgba(215,228,246,.95);
    box-shadow:
        0 14px 30px rgba(70,95,140,.08),
        inset 0 1px 0 rgba(255,255,255,.88);
    transition: all .18s ease;
}

.vehicle-download-option:hover {
    transform: translateY(-2px);
    box-shadow:
        0 20px 38px rgba(70,95,140,.14),
        inset 0 1px 0 rgba(255,255,255,.92);
}

.vehicle-download-option-icon {
    width: 54px;
    height: 54px;
    min-width: 54px;
    border-radius: 18px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.vehicle-download-option-icon.blue {
    background: linear-gradient(135deg, #4d7eff, #6a73ff);
}

.vehicle-download-option-icon.purple {
    background: linear-gradient(135deg, #7d5fff, #5f47ff);
}

.vehicle-download-option-text {
    flex: 1;
}

.vehicle-download-option-text strong {
    display: block;
    color: #172848;
    font-size: 14px;
    font-weight: 950;
    margin-bottom: 4px;
}

.vehicle-download-option-text span {
    display: block;
    color: #7285a4;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.35;
}

.vehicle-download-arrow {
    color: #8ba0bd;
}

.vehicle-download-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 4px;
    padding: 14px;
    border-radius: 16px;
    background: rgba(229,242,255,.96);
    color: #245b95;
    font-size: 12px;
    font-weight: 750;
}

/* =========================================================
   AJUSTE HOME IFRAME SIN MARCO BLANCO INFERIOR
========================================================= */

html,
body {
    height: 100%;
}

body.layout-fixed {
    overflow: hidden;
}

.content-wrapper.iframe-mode {
    padding: 0 !important;
    margin-bottom: 0 !important;
    overflow: hidden !important;
    background: #eef7ff !important;
}

.content-wrapper.iframe-mode > .nav {
    display: none !important;
    height: 0 !important;
    min-height: 0 !important;
    max-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    overflow: hidden !important;
}

.content-wrapper.iframe-mode .tab-content,
.content-wrapper.iframe-mode .tab-pane {
    width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden !important;
    background: #eef7ff !important;
}

.content-wrapper.iframe-mode .tab-pane iframe {
    width: 100% !important;
    border: 0 !important;
    display: block !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #eef7ff !important;
}
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed" data-panel-auto-height-mode="height">
<div class="wrapper">

<?php if (strtoupper(trim($division_nombre)) != 'MC'): ?>
  <input type="hidden" id="division" name="division"
         value="<?php echo (int)$division; ?>">
<?php endif; ?>

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button">
          <i class="fas fa-bars"></i>
        </a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="ui_dashboard.php" class="nav-link">Inicio</a>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <!-- Navbar Search -->
      <li class="nav-item">
        <a class="nav-link" data-widget="navbar-search" href="#" role="button">
          <i class="fas fa-search"></i>
        </a>
        <div class="navbar-search-block">
          <form class="form-inline">
            <div class="input-group input-group-sm">
              <input class="form-control form-control-navbar" type="search" placeholder="Buscar" aria-label="Search">
              <div class="input-group-append">
                <button class="btn btn-navbar" type="submit">
                  <i class="fas fa-search"></i>
                </button>
                <button class="btn btn-navbar" type="button" data-widget="navbar-search">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>
          </form>
        </div>
      </li>
      <!-- Men迆 de usuario -->
      <li class="nav-item dropdown user-menu">
        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
          <img src="<?php echo htmlspecialchars($fotoPerfil); ?>" class="user-image img-circle elevation-2" alt="User Image">
          <i class="far fa-user"></i>
          <span class="d-none d-md-inline"><?php echo htmlspecialchars($nombre . ' ' . $apellido); ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right" style="left: inherit; right: 0px;">
          <!-- User image -->
          <li class="user-header bg-primary">
            <img src="<?php echo htmlspecialchars($fotoPerfil); ?>" class="img-circle elevation-2" alt="User Image">
            <p>
              <?php echo htmlspecialchars($nombre . ' ' . $apellido); ?> - <?php echo htmlspecialchars($perfilUser); ?>
              <small>Registrado desde <?php echo htmlspecialchars($usuariofc); ?></small>
            </p>
          </li>
          <li class="user-footer">
            <a href="#" class="btn btn-default btn-flat" data-toggle="modal" data-target="#modalPerfil">Perfil</a>
            <a href="modulos/logout.php" class="btn btn-default btn-flat float-right">Cerrar sesi&oacute;n</a>
          </li>
        </ul>
      </li>
      <li class="nav-item">
        <a class="nav-link" data-widget="fullscreen" href="#" role="button">
          <i class="fas fa-expand-arrows-alt"></i>
        </a>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->

  <!-- Modal de Perfil -->
  <div class="modal fade" id="modalPerfil" tabindex="-1" role="dialog" aria-labelledby="modalPerfilLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="modalPerfilLabel">Editar Perfil</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <div id="mensajePerfil"></div>
                  <form id="formPerfil" enctype="multipart/form-data">
                      <div class="text-center mb-3">
                          <img id="previewImagen" src="<?php echo htmlspecialchars($fotoPerfil); ?>" alt="Foto de Perfil" class="rounded-circle" width="100">
                      </div>
                      <div class="form-group">
                          <label for="fotoPerfil">Cambiar Foto de Perfil</label>
                          <input type="file" class="form-control" id="fotoPerfil" name="fotoPerfil" accept="image/*">
                      </div>
                      <div class="row">
                          <div class="col-12 col-sm-6">
                              <div class="form-group">
                                  <label for="nombre">Nombre</label>
                                  <input type="text" class="form-control" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre); ?>" required>
                              </div>
                              <div class="form-group">
                                  <label for="apellido">Apellido</label>
                                  <input type="text" class="form-control" id="apellido" name="apellido" value="<?php echo htmlspecialchars($apellido); ?>" required>
                              </div>
                              <div class="form-group">
                                  <label for="email">Correo Electronico</label>
                                  <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                              </div>
                              <div class="form-group">
                                  <label for="telefono">Telofono</label>
                                  <input type="text" class="form-control" id="telefono" name="telefono" value="<?php echo htmlspecialchars($telefono); ?>" required>
                              </div>
                          </div>
                          <div class="col-12 col-sm-6">
                              <div class="form-group">
                                  <input type="text" class="form-control" id="empresa" name="empresa" value="<?php echo htmlspecialchars($empresa); ?>" readonly>
                              </div>
                              <div class="form-group">
                                  <label for="perfilUser">Perfil de Usuario</label>
                                  <input type="text" class="form-control" id="perfilUser" name="perfilUser" value="<?php echo htmlspecialchars($perfilUser); ?>" readonly>
                              </div>
                              <div class="form-group">
                                  <label for="usuariofc">Fecha de Creacion</label>
                                  <input type="text" class="form-control" id="usuariofc" name="usuariofc" value="<?php echo htmlspecialchars($usuariofc); ?>" readonly>
                              </div>
                          </div>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                          <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                      </div>
                  </form>
              </div>
          </div>
      </div>
  </div>

<!-- Modal para Descargar Data Usuarios -->
<div class="modal fade" id="modalDataUsuarios" tabindex="-1" role="dialog" aria-labelledby="modalDataUsuariosLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content shadow-sm border-0">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold" id="modalDataUsuariosLabel">
                    Descargar Data Usuarios
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">

                <?php if (strtoupper(trim($division_nombre)) === 'MC'): ?>
                    <div class="form-group">
                        <label for="division">Seleccionar división:</label>
                        <select class="form-control" id="division" name="division">
                            <option value="">Todas las divisiones</option>
                            <?php
                            $queryDivision = "SELECT id, nombre
                                              FROM division_empresa
                                              WHERE estado = 1
                                              ORDER BY nombre ASC";
                            $resultDivision = $conn->query($queryDivision);

                            while ($rowDivision = $resultDivision->fetch_assoc()) {
                                echo '<option value="' . (int)$rowDivision['id'] . '">'
                                    . htmlspecialchars($rowDivision['nombre'], ENT_QUOTES, 'UTF-8')
                                    . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden"
                           id="division"
                           name="division"
                           value="<?php echo (int)$division_id; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="perfil">Seleccionar perfil:</label>
                    <select class="form-control" id="perfil" name="perfil">
                        <option value="">Todos los perfiles</option>
                        <?php
                        $queryPerfil = "SELECT id, UPPER(nombre) AS nombre
                                        FROM perfil
                                        ORDER BY nombre ASC";
                        $resultPerfil = $conn->query($queryPerfil);

                        while ($rowPerfil = $resultPerfil->fetch_assoc()) {
                            echo '<option value="' . (int)$rowPerfil['id'] . '">'
                                . htmlspecialchars($rowPerfil['nombre'], ENT_QUOTES, 'UTF-8')
                                . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label for="formato">Formato de descarga:</label>
                    <select class="form-control" id="formato" name="formato">
                        <option value="xlsx" selected>Excel (.xlsx)</option>
                        <option value="csv">CSV (.csv)</option>
                    </select>
                    <small class="form-text text-muted">
                        Se recomienda Excel para una visual más ordenada.
                    </small>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-success" onclick="descargarDataUsuarios()">
                    <i class="fa fa-download"></i> Descargar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Overlay de descarga -->
<div id="downloadUsuariosOverlay" class="form-submit-overlay" style="display:none;">
    <div class="form-submit-box">
        <div class="spinner-border text-success mb-3" role="status" style="width:3rem;height:3rem;">
            <span class="sr-only">Procesando...</span>
        </div>
        <h5 class="mb-2" id="downloadUsuariosTitulo">Generando archivo</h5>
        <p class="mb-3 text-muted" id="downloadUsuariosTexto">
            Preparando la descarga de usuarios, por favor espera...
        </p>

        <div class="progress" style="height:10px; border-radius:999px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 role="progressbar"
                 style="width:100%">
            </div>
        </div>

        <small class="d-block mt-3 text-muted">
            No cierres esta ventana ni recargues la página.
        </small>
    </div>
</div>

<iframe id="downloadUsuariosFrame" name="downloadUsuariosFrame" style="display:none;"></iframe>

<!-- Iframe oculto para disparar la descarga sin salir de la página -->
<iframe id="downloadUsuariosFrame" name="downloadUsuariosFrame" style="display:none;"></iframe>

<!-- Modal para Descargar Data Locales -->
<div class="modal fade" id="modalDataLocales" tabindex="-1" role="dialog" aria-labelledby="modalDataLocalesLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form id="formFiltrosLocales">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalDataLocalesLabel">Descargar Data Locales</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">

                    <div class="form-group">
                        <label for="canal_locales" class="t2">Seleccionar Canal:</label>
                        <select class="form-control" id="canal_locales" name="canal[]" multiple size="6">
                            <option value="">Todos los Canales</option>
                        </select>
                        <small class="form-text text-muted">
                            Puedes seleccionar más de uno manteniendo presionada la tecla Ctrl.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="distrito_locales" class="t2">Seleccionar Distrito:</label>
                        <select class="form-control" id="distrito_locales" name="distrito[]" multiple size="6">
                            <option value="">Todos los Distritos</option>
                        </select>
                        <small class="form-text text-muted">
                            Puedes seleccionar más de uno manteniendo presionada la tecla Ctrl.
                        </small>
                    </div>

                    <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
                        <div class="form-group">
                            <label for="division_locales" class="t2">Seleccionar División:</label>
                            <select class="form-control" id="division_locales" name="division[]" multiple size="6">
                                <option value="">Todas las Divisiones</option>
                                <?php 
                                $query = "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
                                $result = $conn->query($query);

                                while ($row = $result->fetch_assoc()) {
                                    echo '<option value="' . (int)$row['id'] . '">'
                                        . htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8')
                                        . '</option>';
                                }
                                ?>
                            </select>
                            <small class="form-text text-muted">
                                Puedes seleccionar Red Bull, CCU u otras divisiones en una misma descarga.
                            </small>
                        </div>
                    <?php else: ?>
                        <input type="hidden" id="division_locales" name="division[]" value="<?php echo (int)$division; ?>">
                    <?php endif; ?>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        Cerrar
                    </button>

                    <button type="button" class="btn btn-success" onclick="descargarData(this,'csv')">
                        <i class="fa fa-download"></i> Descargar
                    </button>

                    <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
                        <button type="button" class="btn btn-outline-secondary" onclick="descargarData(this,'csv', true)">
                            Debug
                        </button>
                    <?php endif; ?>
                </div>

            </div>
        </form>
    </div>
</div>
  
  <!-- Modal para Descargar Data Locales Ultima Gestion -->
  <div class="modal fade" id="modalDataLocalesUltimaGestion" tabindex="-1" role="dialog" aria-labelledby="modalDataLocalesLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="modalDataLocalesLabel">Descargar Data Locales Ultima Gestion</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <form id="formFiltros">
                      <div class="form-group">
                          <label for="canal">Seleccionar Canal:</label>
                          <select class="form-control" id="canalug" name="canalug">
                              <option value="">Todos los Canales</option>
                          </select>
                      </div>
                      <div class="form-group">
                          <label for="distrito">Seleccionar Distrito:</label>
                          <select class="form-control" id="distritoug" name="distritoug">
                              <option value="">Todos los Distritos</option>
                          </select>
                      </div>
                      <!-- Filtro de Divisi車n: mostrar select solo si la divisi車n del usuario es "MC" -->
                      <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
                          <div class="form-group">
                              <label for="division">Seleccionar Divisi&oacute;n:</label>
                              <select class="form-control" id="division" name="division">
                                  <option value="">Todas las Divisiones</option>
                                  <?php 
                                  // Cargar todas las divisiones disponibles
                                  $query = "SELECT id, nombre FROM division_empresa where estado = 1 ORDER BY nombre ASC";
                                  $result = $conn->query($query);
                                  while ($row = $result->fetch_assoc()) {
                                      echo '<option value="'.$row['id'].'">'.$row['nombre'].'</option>';
                                  }
                                  ?>
                              </select>
                          </div>
                      <?php else: ?>
                          <!-- Si no es "MC", se env赤a el ID de la divisi車n del usuario en un input oculto -->
                          <input type="hidden" id="division" name="division" value="<?php echo htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php endif; ?>
                      <button type="button" class="btn btn-success" onclick="descargarDataUltimaGestion('csv')">Descargar</button>
                  </form>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              </div>
          </div>
      </div>
  </div>
  
  <!-- Modal para Descargar Data Locales Historico Gestion -->
  <div class="modal fade" id="modalDataLocalesHistoricoGestion" tabindex="-1" role="dialog" aria-labelledby="modalDataLocalesLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="modalDataLocalesLabel">Descargar Data Historica de Gestiones por Local</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <form id="formFiltros">
                    <div class="form-group">
                      <label for="canalhg">Seleccionar Canal:</label>
                      <select id="canalhg" name="canal" class="form-control">
                        <option value="">Todos los Canales</option>
                        <?php
                        // Esto siempre debe ejecutarse
                        $rs = $conn->query("SELECT id,nombre_canal FROM canal ORDER BY nombre_canal");
                        while($r = $rs->fetch_assoc()){
                          echo "<option value=\"{$r['id']}\">{$r['nombre_canal']}</option>";
                        }
                        ?>
                      </select>
                    </div>
                    
                    <div class="form-group">
                      <label for="distritohg">Seleccionar Distrito:</label>
                      <select id="distritohg" name="distrito" class="form-control">
                        <option value="">Todos los Distritos</option>
                        <?php
                        $rs = $conn->query("SELECT id,nombre_distrito FROM distrito ORDER BY nombre_distrito");
                        while($r = $rs->fetch_assoc()){
                          echo "<option value=\"{$r['id']}\">{$r['nombre_distrito']}</option>";
                        }
                        ?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label for="tipoGestion">Tipo de gestión:</label>
                      <select id="tipoGestion" name="tipoGestion" class="form-control">
                        <option value="">Todos</option>
                        <option value="1">Campaña programada</option>
                        <option value="3">Ruta programada</option>
                      </select>
                    </div>                    
                    <?php if (strtoupper(trim($division_nombre)) === 'MC'): ?>
                      <div class="form-group">
                        <label for="division">Seleccionar División:</label>
                        <select id="division" name="division" class="form-control">
                          <option value="">Todas las Divisiones</option>
                          <?php
                          $rs = $conn->query("SELECT id,nombre FROM division_empresa WHERE estado=1 ORDER BY nombre");
                          while($r = $rs->fetch_assoc()){
                            echo "<option value=\"{$r['id']}\">{$r['nombre']}</option>";
                          }
                          ?>
                        </select>
                      </div>
                    <?php else: ?>
                      <input type="hidden" name="division" value="<?php echo $division_id ?>">
                    <?php endif; ?>
                      <button type="button" class="btn btn-success" onclick="modalDataLocalesHistoricoGestion('csv')">Descargar</button>
                  </form>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              </div>
          </div>
      </div>
  </div>    

<!-- Modal para Descargar Data Campañas Programadas -->
<div class="modal fade" id="modalDataProgramados" tabindex="-1" role="dialog" aria-labelledby="modalDataProgramadosLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalDataProgramadosLabel">Descargar Data Campañas Programadas</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="formFiltrosProgramados">
          <div class="form-group">
            <label for="canal_programados">Seleccionar Canal:</label>
            <select class="form-control" id="canal_programados" name="canal">
              <option value="">Todos los Canales</option>
              <!-- Opciones dinámicas de canal -->
              <?php 
              $queryCanal = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
              $resultCanal = $conn->query($queryCanal);
              while($rowCanal = $resultCanal->fetch_assoc()){
                  echo '<option value="'.$rowCanal['id'].'">'.$rowCanal['nombre_canal'].'</option>';
              }
              ?>
            </select>
          </div>
          <div class="form-group">
            <label for="distrito_programados">Seleccionar Distrito:</label>
            <select class="form-control" id="distrito_programados" name="distrito">
              <option value="">Todos los Distritos</option>
              <!-- Opciones dinámicas de distrito -->
              <?php 
              $queryDistrito = "SELECT id, nombre_distrito FROM distrito ORDER BY nombre_distrito ASC";
              $resultDistrito = $conn->query($queryDistrito);
              while($rowDistrito = $resultDistrito->fetch_assoc()){
                  echo '<option value="'.$rowDistrito['id'].'">'.$rowDistrito['nombre_distrito'].'</option>';
              }
              ?>
            </select>
          </div>
          <!-- Filtro de División: se muestra el selector solo para usuarios de división "MC" -->
          <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
          <div class="form-group">
            <label for="division_programados">Seleccionar Divisi&oacute;n:</label>
            <select class="form-control" id="division_programados" name="division">
              <option value="">Todas las Divisiones</option>
              <?php 
              $queryDiv = "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
              $resultDiv = $conn->query($queryDiv);
              while($rowDiv = $resultDiv->fetch_assoc()){
                $idDiv = (int)$rowDiv['id'];
                $isSelected = ($idDiv === (int)$division) ? ' selected' : '';
                echo '<option value="'.$idDiv.'"'.$isSelected.'>'
                     . htmlspecialchars($rowDiv['nombre'], ENT_QUOTES, 'UTF-8')
                     . '</option>';
              }
              ?>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" id="division_programados" name="division" value="<?php echo htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8'); ?>">
          <?php endif; ?>
          
          <div class="form-group">
          <label for="ejecutor_programados">Seleccionar Ejecutor:</label>
          <select class="form-control" id="ejecutor_programados" name="id_usuario">
            <option value="">Todos los Ejecutores</option>
          </select>
        </div>
          <!-- Filtros por fecha -->
          <div class="form-group">
            <label for="fecha_inicio_programados">Fecha Inicio:</label>
            <input type="date" class="form-control" id="fecha_inicio_programados" name="fecha_inicio">
          </div>
          <div class="form-group">
            <label for="fecha_fin_programados">Fecha Fin:</label>
            <input type="date" class="form-control" id="fecha_fin_programados" name="fecha_fin">
          </div>
          <!-- Filtro adicional por estado -->
          <div class="form-group">
            <label for="estado_programados">Seleccionar Estado:</label>
            <select class="form-control" id="estado_programados" name="estado">
              <option value="">Todos los Estados</option>
              <option value="1">En Curso</option>
              <option value="2">Finalizado</option>
              <option value="3">Cancelado</option>
            </select>
          </div>
          <!-- Nuevo select para Tipo de Gestión -->
          <div class="form-group">
            <label for="tipo_gestion">Tipo de Gestión:</label>
            <select class="form-control" id="tipo_gestion" name="tipo_gestion" onchange="toggleDownloadButtons()">
              <option value="implementacion">Implementación</option>
              <option value="encuesta">Encuesta</option>
            </select>
          </div>
          <div class="text-right">
          <div class="form-group">                 
            <!-- Botones para Implementación -->
            <button type="button" class="btn btn-primary" id="btnDescargarImplementacionExcel" onclick="descargarDataProgramados('excel')">Descargar implementaciones</button>
            <button type="button" class="btn btn-primary" id="btnDescargarEncuesta" onclick="descargarEncuestaPivot('excel')" style="display:none;">Descargar encuesta</button>
          </div>            
          <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
          <div class="form-group">          
            <button type="button" class="btn btn-primary" id="btnDescargarImplementacionExcel" onclick="descargarDataAdicionales('excel')">Descargar adicionales</button>  
          </div>            
          <?php endif; ?>          
            </div>            
        </form>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal para Descargar Data Adicionales -->
<div class="modal fade" id="modalDataAdicionales" tabindex="-1" role="dialog" aria-labelledby="modalDataAdicionalesLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalDataAdicionalesLabel">Descargar Data Adicionales</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="formFiltrosAdicionales">
          <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
          <div class="form-group">
            <label for="division_adicionales">Seleccionar Divisi&oacute;n:</label>
            <select class="form-control" id="division_adicionales" name="id_division" required>
              <option value="">Seleccione una Divisi&oacute;n</option>
              <?php
              $queryDivAdicionales = "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
              $resultDivAdicionales = $conn->query($queryDivAdicionales);
              while ($rowDivAdicionales = $resultDivAdicionales->fetch_assoc()) {
                $idDivAdicionales = (int)$rowDivAdicionales['id'];
                $isSelectedAdicionales = ($idDivAdicionales === (int)$division) ? ' selected' : '';
                echo '<option value="' . $idDivAdicionales . '"' . $isSelectedAdicionales . '>'
                  . htmlspecialchars($rowDivAdicionales['nombre'], ENT_QUOTES, 'UTF-8')
                  . '</option>';
              }
              ?>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" id="division_adicionales" name="id_division" value="<?php echo htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8'); ?>">
          <?php endif; ?>

          <div class="form-group">
            <label for="fecha_inicio_adicionales">Fecha Inicio:</label>
            <input type="date" class="form-control" id="fecha_inicio_adicionales" name="fecha_inicio" required>
          </div>
          <div class="form-group">
            <label for="fecha_fin_adicionales">Fecha Fin:</label>
            <input type="date" class="form-control" id="fecha_fin_adicionales" name="fecha_fin" required>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-primary" onclick="descargarReporteAdicionales('excel')">
          <i class="fa fa-download"></i> Descargar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal para Descargar Data IPT -->
<div class="modal fade" id="modalDataIPT" tabindex="-1" role="dialog" aria-labelledby="modalDataIPTLabel" aria-hidden="true">
  <div class="modal-dialog" role="document" aria-modal="true">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalDataIPTLabel">Descargar Data Ruta IPT</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

<div class="modal-body">
  <form id="formFiltros2" novalidate>
    <?php if (strtoupper(trim($division_nombre)) === 'MC'): ?>
      <div class="form-group">
        <label for="division_ipt">Seleccionar División</label>
        <select class="form-control" id="division_ipt" name="id_division">
          <option value="">Todas las Divisiones</option>
          <?php
            $queryDiv = "SELECT id, nombre
                         FROM division_empresa
                         WHERE estado = 1
                         ORDER BY nombre ASC";
            $resultDiv = $conn->query($queryDiv);

            while ($rowDiv = $resultDiv->fetch_assoc()) {
              echo '<option value="' . (int)$rowDiv['id'] . '">'
                 . htmlspecialchars($rowDiv['nombre'], ENT_QUOTES, 'UTF-8')
                 . '</option>';
            }
          ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden"
             id="division_ipt"
             name="id_division"
             value="<?php echo isset($division_id) ? (int)$division_id : 0; ?>">
    <?php endif; ?>

    <div class="form-group">
      <label for="subdivision_ipt">Seleccionar Subdivisión</label>
      <select class="form-control" id="subdivision_ipt" name="id_subdivision" disabled>
        <option value="0">Todas</option>
        <option value="-1">Sin Subdivisión</option>
      </select>
    </div>

    <div class="form-group">
      <label for="ejecutor_ipt">Seleccionar Ejecutor</label>
      <select class="form-control" id="ejecutor_ipt" name="id_usuario">
        <option value="">Todos los Ejecutores</option>
      </select>
    </div>

    <div class="form-group">
      <label for="fecha_inicio_ipt">Fecha Inicio</label>
      <input type="date" class="form-control" id="fecha_inicio_ipt" name="fecha_inicio" required>
    </div>

    <div class="form-group">
      <label for="fecha_fin_ipt">Fecha Fin</label>
      <input type="date" class="form-control" id="fecha_fin_ipt" name="fecha_fin" required>
    </div>

    <div class="form-group">
      <label for="tipo_gestion2">Tipo de Gestión</label>
      <select class="form-control" id="tipo_gestion2" name="tipo_gestion">
        <option value="implementacion">Implementación</option>
        <option value="encuesta">Encuesta</option>
      </select>
    </div>

    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="separar" name="separar" value="1">
      <label class="form-check-label" for="separar">Una fila por respuesta (no agrupar)</label>
    </div>

    <div class="text-right mt-3">
      <button type="button" class="btn btn-primary" id="btnDescargarImplementacionExcelipt" style="display:none;">
        Descargar implementaciones
      </button>
      <button type="button" class="btn btn-primary" id="btnDescargarEncuestaipt" style="display:none;">
        Descargar encuesta
      </button>
    </div>

  </form>
</div>

      <div class="modal-footer">
        <small id="ayudaRango" class="text-muted mr-auto">Sugerencia: usa rangos acotados para acelerar la descarga.</small>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Overlay de descarga IPT -->
<div id="downloadIPTOverlay" class="form-submit-overlay" style="display:none;">
    <div class="form-submit-box">
        <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;">
            <span class="sr-only">Procesando...</span>
        </div>
        <h5 class="mb-2" id="downloadIPTTitulo">Generando archivo</h5>
        <p class="mb-3 text-muted" id="downloadIPTTexto">
            Preparando la descarga del reporte IPT, por favor espera...
        </p>

        <div class="progress" style="height:10px; border-radius:999px;">
            <div id="downloadIPTBar"
                 class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 role="progressbar"
                 style="width:0%">
            </div>
        </div>

        <small class="d-block mt-3 text-muted" id="downloadIPTPercent">
            0%
        </small>

        <small class="d-block mt-2 text-muted">
            No cierres esta ventana ni recargues la página.
        </small>
    </div>
</div>

  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="home.php" class="brand-link">
      <img src="../app/assets/imagenes/logo/logo-Visibility.png" alt="MC Logo" style="opacity: .8; width:40%;">
      <span class="brand-text font-weight-light">Visibility</span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
      <!-- Sidebar user panel (optional) -->
      <div class="user-panel mt-3 pb-3 mb-3 d-flex">
          <div class="image">
              <img src="<?php echo htmlspecialchars($fotoPerfil); ?>" class="img-circle elevation-2" style="width: 3.1rem; height: 50px;" alt="User Image">
          </div>
          <div class="info">
              <a href="#" class="d-block"><?php echo htmlspecialchars($nombre . ' ' . $apellido); ?></a>
              <a href="#" class="d-block"><?php echo htmlspecialchars($empresa); ?></a>
          </div>
      </div>

      <!-- SidebarSearch Form -->
      <div class="form-inline">
        <div class="input-group" data-widget="sidebar-search">
          <input class="form-control form-control-sidebar" type="search" placeholder="Buscar" aria-label="Search">
          <div class="input-group-append">
            <button class="btn btn-sidebar">
              <i class="fas fa-search fa-fw"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Sidebar Menu -->
      <nav class="mt-2">
        <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
          <li class="nav-item">
            <a href="ui_dashboard2.php" class="nav-link">
              <i class="nav-icon fas fa-tachometer-alt"></i>
              <p>Dashboard</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="ui_dashboard.php" class="nav-link">
              <i class="nav-icon fas fa-th"></i>
              <p>Inicio</p>
            </a>
          </li>

          <li class="nav-header">MODULOS</li>
          <?php if (puedeVerModulo($conn, 'usuarios') || puedeVerModulo($conn, 'usuarios.crear_editar') || puedeVerModulo($conn, 'usuarios.estadisticas') || puedeVerModulo($conn, 'usuarios.descargar')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon far fa-user"></i>
              <p>
                Usuarios
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'usuarios.crear_editar')): ?>
              <li class="nav-item">
                <a href="modulos/mod_create_user.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar usuarios</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'usuarios.estadisticas')): ?>
              <li class="nav-item">
                <a href="modulos/mod_statistic_user.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Estadisticas de usuarios</p>
                </a>
              </li>              
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'usuarios.descargar')): ?>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataUsuarios">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Descargar Usuarios</p>
                  </a>
              </li>              
              <?php endif; ?>
            </ul>
          </li>


          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'flota') || puedeVerModulo($conn, 'flota.crear_editar') || puedeVerModulo($conn, 'flota.descargar')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-car"></i>
              <p>
                Administrar flota
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'flota.crear_editar')): ?>
              <li class="nav-item">
                <a href="modulos/mod_vehiculos/mod_vehiculos.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar vehiculos</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'flota.descargar')): ?>
                <li class="nav-item">
                    <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDescargarVehiculos">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Descargar vehículos</p>
                    </a>
                </li>             
              <?php endif; ?>
            </ul>
          </li>

          
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'locales') || puedeVerModulo($conn, 'locales.crear_editar') || puedeVerModulo($conn, 'locales.descargar') || puedeVerModulo($conn, 'locales.ultima_gestion') || puedeVerModulo($conn, 'locales.historico_gestiones')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-building"></i>
              <p>
                Locales
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'locales.crear_editar')): ?>
              <li class="nav-item">
                <a href="modulos/mod_local.php" class="nav-link">
                  <i class="fas fa-plus-circle nav-icon"></i>
                  <p>Crear/Editar local</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'locales.descargar')): ?>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocales">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Descargar Locales</p>
                  </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'locales.ultima_gestion')): ?>              
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocalesUltimaGestion">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Estado Ultima Gestion</p>
                  </a>
              </li> 
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'locales.historico_gestiones')): ?>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocalesHistoricoGestion">
                      <i class="fas fa-database nav-icon"></i>
                      <p>Historico Gestiones</p>
                  </a>
              </li>
              <?php endif; ?>              
            </ul>
          </li>
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'reportes') || puedeVerModulo($conn, 'reportes.campanas') || puedeVerModulo($conn, 'reportes.adicionales') || puedeVerModulo($conn, 'reportes.ruta_planificada') || puedeVerModulo($conn, 'reportes.temporada_2024_2025') || puedeVerModulo($conn, 'reportes.temporada_2025_2026')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-cloud-download-alt"></i>
              <p>
                Reportes
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'reportes.campanas')): ?>
              <li class="nav-item">
                <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataProgramados">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Campañas</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'reportes.adicionales')): ?>
              <li class="nav-item">
                <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataAdicionales">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Adicionales</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'reportes.ruta_planificada')): ?>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataIPT">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Ruta Planificada</p>
                  </a>
              </li>
              <?php endif; ?>
            <?php if (in_array(strtoupper(trim($division_nombre)), ['SAVORY', 'MC'], true)): ?>              
                <?php if (puedeVerModulo($conn, 'reportes.temporada_2024_2025')): ?>
                <li class="nav-item">
                    <a href="https://visibility.cl/visibility2/portal/repositorio/SAVORY/SAVORY_TEMPORADA_2024-2025.xlsx" class="nav-link">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Temporada 2024-2025</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (puedeVerModulo($conn, 'reportes.temporada_2025_2026')): ?>
                <li class="nav-item">
                    <a href="https://visibility.cl/visibility2/portal/repositorio/SAVORY/SAVORY_TEMPORADA_2025-2026.xlsx" class="nav-link">
                        <i class="far fa-circle nav-icon"></i>
                        <p>Temporada 2025-2026</p>
                    </a>
                </li>
                <?php endif; ?>
            <?php endif; ?>              
            </ul>
          </li>
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'galeria') || puedeVerModulo($conn, 'galeria.imagenes') || puedeVerModulo($conn, 'galeria.campanas')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-images"></i>
              <p>
                Galeria
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'galeria.campanas')): ?>
              <li class="nav-item">
                 <a href="modulos/mod_galeria/mod_galeria_programadas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Campañas</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'galeria.imagenes')): ?>
              <li class="nav-item">
                   <a href="modulos/mod_galeria/mod_galeria_ipt.php" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Galeria de imagenes</p>
                  </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'mapa') || puedeVerModulo($conn, 'mapa.panel_rutas') || puedeVerModulo($conn, 'mapa.visor_rutas')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-route"></i>
              <p>
                Mapa
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'mapa.panel_rutas')): ?>
              <li class="nav-item">
                 <a href="modulos/mod_panel/mod_panel_detalle_locales_rutas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Panel Rutas</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'mapa.visor_rutas')): ?>
              <li class="nav-item">
                 <a href="modulos/mod_panel/mod_visualizar_ruta_excel.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Visor de rutas</p>
                </a>
              </li>              
              <?php endif; ?>
            </ul>
          </li>           
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'formularios') || puedeVerModulo($conn, 'formularios.crear_editar') || puedeVerModulo($conn, 'formularios.seguimiento_etapas') || puedeVerModulo($conn, 'formularios.generador_rutas')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>
                Formulario
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'formularios.crear_editar')): ?>
              <li class="nav-item">
                <a href="modulos/mod_formulario.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar Formulario</p>
                </a>
              </li>
              <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'formularios.seguimiento_etapas')): ?>
          <li class="nav-item">
            <a href="modulos/mod_seguimiento_etapas.php" class="nav-link">
              <i class="nav-icon fas fa-layer-group"></i>
              <p>Seguimiento por Etapas</p>
            </a>
          </li>
          <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'formularios.generador_rutas')): ?>
              <li class="nav-item">
                <a href="mod_planificador_fecha_propuesta.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Generador de rutas campañas</p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>
          <?php if (puedeVerModulo($conn, 'panel_control') || puedeVerModulo($conn, 'panel_control.merchan') || puedeVerModulo($conn, 'panel_control.campanas') || puedeVerModulo($conn, 'panel_control.trademarketing') || puedeVerModulo($conn, 'panel_control.recepcion_materiales')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-users-cog"></i>
              <p>
                Panel de control
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (puedeVerModulo($conn, 'panel_control.merchan')): ?>
              <li class="nav-item">
                <a href="modulos/mod_panel/mod_panel.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Merchan</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'panel_control.campanas')): ?>
              <li class="nav-item">
                <a href="modulos/mod_panel/mod_panel_campanas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Campaña/Actividades</p>
                </a>
              </li>
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'panel_control.trademarketing')): ?>
              <li class="nav-item">
                <a href="mod_formularios_prioridad_cliente.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Modulo Trademarketing</p>
                </a>
              </li>              
              <?php endif; ?>
              <?php if (puedeVerModulo($conn, 'panel_control.recepcion_materiales')): ?>
              <li class="nav-item">
                <a href="modulos/mod_recepcion_materiales.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Recepción de Materiales</p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
          <?php endif; ?>
          
          <?php if ($esUsuarioMC && (puedeVerModulo($conn, 'control_gestion') || puedeVerModulo($conn, 'dashboard_admin'))): ?>
          <li class="nav-header">CONTROL DE GESTION</li>
          <?php endif; ?>
          <?php if ($esUsuarioMC && puedeVerModulo($conn, 'control_gestion')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>
                Administrador
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_admin_rutas/mod_admin_locales_mapa.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Editor de rutas</p>
                </a>
              </li>
            </ul>
          </li>          
          <?php endif; ?>
          <?php if ($esUsuarioMC && puedeVerModulo($conn, 'dashboard_admin')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>
                Administrador de dashboard
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="UI_crear_dashboard.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/editar Dashboard</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="UI_subir_archivo.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Subir archivo - deprecated</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="UI_subir_archivo_nuevo.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Subir archivo nuevo</p>
                </a>
              </li>
            <li class="nav-item">
              <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDescargaRBTradicional">
                <i class="far fa-circle nav-icon"></i>
                <p>Descarga data RB Tradicional (formato rb)</p>
              </a>
            </li>               
            </ul>
          </li>
          <?php endif; ?>
          <?php if ($esUsuarioMC && puedeVerModulo($conn, 'desarrollo_testing')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-code"></i>
              <p>
                Desarrollo Testing
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_dashboard_carousel.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Nuevo menu para dashboard</p>
                </a>
              </li>      
              <li class="nav-item">
                <a href="ui_dashboard1_test.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>ENLACE DE PRUEBAS</p>
                </a>
              </li>              
            </ul>
          </li> 
          <?php endif; ?>
          <?php if ($esUsuarioMC && puedeVerModulo($conn, 'analisis_datos')): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-database"></i>
              <p>
                Analisis de datos
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_panel/mod_control_carga.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Control de ruta</p>
                </a>                
              <li class="nav-item">
                <a href="modulos/mod_rutas/mapa_rutas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Planificador de rutas</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="modulos/mod_rutas/mod_visualizar_rutas_planificadas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Visor de rutas planificadas</p>
                </a>
              </li>
              <li class="nav-item">
                  <a href="modulos/mod_etl/mod_etl_locales.php" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Procesar ETL</p>
                  </a>
              </li>                 
            </ul>
          </li>
              <li class="nav-item">
                <a href="ui_changelog.php" class="nav-link">
                  <i class="fas fa-sliders-h nav-icon"></i>
                <p>Changelog</p>
              </a>
              </li>
          <?php endif; ?>
          
          <?php if ($esUsuarioMC && puedeVerModulo($conn, 'elementos_portal')): ?>
          <li class="nav-header">ELEMENTOS PORTAL</li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>
                Administrador de clientes
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_elementos.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear empresa</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="modulos/mod_divisiones.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear division</p>
                </a>
              </li>
              <?php if (puedeVerModulo($conn, 'permisos.admin')): ?>
              <li class="nav-item">
                <a href="modulos/mod_permisos.php" class="nav-link">
                  <i class="fas fa-user-shield nav-icon"></i>
                  <p>Permisos de modulos</p>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </li>
            <li class="nav-item">
              <i class="nav-icon far "></i>
              <p class="text"></p>
          </li>  
            <li class="nav-item">
              <i class="nav-icon far "></i>
              <p class="text"></p>
          </li>  
            <li class="nav-item">
              <i class="nav-icon far "></i>
              <p class="text"></p>
          </li>  
            <li class="nav-item">
              <i class="nav-icon far "></i>
              <p class="text"></p>
          </li>            
          <?php endif; ?>
                    <li class="nav-item">
            <a href="modulos/mod_panel_encuesta/panel_encuesta.php" class="nav-link">
              <i class="nav-icon fas fa-th"></i>
              <p>Panel Encuesta</p>
            </a>
          </li>
          
        </ul>
      </nav>
      <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
  </aside>

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper iframe-mode" data-widget="iframe" data-loading-screen="750">
    <div class="nav navbar navbar-expand navbar-white navbar-light border-bottom p-0" style="display:none;">
      <div class="nav-item dropdown">
        <a class="nav-link bg-danger dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">Close</a>
        <div class="dropdown-menu mt-0">
          <a class="dropdown-item" href="#" data-widget="iframe-close" data-type="all">Close All</a>
          <a class="dropdown-item" href="#" data-widget="iframe-close" data-type="all-other">Close All Other</a>
        </div>
      </div>
      <a class="nav-link bg-light" href="#" data-widget="iframe-scrollleft"><i class="fas fa-angle-double-left"></i></a>
      <ul class="navbar-nav overflow-hidden" role="tablist"></ul>
      <a class="nav-link bg-light" href="#" data-widget="iframe-scrollright"><i class="fas fa-angle-double-right"></i></a>
      <a class="nav-link bg-light" href="#" data-widget="iframe-fullscreen"><i class="fas fa-expand"></i></a>
    </div>
    <div class="tab-content">
      <div class="tab-empty" style="display: none;">
        <h2 class="display-4">No tab selected!</h2>
      </div>
      <div class="tab-loading" style="display: none;">
        <div>
          <h2 class="display-4">Tab is loading <i class="fa fa-sync fa-spin"></i></h2>
        </div>
      </div>
      <div class="tab-pane fade active show" id="panel-index2-html" role="tabpanel" aria-labelledby="tab-index2-html">
        <iframe src="./ui_dashboard2.php"></iframe>
      </div>
    </div>
  </div>

<!-- MODAL DESCARGA VEHÍCULOS -->
<div class="modal fade" id="modalDescargarVehiculos" tabindex="-1" role="dialog" aria-labelledby="modalDescargarVehiculosLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content vehicle-download-modal">

            <div class="modal-header vehicle-download-header">
                <div class="vehicle-download-title-wrap">
                    <div class="vehicle-download-icon">
                        <i class="fas fa-car-side"></i>
                    </div>

                    <div>
                        <h5 class="modal-title" id="modalDescargarVehiculosLabel">
                            Descargar vehículos
                        </h5>
                        <p>Exporta el estado actual o el historial completo de la flota.</p>
                    </div>
                </div>

                <button type="button" class="close vehicle-download-close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body vehicle-download-body">

                <a href="/visibility2/portal/modulos/mod_vehiculos/exportar_vehiculos.php?tipo=actual"
                   class="vehicle-download-option">
                    <div class="vehicle-download-option-icon blue">
                        <i class="fas fa-file-download"></i>
                    </div>

                    <div class="vehicle-download-option-text">
                        <strong>Estado actual de vehículos</strong>
                        <span>Descarga el último registro vigente de cada vehículo, incluyendo empresa, división, merchan actual y estado.</span>
                    </div>

                    <i class="fas fa-chevron-right vehicle-download-arrow"></i>
                </a>

                <a href="/visibility2/portal/modulos/mod_vehiculos/exportar_vehiculos.php?tipo=historico"
                   class="vehicle-download-option">
                    <div class="vehicle-download-option-icon purple">
                        <i class="fas fa-history"></i>
                    </div>

                    <div class="vehicle-download-option-text">
                        <strong>Histórico de asignaciones</strong>
                        <span>Descarga todos los movimientos históricos de asignación por vehículo.</span>
                    </div>

                    <i class="fas fa-chevron-right vehicle-download-arrow"></i>
                </a>

                <div class="vehicle-download-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Los archivos se descargan en formato Excel (.xlsx).
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Modal descarga RB Tradicional -->
<div class="modal fade" id="modalDescargaRBTradicional" tabindex="-1" role="dialog" aria-labelledby="modalDescargaRBTradicionalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form id="formDescargaRBTradicional"
          action="/visibility2/portal/modulos/rb_tradicional/descargar_rb_tradicional_csv.php"
          method="post"
          target="iframeDescargaRBTradicional">
      <input type="hidden" name="download_token" id="rb_download_token">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalDescargaRBTradicionalLabel">Descargar data RB Tradicional</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body">
          <div class="alert alert-info mb-3">
            Selecciona el rango de fechas. El máximo permitido es de 3 meses por descarga.
          </div>

          <div class="form-group">
            <label for="rb_fecha_desde">Fecha desde</label>
            <input type="date" class="form-control" id="rb_fecha_desde" name="fecha_desde" required>
          </div>

          <div class="form-group">
            <label for="rb_fecha_hasta">Fecha hasta</label>
            <input type="date" class="form-control" id="rb_fecha_hasta" name="fecha_hasta" required>
          </div>

          <small class="text-muted d-block">
            El archivo se descargará en CSV y puede tardar un poco según el volumen.
          </small>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
          <button type="button" class="btn btn-primary" id="btnDescargarRBTradicional">
            Descargar CSV
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Iframe oculto para no salir de la página -->
<iframe name="iframeDescargaRBTradicional" id="iframeDescargaRBTradicional" style="display:none;"></iframe>

<!-- Overlay de descarga -->
<div id="rbDownloadOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:99999;">
  <div style="width:420px; max-width:92%; background:#fff; border-radius:14px; padding:22px; box-shadow:0 10px 30px rgba(0,0,0,.18); position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);">
    <div style="font-size:18px; font-weight:700; color:#1f3c88; margin-bottom:10px;">
      Generando archivo
    </div>
    <div style="font-size:14px; color:#555; margin-bottom:14px;">
      Estamos preparando la descarga del CSV. No cierres esta ventana.
    </div>

    <div class="progress" style="height:18px; border-radius:999px; overflow:hidden;">
      <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
           role="progressbar"
           style="width:100%">
        Descargando...
      </div>
    </div>
  </div>
</div>
  
  
<!-- Modal / barra (puedes estilizar a tu gusto) -->
<div id="dlModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div style="width:420px; margin:10% auto; background:#fff; border-radius:12px; padding:16px; box-shadow:0 8px 32px rgba(0,0,0,.2);">
    <h3 style="margin:0 0 8px;">Descargando reporte…</h3>
    <div style="height:10px; background:#eee; border-radius:999px; overflow:hidden;">
      <div id="dlBar" style="height:100%; width:0%; background:#4C8BF5; transition:width .15s;"></div>
    </div>
    <div id="dlText" style="margin-top:8px; font-size:12px; color:#555;">0%</div>
  </div>
</div>  
  <!-- /.content-wrapper -->
  <!--footer class="main-footer">
    <strong>Copyright &copy; 2014-2024 
      <a href="https://mentecreativa.cl/">Visibility</a>.
    </strong> Todos los derechos reservados.
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 3
    </div>
  </footer-->
  <!-- Control Sidebar -->
  <!--aside class="control-sidebar control-sidebar-dark">
    <!-- Contenido del sidebar de control -->
  </aside-->
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolver conflicto en jQuery UI tooltip con Bootstrap tooltip -->
<script>
  $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- overlayScrollbars -->
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>
<script>
  window.ES_USUARIO_MC = <?php echo json_encode($esUsuarioMC); ?>;
  window.ID_MC = "1";
</script>
<script src="js/home.js" defer></script>


<script>
(function () {
  var btnDescargar = document.getElementById('btnDescargarRBTradicional');
  var form         = document.getElementById('formDescargaRBTradicional');
  var inputDesde   = document.getElementById('rb_fecha_desde');
  var inputHasta   = document.getElementById('rb_fecha_hasta');
  var tokenInput   = document.getElementById('rb_download_token');
  var overlay      = document.getElementById('rbDownloadOverlay');
  var pollTimer    = null;

  function getCookie(name) {
    var value = '; ' + document.cookie;
    var parts = value.split('; ' + name + '=');
    if (parts.length === 2) return parts.pop().split(';').shift();
    return '';
  }

  function clearDownloadCookie() {
    document.cookie = 'fileDownloadToken=; Max-Age=0; path=/';
  }

  function showOverlay() {
    overlay.style.display = 'block';
  }

  function hideOverlay() {
    overlay.style.display = 'none';
  }

  function validarRango(desde, hasta) {
    if (!desde || !hasta) {
      return 'Debes seleccionar ambas fechas.';
    }

    var d1 = new Date(desde + 'T00:00:00');
    var d2 = new Date(hasta + 'T00:00:00');

    if (isNaN(d1.getTime()) || isNaN(d2.getTime())) {
      return 'Las fechas ingresadas no son válidas.';
    }

    if (d2 < d1) {
      return 'La fecha hasta no puede ser menor que la fecha desde.';
    }

    var max = new Date(d1);
    max.setMonth(max.getMonth() + 3);

    if (d2 >= max) {
      return 'El rango máximo permitido es de 3 meses.';
    }

    return '';
  }

  function iniciarPolling(token) {
    if (pollTimer) {
      clearInterval(pollTimer);
    }

    pollTimer = setInterval(function () {
      var cookieToken = getCookie('fileDownloadToken');
      if (cookieToken === token) {
        clearInterval(pollTimer);
        pollTimer = null;
        clearDownloadCookie();
        hideOverlay();
        $('#modalDescargaRBTradicional').modal('hide');
      }
    }, 1000);
  }

  btnDescargar.addEventListener('click', function () {
    var desde = inputDesde.value;
    var hasta = inputHasta.value;

    var error = validarRango(desde, hasta);
    if (error) {
      alert(error);
      return;
    }

    var token = 'rb_' + Date.now();
    tokenInput.value = token;

    showOverlay();
    iniciarPolling(token);
    form.submit();
  });

  window.addEventListener('message', function (event) {
    if (!event.data || event.data.type !== 'rbDownloadError') {
      return;
    }

    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }

    hideOverlay();
    clearDownloadCookie();
    alert(event.data.message || 'No fue posible generar el archivo.');
  });
})();
</script>

<script>
function descargarDataUsuarios() {
    var division = $('#division').val() || '';
    var perfil   = $('#perfil').val() || '';
    var formato  = $('#formato').val() || 'xlsx';

    var $overlay = $('#downloadUsuariosOverlay');
    var $iframe  = $('#downloadUsuariosFrame');

    if (formato === 'xlsx') {
        $('#downloadUsuariosTitulo').text('Generando archivo Excel');
        $('#downloadUsuariosTexto').text('Preparando la descarga en formato Excel, por favor espera...');
    } else {
        $('#downloadUsuariosTitulo').text('Generando archivo CSV');
        $('#downloadUsuariosTexto').text('Preparando la descarga en formato CSV, por favor espera...');
    }

    $overlay.css('display', 'flex');

    var params = new URLSearchParams();
    params.append('formato', formato);
    params.append('division', division);
    params.append('perfil', perfil);
    params.append('_t', Date.now()); // evita cache

    // IMPORTANTE:
    // usa aquí la ruta REAL de tu archivo PHP
    var url = 'modulos/descargar_data_usuarios.php?' + params.toString();

    $iframe.attr('src', url);

    setTimeout(function () {
        $overlay.fadeOut(250);
    }, 3500);
}
</script>

<script>
$(document).ready(function () {
    let xhrEjecutoresIPT = null;
    let ultimoFiltroEjecutoresIPT = '';

    function resetSelectIPT(sel, placeholder) {
        if (!sel || !sel.length) return;
        sel.html(`<option value="0">${placeholder}</option>`);
    }

function cargarSubdivisionesIPT(idDivision, restaurar = false) {
    const $subdivision = $('#subdivision_ipt');
    if (!$subdivision.length) return;

    console.log('cargarSubdivisionesIPT -> idDivision:', idDivision);

    $subdivision.prop('disabled', true);
    resetSelectIPT($subdivision, 'Cargando...');

    $.get('/visibility2/portal/modulos/mod_cargar/cargar_subdivisiones.php', {
        id_division: idDivision || 0
    }, function (resp) {
        console.log('respuesta subdivisiones:', resp);

        let data = resp;

        if (typeof resp === 'string') {
            try {
                data = JSON.parse(resp);
            } catch (e) {
                data = { ok: false, subdivisiones: [] };
            }
        }

        $subdivision.empty();
        $subdivision.append('<option value="0">Todas</option>');
        $subdivision.append('<option value="-1">Sin Subdivisión</option>');

        if (data.ok && Array.isArray(data.subdivisiones)) {
            data.subdivisiones.forEach(function (item) {
                $subdivision.append(
                    $('<option>', {
                        value: item.id,
                        text: item.nombre
                    })
                );
            });
        }

        if (restaurar) {
            const saved = $('#subdivision_ipt').data('selected') || '0';
            if ($subdivision.find(`option[value="${saved}"]`).length) {
                $subdivision.val(saved);
            }
        }

        $subdivision.prop('disabled', false);
    }).fail(function (xhr) {
        console.error('Error cargando subdivisiones IPT:', xhr.status, xhr.responseText);
        resetSelectIPT($subdivision, 'Error al cargar');
        $subdivision.prop('disabled', false);
    });
}

    function cargarEjecutoresIPT() {
        const division    = $('#division_ipt').val() || '';
        const subdivision = $('#subdivision_ipt').val() || '';

        const firma = [division, subdivision].join('|');

        if (firma === ultimoFiltroEjecutoresIPT) {
            return;
        }
        ultimoFiltroEjecutoresIPT = firma;

        if (xhrEjecutoresIPT && xhrEjecutoresIPT.readyState !== 4) {
            xhrEjecutoresIPT.abort();
        }

        $('#ejecutor_ipt').html('<option value="">Cargando ejecutores...</option>');

        xhrEjecutoresIPT = $.ajax({
            url: 'modulos/cargar_filtros.php',
            type: 'GET',
            data: {
                filtro: 'ejecutor',
                division: division,
                subdivision: subdivision
            },
            cache: false,
            success: function (data) {
                $('#ejecutor_ipt').html(data);
            },
            error: function (xhr, status) {
                if (status !== 'abort') {
                    $('#ejecutor_ipt').html('<option value="">No fue posible cargar ejecutores</option>');
                }
            }
        });
    }

$('#modalDataIPT').on('shown.bs.modal', function () {
    ultimoFiltroEjecutoresIPT = '';

    const division = $('#division_ipt').val() || 0;
    console.log('division_ipt al abrir modal:', division);

    if (parseInt(division, 10) > 0) {
        cargarSubdivisionesIPT(division, false);

        setTimeout(function () {
            cargarEjecutoresIPT();
        }, 200);
    } else {
        $('#subdivision_ipt').html('<option value="0">Todas</option><option value="-1">Sin Subdivisión</option>');
        $('#subdivision_ipt').prop('disabled', false);
        cargarEjecutoresIPT();
    }
});

$('#division_ipt').on('change', function () {
    const division = $(this).val() || 0;

    ultimoFiltroEjecutoresIPT = '';
    $('#ejecutor_ipt').html('<option value="">Todos los Ejecutores</option>');

    if (parseInt(division, 10) > 0) {
        cargarSubdivisionesIPT(division, false);

        setTimeout(function () {
            cargarEjecutoresIPT();
        }, 200);
    } else {
        $('#subdivision_ipt').html('<option value="0">Todas</option><option value="-1">Sin Subdivisión</option>');
        $('#subdivision_ipt').prop('disabled', false);
        cargarEjecutoresIPT();
    }
});

    $('#subdivision_ipt').on('change', function () {
        ultimoFiltroEjecutoresIPT = '';
        cargarEjecutoresIPT();
    });
});
</script>

<script>

    $(document).ready(function() {
        // Al abrir el modal, cargar opciones de Canal y Distrito
        $('#modalDataLocalesUltimaGestion').on('show.bs.modal', function () {
            $.get('modulos/cargar_filtros.php', { filtro: 'canalug' }, function(data) {
                $('#canalug').html(data);
            });
            $.get('modulos/cargar_filtros.php', { filtro: 'distritoug' }, function(data) {
                $('#distritoug').html(data);
            });
        });
    });
    
    function descargarDataUltimaGestion(formato) {
        var canal = $('#canalug').val();
        var distrito = $('#distritoug').val();
        var division = $('#division').val(); // Valor del select o input oculto
        window.location.href = 'modulos/descargar_data_locales_ultimaGestion.php?formato=' + formato + '&canal=' + canal + '&distrito=' + distrito + '&division=' + division;
    }  
    
$(document).ready(function () {

    $('#modalDataLocales').on('show.bs.modal', function () {
        const $modal = $(this);

        $.get('modulos/cargar_filtros.php', { filtro: 'canal' }, function (data) {
            $modal.find('#canal_locales').html(data);
        });

        $.get('modulos/cargar_filtros.php', { filtro: 'distrito' }, function (data) {
            $modal.find('#distrito_locales').html(data);
        });
    });

});    
            
        function descargarData(btn, formato, debug = false) {
          const $form = $(btn).closest('form');                 // <-- form del botón
          const params = $form.serializeArray();
          params.push({ name: 'formato', value: formato });
          if (debug) params.push({ name: 'debug', value: '1' });
        
          const usp = new URLSearchParams();
          params.forEach(p => usp.append(p.name, p.value || ''));
          const url = `modulos/descargar_data_locales.php?${usp.toString()}`;
        
          console.log('URL descarga:', url);
          if (debug) window.open(url, '_blank'); else window.location.href = url;
        }    
    
    function modalDataLocalesHistoricoGestion(formato) {
      var $modal    = $('#modalDataLocalesHistoricoGestion');
      var canal     = $modal.find('#canal').val();
      var distrito  = $modal.find('#distrito').val();
      var division  = $modal.find('#division').val();
      var tipoGest  = $modal.find('#tipoGestion').val();
    
      var url = 'modulos/descargar_data_locales_historicoGestion.php'
        + '?formato='    + encodeURIComponent(formato)
        + '&canal='      + encodeURIComponent(canal)
        + '&distrito='   + encodeURIComponent(distrito)
        + '&division='   + encodeURIComponent(division)
        + '&tipoGestion='+ encodeURIComponent(tipoGest);
    
      window.open(url, '_blank');
    }  
        
    
function descargarDataProgramados(formato) {
        var canal     = document.getElementById('canal_programados').value;
        var distrito  = document.getElementById('distrito_programados').value;
        var division  = document.getElementById('division_programados').value;
        var fecha_inicio = document.getElementById('fecha_inicio_programados').value;
        var fecha_fin    = document.getElementById('fecha_fin_programados').value;
        var estado    = document.getElementById('estado_programados').value;
        const ejecutor = $('#ejecutor_programados').val();

    // Construir la URL y codificar los parámetros
    var url = 'modulos/descargar_data_programada.php?formato=' + encodeURIComponent(formato)
                  + "&id_canal=" + encodeURIComponent(canal)
                  + "&id_distrito=" + encodeURIComponent(distrito)
                  + "&id_division=" + encodeURIComponent(division)
                  + "&fecha_inicio=" + encodeURIComponent(fecha_inicio)
                  + "&fecha_fin=" + encodeURIComponent(fecha_fin)
                  + "&estado=" + encodeURIComponent(estado);
                 if (ejecutor) {
                  url += '&id_usuario=' + encodeURIComponent(ejecutor);
                    }
    
    // Abrir la URL en una nueva pestaña
   window.location.href = url;
} 

function descargarDataAdicionales(formato) {
        var canal     = document.getElementById('canal_programados').value;
        var distrito  = document.getElementById('distrito_programados').value;
        var division  = document.getElementById('division_programados').value;
        var fecha_inicio = document.getElementById('fecha_inicio_programados').value;
        var fecha_fin    = document.getElementById('fecha_fin_programados').value;
        var estado    = document.getElementById('estado_programados').value;
        const ejecutor = $('#ejecutor_programados').val();

    // Construir la URL y codificar los parámetros
    var url = 'modulos/descargar_data_programada_adicionales.php?formato=' + encodeURIComponent(formato)
                  + "&id_canal=" + encodeURIComponent(canal)
                  + "&id_distrito=" + encodeURIComponent(distrito)
                  + "&id_division=" + encodeURIComponent(division)
                  + "&fecha_inicio=" + encodeURIComponent(fecha_inicio)
                  + "&fecha_fin=" + encodeURIComponent(fecha_fin)
                  + "&estado=" + encodeURIComponent(estado);
                 if (ejecutor) {
                  url += '&id_usuario=' + encodeURIComponent(ejecutor);
                    }
    
    // Abrir la URL en una nueva pestaña
   window.location.href = url;
} 
    
function descargarReporteAdicionales(formato) {
    var division = document.getElementById('division_adicionales').value;
    var fecha_inicio = document.getElementById('fecha_inicio_adicionales').value;
    var fecha_fin = document.getElementById('fecha_fin_adicionales').value;

    if (!division) {
        alert('Debe seleccionar una división.');
        return;
    }
    if (!fecha_inicio || !fecha_fin) {
        alert('Debe seleccionar fecha inicio y fecha fin.');
        return;
    }
    if (fecha_fin < fecha_inicio) {
        alert('La fecha fin no puede ser menor que la fecha inicio.');
        return;
    }

    var url = 'modulos/descargar_data_programada_adicionales.php?formato=' + encodeURIComponent(formato)
        + '&id_division=' + encodeURIComponent(division)
        + '&fecha_inicio=' + encodeURIComponent(fecha_inicio)
        + '&fecha_fin=' + encodeURIComponent(fecha_fin);

    window.location.href = url;
}

function descargarEncuestaPivot(formato) {
        var canal     = document.getElementById('canal_programados').value;
        var distrito  = document.getElementById('distrito_programados').value;
        var division  = document.getElementById('division_programados').value;
        var fecha_inicio = document.getElementById('fecha_inicio_programados').value;
        var fecha_fin    = document.getElementById('fecha_fin_programados').value;
        var estado    = document.getElementById('estado_programados').value;
        const ejecutor = $('#ejecutor_programados').val();
        var url = "modulos/descargar_data_programada_E.php?formato=" + encodeURIComponent(formato)
                  + "&id_canal=" + encodeURIComponent(canal)
                  + "&id_distrito=" + encodeURIComponent(distrito)
                  + "&id_division=" + encodeURIComponent(division)
                  + "&fecha_inicio=" + encodeURIComponent(fecha_inicio)
                  + "&fecha_fin=" + encodeURIComponent(fecha_fin)
                  + "&estado=" + encodeURIComponent(estado);
                   if (ejecutor) {
                    url += '&id_usuario=' + encodeURIComponent(ejecutor);
                    }
                  
        //window.location.href = url;
    window.location.href = url;        
    }
    
function descargarDataIPTMC(formato) {
    var canal2 = document.getElementById('canal_ipt').value;
    var distrito2 = document.getElementById('distrito_ipt').value;
    var division2 = document.getElementById('division_ipt').value;
    console.log("Valor de división:", division2);
    var fecha_inicio2 = document.getElementById('fecha_inicio_ipt').value;
    var fecha_fin2 = document.getElementById('fecha_fin_ipt').value;
    const ejecutor     = $('#ejecutor_ipt').val();
    
    var url = "modulos/descarga_data_ruta.php?formato=" + encodeURIComponent(formato)
              + "&id_canal=" + encodeURIComponent(canal2)
              + "&id_distrito=" + encodeURIComponent(distrito2)
              + "&id_division=" + encodeURIComponent(division2)
              + "&fecha_inicio=" + encodeURIComponent(fecha_inicio2)
              + "&fecha_fin=" + encodeURIComponent(fecha_fin2);
    if (ejecutor) url += "&id_usuario=" + encodeURIComponent(ejecutor);
    window.location.href = url;     
}    
    
function descargarDataIPT(formato) {
    var canal2 = document.getElementById('canal_ipt').value;
    var distrito2 = document.getElementById('distrito_ipt').value;
    var division2 = document.getElementById('division_ipt').value;
    console.log("Valor de división:", division2);
    var fecha_inicio2 = document.getElementById('fecha_inicio_ipt').value;
    var fecha_fin2 = document.getElementById('fecha_fin_ipt').value;
    const ejecutor = $('#ejecutor_ipt').val();
    
    var url = "modulos/descargar_data_ipt.php?formato=" + encodeURIComponent(formato)
              + "&id_canal=" + encodeURIComponent(canal2)
              + "&id_distrito=" + encodeURIComponent(distrito2)
              + "&id_division=" + encodeURIComponent(division2)
              + "&fecha_inicio=" + encodeURIComponent(fecha_inicio2)
              + "&fecha_fin=" + encodeURIComponent(fecha_fin2);
            if (ejecutor) url += "&id_usuario=" + encodeURIComponent(ejecutor);
    window.location.href = url;     
}

function showDlModal(show) {
  document.getElementById('dlModal').style.display = show ? 'block' : 'none';
}
function setDlProgress(percent, text) {
  const p = Math.max(0, Math.min(100, percent|0));
  document.getElementById('dlBar').style.width = p + '%';
  document.getElementById('dlText').textContent = text ?? (p + '%');
}

   
    function toggleDownloadButtons() {
    var tipo = document.getElementById("tipo_gestion").value;
    if (tipo === "implementacion") {
        document.getElementById("btnDescargarImplementacionExcel").style.display = "";
        document.getElementById("btnDescargarEncuesta").style.display = "none";
        
    } else if (tipo === "encuesta") {
        document.getElementById("btnDescargarImplementacionExcel").style.display = "none";
        document.getElementById("btnDescargarEncuesta").style.display = "";
    }
}

$(document).ready(function() {
  // al cargar
  toggleDownloadButtons2();

  // cuando abres el modal (usa shown para que el DOM del modal ya esté en pantalla)
  $('#modalDataIPT').on('shown.bs.modal', function () {
    toggleDownloadButtons2();
  });

  // cuando cambie el tipo
  $('#tipo_gestion2').on('change', toggleDownloadButtons2);

  // cuando cambie la división (por si el usuario la cambia manualmente)
  $('#division_ipt').on('change', toggleDownloadButtons2);
});

</script>

<script>
$(document).ready(function() {
    $("#formPerfil").submit(function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        $.ajax({
            url: "modulos/actualizar_perfil.php",
            type: "POST",
            data: formData,
            contentType: false,
            processData: false,
            beforeSend: function() {
                $("#mensajePerfil").html('<div class="alert alert-info">Actualizando perfil...</div>');
            },
            success: function(response) {
                if (response.success) {
                    $("#mensajePerfil").html('<div class="alert alert-success">Perfil actualizado correctamente.</div>');
                    if (response.nuevaImagen) {
                        var nuevaImagen = response.nuevaImagen.replace(/\\/g, "/");
                        $("#previewImagen").attr("src", nuevaImagen);
                    }
                } else {
                    $("#mensajePerfil").html('<div class="alert alert-danger">' + response.error + '</div>');
                }
            },
            error: function() {
                $("#mensajePerfil").html('<div class="alert alert-danger">Error al actualizar el perfil.</div>');
            }
        });
    });

    $("#fotoPerfil").change(function(event) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $("#previewImagen").attr("src", e.target.result);
        }
        reader.readAsDataURL(event.target.files[0]);
    });
});
</script>
<script>
function ajustarIframeHome() {
    const header = document.querySelector('.main-header');
    const headerH = header ? Math.ceil(header.getBoundingClientRect().height) : 57;

    const altoDisponible = window.innerHeight - headerH;

    const elementos = document.querySelectorAll(
        '.content-wrapper.iframe-mode, ' +
        '.content-wrapper.iframe-mode .tab-content, ' +
        '.content-wrapper.iframe-mode .tab-pane, ' +
        '.content-wrapper.iframe-mode .tab-pane iframe, ' +
        '.content-wrapper.iframe-mode .tab-empty, ' +
        '.content-wrapper.iframe-mode .tab-loading'
    );

    elementos.forEach(function (el) {
        el.style.setProperty('height', altoDisponible + 'px', 'important');
        el.style.setProperty('min-height', altoDisponible + 'px', 'important');
        el.style.setProperty('max-height', altoDisponible + 'px', 'important');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    ajustarIframeHome();

    setTimeout(ajustarIframeHome, 300);
    setTimeout(ajustarIframeHome, 800);
    setTimeout(ajustarIframeHome, 1500);
});

window.addEventListener('load', ajustarIframeHome);
window.addEventListener('resize', ajustarIframeHome);

if (window.jQuery) {
    $(document).on('collapsed.lte.pushmenu shown.lte.pushmenu', function () {
        setTimeout(ajustarIframeHome, 250);
    });
}
</script>
</body>
</html>

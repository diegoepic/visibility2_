<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);


require_once __DIR__ . '/_session_guard.php';
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
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="modalDataUsuariosLabel">Descargar Data Usuarios</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <form id="formFiltros">
                      <!-- Filtro de Division: mostrar select solo si la division del usuario es "MC" -->
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
                          <input type="hidden" id="division" name="division" value="<?php echo htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php endif; ?>
                          <div class="form-group">
                              <label for="perfil">Seleccionar perfil:</label>
                              <select class="form-control" id="perfil" name="perfil">
                                  <option value="">Todas los perfiles</option>
                                  <?php 
                                  $query = "SELECT id, upper(nombre) as nombre FROM perfil ORDER BY nombre ASC";
                                  $result = $conn->query($query);
                                  while ($row = $result->fetch_assoc()) {
                                      echo '<option value="'.$row['id'].'">'.$row['nombre'].'</option>';
                                  }
                                  ?>
                              </select>
                          </div>                        
                      <button type="button" class="btn btn-success" onclick="descargarDataUsuarios('csv')">Descargar</button>
                  </form>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              </div>
          </div>
      </div>
  </div>

  <!-- Modal para Descargar Data Locales -->
  <div class="modal fade" id="modalDataLocales" tabindex="-1" role="dialog" aria-labelledby="modalDataLocalesLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
          <div class="modal-content">
              <div class="modal-header">
                  <h5 class="modal-title" id="modalDataLocalesLabel">Descargar Data Locales</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
              <div class="modal-body">
                  <form id="formFiltrosLocales">
                      <div class="form-group">
                          <label for="canal">Seleccionar Canal:</label>
                          <select class="form-control" id="canal" name="canal">
                              <option value="">Todos los Canales</option>
                          </select>
                      </div>
                      <div class="form-group">
                          <label for="distrito">Seleccionar Distrito:</label>
                          <select class="form-control" id="distrito" name="distrito">
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
                                      echo '<option value="'.$row['id'].'"'.$sel.'>'.$row['nombre'].'</option>';
                                  }
                                  ?>
                              </select>
                          </div>
                      <?php else: ?>
                          <!-- Si no es "MC", se env赤a el ID de la divisi車n del usuario en un input oculto -->
                          <input type="hidden" id="division_locales" name="division" value="<?php echo (int)$division; ?>">
                      <?php endif; ?>
                      <button type="button" class="btn btn-primary" onclick="descargarData('excel')" hidden>Descargar en Excel</button>
                      <button type="button" class="btn btn-success" onclick="descargarData(this,'csv')">Descargar</button>
                      <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>                      
                      <button type="button" class="btn btn-secondary" onclick="descargarData(this,'csv', true)">Debug</button>
                      <?php else: ?>  
                      <?php endif; ?>                      
                  </form>
              </div>
              <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
              </div>
          </div>
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

  
<!-- Modal para Descargar Data programados -->
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
        <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
          <div class="form-group">
            <label for="division_ipt">Seleccionar División</label>
            <select class="form-control" id="division_ipt" name="id_division">
              <option value="">Todas las Divisiones</option>
              <?php 
                $queryDiv = "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
                $resultDiv = $conn->query($queryDiv);
                while($rowDiv = $resultDiv->fetch_assoc()){
                  echo '<option value="'.$rowDiv['id'].'">'.
                       htmlspecialchars($rowDiv['nombre'], ENT_QUOTES, 'UTF-8').
                       '</option>';
                }
              ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" id="division_ipt" name="id_division"
                 value="<?php echo isset($division_id) ? htmlspecialchars($division_id, ENT_QUOTES, 'UTF-8') : ''; ?>">
        <?php endif; ?>

        <div class="form-group">
          <label for="subdivision_ipt">Seleccionar Subdivisión</label>
          <select class="form-control" id="subdivision_ipt" name="id_subdivision">
            <option value="">Todas las Subdivisiones</option>
            <?php 
              // Ajusta el nombre de tabla/campos a tu esquema real
              $qSub = "SELECT id, nombre FROM subdivision WHERE 1 = 1 ORDER BY nombre ASC";
              $rSub = $conn->query($qSub);
              while($row = $rSub->fetch_assoc()){
                echo '<option value="'.$row['id'].'">'.htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8').'</option>';
              }
            ?>
          </select>
        </div>

          <div class="form-group">
            <label for="ejecutor_ipt">Seleccionar Ejecutor</label>
            <select class="form-control" id="ejecutor_ipt" name="id_usuario">
              <option value="">Todos los Ejecutores</option>
              <!-- Opcional: se llenará vía JS -->
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
            <div class="text-right">
              <!-- Grupo MC -->
              <button type="button" class="btn btn-primary" id="btnDescargarImplementacionExceliptMC" style="display:none;">
                Descargar implementaciones (MC)
              </button>
              <button type="button" class="btn btn-primary" id="btnDescargarEncuestaiptMC" style="display:none;">
                Descargar encuesta (MC)
              </button>
            
              <!-- Grupo otras divisiones -->
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


<script>
(function () {
  // Límite duro (no se permite pasar)
  const MAX_DAYS = 90;       // ~3 meses
  // Umbral de advertencia (solo aviso, sí se permite)
  const SOFT_WARN_DAYS = 31; // ~1 mes

  const $fi = document.getElementById('fecha_inicio_ipt');
  const $ff = document.getElementById('fecha_fin_ipt');
  const $ayuda = document.getElementById('ayudaRango');

  const downloadButtons = [
    'btnDescargarImplementacionExceliptMC',
    'btnDescargarEncuestaiptMC',
    'btnDescargarImplementacionExcelipt',
    'btnDescargarEncuestaipt'
  ].map(id => document.getElementById(id)).filter(Boolean);

  const toYMD = d => d.toISOString().slice(0,10);
  const parseYMD = s => {
    const [y,m,d] = (s || '').split('-').map(n => parseInt(n,10));
    if (!y || !m || !d) return null;
    return new Date(y, m-1, d);
  };
  const addDays = (date, days) => {
    const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    d.setDate(d.getDate() + days);
    return d;
  };
  const diffDays = (a, b) => {
    const d1 = new Date(a.getFullYear(), a.getMonth(), a.getDate());
    const d2 = new Date(b.getFullYear(), b.getMonth(), b.getDate());
    return Math.round((d2 - d1) / 86400000);
  };

  // helper de mensaje (usa clases Bootstrap)
  function setHelpMsg(msg, level='muted') {
    if (!$ayuda) return;
    $ayuda.textContent = msg;
    $ayuda.classList.remove('text-muted','text-warning','text-danger');
    $ayuda.classList.add(
      level === 'danger' ? 'text-danger' :
      level === 'warning' ? 'text-warning' : 'text-muted'
    );
  }

  function refreshConstraints() {
    const fi = parseYMD($fi.value);
    const ff = parseYMD($ff.value);

    // Limita FIN a FI + MAX_DAYS
    if (fi) {
      const maxFF = addDays(fi, MAX_DAYS);
      $ff.min = toYMD(fi);
      $ff.max = toYMD(maxFF);
      if (ff && ff > maxFF) $ff.value = toYMD(maxFF);
    } else {
      $ff.removeAttribute('min');
      $ff.removeAttribute('max');
    }

    // Limita INICIO a FF - MAX_DAYS
    if (ff) {
      const minFI = addDays(ff, -MAX_DAYS);
      $fi.max = toYMD(ff);
      $fi.min = toYMD(minFI);
      if (fi && fi < minFI) $fi.value = toYMD(minFI);
    } else {
      $fi.removeAttribute('min');
      $fi.removeAttribute('max');
    }

    // Mensajería
    const fi2 = parseYMD($fi.value);
    const ff2 = parseYMD($ff.value);
        if (fi2 && ff2) {
          const d = diffDays(fi2, ff2);
        
          // Fecha fin antes que fecha inicio
          if (d < 0) {
            setHelpMsg('La fecha de fin debe ser igual o posterior a la fecha de inicio.', 'danger');
          }
        
          // Bloqueo duro
          else if (d > MAX_DAYS) {
            setHelpMsg(`El rango máximo permitido es de ${MAX_DAYS} días. Seleccionaste ${d} días.`, 'danger');
          }
        
          // Advertencia suave
          else if (d > SOFT_WARN_DAYS) {
            setHelpMsg(`Aviso: el rango es de ${d} días (supera ${SOFT_WARN_DAYS}). La descarga puede tardar más.`, 'warning');
          }
        
          // OK
          else {
            setHelpMsg('Sugerencia: usar rangos acotados puede acelerar la descarga.', 'muted');
          }
        } 
        else {
          setHelpMsg('Selecciona fechas de inicio y término para comenzar.', 'muted');
        }

  // Validación final ANTES de descargar:
  // - Solo bloquea si falta fecha, si fin < inicio o si d > MAX_DAYS.
    function validateRangeOrWarn() {
      const fi = parseYMD($fi.value);
      const ff = parseYMD($ff.value);
    
      if (!fi || !ff) {
        alert('Debes seleccionar Fecha Inicio y Fecha Fin.');
        return false;
      }
    
      if (ff < fi) {
        alert('La Fecha Fin no puede ser anterior a la Fecha Inicio.');
        return false;
      }
    
      const d = diffDays(fi, ff);
    
      if (d > MAX_DAYS) {
        alert(`El rango máximo permitido es de ${MAX_DAYS} días. Seleccionaste ${d} días.`);
        return false;
      }
    
      // ⚠️ Si d > SOFT_WARN_DAYS: no bloquea, solo se muestra en UI
      return true;
    }

  if ($fi) $fi.addEventListener('change', refreshConstraints);
  if ($ff) $ff.addEventListener('change', refreshConstraints);

  downloadButtons.forEach(btn => {
    btn.addEventListener('click', function (e) {
      if (!validateRangeOrWarn()) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      // Aquí sigue tu flujo normal de descarga (submit/fetch)
      // document.getElementById('formFiltros2').submit();
    });
  });

  document.addEventListener('DOMContentLoaded', refreshConstraints);
  // Si usas Bootstrap modal con jQuery, revalida al abrir:
  if (window.jQuery) $('#modalDataIPT').on('shown.bs.modal', refreshConstraints);
})();
</script>




  <!-- Main Sidebar Container -->
  <aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="home.php" class="brand-link">
      <img src="../app/assets/imagenes/logo/logo-Visibility.png" alt="MC Logo" style="opacity: .8; width:50%;">
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
            <a href="ui_dashboard1.php" class="nav-link">
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
          <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon far fa-user"></i>
              <p>
                Usuarios
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_create_user.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar usuarios</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="modulos/mod_statistic_user.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Estadisticas de usuarios</p>
                </a>
              </li>              
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataUsuarios">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Descargar Usuarios</p>
                  </a>
              </li>              
            </ul>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-stream"></i>
              <p>
                Locales
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>
              <li class="nav-item">
                <a href="modulos/mod_local.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar local</p>
                </a>
              </li>
              <?php endif; ?>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocales">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Descargar Locales</p>
                  </a>
              </li>
              <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>              
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocalesUltimaGestion">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Estado Ultima Gestion</p>
                  </a>
              </li> 
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataLocalesHistoricoGestion">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Historico Gestiones</p>
                  </a>
              </li>               
              <?php endif; ?>              
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-cloud-download-alt"></i>
              <p>
                Reportes
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataProgramados">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Campañas</p>
                </a>
              </li>
              <li class="nav-item">
                  <a href="#" class="nav-link" data-toggle="modal" data-target="#modalDataIPT">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Ruta Planificada</p>
                  </a>
              </li>
          <?php if (strtoupper(trim($division_nombre)) == 'SAVORY' or 'MC'): ?>              
              <li class="nav-item">
                  <a href="https://visibility.cl/visibility2/portal/repositorio/SAVORY/SAVORY_TEMPORADA_2024-2025.xlsx" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Temporada 2024-2025</p>
                  </a>
              </li>
              <li class="nav-item">
                  <a href="https://visibility.cl/visibility2/portal/repositorio/SAVORY/SAVORY_TEMPORADA_2023-2024.xlsx" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Temporada 2023-2024</p>
                  </a>
              </li>              
          <?php else: ?>  
          <?php endif; ?>                
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-images"></i>
              <p>
                Galeria
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                 <a href="modulos/mod_galeria/mod_galeria_programadas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Campañas</p>
                </a>
              </li>
              <li class="nav-item">
                   <a href="modulos/mod_galeria/mod_galeria_ipt.php" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Rutas</p>
                  </a>
              </li>
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-route"></i>
              <p>
                Mapa Rutas
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                 <a href="modulos/mod_panel/mod_panel_detalle_locales_rutas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Panel Rutas</p>
                </a>
              </li>
              <li class="nav-item">
                   <a href="modulos/mod_panel/mod_panel_detalle_locales_campanas.php" class="nav-link">
                      <i class="far fa-circle nav-icon"></i>
                      <p>Ruta campañas</p>
                  </a>
              </li>
            </ul>
          </li>           
          <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>
                Formulario
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_formulario.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear/Editar Formulario</p>
                </a>
              </li>
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-users-cog"></i>
              <p>
                Panel Coordinador
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mod_panel/mod_panel.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Panel de Control</p>
                </a>
              </li>
            </ul>
          </li>

          
          <li class="nav-header">ELEMENTOS PORTAL</li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>
                EMPRESAS
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
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-edit"></i>
              <p>
                DASHBOARD
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="UI_crear_dashboard.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Crear Dashboard</p>
                </a>
              </li>
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-upload"></i>
              <p>
                SUBIR ARCHIVOS
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="UI_subir_archivo.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Subir archivo</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="UI_subir_archivo_nuevo.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Subir archivo nuevo</p>
                </a>
              </li>              
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-map"></i>
              <p>
                GENERAR RUTA
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="modulos/mapa_rutas.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>Generar Ruta</p>
                </a>
              </li>
            </ul>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link">
              <i class="nav-icon fas fa-map"></i>
              <p>
                PRUEBAS
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview">
              <li class="nav-item">
                <a href="ui_dashboard1_test.php" class="nav-link">
                  <i class="far fa-circle nav-icon"></i>
                  <p>ENLACE DE PRUEBAS</p>
                </a>
              </li>
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
    <div class="nav navbar navbar-expand navbar-white navbar-light border-bottom p-0">
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
      <div class="tab-empty" style="height: 574.8px; display: none;">
        <h2 class="display-4">No tab selected!</h2>
      </div>
      <div class="tab-loading" style="height: 574.8px; display: none;">
        <div>
          <h2 class="display-4">Tab is loading <i class="fa fa-sync fa-spin"></i></h2>
        </div>
      </div>
      <div class="tab-pane fade active show" id="panel-index2-html" role="tabpanel" aria-labelledby="tab-index2-html">
        <iframe src="./ui_dashboard1.php" style="height: 300.6px;"></iframe>
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
  <footer class="main-footer">
    <strong>Copyright &copy; 2014-2024 
      <a href="https://mentecreativa.cl/">Visibility</a>.
    </strong> Todos los derechos reservados.
    <div class="float-right d-none d-sm-inline-block">
      <b>Version</b> 3
    </div>
  </footer>
  <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Contenido del sidebar de control -->
  </aside>
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
  window.ES_USUARIO_MC = <?php echo json_encode(strtoupper(trim($division_nombre)) == 'MC'); ?>;
  window.ID_MC = "1";
</script>
<script src="js/home.js" defer></script>

<script>
    $(document).ready(function() {
        // Al abrir el modal, cargar opciones de Canal y Distrito
        $('#modalDataLocales').on('show.bs.modal', function () {
            $.get('modulos/cargar_filtros.php', { filtro: 'canal' }, function(data) {
                $('#canal').html(data);
            });
            $.get('modulos/cargar_filtros.php', { filtro: 'distrito' }, function(data) {
                $('#distrito').html(data);
            });
        });
        $('#modalDataIPT').on('show.bs.modal', function () {
          const division  = $('#division_ipt').val();
          // ya cargas canal y distrito; ahora cargamos ejecutores:
          $.get('modulos/cargar_filtros.php',
            { filtro: 'ejecutor', division },
            data => $('#ejecutor_ipt').html(data)
          );
        });
        
        $('#division_ipt').on('change', function(){
          const division = $(this).val();
          $.get('modulos/cargar_filtros.php',
            { filtro: 'ejecutor', division },
            data => $('#ejecutor_ipt').html(data)
          );
        });
        
        $('#modalDataProgramados').on('show.bs.modal', function () {
          const division = $('#division_programados').val();
    
          $.get('modulos/cargar_filtros.php', { filtro: 'ejecutor', division }, function(html){
            $('#ejecutor_programados').html(html);
          });
        });
    
        $('#division_programados').on('change', function(){
          const division = $(this).val();
          $.get('modulos/cargar_filtros.php', { filtro: 'ejecutor', division }, function(html){
            $('#ejecutor_programados').html(html);
          });
        });

});
        
        

              
        
        

    
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
    
    
     function descargarDataUsuarios(formato) {
        var division = $('#division').val(); // Valor del select o input oculto
        var perfil = $('#perfil').val();        
        window.location.href = 'modulos/descargar_data_usuarios.php?formato=' + formato + '&division=' + division + '&perfil=' + perfil;
    }   
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
    function descargarDataUltimaGestion(formato) {
        var canal = $('#canalug').val();
        var distrito = $('#distritoug').val();
        var division = $('#division').val(); // Valor del select o input oculto
        window.location.href = 'modulos/descargar_data_locales_ultimaGestion.php?formato=' + formato + '&canal=' + canal + '&distrito=' + distrito + '&division=' + division;
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



function descargarDataIPTencuesta(formato) {
    var canal3 = document.getElementById('canal_ipt').value;
    var distrito3 = document.getElementById('distrito_ipt').value;
    var division3 = document.getElementById('division_ipt').value;
    console.log("Valor de división:", division3);
    var fecha_inicio3 = document.getElementById('fecha_inicio_ipt').value;
    var fecha_fin3 = document.getElementById('fecha_fin_ipt').value;
    const ejecutor     = $('#ejecutor_ipt').val();
    
    var url = "modulos/descargar_data_ipt_E.php?formato=" + encodeURIComponent(formato)
              + "&id_canal=" + encodeURIComponent(canal3)
              + "&id_distrito=" + encodeURIComponent(distrito3)
              + "&id_division=" + encodeURIComponent(division3)
              + "&fecha_inicio=" + encodeURIComponent(fecha_inicio3)
              + "&fecha_fin=" + encodeURIComponent(fecha_fin3);
     if (ejecutor) url += "&id_usuario=" + encodeURIComponent(ejecutor);
    window.location.href = url;     
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
    var timeout;
    function resetTimeout() {
        clearTimeout(timeout);
        timeout = setTimeout(function(){
            window.location.href = "index.php?session_expired=1";
        }, 1800000); // 10 minutos de inactividad
    }
    window.onload = resetTimeout;
    document.onmousemove = resetTimeout;
    document.onkeypress = resetTimeout;
</script>
</body>
</html>

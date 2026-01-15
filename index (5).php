<?php
// ------- Endurecer cookies de sesión ANTES de session_start -------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// ------- Encabezados de seguridad y no-cache en página de login -------
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ------- CSRF token para formularios -------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

// ------- Utilidad de escape -------
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ------- Mensajes de estado -------
$error_login    = $_SESSION['error_login']    ?? '';
$error_forgot   = $_SESSION['error_forgot']   ?? '';
$success_forgot = $_SESSION['success_forgot'] ?? '';
$notice_login   = $_SESSION['notice_login']   ?? ''; // opcional

unset($_SESSION['error_login'], $_SESSION['error_forgot'], $_SESSION['success_forgot'], $_SESSION['notice_login']);

// (Opcional) Si ya hay sesión válida, podrías redirigir al home del portal:
// if (!empty($_SESSION['usuario_id'])) {
//     header('Location: visibility2/portal/home.php');
//     exit;
// }
?>
<!DOCTYPE html>
<html lang="es" class="no-js">
<head>
    <meta charset="UTF-8">
    <title>Visibility</title>
    <!-- start: META -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimum-scale=1.0, maximum-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta content="" name="description" />
    <meta content="" name="author" />

    <link rel="stylesheet" href="visibility2/portal/assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="visibility2/portal/assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="visibility2/portal/assets/fonts/style.css">
    <link rel="stylesheet" href="visibility2/portal/assets/css/main.css">
    <link rel="stylesheet" href="visibility2/portal/assets/css/main-responsive.css">
    <link rel="stylesheet" href="visibility2/portal/assets/plugins/iCheck/skins/all.css">
    <link rel="stylesheet" href="visibility2/portal/assets/plugins/bootstrap-colorpalette/css/bootstrap-colorpalette.css">
    <link rel="stylesheet" href="visibility2/portal/assets/plugins/perfect-scrollbar/src/perfect-scrollbar.css">
    <link rel="stylesheet" href="visibility2/portal/assets/css/theme_light.css" type="text/css" id="skin_color">
    <link rel="stylesheet" href="visibility2/portal/assets/css/print.css" type="text/css" media="print" />
    <link rel="icon" type="image/png" href="visibility2/portal/images/logo/Logo_MENTE CREATIVA-02.png">    
    <style>
      #bg-video {
        position: fixed;
        top: 0; left: 0;
        min-width: 100%; min-height: 100%;
        width: auto; height: auto;
        z-index: -1;
        background-size: cover;
      }
    </style>
    
    
    <script>
  if (window.top && window.top !== window.self) {
    window.top.location = window.location.href;
  }
</script>
</head>

<body class="login example2">
  <!-- Video de fondo -->
  <video autoplay muted loop id="bg-video">
    <source src="visibility2/portal/video/background-v3.mp4" type="video/mp4">
    Tu navegador no soporta HTML5 video.
  </video>

  <div class="main-login col-sm-4 col-sm-offset-4">
    <center>
      <img src="visibility2/app/assets/imagenes/logo/logo-Visibility.png" alt="Logo de Visibility" style="width:50%;">
    </center>

    <div class="box-login">
      <h3>Inicia sesi&oacute;n en tu cuenta</h3>
      <p>Por favor, ingrese su nombre y contrase&ntilde;a para iniciar sesi&oacute;n.</p>

      <form class="form-login" action="visibility2/portal/modulos/procesar_login.php" method="POST" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

        <!-- Mensaje informativo opcional (p. ej., cuenta bloqueada mostrada en modo aviso) -->
        <?php if ($notice_login !== ""): ?>
          <div class="errorHandler alert alert-warning">
            <i class="fa fa-info-circle"></i> <?php echo e($notice_login); ?>
          </div>
        <?php endif; ?>

        <!-- Mensaje de Error de Inicio de Sesión -->
        <?php if ($error_login !== ""): ?>
          <div class="errorHandler alert alert-danger">
            <i class="fa fa-remove-sign"></i> <?php echo e($error_login); ?>
          </div>
        <?php endif; ?>

        <!-- Mensaje de Error de Restablecimiento de Contraseña -->
        <?php if ($error_forgot !== ""): ?>
          <div class="errorHandler alert alert-danger">
            <i class="fa fa-remove-sign"></i> <?php echo e($error_forgot); ?>
          </div>
        <?php endif; ?>

        <!-- Mensaje de Éxito de Restablecimiento de Contraseña -->
        <?php if ($success_forgot !== ""): ?>
          <div class="errorHandler alert alert-success">
            <i class="fa fa-check-sign"></i> <?php echo e($success_forgot); ?>
          </div>
        <?php endif; ?>

        <!-- Mensajes de Error o Éxito de otras acciones -->
        <?php
        if (isset($_SESSION['error_solicitud'])) {
            echo '<div class="errorHandler alert alert-danger">' . e($_SESSION['error_solicitud']) . '</div>';
            unset($_SESSION['error_solicitud']);
        }
        if (isset($_SESSION['success_solicitud'])) {
            echo '<div class="errorHandler alert alert-success">' . e($_SESSION['success_solicitud']) . '</div>';
            unset($_SESSION['success_solicitud']);
        }
        ?>

        <?php 
        if (isset($_GET['session_expired']) && $_GET['session_expired'] == 1) {
            echo '<div class="alert alert-warning">Tu sesi&oacute;n ha expirado por inactividad. Por favor, inicia sesi&oacute;n de nuevo.</div>';
        }
        ?>

        <fieldset>
          <div class="form-group">
            <span class="input-icon">
              <input type="text"
                     class="form-control"
                     name="usuario"
                     placeholder="Usuario o Email"
                     required
                     autocomplete="username"
                     spellcheck="false">
              <i class="fa fa-user"></i>
            </span>
          </div>

          <div class="form-group form-actions">
            <span class="input-icon">
              <input type="password"
                     class="form-control password"
                     name="clave"
                     placeholder="Contrase&ntilde;a"
                     required
                     autocomplete="current-password">
              <i class="fa fa-lock"></i>
              <a class="forgot" href="#forgot">Olvid&eacute; mi contrase&ntilde;a</a>
            </span>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-bricky pull-right">
              Iniciar <i class="fa fa-arrow-circle-right"></i>
            </button>
          </div>

          <div class="new-account">
            &iquest;A&uacute;n no tienes una cuenta?
            <a href="#" class="register">Crea una cuentaaa</a>
          </div>
        </fieldset>
      </form>
    </div>

    <!-- Formulario de Restablecimiento de Contraseña -->
    <div class="box-forgot">
      <h3>Olvidaste tu contrase&ntilde;a?</h3>
      <p>Ingrese su direcci&oacute;n de correo electr&oacute;nico a continuaci&oacute;n para restablecer su contrase&ntilde;a.</p>
      <form class="form-forgot" action="visibility2/portal/modulos/restablecer_contrasena.php" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

        <!-- Mensaje de Error de Restablecimiento de Contraseña -->
        <?php if ($error_forgot !== ""): ?>
          <div class="errorHandler alert alert-danger">
            <i class="fa fa-remove-sign"></i> <?php echo e($error_forgot); ?>
          </div>
        <?php endif; ?>

        <!-- Mensaje de Éxito de Restablecimiento de Contraseña -->
        <?php if ($success_forgot !== ""): ?>
          <div class="errorHandler alert alert-success">
            <i class="fa fa-check-sign"></i> <?php echo e($success_forgot); ?>
          </div>
        <?php endif; ?>

        <fieldset>
          <div class="form-group">
            <span class="input-icon">
              <input type="email"
                     class="form-control"
                     name="email"
                     placeholder="Correo Electr&oacute;nico"
                     required
                     autocomplete="email"
                     inputmode="email">
              <i class="fa fa-envelope"></i>
            </span>
          </div>
          <div class="form-actions">
            <a class="btn btn-light-grey go-back" href="#">
              <i class="fa fa-circle-arrow-left"></i> Atr&aacute;s
            </a>
            <button type="submit" class="btn btn-bricky pull-right">
              Enviar <i class="fa fa-arrow-circle-right"></i>
            </button>
          </div>
        </fieldset>
      </form>	
    </div>

    <!-- Formulario de Solicitud de Creación de Cuenta -->
    <div class="box-register">
      <h3>Solicitud de Creaci&oacute;n de Cuenta</h3>
      <p>Ingrese sus datos para solicitar la creaci&oacute;n de su cuenta:</p>
      <form class="form-register" action="visibility2/portal/modulos/enviar_solicitud.php" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

        <fieldset>
          <div class="form-group">
            <input type="text" class="form-control" name="nombre" placeholder="Nombre" required>
          </div>
          <div class="form-group">
            <input type="text" class="form-control" name="apellido" placeholder="Apellido" required>
          </div>
          <div class="form-group">
            <input type="text" class="form-control" name="empresa" placeholder="Empresa a la que pertenece" required>
          </div>
          <div class="form-group">
            <span class="input-icon">
              <input type="email" class="form-control" name="email" placeholder="Correo Electr&oacute;nico" required autocomplete="email">
              <i class="fa fa-envelope"></i>
            </span>
          </div>
          <div class="form-actions">
            <a class="btn btn-light-grey go-back" href="#">
              <i class="fa fa-circle-arrow-left"></i> Atr&aacute;s
            </a>
            <button type="submit" class="btn btn-bricky pull-right">
              Enviar Solicitud <i class="fa fa-arrow-circle-right"></i>
            </button>
          </div>
        </fieldset>
      </form>
    </div>

    <div class="copyright">
      2024 &copy; Visibility por Mentecreativa.
    </div>
  </div>

  <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
  <script src="visibility2/portal/assets/plugins/jquery-ui/jquery-ui-1.10.2.custom.min.js"></script>
  <script src="visibility2/portal/assets/plugins/bootstrap/js/bootstrap.min.js"></script>
  <script src="visibility2/portal/assets/plugins/bootstrap-hover-dropdown/bootstrap-hover-dropdown.min.js"></script>
  <script src="visibility2/portal/assets/plugins/blockUI/jquery.blockUI.js"></script>
  <script src="visibility2/portal/assets/plugins/iCheck/jquery.icheck.min.js"></script>
  <script src="visibility2/portal/assets/plugins/perfect-scrollbar/src/jquery.mousewheel.js"></script>
  <script src="visibility2/portal/assets/plugins/perfect-scrollbar/src/perfect-scrollbar.js"></script>
  <script src="visibility2/portal/assets/plugins/less/less-1.5.0.min.js"></script>
  <script src="visibility2/portal/assets/plugins/jquery-cookie/jquery.cookie.js"></script>
  <script src="visibility2/portal/assets/plugins/bootstrap-colorpalette/js/bootstrap-colorpalette.js"></script>
  <script src="visibility2/portal/assets/js/main.js"></script>
  <script src="visibility2/portal/assets/plugins/jquery-validation/dist/jquery.validate.min.js"></script>
  <script src="visibility2/portal/assets/js/login.js"></script>

  <script>
    jQuery(document).ready(function() {
      Main.init();
      Login.init();
      // Ocultar alertas luego de 5s
      setTimeout(function(){ $('.alert').fadeOut('slow'); }, 5000);
    });
  </script>
</body>
</html>

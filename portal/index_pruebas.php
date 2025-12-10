<?php
session_start();

/* CSRF token para el formulario de login */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error_login   = "";
$error_forgot  = "";
$success_forgot = "";
$notice_login  = "";

/* Redirección legacy si acceden a /visibility2/portal directamente */
if (strpos($_SERVER['REQUEST_URI'], '/visibility2/portal') === 0) {
    header('Location: https://visibility.cl/', true, 301);
    exit;
}

/* Mensajes de backend */
if (isset($_SESSION['error_login'])) {
    $error_login = $_SESSION['error_login'];
    unset($_SESSION['error_login']);
}
if (isset($_SESSION['error_forgot'])) {
    $error_forgot = $_SESSION['error_forgot'];
    unset($_SESSION['error_forgot']);
}
if (isset($_SESSION['success_forgot'])) {
    $success_forgot = $_SESSION['success_forgot'];
    unset($_SESSION['success_forgot']);
}
if (isset($_SESSION['notice_login'])) {
    $notice_login = $_SESSION['notice_login'];
    unset($_SESSION['notice_login']);
}
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

    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/fonts/style.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <link rel="stylesheet" href="assets/plugins/iCheck/skins/all.css">
    <link rel="stylesheet" href="assets/plugins/bootstrap-colorpalette/css/bootstrap-colorpalette.css">
    <link rel="stylesheet" href="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.css">
    <link rel="stylesheet" href="assets/css/theme_light.css" type="text/css" id="skin_color">
    <link rel="stylesheet" href="assets/css/print.css" type="text/css" media="print" />
    <link rel="icon" type="image/png" href="images/logo/Logo_MENTE CREATIVA-02.png">    

    <style>
      #bg-video {
        position: fixed;
        top: 0;
        left: 0;
        min-width: 100%;
        min-height: 100%;
        width: auto;
        height: auto;
        z-index: -1; /* detrás del contenido */
        background-size: cover;
      }
    </style>
</head>

<body class="login example2">
<!-- Video de fondo -->
<video autoplay muted loop id="bg-video">
  <source src="video/background-v3.mp4" type="video/mp4">
  Tu navegador no soporta HTML5 video.
</video>

    <div class="main-login col-sm-4 col-sm-offset-4">
        <center>
            <img src="../app/assets/imagenes/logo/logo-Visibility.png" alt="Logo de Visibility" style="width:50%;">
        </center>

        <div class="box-login">
            <h3>Inicia sesi&oacute;n en tu cuenta</h3>
            <p>Por favor, ingrese su nombre y contrase&ntilde;a para iniciar sesi&oacute;n.</p>

            <!-- Mensajes del backend -->
            <?php if ($notice_login !== ""): ?>
              <div class="errorHandler alert alert-warning">
                <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars($notice_login); ?>
              </div>
            <?php endif; ?>

            <?php if ($error_login !== ""): ?>
              <div class="errorHandler alert alert-danger">
                <i class="fa fa-remove-sign"></i> <?php echo htmlspecialchars($error_login); ?>
              </div>
            <?php endif; ?>

            <?php if ($error_forgot !== ""): ?>
              <div class="errorHandler alert alert-danger">
                <i class="fa fa-remove-sign"></i> <?php echo htmlspecialchars($error_forgot); ?>
              </div>
            <?php endif; ?>

            <?php if ($success_forgot !== ""): ?>
              <div class="errorHandler alert alert-success">
                <i class="fa fa-check-sign"></i> <?php echo htmlspecialchars($success_forgot); ?>
              </div>
            <?php endif; ?>

            <?php
            if (isset($_SESSION['error_solicitud'])) {
                echo '<div class="errorHandler alert alert-danger">' . htmlspecialchars($_SESSION['error_solicitud']) . '</div>';
                unset($_SESSION['error_solicitud']);
            }
            if (isset($_SESSION['success_solicitud'])) {
                echo '<div class="errorHandler alert alert-success">' . htmlspecialchars($_SESSION['success_solicitud']) . '</div>';
                unset($_SESSION['success_solicitud']);
            }
            if (isset($_GET['session_expired']) && $_GET['session_expired'] == 1) {
                echo '<div class="alert alert-warning">Tu sesi&oacute;n ha expirado o fue cerrada. Por favor, inicia sesi&oacute;n nuevamente.</div>';
            }
            ?>

            <!-- Formulario Login -->
            <form class="form-login" action="modulos/procesar_login.php" method="POST" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <fieldset>
                    <div class="form-group">
                        <span class="input-icon">
                            <input type="text" class="form-control" name="usuario" placeholder="Usuario o email" required autocomplete="username">
                            <i class="fa fa-user"></i>
                        </span>
                    </div>
                    <div class="form-group form-actions">
                        <span class="input-icon">
                            <input type="password" class="form-control password" name="clave" placeholder="Contrase&ntilde;a" required autocomplete="current-password">
                            <i class="fa fa-lock"></i>
                            <a class="forgot" href="#forgot" onclick="document.querySelector('.box-login').style.display='none';document.querySelector('.box-forgot').style.display='block';">
                                Olvid&eacute; mi contrase&ntilde;a
                            </a>
                        </span>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-bricky pull-right">
                            Iniciar <i class="fa fa-arrow-circle-right"></i>
                        </button>
                    </div>
                    <div class="new-account">
                        &iquest;A&uacute;n no tienes una cuenta?
                        <a href="#" class="register" onclick="document.querySelector('.box-login').style.display='none';document.querySelector('.box-register').style.display='block';">
                            Crea una cuenta
                        </a>
                    </div>
                </fieldset>
            </form>
        </div>

        <!-- Formulario de Restablecimiento de Contraseña -->
        <div class="box-forgot" style="display:none;">
            <h3>Olvidaste tu contrase&ntilde;a?</h3>
            <p>Ingrese su direcci&oacute;n de correo electr&oacute;nico a continuaci&oacute;n para restablecer su contrase&ntilde;a.</p>
            <form class="form-forgot" action="modulos/restablecer_contrasena.php" method="POST" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($error_forgot != ""): ?>
                    <div class="errorHandler alert alert-danger">
                        <i class="fa fa-remove-sign"></i> <?php echo htmlspecialchars($error_forgot); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success_forgot != ""): ?>
                    <div class="errorHandler alert alert-success">
                        <i class="fa fa-check-sign"></i> <?php echo htmlspecialchars($success_forgot); ?>
                    </div>
                <?php endif; ?>

                <fieldset>
                    <div class="form-group">
                        <span class="input-icon">
                            <input type="email" class="form-control" name="email" placeholder="Correo Electr&oacute;nico" required autocomplete="email">
                            <i class="fa fa-envelope"></i>
                        </span>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-light-grey go-back" href="#" onclick="document.querySelector('.box-forgot').style.display='none';document.querySelector('.box-login').style.display='block';">
                            <i class="fa fa-circle-arrow-left"></i> Atr&aacute;s
                        </a>
                        <button type="submit" class="btn btn-bricky pull-right">
                            Enviar <i class="fa fa-arrow-circle-right"></i>
                        </button>
                    </div>
                </fieldset>
            </form>	
        </div>

        <!-- Formulario de Solicitud de Cuenta -->
        <div class="box-register" style="display:none;">
            <h3>Solicitud de Creaci&oacute;n de Cuenta</h3>
            <p>Ingrese sus datos para solicitar la creaci&oacute;n de su cuenta:</p>
            <form class="form-register" action="modulos/enviar_solicitud.php" method="POST" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
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
                            <input type="email" class="form-control" name="email" placeholder="Correo Electr&oacute;nico" required>
                            <i class="fa fa-envelope"></i>
                        </span>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-light-grey go-back" href="#" onclick="document.querySelector('.box-register').style.display='none';document.querySelector('.box-login').style.display='block';">
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
    <script src="assets/plugins/jquery-ui/jquery-ui-1.10.2.custom.min.js"></script>
    <script src="assets/plugins/bootstrap/js/bootstrap.min.js"></script>
    <script src="assets/plugins/bootstrap-hover-dropdown/bootstrap-hover-dropdown.min.js"></script>
    <script src="assets/plugins/blockUI/jquery.blockUI.js"></script>
    <script src="assets/plugins/iCheck/jquery.icheck.min.js"></script>
    <script src="assets/plugins/perfect-scrollbar/src/jquery.mousewheel.js"></script>
    <script src="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.js"></script>
    <script src="assets/plugins/less/less-1.5.0.min.js"></script>
    <script src="assets/plugins/jquery-cookie/jquery.cookie.js"></script>
    <script src="assets/plugins/bootstrap-colorpalette/js/bootstrap-colorpalette.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/plugins/jquery-validation/dist/jquery.validate.min.js"></script>
    <script src="assets/js/login.js"></script>

    <script>
      jQuery(document).ready(function() {
        Main.init();
        Login.init();
        // Ocultar mensajes después de 5s
        setTimeout(function(){ $('.alert').fadeOut('slow'); }, 5000);
      });
    </script>
</body>
</html>

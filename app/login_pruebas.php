<?php
// login.php
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'domain' => '',
  'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
]);


session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$login_error = $_SESSION['error_login'] ?? '';
unset($_SESSION['error_login']);
?>
<!DOCTYPE html>
<html lang="es" class="no-js">
  <head>
    <title>Visibility 2</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimum-scale=1.0, maximum-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">

    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/fonts/style.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/estilo.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <link rel="stylesheet" href="assets/plugins/iCheck/skins/all.css">
    <link rel="stylesheet" href="assets/plugins/bootstrap-colorpalette/css/bootstrap-colorpalette.css">
    <link rel="stylesheet" href="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.css">
    <link rel="stylesheet" href="assets/css/theme_light.css" type="text/css" id="skin_color">
    <link rel="stylesheet" href="assets/css/print.css" type="text/css" media="print"/>
  </head>

  <body class="login example2">
    <div class="centrado" id="preloader">
      <div class="lds-default">
        <div></div><div></div><div></div><div></div><div></div><div></div>
        <div></div><div></div><div></div><div></div><div></div><div></div>
      </div>
    </div>

    <div class="hidden" id="principal" style="margin-top:10%;">
      <div class="main-login col-sm-4 col-sm-offset-4">
        <center><img src="assets/imagenes/logo/logo-Visibility.png" alt="image" style="width:50%;"></center>
      </div>

      <!-- BOX LOGIN -->
      <div class="box-login">
        <h3><center>Iniciar sesión</center></h3>

        <?php if ($login_error): ?>
          <div class="alert alert-danger" role="alert" style="margin-top:10px;">
            <i class="fa fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <form class="form-login" action="procesar_login.php" method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

          <!-- Campos ocultos para GPS -->
          <input type="hidden" name="gps_lat" id="gps_lat">
          <input type="hidden" name="gps_lng" id="gps_lng">
          <input type="hidden" name="gps_acc" id="gps_acc">
          <input type="hidden" name="gps_ts"  id="gps_ts">

          <div class="errorHandler alert alert-danger no-display">
            <i class="fa fa-remove-sign"></i> Tienes algunos errores de formulario. Por favor verifique a continuación.
          </div>

          <fieldset>
            <div class="form-group">
              <span class="input-icon">
                <input type="text" class="form-control" name="usuario"
                       placeholder="RUT (sin puntos ni guión) o usuario"
                       required autocomplete="username"
                       style="text-transform: lowercase;">
                <i class="fa fa-user"></i>
              </span>
            </div>

            <div class="form-group form-actions">
              <span class="input-icon">
                <input type="password" class="form-control password" name="clave"
                       placeholder="Contraseña" required autocomplete="current-password">
                <i class="fa fa-lock"></i>
                <a class="forgot" href="javascript:void(0)" onclick="toggleBoxes('forgot')">
                  Olvidé mi contraseña
                </a>
              </span>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-bricky pull-right" id="btnLogin">
                Acceder <i class="fa fa-arrow-circle-right"></i>
              </button>
            </div>

            <div class="new-account">
              ¿Aún no tienes una cuenta?
              <a href="javascript:void(0)" class="register" onclick="toggleBoxes('register')">
                Crea una cuenta
              </a>
            </div>
          </fieldset>
        </form>

        <small class="text-muted">* Para ingresar debes tener el GPS activo y otorgar permiso de ubicación.</small>
      </div>

      <!-- BOX FORGOT -->
      <div class="box-forgot" style="display:none;">
        <h3>¿Olvidaste tu Contraseña?</h3>
        <p>Ingrese su dirección de correo electrónico a continuación para restablecer su contraseña.</p>
        <form class="form-forgot" id="forgotPasswordForm" onsubmit="return false;">
          <input type="hidden" id="forgot_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="errorHandler alert alert-danger no-display">
            <i class="fa fa-remove-sign"></i> Tienes algunos errores de formulario. Por favor, verifique a continuación.
          </div>
          <fieldset>
            <div class="form-group">
              <span class="input-icon">
                <input type="email" class="form-control" name="email" id="email" placeholder="Email" required autocomplete="email">
                <i class="fa fa-envelope"></i>
              </span>
            </div>
            <div class="form-actions">
              <a class="btn btn-light-grey go-back" onclick="toggleBoxes('login')">
                <i class="fa fa-circle-arrow-left"></i> Atrás
              </a>
              <button type="button" class="btn btn-bricky pull-right" onclick="sendForgotPassword()">
                Enviar <i class="fa fa-arrow-circle-right"></i>
              </button>
            </div>
          </fieldset>
        </form>
      </div>

      <!-- BOX REGISTER -->
      <div class="box-register" style="display:none;">
        <h3>Crear cuenta</h3>
        <p>Ingrese datos requeridos a continuación:</p>
        <form class="form-register" onsubmit="return false;">
          <div class="errorHandler alert alert-danger no-display">
            <i class="fa fa-remove-sign"></i> Tienes algunos errores de formulario. Por favor verifique a continuación.
          </div>
          <fieldset>
            <div class="form-group"><input type="text" class="form-control" id="nombreEmpresa" placeholder="Nombre Empresa"></div>
            <div class="form-group"><input type="text" class="form-control" id="run" placeholder="Run"></div>
            <div class="form-group"><input type="text" class="form-control" id="telefonoContacto" placeholder="Teléfono Contacto"></div>
            <div class="form-group"><input type="email" class="form-control" id="correo" placeholder="Correo"></div>
            <div class="form-group">
              <label for="agree" class="checkbox-inline">
                <input type="checkbox" class="grey agree" id="agree" name="agree">
                Acepto los Términos de servicio y la Política de privacidad
              </label>
            </div>
            <div class="form-actions">
              <a class="btn btn-light-grey go-back" onclick="toggleBoxes('login')">
                <i class="fa fa-circle-arrow-left"></i> Volver
              </a>
              <a class="btn btn-success pull-right" style="cursor: pointer;" onclick="crearUsuario();">
                <i class="fa fa-save"></i> Guardar
              </a>
            </div>
          </fieldset>
        </form>
      </div>

      <!-- BOX PERMISSIONS: Habilitar GPS -->
      <div class="box-permissions" style="display:none;">
        <h3>Habilitar permiso de GPS</h3>
        <p>Para continuar, habilita los servicios de ubicación y otorga permiso al sitio/app.</p>

        <div id="permStatus" class="alert alert-warning" style="margin-bottom:12px;">
          Debes permitir “Ubicación” cuando aparezca el mensaje del navegador/sistema.
        </div>

        <div class="form-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
          <button type="button" class="btn btn-success" id="btnPermitirAhora">
            <i class="fa fa-location-arrow"></i> Permitir ubicación ahora
          </button>

          <button type="button" class="btn btn-info" id="btnAbrirAjustes" style="display:none;">
            <i class="fa fa-cog"></i> Abrir ajustes de ubicación
          </button>

          <a class="btn btn-light-grey" onclick="toggleBoxes('login')">
            <i class="fa fa-circle-arrow-left"></i> Volver al login
          </a>
        </div>

        <hr>
        <small>
          <strong>Tips:</strong>
          <ul style="margin-left:18px;">
            <li><b>Chrome (Android/Windows):</b> haz clic en el candado de la barra de direcciones → Permisos → Ubicación → “Permitir”.</li>
            <li><b>Safari (iPhone):</b> Ajustes → Safari → Ubicación → “Preguntar” o “Permitir” y asegúrate de que “Servicios de ubicación” estén activos.</li>
            <li><b>Android:</b> Ajustes → Ubicación → “Activado” (Alta precisión).</li>
          </ul>
        </small>
      </div>

      <div class="copyright">
        2025 &copy; Visibility 2
      </div>
    </div>

    <script src="assets/js/preloader.js"></script>
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
    <script src="assets/js/usuario.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
      function toggleBoxes(which) {
        $('.box-login, .box-forgot, .box-register, .box-permissions').hide();
        if (which === 'forgot') $('.box-forgot').show();
        else if (which === 'register') $('.box-register').show();
        else if (which === 'permissions') $('.box-permissions').show();
        else $('.box-login').show();
      }

      jQuery(document).ready(function() {
        Main.init();
        Login.init();

        // Intercepta el submit para forzar el flujo de permisos/GPS
        const form = document.querySelector('.form-login');
        form.addEventListener('submit', function(e){
          e.preventDefault();
          flujoLoginConUbicacion(form);
        });

        // Botón de la pestaña "Habilitar permiso de GPS"
        document.getElementById('btnPermitirAhora').addEventListener('click', async function() {
          await reintentarPermisosYGeolocalizar();
        });

        // Si hay bridge de Median, mostramos botón "Abrir ajustes"
        if (medianAvailable()) {
          document.getElementById('btnAbrirAjustes').style.display = '';
          document.getElementById('btnAbrirAjustes').addEventListener('click', async function(){
            try {
              // intenta prompt nativo
              await medianPromptLocation();
              // luego intenta geolocalizar y, si logra, vuelve al login y envía
              const pos = await getWebPosition({ enableHighAccuracy:true, timeout:20000, maximumAge:0 });
              setPosYEnviar(pos);
            } catch(e) {
              setPermStatus('No se pudo habilitar la ubicación desde ajustes. Verifica permisos y GPS.', 'danger');
            }
          });
        }
      });

      // ======== Bridge Median ========
      function medianAvailable() {
        return !!(window.median && median.android && median.android.geoLocation);
      }
      function medianIsLocationEnabled() {
        return new Promise(resolve => {
          try {
            median.android.geoLocation.isLocationServicesEnabled({
              callback: function(res){ resolve(!!(res && res.enabled)); }
            });
          } catch(e) { resolve(false); }
        });
      }
      async function medianPromptLocation() {
        try {
          median.android.geoLocation.promptLocationServices();
          await new Promise(r => setTimeout(r, 1200));
          return await medianIsLocationEnabled();
        } catch(e) { return false; }
      }

      // ======== Geolocalización Web ========
      function getWebPosition(opts) {
        return new Promise((resolve, reject) => {
          if (!('geolocation' in navigator)) {
            return reject({code: -1, message: 'Geolocation API no disponible'});
          }
          navigator.geolocation.getCurrentPosition(
            pos => resolve(pos),
            err => reject(err),
            opts || { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
          );
        });
      }

      // Para status en la pestaña de permisos
      function setPermStatus(msg, kind) {
        const el = document.getElementById('permStatus');
        el.className = 'alert alert-' + (kind || 'warning');
        el.textContent = msg;
      }

      function setPosYEnviar(pos) {
        document.getElementById('gps_lat').value = pos.coords.latitude;
        document.getElementById('gps_lng').value = pos.coords.longitude;
        document.getElementById('gps_acc').value = pos.coords.accuracy || 0;
        document.getElementById('gps_ts').value  = Math.floor(Date.now()/1000);
        // volvemos al login por si estaba en la pestaña de permisos
        toggleBoxes('login');
        document.querySelector('.form-login').submit();
      }

      // ======== Flujo de login con ubicación ========
      async function flujoLoginConUbicacion(form) {
        const btn = document.getElementById('btnLogin');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Verificando ubicación...';

        // 0) HTTPS check (los navegadores bloquean geolocalización sin https, salvo localhost)
        const isLocalhost = /^localhost$|^127\.0\.0\.1$|^\[::1\]$/.test(location.hostname);
        if (location.protocol !== 'https:' && !isLocalhost) {
          toggleBoxes('permissions');
          setPermStatus('La geolocalización requiere HTTPS o localhost. Accede por una URL segura (https://).', 'danger');
          btn.disabled = false; btn.innerHTML = original; return;
        }

        try {
          // 1) Si estamos en APK, intentamos habilitar servicios de ubicación nativos antes
          if (medianAvailable()) {
            const enabled = await medianIsLocationEnabled();
            if (!enabled) { await medianPromptLocation(); }
          }

          // 2) Dispara inmediatamente el prompt del navegador (sin awaits previos que “rompan” el gesto)
          const pos = await getWebPosition({ enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });

          // 3) Si todo bien, enviamos
          setPosYEnviar(pos);

        } catch (err) {
          // Si falla: mostramos pestaña "Habilitar permiso de GPS" con mensaje claro
          toggleBoxes('permissions');

          let msg = 'Activa el GPS y otorga permiso de ubicación.';
          if (err && typeof err.code === 'number') {
            if (err.code === 1) msg = 'Permiso de ubicación denegado. Permite la ubicación para continuar.';
            else if (err.code === 2) msg = 'Ubicación no disponible. Enciende el GPS (alta precisión) y verifica señal.';
            else if (err.code === 3) msg = 'Tiempo de espera agotado. Intenta nuevamente al aire libre.';
          } else if (location.protocol !== 'https:' && !/^localhost$|^127\.0\.0\.1$|^\[::1\]$/.test(location.hostname)) {
            msg = 'La geolocalización requiere HTTPS o localhost. Accede por una URL segura.';
          }
          setPermStatus(msg, 'warning');

        } finally {
          btn.disabled = false;
          btn.innerHTML = original;
        }
      }

      // Reintento desde la pestaña de permisos
      async function reintentarPermisosYGeolocalizar() {
        // En APK: intenta nuevamente prompt nativo
        if (medianAvailable()) { await medianPromptLocation(); }

        try {
          const pos = await getWebPosition({ enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
          setPosYEnviar(pos);
        } catch (e) {
          // Actualiza mensajes según error
          let msg = 'Aún no se pudo obtener tu ubicación.';
          if (e && e.code === 1) msg = 'Permiso denegado. Habilita “Ubicación” para este sitio/app.';
          else if (e && e.code === 2) msg = 'GPS desactivado o sin señal. Enciéndelo (alta precisión).';
          else if (e && e.code === 3) msg = 'Se agotó el tiempo. Inténtalo nuevamente.';
          setPermStatus(msg, 'danger');
        }
      }

      // ===== Recuperar contraseña =====
      function sendForgotPassword() {
        var email = document.getElementById('email').value;
        var csrf  = document.getElementById('forgot_csrf').value;

        fetch('recuperar_clave.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch { return {status:'error', message:t||'Respuesta inesperada'}; } })
        .then(resp => {
          Swal.fire(resp.status === 'ok' ? 'Listo' : 'Error',
                    resp.message || 'Solicitud procesada',
                    resp.status === 'ok' ? 'success' : 'error');
        })
        .catch(() => Swal.fire('Error','No se pudo procesar la solicitud','error'));
      }
    </script>
  </body>
</html>

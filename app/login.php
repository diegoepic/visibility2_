<?php
declare(strict_types=1);


$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$appBasePath = '/visibility2/app';
$indexUrl = $appBasePath . '/index.php';
$indexPruebasUrl = $appBasePath . '/index_pruebas.php';

session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

/* Encabezados de seguridad y no-cache */
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/*  Verificacion Token CSRF */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['usuario_id'])) {
    $divisionId = isset($_SESSION['division_id']) ? (int) $_SESSION['division_id'] : 0;

    if ($divisionId === 14) {
        header('Location: ' . $indexPruebasUrl);
    } else {
        header('Location: ' . $indexUrl);
    }
    exit;
}


$login_error = $_SESSION['error_login'] ?? '';
unset($_SESSION['error_login']);
$session_expired = (isset($_GET['session_expired']) && $_GET['session_expired'] == '1');
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
    <link rel="stylesheet" href="assets/css/theme_light.css" type="text/css" id="skin_color">

<link rel="manifest" href="/visibility2/manifest.webmanifest">
<meta name="theme-color" content="#0d6efd">

    <style>
      .box-login{ position:relative; margin:16px auto 32px; max-width:520px; width:92%; }
      .main-login{ margin-top:32px; margin-bottom:8px; text-align:center; }
      #geoBanner{ display:none; margin:0 0 12px; border-radius:6px; box-shadow:0 2px 6px rgba(0,0,0,.08); }
      #geoBanner .geo-head{ display:flex; align-items:center; gap:8px; margin-bottom:8px; font-weight:600; }
      #geoBanner .geo-actions{ display:flex; gap:8px; flex-wrap:wrap; margin:6px 0 4px; }
      #geoStatus{ font-size:12px; color:#333; margin-top:4px; }
      #geoHttpsMsg{ display:none; }
      .form-actions .btn{ min-width:140px; }
      @media (max-width:480px){
        .box-login{ width:94%; margin:12px auto 24px; }
        #geoBanner .geo-actions{ display:block; }
        #geoBanner .geo-actions .btn{ display:block; width:100%; margin-bottom:8px; }
      }
    </style>
  </head>

  <body class="login example2">
    <div class="centrado" id="preloader">
      <div class="lds-default">
        <div></div><div></div><div></div><div></div><div></div><div></div>
        <div></div><div></div><div></div><div></div><div></div><div></div>
      </div>
    </div>

    <div class="hidden" id="principal">
      <div class="main-login col-sm-4 col-sm-offset-4">
        <center><img src="assets/imagenes/logo/logo-Visibility.png" alt="Visibility" style="width:50%;max-width:240px;"></center>
      </div>

      <div class="box-login">

        <!-- Avisos -->
        <?php if ($session_expired): ?>
          <div class="alert alert-warning" role="alert" style="margin-top:10px;">
            <i class="fa fa-info-circle"></i>
            Tu sesión ha expirado por inactividad o porque iniciaste sesión en otro dispositivo. Por favor, ingresa de nuevo.
          </div>
        <?php endif; ?>
        <?php if ($login_error): ?>
          <div class="alert alert-danger" role="alert" style="margin-top:10px;">
            <i class="fa fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <!-- Banner geolocalización -->
        <div id="geoBanner" class="alert alert-info" role="alert">
          <div class="geo-head">
            <i class="fa fa-location-arrow"></i>
            <span>Visibility necesita acceso a la ubicación</span>
          </div>
          <div class="geo-actions">
            <button id="btnGrantGeo" class="btn btn-primary btn-sm" type="button">Permitir ubicación ahora</button>
            <button id="btnHideGeo" class="btn btn-default btn-sm" type="button">Más tarde</button>
          </div>
          <div id="geoStatus">
            Pulsa "Permitir ubicación ahora" para solicitar el permiso.
            <strong id="geoHttpsMsg"> Debes usar HTTPS.</strong>
          </div>
        </div>

        <h3><center>Iniciar sesión</center></h3>

        <form class="form-login" id="formLogin" action="procesar_login.php" method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="errorHandler alert alert-danger no-display">
            <i class="fa fa-remove-sign"></i> Tienes algunos errores de formulario. Por favor verifique a continuación.
          </div>
          <fieldset>
            <div class="form-group">
              <span class="input-icon">
                <input type="text" class="form-control" name="usuario"
                       placeholder="RUT (sin puntos ni guión) o usuario" required
                       autocomplete="username" style="text-transform: lowercase;">
                <i class="fa fa-user"></i>
              </span>
            </div>
            <div class="form-group form-actions">
              <span class="input-icon">
                <input type="password" class="form-control password" name="clave"
                       placeholder="Contraseña" required autocomplete="current-password">
                <i class="fa fa-lock"></i>
                <a class="forgot" href="javascript:void(0)" onclick="$('.box-login').hide();$('.box-forgot').show();">
                  Olvidé mi contraseña
                </a>
              </span>
            </div>
            <div class="form-actions">
              <button id="btnLogin" type="submit" class="btn btn-bricky pull-right">
                Acceder <i class="fa fa-arrow-circle-right"></i>
              </button>
            </div>
            <div class="new-account">
              ¿Aún no tienes una cuenta?
              <a href="javascript:void(0)" class="register" onclick="$('.box-login').hide();$('.box-register').show();">
                Crea una cuenta
              </a>
            </div>
          </fieldset>
        </form>
      </div>

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
              <a class="btn btn-light-grey go-back" onclick="$('.box-forgot').hide();$('.box-login').show();">
                <i class="fa fa-circle-arrow-left"></i> Atrás
              </a>
              <button type="button" class="btn btn-bricky pull-right" onclick="sendForgotPassword()">
                Enviar <i class="fa fa-arrow-circle-right"></i>
              </button>
            </div>
          </fieldset>
        </form>
      </div>

      <div class="box-register" style="display:none;">
        <h3>Crear cuenta</h3>
        <p>Ingrese datos requeridos a continuación:</p>
        <form class="form-register" onsubmit="return false;">
          <div class="errorHandler alert alert-danger no-display">
            <i class="fa fa-remove-sign"></i> Tienes algunos errores de formulario. Por favor verifique a continuación.
          </div>
          <fieldset>
            <div class="form-group">
              <input type="text" class="form-control" id="nombreEmpresa" placeholder="Nombre Empresa">
            </div>
            <div class="form-group">
              <input type="text" class="form-control" id="run" placeholder="Run">
            </div>
            <div class="form-group">
              <input type="text" class="form-control" id="telefonoContacto" placeholder="Teléfono Contacto">
            </div>
            <div class="form-group">
              <input type="email" class="form-control" id="correo" placeholder="Correo">
            </div>
            <div class="form-group">
              <label for="agree" class="checkbox-inline">
                <input type="checkbox" class="grey agree" id="agree" name="agree">
                Acepto los Términos de servicio y la Política de privacidad
              </label>
            </div>
            <div class="form-actions">
              <a class="btn btn-light-grey go-back" onclick="$('.box-register').hide();$('.box-login').show();">
                <i class="fa fa-circle-arrow-left"></i> Volver
              </a>
              <a class="btn btn-success pull-right" style="cursor: pointer;" onclick="crearUsuario();">
                <i class="fa fa-save"></i> Guardar
              </a>
            </div>
          </fieldset>
        </form>
      </div>

      <div class="copyright">
        2025 &copy; Visibility 2
      </div>
    </div>

    <script src="assets/js/preloader.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
    <script src="assets/plugins/bootstrap/js/bootstrap.min.js"></script>
    <script src="assets/plugins/jquery-validation/dist/jquery.validate.min.js"></script>
    <script src="assets/js/login.js"></script>
    <script src="assets/js/usuario.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
      jQuery(function(){ Login.init(); });
      function sendForgotPassword() {
        var email = document.getElementById('email').value;
        var csrf  = document.getElementById('forgot_csrf').value;
        fetch('recuperar_clave.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(csrf)
        })
        .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch { return {status:'error', message:t||'Respuesta inesperada'}; }})
        .then(resp => Swal.fire(resp.status==='ok'?'Listo':'Error', resp.message||'Solicitud procesada', resp.status==='ok'?'success':'error'))
        .catch(() => Swal.fire('Error','No se pudo procesar la solicitud','error'));
      }

      /* ===== Geolocalización ===== */
      (function setupGeoPermission(){
        const isSecure    = location.protocol === 'https:' || ['localhost','127.0.0.1'].includes(location.hostname);
        const geoBanner   = document.getElementById('geoBanner');
        const geoHttpsMsg = document.getElementById('geoHttpsMsg');
        const geoStatus   = document.getElementById('geoStatus');
        const btnGrant    = document.getElementById('btnGrantGeo');
        const btnHide     = document.getElementById('btnHideGeo');
        const formLogin   = document.getElementById('formLogin');

        const PERMIT_KEY='v2_geo_permit', PERMIT_TTL_MS=30*24*60*60*1000;
        const hasRecentPermit=()=>{ try{const v=JSON.parse(localStorage.getItem(PERMIT_KEY)||'null'); return v && (Date.now()-v.ts)<PERMIT_TTL_MS;}catch(e){return false;} };
        const markPermit =()=>{ localStorage.setItem(PERMIT_KEY, JSON.stringify({ts:Date.now()})); };
        const clearPermit=()=>{ localStorage.removeItem(PERMIT_KEY); };

        let triedOnceOnSubmit=false;

        function showBanner(msg){
          if(!isSecure) geoHttpsMsg.style.display='inline';
          geoStatus.textContent = msg || 'Pulsa "Permitir ubicación ahora" para solicitar el permiso.';
          geoBanner.style.display='block';
        }
        function hideBanner(){ geoBanner.style.display='none'; }

        function explainDenied(){
          const host = location.host;
          Swal.fire({icon:'info',title:'Permiso denegado',html:'<div style="text-align:left;font-size:14px;line-height:1.4;">'
            +'<b>Cómo re-activarlo:</b><br>'
            +'- Chrome Android: ⋮ &gt; Información del sitio &gt; Permisos &gt; Ubicación &gt; Permitir.<br>'
            +'- iPhone (Safari): Ajustes &gt; Safari &gt; Ubicación &gt; Preguntar, luego vuelve al sitio.<br>'
            +'- Usa <b>HTTPS</b>: <code>https://'+host+'</code>.'
            +'</div>'});
        }

        function onPositionOK(pos){
          try{ localStorage.setItem('v2_last_geo', JSON.stringify({lat:pos.coords.latitude,lng:pos.coords.longitude,acc:pos.coords.accuracy,ts:Date.now()})); }catch(e){}
          markPermit(); geoStatus.textContent='Permiso otorgado. ¡Listo!'; hideBanner();
        }
        function onPositionErr(err){
          if(err && err.code===1){ clearPermit(); explainDenied(); showBanner('El permiso fue denegado por el navegador.'); }
          else if(err && err.code===2){ showBanner('Ubicación no disponible. Intenta al aire libre o activa el GPS.'); }
          else if(err && err.code===3){ showBanner('Tiempo de espera agotado. Vuelve a intentarlo.'); }
          else { showBanner('No se pudo obtener la ubicación.'); }
        }

        function requestGeolocation(){
          if(!('geolocation' in navigator)) { showBanner('Este navegador no soporta geolocalización.'); return; }
          if(!isSecure){ showBanner('Este sitio no está en HTTPS. El navegador bloqueará la geolocalización.'); return; }
          try{ navigator.geolocation.getCurrentPosition(onPositionOK,onPositionErr,{enableHighAccuracy:true,timeout:10000,maximumAge:0}); }catch(e){ onPositionErr(e); }
        }

        function initPermissionUI(){
          if(!('geolocation' in navigator)){ showBanner('Tu navegador no soporta geolocalización.'); return; }
          if(!isSecure){ showBanner('Activa HTTPS para poder pedir el permiso de ubicación.'); return; }

          const hasPermsAPI = !!(navigator.permissions && navigator.permissions.query);
          if(hasPermsAPI){
            navigator.permissions.query({name:'geolocation'}).then(p=>{
              const update=()=>{
                if(p.state==='granted') hideBanner();
                else if(p.state==='prompt'){ hasRecentPermit()?hideBanner():showBanner('Pulsa "Permitir ubicación ahora" para solicitar el permiso.'); }
                else if(p.state==='denied'){ showBanner('El permiso está bloqueado. Debes re-activarlo en la configuración del navegador.'); }
              };
              update(); p.onchange = update;
            }).catch(()=>{ hasRecentPermit()?hideBanner():showBanner('Pulsa "Permitir ubicación ahora" para solicitar el permiso.'); });
          } else {
            hasRecentPermit()?hideBanner():showBanner('Pulsa "Permitir ubicación ahora" para solicitar el permiso.');
          }

          setTimeout(()=>{ try{
            navigator.geolocation.getCurrentPosition(onPositionOK, err=>{ if(err && err.code===1) clearPermit(); }, {timeout:4000, enableHighAccuracy:false, maximumAge:0});
          }catch(e){} }, 200);
        }

        document.getElementById('btnGrantGeo').addEventListener('click', requestGeolocation);
        document.getElementById('btnHideGeo').addEventListener('click', hideBanner);

        formLogin.addEventListener('submit', function(){
          if(!triedOnceOnSubmit && isSecure && ('geolocation' in navigator)){
            triedOnceOnSubmit = true;
            try{ navigator.geolocation.getCurrentPosition(()=>{markPermit();},()=>{}, {timeout:5000}); }catch(e){}
          }
        });

        initPermissionUI();
      })();
    </script>
    
    <script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/visibility2/sw.js', { scope: '/visibility2/' }).catch(()=>{});
}
</script>
    
  </body>
</html>

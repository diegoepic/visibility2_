package com.visibility.wrapper

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.speech.tts.TextToSpeech
import android.view.ViewGroup
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.JavascriptInterface
import android.webkit.RenderProcessGoneDetail
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.ActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.isVisible
import androidx.webkit.ServiceWorkerControllerCompat
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewFeature
import com.visibility.wrapper.databinding.ActivityMainBinding
import java.io.File
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import android.widget.Toast
import android.util.Log

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding

    /**
     * WebView actual. NO se usa binding.webView directamente porque, cuando el proceso del
     * renderer muere, se destruye esa instancia y se crea otra en su lugar (ver
     * onRenderProcessGone): el binding seguiría apuntando a la vista muerta.
     */
    private lateinit var webView: WebView

    companion object {
        private const val TAG = "VisibilityWrapper"
        /** URL base que se cargará siempre al iniciar la app. */
        const val INITIAL_URL = "https://visibility.cl/visibility2/app/login.php"
        private const val MIME_TYPE_IMAGES = "image/*"
        /** Único host al que se le entregan GPS, voz y puentes nativos. */
        private const val TRUSTED_HOST = "visibility.cl"
    }

    // Permisos
    private val locationPermissions = arrayOf(
        Manifest.permission.ACCESS_FINE_LOCATION,
        Manifest.permission.ACCESS_COARSE_LOCATION
    )

    private val galleryPermissions: Array<String> = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        arrayOf(Manifest.permission.READ_MEDIA_IMAGES)
    } else {
        arrayOf(Manifest.permission.READ_EXTERNAL_STORAGE)
    }

    private val cameraPermissions = arrayOf(Manifest.permission.CAMERA)

    private var geoOrigin: String? = null
    private var geoCallback: GeolocationPermissions.Callback? = null

    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var pendingFileChooserParams: WebChromeClient.FileChooserParams? = null
    private var cameraPhotoUri: Uri? = null
    private var pendingDownload: DownloadRequestData? = null

    /** Última URL cargada. Sirve para reponer la página tras un crash del renderer y para
     *  saber, desde los puentes JS (que corren en otro hilo), si la página es de confianza. */
    @Volatile
    private var lastLoadedUrl: String = INITIAL_URL

    // Texto a voz nativo: el WebView de Android no implementa speechSynthesis del navegador.
    private var tts: TextToSpeech? = null
    @Volatile
    private var ttsReady = false

    // Connectivity monitoring para eventos online/offline
    private val connectivityManager by lazy {
        getSystemService(ConnectivityManager::class.java)
    }

    private val connectivityCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            Log.d(TAG, "Network available - dispatching online event")
            runOnUiThread {
                webView.evaluateJavascript(
                    """
                    (function() {
                        window.dispatchEvent(new Event('online'));
                        if (window.Queue && typeof window.Queue.drain === 'function') {
                            window.Queue.drain();
                        }
                    })();
                    """.trimIndent(),
                    null
                )
            }
        }

        override fun onLost(network: Network) {
            Log.d(TAG, "Network lost - dispatching offline event")
            runOnUiThread {
                webView.evaluateJavascript(
                    "window.dispatchEvent(new Event('offline'));",
                    null
                )
            }
        }

        override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
            val hasInternet = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            val hasValidated = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
            Log.d(TAG, "Network capabilities changed - internet: $hasInternet, validated: $hasValidated")

            if (hasInternet && hasValidated) {
                runOnUiThread {
                    webView.evaluateJavascript(
                        """
                        (function() {
                            if (!navigator.onLine) return;
                            window.dispatchEvent(new Event('online'));
                            if (window.Queue && typeof window.Queue.drain === 'function') {
                                window.Queue.drain();
                            }
                        })();
                        """.trimIndent(),
                        null
                    )
                }
            }
        }
    }

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grantResults ->
            val granted = grantResults.values.all { it }
            geoCallback?.invoke(geoOrigin, granted, false)
            geoCallback = null
            geoOrigin = null
        }

    /**
     * Permisos para adjuntar fotos.
     *
     * Antes se exigían cámara Y galería juntas y, si faltaba una, se cancelaba el selector
     * completo: negar la galería dejaba al ejecutor sin poder fotografiar la visita. Ahora cada
     * permiso habilita su camino por separado, y el selector de archivos siempre se abre (el
     * ACTION_GET_CONTENT del sistema no necesita permiso de galería para entregar una imagen).
     */
    private val filePermissionsLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            val params = pendingFileChooserParams
            val cameraGranted = hasAllPermissions(cameraPermissions)
            if (!cameraGranted) {
                Toast.makeText(this, R.string.permission_camera_denied, Toast.LENGTH_LONG).show()
            }
            launchFilePicker(params, allowCamera = cameraGranted)
        }

    private val fileChooserLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result: ActivityResult ->
            handleFileChooserResult(result.resultCode, result.data)
        }

    private val writeStoragePermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            val downloadRequest = pendingDownload
            pendingDownload = null
            if (granted && downloadRequest != null) {
                enqueueDownload(downloadRequest)
            } else if (downloadRequest != null) {
                Toast.makeText(this, R.string.permission_denied, Toast.LENGTH_SHORT).show()
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        webView = binding.webView

        // ORDEN IMPORTANTE: Cookies -> Service Workers -> WebView
        setupCookieManager()
        setupServiceWorkers()
        setupWebView(webView)
        setupWindowInsets()
        setupErrorView()
        // El motor de voz NO se inicializa acá: ver ensureTextToSpeech().
        onBackPressedDispatcher.addCallback(this, backCallback)

        // Restaurar estado del WebView si existe
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(INITIAL_URL)
        }
    }

    /**
     * Desde targetSdk 35 la ventana es borde a borde en Android 15+: sin esto el header de la PWA
     * quedaría debajo del reloj y la barra de gestos taparía los botones de abajo. Se fuerza el
     * mismo comportamiento en versiones anteriores para que el layout sea uno solo.
     *
     * Se incluye el inset del teclado (ime) a propósito: la app tiene formularios largos y sin eso
     * el campo enfocado queda tapado al escribir.
     */
    private fun setupWindowInsets() {
        WindowCompat.setDecorFitsSystemWindows(window, false)

        ViewCompat.setOnApplyWindowInsetsListener(binding.rootContainer) { view, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars()
                        or WindowInsetsCompat.Type.displayCutout()
                        or WindowInsetsCompat.Type.ime()
            )
            view.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            insets
        }

        // Los íconos de la barra de estado se dibujan sobre el fondo de la app: oscuros en tema
        // claro, claros en tema oscuro. Antes estaban fijos en claros y se perdían sobre el
        // header blanco de la PWA.
        val nightMode = (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES
        WindowInsetsControllerCompat(window, binding.root).isAppearanceLightStatusBars = !nightMode
    }

    private fun setupErrorView() {
        binding.errorRetry.setOnClickListener {
            hideErrorView()
            val target = lastLoadedUrl.takeIf { it.isNotBlank() } ?: INITIAL_URL
            webView.loadUrl(target)
        }
    }

    private fun showErrorView() {
        binding.errorView.isVisible = true
        binding.progressBar.isVisible = false
    }

    private fun hideErrorView() {
        binding.errorView.isVisible = false
    }

    /**
     * Inicializa el motor de voz del sistema, UNA sola vez y sólo cuando se va a usar.
     *
     * El WebView de Android expone window.speechSynthesis pero no lo conecta al motor de TTS:
     * getVoices() vuelve vacío y speak() no emite sonido, así que la voz de la navegación quedaba
     * muda dentro del APK aunque funcionara en Chrome. voice_controller.js usa este puente cuando
     * existe (window.AndroidVoice).
     *
     * OJO con inicializarlo en onCreate: `new TextToSpeech()` levanta el servicio de voz del
     * sistema (otro proceso, decenas de MB) al abrir la app, se use o no la navegación. En un
     * teléfono de gama baja eso es memoria y arranque regalados a una función que la mayoría de
     * los ejecutores no usa todos los días. Se dispara desde AndroidNav.keepAwake(true), que la
     * página llama justo al iniciar la navegación: alcanza a estar listo antes de la primera
     * instrucción, que llega segundos después.
     */
    private fun ensureTextToSpeech() {
        if (tts != null) return
        Log.d(TAG, "Inicializando TextToSpeech (primer uso)")
        tts = TextToSpeech(this) { status ->
            if (status != TextToSpeech.SUCCESS) {
                Log.w(TAG, "TextToSpeech no disponible (status=$status)")
                return@TextToSpeech
            }
            val engine = tts ?: return@TextToSpeech
            val result = engine.setLanguage(Locale("es", "CL"))
            if (result == TextToSpeech.LANG_MISSING_DATA || result == TextToSpeech.LANG_NOT_SUPPORTED) {
                // Español de Chile no siempre está instalado; el genérico sirve igual.
                engine.setLanguage(Locale("es", "ES"))
            }
            ttsReady = true
            Log.d(TAG, "TextToSpeech listo")
        }
    }

    /**
     * Configura el CookieManager para persistir cookies entre sesiones.
     * CRÍTICO: Sin esto, las cookies de sesión se pierden al cerrar la app.
     */
    private fun setupCookieManager() {
        val cookieManager = CookieManager.getInstance()

        // Aceptar cookies
        cookieManager.setAcceptCookie(true)

        // Aceptar cookies de terceros (necesario para algunos flujos de autenticación)
        cookieManager.setAcceptThirdPartyCookies(webView, true)

        // Forzar persistencia inicial
        cookieManager.flush()

        Log.d(TAG, "CookieManager configurado para persistencia")
    }

    /**
     * Configura Service Workers para soporte PWA.
     */
    private fun setupServiceWorkers() {
        try {
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_BASIC_USAGE)) {
                val swController = ServiceWorkerControllerCompat.getInstance()
                swController.serviceWorkerWebSettings.apply {
                    allowContentAccess = true
                    allowFileAccess = true
                    cacheMode = WebSettings.LOAD_DEFAULT
                }
                Log.d(TAG, "Service Workers habilitados")
            } else {
                Log.d(TAG, "Service Workers no soportados en este dispositivo")
            }
        } catch (e: Exception) {
            Log.w(TAG, "Error configurando Service Workers: ${e.message}")
        }
    }

    /**
     * Botón/gesto atrás.
     *
     * OBLIGATORIO desde que la app apunta a targetSdk 35: en Android 15 el "predictive back" viene
     * activado para las apps que apuntan a 35 o más, y con él el sistema YA NO llama a
     * onBackPressed() ni a onKeyDown(KEYCODE_BACK). Con el manejo viejo, en esos teléfonos el atrás
     * habría cerrado la app en vez de retroceder en el WebView — a media gestión.
     *
     * Además pide confirmación antes de salir: el atrás desde la pantalla inicial cerraba la app de
     * una, y en un formulario largo eso es perder el trabajo por un toque de más.
     */
    private var ultimoIntentoSalir = 0L

    private val backCallback = object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
            if (webView.canGoBack()) {
                webView.goBack()
                return
            }
            val ahora = System.currentTimeMillis()
            if (ahora - ultimoIntentoSalir < 2500) {
                finish()
            } else {
                ultimoIntentoSalir = ahora
                Toast.makeText(this@MainActivity, R.string.confirmar_salida, Toast.LENGTH_SHORT).show()
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView(target: WebView) {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)

        with(target.settings) {
            // JavaScript y almacenamiento
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true

            // Cache
            cacheMode = WebSettings.LOAD_DEFAULT

            // Acceso a archivos: allowContentAccess sí (content:// de la cámara y el selector),
            // allowFileAccess NO. La app nunca carga URLs file://, así que dejarlo en true solo
            // ampliaba lo que una página podría intentar leer del teléfono.
            allowContentAccess = true
            allowFileAccess = false

            // Geolocalización
            setGeolocationEnabled(true)

            // Todo el sitio es HTTPS y el manifiesto ya bloquea cleartext, así que el modo
            // compatible no aportaba nada: NEVER_ALLOW deja explícito que no hay contenido mixto.
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW

            // Media
            mediaPlaybackRequiresUserGesture = false

            // User Agent: la versión sale de BuildConfig, no escrita a mano, para que el servidor
            // pueda saber qué build de APK tiene cada ejecutor.
            userAgentString = "$userAgentString VisibilityWrapper/${BuildConfig.VERSION_NAME}"
        }

        // Safe Browsing para protección contra sitios maliciosos
        if (WebViewFeature.isFeatureSupported(WebViewFeature.SAFE_BROWSING_ENABLE)) {
            WebSettingsCompat.setSafeBrowsingEnabled(target.settings, true)
            Log.d(TAG, "Safe Browsing habilitado")
        }

        // El oscurecimiento automático invertía la PWA (Bootstrap con colores propios) en los
        // teléfonos en modo oscuro, con resultados distintos según la versión de WebView. La app
        // se ve igual en todos hasta que su CSS maneje el tema oscuro por su cuenta.
        if (WebViewFeature.isFeatureSupported(WebViewFeature.FORCE_DARK)) {
            @Suppress("DEPRECATION")
            WebSettingsCompat.setForceDark(target.settings, WebSettingsCompat.FORCE_DARK_OFF)
        }

        target.webViewClient = object : WebViewClient() {
            /**
             * Lista blanca: dentro del WebView solo se carga visibility.cl.
             *
             * Todo lo demás sale al navegador del teléfono. Antes cualquier http(s) se cargaba
             * adentro, o sea que un enlace externo abría un sitio ajeno en la app corporativa, con
             * la sesión de visibility.cl en el mismo WebView y los puentes JS al alcance.
             *
             * Si no hay app capaz de abrirlo (o el intent falla), se deja pasar antes que dejar al
             * ejecutor con un enlace muerto.
             */
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val uri = request?.url ?: return false
                val scheme = uri.scheme?.lowercase(Locale.ROOT)
                val isHttpOrHttps = scheme == "http" || scheme == "https"

                // visibility.cl se queda adentro; es la app.
                if (isHttpOrHttps && isTrustedUrl(uri.toString())) return false

                return abrirFuera(uri)
            }

            /** Abre la URI en la app que corresponda. true = manejado, false = cárgala adentro. */
            private fun abrirFuera(uri: Uri): Boolean {
                return try {
                    val intent = Intent(Intent.ACTION_VIEW, uri).apply {
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    }
                    if (intent.resolveActivity(packageManager) != null) {
                        startActivity(intent)
                        true
                    } else {
                        Log.w(TAG, "Sin app para abrir $uri")
                        false
                    }
                } catch (e: Exception) {
                    Log.w(TAG, "No se pudo abrir $uri afuera: ${e.message}")
                    false
                }
            }

            override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
                super.onPageStarted(view, url, favicon)
                if (!url.isNullOrBlank()) lastLoadedUrl = url
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                if (!url.isNullOrBlank()) lastLoadedUrl = url
                // Persistir cookies después de cada carga de página
                CookieManager.getInstance().flush()
                hideErrorView()
            }

            /**
             * Sin esto, quedarse sin señal mostraba la pantalla de error del navegador, en inglés.
             * Solo interesa el frame principal: que falle un ícono o una foto no es motivo para
             * tapar la app entera.
             */
            override fun onReceivedError(
                view: WebView?,
                request: WebResourceRequest?,
                error: WebResourceError?
            ) {
                super.onReceivedError(view, request, error)
                if (request?.isForMainFrame == true) {
                    Log.w(TAG, "Error cargando ${request.url}")
                    showErrorView()
                }
            }

            /**
             * El proceso del renderer murió (casi siempre por memoria: esta app mantiene fotos,
             * mapas e IndexedDB abiertos). Si no se maneja acá, Android mata la Activity completa y
             * el ejecutor ve "la aplicación se detuvo". Devolver true significa "ya me hice cargo":
             * se descarta el WebView muerto, se crea uno nuevo y se vuelve a la última página.
             */
            override fun onRenderProcessGone(view: WebView?, detail: RenderProcessGoneDetail?): Boolean {
                val crashed = detail?.didCrash() == true
                Log.e(TAG, "Renderer caído (didCrash=$crashed). Recreando WebView.")
                recreateWebView(view)
                return true
            }
        }

        target.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView?, newProgress: Int) {
                super.onProgressChanged(view, newProgress)
                binding.progressBar.progress = newProgress
                binding.progressBar.isVisible = newProgress in 1..99
            }

            override fun onGeolocationPermissionsShowPrompt(origin: String?, callback: GeolocationPermissions.Callback?) {
                // El GPS solo se entrega a visibility.cl: cualquier otra página que llegue a
                // cargarse dentro del WebView no tiene por qué saber dónde está el ejecutor.
                if (!isTrustedUrl(origin)) {
                    Log.w(TAG, "Geolocalización negada a origen no confiable: $origin")
                    callback?.invoke(origin, false, false)
                    return
                }
                geoOrigin = origin
                geoCallback = callback
                if (hasAllPermissions(locationPermissions)) {
                    callback?.invoke(origin, true, false)
                } else {
                    locationPermissionLauncher.launch(locationPermissions)
                }
            }

            override fun onShowFileChooser(
                webView: WebView?,
                filePathCallback: ValueCallback<Array<Uri>>?,
                fileChooserParams: FileChooserParams?
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallback
                pendingFileChooserParams = fileChooserParams

                // Solo se pide lo que falta. La galería no bloquea: el selector del sistema
                // entrega la imagen aunque READ_MEDIA_IMAGES esté denegado.
                val faltantes = (cameraPermissions + galleryPermissions)
                    .filter { ContextCompat.checkSelfPermission(this@MainActivity, it) != PackageManager.PERMISSION_GRANTED }

                if (faltantes.isEmpty()) {
                    launchFilePicker(fileChooserParams, allowCamera = true)
                } else {
                    filePermissionsLauncher.launch(faltantes.toTypedArray())
                }
                return true
            }
        }

        target.addJavascriptInterface(VoiceInterface(), "AndroidVoice")
        target.addJavascriptInterface(NavInterface(), "AndroidNav")
        target.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            handleDownloadRequest(url, userAgent, contentDisposition, mimeType)
        }
    }

    /**
     * Sustituye la instancia muerta del WebView por una nueva, configurada igual, y repone la
     * última página. Se agrega en el índice 0 para que la barra de progreso y la pantalla de
     * error sigan por encima.
     */
    private fun recreateWebView(dead: WebView?) {
        val container: ViewGroup = binding.rootContainer
        val target = lastLoadedUrl.takeIf { it.isNotBlank() } ?: INITIAL_URL

        dead?.let {
            container.removeView(it)
            it.destroy()
        }

        val fresh = WebView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT
            )
        }
        container.addView(fresh, 0)
        webView = fresh

        setupCookieManager()
        setupWebView(fresh)
        fresh.loadUrl(target)

        Toast.makeText(this, R.string.webview_recovered, Toast.LENGTH_LONG).show()
    }

    /** ¿La URL pertenece a visibility.cl? Los puentes nativos solo responden a ese host. */
    private fun isTrustedUrl(url: String?): Boolean {
        val host = try {
            Uri.parse(url ?: return false).host?.lowercase(Locale.ROOT)
        } catch (e: Exception) {
            null
        } ?: return false
        return host == TRUSTED_HOST || host.endsWith(".$TRUSTED_HOST")
    }

    /**
     * Registrar callback de conectividad cuando la app vuelve a foreground.
     */
    override fun onResume() {
        super.onResume()
        registerConnectivityCallback()
        Log.d(TAG, "ConnectivityCallback registrado en onResume")
    }

    /**
     * Persistir cookies y desregistrar callback cuando la app va a background.
     */
    override fun onPause() {
        super.onPause()
        unregisterConnectivityCallback()
        CookieManager.getInstance().flush()
        Log.d(TAG, "Cookies persistidas y ConnectivityCallback desregistrado en onPause")
    }

    /**
     * Persistir cookies cuando la app se detiene.
     */
    override fun onStop() {
        super.onStop()
        CookieManager.getInstance().flush()
        Log.d(TAG, "Cookies persistidas en onStop")
    }

    override fun onDestroy() {
        tts?.stop()
        tts?.shutdown()
        tts = null
        ttsReady = false
        super.onDestroy()
    }

    /**
     * Guardar estado del WebView para restauración.
     */
    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    private fun hasAllPermissions(permissions: Array<String>): Boolean =
        permissions.all { ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED }

    /**
     * Registra el callback de conectividad para monitorear cambios de red.
     * Esto permite disparar eventos online/offline al WebView.
     */
    private fun registerConnectivityCallback() {
        try {
            val networkRequest = NetworkRequest.Builder()
                .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                .addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
                .addTransportType(NetworkCapabilities.TRANSPORT_CELLULAR)
                .addTransportType(NetworkCapabilities.TRANSPORT_ETHERNET)
                .build()

            connectivityManager?.registerNetworkCallback(networkRequest, connectivityCallback)
        } catch (e: Exception) {
            Log.e(TAG, "Error registrando ConnectivityCallback: ${e.message}")
        }
    }

    /**
     * Desregistra el callback de conectividad.
     */
    private fun unregisterConnectivityCallback() {
        try {
            connectivityManager?.unregisterNetworkCallback(connectivityCallback)
        } catch (e: Exception) {
            Log.e(TAG, "Error desregistrando ConnectivityCallback: ${e.message}")
        }
    }

    /**
     * Abre el selector de archivos. Si no hay permiso de cámara se abre solo la galería, en vez
     * de cancelar el adjunto completo como hacía antes.
     */
    private fun launchFilePicker(
        fileChooserParams: WebChromeClient.FileChooserParams?,
        allowCamera: Boolean
    ) {
        val captureIntent = if (allowCamera) createCameraIntent() else null
        val mimeType = fileChooserParams?.acceptTypes?.firstOrNull { it.isNotBlank() } ?: MIME_TYPE_IMAGES
        val allowMultiple = fileChooserParams?.mode == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE

        val contentSelectionIntent = Intent(Intent.ACTION_GET_CONTENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = mimeType
            putExtra(Intent.EXTRA_ALLOW_MULTIPLE, allowMultiple)
        }

        val initialIntents = captureIntent?.let { arrayOf(it) } ?: emptyArray()
        val chooserIntent = Intent(Intent.ACTION_CHOOSER).apply {
            putExtra(Intent.EXTRA_INTENT, contentSelectionIntent)
            putExtra(Intent.EXTRA_TITLE, getString(R.string.file_chooser_title))
            if (initialIntents.isNotEmpty()) {
                putExtra(Intent.EXTRA_INITIAL_INTENTS, initialIntents)
            }
        }

        fileChooserLauncher.launch(chooserIntent)
    }

    private fun createCameraIntent(): Intent? {
        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
        if (intent.resolveActivity(packageManager) == null) return null

        val photoFile = try {
            createImageFile()
        } catch (ex: IOException) {
            null
        }

        return photoFile?.let { file ->
            cameraPhotoUri = FileProvider.getUriForFile(
                this,
                "${BuildConfig.APPLICATION_ID}.fileprovider",
                file
            )
            intent.putExtra(MediaStore.EXTRA_OUTPUT, cameraPhotoUri)
            intent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
            intent
        }
    }

    @Throws(IOException::class)
    private fun createImageFile(): File {
        val timeStamp: String = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
        val storageDir: File = externalCacheDir ?: cacheDir
        return File.createTempFile("JPEG_${timeStamp}", ".jpg", storageDir)
    }

    private fun handleFileChooserResult(resultCode: Int, intent: Intent?) {
        val results: Array<Uri>? = if (resultCode == Activity.RESULT_OK) {
            val clipData = intent?.clipData
            val dataUri = intent?.data
            when {
                clipData != null -> Array(clipData.itemCount) { index -> clipData.getItemAt(index).uri }
                dataUri != null -> arrayOf(dataUri)
                cameraPhotoUri != null -> arrayOf(cameraPhotoUri!!)
                else -> null
            }
        } else {
            null
        }

        filePathCallback?.onReceiveValue(results)
        filePathCallback = null
        cameraPhotoUri = null
        pendingFileChooserParams = null
    }

    private fun handleDownloadRequest(
        url: String?,
        userAgent: String?,
        contentDisposition: String?,
        mimeType: String?
    ) {
        if (url.isNullOrBlank()) return
        val uri = Uri.parse(url)
        val scheme = uri.scheme?.lowercase(Locale.ROOT)
        if (scheme != "http" && scheme != "https") {
            Toast.makeText(this, R.string.download_not_supported, Toast.LENGTH_SHORT).show()
            return
        }

        val request = DownloadRequestData(
            url = url,
            userAgent = userAgent.orEmpty(),
            contentDisposition = contentDisposition.orEmpty(),
            mimeType = mimeType.orEmpty()
        )

        if (requiresLegacyWritePermission() && !hasWriteStoragePermission()) {
            pendingDownload = request
            writeStoragePermissionLauncher.launch(Manifest.permission.WRITE_EXTERNAL_STORAGE)
            return
        }

        enqueueDownload(request)
    }

    private fun enqueueDownload(request: DownloadRequestData) {
        val filename = URLUtil.guessFileName(
            request.url,
            request.contentDisposition,
            request.mimeType
        )

        val downloadRequest = DownloadManager.Request(Uri.parse(request.url)).apply {
            setMimeType(request.mimeType)
            addRequestHeader("User-Agent", request.userAgent)
            val cookie = CookieManager.getInstance().getCookie(request.url)
            if (!cookie.isNullOrBlank()) {
                addRequestHeader("Cookie", cookie)
            }
            setTitle(filename)
            setDescription(getString(R.string.file_chooser_title))
            setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename)
            @Suppress("DEPRECATION")
            allowScanningByMediaScanner()
        }

        val downloadManager = getSystemService(DownloadManager::class.java)
        downloadManager.enqueue(downloadRequest)
    }

    private fun requiresLegacyWritePermission(): Boolean = Build.VERSION.SDK_INT <= Build.VERSION_CODES.P

    private fun hasWriteStoragePermission(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED

    private data class DownloadRequestData(
        val url: String,
        val userAgent: String,
        val contentDisposition: String,
        val mimeType: String
    )

    /**
     * Voz nativa para la navegación. Lo consume voice_controller.js, que prefiere este puente
     * sobre speechSynthesis cuando existe.
     */
    private inner class VoiceInterface {
        /**
         * Red de seguridad: si alguien pide voz sin haber pasado por keepAwake (por ejemplo el
         * botón "Voz" del panel de ruta), se inicializa acá. Devuelve false esa primera vez —
         * voice_controller.js cae a speechSynthesis, que no suena— y a partir de la siguiente
         * frase, segundos después, ya responde el motor nativo.
         */
        @JavascriptInterface
        fun isAvailable(): Boolean {
            if (!isTrustedUrl(lastLoadedUrl)) return false
            if (tts == null) runOnUiThread { ensureTextToSpeech() }
            return ttsReady
        }

        @JavascriptInterface
        fun speak(text: String?, flush: Boolean) {
            if (!ttsReady || !isTrustedUrl(lastLoadedUrl)) return
            val phrase = text?.trim()?.takeIf { it.isNotEmpty() } ?: return
            val mode = if (flush) TextToSpeech.QUEUE_FLUSH else TextToSpeech.QUEUE_ADD
            tts?.speak(phrase, mode, null, "visibility-nav-${System.currentTimeMillis()}")
        }

        @JavascriptInterface
        fun stop() {
            tts?.stop()
        }
    }

    /**
     * Utilidades de navegación. keepAwake se enciende al iniciar la ruta y se apaga al terminar:
     * con la pantalla apagada el WebView se congela y deja de seguir el GPS.
     */
    private inner class NavInterface {
        @JavascriptInterface
        fun keepAwake(on: Boolean) {
            if (!isTrustedUrl(lastLoadedUrl)) return
            runOnUiThread {
                if (on) {
                    // Arranca la navegación: es el momento de levantar el motor de voz, no antes.
                    ensureTextToSpeech()
                    window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    Log.d(TAG, "Pantalla forzada encendida (navegación activa)")
                } else {
                    window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
                    Log.d(TAG, "Pantalla liberada")
                }
            }
        }

        /** Versión del APK, para mostrarla en el diagnóstico de la app. */
        @JavascriptInterface
        fun appVersion(): String = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"
    }
}

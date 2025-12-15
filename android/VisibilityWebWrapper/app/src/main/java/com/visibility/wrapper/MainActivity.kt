package com.visibility.wrapper

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.util.Log
import android.view.KeyEvent
import android.view.WindowManager
import android.webkit.GeolocationPermissions
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.ActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.webkit.ServiceWorkerClient
import androidx.webkit.ServiceWorkerController
import androidx.webkit.ServiceWorkerWebSettingsCompat
import androidx.webkit.WebResourceErrorCompat
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewClientCompat
import androidx.webkit.WebViewFeature
import com.visibility.wrapper.BuildConfig
import com.visibility.wrapper.databinding.ActivityMainBinding
import java.io.File
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding

    companion object {
        /** URL base que se cargará siempre al iniciar la app. Cambia este valor para apuntar a otro entorno. */
        const val INITIAL_URL = "https://visibility.cl/visibility2/app/login.php"
        private const val MIME_TYPE_IMAGES = "image/*"
        private const val MAPS_PACKAGE = "com.google.android.apps.maps"
        private const val TAG = "VisibilityWrapper"
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
    private var pageStartTime: Long = 0

    private val locationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grantResults ->
            val granted = grantResults.values.all { it }
            geoCallback?.invoke(geoOrigin, granted, false)
            geoCallback = null
            geoOrigin = null
        }

    private val filePermissionsLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grantResults ->
            val granted = grantResults.values.all { it }
            if (granted) {
                pendingFileChooserParams?.let { launchFilePicker(it) }
            } else {
                filePathCallback?.onReceiveValue(null)
                filePathCallback = null
                pendingFileChooserParams = null
            }
        }

    private val fileChooserLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result: ActivityResult ->
            handleFileChooserResult(result.resultCode, result.data)
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setupWebView()
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        return if (keyCode == KeyEvent.KEYCODE_BACK && binding.webView.canGoBack()) {
            binding.webView.goBack()
            true
        } else {
            super.onKeyDown(keyCode, event)
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)

        with(binding.webView.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            cacheMode = WebSettings.LOAD_DEFAULT
            allowContentAccess = true
            allowFileAccess = true
            setGeolocationEnabled(true)
            mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
            mediaPlaybackRequiresUserGesture = false
            userAgentString = userAgentString + " VisibilityWrapper"
            javaScriptCanOpenWindowsAutomatically = true
            setSupportMultipleWindows(true)
            setAppCacheEnabled(true)
            builtInZoomControls = true
            displayZoomControls = false
        }

        if (WebViewFeature.isFeatureSupported(WebViewFeature.FORCE_DARK)) {
            WebSettingsCompat.setForceDark(binding.webView.settings, WebSettingsCompat.FORCE_DARK_OFF)
        }

        enableServiceWorkers()

        binding.webView.webViewClient = object : WebViewClientCompat() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                super.onPageStarted(view, url, favicon)
                pageStartTime = System.currentTimeMillis()
                Log.d(TAG, "Page started: ${'$'}url")
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                val duration = if (pageStartTime > 0) System.currentTimeMillis() - pageStartTime else -1
                Log.d(TAG, "Page finished: ${'$'}url in ${'$'}duration ms")
            }

            override fun onReceivedError(
                view: WebView,
                request: WebResourceRequest,
                error: WebResourceErrorCompat
            ) {
                super.onReceivedError(view, request, error)
                Log.e(TAG, "Web error ${'$'}{error.errorCode} ${'$'}{error.description} for ${'$'}{request.url}")
            }

            override fun onReceivedHttpError(
                view: WebView,
                request: WebResourceRequest,
                errorResponse: WebResourceResponse
            ) {
                super.onReceivedHttpError(view, request, errorResponse)
                Log.e(TAG, "HTTP error ${'$'}{errorResponse.statusCode} for ${'$'}{request.url}")
            }

            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                val url = request?.url?.toString() ?: return false
                if (request.isForMainFrame) {
                    return handleCustomUrl(url)
                }
                return false
            }
        }

        binding.webView.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(origin: String?, callback: GeolocationPermissions.Callback?) {
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

                val permissionsToRequest = buildList {
                    addAll(galleryPermissions)
                    addAll(cameraPermissions)
                }.toTypedArray()

                return if (hasAllPermissions(permissionsToRequest)) {
                    launchFilePicker(fileChooserParams)
                    true
                } else {
                    filePermissionsLauncher.launch(permissionsToRequest)
                    true
                }
            }

            override fun onCreateWindow(
                view: WebView?,
                isDialog: Boolean,
                isUserGesture: Boolean,
                resultMsg: android.os.Message?
            ): Boolean {
                val transport = resultMsg?.obj as? WebView.WebViewTransport
                transport?.webView = binding.webView
                resultMsg?.sendToTarget()
                return true
            }

            override fun onConsoleMessage(consoleMessage: android.webkit.ConsoleMessage?): Boolean {
                Log.d(
                    TAG,
                    "Console: ${'$'}{consoleMessage?.message()} -- ${'$'}{consoleMessage?.sourceId()}:${'$'}{consoleMessage?.lineNumber()}"
                )
                return super.onConsoleMessage(consoleMessage)
            }
        }

        binding.webView.setDownloadListener { url, userAgent, contentDisposition, mimetype, _ ->
            val filename = android.webkit.URLUtil.guessFileName(url, contentDisposition, mimetype)
            val request = android.app.DownloadManager.Request(Uri.parse(url)).apply {
                addRequestHeader("User-Agent", userAgent)
                setMimeType(mimetype)
                setNotificationVisibility(android.app.DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename)
                allowScanningByMediaScanner()
            }
            val dm = getSystemService(DOWNLOAD_SERVICE) as android.app.DownloadManager
            try {
                dm.enqueue(request)
                Log.d(TAG, "Download enqueued: ${'$'}url")
            } catch (e: Exception) {
                Log.e(TAG, "Download failed", e)
                openExternalUrl(url)
            }
        }

        binding.webView.loadUrl(INITIAL_URL)
    }

    private fun hasAllPermissions(permissions: Array<String>): Boolean =
        permissions.all { ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED }

    private fun launchFilePicker(fileChooserParams: WebChromeClient.FileChooserParams?) {
        val captureIntent = createCameraIntent()
        val mimeType = fileChooserParams?.acceptTypes?.firstOrNull { it.isNotBlank() } ?: MIME_TYPE_IMAGES
        val allowMultiple = fileChooserParams?.mode == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE

        if (fileChooserParams?.isCaptureEnabled == true && captureIntent != null) {
            fileChooserLauncher.launch(captureIntent)
            return
        }

        val contentSelectionIntent = Intent(Intent.ACTION_GET_CONTENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = mimeType
            putExtra(Intent.EXTRA_ALLOW_MULTIPLE, allowMultiple)
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            contentSelectionIntent.putExtra(MediaStore.EXTRA_PICK_IMAGES_MAX, if (allowMultiple) 10 else 1)
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
        return File.createTempFile("JPEG_${'$'}timeStamp", ".jpg", storageDir)
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

    private fun handleCustomUrl(url: String): Boolean {
        val lowerUrl = url.lowercase(Locale.ROOT)
        return when {
            lowerUrl.startsWith("intent:") -> {
                handleIntentUrl(url)
                true
            }

            lowerUrl.startsWith("geo:") || lowerUrl.startsWith("google.navigation:") -> {
                openExternalIntent(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                true
            }

            lowerUrl.startsWith("market:") -> {
                openExternalIntent(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                true
            }

            lowerUrl.startsWith("tel:") || lowerUrl.startsWith("mailto:") || lowerUrl.startsWith("whatsapp:") ||
                lowerUrl.startsWith("tg:") || lowerUrl.startsWith("sms:") -> {
                openExternalIntent(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                true
            }

            isGoogleMapsUrl(url) -> {
                openMapUrl(url)
                true
            }

            else -> false
        }
    }

    private fun isGoogleMapsUrl(url: String): Boolean {
        val uri = Uri.parse(url)
        val host = uri.host?.lowercase(Locale.ROOT).orEmpty()
        val path = uri.path.orEmpty()
        return host.contains("maps.google.") ||
                (host.endsWith("google.com") && path.startsWith("/maps")) ||
                host == "maps.app.goo.gl" ||
                (host == "goo.gl" && path.startsWith("/maps")) ||
                (host == "goo.gle" && path.startsWith("/maps"))
    }

    private fun openMapUrl(url: String) {
        val uri = Uri.parse(url)
        val mapIntent = Intent(Intent.ACTION_VIEW, uri).apply {
            `package` = MAPS_PACKAGE
        }
        if (isPackageInstalled(MAPS_PACKAGE)) {
            try {
                startActivity(mapIntent)
            } catch (ex: ActivityNotFoundException) {
                Log.w(TAG, "Maps activity not found, falling back", ex)
                openExternalUrl(url)
            }
        } else {
            val browserUri = Uri.parse(url)
            val fallback = Intent(Intent.ACTION_VIEW, browserUri)
            try {
                startActivity(fallback)
            } catch (ex: Exception) {
                openPlayStore(MAPS_PACKAGE)
            }
        }
    }

    private fun handleIntentUrl(url: String) {
        try {
            val intent = Intent.parseUri(url, Intent.URI_INTENT_SCHEME)
            val packageName = intent.`package`
            if (packageName != null) {
                if (isPackageInstalled(packageName)) {
                    startActivity(intent)
                    return
                }
                openPlayStore(packageName)
                return
            }

            val fallback = intent.getStringExtra("browser_fallback_url") ?: intent.dataString
            if (!fallback.isNullOrBlank()) {
                binding.webView.loadUrl(fallback)
                return
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to handle intent url", e)
        }

        val httpsUrl = url
            .replaceFirst("^intent://".toRegex(), "https://")
            .replaceFirst("#Intent;.*$".toRegex(), "")
        if (httpsUrl.isNotBlank()) {
            binding.webView.loadUrl(httpsUrl)
        }
    }

    private fun openExternalIntent(intent: Intent) {
        try {
            startActivity(intent)
        } catch (ex: ActivityNotFoundException) {
            Log.w(TAG, "No activity found for intent ${'$'}intent")
            intent.`package`?.let { openPlayStore(it) }
        }
    }

    private fun openPlayStore(packageName: String) {
        val marketIntent = Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=${'$'}packageName"))
        try {
            startActivity(marketIntent)
        } catch (ex: ActivityNotFoundException) {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=${'$'}packageName")))
        }
    }

    private fun openExternalUrl(url: String) {
        openExternalIntent(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    }

    private fun isPackageInstalled(packageName: String): Boolean = try {
        packageManager.getPackageInfo(packageName, 0)
        true
    } catch (ex: PackageManager.NameNotFoundException) {
        false
    }

    @SuppressLint("NewApi")
    private fun enableServiceWorkers() {
        if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_BASIC_USAGE)) {
            val controller = ServiceWorkerController.getInstance()
            controller.setServiceWorkerClient(object : ServiceWorkerClient() {
                override fun shouldInterceptRequest(request: WebResourceRequest): WebResourceResponse? {
                    Log.d(TAG, "SW request: ${'$'}{request.url}")
                    return null
                }
            })
            val settings: ServiceWorkerWebSettingsCompat = controller.serviceWorkerWebSettings
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_BLOCK_NETWORK_LOADS)) {
                settings.setBlockNetworkLoads(false)
            }
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_CACHE_MODE)) {
                settings.setCacheMode(WebSettings.LOAD_DEFAULT)
            }
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_CONTENT_ACCESS)) {
                settings.setAllowContentAccess(true)
            }
            if (WebViewFeature.isFeatureSupported(WebViewFeature.SERVICE_WORKER_FILE_ACCESS)) {
                settings.setAllowFileAccess(true)
            }
        }
    }
}
package com.visibility.wrapper

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.MediaStore
import android.view.KeyEvent
import android.webkit.GeolocationPermissions
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.ActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
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
        }

        binding.webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
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
        }

        binding.webView.loadUrl(INITIAL_URL)
    }

    private fun hasAllPermissions(permissions: Array<String>): Boolean =
        permissions.all { ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED }

    private fun launchFilePicker(fileChooserParams: WebChromeClient.FileChooserParams?) {
        val captureIntent = createCameraIntent()
        val contentSelectionIntent = Intent(Intent.ACTION_GET_CONTENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = MIME_TYPE_IMAGES
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
            val dataUri = intent?.data
            when {
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
}

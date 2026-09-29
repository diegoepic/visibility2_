import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

/*
 * Firma de release.
 *
 * La llave y sus contraseñas NO viven en el proyecto (estaban en la raíz, dentro de OneDrive
 * y sin .gitignore). Se buscan en este orden:
 *   1. la ruta de la variable de entorno VISIBILITY_KEYSTORE_PROPERTIES
 *   2. ~/.keys/visibility/keystore.properties   <- ubicación actual
 *   3. keystore.properties en la raíz del proyecto (compatibilidad; ya no debería existir)
 *
 * Si no aparece en ninguna, el release se arma SIN firmar en vez de fallar: así el proyecto
 * sigue compilando en un equipo que no tenga la llave (por ejemplo para revisar código).
 */
val keystorePropertiesFile: File? = listOfNotNull(
    System.getenv("VISIBILITY_KEYSTORE_PROPERTIES")?.takeIf { it.isNotBlank() }?.let { file(it) },
    File(System.getProperty("user.home"), ".keys/visibility/keystore.properties"),
    rootProject.file("keystore.properties")
).firstOrNull { it.exists() }

val keystoreProperties: Properties? = keystorePropertiesFile?.let { propsFile ->
    Properties().apply { propsFile.inputStream().use { load(it) } }
}

android {
    namespace = "com.visibility.wrapper"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.visibility.wrapper"
        minSdk = 26
        // targetSdk 35 = Android 15. Obliga a manejar los insets (lo hace MainActivity), porque
        // desde esta versión la ventana es borde a borde y el WebView se dibujaría debajo de la
        // barra de estado. El siguiente salto a 36 ya no necesita más cambios de layout.
        targetSdk = 35
        // SUBIR EN CADA ENTREGA. versionCode es lo que Android compara para actualizar;
        // versionName es lo que viaja en el User-Agent y queda en los logs del servidor.
        versionCode = 4
        versionName = "1.3"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    signingConfigs {
        if (keystoreProperties != null) {
            create("release") {
                val storeFilePath = keystoreProperties.getProperty("storeFile")
                // Una ruta relativa se resuelve contra la carpeta del keystore.properties,
                // no contra el proyecto: así el archivo se puede mover entero sin editarlo.
                storeFile = File(storeFilePath).let { candidate ->
                    if (candidate.isAbsolute) candidate
                    else File(keystorePropertiesFile!!.parentFile, storeFilePath)
                }
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            signingConfig = signingConfigs.findByName("release")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    // Compose estaba activado y sin usar: la app es una AppCompatActivity con ViewBinding sobre un
    // XML. Se fue el compilador de Compose, su BOM y cinco dependencias; el APK de release bajó de
    // 8,9 MB a 4,7 MB (medido, no estimado).
    buildFeatures {
        viewBinding = true
        buildConfig = true
    }
}

// Aviso en el log del build: es la diferencia entre entregar un APK firmado y uno que los
// teléfonos van a rechazar como actualización.
tasks.register("verificarFirma") {
    doLast {
        if (keystorePropertiesFile == null) {
            logger.warn("[firma] Sin keystore.properties: el release saldrá SIN FIRMAR.")
        } else {
            logger.lifecycle("[firma] Usando ${keystorePropertiesFile.absolutePath}")
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.6.1")
    // material: el tema Theme.Material3.DayNight sale de acá.
    implementation("com.google.android.material:material:1.12.0")
    // activity-ktx: onBackPressedDispatcher para el botón atrás.
    implementation("androidx.activity:activity-ktx:1.9.1")
    // webkit: WebViewFeature / WebSettingsCompat (service workers, safe browsing, force dark).
    implementation("androidx.webkit:webkit:1.11.0")

    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.5.1")
}

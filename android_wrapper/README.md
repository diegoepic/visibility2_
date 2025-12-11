# APK wrapper (WebView + permisos) para Visibility 2

Ejemplo completo en Kotlin para abrir la web de **Visibility 2** dentro de un WebView a pantalla completa, incluyendo permisos de geolocalización, cámara y galería (input file).

## Pasos para crear el proyecto en Android Studio
1. **Crear proyecto nuevo**: `File` → `New` → `New Project` → plantilla **Empty Activity**.
2. **Nombre de la app**: por ejemplo `VisibilityWebWrapper` (package `com.visibility.wrapper`). **Lenguaje**: Kotlin. **Minimum SDK**: API 24 o superior.
3. Cierra Android Studio y reemplaza los archivos generados con los de este directorio (`android_wrapper`). Mantén los archivos del Gradle Wrapper que creó el asistente.
4. Verifica que `settings.gradle.kts` y `build.gradle.kts` coincidan con los incluidos aquí (repositorios `google()` y `mavenCentral()`, plugin `com.android.application` 8.5.1 y Kotlin 1.9.24).
5. Reabre el proyecto y sincroniza Gradle. Compila con **Build → Build Bundle(s) / APK(s) → Build APK(s)**.

## Archivos y contenido principal
- `settings.gradle.kts`: repositorios y nombre del proyecto.
- `build.gradle.kts`: plugins de Android y Kotlin.
- `app/build.gradle.kts`: `compileSdk/targetSdk` 34, `minSdk` 24, dependencias y `viewBinding` activo.
- `app/src/main/AndroidManifest.xml`: permisos de red, ubicación, cámara y lectura de imágenes; definición de `FileProvider`; `MainActivity` exportada.
- `app/src/main/res/layout/activity_main.xml`: WebView a pantalla completa.
- `app/src/main/res/xml/file_paths.xml`: rutas permitidas para `FileProvider`.
- `app/src/main/java/com/visibility/wrapper/MainActivity.kt`: configuración de WebView, geolocalización, file chooser (cámara/galería) y manejo de permisos en runtime.
- `app/src/main/res/values/*.xml`: temas, colores y textos.

## Explicación breve de permisos y flujos
- **Geolocalización**: cuando la web llama a `navigator.geolocation.*`, `onGeolocationPermissionsShowPrompt` solicita `ACCESS_FINE_LOCATION` y `ACCESS_COARSE_LOCATION` mediante `ActivityResultContracts.RequestMultiplePermissions`. Si el usuario acepta, se invoca `callback(origin, true, false)` y el WebView recibe la ubicación. Si no, el callback retorna `false` y la web seguirá sin acceso.
- **Cámara y galería (`<input type="file">`)**: `onShowFileChooser` junta los permisos de cámara y galería (Android 13+: `READ_MEDIA_IMAGES`, anteriores: `READ_EXTERNAL_STORAGE`). Si están concedidos, abre un chooser con dos opciones: tomar foto con `MediaStore.ACTION_IMAGE_CAPTURE` usando `FileProvider` (salida en cache) o elegir una imagen con `ACTION_GET_CONTENT`. El resultado se entrega a la web vía `ValueCallback<Array<Uri>>` en `handleFileChooserResult`.
- **Cambiar la URL inicial**: edita la constante `INITIAL_URL` en `MainActivity.kt` para apuntar a staging u otra ruta. También puedes llamar a `webView.loadUrl(...)` con otra URL cuando lo necesites.

Con estos archivos se genera un APK que abre siempre tu web de producción, permite iniciar sesión y usar cámara/galería/geolocalización dentro del WebView desde Android 8 (API 24) en adelante.

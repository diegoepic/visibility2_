# Android sample (camera + geolocalización)

Este directorio contiene todos los archivos que puedes copiar/pegar en Android Studio para crear un APK que solicite permisos de cámara y ubicación, tome una foto con `FileProvider` y muestre la última localización conocida usando `FusedLocationProviderClient`.

## Pasos para crear el proyecto en Android Studio
1. **Crear proyecto nuevo**: `File` → `New` → `New Project` → plantilla **Empty Activity**.
2. **Nombre de la app**: `VisibilityApp` (package `com.example.visibilityapp`).
3. **Lenguaje**: Kotlin. **Minimum SDK**: API 24 (Android 7.0) o superior.
4. Desmarca **Use legacy support libraries** si aparece. Finaliza el asistente.
5. Cierra Android Studio y reemplaza los archivos generados por los de este directorio (conserva los archivos de Gradle Wrapper que el asistente ya creó). También puedes copiar solo los archivos dentro de `app/src/main` y los `build.gradle.kts` indicados.
6. Verifica que `settings.gradle.kts` incluya los repositorios `google()` y `mavenCentral()` en los bloques `pluginManagement` y `dependencyResolutionManagement` (este ejemplo ya los trae listos). Con eso se descarga correctamente el plugin `com.android.application` desde Google Maven, evitando errores de sincronización como “Plugin ... version '8.2.2' was not found”.
7. Reabre el proyecto. Android Studio sincronizará Gradle automáticamente y quedará listo para compilar el APK (`Build` → `Build Bundle(s) / APK(s)` → `Build APK(s)`).

## Archivos incluidos
- `settings.gradle.kts`: nombre del proyecto e inclusión del módulo app.
- `build.gradle.kts` (raíz): plugins Android/Kotlin.
- `app/build.gradle.kts`: configuración de la app (SDKs, dependencias y `viewBinding`).
- `AndroidManifest.xml`: permisos, `FileProvider` y actividad principal exportada.
- `res/layout/activity_main.xml`: interfaz simple con botones de cámara y ubicación, texto de resultado e imagen previa.
- `res/xml/file_paths.xml`: rutas para `FileProvider`.
- `MainActivity.kt`: lógica de permisos, toma de foto y lectura de la última ubicación conocida.
- `res/values/strings.xml`: textos de la interfaz.

Con estos archivos puedes compilar un APK listo para solicitar permisos de cámara y geolocalización, tomar fotos y mostrar la ubicación actual.

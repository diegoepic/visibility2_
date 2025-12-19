# Offline/Online Audit — Visibility 2

## Diagrama de flujo actual (alto nivel)

```
UI (index_pruebas.php / gestionarPruebas.php)
  ├─> JS inline + offline-queue.js
  │     ├─> AppDB (assets/js/db.js)  [IndexedDB queue]
  │     └─> JournalDB (assets/js/journal_db.js)
  │
  ├─> Endpoints
  │     ├─ ping.php / csrf_refresh.php
  │     ├─ create_visita_pruebas.php
  │     ├─ procesar_gestion_pruebas.php
  │     ├─ upload_material_foto_pruebas.php
  │     └─ api/sync_bundle.php
  │
  └─> DB: user_sessions, visita, fotoVisita, form_question_responses, request_log, client_devices, etc.
```

## Lo bueno (sí sirve)
- **Idempotencia** ya existe en `lib/idempotency.php` y se usa en `create_visita_pruebas.php`, `procesar_gestion_pruebas.php` y `upload_material_foto_pruebas.php`.
- **Queue offline** en `assets/js/offline-queue.js` ya encola y envía (maneja offline) y tiene eventos para UI.
- **Journal básico** en `assets/js/journal_db.js` y `assets/js/journal_ui.js` da visibilidad de lo enviado.
- **Service Worker** con cache y rutas sensibles ya excluidas en `app/sw.js`.

## Lo malo (directo)
- **Estados frágiles y sin bloqueo real**: `offline-queue.js` permite drains paralelos y no recupera jobs “running” eternos.
- **Error handling ambiguo**: HTML de login puede ser tratado como éxito, respuestas 401/403/419 no bloquean la cola; reintentos no distinguen errores terminales.
- **CSRF refresh débil**: se refresca siempre sin validar resultado; si expira en background no se recupera bien.
- **Observabilidad pobre**: el journal solo guarda “error string”, sin código, HTTP status ni próxima hora de reintento.
- **UI inconsistente**: en journal faltan acciones (cancelar/pausar/reintentar granular) y detalle técnico; badges incompletos.
- **Crecimiento de storage**: no hay política de retención en IndexedDB/journal ni limpieza de runtime cache.
- **Mapa / modo**: el estado guardado del modo (prog/reag) persiste incluso cuando no hay datos reagendados; el mapa queda “vacío” después de refresh.

## Failure modes reales (con síntomas)
- **Sesión expirada / HTML login**: fetch recibe HTML (200) y la cola lo marca como éxito o reintenta infinito.
  - Evidencia: `offline-queue.js` no detecta HTML ni valida Content-Type; `_session_guard.php` redirige HTML.
- **CSRF inválido al volver de background**: la cola reintenta con el mismo token y no bloquea.
  - Evidencia: `offline-queue.js` no bloquea ni marca `blocked_csrf`.
- **Jobs “running” eternos / doble drain**: si se corta la app en medio, queda `running` y no se recupera.
  - Evidencia: `offline-queue.js` no tiene recuperación ni mutex real.
- **Reintentos infinitos mal clasificados**: 401/403/419 se tratan como “retryable”.
  - Evidencia: `smartPost` reencola 401/403/419; no hay clasificación terminal.
- **Inconsistencia UI**: el journal solo muestra un string de error, sin razón ni próxima hora.
  - Evidencia: `journal_ui.js` usa `last_error` como texto plano.
- **Colas duplicadas** por reload / handlers múltiples: se disparan drains a la carga y online sin mutex.
  - Evidencia: `offline-queue.js` usa `_draining` bool pero no evita reentradas reales.
- **Crecimiento de IndexedDB/caches**: sin retención de journal y sin límite de runtime cache.
  - Evidencia: `journal_db.js` no limpia; `sw.js` no recorta runtime.

## Evidencia (archivos / funciones)
- `app/assets/js/offline-queue.js` — `drain()`, `smartPost()`, `processTask()`, `parseJsonSafe()`.
- `app/assets/js/db.js` — esquema básico sin estados enriquecidos.
- `app/assets/js/journal_db.js` / `journal_ui.js` — UI sin detalle técnico ni acciones.
- `app/_session_guard.php` — redirección HTML en sesión inválida.
- `app/ping.php`, `app/csrf_refresh.php` — JSON inconsistente.
- `app/sw.js` — no limita runtime cache.
- `app/index_pruebas.php` — persistencia de modo no valida disponibilidad de reagendados.

## Plan de cambios (resumen)
1. **Refactor queue**: estados normalizados, mutex real, recuperación de stale jobs, backoff con jitter, clasificación de errores y bloqueo por auth/csrf.
2. **CSRF/heartbeat**: refresh controlado + resume en foreground + heartbeat cuando hay pendientes.
3. **Journal**: tracking por intento, error_code/HTTP/nextTryAt, export diagnóstico y acciones UI.
4. **Endpoints**: JSON consistente, no HTML en AJAX; test mode para CI.
5. **Cache/retención**: limpieza en SW y cleanup en JournalDB.
6. **Mapa/Modo**: fallback a programados si no hay reagendados; reset de modo en refresh.
7. **Tests + CI**: unit JS, integración API, 1 E2E crítico en Playwright.

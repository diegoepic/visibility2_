# Auditoría offline/online actual

## Flujo observado (extremo a extremo)
1. **Inicio de visita (`create_visita`)**: `offline-queue.js` arma un `FormData` y lo envía con `smartPost` o lo encola en IndexedDB (`AppDB`). Si llega `client_guid`, se almacena en `LocalByGuid` y se guarda `visita_local_id` local. En éxito se mapea `local-*` a `visita_id` real (localStorage `Visits`).
2. **Captura de respuestas/procesar gestión**: se envían como tareas `procesar_gestion(_pruebas)` reutilizando `smartPost`/`enqueue`. En éxito se marca `v2_agenda_needs_refresh` en localStorage para forzar `sync_bundle` en `bootstrap_index_cache.js`.
3. **Fotos**: `smartPost`/`enqueueFromForm` construyen tareas con `files` para `upload_material_foto_pruebas.php` o `procesar_pregunta_foto_pruebas.php`. No hay control granular de progreso ni detección de reintentos parciales.
4. **Cierre/finish**: `procesar_gestion` culmina la visita. No hay aseguramiento de orden más allá de un campo `dependsOn` simple (string) comparado con `CompletedDeps` (array en localStorage) que sólo marca `create:<client_guid>`.
5. **Drenado**: `drain()` lista tareas `pending`, marca `running`, ejecuta `processTask`, y en éxito elimina la tarea. Errores vuelven a `pending` con `nextTry` (+backoff). No hay persistencia de errores ni recuperación de `running` tras recarga.
6. **Service Worker / cache**: `sw.js` precachea assets y delega runtime cache básico. `bootstrap_index_cache.js` fuerza `sync_bundle` si `v2_agenda_needs_refresh`.
7. **Journal**: `journal_db.js` guarda un registro por job con estado simple `pending|running|success|error`, campos limitados (`names`, `counts`, `vars`). `journal_ui.js` muestra una tabla resumida por fecha, sin detalle de reintentos ni errores.

## Qué queda en cola y cuándo
- **`AppDB`** guarda tasks con campos mínimos (`id,type,url,fields,files,status,pending/attempts,nextTry`) en IndexedDB `v2_offline` versión 7. Deduplicación opcional por `dedupeKey`.
- **`CompletedDeps`** en `localStorage` almacena dependencias resueltas (sólo `create:<guid>`).
- **Journal** guarda un registro separado pero no se sincroniza con la cola; si una tarea desaparece (ej. error fatal + remove) el journal puede quedar inconsistente.

## Puntos donde se pierden estados
- **Tareas `running`**: al recargar la página, `running` queda así y nunca se reintenta; `drain()` sólo consulta `pending`.
- **Errores lógicos**: si la respuesta JSON no cumple `isLogicalSuccess`, se lanza excepción y se vuelve a `pending` pero sin clasificar el error; el journal sólo guarda string de error.
- **Respuestas HTML/redirect**: `parseJsonSafe` retorna `{}` ante HTML; `isLogicalSuccess` devuelve `false` → se reintenta indefinidamente sin marcar que fue un redirect/login.
- **CSRF caducado**: `httpPost` refresca CSRF pero si falla y server responde 419/403, se reintenta inline; si sigue fallando, vuelve a `pending` sin pausar ni avisar.
- **Sesión expirada**: `heartbeat()` sólo retorna `false` pero no marca las tareas; el usuario no ve motivo.
- **Race IndexedDB**: `db.js` abre transacción por operación; no hay mutex global. `tx.abort` en dedupe puede quedar silencioso; `nextTry` se compara en memoria, no se revalida al reabrir.
- **Progreso uploads**: `smartPost` emite eventos con `progress` fijo 50/90; aunque el servidor rechace, el Journal puede mostrar 90%.

## Fallos concretos posibles
- **Sesión expirada/revocada (`user_sessions`)**: heartbeat devuelve `false`, pero tasks vuelven a `pending` sin mensaje. Pueden ciclar indefinidamente.
- **CSRF inválido**: si refresco falla o token antiguo, `httpPost` reintenta una vez; luego error genérico. No se pausa la cola ni se instruye re-login.
- **Respuesta HTML/redirect a login**: `parseJsonSafe` devuelve `{ raw: ... }` → `isLogicalSuccess`=false → backoff. No se detecta login redirect → UX “enviando infinito”.
- **Timeout/Abort/FPM saturado**: `withTimeout` aborta → se marca `pending` con `lastError` string; sin código. No hay deadline por tipo de job ni jitter configurable.
- **Fetch suspendido (Android background)**: si el navegador suspende la pestaña, tareas quedan `running` sin recovery.
- **Duplicados por reintentos**: idempotencyKey se envía pero no hay `dedupeKey` consistente; tareas reencoladas por `smartPost` generan nuevo id salvo que caller lo provea. Server puede crear visitas duplicadas si idempotency falla.
- **Jobs cancelados en UI**: `cancel()` borra cola + journal; si estaba en `running` se pierde trazabilidad.
- **`CompletedDeps` incompleto**: sólo marca creación de visita; fotos y gestiones no dependen explícitamente de `create_visita` → podría subir foto antes de tener visita real si mapping falla.
- **Logs mínimos**: no se guarda HTTP status, cuerpo, bytes enviados, ni próximo reintento → difícil diagnosticar “online pero no sube”.

## Mala prácticas / bugs lógicos
- **Sin recuperación de `running`** → jobs huérfanos.
- **Backoff limitado**: `Math.pow(2, attempts)` sin tope inicial por tipo de job; fotos grandes usan mismo timeout.
- **`heartbeat()`/`refreshCSRF()` sin manejo de códigos HTTP**: si 401/419 con HTML, se interpreta como falso pero no se clasifica.
- **`parseJsonSafe` ignora Content-Type**: no detecta HTML login.
- **`smartPost` registra journal aunque no se encole**: deja registros “online” sin relación con la cola.
- **Endpoints PHP**: aunque muchos responden JSON, no todos chequean `Accept`/`X-Requested-With`; errores pueden terminar en HTML (login) si la sesión se pierde. No hay código estable (`AUTH_REQUIRED`, etc.) ni logging de idempotencia en BD.
- **CSRF refresh**: `csrf_refresh.php` puede devolver HTML si sesión muere antes de header JSON. No extiende sesión de forma explícita.

## Decisiones de refactor (propuesta aplicada)
- Introducir **máquina de estados** persistente para la cola (`queued|running|retry|auth_paused|fatal|success`) con metadatos (`lastHttpStatus`, `lastErrorCode`, `nextTryAt`, `durationMs`, `bytesSent`, `idempotencyKey`, `dependsOn[]`).
- **Mutex global** por almacenamiento local y recuperación automática de jobs `running` a `retry` al recargar.
- Clasificación de errores (`NET_OFFLINE`, `TIMEOUT`, `HTTP_401_AUTH`, `HTML_LOGIN_REDIRECT_DETECTED`, etc.) y pausa automática ante problemas de sesión/CSRF.
- **Backoff exponencial con jitter** y timeouts diferenciados (fotos más largos).
- Detección de HTML en respuestas esperadas JSON para cortar loops de login redirect.
- Journal UI enriquecido: muestra reintentos, próximo intento y códigos de error para cada tarea.
- `ping.php` conserva sesión abierta hasta emitir CSRF para evitar tokens vacíos en heartbeat.


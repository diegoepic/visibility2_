# Reporte técnico — Offline/Online “a prueba de terreno”

## Qué cambió (resumen)
- Cola offline con estados normalizados, mutex real, backoff con jitter y recuperación de jobs `running` stale.
- Clasificación de errores: auth/CSRF bloquea la cola; 4xx terminales no reintentan; 5xx/timeout sí.
- Heartbeat + resume foreground: `ping` + `csrf_refresh` al volver y keepalive cuando hay pendientes.
- Journal con eventos por intento, códigos de error, HTTP status, próxima hora de reintento y export diagnóstico.
- UI de Journal con acciones (reintentar/cancelar), detalle técnico y badges completos.
- JSON consistente en endpoints críticos y session guard compatible con AJAX.
- Limpieza de runtime cache y retención de journal.
- Bug de mapa/modo: fallback a programados si no hay reagendados y reset al refrescar.

## Impacto esperado en terreno
- Menos jobs “pegados”, menos reintentos sin sentido y mejor recuperación post-background.
- Diagnóstico claro cuando expira sesión o CSRF (sin loops silenciosos).
- Menor crecimiento de storage y caches.

## Riesgos
- **Cambios en cola/Journals**: migración automática de schema podría exponer corner cases en jobs antiguos.
- **Session guard JSON**: endpoints que esperaban redirect podrían requerir manejo adicional en front.
- **Test mode**: endpoint `api/test_session.php` solo habilitado con `V2_TEST_MODE=1`.

## Cómo revertir
1. Revertir commit(s) asociados a:
   - `assets/js/offline-queue.js`, `assets/js/db.js`, `assets/js/journal_db.js`, `assets/js/journal_ui.js`.
   - `app/sw.js`, `app/_session_guard.php`, endpoints `ping.php`, `csrf_refresh.php`.
2. Eliminar `app/api/test_session.php` si no se requiere.
3. Limpiar cache del SW en los dispositivos para evitar usar assets antiguos.

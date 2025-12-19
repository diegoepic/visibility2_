# Manual Test Checklist — Offline/Online

## Preparación
- [ ] Abrir `index_pruebas.php` con sesión válida.
- [ ] Confirmar que el journal muestra badges (Pendientes/Enviando/Subidas/Errores/Bloqueadas).
- [ ] Abrir consola (para ver logs si aplica).

## Offline → Online (cola)
- [ ] Activar modo avión / desconectar red.
- [ ] Iniciar una visita y subir al menos 1 foto/material.
- [ ] Verificar que el job aparece en el Journal como **Pendiente**.
- [ ] Volver a online.
- [ ] Confirmar que el job pasa a **Enviando** y luego **Subida OK**.

## Sesión expirada
- [ ] Dejar la app en background 10–15 min.
- [ ] Volver a foreground.
- [ ] Si sesión expirada, la cola debe quedar en **Requiere login**.
- [ ] Reautenticar y verificar que la cola se desbloquea.

## CSRF inválido
- [ ] Forzar CSRF expirado (logout/login en otra pestaña o esperar expiración).
- [ ] Volver a la app, generar un job.
- [ ] Confirmar que se intenta `csrf_refresh` y si falla queda en **CSRF inválido**.

## Stale running
- [ ] Forzar una tarea en running (cerrar la app mientras sube).
- [ ] Reabrir luego de 6+ minutos.
- [ ] Confirmar que se recupera a **Pendiente** y reintenta.

## Journal UI
- [ ] Abrir “Ver tareas”, abrir “Ver detalle” de un job.
- [ ] Validar que muestra endpoint, HTTP status, error_code, attempts, nextTryAt, idempotencyKey.
- [ ] Usar “Reintentar ahora” desde el modal.
- [ ] Usar “Cancelar” (queda marcado como canceled).
- [ ] Exportar diagnóstico (descarga JSON).

## Mapa / Modo
- [ ] Entrar a **Reagendados**.
- [ ] Click **Actualizar** (refresh).
- [ ] Confirmar que vuelve a **Programados**.
- [ ] Abrir mapa: debe mostrar markers de programados.

## Limpieza
- [ ] Esperar/forzar limpieza (JournalDB.cleanup) y confirmar que logs antiguos desaparecen.
- [ ] Verificar que runtime cache no crece indefinidamente.

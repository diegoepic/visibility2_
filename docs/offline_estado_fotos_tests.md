# Pruebas sugeridas: fotos de estado (pendiente/cancelado)

## Manuales
1. **Caso A: Online**
   - Iniciar visita.
   - Marcar “Pendiente” y adjuntar foto.
   - Finalizar gestión.
   - Verificar en DB `fotoVisita` (registro con `kind` si aplica) y `gestion_visita` con `foto_url` (opcional).

2. **Caso B: Offline real**
   - Desconectar red.
   - Adjuntar foto de estado.
   - Finalizar gestión (queda en cola y journal).
   - Reconectar y validar que la foto se sube y se registra.

3. **Caso C: Caída en medio**
   - Subir foto y cerrar pestaña/APP.
   - Reabrir y validar que el journal retoma y sube al reconectar.

4. **Caso D: CSRF expirado**
   - Forzar CSRF inválido (o esperar expiración).
   - Verificar que la cola refresca CSRF y reintenta.

## Automatizadas (si hay stack)
- Unit: normalización de payload y dedupeKey para `upload_estado_foto`.
- E2E: mock offline/online en Playwright si está disponible.

> Nota: Estas pruebas están documentadas como guía. Ejecutarlas en staging antes de producción.

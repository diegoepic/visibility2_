# Flujo offline/online para fotos de estado (Pendiente/Cancelado)

## Discovery (estado actual)
- **Frontend**: `app/gestionarPruebas.php` renderiza inputs de fotos de estado (`#fotoLocalCerrado`, `#fotoLocalNoExiste`, `#fotoMuebleNoSala`, `#fotoPendienteGenerica`, `#fotoCanceladoGenerica`).
- **Backend**: `app/procesar_gestion_pruebas.php` procesa esas fotos **solo** cuando llegan embebidas en el POST grande y luego:
  - Inserta la **ruta** en `formularioQuestion.observacion` y/o `gestion_visita.observacion`.
  - Guarda el archivo vía `guardarFotoUnitaria(...)`.
- **Problema**: esta carga no tiene retries por foto ni idempotencia por evidencia. Si el request grande falla o se interrumpe, la gestión se guarda pero la foto no siempre queda subida.

## Objetivo
- Subir evidencias **Pendiente/Cancelado** con el mismo flujo robusto que materiales/encuesta:
  - online-first
  - cola offline con reintentos
  - idempotencia por foto
  - journal detallado
- Mantener el submit de la gestión **sin romper compatibilidad**, y evitar insertar rutas en `observacion`.

## Nuevo flujo (resumen)
1. **Nuevo endpoint**: `app/upload_estado_foto_pruebas.php` (multipart)
   - Inputs principales: `visita_id` o `client_guid`, `id_formulario`, `id_local`, `estado`, `motivo`, `observacion_text`, `foto`.
   - Valida sesión, CSRF, permisos, idempotencia.
   - Guarda imagen en WebP y registra en `fotoVisita` con `kind`/metadata si el esquema lo permite.
2. **Frontend** (`gestionarPruebas.php`):
   - Cuando se selecciona foto de estado, se dispara subida inmediata.
   - Si está offline o falla la red, se encola job `upload_estado_foto` (con `dependsOn` en `create_visita`).
   - Se guarda referencia `foto_visita_id` en un input hidden para incluir en el submit final.
3. **Submit final** (`procesar_gestion_pruebas.php`):
   - Si llegan `estado_foto_ids[]`, asocia la evidencia sin escribir rutas en `observacion`.
   - Si llegan archivos legacy (`$_FILES[...]`), se sigue procesando (compatibilidad), pero **sin** concatenar rutas a `observacion`.

## Compatibilidad (legacy)
- Se mantiene soporte a archivos embebidos en `procesar_gestion_pruebas.php`.
- **Preferido**: fotos subidas por el endpoint dedicado.

## Journal / Observabilidad
- Los jobs `upload_estado_foto` aparecen como “Foto estado: Pendiente/Cancelado”.
- Registro de intentos con `http_status`, `error_code`, `message`, `timestamp` y `url`.

## Troubleshooting
- Si la cola queda bloqueada por CSRF o sesión, el journal indica “CSRF inválido” o “Requiere login”.
- El botón “Reintentar” reprograma jobs en estado `error`.

## SQL opcional
Si `fotoVisita` no tiene columna para “kind/source”, se puede agregar:

```sql
ALTER TABLE fotoVisita
  ADD COLUMN kind VARCHAR(50) NULL AFTER id_formularioQuestion;
```

> Alternativa sin migración: usar `fotoVisita_meta.raw_json` (si existe) para guardar `kind` y metadatos.

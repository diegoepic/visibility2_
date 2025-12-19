# Panel Encuesta — Auditoría técnica

## Arquitectura actual (mapa inicial)

**Entrada UI**
- `portal/modulos/mod_panel_encuesta/panel_encuesta.php`
  - Renderiza filtros (división, subdivisión, tipo, campaña, preguntas, fechas, etc.).
  - Carga catálogos iniciales (divisiones, subdivisiones, campañas, usuarios, jefes, distritos).
  - Front-end en el mismo archivo (HTML/CSS/JS embebido) con AJAX a endpoints del módulo.

**Endpoints AJAX / datos**
- `portal/modulos/mod_panel_encuesta/panel_encuesta_data.php`
  - Endpoint principal JSON para resultados paginados del panel.
  - Usa helpers para construir filtros y query.
- `portal/modulos/mod_panel_encuesta/ajax_preguntas_lookup.php`
  - Devuelve preguntas según campaña/criterios (para select2).
- `portal/modulos/mod_panel_encuesta/ajax_pregunta_stats.php`
  - Devuelve estadísticas agregadas por pregunta (resumen/insights rápidos).

**Helpers / shared**
- `portal/modulos/mod_panel_encuesta/panel_encuesta_helpers.php`
  - Normaliza filtros, arma WHERE común, prepara parámetros y logs.
  - Funciones auxiliares para logging en tabla `panel_encuesta_log`.

**Exportaciones**
- `portal/modulos/mod_panel_encuesta/export_csv_panel_encuesta.php`
  - Export CSV usando la misma base de filtros/joins que el panel.
- `portal/modulos/mod_panel_encuesta/export_pdf_panel_encuesta.php`
  - Export PDF (respuestas + fotos), con dompdf.
- `portal/modulos/mod_panel_encuesta/export_pdf_panel_encuesta_fotos.php`
  - Export PDF sólo fotos, reutiliza filtros del panel.

**Dependencias front-end**
- Bootstrap 4.5.2, FontAwesome 6.5.0, Select2 4.1.0-rc.0 (CDN).

## Issues P0 detectados (bloqueantes)

1) **Seguridad — CSRF ausente en endpoints críticos**
   - Formularios y endpoints de export/data no validan token CSRF.
   - Riesgo: ejecución de exportaciones masivas, carga de datos sin autorización explícita.

2) **Seguridad — controles de ámbito/permisos inconsistentes**
   - El control de sesión se realiza en `panel_encuesta.php`, pero endpoints (data/export/stats) no muestran un control explícito de empresa/división/perfil homogéneo.
   - Riesgo: consultas fuera del scope si se llama directo al endpoint con params manipulados.

3) **Performance — paginación y límites no garantizados en export**
   - Los exportadores CSV/PDF trabajan con rangos potencialmente grandes.
   - No hay límites globales por export ni streaming consistente en CSV.
   - Riesgo: timeouts, consumo alto de memoria.

4) **Correctitud — posible duplicidad por joins en respuestas**
   - La consulta base agrupa por visita/pregunta, pero usa joins sobre `form_question_responses` que pueden duplicar filas si hay multi-respuesta/fotos.
   - Riesgo: conteos incorrectos y duplicados visuales.

5) **UX — falta de estados de error controlados**
   - En varios endpoints se usa `die()`/`echo` directo sin JSON estandarizado.
   - La UI no tiene un manejo consistente para errores (mensajes, retry, etc.).

## Problemas detectados (prioridad P0/P1/P2)

### P0
- CSRF faltante en `panel_encuesta_data.php`, `ajax_preguntas_lookup.php`, `ajax_pregunta_stats.php`, `ajax_pregunta_meta.php` y exportaciones.
- Exportaciones sin guardrail de rango “sin scope” (posibles consultas masivas).
- Respuestas JSON inconsistentes entre endpoints (UI sin manejo uniforme).

### P1
- Inconsistencia de criterio de fecha (`created_at` vs `fecha_fin`) según endpoint.
- Falta de “modo parcial” (mostrar visitas que cumplen al menos una condición).
- Falta de chips de filtros activos y botón “limpiar todo”.

### P2
- Sin límites informativos explícitos en UI para exportación grande.
- Duplicados potenciales en datasets con multi-respuesta y fotos.
- Falta de indicadores de rendimiento visibles (tiempo / filas estimadas).

## Decisiones de refactor (y por qué)
- **Centralizar validaciones de CSRF y request_id** en `panel_encuesta_helpers.php` para reutilizar y estandarizar respuestas.
- **Incluir modo “parciales” opcional** (default mantiene “todas”) para mejorar la exploración sin romper la semántica actual.
- **Guardrail de rango “sin scope”**: bloquear exportaciones demasiado amplias para evitar timeouts y caídas.
- **Respuesta JSON consistente** `{status, data, error_code, message, debug_id}` para permitir manejo homogéneo en UI.

## Plan de rollback
1) Revertir commits de la rama `feature/panel-encuesta-polish`.
2) Si es necesario, deshabilitar los nuevos guardrails de export (bloqueos de rango) revirtiendo las validaciones CSRF/rango en `export_*`.
3) Verificar que el panel vuelve a cargar con filtros básicos y exportaciones simples.

## Checklist de verificación manual
- [ ] El panel carga con sesión válida y muestra filtros iniciales.
- [ ] Búsqueda con filtros básicos retorna resultados o “Sin resultados”.
- [ ] Modo “todas”/“parciales” funciona y el texto explicativo concuerda.
- [ ] Export CSV/PDF rechaza rangos amplios sin scope y muestra error legible.
- [ ] Fotos abren en modal y pueden abrirse en nueva pestaña.
- [ ] Paginación mantiene el estado de filtros.

---

> Este documento se irá completando en fases posteriores con decisiones de refactor, plan de rollback, checklist y mapa final de consultas/índices.

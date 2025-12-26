# Módulo de mapa de campañas (mod_formulario)

Este módulo ahora usa una estructura MVC ligera:

- `controllers/`: controladores HTTP (`MapaCampanaController`, `GestionesController`, `DetalleLocalController`).
- `models/`: consultas y reglas de negocio (`LocalModel`, `GestionVisitaModel`, `DetalleLocalModel`, `Database`).
- `views/`: plantillas PHP para HTML (`views/mapa_campana.php`).
- `js/`: scripts separados para el frontend (`mapa.js`, `gestiones.js`, `detalle_local.js`).

## Configuración

- La clave de Google Maps se toma desde la variable de entorno `GOOGLE_MAPS_API_KEY`.
- Las peticiones AJAX incluyen el token CSRF almacenado en `$_SESSION['csrf_token']` mediante la cabecera `X-CSRF-TOKEN`.

## Endpoints

- `mapa_campana.php`: renderiza la vista o entrega JSON con `format=json`/`Accept: application/json`.
- `ajax_gestiones_mapa.php`: responde con JSON de gestiones por local.
- `detalle_local.php`: responde con JSON del detalle de un local.

Ejecute `php -l` sobre los controladores si modifica el código para validar la sintaxis.

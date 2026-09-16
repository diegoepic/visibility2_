<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$mapsConfigPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/visibility2/portal/config/maps_config.php';
$mapsConfig = is_file($mapsConfigPath) ? include $mapsConfigPath : [];
$mapsKey = is_array($mapsConfig)
    ? trim((string)($mapsConfig['google_maps_browser_api_key'] ?? $mapsConfig['google_maps_api_key'] ?? ''))
    : '';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rutas publicadas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --routes-ink: #1e3c6b;
            --routes-muted: #647c9f;
            --routes-blue: #456edc;
            --routes-border: #d4e1fa;
            --routes-shadow: 0 14px 36px rgba(37, 72, 133, .08);
            --bs-primary-rgb: 69, 110, 220;
            --bs-secondary-color: #647c9f;
        }
        body {
            background: linear-gradient(180deg, #edf4ff 0, #f5f8fd 480px, #fff 100%);
            color: var(--routes-ink);
            color-scheme: light;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .page-shell { max-width: 1600px; padding: 32px 28px; }
        .page-heading h2 { color: var(--routes-ink); font-size: 26px; letter-spacing: -.4px; }
        .page-heading p { font-size: 13px; max-width: 850px; line-height: 1.6; }
        .card {
            border: 1px solid var(--routes-border);
            border-radius: 24px;
            background: #fff;
            box-shadow: var(--routes-shadow);
        }
        .card-header {
            border-radius: 24px 24px 0 0 !important;
            border-bottom: 1px solid #e5ecf8;
            background: #fff;
            color: var(--routes-ink);
            font-weight: 700;
            padding: 20px 24px;
        }
        .card-body { padding: 24px; }
        .filters-card { max-width: 1280px; margin-left: auto; margin-right: auto; }
        .filters-card .card-header { border-bottom: 0; padding-bottom: 0; font-size: 15px; }
        .filter-label {
            color: var(--routes-ink);
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .025em;
        }
        .form-select, .form-control {
            min-height: 44px; border: 1px solid #c8d9f8; border-radius: 13px;
            color: var(--routes-ink); font-size: 12px;
        }
        .form-select:disabled, .form-control:disabled { background-color: #f2f5fa; color: #7687a0; border-color: #e0e7f2; }
        .form-select:focus, .form-control:focus {
            border-color: #5e87ec;
            box-shadow: 0 0 0 .2rem rgba(78, 123, 238, .13);
        }
        .stat-card {
            height: 100%;
            padding: 18px;
            border: 1px solid var(--routes-border);
            border-radius: 20px;
            background: #fff;
            box-shadow: var(--routes-shadow);
            position: relative;
            overflow: hidden;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            right: -22px;
            bottom: -22px;
            width: 78px;
            height: 78px;
            border-radius: 50%;
            background: rgba(0, 74, 173, .06);
        }
        .stat-icon {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            color: var(--routes-blue);
            background: #eef4ff;
            margin-bottom: 10px;
        }
        .stat-number {
            color: var(--routes-ink);
            font-size: 1.7rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-label { color: var(--routes-muted); font-size: 12px; margin-top: 7px; }
        .set-banner {
            border: 1px solid #cfe0fb;
            border-left: 4px solid var(--routes-blue);
            border-radius: 16px;
            background: #eef4ff;
            padding: 13px 16px;
        }
        #map {
            height: 680px;
            border-radius: 12px;
            background: #e7edf4;
        }
        .map-wrapper { position: relative; }
        .map-empty {
            position: absolute;
            inset: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            border-radius: 12px;
            background: rgba(244, 246, 249, .92);
            color: #6c757d;
            text-align: center;
            pointer-events: none;
        }
        .map-empty i { color: #004aad; font-size: 2rem; margin-bottom: 10px; }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            background: #f7f9fc;
            padding: 6px 10px;
            color: #425466;
        }
        .route-code-badge {
            min-width: 34px;
            height: 25px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid currentColor;
            border-radius: 7px;
            background: #fff;
            color: #17324d;
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .02em;
        }
        .route-line-sample {
            width: 24px;
            height: 0;
            border-top: 4px solid currentColor;
            display: inline-block;
        }
        .route-line-sample.dashed { border-top-style: dashed; }
        .route-line-sample.dotted { border-top-style: dotted; }
        .drag-route-box {
            border: 1px solid #dce5ef;
            border-radius: 10px;
            padding: 11px 13px;
            background: #f8fafc;
        }
        .download-note { color: #6c757d; font-size: .86rem; }
        .editor-card { position: sticky; top: 18px; }
        .editor-intro {
            color: #5b6b7c;
            font-size: .88rem;
            line-height: 1.45;
        }
        .editor-section {
            border-top: 1px solid #e8edf3;
            padding-top: 14px;
            margin-top: 14px;
        }
        .selected-list {
            max-height: 205px;
            overflow: auto;
            border: 1px solid #e4eaf1;
            border-radius: 10px;
            background: #f8fafc;
        }
        .selected-item {
            padding: 8px 10px;
            border-bottom: 1px solid #e8edf3;
            font-size: .8rem;
        }
        .selected-item:last-child { border-bottom: 0; }
        .edit-state {
            border-radius: 10px;
            padding: 10px 12px;
            font-size: .84rem;
            background: #f1f5f9;
            color: #526174;
        }
        .edit-state.changed { background: #fff3cd; color: #765c00; }
        .route-option-note { font-size: .78rem; color: #6c757d; }
        .new-route-panel {
            border: 1px solid #cfe0fb;
            border-radius: 10px;
            background: #f5f9ff;
            padding: 11px;
        }
        .route-date-menu {
            width: 290px;
            max-height: 330px;
            overflow-y: auto;
        }
        .base-route-group {
            border: 1px solid #dce5ef;
            border-radius: 10px;
            overflow: hidden;
        }
        .base-route-group + .base-route-group { margin-top: 10px; }
        .base-route-group summary {
            cursor: pointer;
            padding: 12px 14px;
            background: #f7f9fc;
        }
        .base-route-group summary:focus-visible { outline: 2px solid #004aad; outline-offset: -2px; }
        .base-route-group .table { font-size: .85rem; }
        .base-route-group th { white-space: nowrap; }
        .base-route-options {
            max-height: 360px;
            overflow-y: auto;
            border: 1px solid #dce5ef;
            border-radius: 10px;
            background: #f8fafc;
        }
        .base-route-option {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin: 0;
            padding: 11px 10px;
            border-bottom: 1px solid #e4eaf1;
            cursor: pointer;
        }
        .base-route-option:last-child { border-bottom: 0; }
        .base-route-option:hover { background: #edf4ff; }
        .base-route-option:focus-within { outline: 2px solid #80aee8; outline-offset: -2px; }
        .base-route-option .form-check-input { flex-shrink: 0; margin-top: 4px; }
        .base-route-option-text { min-width: 0; font-size: .83rem; overflow-wrap: anywhere; }
        .base-route-option input:checked + .base-route-option-text { color: #004aad; }
        .base-route-option-name { display: block; font-weight: 600; }
        .base-route-option-meta { display: block; color: #6c757d; font-size: .76rem; margin-top: 3px; }
        .btn { border-radius: 12px; font-size: 12px; font-weight: 600; padding: 10px 14px; }
        .btn-sm { padding: 7px 10px; font-size: 11px; }
        .btn-primary, #downloadButton {
            --bs-btn-bg: #456edc; --bs-btn-border-color: #456edc;
            --bs-btn-hover-bg: #365bc1; --bs-btn-hover-border-color: #365bc1;
            --bs-btn-active-bg: #365bc1; --bs-btn-active-border-color: #365bc1;
            --bs-btn-disabled-bg: #456edc; --bs-btn-disabled-border-color: #456edc;
            box-shadow: 0 8px 20px rgba(71, 112, 223, .16);
        }
        .btn-outline-primary, .btn-outline-secondary {
            --bs-btn-color: #456edc; --bs-btn-border-color: #c8d9f8;
            --bs-btn-hover-bg: #edf4ff; --bs-btn-hover-color: #1e3c6b; --bs-btn-hover-border-color: #9bb7f3;
            --bs-btn-active-bg: #edf4ff; --bs-btn-active-color: #1e3c6b; --bs-btn-active-border-color: #9bb7f3;
            --bs-btn-disabled-color: #7687a0; --bs-btn-disabled-border-color: #e0e7f2;
        }
        #routeDateButton { min-height: 44px; }
        .table { --bs-table-color: #1e3c6b; --bs-table-border-color: #e5ecf8; --bs-table-striped-bg: #f9fbff; }
        .table thead th { background: #f0f5ff; color: #365782; font-size: 11px; padding: 14px 12px; }
        .table td { padding: 12px; }
        .base-route-group { border-color: var(--routes-border); border-radius: 16px; }
        .base-route-group summary, .base-route-options, .selected-list { background: #f7faff; }
        .modal-content { border-radius: 22px; color: var(--routes-ink); }
        .modal-header { background: #f2f6ff; border-bottom-color: var(--routes-border); border-radius: 22px 22px 0 0; }
        .rutero-loading {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            z-index: 1040; display: flex; align-items: center; gap: 16px;
            width: min(460px, calc(100% - 32px)); padding: 22px 24px;
            background: #fff; border: 1px solid #b7ccfa; border-radius: 20px;
            box-shadow: 0 14px 50px rgba(30, 60, 107, .22);
        }
        .rutero-loading[hidden] { display: none; }
        .rutero-loading strong { display: block; color: var(--routes-ink); font-size: 16px; }
        .rutero-loading p { margin: 4px 0 0; color: var(--routes-muted); font-size: 12px; overflow-wrap: anywhere; }
        .rutero-spinner { width: 32px; height: 32px; flex: 0 0 32px; border: 3px solid #dce7ff; border-top-color: #456edc; border-radius: 50%; animation: rutero-spin .8s linear infinite; }
        @keyframes rutero-spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .rutero-spinner { animation: none; } }
        @media (max-width: 991.98px) {
            #map { height: 500px; }
            .editor-card { position: static; }
        }
        @media (max-width: 767.98px) {
            .page-shell { padding: 18px 12px; }
            .page-heading h2 { font-size: 22px; }
            .card-header { padding: 18px; }
            .card-body { padding: 18px; }
            #map { height: 430px; }
            .page-heading { text-align: left !important; }
        }
    </style>
</head>
<body>
<main id="routeWorkspace" class="container-fluid page-shell" aria-busy="false">
    <header class="page-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1"><i class="fa-solid fa-map-location-dot me-2"></i>Visualizador de rutas publicadas</h2>
            <p class="text-muted mb-0">Revisa las rutas agrupadas por usuario y descarga el rutero publicado. Las planificaciones con fechas también permiten ajustar locales entre rutas.</p>
        </div>
        <div class="text-end">
            <a id="downloadButton" class="btn btn-success disabled" href="#" aria-disabled="true">
                <i class="fa-solid fa-file-excel me-2"></i><span>Descargar rutero</span>
            </a>
            <div class="download-note mt-1">Disponible al seleccionar un set.</div>
        </div>
    </header>

    <div id="alertBox"></div>

    <section class="card filters-card mb-4">
        <div class="card-header"><i class="fa-solid fa-sliders me-2"></i>Seleccionar rutero</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-xl-2 col-md-6">
                    <label class="filter-label" for="division">División</label>
                    <select id="division" class="form-select"><option value="">Seleccionar división</option></select>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="filter-label" for="subdivision">Subdivisión</label>
                    <select id="subdivision" class="form-select" disabled><option value="">Seleccionar subdivisión</option></select>
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="filter-label" for="status">Estado</label>
                    <select id="status" class="form-select">
                        <option value="activo">Ruteros activos</option>
                        <option value="finalizado">Ruteros finalizados</option>
                        <option value="">Todas</option>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="filter-label" for="set">Rutero</label>
                    <select id="set" class="form-select" disabled><option value="">Seleccionar rutero</option></select>
                </div>
                <div class="col-xl-3 col-md-12">
                    <label class="filter-label" for="routeUser">Filtrar por usuario</label>
                    <select id="routeUser" class="form-select" disabled><option value="">Primero selecciona un rutero</option></select>
                    <div id="userHint" class="small text-muted mt-1"></div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="filter-label" for="routeDateButton">Filtrar días planificados</label>
                    <div class="dropdown" data-bs-auto-close="outside">
                        <button id="routeDateButton" class="btn btn-outline-secondary dropdown-toggle w-100 text-start" type="button" data-bs-toggle="dropdown" disabled>Primero selecciona un usuario</button>
                        <div class="dropdown-menu route-date-menu p-2">
                            <div class="d-flex justify-content-between border-bottom pb-2 mb-2 px-1">
                                <button id="allRouteDatesButton" type="button" class="btn btn-sm btn-link text-decoration-none p-0">Ver todos</button>
                                <button id="clearRouteDatesButton" type="button" class="btn btn-sm btn-link text-decoration-none p-0">Limpiar selección</button>
                            </div>
                            <div id="routeDateOptions" class="small text-muted px-1">Selecciona un usuario.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div id="selectedSetBanner" class="set-banner d-none mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><strong id="selectedSetName"></strong><div id="selectedSetDescription" class="small text-muted"></div></div>
            <div id="selectedSetFile" class="small text-primary"></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-icon"><i class="fa-solid fa-store"></i></span><div class="stat-number" id="mLocals">0</div><div class="stat-label">Locales en rutero base</div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-icon"><i class="fa-solid fa-user"></i></span><div class="stat-number" id="mUsers">0</div><div class="stat-label">Usuarios del rutero</div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-icon"><i class="fa-solid fa-route"></i></span><div class="stat-number" id="mRoutes">0</div><div class="stat-label">Rutas base</div></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-icon"><i class="fa-solid fa-location-dot"></i></span><div class="stat-number" id="mMissing">0</div><div class="stat-label">Sin coordenadas válidas</div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-9">
            <section class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <span><i class="fa-solid fa-map me-2"></i>Mapa de rutas</span>
                    <small id="mapContext" class="fw-normal opacity-75"></small>
                </div>
                <div class="card-body">
                    <div class="map-wrapper">
                        <div id="map"></div>
                        <div id="mapEmpty" class="map-empty"><div><i class="fa-solid fa-route d-block"></i><span>Selecciona un rutero y luego un usuario para cargar sus rutas.</span></div></div>
                    </div>
                    <div class="small text-muted mt-3"><i class="fa-solid fa-universal-access me-1 text-primary"></i>Cada marcador muestra <strong>ruta·orden</strong>: por ejemplo, <strong>7·12</strong> corresponde a la ruta 07, visita 12. Las formas y los trazos también distinguen las rutas.</div>
                    <div id="legend" class="d-flex flex-wrap gap-2 mt-3 small"></div>
                </div>
            </section>
        </div>
        <div class="col-xl-3">
            <section class="card editor-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span id="routeEditorTitle"><i class="fa-solid fa-pen-ruler me-2"></i>Editar rutero base</span>
                    <span id="selectedCount" class="badge text-bg-light text-primary">0</span>
                </div>
                <div class="card-body">
                    <p id="baseOnlyNotice" class="editor-intro mb-0 d-none">Selecciona un usuario y marca las rutas que quieres ver en el mapa. Puedes mostrar varias a la vez.</p>
                    <section id="baseRouteSelector" class="editor-section d-none" aria-labelledby="baseRouteSelectorTitle">
                        <h6 id="baseRouteSelectorTitle" class="filter-label">Rutas agrupadas sin planificación</h6>
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <button id="allBaseRoutesButton" type="button" class="btn btn-sm btn-outline-primary" disabled>Ver todas</button>
                            <button id="clearBaseRoutesButton" type="button" class="btn btn-sm btn-outline-secondary" disabled>Limpiar</button>
                        </div>
                        <div id="baseRouteSelectionCount" class="small text-muted mb-2" aria-live="polite"></div>
                        <div id="baseRouteOptions" class="base-route-options"><div class="p-3 small text-muted">Selecciona un usuario para ver sus rutas.</div></div>
                        <a href="#baseRoutesPanel" class="btn btn-outline-primary w-100 mt-3"><i class="fa-solid fa-list me-2"></i>Ver locales seleccionados</a>
                    </section>
                    <div id="scheduledRouteEditor">
                    <p class="editor-intro mb-3">Activa la selección y marca puntos individualmente, o mantén presionado y arrastra sobre una zona vacía del mapa para seleccionar muchos locales. Después puedes moverlos, dejarlos sin ruta o crear una ruta nueva.</p>
                    <button id="editModeButton" type="button" class="btn btn-outline-primary w-100" disabled>
                        <i class="fa-solid fa-location-crosshairs me-2"></i>Activar selección
                    </button>

                    <div class="editor-section">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="filter-label mb-0">Locales seleccionados</span>
                            <button id="clearSelectionButton" type="button" class="btn btn-sm btn-link text-decoration-none p-0" disabled>Limpiar</button>
                        </div>
                        <div id="selectedList" class="selected-list"><div class="p-3 small text-muted text-center">Aún no hay locales seleccionados.</div></div>
                    </div>

                    <div class="editor-section">
                        <label class="filter-label" for="targetRoute">Asignar destino</label>
                        <select id="targetRoute" class="form-select" disabled><option value="">Selecciona un rutero</option></select>
                        <div id="newRouteFields" class="new-route-panel d-none mt-2">
                            <label class="filter-label" for="newRouteName">Nombre de la nueva ruta</label>
                            <input id="newRouteName" type="text" class="form-control" maxlength="80" placeholder="Ej.: RUTA 12">
                            <div id="newRouteScheduleHint" class="route-option-note mt-2"></div>
                        </div>
                        <div class="route-option-note mt-1">Puedes mover los locales a una ruta existente, crear una nueva o dejarlos sin ruta.</div>
                        <button id="applyMoveButton" type="button" class="btn btn-primary w-100 mt-3" disabled>
                            <i class="fa-solid fa-arrow-right-arrow-left me-2"></i>Mover y autoordenar
                        </button>
                    </div>

                    <div class="editor-section">
                        <div id="editState" class="edit-state"><i class="fa-solid fa-circle-info me-1"></i>Sin cambios pendientes.</div>
                        <button id="adjustedDownloadButton" type="button" class="btn btn-success w-100 mt-3" disabled>
                            <i class="fa-solid fa-file-excel me-2"></i>Descargar cambios
                        </button>
                        <button id="restoreButton" type="button" class="btn btn-outline-secondary w-100 mt-2" disabled>
                            <i class="fa-solid fa-rotate-left me-2"></i>Restaurar rutero original
                        </button>
                        <p class="download-note mt-2 mb-0">La descarga conserva el archivo original y agrega el rutero base, la planificación recalculada y el registro de cambios.</p>
                    </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <section id="baseRoutesPanel" class="card mt-4 d-none" aria-labelledby="baseRoutesTitle">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span id="baseRoutesTitle"><i class="fa-solid fa-list me-2"></i>Locales de las rutas seleccionadas</span>
            <small id="baseRoutesContext" class="fw-normal"></small>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">Abre cada grupo para revisar sus locales en el orden del archivo. Se muestran las rutas marcadas en el panel derecho, incluidos sus locales sin coordenadas válidas.</p>
            <div id="baseRoutesList"></div>
        </div>
    </section>
</main>

<div class="modal fade" id="dragRouteModal" tabindex="-1" aria-labelledby="dragRouteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title" id="dragRouteModalLabel"><i class="fa-solid fa-route me-2 text-primary"></i>Confirmar cambio de ruta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p id="dragRouteQuestion" class="mb-3"></p>
                <div class="drag-route-box mb-2"><span class="small text-muted d-block">Ruta actual</span><strong id="dragRouteFrom"></strong></div>
                <div class="text-center text-primary my-1"><i class="fa-solid fa-arrow-down"></i></div>
                <div class="drag-route-box"><span class="small text-muted d-block">Ruta sugerida por cercanía</span><strong id="dragRouteTo"></strong><div id="dragRouteDistance" class="small text-muted mt-1"></div></div>
                <p class="small text-muted mt-3 mb-0">Al confirmar se moverán los locales seleccionados y se autoordenarán las rutas afectadas.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="confirmDragMoveButton" class="btn btn-primary"><i class="fa-solid fa-check me-2"></i>Mover a esta ruta</button>
            </div>
        </div>
    </div>
</div>

<aside id="ruteroLoading" class="rutero-loading" role="status" aria-live="polite" aria-atomic="true" hidden>
    <span class="rutero-spinner" aria-hidden="true"></span>
    <div><strong>Cargando rutero…</strong><p id="ruteroLoadingText">Estamos preparando los locales y las rutas. Espera un momento.</p></div>
</aside>

<script>
const API = '/visibility2/portal/modulos/mod_panel/rutas_excel_sets_api.php';
const CSRF = <?= json_encode($_SESSION['csrf_token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const UNASSIGNED_ROUTE_KEY = '__SIN_RUTA__';
const CREATE_ROUTE_KEY = '__CREAR_NUEVA_RUTA__';
const palette = ['#0072B2', '#E69F00', '#009E73', '#CC79A7', '#56B4E9', '#D55E00', '#6F4E7C', '#000000', '#4D4D4D', '#8A7D00'];
let map = null;
let bounds = null;
let markers = [];
let polylines = [];
let baseRows = [];
let originalRows = [];
let routeTargets = [];
let originalRouteTargets = [];
let selectedRowKeys = new Set();
let selectedRouteDates = new Set();
let selectedBaseRouteKeys = new Set();
let routeDateFilterActive = false;
let editMode = false;
let dirty = false;
let periodStart = '';
let periodEnd = '';
let baseOnly = false;
let pendingDragMove = null;
let areaSelectionRectangle = null;
let areaSelectionStart = null;
let areaSelectionLast = null;
let areaSelectionMoved = false;
let newRouteSequence = 0;
let ruteroRequest = null;

function setRuteroLoading(loading, nombre = '') {
    document.getElementById('ruteroLoading').hidden = !loading;
    document.getElementById('routeWorkspace').setAttribute('aria-busy', String(loading));
    document.getElementById('ruteroLoadingText').textContent = nombre
        ? `${nombre}. Estamos preparando los locales y las rutas.`
        : 'Estamos preparando los locales y las rutas. Espera un momento.';
}

function cancelRuteroLoading() {
    if (ruteroRequest) ruteroRequest.abort();
    ruteroRequest = null;
    setRuteroLoading(false);
}

const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
}[char]));

async function json(url, options = {}) {
    const response = await fetch(url, options);
    let data;
    try {
        data = await response.json();
    } catch (error) {
        throw new Error(`Respuesta inválida (HTTP ${response.status}).`);
    }
    if (!response.ok || !data.ok) {
        throw new Error(data.message || `HTTP ${response.status}`);
    }
    return data;
}

function message(text, type = 'danger') {
    document.getElementById('alertBox').innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${esc(text)}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}

function clearMessage() {
    document.getElementById('alertBox').innerHTML = '';
}

function fill(select, rows, label) {
    select.innerHTML = `<option value="">${label}</option>` + rows.map(row => `<option value="${row.id}">${esc(row.nombre || row.name)}</option>`).join('');
    select.disabled = false;
}

function formatDate(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[3]}-${match[2]}-${match[1]}` : String(value || '');
}

function initMap() {
    map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: -33.45, lng: -70.66 },
        zoom: 6,
        mapTypeControl: true,
        streetViewControl: false,
        fullscreenControl: true
    });
    setupAreaSelection();
    if (baseRows.length) {
        renderMap(true);
    }
    updateEditor();
}

function clearMap() {
    clearAreaSelection();
    markers.forEach(marker => marker.setMap(null));
    polylines.forEach(polyline => polyline.setMap(null));
    markers = [];
    polylines = [];
    bounds = window.google?.maps ? new google.maps.LatLngBounds() : null;
}

function clearAreaSelection() {
    if (areaSelectionRectangle) areaSelectionRectangle.setMap(null);
    areaSelectionRectangle = null;
    areaSelectionStart = null;
    areaSelectionLast = null;
    areaSelectionMoved = false;
}

function selectionBounds(start, end) {
    return new google.maps.LatLngBounds(
        { lat: Math.min(start.lat(), end.lat()), lng: Math.min(start.lng(), end.lng()) },
        { lat: Math.max(start.lat(), end.lat()), lng: Math.max(start.lng(), end.lng()) }
    );
}

function finishAreaSelection() {
    if (!areaSelectionStart) return;
    const start = areaSelectionStart;
    const end = areaSelectionLast;
    const moved = areaSelectionMoved;
    clearAreaSelection();
    if (!moved || !end || !editMode) return;

    const selectedBounds = selectionBounds(start, end);
    let added = 0;
    visibleRows().forEach(row => {
        if (!validCoordinate(row)) return;
        const key = String(row.row_key);
        if (selectedBounds.contains({ lat: Number(row.lat), lng: Number(row.lng) }) && !selectedRowKeys.has(key)) {
            selectedRowKeys.add(key);
            added++;
        }
    });
    updateEditor();
    renderMap(false);
    message(added
        ? `${added} local(es) fueron agregados a la selección por área.`
        : 'El área dibujada no contiene locales nuevos.', added ? 'info' : 'warning');
}

function setupAreaSelection() {
    map.addListener('mousedown', event => {
        if (!editMode || !document.getElementById('routeUser').value || !event?.latLng) return;
        clearAreaSelection();
        areaSelectionStart = event.latLng;
        areaSelectionLast = event.latLng;
        areaSelectionRectangle = new google.maps.Rectangle({
            map,
            bounds: selectionBounds(event.latLng, event.latLng),
            clickable: false,
            editable: false,
            draggable: false,
            fillColor: '#FFC107',
            fillOpacity: .16,
            strokeColor: '#B88700',
            strokeOpacity: .9,
            strokeWeight: 2,
            zIndex: 2000
        });
    });
    map.addListener('mousemove', event => {
        if (!areaSelectionStart || !event?.latLng || !areaSelectionRectangle) return;
        areaSelectionLast = event.latLng;
        areaSelectionMoved = areaSelectionMoved
            || Math.abs(areaSelectionStart.lat() - event.latLng.lat()) > 0.00001
            || Math.abs(areaSelectionStart.lng() - event.latLng.lng()) > 0.00001;
        areaSelectionRectangle.setBounds(selectionBounds(areaSelectionStart, event.latLng));
    });
    map.addListener('mouseup', finishAreaSelection);
    document.addEventListener('mouseup', finishAreaSelection);
}

function setMapEmpty(text, visible = true) {
    const empty = document.getElementById('mapEmpty');
    empty.querySelector('span').textContent = text;
    empty.classList.toggle('d-none', !visible);
}

function cloneRows(rows) {
    return JSON.parse(JSON.stringify(rows));
}

function validCoordinate(row) {
    if (![row.lat, row.lng].every(value => (typeof value === 'number' || typeof value === 'string') && String(value).trim() !== '')) return false;
    const lat = Number(row.lat);
    const lng = Number(row.lng);
    return Number.isFinite(lat) && Number.isFinite(lng)
        && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
        && !(lat === 0 && lng === 0);
}

function isUnassignedRoute(routeKey) {
    return String(routeKey || '') === UNASSIGNED_ROUTE_KEY;
}

function resetDataset(text = 'Selecciona un rutero y luego un usuario para cargar sus rutas.') {
    cancelRuteroLoading();
    baseRows = [];
    originalRows = [];
    routeTargets = [];
    originalRouteTargets = [];
    selectedRowKeys = new Set();
    selectedRouteDates = new Set();
    selectedBaseRouteKeys = new Set();
    routeDateFilterActive = false;
    editMode = false;
    dirty = false;
    periodStart = '';
    periodEnd = '';
    baseOnly = false;
    pendingDragMove = null;
    newRouteSequence = 0;
    if (map) map.setOptions({ draggable: true, draggableCursor: null });
    clearMap();
    document.getElementById('legend').innerHTML = '';
    document.getElementById('baseRoutesPanel').classList.add('d-none');
    document.getElementById('baseRoutesList').innerHTML = '';
    document.getElementById('baseRoutesContext').textContent = '';
    document.getElementById('mapContext').textContent = '';
    document.getElementById('mLocals').textContent = '0';
    document.getElementById('mUsers').textContent = '0';
    document.getElementById('mRoutes').textContent = '0';
    document.getElementById('mMissing').textContent = '0';
    const userSelect = document.getElementById('routeUser');
    userSelect.disabled = true;
    userSelect.innerHTML = '<option value="">Primero selecciona un rutero</option>';
    const targetSelect = document.getElementById('targetRoute');
    targetSelect.disabled = true;
    targetSelect.innerHTML = '<option value="">Selecciona un rutero</option>';
    document.getElementById('newRouteFields').classList.add('d-none');
    document.getElementById('newRouteName').value = '';
    document.getElementById('routeDateButton').disabled = true;
    document.getElementById('routeDateButton').textContent = 'Primero selecciona un usuario';
    document.getElementById('routeDateOptions').innerHTML = '<span class="text-muted">Selecciona un usuario.</span>';
    document.getElementById('userHint').textContent = '';
    refreshBaseRouteSelector();
    setMapEmpty(text, true);
    updateEditor();
}

function updateGeneralKpis() {
    const users = new Set(baseRows.map(row => String(row.usuario_login || 'SIN ASIGNAR')));
    const routes = new Set(baseRows.filter(row => !isUnassignedRoute(row.route_key)).map(row => String(row.route_key)));
    const missing = baseRows.filter(row => !validCoordinate(row)).length;
    document.getElementById('mLocals').textContent = String(baseRows.length);
    document.getElementById('mUsers').textContent = String(users.size);
    document.getElementById('mRoutes').textContent = String(routes.size);
    document.getElementById('mMissing').textContent = String(missing);
}

function visibleRows() {
    const selectedUser = document.getElementById('routeUser').value;
    if (!selectedUser) return [];
    if (baseOnly) {
        return baseRows.filter(row => String(row.usuario_login) === selectedUser
            && selectedBaseRouteKeys.has(String(row.route_key)));
    }
    const visibleRouteKeys = routeDateFilterActive
        ? new Set(routeTargets
            .filter(route => String(route.usuario_login) === selectedUser
                && (route.fechas || []).some(date => selectedRouteDates.has(String(date))))
            .map(route => String(route.key)))
        : null;
    return baseRows.filter(row => String(row.usuario_login) === selectedUser
        && (!visibleRouteKeys || visibleRouteKeys.has(String(row.route_key))));
}

function baseRouteOptionsForUser() {
    const selectedUser = document.getElementById('routeUser').value;
    if (!baseOnly || !selectedUser) return [];
    const groups = new Map();
    baseRows.forEach(row => {
        if (String(row.usuario_login) !== selectedUser) return;
        const key = String(row.route_key);
        if (!groups.has(key)) groups.set(key, {
            key,
            name: isUnassignedRoute(key) ? 'Sin ruta' : String(row.grupo_ruta || 'Sin grupo'),
            count: 0,
            mapped: 0
        });
        groups.get(key).count++;
        if (validCoordinate(row)) groups.get(key).mapped++;
    });
    return [...groups.values()].sort((left, right) => left.name.localeCompare(right.name, 'es', { numeric: true, sensitivity: 'base' }));
}

function updateBaseRouteSelectionCount() {
    const options = baseRouteOptionsForUser();
    const selected = options.filter(route => selectedBaseRouteKeys.has(route.key)).length;
    document.getElementById('baseRouteSelectionCount').textContent = options.length
        ? `${selected} de ${options.length} rutas seleccionadas`
        : '';
    document.getElementById('allBaseRoutesButton').disabled = !options.length || selected === options.length;
    document.getElementById('clearBaseRoutesButton').disabled = selected === 0;
}

function refreshBaseRouteSelector(resetSelection = false) {
    const options = baseRouteOptionsForUser();
    const available = new Set(options.map(route => route.key));
    selectedBaseRouteKeys = resetSelection
        ? available
        : new Set([...selectedBaseRouteKeys].filter(key => available.has(key)));
    document.getElementById('baseRouteSelector').classList.toggle('d-none', !baseOnly);
    document.getElementById('baseRouteOptions').innerHTML = options.length
        ? options.map((route, index) => `<label class="base-route-option" for="baseRoute${index}">
            <input class="form-check-input base-route-check" type="checkbox" id="baseRoute${index}" value="${esc(route.key)}" ${selectedBaseRouteKeys.has(route.key) ? 'checked' : ''}>
            <span class="base-route-option-text"><span class="base-route-option-name">${esc(route.name)}</span><span class="base-route-option-meta">${route.count} local(es)${route.mapped === 0 ? ' · Sin coordenadas válidas' : ''}</span></span>
        </label>`).join('')
        : '<div class="p-3 small text-muted">Selecciona un usuario para ver sus rutas.</div>';
    updateBaseRouteSelectionCount();
}

function renderBaseRoutesList() {
    const panel = document.getElementById('baseRoutesPanel');
    const list = document.getElementById('baseRoutesList');
    const context = document.getElementById('baseRoutesContext');
    panel.classList.toggle('d-none', !baseOnly);
    list.innerHTML = '';
    context.textContent = '';
    if (!baseOnly) return;

    const selectedUser = document.getElementById('routeUser').value;
    if (!selectedUser) {
        list.innerHTML = '<p class="text-muted mb-0">Selecciona un usuario para revisar sus grupos y locales.</p>';
        return;
    }
    const rows = visibleRows();
    const groups = new Map();
    rows.forEach(row => {
        const key = String(row.route_key);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(row);
    });
    context.textContent = `${selectedUser} · ${groups.size} grupo(s) · ${rows.length} local(es)`;
    if (!rows.length) {
        list.innerHTML = '<p class="text-muted mb-0">Marca una o más rutas en el panel derecho para ver sus locales.</p>';
        return;
    }

    const groupName = ([key, group]) => isUnassignedRoute(key) ? 'Sin ruta' : String(group[0].grupo_ruta || 'Sin grupo');
    list.innerHTML = [...groups.entries()]
        .sort((left, right) => groupName(left).localeCompare(groupName(right), 'es', { numeric: true, sensitivity: 'base' }))
        .map((entry, groupIndex) => {
            const [, group] = entry;
            group.sort((left, right) => Number(left.orden_visita) - Number(right.orden_visita));
            const validCount = group.filter(validCoordinate).length;
            const tableRows = group.map(row => `<tr>
                <td>${esc(row.orden_visita ?? '—')}</td>
                <td>${esc(row.codigo_local)}</td>
                <td>${esc(row.nombre)}</td>
                <td>${esc(row.direccion || '—')}</td>
                <td>${validCoordinate(row) ? 'Válidas' : 'Sin coordenadas válidas'}</td>
            </tr>`).join('');
            return `<details class="base-route-group"${groupIndex === 0 ? ' open' : ''}>
                <summary><strong>${esc(groupName(entry))}</strong><span class="small text-muted ms-2">${group.length} local(es) · ${validCount} con coordenadas válidas</span></summary>
                <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
                    <caption class="visually-hidden">Locales de ${esc(groupName(entry))}, usuario ${esc(selectedUser)}</caption>
                    <thead><tr><th scope="col">Orden</th><th scope="col">Código local</th><th scope="col">Nombre</th><th scope="col">Dirección</th><th scope="col">Coordenadas</th></tr></thead>
                    <tbody>${tableRows}</tbody>
                </table></div>
            </details>`;
        }).join('');
}

function routeVisualIndex(routeKey) {
    const selectedUser = document.getElementById('routeUser').value;
    const userRoutes = routeTargets
        .filter(route => String(route.usuario_login) === selectedUser)
        .sort((left, right) => String(left.grupo_ruta).localeCompare(String(right.grupo_ruta), 'es', { numeric: true, sensitivity: 'base' }));
    const userIndex = userRoutes.findIndex(route => String(route.key) === String(routeKey));
    if (userIndex >= 0) return userIndex;
    let hash = 0;
    for (const char of String(routeKey)) hash = ((hash << 5) - hash) + char.charCodeAt(0);
    return Math.abs(hash);
}

function routeVisual(routeKey, groupName = '') {
    if (isUnassignedRoute(routeKey)) {
        return {
            index: -1,
            color: '#6c757d',
            labelColor: '#fff',
            badge: 'SR',
            shape: 'square',
            lineStyle: 'dotted'
        };
    }
    const index = routeVisualIndex(routeKey);
    const numericMatch = String(groupName).match(/(?:^|\D)(\d{1,3})(?:\D|$)/);
    const routeNumber = numericMatch ? numericMatch[1].padStart(2, '0') : String(index + 1).padStart(2, '0');
    const shapes = ['circle', 'square', 'diamond', 'triangle', 'hexagon'];
    const lineStyles = ['solid', 'dashed', 'dotted'];
    const color = palette[index % palette.length];
    const red = parseInt(color.slice(1, 3), 16);
    const green = parseInt(color.slice(3, 5), 16);
    const blue = parseInt(color.slice(5, 7), 16);
    return {
        index,
        color,
        labelColor: ((red * 299 + green * 587 + blue * 114) / 1000) > 145 ? '#172b4d' : '#fff',
        badge: `R${routeNumber}`,
        shape: shapes[index % shapes.length],
        lineStyle: lineStyles[index % lineStyles.length]
    };
}

function routeMarkerPath(shape) {
    if (shape === 'circle') return google.maps.SymbolPath.CIRCLE;
    if (shape === 'square') return 'M -1,-1 1,-1 1,1 -1,1 z';
    if (shape === 'diamond') return 'M 0,-1.35 1.35,0 0,1.35 -1.35,0 z';
    if (shape === 'triangle') return 'M 0,-1.35 1.25,1 -1.25,1 z';
    return 'M 0,-1.25 1.08,-0.63 1.08,0.63 0,1.25 -1.08,0.63 -1.08,-0.63 z';
}

function routePolylineOptions(visual) {
    const options = {
        strokeColor: visual.color,
        strokeOpacity: .9,
        strokeWeight: 5,
        icons: []
    };
    if (visual.lineStyle === 'dashed') {
        options.strokeOpacity = 0;
        options.icons = [{
            icon: { path: 'M 0,-1 0,1', strokeColor: visual.color, strokeOpacity: 1, strokeWeight: 4, scale: 4 },
            offset: '0',
            repeat: '18px'
        }];
    } else if (visual.lineStyle === 'dotted') {
        options.strokeOpacity = .25;
        options.icons = [{
            icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: visual.color, fillOpacity: 1, strokeOpacity: 0, scale: 2.2 },
            offset: '0',
            repeat: '12px'
        }];
    }
    return options;
}

function renderMap(fitBounds = true) {
    renderBaseRoutesList();
    const rows = visibleRows();
    clearMap();
    const selectedUser = document.getElementById('routeUser').value;
    if (!selectedUser) {
        document.getElementById('legend').innerHTML = '';
        document.getElementById('mapContext').textContent = '';
        document.getElementById('userHint').textContent = baseRows.length
            ? 'Selecciona un usuario para revisar sus rutas.'
            : '';
        setMapEmpty(baseRows.length
            ? 'Selecciona un usuario para revisar sus rutas.'
            : 'Selecciona un rutero para visualizar sus rutas base.', true);
        return;
    }
    const groups = new Map();
    let missing = 0;

    rows.forEach(row => {
        const key = String(row.route_key);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(row);
    });

    const legendItems = [];
    const canDrawMap = Boolean(map && window.google?.maps && bounds);
    [...groups.entries()].forEach(([routeKey, group]) => {
        group.sort((left, right) => Number(left.orden_visita) - Number(right.orden_visita));
        const unassigned = isUnassignedRoute(routeKey);
        const visual = routeVisual(routeKey, group[0]?.grupo_ruta || '');
        const path = [];

        group.forEach((row, rowIndex) => {
            if (!validCoordinate(row)) {
                missing++;
                return;
            }
            if (!canDrawMap) return;

            const lat = Number(row.lat);
            const lng = Number(row.lng);
            const position = { lat, lng };
            if (!unassigned) path.push(position);
            bounds.extend(position);
            const selected = selectedRowKeys.has(String(row.row_key));
            const visitOrder = unassigned ? 0 : (Number(row.orden_visita) > 0 ? Number(row.orden_visita) : rowIndex + 1);
            const markerLabel = unassigned ? 'SR' : `${Number(visual.badge.slice(1))}·${visitOrder}`;
            const marker = new google.maps.Marker({
                map,
                position,
                label: {
                    text: markerLabel,
                    color: selected ? '#fff' : visual.labelColor,
                    fontWeight: 'bold',
                    fontSize: '10px'
                },
                title: unassigned
                    ? `Sin ruta · ${row.codigo_local} - ${row.nombre}`
                    : `${visual.badge} · orden ${visitOrder} · ${row.codigo_local} - ${row.nombre}`,
                draggable: editMode,
                zIndex: selected ? 1000 : visitOrder,
                icon: {
                    path: routeMarkerPath(visual.shape),
                    scale: selected ? 20 : 17,
                    fillColor: selected ? '#172b4d' : visual.color,
                    fillOpacity: 1,
                    strokeColor: selected ? '#ffc107' : '#fff',
                    strokeWeight: selected ? 4 : 2
                }
            });
            const info = new google.maps.InfoWindow({
                content: unassigned
                    ? `<strong>Sin ruta asignada</strong><br><strong>${esc(row.codigo_local)} - ${esc(row.nombre)}</strong><br>${esc(row.direccion || '')}<br>${esc(row.usuario_nombre || row.usuario_login)}`
                    : `<strong>${esc(visual.badge)} · ${esc(row.grupo_ruta)}</strong><br><strong>${esc(row.codigo_local)} - ${esc(row.nombre)}</strong><br>${esc(row.direccion || '')}<br>${esc(row.usuario_nombre || row.usuario_login)}<br>Orden de visita: ${esc(visitOrder)}`
            });
            marker.addListener('click', () => {
                if (editMode) {
                    const key = String(row.row_key);
                    if (selectedRowKeys.has(key)) selectedRowKeys.delete(key);
                    else selectedRowKeys.add(key);
                    updateEditor();
                    renderMap(false);
                    return;
                }
                info.open({ anchor: marker, map });
            });
            marker.addListener('dragstart', () => prepareDraggedSelection(row));
            marker.addListener('dragend', event => handleMarkerDragEnd(row, event));
            markers.push(marker);
        });

        if (!unassigned && canDrawMap && path.length > 1) {
            polylines.push(new google.maps.Polyline({
                map,
                path,
                ...routePolylineOptions(visual)
            }));
        }
        legendItems.push(unassigned
            ? `<span class="legend-item"><span class="route-code-badge" style="border-color:${visual.color}">SR</span>${esc(group[0].usuario_login)} · Sin ruta (${group.length})</span>`
            : `<span class="legend-item"><span class="route-code-badge" style="border-color:${visual.color}">${esc(visual.badge)}</span><i class="route-line-sample ${visual.lineStyle}" style="color:${visual.color}"></i>${esc(group[0].usuario_login)} · ${esc(group[0].grupo_ruta)} (${group.length})</span>`);
    });

    if (canDrawMap && !bounds.isEmpty()) {
        if (fitBounds) map.fitBounds(bounds);
        setMapEmpty('', false);
    } else if (!rows.length && baseRows.length) {
        setMapEmpty(baseOnly
            ? 'Marca una o más rutas en el panel derecho para verlas en el mapa.'
            : 'El filtro seleccionado no contiene locales.', true);
    } else if (rows.length && missing === rows.length) {
        setMapEmpty(baseOnly
            ? 'Los locales visibles no tienen coordenadas válidas. Puedes revisar todos sus grupos y locales en el listado bajo el mapa.'
            : 'Los locales visibles no tienen coordenadas válidas.', true);
    } else if (!canDrawMap) {
        setMapEmpty(baseOnly
            ? 'El mapa aún no está disponible. Puedes revisar todos sus grupos y locales en el listado bajo el mapa.'
            : 'El mapa aún no está disponible. El editor se habilitará cuando cargue Google Maps.', true);
    }

    const visibleLegend = legendItems.slice(0, 20);
    if (legendItems.length > 20) visibleLegend.push(`<span class="legend-item">+${legendItems.length - 20} rutas</span>`);
    document.getElementById('legend').innerHTML = visibleLegend.join('');
    const assignedRouteCount = [...groups.keys()].filter(routeKey => !isUnassignedRoute(routeKey)).length;
    const unassignedCount = rows.filter(row => isUnassignedRoute(row.route_key)).length;
    const unassignedLabel = unassignedCount ? ` · ${unassignedCount} sin ruta` : '';
    document.getElementById('mapContext').textContent = `${rows.length} local(es) · ${assignedRouteCount} ruta(s)${unassignedLabel}`;
    document.getElementById('userHint').textContent = `${rows.length} locales y ${assignedRouteCount} rutas visibles${unassignedLabel}. Los KPI mantienen el total general.`;
}

function refreshUserFilter() {
    const select = document.getElementById('routeUser');
    const previous = select.value;
    const users = new Map();
    baseRows.forEach(row => {
        const login = String(row.usuario_login || 'SIN ASIGNAR');
        if (!users.has(login)) users.set(login, String(row.usuario_nombre || login));
    });
    const sorted = [...users.entries()].sort((left, right) => left[0].localeCompare(right[0], 'es', { sensitivity: 'base' }));
    select.innerHTML = '<option value="">Seleccionar usuario para ver rutas</option>' + sorted.map(([login, name]) => `<option value="${esc(login)}">${esc(login)} · ${esc(name)}</option>`).join('');
    select.disabled = !baseRows.length;
    if (sorted.some(([login]) => login === previous)) select.value = previous;
}

function routeDateLabel(dateSql) {
    const match = String(dateSql || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return String(dateSql || '');
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0);
    const weekday = new Intl.DateTimeFormat('es-CL', { weekday: 'short' }).format(date).replace('.', '');
    return `${weekday.charAt(0).toUpperCase()}${weekday.slice(1)} ${match[3]}-${match[2]}-${match[1]}`;
}

function updateRouteDateButton() {
    const button = document.getElementById('routeDateButton');
    const selectedUser = document.getElementById('routeUser').value;
    const hasDates = routeTargets.some(route => String(route.usuario_login) === selectedUser && (route.fechas || []).length > 0);
    if (baseOnly || (selectedUser && !hasDates)) {
        button.textContent = 'Sin fechas de visita';
        button.disabled = true;
        return;
    }
    if (!selectedUser) {
        button.textContent = 'Primero selecciona un usuario';
        button.disabled = true;
        return;
    }
    button.disabled = false;
    button.textContent = !routeDateFilterActive
        ? 'Todos los días planificados'
        : selectedRouteDates.size
            ? `${selectedRouteDates.size} día(s) seleccionado(s)`
            : 'Ningún día seleccionado';
}

function refreshRouteDateFilter() {
    const selectedUser = document.getElementById('routeUser').value;
    const dates = [...new Set(routeTargets
        .filter(route => String(route.usuario_login) === selectedUser)
        .flatMap(route => Array.isArray(route.fechas) ? route.fechas.map(String) : []))]
        .sort();
    const available = new Set(dates);
    if (!dates.length) routeDateFilterActive = false;
    selectedRouteDates = new Set([...selectedRouteDates].filter(date => available.has(date)));
    document.getElementById('routeDateOptions').innerHTML = dates.length
        ? dates.map((date, index) => `<div class="form-check py-1">
            <input class="form-check-input route-date-check" type="checkbox" value="${esc(date)}" id="routeDate${index}" ${selectedRouteDates.has(date) ? 'checked' : ''}>
            <label class="form-check-label" for="routeDate${index}">${esc(routeDateLabel(date))}</label>
        </div>`).join('')
        : '<span class="text-muted">Este usuario no posee días planificados.</span>';
    updateRouteDateButton();
}

function refreshTargetRoutes() {
    const select = document.getElementById('targetRoute');
    const previous = select.value;
    const counts = new Map();
    baseRows.forEach(row => counts.set(String(row.route_key), (counts.get(String(row.route_key)) || 0) + 1));
    const available = routeTargets.filter(route => Number(route.repeticiones) > 0);
    select.innerHTML = '<option value="">Seleccionar destino</option>'
        + `<option value="${CREATE_ROUTE_KEY}">＋ CREAR NUEVA RUTA</option>`
        + `<option value="${UNASSIGNED_ROUTE_KEY}">— DEJAR SIN RUTA —</option>`
        + available.map(route => {
        const count = counts.get(String(route.key)) || 0;
        return `<option value="${esc(route.key)}">${esc(route.usuario_login)} · ${esc(route.grupo_ruta)} (${count} locales / ${esc(route.repeticiones)} fechas)</option>`;
    }).join('');
    select.disabled = !baseRows.length;
    if ([UNASSIGNED_ROUTE_KEY, CREATE_ROUTE_KEY].includes(previous) || available.some(route => String(route.key) === previous)) select.value = previous;
}

function normalizedRouteName(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toUpperCase();
}

function suggestNewRouteName() {
    const selectedUser = document.getElementById('routeUser').value;
    const names = routeTargets
        .filter(route => String(route.usuario_login) === selectedUser)
        .map(route => String(route.grupo_ruta || ''));
    const numbers = names
        .map(name => name.match(/(\d+)(?!.*\d)/))
        .filter(Boolean)
        .map(match => Number(match[1]));
    let next = numbers.length ? Math.max(...numbers) + 1 : 1;
    let suggestion;
    do {
        suggestion = `RUTA ${String(next++).padStart(2, '0')}`;
    } while (names.some(name => normalizedRouteName(name) === normalizedRouteName(suggestion)));
    return suggestion;
}

function resolveOriginalScheduleTarget(target) {
    let current = target;
    const visited = new Set();
    while (current?.is_new && current.source_route_key && !visited.has(String(current.key))) {
        visited.add(String(current.key));
        current = routeTargets.find(route => String(route.key) === String(current.source_route_key));
    }
    return current && Number(current.repeticiones) > 0 ? current : null;
}

function scheduleSourceForSelection(selectedRows) {
    const selectedUser = document.getElementById('routeUser').value;
    const candidates = routeTargets.filter(route => String(route.usuario_login) === selectedUser && Number(route.repeticiones) > 0);
    if (!candidates.length) return null;

    const counts = new Map();
    selectedRows.forEach(row => counts.set(String(row.route_key), (counts.get(String(row.route_key)) || 0) + 1));
    const ranked = candidates
        .filter(route => counts.has(String(route.key)))
        .sort((left, right) => (counts.get(String(right.key)) || 0) - (counts.get(String(left.key)) || 0));
    return resolveOriginalScheduleTarget(ranked[0] || candidates[0]);
}

function createRouteFromSelection() {
    const selectedRows = baseRows.filter(row => selectedRowKeys.has(String(row.row_key)));
    const selectedUser = document.getElementById('routeUser').value;
    const routeName = document.getElementById('newRouteName').value.trim().replace(/\s+/g, ' ');
    if (!selectedRows.length || !selectedUser) return;
    if (!routeName) {
        message('Ingresa un nombre para la nueva ruta.', 'warning');
        document.getElementById('newRouteName').focus();
        return;
    }
    if (routeTargets.some(route => String(route.usuario_login) === selectedUser && normalizedRouteName(route.grupo_ruta) === normalizedRouteName(routeName))) {
        message(`Ya existe una ruta llamada ${routeName} para este usuario.`, 'warning');
        return;
    }
    const scheduleSource = scheduleSourceForSelection(selectedRows);
    if (!scheduleSource) {
        message('No existe una ruta del usuario con fechas que permita asignar la frecuencia a la ruta nueva.', 'warning');
        return;
    }
    if (!window.confirm(`¿Deseas crear ${routeName} con ${selectedRows.length} local(es)? Las rutas involucradas se reordenarán automáticamente.`)) {
        return;
    }

    const key = `__NUEVA_RUTA__${Date.now()}_${++newRouteSequence}`;
    const firstRow = selectedRows[0];
    routeTargets.push({
        key,
        usuario_login: selectedUser,
        usuario_nombre: String(firstRow.usuario_nombre || selectedUser),
        grupo_ruta: routeName,
        locales: 0,
        repeticiones: Number(scheduleSource.repeticiones),
        fechas: Array.isArray(scheduleSource.fechas) ? [...scheduleSource.fechas] : [],
        source_route_key: String(scheduleSource.key),
        is_new: true
    });
    moveSelectedToRoute(key, 'new');
    document.getElementById('targetRoute').value = '';
    document.getElementById('newRouteName').value = '';
    delete document.getElementById('newRouteName').dataset.user;
    updateEditor();
}

function updateEditor() {
    document.getElementById('baseOnlyNotice').classList.toggle('d-none', !baseOnly);
    document.getElementById('baseRouteSelector').classList.toggle('d-none', !baseOnly);
    document.getElementById('scheduledRouteEditor').classList.toggle('d-none', baseOnly);
    document.getElementById('routeEditorTitle').innerHTML = baseOnly
        ? '<i class="fa-solid fa-route me-2"></i>Rutas agrupadas'
        : '<i class="fa-solid fa-pen-ruler me-2"></i>Editar rutero base';
    document.getElementById('selectedCount').classList.toggle('d-none', baseOnly);
    const selectedRows = baseRows.filter(row => selectedRowKeys.has(String(row.row_key)));
    document.getElementById('selectedCount').textContent = String(selectedRows.length);
    document.getElementById('selectedList').innerHTML = selectedRows.length
        ? selectedRows.slice(0, 12).map(row => `<div class="selected-item"><strong>${esc(row.codigo_local)}</strong> · ${esc(row.nombre)}<div class="text-muted">${esc(row.usuario_login)} · ${isUnassignedRoute(row.route_key) ? 'Sin ruta' : esc(row.grupo_ruta)}</div></div>`).join('') + (selectedRows.length > 12 ? `<div class="selected-item text-center text-muted">+${selectedRows.length - 12} locales seleccionados</div>` : '')
        : '<div class="p-3 small text-muted text-center">Aún no hay locales seleccionados.</div>';
    const editButton = document.getElementById('editModeButton');
    editButton.disabled = baseOnly || !baseRows.length || !document.getElementById('routeUser').value || !(map && window.google?.maps);
    editButton.className = editMode ? 'btn btn-warning w-100' : 'btn btn-outline-primary w-100';
    editButton.innerHTML = editMode
        ? '<i class="fa-solid fa-check me-2"></i>Selección activa'
        : '<i class="fa-solid fa-location-crosshairs me-2"></i>Activar selección';
    document.getElementById('clearSelectionButton').disabled = !selectedRows.length;
    const targetValue = document.getElementById('targetRoute').value;
    const creatingRoute = targetValue === CREATE_ROUTE_KEY;
    const newRouteFields = document.getElementById('newRouteFields');
    const newRouteName = document.getElementById('newRouteName');
    newRouteFields.classList.toggle('d-none', !creatingRoute);
    if (creatingRoute) {
        const selectedUser = document.getElementById('routeUser').value;
        if (newRouteName.dataset.user !== selectedUser) {
            newRouteName.value = suggestNewRouteName();
            newRouteName.dataset.user = selectedUser;
        }
        const scheduleSource = scheduleSourceForSelection(selectedRows);
        document.getElementById('newRouteScheduleHint').textContent = scheduleSource
            ? `La planificación conservará las ${scheduleSource.repeticiones} fecha(s) y frecuencia de ${scheduleSource.grupo_ruta}.`
            : 'El usuario necesita al menos una ruta con fechas para crear la planificación de la ruta nueva.';
    }
    const applyButton = document.getElementById('applyMoveButton');
    applyButton.disabled = !selectedRows.length || !targetValue || (creatingRoute && !newRouteName.value.trim());
    applyButton.innerHTML = creatingRoute
        ? '<i class="fa-solid fa-plus me-2"></i>Crear ruta y autoordenar'
        : targetValue === UNASSIGNED_ROUTE_KEY
        ? '<i class="fa-solid fa-link-slash me-2"></i>Dejar sin ruta'
        : '<i class="fa-solid fa-arrow-right-arrow-left me-2"></i>Mover y autoordenar';
    const state = document.getElementById('editState');
    state.classList.toggle('changed', dirty);
    state.innerHTML = dirty
        ? '<i class="fa-solid fa-triangle-exclamation me-1"></i>Hay cambios locales listos para descargar.'
        : '<i class="fa-solid fa-circle-info me-1"></i>Sin cambios pendientes.';
    document.getElementById('adjustedDownloadButton').disabled = !dirty;
    document.getElementById('restoreButton').disabled = !dirty;
}

function distanceKm(left, right) {
    if (!validCoordinate(left) || !validCoordinate(right)) return null;
    const radius = 6371.0088;
    const lat1 = Number(left.lat) * Math.PI / 180;
    const lat2 = Number(right.lat) * Math.PI / 180;
    const deltaLat = (Number(right.lat) - Number(left.lat)) * Math.PI / 180;
    const deltaLng = (Number(right.lng) - Number(left.lng)) * Math.PI / 180;
    const a = Math.sin(deltaLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(deltaLng / 2) ** 2;
    return radius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));
}

function autoOrderRoutes(routeKeys) {
    routeKeys.forEach(routeKey => {
        if (isUnassignedRoute(routeKey)) return;
        const routeRows = baseRows
            .filter(row => String(row.route_key) === String(routeKey))
            .sort((left, right) => Number(left.orden_visita) - Number(right.orden_visita));
        let candidates = routeRows.filter(validCoordinate);
        const withoutCoordinates = routeRows.filter(row => !validCoordinate(row));
        const ordered = [];
        if (candidates.length) {
            let current = candidates.shift();
            ordered.push(current);
            while (candidates.length) {
                let nearestIndex = 0;
                let nearestDistance = Infinity;
                candidates.forEach((candidate, index) => {
                    const distance = distanceKm(current, candidate);
                    if (distance !== null && distance < nearestDistance) {
                        nearestDistance = distance;
                        nearestIndex = index;
                    }
                });
                current = candidates.splice(nearestIndex, 1)[0];
                ordered.push(current);
            }
        }
        ordered.push(...withoutCoordinates);
        let total = 0;
        ordered.forEach((row, index) => {
            const segment = index === 0 ? 0 : (distanceKm(ordered[index - 1], row) || 0);
            total += segment;
            row.orden_visita = index + 1;
            row.tamano_ruta = ordered.length;
            row.distancia_desde_anterior_km = Number(segment.toFixed(2));
        });
        ordered.forEach(row => { row.distancia_total_ruta_km = Number(total.toFixed(2)); });
    });
}

function prepareDraggedSelection(row) {
    if (!editMode) return;
    const rowKey = String(row.row_key);
    if (!selectedRowKeys.has(rowKey)) {
        selectedRowKeys.clear();
        selectedRowKeys.add(rowKey);
        updateEditor();
    }
}

function pointToSegmentDistanceKm(point, start, end) {
    if (!validCoordinate(point) || !validCoordinate(start) || !validCoordinate(end)) return null;
    const referenceLat = (Number(point.lat) + Number(start.lat) + Number(end.lat)) / 3 * Math.PI / 180;
    const kmPerDegreeLat = 111.32;
    const kmPerDegreeLng = 111.32 * Math.cos(referenceLat);
    const startX = (Number(start.lng) - Number(point.lng)) * kmPerDegreeLng;
    const startY = (Number(start.lat) - Number(point.lat)) * kmPerDegreeLat;
    const endX = (Number(end.lng) - Number(point.lng)) * kmPerDegreeLng;
    const endY = (Number(end.lat) - Number(point.lat)) * kmPerDegreeLat;
    const segmentX = endX - startX;
    const segmentY = endY - startY;
    const segmentLengthSquared = segmentX ** 2 + segmentY ** 2;
    const projection = segmentLengthSquared > 0
        ? Math.max(0, Math.min(1, -(startX * segmentX + startY * segmentY) / segmentLengthSquared))
        : 0;
    return Math.hypot(startX + projection * segmentX, startY + projection * segmentY);
}

function closestRouteToPosition(position) {
    const selectedRows = baseRows.filter(row => selectedRowKeys.has(String(row.row_key)));
    const excludedRoutes = new Set(selectedRows.map(row => String(row.route_key)));
    const selectedUser = document.getElementById('routeUser').value;
    const allowedRoutes = new Set(routeTargets
        .filter(route => String(route.usuario_login) === selectedUser && Number(route.repeticiones) > 0 && !excludedRoutes.has(String(route.key)))
        .map(route => String(route.key)));
    const routeRows = new Map();
    baseRows.forEach(candidate => {
        const routeKey = String(candidate.route_key);
        if (!allowedRoutes.has(routeKey) || !validCoordinate(candidate)) return;
        if (!routeRows.has(routeKey)) routeRows.set(routeKey, []);
        routeRows.get(routeKey).push(candidate);
    });
    let result = null;
    routeRows.forEach((rows, routeKey) => {
        rows.sort((left, right) => Number(left.orden_visita) - Number(right.orden_visita));
        let routeDistance = Infinity;
        rows.forEach((candidate, index) => {
            routeDistance = Math.min(routeDistance, distanceKm(position, candidate) ?? Infinity);
            if (index > 0) {
                routeDistance = Math.min(routeDistance, pointToSegmentDistanceKm(position, rows[index - 1], candidate) ?? Infinity);
            }
        });
        if (!Number.isFinite(routeDistance) || (result && routeDistance >= result.distance)) return;
        const target = routeTargets.find(route => String(route.key) === routeKey);
        if (target) result = { target, distance: routeDistance };
    });
    return result;
}

function moveSelectedToRoute(targetKey, source = 'panel') {
    const unassigned = isUnassignedRoute(targetKey);
    const target = unassigned
        ? null
        : routeTargets.find(route => String(route.key) === String(targetKey) && Number(route.repeticiones) > 0);
    if ((!unassigned && !target) || !selectedRowKeys.size) return 0;
    const affectedRoutes = new Set();
    if (!unassigned) affectedRoutes.add(String(targetKey));
    let moved = 0;
    baseRows.forEach(row => {
        if (!selectedRowKeys.has(String(row.row_key))) return;
        if (!isUnassignedRoute(row.route_key)) affectedRoutes.add(String(row.route_key));
        if (unassigned) {
            row.route_key = UNASSIGNED_ROUTE_KEY;
            row.grupo_ruta = '';
            row.orden_visita = 0;
            row.tamano_ruta = 0;
            row.distancia_desde_anterior_km = 0;
            row.distancia_total_ruta_km = 0;
        } else {
            row.route_key = String(targetKey);
            row.usuario_login = target.usuario_login;
            row.usuario_nombre = target.usuario_nombre;
            row.grupo_ruta = target.grupo_ruta;
        }
        moved++;
    });
    autoOrderRoutes(affectedRoutes);
    routeTargets = routeTargets.filter(route => !route.is_new
        || baseRows.some(row => String(row.route_key) === String(route.key)));
    dirty = true;
    selectedRowKeys.clear();
    refreshUserFilter();
    refreshTargetRoutes();
    refreshRouteDateFilter();
    updateGeneralKpis();
    updateEditor();
    renderMap(true);
    const method = source === 'drag' ? 'mediante arrastre' : 'desde el panel';
    message(unassigned
        ? `${moved} local(es) quedaron sin ruta; sus rutas de origen fueron autoordenadas.`
        : source === 'new'
            ? `Se creó ${target.grupo_ruta} con ${moved} local(es); la ruta nueva y las rutas de origen quedaron autoordenadas.`
            : `${moved} local(es) fueron movidos ${method} a ${target.grupo_ruta}; las rutas afectadas quedaron autoordenadas.`, 'success');
    return moved;
}

function handleMarkerDragEnd(row, event) {
    if (!editMode || !event?.latLng) {
        renderMap(false);
        return;
    }
    prepareDraggedSelection(row);
    const droppedPosition = { lat: event.latLng.lat(), lng: event.latLng.lng() };
    const suggestion = closestRouteToPosition(droppedPosition);
    if (!suggestion) {
        message('No se encontró otra ruta visible con fechas y coordenadas para sugerir el traslado.', 'warning');
        renderMap(false);
        return;
    }

    const selectedRows = baseRows.filter(item => selectedRowKeys.has(String(item.row_key)));
    const sourceLabels = [...new Set(selectedRows.map(item => {
        const visual = routeVisual(item.route_key, item.grupo_ruta);
        return isUnassignedRoute(item.route_key) ? 'SR · Sin ruta' : `${visual.badge} · ${item.grupo_ruta}`;
    }))];
    const targetVisual = routeVisual(suggestion.target.key, suggestion.target.grupo_ruta);
    pendingDragMove = { targetKey: String(suggestion.target.key) };
    document.getElementById('dragRouteQuestion').textContent = selectedRows.length === 1
        ? 'El punto quedó más cerca de esta ruta. ¿Deseas mover el local seleccionado?'
        : `El punto quedó más cerca de esta ruta. ¿Deseas mover los ${selectedRows.length} locales seleccionados?`;
    document.getElementById('dragRouteFrom').textContent = sourceLabels.join(', ');
    document.getElementById('dragRouteTo').textContent = `${targetVisual.badge} · ${suggestion.target.usuario_login} · ${suggestion.target.grupo_ruta}`;
    document.getElementById('dragRouteDistance').textContent = `Referencia: el punto se soltó a ${suggestion.distance.toFixed(2)} km del tramo más cercano de esa ruta.`;

    if (window.bootstrap?.Modal) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('dragRouteModal')).show();
        return;
    }
    if (window.confirm(`${document.getElementById('dragRouteQuestion').textContent}\nDestino: ${document.getElementById('dragRouteTo').textContent}`)) {
        const targetKey = pendingDragMove.targetKey;
        pendingDragMove = null;
        moveSelectedToRoute(targetKey, 'drag');
    } else {
        pendingDragMove = null;
        renderMap(false);
    }
}

function clearSelectedSet() {
    const button = document.getElementById('downloadButton');
    button.href = '#';
    button.classList.add('disabled');
    button.setAttribute('aria-disabled', 'true');
    button.querySelector('i').className = 'fa-solid fa-file-excel me-2';
    button.querySelector('span').textContent = 'Descargar rutero';
    document.getElementById('selectedSetBanner').classList.add('d-none');
}

async function loadSets() {
    const division = document.getElementById('division').value;
    const subdivision = document.getElementById('subdivision').value;
    const status = document.getElementById('status').value;
    const setSelect = document.getElementById('set');

    clearSelectedSet();
    resetDataset();

    if (!division || !subdivision) {
        setSelect.disabled = true;
        setSelect.innerHTML = '<option value="">Seleccionar rutero</option>';
        return;
    }

    const params = new URLSearchParams({ action: 'sets', id_division: division, id_subdivision: subdivision });
    if (status) params.set('estado', status);
    setSelect.disabled = true;
    setSelect.innerHTML = '<option value="">Cargando ruteros...</option>';
    try {
        const data = await json(`${API}?${params}`);
        setSelect.innerHTML = '<option value="">Seleccionar rutero</option>' + data.data.map(row => {
            const countLabel = row.tipo_contenido === 'rutas_base' ? `${row.filas_validas} locales base · sin fechas` : `${row.filas_validas} visitas`;
            return `<option value="${row.id}" data-status="${esc(row.estado)}" data-file="${esc(row.archivo_original || '')}" data-original="${row.archivo_original_disponible ? '1' : '0'}" data-description="${esc(row.descripcion || '')}" data-count="${row.filas_validas}">${esc(row.nombre)} · ${esc(row.estado.toUpperCase())} · ${esc(countLabel)}</option>`;
        }).join('');
        setSelect.disabled = false;
        if (!data.data.length) resetDataset('No hay ruteros para los filtros seleccionados.');
    } catch (error) {
        setSelect.innerHTML = '<option value="">No fue posible cargar</option>';
        message(error.message);
    }
}

document.getElementById('division').addEventListener('change', async event => {
    clearMessage();
    const subdivision = document.getElementById('subdivision');
    subdivision.disabled = true;
    subdivision.innerHTML = '<option value="">Cargando...</option>';
    clearSelectedSet();
    resetDataset();
    if (!event.target.value) {
        subdivision.innerHTML = '<option value="">Seleccionar subdivisión</option>';
        document.getElementById('set').disabled = true;
        return;
    }
    try {
        const data = await json(`${API}?action=catalogs&id_division=${encodeURIComponent(event.target.value)}`);
        fill(subdivision, data.subdivisions, 'Seleccionar subdivisión');
    } catch (error) {
        message(error.message);
    }
});

document.getElementById('subdivision').addEventListener('change', loadSets);
document.getElementById('status').addEventListener('change', loadSets);

document.getElementById('set').addEventListener('change', async event => {
    const button = document.getElementById('downloadButton');
    const option = event.target.selectedOptions[0];

    resetDataset();
    clearSelectedSet();
    clearMessage();
    if (!event.target.value) {
        return;
    }

    button.href = `${API}?action=download&id_set=${event.target.value}`;
    button.classList.remove('disabled');
    button.removeAttribute('aria-disabled');
    button.querySelector('i').className = option.dataset.original === '1' ? 'fa-solid fa-file-excel me-2' : 'fa-solid fa-file-csv me-2';
    button.querySelector('span').textContent = option.dataset.original === '1' ? 'Descargar Excel original' : 'Descargar data';
    document.getElementById('selectedSetName').textContent = option.textContent.split(' · ')[0];
    document.getElementById('selectedSetDescription').textContent = `${option.dataset.description || 'Rutero publicado'} · cargando rutas base...`;
    document.getElementById('selectedSetFile').innerHTML = `<i class="fa-solid fa-file-excel me-1"></i>${esc(option.dataset.file || 'Exportación disponible')}`;
    document.getElementById('selectedSetBanner').classList.remove('d-none');
    setMapEmpty('Cargando rutero base...', true);
    const request = new AbortController();
    ruteroRequest = request;
    setRuteroLoading(true, option.textContent.split(' · ')[0]);

    try {
        const data = await json(`${API}?action=base_routes&id_set=${encodeURIComponent(event.target.value)}`, { signal: request.signal });
        if (ruteroRequest !== request || request.signal.aborted) return;
        baseRows = Array.isArray(data.data) ? data.data : [];
        originalRows = cloneRows(baseRows);
        routeTargets = Array.isArray(data.targets) ? data.targets : [];
        originalRouteTargets = cloneRows(routeTargets);
        periodStart = String(data.period_start || '');
        periodEnd = String(data.period_end || '');
        baseOnly = data.tipo_contenido === 'rutas_base';
        const periodLabel = periodStart && periodEnd ? ` · período ${formatDate(periodStart)} a ${formatDate(periodEnd)}` : '';
        const scheduleLabel = baseOnly ? ' · rutas agrupadas sin fechas de visita' : periodLabel;
        document.getElementById('selectedSetDescription').textContent = `${option.dataset.description || 'Rutero publicado'} · ${baseRows.length} locales base${scheduleLabel}`;
        updateGeneralKpis();
        refreshUserFilter();
        refreshBaseRouteSelector(true);
        refreshTargetRoutes();
        refreshRouteDateFilter();
        updateEditor();
        renderMap(true);
        if (!baseRows.length) setMapEmpty('El archivo no contiene locales en el rutero base.', true);
    } catch (error) {
        if (ruteroRequest !== request || request.signal.aborted) return;
        resetDataset('No fue posible cargar el rutero base.');
        clearSelectedSet();
        message(error.message);
    } finally {
        if (ruteroRequest === request) {
            ruteroRequest = null;
            setRuteroLoading(false);
        }
    }
});

document.getElementById('routeUser').addEventListener('change', () => {
    selectedRowKeys.clear();
    selectedRouteDates.clear();
    routeDateFilterActive = false;
    editMode = false;
    if (map) map.setOptions({ draggable: true, draggableCursor: null });
    refreshBaseRouteSelector(true);
    refreshRouteDateFilter();
    updateEditor();
    renderMap(true);
});
document.getElementById('targetRoute').addEventListener('change', updateEditor);

document.getElementById('baseRouteOptions').addEventListener('change', event => {
    if (!baseOnly || !event.target.classList.contains('base-route-check')) return;
    const key = String(event.target.value);
    if (!baseRouteOptionsForUser().some(route => route.key === key)) return;
    if (event.target.checked) selectedBaseRouteKeys.add(key);
    else selectedBaseRouteKeys.delete(key);
    updateBaseRouteSelectionCount();
    renderMap(true);
});

document.getElementById('allBaseRoutesButton').addEventListener('click', () => {
    if (!baseOnly) return;
    refreshBaseRouteSelector(true);
    renderMap(true);
});

document.getElementById('clearBaseRoutesButton').addEventListener('click', () => {
    if (!baseOnly) return;
    selectedBaseRouteKeys.clear();
    refreshBaseRouteSelector();
    renderMap(true);
});
document.getElementById('newRouteName').addEventListener('input', updateEditor);

document.getElementById('routeDateOptions').addEventListener('change', event => {
    if (!event.target.classList.contains('route-date-check')) return;
    routeDateFilterActive = true;
    if (event.target.checked) selectedRouteDates.add(String(event.target.value));
    else selectedRouteDates.delete(String(event.target.value));
    selectedRowKeys.clear();
    updateRouteDateButton();
    updateEditor();
    renderMap(true);
});

document.getElementById('allRouteDatesButton').addEventListener('click', () => {
    selectedRouteDates.clear();
    routeDateFilterActive = false;
    selectedRowKeys.clear();
    refreshRouteDateFilter();
    updateEditor();
    renderMap(true);
});

document.getElementById('clearRouteDatesButton').addEventListener('click', () => {
    selectedRouteDates.clear();
    routeDateFilterActive = true;
    selectedRowKeys.clear();
    refreshRouteDateFilter();
    updateEditor();
    renderMap(true);
});

document.getElementById('editModeButton').addEventListener('click', () => {
    editMode = !editMode;
    clearAreaSelection();
    if (map) map.setOptions({ draggable: !editMode, draggableCursor: editMode ? 'crosshair' : null });
    updateEditor();
    renderMap(false);
});

document.getElementById('clearSelectionButton').addEventListener('click', () => {
    selectedRowKeys.clear();
    updateEditor();
    renderMap(false);
});

document.getElementById('applyMoveButton').addEventListener('click', () => {
    const targetKey = document.getElementById('targetRoute').value;
    if (targetKey === CREATE_ROUTE_KEY) {
        createRouteFromSelection();
        return;
    }
    if (targetKey === UNASSIGNED_ROUTE_KEY) {
        const total = selectedRowKeys.size;
        const confirmed = window.confirm(`¿Deseas dejar ${total} local(es) sin ruta? No se incluirán en la planificación por fechas hasta que vuelvan a asignarse a una ruta.`);
        if (!confirmed) return;
    }
    moveSelectedToRoute(targetKey, 'panel');
});

document.getElementById('confirmDragMoveButton').addEventListener('click', () => {
    if (!pendingDragMove) return;
    const targetKey = pendingDragMove.targetKey;
    pendingDragMove = null;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('dragRouteModal')).hide();
    moveSelectedToRoute(targetKey, 'drag');
});

document.getElementById('dragRouteModal').addEventListener('hidden.bs.modal', () => {
    if (!pendingDragMove) return;
    pendingDragMove = null;
    renderMap(false);
});

document.getElementById('restoreButton').addEventListener('click', () => {
    baseRows = cloneRows(originalRows);
    routeTargets = cloneRows(originalRouteTargets);
    selectedRowKeys.clear();
    dirty = false;
    refreshUserFilter();
    refreshTargetRoutes();
    refreshRouteDateFilter();
    updateGeneralKpis();
    updateEditor();
    renderMap(true);
    message('Se restauró el rutero base original.', 'info');
});

document.getElementById('adjustedDownloadButton').addEventListener('click', async event => {
    const setId = document.getElementById('set').value;
    if (!setId || !dirty) return;
    const button = event.currentTarget;
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Replanificando...';
    clearMessage();
    try {
        const formData = new FormData();
        formData.set('action', 'download_base_adjusted');
        formData.set('csrf_token', CSRF);
        formData.set('id_set', setId);
        formData.set('rows', JSON.stringify(baseRows.map(row => ({
            row_key: row.row_key,
            route_key: row.route_key,
            orden_visita: row.orden_visita
        }))));
        formData.set('new_routes', JSON.stringify(routeTargets
            .filter(route => route.is_new && baseRows.some(row => String(row.route_key) === String(route.key)))
            .map(route => ({
                key: route.key,
                grupo_ruta: route.grupo_ruta,
                source_route_key: route.source_route_key
            }))));
        const response = await fetch(API, { method: 'POST', body: formData });
        if (!response.ok) {
            const raw = await response.text();
            let errorData = {};
            try { errorData = JSON.parse(raw); } catch (error) { /* respuesta no JSON */ }
            throw new Error(errorData.message || `No fue posible generar el archivo (HTTP ${response.status}).`);
        }
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
        const filename = filenameMatch ? filenameMatch[1] : `planificacion_ajustada_${setId}.xlsx`;
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        message('Se descargó la planificación ajustada sin modificar el archivo publicado.', 'success');
    } catch (error) {
        message(error.message);
    } finally {
        button.innerHTML = originalHtml;
        updateEditor();
    }
});

(async () => {
    try {
        const data = await json(`${API}?action=catalogs`);
        fill(document.getElementById('division'), data.divisions, 'Seleccionar división');
    } catch (error) {
        message(error.message);
    }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($mapsKey !== ''): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsKey, ENT_QUOTES, 'UTF-8') ?>&callback=initMap"></script>
<?php else: ?>
<script>message('Falta configurar google_maps_browser_api_key en config/maps_config.php.');</script>
<?php endif; ?>
</body>
</html>

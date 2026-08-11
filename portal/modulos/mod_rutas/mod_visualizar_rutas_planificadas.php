<?php
session_start();
date_default_timezone_set('America/Santiago');

if (empty($_SESSION['route_optimizer_csrf'])) {
    $_SESSION['route_optimizer_csrf'] = bin2hex(random_bytes(32));
}

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: text/html; charset=UTF-8');

$db = isset($conn) && $conn instanceof mysqli ? $conn : null;
$divisionSesionId = (int)($_SESSION['division_id'] ?? 0);
$divisionSesionNombre = '';
$divisionesDisponibles = [];

if ($db) {
    mysqli_set_charset($db, 'utf8mb4');
    if ($divisionSesionId > 0) {
        $stmtDivisionSesion = $db->prepare('SELECT nombre FROM division_empresa WHERE id=? LIMIT 1');
        if ($stmtDivisionSesion) {
            $stmtDivisionSesion->bind_param('i', $divisionSesionId);
            $stmtDivisionSesion->execute();
            $stmtDivisionSesion->bind_result($divisionSesionNombre);
            $stmtDivisionSesion->fetch();
            $stmtDivisionSesion->close();
        }
    }

    $esMc = strtoupper(trim($divisionSesionNombre)) === 'MC';
    if ($esMc) {
        $resultDivisiones = $db->query('SELECT id,nombre FROM division_empresa WHERE estado=1 ORDER BY nombre');
        if ($resultDivisiones) {
            while ($division = $resultDivisiones->fetch_assoc()) {
                $divisionesDisponibles[] = $division;
            }
        }
    } elseif ($divisionSesionId > 0) {
        $divisionesDisponibles[] = ['id' => $divisionSesionId, 'nombre' => $divisionSesionNombre];
    }
} else {
    $esMc = false;
}

$mapsConfigPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/visibility2/portal/config/maps_config.php';
$mapsConfig = is_file($mapsConfigPath) ? include $mapsConfigPath : [];
$googleMapsBrowserKey = is_array($mapsConfig)
    ? trim((string)($mapsConfig['google_maps_browser_api_key'] ?? $mapsConfig['google_maps_api_key'] ?? ''))
    : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visualizador de Rutas Planificadas - Visibility 2</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        .container-fluid {
            padding: 28px;
        }

        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.08);
        }

        .card-header {
            border-top-left-radius: 14px !important;
            border-top-right-radius: 14px !important;
            font-weight: 600;
        }

        .stat-card {
            padding: 18px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,.08);
            height: 100%;
        }

        .stat-number {
            font-size: 1.7rem;
            font-weight: 700;
            color: #004AAD;
            line-height: 1;
        }

        .stat-label {
            color: #6c757d;
            margin-top: 8px;
            font-size: .95rem;
        }

        .nav-tabs .nav-link {
            font-weight: 600;
        }

        #mapRutas {
            height: 620px;
            border-radius: 14px;
            background: #e9ecef;
        }

        .map-wrapper {
            position: relative;
        }

        .map-selection-layer {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: none;
            cursor: crosshair;
            user-select: none;
            touch-action: none;
        }

        .map-selection-layer.active {
            display: block;
        }

        .map-selection-box {
            position: absolute;
            display: none;
            border: 2px solid #0d6efd;
            background: rgba(13,110,253,.16);
            pointer-events: none;
        }

        .map-selection-counter {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,.96);
            box-shadow: 0 4px 16px rgba(0,0,0,.18);
            border: 1px solid #dbe4f0;
        }

        .map-selection-counter strong {
            min-width: 24px;
            text-align: center;
            color: #0d6efd;
            font-size: 1.05rem;
        }

        .selection-mode-active {
            background: #0d6efd !important;
            border-color: #0d6efd !important;
            color: #fff !important;
        }

        .table thead th {
            background: #004AAD;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .table-responsive {
            max-height: 420px;
            overflow: auto;
        }

        .table-sm td, .table-sm th {
            vertical-align: middle;
        }

        .clickable-row {
            cursor: pointer;
        }

        .clickable-row:hover {
            background: #eef4ff !important;
        }

        .route-color-box {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }

        .unplanned-row {
            background: #fff1f1 !important;
            color: #9f1239;
        }

        .selected-row {
            box-shadow: inset 4px 0 0 #0d6efd;
            background: #eaf2ff !important;
        }

        .selection-toolbar {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 10px 0;
        }

        .empty-box {
            background: #fff;
            border: 1px dashed #ced4da;
            border-radius: 14px;
            padding: 30px;
            color: #6c757d;
            text-align: center;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: 18px;
            margin-bottom: 8px;
        }

        .legend-line {
            width: 26px;
            height: 4px;
            border-radius: 999px;
            display: inline-block;
        }

        .mini-note {
            font-size: .9rem;
            color: #6c757d;
        }

        .summary-pill {
            display: inline-block;
            margin-right: 8px;
            margin-bottom: 8px;
            padding: .5rem .75rem;
            border-radius: 999px;
            background: #eef4ff;
            color: #004AAD;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary mb-2">
            <i class="fa-solid fa-route"></i> Visualizador de Rutas Planificadas
        </h2>
        <p class="text-muted mb-0">
            Sube un CSV con código de local y usuario. Las coordenadas se obtienen desde SQL y, si faltan, se intenta validarlas con Google.
        </p>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <ul class="nav nav-tabs" id="tabsRutas" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-carga-btn" data-bs-toggle="tab" data-bs-target="#tab-carga" type="button" role="tab">
                        <i class="fa-solid fa-upload"></i> Cargar archivo
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-mapa-btn" data-bs-toggle="tab" data-bs-target="#tab-mapa" type="button" role="tab">
                        <i class="fa-solid fa-map-location-dot"></i> Mapa de rutas
                    </button>
                </li>
            </ul>

            <div class="tab-content pt-4">
                <div class="tab-pane fade show active" id="tab-carga" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <i class="fa-solid fa-file-csv"></i> Cargar locales y usuarios
                                </div>
                                <div class="card-body">
                                    <form id="formUploadPlanificacion" enctype="multipart/form-data">
                                        <input type="hidden" id="routeOptimizerCsrf" value="<?php echo htmlspecialchars($_SESSION['route_optimizer_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="mb-3">
                                            <label for="idDivisionPlanificacion" class="form-label fw-semibold">División</label>
                                            <?php if ($esMc): ?>
                                                <select class="form-select" id="idDivisionPlanificacion" name="id_division" required>
                                                    <option value="">Selecciona una división</option>
                                                    <?php foreach ($divisionesDisponibles as $division): ?>
                                                        <option value="<?php echo (int)$division['id']; ?>"><?php echo htmlspecialchars($division['nombre'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" id="idDivisionPlanificacion" name="id_division" value="<?php echo $divisionSesionId; ?>">
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($divisionSesionNombre, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label for="archivoPlanificacion" class="form-label fw-semibold">Archivo CSV</label>
                                            <input
                                                type="file"
                                                class="form-control"
                                                id="archivoPlanificacion"
                                                name="csvFile"
                                                accept=".csv"
                                                required
                                            >
                                        </div>

                                        <div class="mb-3 mini-note" style="display:none;">
                                            El sistema leerá la hoja <strong>Planificacion</strong> del archivo exportado.
                                        </div>

                                        <div class="mb-3 mini-note">
                                            El archivo debe contener las columnas <strong>codigo</strong> y <strong>usuario</strong>.
                                            Dirección, comuna, latitud y longitud se consultan automáticamente desde SQL.
                                        </div>

                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="submit" class="btn btn-success" id="btnCargarPlanificacion">
                                                <i class="fa-solid fa-cloud-arrow-up"></i> Procesar archivo
                                            </button>
                                            <button type="button" class="btn btn-outline-primary" id="btnDescargarTemplateRutas">
                                                <i class="fa-solid fa-file-arrow-down"></i> Descargar template
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="card">
                                <div class="card-header bg-dark text-white">
                                    <i class="fa-solid fa-circle-info"></i> Qué espera esta vista
                                </div>
                                <div class="card-body">
                                    <div class="mini-note">
                                        El archivo sólo asigna cada local a un usuario. El mapa y el optimizador usan la dirección y las coordenadas guardadas en la tabla <strong>local</strong>.
                                        Address Validation se consulta únicamente cuando esas coordenadas faltan y el resultado queda guardado para próximas cargas.
                                    </div>
                                    <hr>
                                    <div class="mb-2"><strong>Campos esperados:</strong></div>
                                    <div class="summary-pill">codigo</div>
                                    <div class="summary-pill">usuario</div>
                                    <div class="mt-3 small text-muted">Admite coma, punto y coma o tabulación como delimitador.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="uploadResultBox" class="mt-4" style="display:none;"></div>
                </div>

                <div class="tab-pane fade" id="tab-mapa" role="tabpanel">
                    <div id="bloqueResumen" style="display:none;">
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalGrupos">0</div>
                                    <div class="stat-label">Grupos de ruta</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalPuntos">0</div>
                                    <div class="stat-label">Puntos georreferenciados</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statTotalKm">0</div>
                                    <div class="stat-label">KM estimados</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-number" id="statFilasIgnoradas">0</div>
                                    <div class="stat-label">Locales sin ruta</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="emptyMapState" class="empty-box">
                        <i class="fa-solid fa-map-location-dot fa-2x mb-3"></i>
                        <div class="fw-semibold mb-1">Todavía no hay rutas cargadas</div>
                        <div>Sube el archivo desde la pestaña <strong>Cargar archivo</strong>.</div>
                    </div>

                    <div id="bloqueMapa" style="display:none;">
                        <div class="row g-4">
                            <div class="col-lg-3">
                                <div class="card mb-4">
                                    <div class="card-header bg-primary text-white">
                                        <i class="fa-solid fa-filter"></i> Filtros
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="filtroUsuario" class="form-label fw-semibold">Usuario</label>
                                            <select id="filtroUsuario" class="form-select">
                                                <option value="__ALL__">Todos los usuarios</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="filtroFechaRuta" class="form-label fw-semibold">Fecha planificada</label>
                                            <select id="filtroFechaRuta" class="form-select">
                                                <option value="__ALL__">Todas las fechas</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="filtroGrupoRuta" class="form-label fw-semibold">Grupo de ruta</label>
                                            <select id="filtroGrupoRuta" class="form-select">
                                                <option value="__ALL__">Todas las rutas</option>
                                            </select>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <button class="btn btn-outline-primary" id="btnAjustarMapa" type="button">
                                                <i class="fa-solid fa-expand"></i> Ajustar mapa
                                            </button>
                                            <button class="btn btn-outline-secondary" id="btnVerTodas" type="button">
                                                <i class="fa-solid fa-layer-group"></i> Ver todo
                                            </button>
                                            <button class="btn btn-outline-primary" id="btnModoSeleccion" type="button">
                                                <i class="fa-solid fa-vector-square"></i> Seleccionar por área
                                            </button>
                                            <button class="btn btn-warning" id="btnEditarSeleccionados" type="button" disabled>
                                                <i class="fa-solid fa-pen-to-square"></i> Editar seleccionados
                                            </button>
                                            <button class="btn btn-success" id="btnDescargarEditado" type="button">
                                                <i class="fa-solid fa-file-arrow-down"></i> Descargar modificado
                                            </button>
                                        </div>

                                        <hr>

                                        <div class="mini-note mb-2">
                                            Puedes filtrar primero por usuario, luego por fecha y finalmente por grupo de ruta.
                                        </div>

                                        <div class="mini-note mb-3">
                                            <span class="route-color-box" style="background:#dc2626;"></span>
                                            Rojo: local sin ruta o fecha planificada.
                                        </div>

                                        <div class="mini-note mb-3">
                                            Activa <strong>Seleccionar por área</strong>, mantén presionado el clic y arrastra
                                            para encerrar varios locales.
                                            Puedes dibujar varias áreas para acumular locales.
                                        </div>

                                        <div id="leyendaRutas"></div>
                                    </div>
                                </div>

                                <div class="card mb-4">
                                    <div class="card-header bg-info text-white">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Planificador optimizado
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="optMetaDiaria" class="form-label fw-semibold">Carga objetivo por día</label>
                                            <input type="number" min="2" max="30" class="form-control" id="optMetaDiaria" value="19">
                                            <div class="mini-note mt-1">Es una meta flexible: prioriza cercanía geográfica, admite grupos mayores y procura que ninguno quede bajo el 80% de la meta.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optAislamientoKm" class="form-label fw-semibold">Separación para considerar aislado</label>
                                            <div class="input-group">
                                                <input type="number" min="2" max="150" step="1" class="form-control" id="optAislamientoKm" value="25">
                                                <span class="input-group-text">km</span>
                                            </div>
                                            <div class="mini-note mt-1">Los grupos pequeños desconectados por más de esta distancia quedan sin ruta.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optFechaInicio" class="form-label fw-semibold">Fecha inicio</label>
                                            <input type="date" class="form-control" id="optFechaInicio">
                                        </div>

                                        <div class="mb-3">
                                            <label for="optPrefijoGrupo" class="form-label fw-semibold">Prefijo ruta</label>
                                            <input type="text" class="form-control" id="optPrefijoGrupo" value="RUTA OPT">
                                        </div>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="optSoloSeleccionados" checked>
                                            <label class="form-check-label" for="optSoloSeleccionados">
                                                Usar seleccionados si existen
                                            </label>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <button class="btn btn-info text-white" id="btnOptimizarRutas" type="button">
                                                <i class="fa-solid fa-route"></i> Generar rutas optimizadas
                                            </button>
                                        </div>

                                        <div class="mini-note mt-3" id="optimizadorEstado">
                                            Detecta clústeres geográficos por usuario y después ordena cada ruta con Google.
                                        </div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header bg-dark text-white">
                                        <i class="fa-solid fa-list"></i> Resumen por grupo
                                    </div>
                                    <div class="card-body table-responsive" style="max-height:350px;">
                                        <table class="table table-sm table-hover mb-0" id="tablaGruposResumen">
                                            <thead>
                                                <tr>
                                                    <th>Ruta</th>
                                                    <th>Usuario</th>
                                                    <th>Fecha</th>
                                                    <th>Paradas</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="card mb-4">
                                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                        <span><i class="fa-solid fa-map"></i> Mapa de rutas</span>
                                        <span class="badge bg-light text-dark" id="badgeRutaActiva">Todas las rutas</span>
                                    </div>
                                    <div class="card-body">
                                        <div class="map-wrapper">
                                            <div id="mapRutas"></div>
                                            <div class="map-selection-layer" id="mapSelectionLayer">
                                                <div class="map-selection-box" id="mapSelectionBox"></div>
                                            </div>
                                            <div class="map-selection-counter">
                                                <i class="fa-solid fa-location-dot text-primary"></i>
                                                <span>Seleccionados</span>
                                                <strong id="mapSelectedCount">0</strong>
                                                <button type="button" class="btn btn-sm btn-outline-danger" id="btnLimpiarSeleccion" title="Limpiar selección">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <i class="fa-solid fa-location-dot"></i> Detalle de puntos
                                    </div>
                                    <div class="card-body table-responsive">
                                        <div class="selection-toolbar d-flex justify-content-between align-items-center">
                                            <div>
                                                <input class="form-check-input me-2" type="checkbox" id="checkSeleccionarVisibles">
                                                <label for="checkSeleccionarVisibles" class="form-check-label">
                                                    Seleccionar visibles
                                                </label>
                                            </div>
                                            <span class="badge bg-primary" id="badgeSeleccionados">0 seleccionados</span>
                                        </div>
                                        <table class="table table-hover table-sm mb-0" id="tablaDetalleRuta">
                                            <thead>
                                                <tr>
                                                    <th></th>
                                                    <th>Usuario</th>
                                                    <th>Fecha</th>
                                                    <th>Grupo</th>
                                                    <th>Día Plan</th>
                                                    <th>Orden</th>
                                                    <th>Código</th>
                                                    <th>Nombre</th>
                                                    <th>Dirección</th>
                                                    <th>Comuna</th>
                                                    <th>Día</th>
                                                    <th>Semana</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarRuta" tabindex="-1" aria-labelledby="modalEditarRutaLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarRutaLabel">
                    <i class="fa-solid fa-route"></i> Editar planificación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2">
                    Se actualizarán <strong id="modalCantidadSeleccionados">0</strong> local(es).
                    Deja un campo vacío para conservar su valor actual.
                </div>

                <div class="mb-3">
                    <label for="editFechaRuta" class="form-label fw-semibold">Nueva fecha</label>
                    <input type="date" class="form-control" id="editFechaRuta">
                </div>

                <div class="mb-3">
                    <label for="editGrupoRuta" class="form-label fw-semibold">Grupo de ruta</label>
                    <input type="text" class="form-control" id="editGrupoRuta" list="listaGruposRuta" placeholder="Ej: RUTA 01">
                    <datalist id="listaGruposRuta"></datalist>
                </div>

                <div class="mb-3">
                    <label for="editOrdenVisita" class="form-label fw-semibold">Orden inicial</label>
                    <input type="number" min="1" class="form-control" id="editOrdenVisita" placeholder="Se incrementa para varios locales">
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Orden de visita</label>
                        <span class="mini-note">Usa las flechas para reorganizar las paradas seleccionadas.</span>
                    </div>
                    <div class="table-responsive" style="max-height:280px;">
                        <table class="table table-sm table-hover align-middle mb-0" id="tablaOrdenEdicion">
                            <thead>
                                <tr>
                                    <th style="width:80px;">Orden</th>
                                    <th style="width:120px;">Codigo</th>
                                    <th>Local</th>
                                    <th>Comuna</th>
                                    <th style="width:110px;">Mover</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="editUsuarioNombre" class="form-label fw-semibold">Usuario</label>
                    <input type="text" class="form-control" id="editUsuarioNombre" placeholder="Nombre del usuario">
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="editQuitarRuta">
                    <label class="form-check-label text-danger" for="editQuitarRuta">
                        Dejar los locales sin ruta planificada
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnAplicarEdicion">
                    <i class="fa-solid fa-check"></i> Aplicar cambios
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
let mapaRutas;
let infoWindowRutas;
let directionsServiceRutas;
let markersRutas = [];
let polylinesRutas = [];

let planRows = [];
let planGroups = [];
let planSummary = {};
let planFilters = { usuarios: [], fechas: [], grupos: [] };
let selectedRowIds = new Set();
let editOrderRows = [];
let modalEditarRuta;
let mapSelectionMode = false;
let mapProjectionOverlay;
let selectionDragStart = null;
let optimizationWarnings = [];

const routePalette = [
    '#d32f2f', '#1976d2', '#388e3c', '#f57c00', '#7b1fa2',
    '#0097a7', '#5d4037', '#c2185b', '#455a64', '#7cb342',
    '#8e24aa', '#039be5', '#fb8c00', '#43a047', '#e53935',
    '#6d4c41', '#546e7a', '#00acc1', '#8bc34a', '#ff7043'
];

function initMapRutas() {
    mapaRutas = new google.maps.Map(document.getElementById('mapRutas'), {
        center: { lat: -33.45, lng: -70.66 },
        zoom: 5,
        mapTypeId: 'roadmap'
    });

    infoWindowRutas = new google.maps.InfoWindow();

    mapProjectionOverlay = new google.maps.OverlayView();
    mapProjectionOverlay.onAdd = function() {};
    mapProjectionOverlay.draw = function() {};
    mapProjectionOverlay.onRemove = function() {};
    mapProjectionOverlay.setMap(mapaRutas);

    initializeMapAreaSelection();
}

function getRouteColor(groupName) {
    const idx = Math.abs(hashCode(groupName)) % routePalette.length;
    return routePalette[idx];
}

function hashCode(str) {
    let hash = 0;
    const safe = String(str || '');
    for (let i = 0; i < safe.length; i++) {
        hash = ((hash << 5) - hash) + safe.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function clearMapRutas() {
    markersRutas.forEach(marker => marker.setMap(null));
    polylinesRutas.forEach(poly => poly.setMap(null));
    markersRutas = [];
    polylinesRutas = [];
}

function getCurrentFilters() {
    return {
        usuario: $('#filtroUsuario').val() || '__ALL__',
        fecha: $('#filtroFechaRuta').val() || '__ALL__',
        grupo: $('#filtroGrupoRuta').val() || '__ALL__'
    };
}

function getFilteredRows() {
    const filters = getCurrentFilters();

    return planRows.filter(row => {
        const okUsuario = filters.usuario === '__ALL__'
            || String(row.usuario_id) === String(filters.usuario)
            || String(row.usuario_login) === String(filters.usuario);

        const okFecha = filters.fecha === '__ALL__'
            || String(row.fecha_ruta_sql) === String(filters.fecha)
            || String(row.fecha_ruta) === String(filters.fecha);

        const okGrupo = filters.grupo === '__ALL__'
            || (filters.grupo === '__UNPLANNED__' && Boolean(row.sin_ruta))
            || String(row.grupo_ruta) === String(filters.grupo);

        return okUsuario && okFecha && okGrupo;
    });
}

function hasUsableCoordinates(row) {
    if (row.lat === null || row.lat === '' || row.lng === null || row.lng === '') return false;
    const lat = Number(row.lat);
    const lng = Number(row.lng);
    return Number.isFinite(lat) && Number.isFinite(lng)
        && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
        && (Math.abs(lat) > 0.0001 || Math.abs(lng) > 0.0001);
}

function getFilteredGroups() {
    const rows = getFilteredRows();
    const groupMap = {};

    rows.forEach(row => {
        const key = row.sin_ruta ? '__UNPLANNED__' : row.grupo_ruta;
        if (!groupMap[key]) {
            groupMap[key] = {
                grupo_ruta: row.sin_ruta ? 'SIN RUTA' : row.grupo_ruta,
                group_key: key,
                ruta_global: row.ruta_global || '',
                usuario_id: row.usuario_id || '',
                usuario_login: row.usuario_login || '',
                usuario_nombre: row.usuario_nombre || '',
                fecha_ruta: row.fecha_ruta || '',
                fecha_ruta_sql: row.fecha_ruta_sql || '',
                total_paradas: 0,
                distancia_total_ruta_km: Number(row.distancia_total_ruta_km || 0)
            };
        }
        groupMap[key].total_paradas++;
    });

    return Object.values(groupMap).sort((a, b) => {
        const cmpUsuario = String(a.usuario_nombre).localeCompare(String(b.usuario_nombre));
        if (cmpUsuario !== 0) return cmpUsuario;

        const cmpFecha = String(a.fecha_ruta_sql).localeCompare(String(b.fecha_ruta_sql));
        if (cmpFecha !== 0) return cmpFecha;

        return String(a.grupo_ruta).localeCompare(String(b.grupo_ruta));
    });
}

function renderSummary() {
    const rows = getFilteredRows();
    const geoRows = rows.filter(hasUsableCoordinates);
    const groups = getFilteredGroups();
    const totalKm = groups.reduce((acc, g) => acc + Number(g.distancia_total_ruta_km || 0), 0);

    $('#statTotalGrupos').text(groups.length);
    $('#statTotalPuntos').text(geoRows.length);
    $('#statTotalKm').text(totalKm.toFixed(2));
    $('#statFilasIgnoradas').text(planSummary.sin_ruta || 0);

    $('#bloqueResumen').show();
}

function renderUsuarioSelect() {
    const select = $('#filtroUsuario').empty();
    select.append('<option value="__ALL__">Todos los usuarios</option>');

    (planFilters.usuarios || []).forEach(user => {
        const value = user.usuario_id || user.usuario_login;
        const label = user.usuario_nombre || user.usuario_login || user.usuario_id;
        select.append(`<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`);
    });
}

function renderFechaSelect() {
    const select = $('#filtroFechaRuta').empty();
    select.append('<option value="__ALL__">Todas las fechas</option>');

    (planFilters.fechas || []).forEach(fecha => {
        const value = fecha.fecha_ruta_sql || fecha.fecha_ruta;
        const label = fecha.fecha_ruta || fecha.fecha_ruta_sql;
        select.append(`<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`);
    });
}

function renderGroupSelect() {
    const currentRows = getFilteredRows();
    const hasUnplanned = currentRows.some(row => row.sin_ruta);
    const availableGroups = [...new Set(
        currentRows.filter(row => !row.sin_ruta && row.grupo_ruta).map(row => row.grupo_ruta)
    )].sort((a, b) => String(a).localeCompare(String(b)));
    const currentValue = $('#filtroGrupoRuta').val() || '__ALL__';

    const select = $('#filtroGrupoRuta').empty();
    select.append('<option value="__ALL__">Todas las rutas</option>');
    if (hasUnplanned) {
        const totalSinRuta = currentRows.filter(row => row.sin_ruta).length;
        select.append(`<option value="__UNPLANNED__">SIN RUTA · ${totalSinRuta} locales</option>`);
    }

    availableGroups.forEach(groupName => {
        const total = currentRows.filter(r => r.grupo_ruta === groupName).length;
        select.append(`
            <option value="${escapeHtml(groupName)}">
                ${escapeHtml(groupName)} · ${total} paradas
            </option>
        `);
    });

    if (availableGroups.includes(currentValue) || (currentValue === '__UNPLANNED__' && hasUnplanned)) {
        select.val(currentValue);
    } else {
        select.val('__ALL__');
    }
}

function renderLegend() {
    const container = $('#leyendaRutas').empty();
    const groups = getFilteredGroups();

    groups.slice(0, 12).forEach(group => {
        const color = group.group_key === '__UNPLANNED__' ? '#dc2626' : getRouteColor(group.grupo_ruta);
        container.append(`
            <div class="legend-item">
                <span class="legend-line" style="background:${color};"></span>
                <span class="mini-note">${escapeHtml(group.grupo_ruta)}</span>
            </div>
        `);
    });

    if (groups.length > 12) {
        container.append('<div class="mini-note mt-2">La paleta se reutiliza si hay muchos grupos.</div>');
    }
}

function renderGroupSummaryTable() {
    const tbody = $('#tablaGruposResumen tbody').empty();
    const groups = getFilteredGroups();

    groups.forEach(group => {
        const isUnplanned = group.group_key === '__UNPLANNED__';
        const color = isUnplanned ? '#dc2626' : getRouteColor(group.grupo_ruta);

        tbody.append(`
            <tr class="clickable-row ${isUnplanned ? 'unplanned-row' : ''}" data-group="${escapeHtml(group.group_key || group.grupo_ruta)}">
                <td>
                    <span class="route-color-box" style="background:${color};"></span>
                    ${escapeHtml(group.grupo_ruta)}
                </td>
                <td>${escapeHtml(group.usuario_nombre || group.usuario_login || '')}</td>
                <td>${escapeHtml(group.fecha_ruta || '')}</td>
                <td>${group.total_paradas}</td>
            </tr>
        `);
    });
}

function renderDetalleTable() {
    const rows = getFilteredRows().sort((a, b) => {
        const cmpUsuario = String(a.usuario_nombre).localeCompare(String(b.usuario_nombre));
        if (cmpUsuario !== 0) return cmpUsuario;

        const cmpFecha = String(a.fecha_ruta_sql).localeCompare(String(b.fecha_ruta_sql));
        if (cmpFecha !== 0) return cmpFecha;

        const cmpGrupo = String(a.grupo_ruta).localeCompare(String(b.grupo_ruta));
        if (cmpGrupo !== 0) return cmpGrupo;

        return Number(a.orden_visita || 0) - Number(b.orden_visita || 0);
    });

    const tbody = $('#tablaDetalleRuta tbody').empty();

    rows.forEach(row => {
        const selected = selectedRowIds.has(row.row_id);
        tbody.append(`
            <tr class="clickable-row ${row.sin_ruta ? 'unplanned-row' : ''} ${selected ? 'selected-row' : ''}"
                data-row-id="${escapeHtml(row.row_id)}"
                data-group="${escapeHtml(row.sin_ruta ? '__UNPLANNED__' : row.grupo_ruta)}"
                data-codigo="${escapeHtml(row.codigo_local)}">
                <td>
                    <input type="checkbox" class="form-check-input row-selector"
                        data-row-id="${escapeHtml(row.row_id)}" ${selected ? 'checked' : ''}>
                </td>
                <td>${escapeHtml(row.usuario_nombre || row.usuario_login || '')}</td>
                <td>${escapeHtml(row.fecha_ruta || '')}</td>
                <td>${escapeHtml(
                    row.sin_ruta
                        ? `SIN RUTA${row.grupo_ruta_sugerido ? ` · Sug: ${row.grupo_ruta_sugerido}` : ''}`
                        : row.grupo_ruta
                )}</td>
                <td>${escapeHtml(row.dia_plan || '')}</td>
                <td>${row.orden_visita ?? ''}</td>
                <td>${escapeHtml(row.codigo_local)}</td>
                <td>${escapeHtml(row.nombre || '')}</td>
                <td>${escapeHtml(row.direccion || '')}</td>
                <td>${escapeHtml(row.comuna || '')}</td>
                <td>${escapeHtml(row.dia_semana || '')}</td>
                <td>${escapeHtml(row.semana_plan || '')}</td>
            </tr>
        `);
    });

    updateSelectionUi();
}

function updateSelectionUi() {
    const count = selectedRowIds.size;
    $('#badgeSeleccionados').text(`${count} seleccionado${count === 1 ? '' : 's'}`);
    $('#mapSelectedCount').text(count);
    $('#btnEditarSeleccionados').prop('disabled', count === 0);

    const visibleIds = getFilteredRows().map(row => row.row_id);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every(id => selectedRowIds.has(id));
    $('#checkSeleccionarVisibles').prop('checked', allVisibleSelected);
}

function toggleRowSelection(rowId, forceValue = null) {
    const shouldSelect = forceValue === null ? !selectedRowIds.has(rowId) : forceValue;
    if (shouldSelect) {
        selectedRowIds.add(rowId);
    } else {
        selectedRowIds.delete(rowId);
    }
    renderDetalleTable();
    renderMap(false);
}

function selectRowsInsideBounds(bounds) {
    let added = 0;

    getFilteredRows().forEach(row => {
        if (!hasUsableCoordinates(row)) return;
        const lat = Number(row.lat);
        const lng = Number(row.lng);

        const position = new google.maps.LatLng(lat, lng);
        if (bounds.contains(position) && !selectedRowIds.has(row.row_id)) {
            selectedRowIds.add(row.row_id);
            added++;
        }
    });

    renderDetalleTable();
    renderMap(false);

    if (added === 0) {
        $('#mapSelectedCount').addClass('text-danger');
        setTimeout(() => $('#mapSelectedCount').removeClass('text-danger'), 800);
    }
}

function initializeMapAreaSelection() {
    const layer = document.getElementById('mapSelectionLayer');
    const box = document.getElementById('mapSelectionBox');
    if (!layer || !box) return;

    const pointFromEvent = event => {
        const rect = layer.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(event.clientX - rect.left, rect.width)),
            y: Math.max(0, Math.min(event.clientY - rect.top, rect.height))
        };
    };

    const drawBox = (start, end) => {
        const left = Math.min(start.x, end.x);
        const top = Math.min(start.y, end.y);
        const width = Math.abs(end.x - start.x);
        const height = Math.abs(end.y - start.y);

        box.style.display = 'block';
        box.style.left = `${left}px`;
        box.style.top = `${top}px`;
        box.style.width = `${width}px`;
        box.style.height = `${height}px`;
    };

    layer.addEventListener('mousedown', event => {
        if (!mapSelectionMode || event.button !== 0) return;
        event.preventDefault();
        selectionDragStart = pointFromEvent(event);
        drawBox(selectionDragStart, selectionDragStart);
    });

    layer.addEventListener('mousemove', event => {
        if (!selectionDragStart) return;
        event.preventDefault();
        drawBox(selectionDragStart, pointFromEvent(event));
    });

    const finishSelection = event => {
        if (!selectionDragStart) return;
        event.preventDefault();

        const end = pointFromEvent(event);
        const start = selectionDragStart;
        selectionDragStart = null;
        box.style.display = 'none';

        if (Math.abs(end.x - start.x) < 6 || Math.abs(end.y - start.y) < 6) {
            return;
        }

        const projection = mapProjectionOverlay?.getProjection();
        if (!projection) return;

        const topLeft = projection.fromContainerPixelToLatLng(
            new google.maps.Point(Math.min(start.x, end.x), Math.min(start.y, end.y))
        );
        const bottomRight = projection.fromContainerPixelToLatLng(
            new google.maps.Point(Math.max(start.x, end.x), Math.max(start.y, end.y))
        );

        const bounds = new google.maps.LatLngBounds(
            new google.maps.LatLng(bottomRight.lat(), topLeft.lng()),
            new google.maps.LatLng(topLeft.lat(), bottomRight.lng())
        );
        selectRowsInsideBounds(bounds);
    };

    layer.addEventListener('mouseup', finishSelection);
    layer.addEventListener('mouseleave', event => {
        if (selectionDragStart) {
            finishSelection(event);
        }
    });
}

function setMapSelectionMode(active) {
    mapSelectionMode = Boolean(active);
    const button = $('#btnModoSeleccion');

    button.toggleClass('selection-mode-active', mapSelectionMode);
    button.html(
        mapSelectionMode
            ? '<i class="fa-regular fa-square"></i> Dibujar área'
            : '<i class="fa-solid fa-arrow-pointer"></i> Seleccionar por área'
    );

    if (mapaRutas) {
        mapaRutas.setOptions({
            draggable: !mapSelectionMode,
            gestureHandling: mapSelectionMode ? 'none' : 'auto',
            scrollwheel: !mapSelectionMode,
            disableDoubleClickZoom: mapSelectionMode,
            draggableCursor: mapSelectionMode ? 'crosshair' : null,
            draggingCursor: mapSelectionMode ? 'crosshair' : null
        });
    }

    $('#mapSelectionLayer').toggleClass('active', mapSelectionMode);
    if (!mapSelectionMode) {
        selectionDragStart = null;
        $('#mapSelectionBox').hide();
    }

    if (infoWindowRutas) {
        infoWindowRutas.close();
    }
}

function createMarker(row, color) {
    const isUnplanned = Boolean(row.sin_ruta);
    const isSelected = selectedRowIds.has(row.row_id);
    const marker = new google.maps.Marker({
        position: { lat: Number(row.lat), lng: Number(row.lng) },
        map: mapaRutas,
        label: {
            text: isUnplanned ? '!' : String(row.orden_visita ?? ''),
            color: '#ffffff',
            fontSize: '11px',
            fontWeight: '700'
        },
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: isSelected ? 15 : 12,
            fillColor: isUnplanned ? '#dc2626' : color,
            fillOpacity: 1,
            strokeColor: isSelected ? '#0d6efd' : '#ffffff',
            strokeWeight: isSelected ? 4 : 2
        },
        title: `${isUnplanned ? 'SIN RUTA' : row.grupo_ruta} - ${row.codigo_local}`
    });

    marker.addListener('click', () => {
        if (mapSelectionMode) {
            toggleRowSelection(row.row_id);
            return;
        }

        infoWindowRutas.setContent(`
            <div style="min-width:260px;">
                <div><strong>Usuario:</strong> ${escapeHtml(row.usuario_nombre || row.usuario_login || '')}</div>
                <div><strong>Fecha ruta:</strong> ${escapeHtml(row.fecha_ruta || '')}</div>
                <div><strong>Estado:</strong> ${isUnplanned ? '<span style="color:#dc2626;font-weight:700;">SIN RUTA</span>' : 'PLANIFICADO'}</div>
                <div><strong>Grupo:</strong> ${escapeHtml(row.grupo_ruta || '')}</div>
                ${row.grupo_ruta_sugerido ? `<div><strong>Grupo sugerido:</strong> ${escapeHtml(row.grupo_ruta_sugerido)}</div>` : ''}
                <div><strong>Día plan:</strong> ${escapeHtml(row.dia_plan || '')}</div>
                <div><strong>Orden:</strong> ${escapeHtml(row.orden_visita)}</div>
                <div><strong>Código:</strong> ${escapeHtml(row.codigo_local)}</div>
                <div><strong>Nombre:</strong> ${escapeHtml(row.nombre || '')}</div>
                <div><strong>Dirección:</strong> ${escapeHtml(row.direccion || '')}</div>
                ${row.direccion_original && row.direccion_original !== row.direccion ? `<div><strong>Dirección original:</strong> ${escapeHtml(row.direccion_original)}</div>` : ''}
                <div><strong>Comuna:</strong> ${escapeHtml(row.comuna || '')}</div>
                <div><strong>Origen coordenadas:</strong> ${escapeHtml(row.coordenadas_origen || 'SQL')}</div>
                <div><strong>Día:</strong> ${escapeHtml(row.dia_semana || '')}</div>
                <div><strong>Semana:</strong> ${escapeHtml(row.semana_plan || '')}</div>
                <button type="button" class="btn btn-sm btn-primary mt-2"
                    onclick="toggleRowSelection('${escapeHtml(row.row_id)}')">
                    ${isSelected ? 'Quitar selección' : 'Seleccionar local'}
                </button>
            </div>
        `);
        infoWindowRutas.open(mapaRutas, marker);
    });

    markersRutas.push(marker);
    return marker;
}

function decodeGooglePolyline(encoded) {
    if (!encoded) return [];
    const path = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < encoded.length) {
        let shift = 0;
        let result = 0;
        let byte;
        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index <= encoded.length);
        lat += (result & 1) ? ~(result >> 1) : (result >> 1);

        shift = 0;
        result = 0;
        do {
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20 && index <= encoded.length);
        lng += (result & 1) ? ~(result >> 1) : (result >> 1);
        path.push({ lat: lat / 1e5, lng: lng / 1e5 });
    }
    return path;
}

function renderMap(ajustarVista = true) {
    if (!mapaRutas || !window.google || !google.maps) {
        setTimeout(() => renderMap(ajustarVista), 250);
        return;
    }

    const currentCenter = mapaRutas ? mapaRutas.getCenter() : null;
    const currentZoom = mapaRutas ? mapaRutas.getZoom() : null;

    clearMapRutas();

    const rows = getFilteredRows()
        .filter(hasUsableCoordinates);

    if (!rows.length) {
        $('#badgeRutaActiva').text('Sin datos para el filtro actual');
        renderDetalleTable();
        return;
    }

    const groupMap = {};
    rows.forEach(row => {
        const groupKey = row.sin_ruta ? '__UNPLANNED__' : row.grupo_ruta;
        if (!groupMap[groupKey]) {
            groupMap[groupKey] = [];
        }
        groupMap[groupKey].push(row);
    });

    const allBounds = new google.maps.LatLngBounds();
    const selectedGroup = $('#filtroGrupoRuta').val() || '__ALL__';

    Object.keys(groupMap).sort().forEach(groupName => {
        const groupRows = groupMap[groupName].sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
        const isUnplannedGroup = groupName === '__UNPLANNED__';
        const color = isUnplannedGroup ? '#dc2626' : getRouteColor(groupName);
        const markerPath = [];

        groupRows.forEach(row => {
            const position = { lat: Number(row.lat), lng: Number(row.lng) };
            markerPath.push(position);
            allBounds.extend(position);
            createMarker(row, color);
        });

        const encodedPolyline = groupRows.find(row => row.route_polyline)?.route_polyline || '';
        const roadPath = decodeGooglePolyline(encodedPolyline);
        const path = roadPath.length > 1 ? roadPath : markerPath;

        if (!isUnplannedGroup && path.length > 1) {
            const polyline = new google.maps.Polyline({
                path,
                geodesic: roadPath.length <= 1,
                strokeColor: color,
                strokeOpacity: 0.85,
                strokeWeight: 4,
                map: mapaRutas
            });

            polylinesRutas.push(polyline);
        }
    });

    if (ajustarVista && !allBounds.isEmpty()) {
        mapaRutas.fitBounds(allBounds);
    } else if (!ajustarVista && currentCenter) {
        mapaRutas.setCenter(currentCenter);
        if (currentZoom !== null && currentZoom !== undefined) {
            mapaRutas.setZoom(currentZoom);
        }
    }

    $('#badgeRutaActiva').text(
        selectedGroup === '__ALL__'
            ? 'Vista filtrada'
            : (selectedGroup === '__UNPLANNED__' ? 'SIN RUTA' : selectedGroup)
    );
    renderDetalleTable();
}

function refreshAllViews(resetGroup = false) {
    if (resetGroup) {
        $('#filtroGrupoRuta').val('__ALL__');
    }

    renderGroupSelect();
    renderSummary();
    renderLegend();
    renderGroupSummaryTable();
    renderMap();
}

function rebuildFiltersFromRows() {
    const users = {};
    const dates = {};

    planRows.forEach(row => {
        const userKey = row.usuario_id || row.usuario_login;
        if (userKey) {
            users[userKey] = {
                usuario_id: row.usuario_id || '',
                usuario_login: row.usuario_login || '',
                usuario_nombre: row.usuario_nombre || ''
            };
        }
        if (row.fecha_ruta_sql) {
            dates[row.fecha_ruta_sql] = {
                fecha_ruta: row.fecha_ruta || row.fecha_ruta_sql,
                fecha_ruta_sql: row.fecha_ruta_sql
            };
        }
    });

    planFilters = {
        usuarios: Object.values(users),
        fechas: Object.values(dates).sort((a, b) => String(a.fecha_ruta_sql).localeCompare(String(b.fecha_ruta_sql))),
        grupos: [...new Set(planRows.filter(row => !row.sin_ruta && row.grupo_ruta).map(row => row.grupo_ruta))].sort()
    };

    planSummary.sin_ruta = planRows.filter(row => row.sin_ruta).length;
    planSummary.total_grupos = planFilters.grupos.length;
    planSummary.total_usuarios = planFilters.usuarios.length;
    planSummary.total_fechas = planFilters.fechas.length;
}

function dateMetadata(dateSql) {
    if (!dateSql) {
        return { display: '', dayName: '', dayNumber: '', week: '' };
    }

    const date = new Date(`${dateSql}T12:00:00`);
    if (Number.isNaN(date.getTime())) {
        return { display: dateSql, dayName: '', dayNumber: '', week: '' };
    }

    const dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    const start = new Date(date.getFullYear(), 0, 1);
    const days = Math.floor((date - start) / 86400000);
    const week = Math.ceil((days + start.getDay() + 1) / 7);

    return {
        display: `${String(date.getDate()).padStart(2, '0')}-${String(date.getMonth() + 1).padStart(2, '0')}-${date.getFullYear()}`,
        dayName: dayNames[date.getDay()],
        dayNumber: date.getDay() === 0 ? 7 : date.getDay(),
        week: week
    };
}

function isWeekendSql(dateSql) {
    if (!dateSql) return false;
    const date = new Date(`${dateSql}T12:00:00`);
    if (Number.isNaN(date.getTime())) return false;
    return date.getDay() === 0 || date.getDay() === 6;
}

function getSelectedRowsSortedForEdit() {
    return planRows
        .filter(row => selectedRowIds.has(row.row_id))
        .sort((a, b) => {
            const cmpFecha = String(a.fecha_ruta_sql || '').localeCompare(String(b.fecha_ruta_sql || ''));
            if (cmpFecha !== 0) return cmpFecha;

            const cmpGrupo = String(a.grupo_ruta || '').localeCompare(String(b.grupo_ruta || ''));
            if (cmpGrupo !== 0) return cmpGrupo;

            const cmpOrden = Number(a.orden_visita || 0) - Number(b.orden_visita || 0);
            if (cmpOrden !== 0) return cmpOrden;

            return String(a.codigo_local || '').localeCompare(String(b.codigo_local || ''));
        });
}

function renderEditOrderTable() {
    const tbody = $('#tablaOrdenEdicion tbody').empty();

    editOrderRows.forEach((row, index) => {
        tbody.append(`
            <tr>
                <td class="fw-semibold">${index + 1}</td>
                <td>${escapeHtml(row.codigo_local || '')}</td>
                <td>${escapeHtml(row.nombre || '')}</td>
                <td>${escapeHtml(row.comuna || '')}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary btnMoverOrden"
                            data-index="${index}" data-direction="-1" ${index === 0 ? 'disabled' : ''}>
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btnMoverOrden"
                            data-index="${index}" data-direction="1" ${index === editOrderRows.length - 1 ? 'disabled' : ''}>
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `);
    });
}

function moveEditOrderRow(index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= editOrderRows.length) return;

    const moved = editOrderRows.splice(index, 1)[0];
    editOrderRows.splice(newIndex, 0, moved);
    renderEditOrderTable();
}

function openEditModal() {
    if (!selectedRowIds.size) return;

    const selectedRows = getSelectedRowsSortedForEdit();
    editOrderRows = selectedRows.slice();
    $('#modalCantidadSeleccionados').text(selectedRows.length);
    $('#editFechaRuta, #editGrupoRuta, #editOrdenVisita, #editUsuarioNombre').val('');
    if (selectedRows.length === 1 && selectedRows[0].grupo_ruta_sugerido) {
        $('#editGrupoRuta').val(selectedRows[0].grupo_ruta_sugerido);
    }
    $('#editQuitarRuta').prop('checked', false);

    const groups = [...new Set(planRows.map(row => row.grupo_ruta).filter(Boolean))].sort();
    $('#listaGruposRuta').html(groups.map(group => `<option value="${escapeHtml(group)}"></option>`).join(''));
    renderEditOrderTable();

    modalEditarRuta.show();
}

function applyRouteEdits() {
    const fecha = $('#editFechaRuta').val();
    const grupo = $('#editGrupoRuta').val().trim();
    const ordenRaw = $('#editOrdenVisita').val();
    const usuarioNombre = $('#editUsuarioNombre').val().trim();
    const quitarRuta = $('#editQuitarRuta').is(':checked');
    const metadata = dateMetadata(fecha);
    const orderedRows = editOrderRows.length ? editOrderRows : getSelectedRowsSortedForEdit();
    if (fecha && isWeekendSql(fecha)) {
        alert('La fecha de ruta debe ser de lunes a viernes.');
        return;
    }
    if (ordenRaw !== '' && (!Number.isFinite(Number(ordenRaw)) || Number(ordenRaw) < 1)) {
        alert('El orden inicial debe ser un numero mayor o igual a 1.');
        return;
    }
    const existingOrders = orderedRows
        .map(row => Number(row.orden_visita || 0))
        .filter(order => Number.isFinite(order) && order > 0);
    const startOrder = ordenRaw !== ''
        ? Number(ordenRaw)
        : (existingOrders.length ? Math.min(...existingOrders) : 1);

    orderedRows.forEach((row, orderOffset) => {
        const planRow = planRows.find(item => item.row_id === row.row_id);
        if (!planRow) return;

        if (quitarRuta) {
            planRow.grupo_ruta = '';
            planRow.fecha_ruta = '';
            planRow.fecha_ruta_sql = '';
            planRow.orden_visita = 0;
            planRow.dia_plan = '';
            planRow.dia_semana = '';
            planRow.dia_semana_num = '';
            planRow.semana_plan = '';
            planRow.sin_ruta = true;
            return;
        }

        if (fecha) {
            planRow.fecha_ruta_sql = fecha;
            planRow.fecha_ruta = metadata.display;
            planRow.dia_semana = metadata.dayName;
            planRow.dia_semana_num = metadata.dayNumber;
            planRow.semana_plan = metadata.week;
        }
        if (grupo) {
            planRow.grupo_ruta = grupo;
        }
        planRow.orden_visita = startOrder + orderOffset;
        if (usuarioNombre) {
            planRow.usuario_nombre = usuarioNombre;
        }

        planRow.sin_ruta = !planRow.grupo_ruta || !planRow.fecha_ruta_sql;
    });

    selectedRowIds.clear();
    editOrderRows = [];
    rebuildFiltersFromRows();
    renderUsuarioSelect();
    renderFechaSelect();
    refreshAllViews(true);
    modalEditarRuta.hide();
}

function distanceBetweenRows(a, b) {
    const latA = Number(a.lat);
    const lngA = Number(a.lng);
    const latB = Number(b.lat);
    const lngB = Number(b.lng);
    if (![latA, lngA, latB, lngB].every(Number.isFinite)) return Number.POSITIVE_INFINITY;

    const toRadians = degrees => degrees * Math.PI / 180;
    const dLat = toRadians(latB - latA);
    const dLng = toRadians(lngB - lngA);
    const value = Math.sin(dLat / 2) ** 2
        + Math.cos(toRadians(latA)) * Math.cos(toRadians(latB)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
}

function sortRowsByNearestNeighbor(rows) {
    const pending = rows.slice();
    if (pending.length <= 2) return pending;

    let currentIndex = 0;
    pending.forEach((row, index) => {
        const current = pending[currentIndex];
        const isMoreNorthWest = Number(row.lat) > Number(current.lat)
            || (Number(row.lat) === Number(current.lat) && Number(row.lng) < Number(current.lng));
        if (isMoreNorthWest) {
            currentIndex = index;
        }
    });

    const ordered = [pending.splice(currentIndex, 1)[0]];
    while (pending.length) {
        const current = ordered[ordered.length - 1];
        let nearestIndex = 0;
        let nearestDistance = distanceBetweenRows(current, pending[0]);
        for (let i = 1; i < pending.length; i++) {
            const distance = distanceBetweenRows(current, pending[i]);
            if (distance < nearestDistance) {
                nearestDistance = distance;
                nearestIndex = i;
            }
        }
        ordered.push(pending.splice(nearestIndex, 1)[0]);
    }

    return ordered;
}

function addBusinessDaysSql(dateSql, businessDaysToAdd) {
    const date = new Date(`${dateSql}T12:00:00`);
    if (Number.isNaN(date.getTime())) return dateSql;

    // Si la fecha inicial cae en fin de semana, comienza el lunes siguiente.
    while (date.getDay() === 0 || date.getDay() === 6) {
        date.setDate(date.getDate() + 1);
    }

    let remaining = Math.max(0, Math.trunc(Number(businessDaysToAdd) || 0));
    while (remaining > 0) {
        date.setDate(date.getDate() + 1);
        if (date.getDay() !== 0 && date.getDay() !== 6) {
            remaining--;
        }
    }

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function todaySqlLocal() {
    const date = new Date();
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function chunkRows(rows, size) {
    const chunks = [];
    for (let i = 0; i < rows.length; i += size) {
        chunks.push(rows.slice(i, i + size));
    }
    return chunks;
}

function clusterCentroid(rows, fallback = null) {
    if (!rows.length) return fallback;
    return {
        lat: rows.reduce((sum, row) => sum + Number(row.lat), 0) / rows.length,
        lng: rows.reduce((sum, row) => sum + Number(row.lng), 0) / rows.length
    };
}

function chooseGeographicSeeds(rows, count) {
    if (count <= 1) {
        return [rows.reduce((best, row) => Number(row.lat) > Number(best.lat) ? row : best, rows[0])];
    }

    const seeds = [rows.reduce((best, row) => {
        const rowScore = Number(row.lat) - Number(row.lng) * 0.01;
        const bestScore = Number(best.lat) - Number(best.lng) * 0.01;
        return rowScore > bestScore ? row : best;
    }, rows[0])];

    while (seeds.length < count) {
        let bestCandidate = null;
        let bestDistance = -1;
        rows.forEach(row => {
            if (seeds.includes(row)) return;
            const nearestSeed = Math.min(...seeds.map(seed => distanceBetweenRows(row, seed)));
            if (nearestSeed > bestDistance) {
                bestDistance = nearestSeed;
                bestCandidate = row;
            }
        });
        if (!bestCandidate) break;
        seeds.push(bestCandidate);
    }
    return seeds;
}

function assignRowsToGeographicClusters(rows, centroids, maxSize) {
    const clusters = centroids.map(() => []);
    const rankedRows = rows.map(row => {
        const distances = centroids
            .map((centroid, index) => ({ index, distance: distanceBetweenRows(row, centroid) }))
            .sort((a, b) => a.distance - b.distance);
        return {
            row,
            distances,
            confidence: distances.length > 1 ? distances[1].distance - distances[0].distance : Number.POSITIVE_INFINITY
        };
    }).sort((a, b) => b.confidence - a.confidence);

    rankedRows.forEach(item => {
        const destination = item.distances.find(candidate => clusters[candidate.index].length < maxSize)
            || item.distances[0];
        clusters[destination.index].push(item.row);
    });
    return clusters;
}

function rebalanceSmallGeographicClusters(clusters, centroids, minSize) {
    const updatedCentroids = () => clusters.map((cluster, index) => clusterCentroid(cluster, centroids[index]));
    let guard = 0;

    while (guard++ < 1000) {
        const smallIndex = clusters.findIndex(cluster => cluster.length > 0 && cluster.length < minSize);
        if (smallIndex < 0) break;

        const currentCentroids = updatedCentroids();
        let bestMove = null;
        clusters.forEach((donor, donorIndex) => {
            if (donorIndex === smallIndex || donor.length <= minSize) return;
            donor.forEach((row, rowIndex) => {
                const cost = distanceBetweenRows(row, currentCentroids[smallIndex])
                    - distanceBetweenRows(row, currentCentroids[donorIndex]);
                if (!bestMove || cost < bestMove.cost) {
                    bestMove = { donorIndex, rowIndex, cost };
                }
            });
        });

        if (!bestMove) break;
        clusters[smallIndex].push(clusters[bestMove.donorIndex].splice(bestMove.rowIndex, 1)[0]);
    }

    return clusters.filter(cluster => cluster.length > 0);
}

function smartGeographicClusters(rows, targetSize, absoluteMaxSize = 30) {
    if (!rows.length) return [];

    const minSize = Math.max(1, Math.ceil(targetSize * 0.8));
    const maxSize = Math.min(absoluteMaxSize, Math.max(targetSize, Math.ceil(targetSize * 1.5)));
    if (rows.length <= maxSize) return [sortRowsByNearestNeighbor(rows)];

    const minimumGroups = Math.max(1, Math.ceil(rows.length / maxSize));
    const maximumGroups = Math.max(1, Math.floor(rows.length / minSize));
    const idealGroups = Math.max(1, Math.round(rows.length / targetSize));
    const groupCount = Math.max(minimumGroups, Math.min(idealGroups, maximumGroups));
    const seeds = chooseGeographicSeeds(rows, groupCount);
    let centroids = seeds.map(seed => ({ lat: Number(seed.lat), lng: Number(seed.lng) }));
    let clusters = [];

    for (let iteration = 0; iteration < 8; iteration++) {
        clusters = assignRowsToGeographicClusters(rows, centroids, maxSize);
        const nextCentroids = clusters.map((cluster, index) => clusterCentroid(cluster, centroids[index]));
        const movement = nextCentroids.reduce((sum, centroid, index) =>
            sum + distanceBetweenRows(centroid, centroids[index]), 0);
        centroids = nextCentroids;
        if (movement < 0.01) break;
    }

    clusters = rebalanceSmallGeographicClusters(clusters, centroids, minSize);
    return clusters
        .map(cluster => sortRowsByNearestNeighbor(cluster))
        .sort((a, b) => Number(b[0]?.lat || 0) - Number(a[0]?.lat || 0));
}

function buildSmartRoutesByUser(rows, targetSize) {
    const byUser = new Map();
    rows.forEach(row => {
        const key = String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
        if (!byUser.has(key)) byUser.set(key, []);
        byUser.get(key).push(row);
    });

    const routes = [];
    [...byUser.entries()].forEach(([userKey, userRows]) => {
        smartGeographicClusters(userRows, targetSize, 30).forEach((routeRows, userDayIndex) => {
            routes.push({ userKey, userDayIndex, rows: routeRows });
        });
    });
    return routes;
}

function optimizeChunkWithGoogle(rows) {
    return new Promise(resolve => {
        if (!directionsServiceRutas || rows.length <= 2) {
            resolve(rows);
            return;
        }

        const origin = rows[0];
        const destination = rows[rows.length - 1];
        const intermediateRows = rows.slice(1, -1);

        directionsServiceRutas.route({
            origin: { lat: Number(origin.lat), lng: Number(origin.lng) },
            destination: { lat: Number(destination.lat), lng: Number(destination.lng) },
            waypoints: intermediateRows.map(row => ({
                location: { lat: Number(row.lat), lng: Number(row.lng) },
                stopover: true
            })),
            optimizeWaypoints: true,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            if (status !== 'OK' || !result || !result.routes || !result.routes[0]) {
                optimizationWarnings.push(status || 'ERROR');
                resolve(rows);
                return;
            }

            const order = result.routes[0].waypoint_order || [];
            const optimized = [
                origin,
                ...order.map(index => intermediateRows[index]).filter(Boolean),
                destination
            ];
            resolve(optimized);
        });
    });
}

async function optimizeDailyRowsWithGoogle(rows) {
    const maxRowsPerDirectionsRequest = 23;
    if (rows.length <= maxRowsPerDirectionsRequest) {
        return await optimizeChunkWithGoogle(rows);
    }

    const optimized = [];
    const segments = chunkRows(rows, maxRowsPerDirectionsRequest);
    for (const segment of segments) {
        const optimizedSegment = await optimizeChunkWithGoogle(segment);
        optimized.push(...optimizedSegment);
    }

    return optimized;
}

function getOptimizationRows() {
    const useSelected = $('#optSoloSeleccionados').is(':checked') && selectedRowIds.size > 0;
    const sourceRows = useSelected
        ? planRows.filter(row => selectedRowIds.has(row.row_id))
        : getFilteredRows();

    return sourceRows.filter(hasUsableCoordinates);
}

async function requestGoogleRouteOptimization(rows, targetSize, isolationKm) {
    const payloadRows = rows.map(row => ({
        row_id: row.row_id,
        codigo_local: row.codigo_local,
        usuario_id: row.usuario_id,
        usuario_login: row.usuario_login,
        usuario_nombre: row.usuario_nombre,
        lat: Number(row.lat),
        lng: Number(row.lng)
    }));
    const body = new URLSearchParams();
    body.set('rows', JSON.stringify(payloadRows));
    body.set('target_size', String(targetSize));
    body.set('isolation_km', String(isolationKm));
    body.set('csrf_token', String($('#routeOptimizerCsrf').val() || ''));

    const response = await fetch('mod_optimizar_rutas_google.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString()
    });
    const raw = await response.text();
    let result;
    try {
        result = JSON.parse(raw);
    } catch (error) {
        throw new Error(raw || 'Route Optimization API devolvió una respuesta inválida.');
    }
    if (!response.ok || result.success !== true) {
        throw new Error(result.message || 'No se pudo optimizar con Route Optimization API.');
    }

    const rowsById = new Map(rows.map(row => [String(row.row_id), row]));
    const routes = (result.routes || []).map(route => ({
        userKey: route.user_key || '',
        userDayIndex: Number(route.user_day_index || 0),
        encodedPolyline: route.encoded_polyline || '',
        distanceMeters: Number(route.distance_meters || 0),
        totalDuration: route.total_duration || '',
        rows: (route.row_ids || []).map(rowId => rowsById.get(String(rowId))).filter(Boolean)
    })).filter(route => route.rows.length > 0);

    return {
        routes,
        skipped: Array.isArray(result.skipped) ? result.skipped : [],
        summary: result.summary || {}
    };
}

function leaveRowWithoutRoute(row, reason) {
    row.fecha_ruta_sql = '';
    row.fecha_ruta = '';
    row.dia_semana = '';
    row.dia_semana_num = '';
    row.semana_plan = '';
    row.dia_plan = '';
    row.grupo_ruta = '';
    row.ruta_global = '';
    row.orden_visita = 0;
    row.tamano_ruta = 0;
    row.distancia_total_ruta_km = 0;
    row.route_polyline = '';
    row.observacion = reason || 'Local dejado sin ruta por Route Optimization API.';
    row.sin_ruta = true;
}

function addUnreturnedRowsToNearestRoute(rows, routePlans, skippedById) {
    const assignedIds = new Set(routePlans.flatMap(route => route.rows.map(row => String(row.row_id))));

    rows.forEach(row => {
        const rowId = String(row.row_id);
        if (assignedIds.has(rowId) || skippedById.has(rowId)) return;

        const sameUserRoutes = routePlans.filter(route => route.rows.some(candidate =>
            String(candidate.usuario_id || candidate.usuario_login || candidate.usuario_nombre || '')
            === String(row.usuario_id || row.usuario_login || row.usuario_nombre || '')
        ));
        const candidateRoutes = sameUserRoutes.length ? sameUserRoutes : routePlans;
        let selectedRoute = null;
        let insertAt = 0;
        let nearestDistance = Infinity;

        candidateRoutes.forEach(route => {
            route.rows.forEach((candidate, index) => {
                const distance = distanceBetweenRows(row, candidate);
                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    selectedRoute = route;
                    insertAt = index + 1;
                }
            });
        });

        if (!selectedRoute) return;
        selectedRoute.rows.splice(insertAt, 0, row);
        selectedRoute.encodedPolyline = '';
        row.observacion = 'Incorporado por cercanía: Google no lo devolvió en la ruta, pero tiene coordenadas válidas.';
        assignedIds.add(rowId);
    });
}

async function optimizeVisibleRoutes() {
    if (!planRows.length) {
        alert('Primero debes cargar un archivo.');
        return;
    }

    const dailyLoad = Number($('#optMetaDiaria').val() || 0);
    if (!Number.isInteger(dailyLoad) || dailyLoad < 2 || dailyLoad > 30) {
        alert('La carga por dia debe estar entre 2 y 30 locales para este optimizador.');
        return;
    }

    const isolationKm = Number($('#optAislamientoKm').val() || 25);
    if (!Number.isFinite(isolationKm) || isolationKm < 2 || isolationKm > 150) {
        alert('La separación para considerar un local aislado debe estar entre 2 y 150 km.');
        return;
    }

    const selectedStartDate = $('#optFechaInicio').val();
    if (!selectedStartDate) {
        alert('Debes indicar una fecha de inicio.');
        return;
    }
    const startDate = addBusinessDaysSql(selectedStartDate, 0);
    if (startDate !== selectedStartDate) {
        $('#optFechaInicio').val(startDate);
    }

    const candidates = getOptimizationRows();
    if (!candidates.length) {
        alert('No hay locales visibles o seleccionados con coordenadas validas.');
        return;
    }

    const button = $('#btnOptimizarRutas');
    const originalHtml = button.html();
    const prefix = ($('#optPrefijoGrupo').val().trim() || 'RUTA OPT').toUpperCase();
    optimizationWarnings = [];
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Optimizando...');
    $('#optimizadorEstado').text(`Enviando ${candidates.length} local(es) a Google Route Optimization API...`);

    try {
        const googleResult = await requestGoogleRouteOptimization(candidates, dailyLoad, isolationKm);
        const routePlans = googleResult.routes;
        const skippedById = new Map(googleResult.skipped.map(item => [String(item.row_id), item]));
        addUnreturnedRowsToNearestRoute(candidates, routePlans, skippedById);
        const assignedIds = new Set(routePlans.flatMap(route => route.rows.map(row => String(row.row_id))));

        candidates.forEach(row => {
            const skipped = skippedById.get(String(row.row_id));
            if (skipped) {
                leaveRowWithoutRoute(row, skipped.reason);
            } else if (!assignedIds.has(String(row.row_id))) {
                leaveRowWithoutRoute(row, 'Google no encontró una ruta conveniente para este local.');
            }
        });

        for (let routeIndex = 0; routeIndex < routePlans.length; routeIndex++) {
            const routePlan = routePlans[routeIndex];
            $('#optimizadorEstado').text(`Aplicando ruta vial ${routeIndex + 1} de ${routePlans.length} (${routePlan.rows.length} locales)...`);
            const optimizedRows = routePlan.rows;
            const dateSql = addBusinessDaysSql(startDate, routePlan.userDayIndex);
            const metadata = dateMetadata(dateSql);
            const groupName = `${prefix} ${String(routeIndex + 1).padStart(2, '0')}`;

            optimizedRows.forEach((row, orderIndex) => {
                row.fecha_ruta_sql = dateSql;
                row.fecha_ruta = metadata.display;
                row.dia_semana = metadata.dayName;
                row.dia_semana_num = metadata.dayNumber;
                row.semana_plan = metadata.week;
                row.dia_plan = routePlan.userDayIndex + 1;
                row.grupo_ruta = groupName;
                row.ruta_global = groupName;
                row.orden_visita = orderIndex + 1;
                row.tamano_ruta = optimizedRows.length;
                row.distancia_total_ruta_km = routePlan.distanceMeters / 1000;
                row.route_polyline = routePlan.encodedPolyline;
                row.observacion = 'Optimizado por Google Route Optimization API.';
                row.sin_ruta = false;
            });
        }

        selectedRowIds.clear();
        rebuildFiltersFromRows();
        renderUsuarioSelect();
        renderFechaSelect();
        refreshAllViews(true);
        const routeSizes = routePlans.map(route => route.rows.length);
        const sizeRange = routeSizes.length ? `${Math.min(...routeSizes)}–${Math.max(...routeSizes)}` : '0';
        const skippedCount = googleResult.skipped.length;
        $('#optimizadorEstado').text(
            `Listo con Google Route Optimization: ${routePlans.length} ruta(s) de ${sizeRange} locales. `
            + `${skippedCount} local(es) aislado(s) quedaron SIN RUTA.`
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'No se pudo optimizar la ruta.');
        $('#optimizadorEstado').text('No se pudo completar la optimizacion.');
    } finally {
        button.prop('disabled', false).html(originalHtml);
    }
}

async function downloadEditedRoutes() {
    if (!planRows.length) {
        alert('Primero debes cargar un archivo.');
        return;
    }

    const weekendRows = planRows.filter(row => row.fecha_ruta_sql && isWeekendSql(row.fecha_ruta_sql));
    if (weekendRows.length) {
        alert(`Hay ${weekendRows.length} local(es) asignados a sabado o domingo. Vuelve a ejecutar el optimizador o corrige sus fechas antes de descargar.`);
        return;
    }

    const button = $('#btnDescargarEditado');
    const original = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Generando...');

    try {
        const body = new URLSearchParams();
        body.set('rows', JSON.stringify(planRows));

        const response = await fetch('mod_descargar_rutas_editadas.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        });

        if (!response.ok) {
            throw new Error(await response.text() || 'No se pudo generar el archivo.');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `planificacion_rutas_editada_${new Date().toISOString().slice(0, 10)}.xlsx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (error) {
        alert(error.message || 'No se pudo descargar el archivo.');
    } finally {
        button.prop('disabled', false).html(original);
    }
}

function activateMapView() {
    $('#emptyMapState').hide();
    $('#bloqueMapa').show();

    const tabMapa = new bootstrap.Tab(document.querySelector('#tab-mapa-btn'));
    tabMapa.show();

    setTimeout(() => {
        if (mapaRutas) {
            google.maps.event.trigger(mapaRutas, 'resize');
            renderMap();
        }
    }, 250);
}

function rowsFromLocalUpload(resp) {
    return (Array.isArray(resp.encontrados) ? resp.encontrados : []).map((local, index) => ({
        row_id: `csv_${local.fila_csv || index + 2}_${local.id_local || local.codigo || index}`,
        codigo_local: String(local.codigo || ''),
        nombre: local.nombre || '',
        direccion: local.direccion_google || local.direccion || '',
        direccion_original: local.direccion || '',
        comuna: local.comuna || '',
        lat: local.tiene_coords ? Number(local.lat) : null,
        lng: local.tiene_coords ? Number(local.lng) : null,
        cantidad_objetivo_dia: 0,
        dias_planificados: 0,
        grupo_ruta: '',
        ruta_global: '',
        fecha_ruta: '',
        fecha_ruta_sql: '',
        usuario_id: local.usuario_id || '',
        usuario_login: local.usuario_login || '',
        usuario_nombre: local.usuario_nombre || local.usuario_login || '',
        dia_plan: '',
        semana_plan: '',
        dia_semana_num: '',
        dia_semana: '',
        orden_visita: 0,
        tamano_ruta: 0,
        distancia_desde_anterior_km: 0,
        distancia_total_ruta_km: 0,
        observacion: local.motivo_validacion || '',
        estado_address_validation: local.estado_address_validation || '',
        coordenadas_origen: local.coordenadas_origen || '',
        sin_ruta: true
    }));
}

$('#btnDescargarTemplateRutas').on('click', function() {
    // BOM UTF-8 + punto y coma permiten abrir el archivo directamente en Excel
    // manteniendo los encabezados que reconoce mod_cargar_locales.php.
    const csv = '\uFEFFcodigo;usuario\r\n500038;MCARVAJAL\r\n';
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'template_visualizador_rutas.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
});

$('#formUploadPlanificacion').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const btn = $('#btnCargarPlanificacion');
    const original = btn.html();

    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Procesando...');

    $.ajax({
        url: 'mod_cargar_locales.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            btn.prop('disabled', false).html(original);

            if (!resp || resp.success !== true) {
                $('#uploadResultBox')
                    .show()
                    .html(`<div class="alert alert-danger mb-0">${escapeHtml(resp?.message || 'No se pudo procesar el archivo.')}</div>`);
                return;
            }

            planRows = rowsFromLocalUpload(resp);
            planGroups = [];
            planSummary = Object.assign({}, resp.resumen || {});
            planFilters = { usuarios: [], fechas: [], grupos: [] };
            selectedRowIds.clear();

            rebuildFiltersFromRows();
            renderUsuarioSelect();
            renderFechaSelect();
            $('#filtroGrupoRuta').html('<option value="__ALL__">Todas las rutas</option>');

            refreshAllViews(true);

            const resumen = resp.resumen || {};
            const invalidas = Number(resumen.filas_invalidas || 0);
            const detalleInvalidas = invalidas > 0
                ? `<br><span class="text-danger">${invalidas} fila(s) no se cargaron por código o usuario inválido.</span>`
                : '';
            const problemas = [
                ...(Array.isArray(resp.filas_invalidas) ? resp.filas_invalidas.map(item =>
                    `Fila ${item.fila_csv}: ${item.codigo || '(sin código)'} — ${item.motivo || 'Fila inválida'}`) : []),
                ...(Array.isArray(resp.encontrados) ? resp.encontrados
                    .filter(item => !item.tiene_coords)
                    .map(item => `Fila ${item.fila_csv}: ${item.codigo} — ${item.motivo_validacion || 'Sin coordenadas'}`) : [])
            ];
            const detalleProblemas = problemas.length
                ? `<details class="mt-2"><summary>Ver observaciones</summary><ul class="mb-0 mt-2">${problemas.slice(0, 50).map(text => `<li>${escapeHtml(text)}</li>`).join('')}</ul>${problemas.length > 50 ? '<small>Se muestran las primeras 50 observaciones.</small>' : ''}</details>`
                : '';

            $('#uploadResultBox')
                .show()
                .html(`
                    <div class="alert alert-success mb-0">
                        <strong>Archivo procesado correctamente.</strong><br>
                        Se cargaron ${planRows.length} local(es): ${resumen.coordenadas_sql || 0} con coordenadas SQL,
                        ${resumen.validados_google || 0} recuperado(s) mediante Address Validation y
                        ${resumen.sin_coordenadas || 0} sin coordenadas utilizables.${detalleInvalidas}
                        ${detalleProblemas}
                    </div>
                `);

            activateMapView();
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(original);

            const msg = xhr.responseJSON?.message
                || xhr.responseText
                || 'Ocurrió un error al procesar el archivo.';

            $('#uploadResultBox')
                .show()
                .html(`<div class="alert alert-danger mb-0">${escapeHtml(msg)}</div>`);
        }
    });
});

$('#filtroUsuario').on('change', function() {
    $('#filtroGrupoRuta').val('__ALL__');
    refreshAllViews(true);
});

$('#filtroFechaRuta').on('change', function() {
    $('#filtroGrupoRuta').val('__ALL__');
    refreshAllViews(true);
});

$('#filtroGrupoRuta').on('change', function() {
    refreshAllViews(false);
});

$('#btnVerTodas').on('click', function() {
    $('#filtroUsuario').val('__ALL__');
    $('#filtroFechaRuta').val('__ALL__');
    $('#filtroGrupoRuta').val('__ALL__');
    refreshAllViews(true);
});

$('#btnAjustarMapa').on('click', function() {
    renderMap();
});

$('#btnModoSeleccion').on('click', function() {
    setMapSelectionMode(!mapSelectionMode);
});

$('#btnLimpiarSeleccion').on('click', function() {
    selectedRowIds.clear();
    renderDetalleTable();
    renderMap(false);
});

$('#btnEditarSeleccionados').on('click', openEditModal);
$('#btnAplicarEdicion').on('click', applyRouteEdits);
$('#btnDescargarEditado').on('click', downloadEditedRoutes);
$('#btnOptimizarRutas').on('click', optimizeVisibleRoutes);

$(document).on('click', '.btnMoverOrden', function() {
    moveEditOrderRow(Number($(this).data('index')), Number($(this).data('direction')));
});

$('#optFechaInicio').val(todaySqlLocal());

$('#checkSeleccionarVisibles').on('change', function() {
    const checked = $(this).is(':checked');
    getFilteredRows().forEach(row => {
        if (checked) {
            selectedRowIds.add(row.row_id);
        } else {
            selectedRowIds.delete(row.row_id);
        }
    });
    renderDetalleTable();
    renderMap(false);
});

$(document).on('click', '.row-selector', function(event) {
    event.stopPropagation();
    const rowId = $(this).data('row-id');
    toggleRowSelection(rowId, $(this).is(':checked'));
});

$(document).on('click', '#tablaGruposResumen tbody tr', function() {
    const group = $(this).data('group');
    if (!group) return;

    $('#filtroGrupoRuta').val(group);
    refreshAllViews(false);
});

$(document).on('click', '#tablaDetalleRuta tbody tr', function() {
    const group = $(this).data('group');
    const codigo = $(this).data('codigo');

    if (group && $('#filtroGrupoRuta').val() !== group) {
        $('#filtroGrupoRuta').val(group);
        refreshAllViews(false);
    }

    const marker = markersRutas.find(m => m.getTitle() && m.getTitle().includes(codigo));
    if (marker) {
        mapaRutas.panTo(marker.getPosition());
        mapaRutas.setZoom(Math.max(mapaRutas.getZoom(), 14));
        google.maps.event.trigger(marker, 'click');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    modalEditarRuta = new bootstrap.Modal(document.getElementById('modalEditarRuta'));
});

window.initMapRutas = initMapRutas;
</script>

<?php if ($googleMapsBrowserKey !== ''): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo rawurlencode($googleMapsBrowserKey); ?>&callback=initMapRutas&loading=async"></script>
<?php else: ?>
<script>console.error('Falta google_maps_browser_api_key en config/maps_config.php');</script>
<?php endif; ?>

</body>
</html>

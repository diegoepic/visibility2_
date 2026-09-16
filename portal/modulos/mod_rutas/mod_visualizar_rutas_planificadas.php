<?php
session_start();
date_default_timezone_set('America/Santiago');

if (empty($_SESSION['route_optimizer_csrf'])) {
    $_SESSION['route_optimizer_csrf'] = bin2hex(random_bytes(32));
}

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
        .merch-selector-list {
            max-height: 210px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            background: #fff;
        }

        .merch-selector-item {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            padding: 8px 10px;
            border-bottom: 1px solid #eef1f4;
            cursor: pointer;
        }

        .merch-selector-item:last-child { border-bottom: 0; }
        .merch-selector-item:hover { background: #f5f9ff; }
        .merch-selector-item small { display: block; color: #6c757d; }
        .georef-result-box { min-height: 42px; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-primary mb-2">
            <i class="fa-solid fa-route"></i> Visualizador de Rutas Planificadas
        </h2>
        <p class="text-muted mb-0">
            Carga locales, reutiliza rutas o importa el orden de visita entregado por un cliente. Los datos de cada local se completan desde Visibility.
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
                    <button class="nav-link" id="tab-rutas-base-btn" data-bs-toggle="tab" data-bs-target="#tab-rutas-base" type="button" role="tab">
                        <i class="fa-solid fa-arrows-rotate"></i> Reutilizar rutas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-orden-cliente-btn" data-bs-toggle="tab" data-bs-target="#tab-orden-cliente" type="button" role="tab">
                        <i class="fa-solid fa-list-ol"></i> Orden del cliente
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
                                        La columna usuario funciona como responsable o agrupador de ruta. Puede contener un nombre nuevo aunque todavía no exista en el sistema. El mapa y el optimizador usan la dirección y las coordenadas guardadas en la tabla <strong>local</strong>.
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

                <div class="tab-pane fade" id="tab-orden-cliente" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <i class="fa-solid fa-list-ol"></i> Cargar orden de visita entregado por el cliente
                                </div>
                                <div class="card-body">
                                    <form id="formUploadOrdenCliente" enctype="multipart/form-data">
                                        <input type="hidden" name="modo" value="orden_cliente">
                                        <div class="mb-3">
                                            <label for="idDivisionOrdenCliente" class="form-label fw-semibold">División</label>
                                            <?php if ($esMc): ?>
                                                <select class="form-select" id="idDivisionOrdenCliente" name="id_division" required>
                                                    <option value="">Selecciona una división</option>
                                                    <?php foreach ($divisionesDisponibles as $division): ?>
                                                        <option value="<?php echo (int)$division['id']; ?>"><?php echo htmlspecialchars($division['nombre'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="hidden" id="idDivisionOrdenCliente" name="id_division" value="<?php echo $divisionSesionId; ?>">
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($divisionSesionNombre, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label for="archivoOrdenCliente" class="form-label fw-semibold">Archivo Excel del cliente</label>
                                            <input type="file" class="form-control" id="archivoOrdenCliente" name="ordenFile" accept=".xlsx,.xls" required>
                                        </div>
                                        <div class="mini-note mb-3">
                                            La hoja debe contener <strong>CODIGO LOCAL</strong>, <strong>USUARIO</strong>, <strong>FECHA VISITA</strong> y <strong>ORDEN VISITA</strong>. El orden se conserva exactamente; los datos del local se completan desde SQL.
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="submit" class="btn btn-warning" id="btnCargarOrdenCliente">
                                                <i class="fa-solid fa-wand-magic-sparkles"></i> Preparar planificación
                                            </button>
                                            <button type="button" class="btn btn-success" id="btnDescargarOrdenCliente" disabled>
                                                <i class="fa-solid fa-file-excel"></i> Descargar archivo para cliente
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card">
                                <div class="card-header bg-dark text-white">
                                    <i class="fa-solid fa-circle-info"></i> Cómo se transforma
                                </div>
                                <div class="card-body mini-note">
                                    <p>La combinación de <strong>usuario + fecha</strong> se convierte en una ruta diaria. La numeración comienza en Ruta 01 para cada trabajador.</p>
                                    <p>Nombre, dirección, comuna y coordenadas se consultan desde la división seleccionada. No se valida que el usuario exista y no se modifica el orden entregado.</p>
                                    <p class="mb-0">La descarga genera las hojas <strong>Resumen</strong>, <strong>Planificacion</strong>, <strong>Rutas Base</strong> y, cuando corresponda, <strong>Sin Ruta Asignada</strong>.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="ordenClienteResultBox" class="mt-4" style="display:none;"></div>
                </div>

                <div class="tab-pane fade" id="tab-rutas-base" role="tabpanel">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <i class="fa-solid fa-file-excel"></i> Cargar rutas ya construidas
                                </div>
                                <div class="card-body">
                                    <form id="formUploadRutasBase" enctype="multipart/form-data">
                                        <input type="hidden" name="modo" value="rutas_base">
                                        <div class="mb-3">
                                            <label for="archivoRutasBase" class="form-label fw-semibold">Archivo de rutas base</label>
                                            <input
                                                type="file"
                                                class="form-control"
                                                id="archivoRutasBase"
                                                name="archivoPlanificacion"
                                                accept=".xlsx,.xls"
                                                required
                                            >
                                        </div>
                                        <div class="mb-3">
                                            <label for="mesRutasBase" class="form-label fw-semibold">Mes inicial</label>
                                            <input type="month" class="form-control" id="mesRutasBase" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="mesFinRutasBase" class="form-label fw-semibold">Mes final</label>
                                            <input type="month" class="form-control" id="mesFinRutasBase" required>
                                            <div class="mini-note mt-1">Puedes seleccionar 3, 4 o más meses. Se asigna una ruta por cada día hábil y la frecuencia se ajusta durante todo el período.</div>
                                        </div>
                                        <button type="submit" class="btn btn-success" id="btnCargarRutasBase">
                                            <i class="fa-solid fa-calendar-plus"></i> Cargar y planificar período
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card">
                                <div class="card-header bg-dark text-white">
                                    <i class="fa-solid fa-circle-info"></i> Qué conserva esta carga
                                </div>
                                <div class="card-body">
                                    <div class="mini-note">
                                        Lee preferentemente la hoja <strong>Rutas Base</strong>. Mantiene el responsable, los locales asociados a cada ruta y el orden de visita, sin volver a ejecutar la optimización geográfica.
                                    </div>
                                    <hr>
                                    <div class="mini-note">
                                        Al descargar el rutero mensual o de varios meses, la hoja original <strong>Rutas Base</strong> se conserva con su contenido, fórmulas y formato. La planificación mensual se agrega en hojas separadas.
                                    </div>
                                    <hr>
                                    <div class="mini-note">
                                        La agenda comienza el primer día hábil, incluye la primera semana aunque su lunes pertenezca al mes anterior y cubre todos los días hábiles. Mantiene 14 días entre repeticiones cuando es posible.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="rutasBaseResultBox" class="mt-4" style="display:none;"></div>
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
                                                <i class="fa-solid fa-calendar-check"></i> Descargar planificación por período
                                            </button>
                                            <button class="btn btn-outline-success" id="btnDescargarRuta" type="button">
                                                <i class="fa-solid fa-route"></i> Descargar rutas seleccionadas
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
                                            Puedes dibujar varias áreas para acumular locales. Cuando termines, pulsa
                                            <strong>Finalizar selección</strong> para habilitar el arrastre de los puntos marcados.
                                        </div>

                                        <div class="mini-note mb-3">
                                            Para mover locales, selecciónalos y arrastra uno de sus marcadores encima de un local de la ruta de destino. Se abrirá el editor con el usuario, la ruta y sus fechas preparados.
                                        </div>

                                        <div id="leyendaRutas"></div>
                                    </div>
                                </div>

                                <div class="card mb-4">
                                    <div class="card-header bg-info text-white">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Planificador optimizado
                                    </div>
                                    <div class="card-body">
                                        <div class="border rounded-3 p-3 mb-4 bg-light">
                                            <div class="fw-semibold mb-1">
                                                <i class="fa-solid fa-people-group"></i> Distribución territorial
                                            </div>
                                            <div class="mini-note mb-2">
                                                Selecciona dos o más merchandisers. Los locales se repartirán equitativamente en sectores geográficos contiguos antes de generar las rutas.
                                            </div>
                                            <input type="search" class="form-control form-control-sm mb-2" id="buscarMerchReparto" placeholder="Buscar merchandiser...">
                                            <div id="listaMerchReparto" class="merch-selector-list">
                                                <div class="p-3 text-muted small">Selecciona una división para cargar trabajadores.</div>
                                            </div>
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" id="repartoSoloSeleccionados">
                                                <label class="form-check-label small" for="repartoSoloSeleccionados">Repartir sólo los locales seleccionados en el mapa</label>
                                            </div>
                                            <button class="btn btn-outline-info w-100 mt-2" id="btnRepartirMerch" type="button">
                                                <i class="fa-solid fa-object-group"></i> Repartir por sectores
                                            </button>
                                            <div class="mini-note mt-2" id="repartoMerchEstado">Los puntos sin coordenadas se asignarán al trabajador con menor carga.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optMetaDiaria" class="form-label fw-semibold">Meta mínima por día</label>
                                            <input type="number" min="2" max="30" class="form-control" id="optMetaDiaria" value="19">
                                            <div class="mini-note mt-1">Busca jornadas entre la meta y meta+2. Los grupos pequeños o aislados se unen a la ruta más cercana del mismo responsable, aunque el resultado supere 30 locales.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optAislamientoKm" class="form-label fw-semibold">Separación para considerar aislado</label>
                                            <div class="input-group">
                                                <input type="number" min="2" max="150" step="1" class="form-control" id="optAislamientoKm" value="25">
                                                <span class="input-group-text">km</span>
                                            </div>
                                            <div class="mini-note mt-1">Esta distancia detecta grupos aislados; ya no se dejan fuera, sino que se incorporan a la ruta más cercana del mismo responsable.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optFechaInicio" class="form-label fw-semibold">Fecha inicio</label>
                                            <input type="date" class="form-control" id="optFechaInicio">
                                        </div>

                                        <div class="mb-3">
                                            <label for="optMesPlanificacion" class="form-label fw-semibold">Mes inicial</label>
                                            <input type="month" class="form-control" id="optMesPlanificacion">
                                        </div>

                                        <div class="mb-3">
                                            <label for="optMesFinPlanificacion" class="form-label fw-semibold">Mes final</label>
                                            <input type="month" class="form-control" id="optMesFinPlanificacion">
                                            <div class="mini-note mt-1">Respeta la meta mínima y asigna una ruta por responsable en cada día hábil. Mantiene 14 días entre repeticiones cuando es posible; si la cobertura inicial no alcanza, continúa después del mes final.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="optPrefijoGrupo" class="form-label fw-semibold">Prefijo ruta</label>
                                            <input type="text" class="form-control" id="optPrefijoGrupo" value="RUTA OPT">
                                        </div>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="optSoloSeleccionados">
                                            <label class="form-check-label" for="optSoloSeleccionados">
                                                Optimizar únicamente los locales seleccionados
                                            </label>
                                            <div class="mini-note">Déjalo desmarcado para incluir todos los locales visibles. Si lo marcas, los puntos no seleccionados no se modificarán.</div>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <button class="btn btn-info text-white" id="btnOptimizarRutas" type="button">
                                                <i class="fa-solid fa-route"></i> Generar rutas optimizadas
                                            </button>
                                            <button class="btn btn-primary" id="btnPlanificarMes" type="button">
                                                <i class="fa-solid fa-calendar-days"></i> Planificar período + continuidad
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
                                                    <th>Frecuencia</th>
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
                    Se editarán <strong id="modalCantidadRutas">0</strong> ruta(s), con
                    <strong id="modalCantidadSeleccionados">0</strong> local(es) únicos.
                    Sólo esos locales se moverán o quedarán sin ruta; el cambio se aplicará a todas sus repeticiones.
                </div>

                <div class="mb-3">
                    <label for="editGrupoRuta" class="form-label fw-semibold">Grupo de ruta</label>
                    <input type="text" class="form-control" id="editGrupoRuta" list="listaGruposRuta" placeholder="Ej: RUTA 01">
                    <datalist id="listaGruposRuta"></datalist>
                    <div class="mini-note mt-1">Si eliges una ruta existente, se conserva su calendario. Para un grupo nuevo se usa el calendario más temprano de las rutas seleccionadas.</div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">Orden de visita</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnOrdenarSeleccionCercania">
                            <i class="fa-solid fa-arrow-down-short-wide"></i> Ordenar selección por cercanía
                        </button>
                    </div>
                    <div class="mini-note mb-2">También puedes usar las flechas para ajustar manualmente las paradas seleccionadas.</div>
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

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="editReordenarRutaCompleta" checked>
                    <label class="form-check-label" for="editReordenarRutaCompleta">
                        Reordenar automáticamente toda la ruta base resultante
                    </label>
                    <div class="mini-note">El mismo orden se aplicará a cada repetición quincenal de la ruta.</div>
                </div>

                <div class="mb-3">
                    <label for="editUsuarioNombre" class="form-label fw-semibold">Usuario</label>
                    <input type="text" class="form-control" id="editUsuarioNombre" placeholder="Nombre del usuario">
                    <div class="mini-note mt-1" id="editFechaDestinoHint">La fecha se tomará del calendario de la ruta de destino.</div>
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

<div class="modal fade" id="modalGeorreferencia" tabindex="-1" aria-labelledby="modalGeorreferenciaLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalGeorreferenciaLabel">
                    <i class="fa-solid fa-location-crosshairs"></i> Actualizar ubicación del local
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="geoLocalId">
                <div class="alert alert-light border py-2">
                    <strong id="geoLocalCodigo"></strong> · <span id="geoLocalNombre"></span>
                </div>
                <div class="mb-3">
                    <label for="geoDireccion" class="form-label fw-semibold">Dirección corregida</label>
                    <textarea class="form-control" id="geoDireccion" rows="2" maxlength="500" placeholder="Ej: Av. Américo Vespucio N° 2001, Renca, Región Metropolitana"></textarea>
                    <div class="mini-note mt-1">La dirección original se conserva; esta corrección se guardará como dirección validada.</div>
                </div>
                <div class="d-grid mb-3">
                    <button type="button" class="btn btn-outline-primary" id="btnBuscarGeorreferencia">
                        <i class="fa-solid fa-magnifying-glass-location"></i> Buscar dirección en Google Maps
                    </button>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label for="geoLat" class="form-label small fw-semibold">Latitud encontrada</label>
                        <input class="form-control" id="geoLat" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="geoLng" class="form-label small fw-semibold">Longitud encontrada</label>
                        <input class="form-control" id="geoLng" readonly>
                    </div>
                </div>
                <div id="geoResultado" class="georef-result-box mini-note"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnGuardarGeorreferencia" disabled>
                    <i class="fa-solid fa-floppy-disk"></i> Guardar y actualizar mapa
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
let loadedRouteBaseRows = [];
let loadedRouteBaseFile = null;
let planGroups = [];
let planSummary = {};
let planFilters = { usuarios: [], fechas: [], grupos: [] };
let selectedRowIds = new Set();
let editOrderRows = [];
let editScopeRouteGroups = new Set();
let editScopeUnplannedKeys = new Set();
let editScopeSelectedLocalKeys = new Set();
let editDestinationRouteIdentity = '';
let editDestinationUserTemplate = null;
let modalEditarRuta;
let mapSelectionMode = false;
let mapProjectionOverlay;
let selectionDragStart = null;
let optimizationWarnings = [];
let availableMerchWorkers = [];
let selectedMerchWorkerIds = new Set();
let modalGeorreferencia;
let pendingGeoreference = null;

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
    directionsServiceRutas = new google.maps.DirectionsService();

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
            || routeDownloadIdentity(row) === String(filters.grupo);

        return okUsuario && okFecha && okGrupo;
    });
}

function routeLevelLocalKey(row) {
    return `${routeDownloadIdentity(row)}::${routeBaseLocalKey(row)}`;
}

function getRouteLevelRows(rows = getFilteredRows()) {
    const uniqueRows = new Map();
    rows.forEach(row => {
        const key = routeLevelLocalKey(row);
        const current = uniqueRows.get(key);
        if (!current || String(row.fecha_ruta_sql || '') < String(current.fecha_ruta_sql || '')) {
            uniqueRows.set(key, row);
        }
    });
    return [...uniqueRows.values()];
}

function selectedRouteLevelKeys() {
    return new Set(
        planRows.filter(row => selectedRowIds.has(row.row_id)).map(routeLevelLocalKey)
    );
}

function isRouteLevelRowSelected(row) {
    return selectedRouteLevelKeys().has(routeLevelLocalKey(row));
}

function setRouteLevelRowSelection(row, selected) {
    const selectionKey = routeLevelLocalKey(row);
    planRows.forEach(candidate => {
        if (routeLevelLocalKey(candidate) !== selectionKey) return;
        if (selected) selectedRowIds.add(candidate.row_id);
        else selectedRowIds.delete(candidate.row_id);
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
    const filteredRows = getFilteredRows();
    const rows = getRouteLevelRows(filteredRows);
    const datesByGroup = new Map();
    filteredRows.forEach(row => {
        const key = routeDownloadIdentity(row);
        if (!datesByGroup.has(key)) datesByGroup.set(key, new Set());
        if (row.fecha_ruta_sql) datesByGroup.get(key).add(row.fecha_ruta_sql);
    });
    const groupMap = {};

    rows.forEach(row => {
        const key = routeDownloadIdentity(row);
        if (!groupMap[key]) {
            groupMap[key] = {
                grupo_ruta: row.sin_ruta ? 'SIN RUTA' : row.grupo_ruta,
                group_key: key,
                is_unplanned: Boolean(row.sin_ruta),
                ruta_global: row.ruta_global || '',
                usuario_id: row.usuario_id || '',
                usuario_login: row.usuario_login || '',
                usuario_nombre: row.usuario_nombre || '',
                fecha_ruta: row.fecha_ruta || '',
                fecha_ruta_sql: row.fecha_ruta_sql || '',
                total_fechas: datesByGroup.get(key)?.size || 0,
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
    const rows = getRouteLevelRows();
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
    const currentRows = getRouteLevelRows();
    const hasUnplanned = currentRows.some(row => row.sin_ruta);
    const routeOptions = new Map();
    currentRows.filter(row => !row.sin_ruta && row.grupo_ruta).forEach(row => {
        const identity = routeDownloadIdentity(row);
        if (!routeOptions.has(identity)) routeOptions.set(identity, row);
    });
    const availableGroups = [...routeOptions.keys()].sort((a, b) => {
        const rowA = routeOptions.get(a) || {};
        const rowB = routeOptions.get(b) || {};
        return String(rowA.usuario_nombre || rowA.usuario_login || '').localeCompare(String(rowB.usuario_nombre || rowB.usuario_login || ''), 'es', { sensitivity: 'base' })
            || String(rowA.grupo_ruta || '').localeCompare(String(rowB.grupo_ruta || ''), 'es', { numeric: true, sensitivity: 'base' });
    });
    const currentValue = $('#filtroGrupoRuta').val() || '__ALL__';

    const select = $('#filtroGrupoRuta').empty();
    select.append('<option value="__ALL__">Todas las rutas</option>');
    if (hasUnplanned) {
        const totalSinRuta = currentRows.filter(row => row.sin_ruta).length;
        select.append(`<option value="__UNPLANNED__">SIN RUTA · ${totalSinRuta} locales</option>`);
    }

    availableGroups.forEach(routeIdentity => {
        const routeRow = routeOptions.get(routeIdentity) || {};
        const total = currentRows.filter(row => routeDownloadIdentity(row) === routeIdentity).length;
        const userLabel = routeRow.usuario_nombre || routeRow.usuario_login || routeRow.usuario_id || 'SIN USUARIO';
        select.append(`
            <option value="${escapeHtml(routeIdentity)}">
                ${escapeHtml(userLabel)} · ${escapeHtml(routeRow.grupo_ruta || '')} · ${total} paradas
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
        const color = group.is_unplanned ? '#dc2626' : getRouteColor(group.group_key);
        container.append(`
            <div class="legend-item">
                <span class="legend-line" style="background:${color};"></span>
                <span class="mini-note">${escapeHtml(group.usuario_nombre || group.usuario_login || '')} · ${escapeHtml(group.grupo_ruta)}</span>
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
        const isUnplanned = group.is_unplanned;
        const color = isUnplanned ? '#dc2626' : getRouteColor(group.group_key);

        tbody.append(`
            <tr class="clickable-row ${isUnplanned ? 'unplanned-row' : ''}" data-group="${escapeHtml(isUnplanned ? '__UNPLANNED__' : group.group_key)}">
                <td>
                    <span class="route-color-box" style="background:${color};"></span>
                    ${escapeHtml(group.grupo_ruta)}
                </td>
                <td>${escapeHtml(group.usuario_nombre || group.usuario_login || '')}</td>
                <td>${group.total_fechas} fecha${group.total_fechas === 1 ? '' : 's'}</td>
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
    const selectedKeys = selectedRouteLevelKeys();

    rows.forEach(row => {
        const selected = selectedKeys.has(routeLevelLocalKey(row));
        tbody.append(`
            <tr class="clickable-row ${row.sin_ruta ? 'unplanned-row' : ''} ${selected ? 'selected-row' : ''}"
                data-row-id="${escapeHtml(row.row_id)}"
                data-group="${escapeHtml(row.sin_ruta ? '__UNPLANNED__' : routeDownloadIdentity(row))}"
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
    const selectedKeys = selectedRouteLevelKeys();
    const count = selectedKeys.size;
    $('#badgeSeleccionados').text(`${count} seleccionado${count === 1 ? '' : 's'}`);
    $('#mapSelectedCount').text(count);
    $('#btnEditarSeleccionados').prop('disabled', count === 0);

    const visibleKeys = getRouteLevelRows().map(routeLevelLocalKey);
    const allVisibleSelected = visibleKeys.length > 0 && visibleKeys.every(key => selectedKeys.has(key));
    $('#checkSeleccionarVisibles').prop('checked', allVisibleSelected);
}

function toggleRowSelection(rowId, forceValue = null) {
    const row = planRows.find(candidate => candidate.row_id === rowId);
    if (!row) return;
    const shouldSelect = forceValue === null ? !isRouteLevelRowSelected(row) : forceValue;
    setRouteLevelRowSelection(row, shouldSelect);
    renderDetalleTable();
    renderMap(false);
}

function selectRowsInsideBounds(bounds) {
    let added = 0;

    const selectedKeys = selectedRouteLevelKeys();
    getRouteLevelRows().forEach(row => {
        if (!hasUsableCoordinates(row)) return;
        const lat = Number(row.lat);
        const lng = Number(row.lng);

        const position = new google.maps.LatLng(lat, lng);
        const selectionKey = routeLevelLocalKey(row);
        if (bounds.contains(position) && !selectedKeys.has(selectionKey)) {
            setRouteLevelRowSelection(row, true);
            selectedKeys.add(selectionKey);
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
            ? '<i class="fa-solid fa-check"></i> Finalizar selección'
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

    markersRutas.forEach(marker => {
        const row = marker.__routeRow;
        if (!row) return;
        const draggable = !mapSelectionMode && isRouteLevelRowSelected(row);
        marker.setDraggable(draggable);
        marker.setCursor(draggable ? 'grab' : 'pointer');
    });
}

function markerDropPixelDistance(firstPosition, secondPosition) {
    const projection = mapProjectionOverlay?.getProjection();
    if (!projection || !firstPosition || !secondPosition) return Number.POSITIVE_INFINITY;
    const firstPoint = projection.fromLatLngToDivPixel(firstPosition);
    const secondPoint = projection.fromLatLngToDivPixel(secondPosition);
    if (!firstPoint || !secondPoint) return Number.POSITIVE_INFINITY;
    return Math.hypot(firstPoint.x - secondPoint.x, firstPoint.y - secondPoint.y);
}

function findMarkerDropDestination(sourceMarker, dropPosition) {
    const selectedKeys = selectedRouteLevelKeys();
    let closest = null;
    let closestPixels = Number.POSITIVE_INFINITY;

    markersRutas.forEach(candidate => {
        const candidateRow = candidate.__routeRow;
        if (!candidateRow || candidate === sourceMarker || candidateRow.sin_ruta) return;
        if (selectedKeys.has(routeLevelLocalKey(candidateRow))) return;

        const pixels = markerDropPixelDistance(dropPosition, candidate.getPosition());
        if (pixels < closestPixels) {
            closestPixels = pixels;
            closest = candidate;
        }
    });

    return closestPixels <= 55 ? closest : null;
}

function handleSelectedMarkerDrop(marker, sourceRow, dropPosition) {
    const originalPosition = marker.__originalPosition
        || new google.maps.LatLng(Number(sourceRow.lat), Number(sourceRow.lng));
    const destinationMarker = findMarkerDropDestination(marker, dropPosition);
    marker.setPosition(originalPosition);

    if (!destinationMarker?.__routeRow) {
        alert('Suelta el marcador encima de un local no seleccionado que pertenezca a la ruta de destino.');
        return;
    }

    const destinationRow = destinationMarker.__routeRow;
    const selectedCount = selectedRouteLevelKeys().size;
    const destinationUser = destinationRow.usuario_nombre || destinationRow.usuario_login || destinationRow.usuario_id || 'SIN USUARIO';
    const confirmed = window.confirm(
        `¿Mover ${selectedCount} local${selectedCount === 1 ? '' : 'es'} a ${destinationUser} · ${destinationRow.grupo_ruta}?\n\n`
        + 'La ruta completa se reordenará y los locales adoptarán las fechas planificadas de la ruta de destino.'
    );
    if (!confirmed) return;

    openEditModal(destinationRow);
}

function createMarker(row, color) {
    const isUnplanned = Boolean(row.sin_ruta);
    const isSelected = isRouteLevelRowSelected(row);
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
        title: `${isUnplanned ? 'SIN RUTA' : row.grupo_ruta} - ${row.codigo_local}`,
        draggable: isSelected && !mapSelectionMode,
        cursor: isSelected && !mapSelectionMode ? 'grab' : 'pointer',
        zIndex: isSelected ? 1000 : undefined
    });
    marker.__routeRow = row;
    marker.__originalPosition = marker.getPosition();

    marker.addListener('dragstart', () => {
        marker.__originalPosition = new google.maps.LatLng(Number(row.lat), Number(row.lng));
        if (infoWindowRutas) infoWindowRutas.close();
    });

    marker.addListener('dragend', event => {
        handleSelectedMarkerDrop(marker, row, event.latLng);
    });

    marker.addListener('click', () => {
        if (mapSelectionMode) {
            toggleRowSelection(row.row_id);
            return;
        }

        infoWindowRutas.setContent(`
            <div style="min-width:260px;">
                <div><strong>Usuario:</strong> ${escapeHtml(row.usuario_nombre || row.usuario_login || '')}</div>
                <div><strong>Estado:</strong> ${isUnplanned ? '<span style="color:#dc2626;font-weight:700;">SIN RUTA</span>' : 'PLANIFICADO'}</div>
                <div><strong>Grupo:</strong> ${escapeHtml(row.grupo_ruta || '')}</div>
                ${row.grupo_ruta_sugerido ? `<div><strong>Grupo sugerido:</strong> ${escapeHtml(row.grupo_ruta_sugerido)}</div>` : ''}
                <div><strong>Repeticiones:</strong> ${escapeHtml(row.dias_planificados || 1)}</div>
                <div><strong>Orden:</strong> ${escapeHtml(row.orden_visita)}</div>
                <div><strong>Código:</strong> ${escapeHtml(row.codigo_local)}</div>
                <div><strong>Nombre:</strong> ${escapeHtml(row.nombre || '')}</div>
                <div><strong>Dirección:</strong> ${escapeHtml(row.direccion || '')}</div>
                ${row.direccion_original && row.direccion_original !== row.direccion ? `<div><strong>Dirección original:</strong> ${escapeHtml(row.direccion_original)}</div>` : ''}
                <div><strong>Comuna:</strong> ${escapeHtml(row.comuna || '')}</div>
                <div><strong>Origen coordenadas:</strong> ${escapeHtml(row.coordenadas_origen || 'SQL')}</div>
                ${Number(row.id_local) > 0 ? `
                    <button type="button" class="btn btn-sm btn-outline-success mt-2"
                        onclick="openGeoreferenceModal(${Number(row.id_local)})">
                        <i class="fa-solid fa-location-crosshairs"></i> Actualizar ubicación
                    </button>
                ` : ''}
                <button type="button" class="btn btn-sm btn-primary mt-2"
                    onclick="toggleRowSelection('${escapeHtml(row.row_id)}')">
                    ${isSelected ? 'Quitar selección' : 'Seleccionar local'}
                </button>
                ${isSelected ? '<div class="mt-2" style="font-size:12px;color:#475569;">Arrastra este marcador encima de otra ruta para mover todos los locales seleccionados.</div>' : ''}
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

    const rows = getRouteLevelRows()
        .filter(hasUsableCoordinates);

    if (!rows.length) {
        $('#badgeRutaActiva').text('Sin datos para el filtro actual');
        renderDetalleTable();
        return;
    }

    const groupMap = {};
    rows.forEach(row => {
        const groupKey = routeDownloadIdentity(row);
        if (!groupMap[groupKey]) {
            groupMap[groupKey] = [];
        }
        groupMap[groupKey].push(row);
    });

    const allBounds = new google.maps.LatLngBounds();
    const selectedGroup = $('#filtroGrupoRuta').val() || '__ALL__';

    Object.keys(groupMap).sort().forEach(groupName => {
        const groupRows = groupMap[groupName].sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
        const isUnplannedGroup = Boolean(groupRows[0]?.sin_ruta);
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
            : (selectedGroup === '__UNPLANNED__'
                ? 'SIN RUTA'
                : `${groupMap[selectedGroup]?.[0]?.usuario_nombre || groupMap[selectedGroup]?.[0]?.usuario_login || ''} · ${groupMap[selectedGroup]?.[0]?.grupo_ruta || ''}`)
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
        grupos: [...new Set(planRows.filter(row => !row.sin_ruta && row.grupo_ruta).map(routeDownloadIdentity))].sort()
    };

    planSummary.sin_ruta = planRows.filter(row => row.sin_ruta).length;
    planSummary.total_grupos = planFilters.grupos.length;
    planSummary.total_usuarios = planFilters.usuarios.length;
    planSummary.total_fechas = planFilters.fechas.length;
}

function rowMatchesUserFilter(row, userFilter) {
    return userFilter === '__ALL__'
        || String(row.usuario_id || '') === String(userFilter)
        || String(row.usuario_login || '') === String(userFilter);
}

function routeUserFilterValue(row = {}) {
    return String(row.usuario_id || row.usuario_login || '__ALL__');
}

function restoreRouteFilters(savedFilters = {}, fallbackRouteIdentity = '') {
    let desiredUser = savedFilters.usuario || '__ALL__';
    let desiredDate = savedFilters.fecha || '__ALL__';
    let desiredGroup = savedFilters.grupo || '__ALL__';
    const fallbackRow = fallbackRouteIdentity
        ? planRows.find(row => !row.sin_ruta && routeDownloadIdentity(row) === fallbackRouteIdentity)
        : null;

    if (desiredUser !== '__ALL__' && !planRows.some(row => rowMatchesUserFilter(row, desiredUser))) {
        desiredUser = fallbackRow ? routeUserFilterValue(fallbackRow) : '__ALL__';
    }
    if (desiredDate !== '__ALL__' && !planRows.some(row =>
        String(row.fecha_ruta_sql || row.fecha_ruta || '') === String(desiredDate)
    )) {
        desiredDate = '__ALL__';
    }

    if (desiredGroup !== '__ALL__') {
        const groupRows = desiredGroup === '__UNPLANNED__'
            ? planRows.filter(row => row.sin_ruta)
            : planRows.filter(row => !row.sin_ruta && routeDownloadIdentity(row) === desiredGroup);

        if (groupRows.length) {
            if (desiredUser !== '__ALL__' && !groupRows.some(row => rowMatchesUserFilter(row, desiredUser))) {
                desiredUser = routeUserFilterValue(groupRows[0]);
            }
            if (desiredDate !== '__ALL__' && !groupRows.some(row =>
                String(row.fecha_ruta_sql || row.fecha_ruta || '') === String(desiredDate)
            )) {
                desiredDate = '__ALL__';
            }
        } else {
            if (fallbackRow) {
                desiredGroup = fallbackRouteIdentity;
                desiredUser = routeUserFilterValue(fallbackRow);
                if (desiredDate !== '__ALL__' && !planRows.some(row =>
                    !row.sin_ruta
                    && routeDownloadIdentity(row) === fallbackRouteIdentity
                    && String(row.fecha_ruta_sql || row.fecha_ruta || '') === String(desiredDate)
                )) {
                    desiredDate = '__ALL__';
                }
            } else {
                desiredGroup = '__ALL__';
            }
        }
    }

    rebuildFiltersFromRows();
    renderUsuarioSelect();
    renderFechaSelect();
    $('#filtroUsuario').val(desiredUser);
    $('#filtroFechaRuta').val(desiredDate);
    $('#filtroGrupoRuta').val('__ALL__');
    renderGroupSelect();

    const availableRouteIdentities = new Set(
        getRouteLevelRows().map(row => row.sin_ruta ? '__UNPLANNED__' : routeDownloadIdentity(row))
    );
    $('#filtroGrupoRuta').val(availableRouteIdentities.has(desiredGroup) ? desiredGroup : '__ALL__');
    refreshAllViews(false);
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

function routeBaseLocalKey(row) {
    return String(row.id_local || row.codigo_local || row.row_id || '');
}

function getRouteEditScopeRows() {
    const representatives = new Map();
    planRows.forEach(row => {
        const routeLocalKey = routeDownloadLocalKey(row);
        const isSelectedLocal = editScopeSelectedLocalKeys.has(routeLocalKey);
        const includeRoute = isSelectedLocal && !row.sin_ruta
            && editScopeRouteGroups.has(routeDownloadIdentity(row));
        const includeUnplanned = isSelectedLocal && row.sin_ruta
            && editScopeUnplannedKeys.has(routeLocalKey);
        if (!includeRoute && !includeUnplanned) return;

        const current = representatives.get(routeLocalKey);
        if (!current || String(row.fecha_ruta_sql || '') < String(current.fecha_ruta_sql || '')) {
            representatives.set(routeLocalKey, row);
        }
    });

    return [...representatives.values()].sort((a, b) => {
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

async function orderRowsByRouteProximity(rows) {
    const geoRows = rows.filter(hasUsableCoordinates);
    const rowsWithoutCoordinates = rows.filter(row => !hasUsableCoordinates(row));
    if (geoRows.length <= 1) return [...geoRows, ...rowsWithoutCoordinates];

    const geographicallyOrdered = sortRowsByNearestNeighbor(geoRows);
    const roadOrdered = await optimizeDailyRowsWithGoogle(geographicallyOrdered);
    return [...roadOrdered, ...rowsWithoutCoordinates];
}

async function reorderSelectedEditRows() {
    if (editOrderRows.length <= 1) return;
    const button = $('#btnOrdenarSeleccionCercania');
    const originalHtml = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Ordenando...');
    try {
        editOrderRows = await orderRowsByRouteProximity(editOrderRows);
        renderEditOrderTable();
    } finally {
        button.prop('disabled', false).html(originalHtml);
    }
}

function normalizedRouteEditorText(value) {
    return String(value || '').trim().toLocaleUpperCase('es');
}

function routeEditorUserTemplate(row = {}) {
    return {
        usuario_id: row.usuario_id || '',
        usuario_login: row.usuario_login || '',
        usuario_nombre: row.usuario_nombre || '',
    };
}

function findRouteEditorUserTemplate(value) {
    const normalized = normalizedRouteEditorText(value);
    if (!normalized) return null;
    const matchingRow = planRows.find(row => [row.usuario_id, row.usuario_login, row.usuario_nombre]
        .some(candidate => normalizedRouteEditorText(candidate) === normalized));
    return matchingRow ? routeEditorUserTemplate(matchingRow) : null;
}

function routeIdentityForEditor(userTemplate, groupName) {
    return `${planningUserKey(userTemplate)}::${String(groupName || '')}`;
}

function routeEditorUserDisplay(userTemplate = {}) {
    return String(userTemplate.usuario_nombre || userTemplate.usuario_login || userTemplate.usuario_id || '');
}

function routeEditorInputMatchesUser(value, userTemplate = {}) {
    const normalized = normalizedRouteEditorText(value);
    if (!normalized) return false;
    return [userTemplate.usuario_id, userTemplate.usuario_login, userTemplate.usuario_nombre]
        .some(candidate => normalizedRouteEditorText(candidate) === normalized);
}

function openEditModal(destinationRow = null) {
    if (!selectedRowIds.size) return;

    const directlySelectedRows = planRows.filter(row => selectedRowIds.has(row.row_id));
    editScopeSelectedLocalKeys = new Set(directlySelectedRows.map(routeDownloadLocalKey));
    editScopeRouteGroups = new Set(
        directlySelectedRows.filter(row => !row.sin_ruta && row.grupo_ruta).map(routeDownloadIdentity)
    );
    editScopeUnplannedKeys = new Set(
        directlySelectedRows.filter(row => row.sin_ruta).map(routeDownloadLocalKey)
    );
    editOrderRows = getRouteEditScopeRows();
    editDestinationRouteIdentity = '';
    editDestinationUserTemplate = null;

    $('#modalCantidadSeleccionados').text(editOrderRows.length);
    $('#modalCantidadRutas').text(Math.max(1, editScopeRouteGroups.size));
    $('#editGrupoRuta, #editUsuarioNombre').val('');
    $('#editFechaDestinoHint').text('La fecha se tomará del calendario de la ruta de destino.');
    if (editScopeRouteGroups.size === 1) {
        const sourceIdentity = [...editScopeRouteGroups][0];
        const sourceRow = planRows.find(row => !row.sin_ruta && routeDownloadIdentity(row) === sourceIdentity);
        if (sourceRow) {
            editDestinationRouteIdentity = sourceIdentity;
            editDestinationUserTemplate = {
                ...routeEditorUserTemplate(sourceRow),
                grupo_ruta: sourceRow.grupo_ruta,
            };
            $('#editGrupoRuta').val(sourceRow.grupo_ruta || '');
            $('#editUsuarioNombre').val(routeEditorUserDisplay(sourceRow));
        }
    } else if (editOrderRows.length === 1 && editOrderRows[0].grupo_ruta_sugerido) {
        $('#editGrupoRuta').val(editOrderRows[0].grupo_ruta_sugerido);
    }
    $('#editQuitarRuta').prop('checked', false);
    $('#editReordenarRutaCompleta').prop('checked', true);

    const groups = [...new Set(planRows.map(row => row.grupo_ruta).filter(Boolean))].sort();
    $('#listaGruposRuta').html(groups.map(group => `<option value="${escapeHtml(group)}"></option>`).join(''));

    if (destinationRow && !destinationRow.sin_ruta && destinationRow.grupo_ruta) {
        editDestinationRouteIdentity = routeDownloadIdentity(destinationRow);
        editDestinationUserTemplate = {
            ...routeEditorUserTemplate(destinationRow),
            grupo_ruta: destinationRow.grupo_ruta,
        };
        $('#editGrupoRuta').val(destinationRow.grupo_ruta);
        $('#editUsuarioNombre').val(routeEditorUserDisplay(editDestinationUserTemplate));
        const routeDates = uniqueRouteScheduleRows(
            planRows.filter(row => !row.sin_ruta && routeDownloadIdentity(row) === editDestinationRouteIdentity)
        ).map(row => row.fecha_ruta || row.fecha_ruta_sql).filter(Boolean);
        const dateLabel = routeDates.length
            ? `${routeDates.slice(0, 3).join(', ')}${routeDates.length > 3 ? ` y ${routeDates.length - 3} más` : ''}`
            : 'sin fecha definida';
        $('#editFechaDestinoHint').text(
            `Destino: ${routeEditorUserDisplay(editDestinationUserTemplate)} · ${destinationRow.grupo_ruta}. Fechas que se aplicarán: ${dateLabel}.`
        );
    }

    renderEditOrderTable();

    modalEditarRuta.show();
}

function uniqueRouteScheduleRows(rows) {
    const dates = new Map();
    rows
        .filter(row => row.fecha_ruta_sql)
        .sort((a, b) => String(a.fecha_ruta_sql).localeCompare(String(b.fecha_ruta_sql)))
        .forEach(row => {
            if (!dates.has(row.fecha_ruta_sql)) dates.set(row.fecha_ruta_sql, row);
        });
    return [...dates.values()];
}

function routeTemplateDistance(orderedRows) {
    let totalDistanceKm = 0;
    const distances = orderedRows.map((row, index) => {
        if (index === 0) return 0;
        const distance = distanceBetweenRows(orderedRows[index - 1], row);
        if (!Number.isFinite(distance)) return 0;
        totalDistanceKm += distance;
        return distance;
    });
    return { distances, totalDistanceKm };
}

async function reorderExistingRouteTemplate(routeIdentity, useProximity = true) {
    const routeRows = planRows.filter(row =>
        !row.sin_ruta && routeDownloadIdentity(row) === String(routeIdentity || '')
    );
    if (!routeRows.length) return;

    const representatives = new Map();
    routeRows.forEach(row => {
        const key = routeBaseLocalKey(row);
        if (!representatives.has(key)) representatives.set(key, row);
    });
    let templateRows = [...representatives.values()]
        .sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
    if (useProximity) templateRows = await orderRowsByRouteProximity(templateRows);
    const templateOrder = new Map(templateRows.map((row, index) => [routeBaseLocalKey(row), index]));

    const rowsByDate = new Map();
    routeRows.forEach(row => {
        const dateKey = String(row.fecha_ruta_sql || '');
        if (!rowsByDate.has(dateKey)) rowsByDate.set(dateKey, []);
        rowsByDate.get(dateKey).push(row);
    });
    rowsByDate.forEach(dateRows => {
        dateRows.sort((a, b) =>
            Number(templateOrder.get(routeBaseLocalKey(a)) ?? Number.MAX_SAFE_INTEGER)
            - Number(templateOrder.get(routeBaseLocalKey(b)) ?? Number.MAX_SAFE_INTEGER)
        );
        const { distances, totalDistanceKm } = routeTemplateDistance(dateRows);
        dateRows.forEach((row, index) => {
            row.orden_visita = index + 1;
            row.tamano_ruta = dateRows.length;
            row.distancia_desde_anterior_km = distances[index] || 0;
            row.distancia_total_ruta_km = totalDistanceKm;
            row.route_polyline = '';
        });
    });
}

async function applyRouteEdits() {
    const filtersBeforeUpdate = getCurrentFilters();
    const grupo = $('#editGrupoRuta').val().trim();
    const usuarioInput = $('#editUsuarioNombre').val().trim();
    const quitarRuta = $('#editQuitarRuta').is(':checked');
    const reorderCompleteRoute = $('#editReordenarRutaCompleta').is(':checked');
    const sourceRouteIdentities = [...editScopeRouteGroups];
    const singleSourceRow = sourceRouteIdentities.length === 1
        ? planRows.find(row => !row.sin_ruta && routeDownloadIdentity(row) === sourceRouteIdentities[0])
        : null;
    const destinationGroup = grupo || String(singleSourceRow?.grupo_ruta || '');
    if (!quitarRuta && !destinationGroup) {
        alert('Debes indicar el grupo de ruta resultante para unir las rutas seleccionadas.');
        return;
    }

    const button = $('#btnAplicarEdicion');
    const originalHtml = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Aplicando...');

    try {
        const selectedSourceRows = planRows.filter(row => {
            const routeLocalKey = routeDownloadLocalKey(row);
            return editScopeSelectedLocalKeys.has(routeLocalKey)
                || editScopeUnplannedKeys.has(routeLocalKey);
        });

        if (!selectedSourceRows.length) {
            throw new Error('No se encontraron los locales seleccionados dentro de las rutas originales.');
        }

        let destinationUser = null;
        let destinationRouteIdentity = '';
        if (!quitarRuta) {
            const forcedDestinationValid = editDestinationRouteIdentity
                && editDestinationUserTemplate
                && normalizedRouteEditorText(destinationGroup) === normalizedRouteEditorText(editDestinationUserTemplate.grupo_ruta)
                && routeEditorInputMatchesUser(usuarioInput, editDestinationUserTemplate);

            if (forcedDestinationValid) {
                destinationUser = routeEditorUserTemplate(editDestinationUserTemplate);
                destinationRouteIdentity = editDestinationRouteIdentity;
            } else if (usuarioInput) {
                destinationUser = findRouteEditorUserTemplate(usuarioInput) || {
                    usuario_id: '',
                    usuario_login: usuarioInput,
                    usuario_nombre: usuarioInput,
                };
                destinationRouteIdentity = routeIdentityForEditor(destinationUser, destinationGroup);
            } else {
                const selectedUsers = new Map();
                selectedSourceRows.forEach(row => {
                    const userTemplate = routeEditorUserTemplate(row);
                    selectedUsers.set(planningUserKey(userTemplate), userTemplate);
                });
                if (selectedUsers.size > 1) {
                    throw new Error('Los locales seleccionados pertenecen a más de un usuario. Indica el usuario de destino.');
                }
                destinationUser = [...selectedUsers.values()][0] || routeEditorUserTemplate(singleSourceRow || {});
                destinationRouteIdentity = routeIdentityForEditor(destinationUser, destinationGroup);
            }
        }

        const existingDestinationRows = !quitarRuta
            ? planRows.filter(row => !row.sin_ruta && routeDownloadIdentity(row) === destinationRouteIdentity)
            : [];
        const templateRows = quitarRuta
            ? selectedSourceRows
            : [...selectedSourceRows, ...existingDestinationRows];
        const baseRowsByKey = new Map();
        templateRows.forEach(row => {
            const key = quitarRuta ? routeDownloadLocalKey(row) : routeBaseLocalKey(row);
            const current = baseRowsByKey.get(key);
            if (!current || String(row.fecha_ruta_sql || '') < String(current.fecha_ruta_sql || '')) {
                baseRowsByKey.set(key, { ...row });
            }
        });

        let baseRows = [...baseRowsByKey.values()];
        const manualOrder = new Map(editOrderRows.map((row, index) => [routeBaseLocalKey(row), index]));
        baseRows.sort((a, b) => {
            const orderA = manualOrder.has(routeBaseLocalKey(a)) ? manualOrder.get(routeBaseLocalKey(a)) : Number.MAX_SAFE_INTEGER;
            const orderB = manualOrder.has(routeBaseLocalKey(b)) ? manualOrder.get(routeBaseLocalKey(b)) : Number.MAX_SAFE_INTEGER;
            return orderA - orderB || Number(a.orden_visita || 0) - Number(b.orden_visita || 0);
        });
        if (reorderCompleteRoute) baseRows = await orderRowsByRouteProximity(baseRows);

        if (quitarRuta) {
            planRows = planRows.filter(row => {
                const routeLocalKey = routeDownloadLocalKey(row);
                return !editScopeSelectedLocalKeys.has(routeLocalKey)
                    && !editScopeUnplannedKeys.has(routeLocalKey);
            });
            baseRows.forEach((row, index) => {
                const unplanned = { ...row, row_id: `edit_sin_ruta_${Date.now()}_${index}` };
                leaveRowWithoutRoute(unplanned, 'Ruta retirada desde el editor de rutas base.');
                planRows.push(unplanned);
            });
            for (const sourceRouteIdentity of sourceRouteIdentities) {
                await reorderExistingRouteTemplate(sourceRouteIdentity, reorderCompleteRoute);
            }
        } else {
            let scheduleRows = uniqueRouteScheduleRows(existingDestinationRows);
            if (!scheduleRows.length) {
                const sourceRoutesByFirstDate = sourceRouteIdentities
                    .map(routeIdentity => ({
                        routeIdentity,
                        rows: selectedSourceRows.filter(row => routeDownloadIdentity(row) === routeIdentity),
                    }))
                    .filter(item => item.rows.some(row => row.fecha_ruta_sql))
                    .sort((a, b) => String(uniqueRouteScheduleRows(a.rows)[0]?.fecha_ruta_sql || '')
                        .localeCompare(String(uniqueRouteScheduleRows(b.rows)[0]?.fecha_ruta_sql || '')));
                scheduleRows = sourceRoutesByFirstDate.length
                    ? uniqueRouteScheduleRows(sourceRoutesByFirstDate[0].rows)
                    : [];
            }
            if (!scheduleRows.length) {
                throw new Error('La ruta base necesita al menos una ruta existente con fechas para conservar su frecuencia.');
            }

            planRows = planRows.filter(row => {
                const routeIdentity = routeDownloadIdentity(row);
                const routeLocalKey = routeDownloadLocalKey(row);
                if (!row.sin_ruta && routeIdentity === destinationRouteIdentity) return false;
                if (editScopeSelectedLocalKeys.has(routeLocalKey)) return false;
                if (editScopeUnplannedKeys.has(routeLocalKey)) return false;
                return true;
            });
            const { distances, totalDistanceKm } = routeTemplateDistance(baseRows);
            const mutationId = Date.now();
            scheduleRows.forEach((scheduleRow, occurrenceIndex) => {
                baseRows.forEach((baseRow, orderIndex) => {
                    const planned = {
                        ...baseRow,
                        row_id: `edit_ruta_${mutationId}_${occurrenceIndex}_${orderIndex}`,
                        grupo_ruta: destinationGroup,
                        ruta_global: `${planningUserKey(destinationUser)} | ${destinationGroup}`,
                        usuario_id: destinationUser.usuario_id || '',
                        usuario_login: destinationUser.usuario_login || '',
                        usuario_nombre: destinationUser.usuario_nombre || destinationUser.usuario_login || destinationUser.usuario_id || '',
                        fecha_ruta: scheduleRow.fecha_ruta,
                        fecha_ruta_sql: scheduleRow.fecha_ruta_sql,
                        dia_semana: scheduleRow.dia_semana,
                        dia_semana_num: scheduleRow.dia_semana_num,
                        semana_plan: scheduleRow.semana_plan,
                        dia_plan: scheduleRow.dia_plan || occurrenceIndex + 1,
                        orden_visita: orderIndex + 1,
                        tamano_ruta: baseRows.length,
                        dias_planificados: scheduleRows.length,
                        distancia_desde_anterior_km: distances[orderIndex] || 0,
                        distancia_total_ruta_km: totalDistanceKm,
                        route_polyline: '',
                        observacion: 'Ruta base modificada; cambio aplicado a todas sus repeticiones.',
                        sin_ruta: false
                    };
                    planRows.push(planned);
                });
            });
            for (const sourceRouteIdentity of sourceRouteIdentities) {
                if (sourceRouteIdentity !== destinationRouteIdentity) {
                    await reorderExistingRouteTemplate(sourceRouteIdentity, reorderCompleteRoute);
                }
            }
        }

        selectedRowIds.clear();
        editOrderRows = [];
        editScopeRouteGroups.clear();
        editScopeUnplannedKeys.clear();
        editScopeSelectedLocalKeys.clear();
        editDestinationRouteIdentity = '';
        editDestinationUserTemplate = null;
        restoreRouteFilters(
            filtersBeforeUpdate,
            !quitarRuta ? destinationRouteIdentity : ''
        );
        modalEditarRuta.hide();
    } catch (error) {
        console.error(error);
        alert(error.message || 'No se pudo reordenar la ruta modificada.');
    } finally {
        button.prop('disabled', false).html(originalHtml);
    }
}

function selectedPlanningDivision() {
    return Number($('#idDivisionPlanificacion').val() || 0);
}

function renderMerchWorkers(search = '') {
    const container = document.getElementById('listaMerchReparto');
    if (!container) return;

    container.querySelectorAll('.merch-reparto-check').forEach(input => {
        if (input.checked) selectedMerchWorkerIds.add(String(input.value));
        else selectedMerchWorkerIds.delete(String(input.value));
    });
    const needle = String(search || '').trim().toLocaleLowerCase('es');
    const visible = availableMerchWorkers.filter(worker => {
        const text = `${worker.nombre} ${worker.usuario} ${worker.clasificacion}`.toLocaleLowerCase('es');
        return !needle || text.includes(needle);
    });

    if (!visible.length) {
        container.innerHTML = '<div class="p-3 text-muted small">No se encontraron trabajadores activos.</div>';
        return;
    }

    container.innerHTML = visible.map(worker => `
        <label class="merch-selector-item">
            <input class="form-check-input merch-reparto-check" type="checkbox" value="${Number(worker.id)}" ${selectedMerchWorkerIds.has(String(worker.id)) ? 'checked' : ''}>
            <span>
                <strong>${escapeHtml(worker.nombre || worker.usuario)}</strong>
                <small>${escapeHtml(worker.usuario || '')}${worker.clasificacion ? ` · ${escapeHtml(worker.clasificacion)}` : ''}</small>
            </span>
        </label>
    `).join('');

    container.querySelectorAll('.merch-reparto-check').forEach(input => {
        input.addEventListener('change', () => {
            if (input.checked) selectedMerchWorkerIds.add(String(input.value));
            else selectedMerchWorkerIds.delete(String(input.value));
        });
    });
}

async function loadMerchWorkers() {
    const division = selectedPlanningDivision();
    const container = document.getElementById('listaMerchReparto');
    if (!container) return;

    availableMerchWorkers = [];
    selectedMerchWorkerIds.clear();
    if (!division) {
        container.innerHTML = '<div class="p-3 text-muted small">Selecciona una división para cargar trabajadores.</div>';
        return;
    }

    container.innerHTML = '<div class="p-3 text-muted small"><i class="fa-solid fa-spinner fa-spin"></i> Cargando trabajadores...</div>';
    try {
        const response = await fetch(`mod_listar_merch_rutas.php?id_division=${encodeURIComponent(division)}`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'No se pudo cargar el listado.');
        availableMerchWorkers = Array.isArray(data.trabajadores) ? data.trabajadores : [];
        renderMerchWorkers($('#buscarMerchReparto').val());
    } catch (error) {
        console.error(error);
        container.innerHTML = `<div class="p-3 text-danger small">${escapeHtml(error.message || 'Error al cargar trabajadores.')}</div>`;
    }
}

function selectedWorkersForDistribution() {
    return availableMerchWorkers.filter(worker => selectedMerchWorkerIds.has(String(worker.id)));
}

function distributionScopeRepresentatives() {
    const onlySelected = $('#repartoSoloSeleccionados').is(':checked');
    const selectedBaseKeys = new Set(
        planRows.filter(row => selectedRowIds.has(row.row_id)).map(routeBaseLocalKey)
    );
    const representatives = new Map();

    planRows.forEach(row => {
        const key = routeBaseLocalKey(row);
        if (onlySelected && !selectedBaseKeys.has(key)) return;
        if (!representatives.has(key)) representatives.set(key, row);
    });

    return Array.from(representatives.values());
}

function geographicSectorOrder(rows) {
    if (rows.length <= 1) return rows.slice();
    const center = {
        lat: rows.reduce((sum, row) => sum + Number(row.lat), 0) / rows.length,
        lng: rows.reduce((sum, row) => sum + Number(row.lng), 0) / rows.length
    };
    const angular = rows.map(row => ({
        row,
        angle: Math.atan2(Number(row.lat) - center.lat, Number(row.lng) - center.lng)
    })).sort((a, b) => a.angle - b.angle);

    let start = 0;
    let largestGap = -1;
    angular.forEach((entry, index) => {
        const nextAngle = index === angular.length - 1 ? angular[0].angle + Math.PI * 2 : angular[index + 1].angle;
        const gap = nextAngle - entry.angle;
        if (gap > largestGap) {
            largestGap = gap;
            start = (index + 1) % angular.length;
        }
    });

    return angular.slice(start).concat(angular.slice(0, start)).map(entry => entry.row);
}

function clearPlanningForRedistribution(row) {
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
    row.distancia_desde_anterior_km = 0;
    row.distancia_total_ruta_km = 0;
    row.route_polyline = '';
    row.sin_ruta = true;
    row.observacion = 'Responsable asignado mediante distribución territorial; pendiente de optimizar.';
}

function distributeLocalsAmongWorkers() {
    if (!planRows.length) return alert('Primero debes cargar un archivo con locales.');

    const workers = selectedWorkersForDistribution();
    if (workers.length < 2) return alert('Selecciona al menos dos merchandisers.');

    const representatives = distributionScopeRepresentatives();
    if (representatives.length < workers.length) {
        return alert('La cantidad de locales debe ser igual o superior a la cantidad de trabajadores.');
    }

    const alreadyPlanned = representatives.some(row => !row.sin_ruta || row.fecha_ruta_sql || row.grupo_ruta);
    const confirmationText = alreadyPlanned
        ? 'Los locales incluidos ya tienen trabajo de rutas. La distribución conservará el archivo cargado, pero reiniciará sus rutas y fechas para volver a optimizarlos. ¿Continuar?'
        : `Se repartirán ${representatives.length} locales entre ${workers.length} trabajadores por sectores geográficos. ¿Continuar?`;
    if (!window.confirm(confirmationText)) return;

    const valid = representatives.filter(hasUsableCoordinates);
    const invalid = representatives.filter(row => !hasUsableCoordinates(row));
    const total = representatives.length;
    const baseSize = Math.floor(total / workers.length);
    const remainder = total % workers.length;
    const quotas = workers.map((_, index) => baseSize + (index < remainder ? 1 : 0));
    const invalidCounts = workers.map(() => 0);
    const assignments = new Map();

    invalid.forEach(row => {
        let workerIndex = 0;
        for (let index = 1; index < workers.length; index++) {
            const remaining = quotas[index] - invalidCounts[index];
            const bestRemaining = quotas[workerIndex] - invalidCounts[workerIndex];
            if (remaining > bestRemaining) workerIndex = index;
        }
        invalidCounts[workerIndex]++;
        assignments.set(routeBaseLocalKey(row), workers[workerIndex]);
    });

    const ordered = geographicSectorOrder(valid);
    let cursor = 0;
    workers.forEach((worker, workerIndex) => {
        const validQuota = Math.max(0, quotas[workerIndex] - invalidCounts[workerIndex]);
        ordered.slice(cursor, cursor + validQuota).forEach(row => {
            assignments.set(routeBaseLocalKey(row), worker);
        });
        cursor += validQuota;
    });

    // Cualquier punto remanente por redondeo se entrega al trabajador con menor carga.
    ordered.slice(cursor).forEach(row => {
        const counts = workers.map(worker => Array.from(assignments.values()).filter(value => value.id === worker.id).length);
        const workerIndex = counts.indexOf(Math.min(...counts));
        assignments.set(routeBaseLocalKey(row), workers[workerIndex]);
    });

    planRows.forEach(row => {
        const worker = assignments.get(routeBaseLocalKey(row));
        if (!worker) return;
        row.usuario_id = worker.id;
        row.usuario_login = worker.usuario;
        row.usuario_nombre = worker.nombre || worker.usuario;
        clearPlanningForRedistribution(row);
    });

    loadedRouteBaseFile = null;
    loadedRouteBaseRows = [];
    selectedRowIds.clear();
    rebuildFiltersFromRows();
    renderUsuarioSelect();
    renderFechaSelect();
    $('#filtroUsuario, #filtroFechaRuta, #filtroGrupoRuta').val('__ALL__');
    refreshAllViews(true);

    const finalCounts = workers.map(worker => ({
        worker,
        count: Array.from(assignments.values()).filter(value => value.id === worker.id).length
    }));
    $('#repartoMerchEstado').html(
        `<strong>Distribución lista:</strong> ${finalCounts.map(item => `${escapeHtml(item.worker.usuario || item.worker.nombre)}: ${item.count}`).join(' · ')}. `
        + 'Ahora puedes generar las rutas optimizadas.'
    );
}

function openGeoreferenceModal(idLocal) {
    const row = planRows.find(item => Number(item.id_local) === Number(idLocal));
    if (!row || !modalGeorreferencia) return;

    pendingGeoreference = null;
    $('#geoLocalId').val(row.id_local);
    $('#geoLocalCodigo').text(row.codigo_local || 'SIN CÓDIGO');
    $('#geoLocalNombre').text(row.nombre || 'SIN NOMBRE');
    $('#geoDireccion').val(row.direccion || row.direccion_original || '');
    $('#geoLat, #geoLng').val('');
    $('#geoResultado').html('Busca la dirección para obtener y revisar la nueva coordenada.');
    $('#btnGuardarGeorreferencia').prop('disabled', true);
    modalGeorreferencia.show();
}

function searchCorrectedGeoreference() {
    const address = $('#geoDireccion').val().trim();
    if (!address) return alert('Ingresa una dirección para buscar.');
    if (!window.google?.maps?.Geocoder) return alert('Google Maps todavía no está disponible.');

    const button = $('#btnBuscarGeorreferencia');
    const original = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Buscando...');
    $('#btnGuardarGeorreferencia').prop('disabled', true);
    $('#geoResultado').html('<span class="text-primary">Consultando Google Maps...</span>');

    const geocoder = new google.maps.Geocoder();
    const query = /chile/i.test(address) ? address : `${address}, Chile`;
    geocoder.geocode({ address: query }, (results, status) => {
        button.prop('disabled', false).html(original);
        if (status !== 'OK' || !results?.length) {
            pendingGeoreference = null;
            $('#geoResultado').html(`<span class="text-danger">Google no encontró una coincidencia (${escapeHtml(status)}).</span>`);
            return;
        }

        const result = results[0];
        const lat = result.geometry.location.lat();
        const lng = result.geometry.location.lng();
        pendingGeoreference = { address: result.formatted_address || address, lat, lng };
        $('#geoDireccion').val(pendingGeoreference.address);
        $('#geoLat').val(lat.toFixed(7));
        $('#geoLng').val(lng.toFixed(7));
        $('#geoResultado').html(`<span class="text-success"><strong>Coincidencia:</strong> ${escapeHtml(pendingGeoreference.address)}</span>`);
        $('#btnGuardarGeorreferencia').prop('disabled', false);
    });
}

async function saveCorrectedGeoreference() {
    const idLocal = Number($('#geoLocalId').val() || 0);
    if (!idLocal || !pendingGeoreference) return;

    const button = $('#btnGuardarGeorreferencia');
    const original = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

    try {
        const response = await fetch('mod_actualizar_georeferencia_local.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: $('#routeOptimizerCsrf').val(),
                id_local: idLocal,
                direccion: pendingGeoreference.address,
                lat: pendingGeoreference.lat,
                lng: pendingGeoreference.lng
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'No se pudo actualizar el local.');

        const affectedRoutes = new Set(
            planRows.filter(row => Number(row.id_local) === idLocal && !row.sin_ruta).map(routeDownloadIdentity)
        );
        planRows.forEach(row => {
            if (Number(row.id_local) === idLocal) {
                row.direccion = data.local.direccion;
                row.lat = Number(data.local.lat);
                row.lng = Number(data.local.lng);
                row.coordenadas_origen = 'CORRECCIÓN MANUAL EN MAPA';
                row.estado_address_validation = 'VALIDADO_MANUAL';
            }
            if (!row.sin_ruta && affectedRoutes.has(routeDownloadIdentity(row))) {
                row.route_polyline = '';
            }
        });

        modalGeorreferencia.hide();
        renderMap(false);
        renderDetalleTable();
        $('#optimizadorEstado').text(`Ubicación de ${data.local.direccion} actualizada sin recargar el archivo. Puedes reasignar el punto o reoptimizar su ruta.`);
    } catch (error) {
        console.error(error);
        $('#geoResultado').html(`<span class="text-danger">${escapeHtml(error.message || 'Error al guardar la ubicación.')}</span>`);
        button.prop('disabled', false).html(original);
    }
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

function addCalendarDaysSql(dateSql, daysToAdd) {
    const date = new Date(`${dateSql}T12:00:00`);
    if (Number.isNaN(date.getTime())) return dateSql;
    date.setDate(date.getDate() + Math.trunc(Number(daysToAdd) || 0));
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function nextBusinessDaySql(dateSql) {
    let result = dateSql;
    while (isWeekendSql(result)) {
        result = addCalendarDaysSql(result, 1);
    }
    return result;
}

function businessDatesSql(startDateSql, endDateSql) {
    const dates = [];
    let current = nextBusinessDaySql(startDateSql);
    while (current && current <= endDateSql) {
        dates.push(current);
        current = addBusinessDaysSql(current, 1);
    }
    return dates;
}

function monthBounds(monthValue) {
    if (!/^\d{4}-\d{2}$/.test(String(monthValue || ''))) return null;
    const [year, month] = monthValue.split('-').map(Number);
    const lastDay = new Date(year, month, 0).getDate();
    return {
        start: `${year}-${String(month).padStart(2, '0')}-01`,
        end: `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
    };
}

function planningRangeBounds(startMonthValue, endMonthValue = '') {
    const startBounds = monthBounds(startMonthValue);
    const endBounds = monthBounds(endMonthValue || startMonthValue);
    if (!startBounds || !endBounds || endBounds.end < startBounds.start) return null;

    const [startYear, startMonth] = String(startMonthValue).split('-').map(Number);
    const [endYear, endMonth] = String(endMonthValue || startMonthValue).split('-').map(Number);
    return {
        start: startBounds.start,
        end: endBounds.end,
        startMonth: String(startMonthValue),
        endMonth: String(endMonthValue || startMonthValue),
        totalMonths: ((endYear - startYear) * 12) + endMonth - startMonth + 1
    };
}

function planningWeekNumber(dateSql, planStartSql) {
    const date = new Date(`${dateSql}T12:00:00`);
    const planStart = new Date(`${planStartSql}T12:00:00`);
    if (Number.isNaN(date.getTime()) || Number.isNaN(planStart.getTime())) {
        return dateMetadata(dateSql).week;
    }

    // La primera semana del plan incluye todos los días del mes aunque su
    // lunes pertenezca al mes anterior (por ejemplo, 31-08 al 04-09).
    const dateUtc = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    const startUtc = Date.UTC(planStart.getFullYear(), planStart.getMonth(), planStart.getDate());
    const elapsedDays = Math.floor((dateUtc - startUtc) / 86400000);
    const daysFromMonday = (planStart.getDay() + 6) % 7;
    return Math.max(1, Math.floor((elapsedDays + daysFromMonday) / 7) + 1);
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

function getOptimizationScopeRows() {
    const selectedKeys = selectedRouteLevelKeys();
    const useSelected = $('#optSoloSeleccionados').is(':checked') && selectedKeys.size > 0;
    return useSelected
        ? getRouteLevelRows(planRows.filter(row => selectedKeys.has(routeLevelLocalKey(row))))
        : getRouteLevelRows();
}

function getOptimizationRows() {
    return getOptimizationScopeRows().filter(hasUsableCoordinates);
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
        componentKey: route.component_key || '',
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

function planningUserKey(row) {
    return String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
}

function minimumDistanceBetweenRouteRows(rowsA, rowsB) {
    let minimum = Infinity;
    rowsA.forEach(rowA => {
        rowsB.forEach(rowB => {
            minimum = Math.min(minimum, distanceBetweenRows(rowA, rowB));
        });
    });
    return minimum;
}

function rebalanceRoutesToDailyMinimum(routePlans, targetSize, isolationKm = Infinity) {
    const routesByUser = new Map();
    routePlans.forEach(route => {
        const userKey = String(route.userKey || planningUserKey(route.rows[0] || {}));
        route.userKey = userKey;
        if (!routesByUser.has(userKey)) routesByUser.set(userKey, []);
        routesByUser.get(userKey).push(route);
    });

    const consolidated = [];
    routesByUser.forEach(userRoutes => {
        userRoutes.sort((a, b) => Number(a.userDayIndex || 0) - Number(b.userDayIndex || 0));
        let guard = 0;

        while (userRoutes.length > 1 && guard++ < 10000) {
            const smallRoute = userRoutes
                .filter(route => route.rows.length < targetSize)
                .sort((a, b) => a.rows.length - b.rows.length)[0];
            if (!smallRoute) break;

            // Primero intenta completar la ruta pequeña tomando los locales más
            // cercanos de rutas del mismo componente geográfico. Nunca se usan
            // locales de otra zona sólo para completar la meta.
            while (smallRoute.rows.length < targetSize) {
                let bestMove = null;
                const smallComponentKey = String(smallRoute.componentKey || '');
                userRoutes.forEach(donorRoute => {
                    const donorComponentKey = String(donorRoute.componentKey || '');
                    if (donorRoute === smallRoute
                        || donorRoute.rows.length <= targetSize
                        || (smallComponentKey && donorComponentKey && donorComponentKey !== smallComponentKey)
                        || minimumDistanceBetweenRouteRows(smallRoute.rows, donorRoute.rows) > isolationKm) return;
                    donorRoute.rows.forEach((row, rowIndex) => {
                        const distance = Math.min(...smallRoute.rows.map(candidate => distanceBetweenRows(row, candidate)));
                        if (!bestMove || distance < bestMove.distance) {
                            bestMove = { donorRoute, rowIndex, distance };
                        }
                    });
                });
                if (!bestMove) break;

                smallRoute.rows.push(bestMove.donorRoute.rows.splice(bestMove.rowIndex, 1)[0]);
                smallRoute.rebalanced = true;
                bestMove.donorRoute.rebalanced = true;
            }

            if (smallRoute.rows.length >= targetSize) continue;

            // Si no hay locales disponibles para redistribuir, absorbe el grupo
            // completo en la ruta geográficamente más cercana, sin máximo.
            const destination = userRoutes
                .filter(route => route !== smallRoute)
                .map(route => ({
                    route,
                    distance: minimumDistanceBetweenRouteRows(smallRoute.rows, route.rows)
                }))
                .sort((a, b) => a.distance - b.distance || a.route.rows.length - b.route.rows.length)[0]?.route;
            if (!destination) break;

            destination.rows.push(...smallRoute.rows);
            destination.rebalanced = true;
            destination.absorbedRoutes = Number(destination.absorbedRoutes || 0) + 1;
            userRoutes.splice(userRoutes.indexOf(smallRoute), 1);
        }

        userRoutes.forEach((route, userDayIndex) => {
            route.userDayIndex = userDayIndex;
            if (route.rebalanced) {
                route.rows = sortRowsByNearestNeighbor(route.rows);
                route.encodedPolyline = '';
                route.distanceMeters = 0;
            }
            consolidated.push(route);
        });
    });

    return consolidated;
}

function addUnreturnedRowsToNearestRoute(rows, routePlans, skippedById = new Map()) {
    const assignedRows = new Set(routePlans.flatMap(route => route.rows));
    let incorporated = 0;

    rows.forEach(row => {
        const rowId = String(row.row_id);
        if (assignedRows.has(row)) return;

        const userKey = planningUserKey(row);
        const candidateRoutes = routePlans.filter(route =>
            String(route.userKey || planningUserKey(route.rows[0] || {})) === userKey
        );
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

        if (!selectedRoute) {
            selectedRoute = {
                userKey,
                componentKey: `${userKey}|OMITIDOS`,
                userDayIndex: 0,
                encodedPolyline: '',
                distanceMeters: 0,
                totalDuration: '',
                rows: []
            };
            routePlans.push(selectedRoute);
        }

        selectedRoute.rows.splice(insertAt, 0, row);
        selectedRoute.encodedPolyline = '';
        selectedRoute.rebalanced = true;
        row.observacion = skippedById.has(rowId)
            ? 'Google lo omitió y fue incorporado a la ruta más cercana.'
            : 'Incorporado por cercanía a la ruta disponible más próxima.';
        assignedRows.add(row);
        incorporated++;
    });

    return incorporated;
}

async function optimizeVisibleRoutes() {
    if (!planRows.length) {
        alert('Primero debes cargar un archivo.');
        return;
    }

    const filtersBeforeUpdate = getCurrentFilters();
    const filteredRouteAnchor = getFilteredRows()[0] || null;
    const selectedOnlyScope = $('#optSoloSeleccionados').is(':checked') && selectedRouteLevelKeys().size > 0;

    const dailyLoad = Number($('#optMetaDiaria').val() || 0);
    if (!Number.isInteger(dailyLoad) || dailyLoad < 2 || dailyLoad > 30) {
        alert('La meta mínima debe estar entre 2 y 30 locales para este optimizador.');
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

    const optimizationScopeRows = getOptimizationScopeRows();
    const candidates = optimizationScopeRows.filter(hasUsableCoordinates);
    const withoutCoordinates = optimizationScopeRows.filter(row => !hasUsableCoordinates(row));
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
        let routePlans = googleResult.routes;
        const skippedById = new Map(googleResult.skipped.map(item => [String(item.row_id), item]));
        let incorporatedCount = addUnreturnedRowsToNearestRoute(candidates, routePlans, skippedById);
        routePlans = rebalanceRoutesToDailyMinimum(routePlans, dailyLoad, isolationKm);
        // Verificación de seguridad: después del balanceo, ningún objeto candidato
        // puede quedar fuera aunque existan identificadores de fila repetidos.
        incorporatedCount += addUnreturnedRowsToNearestRoute(candidates, routePlans, skippedById);
        assignRouteNamesByUser(routePlans, prefix);
        const assignedRows = new Set(routePlans.flatMap(route => route.rows));

        candidates.forEach(row => {
            const skipped = skippedById.get(String(row.row_id));
            if (assignedRows.has(row)) return;
            leaveRowWithoutRoute(
                row,
                skipped?.reason || 'Google no encontró una ruta conveniente para este local.'
            );
        });

        for (let routeIndex = 0; routeIndex < routePlans.length; routeIndex++) {
            const routePlan = routePlans[routeIndex];
            $('#optimizadorEstado').text(`Aplicando ruta vial ${routeIndex + 1} de ${routePlans.length} (${routePlan.rows.length} locales)...`);
            const optimizedRows = routePlan.rebalanced
                ? await optimizeDailyRowsWithGoogle(routePlan.rows)
                : routePlan.rows;
            routePlan.rows = optimizedRows;
            const dateSql = addBusinessDaysSql(startDate, routePlan.userDayIndex);
            const metadata = dateMetadata(dateSql);
            const groupName = routePlan.routeName;

            optimizedRows.forEach((row, orderIndex) => {
                row.fecha_ruta_sql = dateSql;
                row.fecha_ruta = metadata.display;
                row.dia_semana = metadata.dayName;
                row.dia_semana_num = metadata.dayNumber;
                row.semana_plan = metadata.week;
                row.dia_plan = routePlan.userDayIndex + 1;
                row.grupo_ruta = groupName;
                row.ruta_global = routePlan.routeIdentity;
                row.orden_visita = orderIndex + 1;
                row.tamano_ruta = optimizedRows.length;
                row.distancia_total_ruta_km = routePlan.distanceMeters / 1000;
                row.route_polyline = routePlan.encodedPolyline;
                row.observacion = routePlan.rebalanced
                    ? 'Ruta optimizada y consolidada por cercanía para respetar la meta mínima.'
                    : 'Optimizado por Google Route Optimization API.';
                row.sin_ruta = false;
            });
        }

        selectedRowIds.clear();
        const updatedAnchorIdentity = filteredRouteAnchor && !filteredRouteAnchor.sin_ruta
            ? routeDownloadIdentity(filteredRouteAnchor)
            : '';
        restoreRouteFilters(filtersBeforeUpdate, updatedAnchorIdentity);
        const routeSizes = routePlans.map(route => route.rows.length);
        const sizeRange = routeSizes.length ? `${Math.min(...routeSizes)}–${Math.max(...routeSizes)}` : '0';
        const belowTargetCount = routePlans.filter(route => route.rows.length < dailyLoad).length;
        const unavoidableNote = belowTargetCount > 0
            ? ` ${belowTargetCount} ruta(s) quedaron bajo la meta porque el responsable no tiene suficientes locales en total.`
            : '';
        const withoutCoordinatesNote = withoutCoordinates.length > 0
            ? ` ${withoutCoordinates.length} local(es) no pudieron procesarse porque no poseen coordenadas válidas.`
            : '';
        const selectedOnlyNote = selectedOnlyScope
            ? ' Se procesaron únicamente los locales seleccionados.'
            : '';
        $('#optimizadorEstado').text(
            `Listo con Google Route Optimization: ${routePlans.length} ruta(s) de ${sizeRange} locales. `
            + `${incorporatedCount} local(es) omitido(s) o aislado(s) fueron incorporados por cercanía.`
            + unavoidableNote
            + withoutCoordinatesNote
            + selectedOnlyNote
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'No se pudo optimizar la ruta.');
        $('#optimizadorEstado').text('No se pudo completar la optimizacion.');
    } finally {
        button.prop('disabled', false).html(originalHtml);
    }
}

function monthlyLocalKey(row) {
    const localKey = String(row.id_local || row.codigo_local || row.row_id || '');
    const userKey = String(row.usuario_id || row.usuario_login || row.usuario_nombre || '');
    return `${localKey}::${userKey}`;
}

function getUniqueRowsForMonthlyPlanning() {
    const uniqueRows = new Map();
    planRows.forEach(row => {
        const key = monthlyLocalKey(row);
        if (!uniqueRows.has(key)) {
            uniqueRows.set(key, { ...row, row_id: `mensual_base_${uniqueRows.size + 1}` });
        }
    });
    return [...uniqueRows.values()];
}

function addFallbackMonthlyRoutes(rows, routePlans, targetSize) {
    const assignedRows = new Set(routePlans.flatMap(route => route.rows));
    const missingByUser = new Map();

    rows.forEach(row => {
        if (assignedRows.has(row)) return;
        const userKey = String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
        if (!missingByUser.has(userKey)) missingByUser.set(userKey, []);
        missingByUser.get(userKey).push(row);
    });

    missingByUser.forEach((missingRows, userKey) => {
        const sameUserRoutes = routePlans.filter(route => String(route.userKey || '') === userKey);
        let nextDayIndex = sameUserRoutes.length
            ? Math.max(...sameUserRoutes.map(route => Number(route.userDayIndex || 0))) + 1
            : 0;
        const orderedRows = sortRowsByNearestNeighbor(missingRows);
        chunkRows(orderedRows, targetSize).forEach(routeRows => {
            routePlans.push({
                userKey,
                componentKey: `${userKey}|RESPALDO`,
                userDayIndex: nextDayIndex++,
                encodedPolyline: '',
                distanceMeters: 0,
                totalDuration: '',
                rows: routeRows,
                fallback: true
            });
        });
    });
}

function rowDueDate(row, monthStart) {
    const lastDate = String(row.ultima_fecha_propuesta_sql || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(lastDate)) return monthStart;
    const dueDate = addCalendarDaysSql(lastDate, 14);
    return dueDate > monthStart ? dueDate : monthStart;
}

function monthlyRouteUserKey(routePlan) {
    if (String(routePlan.userKey || '').trim()) return String(routePlan.userKey);
    const row = routePlan.rows[0] || {};
    return String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
}

function assignRouteNamesByUser(routePlans, prefix) {
    const routesByUser = new Map();
    routePlans.forEach((routePlan, originalIndex) => {
        const userKey = monthlyRouteUserKey(routePlan);
        routePlan.userKey = userKey;
        if (!routesByUser.has(userKey)) routesByUser.set(userKey, []);
        routesByUser.get(userKey).push({ routePlan, originalIndex });
    });

    routesByUser.forEach(userRoutes => {
        userRoutes
            .sort((left, right) => Number(left.routePlan.userDayIndex || 0) - Number(right.routePlan.userDayIndex || 0)
                || left.originalIndex - right.originalIndex)
            .forEach(({ routePlan }, index) => {
                const routeNumber = index + 1;
                routePlan.userDayIndex = index;
                routePlan.routeNumberByUser = routeNumber;
                routePlan.routeName = `${prefix} ${String(routeNumber).padStart(2, '0')}`;
                routePlan.routeIdentity = `${routePlan.userKey} | ${routePlan.routeName}`;
            });
    });
    return routePlans;
}

function firstFreeBusinessDate(fromDate, occupiedDates, limitDate = '') {
    let candidate = nextBusinessDaySql(fromDate);
    while (occupiedDates.has(candidate)) {
        candidate = addBusinessDaysSql(candidate, 1);
    }
    return limitDate && candidate > limitDate ? '' : candidate;
}

function buildMonthlyRouteAssignments(routePlans, bounds) {
    const occupiedByUser = new Map();
    const primaryAssignments = [];
    let coverageEnd = bounds.start;

    // La frecuencia previa define la prioridad de cada ruta, pero no debe dejar
    // vacíos los primeros días hábiles del mes. Si todavía no hay una ruta
    // vencida, se adelanta la más próxima y se toma la fecha asignada como nueva
    // referencia para intentar la siguiente recurrencia a los 14 días.
    const pendingByUser = new Map();
    routePlans.forEach((routePlan, routeIndex) => {
        const userKey = monthlyRouteUserKey(routePlan);
        if (!occupiedByUser.has(userKey)) occupiedByUser.set(userKey, new Set());
        const dueDate = routePlan.rows.reduce((latest, row) => {
            const rowDate = rowDueDate(row, bounds.start);
            return rowDate > latest ? rowDate : latest;
        }, bounds.start);

        if (!pendingByUser.has(userKey)) pendingByUser.set(userKey, []);
        pendingByUser.get(userKey).push({ routePlan, routeIndex, userKey, dueDate });
    });

    pendingByUser.forEach((userRoutes, userKey) => {
        const occupiedDates = occupiedByUser.get(userKey);
        const pending = [...userRoutes];
        let dateSql = firstFreeBusinessDate(bounds.start, occupiedDates);

        while (pending.length) {
            pending.sort((a, b) => a.dueDate.localeCompare(b.dueDate) || a.routeIndex - b.routeIndex);
            let selectedIndex = pending.findIndex(route => route.dueDate <= dateSql);
            if (selectedIndex < 0) selectedIndex = 0;

            const selected = pending.splice(selectedIndex, 1)[0];
            occupiedDates.add(dateSql);
            if (dateSql > coverageEnd) coverageEnd = dateSql;
            primaryAssignments.push({
                ...selected,
                dateSql,
                occurrence: 1,
                frequencyAdjusted: dateSql < selected.dueDate
            });

            dateSql = firstFreeBusinessDate(addBusinessDaysSql(dateSql, 1), occupiedDates);
        }
    });

    // Segunda prioridad: completar todos los días hábiles del período. Se elige
    // primero la ruta cuya repetición de 14 días ya venció. Si ninguna está
    // vencida, se adelanta la más próxima para no dejar una jornada vacía.
    // El horizonte llega al fin del período o a la fecha necesaria para completar
    // la cobertura inicial, lo que sea posterior.
    const planningEnd = coverageEnd > bounds.end ? coverageEnd : bounds.end;
    const recurrenceAssignments = [];

    pendingByUser.forEach((userRoutes, userKey) => {
        const occupiedDates = occupiedByUser.get(userKey);
        const recurrenceState = primaryAssignments
            .filter(assignment => assignment.userKey === userKey)
            .map(assignment => ({
                routePlan: assignment.routePlan,
                routeIndex: assignment.routeIndex,
                userKey,
                dueDate: addCalendarDaysSql(assignment.dateSql, 14),
                occurrence: 2
            }));
        let dateSql = nextBusinessDaySql(bounds.start);

        while (dateSql <= planningEnd) {
            if (occupiedDates.has(dateSql)) {
                dateSql = addBusinessDaysSql(dateSql, 1);
                continue;
            }

            recurrenceState.sort((a, b) =>
                a.dueDate.localeCompare(b.dueDate) || a.routeIndex - b.routeIndex
            );
            const selected = recurrenceState[0];
            if (!selected) break;

            const frequencyAdjusted = dateSql < selected.dueDate;
            recurrenceAssignments.push({
                ...selected,
                dateSql,
                frequencyAdjusted
            });
            occupiedDates.add(dateSql);
            selected.dueDate = addCalendarDaysSql(dateSql, 14);
            selected.occurrence++;
            dateSql = addBusinessDaysSql(dateSql, 1);
        }
    });

    return { primaryAssignments, recurrenceAssignments, coverageEnd, planningEnd };
}

function buildRoutePlansFromBaseRows(rows) {
    const routes = new Map();

    rows.forEach(row => {
        if (row.sin_ruta || !row.grupo_ruta || !hasUsableCoordinates(row)) return;
        const userKey = String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
        const routeName = String(row.grupo_ruta).trim();
        const routeKey = `${userKey}::${routeName}`;
        if (!routes.has(routeKey)) {
            routes.set(routeKey, {
                userKey,
                routeName,
                componentKey: routeKey,
                userDayIndex: 0,
                encodedPolyline: '',
                distanceMeters: 0,
                totalDuration: '',
                rows: []
            });
        }

        const route = routes.get(routeKey);
        route.rows.push({
            ...row,
            fecha_ruta: '',
            fecha_ruta_sql: '',
            dia_plan: '',
            semana_plan: '',
            dia_semana_num: '',
            dia_semana: '',
            sin_ruta: false
        });
        route.distanceMeters = Math.max(
            route.distanceMeters,
            Number(row.distancia_total_ruta_km || 0) * 1000
        );
    });

    const routePlans = [...routes.values()].sort((a, b) =>
        a.userKey.localeCompare(b.userKey, 'es', { numeric: true })
        || a.routeName.localeCompare(b.routeName, 'es', { numeric: true })
    );
    const userIndexes = new Map();
    routePlans.forEach(route => {
        route.rows.sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
        route.rows.forEach((row, index) => {
            row.orden_visita = index + 1;
            row.tamano_ruta = route.rows.length;
        });
        const nextIndex = userIndexes.get(route.userKey) || 0;
        route.userDayIndex = nextIndex;
        userIndexes.set(route.userKey, nextIndex + 1);
    });

    return routePlans;
}

function planMonthlyFromLoadedRouteBase(startMonthValue, endMonthValue = '') {
    const bounds = planningRangeBounds(startMonthValue, endMonthValue);
    if (!bounds) throw new Error('El mes final debe ser igual o posterior al mes inicial.');

    const routePlans = buildRoutePlansFromBaseRows(loadedRouteBaseRows);
    if (!routePlans.length) {
        throw new Error('El archivo no contiene rutas base con coordenadas válidas.');
    }

    const { primaryAssignments, recurrenceAssignments, coverageEnd } =
        buildMonthlyRouteAssignments(routePlans, bounds);
    const monthlyRows = [];

    [...primaryAssignments, ...recurrenceAssignments]
        .sort((a, b) => a.dateSql.localeCompare(b.dateSql) || a.routeIndex - b.routeIndex)
        .forEach(assignment => {
            const { routePlan, routeIndex, dateSql, occurrence, frequencyAdjusted } = assignment;
            const metadata = dateMetadata(dateSql);
            routePlan.rows.forEach((row, orderIndex) => {
                monthlyRows.push({
                    ...row,
                    row_id: `base_mensual_${routeIndex}_${dateSql}_${row.row_id}`,
                    fecha_ruta_sql: dateSql,
                    fecha_ruta: metadata.display,
                    dia_semana: metadata.dayName,
                    dia_semana_num: metadata.dayNumber,
                    semana_plan: planningWeekNumber(dateSql, bounds.start),
                    dia_plan: occurrence,
                    grupo_ruta: routePlan.routeName,
                    orden_visita: orderIndex + 1,
                    tamano_ruta: routePlan.rows.length,
                    distancia_total_ruta_km: Number(routePlan.distanceMeters || 0) / 1000,
                    route_polyline: '',
                    observacion: occurrence > 1
                        ? frequencyAdjusted
                            ? 'Repetición adelantada para cubrir todos los días hábiles del período.'
                            : 'Repetición quincenal de una ruta base reutilizada.'
                        : frequencyAdjusted
                            ? 'Ruta base adelantada para cubrir los días hábiles iniciales del período.'
                            : 'Primera visita del período de una ruta base reutilizada.',
                    sin_ruta: false
                });
            });
        });

    const visitsPerLocal = new Map();
    monthlyRows.forEach(row => {
        const key = monthlyLocalKey(row);
        visitsPerLocal.set(key, (visitsPerLocal.get(key) || 0) + 1);
    });
    monthlyRows.forEach(row => {
        row.cantidad_objetivo_dia = Number(row.cantidad_objetivo_dia || 0) || Number(row.tamano_ruta || 0);
        row.dias_planificados = visitsPerLocal.get(monthlyLocalKey(row)) || 0;
    });

    planRows = monthlyRows;
    selectedRowIds.clear();
    rebuildFiltersFromRows();
    renderUsuarioSelect();
    renderFechaSelect();
    refreshAllViews(true);

    const allAssignments = [...primaryAssignments, ...recurrenceAssignments];
    const firstDate = allAssignments.reduce((earliest, assignment) =>
        !earliest || assignment.dateSql < earliest ? assignment.dateSql : earliest, '');
    const recurrenceVisits = recurrenceAssignments.reduce(
        (total, assignment) => total + assignment.routePlan.rows.length,
        0
    );
    const adjustedFrequencyAssignments = allAssignments.filter(
        assignment => assignment.frequencyAdjusted
    ).length;
    const expectedBusinessDays = businessDatesSql(bounds.start, bounds.end).length;
    const scheduledDaysByUser = new Map();
    allAssignments
        .filter(assignment => assignment.dateSql >= bounds.start && assignment.dateSql <= bounds.end)
        .forEach(assignment => {
            if (!scheduledDaysByUser.has(assignment.userKey)) {
                scheduledDaysByUser.set(assignment.userKey, new Set());
            }
            scheduledDaysByUser.get(assignment.userKey).add(assignment.dateSql);
        });
    const minimumScheduledDays = scheduledDaysByUser.size
        ? Math.min(...[...scheduledDaysByUser.values()].map(dates => dates.size))
        : 0;
    const overflowVisits = monthlyRows.filter(row => row.fecha_ruta_sql > bounds.end).length;

    return {
        routes: routePlans.length,
        baseAssociations: routePlans.reduce((total, route) => total + route.rows.length, 0),
        scheduledVisits: monthlyRows.length,
        recurrenceVisits,
        adjustedFrequencyAssignments,
        expectedBusinessDays,
        minimumScheduledDays,
        firstDate,
        coverageEnd,
        overflowVisits,
        totalMonths: bounds.totalMonths,
        rangeStart: bounds.start,
        rangeEnd: bounds.end
    };
}

async function planMonthlyRoutes() {
    if (!planRows.length) {
        alert('Primero debes cargar un archivo con los locales y usuarios asignados.');
        return;
    }

    const filtersBeforeUpdate = getCurrentFilters();

    const bounds = planningRangeBounds(
        $('#optMesPlanificacion').val(),
        $('#optMesFinPlanificacion').val()
    );
    if (!bounds) {
        alert('Selecciona un período válido: el mes final debe ser igual o posterior al mes inicial.');
        return;
    }

    const dailyLoad = Number($('#optMetaDiaria').val() || 0);
    if (!Number.isInteger(dailyLoad) || dailyLoad < 2 || dailyLoad > 30) {
        alert('La meta mínima debe estar entre 2 y 30 locales.');
        return;
    }

    const isolationKm = Number($('#optAislamientoKm').val() || 25);
    if (!Number.isFinite(isolationKm) || isolationKm < 2 || isolationKm > 150) {
        alert('La separacion para considerar un local aislado debe estar entre 2 y 150 km.');
        return;
    }

    const baseRows = getUniqueRowsForMonthlyPlanning();
    const candidates = baseRows.filter(hasUsableCoordinates);
    const withoutCoordinates = baseRows.filter(row => !hasUsableCoordinates(row));
    if (!candidates.length) {
        alert('Ningun local cargado tiene coordenadas validas para construir las rutas.');
        return;
    }

    const button = $('#btnPlanificarMes');
    const originalHtml = button.html();
    const prefix = ($('#optPrefijoGrupo').val().trim() || 'RUTA OPT').toUpperCase();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Planificando período...');
    $('#btnOptimizarRutas').prop('disabled', true);
    $('#optimizadorEstado').text(`Construyendo rutas para ${candidates.length} locales durante ${bounds.totalMonths} mes(es)...`);

    try {
        const googleResult = await requestGoogleRouteOptimization(candidates, dailyLoad, isolationKm);
        let routePlans = googleResult.routes;
        // En el modo mensual todos los locales con coordenadas deben quedar
        // asignados. Los omitidos por Google se agrupan en rutas de respaldo.
        addFallbackMonthlyRoutes(candidates, routePlans, dailyLoad);
        // La meta mensual también es un mínimo: redistribuye o absorbe cualquier
        // grupo pequeño en la ruta más cercana del mismo responsable.
        routePlans = rebalanceRoutesToDailyMinimum(routePlans, dailyLoad, isolationKm);
        routePlans.sort((a, b) => {
            const userComparison = monthlyRouteUserKey(a).localeCompare(monthlyRouteUserKey(b));
            if (userComparison !== 0) return userComparison;
            return Number(a.userDayIndex || 0) - Number(b.userDayIndex || 0)
                || Number(a.splitIndex || 0) - Number(b.splitIndex || 0);
        });
        assignRouteNamesByUser(routePlans, prefix);

        const monthlyRows = [];
        let scheduledVisits = 0;
        const { primaryAssignments, recurrenceAssignments, coverageEnd } =
            buildMonthlyRouteAssignments(routePlans, bounds);

        [...primaryAssignments, ...recurrenceAssignments]
            .sort((a, b) => a.dateSql.localeCompare(b.dateSql) || a.routeIndex - b.routeIndex)
            .forEach(assignment => {
                const { routePlan, routeIndex, dateSql, occurrence, frequencyAdjusted } = assignment;
                const groupName = routePlan.routeName;
                const metadata = dateMetadata(dateSql);
                routePlan.rows.forEach((row, orderIndex) => {
                    monthlyRows.push({
                        ...row,
                        row_id: `mensual_${routeIndex}_${dateSql}_${row.row_id}`,
                        fecha_ruta_sql: dateSql,
                        fecha_ruta: metadata.display,
                        dia_semana: metadata.dayName,
                        dia_semana_num: metadata.dayNumber,
                        semana_plan: planningWeekNumber(dateSql, bounds.start),
                        dia_plan: occurrence,
                        grupo_ruta: groupName,
                        ruta_global: routePlan.routeIdentity,
                        orden_visita: orderIndex + 1,
                        tamano_ruta: routePlan.rows.length,
                        distancia_total_ruta_km: Number(routePlan.distanceMeters || 0) / 1000,
                        route_polyline: routePlan.encodedPolyline || '',
                        observacion: routePlan.fallback
                            ? 'Ruta de respaldo para asegurar la cobertura del local durante el período.'
                            : occurrence > 1
                                ? frequencyAdjusted
                                    ? 'Repetición adelantada para cubrir todos los días hábiles del período.'
                                    : 'Repetición quincenal de ruta optimizada.'
                            : frequencyAdjusted
                                ? 'Primera visita adelantada para cubrir los días hábiles iniciales; la siguiente repetición conserva 14 días cuando sea posible.'
                                : 'Primera visita de ruta optimizada para el período.',
                        sin_ruta: false
                    });
                    scheduledVisits++;
                });
            });

        withoutCoordinates.forEach((row, index) => {
            const unplanned = { ...row, row_id: `mensual_sin_coords_${index}_${row.row_id}` };
            leaveRowWithoutRoute(unplanned, 'Sin coordenadas válidas: no fue posible construir su ruta para el período.');
            monthlyRows.push(unplanned);
        });

        const visitsPerLocal = new Map();
        monthlyRows.filter(row => !row.sin_ruta).forEach(row => {
            const key = monthlyLocalKey(row);
            visitsPerLocal.set(key, (visitsPerLocal.get(key) || 0) + 1);
        });
        monthlyRows.forEach(row => {
            row.cantidad_objetivo_dia = dailyLoad;
            row.dias_planificados = visitsPerLocal.get(monthlyLocalKey(row)) || 0;
        });

        planRows = monthlyRows;
        selectedRowIds.clear();
        restoreRouteFilters(filtersBeforeUpdate);

        const uniqueScheduled = new Set(planRows.filter(row => !row.sin_ruta).map(monthlyLocalKey)).size;
        const fallbackCount = routePlans.filter(route => route.fallback).length;
        const allMonthlyAssignments = [...primaryAssignments, ...recurrenceAssignments];
        const adjustedFrequencyCount = allMonthlyAssignments
            .filter(assignment => assignment.frequencyAdjusted).length;
        const expectedBusinessDays = businessDatesSql(bounds.start, bounds.end).length;
        const scheduledDaysByUser = new Map();
        allMonthlyAssignments
            .filter(assignment => assignment.dateSql >= bounds.start && assignment.dateSql <= bounds.end)
            .forEach(assignment => {
                if (!scheduledDaysByUser.has(assignment.userKey)) {
                    scheduledDaysByUser.set(assignment.userKey, new Set());
                }
                scheduledDaysByUser.get(assignment.userKey).add(assignment.dateSql);
            });
        const minimumScheduledDays = scheduledDaysByUser.size
            ? Math.min(...[...scheduledDaysByUser.values()].map(dates => dates.size))
            : 0;
        const overflowVisits = planRows.filter(row => !row.sin_ruta && row.fecha_ruta_sql > bounds.end).length;
        const recurrenceVisits = recurrenceAssignments.reduce((total, assignment) => total + assignment.routePlan.rows.length, 0);
        const overflowNote = overflowVisits > 0
            ? ` La cobertura continúa hasta ${dateMetadata(coverageEnd).display}: ${overflowVisits} visita(s) quedaron fuera del período seleccionado.`
            : '';
        $('#optimizadorEstado').text(
            `Período planificado (${bounds.totalMonths} mes(es)): ${uniqueScheduled} local(es), ${scheduledVisits} visita(s) y ${routePlans.length} ruta(s) base. `
            + `Días hábiles cubiertos por responsable: ${minimumScheduledDays}/${expectedBusinessDays}. `
            + `Meta mínima: ${dailyLoad} locales por usuario/día. Visitas por repetición: ${recurrenceVisits}. `
            + `Asignaciones adelantadas para cubrir todos los días hábiles: ${adjustedFrequencyCount}. `
            + `Rutas de respaldo: ${fallbackCount}. Sin coordenadas: ${withoutCoordinates.length}.`
            + overflowNote
        );
    } catch (error) {
        console.error(error);
        alert(error.message || 'No se pudo construir la planificación del período.');
        $('#optimizadorEstado').text('No se pudo completar la planificación del período.');
    } finally {
        button.prop('disabled', false).html(originalHtml);
        $('#btnOptimizarRutas').prop('disabled', false);
    }
}

function routeDownloadIdentity(row) {
    const userKey = String(row.usuario_id || row.usuario_login || row.usuario_nombre || 'SIN_USUARIO');
    const groupKey = row.sin_ruta ? '__UNPLANNED__' : String(row.grupo_ruta || '');
    return `${userKey}::${groupKey}`;
}

function routeDownloadLocalKey(row) {
    return `${routeDownloadIdentity(row)}::${routeBaseLocalKey(row)}`;
}

function routeRowsForBaseDownload() {
    const selectedRows = planRows.filter(row => selectedRowIds.has(row.row_id));
    if (selectedRowIds.size) {
        if (!selectedRows.length) return [];
        const selectedLocalKeys = new Set(selectedRows.map(routeDownloadLocalKey));
        return planRows.filter(row => selectedLocalKeys.has(routeDownloadLocalKey(row)));
    }

    const filters = getCurrentFilters();
    return planRows.filter(row => {
        const matchesUser = filters.usuario === '__ALL__'
            || String(row.usuario_id) === String(filters.usuario)
            || String(row.usuario_login) === String(filters.usuario);
        if (!matchesUser) return false;

        if (filters.grupo === '__ALL__') return true;
        if (filters.grupo === '__UNPLANNED__') return Boolean(row.sin_ruta);
        return !row.sin_ruta && routeDownloadIdentity(row) === String(filters.grupo);
    });
}

function buildRouteBaseDownloadRows(sourceRows = routeRowsForBaseDownload()) {
    const routeRows = [...sourceRows]
        .sort((a, b) => String(a.fecha_ruta_sql || '').localeCompare(String(b.fecha_ruta_sql || ''))
            || Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
    const visitsByLocal = new Map();
    const datesByRoute = new Map();
    const baseRows = new Map();
    routeRows.forEach(row => {
        const localKey = routeDownloadLocalKey(row);
        const routeKey = routeDownloadIdentity(row);
        visitsByLocal.set(localKey, (visitsByLocal.get(localKey) || 0) + 1);
        if (!datesByRoute.has(routeKey)) datesByRoute.set(routeKey, new Set());
        if (!row.sin_ruta && row.fecha_ruta_sql) datesByRoute.get(routeKey).add(String(row.fecha_ruta_sql));
        if (!baseRows.has(localKey)) baseRows.set(localKey, { ...row });
    });

    const rowsByRoute = new Map();
    [...baseRows.values()].forEach(row => {
        const routeKey = routeDownloadIdentity(row);
        if (!rowsByRoute.has(routeKey)) rowsByRoute.set(routeKey, []);
        rowsByRoute.get(routeKey).push(row);
    });

    const downloadRows = [];
    [...rowsByRoute.entries()]
        .sort(([left], [right]) => left.localeCompare(right, 'es', { numeric: true, sensitivity: 'base' }))
        .forEach(([, rows]) => {
            rows.sort((a, b) => Number(a.orden_visita || 0) - Number(b.orden_visita || 0));
            rows.forEach((row, index) => {
                const routeDates = [...(datesByRoute.get(routeDownloadIdentity(row)) || [])].sort();
                const unplanned = Boolean(row.sin_ruta);
                downloadRows.push({
                    ...row,
                    row_id: `descarga_ruta_${downloadRows.length}_${row.row_id}`,
                    grupo_ruta: unplanned ? '' : row.grupo_ruta,
                    orden_visita: unplanned ? 0 : (Number(row.orden_visita) > 0 ? Number(row.orden_visita) : index + 1),
                    tamano_ruta: unplanned ? 0 : rows.length,
                    dias_planificados: unplanned ? 0 : (visitsByLocal.get(routeDownloadLocalKey(row)) || 1),
                    primera_fecha_original: unplanned ? '' : (routeDates[0] || row.primera_fecha_original || ''),
                    ultima_fecha_original: unplanned ? '' : (routeDates[routeDates.length - 1] || row.ultima_fecha_original || ''),
                    observacion: unplanned
                        ? `${String(row.observacion || '').trim()} Local sin ruta en la selección descargada.`.trim()
                        : `${String(row.observacion || '').trim()} Descarga de ruta base; el orden aplica a todas sus repeticiones.`.trim()
                });
            });
        });

    return downloadRows;
}

async function downloadEditedRoutes(scope = 'monthly', buttonSelector = '') {
    if (!planRows.length) {
        alert('Primero debes cargar un archivo.');
        return;
    }

    const isRouteOnly = scope === 'route';
    const selectedPlanningRows = isRouteOnly ? routeRowsForBaseDownload() : [];
    const routeBaseRows = buildRouteBaseDownloadRows(isRouteOnly ? selectedPlanningRows : planRows);
    const rowsToDownload = isRouteOnly ? selectedPlanningRows : planRows;
    if (!rowsToDownload.length) {
        alert(isRouteOnly
            ? 'Selecciona uno o más locales, o filtra un grupo de ruta antes de descargar.'
            : 'La planificación no tiene locales para descargar.');
        return;
    }

    const weekendRows = rowsToDownload.filter(row => row.fecha_ruta_sql && isWeekendSql(row.fecha_ruta_sql));
    if (weekendRows.length) {
        alert(`Hay ${weekendRows.length} local(es) asignados a sabado o domingo. Vuelve a ejecutar el optimizador o corrige sus fechas antes de descargar.`);
        return;
    }

    const button = $(buttonSelector || (isRouteOnly ? '#btnDescargarRuta' : '#btnDescargarEditado'));
    const original = button.html();
    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Generando...');

    try {
        const body = new FormData();
        body.set('rows', JSON.stringify(rowsToDownload));
        if (isRouteOnly || (!loadedRouteBaseFile && routeBaseRows.length)) {
            body.set('route_base_rows', JSON.stringify(routeBaseRows));
        }
        if (!isRouteOnly && loadedRouteBaseFile) {
            body.set('archivoRutasBaseOriginal', loadedRouteBaseFile, loadedRouteBaseFile.name);
        }

        const response = await fetch('mod_descargar_rutas_editadas.php', {
            method: 'POST',
            body
        });

        if (!response.ok) {
            throw new Error(await response.text() || 'No se pudo generar el archivo.');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        const disposition = response.headers.get('Content-Disposition') || '';
        const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
        link.download = filenameMatch
            ? filenameMatch[1]
            : isRouteOnly
                ? `rutero_rutas_seleccionadas_${new Date().toISOString().slice(0, 10)}.xlsx`
                : `${loadedRouteBaseFile ? 'rutero_periodo_con_rutas_base' : 'planificacion_periodo_frecuencia'}_${new Date().toISOString().slice(0, 10)}.xlsx`;
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
        id_local: local.id_local || '',
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
        ultima_fecha_propuesta_sql: local.ultima_fecha_propuesta_sql || '',
        sin_ruta: true
    }));
}

function rowsFromClientOrderUpload(resp) {
    const sourceRows = (Array.isArray(resp.encontrados) ? resp.encontrados : [])
        .filter(local => /^\d{4}-\d{2}-\d{2}$/.test(String(local.fecha_visita_sql || '')) && Number(local.orden_visita) > 0)
        .map((local, index) => ({
            local,
            sourceIndex: index,
            userKey: String(local.usuario_id || local.usuario_login || local.usuario_nombre || local.usuario_input || 'SIN_USUARIO'),
            dateSql: String(local.fecha_visita_sql),
            order: Number(local.orden_visita)
        }))
        .sort((left, right) => left.userKey.localeCompare(right.userKey, 'es', { numeric: true, sensitivity: 'base' })
            || left.dateSql.localeCompare(right.dateSql)
            || left.order - right.order
            || left.sourceIndex - right.sourceIndex);

    const routeNumbers = new Map();
    const userCounters = new Map();
    sourceRows.forEach(item => {
        const routeKey = `${item.userKey}::${item.dateSql}`;
        if (routeNumbers.has(routeKey)) return;
        const next = (userCounters.get(item.userKey) || 0) + 1;
        userCounters.set(item.userKey, next);
        routeNumbers.set(routeKey, next);
    });
    const firstDate = sourceRows.map(item => item.dateSql).sort()[0] || '';
    const grouped = new Map();

    sourceRows.forEach(item => {
        const local = item.local;
        const routeKey = `${item.userKey}::${item.dateSql}`;
        const routeNumber = routeNumbers.get(routeKey) || 1;
        const groupName = `RUTA ${String(routeNumber).padStart(2, '0')}`;
        const metadata = dateMetadata(item.dateSql);
        const row = {
            row_id: `orden_cliente_${local.fila_csv || item.sourceIndex + 2}_${local.id_local || local.codigo || item.sourceIndex}`,
            id_local: local.id_local || '',
            codigo_local: String(local.codigo || ''),
            nombre: local.nombre || '',
            direccion: local.direccion_google || local.direccion || '',
            direccion_original: local.direccion || '',
            comuna: local.comuna || '',
            lat: local.tiene_coords ? Number(local.lat) : null,
            lng: local.tiene_coords ? Number(local.lng) : null,
            cantidad_objetivo_dia: 0,
            dias_planificados: 1,
            grupo_ruta: groupName,
            ruta_global: `${item.userKey} | ${groupName}`,
            fecha_ruta: metadata.display,
            fecha_ruta_sql: item.dateSql,
            usuario_id: local.usuario_id || '',
            usuario_login: local.usuario_login || local.usuario_input || '',
            usuario_nombre: local.usuario_nombre || local.usuario_login || local.usuario_input || '',
            dia_plan: routeNumber,
            semana_plan: firstDate ? planningWeekNumber(item.dateSql, firstDate) : metadata.week,
            dia_semana_num: metadata.dayNumber,
            dia_semana: metadata.dayName,
            orden_visita: item.order,
            tamano_ruta: 0,
            distancia_desde_anterior_km: 0,
            distancia_total_ruta_km: 0,
            observacion: 'Orden de visita entregado por el cliente; datos del local completados desde Visibility.',
            estado_address_validation: local.estado_address_validation || '',
            coordenadas_origen: local.coordenadas_origen || '',
            ultima_fecha_propuesta_sql: local.ultima_fecha_propuesta_sql || '',
            sin_ruta: false
        };
        if (!grouped.has(routeKey)) grouped.set(routeKey, []);
        grouped.get(routeKey).push(row);
    });

    const result = [];
    grouped.forEach(routeRows => {
        routeRows.sort((left, right) => Number(left.orden_visita) - Number(right.orden_visita));
        const { distances, totalDistanceKm } = routeTemplateDistance(routeRows);
        routeRows.forEach((row, index) => {
            row.orden_visita = Number(row.orden_visita);
            row.tamano_ruta = routeRows.length;
            row.cantidad_objetivo_dia = routeRows.length;
            row.distancia_desde_anterior_km = Number(distances[index] || 0);
            row.distancia_total_ruta_km = Number(totalDistanceKm || 0);
            result.push(row);
        });
    });
    return result.sort((left, right) => String(left.fecha_ruta_sql).localeCompare(String(right.fecha_ruta_sql))
        || String(left.usuario_login || left.usuario_nombre).localeCompare(String(right.usuario_login || right.usuario_nombre), 'es', { sensitivity: 'base' })
        || Number(left.orden_visita) - Number(right.orden_visita));
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

$('#formUploadRutasBase').on('submit', function(e) {
    e.preventDefault();

    const startMonthValue = $('#mesRutasBase').val();
    const endMonthValue = $('#mesFinRutasBase').val();
    if (!planningRangeBounds(startMonthValue, endMonthValue)) {
        alert('Selecciona un período válido: el mes final debe ser igual o posterior al mes inicial.');
        return;
    }

    const sourceFile = document.getElementById('archivoRutasBase')?.files?.[0] || null;
    const formData = new FormData(this);
    formData.set('modo', 'rutas_base');
    const btn = $('#btnCargarRutasBase');
    const original = btn.html();
    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Construyendo mes...');
    $('#rutasBaseResultBox').show().html('<div class="alert alert-info mb-0">Leyendo asociaciones de rutas y generando la planificación del período...</div>');

    $.ajax({
        url: 'mod_cargar_rutas_excel.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (!resp || resp.success !== true || !Array.isArray(resp.rows)) {
                btn.prop('disabled', false).html(original);
                $('#rutasBaseResultBox').html(`<div class="alert alert-danger mb-0">${escapeHtml(resp?.message || 'No se pudo procesar el archivo de rutas base.')}</div>`);
                return;
            }

            try {
                loadedRouteBaseRows = resp.rows.map((row, index) => ({
                    ...row,
                    row_id: row.row_id || `ruta_base_${index + 1}`
                }));
                $('#optMesPlanificacion').val(startMonthValue);
                $('#optMesFinPlanificacion').val(endMonthValue);
                const stats = planMonthlyFromLoadedRouteBase(startMonthValue, endMonthValue);
                loadedRouteBaseFile = sourceFile;
                const ignored = Number(resp.summary?.filas_ignoradas || 0);
                const ignoredNote = ignored > 0
                    ? ` Se eliminaron ${ignored} fila(s) repetida(s) o sin coordenadas.`
                    : '';
                const overflowNote = stats.overflowVisits > 0
                    ? ` La continuidad llega hasta ${dateMetadata(stats.coverageEnd).display}, con ${stats.overflowVisits} visita(s) fuera del período.`
                    : '';
                const rangeLabel = `${dateMetadata(stats.rangeStart).display} al ${dateMetadata(stats.rangeEnd).display}`;

                planSummary = Object.assign({}, resp.summary || {}, {
                    total_rutas_base: stats.routes,
                    total_asociaciones_base: stats.baseAssociations,
                    total_visitas_planificadas: stats.scheduledVisits
                });
                $('#rutasBaseResultBox').html(`
                    <div class="alert alert-success mb-0">
                        <strong>Planificación de ${stats.totalMonths} mes(es) construida sin reoptimizar las rutas.</strong><br>
                        Período: <strong>${escapeHtml(rangeLabel)}</strong>.<br>
                        ${stats.routes} ruta(s), ${stats.baseAssociations} asociación(es) de local y ${stats.scheduledVisits} visita(s) programadas.<br>
                        Días hábiles cubiertos por responsable: <strong>${stats.minimumScheduledDays} de ${stats.expectedBusinessDays}</strong>.<br>
                        Primera fecha incluida: <strong>${escapeHtml(dateMetadata(stats.firstDate).display)}</strong>. Repeticiones: ${stats.recurrenceVisits} visita(s); ajustes anticipados: ${stats.adjustedFrequencyAssignments}.${ignoredNote}${overflowNote}
                        <br>La descarga del período conservará la hoja <strong>Rutas Base</strong> del archivo original y agregará el rutero mensual en hojas separadas.
                    </div>
                `);
                $('#optimizadorEstado').text(
                    `Rutas base reutilizadas: ${stats.routes}. Primera fecha: ${dateMetadata(stats.firstDate).display}. `
                    + `Días hábiles cubiertos por responsable: ${stats.minimumScheduledDays}/${stats.expectedBusinessDays}.`
                );
                activateMapView();
            } catch (error) {
                console.error(error);
                $('#rutasBaseResultBox').html(`<div class="alert alert-danger mb-0">${escapeHtml(error.message || 'No se pudo generar la planificación del período.')}</div>`);
            } finally {
                btn.prop('disabled', false).html(original);
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(original);
            const message = xhr.responseJSON?.message || xhr.responseText || 'No se pudo cargar el archivo de rutas base.';
            $('#rutasBaseResultBox').html(`<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`);
        }
    });
});

$('#btnDescargarOrdenCliente').on('click', function() {
    downloadEditedRoutes('monthly', '#btnDescargarOrdenCliente');
});

$('#formUploadOrdenCliente').on('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.set('modo', 'orden_cliente');
    const btn = $('#btnCargarOrdenCliente');
    const downloadButton = $('#btnDescargarOrdenCliente');
    const original = btn.html();
    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Preparando...');
    downloadButton.prop('disabled', true);
    $('#ordenClienteResultBox').show().html('<div class="alert alert-info mb-0">Leyendo el orden del cliente y completando los datos desde Visibility...</div>');

    $.ajax({
        url: 'mod_cargar_locales.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (!resp || resp.success !== true) {
                $('#ordenClienteResultBox').html(`<div class="alert alert-danger mb-0">${escapeHtml(resp?.message || 'No se pudo procesar el orden del cliente.')}</div>`);
                return;
            }

            loadedRouteBaseFile = null;
            loadedRouteBaseRows = [];
            planRows = rowsFromClientOrderUpload(resp);
            if (!planRows.length) {
                $('#ordenClienteResultBox').html('<div class="alert alert-danger mb-0">No quedaron visitas válidas para generar la planificación.</div>');
                return;
            }
            planGroups = [];
            planSummary = Object.assign({}, resp.resumen || {});
            planFilters = { usuarios: [], fechas: [], grupos: [] };
            selectedRowIds.clear();
            rebuildFiltersFromRows();
            renderUsuarioSelect();
            renderFechaSelect();
            $('#filtroGrupoRuta').html('<option value="__ALL__">Todas las rutas</option>');
            refreshAllViews(true);
            $('#emptyMapState').hide();
            $('#bloqueMapa').show();
            downloadButton.prop('disabled', false);

            const resumen = resp.resumen || {};
            const routeCount = new Set(planRows.map(routeDownloadIdentity)).size;
            const dateCount = new Set(planRows.map(row => row.fecha_ruta_sql).filter(Boolean)).size;
            const invalidRows = Array.isArray(resp.filas_invalidas) ? resp.filas_invalidas : [];
            const missingCoordinates = (Array.isArray(resp.encontrados) ? resp.encontrados : []).filter(item => !item.tiene_coords);
            const observations = [
                ...invalidRows.map(item => `Fila ${item.fila_csv}: ${item.codigo || '(sin código)'} — ${item.motivo || 'Fila inválida'}`),
                ...missingCoordinates.map(item => `Fila ${item.fila_csv}: ${item.codigo} — ${item.motivo_validacion || 'Sin coordenadas'}`)
            ];
            const detail = observations.length
                ? `<details class="mt-2"><summary>Ver ${observations.length} observación(es)</summary><ul class="mb-0 mt-2">${observations.slice(0, 80).map(text => `<li>${escapeHtml(text)}</li>`).join('')}</ul>${observations.length > 80 ? '<small>Se muestran las primeras 80 observaciones.</small>' : ''}</details>`
                : '';

            $('#ordenClienteResultBox').html(`
                <div class="alert alert-success mb-0">
                    <strong>Planificación preparada respetando el orden del cliente.</strong><br>
                    ${planRows.length} visita(s), ${routeCount} ruta(s) diaria(s), ${dateCount} fecha(s) y ${planFilters.usuarios.length} responsable(s).<br>
                    ${Number(resumen.filas_invalidas || 0)} fila(s) fueron descartadas; ${Number(resumen.sin_coordenadas || 0)} local(es) quedaron sin coordenadas para el mapa, pero se mantienen en la descarga cuando existen en Visibility.<br>
                    Ya puedes descargar el Excel compatible con la planificación del cliente o revisar las rutas en el mapa.
                    ${detail}
                </div>
            `);
        },
        error: function(xhr) {
            const message = xhr.responseJSON?.message || xhr.responseText || 'No se pudo procesar el orden del cliente.';
            $('#ordenClienteResultBox').html(`<div class="alert alert-danger mb-0">${escapeHtml(message)}</div>`);
        },
        complete: function() {
            btn.prop('disabled', false).html(original);
        }
    });
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

            loadedRouteBaseFile = null;
            loadedRouteBaseRows = [];
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
                ? `<br><span class="text-danger">${invalidas} fila(s) no se cargaron por código de local inválido o responsable vacío.</span>`
                : '';
            const responsablesLibres = Number(resumen.responsables_sin_registro || 0);
            const detalleResponsables = responsablesLibres > 0
                ? `<br><span class="text-primary">${responsablesLibres} responsable(s) aún no registrados fueron aceptados.</span>`
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
                        ${resumen.sin_coordenadas || 0} sin coordenadas utilizables.${detalleInvalidas}${detalleResponsables}
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

$('#btnEditarSeleccionados').on('click', () => openEditModal());
$('#btnAplicarEdicion').on('click', applyRouteEdits);
$('#btnOrdenarSeleccionCercania').on('click', reorderSelectedEditRows);
$('#btnDescargarEditado').on('click', () => downloadEditedRoutes('monthly'));
$('#btnDescargarRuta').on('click', () => downloadEditedRoutes('route'));
$('#btnOptimizarRutas').on('click', optimizeVisibleRoutes);
$('#btnPlanificarMes').on('click', planMonthlyRoutes);
$('#btnRepartirMerch').on('click', distributeLocalsAmongWorkers);
$('#btnBuscarGeorreferencia').on('click', searchCorrectedGeoreference);
$('#btnGuardarGeorreferencia').on('click', saveCorrectedGeoreference);
$('#buscarMerchReparto').on('input', function() {
    renderMerchWorkers($(this).val());
});
$('#idDivisionPlanificacion').on('change', loadMerchWorkers);
$('#geoDireccion').on('input', function() {
    pendingGeoreference = null;
    $('#geoLat, #geoLng').val('');
    $('#btnGuardarGeorreferencia').prop('disabled', true);
});

$(document).on('click', '.btnMoverOrden', function() {
    moveEditOrderRow(Number($(this).data('index')), Number($(this).data('direction')));
});

$('#optFechaInicio').val(todaySqlLocal());
const defaultPlanningMonth = todaySqlLocal().slice(0, 7);
$('#optMesPlanificacion, #optMesFinPlanificacion, #mesRutasBase, #mesFinRutasBase').val(defaultPlanningMonth);

$('#optMesPlanificacion').on('change', function() {
    const startMonth = $(this).val();
    if (!$('#optMesFinPlanificacion').val() || $('#optMesFinPlanificacion').val() < startMonth) {
        $('#optMesFinPlanificacion').val(startMonth);
    }
});

$('#mesRutasBase').on('change', function() {
    const startMonth = $(this).val();
    if (!$('#mesFinRutasBase').val() || $('#mesFinRutasBase').val() < startMonth) {
        $('#mesFinRutasBase').val(startMonth);
    }
});

$('#checkSeleccionarVisibles').on('change', function() {
    const checked = $(this).is(':checked');
    getRouteLevelRows().forEach(row => setRouteLevelRowSelection(row, checked));
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
    modalGeorreferencia = new bootstrap.Modal(document.getElementById('modalGeorreferencia'));
    loadMerchWorkers();
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

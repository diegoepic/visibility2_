<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
set_time_limit(180);
date_default_timezone_set('America/Santiago');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $conn->error);
    }
    bindParams($stmt, $types, $params);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error ejecutando consulta: ' . $error);
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function hasCoords(array $row): bool {
    return isset($row['lat'], $row['lng'])
        && $row['lat'] !== null
        && $row['lng'] !== null
        && $row['lat'] !== ''
        && $row['lng'] !== ''
        && is_numeric((string)$row['lat'])
        && is_numeric((string)$row['lng']);
}

function distanceKm(array $a, array $b): float {
    if (!hasCoords($a) || !hasCoords($b)) {
        return 99999.0;
    }
    $lat1 = deg2rad((float)$a['lat']);
    $lat2 = deg2rad((float)$b['lat']);
    $dLat = $lat2 - $lat1;
    $dLng = deg2rad((float)$b['lng'] - (float)$a['lng']);
    $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
    return 6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h));
}

function businessDays(string $startDate, int $count): array {
    $days = [];
    $date = new DateTime($startDate !== '' ? $startDate : date('Y-m-d'));
    while (count($days) < $count) {
        $dow = (int)$date->format('N');
        if ($dow <= 5) {
            $days[] = $date->format('Y-m-d');
        }
        $date->modify('+1 day');
    }
    return $days;
}

function summarizeByUser(array $rows): array {
    $summary = [];
    foreach ($rows as $row) {
        $userId = (int)($row['id_usuario'] ?? 0);
        if (!isset($summary[$userId])) {
            $summary[$userId] = [
                'usuario' => (string)($row['usuario'] ?? ''),
                'locales_pendientes' => 0,
                'volumen_campanas' => 0,
                'total_registros' => 0,
                'rutas' => [],
                'sin_coordenadas' => 0,
            ];
        }
        $summary[$userId]['locales_pendientes']++;
        $summary[$userId]['volumen_campanas'] += (int)($row['volumen_campanas'] ?? 0);
        $summary[$userId]['total_registros'] += (int)($row['total_registros'] ?? 0);
        if (($row['propuesta_fecha'] ?? '') === '') {
            $summary[$userId]['sin_coordenadas']++;
        }
        if (!empty($row['grupo_ruta']) && $row['grupo_ruta'] !== 'SIN COORDENADAS') {
            $summary[$userId]['rutas'][$row['propuesta_fecha'] . ' ' . $row['grupo_ruta']] = true;
        }
    }

    foreach ($summary as &$item) {
        $item['rutas'] = count($item['rutas']);
    }
    unset($item);

    usort($summary, function ($a, $b) {
        return $b['locales_pendientes'] <=> $a['locales_pendientes']
            ?: $b['volumen_campanas'] <=> $a['volumen_campanas']
            ?: strcmp($a['usuario'], $b['usuario']);
    });

    return $summary;
}

function nextBestLocal(array $seed, array $remaining): int {
    $bestIndex = 0;
    $bestScore = PHP_FLOAT_MAX;
    foreach ($remaining as $idx => $candidate) {
        $dist = distanceKm($seed, $candidate);
        $sameComuna = trim((string)$seed['comuna']) !== ''
            && strcasecmp((string)$seed['comuna'], (string)$candidate['comuna']) === 0;
        $score = $dist - ((int)$candidate['volumen_campanas'] * 0.65) + ($sameComuna ? -2.5 : 0);
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestIndex = $idx;
        }
    }
    return $bestIndex;
}

function orderRoute(array $route): array {
    if (count($route) <= 2) {
        return $route;
    }
    usort($route, fn($a, $b) => ((int)$b['volumen_campanas'] <=> (int)$a['volumen_campanas']));
    $ordered = [array_shift($route)];
    while (!empty($route)) {
        $last = $ordered[count($ordered) - 1];
        $idx = nextBestLocal($last, $route);
        $ordered[] = $route[$idx];
        array_splice($route, $idx, 1);
    }
    return $ordered;
}

function buildProposal(array $workload, int $targetPerDay, string $startDate): array {
    $byUser = [];
    foreach ($workload as $row) {
        $byUser[(int)$row['id_usuario']][] = $row;
    }

    $proposal = [];
    foreach ($byUser as $userId => $items) {
        $withCoords = [];
        $withoutCoords = [];
        foreach ($items as $item) {
            if (hasCoords($item)) {
                $withCoords[] = $item;
            } else {
                $withoutCoords[] = $item;
            }
        }

        usort($withCoords, function ($a, $b) {
            $cmp = (int)$b['volumen_campanas'] <=> (int)$a['volumen_campanas'];
            if ($cmp !== 0) return $cmp;
            return strcmp((string)$a['codigo'], (string)$b['codigo']);
        });

        $dayCount = max(1, (int)ceil(count($withCoords) / max(1, $targetPerDay)));
        $dates = businessDays($startDate, $dayCount);
        $day = 0;
        $remaining = $withCoords;

        while (!empty($remaining)) {
            $seed = array_shift($remaining);
            $route = [$seed];
            while (count($route) < $targetPerDay && !empty($remaining)) {
                $idx = nextBestLocal($seed, $remaining);
                $route[] = $remaining[$idx];
                array_splice($remaining, $idx, 1);
            }
            $route = orderRoute($route);
            $routeName = 'RUTA ' . str_pad((string)($day + 1), 2, '0', STR_PAD_LEFT);
            foreach ($route as $order => $local) {
                $proposal[] = [
                    'propuesta_fecha' => $dates[$day] ?? end($dates),
                    'grupo_ruta' => $routeName,
                    'orden_visita' => $order + 1,
                    'id_usuario' => (int)$local['id_usuario'],
                    'usuario' => $local['usuario'],
                    'id_local' => (int)$local['id_local'],
                    'codigo' => $local['codigo'],
                    'local' => $local['local_nombre'],
                    'direccion' => $local['direccion'],
                    'comuna' => $local['comuna'],
                    'lat' => $local['lat'],
                    'lng' => $local['lng'],
                    'volumen_campanas' => (int)$local['volumen_campanas'],
                    'total_registros' => (int)$local['total_registros'],
                    'campanas' => $local['campanas'],
                    'observacion' => 'Propuesta por volumen + cercania',
                ];
            }
            $day++;
        }

        foreach ($withoutCoords as $local) {
            $proposal[] = [
                'propuesta_fecha' => '',
                'grupo_ruta' => 'SIN COORDENADAS',
                'orden_visita' => '',
                'id_usuario' => (int)$local['id_usuario'],
                'usuario' => $local['usuario'],
                'id_local' => (int)$local['id_local'],
                'codigo' => $local['codigo'],
                'local' => $local['local_nombre'],
                'direccion' => $local['direccion'],
                'comuna' => $local['comuna'],
                'lat' => $local['lat'],
                'lng' => $local['lng'],
                'volumen_campanas' => (int)$local['volumen_campanas'],
                'total_registros' => (int)$local['total_registros'],
                'campanas' => $local['campanas'],
                'observacion' => 'No se puede rutear: local sin lat/lng',
            ];
        }
    }

    usort($proposal, function ($a, $b) {
        return [$a['usuario'], $a['propuesta_fecha'], $a['grupo_ruta'], (int)$a['orden_visita']]
            <=> [$b['usuario'], $b['propuesta_fecha'], $b['grupo_ruta'], (int)$b['orden_visita']];
    });

    return $proposal;
}

function loadWorkload(mysqli $conn, int $divisionId, int $estado, array $formIds = []): array {
    $params = [$divisionId, $estado];
    $types = 'ii';
    $formFilter = '';
    if (!empty($formIds)) {
        $formFilter = ' AND f.id IN (' . implode(',', array_fill(0, count($formIds), '?')) . ')';
        foreach ($formIds as $formId) {
            $params[] = (int)$formId;
            $types .= 'i';
        }
    }

    $sql = "
        SELECT
            fq.id_usuario,
            UPPER(COALESCE(u.usuario, CONCAT('USUARIO ', fq.id_usuario))) AS usuario,
            fq.id_local,
            l.codigo,
            l.nombre AS local_nombre,
            l.direccion,
            COALESCE(c.comuna, '') AS comuna,
            l.lat,
            l.lng,
            COUNT(DISTINCT f.id) AS volumen_campanas,
            COUNT(fq.id) AS total_registros,
            GROUP_CONCAT(DISTINCT f.nombre ORDER BY f.fechaInicio, f.nombre SEPARATOR ' | ') AS campanas
        FROM formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        INNER JOIN usuario u ON u.id = fq.id_usuario
        INNER JOIN local l ON l.id = fq.id_local
        LEFT JOIN comuna c ON c.id = l.id_comuna
        WHERE f.id_division = ?
          AND f.estado = ?
          AND f.tipo IN (1, 3)
          AND fq.id_usuario IS NOT NULL
          AND fq.id_usuario > 0
          AND fq.id_local IS NOT NULL
          AND fq.id_local > 0
          AND (
              fq.fechaVisita IS NULL
              OR CAST(fq.fechaVisita AS CHAR) = ''
              OR CAST(fq.fechaVisita AS CHAR) = '0000-00-00 00:00:00'
          )
          AND COALESCE(u.activo, 1) = 1
          AND l.deleted_at IS NULL
          {$formFilter}
        GROUP BY
            fq.id_usuario, u.usuario, fq.id_local, l.codigo, l.nombre,
            l.direccion, c.comuna, l.lat, l.lng
        ORDER BY usuario ASC, volumen_campanas DESC, l.codigo ASC
    ";

    return fetchAll($conn, $sql, $types, $params);
}

function outputExcel(array $rows): void {
    $summary = summarizeByUser($rows);
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="propuesta_fecha_ruta_' . date('Ymd_His') . '.xls"');
    echo "\xEF\xBB\xBF";
    echo "<table border='1'>";
    echo "<tr><th colspan='6'>Resumen por usuario</th></tr>";
    echo "<tr><th>Usuario</th><th>Locales Pendientes</th><th>Volumen Campanas</th><th>Total Registros</th><th>Rutas Propuestas</th><th>Sin Coordenadas</th></tr>";
    foreach ($summary as $row) {
        echo '<tr>';
        foreach (['usuario','locales_pendientes','volumen_campanas','total_registros','rutas','sin_coordenadas'] as $key) {
            echo '<td>' . h($row[$key] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo "</table><br>";

    echo "<table border='1'>";
    echo "<tr><th>Fecha Propuesta</th><th>Usuario</th><th>Grupo Ruta</th><th>Orden</th><th>Codigo Local</th><th>Local</th><th>Direccion</th><th>Comuna</th><th>Lat</th><th>Lng</th><th>Volumen Campanas</th><th>Total Registros</th><th>Campanas</th><th>Observacion</th></tr>";
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (['propuesta_fecha','usuario','grupo_ruta','orden_visita','codigo','local','direccion','comuna','lat','lng','volumen_campanas','total_registros','campanas','observacion'] as $key) {
            echo '<td>' . h($row[$key] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

function applyProposal(mysqli $conn, array $rows, int $divisionId, int $estado): int {
    $sql = "
        UPDATE formularioQuestion fq
        INNER JOIN formulario f ON f.id = fq.id_formulario
        SET fq.fechaPropuesta = ?
        WHERE fq.id_usuario = ?
          AND fq.id_local = ?
          AND f.id_division = ?
          AND f.estado = ?
          AND f.tipo IN (1, 3)
          AND (
              fq.fechaVisita IS NULL
              OR CAST(fq.fechaVisita AS CHAR) = ''
              OR CAST(fq.fechaVisita AS CHAR) = '0000-00-00 00:00:00'
          )
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando update: ' . $conn->error);
    }

    $updated = 0;
    $conn->begin_transaction();
    try {
        foreach ($rows as $row) {
            if (empty($row['propuesta_fecha'])) {
                continue;
            }
            $fecha = $row['propuesta_fecha'];
            $usuario = (int)$row['id_usuario'];
            $local = (int)$row['id_local'];
            $stmt->bind_param('siiii', $fecha, $usuario, $local, $divisionId, $estado);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $updated += $stmt->affected_rows;
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $stmt->close();
        throw $e;
    }
    $stmt->close();
    return $updated;
}

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$divisionSesion = (int)($_SESSION['division_id'] ?? 0);
$mensaje = '';
$error = '';
$proposal = [];

try {
    $divisiones = fetchAll(
        $conn,
        "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC"
    );
} catch (Exception $e) {
    $divisiones = [];
    $error = $e->getMessage();
}

$divisionId = (int)($_POST['division_id'] ?? $_GET['division_id'] ?? ($divisionSesion ?: 0));
$estado = (int)($_POST['estado'] ?? $_GET['estado'] ?? 1);
$target = max(1, (int)($_POST['target_per_day'] ?? $_GET['target_per_day'] ?? 20));
$startDateInput = trim((string)($_POST['start_date'] ?? $_GET['start_date'] ?? ''));
$startDate = $startDateInput !== '' ? $startDateInput : date('Y-m-d');
$action = (string)($_POST['action'] ?? '');

try {
    if ($divisionId > 0 && in_array($action, ['preview', 'excel', 'apply'], true)) {
        $workload = loadWorkload($conn, $divisionId, $estado);
        $proposal = buildProposal($workload, $target, $startDate);

        if ($action === 'excel') {
            outputExcel($proposal);
        }

        if ($action === 'apply') {
            $updated = applyProposal($conn, $proposal, $divisionId, $estado);
            $mensaje = "Update aplicado. Registros formularioQuestion actualizados: " . number_format($updated, 0, ',', '.');
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planificador fecha propuesta</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        body { background:#f4f7fb; color:#1f2d3d; font-family: Arial, sans-serif; }
        .page { max-width: 1320px; margin: 28px auto; padding: 0 18px; }
        .panel { background:#fff; border:1px solid #dfe8f4; border-radius:10px; padding:18px; box-shadow:0 10px 28px rgba(30,60,100,.06); }
        .metric { border-radius:8px; background:#f7fbff; border:1px solid #dfe8f4; padding:14px; }
        .metric strong { display:block; font-size:24px; color:#145ca8; }
        .table-wrap { max-height: 620px; overflow:auto; border:1px solid #e3ebf5; border-radius:8px; }
        th { position:sticky; top:0; background:#0f5da8; color:#fff; z-index:2; white-space:nowrap; }
        td { vertical-align:middle !important; font-size:13px; }
        .route-badge { font-weight:800; color:#0f5da8; }
        #proposalMap { height: 68vh; min-height: 520px; border-radius: 10px; overflow: hidden; border:1px solid #dfe8f4; }
        .priority-dot { border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 2px rgba(20,80,140,.18), 0 8px 18px rgba(20,60,120,.22); color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; }
        .map-legend { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; font-size:12px; color:#52657a; }
        .legend-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 8px; border-radius:999px; background:#f5f9ff; border:1px solid #dfe8f4; }
        .legend-color { width:12px; height:12px; border-radius:50%; display:inline-block; }
        .map-modal-backdrop { position:fixed; inset:0; background:rgba(11,24,42,.58); z-index:1040; display:none; align-items:center; justify-content:center; padding:22px; }
        .map-modal-backdrop.is-open { display:flex; }
        .map-modal { width:min(1480px, 96vw); max-height:94vh; background:#fff; border-radius:14px; box-shadow:0 28px 70px rgba(6,24,44,.35); overflow:hidden; display:flex; flex-direction:column; }
        .map-modal-header { padding:14px 18px; border-bottom:1px solid #dfe8f4; display:flex; align-items:center; justify-content:space-between; gap:14px; }
        .map-modal-title { font-weight:800; color:#16324f; margin:0; }
        .map-modal-body { padding:16px; overflow:auto; }
        .map-toolbar { display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin-bottom:12px; }
        .map-toolbar .form-group { min-width:280px; margin-bottom:0; }
        .map-counter { color:#52657a; font-size:13px; padding-bottom:8px; }
        .modal-close-btn { border:0; background:#eef4fb; color:#17324f; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    </style>
</head>
<body>
<div class="page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="mb-1"><i class="fa-solid fa-route"></i> Planificador de fecha propuesta</h3>
            <div class="text-muted">Genera rutas propuestas por usuario priorizando volumen de campanas y cercania geografica.</div>
        </div>
    </div>

    <?php if ($mensaje): ?><div class="alert alert-success"><?php echo h($mensaje); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

    <div class="panel mb-4">
        <form method="post" class="row align-items-end">
            <input type="hidden" name="action" value="preview">
            <div class="col-md-3">
                <label>Division</label>
                <select name="division_id" class="form-control" required>
                    <option value="">Seleccionar</option>
                    <?php foreach ($divisiones as $division): ?>
                        <option value="<?php echo (int)$division['id']; ?>" <?php echo $divisionId === (int)$division['id'] ? 'selected' : ''; ?>>
                            <?php echo h($division['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Estado campana</label>
                <select name="estado" class="form-control">
                    <option value="1" <?php echo $estado === 1 ? 'selected' : ''; ?>>En curso</option>
                    <option value="2" <?php echo $estado === 2 ? 'selected' : ''; ?>>En proceso</option>
                    <option value="3" <?php echo $estado === 3 ? 'selected' : ''; ?>>Finalizada</option>
                </select>
            </div>
            <div class="col-md-2">
                <label>Fecha inicio</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo h($startDateInput); ?>">
                <small class="text-muted">En blanco toma todo lo activo pendiente y agenda desde hoy.</small>
            </div>
            <div class="col-md-2">
                <label>Locales por dia</label>
                <input type="number" name="target_per_day" class="form-control" min="1" value="<?php echo (int)$target; ?>" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-wand-magic-sparkles"></i> Generar propuesta</button>
            </div>
        </form>
    </div>

    <?php if (!empty($proposal)): ?>
        <?php
        $locales = array_filter($proposal, fn($r) => $r['propuesta_fecha'] !== '');
        $sinCoords = count($proposal) - count($locales);
        $usuarios = count(array_unique(array_column($proposal, 'id_usuario')));
        $volumen = array_sum(array_column($proposal, 'volumen_campanas'));
        $summaryByUser = summarizeByUser($proposal);
        $mapRows = array_values(array_filter($proposal, fn($r) => $r['propuesta_fecha'] !== '' && is_numeric((string)$r['lat']) && is_numeric((string)$r['lng'])));
        ?>
        <div class="row mb-4">
            <div class="col-md-3"><div class="metric"><strong><?php echo number_format(count($locales), 0, ',', '.'); ?></strong>Locales ruteados</div></div>
            <div class="col-md-3"><div class="metric"><strong><?php echo number_format($usuarios, 0, ',', '.'); ?></strong>Usuarios</div></div>
            <div class="col-md-3"><div class="metric"><strong><?php echo number_format($volumen, 0, ',', '.'); ?></strong>Volumen campanas</div></div>
            <div class="col-md-3"><div class="metric"><strong><?php echo number_format($sinCoords, 0, ',', '.'); ?></strong>Sin coordenadas</div></div>
        </div>

        <div class="panel mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Ruta propuesta</h5>
                <div>
                    <button type="button" class="btn btn-info" id="btnShowMap">
                        <i class="fa-solid fa-map-location-dot"></i> Ver mapa
                    </button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="excel">
                        <input type="hidden" name="division_id" value="<?php echo (int)$divisionId; ?>">
                        <input type="hidden" name="estado" value="<?php echo (int)$estado; ?>">
                        <input type="hidden" name="start_date" value="<?php echo h($startDate); ?>">
                        <input type="hidden" name="target_per_day" value="<?php echo (int)$target; ?>">
                        <button class="btn btn-success"><i class="fa-solid fa-file-excel"></i> Descargar Excel</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Esto actualizara fechaPropuesta en formularioQuestion para las rutas propuestas. Deseas continuar?');">
                        <input type="hidden" name="action" value="apply">
                        <input type="hidden" name="division_id" value="<?php echo (int)$divisionId; ?>">
                        <input type="hidden" name="estado" value="<?php echo (int)$estado; ?>">
                        <input type="hidden" name="start_date" value="<?php echo h($startDate); ?>">
                        <input type="hidden" name="target_per_day" value="<?php echo (int)$target; ?>">
                        <button class="btn btn-danger"><i class="fa-solid fa-database"></i> Aplicar update fechaPropuesta</button>
                    </form>
                </div>
            </div>

            <div class="mb-4">
                <h6>Resumen por usuario</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Locales pendientes</th>
                            <th>Volumen campañas</th>
                            <th>Total registros</th>
                            <th>Rutas propuestas</th>
                            <th>Sin coordenadas</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($summaryByUser as $summary): ?>
                            <tr>
                                <td><?php echo h($summary['usuario']); ?></td>
                                <td><?php echo (int)$summary['locales_pendientes']; ?></td>
                                <td><?php echo (int)$summary['volumen_campanas']; ?></td>
                                <td><?php echo (int)$summary['total_registros']; ?></td>
                                <td><?php echo (int)$summary['rutas']; ?></td>
                                <td><?php echo (int)$summary['sin_coordenadas']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="mapModalBackdrop" class="map-modal-backdrop" aria-hidden="true">
                <div class="map-modal" role="dialog" aria-modal="true" aria-labelledby="mapModalTitle">
                    <div class="map-modal-header">
                        <h5 id="mapModalTitle" class="map-modal-title"><i class="fa-solid fa-map-location-dot"></i> Mapa de prioridades y ruta</h5>
                        <button type="button" class="modal-close-btn" id="btnCloseMap" aria-label="Cerrar mapa">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="map-modal-body">
                        <div class="map-toolbar">
                            <div class="form-group">
                                <label for="mapUserFilter">Usuario</label>
                                <select id="mapUserFilter" class="form-control">
                                    <option value="">Todos los usuarios</option>
                                </select>
                            </div>
                            <div class="map-counter" id="mapCounter"></div>
                        </div>
                        <div id="proposalMap"></div>
                        <div class="map-legend" id="mapLegend"></div>
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Fecha</th><th>Usuario</th><th>Ruta</th><th>Orden</th><th>Codigo</th><th>Local</th><th>Comuna</th><th>Volumen</th><th>Campanas</th><th>Obs.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($proposal as $row): ?>
                        <tr>
                            <td><?php echo h($row['propuesta_fecha']); ?></td>
                            <td><?php echo h($row['usuario']); ?></td>
                            <td class="route-badge"><?php echo h($row['grupo_ruta']); ?></td>
                            <td><?php echo h($row['orden_visita']); ?></td>
                            <td><?php echo h($row['codigo']); ?></td>
                            <td><?php echo h($row['local']); ?></td>
                            <td><?php echo h($row['comuna']); ?></td>
                            <td><?php echo (int)$row['volumen_campanas']; ?></td>
                            <td><?php echo h($row['campanas']); ?></td>
                            <td><?php echo h($row['observacion']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php if (!empty($proposal)): ?>
<script>
const proposalMapRows = <?php echo json_encode($mapRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const userPalette = ['#0f5da8', '#21a67a', '#f59f00', '#d9480f', '#7048e8', '#0ca6a6', '#c2255c', '#2f9e44', '#4263eb', '#e67700'];
let proposalMap = null;
let proposalLayer = null;

function userColor(userId) {
    const numeric = Number(userId) || 0;
    return userPalette[Math.abs(numeric) % userPalette.length];
}

function markerSize(volume) {
    const v = Math.max(1, Number(volume) || 1);
    return Math.min(36, 16 + (v * 2));
}

function getFilteredMapRows() {
    const filter = document.getElementById('mapUserFilter').value;
    if (!filter) {
        return proposalMapRows;
    }
    return proposalMapRows.filter(row => String(row.id_usuario) === filter);
}

function fillUserFilter() {
    const filter = document.getElementById('mapUserFilter');
    const users = new Map();
    proposalMapRows.forEach(row => {
        users.set(String(row.id_usuario), row.usuario);
    });

    Array.from(users.entries())
        .sort((a, b) => String(a[1]).localeCompare(String(b[1])))
        .forEach(([id, name]) => {
            const option = document.createElement('option');
            option.value = id;
            option.textContent = name;
            filter.appendChild(option);
        });
}

function renderProposalLayers() {
    const rows = getFilteredMapRows();
    const bounds = [];
    const routeGroups = new Map();
    const legendUsers = new Map();
    const layer = L.layerGroup();

    if (proposalLayer) {
        proposalLayer.remove();
    }

    rows.forEach(row => {
        const lat = Number(row.lat);
        const lng = Number(row.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const color = userColor(row.id_usuario);
        const size = markerSize(row.volumen_campanas);
        const key = `${row.id_usuario}|${row.propuesta_fecha}|${row.grupo_ruta}`;
        if (!routeGroups.has(key)) routeGroups.set(key, []);
        routeGroups.get(key).push(row);
        legendUsers.set(row.usuario, color);

        const icon = L.divIcon({
            className: '',
            html: `<div class="priority-dot" style="width:${size}px;height:${size}px;background:${color};">${row.orden_visita || ''}</div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });

        L.marker([lat, lng], { icon }).addTo(layer).bindPopup(`
            <strong>${row.usuario}</strong><br>
            ${row.propuesta_fecha} - ${row.grupo_ruta}<br>
            Orden ${row.orden_visita}<br>
            ${row.codigo} - ${row.local}<br>
            Volumen campañas: ${row.volumen_campanas}<br>
            ${row.comuna || ''}
        `);
        bounds.push([lat, lng]);
    });

    routeGroups.forEach(rows => {
        const sorted = rows.slice().sort((a, b) => Number(a.orden_visita) - Number(b.orden_visita));
        const points = sorted
            .map(row => [Number(row.lat), Number(row.lng)])
            .filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
        if (points.length > 1) {
            L.polyline(points, {
                color: userColor(sorted[0].id_usuario),
                weight: 4,
                opacity: .68
            }).addTo(layer);
        }
    });

    layer.addTo(proposalMap);
    proposalLayer = layer;

    const legend = document.getElementById('mapLegend');
    legend.innerHTML = '';
    legendUsers.forEach((color, user) => {
        const chip = document.createElement('span');
        chip.className = 'legend-chip';
        chip.innerHTML = `<span class="legend-color" style="background:${color}"></span>${user}`;
        legend.appendChild(chip);
    });

    const counter = document.getElementById('mapCounter');
    counter.textContent = `${bounds.length} locales visibles`;

    if (bounds.length) {
        proposalMap.fitBounds(bounds, { padding: [28, 28] });
    } else {
        proposalMap.setView([-33.45, -70.66], 10);
    }
}

function openProposalMap() {
    const modal = document.getElementById('mapModalBackdrop');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    if (!proposalMap) {
        proposalMap = L.map('proposalMap', { scrollWheelZoom: true }).setView([-33.45, -70.66], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(proposalMap);
    }

    setTimeout(() => {
        proposalMap.invalidateSize();
        renderProposalLayers();
    }, 120);
}

function closeProposalMap() {
    const modal = document.getElementById('mapModalBackdrop');
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

fillUserFilter();

const userFilter = document.getElementById('mapUserFilter');
if (userFilter) {
    userFilter.addEventListener('change', function () {
        if (proposalMap) {
            renderProposalLayers();
        }
    });
}

const closeMapButton = document.getElementById('btnCloseMap');
if (closeMapButton) {
    closeMapButton.addEventListener('click', closeProposalMap);
}

const mapModalBackdrop = document.getElementById('mapModalBackdrop');
if (mapModalBackdrop) {
    mapModalBackdrop.addEventListener('click', function (event) {
        if (event.target === mapModalBackdrop) {
            closeProposalMap();
        }
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeProposalMap();
    }
});

const mapButton = document.getElementById('btnShowMap');
if (mapButton) {
    mapButton.addEventListener('click', openProposalMap);
}
</script>
<?php endif; ?>
</body>
</html>

<?php
declare(strict_types=1);
// ajax_resumen.php — KPIs + filas (una por material) de una campaña por etapas.
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesión expirada']);
    exit;
}

$conn->set_charset('utf8mb4');
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$division_id = (int)($_SESSION['division_id'] ?? 0);
$isMC = ($division_id === 1); // MC ve cualquier empresa
$idForm     = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;

if ($idForm <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Falta id_formulario']);
    exit;
}

// Validar que la campaña es por etapas y (salvo MC) de la empresa de la sesión (IDOR-safe).
$sqlF = "SELECT nombre, id_division FROM formulario WHERE id = ? AND modalidad = 'implementacion_por_etapas' "
      . ($isMC ? "" : "AND id_empresa = ? ") . "LIMIT 1";
$stmtF = $conn->prepare($sqlF);
if ($isMC) { $stmtF->bind_param('i', $idForm); }
else       { $stmtF->bind_param('ii', $idForm, $empresa_id); }
$stmtF->execute();
$campania = $stmtF->get_result()->fetch_assoc();
$stmtF->close();

if (!$campania) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Campaña no encontrada o no es por etapas']);
    exit;
}

$sql = "
    SELECT
        fq.id                       AS idFQ,
        fq.id_local,
        fq.id_usuario,
        l.codigo                    AS codigo_local,
        l.nombre                    AS local_nombre,
        co.comuna                   AS comuna,
        l.lat                       AS lat,
        l.lng                       AS lng,
        fq.material,
        fq.categoria,
        fq.marca,
        u.usuario                   AS ejecutor,
        fq.etapa_material,
        fq.pregunta,
        CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED) AS propuesto,
        CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED)          AS implementado,
        fq.fechaVisita,
        fq.fechaPropuesta,
        fq.observacion,
        (SELECT COUNT(*) FROM fotoVisita fv
          WHERE fv.id_formularioQuestion = fq.id
            AND fv.kind IN ('armado','entregado','implementado','retirado')) AS n_fotos,
        (SELECT COUNT(*) FROM gestion_apoyo ga
          WHERE ga.id_formularioQuestion = fq.id) AS n_apoyos
    FROM formularioQuestion fq
    INNER JOIN local l         ON l.id = fq.id_local
    LEFT  JOIN comuna co       ON co.id = l.id_comuna
    LEFT  JOIN usuario u       ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
    ORDER BY l.codigo ASC, fq.material ASC
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Error SQL: ' . $conn->error]);
    exit;
}
$stmt->bind_param('i', $idForm);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$kpi = [
    'materiales'    => 0,
    'locales'       => [],
    'armados'       => 0,
    'entregados'    => 0,
    'implementados' => 0,
    'retirados'     => 0,
    'parciales'     => 0,
    'sin_iniciar'   => 0,
    'visita_pendiente' => 0,
    'cancelados'    => 0,
    'apoyos'        => 0,
    'uds_prop'      => 0,
    'uds_impl'      => 0,
];
$etapaLabels = ['armado' => 'Armado', 'entregado' => 'Entregado', 'implementado' => 'Implementado', 'retirado' => 'Retirado'];

while ($r = $res->fetch_assoc()) {
    $etapa = (string)($r['etapa_material'] ?? '');
    $prop  = (int)$r['propuesto'];
    $impl  = (int)$r['implementado'];
    $falt  = max(0, $prop - $impl);

    // Estado (columna tabla) + tipificación KPI por etapa.
    if ($etapa === 'retirado') {
        $estado = 'Completo';      $kpi['retirados']++;
    } elseif ($etapa === 'implementado' && $impl >= $prop && $prop > 0) {
        $estado = 'Completo';      $kpi['implementados']++;
    } elseif ($etapa === 'implementado' && $impl < $prop) {
        $estado = 'Parcial';       $kpi['parciales']++;
    } elseif ($etapa === 'armado') {
        $estado = 'En proceso';    $kpi['armados']++;
    } elseif ($etapa === 'entregado') {
        $estado = 'En proceso';    $kpi['entregados']++;
    } else {
        // Sin etapa registrada: distinguir visitas que quedaron Pendiente/Cancelado.
        // El flujo general de estado (procesar_gestion_pruebas.php) escribe
        // pregunta='en proceso' (pendiente) o 'cancelado' SIN tocar etapa_material,
        // por lo que antes estas salas visitadas aparecían como "Sin iniciar".
        $preg = strtolower(trim((string)($r['pregunta'] ?? '')));
        if ($preg === 'cancelado') {
            $estado = 'Cancelado';            $kpi['cancelados']++;
        } elseif ($preg === 'en proceso') {
            $estado = 'Pendiente (visitado)'; $kpi['visita_pendiente']++;
        } else {
            $estado = 'Sin iniciar';
        }
        $kpi['sin_iniciar']++; // los 3 casos siguen contando como no-iniciados para el KPI Pendientes
    }

    $r['propuesto']      = $prop;
    $r['implementado']   = $impl;
    $r['faltante']       = $falt;
    $r['etapa_label']    = $etapa !== '' ? ($etapaLabels[$etapa] ?? $etapa) : 'Sin iniciar';
    $r['estado']         = $estado;
    $r['n_fotos']        = (int)$r['n_fotos'];
    $r['n_apoyos']       = (int)$r['n_apoyos'];

    $kpi['materiales']++;
    $kpi['locales'][(int)$r['id_local']] = true;
    $kpi['apoyos']   += (int)$r['n_apoyos'];
    $kpi['uds_prop'] += $prop;
    $kpi['uds_impl'] += min($impl, $prop);

    $rows[] = $r;
}
$stmt->close();

$completos  = $kpi['implementados'] + $kpi['retirados'];
$pendientes = $kpi['armados'] + $kpi['entregados'] + $kpi['parciales'] + $kpi['sin_iniciar'];

// KPI: fotos tomadas fuera del local (>RADIO m). MySQL 8 → ST_Distance_Sphere.
$RADIO_OK_METROS = 200;
$fotosFuera = 0;
try {
    $stmtFF = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM fotoVisita fv
        INNER JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
        INNER JOIN local l ON l.id = fq.id_local
        WHERE fv.id_formulario = ?
          AND fv.kind IN ('armado','entregado','implementado','retirado')
          AND fv.fotoLat IS NOT NULL AND fv.fotoLat <> 0
          AND fv.fotoLng IS NOT NULL AND fv.fotoLng <> 0
          AND l.lat IS NOT NULL AND l.lat <> 0
          AND l.lng IS NOT NULL AND l.lng <> 0
          AND ST_Distance_Sphere(POINT(fv.fotoLng, fv.fotoLat), POINT(l.lng, l.lat)) > ?
    ");
    if ($stmtFF) {
        $stmtFF->bind_param('ii', $idForm, $RADIO_OK_METROS);
        $stmtFF->execute();
        $rowFF = $stmtFF->get_result()->fetch_assoc();
        $fotosFuera = (int)($rowFF['c'] ?? 0);
        $stmtFF->close();
    }
} catch (Throwable $e) {
    $fotosFuera = 0; // si la BBDD no soporta ST_Distance_Sphere, no rompe el resumen
}

// KPI: fotos marcadas como posible duplicada (dup_flag=1). Tolerante si la columna no existe aún
// (BBDD sin la migración 12_fotos_duplicadas.sql).
$fotosDup = 0;
try {
    $stmtFD = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM fotoVisita fv
        WHERE fv.id_formulario = ?
          AND fv.dup_flag = 1
          AND fv.kind IN ('armado','entregado','implementado','retirado')
    ");
    if ($stmtFD) {
        $stmtFD->bind_param('i', $idForm);
        $stmtFD->execute();
        $rowFD = $stmtFD->get_result()->fetch_assoc();
        $fotosDup = (int)($rowFD['c'] ?? 0);
        $stmtFD->close();
    }
} catch (Throwable $e) {
    $fotosDup = 0;
}

echo json_encode([
    'ok'       => true,
    'campania' => ['id' => $idForm, 'nombre' => $campania['nombre']],
    'kpis'     => [
        'materiales'    => $kpi['materiales'],
        'locales'       => count($kpi['locales']),
        'armados'       => $kpi['armados'],
        'entregados'    => $kpi['entregados'],
        'implementados' => $kpi['implementados'],
        'retirados'     => $kpi['retirados'],
        'parciales'     => $kpi['parciales'],
        'pendientes'    => $pendientes,
        'visita_pendiente' => $kpi['visita_pendiente'],
        'cancelados'    => $kpi['cancelados'],
        'apoyos'        => $kpi['apoyos'],
        'uds_prop'      => $kpi['uds_prop'],
        'uds_impl'      => $kpi['uds_impl'],
        'pct_unidades'  => $kpi['uds_prop'] > 0 ? round($kpi['uds_impl'] * 100 / $kpi['uds_prop']) : 0,
        'fotos_fuera'   => $fotosFuera,
        'fotos_duplicadas' => $fotosDup,
        'pct'           => $kpi['materiales'] > 0 ? round($completos * 100 / $kpi['materiales']) : 0,
    ],
    'data'     => $rows,
], JSON_UNESCAPED_UNICODE);

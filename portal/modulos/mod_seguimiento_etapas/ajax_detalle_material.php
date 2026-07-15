<?php
declare(strict_types=1);
// ajax_detalle_material.php — detalle de un material (timeline, fotos por etapa, apoyos).
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
$idFQ       = isset($_GET['idFQ']) ? (int)$_GET['idFQ'] : 0;

if ($idFQ <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Falta idFQ']);
    exit;
}

function normFoto(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $url = ltrim(str_replace('\\', '/', $url), '/');
    return 'https://www.visibility.cl/visibility2/app/' . $url;
}

// Distancia en metros (haversine). Devuelve null si falta alguna coord o son ~0,0.
function distanciaMetros($lat1, $lng1, $lat2, $lng2): ?float {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
    $lat1 = (float)$lat1; $lng1 = (float)$lng1; $lat2 = (float)$lat2; $lng2 = (float)$lng2;
    if ((abs($lat1) < 0.0001 && abs($lng1) < 0.0001) || (abs($lat2) < 0.0001 && abs($lng2) < 0.0001)) return null;
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
$RADIO_OK_METROS = 200; // radio aceptable foto↔local

// Validar pertenencia a la empresa.
$stmtFQ = $conn->prepare("
    SELECT fq.id, fq.id_local, fq.id_formulario, fq.material, fq.categoria, fq.marca, fq.etapa_material,
           CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED) AS propuesto,
           CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED)          AS implementado,
           fq.observacion, l.codigo AS codigo_local, l.nombre AS local_nombre,
           l.lat AS local_lat, l.lng AS local_lng,
           u.usuario AS ejecutor
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN local l      ON l.id = fq.id_local
    LEFT  JOIN usuario u    ON u.id = fq.id_usuario
    WHERE fq.id = ? AND f.modalidad = 'implementacion_por_etapas'
      " . ($isMC ? "" : "AND f.id_empresa = ?") . "
    LIMIT 1
");
if ($isMC) { $stmtFQ->bind_param('i', $idFQ); }
else       { $stmtFQ->bind_param('ii', $idFQ, $empresa_id); }
$stmtFQ->execute();
$fq = $stmtFQ->get_result()->fetch_assoc();
$stmtFQ->close();

if (!$fq) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Material no encontrado']);
    exit;
}

// --- Fotos por etapa ---
$fotosPorEtapa = ['armado' => [], 'entregado' => [], 'implementado' => [], 'retirado' => []];
// dup_flag puede no existir aún (BBDD sin la migración 12_fotos_duplicadas.sql) → seleccionar 0.
$fvHasDup = false;
try { $cdup = $conn->query("SHOW COLUMNS FROM fotoVisita LIKE 'dup_flag'"); $fvHasDup = ($cdup && $cdup->num_rows > 0); if ($cdup) { $cdup->close(); } } catch (Throwable $e) { $fvHasDup = false; }
$dupCol = $fvHasDup ? 'dup_flag' : '0 AS dup_flag';
$stmtFoto = $conn->prepare("
    SELECT id, url, kind, fotoLat, fotoLng, exif_lat, exif_lng, exif_datetime, $dupCol
    FROM fotoVisita
    WHERE id_formularioQuestion = ?
      AND kind IN ('armado','entregado','implementado','retirado')
    ORDER BY id ASC
");
$stmtFoto->bind_param('i', $idFQ);
$stmtFoto->execute();
$resFoto = $stmtFoto->get_result();
$localLat = $fq['local_lat'] ?? null;
$localLng = $fq['local_lng'] ?? null;
while ($f = $resFoto->fetch_assoc()) {
    $kind = (string)$f['kind'];
    if (!isset($fotosPorEtapa[$kind])) continue;
    // Coords de la foto: preferir fotoLat/Lng; si no, EXIF.
    $flat = $f['fotoLat']; $flng = $f['fotoLng'];
    if (($flat === null || (float)$flat == 0.0) && $f['exif_lat'] !== null) {
        $flat = $f['exif_lat']; $flng = $f['exif_lng'];
    }
    $dist = distanciaMetros($flat, $flng, $localLat, $localLng);
    $fotosPorEtapa[$kind][] = [
        'id'  => (int)$f['id'],
        'url' => normFoto((string)$f['url']),
        'lat' => $f['fotoLat'],
        'lng' => $f['fotoLng'],
        'fecha' => $f['exif_datetime'],
        'dist_m' => $dist !== null ? (int)round($dist) : null,
        'fuera_rango' => ($dist !== null && $dist > $RADIO_OK_METROS),
        'dup_flag' => (int)($f['dup_flag'] ?? 0),
    ];
}
$stmtFoto->close();

// --- Apoyos ---
$apoyos = [];
$stmtAp = $conn->prepare("
    SELECT ga.id, ga.id_usuario_apoyo, ga.etapa, ga.created_at,
           TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS nombre,
           u.usuario
    FROM gestion_apoyo ga
    INNER JOIN usuario u ON u.id = ga.id_usuario_apoyo
    WHERE ga.id_formularioQuestion = ?
    ORDER BY ga.created_at ASC
");
$stmtAp->bind_param('i', $idFQ);
$stmtAp->execute();
$resAp = $stmtAp->get_result();
while ($a = $resAp->fetch_assoc()) {
    $apoyos[] = [
        'id'        => (int)$a['id'],
        'id_usuario'=> (int)$a['id_usuario_apoyo'],
        'etapa'     => (string)$a['etapa'],
        'fecha'     => (string)$a['created_at'],
        'nombre'    => trim($a['nombre']) !== '' ? trim($a['nombre']) : (string)$a['usuario'],
    ];
}
$stmtAp->close();

// --- Timeline (gestion_visita) ---
$timeline = [];
$stmtTl = $conn->prepare("
    SELECT gv.id, gv.estado_gestion, gv.valor_real, gv.motivo_no_implementacion,
           gv.observacion, gv.fecha_visita, gv.created_at,
           u.usuario AS ejecutor
    FROM gestion_visita gv
    LEFT JOIN usuario u ON u.id = gv.id_usuario
    WHERE gv.id_formularioQuestion = ?
    ORDER BY gv.fecha_visita ASC, gv.id ASC
");
$stmtTl->bind_param('i', $idFQ);
$stmtTl->execute();
$resTl = $stmtTl->get_result();
while ($t = $resTl->fetch_assoc()) {
    $timeline[] = [
        'id'        => (int)$t['id'],
        'etapa'     => (string)$t['estado_gestion'],
        'valor'     => $t['valor_real'],
        'motivo'    => (string)($t['motivo_no_implementacion'] ?? ''),
        'observacion' => (string)($t['observacion'] ?? ''),
        'fecha'     => (string)($t['fecha_visita'] ?? ''),
        'ejecutor'  => (string)($t['ejecutor'] ?? ''),
    ];
}
$stmtTl->close();

$fq['propuesto']    = (int)$fq['propuesto'];
$fq['implementado'] = (int)$fq['implementado'];
$fq['faltante']     = max(0, $fq['propuesto'] - $fq['implementado']);

echo json_encode([
    'ok'             => true,
    'material'       => $fq,
    'fotos_por_etapa'=> $fotosPorEtapa,
    'apoyos'         => $apoyos,
    'timeline'       => $timeline,
], JSON_UNESCAPED_UNICODE);

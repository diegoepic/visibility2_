<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sin sesión.']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['ok' => false, 'msg' => 'Sin conexión.']);
    exit;
}
$conn->set_charset('utf8mb4');
$usuarioId = (int)$_SESSION['usuario_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

// ─── crear ──────────────────────────────────────────────────
if ($action === 'crear') {
    $idLocal  = (int)($_POST['id_local'] ?? 0);
    $dirNueva = trim($_POST['dir_nueva'] ?? '');
    $latNueva = (float)($_POST['lat_nueva'] ?? 0);
    $lngNueva = (float)($_POST['lng_nueva'] ?? 0);

    if ($idLocal <= 0 || $dirNueva === '') {
        echo json_encode(['ok' => false, 'msg' => 'Datos incompletos.']);
        exit;
    }

    // Snapshot del local actual
    $stmtL = $conn->prepare("SELECT direccion, lat, lng FROM local WHERE id = ? AND deleted_at IS NULL");
    $stmtL->bind_param('i', $idLocal);
    $stmtL->execute();
    $localRow = $stmtL->get_result()->fetch_assoc();
    $stmtL->close();

    if (!$localRow) {
        echo json_encode(['ok' => false, 'msg' => 'Local no encontrado.']);
        exit;
    }

    $dirAnterior = (string)($localRow['direccion'] ?? '');
    $latAnterior = (float)($localRow['lat'] ?? 0);
    $lngAnterior = (float)($localRow['lng'] ?? 0);
    $distanciaKm = haversineKm($latAnterior, $lngAnterior, $latNueva, $lngNueva);

    // Evaluación de reglas de sospecha
    $sospechoso = 0;
    $motivos    = [];

    // R1: más de 5 solicitudes del mismo usuario en la última hora
    $s1 = $conn->prepare("SELECT COUNT(*) AS cnt FROM solicitud_cambio_local WHERE id_usuario=? AND created_at >= NOW()-INTERVAL 1 HOUR AND deleted_at IS NULL");
    $s1->bind_param('i', $usuarioId);
    $s1->execute();
    if ((int)$s1->get_result()->fetch_assoc()['cnt'] > 5) {
        $sospechoso = 1; $motivos[] = 'Más de 5 cambios en 1 hora';
    }
    $s1->close();

    // R2: distancia > 5 km
    if ($distanciaKm > 5.0) {
        $sospechoso = 1; $motivos[] = sprintf('Distancia inusual: %.1f km', $distanciaKm);
    }

    // R3: > 3 usuarios distintos al mismo local en las últimas 24h
    $s3 = $conn->prepare("SELECT COUNT(DISTINCT id_usuario) AS cnt FROM solicitud_cambio_local WHERE id_local=? AND created_at >= NOW()-INTERVAL 24 HOUR AND deleted_at IS NULL");
    $s3->bind_param('i', $idLocal);
    $s3->execute();
    if ((int)$s3->get_result()->fetch_assoc()['cnt'] >= 3) {
        $sospechoso = 1; $motivos[] = 'Local modificado por +3 usuarios en 24h';
    }
    $s3->close();

    // R4: coordenadas fuera del bounding box de Chile
    if ($latNueva < -56.0 || $latNueva > -17.5 || $lngNueva < -76.0 || $lngNueva > -65.0) {
        $sospechoso = 1; $motivos[] = 'Coordenadas fuera de Chile';
    }

    $motivoStr = count($motivos) ? implode('; ', $motivos) : null;

    // Convertir floats a strings para bind 's' (MySQL auto-castea DECIMAL)
    $distStr   = (string)round($distanciaKm, 3);
    $latAntStr = (string)$latAnterior;
    $lngAntStr = (string)$lngAnterior;
    $latNvaStr = (string)$latNueva;
    $lngNvaStr = (string)$lngNueva;

    // ¿Existe ya una solicitud pendiente de este usuario para este local?
    $stmtEx = $conn->prepare("SELECT id FROM solicitud_cambio_local WHERE id_local=? AND id_usuario=? AND estado='pendiente' AND deleted_at IS NULL LIMIT 1");
    $stmtEx->bind_param('ii', $idLocal, $usuarioId);
    $stmtEx->execute();
    $existing = $stmtEx->get_result()->fetch_assoc();
    $stmtEx->close();

    if ($existing) {
        // UPDATE: reemplaza la propuesta anterior sin crear un duplicado
        $stmtU = $conn->prepare("
            UPDATE solicitud_cambio_local
               SET dir_nueva=?, lat_nueva=?, lng_nueva=?,
                   dir_anterior=?, lat_anterior=?, lng_anterior=?,
                   distancia_km=?, sospechoso=?, motivo_sospecha=?, created_at=NOW()
             WHERE id=?
        ");
        // types: s s s s s s s i s i
        $stmtU->bind_param('sssssssisi',
            $dirNueva, $latNvaStr, $lngNvaStr,
            $dirAnterior, $latAntStr, $lngAntStr,
            $distStr, $sospechoso, $motivoStr, $existing['id']
        );
        $stmtU->execute();
        $stmtU->close();
    } else {
        // INSERT: nueva solicitud
        // types: i i s s s s s s s i s  (11 params)
        $stmtI = $conn->prepare("
            INSERT INTO solicitud_cambio_local
                (id_local, id_usuario, dir_anterior, lat_anterior, lng_anterior,
                 dir_nueva, lat_nueva, lng_nueva, distancia_km, sospechoso, motivo_sospecha)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmtI->bind_param('iisssssssis',
            $idLocal, $usuarioId,
            $dirAnterior, $latAntStr, $lngAntStr,
            $dirNueva, $latNvaStr, $lngNvaStr,
            $distStr, $sospechoso, $motivoStr
        );
        $stmtI->execute();
        $stmtI->close();
    }

    echo json_encode(['ok' => true, 'sospechoso' => (bool)$sospechoso]);
    exit;
}

// ─── mis_pendientes ──────────────────────────────────────────
if ($action === 'mis_pendientes') {
    $stmt = $conn->prepare("SELECT id_local FROM solicitud_cambio_local WHERE id_usuario=? AND estado='pendiente' AND deleted_at IS NULL");
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['ok' => true, 'data' => array_map(fn($r) => (int)$r['id_local'], $rows)]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida.']);

<?php
declare(strict_types=1);
/**
 * Guarda la ruta que el ejecutor acaba de planificar (hallazgo C-2).
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * Hoy la ruta se calcula en el telefono, se dibuja y se tira. Nadie puede comparar despues
 * lo planificado con lo que realmente paso, que es justo lo que hace falta para la
 * estimacion de cierre de jornada, para el cierre verificado (B-1) y para meter prioridad y
 * ventanas horarias en la funcion de costo (V-28). Las tablas `ruta` y `ruta_parada` ya
 * existian, bien disenadas, con 2 filas de un experimento del 2025 y ningun escritor.
 *
 * ESTO ES DATO DERIVADO, NO TRABAJO DE CAMPO
 * ------------------------------------------
 * De ahi salen casi todas las decisiones de este archivo:
 *
 *   * NO pasa por la cola offline. La cola es para lo que no se puede perder (gestiones,
 *     fotos) y su contador es algo que el ejecutor mira. Un plan perdido no rompe nada: el
 *     siguiente recalculo lo vuelve a escribir. Meterlo en la cola ensuciaria el contador
 *     con trabajo que a nadie le importa si se pierde.
 *   * El limitador falla ABIERTO y cualquier error responde 200. Que no se pueda guardar el
 *     plan jamas puede afectar a que el ejecutor vea su ruta.
 *   * Si la migracion scripts/38 no esta aplicada responde ok con guardado=false, para que
 *     el cliente no reintente ni avise de nada.
 *
 * QUE SE PRESERVA AL REPLANIFICAR
 * -------------------------------
 * `ruta_parada` tiene UNIQUE(ruta_id, seq) y el orden cambia en cada recalculo, asi que las
 * paradas se rehacen enteras. Lo que NO se puede perder al rehacerlas es lo que ya paso en
 * el terreno: status, visited_at y nota. Se leen antes de borrar y se reaplican por
 * local_id. Sin esto, un recalculo a media jornada borraria las visitas ya marcadas.
 *
 * Por lo mismo `ruta.estado` nunca retrocede: una ruta 'en_progreso' o 'finalizada' no
 * vuelve a 'planeada' porque el ejecutor haya reordenado sus paradas.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/** Respuesta uniforme. Siempre 200 salvo sesion ausente: ver la nota de arriba. */
function rp_salir(bool $ok, bool $guardado, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'guardado' => $guardado, 'msg' => $msg], $extra));
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    rp_salir(false, false, 'Sin sesión.');
}
$usuarioId = (int)$_SESSION['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    rp_salir(false, false, 'Sólo POST.');
}

require_once __DIR__ . '/../lib/csrf.php';
try {
    csrf_validate();
} catch (Throwable $e) {
    http_response_code(403);
    rp_salir(false, false, 'CSRF inválido.');
}

require_once __DIR__ . '/../con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    rp_salir(true, false, 'Sin conexión.');
}
$conn->set_charset('utf8mb4');

/* Tope generoso: planificar dispara como mucho una escritura por cambio real de estado de
   ruta (el cliente ya filtra por routeStateHash). Falla ABIERTO a proposito. */
require_once __DIR__ . '/../lib/rate_limiter.php';
if (function_exists('rate_limit_check')
    && !rate_limit_check($conn, 'ruta_plan_guardar', $usuarioId, 60, 60, false)) {
    rp_salir(true, false, 'Demasiadas peticiones.');
}

/* ── Guarda de migración ────────────────────────────────────────────────────────────
   Sin scripts/38 no hay columna `modo`, y sin ella no se puede distinguir la ruta de
   programados de la de reagendados del mismo día. Se responde ok para que el cliente
   calle, no para fingir que se guardó: `guardado` va en false. */
$hayTabla = $conn->query("SHOW TABLES LIKE 'ruta_parada'");
if (!$hayTabla || $hayTabla->num_rows === 0) {
    rp_salir(true, false, 'Tablas de ruta no disponibles.');
}
$hayModo = $conn->query("SHOW COLUMNS FROM `ruta` LIKE 'modo'");
if (!$hayModo || $hayModo->num_rows === 0) {
    rp_salir(true, false, 'Migración scripts/38 pendiente.');
}

/* ── Validación de la entrada ───────────────────────────────────────────────────── */
$fecha = trim((string)($_POST['fecha'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    rp_salir(true, false, 'Fecha inválida.');
}
$modo = ($_POST['modo'] ?? '') === 'reag' ? 'reag' : 'prog';

$origenLat = isset($_POST['origen_lat']) && is_numeric($_POST['origen_lat']) ? (float)$_POST['origen_lat'] : null;
$origenLng = isset($_POST['origen_lng']) && is_numeric($_POST['origen_lng']) ? (float)$_POST['origen_lng'] : null;

$crudas = json_decode((string)($_POST['paradas'] ?? ''), true);
if (!is_array($crudas) || !$crudas) {
    rp_salir(true, false, 'Sin paradas.');
}
if (count($crudas) > 300) {
    rp_salir(true, false, 'Demasiadas paradas.');
}

$paradas = [];
$vistos  = [];
foreach ($crudas as $p) {
    if (!is_array($p)) { continue; }
    $idLocal = (int)($p['local_id'] ?? 0);
    $lat     = isset($p['lat']) && is_numeric($p['lat']) ? (float)$p['lat'] : null;
    $lng     = isset($p['lng']) && is_numeric($p['lng']) ? (float)$p['lng'] : null;
    // Sin coordenadas la parada no sirve para nada de lo que vendrá después (eta, distancia).
    if ($idLocal <= 0 || $lat === null || $lng === null) { continue; }
    if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) { continue; }
    if (isset($vistos[$idLocal])) { continue; }   // un local, una parada
    $vistos[$idLocal] = true;
    $paradas[] = ['local_id' => $idLocal, 'lat' => $lat, 'lng' => $lng];
}
if (!$paradas) {
    rp_salir(true, false, 'Ninguna parada válida.');
}

/* ── Autorización: los locales tienen que estar asignados a QUIEN PIDE ───────────────
   El usuario sale de la sesión, nunca del cuerpo. Una sola consulta para todos los ids;
   los que no le correspondan se descartan en silencio en vez de rechazar la petición
   entera, porque esto es dato derivado y una parada de más no justifica perder el plan. */
$ids   = array_column($paradas, 'local_id');
$marca = implode(',', array_fill(0, count($ids), '?'));
$tipos = str_repeat('i', count($ids) + 1);   // usuario + un id por parada
$stmt  = $conn->prepare(
    "SELECT DISTINCT fq.id_local
       FROM formularioQuestion fq
      WHERE fq.id_usuario = ?
        AND fq.id_local IN ($marca)"
);
if (!$stmt) {
    rp_salir(true, false, 'No se pudo validar.');
}
$args = array_merge([$usuarioId], $ids);
$stmt->bind_param($tipos, ...$args);
$stmt->execute();
$res = $stmt->get_result();
$propios = [];
while ($fila = $res->fetch_assoc()) { $propios[(int)$fila['id_local']] = true; }
$stmt->close();

$paradas = array_values(array_filter($paradas, static fn($p) => isset($propios[$p['local_id']])));
$descartadas = count($ids) - count($paradas);
if (!$paradas) {
    rp_salir(true, false, 'Ninguna parada asignada a este usuario.');
}

/* ── Escritura ──────────────────────────────────────────────────────────────────── */
$conn->begin_transaction();
try {
    // 1. La ruta del día. Una por (usuario, fecha, modo) — lo garantiza la clave única de
    //    scripts/38; el SELECT ... FOR UPDATE evita la carrera mientras no esté aplicada.
    $st = $conn->prepare("SELECT id, estado FROM ruta
                           WHERE usuario_id = ? AND fecha = ? AND modo = ? FOR UPDATE");
    $st->bind_param('iss', $usuarioId, $fecha, $modo);
    $st->execute();
    $ruta = $st->get_result()->fetch_assoc();
    $st->close();

    if ($ruta) {
        $rutaId = (int)$ruta['id'];
        // El estado NO retrocede: replanificar no deshace una jornada empezada o cerrada.
        $st = $conn->prepare("UPDATE ruta SET origen_lat = ?, origen_lng = ? WHERE id = ?");
        $st->bind_param('ddi', $origenLat, $origenLng, $rutaId);
        $st->execute();
        $st->close();
    } else {
        $st = $conn->prepare("INSERT INTO ruta (usuario_id, fecha, modo, origen_lat, origen_lng, estado)
                              VALUES (?, ?, ?, ?, ?, 'planeada')");
        $st->bind_param('issdd', $usuarioId, $fecha, $modo, $origenLat, $origenLng);
        $st->execute();
        $rutaId = (int)$conn->insert_id;
        $st->close();
    }

    // 2. Lo que ya pasó en el terreno, antes de rehacer las paradas.
    $previo = [];
    $st = $conn->prepare("SELECT local_id, status, visited_at, nota FROM ruta_parada WHERE ruta_id = ?");
    $st->bind_param('i', $rutaId);
    $st->execute();
    $r = $st->get_result();
    while ($f = $r->fetch_assoc()) { $previo[(int)$f['local_id']] = $f; }
    $st->close();

    // 3. Rehacer. UNIQUE(ruta_id, seq) obliga a borrar antes de insertar, porque el orden
    //    cambia en cada recálculo y un UPDATE parcial chocaría con la clave.
    $st = $conn->prepare("DELETE FROM ruta_parada WHERE ruta_id = ?");
    $st->bind_param('i', $rutaId);
    $st->execute();
    $st->close();

    $st = $conn->prepare(
        "INSERT INTO ruta_parada (ruta_id, local_id, seq, lat, lng, status, visited_at, nota)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $seq = 0;
    foreach ($paradas as $p) {
        $seq++;
        $idLocal   = $p['local_id'];
        $lat       = $p['lat'];
        $lng       = $p['lng'];
        $ant       = $previo[$idLocal] ?? null;
        $status    = $ant['status']     ?? 'pendiente';
        $visitedAt = $ant['visited_at'] ?? null;
        $nota      = $ant['nota']       ?? null;
        $st->bind_param('iiiddsss', $rutaId, $idLocal, $seq, $lat, $lng,
                        $status, $visitedAt, $nota);
        $st->execute();
    }
    $st->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[ruta_plan_guardar] ' . $e->getMessage());
    rp_salir(true, false, 'No se pudo guardar el plan.');
}

rp_salir(true, true, '', [
    'ruta_id'     => $rutaId,
    'paradas'     => count($paradas),
    'descartadas' => $descartadas,
]);

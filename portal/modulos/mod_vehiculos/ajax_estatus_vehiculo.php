<?php
declare(strict_types=1);

/**
 * ajax_estatus_vehiculo.php
 * -----------------------------------------------------------------------------
 * Versión EN PANTALLA del informe "Estatus del vehículo" (formulario 138).
 *
 * Mismo cálculo que el Excel: ambos usan informes/lib_estatus_vehiculo.php, así
 * que no pueden dar cifras distintas. Existe para que los coordinadores consulten
 * el dato en vivo en vez de trabajar todo el día sobre un Excel descargado en la
 * mañana, que a media tarde ya está desactualizado.
 *
 * Entrada  (GET/POST): start_date, end_date, feriados[]
 * Salida (JSON): { ok, meta, kpis, resumen[], matriz{}, detalle[], duplicadas[] }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
@set_time_limit(120);
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/informes/lib_estatus_vehiculo.php';

function ev_json_fail(string $msg, int $http = 200): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Seguridad ---- */
$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
    ev_json_fail('Sesión no válida.', 403);
}

/* ---- Entrada ---- */
$req        = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$start_date = trim((string)($req['start_date'] ?? ''));
$end_date   = trim((string)($req['end_date']   ?? ''));
$feriados   = array_values(array_filter(
    (array)($req['feriados'] ?? []),
    static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)
));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)   ||
    $start_date > $end_date) {
    ev_json_fail('Rango de fechas inválido.');
}

/* Tope de rango: la matriz genera una columna por día y arriba de ~3 meses
   deja de ser legible además de pesada. */
$dias = (int)(new DateTime($start_date))->diff(new DateTime($end_date))->days;
if ($dias > 120) {
    ev_json_fail('El rango no puede superar los 120 días. Acorta el período.');
}

/* La empresa debe tener acceso a la campaña 138 */
$formId  = EV_INFORME_FORMULARIO;
$stmtAcc = $conn->prepare("SELECT id FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1");
$stmtAcc->bind_param('ii', $formId, $empresa_id);
$stmtAcc->execute();
$stmtAcc->store_result();
$tieneAcceso = $stmtAcc->num_rows > 0;
$stmtAcc->close();
if (!$tieneAcceso) {
    ev_json_fail('No tienes acceso a este informe.', 403);
}

mysqli_set_charset($conn, 'utf8mb4');

/* ---- Cálculo (compartido con el Excel) ---- */
try {
    $data = ev_calcular_informe($conn, $empresa_id, $start_date, $end_date, $feriados);
} catch (Throwable $e) {
    error_log('[estatus_vehiculo] ' . $e->getMessage());
    ev_json_fail('Error al calcular el informe.');
}

$users      = $data['users'];
$userStats  = $data['userStats'];
$uploads    = $data['uploads'];
$vehInfo    = $data['vehicleInfo'];
$matrixCols = $data['matrixCols'];
$diasEs     = ev_diasEs();

/* ---- Resumen por ejecutor + KPIs ---- */
$resumen        = [];
$sumaPct        = 0.0;
$conDuplicados  = 0;
$sinNingunaSub  = 0;
$totalFotos     = 0;

foreach ($userStats as $uid => $st) {
    $u = $users[$uid];
    $v = $vehInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];
    $extra = ev_resumen_extra_usuario($data, (int)$uid, (string)$v['patente']);

    foreach ($uploads[$uid] ?? [] as $up) {
        $totalFotos += (int)$up['total'];
    }
    if (empty($uploads[$uid])) $sinNingunaSub++;
    if ($st['has_dups'])       $conDuplicados++;
    $sumaPct += (float)$st['pct'];

    $resumen[] = [
        'id'          => (int)$uid,
        'usuario'     => $u['usuario'],
        'rut'         => $u['rut'] ?: '—',
        'nombre'      => $u['nombre_completo'],
        'email'       => $u['email'],
        'patente'     => $v['patente'],
        'modelo'      => $v['modelo'],
        'division'    => $u['division'] ?: '—',
        'expected'    => (int)$st['expected'],
        'complied'    => (int)$st['complied'],
        'missed'      => (int)$st['missed'],
        'pct'         => (float)$st['pct'],
        'has_dups'    => (bool)$st['has_dups'],
        'km_restantes'          => $extra['km_restantes'],
        'dias_patente_distinta' => (int)$extra['dias_patente_distinta'],
    ];
}

$nUsers = count($userStats);
$kpis = [
    'ejecutores'        => $nUsers,
    'cumplimiento_prom' => $nUsers > 0 ? round($sumaPct / $nUsers, 1) : 0.0,
    'con_duplicados'    => $conDuplicados,
    'sin_subidas'       => $sinNingunaSub,
    'total_fotos'       => $totalFotos,
    'subidas_esperadas' => count($data['expectedDays']),
];

/* ---- Matriz de cumplimiento ---- */
$matriz = ['cols' => [], 'filas' => []];
foreach ($matrixCols as $mc) {
    $matriz['cols'][] = [
        'date'  => $mc['date'],
        'label' => str_replace("\n", ' · ', $mc['label']),
        'tipo'  => $mc['tipo'],
    ];
}
foreach ($userStats as $uid => $st) {
    $u = $users[$uid];
    $v = $vehInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];

    $celdas = [];
    foreach ($matrixCols as $mc) {
        $celdas[] = ev_cumpleCelda($uploads[$uid] ?? [], $mc);
    }

    $matriz['filas'][] = [
        'usuario'  => $u['usuario'],
        'nombre'   => $u['nombre_completo'],
        'patente'  => $v['patente'],
        'division' => $u['division'] ?: '—',
        'celdas'   => $celdas,
        'pct'      => (float)$st['pct'],
    ];
}

/* ---- Detalle de subidas ---- */
$detalle = [];
foreach ($data['allUploadRows'] as $row) {
    $uid = (int)$row['id_usuario'];
    if (!isset($users[$uid])) continue;

    $u    = $users[$uid];
    $v    = $vehInfo[$uid] ?? ['patente' => '—', 'modelo' => '—'];
    $date = (string)$row['fecha'];

    $patEnc = '';
    if ($data['qid_patente_encuesta'] !== null) {
        $ans = $data['textNumAnswers'][$uid][$date][$data['qid_patente_encuesta']] ?? null;
        if ($ans !== null) $patEnc = trim((string)($ans['answer_text'] ?? ''));
    }

    $coincide = null;   // null = no se puede comparar
    if ($patEnc !== '' && $v['patente'] !== '' && $v['patente'] !== '—') {
        $coincide = ev_normalizarPatente($patEnc) === ev_normalizarPatente((string)$v['patente']);
    }

    /* Respuestas texto/numéricas de ese día (kilometraje, etc.) */
    $respuestas = [];
    foreach ($data['nonPhotoQuestions'] as $qid => $qdef) {
        $ans = $data['textNumAnswers'][$uid][$date][$qid] ?? null;
        $val = '—';
        if ($ans !== null) {
            $txt = trim((string)$ans['answer_text']);
            if ($txt !== '')                     $val = $txt;
            elseif ($ans['valor'] !== null)      $val = (string)$ans['valor'];
        }
        $respuestas[] = ['pregunta' => $qdef['text'], 'valor' => $val];
    }

    $esperado = isset($data['expectedMap'][$date]);

    $detalle[] = [
        'usuario'       => $u['usuario'],
        'rut'           => $u['rut'] ?: '—',
        'nombre'        => $u['nombre_completo'],
        'fecha'         => ev_fmtDate($date),
        'fecha_iso'     => $date,
        'dia'           => $diasEs[(int)(new DateTime($date))->format('N')] ?? '?',
        'patente'       => $v['patente'],
        'division'      => $u['division'] ?: '—',
        'primera'       => ev_fmtDateTime($row['primera_hora']),
        'ultima'        => ev_fmtDateTime($row['ultima_hora']),
        'fotos'         => (int)$row['total_fotos'],
        'esperado'      => $esperado,
        'tipo'          => $esperado ? implode(' y ', $data['expectedMap'][$date]) : '—',
        'patente_ing'   => $patEnc !== '' ? $patEnc : '—',
        'coincide'      => $coincide,
        'respuestas'    => $respuestas,
    ];
}

/* ---- Fotos duplicadas ---- */
$duplicadas = [];
foreach ($data['duplicates'] as $d) {
    // La consulta de duplicadas no trae la división; se resuelve desde el
    // usuario ya cargado para que la columna exista en todas las pestañas.
    $uidDup = (int)($d['id_usuario'] ?? 0);

    $duplicadas[] = [
        'usuario'  => (string)$d['username'],
        'nombre'   => trim((string)$d['nombre_completo']),
        'division' => $users[$uidDup]['division'] ?? '—',
        'sha1'     => substr((string)$d['sha1'], 0, 7),
        'subidas'  => (int)$d['total_subidas'],
        'dias'     => (int)$d['dias_distintos'],
        'fechas'   => (string)$d['fechas'],
        'primera'  => ev_fmtDateTime($d['primera_subida']),
        'ultima'   => ev_fmtDateTime($d['ultima_subida']),
        'url'      => (string)$d['primera_url'],
    ];
}

$conn->close();

echo json_encode([
    'ok'   => true,
    'meta' => [
        'periodo'    => ev_fmtDate($start_date) . ' → ' . ev_fmtDate($end_date),
        'generado'   => (new DateTime())->format('d/m/Y H:i'),
        'modo_label' => 'Evaluación diaria (2 subidas por día hábil: mañana y tarde)',
        'feriados'   => array_map('ev_fmtDate', $feriados),
    ],
    'kpis'       => $kpis,
    'resumen'    => $resumen,
    'matriz'     => $matriz,
    'detalle'    => $detalle,
    'duplicadas' => $duplicadas,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

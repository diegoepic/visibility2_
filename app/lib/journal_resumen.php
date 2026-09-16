<?php
declare(strict_types=1);

/**
 * Receptor del resumen diario de la cola del teléfono.
 *
 * La app manda contadores —no la bitácora— cuando recupera conexión y cuando termina de
 * drenar. Con esto, la oficina puede responder "¿quién tiene trabajo sin subir?" el mismo
 * día, en vez de enterarse cuando alguien echa de menos las fotos.
 *
 * Deliberadamente NO guarda el contenido de los trabajos: no es un respaldo ni sirve para
 * recuperar nada. Sirve para saber a quién llamar.
 *
 * Requiere scripts/28_journal_resumen_diario.sql. Mientras no se haya corrido, responde
 * 'ok' con guardado=false en vez de reventar: el despliegue y la migración van separados
 * en este proyecto y el cliente no debe romperse por eso.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jr_salir(int $http, array $payload): void {
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jr_salir(405, ['ok' => false, 'error_code' => 'METHOD_NOT_ALLOWED', 'message' => 'Sólo POST.']);
}

if (empty($_SESSION['usuario_id'])) {
    jr_salir(401, ['ok' => false, 'error_code' => 'NO_SESSION', 'message' => 'Sesión no válida.']);
}

require_once __DIR__ . '/../con_.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    jr_salir(500, ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => 'Sin conexión a BD.']);
}
$conn->set_charset('utf8mb4');

// CSRF: mismo criterio que el resto de los endpoints del app.
$tokenEnviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
$tokenSesion  = $_SESSION['csrf_token'] ?? '';
if ($tokenSesion === '' || !hash_equals((string)$tokenSesion, (string)$tokenEnviado)) {
    jr_salir(419, ['ok' => false, 'error_code' => 'CSRF_INVALID', 'message' => 'Token inválido.']);
}

$user_id = (int)$_SESSION['usuario_id'];

// --- Entrada -----------------------------------------------------------------
$hoy = (new DateTime('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
$ymd = trim((string)($_POST['ymd'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
    $ymd = $hoy;
}
// El cliente no decide el pasado ni el futuro: sólo puede reportar sobre hoy o ayer (una
// jornada que cruza medianoche). Cualquier otra fecha se normaliza a hoy.
$ayer = (new DateTime($hoy, new DateTimeZone('America/Santiago')))->modify('-1 day')->format('Y-m-d');
if ($ymd !== $hoy && $ymd !== $ayer) {
    $ymd = $hoy;
}

$acotar = static function ($v): int {
    $n = (int)$v;
    if ($n < 0) return 0;
    return $n > 100000 ? 100000 : $n;   // techo defensivo: es un contador, no un total real
};

$pendientes = $acotar($_POST['pendientes'] ?? 0);
$errores    = $acotar($_POST['errores']    ?? 0);
$subidas    = $acotar($_POST['subidas']    ?? 0);
$bloqueados = $acotar($_POST['bloqueados'] ?? 0);

$ultimoCode = trim((string)($_POST['ultimo_error_code'] ?? ''));
$ultimoCode = $ultimoCode !== '' ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $ultimoCode), 0, 64) : null;

$appVersion = trim((string)($_POST['app_version'] ?? ''));
$appVersion = $appVersion !== '' ? substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', $appVersion), 0, 32) : null;

$online = !empty($_POST['online']) ? 1 : 0;

// --- Guardado ----------------------------------------------------------------
// Guarda de tabla: el despliegue puede ir por delante de la migración.
$existe = false;
try {
    $res = $conn->query("SHOW TABLES LIKE 'journal_resumen_diario'");
    $existe = ($res && $res->num_rows > 0);
    if ($res) $res->free();
} catch (Throwable $e) {
    $existe = false;
}

if (!$existe) {
    jr_salir(200, [
        'ok'       => true,
        'guardado' => false,
        'message'  => 'Falta correr scripts/28_journal_resumen_diario.sql.'
    ]);
}

$sql = "
    INSERT INTO journal_resumen_diario
        (user_id, ymd, pendientes, errores, subidas, bloqueados,
         ultimo_error_code, app_version, online, actualizado_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        pendientes        = VALUES(pendientes),
        errores           = VALUES(errores),
        -- 'subidas' sólo crece: el cliente reporta el acumulado del día, y si por lo que
        -- sea manda un número menor (cambió de dispositivo, se limpió la bitácora) no se
        -- pierde lo que ya se sabía.
        subidas           = GREATEST(subidas, VALUES(subidas)),
        bloqueados        = VALUES(bloqueados),
        ultimo_error_code = VALUES(ultimo_error_code),
        app_version       = VALUES(app_version),
        online            = VALUES(online),
        actualizado_at    = NOW()
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('journal_resumen: prepare falló: ' . $conn->error);
    jr_salir(500, ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => 'No se pudo guardar el resumen.']);
}

$stmt->bind_param(
    'isiiiissi',
    $user_id, $ymd, $pendientes, $errores, $subidas, $bloqueados,
    $ultimoCode, $appVersion, $online
);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    error_log('journal_resumen: execute falló: ' . $err);
    jr_salir(500, ['ok' => false, 'error_code' => 'INTERNAL_ERROR', 'message' => 'No se pudo guardar el resumen.']);
}
$stmt->close();

jr_salir(200, ['ok' => true, 'guardado' => true, 'ymd' => $ymd]);

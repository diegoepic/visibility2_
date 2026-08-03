<?php
/**
 * odometro_read.php — Lectura automática del kilometraje desde la foto del odómetro.
 *
 * Por qué existe separado del upload: la subida de la foto (procesar_pregunta_fotoIW.php)
 * debe seguir siendo rápida y no puede fallar por culpa de la IA. Acá el cliente llama
 * DESPUÉS de que la foto ya quedó guardada; si esto falla, la encuesta sigue su curso y
 * el ejecutor escribe el kilometraje a mano.
 *
 * La API key nunca sale del servidor (mismo criterio que api/route_compute.php).
 *
 * Entrada  (POST): csrf_token, resp_id, visita_id
 * Salida (JSON):   { ok, status, km, confianza, intentos_restantes, alerta, message }
 *   status: leido | dudosa | ilegible | sin_intentos | no_disponible | error
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$APP_DIR = dirname(__DIR__);

require_once $APP_DIR . '/con_.php';
require_once $APP_DIR . '/lib/encuesta_vehiculo.php';
require_once $APP_DIR . '/lib/odometro_ia.php';
require_once $APP_DIR . '/lib/rate_limiter.php';

/** Respuesta uniforme. Nunca 5xx: el cliente siempre debe poder caer a manual. */
function odo_out(array $extra, int $http = 200): void {
    $GLOBALS['odo_respondido'] = true;
    http_response_code($http);
    $json = json_encode(array_merge([
        'ok' => false, 'status' => 'error', 'km' => null, 'confianza' => null,
        'tipo_odometro' => null, 'intentos_restantes' => 0, 'alerta' => null,
        'transitorio' => false, 'message' => '',
    ], $extra), JSON_UNESCAPED_UNICODE);

    // Si json_encode falla (p.ej. texto con UTF-8 inválido devuelto por la API),
    // se responde igual algo parseable en vez de un cuerpo vacío.
    if ($json === false) {
        $json = '{"ok":false,"status":"error","intentos_restantes":0,'
              . '"message":"Respuesta no codificable.","detalle":'
              . json_encode(json_last_error_msg()) . '}';
    }
    echo $json;
    exit;
}

/* Red de seguridad: si PHP muere con un error fatal, el cliente recibiría un
   cuerpo vacío y no sabría por qué. Esto garantiza que la respuesta SIEMPRE sea
   JSON, con el detalle del fatal para poder diagnosticarlo. */
$GLOBALS['odo_respondido'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['odo_respondido'])) return;
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false, 'status' => 'error', 'intentos_restantes' => 0,
        'message' => 'Error interno en la lectura. Ingresa el kilometraje manualmente.',
        'detalle' => $e['message'] . ' @ ' . basename((string)$e['file']) . ':' . $e['line'],
    ], JSON_UNESCAPED_UNICODE);
});

/* ---------- 1) Seguridad ---------- */
if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
    odo_out(['status' => 'error', 'message' => 'Sesión no iniciada'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    odo_out(['status' => 'error', 'message' => 'Método inválido'], 405);
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])
    || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    odo_out(['status' => 'error', 'message' => 'CSRF inválido'], 403);
}

$usuario   = (int)$_SESSION['usuario_id'];
$empresaId = (int)$_SESSION['empresa_id'];
$respId    = isset($_POST['resp_id'])   ? (int)$_POST['resp_id']   : 0;
$visitaId  = isset($_POST['visita_id']) ? (int)$_POST['visita_id'] : 0;

if ($respId <= 0 || $visitaId <= 0) {
    odo_out(['status' => 'error', 'message' => 'Parámetros inválidos'], 400);
}

/* ---------- 2) Disponibilidad ---------- */
if (!ev_ia_habilitada()) {
    odo_out(['status' => 'no_disponible', 'message' => 'Lectura automática no disponible. Ingresa el kilometraje manualmente.']);
}
if (!ev_tabla_lecturas_existe($conn)) {
    // Falta correr scripts/20 → no leemos (no podríamos auditar ni topar el consumo).
    odo_out(['status' => 'no_disponible', 'message' => 'Lectura automática no disponible. Ingresa el kilometraje manualmente.']);
}

/* ---------- 3) La foto es de este usuario, esta visita y ES la del odómetro ---------- */
$sql = "SELECT r.answer_text, r.id_form_question, r.id_usuario, r.visita_id
          FROM form_question_responses r
         WHERE r.id = ? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i', $respId);
$st->execute();
$foto = $st->get_result()->fetch_assoc();
$st->close();

if (!$foto
    || (int)$foto['id_usuario']       !== $usuario
    || (int)$foto['visita_id']        !== $visitaId
    || (int)$foto['id_form_question'] !== EV_QID_FOTO_ODO) {
    odo_out(['status' => 'error', 'message' => 'Foto no válida para lectura'], 403);
}

// La visita debe ser del usuario y de la campaña 138.
$st = $conn->prepare("SELECT id FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? LIMIT 1");
$formId = EV_FORMULARIO_ID;
$st->bind_param('iii', $visitaId, $usuario, $formId);
$st->execute();
$okVis = (bool)$st->get_result()->fetch_assoc();
$st->close();
if (!$okVis) {
    odo_out(['status' => 'error', 'message' => 'Visita no válida'], 403);
}

/* ---------- 4) Topes de consumo (capas) ---------- */

// 4.a Ráfaga por usuario (anti-bucle de JS)
if (!rate_limit_check($conn, 'odometro_read', $usuario, EV_MAX_RAFAGA_MIN, 60)) {
    odo_out(['status' => 'error', 'transitorio' => true,
             'message' => 'Demasiadas lecturas seguidas. Espera un momento.'], 429);
}

// 4.b Intentos ya usados para ESTA foto
$st = $conn->prepare("SELECT COUNT(*) c FROM odometro_lectura WHERE resp_foto_id = ?");
$st->bind_param('i', $respId);
$st->execute();
$intentosFoto = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
$st->close();

// 4.c Lecturas del usuario hoy
$st = $conn->prepare("SELECT COUNT(*) c FROM odometro_lectura WHERE id_usuario = ? AND DATE(created_at) = CURDATE()");
$st->bind_param('i', $usuario);
$st->execute();
$lecturasHoy = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
$st->close();

$restantes = max(0, min(EV_MAX_INTENTOS_FOTO - $intentosFoto, EV_MAX_LECTURAS_DIA - $lecturasHoy));

if ($intentosFoto >= EV_MAX_INTENTOS_FOTO) {
    odo_out(['status' => 'sin_intentos', 'intentos_restantes' => 0,
             'message' => 'Ya se intentó leer esta foto. Ingresa el kilometraje manualmente.']);
}
if ($lecturasHoy >= EV_MAX_LECTURAS_DIA) {
    odo_out(['status' => 'sin_intentos', 'intentos_restantes' => 0,
             'message' => 'Alcanzaste el máximo de lecturas automáticas por hoy. Ingresa el kilometraje manualmente.']);
}

// 4.d Corte global mensual (protege el presupuesto)
$res = @mysqli_query($conn,
    "SELECT COUNT(*) c FROM odometro_lectura
      WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
$lecturasMes = $res ? (int)(mysqli_fetch_assoc($res)['c'] ?? 0) : 0;
if ($lecturasMes >= EV_MAX_LECTURAS_MES) {
    error_log('[odometro] CORTE MENSUAL alcanzado: ' . $lecturasMes . ' lecturas');
    odo_out(['status' => 'no_disponible',
             'message' => 'Lectura automática pausada este mes. Ingresa el kilometraje manualmente.']);
}

/* ---------- 5) Ubicar el archivo físico ---------- */
$fotoUrl = (string)$foto['answer_text'];
$rel = ltrim(str_replace('\\', '/', $fotoUrl), '/');
$rel = preg_replace('#^visibility2/app/#i', '', $rel);
$fsPath = $APP_DIR . '/' . $rel;

if (!is_file($fsPath)) {
    odo_out(['status' => 'error', 'intentos_restantes' => $restantes,
             'message' => 'No se encontró la foto en el servidor.']);
}

/* ---------- 6) Dedup: si ya leímos ESTA misma imagen, no volvemos a pagar ---------- */
$sha = @hash_file('sha256', $fsPath) ?: null;
if ($sha) {
    $st = $conn->prepare(
        "SELECT km_ia, confianza, tipo_odometro, legible
           FROM odometro_lectura
          WHERE foto_sha256 = ? AND km_ia IS NOT NULL
          ORDER BY id DESC LIMIT 1");
    $st->bind_param('s', $sha);
    $st->execute();
    $prev = $st->get_result()->fetch_assoc();
    $st->close();

    if ($prev) {
        $kmPrev   = (int)$prev['km_ia'];
        $confPrev = (float)$prev['confianza'];
        odo_out([
            'ok' => true,
            'status' => $confPrev >= EV_UMBRAL_CONF ? 'leido' : 'dudosa',
            'km' => $kmPrev, 'confianza' => $confPrev,
            'tipo_odometro' => $prev['tipo_odometro'],
            'intentos_restantes' => $restantes,
            'message' => $confPrev >= EV_UMBRAL_CONF
                ? 'Kilometraje leído desde la foto.'
                : 'Lectura poco confiable. Verifica el valor.',
        ]);
    }
}

/* ---------- 7) Patente respondida en esta visita (para auditoría y validación) ---------- */
$patente = null; $idVehiculo = null;
$st = $conn->prepare("SELECT answer_text FROM form_question_responses
                       WHERE visita_id = ? AND id_form_question = ?
                       ORDER BY id DESC LIMIT 1");
$qPat = EV_QID_PATENTE;
$st->bind_param('ii', $visitaId, $qPat);
$st->execute();
$rowPat = $st->get_result()->fetch_assoc();
$st->close();
if ($rowPat && trim((string)$rowPat['answer_text']) !== '') {
    $patente = trim((string)$rowPat['answer_text']);
    $veh = ev_buscar_vehiculo_por_patente($conn, $patente, $empresaId);
    if ($veh) { $idVehiculo = (int)$veh['id']; $patente = (string)$veh['patente']; }
}

/* ---------- 8) Llamar a la IA ---------- */
$img = odo_preparar_imagen($fsPath);
if (!$img) {
    odo_out(['status' => 'error', 'intentos_restantes' => $restantes,
             'message' => 'No se pudo procesar la imagen.']);
}

$r = odo_leer($img['data_uri']);

// Transitorio (rate limit del plan / red): NO se persiste ni consume intento.
if (!empty($r['transitorio'])) {
    error_log('[odometro] transitorio: ' . ($r['error_msg'] ?? ''));
    odo_out(['status' => 'error', 'transitorio' => true, 'intentos_restantes' => $restantes,
             'message' => 'El servicio está ocupado. Puedes reintentar en unos segundos o escribir el kilometraje.']);
}

/* ---------- 9) Persistir el intento (auditoría + consumo) ---------- */
$intento = $intentosFoto + 1;
$ins = $conn->prepare(
    "INSERT INTO odometro_lectura
       (resp_foto_id, visita_id, id_usuario, id_formulario, patente, id_vehiculo,
        foto_url, foto_sha256, intento, modelo, km_ia, tipo_odometro, confianza, legible,
        raw_respuesta, prompt_tokens, completion_tokens, costo_estimado, latencia_ms,
        http_status, error_msg)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

if ($ins) {
    // bind_param exige variables (no expresiones de array) y el orden/tipos deben
    // calzar exactamente con las 21 columnas del INSERT de arriba.
    $bModelo = EV_MODELO_IA;
    $bKm     = $r['km'];
    $bTipo   = $r['tipo_odometro'];
    $bConf   = $r['confianza'];
    $bLeg    = $r['legible'];
    $bRaw    = $r['raw'];
    $bPT     = $r['prompt_tokens'];
    $bCT     = $r['completion_tokens'];
    $bCosto  = $r['costo'];
    $bLat    = $r['latencia_ms'];
    $bHttp   = $r['http_status'];
    $bErr    = $r['error_msg'];

    //            1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21
    $ins->bind_param(
        'iiiisissisisdisiidiis',
        $respId, $visitaId, $usuario, $formId, $patente, $idVehiculo,
        $fotoUrl, $sha, $intento, $bModelo, $bKm, $bTipo, $bConf, $bLeg,
        $bRaw, $bPT, $bCT, $bCosto, $bLat, $bHttp, $bErr
    );
    @$ins->execute();
    $ins->close();
}
$restantes = max(0, min(EV_MAX_INTENTOS_FOTO - $intento, EV_MAX_LECTURAS_DIA - ($lecturasHoy + 1)));

/* ---------- 10) Alertas de consumo 70% / 90% (quedan en el log del servidor) ---------- */
$pct = EV_MAX_LECTURAS_MES > 0 ? (($lecturasMes + 1) / EV_MAX_LECTURAS_MES) * 100 : 0;
if ($pct >= 90)      error_log('[odometro] ALERTA CRITICA: ' . round($pct) . '% del tope mensual (' . ($lecturasMes + 1) . '/' . EV_MAX_LECTURAS_MES . ')');
elseif ($pct >= 70)  error_log('[odometro] ALERTA: ' . round($pct) . '% del tope mensual (' . ($lecturasMes + 1) . '/' . EV_MAX_LECTURAS_MES . ')');

/* ---------- 11) Resultado ----------
   Se distinguen dos fracasos MUY distintos, porque se diagnostican distinto:
     - fallo técnico (key, cuota, modelo, respuesta inválida) → afecta a TODAS las fotos
     - foto ilegible → afecta solo a esa foto
   Antes ambos mostraban el mismo mensaje y eso escondía la causa real. */
if (!$r['ok'] || !empty($r['error_msg'])) {
    error_log('[odometro] FALLO TECNICO http=' . (int)$r['http_status'] . ' :: ' . (string)$r['error_msg']);
    odo_out([
        'status' => 'error',
        'intentos_restantes' => $restantes,
        'message' => 'El servicio de lectura no está respondiendo. Ingresa el kilometraje manualmente.',
        'detalle' => 'HTTP ' . (int)$r['http_status'] . ' — ' . (string)$r['error_msg'],
    ]);
}

if ($r['km'] === null || empty($r['legible'])) {
    odo_out(['status' => 'ilegible', 'intentos_restantes' => $restantes,
             'confianza' => $r['confianza'],
             'message' => 'No pudimos leer el odómetro en la foto. Ingresa el kilometraje manualmente.']);
}

$conf = (float)($r['confianza'] ?? 0);

// Validación contra la lectura anterior del mismo vehículo (advertir, nunca bloquear).
$alerta = null;
if ($patente !== null) {
    $ant = ev_ultimo_km_de_patente($conn, $patente, $visitaId);
    if ($ant !== null) {
        $delta = $r['km'] - $ant['km'];
        if ($delta < 0) {
            $alerta = 'El kilometraje leído (' . number_format($r['km'], 0, ',', '.') . ') es menor al último registrado ('
                    . number_format($ant['km'], 0, ',', '.') . '). Verifica antes de enviar.';
        } elseif ($delta > EV_SALTO_KM_MAX) {
            $alerta = 'El salto respecto al último registro es de ' . number_format($delta, 0, ',', '.')
                    . ' km. Verifica antes de enviar.';
        }
        if ($alerta !== null) {
            @mysqli_query($conn, "UPDATE odometro_lectura SET alerta_salto = 1
                                   WHERE resp_foto_id = " . (int)$respId . " ORDER BY id DESC LIMIT 1");
        }
    }
}

odo_out([
    'ok' => true,
    'status' => $conf >= EV_UMBRAL_CONF ? 'leido' : 'dudosa',
    'km' => (int)$r['km'],
    'confianza' => $conf,
    'tipo_odometro' => $r['tipo_odometro'],
    'intentos_restantes' => $restantes,
    'alerta' => $alerta,
    'message' => $conf >= EV_UMBRAL_CONF
        ? 'Kilometraje leído desde la foto.'
        : 'Lectura poco confiable. Revisa el valor antes de enviar.',
]);

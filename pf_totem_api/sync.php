<?php
/**
 * =====================================================================
 * pf_totem_api/sync.php
 * Receptor de datos del tótem "Brindemos lo real" (Puntí Ferrer).
 *
 * POR QUÉ VIVE FUERA DE app/:
 *   app/.user.ini tiene auto_prepend_file = _session_guard.php, que exige
 *   sesión web a TODO lo que cuelga de app/. El tótem no tiene sesión de
 *   usuario (es un kiosko anónimo) y solo api/mobile/ está en la lista de
 *   bypass. Colgando el endpoint de la raíz (visibility2/pf_totem_api/)
 *   no pasa por el guard y no hay que tocar archivos compartidos.
 *
 * AUTENTICACIÓN: header X-PF-Token contra PF_TOTEM_TOKEN del .env
 *   (app/.env — el mismo que ya lee con_.php). Sin token válido → 401.
 *
 * IDEMPOTENCIA: todo se escribe con INSERT ... ON DUPLICATE KEY UPDATE
 *   sobre el uuid que genera el tótem. Si la señal se corta después de
 *   grabar pero antes de que el tótem reciba el OK, el reenvío del lote
 *   no duplica nada. Esto es clave: en el evento la señal es mala.
 *
 * Requiere las tablas de scripts/22_pf_totem_juego.sql.
 * =====================================================================
 */
declare(strict_types=1);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* CORS: el tótem puede correr desde file:// (WebView Android → Origin: null)
   o desde un http local. Como la auth es por header y no por cookie, '*' no
   expone nada: sin el token la request se rechaza igual. */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-PF-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function pf_salir(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pf_salir(405, ['ok' => false, 'error_code' => 'METODO_NO_PERMITIDO']);
}

try {
    require_once __DIR__ . '/../app/con_.php';   // carga .env + $conn
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Sin conexión a la base de datos');
    }
    $conn->set_charset('utf8mb4');

    // ---------------------------------------------------------------- auth
    $tokenEsperado = (string)(getenv('PF_TOTEM_TOKEN') ?: '');
    if ($tokenEsperado === '') {
        pf_salir(500, ['ok' => false, 'error_code' => 'TOKEN_NO_CONFIGURADO',
            'message' => 'Falta PF_TOTEM_TOKEN en app/.env']);
    }
    $tokenRecibido = (string)($_SERVER['HTTP_X_PF_TOKEN'] ?? '');
    if (!hash_equals($tokenEsperado, $tokenRecibido)) {
        pf_salir(401, ['ok' => false, 'error_code' => 'TOKEN_INVALIDO']);
    }

    // ------------------------------------------------------------- payload
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 8 * 1024 * 1024) {
        pf_salir(413, ['ok' => false, 'error_code' => 'PAYLOAD_MUY_GRANDE']);
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        pf_salir(400, ['ok' => false, 'error_code' => 'JSON_INVALIDO']);
    }

    $deviceId = substr(trim((string)($in['device_id'] ?? 'desconocido')), 0, 40);

    // Ping del panel admin: valida token y conectividad sin escribir nada
    if (!empty($in['ping'])) {
        pf_salir(200, ['ok' => true, 'ping' => true,
            'server_time' => date('Y-m-d H:i:s'), 'device_id' => $deviceId]);
    }

    // ------------------------------------------------ tablas de la migración
    $faltantes = [];
    foreach (['pf_totem_sesion', 'pf_totem_evento', 'pf_totem_ganador'] as $t) {
        $res = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if (!$res || $res->num_rows === 0) $faltantes[] = $t;
    }
    if ($faltantes) {
        pf_salir(503, ['ok' => false, 'error_code' => 'TABLAS_FALTANTES',
            'message' => 'Falta correr scripts/22_pf_totem_juego.sql',
            'tablas' => $faltantes]);
    }

    /* El tótem manda ISO-8601 UTC (Date.toISOString()); la BD guarda hora
       de Santiago para que los reportes cuadren con la jornada del evento. */
    $tzSCL = new DateTimeZone('America/Santiago');
    $aFecha = static function ($iso) use ($tzSCL): ?string {
        if (!is_string($iso) || $iso === '') return null;
        try {
            return (new DateTime($iso))->setTimezone($tzSCL)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    };
    $txt = static function ($v, int $max): ?string {
        if ($v === null || $v === '') return null;
        return mb_substr(trim((string)$v), 0, $max);
    };
    $uuidOk = static function ($v): bool {
        return is_string($v) && preg_match('/^[0-9a-fA-F-]{8,36}$/', $v) === 1;
    };

    $conn->begin_transaction();
    $guardadas = ['sesiones' => 0, 'eventos' => 0, 'ganadores' => 0];
    $rechazadas = 0;

    // ------------------------------------------------------------ sesiones
    $sesiones = is_array($in['sesiones'] ?? null) ? $in['sesiones'] : [];
    if ($sesiones) {
        $sql = "INSERT INTO pf_totem_sesion
                  (uuid, device_id, inicio, fin, duracion_seg, momento, variedad,
                   vino, resultado, precision_pct, nivel_final, linea_objetivo, dificultad)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  fin            = VALUES(fin),
                  duracion_seg   = VALUES(duracion_seg),
                  momento        = VALUES(momento),
                  variedad       = VALUES(variedad),
                  vino           = VALUES(vino),
                  resultado      = VALUES(resultado),
                  precision_pct  = VALUES(precision_pct),
                  nivel_final    = VALUES(nivel_final),
                  linea_objetivo = VALUES(linea_objetivo),
                  dificultad     = VALUES(dificultad)";
        $st = $conn->prepare($sql);
        foreach ($sesiones as $s) {
            if (!is_array($s) || !$uuidOk($s['uuid'] ?? null)) { $rechazadas++; continue; }
            $uuid       = (string)$s['uuid'];
            $dev        = $txt($s['device_id'] ?? $deviceId, 40) ?? $deviceId;
            $inicio     = $aFecha($s['inicio'] ?? null);
            $fin        = $aFecha($s['fin'] ?? null);
            $dur        = isset($s['duracion_seg']) ? (int)$s['duracion_seg'] : null;
            $momento    = $txt($s['momento'] ?? null, 30);
            $variedad   = $txt($s['variedad'] ?? null, 30);
            $vino       = $txt($s['vino'] ?? null, 120);
            $resultado  = $txt($s['resultado'] ?? null, 20);
            $precision  = isset($s['precision_pct']) ? (int)$s['precision_pct'] : null;
            $nivel      = isset($s['nivel_final']) ? (float)$s['nivel_final'] : null;
            $linea      = isset($s['linea_objetivo']) ? (float)$s['linea_objetivo'] : null;
            $dificultad = $txt($s['dificultad'] ?? null, 20);
            // s s s s i s s s s i d d s  ← duracion_seg y precision_pct son int;
            // nivel_final y linea_objetivo son double
            $st->bind_param(
                'ssssissssidds',
                $uuid, $dev, $inicio, $fin, $dur, $momento, $variedad,
                $vino, $resultado, $precision, $nivel, $linea, $dificultad
            );
            if ($st->execute()) $guardadas['sesiones']++;
        }
        $st->close();
    }

    // ------------------------------------------------------------- eventos
    $eventos = is_array($in['eventos'] ?? null) ? $in['eventos'] : [];
    if ($eventos) {
        $sql = "INSERT INTO pf_totem_evento
                  (uuid, session_uuid, device_id, ts, tipo, data)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), data = VALUES(data)";
        $st = $conn->prepare($sql);
        foreach ($eventos as $e) {
            if (!is_array($e) || !$uuidOk($e['uuid'] ?? null)) { $rechazadas++; continue; }
            $uuid = (string)$e['uuid'];
            $sess = $uuidOk($e['session_uuid'] ?? null) ? (string)$e['session_uuid'] : null;
            $dev  = $txt($e['device_id'] ?? $deviceId, 40) ?? $deviceId;
            $ts   = $aFecha($e['ts'] ?? null);
            $tipo = $txt($e['tipo'] ?? null, 40) ?? 'desconocido';
            $data = isset($e['data']) && $e['data'] !== null
                ? mb_substr((string)json_encode($e['data'], JSON_UNESCAPED_UNICODE), 0, 4000)
                : null;
            $st->bind_param('ssssss', $uuid, $sess, $dev, $ts, $tipo, $data);
            if ($st->execute()) $guardadas['eventos']++;
        }
        $st->close();
    }

    // ----------------------------------------------------------- ganadores
    $ganadores = is_array($in['ganadores'] ?? null) ? $in['ganadores'] : [];
    if ($ganadores) {
        $sql = "INSERT INTO pf_totem_ganador
                  (uuid, session_uuid, device_id, nombre, email, telefono,
                   codigo, consentimiento, ts)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  nombre = VALUES(nombre), email = VALUES(email),
                  telefono = VALUES(telefono), codigo = VALUES(codigo),
                  consentimiento = VALUES(consentimiento)";
        $st = $conn->prepare($sql);
        foreach ($ganadores as $g) {
            if (!is_array($g) || !$uuidOk($g['uuid'] ?? null)) { $rechazadas++; continue; }
            $nombre = $txt($g['nombre'] ?? null, 120);
            $email  = $txt($g['email'] ?? null, 160);
            if ($nombre === null || $email === null) { $rechazadas++; continue; }
            $uuid    = (string)$g['uuid'];
            $sess    = $uuidOk($g['session_uuid'] ?? null) ? (string)$g['session_uuid'] : null;
            $dev     = $txt($g['device_id'] ?? $deviceId, 40) ?? $deviceId;
            $tel     = $txt($g['telefono'] ?? null, 30);
            $codigo  = $txt($g['codigo'] ?? null, 60);
            $consent = !empty($g['consentimiento']) ? 1 : 0;
            $ts      = $aFecha($g['ts'] ?? null);
            $st->bind_param('sssssssis', $uuid, $sess, $dev, $nombre, $email,
                            $tel, $codigo, $consent, $ts);
            if ($st->execute()) $guardadas['ganadores']++;
        }
        $st->close();
    }

    $conn->commit();

    echo json_encode([
        'ok'          => true,
        'guardadas'   => $guardadas,
        'rechazadas'  => $rechazadas,
        'device_id'   => $deviceId,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
    }
    error_log('[pf_totem_sync] ' . $e->getMessage());
    pf_salir(500, ['ok' => false, 'error_code' => 'ERROR_SERVIDOR',
        'message' => 'Error procesando el lote']);
}

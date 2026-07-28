<?php
/**
 * ajax_piloto.php  — dispatcher del piloto de odómetro.
 * Actions:
 *   procesar_lote  -> procesa el siguiente chunk de fotos con todos los modelos
 *   guardar_real   -> guarda la verdad de terreno (km real) y recalcula veredictos
 *   resumen        -> KPIs por modelo
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
@set_time_limit(0);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
require_once __DIR__ . '/lib_piloto.php';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (!isset($conn) || !$conn) {
    jout(['ok' => false, 'msg' => 'Sin conexión a BBDD.']);
}
mysqli_set_charset($conn, 'utf8mb4');

if (!piloto_tabla_existe($conn)) {
    jout(['ok' => false, 'msg' => 'Falta la tabla piloto_odometro_lecturas. Corre scripts/19_piloto_odometro.sql.']);
}

$action = $_REQUEST['action'] ?? '';

/* =========================================================================
   GUARDAR VERDAD DE TERRENO
   ========================================================================= */
if ($action === 'guardar_real') {
    $respFotoId = (int)($_POST['resp_foto_id'] ?? 0);
    $kmReal     = trim((string)($_POST['km_real'] ?? ''));
    $ilegible   = (int)($_POST['ilegible'] ?? 0) === 1;

    if ($respFotoId <= 0) jout(['ok' => false, 'msg' => 'resp_foto_id inválido.']);

    if ($ilegible) {
        $stmt = $conn->prepare(
            "UPDATE piloto_odometro_lecturas
             SET km_real = NULL, veredicto = 'ilegible'
             WHERE resp_foto_id = ?"
        );
        $stmt->bind_param('i', $respFotoId);
        $stmt->execute();
        $stmt->close();
        jout(['ok' => true, 'veredicto_global' => 'ilegible']);
    }

    if ($kmReal === '') jout(['ok' => false, 'msg' => 'Ingresa el km real o marca ilegible.']);

    // Veredicto por fila: correcta si km_ia (normalizado) == km_real (normalizado)
    $stmt = $conn->prepare(
        "UPDATE piloto_odometro_lecturas
         SET km_real = ?,
             veredicto = CASE
                WHEN km_ia IS NOT NULL
                     AND REPLACE(REPLACE(km_ia,'.',''),' ','') = REPLACE(REPLACE(?, '.',''),' ','')
                THEN 'correcta' ELSE 'incorrecta' END
         WHERE resp_foto_id = ?"
    );
    $stmt->bind_param('ssi', $kmReal, $kmReal, $respFotoId);
    $stmt->execute();
    $stmt->close();
    jout(['ok' => true, 'km_real' => $kmReal]);
}

/* =========================================================================
   RESUMEN / KPIs
   ========================================================================= */
if ($action === 'resumen') {
    jout(['ok' => true, 'resumen' => piloto_resumen($conn)]);
}

/* =========================================================================
   PROCESAR LOTE  (llama a OpenAI y consume presupuesto)
   ========================================================================= */
if ($action === 'procesar_lote') {

    // Freno de seguridad por costo acumulado
    $costoTotal = piloto_costo_total($conn);
    if ($costoTotal >= PILOTO_MAX_COSTO_TOTAL_USD) {
        jout([
            'ok' => false, 'freno' => true,
            'msg' => 'Freno de seguridad: costo acumulado ($' . number_format($costoTotal, 4) .
                     ') alcanzó el máximo configurado ($' . number_format(PILOTO_MAX_COSTO_TOTAL_USD, 2) . ').',
            'total_procesadas' => piloto_total_procesadas($conn),
            'costo_total' => $costoTotal,
        ]);
    }

    // El tope de muestra lo aplica piloto_candidatos(): solo inicia fotos NUEVAS si hay cupo;
    // las ya iniciadas se terminan (reintento del modelo que falta) aunque se llegue al tope.
    $candidatos = piloto_candidatos($conn, PILOTO_CHUNK);

    if (empty($candidatos)) {
        jout([
            'ok' => true, 'completado' => true, 'procesadas_ahora' => 0,
            'total_procesadas' => $totalProc, 'cap' => PILOTO_SAMPLE_CAP,
            'restantes' => 0, 'costo_total' => $costoTotal,
            'msg' => 'No hay más fotos candidatas (con foto de odómetro pendientes).',
        ]);
    }

    $modelos = $GLOBALS['PILOTO_MODELOS'];
    $procesadasAhora   = 0;
    $exitososAhora     = 0;  // lecturas nuevas guardadas OK
    $transitoriosAhora = 0;  // fallos temporales (rate limit / red) NO persistidos
    $costoRun = 0.0;

    $ins = $conn->prepare(
        "INSERT INTO piloto_odometro_lecturas
            (id_vehiculo, patente, id_usuario, nombre_usuario, visita_id,
             resp_km_id, resp_foto_id, foto_url, foto_path, fecha_visita,
             km_tipeado, modelo, km_ia, tipo_odometro, confianza, legible,
             raw_respuesta, prompt_tokens, completion_tokens, total_tokens,
             costo_estimado, bytes_enviados, latencia_ms, http_status, error_msg)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
             km_ia=VALUES(km_ia), tipo_odometro=VALUES(tipo_odometro),
             confianza=VALUES(confianza), legible=VALUES(legible),
             raw_respuesta=VALUES(raw_respuesta), prompt_tokens=VALUES(prompt_tokens),
             completion_tokens=VALUES(completion_tokens), total_tokens=VALUES(total_tokens),
             costo_estimado=VALUES(costo_estimado), bytes_enviados=VALUES(bytes_enviados),
             latencia_ms=VALUES(latencia_ms), http_status=VALUES(http_status),
             error_msg=VALUES(error_msg)"
    );

    foreach ($candidatos as $c) {
        $respFotoId = (int)$c['resp_foto_id'];
        $fotoUrl    = piloto_primera_foto((string)$c['foto_answer']);
        $fsPath     = $fotoUrl ? piloto_resolver_fs_path($fotoUrl) : null;

        // Modelos ya persistidos para esta foto (éxito o error definitivo): no re-llamar ni re-cobrar
        $yaModelos = [];
        if ($rq = mysqli_query($conn, "SELECT modelo FROM piloto_odometro_lecturas WHERE resp_foto_id = " . $respFotoId)) {
            while ($rr = mysqli_fetch_assoc($rq)) $yaModelos[$rr['modelo']] = true;
            mysqli_free_result($rq);
        }

        // Preparar imagen una sola vez y reutilizar para todos los modelos
        $img = ($fsPath) ? piloto_preparar_imagen($fsPath) : null;

        foreach ($modelos as $m) {
            $mid = $m['id'];

            if (isset($yaModelos[$mid])) continue; // ya tiene lectura persistida

            if (!$img) {
                // Registrar el fallo de imagen igual, para no reintentar en loop
                $r = [
                    'ok' => false, 'http_status' => 0,
                    'error_msg' => $fsPath ? 'No se pudo preparar la imagen.' : 'Archivo de foto no encontrado en el server.',
                    'km_ia' => null, 'tipo_odometro' => 'desconocido', 'confianza' => null,
                    'legible' => 0, 'raw' => null, 'prompt_tokens' => null,
                    'completion_tokens' => null, 'total_tokens' => null, 'costo' => 0.0,
                    'latencia_ms' => null, 'bytes' => null,
                ];
            } else {
                $r = piloto_llamar_openai($mid, $img['data_uri']);
                $r['bytes'] = $img['bytes'];
                $costoRun += (float)($r['costo'] ?? 0);
            }

            // Fallo transitorio (rate limit / red): NO lo persistimos → la foto sigue candidata
            // y se reintenta en el próximo lote. Evita bloquear la lectura para siempre.
            if (!empty($r['transitorio'])) {
                $transitoriosAhora++;
                continue;
            }

            $idVehiculo  = $c['id_vehiculo'] !== null ? (int)$c['id_vehiculo'] : null;
            $patente     = $c['patente'] ?? null;
            $idUsuario   = $c['id_usuario'] !== null ? (int)$c['id_usuario'] : null;
            $nombreUsr   = $c['nombre_usuario'] ?? null;
            $visitaId    = $c['visita_id'] !== null ? (int)$c['visita_id'] : null;
            $respKmId    = $c['resp_km_id'] !== null ? (int)$c['resp_km_id'] : null;
            $fechaVisita = $c['fecha_visita'] ?? null;
            $kmTipeado   = $c['km_tipeado'] ?? null;

            $km_ia    = $r['km_ia'];
            $tipo     = $r['tipo_odometro'];
            $conf     = $r['confianza'];
            $legible  = $r['legible'];
            $raw      = $r['raw'];
            $pt       = $r['prompt_tokens'];
            $ct       = $r['completion_tokens'];
            $tt       = $r['total_tokens'];
            $costo    = $r['costo'];
            $bytes    = $r['bytes'];
            $lat      = $r['latencia_ms'];
            $http     = $r['http_status'];
            $err      = $r['error_msg'];

            // 25 columnas: i=int s=string d=decimal
            $ins->bind_param(
                'isisiiisssssssdisiiidiiis',
                $idVehiculo, $patente, $idUsuario, $nombreUsr, $visitaId,
                $respKmId, $respFotoId, $fotoUrl, $fsPath, $fechaVisita,
                $kmTipeado, $mid, $km_ia, $tipo, $conf, $legible,
                $raw, $pt, $ct, $tt,
                $costo, $bytes, $lat, $http, $err
            );
            @$ins->execute();
        }

        $procesadasAhora++;
    }
    $ins->close();

    $totalProc  = piloto_total_procesadas($conn);
    $costoTotal = piloto_costo_total($conn);

    jout([
        'ok' => true,
        'completado' => ($totalProc >= PILOTO_SAMPLE_CAP),
        'procesadas_ahora' => $procesadasAhora,
        'total_procesadas' => $totalProc,
        'cap' => PILOTO_SAMPLE_CAP,
        'restantes' => max(0, PILOTO_SAMPLE_CAP - $totalProc),
        'costo_run' => $costoRun,
        'costo_total' => $costoTotal,
    ]);
}

jout(['ok' => false, 'msg' => 'Acción no reconocida.']);

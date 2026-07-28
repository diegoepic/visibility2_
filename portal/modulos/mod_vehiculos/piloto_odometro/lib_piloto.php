<?php
/**
 * lib_piloto.php
 * Helpers compartidos del piloto de odómetro (incluido por la página y el ajax).
 */

require_once __DIR__ . '/config_piloto.php';

/* ---------------------------------------------------------------------------
 * Existencia de la tabla (guard pre-migración, patrón del proyecto)
 * ------------------------------------------------------------------------- */
function piloto_tabla_existe(mysqli $conn): bool {
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'piloto_odometro_lecturas'");
    return $r && mysqli_num_rows($r) > 0;
}

/* ---------------------------------------------------------------------------
 * IDs de preguntas de la 138 resueltos por texto (no por id fijo)
 * ------------------------------------------------------------------------- */
function piloto_get_question_ids(mysqli $conn, string $texto): array {
    $ids  = [];
    $form = PILOTO_FORMULARIO_ID;
    $stmt = $conn->prepare(
        "SELECT id FROM form_questions
         WHERE id_formulario = ?
           AND UPPER(TRIM(question_text)) = UPPER(TRIM(?))"
    );
    if (!$stmt) return $ids;
    $stmt->bind_param('is', $form, $texto);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $stmt->close();
    return $ids;
}

function piloto_num_modelos(): int {
    return count($GLOBALS['PILOTO_MODELOS'] ?? []);
}

/* ---------------------------------------------------------------------------
 * Foto: de answer_text (separadas por ||) toma la primera no vacía
 * ------------------------------------------------------------------------- */
function piloto_primera_foto(string $answerText): ?string {
    $partes = preg_split('/\s*\|\|\s*/', trim($answerText));
    foreach ($partes as $p) {
        $p = trim($p);
        if ($p !== '') return $p;
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * Resuelve la ruta física real de la foto (para leer los bytes con GD/file).
 * Adaptado de normalizar_url_foto_reporte() de ajax_vehiculos_reporte.php.
 * ------------------------------------------------------------------------- */
function piloto_resolver_fs_path(string $valor): ?string {
    $valor = trim(html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($valor === '') return null;

    // Si viene como URL completa, quédate solo con el path
    if (preg_match('/^https?:\/\//i', $valor)) {
        $path = parse_url($valor, PHP_URL_PATH) ?: '';
        $valor = $path;
    }

    $valor = str_replace('\\', '/', $valor);
    $valor = ltrim($valor, '/');
    // Normaliza para que quede relativo a /visibility2/app/
    $valor = preg_replace('#^(visibility2/app/)+#i', '', $valor);
    $valor = preg_replace('#^visibility2/app/#i', '', $valor);

    $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $appFs = $documentRoot . '/visibility2/app/';

    $candidatos = [$valor];
    if (strpos($valor, '/') === false) {
        // Solo nombre de archivo: buscar en carpetas conocidas
        $candidatos[] = 'uploads/fotos_IW/' . $valor;
        $candidatos[] = 'uploads/' . $valor;
        $candidatos[] = 'uploads/fotos/' . $valor;
        $candidatos[] = 'uploads/complementarias/' . $valor;
        $candidatos[] = 'uploads/form_question_responses/' . $valor;
    }

    foreach ($candidatos as $c) {
        $fs = $appFs . $c;
        if (is_file($fs)) return $fs;
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * Prepara la imagen para enviar: redimensiona con GD (lado mayor = PILOTO_IMG_MAX_PX)
 * y re-codifica a JPEG. Si GD/WebP no está, envía los bytes originales.
 * Devuelve ['data_uri'=>..., 'bytes'=>int] o null si no pudo leer el archivo.
 * ------------------------------------------------------------------------- */
function piloto_preparar_imagen(string $fsPath): ?array {
    if (!is_file($fsPath)) return null;

    $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
    $mimeOrig = 'application/octet-stream';
    switch ($ext) {
        case 'webp': $mimeOrig = 'image/webp'; break;
        case 'jpg':
        case 'jpeg': $mimeOrig = 'image/jpeg'; break;
        case 'png':  $mimeOrig = 'image/png';  break;
        case 'gif':  $mimeOrig = 'image/gif';  break;
    }

    $puedeGD = function_exists('imagecreatetruecolor') && function_exists('imagescale');
    $img = null;
    if ($puedeGD) {
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($fsPath);
        } elseif (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($fsPath);
        } elseif ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($fsPath);
        }
    }

    if ($img) {
        $w = imagesx($img);
        $h = imagesy($img);
        $max = max($w, $h);
        if ($max > PILOTO_IMG_MAX_PX) {
            $escala = PILOTO_IMG_MAX_PX / $max;
            $nw = (int)round($w * $escala);
            $nh = (int)round($h * $escala);
            $resized = @imagescale($img, $nw, $nh);
            if ($resized) { imagedestroy($img); $img = $resized; }
        }
        ob_start();
        imagejpeg($img, null, PILOTO_IMG_JPEG_QUALITY);
        $bytes = ob_get_clean();
        imagedestroy($img);
        if ($bytes !== false && $bytes !== '') {
            return [
                'data_uri' => 'data:image/jpeg;base64,' . base64_encode($bytes),
                'bytes'    => strlen($bytes),
            ];
        }
    }

    // Fallback: bytes originales
    $bytes = @file_get_contents($fsPath);
    if ($bytes === false) return null;
    return [
        'data_uri' => 'data:' . $mimeOrig . ';base64,' . base64_encode($bytes),
        'bytes'    => strlen($bytes),
    ];
}

/* ---------------------------------------------------------------------------
 * Segundos a esperar antes de reintentar. Respeta el "try again in Xms/Xs"
 * del cuerpo de OpenAI; si no, usa backoff exponencial (2,4,8,15s).
 * ------------------------------------------------------------------------- */
function piloto_retry_wait(string $resp, int $intento): float {
    $hint = null;
    if ($resp !== '' && preg_match('/try again in ([\d.]+)\s*(ms|s)/i', $resp, $m)) {
        $hint = (float)$m[1];
        if (strtolower($m[2]) === 'ms') $hint /= 1000.0;
    }
    $backoff = min(15.0, pow(2, $intento)); // 2,4,8,15,15...
    // El "try again in 281ms" suele quedar corto para una imagen; nunca menos de 1s.
    $w = ($hint === null || $hint < 1.0) ? $backoff : max($hint, 1.0);
    return min(20.0, $w);
}

/* ---------------------------------------------------------------------------
 * Llama a OpenAI (Chat Completions + Structured Outputs) para leer el odómetro.
 * Devuelve un array normalizado con la lectura, tokens, costo, latencia, etc.
 * ------------------------------------------------------------------------- */
function piloto_llamar_openai(string $modeloId, string $dataUri): array {
    $out = [
        'ok' => false, 'http_status' => 0, 'error_msg' => null, 'transitorio' => false,
        'km_ia' => null, 'tipo_odometro' => 'desconocido', 'confianza' => null,
        'legible' => null, 'raw' => null,
        'prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null,
        'costo' => null, 'latencia_ms' => null,
    ];

    $apiKey = OPENAI_API_KEY;
    if (!$apiKey || $apiKey === 'PEGA_AQUI_TU_API_KEY') {
        $out['error_msg'] = 'API key no configurada (edita config_piloto.php).';
        return $out;
    }

    $schema = [
        'name'   => 'lectura_odometro',
        'strict' => true,
        'schema' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['kilometraje', 'tipo_odometro', 'confianza', 'legible'],
            'properties' => [
                'kilometraje'   => ['type' => ['integer', 'null'],
                                    'description' => 'Kilometraje TOTAL del odómetro, solo dígitos enteros, sin puntos ni decimales. null si no se puede leer.'],
                'tipo_odometro' => ['type' => 'string', 'enum' => ['digital', 'analogico', 'desconocido']],
                'confianza'     => ['type' => 'number', 'description' => 'Certeza de la lectura de 0 a 1.'],
                'legible'       => ['type' => 'boolean', 'description' => 'true si el odómetro se ve legible en la foto.'],
            ],
        ],
    ];

    $systemPrompt =
        'Eres un lector experto de odómetros de vehículos. Tu tarea es leer el KILOMETRAJE TOTAL ' .
        '(cuentakilómetros total, no el tripómetro/parcial). Reglas: 1) Devuelve solo dígitos enteros, ' .
        'sin puntos ni separadores ni decimales. 2) Si el tablero muestra dos números (total y parcial), ' .
        'lee el TOTAL, que suele ser el de mayor valor y sin coma decimal. 3) En odómetros analógicos de ' .
        'ruedas, si un dígito está a medio girar entre dos números, elige el número inferior y baja la ' .
        'confianza. 4) Si la foto está borrosa, cortada o no muestra el odómetro, marca legible=false, ' .
        'kilometraje=null y confianza baja. Nunca inventes dígitos.';

    $payload = [
        'model' => $modeloId,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Lee el kilometraje total de este odómetro.'],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => PILOTO_DETAIL]],
            ]],
        ],
        'response_format' => ['type' => 'json_schema', 'json_schema' => $schema],
        'temperature' => 0,
        'max_tokens' => 300,
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Reintenta ante 429 (rate limit) / 5xx / error de red, con backoff.
    $maxIntentos = 5;
    $resp = false; $http = 0; $cerr = '';
    for ($intento = 1; $intento <= $maxIntentos; $intento++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => PILOTO_HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $t0 = microtime(true);
        $resp = curl_exec($ch);
        $out['latencia_ms'] = (int)round((microtime(true) - $t0) * 1000);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $out['http_status'] = $http;

        $reintentable = ($resp === false) || $http === 429 || $http >= 500;
        if ($reintentable && $intento < $maxIntentos) {
            $espera = piloto_retry_wait(is_string($resp) ? $resp : '', $intento);
            usleep((int)($espera * 1000000));
            continue;
        }
        break;
    }

    // Falla de red o rate limit/5xx tras agotar reintentos → transitorio (no persistir, reintentar luego)
    if ($resp === false) {
        $out['error_msg'] = 'cURL: ' . $cerr;
        $out['transitorio'] = true;
        return $out;
    }
    if ($http === 429 || $http >= 500) {
        $j = json_decode($resp, true);
        $msg = (is_array($j) && isset($j['error']['message'])) ? $j['error']['message'] : ('HTTP ' . $http);
        $out['error_msg'] = 'API ' . $http . ': ' . $msg;
        $out['transitorio'] = true;
        $out['raw'] = substr($resp, 0, 2000);
        return $out;
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        $out['error_msg'] = 'Respuesta no-JSON de la API.';
        $out['raw'] = substr($resp, 0, 2000);
        return $out;
    }

    if (isset($json['error'])) {
        $out['error_msg'] = 'API: ' . ($json['error']['message'] ?? 'error desconocido');
        $out['raw'] = json_encode($json['error'], JSON_UNESCAPED_UNICODE);
        return $out;
    }

    // Tokens / costo
    if (isset($json['usage'])) {
        $out['prompt_tokens']     = (int)($json['usage']['prompt_tokens'] ?? 0);
        $out['completion_tokens'] = (int)($json['usage']['completion_tokens'] ?? 0);
        $out['total_tokens']      = (int)($json['usage']['total_tokens'] ?? 0);
        $out['costo'] = piloto_costo($modeloId, (int)$out['prompt_tokens'], (int)$out['completion_tokens']);
    }

    $content = $json['choices'][0]['message']['content'] ?? null;
    $out['raw'] = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE);

    $parsed = is_string($content) ? json_decode($content, true) : null;
    if (is_array($parsed)) {
        $km = $parsed['kilometraje'] ?? null;
        $out['km_ia']         = ($km === null || $km === '') ? null : (string)$km;
        $tipo = strtolower((string)($parsed['tipo_odometro'] ?? 'desconocido'));
        $out['tipo_odometro'] = in_array($tipo, ['digital', 'analogico', 'desconocido'], true) ? $tipo : 'desconocido';
        $conf = $parsed['confianza'] ?? null;
        if ($conf !== null) $out['confianza'] = max(0, min(1, (float)$conf));
        $out['legible'] = !empty($parsed['legible']) ? 1 : 0;
        $out['ok'] = ($out['http_status'] >= 200 && $out['http_status'] < 300);
    } else {
        $out['error_msg'] = 'No se pudo parsear el JSON de la lectura.';
    }

    return $out;
}

function piloto_costo(string $modeloId, int $promptTokens, int $completionTokens): float {
    $p = $GLOBALS['PILOTO_PRECIOS'][$modeloId] ?? null;
    if (!$p) return 0.0;
    return ($promptTokens / 1000000.0) * $p['in']
         + ($completionTokens / 1000000.0) * $p['out'];
}

/* ---------------------------------------------------------------------------
 * Candidatos: fotos de odómetro (138) con km tipeado en la misma visita, que
 * aún no tengan lectura para TODOS los modelos. Las más recientes primero.
 * ------------------------------------------------------------------------- */
function piloto_candidatos(mysqli $conn, int $limit): array {
    $fotoIds = piloto_get_question_ids($conn, PILOTO_TXT_FOTO);
    $kmIds   = piloto_get_question_ids($conn, PILOTO_TXT_KM);
    if (empty($fotoIds)) return [];

    $inFoto = implode(',', array_map('intval', $fotoIds));
    $inKm   = !empty($kmIds) ? implode(',', array_map('intval', $kmIds)) : '0';
    $numMod = max(1, piloto_num_modelos());
    $limit  = max(1, (int)$limit);

    // Condición para emparejar la respuesta de km con la de la foto (misma visita)
    $matchKm = "rk.id_usuario = rf.id_usuario
                AND rk.id_form_question IN ($inKm)
                AND (
                      (rf.visita_id > 0 AND rk.visita_id = rf.visita_id)
                   OR (COALESCE(rf.visita_id,0) = 0
                       AND DATE(rk.created_at) = DATE(rf.created_at)
                       AND TIME(rk.created_at) = TIME(rf.created_at))
                )";

    // Subconsultas correlacionadas (evita GROUP BY / ONLY_FULL_GROUP_BY y multiplicación de filas)
    $cols = "
        SELECT
            rf.id          AS resp_foto_id,
            rf.id_usuario  AS id_usuario,
            rf.visita_id   AS visita_id,
            rf.answer_text AS foto_answer,
            rf.created_at  AS fecha_visita,
            (SELECT UPPER(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,'')))
               FROM usuario u WHERE u.id = rf.id_usuario) AS nombre_usuario,
            (SELECT h.id_vehiculo FROM vehiculo_asignacion_historial h
               WHERE h.id_merchan = rf.id_usuario AND h.fecha_termino IS NULL
               ORDER BY h.id DESC LIMIT 1) AS id_vehiculo,
            (SELECT v.patente FROM vehiculo v
               WHERE v.id = (SELECT h2.id_vehiculo FROM vehiculo_asignacion_historial h2
                              WHERE h2.id_merchan = rf.id_usuario AND h2.fecha_termino IS NULL
                              ORDER BY h2.id DESC LIMIT 1)) AS patente,
            (SELECT rk.id FROM form_question_responses rk
               WHERE $matchKm ORDER BY rk.id DESC LIMIT 1) AS resp_km_id,
            (SELECT COALESCE(NULLIF(TRIM(rk.answer_text),''), TRIM(rk.valor))
               FROM form_question_responses rk
               WHERE $matchKm ORDER BY rk.id DESC LIMIT 1) AS km_tipeado
        FROM form_question_responses rf ";

    $baseWhere = "rf.id_form_question IN ($inFoto)
                  AND rf.answer_text IS NOT NULL AND rf.answer_text <> ''";
    // A la foto le falta al menos un modelo (cuenta filas persistidas: éxito o error definitivo)
    $faltaModelo = "(SELECT COUNT(DISTINCT p.modelo) FROM piloto_odometro_lecturas p
                     WHERE p.resp_foto_id = rf.id) < $numMod";
    $tieneAlgo = "EXISTS (SELECT 1 FROM piloto_odometro_lecturas p2 WHERE p2.resp_foto_id = rf.id)";
    $sinNada   = "NOT EXISTS (SELECT 1 FROM piloto_odometro_lecturas p3 WHERE p3.resp_foto_id = rf.id)";

    $rows = [];
    $vistos = [];
    $fetch = function ($sql) use ($conn, &$rows, &$vistos) {
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $fid = (int)$r['resp_foto_id'];
                if (isset($vistos[$fid])) continue;
                $vistos[$fid] = true;
                $r['km_tipeado'] = trim((string)($r['km_tipeado'] ?? ''));
                $rows[] = $r;
            }
            mysqli_free_result($res);
        }
    };

    // 1) Reintentos: fotos ya iniciadas a las que aún les falta un modelo (no cuentan contra el tope)
    $fetch("$cols WHERE $baseWhere AND $tieneAlgo AND $faltaModelo
            ORDER BY rf.created_at DESC LIMIT $limit");

    // 2) Fotos nuevas (sin ninguna lectura), solo si queda cupo dentro del tope de muestra
    $faltan = $limit - count($rows);
    if ($faltan > 0) {
        $iniciadas = piloto_total_procesadas($conn);
        $room = max(0, PILOTO_SAMPLE_CAP - $iniciadas);
        if ($room > 0) {
            $lim2 = min($faltan, $room);
            $fetch("$cols WHERE $baseWhere AND $sinNada
                    ORDER BY rf.created_at DESC LIMIT $lim2");
        }
    }
    return $rows;
}

function piloto_total_procesadas(mysqli $conn): int {
    $r = mysqli_query($conn, "SELECT COUNT(DISTINCT resp_foto_id) AS n FROM piloto_odometro_lecturas");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? (int)$row['n'] : 0;
}

function piloto_costo_total(mysqli $conn): float {
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(costo_estimado),0) AS c FROM piloto_odometro_lecturas");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? (float)$row['c'] : 0.0;
}

/* ---------------------------------------------------------------------------
 * KPIs del piloto (por modelo): acierto vs verdad de terreno, coincidencia con
 * lo tipeado, % dudosas, costo y proyección mensual.
 * ------------------------------------------------------------------------- */
function piloto_resumen(mysqli $conn): array {
    $modelos = $GLOBALS['PILOTO_MODELOS'] ?? [];
    $umbral  = PILOTO_UMBRAL_CONFIANZA;
    $out = ['modelos' => [], 'total_fotos' => piloto_total_procesadas($conn),
            'costo_total' => piloto_costo_total($conn),
            'proyeccion_imagenes' => PILOTO_PROYECCION_MENSUAL];

    foreach ($modelos as $m) {
        $mid = $m['id'];
        $mEsc = mysqli_real_escape_string($conn, $mid);

        $sql = "
            SELECT
                COUNT(*)                                                        AS n,
                SUM(CASE WHEN veredicto <> 'pendiente' THEN 1 ELSE 0 END)       AS adjudicadas,
                SUM(CASE WHEN veredicto = 'correcta' THEN 1 ELSE 0 END)         AS correctas,
                SUM(CASE WHEN veredicto = 'incorrecta' THEN 1 ELSE 0 END)       AS incorrectas,
                SUM(CASE WHEN veredicto = 'ilegible' THEN 1 ELSE 0 END)         AS ilegibles,
                SUM(CASE WHEN km_real IS NOT NULL AND km_real <> ''
                          AND REPLACE(REPLACE(km_ia,'.',''),' ','') = REPLACE(REPLACE(km_real,'.',''),' ','')
                         THEN 1 ELSE 0 END)                                     AS coincide_real,
                SUM(CASE WHEN km_tipeado IS NOT NULL AND km_tipeado <> ''
                          AND REPLACE(REPLACE(km_ia,'.',''),' ','') = REPLACE(REPLACE(km_tipeado,'.',''),' ','')
                         THEN 1 ELSE 0 END)                                     AS coincide_tipeado,
                SUM(CASE WHEN confianza IS NOT NULL AND confianza < $umbral THEN 1 ELSE 0 END) AS dudosas,
                SUM(CASE WHEN tipo_odometro = 'digital'   THEN 1 ELSE 0 END)    AS n_digital,
                SUM(CASE WHEN tipo_odometro = 'analogico' THEN 1 ELSE 0 END)    AS n_analogico,
                SUM(CASE WHEN tipo_odometro = 'digital'   AND veredicto='correcta' THEN 1 ELSE 0 END) AS ok_digital,
                SUM(CASE WHEN tipo_odometro = 'analogico' AND veredicto='correcta' THEN 1 ELSE 0 END) AS ok_analogico,
                SUM(CASE WHEN tipo_odometro='digital'   AND veredicto<>'pendiente' THEN 1 ELSE 0 END) AS adj_digital,
                SUM(CASE WHEN tipo_odometro='analogico' AND veredicto<>'pendiente' THEN 1 ELSE 0 END) AS adj_analogico,
                COALESCE(SUM(costo_estimado),0)                                 AS costo,
                COALESCE(AVG(costo_estimado),0)                                 AS costo_prom,
                COALESCE(AVG(prompt_tokens),0)                                  AS prom_prompt_tokens,
                SUM(CASE WHEN error_msg IS NOT NULL AND error_msg <> '' THEN 1 ELSE 0 END) AS errores
            FROM piloto_odometro_lecturas
            WHERE modelo = '$mEsc'
        ";
        $r = mysqli_query($conn, $sql);
        $row = $r ? mysqli_fetch_assoc($r) : [];

        $costoProm = (float)($row['costo_prom'] ?? 0);
        $row['label'] = $m['label'];
        $row['modelo'] = $mid;
        $row['proyeccion_mensual'] = $costoProm * PILOTO_PROYECCION_MENSUAL;
        $out['modelos'][] = $row;
    }
    return $out;
}

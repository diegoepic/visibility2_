<?php
/**
 * odometro_ia.php
 * -----------------------------------------------------------------------------
 * Motor de lectura de odómetro (OpenAI visión + salida estructurada).
 * Portado del piloto validado en portal/modulos/mod_vehiculos/piloto_odometro/.
 *
 * No toca base de datos ni sesión: recibe una ruta de imagen y devuelve la
 * lectura normalizada. Toda la política de topes/persistencia vive en
 * api/odometro_read.php.
 */

require_once __DIR__ . '/encuesta_vehiculo.php';

/**
 * Prepara la imagen para enviar: reescala al lado mayor EV_IMG_MAX_PX y
 * recodifica a JPEG. Menos bytes = menos tokens de imagen = menos costo.
 * Si GD no puede abrirla, cae a los bytes originales.
 *
 * @return array{data_uri:string,bytes:int}|null
 */
function odo_preparar_imagen(string $fsPath): ?array {
    if (!is_file($fsPath)) return null;

    $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
    $img = null;
    if (function_exists('imagescale')) {
        if     ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($fsPath);
        elseif (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($fsPath);
        elseif ($ext === 'png'  && function_exists('imagecreatefrompng'))  $img = @imagecreatefrompng($fsPath);
    }

    if ($img) {
        $w = imagesx($img); $h = imagesy($img);
        $max = max($w, $h);
        if ($max > EV_IMG_MAX_PX) {
            $esc = EV_IMG_MAX_PX / $max;
            $re = @imagescale($img, (int)round($w * $esc), (int)round($h * $esc));
            if ($re) { imagedestroy($img); $img = $re; }
        }
        ob_start();
        imagejpeg($img, null, EV_IMG_JPEG_Q);
        $bytes = ob_get_clean();
        imagedestroy($img);
        if ($bytes !== false && $bytes !== '') {
            return ['data_uri' => 'data:image/jpeg;base64,' . base64_encode($bytes), 'bytes' => strlen($bytes)];
        }
    }

    $bytes = @file_get_contents($fsPath);
    if ($bytes === false || $bytes === '') return null;
    $mime = ($ext === 'webp') ? 'image/webp' : (($ext === 'png') ? 'image/png' : 'image/jpeg');
    return ['data_uri' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'bytes' => strlen($bytes)];
}

/**
 * Segundos a esperar antes de reintentar tras un 429/5xx. Respeta el
 * "try again in Xms" que manda OpenAI; si no viene, backoff exponencial.
 */
function odo_retry_wait(string $resp, int $intento): float {
    $hint = null;
    if ($resp !== '' && preg_match('/try again in ([\d.]+)\s*(ms|s)/i', $resp, $m)) {
        $hint = (float)$m[1];
        if (strtolower($m[2]) === 'ms') $hint /= 1000.0;
    }
    $backoff = min(8.0, pow(2, $intento));           // 2, 4, 8
    $w = ($hint === null || $hint < 1.0) ? $backoff : max($hint, 1.0);
    return min(10.0, $w);
}

function odo_costo(int $promptTokens, int $completionTokens): float {
    return ($promptTokens / 1000000.0) * EV_PRECIO_IN
         + ($completionTokens / 1000000.0) * EV_PRECIO_OUT;
}

/**
 * Lee el kilometraje de la foto de un odómetro.
 *
 * @return array{
 *   ok:bool, transitorio:bool, http_status:int, error_msg:?string,
 *   km:?int, tipo_odometro:string, confianza:?float, legible:?int, raw:?string,
 *   prompt_tokens:?int, completion_tokens:?int, costo:?float, latencia_ms:?int
 * }
 */
function odo_leer(string $dataUri): array {
    $out = [
        'ok' => false, 'transitorio' => false, 'http_status' => 0, 'error_msg' => null,
        'km' => null, 'tipo_odometro' => 'desconocido', 'confianza' => null,
        'legible' => null, 'raw' => null,
        'prompt_tokens' => null, 'completion_tokens' => null, 'costo' => null, 'latencia_ms' => null,
    ];

    /* La key se resuelve acá y no se usa la constante directo en el header: si el
       archivo queda desincronizado con encuesta_vehiculo.php (o alguien copia la
       constante del piloto, que se llama distinto), PHP 8 lanza un Error fatal por
       constante indefinida. Así, en el peor caso, se responde un error legible. */
    $apiKey = '';
    if (defined('EV_OPENAI_API_KEY'))   $apiKey = (string)EV_OPENAI_API_KEY;  // nombre en la app
    elseif (defined('OPENAI_API_KEY'))  $apiKey = (string)OPENAI_API_KEY;     // nombre en el piloto

    if ($apiKey === '') {
        $out['error_msg'] = 'Lectura con IA no configurada (falta OPENAI_API_KEY en app/.env).';
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

    $payload = json_encode([
        'model' => EV_MODELO_IA,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Lee el kilometraje total de este odómetro.'],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'high']],
            ]],
        ],
        'response_format' => ['type' => 'json_schema', 'json_schema' => $schema],
        'temperature' => 0,
        'max_tokens'  => 300,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Reintento ante 429 (rate limit del plan) / 5xx / error de red.
    $maxIntentos = 3;
    $resp = false; $http = 0; $cerr = '';
    for ($i = 1; $i <= $maxIntentos; $i++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => EV_HTTP_TIMEOUT,
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
        if ($reintentable && $i < $maxIntentos) {
            usleep((int)(odo_retry_wait(is_string($resp) ? $resp : '', $i) * 1000000));
            continue;
        }
        break;
    }

    // Fallos transitorios: NO consumen intento del usuario (se puede reintentar luego).
    if ($resp === false) {
        $out['error_msg']   = 'Red: ' . $cerr;
        $out['transitorio'] = true;
        return $out;
    }
    if ($http === 429 || $http >= 500) {
        $j = json_decode($resp, true);
        $out['error_msg']   = 'API ' . $http . ': ' . (is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : 'servicio no disponible');
        $out['transitorio'] = true;
        return $out;
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        $out['error_msg'] = 'Respuesta no válida del servicio.';
        return $out;
    }
    if (isset($json['error'])) {
        $out['error_msg'] = 'API: ' . ($json['error']['message'] ?? 'error desconocido');
        return $out;
    }

    if (isset($json['usage'])) {
        $out['prompt_tokens']     = (int)($json['usage']['prompt_tokens'] ?? 0);
        $out['completion_tokens'] = (int)($json['usage']['completion_tokens'] ?? 0);
        $out['costo'] = odo_costo((int)$out['prompt_tokens'], (int)$out['completion_tokens']);
    }

    $content = $json['choices'][0]['message']['content'] ?? null;
    $out['raw'] = is_string($content) ? mb_substr($content, 0, 1000) : null;

    $parsed = is_string($content) ? json_decode($content, true) : null;
    if (!is_array($parsed)) {
        $out['error_msg'] = 'No se pudo interpretar la lectura.';
        return $out;
    }

    $km = $parsed['kilometraje'] ?? null;
    $out['km'] = ($km === null || $km === '') ? null : (int)$km;

    $tipo = strtolower((string)($parsed['tipo_odometro'] ?? 'desconocido'));
    $out['tipo_odometro'] = in_array($tipo, ['digital', 'analogico', 'desconocido'], true) ? $tipo : 'desconocido';

    $conf = $parsed['confianza'] ?? null;
    if ($conf !== null) $out['confianza'] = max(0.0, min(1.0, (float)$conf));

    $out['legible'] = !empty($parsed['legible']) ? 1 : 0;
    $out['ok'] = ($http >= 200 && $http < 300);

    return $out;
}

<?php
/**
 * odometro_diag.php — DIAGNÓSTICO TEMPORAL de la API key de OpenAI.
 *
 * Compara la key que lee la app (desde app/.env) contra la que usa el piloto
 * (portal/.../config_piloto.php) y hace un ping real a OpenAI, para ubicar por
 * qué "la misma key" funciona en un lado y en el otro no.
 *
 * NUNCA imprime la key completa: solo longitud, primeros/últimos caracteres y
 * un hash corto. Si dos keys tienen el mismo hash, son idénticas.
 *
 * >>> BORRAR ESTE ARCHIVO DEL SERVIDOR CUANDO TERMINES EL DIAGNÓSTICO <<<
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit("Sesión no iniciada. Entra a la app primero y vuelve a abrir esta URL.\n");
}

$APP_DIR = dirname(__DIR__);

/** Describe una key sin revelarla. */
function describir(?string $k, string $origen): string {
    if ($k === null || $k === '') {
        return sprintf("  %-22s => (VACIA / no encontrada)\n", $origen);
    }
    $len   = strlen($k);
    $ini   = substr($k, 0, 8);
    $fin   = substr($k, -4);
    $hash  = substr(hash('sha256', $k), 0, 12);

    // Caracteres que no deberían estar en una API key
    $sucio = [];
    if (preg_match('/\s/', $k))            $sucio[] = 'espacios/saltos de linea';
    if (strpos($k, "'") !== false)         $sucio[] = "comilla simple '";
    if (strpos($k, '"') !== false)         $sucio[] = 'comilla doble "';
    if (strpos($k, ';') !== false)         $sucio[] = 'punto y coma ;';
    if (strpos($k, "\r") !== false)        $sucio[] = 'retorno de carro \\r';
    if (substr($k, 0, 3) !== 'sk-')        $sucio[] = 'NO empieza con sk-';

    return sprintf(
        "  %-22s => largo=%d  inicio=%s...  fin=...%s  hash=%s%s\n",
        $origen, $len, $ini, $fin, $hash,
        $sucio ? "\n" . str_repeat(' ', 26) . "!! PROBLEMA: " . implode(', ', $sucio) : ''
    );
}

echo "=== DIAGNOSTICO API KEY ODOMETRO ===\n\n";

/* ---------- 1) Lo que lee la APP ---------- */
echo "1) KEY QUE USA LA APP\n";

$envFile = $APP_DIR . '/.env';
echo "  archivo .env          => " . $envFile . "\n";
echo "  existe / legible      => " . (is_file($envFile) ? 'si' : 'NO') . ' / '
                                   . (is_readable($envFile) ? 'si' : 'NO') . "\n";

// Línea cruda del .env (sin el parseo de con_.php), para ver qué está escrito ahí.
$rawEnvVal = null;
$lineasKey = 0;
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (stripos($t, 'OPENAI_API_KEY') === 0 && strpos($t, '=') !== false) {
            $lineasKey++;
            [, $v] = explode('=', $t, 2);
            $rawEnvVal = trim($v, " \t\"'");
        }
    }
}
echo "  lineas OPENAI_API_KEY => " . $lineasKey . ($lineasKey > 1 ? "  !! HAY MAS DE UNA, gana la ultima\n" : "\n");
echo describir($rawEnvVal, 'valor crudo en .env');

// Lo que devuelve el entorno (puede venir del servidor, NO del .env)
require_once $APP_DIR . '/con_.php';
$keyEnv = getenv('OPENAI_API_KEY');
echo describir($keyEnv === false ? null : (string)$keyEnv, 'getenv() del entorno');

// Lo que la app USA de verdad (prioriza app/.env sobre el entorno)
require_once $APP_DIR . '/lib/encuesta_vehiculo.php';
$keyApp = EV_OPENAI_API_KEY !== '' ? EV_OPENAI_API_KEY : null;
echo describir($keyApp, 'KEY EFECTIVA APP');

if ($rawEnvVal !== null && $keyEnv !== false && $rawEnvVal !== $keyEnv) {
    echo "\n  OJO: el .env dice una cosa y getenv() devuelve otra.\n";
    echo "  Significa que el servidor (Apache/PHP-FPM) ya tiene definida una variable\n";
    echo "  OPENAI_API_KEY antigua, y con_.php NO la sobrescribe con la del .env.\n";
    echo "  Esa era la causa del 401: la app usaba la vieja. Ya corregido: ahora la\n";
    echo "  app lee el .env directamente. Aun asi, conviene borrar esa variable del\n";
    echo "  servidor para que no confunda a futuro.\n";
}

/* ---------- 2) Lo que usa el PILOTO ---------- */
echo "\n2) KEY QUE USA EL PILOTO (referencia: esta si funcionaba)\n";

$pilotoCfg = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_vehiculos/piloto_odometro/config_piloto.php';
echo "  archivo               => " . $pilotoCfg . "\n";
echo "  existe                => " . (is_file($pilotoCfg) ? 'si' : 'NO') . "\n";

// El piloto ahora resuelve la key igual que la app (leyendo app/.env), así que
// se incluye su config para obtener el valor EFECTIVO que usaría.
$keyPiloto = null;
if (is_readable($pilotoCfg)) {
    require_once $pilotoCfg;
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        $keyPiloto = OPENAI_API_KEY;
    }
}
echo describir($keyPiloto, 'key efectiva piloto');

/* ---------- 3) ¿Son la misma? ---------- */
echo "\n3) COMPARACION\n";
if ($keyApp && $keyPiloto) {
    if (hash_equals($keyPiloto, (string)$keyApp)) {
        echo "  Los hashes COINCIDEN: la app usa exactamente la misma key del piloto.\n";
        echo "  => Si aun asi falla, el problema no es la key sino la cuenta/modelo.\n";
    } else {
        echo "  !! Los hashes NO coinciden: la app NO esta usando la key del piloto.\n";
        echo "  => Corrige app/.env dejando la linea EXACTAMENTE asi (sin comillas,\n";
        echo "     sin ; al final, todo en UNA sola linea):\n";
        echo "     OPENAI_API_KEY=sk-...\n";
    }
} else {
    echo "  No se pudo comparar (falta una de las dos).\n";
}

/* ---------- 4) Ping real a OpenAI con la key de la app ---------- */
echo "\n4) PING REAL A OPENAI (con la key de la app)\n";
if (!$keyApp) {
    echo "  Omitido: la app no tiene key.\n";
} else {
    $ch = curl_init('https://api.openai.com/v1/models/gpt-4o');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $keyApp],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    echo "  HTTP                  => " . $http . "\n";
    if ($resp === false) {
        echo "  Error de red          => " . $err . "\n";
    } else {
        $j = json_decode($resp, true);
        if ($http === 200) {
            echo "  RESULTADO             => OK. La key es valida y gpt-4o esta disponible.\n";
            echo "  => Si la lectura igual falla, revisa error_msg en odometro_lectura.\n";
        } else {
            echo "  Mensaje de OpenAI     => " . ($j['error']['message'] ?? substr((string)$resp, 0, 300)) . "\n";
            echo "  Tipo                  => " . ($j['error']['type'] ?? '-') . "\n";
            if ($http === 401) echo "  => Key invalida o revocada.\n";
            if ($http === 404) echo "  => La key es valida pero NO tiene acceso al modelo gpt-4o.\n";
            if ($http === 429) echo "  => Sin cuota / limite alcanzado (revisa facturacion).\n";
        }
    }
}

echo "\n=== FIN — BORRA ESTE ARCHIVO DEL SERVIDOR ===\n";

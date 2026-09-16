<?php
declare(strict_types=1);

/**
 * Limpieza nocturna de Visibility2.
 *
 * POR QUÉ EXISTE
 * --------------
 * Hasta ahora la limpieza de `request_log` corría DENTRO de las peticiones: al final de
 * `lib/idempotency.php` había un `if (mt_rand(1, 100) === 1) { idempo_gc($conn); }`. Ese
 * archivo lo incluyen 8 endpoints, así que una de cada cien gestiones pagaba tres
 * sentencias de limpieza —una de ellas recorriendo la tabla entera— con el teléfono de un
 * ejecutor esperando en el terreno, muchas veces con mala señal.
 *
 * No era una catástrofe: la tabla tiene ~76 K filas y el barrido son decenas de
 * milisegundos. Pero es trabajo de mantenimiento en la ruta de una petición de usuario, y
 * crece entre pasadas. Aquí abajo no le hace daño a nadie.
 *
 * CÓMO SE PROGRAMA
 * ----------------
 * En el cron del hosting (cPanel: "Cron Jobs"), una vez al día de madrugada:
 *
 *     15 3 * * *  /usr/local/bin/php /home/visibility/public_html/visibility2/scripts/cron_gc.php >> /home/visibility/logs/cron_gc.log 2>&1
 *
 * Ajusta la ruta de php y del proyecto. Si tu panel sólo deja programar URLs y no
 * comandos, mira la nota del final.
 *
 * Opciones:
 *   --dry-run   Dice cuánto borraría, sin borrar nada
 *   --quiet     Sólo imprime si hay algo que decir (para no llenar el log de cron)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "cron_gc.php sólo se ejecuta desde la línea de comandos.\n";
    exit(1);
}

$RAIZ    = dirname(__DIR__);
$dryRun  = in_array('--dry-run', $argv, true);
$quiet   = in_array('--quiet', $argv, true);
$lineas  = [];

function anota(string $s): void { global $lineas; $lineas[] = $s; }

// ---------------------------------------------------------------------------
// Conexión (la misma fuente que la app)
// ---------------------------------------------------------------------------
$cargador = $RAIZ . '/lib_env.php';
if (is_file($cargador)) {
    require_once $cargador;
    if (function_exists('v2_load_env')) { v2_load_env(); }
}

$conn = @new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: '',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_NAME') ?: ''
);
if ($conn->connect_error) {
    fwrite(STDERR, date('c') . " cron_gc: sin conexión a BD: " . $conn->connect_error . "\n");
    exit(3);
}
$conn->set_charset('utf8mb4');

// Las constantes de retención son las mismas que usa la app en caliente.
require_once $RAIZ . '/app/lib/idempotency.php';

$t0 = microtime(true);

// ---------------------------------------------------------------------------
// Helper: borrar por lotes.
//
// Un DELETE de golpe puede bloquear la tabla un rato largo si un día se acumula mucho.
// En lotes, cada transacción es corta y las escrituras de la app siguen pasando entre
// medias. El tope de vueltas evita quedarse dando vueltas para siempre si algo va mal.
// ---------------------------------------------------------------------------
function borrar_por_lotes(mysqli $conn, string $sql, int $lote = 2000, int $maxVueltas = 200): int
{
    $total = 0;
    for ($v = 0; $v < $maxVueltas; $v++) {
        if (!$conn->query($sql . " LIMIT $lote")) {
            anota('  ERROR: ' . $conn->error);
            break;
        }
        $n = $conn->affected_rows;
        $total += $n;
        if ($n < $lote) break;
        usleep(100000);   // 100 ms de respiro entre lotes
    }
    return $total;
}

function contar(mysqli $conn, string $sqlCount): int
{
    $r = @$conn->query($sqlCount);
    if (!$r) return -1;
    $fila = $r->fetch_row();
    $r->free();
    return (int)($fila[0] ?? 0);
}

// ---------------------------------------------------------------------------
// 1) request_log — idempotencia
// ---------------------------------------------------------------------------
$mins = IDEMPO_PROCESSING_TIMEOUT_MINUTES;
$dias = IDEMPO_COMPLETED_TTL_DAYS;

$sqlColgados = "UPDATE request_log
                   SET status = 'failed'
                 WHERE status = 'processing'
                   AND ( (timeout_at IS NOT NULL AND timeout_at < NOW())
                      OR (timeout_at IS NULL AND created_at < NOW() - INTERVAL $mins MINUTE) )";

$sqlViejos   = "DELETE FROM request_log
                 WHERE status IN ('pending','failed')
                   AND created_at < NOW() - INTERVAL 2 DAY";

$sqlCompletos = "DELETE FROM request_log
                  WHERE status = 'completed'
                    AND completed_at IS NOT NULL
                    AND completed_at < NOW() - INTERVAL $dias DAY";

if ($dryRun) {
    $nColgados = contar($conn,
        "SELECT COUNT(*) FROM request_log
          WHERE status = 'processing'
            AND ( (timeout_at IS NOT NULL AND timeout_at < NOW())
               OR (timeout_at IS NULL AND created_at < NOW() - INTERVAL $mins MINUTE) )");
    $nViejos = contar($conn,
        "SELECT COUNT(*) FROM request_log
          WHERE status IN ('pending','failed')
            AND created_at < NOW() - INTERVAL 2 DAY");
    $nCompletos = contar($conn,
        "SELECT COUNT(*) FROM request_log
          WHERE status = 'completed'
            AND completed_at IS NOT NULL
            AND completed_at < NOW() - INTERVAL $dias DAY");

    anota('request_log (simulación):');
    anota(sprintf('  colgados que se marcarían fallidos: %d', $nColgados));
    anota(sprintf('  pendientes/fallidos a borrar:       %d', $nViejos));
    anota(sprintf('  completados a borrar:               %d', $nCompletos));
    anota(sprintf('  total de filas en la tabla:         %d',
        contar($conn, 'SELECT COUNT(*) FROM request_log')));
} else {
    $colgados = 0;
    if ($conn->query($sqlColgados)) { $colgados = $conn->affected_rows; }
    else { anota('  ERROR marcando colgados: ' . $conn->error); }

    $viejos    = borrar_por_lotes($conn, $sqlViejos);
    $completos = borrar_por_lotes($conn, $sqlCompletos);

    if ($colgados || $viejos || $completos) {
        anota(sprintf('request_log: %d colgados marcados, %d pendientes/fallidos borrados, %d completados borrados',
            $colgados, $viejos, $completos));
    }
}

// ---------------------------------------------------------------------------
// 2) visita — cerrar las huérfanas
//
// Una visita huérfana es una fila abierta (sin `fecha_fin`) que no tiene NADA colgando:
// ni gestión, ni foto, ni respuesta de encuesta. Se creaban porque el `INSERT INTO visita`
// vivía 120 líneas antes del `begin_transaction()` de procesar_gestion_pruebas.php: si la
// gestión fallaba después, el rollback deshacía todo menos la visita.
//
// Eso ya está corregido, así que esto es limpieza de lo que quedó. En el volcado del
// 2026-09-03 eran 20.716 filas: el 8% de la tabla.
//
// Se cierran poniendo `fecha_fin = fecha_inicio`. Queda una visita de duración cero, que
// es exactamente lo que fue. No se BORRAN: hay una clave foránea desde gestion_visita con
// ON DELETE RESTRICT, y sobre todo, borrar filas de una tabla de evidencia para ahorrar
// unos megas es un mal negocio.
//
// Las tres subconsultas van por índice: gv_visita_material, idx_fotoVisita_visita_id e
// idx_fqr_visita_id empiezan todas por visita_id. La tabla `visita` sí se recorre entera
// (259 K filas), y se deja así a propósito: añadirle un índice por `fecha_fin` para una
// tarea nocturna sería cargar cada gestión del día con una escritura de más.
// ---------------------------------------------------------------------------
$horas = (int)(getenv('VISITA_ORPHAN_HOURS') ?: 24);
if ($horas < 6) { $horas = 6; }   // suelo: por debajo se pisarían visitas del mismo turno

$condicion = "FROM visita v
               WHERE v.fecha_fin IS NULL
                 AND v.fecha_inicio < NOW() - INTERVAL $horas HOUR
                 AND NOT EXISTS (SELECT 1 FROM gestion_visita g          WHERE g.visita_id = v.id)
                 AND NOT EXISTS (SELECT 1 FROM fotoVisita f              WHERE f.visita_id = v.id)
                 AND NOT EXISTS (SELECT 1 FROM form_question_responses r WHERE r.visita_id = v.id)";

if ($dryRun) {
    $nHuerfanas = contar($conn, "SELECT COUNT(*) $condicion");
    $nAbiertas  = contar($conn, "SELECT COUNT(*) FROM visita WHERE fecha_fin IS NULL");
    anota('visita (simulación):');
    anota(sprintf('  abiertas (sin fecha_fin):           %d', $nAbiertas));
    anota(sprintf('  huérfanas de más de %d h:            %d   <-- se cerrarían', $horas, $nHuerfanas));
    anota(sprintf('  total de filas en la tabla:         %d', contar($conn, 'SELECT COUNT(*) FROM visita')));
} else {
    // Por lotes: la primera pasada toca ~20 K filas, y un UPDATE así de golpe bloquea la
    // tabla mientras la app intenta escribir.
    $cerradas = 0;
    for ($v = 0; $v < 60; $v++) {
        // Se escribe entera en vez de reusar $condicion: MySQL no admite el alias `v` de
        // un UPDATE dentro de una cadena montada aparte, y aquí prefiero verla completa.
        $sql = "UPDATE visita v
                   SET v.fecha_fin = v.fecha_inicio
                 WHERE v.fecha_fin IS NULL
                   AND v.fecha_inicio < NOW() - INTERVAL $horas HOUR
                   AND NOT EXISTS (SELECT 1 FROM gestion_visita g          WHERE g.visita_id = v.id)
                   AND NOT EXISTS (SELECT 1 FROM fotoVisita f              WHERE f.visita_id = v.id)
                   AND NOT EXISTS (SELECT 1 FROM form_question_responses r WHERE r.visita_id = v.id)
                 LIMIT 1000";
        if (!$conn->query($sql)) { anota('  ERROR cerrando huérfanas: ' . $conn->error); break; }
        $n = $conn->affected_rows;
        $cerradas += $n;
        if ($n < 1000) break;
        usleep(200000);
    }
    if ($cerradas) { anota(sprintf('visita: %d huérfanas cerradas (más de %d h sin nada colgando)', $cerradas, $horas)); }
}

// ---------------------------------------------------------------------------
// 2 bis) rate_limit_log — topes de peticiones
//
// Hasta la T6-4 esta limpieza vivía DENTRO de app/lib/rate_limiter.php: un
// `DELETE FROM rate_limit_log WHERE created_at < NOW() - INTERVAL 1 HOUR` que corría 1 de
// cada 10 peticiones. Mismo antipatrón que el GC de idempotencia que se movió acá en la
// T5-3: mantenimiento pagado por un usuario esperando.
//
// La tabla es chica —35 filas en el volcado del 2026-09-03— porque el limitador tenía un
// solo llamador. Ahora que también guarda `route_compute` puede crecer, y además la rama
// de APCu ni siquiera la toca. Se limpia acá y se acabó.
// ---------------------------------------------------------------------------
$existeRL = $conn->query("SHOW TABLES LIKE 'rate_limit_log'");
if ($existeRL && $existeRL->num_rows > 0) {
    $sqlRL = "DELETE FROM rate_limit_log WHERE created_at < NOW() - INTERVAL 2 HOUR LIMIT 5000";
    if ($dryRun) {
        $nRL = contar($conn, "SELECT COUNT(*) FROM rate_limit_log WHERE created_at < NOW() - INTERVAL 2 HOUR");
        anota(sprintf('rate_limit_log: %d filas caducadas se borrarían', $nRL));
    } else {
        $borradas = 0;
        while (true) {
            if (!$conn->query($sqlRL)) { break; }
            $b = $conn->affected_rows;
            $borradas += $b;
            if ($b < 5000) break;
            usleep(200000);
        }
        if ($borradas) { anota(sprintf('rate_limit_log: %d filas caducadas borradas', $borradas)); }
    }
}
if ($existeRL) { $existeRL->close(); }

// ---------------------------------------------------------------------------
// 3) Vigilancia de integridad — ¿se siguen creando huérfanos?
//
// El plan (T5-5) proponía poner claves foráneas en el núcleo. Al contar sobre el volcado
// del 2026-09-03 salió que no se puede todavía: hay 122.066 filas de fotoVisita con
// visita_id = 0 en una columna NOT NULL, y 56 K/59 K asignaciones de formularioQuestion
// apuntando a locales y usuarios que ya no existen. Una FK sobre eso falla, y limpiarlo
// es una decisión de negocio, no una migración (ver 35_integridad_diagnostico.sql).
//
// Esto es lo que sí se puede hacer hoy, y es el 90% del valor de una FK sin ninguno de
// sus riesgos: mirar si el código SIGUE produciendo huérfanos. Sólo se miran las filas
// recientes, para que la comprobación sea barata: la pregunta no es "cuánta basura hay"
// —eso ya se sabe— sino "¿seguimos fabricándola?".
//
// Cuando esto lleve semanas dando cero, se sabrá que el código dejó de producirlos y
// entonces sí tendrá sentido limpiar el histórico y poner las FK.
// ---------------------------------------------------------------------------
$nuevosHuerfanos = contar($conn,
    "SELECT COUNT(*)
       FROM form_question_responses r
       LEFT JOIN form_questions q ON q.id = r.id_form_question
      WHERE r.created_at >= NOW() - INTERVAL 2 DAY
        AND q.id IS NULL");

$nuevasHuerfanasFQ = contar($conn,
    "SELECT COUNT(*)
       FROM formularioQuestion fq
       LEFT JOIN local l ON l.id = fq.id_local
      WHERE fq.created_at >= NOW() - INTERVAL 2 DAY
        AND fq.id_local IS NOT NULL AND fq.id_local <> 0
        AND l.id IS NULL");

if ($nuevosHuerfanos > 0 || $nuevasHuerfanasFQ > 0) {
    // Se anota siempre, incluso con --quiet: es justo lo que hay que ver en el log.
    anota('INTEGRIDAD — se están creando huérfanos NUEVOS (últimas 48 h):');
    if ($nuevosHuerfanos > 0) {
        anota(sprintf('  %d respuestas de encuesta cuya pregunta no existe', $nuevosHuerfanos));
    }
    if ($nuevasHuerfanasFQ > 0) {
        anota(sprintf('  %d asignaciones a un local que no existe', $nuevasHuerfanasFQ));
    }
    anota('  Esto NO es historia: es un fallo activo. Ver scripts/35_integridad_diagnostico.sql');
}

// ---------------------------------------------------------------------------
// Salida
// ---------------------------------------------------------------------------
$ms = (int)round((microtime(true) - $t0) * 1000);
$conn->close();

if ($lineas) {
    echo date('c') . ' cron_gc' . ($dryRun ? ' (simulación)' : '') . ":\n";
    foreach ($lineas as $l) { echo '  ' . $l . "\n"; }
    echo '  ' . $ms . " ms\n";
} elseif (!$quiet) {
    echo date('c') . " cron_gc: nada que limpiar ({$ms} ms)\n";
}
exit(0);

/* ---------------------------------------------------------------------------
 * Si tu panel sólo permite programar URLs, no comandos
 * ---------------------------------------------------------------------------
 * NO expongas este archivo por HTTP. Crea en su lugar un envoltorio con un token secreto
 * en .env, que compare con hash_equals() y llame a este script por CLI, o pide al hosting
 * un cron real. Un endpoint de mantenimiento accesible desde la web es una forma barata
 * de que alguien te borre la tabla de idempotencia.
 */

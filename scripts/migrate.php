<?php
declare(strict_types=1);

/**
 * Runner de migraciones de Visibility2.
 *
 * POR QUÉ EXISTE
 * --------------
 * Los cambios de esquema son archivos sueltos en scripts/ que alguien corre a mano en
 * phpMyAdmin. No había registro de cuáles se habían aplicado, así que la única forma de
 * saberlo era mirar el esquema columna por columna. Los síntomas ya estaban a la vista:
 * dos archivos 26_, dos 27_, ningún 24_, y los scripts 12 a 22 perdidos como archivos
 * porque el .gitignore se los tragó antes de tener la excepción para scripts/.
 *
 * CÓMO SE USA
 * -----------
 *   php scripts/migrate.php status         Qué hay aplicado y qué falta (no toca nada)
 *   php scripts/migrate.php dry-run        Qué haría, sentencia por sentencia
 *   php scripts/migrate.php run            Aplica lo pendiente, en orden, y lo registra
 *   php scripts/migrate.php sql            Imprime el SQL pendiente para pegar en phpMyAdmin
 *   php scripts/migrate.php mark-applied   Registra lo pendiente SIN ejecutarlo
 *   php scripts/migrate.php next           Dice qué número toca para el próximo script
 *
 * Opciones:
 *   --file=NOMBRE   Actúa sólo sobre ese archivo (no sobre todo lo pendiente)
 *   --nota="texto"  Nota que queda guardada en el registro
 *   --yes           No pregunta confirmación (para automatizar)
 *
 * SIN ACCESO A SHELL
 * ------------------
 * Si el hosting no da CLI, el camino es:
 *   1. Aplicar scripts/000_migrations.sql a mano en phpMyAdmin.
 *   2. `php scripts/migrate.php sql` en tu equipo (o pedir el SQL), pegarlo en phpMyAdmin.
 *   3. `mark-applied` desde donde sí haya PHP, o pegar a mano el INSERT que imprime `sql`.
 * Este archivo se niega a correr por HTTP a propósito: un runner de migraciones expuesto
 * en la web es una puerta abierta al esquema.
 *
 * SOBRE TRANSACCIONES
 * -------------------
 * No se envuelve cada archivo en una transacción, y no es un olvido: en MySQL el DDL hace
 * COMMIT implícito, así que la transacción no protegería nada y daría una falsa sensación
 * de seguridad. Lo que sí se hace es parar en la primera sentencia que falle y decir
 * exactamente cuál fue, para que se pueda arreglar y reanudar. Por eso los scripts deben
 * escribirse idempotentes (IF NOT EXISTS, guardas con SHOW COLUMNS, etc.).
 */

// ---------------------------------------------------------------------------
// Sólo CLI
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "migrate.php sólo se ejecuta desde la línea de comandos.\n";
    exit(1);
}

$RAIZ     = dirname(__DIR__);
$DIR_SQL  = __DIR__;
$BOOTSTRAP = '000_migrations.sql';

// ---------------------------------------------------------------------------
// Argumentos
// ---------------------------------------------------------------------------
$argumentos = array_slice($argv, 1);
$comando    = 'status';
$opciones   = ['file' => null, 'nota' => null, 'yes' => false];

foreach ($argumentos as $a) {
    if (str_starts_with($a, '--file='))      { $opciones['file'] = substr($a, 7); }
    elseif (str_starts_with($a, '--nota='))  { $opciones['nota'] = substr($a, 7); }
    elseif ($a === '--yes' || $a === '-y')   { $opciones['yes']  = true; }
    elseif (str_starts_with($a, '-'))        { fwrite(STDERR, "Opción desconocida: $a\n"); exit(2); }
    else                                     { $comando = $a; }
}

$COMANDOS = ['status', 'dry-run', 'run', 'sql', 'mark-applied', 'next'];
if (!in_array($comando, $COMANDOS, true)) {
    fwrite(STDERR, "Comando desconocido: $comando\nUsa uno de: " . implode(', ', $COMANDOS) . "\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Salida
// ---------------------------------------------------------------------------
function out(string $s = ''): void { echo $s . PHP_EOL; }
function err(string $s): void { fwrite(STDERR, $s . PHP_EOL); }
function titulo(string $s): void { out(); out($s); out(str_repeat('-', min(78, max(10, strlen($s))))); }

// ---------------------------------------------------------------------------
// Descubrir migraciones
//
// Orden: primero el número del prefijo, y a igualdad el nombre completo. Es un orden
// TOTAL y estable, así que los 26_/27_ duplicados no dependen de cómo los liste el SO.
// No se renombran: sus cambios ya están en producción y el nombre es la clave del
// registro. El problema real —saber qué número usar— lo resuelve el comando `next`.
// ---------------------------------------------------------------------------
function descubrir_migraciones(string $dir, string $bootstrap): array
{
    $archivos = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $lista = [];
    foreach ($archivos as $ruta) {
        $nombre = basename($ruta);
        if ($nombre === $bootstrap) continue;          // el bootstrap se aplica a mano
        $num = preg_match('/^(\d+)/', $nombre, $m) ? (int)$m[1] : PHP_INT_MAX;
        $lista[] = ['version' => $nombre, 'ruta' => $ruta, 'num' => $num];
    }
    usort($lista, static function (array $a, array $b): int {
        return [$a['num'], $a['version']] <=> [$b['num'], $b['version']];
    });
    return $lista;
}

// ---------------------------------------------------------------------------
// Partir un archivo SQL en sentencias.
//
// No sirve un explode(';'): rompe con los ';' dentro de cadenas, dentro de comentarios y
// con los bloques DELIMITER de triggers y procedimientos. Este lector recorre el texto
// carácter a carácter llevando la cuenta de en qué está.
// ---------------------------------------------------------------------------
function partir_sentencias(string $sql): array
{
    $sentencias = [];
    $buffer     = '';
    $delim      = ';';
    $i = 0;
    $n = strlen($sql);

    while ($i < $n) {
        $c = $sql[$i];

        // --- comentario de línea:  -- …   |   # … ---
        if (($c === '-' && substr($sql, $i, 2) === '--' && (($i + 2 >= $n) || ctype_space($sql[$i + 2]) || $sql[$i + 2] === '-'))
            || $c === '#') {
            $fin = strpos($sql, "\n", $i);
            $i = ($fin === false) ? $n : $fin + 1;
            continue;
        }

        // --- comentario de bloque:  /* … */  ---
        // Ojo: /*! … */ son comentarios ejecutables de MySQL. Se conservan tal cual.
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $ejecutable = (($i + 2) < $n) && $sql[$i + 2] === '!';
            $fin = strpos($sql, '*/', $i + 2);
            $hasta = ($fin === false) ? $n : $fin + 2;
            if ($ejecutable) { $buffer .= substr($sql, $i, $hasta - $i); }
            $i = $hasta;
            continue;
        }

        // --- DELIMITER (sólo válido al principio de línea) ---
        if (($c === 'D' || $c === 'd')
            && ($i === 0 || $sql[$i - 1] === "\n" || $sql[$i - 1] === "\r")
            && preg_match('/^DELIMITER[ \t]+(\S+)[ \t]*\r?\n/i', substr($sql, $i), $m)) {
            if (trim($buffer) !== '') { $sentencias[] = trim($buffer); $buffer = ''; }
            $delim = $m[1];
            $i += strlen($m[0]);
            continue;
        }

        // --- cadenas y literales entre comillas o backticks ---
        if ($c === "'" || $c === '"' || $c === '`') {
            $cierre = $c;
            $buffer .= $c;
            $i++;
            while ($i < $n) {
                $d = $sql[$i];
                if ($d === '\\' && $cierre !== '`') {         // escape (no aplica a backticks)
                    $buffer .= substr($sql, $i, 2);
                    $i += 2;
                    continue;
                }
                if ($d === $cierre) {
                    // '' o "" duplicadas dentro de la cadena = comilla literal
                    if (($i + 1) < $n && $sql[$i + 1] === $cierre) {
                        $buffer .= $cierre . $cierre;
                        $i += 2;
                        continue;
                    }
                    $buffer .= $d;
                    $i++;
                    break;
                }
                $buffer .= $d;
                $i++;
            }
            continue;
        }

        // --- fin de sentencia ---
        if ($delim !== '' && substr($sql, $i, strlen($delim)) === $delim) {
            if (trim($buffer) !== '') { $sentencias[] = trim($buffer); }
            $buffer = '';
            $i += strlen($delim);
            continue;
        }

        $buffer .= $c;
        $i++;
    }

    if (trim($buffer) !== '') { $sentencias[] = trim($buffer); }
    return $sentencias;
}

// ---------------------------------------------------------------------------
// Conexión: la misma que usa la app, para no tener dos fuentes de verdad
// ---------------------------------------------------------------------------
function conectar(string $raiz): mysqli
{
    $cargador = $raiz . '/lib_env.php';
    if (is_file($cargador)) {
        require_once $cargador;
        if (function_exists('v2_load_env')) { v2_load_env(); }
    }

    $host = getenv('DB_HOST')     ?: 'localhost';
    $user = getenv('DB_USER')     ?: '';
    $pass = getenv('DB_PASSWORD') ?: '';
    $base = getenv('DB_NAME')     ?: '';

    if ($user === '' || $base === '') {
        err('No hay credenciales de BD. Revisa .env (DB_HOST/DB_USER/DB_PASSWORD/DB_NAME).');
        exit(3);
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, $base);
    if ($conn->connect_error) {
        err('No se pudo conectar a la BD: ' . $conn->connect_error);
        exit(3);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function tabla_control_existe(mysqli $conn): bool
{
    $res = @$conn->query("SHOW TABLES LIKE 'schema_migrations'");
    $ok = ($res && $res->num_rows > 0);
    if ($res) $res->free();
    return $ok;
}

function leer_aplicadas(mysqli $conn): array
{
    $out = [];
    $res = @$conn->query("SELECT version, checksum, aplicado_at, nota FROM schema_migrations");
    if ($res) {
        while ($fila = $res->fetch_assoc()) { $out[$fila['version']] = $fila; }
        $res->free();
    }
    return $out;
}

function registrar(mysqli $conn, string $version, ?string $checksum, ?int $ms, ?int $sentencias, ?string $nota): bool
{
    $st = $conn->prepare("
        INSERT INTO schema_migrations (version, checksum, aplicado_at, aplicado_por, duracion_ms, sentencias, nota)
        VALUES (?, ?, NOW(), ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            checksum = VALUES(checksum), duracion_ms = VALUES(duracion_ms),
            sentencias = VALUES(sentencias), nota = VALUES(nota)
    ");
    if (!$st) { err('No se pudo preparar el registro: ' . $conn->error); return false; }
    $quien = (string)(getenv('USER') ?: getenv('USERNAME') ?: 'cli');
    $st->bind_param('sssiis', $version, $checksum, $quien, $ms, $sentencias, $nota);
    $ok = $st->execute();
    if (!$ok) { err('No se pudo registrar ' . $version . ': ' . $st->error); }
    $st->close();
    return $ok;
}

function confirmar(string $pregunta, bool $auto): bool
{
    if ($auto) return true;
    echo $pregunta . ' [s/N]: ';
    $r = trim((string)fgets(STDIN));
    return in_array(strtolower($r), ['s', 'si', 'sí', 'y', 'yes'], true);
}

// ---------------------------------------------------------------------------
// Comando: next  (no necesita BD)
// ---------------------------------------------------------------------------
$migraciones = descubrir_migraciones($DIR_SQL, $BOOTSTRAP);

if ($comando === 'next') {
    $usados = [];
    foreach ($migraciones as $m) { if ($m['num'] !== PHP_INT_MAX) $usados[$m['num']][] = $m['version']; }
    $max = $usados ? max(array_keys($usados)) : 0;

    titulo('Números en uso');
    for ($i = 1; $i <= $max; $i++) {
        if (!isset($usados[$i])) { out(sprintf('  %02d  (libre)', $i)); continue; }
        $marca = count($usados[$i]) > 1 ? '  <-- DUPLICADO' : '';
        out(sprintf('  %02d  %s%s', $i, implode(', ', $usados[$i]), $marca));
    }
    out();
    out('El próximo script debe llamarse:  ' . sprintf('%02d_descripcion.sql', $max + 1));
    out('(Los huecos no se rellenan: un número libre en medio significa que ese script');
    out(' se perdió o nunca existió, y reusarlo confundiría el registro.)');
    exit(0);
}

// ---------------------------------------------------------------------------
// A partir de aquí hace falta la base
// ---------------------------------------------------------------------------
$conn = conectar($RAIZ);

if (!tabla_control_existe($conn)) {
    err('');
    err('Falta la tabla schema_migrations. Aplica primero, a mano:');
    err('    mysql -u <user> -p <base> < scripts/000_migrations.sql');
    err('(o pega ese archivo en phpMyAdmin). Después vuelve a ejecutar este comando.');
    exit(4);
}

$aplicadas = leer_aplicadas($conn);

// Clasificar
$pendientes = [];
$alteradas  = [];
foreach ($migraciones as $m) {
    $sha = hash_file('sha256', $m['ruta']);
    $m['checksum'] = $sha;
    if (!isset($aplicadas[$m['version']])) {
        $pendientes[] = $m;
        continue;
    }
    $guardado = $aplicadas[$m['version']]['checksum'] ?? null;
    // checksum NULL = reconciliado sin archivo; no se puede comparar y no es un problema.
    if ($guardado !== null && $guardado !== '' && $guardado !== $sha) {
        $alteradas[] = $m;
    }
}

// Migraciones registradas cuyo archivo ya no está (los 12–22 perdidos)
$huerfanas = [];
$conocidas = array_column($migraciones, 'version');
foreach ($aplicadas as $v => $fila) {
    if (!in_array($v, $conocidas, true)) { $huerfanas[] = $v; }
}

// Filtro por --file
if ($opciones['file'] !== null) {
    $pedido = basename($opciones['file']);
    $pendientes = array_values(array_filter($pendientes, static fn($m) => $m['version'] === $pedido));
    if (!$pendientes) {
        err("No hay ninguna migración pendiente llamada '$pedido'.");
        err('(Puede que ya esté aplicada; míralo con: php scripts/migrate.php status)');
        exit(5);
    }
}

// ---------------------------------------------------------------------------
// Comando: status
// ---------------------------------------------------------------------------
if ($comando === 'status') {
    titulo('Aplicadas (' . count($aplicadas) . ')');
    if (!$aplicadas) {
        out('  ninguna todavía');
    } else {
        foreach ($migraciones as $m) {
            if (!isset($aplicadas[$m['version']])) continue;
            $f = $aplicadas[$m['version']];
            $nota = !empty($f['nota']) ? '  — ' . $f['nota'] : '';
            out(sprintf('  %-44s %s%s', $m['version'], $f['aplicado_at'], $nota));
        }
        foreach ($huerfanas as $v) {
            out(sprintf('  %-44s (registrada, archivo ausente)', $v));
        }
    }

    titulo('Pendientes (' . count($pendientes) . ')');
    if (!$pendientes) { out('  0 pendientes'); }
    else { foreach ($pendientes as $m) { out('  ' . $m['version']); } }

    if ($alteradas) {
        titulo('AVISO: archivos editados después de aplicarse (' . count($alteradas) . ')');
        out('  Su contenido ya NO es lo que corrió en la base. Si el cambio nuevo hace falta,');
        out('  va en un archivo nuevo; editar uno aplicado no lo vuelve a ejecutar.');
        foreach ($alteradas as $m) { out('  ' . $m['version']); }
    }

    out();
    exit($pendientes || $alteradas ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Comando: sql  (imprime para pegar en phpMyAdmin)
// ---------------------------------------------------------------------------
if ($comando === 'sql') {
    if (!$pendientes) { out('-- 0 pendientes.'); exit(0); }
    foreach ($pendientes as $m) {
        $sql = (string)file_get_contents($m['ruta']);
        out('-- ===================================================================');
        out('-- ' . $m['version']);
        out('-- ===================================================================');
        out(rtrim($sql));
        out();
        out('-- Y para dejar constancia de que se aplicó:');
        out(sprintf(
            "INSERT INTO schema_migrations (version, checksum, aplicado_at, aplicado_por, nota)\n" .
            "VALUES ('%s', '%s', NOW(), 'phpmyadmin', 'aplicado a mano')\n" .
            "ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), aplicado_at=VALUES(aplicado_at);",
            $conn->real_escape_string($m['version']),
            $conn->real_escape_string($m['checksum'])
        ));
        out();
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Comando: dry-run
// ---------------------------------------------------------------------------
if ($comando === 'dry-run') {
    if (!$pendientes) { out('0 pendientes. No haría nada.'); exit(0); }
    foreach ($pendientes as $m) {
        $sentencias = partir_sentencias((string)file_get_contents($m['ruta']));
        titulo($m['version'] . '  (' . count($sentencias) . ' sentencias)');
        foreach ($sentencias as $k => $s) {
            $resumen = preg_replace('/\s+/', ' ', $s);
            out(sprintf('  %2d. %s', $k + 1, mb_strimwidth($resumen, 0, 150, '…')));
        }
    }
    out();
    out('Nada de esto se ejecutó. Para aplicarlo:  php scripts/migrate.php run');
    exit(0);
}

// ---------------------------------------------------------------------------
// Comando: mark-applied  (registra SIN ejecutar — la reconciliación inicial)
// ---------------------------------------------------------------------------
if ($comando === 'mark-applied') {
    if (!$pendientes) { out('0 pendientes que marcar.'); exit(0); }

    titulo('Se van a marcar como APLICADAS sin ejecutarlas (' . count($pendientes) . ')');
    foreach ($pendientes as $m) { out('  ' . $m['version']); }
    out();
    out('Úsalo sólo cuando ya sepas que esos cambios ESTÁN en la base.');

    if (!confirmar('¿Marcar como aplicadas?', $opciones['yes'])) { out('Cancelado.'); exit(0); }

    $nota = $opciones['nota'] ?? 'reconciliado: aplicado antes del runner';
    $n = 0;
    foreach ($pendientes as $m) {
        $sentencias = count(partir_sentencias((string)file_get_contents($m['ruta'])));
        if (registrar($conn, $m['version'], $m['checksum'], null, $sentencias, $nota)) { $n++; }
    }
    out("Marcadas $n de " . count($pendientes) . '.');
    exit($n === count($pendientes) ? 0 : 6);
}

// ---------------------------------------------------------------------------
// Comando: run
// ---------------------------------------------------------------------------
if ($alteradas && $opciones['file'] === null) {
    err('');
    err('Hay archivos ya aplicados que fueron editados después:');
    foreach ($alteradas as $m) { err('  - ' . $m['version']); }
    err('Revísalos antes de seguir (php scripts/migrate.php status). No se ejecuta nada.');
    exit(7);
}

if (!$pendientes) { out('0 pendientes. La base está al día.'); exit(0); }

titulo('Se van a APLICAR (' . count($pendientes) . ')');
foreach ($pendientes as $m) { out('  ' . $m['version']); }
out();
out('Base: ' . (getenv('DB_NAME') ?: '?') . ' en ' . (getenv('DB_HOST') ?: 'localhost'));
out('Recuerda: en MySQL el DDL no se puede deshacer con un ROLLBACK. Ten un respaldo.');

if (!confirmar('¿Aplicar?', $opciones['yes'])) { out('Cancelado.'); exit(0); }

$fallos = 0;
foreach ($pendientes as $m) {
    $sentencias = partir_sentencias((string)file_get_contents($m['ruta']));
    out();
    out('==> ' . $m['version'] . ' (' . count($sentencias) . ' sentencias)');

    $t0 = microtime(true);
    $ok = true;
    foreach ($sentencias as $k => $s) {
        if (!@$conn->query($s)) {
            $ok = false;
            err(sprintf('    FALLÓ en la sentencia %d de %d:', $k + 1, count($sentencias)));
            err('      ' . mb_strimwidth(preg_replace('/\s+/', ' ', $s), 0, 200, '…'));
            err('      MySQL dice: ' . $conn->error);
            err('');
            err('    Las sentencias anteriores YA se aplicaron (el DDL de MySQL hace commit');
            err('    implícito). Arregla el archivo para que sea reidempotente desde el');
            err('    principio y vuelve a lanzarlo con --file=' . $m['version'] . '.');
            break;
        }
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($ok) {
        registrar($conn, $m['version'], $m['checksum'], $ms, count($sentencias), $opciones['nota']);
        out(sprintf('    OK (%d ms)', $ms));
    } else {
        $fallos++;
        break;   // no se sigue con las siguientes: el orden importa
    }
}

out();
if ($fallos) { err('Terminado CON ERRORES. Revisa lo de arriba.'); exit(8); }
out('Listo. Comprueba con: php scripts/migrate.php status');
exit(0);

<?php
declare(strict_types=1);

/**
 * Bloqueo temporal de cuenta por intentos fallidos.
 *
 * POR QUÉ HACÍA FALTA
 * -------------------
 * `procesar_login.php` ya tenía un límite: 10 intentos en 15 minutos por (usuario, IP),
 * apoyado en la tabla `login_attempts`. El problema es la clave: **por IP**. Quien rote
 * direcciones —cualquier botnet, o simplemente datos móviles— tiene intentos ilimitados
 * contra una cuenta concreta.
 *
 * La tabla `user_security` existe desde hace meses, con `failed_count`, `lock_until`,
 * `consecutive_locks` y hasta `notify_email`. Estaba **completamente sin cablear**:
 * `procesar_login.php` no la mencionaba ni una vez. Este archivo la conecta.
 *
 * Las dos protecciones son complementarias y se quedan las dos:
 *   - `login_attempts` cubre usuarios que NO existen y ataques por fuerza bruta desde una IP.
 *   - `user_security` cubre una cuenta concreta, venga el intento de donde venga.
 *
 * EL RIESGO QUE SE ACEPTA, Y CÓMO SE ACOTA
 * ----------------------------------------
 * Un bloqueo por usuario se puede usar en contra: quien conozca el RUT de alguien puede
 * dejarlo fuera fallando la contraseña a propósito. Por eso el castigo empieza en **un
 * minuto**, no en una hora, y sube sólo si se repite:
 *
 *     bloqueo 1º -> 1 min     bloqueo 3º -> 15 min
 *     bloqueo 2º -> 5 min     bloqueo 4º y siguientes -> 60 min
 *
 * Y `consecutive_locks` **caduca**: si hace más de 24 h que no hay un fallo, el contador
 * vuelve a cero. Sin eso, un incidente de hace tres meses seguiría castigando hoy con una
 * hora de bloqueo a la primera.
 *
 * FALLA ABIERTO, A PROPÓSITO
 * --------------------------
 * Si algo va mal con esta tabla, el login SIGUE funcionando y se anota en el log. Un fallo
 * aquí dejaría a toda la fuerza de terreno sin poder trabajar, y el límite por IP de
 * `login_attempts` sigue en pie mientras tanto. Es una decisión consciente, no un descuido.
 */

if (!defined('V2_LOGIN_UMBRAL_FALLOS')) {
    // Fallos seguidos antes de bloquear. Cinco deja margen para un dedazo y un teclado
    // con mayúsculas activadas sin castigar a nadie por despiste.
    define('V2_LOGIN_UMBRAL_FALLOS', 5);
}
if (!defined('V2_LOGIN_OLVIDO_HORAS')) {
    // Horas sin fallos tras las cuales se olvida el historial de bloqueos.
    define('V2_LOGIN_OLVIDO_HORAS', 24);
}

/** Minutos de bloqueo según cuántas veces ya se bloqueó seguido. */
function v2_login_minutos_bloqueo(int $bloqueosPrevios): int
{
    $escala = [1, 5, 15, 60];
    $i = max(0, min($bloqueosPrevios, count($escala) - 1));
    return $escala[$i];
}

/**
 * ¿Está la cuenta bloqueada ahora mismo?
 *
 * @return int Minutos que faltan para poder reintentar (0 = no está bloqueada).
 */
function v2_login_minutos_restantes(mysqli $conn, int $userId): int
{
    if ($userId <= 0) return 0;
    try {
        $st = $conn->prepare(
            "SELECT GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), lock_until)) + 1 AS faltan
               FROM user_security
              WHERE user_id = ? AND lock_until IS NOT NULL AND lock_until > NOW()
              LIMIT 1"
        );
        if (!$st) return 0;
        $st->bind_param('i', $userId);
        $st->execute();
        $st->bind_result($faltan);
        $n = $st->fetch() ? (int)$faltan : 0;
        $st->close();
        return max(0, $n);
    } catch (Throwable $e) {
        error_log('[login_seguridad] no se pudo leer el bloqueo: ' . $e->getMessage());
        return 0;   // falla abierto
    }
}

/**
 * Registra un intento fallido. Si se alcanza el umbral, bloquea la cuenta.
 *
 * @return int Minutos de bloqueo aplicados (0 = todavía no se bloqueó).
 */
function v2_login_registrar_fallo(mysqli $conn, int $userId): int
{
    if ($userId <= 0) return 0;
    try {
        // Estado actual, aplicando ya el olvido por antigüedad.
        $st = $conn->prepare(
            "SELECT failed_count, consecutive_locks,
                    (last_failed_at IS NULL
                     OR last_failed_at < NOW() - INTERVAL " . V2_LOGIN_OLVIDO_HORAS . " HOUR) AS caducado
               FROM user_security
              WHERE user_id = ?
              LIMIT 1"
        );
        $fallos = 0; $bloqueos = 0;
        if ($st) {
            $st->bind_param('i', $userId);
            $st->execute();
            $st->bind_result($f, $b, $caducado);
            if ($st->fetch()) {
                // Si hace más de un día que no falla, se empieza de cero: ni el contador
                // de fallos ni el historial de bloqueos deben arrastrarse para siempre.
                $fallos   = $caducado ? 0 : (int)$f;
                $bloqueos = $caducado ? 0 : (int)$b;
            }
            $st->close();
        }

        $fallos++;
        $minutos = 0;

        if ($fallos >= V2_LOGIN_UMBRAL_FALLOS) {
            $minutos  = v2_login_minutos_bloqueo($bloqueos);
            $bloqueos = min($bloqueos + 1, 999);
            $fallos   = 0;   // el contador se reinicia; lo que persiste es el bloqueo
        }

        $sql = $minutos > 0
            ? "INSERT INTO user_security (user_id, failed_count, consecutive_locks, last_failed_at, lock_until, updated_at)
               VALUES (?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE, NOW())
               ON DUPLICATE KEY UPDATE
                   failed_count      = VALUES(failed_count),
                   consecutive_locks = VALUES(consecutive_locks),
                   last_failed_at    = NOW(),
                   lock_until        = NOW() + INTERVAL ? MINUTE,
                   updated_at        = NOW()"
            : "INSERT INTO user_security (user_id, failed_count, consecutive_locks, last_failed_at, updated_at)
               VALUES (?, ?, ?, NOW(), NOW())
               ON DUPLICATE KEY UPDATE
                   failed_count      = VALUES(failed_count),
                   consecutive_locks = VALUES(consecutive_locks),
                   last_failed_at    = NOW(),
                   updated_at        = NOW()";

        $up = $conn->prepare($sql);
        if ($up) {
            if ($minutos > 0) { $up->bind_param('iiiii', $userId, $fallos, $bloqueos, $minutos, $minutos); }
            else              { $up->bind_param('iii',   $userId, $fallos, $bloqueos); }
            $up->execute();
            $up->close();
        }

        if ($minutos > 0) {
            error_log(sprintf('[login_seguridad] cuenta %d bloqueada %d min (bloqueo nº %d)',
                $userId, $minutos, $bloqueos));
        }
        return $minutos;

    } catch (Throwable $e) {
        error_log('[login_seguridad] no se pudo registrar el fallo: ' . $e->getMessage());
        return 0;   // falla abierto
    }
}

/**
 * Login correcto: borra el castigo y deja constancia de desde dónde se entró.
 */
function v2_login_registrar_exito(mysqli $conn, int $userId, string $ip, string $ua): void
{
    if ($userId <= 0) return;
    try {
        $up = $conn->prepare(
            "INSERT INTO user_security
                 (user_id, failed_count, consecutive_locks, lock_until,
                  last_success_at, last_success_ip, last_success_ua, updated_at)
             VALUES (?, 0, 0, NULL, NOW(), INET6_ATON(?), ?, NOW())
             ON DUPLICATE KEY UPDATE
                 failed_count      = 0,
                 consecutive_locks = 0,
                 lock_until        = NULL,
                 last_success_at   = NOW(),
                 last_success_ip   = INET6_ATON(?),
                 last_success_ua   = ?,
                 updated_at        = NOW()"
        );
        if (!$up) return;
        $ua = substr($ua, 0, 255);
        $up->bind_param('issss', $userId, $ip, $ua, $ip, $ua);
        $up->execute();
        $up->close();
    } catch (Throwable $e) {
        error_log('[login_seguridad] no se pudo limpiar tras el login: ' . $e->getMessage());
    }
}

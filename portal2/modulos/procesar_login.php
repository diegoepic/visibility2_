<?php
declare(strict_types=1);

ob_start();

$secure =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    || (stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

register_shutdown_function(function () {
    $e = error_get_last();

    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[procesar_login][shutdown] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

/* ===================== Config ===================== */
const LOGIN_URL             = '/visibility2/portal/index.php';
const HOME_URL              = '/visibility2/portal/home.php';
const USER_HOME_MAP = [
    'mgomez'   => '/visibility2/portal/home.php',
    /*'dalarcon' => '/visibility2/portal/home2.php',
    'ymaray'   => '/visibility2/portal/home2.php',*/
];

const FAILED_WINDOW_MINUTES = 15;
const FAILED_THRESHOLD      = 10;
const BACKOFF_BASE_MS       = 60;
const BACKOFF_STEP_MS       = 20;
const BACKOFF_MAX_MS        = 600;

/* ===================== Helpers ===================== */

function clear_output_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function safe_redirect(string $url): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    clear_output_buffers();

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Location: ' . $url, true, 302);
        exit();
    }

    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    echo '</head><body>';
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '</body></html>';
    exit();
}

function resolve_home_url(array $user): string
{
    $usuario = mb_strtolower(trim((string)($user['usuario'] ?? '')));

    return USER_HOME_MAP[$usuario] ?? HOME_URL;
}

function lockDurationByHistory(int $locks24h): string
{
    if ($locks24h >= 3) return '24 HOUR';
    if ($locks24h >= 1) return '60 MINUTE';
    return '15 MINUTE';
}

function app_key(): string
{
    $k = getenv('APP_KEY');
    return $k && strlen($k) >= 16 ? $k : 'change_this_APP_KEY_in_env_please';
}

function mail_from(): string
{
    $m = getenv('MAIL_FROM');
    return $m && strpos($m, '@') !== false ? $m : 'no-reply@visibility.cl';
}

function ip_addr(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function user_agent(): string
{
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function jsons(array $a): string
{
    return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function micro_backoff(int $failedCount = 0): void
{
    $ms = BACKOFF_BASE_MS + $failedCount * BACKOFF_STEP_MS + random_int(0, 50);
    $ms = min($ms, BACKOFF_MAX_MS);
    usleep($ms * 1000);
}

function fail_and_back_generic(): never
{
    $_SESSION['error_login'] = 'Usuario o contraseña incorrectos.';
    safe_redirect(LOGIN_URL);
}

function fail_and_back_locked(string $until): never
{
    $_SESSION['error_login'] = 'Cuenta bloqueada temporalmente. Intenta nuevamente después de: ' . $until;
    safe_redirect(LOGIN_URL);
}

function send_security_email(string $to, string $subject, string $textBody): void
{
    if (!$to || strpos($to, '@') === false) {
        return;
    }

    $headers = [];
    $headers[] = 'From: ' . mail_from();
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $textBody,
        implode("\r\n", $headers)
    );
}

function log_attempt(mysqli $conn, ?int $userId, string $usuarioInput, string $outcome, string $reason): void
{
    $sql = "INSERT INTO login_attempts (usuario, ip, intento_at, user_id, outcome, reason)
            VALUES (?, INET6_ATON(?), NOW(), ?, ?, ?)";

    if ($st = $conn->prepare($sql)) {
        $ip = ip_addr();
        $st->bind_param('ssiss', $usuarioInput, $ip, $userId, $outcome, $reason);
        $st->execute();
        $st->close();
    }
}

function log_security_event(mysqli $conn, ?int $userId, string $type, array $meta = []): void
{
    $sql = "INSERT INTO security_events (user_id, type, ip, user_agent, meta_json, created_at)
            VALUES (?, ?, INET6_ATON(?), ?, ?, NOW())";

    if ($st = $conn->prepare($sql)) {
        $ip = ip_addr();
        $ua = user_agent();
        $metaJson = jsons($meta);
        $st->bind_param('issss', $userId, $type, $ip, $ua, $metaJson);
        $st->execute();
        $st->close();
    }
}

function ensure_user_security_row(mysqli $conn, int $userId): void
{
    $sql = "INSERT IGNORE INTO user_security (user_id, failed_count, consecutive_locks, notify_email, updated_at)
            VALUES (?, 0, 0, 1, NOW())";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $st->close();
    }
}

function get_user_security(mysqli $conn, int $userId): ?array
{
    $sql = "SELECT failed_count, lock_until, consecutive_locks, last_failed_at, last_success_at,
                   INET6_NTOA(last_success_ip) AS last_success_ip_txt,
                   last_success_ua, notify_email
            FROM user_security
            WHERE user_id = ?
            LIMIT 1";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    return null;
}

function set_lock(mysqli $conn, int $userId, string $lockIntervalSpec, array $meta): void
{
    $sql = "UPDATE user_security
            SET lock_until = DATE_ADD(NOW(), INTERVAL {$lockIntervalSpec}),
                failed_count = 0,
                consecutive_locks = consecutive_locks + 1,
                updated_at = NOW()
            WHERE user_id = ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $st->close();
    }

    log_security_event($conn, $userId, 'account_locked', $meta);
}

function reset_failures_and_lock(mysqli $conn, int $userId): void
{
    $sql = "UPDATE user_security
            SET failed_count = 0, lock_until = NULL, updated_at = NOW()
            WHERE user_id = ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $st->close();
    }
}

function increment_failed(mysqli $conn, int $userId): void
{
    $sql = "UPDATE user_security
            SET failed_count = failed_count + 1, last_failed_at = NOW(), updated_at = NOW()
            WHERE user_id = ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $userId);
        $st->execute();
        $st->close();
    }
}

function count_failures_last_minutes(mysqli $conn, int $userId, int $minutes): int
{
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);

    $sql = "SELECT COUNT(*) AS n
            FROM login_attempts
            WHERE user_id = ?
              AND outcome = 'failure'
              AND intento_at > ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('is', $userId, $since);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($res['n'] ?? 0);
    }

    return 0;
}

function count_locks_last_24h(mysqli $conn, int $userId): int
{
    $since = date('Y-m-d H:i:s', time() - 24 * 3600);

    $sql = "SELECT COUNT(*) AS n
            FROM security_events
            WHERE user_id = ?
              AND type = 'account_locked'
              AND created_at > ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('is', $userId, $since);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        $st->close();
        return (int)($res['n'] ?? 0);
    }

    return 0;
}

if (!function_exists('fpr_salt')) {
    function fpr_salt(): string
    {
        $k = getenv('APP_KEY');
        return ($k && strlen($k) >= 16) ? $k : 'VISIBILITY_FPR_SALT_v1';
    }
}

if (!function_exists('session_fingerprint')) {
    function session_fingerprint(): string
    {
        $sid  = session_id();
        $salt = fpr_salt();
        return hash('sha256', $sid . '|' . $salt, true);
    }
}

function upsert_session(mysqli $conn, int $userId, string $fpr): void
{
    $sql = "INSERT INTO user_sessions (user_id, session_fpr, created_at, last_seen_at, ip, user_agent)
            VALUES (?, ?, NOW(), NOW(), INET6_ATON(?), ?)";

    if ($st = $conn->prepare($sql)) {
        $ip = ip_addr();
        $ua = user_agent();
        $st->bind_param('isss', $userId, $fpr, $ip, $ua);
        $st->execute();
        $st->close();
    }
}

function revoke_other_sessions(mysqli $conn, int $userId, string $currentFpr): void
{
    $sql = "UPDATE user_sessions
            SET revoked_at = NOW()
            WHERE user_id = ?
              AND revoked_at IS NULL
              AND session_fpr <> ?";

    if ($st = $conn->prepare($sql)) {
        $st->bind_param('is', $userId, $currentFpr);
        $st->execute();
        $st->close();
    }
}

function is_new_device_or_network(?array $sec): bool
{
    if (!$sec) return false;
    if (empty($sec['last_success_at'])) return false;

    $prevUA = (string)($sec['last_success_ua'] ?? '');
    $prevIP = (string)($sec['last_success_ip_txt'] ?? '');
    $currUA = user_agent();
    $currIP = ip_addr();

    $uaChanged = ($prevUA !== '' && $prevUA !== $currUA);
    $ipChanged = ($prevIP !== '' && $prevIP !== $currIP);

    return $uaChanged || $ipChanged;
}

function update_last_success(mysqli $conn, int $userId): void
{
    $sql = "UPDATE user_security
            SET last_success_at = NOW(),
                last_success_ip = INET6_ATON(?),
                last_success_ua = ?,
                updated_at = NOW()
            WHERE user_id = ?";

    if ($st = $conn->prepare($sql)) {
        $ip = ip_addr();
        $ua = user_agent();
        $st->bind_param('ssi', $ip, $ua, $userId);
        $st->execute();
        $st->close();
    }
}

try {
    /* ===================== Entrada ===================== */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        safe_redirect(LOGIN_URL);
    }

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        fail_and_back_generic();
    }

    unset($_SESSION['csrf_token']);

    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $clave   = (string)($_POST['clave'] ?? '');

    if ($usuario === '' || $clave === '') {
        fail_and_back_generic();
    }

    /* ===================== Buscar usuario ===================== */

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.rut,
            u.nombre,
            u.apellido,
            u.email,
            u.usuario,
            u.fotoPerfil,
            u.clave,
            u.id_perfil,
            u.id_empresa,
            u.id_division,
            u.login_count,
            u.fechaCreacion,
            u.telefono,
            p.nombre AS perfil_nombre,
            e.nombre AS empresa_nombre
        FROM usuario AS u
        INNER JOIN perfil AS p  ON p.id = u.id_perfil
        INNER JOIN empresa AS e ON e.id = u.id_empresa
        WHERE (u.usuario = ? OR u.email = ?)
          AND u.activo = 1
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta de login: ' . $conn->error);
    }

    $stmt->bind_param('ss', $usuario, $usuario);
    $stmt->execute();
    $res   = $stmt->get_result();
    $found = $res && $res->num_rows === 1;
    $u     = $found ? $res->fetch_assoc() : null;
    $stmt->close();

    /* ===================== Chequear lock por cuenta ===================== */

    if ($found) {
        ensure_user_security_row($conn, (int)$u['id']);
        $sec = get_user_security($conn, (int)$u['id']);

        if ($sec && !empty($sec['lock_until'])) {
            $lockUntil = (string)$sec['lock_until'];
            $now       = now_str();

            if ($lockUntil > $now) {
                log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'locked');
                fail_and_back_locked($lockUntil);
            }
        }
    }

    /* ===================== Verificación de contraseña ===================== */

    $valid = $found && password_verify($clave, (string)($u['clave'] ?? ''));

    /* ===================== FALLÓ ===================== */

    if (!$valid) {
        if ($found) {
            increment_failed($conn, (int)$u['id']);
            log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'bad_password');

            $fails = count_failures_last_minutes($conn, (int)$u['id'], FAILED_WINDOW_MINUTES);

            if ($fails >= FAILED_THRESHOLD) {
                $locks24  = count_locks_last_24h($conn, (int)$u['id']);
                $interval = lockDurationByHistory($locks24);

                set_lock(
                    $conn,
                    (int)$u['id'],
                    $interval,
                    [
                        'reason'   => 'threshold_reached',
                        'window'   => FAILED_WINDOW_MINUTES . 'm',
                        'fails'    => $fails,
                        'duration' => $interval
                    ]
                );

                if (!empty($u['email'])) {
                    $untilQ = $conn->prepare("
                        SELECT DATE_FORMAT(lock_until, '%Y-%m-%d %H:%i:%s') AS lu
                        FROM user_security
                        WHERE user_id = ?
                        LIMIT 1
                    ");

                    $untilStr = 'próximos minutos';

                    if ($untilQ) {
                        $uid = (int)$u['id'];
                        $untilQ->bind_param('i', $uid);
                        $untilQ->execute();
                        $rowU = $untilQ->get_result()->fetch_assoc();
                        $untilStr = $rowU['lu'] ?? $untilStr;
                        $untilQ->close();
                    }

                    $msg = "Hola {$u['nombre']},\n\n"
                        . "Tu cuenta fue bloqueada temporalmente por múltiples intentos fallidos.\n"
                        . "Bloqueo hasta: {$untilStr}\n\n"
                        . "IP reciente: " . ip_addr() . "\n"
                        . "Navegador: " . user_agent() . "\n\n"
                        . "Si no fuiste tú, te recomendamos cambiar tu contraseña al recuperar el acceso.\n\n"
                        . "Equipo Visibility";

                    send_security_email((string)$u['email'], 'Tu cuenta ha sido bloqueada temporalmente', $msg);
                }

                fail_and_back_locked($untilStr ?? 'próximos minutos');
            }

            $secRow      = get_user_security($conn, (int)$u['id']);
            $failedCount = (int)($secRow['failed_count'] ?? 0);
            micro_backoff($failedCount);
        } else {
            log_attempt($conn, null, $usuario, 'failure', 'unknown_user');
            micro_backoff(1);
        }

        fail_and_back_generic();
    }

    /* ===================== ÉXITO ===================== */

    if ((int)$u['id_perfil'] === 3) {
        log_attempt($conn, (int)$u['id'], $usuario, 'failure', 'perfil_denegado');
        $_SESSION['error_login'] = 'Perfil sin permisos, ingresa desde la app.';
        safe_redirect(LOGIN_URL);
    }

    $secBefore = get_user_security($conn, (int)$u['id']);

    reset_failures_and_lock($conn, (int)$u['id']);

    $needRehash = defined('PASSWORD_ARGON2ID')
        ? password_needs_rehash((string)$u['clave'], PASSWORD_ARGON2ID)
        : password_needs_rehash((string)$u['clave'], PASSWORD_DEFAULT);

    if ($needRehash) {
        $newHash = defined('PASSWORD_ARGON2ID')
            ? password_hash($clave, PASSWORD_ARGON2ID)
            : password_hash($clave, PASSWORD_DEFAULT);

        if ($up = $conn->prepare('UPDATE usuario SET clave = ? WHERE id = ?')) {
            $uid = (int)$u['id'];
            $up->bind_param('si', $newHash, $uid);
            $up->execute();
            $up->close();
        }
    }

    if ($up = $conn->prepare('UPDATE usuario SET login_count = login_count + 1, last_login = NOW() WHERE id = ?')) {
        $uid = (int)$u['id'];
        $up->bind_param('i', $uid);
        $up->execute();
        $up->close();
    }

    log_attempt($conn, (int)$u['id'], $usuario, 'success', 'login_ok');

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('No fue posible regenerar la sesión.');
    }

    $_SESSION['usuario_id']              = (int)$u['id'];
    $_SESSION['usuario_nombre']          = (string)$u['nombre'];
    $_SESSION['usuario_apellido']        = (string)$u['apellido'];
    $_SESSION['usuario_fotoPerfil']      = (string)$u['fotoPerfil'];
    $_SESSION['usuario_perfil']          = (int)$u['id_perfil'];
    $_SESSION['perfil_nombre']           = (string)$u['perfil_nombre'];
    $_SESSION['empresa_nombre']          = (string)$u['empresa_nombre'];
    $_SESSION['empresa_id']              = (int)$u['id_empresa'];
    $_SESSION['usuario_fechaCreacion']   = $u['fechaCreacion'];
    $_SESSION['email']                   = (string)$u['email'];
    $_SESSION['telefono']                = (string)$u['telefono'];
    $_SESSION['division_id']             = isset($u['id_division']) ? (int)$u['id_division'] : 0;

    $fpr = session_fingerprint();
    revoke_other_sessions($conn, (int)$u['id'], $fpr);
    upsert_session($conn, (int)$u['id'], $fpr);

    if (is_new_device_or_network($secBefore)) {
        if (!empty($u['email'])) {
            $msg = "Hola {$u['nombre']},\n\n"
                . "Detectamos un nuevo inicio de sesión en tu cuenta.\n"
                . "Fecha: " . now_str() . "\n"
                . "IP: " . ip_addr() . "\n"
                . "Navegador: " . user_agent() . "\n\n"
                . "Si no fuiste tú, te recomendamos cambiar tu contraseña y contactar a soporte.\n\n"
                . "Equipo Visibility";

            send_security_email((string)$u['email'], 'Nuevo inicio de sesión en tu cuenta', $msg);
        }

        log_security_event($conn, (int)$u['id'], 'new_device', []);
    }

update_last_success($conn, (int)$u['id']);

safe_redirect(resolve_home_url($u));

} catch (Throwable $e) {
    error_log('[procesar_login] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail_and_back_generic();
}
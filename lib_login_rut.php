<?php
/**
 * Regla de acceso por RUT — compartida por app/ y portal/.
 *
 * Desde LOGIN_SOLO_RUT_DESDE el ingreso deja de aceptar el nombre de usuario:
 *   - app    → sólo RUT
 *   - portal → RUT o email (el email se mantiene por decisión del negocio)
 *
 * El cambio NO requiere subir nada ese día: el corte lo decide la fecha, así
 * que el código puede quedar publicado desde antes y se activa solo.
 *
 * Vive en la raíz de visibility2/ y no dentro de app/ o portal/ porque los dos
 * lados tienen que coincidir en la fecha. Ojo: portal/con_.php NO carga el
 * .env (tiene las credenciales fijas), así que la fecha va acá como constante
 * y el .env sólo puede sobrescribirla del lado de la app.
 */

if (!defined('LOGIN_SOLO_RUT_DESDE_DEFAULT')) {
    define('LOGIN_SOLO_RUT_DESDE_DEFAULT', '2026-09-01');
}

if (!function_exists('login_zona_horaria')) {
    function login_zona_horaria(): DateTimeZone {
        return new DateTimeZone('America/Santiago');
    }
}

if (!function_exists('login_fecha_corte')) {
    /**
     * Fecha desde la cual rige el ingreso por RUT, en Y-m-d.
     * Se puede mover con LOGIN_SOLO_RUT_DESDE en app/.env (sólo afecta a la
     * app); dejarla vacía NO desactiva la regla, para eso hay que cambiar la
     * constante de arriba.
     */
    function login_fecha_corte(): string {
        $env = getenv('LOGIN_SOLO_RUT_DESDE');
        $env = is_string($env) ? trim($env) : '';
        if ($env !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $env)) {
            return $env;
        }
        return LOGIN_SOLO_RUT_DESDE_DEFAULT;
    }
}

if (!function_exists('login_solo_rut_activo')) {
    /** ¿Ya rige el ingreso sólo por RUT? */
    function login_solo_rut_activo(): bool {
        $hoy = new DateTimeImmutable('now', login_zona_horaria());
        return $hoy->format('Y-m-d') >= login_fecha_corte();
    }
}

if (!function_exists('login_dias_para_corte')) {
    /** Días que faltan para el corte (0 si ya rige). Sirve para el aviso. */
    function login_dias_para_corte(): int {
        if (login_solo_rut_activo()) return 0;
        $tz    = login_zona_horaria();
        $hoy   = new DateTimeImmutable('today', $tz);
        $corte = new DateTimeImmutable(login_fecha_corte(), $tz);
        return max(0, (int)$hoy->diff($corte)->format('%a'));
    }
}

if (!function_exists('login_normalizar_rut')) {
    /**
     * Deja el RUT como se guarda en la tabla `usuario`: sin puntos, sin guion
     * y con el dígito verificador en mayúscula. Es la MISMA transformación que
     * hace mod_user/procesar.php al crear el usuario, así que lo tecleado y lo
     * almacenado se comparan en igualdad de condiciones.
     *
     * Se aplica a lo que la persona escribe para que dé lo mismo si teclea
     * 12.345.678-9 o 123456789: el requerimiento pide "sin puntos ni guion",
     * pero rechazar por un punto sólo genera llamadas a soporte.
     */
    function login_normalizar_rut(string $valor): string {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $valor) ?? '');
    }
}

if (!function_exists('login_texto_aviso')) {
    /**
     * Texto del aviso previo al corte. Se arma acá para que la app y el portal
     * digan exactamente lo mismo.
     */
    function login_texto_aviso(string $rut): string {
        $dias  = login_dias_para_corte();
        $corte = date('d/m/Y', strtotime(login_fecha_corte()));
        $cuando = $dias === 0 ? 'desde hoy'
            : ($dias === 1 ? 'desde mañana' : "en {$dias} días (el {$corte})");
        return "A partir del {$corte} el ingreso será solo con tu RUT, sin puntos ni guion. "
             . "El tuyo es {$rut}. Empieza a usarlo {$cuando}.";
    }
}

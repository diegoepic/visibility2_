<?php
// mod_user/generate_token.php

/**
 * Genera un token CSRF seguro.
 *
 * @param int $length La longitud deseada del token en bytes.
 * @return string El token CSRF generado en formato hexadecimal.
 */
function generate_csrf_token($length = 32) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($length));
    } else {
        // Como última opción, usar mt_rand (menos seguro)
        $token = '';
        for ($i = 0; $i < $length * 2; $i++) {
            $token .= dechex(mt_rand(0, 15));
        }
        return $token;
    }
}
?>

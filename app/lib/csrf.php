<?php
/** Genera o devuelve el token CSRF actual */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Imprime el `<input>` oculto para incluir en todos los formularios */
function csrf_input(): void {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    echo "<input type=\"hidden\" name=\"csrf_token\" value=\"{$t}\">";
}

/** Lanza excepci¨®n si el token POST no coincide con el de sesi¨®n */
function csrf_validate(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            empty($_POST['csrf_token'])
            || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException('CSRF token inv¨¢lido.');
        }
    }
}

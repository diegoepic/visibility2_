<?php
session_start();
date_default_timezone_set('America/Santiago'); 
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// -----------------------------------------------------------
// CONFIGURACIÓN GENERAL
// -----------------------------------------------------------
$error_reset = '';
$success_reset = '';

$selector  = $_GET['selector']  ?? '';
$validator = $_GET['validator'] ?? '';

// -----------------------------------------------------------
// 1️⃣ Validar parámetros del enlace
// -----------------------------------------------------------
if (empty($selector) || empty($validator)) {
    $_SESSION['error_reset'] = "Enlace de restablecimiento inválido.";
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------
// 2️⃣ Buscar registro correspondiente
// -----------------------------------------------------------
$stmt = $conn->prepare("SELECT id, user_id, token_hash, expires_at FROM password_resets WHERE selector = ? LIMIT 1");
$stmt->bind_param("s", $selector);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $_SESSION['error_reset'] = "El enlace de restablecimiento no es válido o ha sido utilizado.";
    header("Location: ../index.php");
    exit();
}

$reset = $result->fetch_assoc();
$usuario_id = (int)$reset['user_id'];
$token_hash = $reset['token_hash'];
$expires_at = $reset['expires_at'];
$reset_id   = (int)$reset['id'];

// -----------------------------------------------------------
// 3️⃣ Verificar expiración (hora de Chile)
// -----------------------------------------------------------
if (strtotime($expires_at) < time()) {
    $_SESSION['error_reset'] = "El enlace de restablecimiento ha expirado.";
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------
// 4️⃣ Verificar hash del validator
// -----------------------------------------------------------
$validator_bin = hex2bin($validator);
if ($validator_bin === false || strlen($validator) !== 64 || !ctype_xdigit($validator) ||
    !hash_equals($token_hash, hash('sha256', $validator_bin))) {
    $_SESSION['error_reset'] = "El enlace de restablecimiento no es válido.";
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------
// 5️⃣ Si el formulario se envía, procesar cambio de contraseña
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $password_again = $_POST['password_again'] ?? '';

    if (empty($password) || empty($password_again)) {
        $error_reset = "Por favor, completa todos los campos.";
    } elseif ($password !== $password_again) {
        $error_reset = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 4) {
        $error_reset = "La contraseña debe tener al menos 4 caracteres.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Actualizar contraseña
        $stmt_update = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?");
        $stmt_update->bind_param("si", $hashed_password, $usuario_id);

        if ($stmt_update->execute()) {
            // Eliminar el token usado
            $stmt_delete = $conn->prepare("DELETE FROM password_resets WHERE id = ?");
            $stmt_delete->bind_param("i", $reset_id);
            $stmt_delete->execute();

            $_SESSION['success_reset'] = "Tu contraseña ha sido restablecida exitosamente. Ahora puedes iniciar sesión.";
            header("Location: ../index.php");
            exit();
        } else {
            $error_reset = "Hubo un error al restablecer la contraseña. Intenta nuevamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Restablecer Contraseña - Visibility 2</title>
    <link rel="stylesheet" href="../assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
    <div class="main-login col-sm-4 col-sm-offset-4">
        <div class="box-login">
            <h3>Restablecer Contraseña</h3>
            <p>Ingrese su nueva contraseña a continuación.</p>

            <?php if ($error_reset): ?>
                <div class="alert alert-danger">
                    <i class="fa fa-remove-sign"></i> <?= htmlspecialchars($error_reset) ?>
                </div>
            <?php endif; ?>

            <form class="form-login" 
                  action="reset_password.php?selector=<?= htmlspecialchars($selector) ?>&validator=<?= htmlspecialchars($validator) ?>" 
                  method="POST">
                <fieldset>
                    <div class="form-group">
                        <span class="input-icon">
                            <input type="password" class="form-control" name="password" placeholder="Nueva Contraseña" required>
                            <i class="fa fa-lock"></i>
                        </span>
                    </div>

                    <div class="form-group">
                        <span class="input-icon">
                            <input type="password" class="form-control" name="password_again" placeholder="Confirmar Contraseña" required>
                            <i class="fa fa-lock"></i>
                        </span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-bricky pull-right">
                            Restablecer <i class="fa fa-arrow-circle-right"></i>
                        </button>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.0.3/jquery.min.js"></script>
    <script src="../assets/plugins/bootstrap/js/bootstrap.min.js"></script>
</body>
</html>

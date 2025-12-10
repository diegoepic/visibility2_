<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

// ⚠️ Activar errores en pantalla
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre style='background:#111;color:#0f0;padding:10px;border-radius:6px;'>DEBUG MODE ON\n";

// -------------------------------------------------------
// 1️⃣ Validar parámetros del enlace
// -------------------------------------------------------
$selector  = $_GET['selector']  ?? '';
$validator = $_GET['validator'] ?? '';

if (empty($selector) || empty($validator)) {
    echo "❌ Falta selector o validator en la URL\n";
    exit("</pre>");
}

echo "✔ Selector recibido: $selector\n";
echo "✔ Validator recibido (hex): $validator\n";

// -------------------------------------------------------
// 2️⃣ Buscar el registro correspondiente
// -------------------------------------------------------
$stmt = $conn->prepare("SELECT id, user_id, token_hash, expires_at FROM password_resets WHERE selector = ? LIMIT 1");
$stmt->bind_param("s", $selector);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo "❌ No se encontró registro en password_resets con selector = $selector\n";
    exit("</pre>");
}

$reset = $result->fetch_assoc();
$usuario_id = (int)$reset['user_id'];
$token_hash = $reset['token_hash'];
$expires_at = $reset['expires_at'];
$reset_id   = (int)$reset['id'];

echo "✔ Registro encontrado para user_id = $usuario_id\n";
echo "✔ Token hash almacenado: $token_hash\n";
echo "✔ Expira en: $expires_at\n";

// -------------------------------------------------------
// 3️⃣ Validar expiración
// -------------------------------------------------------
if (strtotime($expires_at) < time()) {
    echo "❌ El enlace expiró. Expiraba en: $expires_at\n";
    exit("</pre>");
}
echo "✔ El token NO ha expirado aún.\n";

// -------------------------------------------------------
// 4️⃣ Validar token (hash)
// -------------------------------------------------------
$validator_bin = hex2bin($validator);
if ($validator_bin === false) {
    echo "❌ El validator no es hexadecimal válido.\n";
    exit("</pre>");
}

$hash_calc = hash('sha256', $validator_bin);
echo "✔ Hash calculado desde validator: $hash_calc\n";

if (!hash_equals($token_hash, $hash_calc)) {
    echo "❌ El hash no coincide. No es el token correcto.\n";
    exit("</pre>");
}

echo "✔ Hash del validator coincide con token_hash en la base de datos.\n";

// -------------------------------------------------------
// 5️⃣ Si es POST: actualizar contraseña
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "\n--- VALIDANDO FORMULARIO ---\n";

    $password = $_POST['password'] ?? '';
    $password_again = $_POST['password_again'] ?? '';

    if (empty($password) || empty($password_again)) {
        echo "❌ Campos vacíos.\n";
        exit("</pre>");
    }

    if ($password !== $password_again) {
        echo "❌ Las contraseñas no coinciden.\n";
        exit("</pre>");
    }

    if (strlen($password) < 6) {
        echo "❌ La contraseña debe tener al menos 6 caracteres.\n";
        exit("</pre>");
    }

    echo "✔ Validaciones de contraseña pasadas.\n";

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Intentar actualizar contraseña
    $stmt_update = $conn->prepare("UPDATE usuario SET clave = ? WHERE id = ?");
    $stmt_update->bind_param("si", $hashed_password, $usuario_id);

    if ($stmt_update->execute()) {
        echo "✔ Contraseña actualizada correctamente en la tabla usuario.\n";

        // Eliminar el token para prevenir reuso
        $stmt_delete = $conn->prepare("DELETE FROM password_resets WHERE id = ?");
        $stmt_delete->bind_param("i", $reset_id);
        if ($stmt_delete->execute()) {
            echo "✔ Token eliminado correctamente (id = $reset_id).\n";
        } else {
            echo "⚠️ Error al eliminar el token: " . $stmt_delete->error . "\n";
        }

        echo "\n✅ PROCESO FINALIZADO: Contraseña restablecida correctamente.\n";
        exit("</pre>");
    } else {
        echo "❌ Error al actualizar la contraseña: " . $stmt_update->error . "\n";
        exit("</pre>");
    }
}

// -------------------------------------------------------
// 6️⃣ Mostrar formulario si aún no se ha enviado POST
// -------------------------------------------------------
echo "\n✅ Token válido. Se puede mostrar el formulario de restablecimiento.\n</pre>";
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>DEBUG - Restablecer Contraseña</title>
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
</head>
<body>
<div class="container" style="max-width:500px;margin-top:40px;">
    <h3>Formulario de prueba (DEBUG)</h3>
    <form method="POST">
        <div class="form-group">
            <label>Nueva contraseña</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Confirmar contraseña</label>
            <input type="password" name="password_again" class="form-control" required>
        </div>
        <button class="btn btn-primary">Restablecer</button>
    </form>
</div>
</body>
</html>

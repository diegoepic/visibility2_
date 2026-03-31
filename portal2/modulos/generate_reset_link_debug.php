<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ✅ Ajusta con el ID real del usuario que quieras probar
$user_id = 1; // ← cámbialo por el id de la tabla `usuario`

// 1️⃣ Generar valores
$selector = bin2hex(random_bytes(8));
$token = random_bytes(32);
$token_hash = hash('sha256', $token);
$expires_at = date("Y-m-d H:i:s", strtotime("+1 hour"));
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

// 2️⃣ Limpiar registros anteriores del mismo usuario
$conn->query("DELETE FROM password_resets WHERE user_id = $user_id");

// 3️⃣ Insertar nuevo registro
$stmt = $conn->prepare("
    INSERT INTO password_resets (user_id, selector, token_hash, expires_at, used, created_at, ip)
    VALUES (?, ?, ?, ?, 0, NOW(), ?)
");
$stmt->bind_param("issss", $user_id, $selector, $token_hash, $expires_at, $ip);
$stmt->execute();

// 4️⃣ Mostrar resultados en pantalla
$link = "https://visibility.cl/visibility2/portal/modulos/reset_password_debug.php?selector=$selector&validator=" . bin2hex($token);

echo "<pre style='background:#111;color:#0f0;padding:15px;border-radius:8px'>";
echo "DEBUG GENERADOR DE ENLACE\n\n";
echo "User ID: $user_id\n";
echo "Selector: $selector\n";
echo "Token (bin2hex): " . bin2hex($token) . "\n";
echo "Token hash (guardado en DB): $token_hash\n";
echo "Expira: $expires_at\n";
echo "\n👉 Enlace listo para probar:\n$link\n";
echo "</pre>";
?>

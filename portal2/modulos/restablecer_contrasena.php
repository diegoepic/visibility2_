<?php
date_default_timezone_set('America/Santiago');
session_start();

// ⚠️ SOLO EN DESARROLLO
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../libs/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Conexión
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_forgot'] = "Por favor, ingrese un correo electrónico válido.";
        header("Location: ../index.php#forgot");
        exit();
    }

    // Buscar usuario
    $stmt = $conn->prepare("SELECT id, nombre, email FROM usuario WHERE email = ? AND activo = 1 LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $user_id = (int)$user['id'];
        $user_name = $user['nombre'];
        $user_email = $user['email'];

        // Generar token seguro
        $selector = bin2hex(random_bytes(8));       // identificador público
        $token = random_bytes(32);                  // token secreto
        $token_hash = hash('sha256', $token);       // se almacena en DB
        $expires_at = date("Y-m-d H:i:s", strtotime("+1 hour"));

        // Eliminar tokens previos de ese usuario (opcional)
        $conn->query("DELETE FROM password_resets WHERE user_id = $user_id");

        // Insertar nuevo token
        $stmt_insert = $conn->prepare("
            INSERT INTO password_resets (user_id, selector, token_hash, expires_at, used, created_at, ip)
            VALUES (?, ?, ?, ?, 0, NOW(), ?)
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt_insert->bind_param("issss", $user_id, $selector, $token_hash, $expires_at, $ip);
        $stmt_insert->execute();

        // Enlace (selector y token codificados)
        $reset_link = "https://visibility.cl/visibility2/portal/modulos/reset_password.php?selector=$selector&validator=" . urlencode(bin2hex($token));

        // Configuración de PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.visibility.cl';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'contacto@visibility.cl';
            $mail->Password   = 'yzv2b9rn';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('contacto@visibility.cl', 'Equipo Visibility');
            $mail->addAddress($user_email, $user_name);

            // Contenido HTML corporativo
            $anio = date('Y');
            $nombreSafe = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
            $mail->isHTML(true);
            $mail->Subject = '🔐 Restablecimiento de Contraseña - Visibility';
            $mail->Body = "
            <html>
            <head>
              <meta charset='UTF-8'>
              <style>
                body{background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#333;margin:0;padding:0}
                .container{background:#fff;width:90%;max-width:600px;margin:30px auto;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,.1);overflow:hidden}
                .header{background:#004AAD;color:#fff;text-align:center;padding:20px}
                .header img{width:60px;margin-bottom:10px}
                .content{padding:25px;line-height:1.6}
                .btn{display:inline-block;background:#343a40;color:#fff!important;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold}
                .footer{background:#f1f1f1;text-align:center;padding:15px;font-size:12px;color:#666}
              </style>
            </head>
            <body>
              <div class='container'>
                <div class='header'>
                  <img src='https://visibility.cl/visibility2/portal/images/logo/Logo_MENTE CREATIVA-02.png' alt='Visibility Logo'>
                  <h2>Restablecimiento de Contraseña</h2>
                </div>
                <div class='content'>
                  <p>Hola <strong>{$nombreSafe}</strong>,</p>
                  <p>Recibimos una solicitud para restablecer tu contraseña en <strong>Visibility 2</strong>.</p>
                  <p>Para crear una nueva contraseña, haz clic en el siguiente botón:</p>
                  <p style='text-align:center;'>
                    <a class='btn' href='{$reset_link}' target='_blank'>Restablecer Contraseña</a>
                  </p>
                  <p>Este enlace expirará en 1 hora. Si no solicitaste este cambio, puedes ignorar este mensaje.</p>
                  <p>Saludos,<br>Equipo Visibility 2</p>
                </div>
                <div class='footer'>
                  © {$anio} Visibility.cl - Todos los derechos reservados.
                </div>
              </div>
            </body>
            </html>";

            $mail->AltBody = "Hola {$user_name},\n\n".
                             "Para restablecer tu contraseña, usa este enlace (vigente 1 hora):\n".
                             "{$reset_link}\n\n".
                             "Si no solicitaste esto, ignora el mensaje.";

            $mail->send();

            $_SESSION['success_forgot'] = "Te enviamos un enlace para restablecer tu contraseña.";
            header("Location: ../index.php#forgot");
            exit();

        } catch (Exception $e) {
            $_SESSION['error_forgot'] = "❌ Error al enviar el correo: " . $mail->ErrorInfo;
            header("Location: ../index.php#forgot");
            exit();
        }

    } else {
        $_SESSION['error_forgot'] = "No se encontró una cuenta asociada a ese correo electrónico.";
        header("Location: ../index.php#forgot");
        exit();
    }

} else {
    header("Location: ../index.php");
    exit();
}
?>

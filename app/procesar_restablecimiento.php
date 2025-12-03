<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$servername = "localhost";
$username = "visibility";
$password = "xyPz8e/rgaC2";
$dbname = "visibility_visibility2";

// Conectar a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);

// Incluir los archivos de PHPMailer
require 'phpmailer/src/Exception.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Correo electrónico inválido.");
    }

    // Verificar si el correo existe
    $stmt = $conn->prepare("SELECT id FROM usuario WHERE email = ?");
    if (!$stmt) {
        die("Error en la preparación de la consulta SELECT: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->errno) {
        die("Error en la ejecución de la consulta SELECT: " . $stmt->error);
    }

    $stmt->bind_result($usuario_id);
    $stmt->fetch();
    $stmt->close();

    if (isset($usuario_id)) {
        // Generar un token único
        if (function_exists('openssl_random_pseudo_bytes')) {
            $token = bin2hex(openssl_random_pseudo_bytes(50));
        } else {
            $token = bin2hex(random_bytes(50));
        }

        $expira = date("Y-m-d H:i:s", strtotime('+1 hour'));

        // Guardar el token en la base de datos
        $stmt = $conn->prepare("INSERT INTO password_resets (usuario_id, token, expira) VALUES (?, ?, ?)");
        if (!$stmt) {
            die("Error en la preparación de la consulta INSERT: " . $conn->error);
        }
        $stmt->bind_param("iss", $usuario_id, $token, $expira);
        $stmt->execute();
        if ($stmt->errno) {
            die("Error en la ejecución de la consulta INSERT: " . $stmt->error);
        }
        $stmt->close();

        // Enviar el correo electrónico con PHPMailer
        $reset_link = "https://visibility.cl/visibility2/app/cambiar_contraseña.php?token=" . urlencode($token);

        $mail = new PHPMailer(true);

        try {
            // Configuración del servidor SMTP
            $mail->SMTPDebug = 2; // Cambia a 0 en producción
            $mail->isSMTP();
            $mail->Host       = 'mail.visibility.cl';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'solicitudes@visibility.cl';
            $mail->Password   = '~]lDxUfz#6fs';
            $mail->SMTPSecure = 'ssl'; // O 'tls' si usas el puerto 587
            $mail->Port       = 465; // 465 para SSL

             
             $mail->SMTPOptions = array(
                 'ssl' => array(
                     'verify_peer'       => false,
                     'verify_peer_name'  => false,
                     'allow_self_signed' => true
                 )
             );

            // Remitente y destinatario
            $mail->setFrom('solicitudes@visibility.cl', 'Visibility');
            $mail->addAddress($email);

            // Contenido del correo
            $mail->isHTML(true);
            $mail->Subject = 'Restablece tu contraseña';
            $mail->Body    = 'Hola,<br><br>Para restablecer tu contraseña, haz clic en el siguiente enlace:<br><a href="' . $reset_link . '">' . $reset_link . '</a><br><br>Este enlace expirará en una hora.<br><br>Si no solicitaste un restablecimiento, ignora este correo.';
            $mail->AltBody = 'Hola,\n\nPara restablecer tu contraseña, visita el siguiente enlace:\n' . $reset_link . '\n\nEste enlace expirará en una hora.\n\nSi no solicitaste un restablecimiento, ignora este correo.';

            $mail->send();
            echo 'Hemos enviado un enlace de restablecimiento a tu correo electrónico.';
        } catch (Exception $e) {
            echo 'Error al enviar el correo: ', $mail->ErrorInfo;
        }
    } else {
        echo "No se encontró una cuenta con ese correo electrónico.";
    }
} else {
    echo "Método de solicitud no válido.";
}
?>
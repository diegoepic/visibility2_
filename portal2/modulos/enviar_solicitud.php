<?php
session_start();

// Cargar PHPMailer (versión moderna sin autoload)
require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/libs/PHPMailer-master/PHPMailer.php';
require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/libs/PHPMailer-master/SMTP.php';
require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/libs/PHPMailer-master/Exception.php';

// Usar el namespace de la nueva versión
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $empresa  = trim($_POST['empresa'] ?? '');
    $email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);

    if ($nombre && $apellido && $empresa && $email) {
        $mail = new PHPMailer(true);
        try {
            // --- Configuración SMTP ---
            $mail->isSMTP();
            $mail->Host       = 'mail.visibility.cl';     // Servidor SMTP (ajusta si es diferente)
            $mail->SMTPAuth   = true;
            $mail->Username   = 'contacto@visibility.cl';  // Usuario SMTP
            $mail->Password   = 'yzv2b9rn';        // Contraseña SMTP
            $mail->SMTPSecure = 'tls';                     // o 'ssl'
            $mail->Port       = 587;                       // o 465 para SSL

            // --- Encabezados ---
            $mail->setFrom('contacto@visibility.cl', 'Portal Visibility');
            $mail->addAddress('contacto@visibility.cl', 'Administrador');
            $mail->addReplyTo($email, $nombre . ' ' . $apellido);

            // --- Contenido ---
            $mail->isHTML(false);
            $mail->Subject = 'Solicitud de Creacion de Cuenta';
            $mail->Body = "Se ha recibido una solicitud de creacion de cuenta:\n\n" .
                          "Nombre: $nombre\n" .
                          "Apellido: $apellido\n" .
                          "Empresa: $empresa\n" .
                          "Correo: $email\n";

            $mail->send();
            $_SESSION['success_solicitud'] = "✅ Solicitud enviada correctamente.";
        } catch (Exception $e) {
            $_SESSION['error_solicitud'] = "❌ Error al enviar: " . $mail->ErrorInfo;
        }
    } else {
        $_SESSION['error_solicitud'] = "⚠️ Complete todos los campos correctamente.";
    }

    header('Location: ../index.php');
    exit;
}
?>

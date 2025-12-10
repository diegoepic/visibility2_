<?php
// actualizar.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Iniciar la sesi¨®n si a¨²n no est¨¢ iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verifica que se hayan recibido los datos
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitizar y validar las entradas
    $empresaId = isset($_POST['empresa_id']) ? intval($_POST['empresa_id']) : 0;
    $nuevoEstado = isset($_POST['estado_empresa']) ? intval($_POST['estado_empresa']) : null;

    // Validar que $empresaId es positivo y $nuevoEstado es 0 o 1
    if ($empresaId > 0 && ($nuevoEstado === 0 || $nuevoEstado === 1)) {
        // Llama a la funci¨®n para actualizar el estado de la empresa
        if (actualizarEstadoEmpresa($empresaId, $nuevoEstado)) {
            // Redireccionar sin enviar salida previa
            header("Location: ../mod_elementos.php?mensaje=exito");
            exit();
        } else {
            // Obtener el estado actual de la empresa para mayor detalle
            $stmt = $conn->prepare("SELECT activo FROM empresa WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $empresaId);
                $stmt->execute();
                $stmt->bind_result($estadoActual);
                if ($stmt->fetch()) {
                    if ($estadoActual == $nuevoEstado) {
                        // El estado ya est¨¢ establecido
                        $errorMensaje = "La empresa ya est¨¢ en el estado seleccionado.";
                    } else {
                        // Otro tipo de error
                        $errorMensaje = "Error desconocido al actualizar el estado de la empresa.";
                    }
                } else {
                    // No se encontr¨® la empresa
                    $errorMensaje = "No se encontr¨® la empresa con ID $empresaId.";
                }
                $stmt->close();
            } else {
                $errorMensaje = "Error en la preparaci¨®n de la consulta: " . $conn->error;
            }

            header("Location: ../mod_elementos.php?mensaje=error&detalle=" . urlencode($errorMensaje));
            exit();
        }
    } else {
        // Datos inv¨¢lidos
        $errorMensaje = "Datos inv¨¢lidos recibidos. Empresa ID: $empresaId, Estado: $nuevoEstado.";
        header("Location: ../mod_elementos.php?mensaje=error&detalle=" . urlencode($errorMensaje));
        exit();
    }
} else {
    // M¨¦todo de solicitud no v¨¢lido
    $errorMensaje = "M¨¦todo de solicitud no v¨¢lido.";
    header("Location: ../mod_elementos.php?mensaje=error&detalle=" . urlencode($errorMensaje));
    exit();
}
?>

<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Solo iniciar la sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Verificar el Token CSRF y que el método sea POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificación segura de existencia y coincidencia del token CSRF
    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['error_local'] = "Token CSRF inválido.";
        header("Location: ../mod_local.php");
        exit();
    }

    // Obtener y sanitizar los datos
    $nombre_comuna = trim($_POST['inputNombreComuna'] ?? '');
    $id_region     = intval($_POST['region_id'] ?? 0);

    if ($nombre_comuna === '' || $id_region <= 0) {
        $_SESSION['error_local'] = "El nombre de la comuna y la región son obligatorios.";
        header("Location: ../mod_local.php");
        exit();
    }

    // Validar si ya existe
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM comuna WHERE comuna = ? AND id_region = ?");
    if (!$stmt_check) {
        $_SESSION['error_local'] = "Error en la preparación de la consulta: " . $conn->error;
        header("Location: ../mod_local.php");
        exit();
    }

    $stmt_check->bind_param("si", $nombre_comuna, $id_region);
    $stmt_check->execute();
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($count > 0) {
        $_SESSION['error_local'] = "La comuna '$nombre_comuna' ya existe en la región seleccionada.";
        header("Location: ../mod_local.php");
        exit();
    }

    // Insertar comuna (asumo que tienes la función `insertarComuna()` definida en otro archivo)
    if (insertarComuna($nombre_comuna, $id_region)) {
        $_SESSION['success_local'] = "Comuna '$nombre_comuna' creada exitosamente.";
    } else {
        $_SESSION['error_local'] = "Hubo un error al crear la comuna.";
    }

    header("Location: ../mod_local.php");
    exit();
} else {
    $_SESSION['error_local'] = "Método de solicitud no válido.";
    header("Location: ../mod_local.php");
    exit();
}

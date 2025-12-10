<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Verificar si el usuario es coordinador
if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_perfil'] !== 4) {
    die("Acceso denegado.");
}

// Recibir parámetros
$id_ejecutor = isset($_POST['id_ejecutor']) ? intval($_POST['id_ejecutor']) : 0;
$id_campana  = isset($_POST['id_campana'])  ? intval($_POST['id_campana'])  : 0;
$ver         = isset($_POST['ver'])         ? $_POST['ver']                : 'campana';

// Array de checkboxes marcados
$priorityCheckboxes = isset($_POST['priority']) ? $_POST['priority'] : [];

// 1) Primero, resetear is_priority=0
if ($ver === 'campana' && $id_campana>0) {
    $sqlReset = "
       UPDATE formularioQuestion
       SET is_priority=0
       WHERE id_usuario=? AND id_formulario=?
    ";
    $stmt = $conn->prepare($sqlReset);
    $stmt->bind_param("ii", $id_ejecutor, $id_campana);
} else {
    // ver=todos => resetear todos sus locales
    $sqlReset = "
       UPDATE formularioQuestion
       SET is_priority=0
       WHERE id_usuario=?
    ";
    $stmt = $conn->prepare($sqlReset);
    $stmt->bind_param("i", $id_ejecutor);
}

if (!$stmt->execute()) {
    die("Error reseteando prioridad: " . $stmt->error);
}
$stmt->close();

// 2) Para cada local marcado => is_priority=1
foreach ($priorityCheckboxes as $idLocal => $val) {
    // $val es '1' si está checkeado
    if ($ver === 'campana' && $id_campana>0) {
        $sqlUpd = "
          UPDATE formularioQuestion
          SET is_priority=1
          WHERE id_usuario=? AND id_formulario=? AND id_local=?
        ";
        $stmtU = $conn->prepare($sqlUpd);
        $stmtU->bind_param("iii", $id_ejecutor, $id_campana, $idLocal);
    } else {
        $sqlUpd = "
          UPDATE formularioQuestion
          SET is_priority=1
          WHERE id_usuario=? AND id_local=?
        ";
        $stmtU = $conn->prepare($sqlUpd);
        $stmtU->bind_param("ii", $id_ejecutor, $idLocal);
    }

    if (!$stmtU->execute()) {
        die("Error marcando prioridad: " . $stmtU->error);
    }
    $stmtU->close();
}

$conn->close();

// Redirigir de vuelta
header("Location: mod_panel_detalle_locales.php?id_ejecutor=$id_ejecutor&id_campana=$id_campana&ver=$ver");
exit;

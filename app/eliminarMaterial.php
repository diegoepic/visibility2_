<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No hay sesión']);
    exit;
}

// Obtener datos
$idFormularioQuestion = intval($_POST['idFormularioQuestion'] );
$idUsuario = intval($_SESSION['usuario_id']);

// Validaciones
if ($idFormularioQuestion <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
    exit;
}

// Borrar registro de formularioQuestion
$sql = "
    DELETE FROM formularioQuestion
    WHERE id = ?
      AND id_usuario = ?
      AND estado = 0
      -- Se asume que solo se puede borrar si 'estado=0' (aún no finalizado)
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Error preparando SQL']);
    exit;
}
$stmt->bind_param("ii", $idFormularioQuestion, $idUsuario);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'Error al eliminar']);
    exit;
}
if ($stmt->affected_rows > 0) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar (quizá no existe o no tienes permisos)']);
}
$stmt->close();
$conn->close();
?>

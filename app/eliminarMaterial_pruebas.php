<?php
// eliminarMaterial.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

try {
  // ===== Método & sesión =====
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    throw new Exception('Método inválido');
  }
  if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    throw new Exception('No hay sesión');
  }

  // ===== CSRF (flexible para no romper el front actual) =====
  // Si viene el token, se valida estrictamente. Si no viene,
  // se permite para mantener compatibilidad con la llamada actual.
  if (isset($_POST['csrf_token'])) {
    if (
      empty($_SESSION['csrf_token']) ||
      !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
      http_response_code(419);
      throw new Exception('CSRF inválido');
    }
  }

  // ===== Inputs =====
  $idUsuario = (int)$_SESSION['usuario_id'];
  $idFQ = isset($_POST['idFormularioQuestion']) ? (int)$_POST['idFormularioQuestion'] : 0;
  if ($idFQ <= 0) {
    throw new Exception('ID inválido');
  }

  // ===== Validar que el registro exista y sea del usuario =====
  $sqlSel = "
    SELECT id, id_formulario, id_local, id_usuario, estado, valor, countVisita
      FROM formularioQuestion
     WHERE id = ? AND id_usuario = ?
     LIMIT 1
  ";
  $stSel = $conn->prepare($sqlSel);
  if (!$stSel) throw new Exception('Error preparando SELECT: '.$conn->error);
  $stSel->bind_param('ii', $idFQ, $idUsuario);
  $stSel->execute();
  $resSel = $stSel->get_result();
  if ($resSel->num_rows === 0) {
    $stSel->close();
    throw new Exception('No se encontró el material o no tienes permisos');
  }
  $row = $resSel->fetch_assoc();
  $stSel->close();

  // Solo dejamos borrar si:
  // - estado = 0 (no finalizado)
  // - valor = 0 (no implementado)
  // - countVisita = 0 (no gestionado)
  if ((int)$row['estado'] !== 0) {
    throw new Exception('No se puede eliminar: el material ya fue gestionado (estado != 0)');
  }
  if ((int)$row['valor'] !== 0) {
    throw new Exception('No se puede eliminar: el material ya tiene valor implementado');
  }
  if ((int)$row['countVisita'] !== 0) {
    throw new Exception('No se puede eliminar: el material ya registra visitas');
  }

  // ===== Asegurar que no tenga fotos asociadas =====
  $sqlFotos = "SELECT COUNT(*) AS c FROM fotoVisita WHERE id_formularioQuestion = ?";
  $stF = $conn->prepare($sqlFotos);
  if (!$stF) throw new Exception('Error preparando verificación de fotos: '.$conn->error);
  $stF->bind_param('i', $idFQ);
  $stF->execute();
  $c = (int)($stF->get_result()->fetch_assoc()['c'] ?? 0);
  $stF->close();

  if ($c > 0) {
    throw new Exception('No se puede eliminar: el material tiene fotos asociadas');
  }

  // ===== Eliminar =====
  $sqlDel = "
    DELETE FROM formularioQuestion
    WHERE id = ? AND id_usuario = ? AND estado = 0 AND valor = 0 AND countVisita = 0
    LIMIT 1
  ";
  $stDel = $conn->prepare($sqlDel);
  if (!$stDel) throw new Exception('Error preparando DELETE: '.$conn->error);
  $stDel->bind_param('ii', $idFQ, $idUsuario);
  if (!$stDel->execute()) {
    $stDel->close();
    throw new Exception('Error al eliminar');
  }
  $ok = $stDel->affected_rows > 0;
  $stDel->close();

  if (!$ok) {
    throw new Exception('No se pudo eliminar (quizá cambió de estado o ya no existe)');
  }

  echo json_encode(['status' => 'success']);
  exit;

} catch (Throwable $e) {
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
  exit;
} finally {
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
}

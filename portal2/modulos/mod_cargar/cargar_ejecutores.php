<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';
header('Content-Type: application/json; charset=utf-8');

$id_empresa  = intval($_GET['id_empresa'] ?? 0);
$id_campana  = intval($_GET['id_campana'] ?? 0);
$id_division = intval($_GET['id_division'] ?? 0);
$id_distrito = intval($_GET['id_distrito'] ?? 0);
$tipo        = intval($_GET['tipo_gestion'] ?? 0); // 1=Campaña, 3=Ruta

// Validar parámetros necesarios
if ($id_empresa === 0) {
    echo json_encode(['ok' => false, 'msg' => 'Faltan parámetros: id_empresa']);
    exit;
}

// Iniciar try-catch para capturar posibles errores
try {
    // Construcción de la consulta SQL
    $joinLocal    = $id_distrito > 0 ? " JOIN local l ON l.id = fq.id_local " : "";
    $condDistrito = $id_distrito > 0 ? " AND l.id_distrito = ? " : "";

$sql = "
  SELECT DISTINCT u.id, upper(u.nombre) as nombre, upper(u.apellido) as apellido, upper(u.nombre) as nombre_upper, upper(u.apellido) as apellido_upper
  FROM formularioQuestion fq
  JOIN formulario f ON f.id = fq.id_formulario
  JOIN usuario u    ON u.id = fq.id_usuario
  $joinLocal
  WHERE f.id_empresa = ?
";

$params = [$id_empresa];
$types  = "i";

if ($id_campana > 0) {
  $sql .= " AND f.id = ? ";
  $params[] = $id_campana;
  $types   .= "i";
} elseif ($tipo > 0) {
  $sql .= " AND f.tipo = ? ";
  $params[] = $tipo;
  $types   .= "i";
}

if ($id_division > 0) {
  $sql .= " AND f.id_division = ? ";
  $params[] = $id_division;
  $types   .= "i";
}

if ($id_distrito > 0) {
  $params[] = $id_distrito;
  $types   .= "i";
}

$sql .= " ORDER BY nombre_upper, apellido_upper";  // Usando los alias con `upper`

    // Debug: Verificar la consulta SQL
    error_log("Consulta SQL: " . $sql);

    // Preparar y ejecutar la consulta
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta SQL: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    // Verificar si la consulta devuelve resultados
    if (!$res) {
        throw new Exception("Error al ejecutar la consulta: " . $stmt->error);
    }

    $ejecutores = [];
    while ($r = $res->fetch_assoc()) {
        $ejecutores[] = $r;
    }

    // Cerrar la conexión y el statement
    $stmt->close();
    $conn->close();

    // Responder con los datos en formato JSON
    echo json_encode(['ok' => true, 'ejecutores' => $ejecutores]);

} catch (Exception $e) {
    // Capturar cualquier error y devolverlo como respuesta JSON
    echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
}
?>
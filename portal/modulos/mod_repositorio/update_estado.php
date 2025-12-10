<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Que mysqli lance errores “reales”
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==== 1) Validar sesión usuario ====
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'Usuario no autenticado']);
    exit;
}

$idUsuario = (int)$_SESSION['usuario_id'];

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// ==== 2) Validar parámetros ====
$idArchivo = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$estado    = isset($_POST['estado']) ? (int)$_POST['estado'] : null;

if ($idArchivo <= 0 || $estado === null) {
    echo json_encode([
        'error' => 'Parámetros inválidos',
        'debug' => [
            'post'   => $_POST,
            'id'     => $idArchivo,
            'estado' => $estado
        ]
    ]);
    exit;
}

// ==== 3) Consultar división ====
$q = $conn->prepare("
    SELECT d.nombre
    FROM usuario u
    INNER JOIN division_empresa d ON d.id = u.id_division
    WHERE u.id = ?
");
$q->bind_param("i", $idUsuario);
$q->execute();
$q->bind_result($divisionNombre);
$q->fetch();
$q->close();

$divisionNombre = strtoupper(trim((string)$divisionNombre));

if ($divisionNombre !== 'MC') {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// ==== 4) UPDATE ====
$sqlUpdate = "
    UPDATE repo_archivo
    SET 
        estado = ?,
        fecha_gestion = NOW(),
        usuario_gestion = ?
    WHERE id = ?
";

$stmt = $conn->prepare($sqlUpdate);

if (!$stmt) {
    echo json_encode([
        'error' => 'SQL inválido en prepare()',
        'debug' => $conn->error,
        'sql'   => $sqlUpdate
    ]);
    exit;
}

$stmt->bind_param("iii", $estado, $idUsuario, $idArchivo);
$stmt->execute();

echo json_encode(['exito' => true]);
exit;

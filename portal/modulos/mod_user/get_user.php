<?php
// mod_user/get_user.php

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'ID de usuario no proporcionado o inválido'
    ]);
    exit;
}

$usuario_id = (int) $_GET['id'];

/* La columna puede_adelantar_ruta la agrega scripts/21 y los despliegues van
   desacoplados de las migraciones: si aún no existe, se omite del SELECT para
   que el módulo no falle. */
$colAdelantar = false;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'puede_adelantar_ruta'");
if ($chk && mysqli_num_rows($chk) > 0) $colAdelantar = true;

$selAdelantar = $colAdelantar
    ? "u.puede_adelantar_ruta,"
    : "1 AS puede_adelantar_ruta,";

$sql = "SELECT
            $selAdelantar
            u.id,
            u.rut,
            u.nombre,
            u.apellido,
            u.telefono,
            u.email,
            u.usuario,
            u.id_perfil,
            u.id_empresa,
            u.id_division,
            u.id_subdivision,
            u.clasificacion_usuario,
            u.fotoPerfil,
            u.id_comuna,
            u.centro_distribucion,
            c.id_region
        FROM usuario u
        LEFT JOIN comuna c ON c.id = u.id_comuna
        WHERE u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al preparar la consulta'
    ]);
    exit;
}

$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $usuario = $result->fetch_assoc();

    $data = [
        'id' => $usuario['id'],
        'rut' => $usuario['rut'],
        'nombre' => $usuario['nombre'],
        'apellido' => $usuario['apellido'],
        'telefono' => $usuario['telefono'],
        'email' => $usuario['email'],
        'usuario' => $usuario['usuario'],
        'id_perfil' => $usuario['id_perfil'],
        'id_empresa' => $usuario['id_empresa'],
        'id_division' => $usuario['id_division'],
        'id_subdivision' => $usuario['id_subdivision'],
        'clasificacion_usuario' => $usuario['clasificacion_usuario'],
        'fotoPerfil'            => $usuario['fotoPerfil'],
        'id_comuna'             => $usuario['id_comuna'],
        'centro_distribucion'   => $usuario['centro_distribucion'],
        'id_region'             => $usuario['id_region'],
        'puede_adelantar_ruta'  => (int)($usuario['puede_adelantar_ruta'] ?? 1)
    ];

    echo json_encode([
        'status' => 'success',
        'data' => $data
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Usuario no encontrado'
    ]);
}

$stmt->close();
$conn->close();
?>
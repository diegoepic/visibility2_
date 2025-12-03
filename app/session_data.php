<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$usuario_nombre = $_SESSION['usuario_nombre'];
$usuario_apellido = $_SESSION['usuario_apellido'];
$usuario_fotoPerfil = $_SESSION['usuario_fotoPerfil'];
$usuario_perfil = $_SESSION['usuario_perfil'];
$perfil_nombre = $_SESSION['perfil_nombre'];
$empresa_nombre = $_SESSION['empresa_nombre'];
$empresa_id = $_SESSION['empresa_id']; // Verifica este valor

//  Obtener la División del Usuario**
$division_id_session = isset($_SESSION['division_id']) ? intval($_SESSION['division_id']) : 0;

// Función para obtener el nombre de la división por su ID
function obtenerNombreDivision($id_division) {
    global $conn;
    
    // Preparar la consulta para obtener el nombre de la división
    $stmt = $conn->prepare("SELECT nombre FROM division_empresa WHERE id = ?");
    if ($stmt === false) {
        die("Error en la preparación de la consulta: " . $conn->error);
    }
    
    // Vincular el parámetro y ejecutar la consulta
    $stmt->bind_param("i", $id_division);
    if (!$stmt->execute()) {
        die("Error en la ejecución de la consulta: " . $stmt->error);
    }
    
    // Vincular el resultado
    $stmt->bind_result($nombre_division);
    if ($stmt->fetch()) {
        $stmt->close();
        return $nombre_division;
    } else {
        $stmt->close();
        return 'N/A';
    }
}

// **Asignar el Nombre de la División a una Variable de Sesión**
if ($division_id_session > 0) {
    $division_nombre_session = obtenerNombreDivision($division_id_session);
} else {
    $division_nombre_session = 'N/A';
}
?>
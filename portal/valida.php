<?php
session_start();
error_reporting(0);

// Conexión a la base de datos usando mysqli
$servername = "localhost";
$username = "visibility";
$password = "xyPz8e/rgaC2";
$dbname = "visibility_visibility2";

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Revisar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = $_POST['usuario'];
    $clave = $_POST['clave'];

    // Prevenir inyección SQL usando prepared statements
    $stmt = $conn->prepare("SELECT * FROM usuario WHERE usuario = ? AND clave = ?");
    $stmt->bind_param("ss", $usuario, $clave);
    
    // Ejecutar la consulta
    $stmt->execute();
    
    // Obtener el resultado
    $result = $stmt->get_result();
    
    // Verificar si las credenciales son correctas
    if ($result->num_rows > 0) {
        // Usuario y contraseña correctos, iniciar sesión
        $user = $result->fetch_assoc();
        $_SESSION['usuario'] = $user['usuario'];
        // Redirigir al usuario a la página de inicio
        header("Location: index.php");
        exit();
    } else {
        // Mostrar mensaje de error si las credenciales son incorrectas
        echo "<script>alert('Usuario o contraseña incorrectos');</script>";
        header("Location: login.php");
        exit();        
    }
    
    $stmt->close();
}

$conn->close();
?>
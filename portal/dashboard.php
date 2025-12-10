<?php
// dashboard_iframe.php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if(isset($_GET['id'])){
    $id = $conn->real_escape_string($_GET['id']);
    $query = "SELECT * FROM dashboard_items WHERE id = '$id'";
    $result = $conn->query($query);
    if($result->num_rows > 0){
         $row = $result->fetch_assoc();
         $iframe_src = $row['target_url']; // Aquí se espera que target_url contenga la URL de Power BI para el embed.
    } else {
         die("Dashboard item no encontrado.");
    }
} else {
    die("ID no especificado.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Power BI Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700">
    <!-- Theme style de AdminLTE (opcional) -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <style>
        body { margin: 0; padding: 0; }
        iframe { border: none; width: 100%; height: 100vh; }
    </style>
</head>
<body>
    
   <?php echo $iframe_src; ?>
   
   
</body>
</html>

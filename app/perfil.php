<?php
session_start();


if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}


$nombre = htmlspecialchars($_SESSION['usuario_nombre'], ENT_QUOTES, 'UTF-8');
$apellido = htmlspecialchars($_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8');
$fotoPerfil = htmlspecialchars($_SESSION['usuario_fotoPerfil'], ENT_QUOTES, 'UTF-8');
$correo = htmlspecialchars($_SESSION['usuario_email'], ENT_QUOTES, 'UTF-8');
$telefono = htmlspecialchars($_SESSION['usuario_telefono'], ENT_QUOTES, 'UTF-8');


?>

<!DOCTYPE html>
<html lang="en" class="no-js">

	<head>
		<title>Visibility 2</title>

		<meta charset="utf-8" />
	
		<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimum-scale=1.0, maximum-scale=1.0">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="black">
		<meta content="" name="description" />
		<meta content="" name="author" />

		<link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
		<link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
		<link rel="stylesheet" href="assets/fonts/style.css">
		<link rel="stylesheet" href="assets/css/main.css">
		<link rel="stylesheet" href="assets/css/main-responsive.css">
		<link rel="stylesheet" href="assets/plugins/iCheck/skins/all.css">
		<link rel="stylesheet" href="assets/plugins/bootstrap-colorpalette/css/bootstrap-colorpalette.css">
		<link rel="stylesheet" href="assets/plugins/perfect-scrollbar/src/perfect-scrollbar.css">
		<link rel="stylesheet" href="assets/css/theme_light.css" type="text/css" id="skin_color">
		<link rel="stylesheet" href="assets/css/print.css" type="text/css" media="print"/>

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">


    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">


		<link rel="stylesheet" href="assets/plugins/fullcalendar/fullcalendar/fullcalendar.css">

		<link rel="shortcut icon" href="favicon.ico" />
	</head>
<style>
    body {
  background: #BA68C8;
}

.form-control:focus {
  box-shadow: none;
  border-color: #BA68C8;
}

.profile-button {
  background: #BA68C8;
  box-shadow: none;
  border: none;
}

.profile-button:hover {
  background: #682773;
}

.profile-button:focus {
  background: #682773;
  box-shadow: none;
}

.profile-button:active {
  background: #682773;
  box-shadow: none;
}

.back:hover {
  color: #682773;
  cursor: pointer;
}
</style>

<div class="container rounded bg-white mt-5">
        <div class="row">
            <div class="col-md-4 border-right">
                <div class="d-flex flex-column align-items-center text-center p-3 py-5"><img class="rounded-circle mt-5" src="<?php echo !empty($fotoPerfil) ? htmlspecialchars($fotoPerfil, ENT_QUOTES, 'UTF-8') : 'https://static.vecteezy.com/system/resources/previews/000/439/863/original/vector-users-icon.jpg'; ?>" width="90">
                    <span class="font-weight-bold"><?php echo $nombre . ' ' . $apellido; ?></span>
                    <span class="text-black-50"><?php echo $correo; ?></span>
                    <span>Chile</span></div>
            </div>
            <div class="col-md-8">
                <div class="p-3 py-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <a href="index.php">
                                <div class="d-flex flex-row align-items-center back">
                                    <i class="fa fa-long-arrow-left mr-1 mb-1"></i>
                                <h6>Volver al Inicio</h6>
                            </div>
                        </a>
                        <h6 class="text-right">Editar Perfil</h6>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6"><input type="text" class="form-control" placeholder="Nombre" name="nombre" value="<?php echo $nombre; ?>" required></div>
                        <div class="col-md-6"><input type="text" class="form-control" placeholder="Apellido" name="apellido" value="<?php echo $apellido; ?>" required></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6"><input type="email" class="form-control" placeholder="Correo" name="correo" value="<?php echo $correo; ?>" required></div>
                        <div class="col-md-6"><input type="text" class="form-control" placeholder="+56 9" name="telefono" value="<?php echo $telefono; ?>"></div>
                    </div>
                    <div class="mt-5 text-right"><button class="btn btn-primary profile-button" type="button">Guardar Cambios</button></div>
                </div>
            </div>
        </div>
    </div>
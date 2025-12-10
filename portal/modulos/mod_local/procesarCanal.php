<?php
// mod_local/procesarCanal.php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1) Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_crear_local'] = 'Método de solicitud inválido.';
    header('Location: ../mod_local.php');
    exit();
}

// 2) Verificar Token CSRF
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_crear_local'] = 'Token CSRF inválido.';
    header('Location: ../mod_local.php');
    exit();
}

// 3) Validar campo inputNombreCanal
if (empty($_POST['inputNombreCanal'])) {
    $_SESSION['error_crear_local'] = 'Debe ingresar un nombre para el canal.';
    header('Location: ../mod_local.php');
    exit();
}
$nombreCanal = trim($_POST['inputNombreCanal']);

// 4) Incluir la conexión a la base de datos
require_once '../db.php';

// 5) Insertar en la tabla canal
try {
    $idCanal = insertarCanal($nombreCanal);
    $_SESSION['success_crear_local'] = 'Canal creado exitosamente.';
} catch (Exception $e) {
    $_SESSION['error_crear_local'] = $e->getMessage();
}

// 6) Redirigir
header('Location: ../mod_local.php');
exit;

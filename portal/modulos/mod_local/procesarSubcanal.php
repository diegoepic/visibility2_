<?php
// mod_local/procesarSubcanal.php

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

// 3) Validar campos requeridos
if (empty($_POST['inputNombreSubcanal']) || empty($_POST['id_canal'])) {
    $_SESSION['error_crear_local'] = 'Debe ingresar un nombre de subcanal y seleccionar un canal.';
    header('Location: ../mod_local.php');
    exit();
}
$nombreSubcanal = trim($_POST['inputNombreSubcanal']);
$id_canal       = intval($_POST['id_canal']);

// 4) Conexión BD
require_once '../db.php';

// 5) Insertar
$stmt = $conn->prepare("INSERT INTO subcanal (nombre_subcanal, id_canal) VALUES (?, ?)");
if (!$stmt) {
    $_SESSION['error_crear_local'] = 'Error preparando la inserción de subcanal: ' . $conn->error;
    header('Location: ../mod_local.php');
    exit();
}
$stmt->bind_param("si", $nombreSubcanal, $id_canal);

if ($stmt->execute()) {
    $_SESSION['success_crear_local'] = 'Subcanal creado exitosamente.';
} else {
    $_SESSION['error_crear_local'] = 'Error al crear el subcanal: ' . $stmt->error;
}
$stmt->close();

header('Location: ../mod_local.php');
exit;

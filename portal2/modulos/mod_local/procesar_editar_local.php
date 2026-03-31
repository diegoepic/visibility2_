<?php
// mod_local/procesar_editar_local.php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar el token CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_edit_local'] = 'Token CSRF inválido.';
    header('Location: ../mod_local.php');
    exit;
}

// Validar y sanitizar los campos requeridos
$required_fields = [
    'local_id',
    'empresa_id_edit',
    'cuenta_id_edit',
    'cadena_id_edit',
    'inputCodigoLocalEdit',
    'inputLocalEdit',
    'inputDireccionEdit',
    'region_id_edit',
    'comuna_id_edit',
    'canal_id_edit',
    'subcanal_id_edit'
    
];
$missing_fields = [];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        $missing_fields[] = $field;
    }
}
if (!empty($missing_fields)) {
    $_SESSION['error_edit_local'] = 'Todos los campos marcados con * son obligatorios.';
    header('Location: ../mod_local.php');
    exit;
}

// Asignar variables y sanitizar
$local_id   = intval($_POST['local_id']);
$empresa_id = intval($_POST['empresa_id_edit']);
$cuenta_id  = intval($_POST['cuenta_id_edit']);
$cadena_id  = intval($_POST['cadena_id_edit']);
$codigo     = htmlspecialchars(trim($_POST['inputCodigoLocalEdit']), ENT_QUOTES, 'UTF-8');
$nombre     = htmlspecialchars(trim($_POST['inputLocalEdit']), ENT_QUOTES, 'UTF-8');
$direccion  = htmlspecialchars(trim($_POST['inputDireccionEdit']), ENT_QUOTES, 'UTF-8');
$region_id  = intval($_POST['region_id_edit']);
$comuna_id  = intval($_POST['comuna_id_edit']);
$lat        = floatval($_POST['lat_edit']);
$lng        = floatval($_POST['lng_edit']);
$zona_id = intval($_POST['zona_id_edit']);
$distrito_id = intval($_POST['distrito_id_edit']);
$jefe_venta_id = intval($_POST['jefe_venta_id_edit']);
$vendedor_id = intval($_POST['vendedor_id_edit']);
$division_id = isset($_POST['division_id_edit']) && $_POST['division_id_edit'] !== ''
    ? intval($_POST['division_id_edit'])
    : 0;
    
// NUEVO: Subcanal
$subcanal_id = intval($_POST['subcanal_id_edit']);
$canal_id = intval($_POST['canal_id_edit']);
// Incluir la conexi車n a la base de datos y funciones necesarias
require_once '../db.php';

if ($division_id > 0) {
    $stmtChk = $conn->prepare("SELECT 1 FROM division_empresa WHERE id = ? AND id_empresa = ? AND estado = 1");
    $stmtChk->bind_param("ii", $division_id, $empresa_id);
    $stmtChk->execute();
    $stmtChk->store_result();
    if ($stmtChk->num_rows === 0) {
        // Fuerza a 0 si no corresponde o no existe
        $division_id = 0;
    }
    $stmtChk->close();
}


// Llamar a la funci車n actualizarLocal en db.php
$actualizacion_exitosa = actualizarLocal(
    $local_id,
    $codigo,
    $nombre,
    $direccion,
    $cuenta_id,
    $cadena_id,
    $comuna_id,
    $empresa_id,
    $division_id, 
    $lat,
    $lng,
    $canal_id,
    $subcanal_id,
    $zona_id,
    $distrito_id,
    $jefe_venta_id,
    $vendedor_id
);

if ($actualizacion_exitosa) {
    $_SESSION['success_edit_local'] = 'Local actualizado exitosamente.';
} else {
    if (!isset($_SESSION['error_edit_local'])) {
        $_SESSION['error_edit_local'] = 'Error al actualizar el local.';
    }
}

header('Location: ../mod_local.php');
exit;

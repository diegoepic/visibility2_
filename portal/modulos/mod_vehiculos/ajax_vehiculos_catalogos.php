<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: application/json; charset=utf-8');

$mysqli = $conexion ?? $conn ?? $mysqli ?? null;

if (!$mysqli) {
    echo json_encode([
        'ok' => false,
        'msg' => 'No existe conexión a base de datos.'
    ]);
    exit;
}

try {
    $empresas = [];
    $divisiones = [];
    $subdivisiones = [];
    $merchans = [];

    $q = $mysqli->query("
        SELECT id, nombre
        FROM empresa
        WHERE activo = 1
        ORDER BY nombre
    ");

    while ($row = $q->fetch_assoc()) {
        $empresas[] = $row;
    }

    $q = $mysqli->query("
        SELECT id, nombre, id_empresa
        FROM division_empresa
        WHERE estado = 1
        ORDER BY nombre
    ");

    while ($row = $q->fetch_assoc()) {
        $divisiones[] = $row;
    }

    $q = $mysqli->query("
        SELECT id, nombre, id_division
        FROM subdivision
        ORDER BY nombre
    ");

    while ($row = $q->fetch_assoc()) {
        $subdivisiones[] = $row;
    }

    $q = $mysqli->query("
        SELECT 
            id,
            id_empresa,
            id_division,
            id_subdivision,
            upper(CONCAT(nombre, ' ', apellido, ' - ', usuario)) AS nombre_completo
        FROM usuario
        WHERE activo = 1
        AND id_perfil = 3
        ORDER BY nombre, apellido
    ");

    while ($row = $q->fetch_assoc()) {
        $merchans[] = $row;
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'empresas' => $empresas,
            'divisiones' => $divisiones,
            'subdivisiones' => $subdivisiones,
            'merchans' => $merchans
        ]
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}
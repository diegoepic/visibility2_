<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

$id_division = isset($_GET['id_division']) ? intval($_GET['id_division']) : 0;
if ($id_division <= 0) { echo json_encode([]); exit; }

try {
    $subs = obtenerSubdivisionesPorDivision($id_division);
    echo json_encode($subs);
} catch (Throwable $e) {
    echo json_encode([]);
}

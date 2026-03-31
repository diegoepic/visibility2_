<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Leer divisi¨®n y tipo desde GET (0 significa ¡°todos¡±)
$division = isset($_GET['division']) ? intval($_GET['division']) : 0;
$tipo     = isset($_GET['tipo'])     ? intval($_GET['tipo'])     : 1;

// Base de la consulta
$sql  = "SELECT id, nombre
           FROM formulario
          WHERE tipo = ?";
$params = [$tipo];
$types  = "i";

// Si filtramos por divisi¨®n
if ($division > 0) {
    $sql    .= " AND id_division = ?";
    $types  .= "i";
    $params[] = $division;
}

$sql .= " ORDER BY nombre ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$campaigns = [];
while ($r = $res->fetch_assoc()) {
    $campaigns[] = $r;
}

header('Content-Type: application/json');
echo json_encode($campaigns);

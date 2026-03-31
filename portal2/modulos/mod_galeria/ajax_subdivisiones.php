<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$division = intval($_GET['division'] ?? 0);

$out = [];

if ($division > 0) {
    $stmt = $conn->prepare("
        SELECT id, nombre
        FROM subdivision
        WHERE id_division = ?
        ORDER BY nombre
    ");
    $stmt->bind_param("i", $division);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($out);

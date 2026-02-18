<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$sql = "SELECT id, nombre_zona FROM zona ORDER BY nombre_zona";
$res = $conn->query($sql);

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id'     => (int)$r['id'],
        'nombre' => $r['nombre_zona']
    ];
}

header('Content-Type: application/json');
echo json_encode($out);

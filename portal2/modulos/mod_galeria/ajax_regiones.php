<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$sql = "SELECT id, region FROM region ORDER BY region";
$res = $conn->query($sql);

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id'   => (int)$r['id'],
        'nombre' => $r['region']
    ];
}

header('Content-Type: application/json');
echo json_encode($out);

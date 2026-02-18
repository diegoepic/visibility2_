<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$region = intval($_GET['region'] ?? 0);
if ($region <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, comuna
    FROM comuna
    WHERE id_region = ?
    ORDER BY comuna
");
$stmt->bind_param("i", $region);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id'     => (int)$r['id'],
        'nombre' => $r['comuna']
    ];
}

header('Content-Type: application/json');
echo json_encode($out);

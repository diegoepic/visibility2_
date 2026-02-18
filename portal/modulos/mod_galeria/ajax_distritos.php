<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$zona = intval($_GET['zona'] ?? 0);
if ($zona <= 0) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, nombre_distrito
    FROM distrito
    WHERE id_zona = ?
    ORDER BY nombre_distrito
");
$stmt->bind_param("i", $zona);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id'     => (int)$r['id'],
        'nombre' => $r['nombre_distrito']
    ];
}

header('Content-Type: application/json');
echo json_encode($out);

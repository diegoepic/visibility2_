<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

$response = [
    'ok' => false,
    'items' => []
];

$sql = "
    SELECT id, nombre
    FROM system_changelog_types
    WHERE estado = 1
    ORDER BY nombre ASC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $response['items'][] = $row;
    }
    $response['ok'] = true;
}

echo json_encode($response);
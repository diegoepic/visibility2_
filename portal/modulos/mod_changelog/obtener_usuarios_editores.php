<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';
header('Content-Type: application/json; charset=utf-8');

$response = [
    'ok' => false,
    'items' => []
];


$sql = "
SELECT 
    u.id,
    UPPER(
        CONCAT(
            COALESCE(u.nombre, ''), 
            CASE 
                WHEN u.apellido IS NOT NULL AND u.apellido <> '' 
                THEN CONCAT(' ', u.apellido)
                ELSE ''
            END
        )
    ) AS nombre_completo,
    u.usuario,
    p.nombre AS perfil_nombre
FROM usuario u
INNER JOIN perfil p
    ON p.id = u.id_perfil
WHERE u.activo = 1
  AND LOWER(TRIM(p.nombre)) = 'editor'
ORDER BY nombre_completo ASC;
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $nombreMostrar = trim($row['nombre_completo']);
        if ($nombreMostrar === '') {
            $nombreMostrar = $row['usuario'];
        }

        $response['items'][] = [
            'id' => $row['id'],
            'nombre' => $nombreMostrar
        ];
    }

    $response['ok'] = true;
}

echo json_encode($response);
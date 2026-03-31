<?php
// actualizar_sort_order_set.php

session_start();
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "No autorizado.";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tree']) || !isset($_POST['idSet'])) {
    http_response_code(400);
    echo "Parámetros insuficientes.";
    exit();
}

$idSet = intval($_POST['idSet']);
$treeJson = $_POST['tree'];
$tree = json_decode($treeJson, true);
if ($tree === null) {
    http_response_code(400);
    echo "JSON inválido.";
    exit();
}

$globalOrder = 1;
/**
 * Función recursiva que recorre el árbol de preguntas y actualiza el campo sort_order.
 * Cada nodo debe tener la estructura: { "id": "ID_de_pregunta", "children": [ ... ] }
 */
function updateNodeOrder($nodes, $idSet, &$globalOrder, $conn) {
    foreach ($nodes as $node) {
        $nodeId = intval($node['id']);
        $stmt = $conn->prepare("UPDATE question_set_questions SET sort_order = ? WHERE id = ? AND id_question_set = ?");
        $stmt->bind_param("iii", $globalOrder, $nodeId, $idSet);
        $stmt->execute();
        $stmt->close();
        $globalOrder++;
        if (isset($node['children']) && is_array($node['children'])) {
            updateNodeOrder($node['children'], $idSet, $globalOrder, $conn);
        }
    }
}

updateNodeOrder($tree, $idSet, $globalOrder, $conn);
echo "Orden actualizado correctamente.";
?>

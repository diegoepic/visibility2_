<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
$base_url = "https://visibility.cl/visibility2/app/";

// Obtener el ID del local, la vista y los filtros de fecha (por GET)
$local = isset($_GET['local']) ? intval($_GET['local']) : 0;
$view  = isset($_GET['view']) ? trim($_GET['view']) : 'implementacion';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

if ($local <= 0) {
    echo json_encode(["data" => []]);
    exit();
}

$data = [];

if ($view === 'implementacion') {
    // Consulta para la vista de implementación (sin filtro de fecha o con otro esquema)
    $sql = "
        SELECT 
           fv.url,
           fq.fechaVisita AS fecha
        FROM formularioQuestion fq
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_local = ?
        ORDER BY fq.fechaVisita DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $local);
} elseif ($view === 'encuesta') {
    // Consulta para la vista de encuesta, aplicando filtro de fecha
    $sql = "
        SELECT 
           fqr.answer_text AS url,
           fqr.created_at AS fecha
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        WHERE fqr.id_local = ?
          AND fq.id_question_type = 7
    ";
    // Agregar filtros de fecha si se han enviado
    if ($start_date !== '') {
        $sql .= " AND DATE(fqr.created_at) >= ? ";
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fqr.created_at) <= ? ";
    }
    $sql .= " ORDER BY fqr.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(["error" => $conn->error]);
        exit();
    }
    // Construir el binding dinámico
    $bindTypes = "i";
    $bindParams = [$local];
    if ($start_date !== '') {
        $bindTypes .= "s";
        $bindParams[] = $start_date;
    }
    if ($end_date !== '') {
        $bindTypes .= "s";
        $bindParams[] = $end_date;
    }
    call_user_func_array([$stmt, 'bind_param'], array_merge([$bindTypes], refValues($bindParams)));
} else {
    // Fallback: usamos la consulta de implementación
    $sql = "
        SELECT 
           fv.url,
           fq.fechaVisita AS fecha
        FROM formularioQuestion fq
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_local = ?
        ORDER BY fq.fechaVisita DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $local);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['url'] = fixUrl($row['url'], $base_url);
    $row['fecha'] = formatearFecha($row['fecha']);
    $data[] = $row;
}
$stmt->close();

header('Content-Type: application/json');
echo json_encode(["data" => $data]);
exit();

function fixUrl($url, $base_url) {
    $prefix = "../app/";
    if (substr($url, 0, strlen($prefix)) === $prefix) {
        $url = substr($url, strlen($prefix));
    }
    return $base_url . $url;
}

function formatearFecha($f) {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    return $arr;
}
?>

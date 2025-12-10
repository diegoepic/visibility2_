<?php
ob_clean();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Función adaptada para obtener la encuesta (pivot) usando filtros vía GET
function getEncuestaPivotConFiltros() {
    global $conn;
    
    // Recoger parámetros vía GET
    $canal        = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : 0;
    $distrito     = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : 0;
    $division     = isset($_GET['id_division']) ? intval($_GET['id_division']) : 0;
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
    $fecha_fin    = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
    $ejecutor = isset($_GET['id_usuario']) ? intval($_GET['id_usuario']) : 0;
    // Construir filtros dinámicos para la consulta
    $filtros = '';
    if ($canal) {
        $filtros .= " AND l.id_canal = $canal";
    }
    if ($distrito) {
        $filtros .= " AND l.id_distrito = $distrito";
    }
    if ($division) {
        $filtros .= " AND f.id_division = $division";
    }
    if (!empty($fecha_inicio)) {
        $filtros .= " AND DATE(fqr.created_at) >= '" . mysqli_real_escape_string($conn, $fecha_inicio) . "'";
    }
    if (!empty($fecha_fin)) {
        $filtros .= " AND DATE(fqr.created_at) <= '" . mysqli_real_escape_string($conn, $fecha_fin) . "'";
    }
    if ($ejecutor) {
    // filtrar respuestas de la encuesta por usuario
    $filtros .= " AND fqr.id_usuario = $ejecutor";
}
    // Consulta pivot adaptada
    $query = "
        SELECT 
            f.id AS idCampana, 
            f.nombre AS nombreCampana, 
            l.codigo AS codigo_local, 
            l.nombre AS nombre_local, 
            l.direccion AS direccion_local,
            cu.nombre AS cuenta,
            ca.nombre AS cadena,
            cm.comuna AS comuna,
            u.usuario AS usuario,
            DATE(fqr.created_at) AS fecha_respuesta,
            fp.question_text,
            fqr.answer_text
        FROM formulario f
        JOIN form_questions fp ON fp.id_formulario = f.id
        JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
        JOIN usuario u ON u.id = fqr.id_usuario
        JOIN local l ON l.id = fqr.id_local
        JOIN cuenta cu ON cu.id = l.id_cuenta
        JOIN cadena ca ON ca.id = l.id_cadena
        JOIN comuna cm ON cm.id = l.id_comuna
        where f.tipo = 3 $filtros
        ORDER BY l.codigo, fp.sort_order
    ";
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        die("Error en getEncuestaPivotConFiltros: " . mysqli_error($conn));
    }
    
    $pivot = [];
    // Agrupar por código local y fecha de respuesta para diferenciar envíos
    while ($row = mysqli_fetch_assoc($result)) {
        $groupKey = $row['codigo_local'] . '_' . $row['fecha_respuesta'];
        if (!isset($pivot[$groupKey])) {
            $pivot[$groupKey] = [
                'idCampana'       => $row['idCampana'],
                'nombreCampana'   => $row['nombreCampana'],
                'codigo_local'    => $row['codigo_local'],
                'nombre_local'    => $row['nombre_local'],
                'direccion_local' => $row['direccion_local'],
                'cuenta'          => $row['cuenta'],
                'cadena'          => $row['cadena'],
                'comuna'          => $row['comuna'],
                'usuario'         => $row['usuario'],
                'fecha_respuesta' => $row['fecha_respuesta']
            ];
        }
        $question = $row['question_text'];
        $answer   = $row['answer_text'];
        // Si ya existe la pregunta en el grupo, concatenar respuestas evitando duplicados
        if (isset($pivot[$groupKey][$question])) {
            $existing = explode(", ", $pivot[$groupKey][$question]);
            if (!in_array($answer, $existing)) {
                $pivot[$groupKey][$question] .= ", " . $answer;
            }
        } else {
            $pivot[$groupKey][$question] = $answer;
        }
    }
    return array_values($pivot);
}

// Obtener la encuesta pivot con filtros
$encuesta = getEncuestaPivotConFiltros();

// Construir el HTML del reporte
$shtml = "<html><head><meta charset='UTF-8'></head><body>";
$shtml .= "<h2>Encuesta: Preguntas y Respuestas (Pivot)</h2>";
$shtml .= "<table border='1'>";

// Obtener todas las preguntas (columnas dinámicas)
$allQuestions = [];
foreach ($encuesta as $row) {
    foreach ($row as $key => $value) {
        if (!in_array($key, [
            'idCampana', 'nombreCampana',
            'codigo_local', 'nombre_local', 'direccion_local',
            'cuenta', 'cadena', 'comuna',
            'usuario', 'fecha_respuesta'
        ])) {
            $allQuestions[$key] = $key;
        }
    }
}
$allQuestions = array_values($allQuestions);

// Determinar el tipo de salida según la existencia de 'codigo_local'
$esTipo1 = (!empty($encuesta) && isset($encuesta[0]['codigo_local']));

if ($esTipo1) {
    $shtml .= "<tr>
                <th>ID Campaña</th>
                <th>Nombre Campaña</th>
                <th>Código Local</th>
                <th>Cuenta</th>
                <th>Cadena</th>
                <th>Nombre Local</th>
                <th>Dirección Local</th>
                <th>Comuna</th>
                <th>Usuario</th>
                <th>Fecha Respuesta</th>";
} else {
    $shtml .= "<tr>
                <th>ID Campaña</th>
                <th>Nombre Campaña</th>
                <th>ID Usuario</th>
                <th>Nombre Usuario</th>
                <th>Fecha Respuesta</th>";
}
foreach ($allQuestions as $question) {
    $shtml .= "<th>" . htmlspecialchars($question) . "</th>";
}
$shtml .= "</tr>";

// Imprimir cada fila del pivot
foreach ($encuesta as $row) {
    $shtml .= "<tr>";
    if ($esTipo1) {
        $shtml .= "<td>" . htmlspecialchars($row['idCampana']) . "</td>";
        $shtml .= "<td>" . htmlspecialchars($row['nombreCampana']) . "</td>";
        $shtml .= "<td>" . (isset($row['codigo_local']) ? htmlspecialchars($row['codigo_local']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['cuenta']) ? htmlspecialchars($row['cuenta']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['cadena']) ? htmlspecialchars($row['cadena']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['nombre_local']) ? htmlspecialchars($row['nombre_local']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['direccion_local']) ? htmlspecialchars($row['direccion_local']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['comuna']) ? htmlspecialchars($row['comuna']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['usuario']) ? htmlspecialchars($row['usuario']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['fecha_respuesta']) ? htmlspecialchars($row['fecha_respuesta']) : "") . "</td>";
    } else {
        $shtml .= "<td>" . htmlspecialchars($row['idCampana']) . "</td>";
        $shtml .= "<td>" . htmlspecialchars($row['nombreCampana']) . "</td>";
        $shtml .= "<td>" . (isset($row['idUsuario']) ? htmlspecialchars($row['idUsuario']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['nombreUsuario']) ? htmlspecialchars($row['nombreUsuario']) : "") . "</td>";
        $shtml .= "<td>" . (isset($row['fecha_respuesta']) ? htmlspecialchars($row['fecha_respuesta']) : "") . "</td>";
    }
    // Agregar las respuestas de cada pregunta dinámica
    foreach ($allQuestions as $question) {
        $valorCelda = isset($row[$question]) ? $row[$question] : "";
        $shtml .= "<td>" . htmlspecialchars($valorCelda) . "</td>";
    }
    $shtml .= "</tr>";
}
$shtml .= "</table>";
$shtml .= "</body></html>";

// Construir cuerpo final con BOM
$body = "\xEF\xBB\xBF" . $shtml;

// Evitar compresión/colchones que rompen el progreso
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
ini_set('zlib.output_compression', '0');
ini_set('output_buffering', '0');
while (ob_get_level()) { ob_end_flush(); }

// Cabeceras
$nombreArchivo = "Reporte_Pivot_" . date("Ymd_His") . ".xls";
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Expires: 0");
header("Content-Disposition: attachment; filename=\"{$nombreArchivo}\"");
header("Content-Length: " . strlen($body)); // bytes

// Enviar
echo $body;
flush();
exit;
?>

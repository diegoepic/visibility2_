<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Recoger parámetros
$formato   = isset($_GET['formato']) ? $_GET['formato'] : 'csv';
$canal     = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : '';
$distrito  = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : '';
$division  = isset($_GET['id_division']) ? intval($_GET['id_division']) : '';
$ejecutor = isset($_GET['id_usuario']) ? intval($_GET['id_usuario']) : 0;


// Filtros de fecha para fq.fechaVisita
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin    = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

// Construir filtros dinámicos
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
    $filtros .= " AND DATE(fq.fechaVisita) >= '" . mysqli_real_escape_string($conn, $fecha_inicio) . "'";
}
if (!empty($fecha_fin)) {
    $filtros .= " AND DATE(fq.fechaVisita) <= '" . mysqli_real_escape_string($conn, $fecha_fin) . "'";
}
if ($ejecutor) {
    $filtros .= " AND fq.id_usuario = $ejecutor";
}

// Consulta SQL con filtros dinámicos
$query = "
    SELECT 
        l.id as 'ID Resultado',
        f.nombre AS 'ACTIVIDAD',        
        l.codigo AS 'ID LOCAL',        
        DATE(fq.fechaVisita) as 'FECHA VISITA',
        TIME(fq.fechaVisita) as HORA,          
        l.nombre AS 'NOMBRE LOCAL',
        l.direccion AS Direccion, 
        d.nombre_distrito as 'DISTRITO',        
        cm.comuna AS COMUNA,
        re.region as REGION,
        u.usuario AS USUARIO,
        CASE
            WHEN fq.pregunta IN ('en proceso', 'cancelado')
                THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion, '|', '-'), '-', 1))
            ELSE fq.pregunta
        END AS 'ESTADO GESTION',
        fq.material AS 'MATERIAL',
        fq.valor AS 'VALOR',        
        fq.valor_propuesto AS 'VALOR PROPUESTO',
        fq.observacion as 'OBSERVACION'        
    FROM formularioQuestion fq
    INNER JOIN local l ON l.id = fq.id_local
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN usuario u ON fq.id_usuario = u.id
    INNER JOIN cuenta cu ON l.id_cuenta = cu.id
    INNER JOIN cadena ca ON l.id_cadena = ca.id
    INNER JOIN comuna cm ON l.id_comuna = cm.id
    INNER JOIN region re ON cm.id_region = re.id
    INNER JOIN distrito d ON d.id = l.id_distrito
    INNER JOIN vendedor v ON v.id = l.id_vendedor
    INNER JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
    WHERE f.tipo = 3 AND fq.countVisita > 0 $filtros
    ORDER BY l.codigo, fq.fechaVisita ASC
";

// Ejecutar la consulta usando unbuffered query
$result = mysqli_query($conn, $query, MYSQLI_USE_RESULT);

// Función para transformar caracteres (por ejemplo, remover acentos)
function transformarCaracteres($string) {
    $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
    $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
    return str_replace($buscar, $reemplazar, $string);
}

if ($result) {
    // Generar nombre del archivo con fecha y hora
    $filename = "data_ruta_" . date("Ymd_His") . ".csv";
    
    // Enviar encabezados para forzar la descarga del CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Abrir el stream de salida en modo binario y agregar BOM UTF-8
    $output = fopen('php://output', 'wb');
    fputs($output, "\xEF\xBB\xBF");
    
    // Intentar obtener la primera fila para escribir el encabezado
    $firstRow = mysqli_fetch_assoc($result);
    if ($firstRow) {
        // Escribir encabezados (nombres de columnas) usando delimitador ;
        fputcsv($output, array_keys($firstRow), ';');
        // Escribir la primera fila transformada
        fputcsv($output, array_map('transformarCaracteres', $firstRow), ';');
        
        // Recorrer el resto de las filas y escribirlas en el CSV
        while ($row = mysqli_fetch_assoc($result)) {
            $filaTransformada = array_map('transformarCaracteres', $row);
            fputcsv($output, $filaTransformada, ';');
        }
    } else {
        echo "No hay datos disponibles para exportar.";
    }
    
    fclose($output);
    mysqli_free_result($result);
    exit();
} else {
    echo "Error en la consulta: " . mysqli_error($conn);
}
?>

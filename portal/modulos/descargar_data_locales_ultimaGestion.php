<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Configurar la conexi贸n para usar UTF-8
$conn->set_charset("utf8");

$formato = isset($_GET['formato']) ? $_GET['formato'] : 'csv';
$canal   = isset($_GET['canalug']) ? intval($_GET['canalug']) : '';
$distrito= isset($_GET['distritoug']) ? intval($_GET['distritoug']) : '';
$division= isset($_GET['division']) ? intval($_GET['division']) : '';  // Nuevo: filtro por divisi贸n

// Construir filtros din谩micos
$filtros = '';
if ($canal) {
    $filtros .= " AND l.id_canal = $canal";
}
if ($distrito) {
    $filtros .= " AND l.id_distrito = $distrito";
}
if ($division) {
    $filtros .= " AND de.id = $division";
}

// Consulta SQL con filtros din谩micos
$query = "
SELECT distinct
    de.nombre AS 'division',
	CASE 
	  WHEN f.nombre LIKE '%moderno%' THEN 'MODERNO'
	  WHEN f.nombre LIKE '%tradicional%' THEN 'TRADICIONAL'
	  ELSE f.nombre
	END AS 'campaña',
    l.codigo,
    l.nombre AS 'local',
    l.direccion,
    c.comuna,
    r.region,
    l.lat as 'latitud',
    l.lng as 'longitud',
    DATE(fq.fechaVisita) AS 'Fecha Ultima Gestion',
    DATE(fq.fechaPropuesta) AS 'Fecha propuesta',
        CASE
            WHEN fq.pregunta IN ('en proceso', 'cancelado')
                THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion, '|', '-'), '-', 1))
            ELSE fq.pregunta
        END AS 'Ultima Gestion',
    fq.observacion
FROM local l
INNER JOIN comuna c ON c.id = l.id_comuna
INNER JOIN region r ON r.id = c.id_region
INNER JOIN division_empresa de ON de.id = l.id_division
INNER JOIN formularioQuestion fq ON fq.id_local = l.id
INNER JOIN formulario f ON f.id = fq.id_formulario
INNER JOIN (
    SELECT id_local, MAX(fechaVisita) AS max_fecha
    FROM formularioQuestion
    GROUP BY id_local
) ult ON ult.id_local = fq.id_local AND fq.fechaVisita = ult.max_fecha
where 1=1 AND fq.id_usuario != 50 $filtros
ORDER BY l.codigo; 
";

$result = $conn->query($query);

if ($result->num_rows > 0) {
    $data = array();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Descargar en CSV (o Excel, si lo deseas)
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="data_locales.csv"');
    $output = fopen('php://output', 'w');
    
    // Agregar BOM para UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Escribir encabezados con el delimitador ;
    fputcsv($output, array_keys($data[0]), ';');

    // Escribir cada fila
    foreach ($data as $row) {
        fputcsv($output, $row, ';');
    }
    fclose($output);
} else {
    echo "No hay datos disponibles para exportar.";
}
?>

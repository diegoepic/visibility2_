<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Recoger parámetros
$formato   = isset($_GET['formato']) ? $_GET['formato'] : 'csv';
$canal     = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : '';
$distrito  = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : '';
$division  = isset($_GET['id_division']) ? intval($_GET['id_division']) : '';
$estado    = isset($_GET['estado']) ? intval($_GET['estado']) : '';
$ejecutor     = isset($_GET['id_usuario'])   ? intval($_GET['id_usuario'])   : 0;
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
if ($estado) {
    $filtros .= " AND f.estado = $estado";
}
if ($ejecutor) {
    $filtros .= " AND fq.id_usuario = $ejecutor";
}

$conn->query("SET @rownum := 0");

// Consulta SQL con filtros dinámicos
$query = "
        SELECT 
            de.nombre                          AS 'CUENTA PORTAL',
            (@rownum := @rownum + 1)           AS N,
            UPPER(e.nombre)                    AS 'EMPRESA',
            UPPER(f.nombre)                    AS 'CAMPAÑA',            
            l.codigo                           AS 'CODIGO',
            CASE
              WHEN l.nombre REGEXP '^[0-9]+' 
              THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
            END                                AS 'N° LOCAL',
            DATE(fq.fechaPropuesta)            AS 'FECHA CREACION',
            DATE(fq.fechaPropuesta)            AS 'FECHA INICIO IMPLEMENTACION',  
            DATE(fq.fechaPropuesta)            AS 'FECHA FIN IMPLEMENTACION',  
            DATE(fq.fechaPropuesta)            AS 'FECHA VIGENTE DESDE',  
            DATE(fq.fechaPropuesta)            AS 'FECHA VIGENTE HASTA',              
            DATE(fq.fechaVisita)               AS 'FECHA VISITA',
            DATE(fq.fechaVisita)               AS 'FECHA VISITA2',            
            TIME(fq.fechaVisita)               AS HORA,            
            UPPER(cu.nombre)                   AS CUENTA,
            UPPER(ca.nombre)                   AS CADENA,
            UPPER(l.nombre)                    AS LOCAL,
            UPPER(l.direccion)                 AS DIRECCION,
            UPPER(re.region)                   AS REGION,            
            UPPER(cm.comuna)                   AS COMUNA,
            UPPER(c.nombre_canal)              AS CATEGORIA,
            UPPER(u.usuario)                   AS USUARIO,
            CASE
              WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
              WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
              WHEN LOWER(fq.pregunta) = 'solo_auditado'         THEN 'AUDITORIA'
              WHEN LOWER(fq.pregunta) = 'solo_auditoria'        THEN 'AUDITORIA'
              WHEN LOWER(fq.pregunta) = 'retiro'                THEN 'RETIRO'
              WHEN LOWER(fq.pregunta) = 'entrega'               THEN 'ENTREGA'
              WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
              ELSE 'NO IMPLEMENTADO'
            END AS 'ESTADO ACTIVIDAD',
            UPPER(
              REPLACE(
                CASE
                  WHEN IFNULL(fq.valor,0) = 0 THEN
                    TRIM(
                      SUBSTRING_INDEX(
                        REPLACE(fq.observacion,'|','-'),
                        '-',
                        1
                      )
                    )
                  WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                    TRIM(
                      SUBSTRING_INDEX(
                        REPLACE(fq.observacion,'|','-'),
                        '-',
                        1
                      )
                    )
                  WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN
                    ''
                  ELSE
                    fq.pregunta
                END
              , '_', ' ')
            ) AS MOTIVO,
            UPPER(fq.material)                 AS MATERIAL,
            fq.valor                           AS 'CANTIDAD MATERIAL EJECUTADO',            
            fq.valor_propuesto                 AS 'MATERIAL PROPUESTO',
            UPPER(fq.observacion)              AS OBSERVACION        
        FROM formularioQuestion fq
        INNER JOIN formulario   f ON f.id      = fq.id_formulario
        INNER JOIN local        l ON l.id      = fq.id_local
        INNER JOIN usuario      u ON u.id      = fq.id_usuario
        INNER JOIN cuenta       cu ON cu.id     = l.id_cuenta
        INNER JOIN cadena       ca ON ca.id     = l.id_cadena
        INNER JOIN canal        c ON c.id       = l.id_canal        
        INNER JOIN comuna       cm ON cm.id     = l.id_comuna
        INNER JOIN region       re ON re.id     = cm.id_region
        INNER JOIN division_empresa de ON de.id = f.id_division
        INNER JOIN empresa      e  ON e.id = de.id_empresa        
        WHERE f.tipo = 1 $filtros
        ORDER BY N ASC
";

$result = $conn->query($query);

if ($result->num_rows > 0) {
    $data = array();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Función para transformar caracteres
    function transformarCaracteres($string) {
        $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
        $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
        return str_replace($buscar, $reemplazar, $string);
    }

    // Generar nombre del archivo con fecha y hora
    $filename = "data_programadas_" . date("Ymd_His") . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'wb');  // Modo binario
    
    // Agregar BOM para UTF-8 (soluciona problemas de acentos en Excel)
    fputs($output, "\xEF\xBB\xBF");
    
    // Escribir encabezados usando el delimitador ;
    fputcsv($output, array_keys($data[0]), ';');
        
    foreach ($data as $row) {
        $filaTransformada = array_map('transformarCaracteres', $row);
        fputcsv($output, $filaTransformada, ';');
    }
    fclose($output);
} else {
    echo "No hay datos disponibles para exportar.";
}
?>

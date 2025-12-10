<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$conn->set_charset("utf8");

$formato  = $_GET['formato']   ?? 'csv';
$canal    = intval($_GET['canal']   ?? 0);
$distrito = intval($_GET['distrito']?? 0);
$division = intval($_GET['division']?? 0);
$tipoGestion= intval($_GET['tipoGestion'] ?? 0);

// Construir filtros din谩micos
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
if ($tipoGestion) {
    $filtros .= " AND f.tipo = $tipoGestion";
}

// Consulta SQL con filtros din谩micos
$query = "
SELECT
    l.id AS `ID LOCAL`,
    l.codigo AS `CODIGO LOCAL`,
    CASE
      WHEN l.nombre REGEXP '^[0-9]+'
        THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
      ELSE ''
    END AS `NUMERO LOCAL`,
    UPPER(f.nombre) AS `CAMPANA`,
    UPPER(cu.nombre) AS `CUENTA`,
    UPPER(ca.nombre) AS `CADENA`,
    UPPER(l.nombre) AS `LOCAL`,
    UPPER(l.direccion) AS `DIRECCION`,
    UPPER(cm.comuna) AS `COMUNA`,
    UPPER(re.region) AS `REGION`,
    UPPER(u.usuario) AS `USUARIO`,
    DATE(fq.fechaPropuesta) AS `FECHA PROPUESTA`,
    DATE(fq.fechaVisita)    AS `FECHA VISITA`,
    TIME(fq.fechaVisita)    AS `HORA`,
    CASE
      WHEN fq.fechaVisita IS NOT NULL
           AND fq.fechaVisita <> '0000-00-00 00:00:00'
        THEN 'VISITADO'
      ELSE 'NO VISITADO'
    END AS `ESTADO VISTA`,
    CASE
      WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
      WHEN LOWER(fq.pregunta) = 'solo_auditado'         THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'solo_auditoria'        THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'retiro'                THEN 'RETIRO'
      WHEN LOWER(fq.pregunta) = 'entrega'               THEN 'ENTREGA'
      WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
      ELSE ''
    END AS `ESTADO ACTIVIDAD`,
    UPPER(
      REPLACE(
        CASE
          WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
            TRIM(
              SUBSTRING_INDEX(
                REPLACE(fq.observacion, '|', '-'),
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
    ) AS `MOTIVO`,
    UPPER(fq.observacion) AS `OBSERVACION`
FROM formularioQuestion fq
INNER JOIN formulario   f  ON f.id  = fq.id_formulario
INNER JOIN local        l  ON l.id  = fq.id_local
INNER JOIN usuario      u  ON u.id  = fq.id_usuario
INNER JOIN cuenta       cu ON cu.id = l.id_cuenta
INNER JOIN cadena       ca ON ca.id = l.id_cadena
INNER JOIN comuna       cm ON cm.id = l.id_comuna
INNER JOIN region       re ON re.id = cm.id_region
WHERE 1=1
  $filtros
GROUP BY
    l.id,
    l.codigo,
    CASE
      WHEN l.nombre REGEXP '^[0-9]+'
        THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
      ELSE ''
    END,
    UPPER(f.nombre),
    UPPER(cu.nombre),
    UPPER(ca.nombre),
    UPPER(l.nombre),
    UPPER(l.direccion),
    UPPER(cm.comuna),
    UPPER(re.region),
    UPPER(u.usuario),
    DATE(fq.fechaPropuesta),
    DATE(fq.fechaVisita),
    TIME(fq.fechaVisita),
    CASE
      WHEN fq.fechaVisita IS NOT NULL
           AND fq.fechaVisita <> '0000-00-00 00:00:00'
        THEN 'VISITADO'
      ELSE 'NO VISITADO'
    END,
    CASE
      WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
      WHEN LOWER(fq.pregunta) = 'solo_auditado'         THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'solo_auditoria'        THEN 'AUDITORIA'
      WHEN LOWER(fq.pregunta) = 'retiro'                THEN 'RETIRO'
      WHEN LOWER(fq.pregunta) = 'entrega'               THEN 'ENTREGA'
      WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
      ELSE ''
    END,
    UPPER(
      REPLACE(
        CASE
          WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
            TRIM(
              SUBSTRING_INDEX(
                REPLACE(fq.observacion, '|', '-'),
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
    ),
    UPPER(fq.observacion)
ORDER BY l.codigo, DATE(fq.fechaVisita), TIME(fq.fechaVisita); 
";

$result = $conn->query($query);

if ($result->num_rows > 0) {
    $data = array();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Descargar en CSV (o Excel, si lo deseas)
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="data_historico_locales.csv"');
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

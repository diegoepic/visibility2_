<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Recoger parámetros
$formato      = isset($_GET['formato']) ? $_GET['formato'] : 'csv';
$canal        = isset($_GET['id_canal']) ? intval($_GET['id_canal']) : 0;
$distrito     = isset($_GET['id_distrito']) ? intval($_GET['id_distrito']) : 0;
$division     = isset($_GET['id_division']) ? intval($_GET['id_division']) : 0;
$estado       = isset($_GET['estado']) ? intval($_GET['estado']) : 0;
$ejecutor     = isset($_GET['id_usuario']) ? intval($_GET['id_usuario']) : 0;

// Filtros de fecha para fq.fechaVisita
$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin    = isset($_GET['fecha_fin']) ? trim($_GET['fecha_fin']) : '';

// Validar formato de fecha YYYY-MM-DD
function validarFechaFiltro($fecha) {
    if ($fecha === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $fecha);

    if ($dt && $dt->format('Y-m-d') === $fecha) {
        return $fecha;
    }

    return '';
}

$fecha_inicio = validarFechaFiltro($fecha_inicio);
$fecha_fin    = validarFechaFiltro($fecha_fin);

/*
    Manejo seguro de fechas:
    - Soporta: 2026-01-01
    - Soporta: 2026-01-01 10:30:00
    - Evita error con: 0000-00-00
    - Evita error con: 0000-00-00 00:00:00
*/
$fechaVisitaSQL        = "NULLIF(LEFT(CAST(fq.fechaVisita AS CHAR), 10), '0000-00-00')";
$fechaPropuestaSQL     = "NULLIF(LEFT(CAST(fq.fechaPropuesta AS CHAR), 10), '0000-00-00')";
$fechaInicioCampanaSQL = "NULLIF(LEFT(CAST(f.fechaInicio AS CHAR), 10), '0000-00-00')";
$fechaTerminoCampanaSQL = "NULLIF(LEFT(CAST(f.fechaTermino AS CHAR), 10), '0000-00-00')";

$horaVisitaSQL = "
    CASE
        WHEN $fechaVisitaSQL IS NOT NULL
             AND CHAR_LENGTH(CAST(fq.fechaVisita AS CHAR)) >= 19
        THEN SUBSTRING(CAST(fq.fechaVisita AS CHAR), 12, 8)
        ELSE ''
    END
";

// Construir filtros dinámicos
$filtros = '';

if ($canal > 0) {
    $filtros .= " AND l.id_canal = $canal";
}

if ($distrito > 0) {
    $filtros .= " AND l.id_distrito = $distrito";
}

if ($division > 0) {
    $filtros .= " AND f.id_division = $division";
}

// Filtro por fechaInicio de campaña
if ($fecha_inicio !== '') {
    $fechaInicioSafe = mysqli_real_escape_string($conn, $fecha_inicio);
    $filtros .= " AND $fechaInicioCampanaSQL >= '$fechaInicioSafe'";
}

if ($fecha_fin !== '') {
    $fechaFinSafe = mysqli_real_escape_string($conn, $fecha_fin);
    $filtros .= " AND $fechaInicioCampanaSQL <= '$fechaFinSafe'";
}

if ($estado > 0) {
    $filtros .= " AND f.estado = $estado";
}

if ($ejecutor > 0) {
    $filtros .= " AND fq.id_usuario = $ejecutor";
}

// Consulta SQL con filtros dinámicos
$query = "
    SELECT 
        l.id AS 'ID LOCAL',
        l.codigo AS 'CODIGO',

        CASE
            WHEN l.nombre REGEXP '^[0-9]+' 
                THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
            ELSE CAST(l.codigo AS UNSIGNED)
        END AS 'N° LOCAL',

        UPPER(f.nombre) AS 'CAMPAÑA',

        UPPER(cu.nombre) AS 'CUENTA',
        UPPER(ca.nombre) AS 'CADENA',
        UPPER(l.nombre) AS 'LOCAL',
        UPPER(l.direccion) AS 'DIRECCION',
        UPPER(cm.comuna) AS 'COMUNA',
        UPPER(re.region) AS 'REGION',
        UPPER(u.usuario) AS 'USUARIO',

        $fechaInicioCampanaSQL AS 'FECHA INICIO CAMPAÑA',
        $fechaTerminoCampanaSQL AS 'FECHA TERMINO CAMPAÑA',
        $fechaVisitaSQL AS 'FECHA VISITA',
        $horaVisitaSQL AS 'HORA',

        CASE
            WHEN $fechaVisitaSQL IS NOT NULL
                THEN 'VISITADO'
            ELSE 'NO VISITADO'
        END AS 'ESTADO VISITA',

        CASE
            WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
            WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'IMPLEMENTADO'
            WHEN LOWER(fq.pregunta) = 'solo_auditado' THEN 'AUDITORIA'
            WHEN LOWER(fq.pregunta) = 'solo_auditoria' THEN 'AUDITORIA'
            WHEN LOWER(fq.pregunta) = 'retiro' THEN 'RETIRO'
            WHEN LOWER(fq.pregunta) = 'entrega' THEN 'ENTREGA'
            WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
            ELSE 'NO IMPLEMENTADO'
        END AS 'ESTADO ACTIVIDAD',

        UPPER(
            REPLACE(
                CASE
                    WHEN IFNULL(fq.valor, 0) = 0 THEN
                        TRIM(
                            SUBSTRING_INDEX(
                                REPLACE(COALESCE(fq.observacion, ''), '|', '-'),
                                '-',
                                1
                            )
                        )
                    WHEN LOWER(fq.pregunta) IN ('en proceso', 'cancelado') THEN
                        TRIM(
                            SUBSTRING_INDEX(
                                REPLACE(COALESCE(fq.observacion, ''), '|', '-'),
                                '-',
                                1
                            )
                        )
                    WHEN LOWER(fq.pregunta) IN ('solo_implementado', 'solo_auditoria') THEN ''
                    ELSE COALESCE(fq.pregunta, '')
                END,
                '_',
                ' '
            )
        ) AS 'MOTIVO',

        UPPER(COALESCE(fq.material, '')) AS 'MATERIAL',
        fq.valor AS 'CANTIDAD MATERIAL EJECUTADO',
        fq.valor_propuesto AS 'MATERIAL PROPUESTO',
        UPPER(COALESCE(fq.observacion, '')) AS 'OBSERVACION'

    FROM formularioQuestion fq

    INNER JOIN formulario f 
        ON f.id = fq.id_formulario

    INNER JOIN local l 
        ON l.id = fq.id_local

    INNER JOIN usuario u 
        ON u.id = fq.id_usuario

    INNER JOIN cuenta cu 
        ON cu.id = l.id_cuenta

    INNER JOIN cadena ca 
        ON ca.id = l.id_cadena

    INNER JOIN comuna cm 
        ON cm.id = l.id_comuna

    INNER JOIN region re 
        ON re.id = cm.id_region

    WHERE f.tipo = 1
    $filtros

    ORDER BY 
        l.codigo ASC,
        $fechaInicioCampanaSQL ASC
";

$result = $conn->query($query);

if ($result === false) {
    error_log('[descargar_data_programada] Error SQL: ' . $conn->error);
    error_log('[descargar_data_programada] Query: ' . $query);

    echo "Error ejecutando la consulta SQL: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
    exit;
}

if ($result->num_rows > 0) {
    $data = array();

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Función para transformar caracteres
    function transformarCaracteres($string) {
        $string = (string)($string ?? '');

        $buscar = array(
            'á', 'é', 'í', 'ó', 'ú',
            'Á', 'É', 'Í', 'Ó', 'Ú',
            'ñ', 'Ñ'
        );

        $reemplazar = array(
            'a', 'e', 'i', 'o', 'u',
            'A', 'E', 'I', 'O', 'U',
            'n', 'N'
        );

        return str_replace($buscar, $reemplazar, $string);
    }

    // Generar nombre del archivo con fecha y hora
    $filename = "data_programadas_" . date("Ymd_His") . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');

    // Agregar BOM para UTF-8
    fputs($output, "\xEF\xBB\xBF");

    // Escribir encabezados usando delimitador ;
    fputcsv($output, array_keys($data[0]), ';');

    foreach ($data as $row) {
        $filaTransformada = array_map('transformarCaracteres', $row);
        fputcsv($output, $filaTransformada, ';');
    }

    fclose($output);
    exit;

} else {
    echo "No hay datos disponibles para exportar.";
    exit;
}
?>
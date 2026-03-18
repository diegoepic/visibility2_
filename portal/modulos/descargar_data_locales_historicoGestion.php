<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$conn->set_charset("utf8mb4");

$formato      = $_GET['formato'] ?? 'csv';
$canal        = intval($_GET['canal'] ?? 0);
$distrito     = intval($_GET['distrito'] ?? 0);
$division     = intval($_GET['division'] ?? 0);
$tipoGestion  = intval($_GET['tipoGestion'] ?? 0);

// Opcional: aumentar límites si el archivo es muy grande
ini_set('memory_limit', '512M');
set_time_limit(0);

// Construir filtros dinámicos
$filtros = [];
$tipos   = '';
$valores = [];

if ($canal) {
    $filtros[] = "l.id_canal = ?";
    $tipos .= 'i';
    $valores[] = $canal;
}
if ($distrito) {
    $filtros[] = "l.id_distrito = ?";
    $tipos .= 'i';
    $valores[] = $distrito;
}
if ($division) {
    $filtros[] = "f.id_division = ?";
    $tipos .= 'i';
    $valores[] = $division;
}
if ($tipoGestion) {
    $filtros[] = "f.tipo = ?";
    $tipos .= 'i';
    $valores[] = $tipoGestion;
}

$whereFiltros = '';
if (!empty($filtros)) {
    $whereFiltros = ' AND ' . implode(' AND ', $filtros);
}

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
    DATE(fq.fechaVisita) AS `FECHA VISITA`,
    TIME(fq.fechaVisita) AS `HORA`,
    CASE
        WHEN fq.fechaVisita IS NOT NULL THEN 'VISITADO'
        ELSE 'NO VISITADO'
    END AS `ESTADO VISTA`,
    CASE
        WHEN LOWER(fq.pregunta) = 'solo_implementado' THEN 'IMPLEMENTADO'
        WHEN LOWER(fq.pregunta) = 'solo_auditado' THEN 'AUDITORIA'
        WHEN LOWER(fq.pregunta) = 'solo_auditoria' THEN 'AUDITORIA'
        WHEN LOWER(fq.pregunta) = 'retiro' THEN 'RETIRO'
        WHEN LOWER(fq.pregunta) = 'entrega' THEN 'ENTREGA'
        WHEN LOWER(fq.pregunta) = 'implementado_auditado' THEN 'IMPLEMENTADO/AUDITADO'
        ELSE ''
    END AS `ESTADO ACTIVIDAD`,
    UPPER(
        REPLACE(
            CASE
                WHEN LOWER(fq.pregunta) IN ('en proceso', 'cancelado') THEN
                    TRIM(
                        SUBSTRING_INDEX(
                            REPLACE(COALESCE(fq.observacion, ''), '|', '-'),
                            '-',
                            1
                        )
                    )
                WHEN LOWER(fq.pregunta) IN ('solo_implementado', 'solo_auditoria', 'solo_auditado') THEN
                    ''
                ELSE fq.pregunta
            END,
            '_',
            ' '
        )
    ) AS `MOTIVO`,
    UPPER(COALESCE(fq.observacion, '')) AS `OBSERVACION`
FROM formularioQuestion fq
INNER JOIN formulario f ON f.id = fq.id_formulario
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN usuario u ON u.id = fq.id_usuario
INNER JOIN cuenta cu ON cu.id = l.id_cuenta
INNER JOIN cadena ca ON ca.id = l.id_cadena
INNER JOIN comuna cm ON cm.id = l.id_comuna
INNER JOIN region re ON re.id = cm.id_region
WHERE 1=1
$whereFiltros
ORDER BY l.codigo, fq.fechaVisita
";

$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Error al preparar la consulta: " . $conn->error);
}

if (!empty($valores)) {
    $stmt->bind_param($tipos, ...$valores);
}

if (!$stmt->execute()) {
    die("Error al ejecutar la consulta: " . $stmt->error);
}

$result = $stmt->get_result();

if (!$result) {
    die("Error al obtener resultados: " . $stmt->error);
}

if ($result->num_rows === 0) {
    echo "No hay datos disponibles para exportar.";
    $stmt->close();
    $conn->close();
    exit;
}

// Salida CSV en streaming
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="data_historico_locales.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fwrite($output, "\xEF\xBB\xBF");

$headersWritten = false;
$contador = 0;

while ($row = $result->fetch_assoc()) {
    if (!$headersWritten) {
        fputcsv($output, array_keys($row), ';');
        $headersWritten = true;
    }

    fputcsv($output, $row, ';');

    $contador++;

    // liberar buffer cada cierta cantidad
    if ($contador % 1000 === 0) {
        fflush($output);
    }
}

fclose($output);

$stmt->close();
$conn->close();
exit;
?>
<?php
// Salida limpia + headers Excel
while (ob_get_level()) { ob_end_clean(); }
ob_start();
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=Reporte_Masivo_".date('Ymd_His').".xls");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Conexión
include $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/con_.php';
if (function_exists('mysqli_set_charset')) { mysqli_set_charset($conn, 'utf8mb4'); }

// 1) IDs: admitir ?ids=1,2,3 y ?ids[]=1&ids[]=2
if (!isset($_GET['ids'])) {
    die('No se recibieron campañas para descargar.');
}

$raw = $_GET['ids'];
if (is_array($raw)) {
    $ids = array_map('intval', $raw);
} else {
    // separa por coma o espacio
    $tokens = preg_split('/[,\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $ids = array_map('intval', $tokens);
}

// normalizar
$ids = array_values(array_unique(array_filter($ids, fn($v)=> $v > 0)));
if (empty($ids)) {
    die('Lista de campañas inválida.');
}

// 2) SQL con placeholders dinámicos
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "
SELECT 
    l.id                               AS idLocal,
    l.codigo                           AS codigo_local,
    CASE WHEN l.nombre REGEXP '^[0-9]+' 
         THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
         ELSE '' END                   AS numero_local,
    f.modalidad                        AS modalidad,
    UPPER(f.nombre)                    AS nombreCampana,
    DATE(f.fechaInicio)                AS fechaInicio,
    DATE(f.fechaTermino)               AS fechaTermino,
    DATE(fq.fechaVisita)               AS fechaVisita,
    TIME(fq.fechaVisita)               AS hora,
    DATE(fq.fechaPropuesta)            AS fechaPropuesta,
    UPPER(l.nombre)                    AS nombre_local,
    UPPER(l.direccion)                 AS direccion_local,
    UPPER(cm.comuna)                   AS comuna,
    UPPER(re.region)                   AS region,
    UPPER(cu.nombre)                   AS cuenta,
    UPPER(ca.nombre)                   AS cadena,
    UPPER(fq.material)                 AS material,
    UPPER(jv.nombre)                   AS jefeVenta,    
    fq.valor_propuesto,
    fq.valor,
    UPPER(fq.observacion)              AS observacion,
    CASE WHEN fq.fechaVisita IS NOT NULL AND fq.fechaVisita <> '0000-00-00 00:00:00'
         THEN 'VISITADO'
         ELSE 'NO VISITADO' END       AS ESTADO_VISTA,
            CASE
                WHEN IFNULL(fq.valor, 0) >= 1 THEN 'IMPLEMENTADO'
                WHEN IFNULL(fq.valor, 0) = 0 THEN 'NO IMPLEMENTADO'
                WHEN LOWER(fq.pregunta) = 'solo_implementado'      THEN 'IMPLEMENTADO'
                WHEN LOWER(fq.pregunta) = 'solo_auditado'          THEN 'AUDITORIA'
                WHEN LOWER(fq.pregunta) = 'solo_auditoria'         THEN 'AUDITORIA'
                WHEN LOWER(fq.pregunta) = 'retiro'                 THEN 'RETIRO'
                WHEN LOWER(fq.pregunta) = 'entrega'                THEN 'ENTREGA'
                WHEN LOWER(fq.pregunta) = 'implementado_auditado'  THEN 'IMPLEMENTADO/AUDITADO'
                ELSE 'NO IMPLEMENTADO'
            END AS ESTADO_ACTIVIDAD,
            UPPER(
              REPLACE(
                CASE
                  WHEN IFNULL(fq.valor,0) = 0 THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('en proceso','cancelado') THEN
                    TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'),'-',1))
                  WHEN LOWER(fq.pregunta) IN ('solo_implementado','solo_auditoria') THEN
                    ''
                  ELSE
                    fq.pregunta
                END
              , '_', ' ')
            ) AS MOTIVO,
    UPPER(u.usuario)                    AS gestionado_por
FROM formularioQuestion fq
INNER JOIN formulario   f  ON f.id  = fq.id_formulario
INNER JOIN local        l  ON l.id  = fq.id_local
LEFT  JOIN jefe_venta   jv ON jv.id = l.id_jefe_venta   -- <- LEFT por si falta jefe
INNER JOIN usuario      u  ON u.id  = fq.id_usuario
INNER JOIN cuenta       cu ON cu.id = l.id_cuenta
INNER JOIN cadena       ca ON ca.id = l.id_cadena
INNER JOIN comuna       cm ON cm.id = l.id_comuna
INNER JOIN region       re ON re.id = cm.id_region
WHERE f.id IN ($placeholders)
ORDER BY f.id, l.codigo, fq.fechaVisita ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Error al preparar consulta: ".mysqli_error($conn));
}
$types = str_repeat('i', count($ids));
mysqli_stmt_bind_param($stmt, $types, ...$ids);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
if (!$res) {
    die("Error en la consulta masiva: ".mysqli_error($conn));
}
if (mysqli_num_rows($res) === 0) {
    die("No hay datos para las campañas seleccionadas.");
}

// 3) Estructura de columnas
$cols = [
    'idLocal'        => 'ID LOCAL',
    'codigo_local'   => 'CODIGO',
    'numero_local'   => 'N° LOCAL',
    'nombreCampana'  => 'CAMPAÑA',
    'cuenta'         => 'CUENTA',
    'cadena'         => 'CADENA',
    'nombre_local'   => 'LOCAL',
    'direccion_local'=> 'DIRECCION',
    'comuna'         => 'COMUNA',
    'region'         => 'REGION',
    'gestionado_por' => 'USUARIO',
    'jefeVenta'      => 'JEFE VENTA',     
    'fechaPropuesta' => 'FECHA PLANIFICADA',
    'fechaVisita'    => 'FECHA VISITA',
    'hora'           => 'HORA',
    'ESTADO_VISTA'   => 'ESTADO VISITA',
    'ESTADO_ACTIVIDAD' => 'ESTADO ACTIVIDAD',
    'MOTIVO'         => 'MOTIVO',
    'material'       => 'MATERIAL',
    'valor'          => 'CANTIDAD MATERIAL EJECUTADO',
    'valor_propuesto'=> 'MATERIAL PROPUESTO',
    'observacion'    => 'OBSERVACION',
];

// 4) BOM una sola vez + tabla HTML
echo "\xEF\xBB\xBF";
echo "<html><body><table border='1'><thead><tr>";
foreach ($cols as $dbField => $label) {
    echo "<th>".htmlspecialchars($label, ENT_QUOTES, 'UTF-8')."</th>";
}
echo "</tr></thead><tbody>";

while ($r = mysqli_fetch_assoc($res)) {
    echo "<tr>";
    foreach (array_keys($cols) as $dbField) {
        $v = $r[$dbField] ?? '';
        echo "<td>".htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8')."</td>";
    }
    echo "</tr>";
}
echo "</tbody></table></body></html>";

ob_end_flush();
exit;

<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    exit("No autorizado");
}

$id_empresa = intval($_SESSION['empresa_id']);

$id_formulario = intval($_GET['id_formulario'] ?? 0);
$id_ejecutor   = intval($_GET['id_ejecutor'] ?? 0);
$fecha         = $_GET['fecha'] ?? '';

if ($id_formulario <= 0 || $id_ejecutor <= 0 || empty($fecha)) {
    exit("Parámetros inválidos");
}

$inicio = $fecha . " 00:00:00";
$fin    = $fecha . " 23:59:59";

$sql = "
SELECT 
    l.codigo,
    UPPER(l.nombre) AS nombre_local,
    UPPER(l.direccion) AS direccion_local,    
CASE
    WHEN fq.pregunta = 'cancelado' THEN 'CANCELADO'
    WHEN fq.pregunta = 'completado' THEN 'COMPLETADO'
    WHEN fq.pregunta = 'solo_auditoria' THEN 'AUDITADO'
    WHEN fq.pregunta IN ('solo_implementado','solo_retirado','implementado_auditado') THEN
        CASE
            WHEN IFNULL(fq.valor,0) = 0 THEN 'NO IMPLEMENTADO'
            ELSE
                CASE
                    WHEN fq.pregunta = 'solo_implementado' THEN 'IMPLEMENTADO'
                    WHEN fq.pregunta = 'solo_retirado' THEN 'RETIRADO'
                    WHEN fq.pregunta = 'implementado_auditado' THEN 'IMPLE/AUDI'
                    ELSE 'EN PROCESO'
                END
        END
    WHEN fq.pregunta = 'no_implementado' THEN 'NO IMPLEMENTADO'
    WHEN fq.pregunta = 'en proceso' OR fq.pregunta IS NULL OR fq.pregunta = '' THEN 'EN PROCESO'
    ELSE 'EN PROCESO'
END AS estado_texto,
    UPPER(
        CASE 
            WHEN fq.motivo IS NOT NULL AND fq.motivo <> '' THEN fq.motivo
            WHEN fq.observacion IS NOT NULL AND fq.observacion <> ''
                THEN 
                    CASE 
                        WHEN LOCATE('-', fq.observacion) > 0 
                            THEN TRIM(SUBSTRING_INDEX(fq.observacion, '-', 1))
                        ELSE fq.observacion
                    END
            ELSE ''
        END
    ) AS motivo_final,

    (
        SELECT GROUP_CONCAT(
            DISTINCT CONCAT(
                'https://visibility.cl/visibility2/app/',
                fv2.url
            )
            SEPARATOR '||'
        )
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id
    ) AS urls_fotos

FROM formularioQuestion fq
INNER JOIN local l ON l.id = fq.id_local
INNER JOIN formulario f ON f.id = fq.id_formulario

WHERE fq.id_formulario = ?
  AND fq.id_usuario = ?
  AND fq.fechaVisita BETWEEN ? AND ?
  AND f.id_empresa = ?

GROUP BY l.id
ORDER BY l.nombre
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error SQL: " . $conn->error);
}

$stmt->bind_param("iissi", $id_formulario, $id_ejecutor, $inicio, $fin, $id_empresa);
$stmt->execute();
$result = $stmt->get_result();

echo '<table class="table table-sm table-bordered table-striped">';
echo '<thead>
<tr>
<th>Código</th>
<th>Local</th>
<th>Direccion</th>
<th>Estado</th>
<th>Motivo</th>
<th>Fotos</th>
</tr>
</thead><tbody>';

while ($row = $result->fetch_assoc()) {

    $estado = strtoupper($row['estado_texto'] ?? '');
    $motivo = $row['motivo_final'] ?? '';
    $urls = $row['urls_fotos'] ?? '';

    $badge = 'secondary';

    if (strpos($estado, 'IMPLEMENTADO') !== false) $badge = 'success';
    if (strpos($estado, 'AUDITADO') !== false) $badge = 'primary';
    if (strpos($estado, 'CANCEL') !== false) $badge = 'danger';
    if (strpos($estado, 'RETIRADO') !== false) $badge = 'warning';

    echo '<tr>';
    echo '<td>'.$row['codigo'].'</td>';
    echo '<td>'.$row['nombre_local'].'</td>';
    echo '<td>'.$row['direccion_local'].'</td>';    
    echo '<td><span class="badge badge-'.$badge.'">'.$estado.'</span></td>';
    echo '<td>'.htmlspecialchars($motivo).'</td>';

    echo '<td>';
    
    if (!empty($urls)) {
        $fotos = explode('||', $urls);
        foreach ($fotos as $index => $foto) {
    
            echo '<img src="'.$foto.'"
                       class="img-miniatura"
                       data-foto="'.$foto.'"
                       data-grupo="'.$row['codigo'].'"
                       style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin-right:4px;cursor:pointer;">';
        }
    } else {
        echo '<span class="text-muted">Sin fotos</span>';
    }
    
    echo '</td>';

    echo '</tr>';
}

echo '</tbody></table>';

$stmt->close();
$conn->close();
<?php
// descarga_test.php
// Export dinámico a Excel pivotando preguntas como columnas con orden y valorización

// Mostrar errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir conexión
include $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/con_.php';

// Definir campañas a exportar (fijas)
$ids = [1725];
if (empty($ids)) {
    die('No hay campañas definidas para descargar.');
}
$in = implode(',', $ids);

// Configurar headers ANTES de cualquier salida
$filename = "REPORTE_PIVOT_" . date('Ymd_His') . ".XLS";
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: max-age=0");

// Iniciar buffer
ob_start();

// Meta charset para Excel reconocer UTF-8
echo "<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />";

// =======================================
// Sección Pivot Encuesta
// =======================================
// Definir la consulta SQL completa (comentario con "// eliminar comentarios de sort_order para probar sin orden")
$sql = "
SELECT
    f.id AS idCampana,
    l.id AS idLocal,
    l.codigo AS codigo_local,
    CASE WHEN l.nombre REGEXP '^[0-9]+'
         THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
         ELSE CAST(l.codigo AS UNSIGNED)
    END AS numero_local,
    ca.nombre_canal AS nombreCanal,
    di.nombre_distrito AS nombreDistrito,
    f.nombre AS nombreCampana,
    cu.nombre AS cuenta,
    cad.nombre AS cadena,
    l.nombre AS nombre_local,
    l.direccion AS direccion,
    c.comuna AS comuna,
    r.region AS region,
    z.nombre_zona AS nombreZona,
    UPPER(CONCAT(u.nombre, ' ', u.apellido)) AS gestionado_por,
    fp.question_text AS pregunta,
    fp.sort_order AS ordenPregunta,  -- comentar esta línea y el sort en ORDER BY para probar sin orden
    fp.is_valued AS preguntaValorizada,
    fqr.answer_text AS respuesta,
    fqr.valor AS valor,
    DATE(fqr.created_at) AS fecha_respuesta
FROM formulario f
JOIN form_questions fp ON fp.id_formulario = f.id
JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
JOIN usuario u ON u.id = fqr.id_usuario
JOIN local l ON l.id = fq
JOIN canal ca ON ca.id = l.id_canal
JOIN cuenta cu ON cu.id = l.id_cuenta
JOIN cadena cad ON cad.id = l.id_cadena
JOIN distrito di ON di.id = l.id_distrito
JOIN comuna c ON c.id = l.id_comuna
JOIN zona z ON z.id = l.id_zona
JOIN region r ON r.id = c.id_region
WHERE f.id IN ($in)
ORDER BY f.id, l.codigo, fp.sort_order, fqr.created_at ASC
";

$res = mysqli_query($conn, $sql);
if (!$res) {
    die("Error en la consulta: " . mysqli_error($conn));
}
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
if (empty($rows)) {
    die("No hay datos para las campañas seleccionadas.");
}

// Pivotear en PHP y duplicar filas cuando sea necesario
$pivotData = [];
$questionMeta = [];
foreach ($rows as $row) {
    $key = $row['idCampana'] . '|' . $row['codigo_local'];
    if (!isset($pivotData[$key])) {
        $pivotData[$key] = [
            'idLocal' => $row['idLocal'],
            'codigo_local' => $row['codigo_local'],
            'numero_local' => $row['numero_local'],
            'nombreCanal' => $row['nombreCanal'],
            'nombreDistrito' => $row['nombreDistrito'],
            'nombreCampana' => $row['nombreCampana'],
            'cuenta' => $row['cuenta'],
            'cadena' => $row['cadena'],
            'nombre_local' => $row['nombre_local'],
            'direccion' => $row['direccion'],
            'comuna' => $row['comuna'],
            'region' => $row['region'],
            'nombreZona' => $row['nombreZona'],
            'gestionado_por' => $row['gestionado_por'],
            'fecha_respuesta' => $row['fecha_respuesta']
        ];
    }
    $q = $row['pregunta'];
    $questionMeta[$q] = ['order' => $row['ordenPregunta'], 'valued' => $row['preguntaValorizada']];
    $pivotData[$key][$q][] = $row['respuesta'];
    if ($row['preguntaValorizada']) {
        $pivotData[$key][$q . '_valor'][] = $row['valor'];
    }
}

$rowsPivot = [];
foreach ($pivotData as $data) {
    $max = 1;
    foreach ($questionMeta as $q => $m) {
        $max = max($max, count($data[$q] ?? []));
        if ($m['valued']) {
            $max = max($max, count($data[$q . '_valor'] ?? []));
        }
    }
    for ($i = 0; $i < $max; $i++) {
        $out = [];
        foreach (['idLocal','codigo_local','numero_local','nombreCanal','nombreDistrito','nombreCampana','cuenta','cadena','nombre_local','direccion','comuna','region','nombreZona','gestionado_por','fecha_respuesta'] as $c) {
            $out[$c] = $data[$c];
        }
        foreach ($questionMeta as $q => $m) {
            $vals = $data[$q] ?? [];
            $out[$q] = isset($vals[$i]) ? $vals[$i] : (count($vals) === 1 ? $vals[0] : '');
            if ($m['valued']) {
                $vVals = $data[$q . '_valor'] ?? [];
                $out[$q . '_valor'] = isset($vVals[$i]) ? $vVals[$i] : (count($vVals) === 1 ? $vVals[0] : '');
            }
            // Mayúsculas y sin acentos
            $out[$q] = strtoupper(strtr($out[$q], ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']));
            if ($m['valued']) {
                $out[$q . '_valor'] = strtoupper(strtr($out[$q . '_valor'], ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']));
            }
        }
        $rowsPivot[] = $out;
    }
}

// Filtrar filas sin datos
$rowsPivot = array_filter($rowsPivot, function($r) use ($questionMeta) {
    foreach ($questionMeta as $q => $m) {
        if (!empty($r[$q]) || ($m['valued'] && !empty($r[$q . '_valor']))) {
            return true;
        }
    }
    return false;
});

// Preparar encabezados dinámicos
uksort($questionMeta, function($a, $b) use ($questionMeta) { return $questionMeta[$a]['order'] - $questionMeta[$b]['order']; });
$labels = [
    'idLocal'=>'ID LOCAL','codigo_local'=>'CÓDIGO LOCAL','numero_local'=>'NUMERO LOCAL',
    'nombreCanal'=>'CANAL','nombreDistrito'=>'DISTRITO','nombreCampana'=>'CAMPAÑA',
    'cuenta'=>'CUENTA','cadena'=>'CADENA','nombre_local'=>'LOCAL','direccion'=>'DIRECCIÓN',
    'comuna'=>'COMUNA','region'=>'REGIÓN','nombreZona'=>'ZONA','gestionado_por'=>'EJECUTOR',
    'fecha_respuesta'=>'FECHA RESPUESTA'
];
$colKeys = array_keys($labels);
foreach ($questionMeta as $q => $m) {
    $labels[$q] = strtoupper($q);
    $colKeys[] = $q;
    if ($m['valued']) {
        $labels[$q . '_valor'] = strtoupper($q) . ' (VALOR)';
        $colKeys[] = $q . '_valor';
    }
}

// Renderizar tabla
echo '<table border="1"><thead><tr>';
foreach ($colKeys as $key) {
    echo '<th>' . htmlspecialchars($labels[$key], ENT_QUOTES, 'UTF-8') . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($rowsPivot as $r) {
    echo '<tr>';
    foreach ($colKeys as $key) {
        echo '<td>' . htmlspecialchars($r[$key] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table>';

// Finalizar buffer
eb_end_flush();
exit();
?>

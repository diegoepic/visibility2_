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

// Iniciar buffer para capturar la salida
ob_start();

// Meta charset para Excel reconocer UTF-8
echo "<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />";

// Consulta base: obtenemos todos los registros de respuestas con orden y valorización
$sql = "
SELECT
    f.id                                   AS idCampana,
    l.id                                   AS idLocal,    
    l.codigo                               AS codigo_local,
    CASE
        WHEN l.nombre REGEXP '^[0-9]+' 
            THEN SUBSTRING_INDEX(l.nombre, ' ', 1)
              ELSE CAST(l.codigo AS UNSIGNED)
        END                                AS numero_local,    
    ca.nombre_canal                        AS nombreCanal,
    di.nombre_distrito                     AS nombreDistrito,
    f.nombre                               AS nombreCampana,
    cu.nombre                              AS cuenta,
    cad.nombre                             AS cadena,    
    l.nombre                               AS nombre_local,
    l.direccion                            AS direccion,    
    c.comuna                               AS comuna,
    r.region                               AS region,
    z.nombre_zona                          AS nombreZona,
    CONCAT(u.nombre, ' ', u.apellido)      AS nombreCompleto,
    UPPER(u.usuario)                       AS gestionado_por, 
    fp.question_text                       AS pregunta,
    fp.sort_order                          AS ordenPregunta,
    fp.is_valued                           AS preguntaValorizada,
    fqr.answer_text                        AS respuesta,
    fqr.valor                              AS valor,
    DATE(fqr.created_at)                   AS fecha_respuesta
FROM formulario f
JOIN form_questions fp           ON fp.id_formulario     = f.id
JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
JOIN usuario u                   ON u.id                 = fqr.id_usuario
JOIN local l                     ON l.id                 = fqr.id_local
JOIN canal ca                    ON ca.id                = l.id_canal
JOIN cuenta cu                   ON cu.id                = l.id_cuenta
JOIN cadena cad                  ON cad.id               = l.id_cadena
JOIN distrito di                 ON di.id                = l.id_distrito
JOIN comuna c                    ON c.id                 = l.id_comuna
JOIN zona z                      ON z.id                 = l.id_zona
JOIN region r                    ON r.id                 = c.id_region
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

// Pivotear en PHP respetando orden y valorización, duplicando filas para múltiples respuestas
$pivotData = [];
$questionMeta = [];
foreach ($rows as $row) {
    $key = "{$row['idCampana']}|{$row['codigo_local']}";
    if (!isset($pivotData[$key])) {
        $pivotData[$key] = [
            'idCampana'       => $row['idCampana'],
            'codigo_local'    => $row['codigo_local'],
            'fecha_respuesta' => $row['fecha_respuesta'],
            'nombreCanal'     => $row['nombreCanal'],
            'nombreDistrito'  => $row['nombreDistrito'],
            'nombreCampana'   => $row['nombreCampana'],
            'cuenta'          => $row['cuenta'],
            'cadena'          => $row['cadena'],            
            'nombre_local'    => $row['nombre_local'],
            'direccion'       => $row['direccion'],            
            'comuna'          => $row['comuna'],
            'region'          => $row['region'],
            'nombreZona'      => $row['nombreZona'],
            'nombreCompleto'  => $row['nombreCompleto'],
            'gestionado_por'  => $row['gestionado_por'],
            'numero_local'  => $row['numero_local'],
            'idLocal'  => $row['idLocal']             
        ];
    }
    // Metadatos para orden y valorización
    $questionMeta[$row['pregunta']] = [
        'order' => intval($row['ordenPregunta']),
        'valued'=> intval($row['preguntaValorizada'])
    ];
    // Agregar respuestas en arrays
    if (!isset($pivotData[$key][$row['pregunta']]) || !is_array($pivotData[$key][$row['pregunta']])) {
        $pivotData[$key][$row['pregunta']] = [];
    }
    $pivotData[$key][$row['pregunta']][] = $row['respuesta'];
    if ($row['preguntaValorizada']) {
        $vKey = $row['pregunta'] . '_valor';
        if (!isset($pivotData[$key][$vKey]) || !is_array($pivotData[$key][$vKey])) {
            $pivotData[$key][$vKey] = [];
        }
        $pivotData[$key][$vKey][] = $row['valor'];
    }
}

// Flatten: duplicar filas según el máximo de respuestas
$rowsPivot = [];
foreach ($pivotData as $data) {
    $replicate = 1;
    foreach ($questionMeta as $q => $m) {
        $replicate = max($replicate, count($data[$q] ?? []));
        if ($m['valued']) {
            $replicate = max($replicate, count($data[$q . '_valor'] ?? []));
        }
    }
    for ($i = 0; $i < $replicate; $i++) {
        $rowOut = [];
        foreach (['idLocal','codigo_local','numero_local','nombreCanal',
                  'nombreDistrito','nombreCampana','cuenta','cadena','nombre_local','direccion',
                  'comuna','region','nombreZona','gestionado_por','fecha_respuesta'] as $c) {
            $rowOut[$c] = $data[$c];
        }
        foreach ($questionMeta as $q => $m) {
            $rowOut[$q] = strtoupper(strtr(($data[$q][$i] ?? ''), [
                'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'
            ]));
            if ($m['valued']) {
                $vKey = $q . '_valor';
                $rowOut[$vKey] = strtoupper(strtr(($data[$vKey][$i] ?? ''), [
                    'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
                    'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'
                ]));
            }
        }
        $rowsPivot[] = $rowOut;
    }
}

// Filtrar filas vacías
$rowsPivot = array_filter($rowsPivot, function($r) use ($questionMeta) {
    foreach (array_keys($questionMeta) as $q) {
        if (!empty($r[$q]) || !empty($r[$q . '_valor'])) {
            return true;
        }
    }
    return false;
});

// Ordenar columnas
//uksort($questionMeta, function($a,$b) use($questionMeta){return $questionMeta[$a]['order']-$questionMeta[$b]['order'];});

// Definir encabezados
$cols = array_merge(
    ['ID LOCAL','CÓDIGO LOCAL','NUMERO LOCAL','CANAL','DISTRITO','CAMPAÑA',
     'CUENTA','CADENA','LOCAL','DIRECCION','COMUNA','REGIÓN','ZONA','EJECUTOR','FECHA RESPUESTA',],
    array_reduce(array_keys($questionMeta), function($acc,$q) use($questionMeta){
        $acc[] = strtoupper($q);
        if ($questionMeta[$q]['valued']) $acc[] = strtoupper($q).' (VALOR)';
        return $acc;
    }, [])
);

// Generar tabla HTML
echo '<table border="1"><thead><tr>';
foreach ($cols as $h) echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';
echo '</tr></thead><tbody>';
foreach ($rowsPivot as $r) {
    echo '<tr>';
    foreach (array_keys($cols) as $i) echo '<td>'.htmlspecialchars($r[array_keys($r)[$i]] ?? '',ENT_QUOTES,'UTF-8').'</td>';
    echo '</tr>';
}
echo '</tbody></table>';

// Enviar y limpiar buffer
ob_end_flush();
exit();
?>

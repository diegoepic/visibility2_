<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/con_.php';
$conn->set_charset('utf8');
date_default_timezone_set('America/Santiago');

// Parámetros
$canal    = intval($_GET['canal']    ?? 0);
$distrito = intval($_GET['distrito'] ?? 0);
$division = intval($_GET['division'] ?? $_GET['id_division'] ?? 0);
$formato  = strtolower(trim($_GET['formato'] ?? 'excel')); // 'excel' o 'csv'

// Filtros
$filtros = '';
if ($canal)    $filtros .= " AND l.id_canal    = $canal";
if ($distrito) $filtros .= " AND l.id_distrito = $distrito";
if ($division) $filtros .= " AND l.id_division = $division";

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// SQL
$sql = "
SELECT 
    COALESCE(d.nombre,        'SIN DIVISION')  AS DIVISION,
    l.codigo                                   AS CODIGO,
    COALESCE(can.nombre_canal,'SIN CANAL')     AS CANAL,
    COALESCE(ca.nombre,       'SIN CADENA')    AS CADENA,
    COALESCE(cu.nombre,       'SIN CUENTA')    AS CUENTA,
    l.nombre                                   AS LOCAL,
    l.direccion                                AS DIRECCION,
    COALESCE(co.comuna,       'SIN COMUNA')    AS COMUNA,
    COALESCE(re.region,       'SIN REGION')    AS REGION,
    COALESCE(di.nombre_distrito,'SIN DISTRITO') AS DISTRITO,
    COALESCE(zo.nombre_zona,'SIN ZONA')        AS ZONA,
    COALESCE(jv.nombre,'SIN JEFE DE VENTA')        AS JEFEVENTA,        
    l.lat                                      AS LATITUD,
    l.lng                                      AS LONGITUD
FROM local l
LEFT JOIN division_empresa d ON d.id = l.id_division
LEFT JOIN cadena          ca ON ca.id = l.id_cadena
LEFT JOIN cuenta          cu ON cu.id = l.id_cuenta
LEFT JOIN canal           can ON can.id = l.id_canal
LEFT JOIN comuna          co ON co.id = l.id_comuna
LEFT JOIN region          re ON re.id = co.id_region
LEFT JOIN distrito        di ON di.id = l.id_distrito
LEFT JOIN zona            zo ON zo.id = l.id_zona
LEFT JOIN jefe_venta      jv ON jv.id = l.id_jefe_venta
WHERE 1=1 $filtros
";

if ($debug) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "GET:\n";
    print_r($_GET);
    echo "\nFILTROS:\n$filtros\n";
    echo "\nSQL:\n$sql\n";

    // Si quieres, prueba la query y muestra un resumen:
    $resTest = mysqli_query($conn, $sql);
    if ($resTest) {
        $rowsTest = mysqli_fetch_all($resTest, MYSQLI_ASSOC);
        echo "\nFILAS: " . count($rowsTest) . "\n";
        echo "PRIMERAS 3 FILAS:\n";
        echo json_encode(array_slice($rowsTest, 0, 3), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    } else {
        echo "\nERROR SQL: " . mysqli_error($conn) . "\n";
    }
    exit;
}

// Log DESPUÉS de definir $sql
error_log('GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
error_log('SQL: ' . $sql);

$res = mysqli_query($conn, $sql);
if (!$res) {
    die("Error en la consulta: " . mysqli_error($conn));
}
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
if (empty($rows)) {
    die("No hay datos para exportar.");
}

// Normalización
$map = [
  'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
  'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ñ'=>'N'
];
function norm($s) {
    global $map;
    return strtoupper(strtr($s, $map));
}

// Salida según formato
if ($formato === 'csv') {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"locales.csv\"");
    echo "\xEF\xBB\xBF"; // BOM

    $cols = array_keys($rows[0]);
    $out = fopen('php://output', 'w');

    // Encabezados
    fputcsv($out, array_map('norm', $cols), ';');

    // Filas
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c];
            $line[] = is_string($v) ? norm($v) : $v;
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

// Excel (HTML)
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"locales.xls\"");
echo "\xEF\xBB\xBF";

echo "<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />";
echo "<table border='1'><thead><tr>";

$cols = array_keys($rows[0]);
foreach ($cols as $h) {
    echo "<th>".htmlspecialchars(norm($h), ENT_QUOTES, 'UTF-8')."</th>";
}
echo "</tr></thead><tbody>";

foreach ($rows as $row) {
    echo "<tr>";
    foreach ($cols as $c) {
        $v = $row[$c];
        if (is_string($v)) $v = norm($v);
        echo "<td>".htmlspecialchars($v, ENT_QUOTES, 'UTF-8')."</td>";
    }
    echo "</tr>";
}
echo "</tbody></table>";
exit;

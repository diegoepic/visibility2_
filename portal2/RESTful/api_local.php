<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit','-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// ---- helper: normaliza lista de IDs (array o "1,3,5") ----
function parse_id_list($inp) {
    if (is_array($inp)) {
        $arr = $inp;
    } else {
        $arr = preg_split('/[,\s]+/', (string)$inp, -1, PREG_SPLIT_NO_EMPTY);
    }
    $out = [];
    foreach ($arr as $x) {
        $i = (int)$x;
        if ($i > 0) { $out[$i] = $i; } // únicos
    }
    return array_values($out);
}

// acepta id_division[]=... o id_division=1,2,3
$raw = $_GET['id_division'] ?? null;
$ids  = parse_id_list($raw);

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID(s) de división inválido(s). Use id_division=1,2,3 o id_division[]=1&id_division[]=2']);
    exit;
}

$in = implode(',', $ids); // seguro: ya son enteros

$sql = "
SELECT
  l.codigo,
  cu.nombre  AS nombreCuenta,
  ca.nombre  AS nombreCadena,
  l.nombre,
  l.direccion,
  c.comuna,
  r.region,
  de.nombre  AS division,
  z.nombre_zona      AS zona,
  d.nombre_distrito  AS distrito,
  jv.nombre          AS jefeVenta,
  l.lat,
  l.lng
FROM local l
JOIN comuna c           ON c.id = l.id_comuna
JOIN cuenta cu          ON cu.id = l.id_cuenta
JOIN cadena ca          ON ca.id = l.id_cadena
JOIN region r           ON r.id = c.id_region
JOIN division_empresa de ON de.id = l.id_division
LEFT JOIN zona z        ON z.id = l.id_zona
LEFT JOIN distrito d   ON d.id = l.id_distrito
LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
WHERE l.id_division IN ($in)
";

// Usa USE_RESULT para stream
$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)]);
    exit;
}

echo '[';
$first = true;
while ($row = mysqli_fetch_assoc($res)) {
    if (!$first) echo ',';
    echo json_encode($row, JSON_UNESCAPED_UNICODE);
    $first = false;
}
echo ']';

mysqli_free_result($res);
exit;

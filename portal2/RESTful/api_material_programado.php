<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit','-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

// ========= PARÁMETROS =========
$division      = $_GET['id_division']    ?? '';
$subdivision   = $_GET['id_subdivision'] ?? '';
$idFormulario  = $_GET['id_formulario']  ?? ''; // opcional

// Validaciones básicas
if ($division !== '' && !is_numeric($division)) {
  http_response_code(400);
  echo json_encode(['error'=>'ID de división inválido.']);
  exit;
}
if ($subdivision !== '' && !is_numeric($subdivision)) {
  http_response_code(400);
  echo json_encode(['error'=>'ID de subdivisión inválido.']);
  exit;
}
if ($idFormulario !== '' && !is_numeric($idFormulario)) {
  http_response_code(400);
  echo json_encode(['error'=>'ID de formulario inválido.']);
  exit;
}

// ========= CACHÉ =========
$cacheDir  = __DIR__ . '/cache';
@mkdir($cacheDir, 0755, true);
$cacheKey  = 'formq_' . md5("div={$division}|sub={$subdivision}|form={$idFormulario}|estado=1,3") . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;
$ttl       = 1800; // 30 min

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
  readfile($cacheFile);
  exit;
}

// ========= WHERE DINÁMICO =========
$where = [];
$where[] = "f.estado IN (1,3)"; // según tu query

if ($division !== '')     $where[] = "f.id_division = "    . (int)$division;
if ($subdivision !== '')  $where[] = "f.id_subdivision = " . (int)$subdivision;
if ($idFormulario !== '') $where[] = "f.id = "             . (int)$idFormulario;

$whereSql = '';
if (!empty($where)) {
  $whereSql = "WHERE " . implode(' AND ', $where);
}

// ========= QUERY =========
// Notas:
// - Alias claros para columnas con nombres repetidos/ambiguos.
// - Mantengo tu CASE de 'pregunta' tal cual.
$sql = "
  SELECT 
    f.tipo                                AS tipo,    
    l.id                                  AS idLocal,
    can.nombre_canal                      AS nombreCanal,
    di.nombre_distrito                    AS nombreDistrito,            
    l.codigo                              AS codigo_local,
    f.nombre                              AS nombreCampana,
    f.fechaInicio                         AS fechaInicio,
    f.fechaTermino                        AS fechaTermino,
    fq.fechaVisita                        AS fechaVisita,
    fq.fechaPropuesta                     AS fechaPropuesta,            
    l.nombre                              AS nombre_local,
    l.direccion                           AS direccion_local,
    co.comuna                             AS comuna_local,
    re.region                             AS region_local,
    c.nombre                              AS cuenta,
    ca.nombre                             AS cadena,
    fq.material                           AS material,
    fq.valor_propuesto                    AS valor_propuesto,
    fq.valor                              AS valor,
    fq.observacion                        AS observacion,
    f.estado                              AS estado,
    CASE
      WHEN fq.pregunta IN ('en proceso', 'cancelado')
        THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion, '|', '-'), '-', 1))
      ELSE fq.pregunta
    END                                   AS pregunta,
    u.usuario                             AS gestionado_por,
    CONCAT(u.nombre, ' ', u.apellido)     AS nombreCompleto,       
    sc.nombre_subcanal                    AS subcanal,
    sd.nombre                             AS nombre_subdivision
  FROM formularioQuestion fq
  INNER JOIN local        l   ON l.id   = fq.id_local
  INNER JOIN canal        can ON can.id = l.id_canal
  INNER JOIN distrito     di  ON di.id  = l.id_distrito        
  INNER JOIN subcanal     sc  ON sc.id  = l.id_subcanal
  INNER JOIN comuna       co  ON co.id  = l.id_comuna
  INNER JOIN region       re  ON re.id  = co.id_region
  INNER JOIN formulario   f   ON f.id   = fq.id_formulario
  INNER JOIN subdivision  sd  ON sd.id  = f.id_subdivision        
  INNER JOIN usuario      u   ON u.id   = fq.id_usuario
  INNER JOIN cuenta       c   ON c.id   = l.id_cuenta
  INNER JOIN cadena       ca  ON ca.id  = l.id_cadena
  $whereSql
  ORDER BY l.codigo, fq.fechaVisita ASC
";

// ========= EJECUCIÓN =========
$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
  http_response_code(500);
  echo json_encode(['error' => mysqli_error($conn)]);
  exit;
}

// ========= SALIDA JSON (stream + cache) =========
ob_start();
echo '[';
$first = true;
while ($row = mysqli_fetch_assoc($res)) {
  if (!$first) echo ',';
  echo json_encode($row, JSON_UNESCAPED_UNICODE);
  $first = false;
}
echo ']';
mysqli_free_result($res);

$json = ob_get_clean();
@file_put_contents($cacheFile, $json);

echo $json;

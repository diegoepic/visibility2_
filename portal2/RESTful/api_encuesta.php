<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit','-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Parámetros
$division     = $_GET['id_division'] ?? '';
$subdivision  = $_GET['id_subdivision'] ?? '';

if (!is_numeric($division)) {
    http_response_code(400);
    echo json_encode(['error'=>'ID de división inválido.']);
    exit;
}

if (!is_numeric($subdivision)) {
    http_response_code(400);
    echo json_encode(['error'=>'ID de subdivisión inválido.']);
    exit;
}

// Configuración de caché
$cacheDir  = __DIR__ . '/cache';
@mkdir($cacheDir, 0755, true);
$cacheKey  = 'encuesta_'.md5("division={$division}|subdivision={$subdivision}").'.json';
$cacheFile = "$cacheDir/$cacheKey";
$ttl       = 1800; // 30 min en segundos

// Si existe caché y no está vencido, lo devolvemos
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

// Si no hay caché, generamos la respuesta y la guardamos
ob_start();

$sql = "
  SELECT
    f.tipo              AS tipo,
    f.id                AS idCampana,
    ca.nombre_canal     AS nombreCanal,
    di.nombre_distrito  AS nombreDistrito,
    f.nombre            AS nombreCampana,
    l.codigo            AS codigo_local,
    cu.nombre           AS cuenta,
    l.nombre            AS nombre_local,
    fqr.created_at      AS fecha_respuesta,
    c.comuna            AS comuna,
    r.region            AS region,
    z.nombre_zona       AS nombreZona,
    CONCAT(u.nombre, ' ', u.apellido) AS nombreCompleto,
    fp.question_text    AS pregunta,
    fqr.answer_text     AS respuesta,
    fqr.valor           AS precio
  FROM formulario f
  JOIN form_questions fp            ON fp.id_formulario      = f.id
  JOIN form_question_responses fqr  ON fqr.id_form_question  = fp.id
  JOIN usuario u                    ON u.id                  = fqr.id_usuario
  JOIN local l                      ON l.id                  = fqr.id_local
  JOIN canal ca                     ON ca.id                 = l.id_canal
  JOIN cuenta cu                    ON cu.id                 = l.id_cuenta
  JOIN distrito di                  ON di.id                 = l.id_distrito
  JOIN comuna c                     ON c.id                  = l.id_comuna
  JOIN zona z                       ON z.id                  = l.id_zona
  JOIN region r                     ON r.id                  = c.id_region
  WHERE f.id_division    = $division
    AND f.id_subdivision = $subdivision
    AND date(fqr.created_at) >= '2025-08-28'
    AND fqr.created_at
  ORDER BY l.codigo, fp.sort_order
";

$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error'=>mysqli_error($conn)]);
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

// Capturamos el JSON generado
$json = ob_get_flush();

// Guardamos en caché
file_put_contents($cacheFile, $json);

<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit', '-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/**
 * Parámetros opcionales
 * - id_formulario : numérico (opcional)
 * - months        : meses hacia atrás (por defecto 2) — se ignora si usas since/until
 * - since         : YYYY-MM-DD (opcional)
 * - until         : YYYY-MM-DD (opcional, exclusivo; si no se indica, usa hoy+1 día)
 */
$idFormulario = isset($_GET['id_formulario']) && is_numeric($_GET['id_formulario'])
                ? (int)$_GET['id_formulario'] : null;

$months = isset($_GET['months']) && is_numeric($_GET['months']) ? (int)$_GET['months'] : 2;
$months = max(1, min($months, 12)); // acota entre 1 y 12

$reDate = '/^\d{4}-\d{2}-\d{2}$/';
$since  = isset($_GET['since']) && preg_match($reDate, $_GET['since']) ? $_GET['since'] : null;
$until  = isset($_GET['until']) && preg_match($reDate, $_GET['until']) ? $_GET['until'] : null;

// Fechas por defecto (últimos N meses)
if (!$since) {
    $since = date('Y-m-d', strtotime("-{$months} months"));
}
if (!$until) {
    // usamos hasta hoy + 1 día (exclusivo) para no cortar horas
    $until = date('Y-m-d', strtotime('+1 day'));
}

// Configuración de caché (sensible a parámetros)
$cacheDir  = __DIR__ . '/cache';
@mkdir($cacheDir, 0755, true);

$cacheKeyParts = [
    'agg_usuarios_lp_lv_lg',
    "since={$since}",
    "until={$until}",
];
if (!is_null($idFormulario)) $cacheKeyParts[] = "form={$idFormulario}";

$cacheKey  = implode('|', $cacheKeyParts);
$cacheFile = $cacheDir . '/'. md5($cacheKey) . '.json';
$ttl       = 1800; // 30 minutos

// Si existe caché vigente, devolver
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

// Construcción dinámica del WHERE (index-friendly)
$where = [];
$where[] = "fq.fechaVisita >= '{$since} 00:00:00'";
$where[] = "fq.fechaVisita <  '{$until} 00:00:00'";
$where[] = "fq.fechaVisita <> '0000-00-00 00:00:00'";
if (!is_null($idFormulario)) {
    $where[] = "fq.id_formulario = {$idFormulario}";
}
$whereSql = implode("\n  AND ", $where);

// Query agregada
$sql = "
SELECT
  UPPER(TRIM(CONCAT_WS(' ', u.nombre, u.apellido))) AS usuario,
  COUNT(DISTINCT CONCAT_WS('||', fq.id_usuario, fq.id_formulario, fq.id_local)) AS locales_programados,
  COUNT(DISTINCT CASE
    WHEN fq.fechaVisita IS NOT NULL
         AND fq.fechaVisita <> '0000-00-00 00:00:00'
    THEN CONCAT_WS('||', fq.id_usuario, fq.id_formulario, fq.id_local)
  END) AS locales_visitados,
  COUNT(DISTINCT CASE
    WHEN IFNULL(fq.valor, 0) > 0
      OR LOWER(COALESCE(fq.pregunta, '')) IN (
           'solo_implementado',
           'solo_auditado',
           'solo_auditoria',
           'retiro',
           'entrega',
           'implementado_auditado'
      )
    THEN CONCAT_WS('||', fq.id_usuario, fq.id_formulario, fq.id_local)
  END) AS locales_gestionados
FROM formularioQuestion fq
JOIN usuario u ON u.id = fq.id_usuario
WHERE
  {$whereSql}
GROUP BY u.id, u.nombre, u.apellido
ORDER BY usuario
";

// Ejecutar (modo streaming por si crece)
$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Stream JSON y caché
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

$json = ob_get_contents(); // capturamos pero NO flush aún
ob_end_flush();            // ahora sí enviamos al cliente

// Guardar caché
@file_put_contents($cacheFile, $json);

<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit', '-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/**
 * Parámetros opcionales:
 * - months : meses hacia atrás (default 2). Se ignora si usas since/until.
 * - since  : YYYY-MM-DD (inicio inclusivo).
 * - until  : YYYY-MM-DD (fin exclusivo; si no se indica, usa hoy+1 día).
 */

$months = isset($_GET['months']) && is_numeric($_GET['months']) ? (int)$_GET['months'] : 2;
$months = max(1, min($months, 12));

$reDate = '/^\d{4}-\d{2}-\d{2}$/';
$since  = (isset($_GET['since']) && preg_match($reDate, $_GET['since'])) ? $_GET['since'] : null;
$until  = (isset($_GET['until']) && preg_match($reDate, $_GET['until'])) ? $_GET['until'] : null;

if (!$since) $since = date('Y-m-d', strtotime("-{$months} months"));
if (!$until) $until = date('Y-m-d', strtotime('+1 day'));

// ------- Caché -------
$cacheDir = __DIR__ . '/cache';
@mkdir($cacheDir, 0755, true);
$cacheKey = 'visitas_gestion_por_dia|' . http_build_query([
    'since' => $since,
    'until' => $until
]);
$cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
$ttl = 1800; // 30 min

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

// ------- WHERE (index-friendly) -------
$where = [];
$where[] = "fq.fechaVisita IS NOT NULL";
$where[] = "fq.fechaVisita <> '0000-00-00 00:00:00'";
$where[] = "fq.fechaVisita >= '{$since} 00:00:00'";
$where[] = "fq.fechaVisita <  '{$until} 00:00:00'";
$whereSql = implode("\n  AND ", $where);

// ------- SQL -------
$sql = "
SELECT
  UPPER(TRIM(CONCAT_WS(' ', u.nombre, u.apellido))) AS usuario,
  u.usuario as codigo,  
  DATE(fq.fechaVisita) AS fecha,

  -- Visitados: únicos por (usuario, formulario, local) dentro del día
  COUNT(
    DISTINCT CONCAT_WS('||', fq.id_usuario, fq.id_formulario, fq.id_local)
  ) AS locales_visitados,

  -- Gestionados: valor > 0 o pregunta en el set, dentro del día
  COUNT(
    DISTINCT CASE
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
    END
  ) AS locales_gestionados

FROM formularioQuestion fq
JOIN usuario u ON u.id = fq.id_usuario
WHERE
  {$whereSql}
GROUP BY u.id, u.nombre, u.apellido, DATE(fq.fechaVisita)
ORDER BY fecha ASC, usuario ASC
";

// ------- Exec & stream JSON -------
$res = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$json = ob_get_contents();
ob_end_flush();

// Guardar caché
@file_put_contents($cacheFile, $json);

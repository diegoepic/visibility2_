<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit', '-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/**
 * Parámetros opcionales:
 * - forms  : lista separada por coma de IDs de formulario (solo dígitos y comas)
 *            default: 1671,1653,1628,1631
 * - months : meses hacia atrás (por defecto 2). Se ignora si usas since/until.
 * - since  : YYYY-MM-DD (opcional). Si lo pasas, se usa como inicio (inclusivo).
 * - until  : YYYY-MM-DD (opcional). Si lo pasas, se usa como fin (exclusivo).
 */

$defaultForms = '1671,1653,1628,1631';
$forms = isset($_GET['forms']) ? $_GET['forms'] : $defaultForms;
// sanitiza: solo dígitos, comas y espacios
if (!preg_match('/^[0-9,\s]+$/', $forms)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetro "forms" inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// normaliza espacios
$forms = preg_replace('/\s+/', '', $forms);

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
$cacheKey = 'res_created_at|' . http_build_query([
    'forms'  => $forms,
    'since'  => $since,
    'until'  => $until
]);
$cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
$ttl = 1800; // 30 min

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

// ------- SQL -------
$where = [];
$where[] = "q.id_formulario IN ($forms)";
$where[] = "q.id_question_type <> 7";
$where[] = "r.created_at >= '{$since} 00:00:00'";
$where[] = "r.created_at <  '{$until} 00:00:00'";
$where[] = "r.created_at <> '0000-00-00 00:00:00'";

$whereSql = implode("\n  AND ", $where);

$sql = "
SELECT
  UPPER(TRIM(CONCAT_WS(' ', u.nombre, u.apellido))) AS usuario,
  COUNT(DISTINCT r.created_at) AS locales_programados,
  COUNT(DISTINCT r.created_at) AS locales_visitados,
  COUNT(DISTINCT r.created_at) AS locales_gestionados
FROM form_question_responses r
JOIN form_questions q ON q.id = r.id_form_question
JOIN usuario u ON u.id = r.id_usuario
LEFT JOIN visita v ON v.id = r.visita_id
WHERE
  {$whereSql}
GROUP BY u.id, u.nombre, u.apellido
ORDER BY usuario
";

// ------- Ejecución y streaming JSON -------
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

// guarda caché
@file_put_contents($cacheFile, $json);

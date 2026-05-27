<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit', '-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

/**
 * Valida fecha en formato YYYY-MM-DD
 */
function validarFecha($fecha) {
    if ($fecha === '' || $fecha === null) {
        return false;
    }

    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

/**
 * Bind dinámico para prepared statements
 */
function bindParamsDynamic($stmt, $types, &$params) {
    $refs = [];

    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    array_unshift($refs, $types);

    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

// ===============================
// Parámetros URL
// ===============================
$division      = $_GET['id_division'] ?? '';
$subdivision   = $_GET['id_subdivision'] ?? '';
$fechaInicio   = $_GET['fecha_inicio'] ?? '2025-08-28';
$fechaFin      = $_GET['fecha_fin'] ?? '';
$idFormulario  = $_GET['id_formulario'] ?? '';

// ===============================
// Validaciones
// ===============================
if (!is_numeric($division)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de división inválido.']);
    exit;
}

if (!is_numeric($subdivision)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de subdivisión inválido.']);
    exit;
}

if (!validarFecha($fechaInicio)) {
    http_response_code(400);
    echo json_encode(['error' => 'La fecha_inicio debe tener formato YYYY-MM-DD.']);
    exit;
}

if ($fechaFin !== '' && !validarFecha($fechaFin)) {
    http_response_code(400);
    echo json_encode(['error' => 'La fecha_fin debe tener formato YYYY-MM-DD.']);
    exit;
}

if ($fechaFin !== '' && $fechaFin < $fechaInicio) {
    http_response_code(400);
    echo json_encode(['error' => 'La fecha_fin no puede ser menor que fecha_inicio.']);
    exit;
}

if ($idFormulario !== '' && !is_numeric($idFormulario)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de formulario inválido.']);
    exit;
}

// Cast seguros
$division    = (int)$division;
$subdivision = (int)$subdivision;

if ($idFormulario !== '') {
    $idFormulario = (int)$idFormulario;
}

// Rango de fechas
$fechaInicioSQL = $fechaInicio . ' 00:00:00';

if ($fechaFin !== '') {
    $fechaFinSQL = date('Y-m-d H:i:s', strtotime($fechaFin . ' +1 day'));
}

// ===============================
// Configuración de caché
// ===============================
$cacheDir = __DIR__ . '/cache';
@mkdir($cacheDir, 0755, true);

$cacheParams = [
    'division'      => $division,
    'subdivision'   => $subdivision,
    'fecha_inicio'  => $fechaInicio,
    'fecha_fin'     => $fechaFin,
    'id_formulario' => $idFormulario
];

$cacheKey  = 'encuesta_' . md5(json_encode($cacheParams)) . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;
$ttl       = 1800; // 30 minutos

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

// ===============================
// SQL dinámico
// ===============================
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
  WHERE f.id_division = ?
    AND f.id_subdivision = ?
    AND fqr.created_at >= ?
";

$types = "iis";
$params = [
    $division,
    $subdivision,
    $fechaInicioSQL
];

// Fecha fin opcional
if ($fechaFin !== '') {
    $sql .= " AND fqr.created_at < ? ";
    $types .= "s";
    $params[] = $fechaFinSQL;
}

// Formulario opcional
if ($idFormulario !== '') {
    $sql .= " AND f.id = ? ";
    $types .= "i";
    $params[] = $idFormulario;
}

$sql .= "
  ORDER BY l.codigo, fp.sort_order
";

// ===============================
// Ejecutar consulta
// ===============================
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_error($conn)]);
    exit;
}

bindParamsDynamic($stmt, $types, $params);

if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_stmt_error($stmt)]);
    exit;
}

$res = mysqli_stmt_get_result($stmt);

if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => mysqli_stmt_error($stmt)]);
    exit;
}

// ===============================
// Generar JSON
// ===============================
ob_start();

echo '[';

$first = true;

while ($row = mysqli_fetch_assoc($res)) {
    if (!$first) {
        echo ',';
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $first = false;
}

echo ']';

$json = ob_get_clean();

mysqli_free_result($res);
mysqli_stmt_close($stmt);

// Guardar caché
file_put_contents($cacheFile, $json);

// Responder
echo $json;
<?php
// Mostrar errores (quítalo en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Conexión a BD
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Capturo parámetros de la URL
$id_division   = isset($_GET['id_division'])   ? intval($_GET['id_division'])   : 0;
$year_visita   = isset($_GET['year'])          ? intval($_GET['year'])          : 0;
$local_codigo  = isset($_GET['local_codigo'])  ? mysqli_real_escape_string($conn, $_GET['local_codigo']) : '';
$usuario_id    = isset($_GET['id_usuario'])    ? intval($_GET['id_usuario'])    : 0;
// … aquí podrías añadir más filtros según necesites …

// Armo el array de condiciones
$where = [];
if ($id_division > 0) {
    $where[] = "f.id_division = $id_division";
}
if ($year_visita > 0) {
    $where[] = "YEAR(fq.fechaVisita) = $year_visita";
}
// solo visitas no nulas
$where[] = "fq.fechaVisita IS NOT NULL";
if ($local_codigo !== '') {
    $where[] = "l.codigo = '$local_codigo'";
}
if ($usuario_id > 0) {
    $where[] = "fv.id_usuario = $usuario_id";
}

// Fusiono en la cláusula WHERE
$whereSql = count($where) 
    ? 'WHERE ' . implode(' AND ', $where) 
    : '';

// La consulta
$sql = "
SELECT
    fv.id                 AS id,
    f.nombre             AS nombreCampana,
    fv.url               AS urls,
    fq.material,
    fq.fechaVisita,
    l.codigo             AS local_codigo,
    l.nombre             AS local_nombre,
    l.direccion          AS local_direccion,
    co.comuna            AS comuna_nombre,
    r.region             AS region,
    c.nombre             AS cadena_nombre,
    ct.nombre            AS cuenta_nombre,
    u.usuario            AS usuario
FROM formularioQuestion fq
JOIN fotoVisita fv      ON fv.id_formularioQuestion = fq.id
JOIN local l            ON l.id        = fq.id_local
LEFT JOIN comuna co     ON co.id       = l.id_comuna
JOIN region r           ON r.id        = co.id_region
JOIN cadena c           ON c.id        = l.id_cadena
JOIN cuenta ct          ON ct.id       = l.id_cuenta
JOIN usuario u          ON u.id        = fv.id_usuario
JOIN formulario f       ON f.id        = fq.id_formulario
$whereSql
ORDER BY l.codigo, fq.fechaVisita ASC
";

// Ejecuto en modo no buffered
$result = mysqli_query($conn, $sql, MYSQLI_USE_RESULT);

// Cabecera JSON
header('Content-Type: application/json; charset=utf-8');

if ($result) {
    echo '[';
    $first = true;
    while ($row = mysqli_fetch_assoc($result)) {
        if (!$first) echo ',';
        $first = false;
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
        flush();
    }
    echo ']';
    mysqli_free_result($result);
} else {
    echo json_encode(['error' => mysqli_error($conn)]);
}
exit();
?>

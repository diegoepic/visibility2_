<?php
header("Content-Type: application/json; charset=UTF-8");
set_time_limit(0);
ini_set('memory_limit','-1');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (isset($_GET['nombre']) && trim($_GET['nombre']) !== '') {
    $nombre  = mysqli_real_escape_string($conn, trim($_GET['nombre']));
    // preparamos la parte del WHERE para el LIKE
    $filtroNombre = "AND f.nombre LIKE '%{$nombre}%'";
} else {
    // no filtramos por nombre cuando venga vac铆o
    $filtroNombre = "";
}

// 2) Validaci贸n de division (igual que antes)
if (!isset($_GET['id_division']) || !is_numeric($_GET['id_division'])) {
    http_response_code(400);
    echo json_encode(['error'=>'ID de divisi贸n inv谩lido.']);
    exit;
}
$division = (int) $_GET['id_division'];

$sql = "
    SELECT
        r.id_form_question,
        f.nombre,
        c.nombre       AS cuenta,
        ca.nombre      AS cadena,
        l.codigo       AS codigo_local,
        l.nombre       AS nombre_local,
        l.direccion    AS direccion_local,
        co.comuna,
        re.region,
        q.question_text,
        u.usuario,
        CONCAT(u.nombre,' ',u.apellido) AS nombreUsuario,
        r.answer_text,
        r.valor,
        r.created_at
    FROM form_question_responses AS r
    JOIN form_questions  AS q  ON q.id = r.id_form_question
    JOIN formulario      AS f  ON f.id = q.id_formulario
    JOIN usuario         AS u  ON u.id = r.id_usuario
    
    LEFT JOIN local      AS l  ON l.id = r.id_local
    LEFT JOIN comuna     AS co ON co.id = l.id_comuna
    LEFT JOIN region     AS re ON re.id = co.id_region
    LEFT JOIN cuenta     AS c  ON c.id = l.id_cuenta
    LEFT JOIN cadena     AS ca ON ca.id = l.id_cadena
    WHERE f.id_division =$division $filtroNombre
	  AND f.id <> 138
      AND q.id_question_type <> 7
    ORDER BY r.created_at ASC, r.id_usuario ASC";

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
exit;
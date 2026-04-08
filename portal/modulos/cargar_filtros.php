<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

mysqli_set_charset($conn, 'utf8mb4');

$filtro      = isset($_GET['filtro']) ? trim($_GET['filtro']) : '';
$division    = isset($_GET['division']) ? (int)$_GET['division'] : 0;
$subdivision = isset($_GET['subdivision']) ? (int)$_GET['subdivision'] : 0;
$canal       = isset($_GET['canal']) ? (int)$_GET['canal'] : 0;

$options = '';

switch ($filtro) {

    case 'canal':
        $query = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
        $result = $conn->query($query);

        $options = '<option value="">Todos los Canales</option>';

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options .= '<option value="' . (int)$row['id'] . '">'
                         . htmlspecialchars($row['nombre_canal'], ENT_QUOTES, 'UTF-8')
                         . '</option>';
            }
        }
        break;

    case 'distrito':
        $query = "SELECT id, nombre_distrito FROM distrito ORDER BY nombre_distrito ASC";
        $result = $conn->query($query);

        $options = '<option value="">Todos los Distritos</option>';

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options .= '<option value="' . (int)$row['id'] . '">'
                         . htmlspecialchars($row['nombre_distrito'], ENT_QUOTES, 'UTF-8')
                         . '</option>';
            }
        }
        break;

    case 'canalug':
        $query = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
        $result = $conn->query($query);

        $options = '<option value="">Todos los Canales</option>';

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options .= '<option value="' . (int)$row['id'] . '">'
                         . htmlspecialchars($row['nombre_canal'], ENT_QUOTES, 'UTF-8')
                         . '</option>';
            }
        }
        break;

    case 'distritoug':
        $query = "SELECT id, nombre_distrito FROM distrito ORDER BY nombre_distrito ASC";
        $result = $conn->query($query);

        $options = '<option value="">Todos los Distritos</option>';

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $options .= '<option value="' . (int)$row['id'] . '">'
                         . htmlspecialchars($row['nombre_distrito'], ENT_QUOTES, 'UTF-8')
                         . '</option>';
            }
        }
        break;

case 'ejecutor':
    $sql = "
        SELECT
            u.id,
            u.usuario
        FROM usuario u
        INNER JOIN (
            SELECT DISTINCT
                fqr.id_usuario
            FROM form_question_responses fqr
            INNER JOIN form_questions fq
                ON fq.id = fqr.id_form_question
            INNER JOIN formulario f
                ON f.id = fq.id_formulario
            INNER JOIN local l
                ON l.id = fqr.id_local
            WHERE f.tipo = 3
    ";

    $types  = '';
    $params = [];

    if ($division > 0) {
        $sql .= " AND f.id_division = ? ";
        $types .= 'i';
        $params[] = $division;
    }

    if ($subdivision > 0) {
        $sql .= " AND f.id_subdivision = ? ";
        $types .= 'i';
        $params[] = $subdivision;
    }

    $sql .= "
        ) x ON x.id_usuario = u.id
        WHERE u.activo = 1
        ORDER BY u.usuario ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $options = '<option value="">Todos los Ejecutores</option>';

        while ($row = $res->fetch_assoc()) {
            $options .= '<option value="' . (int)$row['id'] . '">'
                     . htmlspecialchars($row['usuario'], ENT_QUOTES, 'UTF-8')
                     . '</option>';
        }

        $stmt->close();
    } else {
        $options = '<option value="">No fue posible cargar ejecutores</option>';
    }
    break;
}

echo $options;
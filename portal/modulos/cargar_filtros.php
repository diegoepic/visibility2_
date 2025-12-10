<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$filtro   = isset($_GET['filtro'])   ? $_GET['filtro']   : '';
$division = isset($_GET['division']) ? intval($_GET['division']) : 0;

$options = '';

// Dependiendo del filtro, armamos el SELECT correspondiente
switch ($filtro) {
    case 'canal':
        $query  = "SELECT id, nombre_canal FROM canal ORDER BY nombre_canal ASC";
        $result = $conn->query($query);
        $options = '<option value="">Todos los Canales</option>';
        while ($row = $result->fetch_assoc()) {
            $options .= '<option value="'.$row['id'].'">'.$row['nombre_canal'].'</option>';
        }
        break;

    case 'distrito':
        $query  = "SELECT id, nombre_distrito FROM distrito ORDER BY nombre_distrito ASC";
        $result = $conn->query($query);
        $options = '<option value="">Todos los Distritos</option>';
        while ($row = $result->fetch_assoc()) {
            $options .= '<option value="'.$row['id'].'">'.$row['nombre_distrito'].'</option>';
        }
        break;

    case 'ejecutor':
        // Cargar ejecutores (usuarios) según la división
        // Si division == 0, se cargan todos
        if ($division > 0) {
            $stmt = $conn->prepare("SELECT id, usuario FROM usuario WHERE id_division = ? AND id_perfil = 3 ORDER BY usuario ASC");
            $stmt->bind_param("i", $division);
        } else {
            $stmt = $conn->prepare("SELECT id, usuario FROM usuario WHERE id_perfil = 3 AND id != 50 ORDER BY usuario ASC");
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $options = '<option value="">Todos los Ejecutores</option>';
        while ($row = $res->fetch_assoc()) {
            $options .= '<option value="'.intval($row['id']).'">'.htmlspecialchars($row['usuario'], ENT_QUOTES, 'UTF-8').'</option>';
        }
        $stmt->close();
        break;

    default:
        $options = '<option value="">Seleccione una opción</option>';
        break;
}

echo $options;
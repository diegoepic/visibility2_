<?php
// eliminar_pregunta.php

session_start();
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

// Validar parámetros: se espera el id de la pregunta y el id_formulario para redirigir
if (!isset($_GET['id']) || !isset($_GET['id_formulario'])) {
    die("Faltan parámetros necesarios.");
}

$question_id    = intval($_GET['id']);
$formulario_id  = intval($_GET['id_formulario']);

// Primero, obtener la información de la pregunta a eliminar
$stmt = $conn->prepare("SELECT id, id_question_type FROM form_questions WHERE id = ? AND id_formulario = ?");
$stmt->bind_param("ii", $question_id, $formulario_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $stmt->close();
    die("Pregunta no encontrada.");
}
$pregunta = $result->fetch_assoc();
$stmt->close();

// Variable para almacenar la lista de opciónes (IDs) para esta pregunta (si es de tipo Sí/No)
$option_ids = [];
// Si la pregunta es de tipo Sí/No (id_question_type = 1), obtenemos sus alternativas
if ($pregunta['id_question_type'] == 1) {
    $stmtOpt = $conn->prepare("SELECT id FROM form_question_options WHERE id_form_question = ?");
    $stmtOpt->bind_param("i", $question_id);
    $stmtOpt->execute();
    $resultOpt = $stmtOpt->get_result();
    while ($row = $resultOpt->fetch_assoc()) {
        $option_ids[] = $row['id'];
    }
    $stmtOpt->close();
}

// Verificar si existen preguntas dependientes: aquellas que tengan en su campo id_dependency_option
// alguno de los option_ids de la pregunta que se quiere eliminar.
$dependientes = [];
if (!empty($option_ids)) {
    // Creamos una lista separada por comas de los IDs
    $placeholders = implode(',', array_fill(0, count($option_ids), '?'));
    $types = str_repeat('i', count($option_ids));
    $sqlDep = "SELECT id, question_text FROM form_questions WHERE id_dependency_option IN ($placeholders) AND id_formulario = ?";
    $stmtDep = $conn->prepare($sqlDep);
    // Agregar los parámetros: primero los option_ids y luego el id_formulario
    $params = array_merge($option_ids, [$formulario_id]);
    // Preparar los parámetros (usamos la función call_user_func_array con bind_param)
    $bind_names[] = $types . "i"; // e.g. "ii" o "iii", dependiendo del número de opciones, + "i" para el formulario
    foreach ($params as $param) {
        $bind_names[] = $param;
    }
    // Llamamos a bind_param dinámicamente
    $tmp = array();
    foreach ($bind_names as $key => $value) {
        $tmp[$key] = &$bind_names[$key];
    }
    call_user_func_array(array($stmtDep, 'bind_param'), $tmp);
    $stmtDep->execute();
    $resDep = $stmtDep->get_result();
    while ($rowDep = $resDep->fetch_assoc()) {
        $dependientes[] = $rowDep;
    }
    $stmtDep->close();
}

// Si se encontró alguna pregunta dependiente y aún no se ha confirmado la eliminación, mostrar advertencia
if (!empty($dependientes) && (!isset($_GET['confirm']) || $_GET['confirm'] != '1')) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Confirmar Eliminación</title>
      <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    </head>
    <body>
    <div class="container mt-5">
      <div class="alert alert-warning">
        <h4 class="alert-heading">Advertencia</h4>
        <p>La pregunta que deseas eliminar es de tipo Sí/No y tiene preguntas condicionales (dependientes) asociadas:</p>
        <ul>
          <?php foreach ($dependientes as $dep): ?>
            <li><?php echo htmlspecialchars($dep['question_text'], ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
        <hr>
        <p class="mb-0">¿Estás seguro de que deseas eliminar esta pregunta y todas las preguntas dependientes?</p>
      </div>
      <a href="eliminar_pregunta.php?id=<?php echo $question_id; ?>&id_formulario=<?php echo $formulario_id; ?>&confirm=1" class="btn btn-danger">Sí, eliminar</a>
      <a href="gestionar_preguntas.php?id_formulario=<?php echo $formulario_id; ?>" class="btn btn-secondary">Cancelar</a>
    </div>
    </body>
    </html>
    <?php
    exit();
}

// Si se ha confirmado o no hay dependientes, proceder a eliminar

// Iniciar transacción para asegurarnos que la eliminación es atómica
$conn->begin_transaction();

try {
    // Si hay preguntas dependientes (para preguntas de tipo Sí/No), eliminarlas también
    if (!empty($dependientes)) {
        $stmtDelDep = $conn->prepare("DELETE FROM form_questions WHERE id = ?");
        foreach ($dependientes as $dep) {
            $stmtDelDep->bind_param("i", $dep['id']);
            $stmtDelDep->execute();
        }
        $stmtDelDep->close();
    }
    
    // Eliminar las alternativas de la pregunta a eliminar
    $stmtDelOpts = $conn->prepare("DELETE FROM form_question_options WHERE id_form_question = ?");
    $stmtDelOpts->bind_param("i", $question_id);
    $stmtDelOpts->execute();
    $stmtDelOpts->close();
    
    // Eliminar la pregunta
    $stmtDelQ = $conn->prepare("DELETE FROM form_questions WHERE id = ? AND id_formulario = ?");
    $stmtDelQ->bind_param("ii", $question_id, $formulario_id);
    $stmtDelQ->execute();
    $stmtDelQ->close();
    
    $conn->commit();
    $_SESSION['success'] = "La pregunta y sus alternativas (y las preguntas dependientes, si existían) se han eliminado correctamente.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error al eliminar la pregunta: " . $e->getMessage();
}

// Redirigir a la página de gestión de preguntas
header("Location: gestionar_preguntas.php?id_formulario=$formulario_id");
exit();
?>

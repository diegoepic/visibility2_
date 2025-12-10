<?php
// editar_formularioIW.php

// Habilitar la visualización de errores para depuración (deshabilítalos en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de conexión a la base de datos y datos de sesión
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

// Obtener el ID del formulario a editar
if (isset($_GET['id'])) {
    $formulario_id = intval($_GET['id']);
} else {
    echo "ID de formulario no proporcionado.";
    exit();
}

// Para campañas complementarias (tipo=2), se asume que el formulario debe tener tipo 2.
// Consultamos y verificamos:
$stmt = $conn->prepare("
    SELECT id, nombre, estado, tipo, url_bi 
    FROM formulario 
    WHERE id = ? AND tipo = 2
");
if ($stmt === false) {
    die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo "Formulario complementario no encontrado o no es de tipo IW.";
    exit();
}
$formulario = $result->fetch_assoc();
$stmt->close();

// Para actividades complementarias, se fuerza que id_empresa y id_division sean 0.
$formulario['id_empresa'] = 0;
$formulario['id_division'] = 0;

// Determinar la pestaña activa (por defecto 'editar-formulario')
$active_tab = 'editar-formulario';
if (isset($_GET['active_tab'])) {
    $active_tab = $_GET['active_tab'];
} elseif (isset($_POST['active_tab'])) {
    $active_tab = $_POST['active_tab'];
}

// Procesar la actualización del formulario complementario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_formulario') {
    // Para actividades IW, no se usan fechas ni división. Se actualizan solo el nombre, estado, tipo y url_bi.
    $nombre = htmlspecialchars($_POST['nombre'], ENT_QUOTES, 'UTF-8');
    $estado = $_POST['estado'];
    $tipo = $_POST['tipo']; // debe ser 2
    // Forzamos id_empresa e id_division a 0 y asignamos fechas dummy (por ejemplo, '0000-00-00 00:00:00')
    $fechaInicio = '0000-00-00 00:00:00';
    $fechaTermino = '0000-00-00 00:00:00';
    $id_division = 0;
    $url_bi = htmlspecialchars($_POST['url_bi'], ENT_QUOTES, 'UTF-8');
    
    if (empty($nombre) || empty($estado) || empty($tipo) || empty($url_bi)) {
        $error = "Por favor, complete todos los campos obligatorios.";
    } else {
        $stmt_update = $conn->prepare("
            UPDATE formulario
            SET nombre = ?, fechaInicio = ?, fechaTermino = ?, estado = ?, tipo = ?, id_empresa = 0, id_division = 0, url_bi = ?
            WHERE id = ?
        ");
        if ($stmt_update === false) {
            die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
        }
        $stmt_update->bind_param("ssssssi", $nombre, $fechaInicio, $fechaTermino, $estado, $tipo, $url_bi, $formulario_id);
        if ($stmt_update->execute()) {
            $success = "Formulario actualizado correctamente.";
            $formulario['nombre'] = $nombre;
            $formulario['estado'] = $estado;
            $formulario['tipo'] = $tipo;
            $formulario['url_bi'] = $url_bi;
        } else {
            $error = "Error al actualizar el formulario: " . htmlspecialchars($stmt_update->error);
        }
        $stmt_update->close();
    }
}

// Procesar la importación de un set de preguntas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_set') {
    $set_id = intval($_POST['selected_set_id']);
    if ($set_id <= 0) {
        $error = "Selecciona un set de preguntas válido.";
    } else {
        // Obtener preguntas del set (se asume que existen las funciones getQuestionsFromSet y getOptionsFromSetQuestion)
        $preguntas_set = getQuestionsFromSet($set_id);
        if (empty($preguntas_set)) {
            $error = "El set seleccionado no tiene preguntas.";
        } else {
            $sql_ins_q_set = "
                INSERT INTO form_questions
                (id_formulario, question_text, id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt_q_set = $conn->prepare($sql_ins_q_set);
            if (!$stmt_q_set) {
                die("Error preparando la inserción del set: " . $conn->error);
            }
            $sql_ins_opt_set = "
                INSERT INTO form_question_options
                (id_form_question, option_text, sort_order, reference_image, id_question_set_option)
                VALUES (?, ?, ?, ?, ?)
            ";
            $stmt_opt_set = $conn->prepare($sql_ins_opt_set);
            if (!$stmt_opt_set) {
                die("Error preparando la inserción de opciones: " . $conn->error);
            }
            
            $max_sort_order_res = $conn->query("
                SELECT COALESCE(MAX(sort_order), 0) AS max_so
                FROM form_questions
                WHERE id_formulario = " . intval($formulario_id)
            );
            $row_so = $max_sort_order_res->fetch_assoc();
            $current_sort = intval($row_so['max_so']);
            
            $map_questions = array(); // mapeo: id del set -> id nuevo en form_questions
            $map_options = array();   // mapeo: [id_set_question][id_set_option] => nuevo id en form_question_options
            
            foreach ($preguntas_set as $pset) {
                $question_text = $pset['question_text'];
                $id_question_type = $pset['id_question_type'];
                $is_required = $pset['is_required'];
                // Para IW, la dependencia se deja NULL inicialmente
                $id_dependency_option = null;
                $is_valued = isset($pset['is_valued']) ? $pset['is_valued'] : 0;
                $current_sort++;
                
                             $temp_set_id = $pset['id'];
                $temp_dep = $id_dependency_option; // aunque en este caso es null, se necesita una variable
                $stmt_q_set->bind_param("isiiiiii", $formulario_id, $question_text, $id_question_type, $current_sort, $is_required, $temp_set_id, $temp_dep, $is_valued);
                $stmt_q_set->execute();
                $new_q_id = $conn->insert_id;
                $map_questions[$pset['id']] = $new_q_id;
                $map_options[$pset['id']] = array();
                
                $opciones = getOptionsFromSetQuestion($pset['id']);
                if (!empty($opciones)) {
                    foreach ($opciones as $opt) {
                        $stmt_opt_set->bind_param('isisi', $new_q_id, $opt['option_text'], $opt['sort_order'], $opt['reference_image'], $opt['id']);
                        $stmt_opt_set->execute();
                        $new_opt_id = $conn->insert_id;
                        $map_options[$pset['id']][$opt['id']] = $new_opt_id;
                    }
                }
            }
            $stmt_q_set->close();
            $stmt_opt_set->close();
            
            // Actualizar las dependencias de las preguntas importadas
            foreach ($preguntas_set as $pset) {
                if (!empty($pset['id_dependency_option'])) {
                    $dep_orig_id = $pset['id_dependency_option'];
                    $query = "SELECT id_question_set_question FROM question_set_options WHERE id = ?";
                    $stmt_dep = $conn->prepare($query);
                    $stmt_dep->bind_param("i", $dep_orig_id);
                    $stmt_dep->execute();
                    $stmt_dep->bind_result($parent_qs_id);
                    $stmt_dep->fetch();
                    $stmt_dep->close();
                    
                    if ($parent_qs_id && isset($map_questions[$parent_qs_id])) {
                        if (isset($map_options[$parent_qs_id][$dep_orig_id])) {
                            $new_dep_option = $map_options[$parent_qs_id][$dep_orig_id];
                        } else {
                            $new_dep_option = null;
                        }
                        $current_form_q_id = $map_questions[$pset['id']];
                        $stmt_update_dep = $conn->prepare("UPDATE form_questions SET id_dependency_option = ? WHERE id = ?");
                        $stmt_update_dep->bind_param("ii", $new_dep_option, $current_form_q_id);
                        $stmt_update_dep->execute();
                        $stmt_update_dep->close();
                    }
                }
            }
            
            $success = "Set de preguntas importado correctamente (con imágenes y dependencias).";
            header("Location: editar_formularioIW.php?id=$formulario_id&active_tab=agregar-pregunta");
            exit();
        }
    }
}

// Obtener todas las preguntas del formulario complementario
$stmt_fq = $conn->prepare("SELECT * FROM form_questions WHERE id_formulario = ? ORDER BY sort_order ASC");
$stmt_fq->bind_param("i", $formulario_id);
$stmt_fq->execute();
$result_fq = $stmt_fq->get_result();
$formulario_questions = $result_fq->fetch_all(MYSQLI_ASSOC);
$stmt_fq->close();

// Obtener listado de sets de preguntas disponibles
$sets = getQuestionSets();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Formulario Complementario</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .material-row { margin-bottom: 10px; }
        .remove-material-btn { margin-top: 32px; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h1>Editar Formulario Complementario</h1>
    <?php if (isset($success)) { ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php } ?>
    <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php } ?>

    <!-- Navegación con pestañas (solo se incluyen pestañas relevantes para campañas complementarias) -->
    <ul class="nav nav-tabs" id="formTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'editar-formulario') ? 'active' : ''; ?>"
               id="editar-formulario-tab"
               data-toggle="tab"
               href="#editar-formulario"
               role="tab"
               aria-controls="editar-formulario"
               aria-selected="<?php echo ($active_tab === 'editar-formulario') ? 'true' : 'false'; ?>">
                Editar Formulario
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'agregar-pregunta') ? 'active' : ''; ?>"
               id="agregar-pregunta-tab"
               data-toggle="tab"
               href="#agregar-pregunta"
               role="tab"
               aria-controls="agregar-pregunta"
               aria-selected="<?php echo ($active_tab === 'agregar-pregunta') ? 'true' : 'false'; ?>">
                Agregar Pregunta
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'import-set') ? 'active' : ''; ?>"
               id="import-set-tab"
               data-toggle="tab"
               href="#import-set"
               role="tab"
               aria-controls="import-set"
               aria-selected="<?php echo ($active_tab === 'import-set') ? 'true' : 'false'; ?>">
                Añadir Set de Preguntas
            </a>
        </li>
    </ul>

    <div class="tab-content" id="formTabsContent">
        <!-- Tab: Editar Formulario -->
        <div class="tab-pane fade <?php echo ($active_tab === 'editar-formulario') ? 'show active' : ''; ?>"
             id="editar-formulario"
             role="tabpanel"
             aria-labelledby="editar-formulario-tab">
            <form action="editar_formularioIW.php?id=<?php echo $formulario_id; ?>" method="post" class="mt-4">
                <input type="hidden" name="action" value="update_formulario">
                <div class="form-group">
                    <label for="nombre">Nombre del formulario:</label>
                    <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo htmlspecialchars($formulario['nombre'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <!-- No se muestran fechas ni división para actividades complementarias -->
                <div class="form-group">
                    <label for="estado">Estado:</label>
                    <select id="estado" name="estado" class="form-control" required>
                        <option value="">Seleccione una opción</option>
                        <option value="1" <?php if ($formulario['estado'] == '1') echo 'selected'; ?>>En curso</option>
                        <option value="2" <?php if ($formulario['estado'] == '2') echo 'selected'; ?>>Proceso</option>
                        <option value="3" <?php if ($formulario['estado'] == '3') echo 'selected'; ?>>Finalizado</option>
                        <option value="4" <?php if ($formulario['estado'] == '4') echo 'selected'; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tipo">Tipo de actividad:</label>
                    <select id="tipo" name="tipo" class="form-control" required>
                        <option value="">Seleccione una opción</option>
                        <option value="2" <?php if ($formulario['tipo'] == '2') echo 'selected'; ?>>Actividad IW</option>
                    </select>
                </div>
                <!-- No se muestran campos para División ni Empresa, se forzarán a 0 -->
                <div class="form-group">
                    <label for="url_bi">URL Informe BI:</label>
                    <input type="text" id="url_bi" name="url_bi" class="form-control" value="<?php echo htmlspecialchars($formulario['url_bi'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>                
                <button type="submit" class="btn btn-primary">Actualizar Formulario</button>
                <a href="../mod_formulario.php" class="btn btn-secondary">Volver</a>
            </form>
        </div>

        <!-- Tab: Agregar Pregunta -->
        <div class="tab-pane fade <?php echo ($active_tab === 'agregar-pregunta') ? 'show active' : ''; ?>"
             id="agregar-pregunta"
             role="tabpanel"
             aria-labelledby="agregar-pregunta-tab">
            <h3 class="mt-4">Agregar Nueva Pregunta</h3>
            <form action="editar_formularioIW.php?id=<?php echo $formulario_id; ?>" method="post" id="addQuestionForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_question">
                <input type="hidden" name="active_tab" value="agregar-pregunta">
                <div class="form-group">
                    <label for="question_text">Texto de la Pregunta:</label>
                    <input type="text" id="question_text" name="question_text" class="form-control" placeholder="Ejemplo: ¿Existe stock suficiente?" required>
                </div>
                <div class="form-group">
                    <label for="id_question_type">Tipo de Pregunta:</label>
                    <select id="id_question_type" name="id_question_type" class="form-control" required onchange="toggleQuestionTypeSingle()">
                        <option value="">-- Seleccionar Tipo --</option>
                        <?php
                        $tipos_raw = ejecutarConsulta("SELECT id, name FROM question_type ORDER BY id ASC", [], '');
                        $tipoTraduc = [
                            'yes_no'          => 'Sí/No',
                            'single_choice'   => 'Selección única',
                            'multiple_choice' => 'Selección múltiple',
                            'text'            => 'Texto',
                            'numeric'         => 'Numérico',
                            'date'            => 'Fecha',
                            'photo'           => 'Foto',
                        ];
                        foreach ($tipos_raw as $tp) {
                            $nombreTipo = isset($tipoTraduc[$tp['name']]) ? $tipoTraduc[$tp['name']] : $tp['name'];
                            echo '<option value="' . htmlspecialchars($tp['id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nombreTipo, ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>¿Requerida?</label>
                    <div class="form-check">
                        <input type="checkbox" id="is_required" name="is_required" class="form-check-input" value="1">
                        <label for="is_required" class="form-check-label">Sí, es obligatoria.</label>
                    </div>
                </div>
                <!-- Campo de dependencia -->
                <div class="form-group">
                    <label>Depende de (opcional):</label>
                    <select name="dependency_option" class="form-control">
                        <?php echo $dependencyOptionsHtml; ?>
                    </select>
                    <small class="form-text text-muted">Selecciona la opción de una pregunta sí/no que dispare esta pregunta.</small>
                </div>
                <!-- Contenedor para "Pregunta valorizada" -->
                <div id="valuedContainer" class="form-check mb-2" style="display: none;">
                    <input type="checkbox" id="is_valued" name="is_valued" class="form-check-input" value="1">
                    <label for="is_valued" class="form-check-label">¿Pregunta valorizada?</label>
                </div>
                <!-- Contenedor para opciones -->
                <div class="form-group" id="questionOptionsContainer" style="display: none;">
                    <label>Opciones:</label>
                    <div id="questionOptionsRows"></div>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addOptionRow()">+ Agregar Opción</button>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Agregar Pregunta</button>
            </form>
        </div>

        <!-- Tab: Importar Set de Preguntas -->
        <div class="tab-pane fade <?php echo ($active_tab === 'import-set') ? 'show active' : ''; ?>"
             id="import-set"
             role="tabpanel"
             aria-labelledby="import-set-tab">
            <h3 class="mt-4">Añadir Set de Preguntas</h3>
            <form action="editar_formularioIW.php?id=<?php echo $formulario_id; ?>" method="post">
                <input type="hidden" name="action" value="import_set">
                <input type="hidden" name="active_tab" value="import-set">
                <div class="form-group">
                    <label for="selected_set_id">Seleccionar Set de Preguntas:</label>
                    <select id="selected_set_id" name="selected_set_id" class="form-control" required>
                        <option value="">-- Seleccionar --</option>
                        <?php
                        if (!empty($sets)) {
                            foreach ($sets as $s) {
                                echo '<option value="' . htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') . '">'
                                     . htmlspecialchars($s['nombre_set'], ENT_QUOTES, 'UTF-8')
                                     . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">Añadir Set al Formulario</button>
            </form>
        </div>
    </div>
</div>

<!-- Incluir jQuery y Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Función para la pestaña "Agregar Pregunta" (formulario individual)
function toggleQuestionTypeSingle() {
    const tipoSelect = document.getElementById('id_question_type');
    const selectedTipo = parseInt(tipoSelect.value, 10);
    const optionsContainer = document.getElementById('questionOptionsContainer');
    const valuedContainer = document.getElementById('valuedContainer');
    
    // Mostrar contenedor de opciones para tipos que lo requieran (1, 2 o 3)
    if ([1, 2, 3].includes(selectedTipo)) {
        optionsContainer.style.display = 'block';
    } else {
        optionsContainer.style.display = 'none';
    }
    
    // Mostrar el checkbox "¿Pregunta valorizada?" solo para Selección única (2) o múltiple (3)
    if ([2, 3].includes(selectedTipo)) {
        valuedContainer.style.display = 'block';
    } else {
        valuedContainer.style.display = 'none';
    }
}

// Funciones para agregar opciones en el formulario individual
function addOptionRow() {
    const optionsRows = document.getElementById('questionOptionsRows');
    const optIndex = optionsRows.children.length;
    const newRow = document.createElement('div');
    newRow.className = 'option-block mb-2';
    const previewId = 'preview_' + optIndex;
    newRow.innerHTML = '<div class="input-group">' +
      '<input type="text" class="form-control" name="options[' + optIndex + ']" placeholder="Texto de la opción">' +
      '<div class="input-group-append">' +
      '<button type="button" class="btn btn-danger" onclick="removeOptionBlock(this)">Eliminar</button>' +
      '</div>' +
      '<div class="custom-file">' +
      '<input type="file" class="custom-file-input" name="option_images[' + optIndex + ']" accept="image/*" onchange="previewOptionImage(event, \'' + previewId + '\')">' +
      '<label class="custom-file-label">Imagen (opcional)</label>' +
      '</div>' +
      '</div>' +
      '<img id="' + previewId + '" style="display:none; max-width:100px; margin-top:5px;">';
    optionsRows.appendChild(newRow);
}

function removeOptionBlock(btn) {
    const optionBlock = btn.closest('.option-block');
    if (optionBlock) {
        optionBlock.remove();
    }
}

function previewOptionImage(evt, previewId) {
    const file = evt.target.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        alert("Solo se permiten archivos de imagen.");
        evt.target.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById(previewId);
        if (img) {
            img.src = e.target.result;
            img.style.display = 'block';
        }
    };
    reader.readAsDataURL(file);
}

// (Opcional) Funciones para agregar múltiples preguntas se pueden dejar si se desean
</script>
</body>
</html>
<?php
$conn->close();
?>

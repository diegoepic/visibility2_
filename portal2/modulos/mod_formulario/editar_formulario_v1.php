<?php
// editar_formulario.php

// Habilitar la visualización de errores para depuración (deshabilítalos en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir el archivo de conexión a la base de datos
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Incluir los datos de la sesión
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

// Obtener la pestaña activa (por defecto 'editar-formulario')
$active_tab = 'editar-formulario';
if (isset($_GET['active_tab'])) {
    $active_tab = $_GET['active_tab'];
} elseif (isset($_POST['active_tab'])) {
    $active_tab = $_POST['active_tab'];
}

// ----------------------------------------------------------------------
// BLOQUE: Agregar Pregunta Nueva (con reference_image + FIX subquery error)
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_set') {
    $set_id = intval($_POST['selected_set_id']);
    if ($set_id <= 0) {
        $error = "Selecciona un set de preguntas válido.";
    } else {
        // Obtener preguntas del set
        $preguntas_set = getQuestionsFromSet($set_id);
        if (empty($preguntas_set)) {
            $error = "El set seleccionado no tiene preguntas.";
        } else {
            // Preparar inserciones en form_questions y form_question_options
            $sql_ins_q_set = "
                INSERT INTO form_questions
                (id_formulario, question_text, id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt_q_set = $conn->prepare($sql_ins_q_set);
            if (!$stmt_q_set) {
                die("Error preparando la inserción del set: " . $conn->error);
            }
            // Incluimos también el id_question_set_option en la inserción de las opciones
            $sql_ins_opt_set = "
                INSERT INTO form_question_options
                (id_form_question, option_text, sort_order, reference_image, id_question_set_option)
                VALUES (?, ?, ?, ?, ?)
            ";
            $stmt_opt_set = $conn->prepare($sql_ins_opt_set);
            if (!$stmt_opt_set) {
                die("Error preparando la inserción de opciones: " . $conn->error);
            }
            
            // Obtener el máximo sort_order actual del formulario
            $max_sort_order_res = $conn->query("
                SELECT COALESCE(MAX(sort_order), 0) AS max_so
                FROM form_questions
                WHERE id_formulario = " . intval($formulario_id)
            );
            $row_so = $max_sort_order_res->fetch_assoc();
            $current_sort = intval($row_so['max_so']);
            
            // Inicializar arreglos de mapeo para preguntas y opciones importadas
            $map_questions = array(); // [ id_question_set_question_original => new_form_question_id ]
            $map_options = array();   // [ id_question_set_question_original => [ id_option_original => new_form_question_option_id ] ]
            
            // 1. Importar cada pregunta del set sin asignar dependencia (se asignará después)
            foreach ($preguntas_set as $pset) {
                $question_text    = $pset['question_text'];
                $id_question_type = $pset['id_question_type'];
                $is_required      = $pset['is_required'];
                // Dejar la dependencia como NULL por ahora
                $id_dependency_option = null;
                $is_valued = isset($pset['is_valued']) ? $pset['is_valued'] : 0;
                $current_sort++;
                
                // Inserción en form_questions: se guardan también el id original de la pregunta (para referencia)
                $stmt_q_set->bind_param(
                    "isiiiiii",
                    $formulario_id,
                    $question_text,
                    $id_question_type,
                    $current_sort,
                    $is_required,
                    $pset['id'],  // id_question_set_question (original)
                    $id_dependency_option, // se asigna NULL por ahora
                    $is_valued
                );
                $stmt_q_set->execute();
                $new_q_id = $conn->insert_id;
                $map_questions[$pset['id']] = $new_q_id;
                
                // Inicializar el mapeo de opciones para esta pregunta
                $map_options[$pset['id']] = array();
                
                // 2. Importar las opciones de esta pregunta
                $opciones = getOptionsFromSetQuestion($pset['id']);
                if (!empty($opciones)) {
                    foreach ($opciones as $opt) {
                        $stmt_opt_set->bind_param(
                            'isisi',
                            $new_q_id,
                            $opt['option_text'],
                            $opt['sort_order'],
                            $opt['reference_image'],
                            $opt['id']  // id_question_set_option (original)
                        );
                        $stmt_opt_set->execute();
                        $new_opt_id = $conn->insert_id;
                        $map_options[$pset['id']][$opt['id']] = $new_opt_id;
                    }
                }
            }
            $stmt_q_set->close();
            $stmt_opt_set->close();
            
            // 3. Actualizar la dependencia de cada pregunta que la tenga
            foreach ($preguntas_set as $pset) {
                if (!empty($pset['id_dependency_option'])) {
                    $dep_orig_id = $pset['id_dependency_option']; // Este valor es de question_set_options
                    // Determinar a qué pregunta pertenece esta opción en el set
                    $query = "SELECT id_question_set_question FROM question_set_options WHERE id = ?";
                    $stmt_dep = $conn->prepare($query);
                    $stmt_dep->bind_param("i", $dep_orig_id);
                    $stmt_dep->execute();
                    $stmt_dep->bind_result($parent_qs_id);
                    $stmt_dep->fetch();
                    $stmt_dep->close();
                    
                    if ($parent_qs_id && isset($map_questions[$parent_qs_id])) {
                        // Buscar en el mapeo de opciones del padre la opción correspondiente
                        if (isset($map_options[$parent_qs_id][$dep_orig_id])) {
                            $new_dep_option = $map_options[$parent_qs_id][$dep_orig_id];
                        } else {
                            $new_dep_option = null;
                        }
                        // Actualizar la pregunta importada actual para asignarle la dependencia correcta
                        $current_form_q_id = $map_questions[$pset['id']];
                        $stmt_update_dep = $conn->prepare("UPDATE form_questions SET id_dependency_option = ? WHERE id = ?");
                        $stmt_update_dep->bind_param("ii", $new_dep_option, $current_form_q_id);
                        $stmt_update_dep->execute();
                        $stmt_update_dep->close();
                    }
                }
            }
            
            $success = "Set de preguntas importado correctamente (incluyendo imágenes en las opciones y dependencia correcta).";
            header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-pregunta");
            exit();
        }
    }
}

// ------------------------------
// Obtener datos del Formulario
// ------------------------------
$stmt = $conn->prepare("
    SELECT id, nombre, fechaInicio, fechaTermino, estado, tipo, id_division, id_empresa, url_bi
    FROM formulario
    WHERE id = ?
");
if ($stmt === false) {
    die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Formulario no encontrado.";
    exit();
}

$formulario = $result->fetch_assoc();
$stmt->close();

// Obtener el id_empresa del formulario
$form_empresa_id = $formulario['id_empresa'];

// Obtener el nombre de la empresa del usuario
$empresa_id = $_SESSION['empresa_id'];
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
$stmt_empresa->bind_param("i", $empresa_id);
$stmt_empresa->execute();
$stmt_empresa->bind_result($nombre_empresa);
$stmt_empresa->fetch();
$stmt_empresa->close();

// Determinar si el usuario pertenece a "Mentecreativa"
$es_mentecreativa = false;
$nombre_empresa_limpio = strtolower(trim($nombre_empresa));
if ($nombre_empresa_limpio === 'mentecreativa') {
    $es_mentecreativa = true;
}

// Obtener todas las divisiones disponibles para seleccionar
if ($es_mentecreativa) {
    $stmt_divisiones = $conn->prepare("
        SELECT id, nombre
        FROM division_empresa
        ORDER BY nombre ASC
    ");
    $stmt_divisiones->execute();
    $result_divisiones = $stmt_divisiones->get_result();
    $divisiones = $result_divisiones->fetch_all(MYSQLI_ASSOC);
    $stmt_divisiones->close();
} else {
    $stmt_divisiones = $conn->prepare("
        SELECT id, nombre
        FROM division_empresa
        WHERE id_empresa = ?
        ORDER BY nombre ASC
    ");
    $stmt_divisiones->bind_param("i", $empresa_id);
    $stmt_divisiones->execute();
    $result_divisiones = $stmt_divisiones->get_result();
    $divisiones = $result_divisiones->fetch_all(MYSQLI_ASSOC);
    $stmt_divisiones->close();
}

// ------------------------------
// Procesar actualización del Formulario
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_formulario') {
    $nombre       = htmlspecialchars($_POST['nombre'], ENT_QUOTES, 'UTF-8');
    $fechaInicio  = $_POST['fechaInicio'];
    $fechaTermino = $_POST['fechaTermino'];
    $estado       = $_POST['estado'];
    $tipo         = $_POST['tipo'];
    $id_division  = $_POST['id_division'];

    if (empty($nombre) || empty($fechaInicio) || empty($fechaTermino) || empty($estado) || empty($tipo)) {
        $error = "Por favor, complete todos los campos obligatorios.";
    } else {
        $stmt_update = $conn->prepare("
            UPDATE formulario
            SET nombre       = ?,
                fechaInicio  = ?,
                fechaTermino = ?,
                estado       = ?,
                tipo         = ?,
                id_division  = ?
            WHERE id = ?
        ");
        if ($stmt_update === false) {
            die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
        }
        $stmt_update->bind_param(
            "sssssii",
            $nombre,
            $fechaInicio,
            $fechaTermino,
            $estado,
            $tipo,
            $id_division,
            $formulario_id
        );
        if ($stmt_update->execute()) {
            $success = "Formulario actualizado correctamente.";
            $formulario['nombre']       = $nombre;
            $formulario['fechaInicio']  = $fechaInicio;
            $formulario['fechaTermino'] = $fechaTermino;
            $formulario['estado']       = $estado;
            $formulario['tipo']         = $tipo;
            $formulario['id_division']  = $id_division;
        } else {
            $error = "Error al actualizar el formulario: " . htmlspecialchars($stmt_update->error);
        }
        $stmt_update->close();
    }
}

// ------------------------------
// Procesar adición de nuevas entradas (formularioQuestion)
// ------------------------------

// Procesar carga masiva desde CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fq_csv') {
    $fileTmpPath = $_FILES['csvFile']['tmp_name'];
    $fileName    = $_FILES['csvFile']['name'];
    $fileSize    = $_FILES['csvFile']['size'];
    $fileType    = $_FILES['csvFile']['type'];
    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExt !== 'csv') {
        $_SESSION['error_formulario'] = "Solo se permiten archivos CSV.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $uploadDir = '../uploads/csv/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            $_SESSION['error_formulario'] = "No se pudo crear directorio de subida.";
            header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
            exit();
        }
    }
    $newFileName = 'formulario_' . $formulario_id . '_' . time() . '.' . $fileExt;
    $dest_path   = $uploadDir . $newFileName;
    if (!move_uploaded_file($fileTmpPath, $dest_path)) {
        $_SESSION['error_formulario'] = "Error al subir archivo CSV.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $handle = fopen($dest_path, 'r');
    if ($handle === false) {
        $_SESSION['error_formulario'] = "No se pudo abrir el CSV.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $header = fgetcsv($handle, 1000, ";");
    if (!$header) {
        $_SESSION['error_formulario'] = "El CSV está vacío.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $req_cols = ['codigo','usuario','material','valor_propuesto'];
    $header_norm = array_map(function($c){ return strtolower(trim($c)); }, $header);
    $faltantes = array_diff($req_cols, $header_norm);
    if (!empty($faltantes)) {
        $_SESSION['error_formulario'] = "Faltan columnas requeridas: " . implode(", ", $faltantes);
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $idx_codigo          = array_search('codigo', $header_norm);
    $idx_usuario         = array_search('usuario', $header_norm);
    $idx_material        = array_search('material', $header_norm);
    $idx_valor_propuesto = array_search('valor_propuesto', $header_norm);
    
    // Para determinar si la empresa tiene divisiones, usamos el id_empresa del formulario
    $empresa_form = $formulario['id_empresa'];
    $tiene_divisiones = (contarDivisionesPorEmpresa($empresa_form) > 0);
    if ($tiene_divisiones) {
        $stmt_local = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_empresa = ?");
        $stmt_usuario = $conn->prepare("SELECT id FROM usuario WHERE usuario = ? AND id_empresa = ?");
    } else {
        $stmt_local = $conn->prepare("SELECT id FROM local WHERE codigo = ?");
        $stmt_usuario = $conn->prepare("SELECT id FROM usuario WHERE usuario = ?");
    }
    
    $stmt_insert_fq = $conn->prepare("
        INSERT INTO formularioQuestion
        (pregunta, motivo, material, valor, valor_propuesto, fechaVisita, countVisita, observacion, id_formulario, id_local, id_usuario, estado)
        VALUES ('', '', ?, '', ?,'',0,'',?,?,?,0)
    ");
    
    $fila = 1; 
    $errores_csv = []; 
    $ok = 0;
    while(($data = fgetcsv($handle, 1000, ";")) !== false) {
        $fila++;
        $cod_local   = trim($data[$idx_codigo]);
        $usr_name    = trim($data[$idx_usuario]);
        $material    = trim($data[$idx_material]);
        $val_prop    = trim($data[$idx_valor_propuesto]);
        if ($cod_local == '' || $usr_name == '' || $material == '' || $val_prop == '') {
            $errores_csv[] = "Fila $fila: Hay campos vacíos.";
            continue;
        }
        if (!is_numeric($val_prop)) {
            $errores_csv[] = "Fila $fila: valor_propuesto debe ser numérico.";
            continue;
        }
        $val_prop = intval($val_prop);
        if ($tiene_divisiones) {
            $stmt_local->bind_param("si", $cod_local, $empresa_form);
        } else {
            $stmt_local->bind_param("s", $cod_local);
        }
        $stmt_local->execute();
        $stmt_local->bind_result($id_local);
        if (!$stmt_local->fetch()) {
            $errores_csv[] = "Fila $fila: local '{$cod_local}' no encontrado.";
            $stmt_local->reset();
            continue;
        }
        $stmt_local->reset();
        
        if ($tiene_divisiones) {
            $stmt_usuario->bind_param("si", $usr_name, $empresa_form);
        } else {
            $stmt_usuario->bind_param("s", $usr_name);
        }
        $stmt_usuario->execute();
        $stmt_usuario->bind_result($id_usuario_csv);
        if (!$stmt_usuario->fetch()) {
            $errores_csv[] = "Fila $fila: usuario '{$usr_name}' no encontrado.";
            $stmt_usuario->reset();
            continue;
        }
        $stmt_usuario->reset();
        
        $stmt_insert_fq->bind_param("ssiii", $material, $val_prop, $formulario_id, $id_local, $id_usuario_csv);
        $stmt_insert_fq->execute();
        $ok++;
    }
    fclose($handle);
    $stmt_local->close();
    $stmt_usuario->close();
    $stmt_insert_fq->close();
    
    if (!empty($errores_csv)) {
        $_SESSION['error_formulario'] = "Errores en CSV:<br>" . implode("<br>", $errores_csv);
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }
    $_SESSION['success_formulario'] = "Entradas agregadas desde CSV. Registros: $ok";
    header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
    exit();
}
// Procesar adición de entrada individual
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fq') {
    $id_usuario = intval($_POST['id_usuario']);
    $codigo_local = htmlspecialchars($_POST['codigo_local'], ENT_QUOTES, 'UTF-8');
    $material_ids = $_POST['material_id'];
    $valores_propuestos = $_POST['valor_propuesto'];
    
    if ($id_usuario > 0 && !empty($codigo_local) && !empty($material_ids) && is_array($material_ids)) {
        foreach ($material_ids as $index => $material_id) {
            $material_id = intval($material_id);
            $valor_propuesto = htmlspecialchars($valores_propuestos[$index], ENT_QUOTES, 'UTF-8');
    
            $stmt_mat = $conn->prepare("SELECT nombre FROM material WHERE id = ?");
            $stmt_mat->bind_param("i", $material_id);
            $stmt_mat->execute();
            $stmt_mat->bind_result($material);
            $stmt_mat->fetch();
            $stmt_mat->close();
    
            if (!empty($material)) {
                $stmt_insert_fq = $conn->prepare("INSERT INTO formularioQuestion (id_formulario, id_usuario, id_local, material, valor_propuesto, estado) VALUES (?, ?, ?, ?, ?, 0)");
                if ($stmt_insert_fq === false) {
                    $error = "Error en la preparación de la consulta: " . htmlspecialchars($conn->error);
                    break;
                }
                $stmt_insert_fq->bind_param("iiiss", $formulario_id, $id_usuario, $codigo_local, $material, $valor_propuesto);
                if (!$stmt_insert_fq->execute()) {
                    $error = "Error al agregar la entrada: " . htmlspecialchars($stmt_insert_fq->error);
                    break;
                }
                $stmt_insert_fq->close();
            } else {
                $error = "Material no encontrado.";
                break;
            }
        }
    
        if (!isset($error)) {
            $success = "Entradas agregadas correctamente.";
            header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
            exit();
        }
    } else {
        $error = "Por favor, complete todos los campos obligatorios para agregar nuevas entradas.";
    }
}

// ------------------------------
// Procesar actualización de una entrada (formularioQuestion)
// ------------------------------
if (isset($_POST['update_fq'])) {
    $fq_id           = intval($_POST['fq_id']);
    $id_usuario      = intval($_POST['id_usuario']);
    $material_id     = intval($_POST['material_id']);
    $valor_propuesto = htmlspecialchars($_POST['valor_propuesto'], ENT_QUOTES, 'UTF-8');
    $fechaVisita     = $_POST['fechaVisita'];
    $observacion     = htmlspecialchars($_POST['observacion'], ENT_QUOTES, 'UTF-8');
    $estado          = intval($_POST['estado']);
    $valor           = htmlspecialchars($_POST['valor'], ENT_QUOTES, 'UTF-8');
    
    $stmt_mat = $conn->prepare("SELECT nombre FROM material WHERE id = ?");
    $stmt_mat->bind_param("i", $material_id);
    $stmt_mat->execute();
    $stmt_mat->bind_result($material);
    $stmt_mat->fetch();
    $stmt_mat->close();
    
    if ($fq_id > 0 && !empty($material)) {
        $pregunta_value = '';
        if ($estado === 0) {
            $pregunta_value = 'en proceso';
        } elseif ($estado === 1) {
            $pregunta_value = 'completado';
        } elseif ($estado === 2) {
            $pregunta_value = 'cancelado';
        }
    
        $stmt_update_fq = $conn->prepare("
            UPDATE formularioQuestion
            SET id_usuario      = ?,
                material        = ?,
                valor           = ?,
                valor_propuesto = ?,
                fechaVisita     = ?,
                observacion     = ?,
                estado          = ?,
                pregunta        = ?
            WHERE id = ?
        ");
        if ($stmt_update_fq === false) {
            die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
        }
        $stmt_update_fq->bind_param("isssssisi",
            $id_usuario,
            $material,
            $valor,
            $valor_propuesto,
            $fechaVisita,
            $observacion,
            $estado,
            $pregunta_value,
            $fq_id
        );
    
        if ($stmt_update_fq->execute()) {
            $success = "Entrada actualizada correctamente.";
            header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
            exit();
        } else {
            $error = "Error al actualizar la entrada: " . htmlspecialchars($stmt_update_fq->error);
        }
        $stmt_update_fq->close();
    } else {
        $error = "Por favor, complete todos los campos para actualizar la entrada (material o ID no válidos).";
    }
}

// ------------------------------
// NUEVO: Importar un Set de Preguntas al formulario (con imágenes en alternativas)
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_set') {
    $set_id = intval($_POST['selected_set_id']);
    if ($set_id <= 0) {
        $error = "Selecciona un set de preguntas válido.";
    } else {
        // Copiar las preguntas del set al formulario
        // 1. Obtener preguntas del set
        $preguntas_set = getQuestionsFromSet($set_id);
        // Cada elemento de $preguntas_set incluye ahora id_dependency_option e is_valued
    
        // MODIFICACIÓN: Incluir también id_dependency_option e is_valued en la inserción
        $sql_ins_q_set = "
            INSERT INTO form_questions
            (id_formulario, question_text, id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt_q_set = $conn->prepare($sql_ins_q_set);
        if (!$stmt_q_set) {
            die("Error preparando la inserción del set: " . $conn->error);
        }
    
        // Para las opciones (copiando reference_image también)
        $sql_ins_opt_set = "
            INSERT INTO form_question_options
            (id_form_question, option_text, sort_order, reference_image)
            VALUES (?, ?, ?, ?)
        ";
        $stmt_opt_set = $conn->prepare($sql_ins_opt_set);
        if (!$stmt_opt_set) {
            die("Error preparando la inserción de opciones: " . $conn->error);
        }
    
        // Hallar el máximo sort_order actual del formulario
        $max_sort_order_res = $conn->query("
            SELECT COALESCE(MAX(sort_order), 0) AS max_so
            FROM form_questions
            WHERE id_formulario = " . intval($formulario_id)
        );
        $row_so = $max_sort_order_res->fetch_assoc();
        $current_sort = intval($row_so['max_so']);
    
        foreach ($preguntas_set as $pset) {
            $question_text    = $pset['question_text'];
            $id_question_type = $pset['id_question_type'];
            $is_required      = $pset['is_required'];
            // Capturar la dependencia y la bandera de valorización del set
            $id_dependency_option = isset($pset['id_dependency_option']) ? $pset['id_dependency_option'] : null;
            $is_valued = isset($pset['is_valued']) ? $pset['is_valued'] : 0;
            // Incrementar el sort_order
            $current_sort++;
    
            // <== CORRECCIÓN: El string de tipos debe tener 8 caracteres, uno para cada parámetro
            $stmt_q_set->bind_param(
                "isiiiiii",
                $formulario_id,
                $question_text,
                $id_question_type,
                $current_sort,
                $is_required,
                $pset['id'],
                $id_dependency_option,
                $is_valued
            );
            $stmt_q_set->execute();
            $new_q_id = $conn->insert_id;  // ID de la nueva pregunta en form_questions
    
            // Obtener las opciones de la pregunta del set
            $opciones = getOptionsFromSetQuestion($pset['id']);
            if (!empty($opciones)) {
                foreach ($opciones as $opt) {
                    $stmt_opt_set->bind_param(
                        'isisi',
                        $new_q_id,
                        $opt['option_text'],
                        $opt['sort_order'],
                        $opt['reference_image'],
                        $opt['id']
                    );
                    $stmt_opt_set->execute();
                }
            }
        }
        $stmt_q_set->close();
        $stmt_opt_set->close();
    
        $success = "Set de preguntas importado correctamente (incluyendo imágenes en las opciones).";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-pregunta");
        exit();
    }
}

// ------------------------------
// Obtener entradas existentes (formularioQuestion) para listar
// ------------------------------
$estado_filtro = isset($_GET['estado']) ? $_GET['estado'] : '';

$sql_fq = "
    SELECT fq.id,
           fq.id_usuario,
           u.usuario AS nombre_usuario,
           fq.id_local,
           l.nombre AS nombre_local,
           fq.material,
           fq.valor_propuesto,
           fq.valor,
           fq.fechaVisita,
           fq.observacion,
           fq.estado,
           fq.pregunta
    FROM formularioQuestion fq
    LEFT JOIN usuario u ON fq.id_usuario = u.id
    LEFT JOIN local l   ON fq.id_local   = l.id
    WHERE fq.id_formulario = ?
";

if ($estado_filtro === 'completado') {
    $sql_fq .= " AND fq.estado = 1";
} elseif ($estado_filtro === 'en_proceso') {
    $sql_fq .= " AND fq.estado = 0";
} elseif ($estado_filtro === 'cancelado') {
    $sql_fq .= " AND fq.estado = 2";
}

$stmt_fq = $conn->prepare($sql_fq);
$stmt_fq->bind_param("i", $formulario_id);
$stmt_fq->execute();
$result_fq = $stmt_fq->get_result();
$formulario_questions = $result_fq->fetch_all(MYSQLI_ASSOC);
$stmt_fq->close();

// ---------------------------------------------------------------
// OBTENER Listado de Sets de Preguntas disponibles
// ---------------------------------------------------------------
$sets = getQuestionSets();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Formulario</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .material-row {
            margin-bottom: 10px;
        }
        .remove-material-btn {
            margin-top: 32px;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h1>Editar Formulario</h1>
    <?php if (isset($success)) { ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php } ?>
    <?php if (isset($error)) { ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php } ?>

    <!-- Barra de navegación con pestañas -->
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
            <a class="nav-link <?php echo ($active_tab === 'agregar-entradas') ? 'active' : ''; ?>"
               id="agregar-entradas-tab"
               data-toggle="tab"
               href="#agregar-entradas"
               role="tab"
               aria-controls="agregar-entradas"
               aria-selected="<?php echo ($active_tab === 'agregar-entradas') ? 'true' : 'false'; ?>">
                Agregar Entradas
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
        <!-- Nueva pestaña: Importar Set de Preguntas -->
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
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" class="mt-4">
                <input type="hidden" name="action" value="update_formulario">
                <div class="form-group">
                    <label for="nombre">Nombre del formulario:</label>
                    <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo htmlspecialchars($formulario['nombre'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="fechaInicio">Fecha de Inicio:</label>
                    <input type="datetime-local" id="fechaInicio" name="fechaInicio" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($formulario['fechaInicio'])); ?>" required>
                </div>
                <div class="form-group">
                    <label for="fechaTermino">Fecha de Término:</label>
                    <input type="datetime-local" id="fechaTermino" name="fechaTermino" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($formulario['fechaTermino'])); ?>" required>
                </div>
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
                        <option value="1" <?php if ($formulario['tipo'] == '1') echo 'selected'; ?>>Actividad Programada</option>
                        <option value="2" <?php if ($formulario['tipo'] == '2') echo 'selected'; ?>>Actividad IW</option>
                    </select>
                </div>
                <!-- Selección de División -->
                <div class="form-group">
                    <label for="id_division">División:</label>
                    <select id="id_division" name="id_division" class="form-control" required>
                        <option value="">Seleccione una división</option>
                        <?php foreach ($divisiones as $division): ?>
                            <option value="<?php echo htmlspecialchars($division['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php if ($formulario['id_division'] == $division['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($division['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nombre">URL Informe BI:</label>
                    <input type="text" id="url_bi" name="url_bi" class="form-control" value="<?php echo htmlspecialchars($formulario['url_bi'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>                
                <button type="submit" class="btn btn-primary">Actualizar Formulario</button>
                <a href="../mod_formulario.php" class="btn btn-secondary">Volver</a>
            </form>
        </div>

        <!-- Tab: Agregar Entradas -->
        <div class="tab-pane fade <?php echo ($active_tab === 'agregar-entradas') ? 'show active' : ''; ?>"
             id="agregar-entradas"
             role="tabpanel"
             aria-labelledby="agregar-entradas-tab">
            <h3 class="mt-4">Agregar Nueva Entrada</h3>
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_fq">
                <input type="hidden" name="active_tab" value="agregar-entradas">
                <div class="form-group">
                    <label for="id_usuario">Usuario:</label>
                    <select id="id_usuario" name="id_usuario" class="form-control" required>
                        <option value="">Seleccione un usuario</option>
                        <?php
                        if ($es_mentecreativa) {
                            $stmt_usuarios = $conn->prepare("SELECT id, usuario FROM usuario ORDER BY usuario ASC");
                            $stmt_usuarios->execute();
                            $result_usuarios = $stmt_usuarios->get_result();
                            $usuarios = $result_usuarios->fetch_all(MYSQLI_ASSOC);
                            $stmt_usuarios->close();
                        } else {
                            $stmt_usuarios = $conn->prepare("SELECT id, usuario FROM usuario WHERE id_empresa = ? ORDER BY usuario ASC");
                            $stmt_usuarios->bind_param("i", $empresa_id);
                            $stmt_usuarios->execute();
                            $result_usuarios = $stmt_usuarios->get_result();
                            $usuarios = $result_usuarios->fetch_all(MYSQLI_ASSOC);
                            $stmt_usuarios->close();
                        }
                        foreach ($usuarios as $usuario): ?>
                            <option value="<?php echo htmlspecialchars($usuario['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="codigo_local">ID Local:</label>
                    <input type="text" id="codigo_local" name="codigo_local" class="form-control" required>
                </div>
                <!-- (No se incluye el campo CSV en este formulario individual) -->
                <!-- Contenedor para materiales y valores propuestos -->
                <div id="materiales-container">
                    <div class="material-row">
                        <div class="form-row">
                            <div class="col">
                                <label>Material:</label>
                                <div class="input-group">
                                    <select name="material_id[]" class="form-control" required>
                                        <option value="">Seleccione un material</option>
                                        <?php
                                        $stmt_materiales = $conn->prepare("SELECT id, nombre FROM material ORDER BY nombre ASC");
                                        $stmt_materiales->execute();
                                        $result_materiales = $stmt_materiales->get_result();
                                        $materiales = $result_materiales->fetch_all(MYSQLI_ASSOC);
                                        $stmt_materiales->close();
                                        foreach ($materiales as $material): ?>
                                            <option value="<?php echo htmlspecialchars($material['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($material['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">
                                            Crear Material
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col">
                                <label>Valor Propuesto:</label>
                                <input type="text" name="valor_propuesto[]" class="form-control">
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-danger remove-material-btn" onclick="removeMaterialRow(this)">
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-info mt-3" onclick="addMaterialRow()">Agregar Otro Material</button>
                <button type="submit" class="btn btn-success mt-3">Agregar Entradas</button>
            </form>
            
            <!-- NUEVO: Formulario independiente para subir CSV masivo -->
            <h3 class="mt-5">Subir IPT Masivo desde CSV</h3>
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_fq_csv">
                <input type="hidden" name="active_tab" value="agregar-entradas">
                <div class="form-group">
                    <label for="csvFile">Archivo CSV:</label>
                    <input type="file" id="csvFile" name="csvFile" class="form-control-file" accept=".csv" required>
                    <small class="form-text text-muted">
                        Debe contener las columnas: <strong>codigo</strong>, <strong>usuario</strong>, <strong>material</strong>, <strong>valor_propuesto</strong>.
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Subir CSV Masivo</button>
            </form>
            
            <!-- Modal para agregar nuevo material -->
            <div class="modal fade" id="agregarMaterialModal" tabindex="-1" role="dialog" aria-labelledby="agregarMaterialModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post">
                        <input type="hidden" name="add_material" value="1">
                        <input type="hidden" name="active_tab" value="agregar-entradas">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="agregarMaterialModalLabel">Agregar Nuevo Material</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                                  <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="form-group">
                                    <label for="nombre_material">Nombre del Material:</label>
                                    <input type="text" id="nombre_material" name="nombre_material" class="form-control" required>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                <button type="submit" class="btn btn-primary">Agregar Material</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Filtro de estado -->
            <div class="filter-container mt-5">
                <form method="get" action="editar_formulario.php">
                    <input type="hidden" name="id" value="<?php echo $formulario_id; ?>">
                    <input type="hidden" name="active_tab" value="agregar-entradas">
                    <label for="estado">Filtrar por estado:</label>
                    <select name="estado" id="estado" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="en_proceso"  <?php if ($estado_filtro === 'en_proceso')  echo 'selected'; ?>>En Proceso</option>
                        <option value="completado"  <?php if ($estado_filtro === 'completado')  echo 'selected'; ?>>Completado</option>
                        <option value="cancelado"   <?php if ($estado_filtro === 'cancelado')   echo 'selected'; ?>>Cancelado</option>
                    </select>
                </form>
            </div>
            <!-- Listado de entradas en formularioQuestion -->
            <h3 class="mt-5">Entradas Existentes</h3>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Local</th>
                            <th>Material</th>
                            <th>Valor Propuesto</th>
                            <th>Valor</th>
                            <th>Fecha Visita</th>
                            <th>Observación</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($formulario_questions) > 0): ?>
                        <?php foreach ($formulario_questions as $fq): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($fq['nombre_usuario'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['nombre_local'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['material'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['valor_propuesto'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['valor'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['fechaVisita'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($fq['observacion'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php
                                    if ($fq['estado'] == 0) { echo 'En Proceso'; }
                                    elseif ($fq['estado'] == 1) { echo 'Completado'; }
                                    elseif ($fq['estado'] == 2) { echo 'Cancelado'; }
                                    else { echo 'Otro'; }
                                    ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#editarFQModal<?php echo $fq['id']; ?>">Editar</button>
                                </td>
                            </tr>
                            <!-- Modal para editar la entrada -->
                            <div class="modal fade" id="editarFQModal<?php echo $fq['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="editarFQModalLabel<?php echo $fq['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog" role="document">
                                    <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post">
                                        <input type="hidden" name="update_fq" value="1">
                                        <input type="hidden" name="fq_id" value="<?php echo $fq['id']; ?>">
                                        <input type="hidden" name="active_tab" value="agregar-entradas">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="editarFQModalLabel<?php echo $fq['id']; ?>">Editar Entrada</h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group">
                                                    <label for="id_usuario">Usuario Asignado:</label>
                                                    <select id="id_usuario" name="id_usuario" class="form-control" required>
                                                        <option value="">Seleccione un usuario</option>
                                                        <?php foreach ($usuarios as $usuario): ?>
                                                            <option value="<?php echo htmlspecialchars($usuario['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php if ($fq['id_usuario'] == $usuario['id']) echo 'selected'; ?>>
                                                                <?php echo htmlspecialchars($usuario['usuario'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label for="material_id">Material:</label>
                                                    <div class="input-group">
                                                        <select id="material_id" name="material_id" class="form-control" required>
                                                            <option value="">Seleccione un material</option>
                                                            <?php foreach ($materiales as $mat): ?>
                                                                <option value="<?php echo htmlspecialchars($mat['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php if ($fq['material'] == $mat['nombre']) echo 'selected'; ?>>
                                                                    <?php echo htmlspecialchars($mat['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="input-group-append">
                                                            <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">Agregar Material</button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group">
                                                    <label for="valor_propuesto">Valor Propuesto:</label>
                                                    <input type="text" id="valor_propuesto" name="valor_propuesto" class="form-control" value="<?php echo htmlspecialchars($fq['valor_propuesto'], ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label for="valor">Valor:</label>
                                                    <input type="text" id="valor" name="valor" class="form-control" value="<?php echo htmlspecialchars($fq['valor'], ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label for="fechaVisita">Fecha de Visita:</label>
                                                    <input type="datetime-local" id="fechaVisita" name="fechaVisita" class="form-control" value="<?php echo !empty($fq['fechaVisita']) ? date('Y-m-d\TH:i', strtotime($fq['fechaVisita'])) : ''; ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label for="observacion">Observación:</label>
                                                    <textarea id="observacion" name="observacion" class="form-control"><?php echo htmlspecialchars($fq['observacion'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                </div>
                                                <div class="form-group">
                                                    <label for="estado">Estado:</label>
                                                    <select id="estado" name="estado" class="form-control" required>
                                                        <option value="0" <?php if ($fq['estado'] == '0') echo 'selected'; ?>>En Proceso</option>
                                                        <option value="1" <?php if ($fq['estado'] == '1') echo 'selected'; ?>>Completado</option>
                                                        <option value="2" <?php if ($fq['estado'] == '2') echo 'selected'; ?>>Cancelado</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center">No hay entradas.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
        </div>

        <!-- Tab: Agregar Pregunta -->
        <div class="tab-pane fade <?php echo ($active_tab === 'agregar-pregunta') ? 'show active' : ''; ?>"
             id="agregar-pregunta"
             role="tabpanel"
             aria-labelledby="agregar-pregunta-tab">
            <h3 class="mt-4">Agregar Nueva Pregunta</h3>
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" id="addQuestionForm" enctype="multipart/form-data">
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
                <!-- Contenedor para "Pregunta valorizada" que se muestra solo si el tipo es Selección única (2) o múltiple (3) -->
                <div id="valuedContainer" class="form-check mb-2" style="display: none;">
                    <input type="checkbox" id="is_valued" name="is_valued" class="form-check-input" value="1">
                    <label for="is_valued" class="form-check-label">¿Pregunta valorizada?</label>
                </div>
                <!-- Contenedor para opciones (solo se muestra si el tipo seleccionado requiere opciones) -->
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
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post">
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

// Funciones para la pestaña "Agregar Varias Preguntas"
let questionCounter = 0;
function addQuestionRowMulti() {
    const container = document.getElementById('questionsContainer');
    const rowIndex = questionCounter++;
    let rowDiv = document.createElement('div');
    rowDiv.className = 'border p-3 mb-3';
    rowDiv.innerHTML = '<div class="form-group">' +
        '<label>Texto de la Pregunta:</label>' +
        '<input type="text" class="form-control" name="questionsMulti[' + rowIndex + '][question_text]" required>' +
        '</div>' +
        '<div class="form-group">' +
        '<label>Tipo de Pregunta:</label>' +
        '<select class="form-control" name="questionsMulti[' + rowIndex + '][id_question_type]" onchange="toggleOptionsMulti(\'' + rowIndex + '\', this)" required>' +
        '<option value="">-- Seleccione --</option>' +
        '<option value="1">Sí/No</option>' +
        '<option value="2">Selección única</option>' +
        '<option value="3">Selección múltiple</option>' +
        '<option value="4">Texto</option>' +
        '<option value="5">Numérico</option>' +
        '<option value="6">Fecha</option>' +
        '<option value="7">Foto</option>' +
        '</select>' +
        '</div>' +
        '<div class="form-check mb-2">' +
        '<input type="checkbox" class="form-check-input" name="questionsMulti[' + rowIndex + '][is_required]" value="1" id="multiRequired_' + rowIndex + '">' +
        '<label for="multiRequired_' + rowIndex + '" class="form-check-label">¿Es requerida?</label>' +
        '</div>' +
        '<div class="form-group">' +
        '<label>Depende de (opcional):</label>' +
        '<select name="questionsMulti[' + rowIndex + '][dependency_option]" class="form-control">' +
        '<?php echo $dependencyOptionsHtml; ?>' +
        '</select>' +
        '<small class="form-text text-muted">Selecciona la opción de una pregunta sí/no que dispare esta pregunta.</small>' +
        '</div>' +
        '<div id="valuedContainer_' + rowIndex + '" class="form-check mb-2" style="display: none;">' +
        '<input type="checkbox" class="form-check-input" name="questionsMulti[' + rowIndex + '][is_valued]" value="1" id="multiIsValued_' + rowIndex + '">' +
        '<label for="multiIsValued_' + rowIndex + '" class="form-check-label">¿Pregunta valorizada?</label>' +
        '</div>' +
        '<div id="optContainer_' + rowIndex + '" style="display: none;">' +
        '<label>Opciones (si aplica):</label>' +
        '<div id="optRows_' + rowIndex + '"></div>' +
        '<button type="button" class="btn btn-sm btn-secondary" onclick="addOptionRowMulti(\'' + rowIndex + '\')">+ Opción</button>' +
        '</div>' +
        '<button type="button" class="btn btn-danger mt-2" onclick="this.parentNode.remove()">Eliminar esta pregunta</button>';
    container.appendChild(rowDiv);
}

function toggleOptionsMulti(rowIndex, selectElem) {
    const val = parseInt(selectElem.value, 10);
    const ctn = document.getElementById('optContainer_' + rowIndex);
    const row = document.getElementById('optRows_' + rowIndex);
    const valuedContainer = document.getElementById('valuedContainer_' + rowIndex);
    
    // Mostrar el checkbox "Pregunta valorizada" solo para tipos 2 y 3
    if ([2, 3].includes(val)) {
        valuedContainer.style.display = 'block';
    } else {
        valuedContainer.style.display = 'none';
    }
    
    row.innerHTML = '';
    if ([1, 2, 3].includes(val)) {
        ctn.style.display = 'block';
        if (val === 1) {
            row.innerHTML = '<div class="option-block mb-2">' +
              '<div class="input-group">' +
              '<input type="text" class="form-control" name="questionsMulti[' + rowIndex + '][options][0]" value="Sí" readonly>' +
              '<div class="input-group-append">' +
              '<button type="button" class="btn btn-secondary" disabled>Fijo</button>' +
              '</div>' +
              '<div class="custom-file">' +
              '<input type="file" class="custom-file-input" name="multi_option_images[' + rowIndex + '][0]" accept="image/*" onchange="previewMultiOptionImage(event, \'multiPrev_' + rowIndex + '_0\')">' +
              '<label class="custom-file-label">Imagen (opcional)</label>' +
              '</div>' +
              '</div>' +
              '<img id="multiPrev_' + rowIndex + '_0" style="max-width:100px; margin-top:5px; display:block;">' +
              '</div>' +
              '<div class="option-block mb-2">' +
              '<div class="input-group">' +
              '<input type="text" class="form-control" name="questionsMulti[' + rowIndex + '][options][1]" value="No" readonly>' +
              '<div class="input-group-append">' +
              '<button type="button" class="btn btn-secondary" disabled>Fijo</button>' +
              '</div>' +
              '<div class="custom-file">' +
              '<input type="file" class="custom-file-input" name="multi_option_images[' + rowIndex + '][1]" accept="image/*" onchange="previewMultiOptionImage(event, \'multiPrev_' + rowIndex + '_1\')">' +
              '<label class="custom-file-label">Imagen (opcional)</label>' +
              '</div>' +
              '</div>' +
              '<img id="multiPrev_' + rowIndex + '_1" style="max-width:100px; margin-top:5px; display:block;">' +
              '</div>';
        }
    } else {
        ctn.style.display = 'none';
    }
}

function addOptionRowMulti(rowIndex) {
    const row = document.getElementById('optRows_' + rowIndex);
    if (!row) return;
    const optCount = row.children.length;
    const container = document.createElement('div');
    container.className = 'option-block mb-2';
    const previewId = 'multiPrev_' + rowIndex + '_' + optCount;
    container.innerHTML = '<div class="input-group">' +
      '<input type="text" class="form-control" name="questionsMulti[' + rowIndex + '][options][' + optCount + ']" placeholder="Texto de la opción">' +
      '<div class="input-group-append">' +
      '<button type="button" class="btn btn-danger" onclick="this.closest(\' .option-block\').remove()">X</button>' +
      '</div>' +
      '<div class="custom-file">' +
      '<input type="file" class="custom-file-input" name="multi_option_images[' + rowIndex + '][' + optCount + ']" accept="image/*" onchange="previewMultiOptionImage(event, \'' + previewId + '\')">' +
      '<label class="custom-file-label">Imagen (opcional)</label>' +
      '</div>' +
      '</div>' +
      '<img id="' + previewId + '" style="max-width:100px; margin-top:5px; display:block;">';
    row.appendChild(container);
}

function previewMultiOptionImage(evt, previewId) {
    const file = evt.target.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        alert("Solo se permiten archivos de imagen.");
        evt.target.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        const imgElement = document.getElementById(previewId);
        if (imgElement) {
            imgElement.src = e.target.result;
        }
    };
    reader.readAsDataURL(file);
}

// Funciones para agregar material en la pestaña "Agregar Entradas"
function addMaterialRow() {
    const materialesContainer = document.getElementById('materiales-container');
    const materialRow = document.createElement('div');
    materialRow.className = 'material-row';
    materialRow.innerHTML = '<div class="form-row">' +
        '<div class="col">' +
            '<label>Material:</label>' +
            '<div class="input-group">' +
                '<select name="material_id[]" class="form-control" required>' +
                    '<option value="">Seleccione un material</option>' +
                    '<?php foreach ($materiales as $material): ?>' +
                        '<option value="<?php echo htmlspecialchars($material["id"], ENT_QUOTES, "UTF-8"); ?>">' +
                            '<?php echo htmlspecialchars($material["nombre"], ENT_QUOTES, "UTF-8"); ?>' +
                        '</option>' +
                    '<?php endforeach; ?>' +
                '</select>' +
                '<div class="input-group-append">' +
                    '<button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">' +
                        'Agregar Material' +
                    '</button>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div class="col">' +
            '<label>Valor Propuesto:</label>' +
            '<input type="text" name="valor_propuesto[]" class="form-control">' +
        '</div>' +
        '<div class="col-auto">' +
            '<button type="button" class="btn btn-danger remove-material-btn" onclick="removeMaterialRow(this)">Eliminar</button>' +
        '</div>' +
    '</div>';
    materialesContainer.appendChild(materialRow);
}

function removeMaterialRow(button) {
    const materialRow = button.closest('.material-row');
    if (materialRow) {
        materialRow.remove();
    }
}
</script>
</body>
</html>
<?php
$conn->close();
?>

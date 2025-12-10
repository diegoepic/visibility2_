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


$qry_existing = "SELECT id, id_question_set_question FROM form_questions WHERE id_formulario = " . intval($formulario_id);
$res_existing = $conn->query($qry_existing);
$existing_questions = [];
while ($row = $res_existing->fetch_assoc()) {
    $existing_questions[$row['id_question_set_question']] = $row['id'];
}


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

            $conn->begin_transaction();
            try {
                // 1) Preguntas ya importadas del set en este formulario: set_q_id -> form_q_id
                $existingFormQuestions = [];
                $sql = "SELECT id, id_question_set_question 
                        FROM form_questions 
                        WHERE id_formulario = ? AND id_question_set_question IS NOT NULL";
                $stmt_existing = $conn->prepare($sql);
                if (!$stmt_existing) { throw new Exception("Error preparando lectura de preguntas existentes: ".$conn->error); }
                $stmt_existing->bind_param("i", $formulario_id);
                $stmt_existing->execute();
                $result_existing = $stmt_existing->get_result();
                while ($row = $result_existing->fetch_assoc()) {
                    $existingFormQuestions[(int)$row['id_question_set_question']] = (int)$row['id'];
                }
                $stmt_existing->close();

                // Mapeos para sincronización
                $map_questions = []; // set_q_id  => form_q_id
                $map_options   = []; // set_q_id  => [ set_opt_id => form_opt_id ]

                // Preparar sentencias
                $sql_ins_q = "INSERT INTO form_questions
                              (id_formulario, question_text, id_question_type, sort_order, is_required, id_question_set_question, id_dependency_option, is_valued)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_ins_q = $conn->prepare($sql_ins_q);
                if (!$stmt_ins_q) { throw new Exception("Error preparando inserción de pregunta: ".$conn->error); }

                $sql_upd_q = "UPDATE form_questions 
                              SET question_text = ?, id_question_type = ?, sort_order = ?, is_required = ?, is_valued = ?
                              WHERE id = ?";
                $stmt_upd_q = $conn->prepare($sql_upd_q);
                if (!$stmt_upd_q) { throw new Exception("Error preparando actualización de pregunta: ".$conn->error); }

                $sql_ins_opt = "INSERT INTO form_question_options
                                (id_form_question, option_text, sort_order, reference_image, id_question_set_option)
                                VALUES (?, ?, ?, ?, ?)";
                $stmt_ins_opt = $conn->prepare($sql_ins_opt);
                if (!$stmt_ins_opt) { throw new Exception("Error preparando inserción de opción: ".$conn->error); }

                $sql_upd_opt = "UPDATE form_question_options 
                                SET option_text = ?, sort_order = ?, reference_image = ?
                                WHERE id = ?";
                $stmt_upd_opt = $conn->prepare($sql_upd_opt);
                if (!$stmt_upd_opt) { throw new Exception("Error preparando actualización de opción: ".$conn->error); }

                // sort base actual (se usa el sort del set)
                $res_so = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS max_so FROM form_questions WHERE id_formulario = ".intval($formulario_id));
                $row_so = $res_so->fetch_assoc();
                $current_sort = (int)$row_so['max_so']; // no se usa si tomamos el sort del set

                // 2) Insertar/actualizar preguntas y opciones
                foreach ($preguntas_set as $pset) {
                    $original_q_id   = (int)$pset['id'];
                    $question_text   = $pset['question_text'];
                    $id_question_type= (int)$pset['id_question_type'];
                    $is_required     = (int)$pset['is_required'];
                    $is_valued       = isset($pset['is_valued']) ? (int)$pset['is_valued'] : 0;
                    $id_dependency_option = null; // se ajusta luego
                    $current_sort    = (int)$pset['sort_order'];

                    if (isset($existingFormQuestions[$original_q_id])) {
                        // Actualizar
                        $form_q_id = $existingFormQuestions[$original_q_id];
                        $stmt_upd_q->bind_param("siiiii", $question_text, $id_question_type, $current_sort, $is_required, $is_valued, $form_q_id);
                        $stmt_upd_q->execute();
                    } else {
                        // Insertar
                        $stmt_ins_q->bind_param("isiiiiii",
                            $formulario_id, $question_text, $id_question_type, $current_sort,
                            $is_required, $original_q_id, $id_dependency_option, $is_valued
                        );
                        $stmt_ins_q->execute();
                        $form_q_id = $conn->insert_id;
                    }
                    $map_questions[$original_q_id] = $form_q_id;

                    // 2.a) Sincronizar opciones de esta pregunta
                    // Opciones ya importadas: set_opt_id -> form_opt_id
                    $existingOpts = [];
                    $sql_get_opts = "SELECT id, id_question_set_option FROM form_question_options WHERE id_form_question = ?";
                    $stmt_get_opts = $conn->prepare($sql_get_opts);
                    if (!$stmt_get_opts) { throw new Exception("Error preparando lectura de opciones existentes: ".$conn->error); }
                    $stmt_get_opts->bind_param("i", $form_q_id);
                    $stmt_get_opts->execute();
                    $result_opts = $stmt_get_opts->get_result();
                    while ($row = $result_opts->fetch_assoc()) {
                        $existingOpts[(int)$row['id_question_set_option']] = (int)$row['id'];
                    }
                    $stmt_get_opts->close();

                    $map_options[$original_q_id] = [];
                    $setOptionIds = [];

                    $opciones = getOptionsFromSetQuestion($original_q_id);
                    if (!empty($opciones)) {
                        foreach ($opciones as $opt) {
                            $original_opt_id = (int)$opt['id'];
                            $setOptionIds[]  = $original_opt_id;
                            $option_text     = $opt['option_text'];
                            $sort_order      = (int)$opt['sort_order'];
                            $reference_image = $opt['reference_image'];

                            if (isset($existingOpts[$original_opt_id])) {
                                // update
                                $form_opt_id = $existingOpts[$original_opt_id];
                                $stmt_upd_opt->bind_param("sisi", $option_text, $sort_order, $reference_image, $form_opt_id);
                                $stmt_upd_opt->execute();
                            } else {
                                // insert
                                $stmt_ins_opt->bind_param("isisi", $form_q_id, $option_text, $sort_order, $reference_image, $original_opt_id);
                                $stmt_ins_opt->execute();
                                $form_opt_id = $conn->insert_id;
                            }
                            $map_options[$original_q_id][$original_opt_id] = $form_opt_id;
                        }
                        // Eliminar opciones que ya no están en el set
                        foreach ($existingOpts as $orig_opt_id => $form_opt_id) {
                            if (!in_array($orig_opt_id, $setOptionIds, true)) {
                                $stmtDeleteOpt = $conn->prepare("DELETE FROM form_question_options WHERE id = ?");
                                $stmtDeleteOpt->bind_param("i", $form_opt_id);
                                $stmtDeleteOpt->execute();
                                $stmtDeleteOpt->close();
                            }
                        }
                    } else {
                        // La pregunta del set ya no tiene opciones => borra todas las del form
                        if (!empty($existingOpts)) {
                            $in_clause = implode(",", array_values($existingOpts));
                            $conn->query("DELETE FROM form_question_options WHERE id IN ($in_clause) AND id_form_question = $form_q_id");
                        }
                    }
                }

                // Cerrar statements base
                $stmt_ins_q->close();
                $stmt_upd_q->close();
                $stmt_ins_opt->close();
                $stmt_upd_opt->close();

                // 3) Borrar preguntas (y sus opciones) que ya no están en el set
                $set_q_ids = array_map(function($pset){ return (int)$pset['id']; }, $preguntas_set);
                if (!empty($set_q_ids)) {
                    $set_q_ids_str = implode(",", $set_q_ids);

                    // 3.a) Eliminar opciones de preguntas que serán eliminadas
                    $delSql = "
                      DELETE fqo FROM form_question_options fqo
                      JOIN form_questions fq ON fq.id = fqo.id_form_question
                      WHERE fq.id_formulario = ?
                        AND fq.id_question_set_question IS NOT NULL
                        AND fq.id_question_set_question NOT IN ($set_q_ids_str)
                    ";
                    $stDel = $conn->prepare($delSql);
                    if (!$stDel) { throw new Exception("Error preparando borrado de opciones huérfanas: ".$conn->error); }
                    $stDel->bind_param("i", $formulario_id);
                    $stDel->execute();
                    $stDel->close();

                    // 3.b) Eliminar preguntas fuera del set
                    $conn->query("
                        DELETE FROM form_questions 
                        WHERE id_formulario = $formulario_id
                          AND id_question_set_question IS NOT NULL
                          AND id_question_set_question NOT IN ($set_q_ids_str)
                    ");
                }

                // 4) Actualizar TODAS las dependencias (incluye limpiar a NULL)
                // Mapa set_opt_id -> set_parent_q_id (una sola query)
                $optToParent = [];
                $stmtOpt = $conn->prepare("
                  SELECT o.id, o.id_question_set_question
                  FROM question_set_options o
                  JOIN question_set_questions q ON q.id = o.id_question_set_question
                  WHERE q.id_question_set = ?
                ");
                if (!$stmtOpt) { throw new Exception("Error preparando mapa opción->padre: ".$conn->error); }
                $stmtOpt->bind_param("i", $set_id);
                $stmtOpt->execute();
                $resOpt = $stmtOpt->get_result();
                while ($row = $resOpt->fetch_assoc()) {
                    $optToParent[(int)$row['id']] = (int)$row['id_question_set_question'];
                }
                $stmtOpt->close();

                foreach ($preguntas_set as $pset) {
                    $set_q_id  = (int)$pset['id'];
                    $form_q_id = (int)$map_questions[$set_q_id];
                    $dep_set_opt = $pset['id_dependency_option']; // puede venir null/''

                    if (empty($dep_set_opt)) {
                        // Ahora es raíz => limpiar a NULL en el form
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = NULL WHERE id = ?");
                        if (!$st) { throw new Exception("Error limpiando dependencia: ".$conn->error); }
                        $st->bind_param("i", $form_q_id);
                        $st->execute();
                        $st->close();
                        continue;
                    }

                    $dep_set_opt  = (int)$dep_set_opt;
                    $parent_set_q = $optToParent[$dep_set_opt] ?? null;

                    $new_dep_form_opt = null;
                    if ($parent_set_q && isset($map_questions[$parent_set_q])) {
                        // map_options[parent_set_q][dep_set_opt] -> id opción en form
                        $new_dep_form_opt = $map_options[$parent_set_q][$dep_set_opt] ?? null;
                    }

                    if (is_null($new_dep_form_opt)) {
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = NULL WHERE id = ?");
                        if (!$st) { throw new Exception("Error actualizando dependencia (NULL): ".$conn->error); }
                        $st->bind_param("i", $form_q_id);
                    } else {
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = ? WHERE id = ?");
                        if (!$st) { throw new Exception("Error actualizando dependencia: ".$conn->error); }
                        $st->bind_param("ii", $new_dep_form_opt, $form_q_id);
                    }
                    $st->execute();
                    $st->close();
                }

                $conn->commit();
                $success = "Set de preguntas importado y sincronizado correctamente.";
                header("Location: editar_formulario.php?id=$formulario_id&active_tab=import-set");
                exit();

            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Error al importar el set: " . $e->getMessage();
            }
        }
    }
}
// ------------------------------
// Obtener datos del Formulario
// ------------------------------
$stmt = $conn->prepare("
    SELECT id, nombre, fechaInicio, fechaTermino, estado, tipo, modalidad, id_division, id_empresa, url_bi, reference_image
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
    $stmt_divisiones = $conn->prepare("SELECT id, nombre FROM division_empresa ORDER BY nombre ASC");
    $stmt_divisiones->execute();
    $result_divisiones = $stmt_divisiones->get_result();
    $divisiones = $result_divisiones->fetch_all(MYSQLI_ASSOC);
    $stmt_divisiones->close();
} else {
    $stmt_divisiones = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE id_empresa = ? ORDER BY nombre ASC");
    $stmt_divisiones->bind_param("i", $empresa_id);
    $stmt_divisiones->execute();
    $result_divisiones = $stmt_divisiones->get_result();
    $divisiones = $result_divisiones->fetch_all(MYSQLI_ASSOC);
    $stmt_divisiones->close();
}

// ------------------------------
// Procesar actualización del Formulario
// ------------------------------
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/reference_images/';

// Asegúrate de que la carpeta existe
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Por defecto, mantenemos la anterior
$reference_image = $formulario['reference_image'];

// Si se subió una nueva
if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
    $allowed = [
      "jpg"  => "image/jpeg",
      "jpeg" => "image/jpeg",
      "png"  => "image/png",
      "gif"  => "image/gif"
    ];
    $fileTmp  = $_FILES['reference_image']['tmp_name'];
    $fileName = $_FILES['reference_image']['name'];
    $fileType = $_FILES['reference_image']['type'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Validar extensión y mime
    if (isset($allowed[$fileExt]) && $allowed[$fileExt] === $fileType) {
        // Generar nombre único
        $newName = uniqid('refimg_') . '.' . $fileExt;
        $dest    = $uploadDir . $newName;
        if (move_uploaded_file($fileTmp, $dest)) {
            // Ruta relativa para BD
            $reference_image = '/visibility2/portal/uploads/reference_images/' . $newName;
        } else {
            $error = "No se pudo mover la imagen de referencia.";
        }
    } else {
        $error = "Formato de imagen de referencia no permitido.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_formulario') {
    $nombre       = htmlspecialchars($_POST['nombre'], ENT_QUOTES, 'UTF-8');
    $fechaInicio  = $_POST['fechaInicio'];
    $fechaTermino = $_POST['fechaTermino'];
    $estado       = $_POST['estado'];
    $tipo         = $_POST['tipo'];
    $id_division  = $_POST['id_division'];
    $modalidad    = $_POST['modalidad'];
    $url_bi       = $_POST['url_bi'];    

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
                modalidad     = ?,
                id_division  = ?,
                url_bi       = ?,
                reference_image  = ?                
            WHERE id = ?
        ");
        if ($stmt_update === false) {
            die("Error en la preparación de la consulta: " . htmlspecialchars($conn->error));
        }
            $stmt_update->bind_param(
              "sssiisissi", 
              $nombre,
              $fechaInicio,
              $fechaTermino,
              $estado,
              $tipo,
              $modalidad,
              $id_division,
              $url_bi,
              $reference_image,
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
            $formulario['url_bi']       = $url_bi;            
        } else {
            $error = "Error al actualizar el formulario: " . htmlspecialchars($stmt_update->error);
        }
        $stmt_update->close();
    }
}

// ------------------------------
// Procesar adición de nuevas entradas (formularioQuestion)
// (Código para CSV y entrada individual se mantiene sin cambios)
// ------------------------------
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
    $req_cols = ['codigo','usuario','material','valor_propuesto','fechapropuesta'];
    
    
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
    $idx_fechaPropuesta  = array_search('fechapropuesta', $header_norm);
    
    
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
    (pregunta, motivo, material, valor, valor_propuesto, fechaPropuesta, countVisita, observacion, id_formulario, id_local, id_usuario, estado)
    VALUES ('', '', ?, '', ?, ? ,0,'',?,?,?,0)
");
    
    $fila = 1; 
    $errores_csv = []; 
    $ok = 0;
    while(($data = fgetcsv($handle, 1000, ";")) !== false) {
    $fila++;
    $cod_local       = trim($data[$idx_codigo]);
    $usr_name        = trim($data[$idx_usuario]);
    $material        = trim($data[$idx_material]);
    $val_prop        = trim($data[$idx_valor_propuesto]);
    $fechaPropuesta  = trim($data[$idx_fechaPropuesta]);
    
$dateObj = DateTime::createFromFormat('y/m/d', $fechaPropuesta);
if ($dateObj) {
    $fechaPropuesta = $dateObj->format('Y-m-d') . ' 00:00:00';
} else {
    // Manejar error en conversión si el formato es incorrecto
}
    // Validar que ninguno de los campos requeridos esté vacío
    if ($cod_local == '' || $usr_name == '' || $material == '' || $val_prop == '' || $fechaPropuesta == '') {
        $errores_csv[] = "Fila $fila: Hay campos vacíos (asegúrate de que 'fechaPropuesta' esté completado).";
        continue;
    }
    if (!is_numeric($val_prop)) {
        $errores_csv[] = "Fila $fila: valor_propuesto debe ser numérico.";
        continue;
    }
    
    // Convertir la fecha propuesta al formato MySQL (ajusta según el formato de entrada)
    $fechaPropuesta = date("Y-m-d H:i:s", strtotime($fechaPropuesta));
    
    // Búsqueda del local y usuario (código existente)
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
    
    // Modificar el bind_param para incluir fechaPropuesta
    $stmt_insert_fq->bind_param("sssiii", $material, $val_prop, $fechaPropuesta, $formulario_id, $id_local, $id_usuario_csv);
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
    $id_usuario      = intval($_POST['id_usuario']);
    $codigo_local    = htmlspecialchars($_POST['codigo_local'], ENT_QUOTES, 'UTF-8');
    $material_ids    = $_POST['material_id'];
    $valores_propuestos = $_POST['valor_propuesto'];
    
    $empresa_form = intval($formulario['id_empresa']);
    $stmt_local = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_empresa = ?");
    $stmt_local->bind_param("si", $codigo_local, $empresa_form);
    $stmt_local->execute();
    $stmt_local->bind_result($id_local);
    $found = $stmt_local->fetch();
    $stmt_local->close();
    
    
    
    
    if (!$found || !$id_local) {
        $error = "No se encontró el local con código '{$codigo_local}' en esta empresa.";
    } elseif ($id_usuario > 0 && !empty($material_ids) && is_array($material_ids)) {

        foreach ($material_ids as $index => $material_id) {
            $material_id     = intval($material_id);
            $valor_propuesto = isset($valores_propuestos[$index]) ? trim($valores_propuestos[$index]) : '';

            // nombre del material
            $stmt_mat = $conn->prepare("SELECT nombre FROM material WHERE id = ?");
            $stmt_mat->bind_param("i", $material_id);
            $stmt_mat->execute();
            $stmt_mat->bind_result($material);
            $stmt_mat->fetch();
            $stmt_mat->close();

            if ($material === null || $material === '') {
                $error = "Material no encontrado.";
                break;
            }

            // ← FIX: ahora insertamos el ID numérico del local ($id_local), no el código
            $stmt_insert_fq = $conn->prepare("
                INSERT INTO formularioQuestion
                    (id_formulario, id_usuario, id_local, material, valor_propuesto, estado)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            if ($stmt_insert_fq === false) {
                $error = "Error en la preparación de la consulta: " . htmlspecialchars($conn->error);
                break;
            }
            $stmt_insert_fq->bind_param("iiiss", $formulario_id, $id_usuario, $id_local, $material, $valor_propuesto);

            if (!$stmt_insert_fq->execute()) {
                $error = "Error al agregar la entrada: " . htmlspecialchars($stmt_insert_fq->error);
                $stmt_insert_fq->close();
                break;
            }
            $stmt_insert_fq->close();
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
        $stmt_update_fq->bind_param("isssssisi", $id_usuario, $material, $valor, $valor_propuesto, $fechaVisita, $observacion, $estado, $pregunta_value, $fq_id);
    
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
// NUEVO: Agrupar y mostrar las gestiones existentes por LOCAL
// Se unen las implementaciones (formularioQuestion) y las respuestas de encuesta (form_question_responses unidas con form_questions)
// Se agrega el campo `pregunta` de formularioQuestion como estado de gestión.
// Además se implementan filtros y paginación para este apartado.
// ------------------------------

// Leer filtros para la agrupación (ahora con selects)
$filter_codigo  = isset($_GET['filter_codigo']) ? trim($_GET['filter_codigo']) : '';
$filter_usuario = isset($_GET['filter_usuario']) ? trim($_GET['filter_usuario']) : '';
$filter_estado  = isset($_GET['filter_estado']) ? trim($_GET['filter_estado']) : '';

// Parámetros para paginación en la agrupación
$limitGroup = isset($_GET['limit_group']) ? intval($_GET['limit_group']) : 10000;
if ($limitGroup <= 0) { $limitGroup = 10000; }
$pageGroup = isset($_GET['page_group']) ? intval($_GET['page_group']) : 1;
if ($pageGroup < 1) { $pageGroup = 1; }
$offsetGroup = ($pageGroup - 1) * $limitGroup;

// Obtener valores únicos para los filtros (Código, Usuario y Estado Gestión)
$distinct_codigo = [];
$query_codigo = "
   SELECT codigo FROM (
     SELECT l.codigo as codigo
     FROM formularioQuestion fq
     LEFT JOIN local l ON l.id = fq.id_local
     WHERE fq.id_formulario = ?
     UNION
     SELECT l.codigo as codigo
     FROM form_question_responses fqr
     INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
     LEFT JOIN local l ON l.id = fqr.id_local
     WHERE fq2.id_formulario = ?
   ) as t
   ORDER BY codigo ASC
";
$stmt_codigo = $conn->prepare($query_codigo);
$stmt_codigo->bind_param("ii", $formulario_id, $formulario_id);
$stmt_codigo->execute();
$result_codigo = $stmt_codigo->get_result();
while($row = $result_codigo->fetch_assoc()){
   $distinct_codigo[] = $row['codigo'];
}
$stmt_codigo->close();

$distinct_usuario = [];
$query_usuario = "
   SELECT usuario FROM (
     SELECT u.usuario as usuario
     FROM formularioQuestion fq
     LEFT JOIN usuario u ON u.id = fq.id_usuario
     WHERE fq.id_formulario = ?
     UNION
     SELECT u.usuario as usuario
     FROM form_question_responses fqr
     INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
     LEFT JOIN usuario u ON u.id = fqr.id_usuario
     WHERE fq2.id_formulario = ?
   ) as t
   ORDER BY usuario ASC
";
$stmt_usuario = $conn->prepare($query_usuario);
$stmt_usuario->bind_param("ii", $formulario_id, $formulario_id);
$stmt_usuario->execute();
$result_usuario = $stmt_usuario->get_result();
while($row = $result_usuario->fetch_assoc()){
   $distinct_usuario[] = $row['usuario'];
}
$stmt_usuario->close();

$distinct_estado = [];
$query_estado = "
   SELECT DISTINCT 
      CASE 
        WHEN TRIM(pregunta) = '' THEN 'No gestionado'
        ELSE pregunta
      END as estado
   FROM formularioQuestion
   WHERE id_formulario = ?
   ORDER BY estado ASC
";
$stmt_estado = $conn->prepare($query_estado);
$stmt_estado->bind_param("i", $formulario_id);
$stmt_estado->execute();
$result_estado = $stmt_estado->get_result();
while($row = $result_estado->fetch_assoc()){
   $distinct_estado[] = $row['estado'];
}
$stmt_estado->close();

// Construir la cláusula HAVING en base a los filtros
$having = array();
if (!empty($filter_codigo)) {
    $filter_codigo_esc = $conn->real_escape_string($filter_codigo);
    $having[] = "codigo = '$filter_codigo_esc'";
}
if (!empty($filter_usuario)) {
    $filter_usuario_esc = $conn->real_escape_string($filter_usuario);
    // Usamos FIND_IN_SET para buscar en la lista de usuarios concatenados
    $having[] = "FIND_IN_SET('$filter_usuario_esc', usuarios) > 0";
}
if (!empty($filter_estado)) {
    if (strtolower($filter_estado) === 'no gestionado') {
        $having[] = "(estado_gestion = '' OR estado_gestion IS NULL)";
    } else {
        $filter_estado_esc = $conn->real_escape_string($filter_estado);
        $having[] = "estado_gestion = '$filter_estado_esc'";
    }
}

// Armar la query de agrupación SIN LIMIT/OFFSET para el conteo total
$sql_group_without_limit = "
    SELECT 
        id_local, 
        codigo, 
        nombre_local, 
        direccion_local,
        SUM(total_entries) AS total_entries,
        GROUP_CONCAT(DISTINCT usuarios SEPARATOR ', ') AS usuarios,
        MAX(estado_gestion) AS estado_gestion
    FROM (
        SELECT 
           fq.id_local,
           l.codigo,
           l.nombre AS nombre_local,
           l.direccion AS direccion_local,
           COUNT(*) AS total_entries,
           SUM(CASE WHEN fq.estado = 1 THEN 1 ELSE 0 END) AS completadas,
           SUM(CASE WHEN fq.estado = 0 THEN 1 ELSE 0 END) AS en_proceso,
           SUM(CASE WHEN fq.estado = 2 THEN 1 ELSE 0 END) AS canceladas,
           GROUP_CONCAT(DISTINCT u.usuario SEPARATOR ', ') AS usuarios,
           MIN(fq.pregunta) AS estado_gestion
        FROM formularioQuestion fq
        LEFT JOIN local l ON l.id = fq.id_local
        LEFT JOIN usuario u ON u.id = fq.id_usuario
        WHERE fq.id_formulario = ?
        GROUP BY fq.id_local, l.codigo, l.nombre, l.direccion

        UNION ALL

        SELECT 
           fqr.id_local,
           l.codigo,
           l.nombre AS nombre_local,
           l.direccion AS direccion_local,
           COUNT(*) AS total_entries,
           0 AS completadas,
           0 AS en_proceso,
           0 AS canceladas,
           GROUP_CONCAT(DISTINCT u.usuario SEPARATOR ', ') AS usuarios,
           '' AS estado_gestion
        FROM form_question_responses fqr
        INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
        LEFT JOIN local l ON l.id = fqr.id_local
        LEFT JOIN usuario u ON u.id = fqr.id_usuario
        WHERE fq2.id_formulario = ?
        GROUP BY fqr.id_local, l.codigo, l.nombre, l.direccion
    ) AS union_table
    GROUP BY id_local, codigo, nombre_local, direccion_local
";
if (!empty($having)) {
    $sql_group_without_limit .= " HAVING " . implode(" AND ", $having);
}

// Obtener el total de registros agrupados para la paginación
$stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM ($sql_group_without_limit) as t");
if (!$stmt_count) {
    die("Error en la preparación de la consulta de conteo: " . htmlspecialchars($conn->error));
}
$stmt_count->bind_param("ii", $formulario_id, $formulario_id);
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$row_count = $result_count->fetch_assoc();
$totalRows = intval($row_count['total']);
$stmt_count->close();
$totalPages = ceil($totalRows / $limitGroup);

// Armar la query de agrupación CON LIMIT y OFFSET
// Reemplaza tu SQL actual (con UNION ALL) por algo así:

$sql_group = "
SELECT
    l.id                      AS id_local,
    l.codigo,
    l.nombre                  AS nombre_local,
    l.direccion               AS direccion_local,
    COALESCE(q1.total_fq, 0)  AS entradas_fq,
    COALESCE(q1.completadas, 0) AS completadas,
    COALESCE(q1.en_proceso, 0)  AS en_proceso,
    COALESCE(q1.canceladas, 0)  AS canceladas,
    COALESCE(q2.total_resps, 0) AS respuestas_encuesta,
    CONCAT_WS(', ',
        COALESCE(q1.usuarios, ''), 
        COALESCE(q2.usuarios, '')
    ) AS usuarios,
    COALESCE(q1.estado_gestion, '') AS estado_gestion
FROM (
    -- 1) obtenemos los locales únicos que aparecen en implementaciones o encuestas
    SELECT id_local FROM formularioQuestion WHERE id_formulario = ?
    UNION
    SELECT id_local
    FROM form_question_responses fqr
    INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
    WHERE fq2.id_formulario = ?
) AS all_locals

JOIN local l ON l.id = all_locals.id_local

-- 2) agregación de datos de implementaciones (formularioQuestion) por local
LEFT JOIN (
    SELECT
      fq.id_local,
      COUNT(*) AS total_fq,
      SUM(fq.estado = 1) AS completadas,
      SUM(fq.estado = 0) AS en_proceso,
      SUM(fq.estado = 2) AS canceladas,
      GROUP_CONCAT(DISTINCT u.usuario SEPARATOR ', ') AS usuarios,
      MIN(fq.pregunta) AS estado_gestion
    FROM formularioQuestion fq
    LEFT JOIN usuario u ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
    GROUP BY fq.id_local
) AS q1 ON q1.id_local = all_locals.id_local

-- 3) agregación de datos de encuesta (form_question_responses) por local
LEFT JOIN (
    SELECT
      fqr.id_local,
      COUNT(*) AS total_resps,
      GROUP_CONCAT(DISTINCT u.usuario SEPARATOR ', ') AS usuarios
    FROM form_question_responses fqr
    INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
    LEFT JOIN usuario u ON u.id = fqr.id_usuario
    WHERE fq2.id_formulario = ?
    GROUP BY fqr.id_local
) AS q2 ON q2.id_local = all_locals.id_local

-- 4) Paginación (limit / offset)
LIMIT ? OFFSET ?
";

$stmt_group = $conn->prepare($sql_group);
$stmt_group->bind_param(
  "iiiiii", 
   $formulario_id,  // for UNION-part 1
   $formulario_id,  // for UNION-part 2
   $formulario_id,  // for q1
   $formulario_id,  // for q2
   $limitGroup,
   $offsetGroup
);
$stmt_group->execute();
$result_group = $stmt_group->get_result();

$gestiones_por_local = $result_group->fetch_all(MYSQLI_ASSOC);
$stmt_group->close();

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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.2.2/css/buttons.bootstrap.min.css">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"> 
    <style>
        .material-row { margin-bottom: 10px; }
        .remove-material-btn { margin-top: 32px; }
        thead input {width: 100%;padding: 3px;box-sizing: border-box;}
        .pagination>li>a, .pagination>li>span {
        position: relative;
        float: left;
        padding: 6px 12px;
        margin-left: -1px;
        line-height: 1.42857143;
        color: #337ab7;
        text-decoration: none;
        background-color: #fff;
        border: 1px solid #ddd;
    }
    .btn-default {
        color: #333;
        background-color: #fff;
        border-color: #ccc;
    }
    
    .dt-buttons { 
      float: left; 
      margin-right: 10px; 
    }
    .dataTables_filter { 
      float: right; 
    }
    .btn-default{
    background-color: #f8f9fa;
    border-color: #ddd;
    color: #444;
    }  
    @media (min-width: 992px) {
    .modal-lg, .modal-xl {
        max-width: 90%!important;
    }
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
                Agregar/Editar Entradas
            </a>
        </li>
        <li class="nav-item" hidden>
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
            <a class="btn btn-outline-primary"
               href="gestionar_preguntas.php?id_formulario=<?= (int)$formulario_id ?>">
               Gestionar preguntas de encuesta
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
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" enctype="multipart/form-data" class="mt-4">
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
                        
                        <option value="3" <?php if ($formulario['tipo'] == '3') echo 'selected'; ?>>Actividad IPT</option>
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
                  <label for="modalidad">Modalidad de la encuesta:</label>
                  <select id="modalidad" name="modalidad" class="form-control">
                    <option value="">Seleccione modalidad</option>
                <option value="implementacion_auditoria"
                   <?php if($formulario['modalidad'] === 'implementacion_auditoria') echo 'selected'; ?>>
                   Implementación + Auditoría
                </option>
                <option value="solo_implementacion"
                   <?php if($formulario['modalidad'] === 'solo_implementacion') echo 'selected'; ?>>
                   Solo Implementación
                </option>
                <option value="solo_auditoria"
                   <?php if($formulario['modalidad'] === 'solo_auditoria') echo 'selected'; ?>>
                   Solo Auditoría
                </option>
                <option value="retiro"
                   <?php if($formulario['modalidad'] === 'retiro') echo 'selected'; ?>>
                   Retiro
                </option>
                    <!-- Añade aquí las que necesites -->
                  </select>
                </div>
                
                <div class="form-group">
                    <label for="url_bi">URL Informe BI:</label>
                    <input type="text" id="url_bi" name="url_bi" class="form-control" value="<?php echo htmlspecialchars($formulario['url_bi'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                  <label for="reference_image">Imagen de Referencia:</label>
                  <input type="file" id="reference_image" name="reference_image" class="form-control">
                  <?php if (!empty($formulario['reference_image'])): ?>
                    <small>Actual: <a href="<?php echo htmlspecialchars($formulario['reference_image'], ENT_QUOTES); ?>" target="_blank">ver imagen</a></small>
                  <?php endif; ?>
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
                    <label for="codigo_local">Código Local:</label>
                    <input type="text" id="codigo_local" name="codigo_local" class="form-control" required>
                </div>
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
                                    <div class="input-group-append" hidden>
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
            
            <!-- Formulario para subir CSV masivo -->
            <h3 class="mt-5">Subir IPT Masivo desde CSV</h3>
            <form action="editar_formulario.php?id=<?php echo $formulario_id; ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_fq_csv">
                <input type="hidden" name="active_tab" value="agregar-entradas">
                <div class="form-group">
                    <label for="csvFile">Archivo CSV:</label>
                    <input type="file" id="csvFile" name="csvFile" class="form-control-file" accept=".csv" required>
                    <small class="form-text text-muted">
    Debe contener las columnas: <strong>codigo</strong>, <strong>usuario</strong>, <strong>material</strong>, <strong>valor_propuesto</strong>, <strong>fechaPropuesta</strong>.
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
            
            
            <!-- NUEVO: Listado de Gestiones Existentes AGRUPADAS por Local -->
            <h3 class="mt-5">Gestiones Agrupadas por Local</h3>
            
<table id="example" class="table table-striped table-bordered" cellspacing="0" width="100%">
	<thead>
		<tr>
                  <th>Código Local</th>
                  <th>Local</th>
                  <th>Dirección</th>
                  <th>Usuarios</th>
                  <th>Estado Gestión</th>
                  <th>Acción</th>
		</tr>
	</thead>
	<tbody>
                  <?php if (!empty($gestiones_por_local)): ?>
                    <?php foreach ($gestiones_por_local as $g): ?>
                      <tr>
                        <td><?= htmlspecialchars((string)($g['codigo']          ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($g['nombre_local']    ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($g['direccion_local'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($g['usuarios']         ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                          <?= empty($g['estado_gestion'])
                             ? 'No gestionado'
                             : htmlspecialchars((string)$g['estado_gestion'], ENT_QUOTES, 'UTF-8')
                          ?>
                        </td>
                        <td>
                          <button
                            class="btn btn-sm btn-info ver-gestiones"
                            data-local-id="<?= htmlspecialchars((string)($g['id_local'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          >Ver Detalles</button>
                            <button
                            class="btn btn-sm btn-primary ver-visitas"
                            data-formulario-id="<?= $formulario_id ?>"
                            data-local-id="<?= htmlspecialchars($g['id_local'], ENT_QUOTES, 'UTF-8') ?>"
                          >Ver Visitas</button>
                          
                          <button
                            class="btn btn-sm btn-warning editar-gestion"
                            data-local-id="<?= htmlspecialchars((string)($g['id_local'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          >Editar Gestión</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="8" class="text-center">No hay gestiones registradas.</td>
                    </tr>
                  <?php endif; ?>            
	</tbody>
  <tfoot>
    <tr>
      <th>Código Local</th>
      <th>Local</th>
      <th>Dirección</th>
      <th>Usuarios</th>
      <th>Estado Gestión</th>
      <th>Acción</th>
    </tr>
  </tfoot>
</table>

        </div>
        
        <!-- Modal para ver gestiones agrupadas por local -->
        <div class="modal fade" id="gestionesModal" tabindex="-1" role="dialog" aria-labelledby="gestionesModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="gestionesModalContent">
              <!-- Detalle de gestiones cargado vía AJAX -->
            </div>
          </div>
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

<script src="/visibility2/portal/dist/js/jquery.dataTables.min.js"></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js'></script>
<script src='https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js'></script>

<script>
    $(document).ready(function () {
	//Only needed for the filename of export files.
	//Normally set in the title tag of your page.
	document.title = "Simple DataTable";
	// Create search inputs in footer
	$("#example tfoot th").each(function () {
		var title = $(this).text();
		$(this).html('<input type="text" placeholder="Buscar ' + title + '" />');
	});
	// DataTable initialisation
	var table = $("#example").DataTable({
		dom: '<"dt-buttons"Bf><"clear">lirtp',
		paging: true,
		autoWidth: true,
		buttons: [
			"colvis",
			"copyHtml5",
			"csvHtml5",
			"excelHtml5",
			"pdfHtml5",
			"print"
		],
		initComplete: function (settings, json) {
			var footer = $("#example tfoot tr");
			$("#example thead").append(footer);
		}
	});

	// Apply the search
	$("#example thead").on("keyup", "input", function () {
		table.column($(this).parent().index())
		.search(this.value)
		.draw();
	});
});
</script>

<script>
$(document).ready(function(){
  // Cargar modal de edición de entrada
  $('.btn-editar').on('click', function(){
    var fqId = $(this).data('fq-id');
    $.ajax({
      url: 'ajax_editar_fq.php',
      method: 'GET',
      data: { id: fqId, formulario_id: <?php echo $formulario_id; ?> },
      success: function(data) {
        $('#editarFQModalContent').html(data);
        $('#editarFQModal').modal('show');
      },
      error: function() {
        alert('Error al cargar el formulario de edición.');
      }
    });
  });



$(function(){
  const params = new URLSearchParams(window.location.search);

  if (params.get('open_modal') === 'gestiones' && params.get('local_id')) {
    const localId   = params.get('local_id');
    const userId    = params.get('user_id') || '';
    const tab       = params.get('tab') || 'impl';
    const pageImpl  = parseInt(params.get('page_impl')||'1',10);
    const pageResp  = parseInt(params.get('page_resp')||'1',10);

    // abrir el modal nuevamente
    $.get('ajax_ver_gestiones.php', {
      formulario_id: <?= json_encode($formulario_id) ?>,
      local_id:      localId,
      user_id:       userId,
      page_impl:     pageImpl,
      page_resp:     pageResp,
      tab:           tab
    })
    .done(html => {
      $('#gestionesModalContent').html(html);
      $('#gestionesModal').modal('show');
    });

    // limpiar parámetros para no reabrir en futuros reloads manuales
    params.delete('open_modal');
    params.delete('local_id');
    params.delete('user_id');
    params.delete('tab');
    params.delete('page_impl');
    params.delete('page_resp');
    history.replaceState({}, '', location.pathname + '?' + params.toString());
  }
});

const formularioId = <?= json_encode($formulario_id) ?>;

$(document).on('click', '.ver-gestiones', function() {
  const localId = $(this).data('local-id');
  $.get('ajax_ver_gestiones.php', { formulario_id: formularioId, local_id: localId })
    .done(html => {
      $('#gestionesModalContent').html(html);
      $('#gestionesModal').modal('show');
    })
    .fail((xhr, status, err) => {
      console.error('AJAX Error:', status, err);
      alert('Error al cargar las gestiones.');
    });
});

$(document).on('click', '.editar-gestion', function() {
  const localId = $(this).data('local-id');
  $.get('ajax_editar_gestion.php', { formulario_id: formularioId, local_id: localId })
    .done(html => {
      $('#editarGestionModalContent').html(html);
      $('#editarGestionModal').modal('show');
    })
    .fail(() => {
      alert('Error al cargar el formulario de edición de gestión.');
    });
});
  
  // Interceptar el submit del formulario de edición de gestión para enviarlo por AJAX
$(document).on('submit', '#editarGestionForm', function(e) {
  e.preventDefault();
  const $form = $(this);
  const url = 'ajax_editar_gestion.php'
            + '?formulario_id=' + encodeURIComponent($form.find('input[name="formulario_id"]').val())
            + '&local_id='      + encodeURIComponent($form.find('input[name="local_id"]').val());

  $.post(url, $form.serialize(), function(response) {
    const $resp  = $('<div>').html(response);
    const $alert = $resp.find('.alert').first();

    if ($alert.length) {
      const $modalBody = $('#editarGestionModal .modal-body');
      $modalBody.find('.alert').remove();
      $modalBody.prepend($alert.hide().fadeIn(150));

      // : si fue éxito, recarga la página quedando en la pestaña "agregar-entradas"
      if ($resp.find('.alert-success').length) {
        setTimeout(() => {
          const url = new URL(window.location.href);
          url.searchParams.set('active_tab', 'agregar-entradas');
          window.location.href = url.toString();
        }, 600);
      }
    }

    const $newTable = $resp.find('table');
    if ($newTable.length) {
      $('#editarGestionModal .modal-body table').replaceWith($newTable);
    }
  })
  .fail(function() {
    alert('Error al guardar la actualización de gestión.');
  });
});
  // Interceptar el submit del formulario de reasignación de local para enviarlo por AJAX
$(document).on('submit', '#reasignarLocalForm', function(e) {
  e.preventDefault();
  var $form = $(this);
  var url = 'ajax_editar_gestion.php?formulario_id=<?php echo $formulario_id; ?>&local_id=' + $('input[name="local_id"]').val();

  $.ajax({
    url: url,
    method: 'POST',
    data: $form.serialize(),
    success: function(response) {
      $('#editarGestionModalContent').html(response);

      // si hay éxito, recarga manteniendo la pestaña
      if (response.indexOf('alert-success') !== -1) {
        setTimeout(() => {
          const url = new URL(window.location.href);
          url.searchParams.set('active_tab', 'agregar-entradas');
          window.location.href = url.toString();
        }, 600);
      }
    },
    error: function() {
      alert('Error al reasignar el local.');
    }
  });
});
})



// Función para la pestaña "Agregar Pregunta" (formulario individual)
function toggleQuestionTypeSingle() {
    const tipoSelect = document.getElementById('id_question_type');
    const selectedTipo = parseInt(tipoSelect.value, 10);
    const optionsContainer = document.getElementById('questionOptionsContainer');
    const valuedContainer = document.getElementById('valuedContainer');
    
    if ([1, 2, 3].includes(selectedTipo)) {
        optionsContainer.style.display = 'block';
    } else {
        optionsContainer.style.display = 'none';
    }
    
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


const formularioId = <?= json_encode($formulario_id) ?>;

$(document).on('click', '.ver-visitas', function() {
  const localId = $(this).data('local-id');
  // Llamamos a un nuevo endpoint que devolverá el HTML del histórico de visitas
  $.get('ajax_ver_visitas.php', {
    formulario_id: formularioId,
    local_id:      localId
  })
  .done(html => {
    $('#visitasModalContent').html(html);
    $('#visitasModal').modal('show');
  })
  .fail((xhr, status, err) => {
    console.error('AJAX Error:', status, err);
    alert('Error al cargar el histórico de visitas.');
  });
});

</script>

<!-- Modal único para edición de entrada -->
<div class="modal fade" id="editarFQModal" tabindex="-1" role="dialog" aria-labelledby="editarFQModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content" id="editarFQModalContent">
      <!-- Contenido cargado vía AJAX -->
    </div>
  </div>
</div>

<!-- Modal para ver gestiones agrupadas por local -->
<div class="modal fade" id="gestionesModal" tabindex="-1" role="dialog" aria-labelledby="gestionesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" id="gestionesModalContent">
      <!-- Detalle de gestiones cargado vía AJAX -->
      
    </div>
  </div>
</div>

<div class="modal fade" id="editarGestionModal" tabindex="-1" role="dialog" aria-labelledby="editarGestionModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">  <!-- Aquí puedes usar modal-lg o modal-xl -->
    <div class="modal-content" id="editarGestionModalContent">
      <!-- Contenido cargado vía AJAX -->
    </div>
  </div>
</div>

<div class="modal fade" id="visitasModal" tabindex="-1" role="dialog" aria-labelledby="visitasModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content" id="visitasModalContent">
      <!-- Aquí cargaremos vía AJAX el listado completo de visitas -->
    </div>
  </div>
</div>

</body>
</html>
<?php
$conn->close();
?>

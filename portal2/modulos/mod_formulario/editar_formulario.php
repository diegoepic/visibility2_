<?php
// editar_formulario.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function parseFechaPropuestaCsv(string $fecha): ?string {
    $fecha = trim($fecha);
    if ($fecha === '') {
        return null;
    }

    $formatos = ['y/m/d', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'];

    foreach ($formatos as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $fecha);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($fecha);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

// -----------------------------------------------------------------------------
// Parámetros base
// -----------------------------------------------------------------------------
$formulario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($formulario_id <= 0) {
    exit("ID de formulario no proporcionado.");
}

$active_tab = 'editar-formulario';
if (isset($_GET['active_tab'])) {
    $active_tab = $_GET['active_tab'];
} elseif (isset($_POST['active_tab'])) {
    $active_tab = $_POST['active_tab'];
}

// -----------------------------------------------------------------------------
// Mapa de preguntas existentes importadas desde set
// -----------------------------------------------------------------------------
$qry_existing = "SELECT id, id_question_set_question FROM form_questions WHERE id_formulario = ?";
$stmt_existing_map = $conn->prepare($qry_existing);
$stmt_existing_map->bind_param("i", $formulario_id);
$stmt_existing_map->execute();
$res_existing = $stmt_existing_map->get_result();

$existing_questions = [];
while ($row = $res_existing->fetch_assoc()) {
    if (!empty($row['id_question_set_question'])) {
        $existing_questions[(int)$row['id_question_set_question']] = (int)$row['id'];
    }
}
$stmt_existing_map->close();

// -----------------------------------------------------------------------------
// IMPORTAR / SINCRONIZAR SET DE PREGUNTAS
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_set') {
    $set_id = (int)($_POST['selected_set_id'] ?? 0);

    if ($set_id <= 0) {
        $error = "Selecciona un set de preguntas válido.";
    } else {
        $preguntas_set = getQuestionsFromSet($set_id);

        if (empty($preguntas_set)) {
            $error = "El set seleccionado no tiene preguntas.";
        } else {
            $conn->begin_transaction();

            try {
                $existingFormQuestions = [];
                $sql = "
                    SELECT id, id_question_set_question
                    FROM form_questions
                    WHERE id_formulario = ?
                      AND id_question_set_question IS NOT NULL
                ";
                $stmt_existing = $conn->prepare($sql);
                if (!$stmt_existing) {
                    throw new Exception("Error preparando lectura de preguntas existentes: " . $conn->error);
                }

                $stmt_existing->bind_param("i", $formulario_id);
                $stmt_existing->execute();
                $result_existing = $stmt_existing->get_result();

                while ($row = $result_existing->fetch_assoc()) {
                    $existingFormQuestions[(int)$row['id_question_set_question']] = (int)$row['id'];
                }
                $stmt_existing->close();

                $map_questions = [];
                $map_options   = [];

                $sql_ins_q = "
                    INSERT INTO form_questions
                    (
                        id_formulario,
                        question_text,
                        id_question_type,
                        sort_order,
                        is_required,
                        id_question_set_question,
                        id_dependency_option,
                        is_valued
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt_ins_q = $conn->prepare($sql_ins_q);
                if (!$stmt_ins_q) {
                    throw new Exception("Error preparando inserción de pregunta: " . $conn->error);
                }

                $sql_upd_q = "
                    UPDATE form_questions
                    SET question_text = ?,
                        id_question_type = ?,
                        sort_order = ?,
                        is_required = ?,
                        is_valued = ?
                    WHERE id = ?
                ";
                $stmt_upd_q = $conn->prepare($sql_upd_q);
                if (!$stmt_upd_q) {
                    throw new Exception("Error preparando actualización de pregunta: " . $conn->error);
                }

                $sql_ins_opt = "
                    INSERT INTO form_question_options
                    (
                        id_form_question,
                        option_text,
                        sort_order,
                        reference_image,
                        id_question_set_option
                    )
                    VALUES (?, ?, ?, ?, ?)
                ";
                $stmt_ins_opt = $conn->prepare($sql_ins_opt);
                if (!$stmt_ins_opt) {
                    throw new Exception("Error preparando inserción de opción: " . $conn->error);
                }

                $sql_upd_opt = "
                    UPDATE form_question_options
                    SET option_text = ?,
                        sort_order = ?,
                        reference_image = ?
                    WHERE id = ?
                ";
                $stmt_upd_opt = $conn->prepare($sql_upd_opt);
                if (!$stmt_upd_opt) {
                    throw new Exception("Error preparando actualización de opción: " . $conn->error);
                }

                foreach ($preguntas_set as $pset) {
                    $original_q_id    = (int)$pset['id'];
                    $question_text    = $pset['question_text'];
                    $id_question_type = (int)$pset['id_question_type'];
                    $is_required      = (int)$pset['is_required'];
                    $is_valued        = isset($pset['is_valued']) ? (int)$pset['is_valued'] : 0;
                    $id_dependency_option = null;
                    $current_sort     = (int)$pset['sort_order'];

                    if (isset($existingFormQuestions[$original_q_id])) {
                        $form_q_id = $existingFormQuestions[$original_q_id];
                        $stmt_upd_q->bind_param(
                            "siiiii",
                            $question_text,
                            $id_question_type,
                            $current_sort,
                            $is_required,
                            $is_valued,
                            $form_q_id
                        );
                        $stmt_upd_q->execute();
                    } else {
                        $stmt_ins_q->bind_param(
                            "isiiiiii",
                            $formulario_id,
                            $question_text,
                            $id_question_type,
                            $current_sort,
                            $is_required,
                            $original_q_id,
                            $id_dependency_option,
                            $is_valued
                        );
                        $stmt_ins_q->execute();
                        $form_q_id = $conn->insert_id;
                    }

                    $map_questions[$original_q_id] = $form_q_id;

                    $existingOpts = [];
                    $sql_get_opts = "
                        SELECT id, id_question_set_option
                        FROM form_question_options
                        WHERE id_form_question = ?
                    ";
                    $stmt_get_opts = $conn->prepare($sql_get_opts);
                    if (!$stmt_get_opts) {
                        throw new Exception("Error preparando lectura de opciones existentes: " . $conn->error);
                    }

                    $stmt_get_opts->bind_param("i", $form_q_id);
                    $stmt_get_opts->execute();
                    $result_opts = $stmt_get_opts->get_result();

                    while ($row = $result_opts->fetch_assoc()) {
                        if (!empty($row['id_question_set_option'])) {
                            $existingOpts[(int)$row['id_question_set_option']] = (int)$row['id'];
                        }
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
                                $form_opt_id = $existingOpts[$original_opt_id];
                                $stmt_upd_opt->bind_param(
                                    "sisi",
                                    $option_text,
                                    $sort_order,
                                    $reference_image,
                                    $form_opt_id
                                );
                                $stmt_upd_opt->execute();
                            } else {
                                $stmt_ins_opt->bind_param(
                                    "isisi",
                                    $form_q_id,
                                    $option_text,
                                    $sort_order,
                                    $reference_image,
                                    $original_opt_id
                                );
                                $stmt_ins_opt->execute();
                                $form_opt_id = $conn->insert_id;
                            }

                            $map_options[$original_q_id][$original_opt_id] = $form_opt_id;
                        }

                        foreach ($existingOpts as $orig_opt_id => $form_opt_id) {
                            if (!in_array($orig_opt_id, $setOptionIds, true)) {
                                $stmtDeleteOpt = $conn->prepare("DELETE FROM form_question_options WHERE id = ?");
                                $stmtDeleteOpt->bind_param("i", $form_opt_id);
                                $stmtDeleteOpt->execute();
                                $stmtDeleteOpt->close();
                            }
                        }
                    } else {
                        if (!empty($existingOpts)) {
                            $stmtDeleteAllOpts = $conn->prepare("DELETE FROM form_question_options WHERE id_form_question = ?");
                            $stmtDeleteAllOpts->bind_param("i", $form_q_id);
                            $stmtDeleteAllOpts->execute();
                            $stmtDeleteAllOpts->close();
                        }
                    }
                }

                $stmt_ins_q->close();
                $stmt_upd_q->close();
                $stmt_ins_opt->close();
                $stmt_upd_opt->close();

                $set_q_ids = array_map(function ($pset) {
                    return (int)$pset['id'];
                }, $preguntas_set);

                if (!empty($set_q_ids)) {
                    $set_q_ids_str = implode(",", $set_q_ids);

                    $delSql = "
                        DELETE fqo
                        FROM form_question_options fqo
                        INNER JOIN form_questions fq ON fq.id = fqo.id_form_question
                        WHERE fq.id_formulario = ?
                          AND fq.id_question_set_question IS NOT NULL
                          AND fq.id_question_set_question NOT IN ($set_q_ids_str)
                    ";
                    $stDel = $conn->prepare($delSql);
                    if (!$stDel) {
                        throw new Exception("Error preparando borrado de opciones huérfanas: " . $conn->error);
                    }
                    $stDel->bind_param("i", $formulario_id);
                    $stDel->execute();
                    $stDel->close();

                    $sqlDeleteQuestions = "
                        DELETE FROM form_questions
                        WHERE id_formulario = ?
                          AND id_question_set_question IS NOT NULL
                          AND id_question_set_question NOT IN ($set_q_ids_str)
                    ";
                    $stDelQuestions = $conn->prepare($sqlDeleteQuestions);
                    if (!$stDelQuestions) {
                        throw new Exception("Error preparando borrado de preguntas fuera del set: " . $conn->error);
                    }
                    $stDelQuestions->bind_param("i", $formulario_id);
                    $stDelQuestions->execute();
                    $stDelQuestions->close();
                }

                $optToParent = [];
                $stmtOpt = $conn->prepare("
                    SELECT o.id, o.id_question_set_question
                    FROM question_set_options o
                    INNER JOIN question_set_questions q ON q.id = o.id_question_set_question
                    WHERE q.id_question_set = ?
                ");
                if (!$stmtOpt) {
                    throw new Exception("Error preparando mapa opción->padre: " . $conn->error);
                }

                $stmtOpt->bind_param("i", $set_id);
                $stmtOpt->execute();
                $resOpt = $stmtOpt->get_result();

                while ($row = $resOpt->fetch_assoc()) {
                    $optToParent[(int)$row['id']] = (int)$row['id_question_set_question'];
                }
                $stmtOpt->close();

                foreach ($preguntas_set as $pset) {
                    $set_q_id    = (int)$pset['id'];
                    $form_q_id   = (int)$map_questions[$set_q_id];
                    $dep_set_opt = $pset['id_dependency_option'];

                    if (empty($dep_set_opt)) {
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = NULL WHERE id = ?");
                        if (!$st) {
                            throw new Exception("Error limpiando dependencia: " . $conn->error);
                        }
                        $st->bind_param("i", $form_q_id);
                        $st->execute();
                        $st->close();
                        continue;
                    }

                    $dep_set_opt   = (int)$dep_set_opt;
                    $parent_set_q  = $optToParent[$dep_set_opt] ?? null;
                    $new_dep_form_opt = null;

                    if ($parent_set_q && isset($map_questions[$parent_set_q])) {
                        $new_dep_form_opt = $map_options[$parent_set_q][$dep_set_opt] ?? null;
                    }

                    if ($new_dep_form_opt === null) {
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = NULL WHERE id = ?");
                        if (!$st) {
                            throw new Exception("Error actualizando dependencia (NULL): " . $conn->error);
                        }
                        $st->bind_param("i", $form_q_id);
                    } else {
                        $st = $conn->prepare("UPDATE form_questions SET id_dependency_option = ? WHERE id = ?");
                        if (!$st) {
                            throw new Exception("Error actualizando dependencia: " . $conn->error);
                        }
                        $st->bind_param("ii", $new_dep_form_opt, $form_q_id);
                    }

                    $st->execute();
                    $st->close();
                }

                $conn->commit();
                header("Location: editar_formulario.php?id=$formulario_id&active_tab=import-set");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Error al importar el set: " . $e->getMessage();
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Obtener formulario
// -----------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT
        id,
        nombre,
        fechaInicio,
        fechaTermino,
        estado,
        tipo,
        modalidad,
        id_division,
        id_empresa,
        url_bi,
        reference_image
    FROM formulario
    WHERE id = ?
");
if ($stmt === false) {
    die("Error en la preparación de la consulta: " . h($conn->error));
}
$stmt->bind_param("i", $formulario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    exit("Formulario no encontrado.");
}

$formulario = $result->fetch_assoc();
$stmt->close();

$form_empresa_id = (int)$formulario['id_empresa'];

// -----------------------------------------------------------------------------
// Empresa del usuario
// -----------------------------------------------------------------------------
$empresa_id = (int)$_SESSION['empresa_id'];
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
$stmt_empresa->bind_param("i", $empresa_id);
$stmt_empresa->execute();
$stmt_empresa->bind_result($nombre_empresa);
$stmt_empresa->fetch();
$stmt_empresa->close();

$es_mentecreativa = strtolower(trim((string)$nombre_empresa)) === 'mentecreativa';

// -----------------------------------------------------------------------------
// Divisiones disponibles
// -----------------------------------------------------------------------------
if ($es_mentecreativa) {
    $stmt_divisiones = $conn->prepare("SELECT id, nombre FROM division_empresa ORDER BY nombre ASC");
} else {
    $stmt_divisiones = $conn->prepare("SELECT id, nombre FROM division_empresa WHERE id_empresa = ? ORDER BY nombre ASC");
    $stmt_divisiones->bind_param("i", $empresa_id);
}
$stmt_divisiones->execute();
$result_divisiones = $stmt_divisiones->get_result();
$divisiones = $result_divisiones->fetch_all(MYSQLI_ASSOC);
$stmt_divisiones->close();

// -----------------------------------------------------------------------------
// ACTUALIZAR FORMULARIO
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_formulario') {
    $nombre       = trim($_POST['nombre'] ?? '');
    $fechaInicio  = $_POST['fechaInicio'] ?? '';
    $fechaTermino = $_POST['fechaTermino'] ?? '';
    $estado       = (int)($_POST['estado'] ?? 0);
    $tipo         = (int)($_POST['tipo'] ?? 0);
    $id_division  = (int)($_POST['id_division'] ?? 0);
    $modalidad    = trim($_POST['modalidad'] ?? '');
    $url_bi       = trim($_POST['url_bi'] ?? '');

    if ($nombre === '' || $fechaInicio === '' || $fechaTermino === '' || $estado <= 0 || $tipo <= 0) {
        $error = "Por favor, complete todos los campos obligatorios.";
    } else {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/reference_images/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $error = "No se pudo crear el directorio de imágenes.";
        } else {
            $reference_image = $formulario['reference_image'];

            if (isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK) {
                $allowed = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'gif'  => 'image/gif'
                ];

                $fileTmp  = $_FILES['reference_image']['tmp_name'];
                $fileName = $_FILES['reference_image']['name'];
                $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $fileType = mime_content_type($fileTmp);

                if (isset($allowed[$fileExt]) && $allowed[$fileExt] === $fileType) {
                    $newName = uniqid('refimg_', true) . '.' . $fileExt;
                    $dest    = $uploadDir . $newName;

                    if (move_uploaded_file($fileTmp, $dest)) {
                        @chmod($dest, 0644);
                        $reference_image = '/visibility2/portal/uploads/reference_images/' . $newName;
                    } else {
                        $error = "No se pudo mover la imagen de referencia.";
                    }
                } else {
                    $error = "Formato de imagen de referencia no permitido.";
                }
            }
        }
    }

    if (!isset($error)) {
        $stmt_update = $conn->prepare("
            UPDATE formulario
            SET nombre = ?,
                fechaInicio = ?,
                fechaTermino = ?,
                estado = ?,
                tipo = ?,
                modalidad = ?,
                id_division = ?,
                url_bi = ?,
                reference_image = ?
            WHERE id = ?
        ");
        if ($stmt_update === false) {
            die("Error en la preparación de la consulta: " . h($conn->error));
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
            $formulario['nombre'] = $nombre;
            $formulario['fechaInicio'] = $fechaInicio;
            $formulario['fechaTermino'] = $fechaTermino;
            $formulario['estado'] = $estado;
            $formulario['tipo'] = $tipo;
            $formulario['modalidad'] = $modalidad;
            $formulario['id_division'] = $id_division;
            $formulario['url_bi'] = $url_bi;
            $formulario['reference_image'] = $reference_image;
        } else {
            $error = "Error al actualizar el formulario: " . h($stmt_update->error);
        }
        $stmt_update->close();
    }
}

// -----------------------------------------------------------------------------
// CARGA MASIVA CSV A formularioQuestion
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fq_csv') {
    $fileTmpPath = $_FILES['csvFile']['tmp_name'] ?? '';
    $fileName    = $_FILES['csvFile']['name'] ?? '';
    $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExt !== 'csv') {
        $_SESSION['error_formulario'] = "Solo se permiten archivos CSV.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }

    $uploadDirCsv = '../uploads/csv/';
    if (!is_dir($uploadDirCsv) && !mkdir($uploadDirCsv, 0755, true)) {
        $_SESSION['error_formulario'] = "No se pudo crear directorio de subida.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }

    $newFileName = 'formulario_' . $formulario_id . '_' . time() . '.' . $fileExt;
    $dest_path   = $uploadDirCsv . $newFileName;

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
        fclose($handle);
        $_SESSION['error_formulario'] = "El CSV está vacío.";
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }

    $req_cols = ['codigo', 'usuario', 'material', 'valor_propuesto', 'fechapropuesta'];
    $header_norm = array_map(fn($c) => strtolower(trim($c)), $header);
    $faltantes = array_diff($req_cols, $header_norm);

    if (!empty($faltantes)) {
        fclose($handle);
        $_SESSION['error_formulario'] = "Faltan columnas requeridas: " . implode(", ", $faltantes);
        header("Location: editar_formulario.php?id=$formulario_id&active_tab=agregar-entradas");
        exit();
    }

    $idx_codigo          = array_search('codigo', $header_norm, true);
    $idx_usuario         = array_search('usuario', $header_norm, true);
    $idx_material        = array_search('material', $header_norm, true);
    $idx_valor_propuesto = array_search('valor_propuesto', $header_norm, true);
    $idx_fechaPropuesta  = array_search('fechapropuesta', $header_norm, true);

    $empresa_form = (int)$formulario['id_empresa'];
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
        (
            pregunta,
            motivo,
            material,
            valor,
            valor_propuesto,
            fechaPropuesta,
            countVisita,
            observacion,
            id_formulario,
            id_local,
            id_usuario,
            estado
        )
        VALUES ('', '', ?, '', ?, ?, 0, '', ?, ?, ?, 0)
    ");

    $fila = 1;
    $errores_csv = [];
    $ok = 0;

    while (($data = fgetcsv($handle, 1000, ";")) !== false) {
        $fila++;

        $cod_local      = trim($data[$idx_codigo] ?? '');
        $usr_name       = trim($data[$idx_usuario] ?? '');
        $material       = trim($data[$idx_material] ?? '');
        $val_prop       = trim($data[$idx_valor_propuesto] ?? '');
        $fechaRaw       = trim($data[$idx_fechaPropuesta] ?? '');

        if ($cod_local === '' || $usr_name === '' || $material === '' || $val_prop === '' || $fechaRaw === '') {
            $errores_csv[] = "Fila $fila: Hay campos vacíos.";
            continue;
        }

        if (!is_numeric($val_prop)) {
            $errores_csv[] = "Fila $fila: valor_propuesto debe ser numérico.";
            continue;
        }

        $fechaPropuesta = parseFechaPropuestaCsv($fechaRaw);
        if ($fechaPropuesta === null) {
            $errores_csv[] = "Fila $fila: fechaPropuesta inválida.";
            continue;
        }

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

        $stmt_insert_fq->bind_param(
            "sssiii",
            $material,
            $val_prop,
            $fechaPropuesta,
            $formulario_id,
            $id_local,
            $id_usuario_csv
        );
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

// -----------------------------------------------------------------------------
// AGREGAR ENTRADA INDIVIDUAL A formularioQuestion
// -----------------------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_fq') {
    $id_usuario         = (int)($_POST['id_usuario'] ?? 0);
    $codigo_local       = trim($_POST['codigo_local'] ?? '');
    $material_ids       = $_POST['material_id'] ?? [];
    $valores_propuestos = $_POST['valor_propuesto'] ?? [];

    $empresa_form = (int)$formulario['id_empresa'];

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
            $material_id = (int)$material_id;
            $valor_propuesto = isset($valores_propuestos[$index]) ? trim((string)$valores_propuestos[$index]) : '';
            $valor_propuesto = ($valor_propuesto === '') ? null : $valor_propuesto;

            $stmt_mat = $conn->prepare("SELECT nombre FROM material WHERE id = ?");
            $stmt_mat->bind_param("i", $material_id);
            $stmt_mat->execute();
            $stmt_mat->bind_result($material);
            $stmt_mat->fetch();
            $stmt_mat->close();

            if (empty($material)) {
                $error = "Material no encontrado.";
                break;
            }

            $stmt_insert_fq = $conn->prepare("
                INSERT INTO formularioQuestion
                    (id_formulario, id_usuario, id_local, material, valor_propuesto, observacion, estado)
                VALUES (?, ?, ?, ?, ?, '', 0)
            ");
            if ($stmt_insert_fq === false) {
                $error = "Error en la preparación de la consulta: " . h($conn->error);
                break;
            }

            $stmt_insert_fq->bind_param(
                "iiiss",
                $formulario_id,
                $id_usuario,
                $id_local,
                $material,
                $valor_propuesto
            );

            if (!$stmt_insert_fq->execute()) {
                $error = "Error al agregar la entrada: " . h($stmt_insert_fq->error);
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

// -----------------------------------------------------------------------------
// ACTUALIZAR ENTRADA formularioQuestion
// -----------------------------------------------------------------------------
if (isset($_POST['update_fq'])) {
    $fq_id           = (int)($_POST['fq_id'] ?? 0);
    $id_usuario      = (int)($_POST['id_usuario'] ?? 0);
    $material_id     = (int)($_POST['material_id'] ?? 0);
    $valor_propuesto = trim($_POST['valor_propuesto'] ?? '');
    $fechaVisita     = $_POST['fechaVisita'] ?? null;
    $observacion     = trim($_POST['observacion'] ?? '');
    $estado          = (int)($_POST['estado'] ?? 0);
    $valor           = trim($_POST['valor'] ?? '');

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
            SET id_usuario = ?,
                material = ?,
                valor = ?,
                valor_propuesto = ?,
                fechaVisita = ?,
                observacion = ?,
                estado = ?,
                pregunta = ?
            WHERE id = ?
        ");
        if ($stmt_update_fq === false) {
            die("Error en la preparación de la consulta: " . h($conn->error));
        }

        $stmt_update_fq->bind_param(
            "isssssisi",
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
            $error = "Error al actualizar la entrada: " . h($stmt_update_fq->error);
        }
        $stmt_update_fq->close();
    } else {
        $error = "Por favor, complete todos los campos para actualizar la entrada.";
    }
}

// -----------------------------------------------------------------------------
// FILTROS Y DATOS AUXILIARES PARA GESTIONES AGRUPADAS
// -----------------------------------------------------------------------------
$filter_codigo  = isset($_GET['filter_codigo']) ? trim($_GET['filter_codigo']) : '';
$filter_usuario = isset($_GET['filter_usuario']) ? trim($_GET['filter_usuario']) : '';
$filter_estado  = isset($_GET['filter_estado']) ? trim($_GET['filter_estado']) : '';

$limitGroup = isset($_GET['limit_group']) ? (int)$_GET['limit_group'] : 10000;
if ($limitGroup <= 0) {
    $limitGroup = 10000;
}

$pageGroup = isset($_GET['page_group']) ? (int)$_GET['page_group'] : 1;
if ($pageGroup < 1) {
    $pageGroup = 1;
}
$offsetGroup = ($pageGroup - 1) * $limitGroup;

// -----------------------------------------------------------------------------
// DISTINCT Código
// -----------------------------------------------------------------------------
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
while ($row = $result_codigo->fetch_assoc()) {
    $distinct_codigo[] = $row['codigo'];
}
$stmt_codigo->close();

// -----------------------------------------------------------------------------
// DISTINCT Usuario
// -----------------------------------------------------------------------------
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
while ($row = $result_usuario->fetch_assoc()) {
    $distinct_usuario[] = $row['usuario'];
}
$stmt_usuario->close();

// -----------------------------------------------------------------------------
// DISTINCT Estado
// -----------------------------------------------------------------------------
$distinct_estado = [];
$query_estado = "
   SELECT DISTINCT
      CASE
        WHEN TRIM(COALESCE(pregunta, '')) = '' THEN 'No gestionado'
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
while ($row = $result_estado->fetch_assoc()) {
    $distinct_estado[] = $row['estado'];
}
$stmt_estado->close();

// -----------------------------------------------------------------------------
// HAVING dinámico
// -----------------------------------------------------------------------------
$having = [];
if ($filter_codigo !== '') {
    $filter_codigo_esc = $conn->real_escape_string($filter_codigo);
    $having[] = "codigo = '{$filter_codigo_esc}'";
}
if ($filter_usuario !== '') {
    $filter_usuario_esc = $conn->real_escape_string($filter_usuario);
    $having[] = "FIND_IN_SET('{$filter_usuario_esc}', REPLACE(usuarios, ', ', ',')) > 0";
}
if ($filter_estado !== '') {
    if (strtolower($filter_estado) === 'no gestionado') {
        $having[] = "(estado_gestion = '' OR estado_gestion IS NULL)";
    } else {
        $filter_estado_esc = $conn->real_escape_string($filter_estado);
        $having[] = "estado_gestion = '{$filter_estado_esc}'";
    }
}

// -----------------------------------------------------------------------------
// Conteo total agrupado
// -----------------------------------------------------------------------------
$sql_group_without_limit = "
    SELECT
        l.id AS id_local,
        l.codigo,
        l.nombre AS nombre_local,
        l.direccion AS direccion_local,
        COALESCE(q1.total_fq, 0) AS entradas_fq,
        COALESCE(q1.completadas, 0) AS completadas,
        COALESCE(q1.en_proceso, 0) AS en_proceso,
        COALESCE(q1.canceladas, 0) AS canceladas,
        COALESCE(q2.total_resps, 0) AS respuestas_encuesta,
        TRIM(BOTH ', ' FROM CONCAT(
            COALESCE(q1.usuarios, ''),
            CASE
                WHEN COALESCE(q1.usuarios, '') <> '' AND COALESCE(q2.usuarios, '') <> '' THEN ', '
                ELSE ''
            END,
            COALESCE(q2.usuarios, '')
        )) AS usuarios,
        COALESCE(q1.estado_gestion, '') AS estado_gestion
    FROM (
        SELECT id_local
        FROM formularioQuestion
        WHERE id_formulario = ?
        UNION
        SELECT fqr.id_local
        FROM form_question_responses fqr
        INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
        WHERE fq2.id_formulario = ?
    ) AS all_locals
    INNER JOIN local l ON l.id = all_locals.id_local
    LEFT JOIN (
        SELECT
          fq.id_local,
          COUNT(*) AS total_fq,
          SUM(fq.estado = 1) AS completadas,
          SUM(fq.estado = 0) AS en_proceso,
          SUM(fq.estado = 2) AS canceladas,
          GROUP_CONCAT(DISTINCT u.usuario ORDER BY u.usuario SEPARATOR ', ') AS usuarios,
          MIN(fq.pregunta) AS estado_gestion
        FROM formularioQuestion fq
        LEFT JOIN usuario u ON u.id = fq.id_usuario
        WHERE fq.id_formulario = ?
        GROUP BY fq.id_local
    ) AS q1 ON q1.id_local = all_locals.id_local
    LEFT JOIN (
        SELECT
          fqr.id_local,
          COUNT(*) AS total_resps,
          GROUP_CONCAT(DISTINCT u.usuario ORDER BY u.usuario SEPARATOR ', ') AS usuarios
        FROM form_question_responses fqr
        INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
        LEFT JOIN usuario u ON u.id = fqr.id_usuario
        WHERE fq2.id_formulario = ?
        GROUP BY fqr.id_local
    ) AS q2 ON q2.id_local = all_locals.id_local
";

if (!empty($having)) {
    $sql_group_without_limit .= " HAVING " . implode(" AND ", $having);
}

$stmt_count = $conn->prepare("SELECT COUNT(*) AS total FROM ($sql_group_without_limit) AS t");
if (!$stmt_count) {
    die("Error en la preparación de la consulta de conteo: " . h($conn->error));
}
$stmt_count->bind_param("iiii", $formulario_id, $formulario_id, $formulario_id, $formulario_id);
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$row_count = $result_count->fetch_assoc();
$totalRows = (int)$row_count['total'];
$stmt_count->close();

$totalPages = ($limitGroup > 0) ? (int)ceil($totalRows / $limitGroup) : 1;

// -----------------------------------------------------------------------------
// Query agrupada con limit y offset
// -----------------------------------------------------------------------------
$sql_group = $sql_group_without_limit . " LIMIT ? OFFSET ?";

$stmt_group = $conn->prepare($sql_group);
$stmt_group->bind_param(
    "iiiiii",
    $formulario_id,
    $formulario_id,
    $formulario_id,
    $formulario_id,
    $limitGroup,
    $offsetGroup
);
$stmt_group->execute();
$result_group = $stmt_group->get_result();
$gestiones_por_local = $result_group->fetch_all(MYSQLI_ASSOC);
$stmt_group->close();

// -----------------------------------------------------------------------------
// Sets de preguntas
// -----------------------------------------------------------------------------
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
<script src="https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js"></script>
<script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js"></script>
<script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js"></script>

<script>
const formularioId = <?= json_encode((int)$formulario_id) ?>;

$(document).ready(function () {
    // ---------------------------------------------------------
    // DataTable principal
    // ---------------------------------------------------------
    document.title = "Simple DataTable";

    $("#example tfoot th").each(function () {
        var title = $(this).text();
        $(this).html('<input type="text" placeholder="Buscar ' + title + '" />');
    });

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
        initComplete: function () {
            var footer = $("#example tfoot tr");
            if (footer.length) {
                $("#example thead").append(footer);
            }
        }
    });

    $("#example thead").on("keyup", "input", function () {
        table
            .column($(this).parent().index())
            .search(this.value)
            .draw();
    });

    // ---------------------------------------------------------
    // Botón editar entrada individual
    // ---------------------------------------------------------
    $('.btn-editar').on('click', function () {
        var fqId = $(this).data('fq-id');

        $.ajax({
            url: 'ajax_editar_fq.php',
            method: 'GET',
            data: {
                id: fqId,
                formulario_id: formularioId
            },
            success: function (data) {
                $('#editarFQModalContent').html(data);
                $('#editarFQModal').modal('show');
            },
            error: function () {
                alert('Error al cargar el formulario de edición.');
            }
        });
    });

    // ---------------------------------------------------------
    // Reabrir modal de gestiones si viene en querystring
    // ---------------------------------------------------------
    const params = new URLSearchParams(window.location.search);

    if (params.get('open_modal') === 'gestiones' && params.get('local_id')) {
        const localId  = params.get('local_id');
        const userId   = params.get('user_id') || '';
        const tab      = params.get('tab') || 'impl';
        const pageImpl = parseInt(params.get('page_impl') || '1', 10);
        const pageResp = parseInt(params.get('page_resp') || '1', 10);

        $.get('ajax_ver_gestiones.php', {
            formulario_id: formularioId,
            local_id: localId,
            user_id: userId,
            page_impl: pageImpl,
            page_resp: pageResp,
            tab: tab
        })
        .done(function (html) {
            $('#gestionesModalContent').html(html);
            $('#gestionesModal').modal('show');
        })
        .fail(function () {
            alert('Error al reabrir el detalle de gestiones.');
        });

        params.delete('open_modal');
        params.delete('local_id');
        params.delete('user_id');
        params.delete('tab');
        params.delete('page_impl');
        params.delete('page_resp');

        const newQuery = params.toString();
        history.replaceState({}, '', location.pathname + (newQuery ? '?' + newQuery : ''));
    }

    // ---------------------------------------------------------
    // Ver gestiones
    // ---------------------------------------------------------
    $(document).on('click', '.ver-gestiones', function () {
        const localId = $(this).data('local-id');

        $.get('ajax_ver_gestiones.php', {
            formulario_id: formularioId,
            local_id: localId
        })
        .done(function (html) {
            $('#gestionesModalContent').html(html);
            $('#gestionesModal').modal('show');
        })
        .fail(function (xhr, status, err) {
            console.error('AJAX Error:', status, err);
            alert('Error al cargar las gestiones.');
        });
    });

    // ---------------------------------------------------------
    // Editar gestión agrupada
    // ---------------------------------------------------------
    $(document).on('click', '.editar-gestion', function () {
        const localId = $(this).data('local-id');

        $.get('ajax_editar_gestion.php', {
            formulario_id: formularioId,
            local_id: localId
        })
        .done(function (html) {
            $('#editarGestionModalContent').html(html);
            $('#editarGestionModal').modal('show');
        })
        .fail(function () {
            alert('Error al cargar el formulario de edición de gestión.');
        });
    });

    // ---------------------------------------------------------
    // Guardar edición de gestión vía AJAX
    // ---------------------------------------------------------
    $(document).on('submit', '#editarGestionForm', function (e) {
        e.preventDefault();

        const $form = $(this);
        const url = 'ajax_editar_gestion.php'
            + '?formulario_id=' + encodeURIComponent($form.find('input[name="formulario_id"]').val())
            + '&local_id=' + encodeURIComponent($form.find('input[name="local_id"]').val());

        $.post(url, $form.serialize(), function (response) {
            const $resp = $('<div>').html(response);
            const $alert = $resp.find('.alert').first();

            if ($alert.length) {
                const $modalBody = $('#editarGestionModal .modal-body');
                $modalBody.find('.alert').remove();
                $modalBody.prepend($alert.hide().fadeIn(150));

                if ($resp.find('.alert-success').length) {
                    setTimeout(function () {
                        const urlReload = new URL(window.location.href);
                        urlReload.searchParams.set('active_tab', 'agregar-entradas');
                        window.location.href = urlReload.toString();
                    }, 600);
                }
            }

            const $newTable = $resp.find('table');
            if ($newTable.length) {
                $('#editarGestionModal .modal-body table').replaceWith($newTable);
            }
        }).fail(function () {
            alert('Error al guardar la actualización de gestión.');
        });
    });

    // ---------------------------------------------------------
    // Reasignar local vía AJAX
    // ---------------------------------------------------------
    $(document).on('submit', '#reasignarLocalForm', function (e) {
        e.preventDefault();

        const $form = $(this);
        const localId = $form.find('input[name="local_id"]').val();

        $.ajax({
            url: 'ajax_editar_gestion.php?formulario_id=' + encodeURIComponent(formularioId) + '&local_id=' + encodeURIComponent(localId),
            method: 'POST',
            data: $form.serialize(),
            success: function (response) {
                $('#editarGestionModalContent').html(response);

                if (response.indexOf('alert-success') !== -1) {
                    setTimeout(function () {
                        const urlReload = new URL(window.location.href);
                        urlReload.searchParams.set('active_tab', 'agregar-entradas');
                        window.location.href = urlReload.toString();
                    }, 600);
                }
            },
            error: function () {
                alert('Error al reasignar el local.');
            }
        });
    });

    // ---------------------------------------------------------
    // Ver historial de visitas
    // ---------------------------------------------------------
    $(document).on('click', '.ver-visitas', function () {
        const localId = $(this).data('local-id');

        $.get('ajax_ver_visitas.php', {
            formulario_id: formularioId,
            local_id: localId
        })
        .done(function (html) {
            $('#visitasModalContent').html(html);
            $('#visitasModal').modal('show');
        })
        .fail(function (xhr, status, err) {
            console.error('AJAX Error:', status, err);
            alert('Error al cargar el histórico de visitas.');
        });
    });
});

// ---------------------------------------------------------
// Funciones globales de preguntas
// ---------------------------------------------------------
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

function addOptionRow() {
    const optionsRows = document.getElementById('questionOptionsRows');
    const optIndex = optionsRows.children.length;
    const newRow = document.createElement('div');
    newRow.className = 'option-block mb-2';
    const previewId = 'preview_' + optIndex;

    newRow.innerHTML =
        '<div class="input-group">' +
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
    reader.onload = function (e) {
        const img = document.getElementById(previewId);
        if (img) {
            img.src = e.target.result;
            img.style.display = 'block';
        }
    };
    reader.readAsDataURL(file);
}

// ---------------------------------------------------------
// Funciones globales de materiales
// ---------------------------------------------------------
function addMaterialRow() {
    const materialesContainer = document.getElementById('materiales-container');
    const materialRow = document.createElement('div');
    materialRow.className = 'material-row';

    materialRow.innerHTML =
        '<div class="form-row">' +
            '<div class="col">' +
                '<label>Material:</label>' +
                '<div class="input-group">' +
                    '<select name="material_id[]" class="form-control" required>' +
                        '<option value="">Seleccione un material</option>' +
                        '<?php foreach ($materiales as $material): ?>' +
                            '<option value="<?= htmlspecialchars($material["id"], ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($material["nombre"], ENT_QUOTES, "UTF-8") ?></option>' +
                        '<?php endforeach; ?>' +
                    '</select>' +
                    '<div class="input-group-append">' +
                        '<button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">Agregar Material</button>' +
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

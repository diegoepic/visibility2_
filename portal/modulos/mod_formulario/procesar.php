<?php
// mod_formulario/procesar.php

session_start();

// Desactivar errores en producción (habilítalo solo en dev)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

/**
 * Normaliza fechas de CSV a YYYY-MM-DD.
 * Acepta: DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD (y DD-MM-YY -> 20YY).
 * Retorna '' si no puede validar.
 */
function normalizarFechaPropuesta(string $s): string {
    $s = trim($s);
    if ($s === '') return '';

    // Solo la parte de fecha (por si viene con hora)
    $s = preg_split('/\s+/', $s)[0];
    // Unificar separadores
    $s = str_replace(['/', '.'], '-', $s);

    // dd-mm-yyyy
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $s)) {
        [$d,$m,$y] = array_map('intval', explode('-', $s));
        if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        return '';
    }
    // yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        [$y,$m,$d] = array_map('intval', explode('-', $s));
        if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        return '';
    }
    // dd-mm-yy  -> 20yy
    if (preg_match('/^\d{2}-\d{2}-\d{2}$/', $s)) {
        [$d,$m,$yy] = array_map('intval', explode('-', $s));
        $y = 2000 + $yy;
        if (checkdate($m,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$m,$d);
        return '';
    }
    return '';
}

try {
    // ====== Permisos / sesión ======
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception("No tienes permisos para esta acción.");
    }

    // Empresa del usuario
    $empresa_id = $_SESSION['empresa_id'] ?? null;
    if (!isset($empresa_id) || !filter_var($empresa_id, FILTER_VALIDATE_INT)) {
        throw new Exception("ID de empresa inválido.");
    }

    // Nombre de empresa => mentecreativa?
    $nombre_empresa = obtenerNombreEmpresa($empresa_id);
    $es_mentecreativa = (strtolower(trim($nombre_empresa)) === 'mentecreativa');

    // Método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método de solicitud no válido.");
    }

    // ====== Datos base ======
    $nombre       = trim($_POST['nombre']   ?? '');
    $estado       = trim($_POST['estado']   ?? '');
    $tipo         = intval($_POST['tipo']   ?? 0);
    
    //  IW requiere local? 
    $iw_requiere_local = 0;
    if ($tipo === 2) {
        $iw_requiere_local = isset($_POST['iw_requiere_local']) ? 1 : 0;
    }

    if ($nombre === '' || $estado === '' || $tipo === 0) {
        throw new Exception("Por favor, completa todos los campos requeridos.");
    }

    // ====== Fechas por tipo ======
    $fechaInicio  = trim($_POST['fechaInicio']  ?? '');
    $fechaTermino = trim($_POST['fechaTermino'] ?? '');

    if ($tipo === 1 || $tipo === 3) {
        if ($fechaInicio === '' || $fechaTermino === '') {
            throw new Exception("Completa la fecha de inicio y la fecha de término para campañas Programadas/IPT.");
        }
        if (strtotime($fechaTermino) < strtotime($fechaInicio)) {
            throw new Exception("La fecha de término debe ser mayor o igual a la fecha de inicio.");
        }
    } elseif ($tipo === 2) {
        // IW: fechas como NULL (usamos NULLIF en el INSERT)
        $fechaInicio  = '';
        $fechaTermino = '';
    } else {
        throw new Exception("Tipo de campaña inválido.");
    }

    // ====== Modalidad ======
    if ($tipo === 1 || $tipo === 3) {
        if (!isset($_POST['modalidad'])) {
            throw new Exception("Debes seleccionar la modalidad de campaña.");
        }
        $modalidad = trim($_POST['modalidad']);
        $valoresPermitidos = [
            'implementacion_auditoria',
            'solo_implementacion',
            'solo_auditoria',
            'retiro',
            'entrega',
        ];
        if (!in_array($modalidad, $valoresPermitidos, true)) {
            throw new Exception("Modalidad inválida.");
        }
    } elseif ($tipo === 2) {
        // IW
        $modalidad = 'complementaria';
    } else {
        $modalidad = 'implementacion_auditoria';
    }

    // ====== Empresa / División / Subdivisión ======
    if ($tipo === 2) {
        // IW: requiere empresa (MC la elige; otros usan la suya) y permite DIVISIÓN; SIN subdivisión
        if ($es_mentecreativa) {
            $empresa_form = intval($_POST['empresa_form'] ?? 0);
            if ($empresa_form <= 0) {
                throw new Exception("Selecciona una empresa válida.");
            }
            $empresas_activas = obtenerEmpresasActivas();
            $ok_empresa = false;
            foreach ($empresas_activas as $ea) {
                if (intval($ea['id']) === $empresa_form) { $ok_empresa = true; break; }
            }
            if (!$ok_empresa) {
                throw new Exception("Empresa no válida.");
            }
        } else {
            $empresa_form = $empresa_id;
        }

        // División (0 = sin división)
        $id_division = isset($_POST['id_division']) ? intval($_POST['id_division']) : 0;
        if ($id_division > 0) {
            // Validar división pertenece a empresa
            $ok_div = false;
            foreach (obtenerDivisionesPorEmpresa($empresa_form) as $dv) {
                if (intval($dv['id']) === $id_division) { $ok_div = true; break; }
            }
            if (!$ok_div) {
                throw new Exception("División inválida para la empresa seleccionada.");
            }
        }

        // Subdivisión: siempre 0 en IW
        $id_subdivision = 0;

    } else {
        // Programada / IPT
        if ($es_mentecreativa) {
            $empresa_form = intval($_POST['empresa_form'] ?? 0);
            if ($empresa_form <= 0) {
                throw new Exception("Selecciona una empresa válida.");
            }
            $empresas_activas = obtenerEmpresasActivas();
            $ok_empresa = false;
            foreach ($empresas_activas as $ea) {
                if (intval($ea['id']) === $empresa_form) { $ok_empresa = true; break; }
            }
            if (!$ok_empresa) {
                throw new Exception("Empresa no válida.");
            }
        } else {
            $empresa_form = $empresa_id;
        }

        // División (0 = sin división)
        $id_division = isset($_POST['id_division']) ? intval($_POST['id_division']) : 0;
        if ($id_division > 0) {
            $ok_div = false;
            foreach (obtenerDivisionesPorEmpresa($empresa_form) as $dv) {
                if (intval($dv['id']) === $id_division) { $ok_div = true; break; }
            }
            if (!$ok_div) {
                throw new Exception("División inválida para la empresa seleccionada.");
            }
        }

        // Subdivisión (opcional)
        $id_subdivision = filter_input(INPUT_POST, 'id_subdivision', FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        if ($id_subdivision > 0) {
            if ($id_division <= 0) {
                throw new Exception("Selecciona primero una división para poder elegir una subdivisión.");
            }
            if (!subdivisionPerteneceADivision($id_subdivision, $id_division)) {
                throw new Exception("Subdivisión inválida para la división seleccionada.");
            }
        } else {
            $id_subdivision = 0;
        }
    }

    // ====== CSV requerido para tipos 1 y 3 ======
    $requiereCsv = ($tipo === 1 || $tipo === 3);
    if ($requiereCsv && (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK)) {
        throw new Exception("Debes subir un CSV válido para campañas Programadas/IPT (tipos 1 o 3).");
    }

    // ====== Transacción ======
    $conn->begin_transaction();

    // ====== Insert en formulario (fechas con NULLIF, incluye id_division e id_subdivision) ======
    $url_bi = '';

    $sql_insert_formulario = "
        INSERT INTO formulario
        (nombre, fechaInicio, fechaTermino, estado, tipo, iw_requiere_local, id_empresa, id_division, id_subdivision, url_bi, modalidad)
        VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt_insert_formulario = $conn->prepare($sql_insert_formulario);
    $stmt_insert_formulario->bind_param(
        'ssssiiiiiss',
        $nombre,             // s
        $fechaInicio,        // s (NULLIF)
        $fechaTermino,       // s (NULLIF)
        $estado,             // s
        $tipo,               // i
        $iw_requiere_local,  // i   <-- NUEVO
        $empresa_form,       // i
        $id_division,        // i
        $id_subdivision,     // i
        $url_bi,             // s
        $modalidad           // s
    );
    $stmt_insert_formulario->execute();
    $formulario_id = $conn->insert_id;

    /*
     * ========================================================================
     * Copiar preguntas desde un "Set de Preguntas", si fue seleccionado.
     * (Requiere implementar en db.php:
     *  - obtenerPreguntasDeSet($set_id)
     *  - obtenerOpcionesDePreguntaSet($question_set_question_id)
     * ========================================================================
     */
    if (isset($_POST['selected_set_id']) && intval($_POST['selected_set_id']) > 0) {
        $set_id = intval($_POST['selected_set_id']);

        // 1. Obtener preguntas del set
        if (!function_exists('obtenerPreguntasDeSet') || !function_exists('obtenerOpcionesDePreguntaSet')) {
            throw new Exception("Faltan helpers del Set de Preguntas en db.php.");
        }
        $preguntas_set = obtenerPreguntasDeSet($set_id);

        // 2. Insertar cada pregunta en form_questions
        $sql_ins_q_set = "
            INSERT INTO form_questions
            (id_formulario, question_text, id_question_type, sort_order, is_required)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt_q_set = $conn->prepare($sql_ins_q_set);

        // 3. Para opciones
        $sql_ins_opt_set = "
            INSERT INTO form_question_options
            (id_form_question, option_text, sort_order)
            VALUES (?, ?, ?)
        ";
        $stmt_opt_set = $conn->prepare($sql_ins_opt_set);

        $sort_q_set = 1;
        foreach ($preguntas_set as $pq) {
            $question_text    = $pq['question_text'];
            $id_question_type = $pq['id_question_type'];
            $is_required      = $pq['is_required'];

            $stmt_q_set->bind_param(
                'isiii',
                $formulario_id,
                $question_text,
                $id_question_type,
                $sort_q_set,
                $is_required
            );
            $stmt_q_set->execute();
            $id_form_question_new = $conn->insert_id;

            $opciones_set = obtenerOpcionesDePreguntaSet($pq['id']);
            if (!empty($opciones_set)) {
                foreach ($opciones_set as $opt) {
                    $stmt_opt_set->bind_param(
                        'isi',
                        $id_form_question_new,
                        $opt['option_text'],
                        $opt['sort_order']
                    );
                    $stmt_opt_set->execute();
                }
            }
            $sort_q_set++;
        }
        $stmt_q_set->close();
        $stmt_opt_set->close();
    }
    // ===================== FIN Copia Set de Preguntas ========================

    // ====== Preguntas personalizadas (flujo existente) ======
    if (isset($_POST['formQuestions']) && is_array($_POST['formQuestions'])) {
        $preguntas = $_POST['formQuestions'];
        $sql_ins_q = "
          INSERT INTO form_questions
          (id_formulario, question_text, id_question_type, sort_order, is_required)
          VALUES (?, ?, ?, ?, ?)
        ";
        $stmt_q = $conn->prepare($sql_ins_q);

        $sql_ins_opt = "
          INSERT INTO form_question_options
          (id_form_question, option_text, sort_order)
          VALUES (?, ?, ?)
        ";
        $stmt_opt = $conn->prepare($sql_ins_opt);

        $sort_q = 1;
        foreach ($preguntas as $pq) {
            $question_text    = isset($pq['question_text']) ? trim($pq['question_text']) : '';
            $id_question_type = isset($pq['id_question_type']) ? intval($pq['id_question_type']) : 0;
            $is_required      = isset($pq['is_required']) ? 1 : 0;

            if ($question_text === '' || $id_question_type === 0) {
                continue;
            }
            $stmt_q->bind_param(
                'isiii',
                $formulario_id,
                $question_text,
                $id_question_type,
                $sort_q,
                $is_required
            );
            $stmt_q->execute();
            $id_form_question = $conn->insert_id;

            if (in_array($id_question_type, [1,2,3], true)) {
                if (isset($pq['options']) && is_array($pq['options'])) {
                    $sort_opt = 1;
                    foreach ($pq['options'] as $opt) {
                        $option_text = isset($opt['option_text']) ? trim($opt['option_text']) : '';
                        if ($option_text !== '') {
                            $stmt_opt->bind_param(
                                'isi',
                                $id_form_question,
                                $option_text,
                                $sort_opt
                            );
                            $stmt_opt->execute();
                            $sort_opt++;
                        }
                    }
                } else {
                    if ($id_question_type === 1) {
                        $yesno = ['Sí','No'];
                        foreach ($yesno as $idx => $txt) {
                            $sopt = $idx + 1;
                            $stmt_opt->bind_param(
                                'isi',
                                $id_form_question,
                                $txt,
                                $sopt
                            );
                            $stmt_opt->execute();
                        }
                    }
                }
            }
            $sort_q++;
        }
        $stmt_q->close();
        $stmt_opt->close();
    }

    // ====== CSV (obligatorio en tipos 1/3) ======
    if ($requiereCsv) {
        // Validaciones de archivo
        $fileTmpPath = $_FILES['csvFile']['tmp_name'];
        $fileName    = $_FILES['csvFile']['name'];
        $fileSize    = $_FILES['csvFile']['size'];
        $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'csv') {
            throw new Exception("Solo se permiten archivos CSV.");
        }
        if ($fileSize > 10 * 1024 * 1024) {
            throw new Exception("CSV demasiado grande (máx 10MB).");
        }

        $uploadDir = '../uploads/csv/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new Exception("No se pudo crear directorio de subida.");
        }
        $newFileName = 'formulario_' . $formulario_id . '_' . time() . '.csv';
        $dest_path   = $uploadDir . $newFileName;

        if (!move_uploaded_file($fileTmpPath, $dest_path)) {
            throw new Exception("Error al subir archivo CSV.");
        }

        $handle = fopen($dest_path, 'r');
        if ($handle === false) {
            throw new Exception("No se pudo abrir el CSV.");
        }

        // Detectar BOM
        $firstBytes = fread($handle, 3);
        if ($firstBytes !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }

        // Detectar delimitador (prefiere ';')
        $sample = fgets($handle);
        $delim  = (substr_count($sample, ';') >= substr_count($sample, ',')) ? ';' : ',';
        fseek($handle, ($firstBytes === "\xEF\xBB\xBF") ? 3 : 0);

        $header = fgetcsv($handle, 10000, $delim);
        if (!$header) {
            throw new Exception("El CSV está vacío.");
        }

        // Se esperan: codigo, usuario, material, valor_propuesto, fechapropuesta
        $req_cols   = ['codigo','usuario','material','valor_propuesto','fechapropuesta'];
        $header_norm= array_map(function($c){ return strtolower(trim($c)); }, $header);
        $faltantes  = array_diff($req_cols, $header_norm);
        if (!empty($faltantes)) {
            throw new Exception("Faltan columnas requeridas: " . implode(", ", $faltantes));
        }

        $idx_codigo          = array_search('codigo',          $header_norm);
        $idx_usuario         = array_search('usuario',         $header_norm);
        $idx_material        = array_search('material',        $header_norm);
        $idx_valor_propuesto = array_search('valor_propuesto', $header_norm);
        $idx_fechapropuesta  = array_search('fechapropuesta',  $header_norm);

        $tiene_divisiones = (contarDivisionesPorEmpresa($empresa_form) > 0);

        if ($tiene_divisiones) {
            $stmt_local   = $conn->prepare("SELECT id FROM local   WHERE codigo = ? AND id_empresa = ?");
            $stmt_usuario = $conn->prepare("SELECT id FROM usuario WHERE usuario = ? AND id_empresa = ?");
        } else {
            $stmt_local   = $conn->prepare("SELECT id FROM local   WHERE codigo = ?");
            $stmt_usuario = $conn->prepare("SELECT id FROM usuario WHERE usuario = ?");
        }

        // TIPOS CORRECTOS para formularioQuestion: material(s), valor_propuesto(i), fechaPropuesta(s), id_formulario(i), id_local(i), id_usuario(i)
        $stmt_insert_fq = $conn->prepare("
            INSERT INTO formularioQuestion
            (pregunta, motivo, material, valor, valor_propuesto, fechaPropuesta, countVisita, observacion, id_formulario, id_local, id_usuario, estado)
            VALUES ('', '', ?, '', ?, ?, 0, '', ?, ?, ?, 0)
        ");

        $fila = 1;
        $errores = [];
        $ok = 0;

        while(($data = fgetcsv($handle, 10000, $delim)) !== false) {
            $fila++;
            $cod_local   = trim($data[$idx_codigo]          ?? '');
            $usr_name    = trim($data[$idx_usuario]         ?? '');
            $material    = trim($data[$idx_material]        ?? '');
            $val_prop_s  = trim($data[$idx_valor_propuesto] ?? '');
            $fechaRaw    = trim($data[$idx_fechapropuesta]  ?? '');
            $fechaProp   = normalizarFechaPropuesta($fechaRaw);

            if ($cod_local === '' || $usr_name === '' || $material === '' || $val_prop_s === '') {
                $errores[] = "Fila $fila: Hay campos vacíos obligatorios.";
                continue;
            }

            if (!is_numeric($val_prop_s)) {
                $errores[] = "Fila $fila: valor_propuesto debe ser numérico.";
                continue;
            }
            $val_prop = (int)$val_prop_s;

            if ($fechaProp === '') {
                if ($fechaRaw === '') {
                    $fechaProp = date('Y-m-d');
                } else {
                    $errores[] = "Fila $fila: fechapropuesta inválida (usa DD-MM-AAAA, DD/MM/AAAA o AAAA-MM-DD).";
                    continue;
                }
            }

            // Local
            if ($tiene_divisiones) {
                $stmt_local->bind_param("si", $cod_local, $empresa_form);
            } else {
                $stmt_local->bind_param("s", $cod_local);
            }
            $stmt_local->execute();
            $stmt_local->bind_result($id_local);
            if (!$stmt_local->fetch()) {
                $errores[] = "Fila $fila: local '{$cod_local}' no encontrado.";
                $stmt_local->reset();
                continue;
            }
            $stmt_local->reset();

            // Usuario
            if ($tiene_divisiones) {
                $stmt_usuario->bind_param("si", $usr_name, $empresa_form);
            } else {
                $stmt_usuario->bind_param("s", $usr_name);
            }
            $stmt_usuario->execute();
            $stmt_usuario->bind_result($id_usuario);
            if (!$stmt_usuario->fetch()) {
                $errores[] = "Fila $fila: usuario '{$usr_name}' no encontrado.";
                $stmt_usuario->reset();
                continue;
            }
            $stmt_usuario->reset();

            // Insert fila
            $stmt_insert_fq->bind_param(
                "sisiii",
                $material,       // s
                $val_prop,       // i
                $fechaProp,      // s
                $formulario_id,  // i
                $id_local,       // i
                $id_usuario      // i
            );
            $stmt_insert_fq->execute();
            $ok++;
        }

        fclose($handle);
        $stmt_local->close();
        $stmt_usuario->close();
        $stmt_insert_fq->close();

        if (!empty($errores)) {
            throw new Exception("Errores en CSV:<br>" . implode("<br>", $errores));
        }
        $_SESSION['success_formulario'] = "Formulario insertado (ID: $formulario_id). Registros CSV: $ok";
    } else {
        // Tipo 2 (IW) o casos sin CSV
        $_SESSION['success_formulario'] = ($tipo === 2)
            ? "Formulario IW (ID: $formulario_id) creado."
            : "Formulario creado sin CSV.";
    }

    // ====== Commit ======
    $conn->commit();

} catch (Exception $e) {
    // Rollback
    if (isset($conn) && $conn->errno === 0) {
        try { $conn->rollback(); } catch (Throwable $t) {}
    }
    $_SESSION['error_formulario'] = "Ocurrió un error: " . $e->getMessage();
}

if (isset($conn)) { $conn->close(); }
header("Location: ../mod_formulario.php");
exit();

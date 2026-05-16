<?php
declare(strict_types=1);

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

set_time_limit(0);
ini_set('memory_limit', '512M');

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[mod_formulario/procesar][shutdown] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});

/**
 * Escapa HTML para mensajes.
 */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Limpia buffers y redirige siempre limpio.
 */
function safeRedirect(string $url): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: ' . $url, true, 302);
    exit();
}

/**
 * Normaliza nombres de columnas del CSV.
 * Ej: "Fecha Propuesta" => "fechapropuesta"
 */
function normalizarNombreColumna(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace([' ', '-', '.'], '', $s);
    return $s;
}

/**
 * Normaliza fechas a YYYY-MM-DD.
 * Acepta:
 * - DD-MM-YYYY
 * - DD/MM/YYYY
 * - YYYY-MM-DD
 * - YYYY/MM/DD
 * - DD-MM-YY
 * - DD/MM/YY
 * Retorna '' si no puede validar.
 */
function normalizarFechaPropuesta(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }

    $s = preg_split('/\s+/', $s)[0] ?? $s;
    $s = str_replace(['/', '.'], '-', $s);
    $s = preg_replace('/-+/', '-', $s);

    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $s)) {
        [$d, $m, $y] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
    }

    if (preg_match('/^\d{2}-\d{2}-\d{2}$/', $s)) {
        [$d, $m, $yy] = array_map('intval', explode('-', $s));
        $y = 2000 + $yy;
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
    }

    return '';
}

/**
 * Obtiene índice de columna requerida.
 */
function obtenerIndiceColumna(array $headerNorm, string $columna): int
{
    $idx = array_search($columna, $headerNorm, true);
    if ($idx === false) {
        throw new Exception("Falta la columna requerida: {$columna}");
    }
    return (int)$idx;
}

/**
 * Acumula errores de CSV sin reventar la sesión con miles de mensajes.
 */
function agregarErrorCsv(array &$errores, int &$totalErrores, string $mensaje, int $maxErrores = 50): void
{
    $totalErrores++;
    if (count($errores) < $maxErrores) {
        $errores[] = $mensaje;
    }
}

try {
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception("No tienes permisos para esta acción.");
    }

    $empresa_id = $_SESSION['empresa_id'] ?? null;
    if (!isset($empresa_id) || !filter_var($empresa_id, FILTER_VALIDATE_INT)) {
        throw new Exception("ID de empresa inválido.");
    }

    $nombre_empresa = obtenerNombreEmpresa($empresa_id);
    $es_mentecreativa = (strtolower(trim((string)$nombre_empresa)) === 'mentecreativa');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método de solicitud no válido.");
    }

    // =========================
    // Datos base
    // =========================
    $nombre = trim($_POST['nombre'] ?? '');
    $estado = trim($_POST['estado'] ?? '');
    $tipo   = intval($_POST['tipo'] ?? 0);

    $iw_requiere_local = 0;
    if ($tipo === 2) {
        $iw_requiere_local = isset($_POST['iw_requiere_local']) ? 1 : 0;
    }

    if ($nombre === '' || $estado === '' || $tipo === 0) {
        throw new Exception("Por favor, completa todos los campos requeridos.");
    }

    // =========================
    // Fechas por tipo
    // =========================
    $fechaInicio  = trim($_POST['fechaInicio'] ?? '');
    $fechaTermino = trim($_POST['fechaTermino'] ?? '');

    if ($tipo === 1 || $tipo === 3) {
        if ($fechaInicio === '' || $fechaTermino === '') {
            throw new Exception("Completa la fecha de inicio y la fecha de término para campañas Programadas/IPT.");
        }

        if (strtotime($fechaTermino) < strtotime($fechaInicio)) {
            throw new Exception("La fecha de término debe ser mayor o igual a la fecha de inicio.");
        }
    } elseif ($tipo === 2) {
        $fechaInicio  = '';
        $fechaTermino = '';
    } else {
        throw new Exception("Tipo de campaña inválido.");
    }

    // =========================
    // Modalidad
    // =========================
    if ($tipo === 1 || $tipo === 3) {
        $modalidad = trim($_POST['modalidad'] ?? '');

        $valoresPermitidos = [
            'implementacion_auditoria',
            'solo_implementacion',
            'solo_auditoria',
            'retiro',
            'entrega',
        ];

        if ($modalidad === '' || !in_array($modalidad, $valoresPermitidos, true)) {
            throw new Exception("Debes seleccionar una modalidad válida.");
        }
    } elseif ($tipo === 2) {
        $modalidad = 'complementaria';
    } else {
        $modalidad = 'implementacion_auditoria';
    }

    // =========================
    // Empresa / División / Subdivisión
    // =========================
    if ($es_mentecreativa) {
        $empresa_form = intval($_POST['empresa_form'] ?? 0);
        if ($empresa_form <= 0) {
            throw new Exception("Selecciona una empresa válida.");
        }

        $empresas_activas = obtenerEmpresasActivas();
        $ok_empresa = false;
        foreach ($empresas_activas as $ea) {
            if ((int)$ea['id'] === $empresa_form) {
                $ok_empresa = true;
                break;
            }
        }

        if (!$ok_empresa) {
            throw new Exception("Empresa no válida.");
        }
    } else {
        $empresa_form = (int)$empresa_id;
    }

    $id_division = isset($_POST['id_division']) ? intval($_POST['id_division']) : 0;
    if ($id_division > 0) {
        $ok_div = false;
        foreach (obtenerDivisionesPorEmpresa($empresa_form) as $dv) {
            if ((int)$dv['id'] === $id_division) {
                $ok_div = true;
                break;
            }
        }

        if (!$ok_div) {
            throw new Exception("División inválida para la empresa seleccionada.");
        }
    }

    if ($tipo === 2) {
        $id_subdivision = 0;
    } else {
        $id_subdivision = filter_input(INPUT_POST, 'id_subdivision', FILTER_VALIDATE_INT, [
            'options' => ['default' => 0]
        ]);

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

    // =========================
    // CSV requerido
    // =========================
    $requiereCsv = ($tipo === 1 || $tipo === 3);
    if ($requiereCsv && (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK)) {
        throw new Exception("Debes subir un CSV válido para campañas Programadas/IPT.");
    }

    // =========================
    // Transacción
    // =========================
    $conn->begin_transaction();

    // =========================
    // Insert formulario
    // =========================
    $url_bi = '';

    $sql_insert_formulario = "
        INSERT INTO formulario
        (nombre, fechaInicio, fechaTermino, estado, tipo, iw_requiere_local, id_empresa, id_division, id_subdivision, url_bi, modalidad)
        VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt_insert_formulario = $conn->prepare($sql_insert_formulario);
    $stmt_insert_formulario->bind_param(
        'ssssiiiiiss',
        $nombre,
        $fechaInicio,
        $fechaTermino,
        $estado,
        $tipo,
        $iw_requiere_local,
        $empresa_form,
        $id_division,
        $id_subdivision,
        $url_bi,
        $modalidad
    );
    $stmt_insert_formulario->execute();
    $formulario_id = (int)$conn->insert_id;
    $stmt_insert_formulario->close();

    // =========================
    // Copia de set de preguntas
    // =========================
    if (isset($_POST['selected_set_id']) && intval($_POST['selected_set_id']) > 0) {
        $set_id = intval($_POST['selected_set_id']);

        if (!function_exists('obtenerPreguntasDeSet') || !function_exists('obtenerOpcionesDePreguntaSet')) {
            throw new Exception("Faltan helpers del Set de Preguntas en db.php.");
        }

        $preguntas_set = obtenerPreguntasDeSet($set_id);

        $sql_ins_q_set = "
            INSERT INTO form_questions
            (id_formulario, question_text, id_question_type, sort_order, is_required)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt_q_set = $conn->prepare($sql_ins_q_set);

        $sql_ins_opt_set = "
            INSERT INTO form_question_options
            (id_form_question, option_text, sort_order)
            VALUES (?, ?, ?)
        ";
        $stmt_opt_set = $conn->prepare($sql_ins_opt_set);

        $sort_q_set = 1;
        foreach ($preguntas_set as $pq) {
            $question_text    = $pq['question_text'];
            $id_question_type = (int)$pq['id_question_type'];
            $is_required      = (int)$pq['is_required'];

            $stmt_q_set->bind_param(
                'isiii',
                $formulario_id,
                $question_text,
                $id_question_type,
                $sort_q_set,
                $is_required
            );
            $stmt_q_set->execute();
            $id_form_question_new = (int)$conn->insert_id;

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

    // =========================
    // Preguntas personalizadas
    // =========================
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
            $question_text    = trim($pq['question_text'] ?? '');
            $id_question_type = intval($pq['id_question_type'] ?? 0);
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
            $id_form_question = (int)$conn->insert_id;

            if (in_array($id_question_type, [1, 2, 3], true)) {
                if (isset($pq['options']) && is_array($pq['options'])) {
                    $sort_opt = 1;
                    foreach ($pq['options'] as $opt) {
                        $option_text = trim($opt['option_text'] ?? '');
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
                } elseif ($id_question_type === 1) {
                    foreach (['Sí', 'No'] as $idx => $txt) {
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

            $sort_q++;
        }

        $stmt_q->close();
        $stmt_opt->close();
    }

    // =========================
    // Procesar CSV
    // =========================
    if ($requiereCsv) {
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

        $firstBytes = fread($handle, 3);
        if ($firstBytes !== "\xEF\xBB\xBF") {
            fseek($handle, 0);
        }

        $sample = fgets($handle);
        $delim  = (substr_count((string)$sample, ';') >= substr_count((string)$sample, ',')) ? ';' : ',';
        fseek($handle, ($firstBytes === "\xEF\xBB\xBF") ? 3 : 0);

        $header = fgetcsv($handle, 10000, $delim);
        if (!$header) {
            fclose($handle);
            throw new Exception("El CSV está vacío.");
        }

        $header_norm = array_map(function ($c) {
            return normalizarNombreColumna((string)$c);
        }, $header);

        $req_cols = [
            'codigo',
            'usuario',
            'material',
            'categoria',
            'marca',
            'valor_propuesto',
            'fechapropuesta'
        ];

        $faltantes = array_diff($req_cols, $header_norm);
        if (!empty($faltantes)) {
            fclose($handle);
            throw new Exception("Faltan columnas requeridas en el CSV: " . implode(', ', $faltantes));
        }

        $idx_codigo          = obtenerIndiceColumna($header_norm, 'codigo');
        $idx_usuario         = obtenerIndiceColumna($header_norm, 'usuario');
        $idx_material        = obtenerIndiceColumna($header_norm, 'material');
        $idx_categoria       = obtenerIndiceColumna($header_norm, 'categoria');
        $idx_marca           = obtenerIndiceColumna($header_norm, 'marca');
        $idx_valor_propuesto = obtenerIndiceColumna($header_norm, 'valor_propuesto');
        $idx_fechapropuesta  = obtenerIndiceColumna($header_norm, 'fechapropuesta');

        $tiene_divisiones = (contarDivisionesPorEmpresa($empresa_form) > 0);

        if ($tiene_divisiones) {
            if ($id_division > 0) {
                $stmt_local          = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_empresa = ? AND id_division = ? LIMIT 1");
                $stmt_local_fallback = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_empresa = ? LIMIT 1");
            } else {
                $stmt_local          = $conn->prepare("SELECT id FROM local WHERE codigo = ? AND id_empresa = ? LIMIT 1");
                $stmt_local_fallback = null;
            }
            $stmt_usuario = $conn->prepare("SELECT id FROM usuario WHERE usuario = ? AND id_empresa = ? LIMIT 1");
        } else {
            $stmt_local          = $conn->prepare("SELECT id FROM local WHERE codigo = ? LIMIT 1");
            $stmt_local_fallback = null;
            $stmt_usuario        = $conn->prepare("SELECT id FROM usuario WHERE usuario = ? LIMIT 1");
        }

        $stmt_insert_fq = $conn->prepare("
            INSERT INTO formularioQuestion
            (pregunta, motivo, material, categoria, marca, valor, valor_propuesto, fechaPropuesta, countVisita, observacion, id_formulario, id_local, id_usuario, estado)
            VALUES ('', '', ?, ?, ?, '', ?, ?, 0, '', ?, ?, ?, 0)
        ");

        $fila = 1;
        $errores = [];
        $totalErrores = 0;
        $ok = 0;

        $cacheLocales = [];
        $cacheUsuarios = [];

        while (($data = fgetcsv($handle, 10000, $delim)) !== false) {
            $fila++;

            if ($data === [null] || $data === false) {
                continue;
            }

            $cod_local   = trim((string)($data[$idx_codigo] ?? ''));
            $usr_name    = trim((string)($data[$idx_usuario] ?? ''));
            $material    = trim((string)($data[$idx_material] ?? ''));
            $categoria   = trim((string)($data[$idx_categoria] ?? ''));
            $marca       = trim((string)($data[$idx_marca] ?? ''));
            $val_prop_s  = trim((string)($data[$idx_valor_propuesto] ?? ''));
            $fechaRaw    = trim((string)($data[$idx_fechapropuesta] ?? ''));

            $fechaProp = normalizarFechaPropuesta($fechaRaw);

            if ($cod_local === '' || $usr_name === '' || $material === '' || $categoria === '' || $marca === '' || $val_prop_s === '') {
                agregarErrorCsv($errores, $totalErrores, "Fila {$fila}: hay campos obligatorios vacíos.");
                continue;
            }

            if (!is_numeric($val_prop_s)) {
                agregarErrorCsv($errores, $totalErrores, "Fila {$fila}: valor_propuesto debe ser numérico.");
                continue;
            }

            $val_prop = (int)$val_prop_s;

            if ($fechaProp === '') {
                if ($fechaRaw === '') {
                    $fechaProp = date('Y-m-d');
                } else {
                    agregarErrorCsv($errores, $totalErrores, "Fila {$fila}: fechapropuesta inválida. Usa DD-MM-AAAA, DD/MM/AAAA, AAAA-MM-DD o AAAA/MM/DD.");
                    continue;
                }
            }

            // Buscar local con caché en memoria
            $localKey = $tiene_divisiones ? ($empresa_form . '|' . $id_division . '|' . $cod_local) : $cod_local;

            if (array_key_exists($localKey, $cacheLocales)) {
                $id_local = $cacheLocales[$localKey];
            } else {
                $id_local = null;

                // Paso 1: búsqueda con filtro de división (desambigua códigos duplicados)
                if ($tiene_divisiones) {
                    if ($id_division > 0) {
                        $stmt_local->bind_param('sii', $cod_local, $empresa_form, $id_division);
                    } else {
                        $stmt_local->bind_param('si', $cod_local, $empresa_form);
                    }
                } else {
                    $stmt_local->bind_param('s', $cod_local);
                }
                $stmt_local->execute();
                $stmt_local->bind_result($id_local_tmp);
                if ($stmt_local->fetch()) {
                    $id_local = (int)$id_local_tmp;
                }
                $stmt_local->free_result();
                $stmt_local->reset();

                // Paso 2: fallback empresa-only si no se encontró (código único en otra división)
                if ($id_local === null && $stmt_local_fallback !== null) {
                    $stmt_local_fallback->bind_param('si', $cod_local, $empresa_form);
                    $stmt_local_fallback->execute();
                    $stmt_local_fallback->bind_result($id_local_tmp2);
                    if ($stmt_local_fallback->fetch()) {
                        $id_local = (int)$id_local_tmp2;
                    }
                    $stmt_local_fallback->free_result();
                    $stmt_local_fallback->reset();
                }

                $cacheLocales[$localKey] = $id_local;
            }

            if (!$id_local) {
                agregarErrorCsv($errores, $totalErrores, "Fila {$fila}: local '{$cod_local}' no encontrado.");
                continue;
            }

            // Buscar usuario con caché en memoria
            $userKey = $tiene_divisiones ? ($empresa_form . '|' . $usr_name) : $usr_name;

            if (array_key_exists($userKey, $cacheUsuarios)) {
                $id_usuario = $cacheUsuarios[$userKey];
            } else {
                if ($tiene_divisiones) {
                    $stmt_usuario->bind_param('si', $usr_name, $empresa_form);
                } else {
                    $stmt_usuario->bind_param('s', $usr_name);
                }

                $stmt_usuario->execute();
                $stmt_usuario->bind_result($id_usuario_tmp);

                if ($stmt_usuario->fetch()) {
                    $id_usuario = (int)$id_usuario_tmp;
                    $cacheUsuarios[$userKey] = $id_usuario;
                } else {
                    $id_usuario = null;
                    $cacheUsuarios[$userKey] = null;
                }

                $stmt_usuario->free_result();
                $stmt_usuario->reset();
            }

            if (!$id_usuario) {
                agregarErrorCsv($errores, $totalErrores, "Fila {$fila}: usuario '{$usr_name}' no encontrado.");
                continue;
            }

            $stmt_insert_fq->bind_param(
                'sssisiii',
                $material,
                $categoria,
                $marca,
                $val_prop,
                $fechaProp,
                $formulario_id,
                $id_local,
                $id_usuario
            );
            $stmt_insert_fq->execute();
            $ok++;
        }

        fclose($handle);
        $stmt_local->close();
        $stmt_usuario->close();
        $stmt_insert_fq->close();

        if ($totalErrores > 0) {
            $mensaje = "Se detectaron {$totalErrores} errores en el CSV.";
            if (!empty($errores)) {
                $mensaje .= "\nPrimeros " . count($errores) . " errores:\n- " . implode("\n- ", $errores);
            }
            throw new Exception($mensaje);
        }

        $_SESSION['success_formulario'] = "Formulario insertado correctamente (ID: {$formulario_id}). Registros CSV: {$ok}";
    } else {
        $_SESSION['success_formulario'] = ($tipo === 2)
            ? "Formulario IW (ID: {$formulario_id}) creado."
            : "Formulario creado sin CSV.";
    }

    $conn->commit();

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $t) {
        }
    }

    error_log('[mod_formulario/procesar] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['error_formulario'] = "Ocurrió un error: " . $e->getMessage();
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

safeRedirect('../mod_formulario.php');
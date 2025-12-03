<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 1) Verificar sesión y método
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: gestionarIW.php");
    exit();
}

// 2) Verificar CSRF
if (
    !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
    die("Token CSRF inválido.");
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

$idCampana   = isset($_POST['idCampana']) ? intval($_POST['idCampana']) : 0;
$id_local    = 0; // Siempre 0 para IW
$usuario_id  = intval($_SESSION['usuario_id']);
$visita_id   = isset($_POST['visita_id']) ? intval($_POST['visita_id']) : 0;
$latGestion  = isset($_POST['latGestion']) ? $_POST['latGestion'] : '';
$lngGestion  = isset($_POST['lngGestion']) ? $_POST['lngGestion'] : '';

if ($idCampana <= 0) {
    die("ID de campaña no proporcionado.");
}
if ($visita_id <= 0) {
    die("Visita no válida.");
}

$empresaId = (int)$_SESSION['empresa_id'];

// Validar que la campaña IW pertenece a la empresa
$sqlCamp = "SELECT COUNT(*) FROM formulario WHERE id=? AND tipo=2 AND id_empresa=? LIMIT 1";
$stmt = $conn->prepare($sqlCamp);
$stmt->bind_param("ii", $idCampana, $empresaId);
$stmt->execute();
$stmt->bind_result($c1); $stmt->fetch(); $stmt->close();
if ((int)$c1 === 0) {
    die("Campaña inválida o sin permiso.");
}

// Validar que la visita existe, pertenece al usuario y a la campaña
$sqlVis = "SELECT id FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? LIMIT 1";
$stmt = $conn->prepare($sqlVis);
$stmt->bind_param("iii", $visita_id, $usuario_id, $idCampana);
$stmt->execute();
$stmt->bind_result($vIdFound); $stmt->fetch(); $stmt->close();
if (!$vIdFound) {
    die("Visita no encontrada o sin permisos.");
}

// Después de validar que la visita existe:
$id_local = 0;
$stmt = $conn->prepare("SELECT id_local FROM visita WHERE id=? AND id_usuario=? AND id_formulario=? LIMIT 1");
$stmt->bind_param("iii", $visita_id, $usuario_id, $idCampana);
$stmt->execute();
$stmt->bind_result($id_local);
if (!$stmt->fetch()) { $stmt->close(); die("Visita no encontrada o sin permisos."); }
$stmt->close();
$id_local = (int)$id_local;


// Normalizar coordenadas finales (para cierre de visita)
function normCoord($v, $min, $max) {
    if ($v === '' || $v === null) return null;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < $min || $f > $max) return null;
    return round($f, 6);
}
$latFin = normCoord($latGestion,  -90,  90);
$lngFin = normCoord($lngGestion, -180, 180);

// 3) Empezar transacción
$conn->begin_transaction();

try {
    // 4) Procesar respuestas no fotográficas
    if (!empty($_POST['respuesta']) && is_array($_POST['respuesta'])) {
        foreach ($_POST['respuesta'] as $qid_str => $resp) {
            $qid = intval($qid_str);

            // 4.1) Obtener tipo de pregunta y validar que pertenece a la campaña
            $stmtT = $conn->prepare("
                SELECT fq.id_question_type, fq.id_formulario
                  FROM form_questions fq
                 WHERE fq.id = ?
                 LIMIT 1
            ");
            $stmtT->bind_param("i", $qid);
            $stmtT->execute();
            $stmtT->bind_result($tipoPregunta, $idFormQ);
            $okT = $stmtT->fetch();
            $stmtT->close();

            if (!$okT || (int)$idFormQ !== $idCampana) {
                // Si alguien manipuló el form, ignoramos esta pregunta
                continue;
            }

            // inicializamos
            $answer_text = "";
            $optId       = 0;
            $valor       = 0;

            if (in_array((int)$tipoPregunta, [1, 2], true)) {
                // Radio (1) o Radio+valor (2)
                $optId = intval($resp);
                // Tomar el texto de la opción para guardar en answer_text
                $stmtO = $conn->prepare("
                    SELECT option_text
                      FROM form_question_options
                     WHERE id = ? AND id_form_question = ? LIMIT 1
                ");
                $stmtO->bind_param("ii", $optId, $qid);
                $stmtO->execute();
                $stmtO->bind_result($answer_text);
                $stmtO->fetch();
                $stmtO->close();

                if (!empty($_POST['valorRespuesta'][$qid][$optId]) &&
                    is_numeric($_POST['valorRespuesta'][$qid][$optId])) {
                    $valor = (float)$_POST['valorRespuesta'][$qid][$optId];
                }

                // INSERT unificado para radio
                $stmtI = $conn->prepare("
                    INSERT INTO form_question_responses
                      (visita_id, id_form_question, id_local, id_usuario,
                       answer_text, id_option, valor, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmtI->bind_param(
                    "iiiisid",
                    $visita_id,
                    $qid,
                    $id_local,
                    $usuario_id,
                    $answer_text,
                    $optId,
                    $valor
                );
                $stmtI->execute();
                $stmtI->close();

            } elseif ((int)$tipoPregunta === 3) {
                // Checkbox múltiple: insert por cada opción marcada
                if (is_array($resp)) {
                    foreach ($resp as $optRaw) {
                        $opt = intval($optRaw);

                        $stmtO = $conn->prepare("
                            SELECT option_text
                              FROM form_question_options
                             WHERE id = ? AND id_form_question = ? LIMIT 1
                        ");
                        $stmtO->bind_param("ii", $opt, $qid);
                        $stmtO->execute();
                        $stmtO->bind_result($answer_text);
                        $stmtO->fetch();
                        $stmtO->close();

                        if (!empty($_POST['valorRespuesta'][$qid][$opt]) &&
                            is_numeric($_POST['valorRespuesta'][$qid][$opt])) {
                            $valor = (int)$_POST['valorRespuesta'][$qid][$opt];
                        } else {
                            $valor = 0;
                        }

                        $stmtI = $conn->prepare("
                            INSERT INTO form_question_responses
                              (visita_id, id_form_question, id_local, id_usuario,
                               answer_text, id_option, valor, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmtI->bind_param(
                            "iiiisii",
                            $visita_id,
                            $qid,
                            $id_local,
                            $usuario_id,
                            $answer_text,
                            $opt,
                            $valor
                        );
                        $stmtI->execute();
                        $stmtI->close();
                    }
                }
                // ya guardamos cada opción; avanzar a la siguiente pregunta
                continue;

            } else {
                // Texto libre (4), Numérico (5), Fecha (6) y otros
                // Nota: para tipo 5 numérico seguimos guardando en answer_text (compatibilidad)
                $answer_text = is_array($resp) ? implode(", ", $resp) : trim((string)$resp);

                $stmtI = $conn->prepare("
                    INSERT INTO form_question_responses
                      (visita_id, id_form_question, id_local, id_usuario,
                       answer_text, id_option, valor, created_at)
                    VALUES (?, ?, ?, ?, ?, 0, 0, NOW())
                ");
                $stmtI->bind_param(
                    "iiiis",
                    $visita_id,
                    $qid,
                    $id_local,
                    $usuario_id,
                    $answer_text
                );
                $stmtI->execute();
                $stmtI->close();
            }
        }
    }

    // 5) Cerrar visita (fecha_fin) y opcionalmente actualizar coordenadas finales
    //    Si se proveen coords válidas, las guardamos (sobrescriben las iniciales).
    if ($latFin !== null || $lngFin !== null) {
        $stmtU = $conn->prepare("
            UPDATE visita
               SET fecha_fin = NOW(),
                   latitud   = IFNULL(?, latitud),
                   longitud  = IFNULL(?, longitud)
             WHERE id = ? AND id_usuario = ? AND id_formulario = ?
             LIMIT 1
        ");
        $stmtU->bind_param("ddiii", $latFin, $lngFin, $visita_id, $usuario_id, $idCampana);
    } else {
        $stmtU = $conn->prepare("
            UPDATE visita
               SET fecha_fin = NOW()
             WHERE id = ? AND id_usuario = ? AND id_formulario = ?
             LIMIT 1
        ");
        $stmtU->bind_param("iii", $visita_id, $usuario_id, $idCampana);
    }
    $stmtU->execute();
    $stmtU->close();

    // 6) Commit y redirección
    $conn->commit();

    // Limpia la visita en sesión para esta campaña (ya cerrada)
    $key = $idCampana . ':' . (int)$id_local;
    if (isset($_SESSION['iw_visitas'][$key])) {
        unset($_SESSION['iw_visitas'][$key]);
    }

    $_SESSION['success'] = "Encuesta enviada correctamente.";
    if (isset($conn)) { $conn->close(); }
    header("Location: index.php");
    exit;

} catch (Exception $e) {
    // Rollback y manejo de error
    try { $conn->rollback(); } catch (Throwable $t) {}
    $_SESSION['error'] = "Error al procesar la encuesta: " . $e->getMessage();
    if (isset($conn)) { $conn->close(); }
    header("Location: gestionarIW.php?idCampana=" . $idCampana);
    exit;
}

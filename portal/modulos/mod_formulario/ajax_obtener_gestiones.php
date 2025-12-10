<?php
// File: /visibility2/portal/modulos/mod_formulario/ajax_obtener_gestiones.php

// Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Conexión y sesión
include_once __DIR__ . '/../db.php';
include_once __DIR__ . '/../session_data.php';

// 1) Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Debes iniciar sesión.'
    ]);
    exit;
}

// 2) En POST, verificar token CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Token CSRF inválido.'
        ]);
        exit;
    }
}

// 3) Leer parámetros básicos
$formulario_id = isset($_REQUEST['formulario_id']) ? intval($_REQUEST['formulario_id']) : 0;
$local_id      = isset($_REQUEST['local_id'])      ? intval($_REQUEST['local_id'])      : 0;
$user_id       = isset($_REQUEST['user_id'])       ? intval($_REQUEST['user_id'])       : 0;

if (!$formulario_id || !$local_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros obligatorios faltan (formulario_id, local_id).'
    ]);
    exit;
}

// ---------------------------
// 4) MUTACIONES (POST)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Función auxiliar para borrar fotos de una implementación
    function borrarFotosDeImplementacion($conn, $impl_id) {
        $stmtF = $conn->prepare("
            SELECT url
            FROM fotoVisita
            WHERE id_formularioQuestion = ?
        ");
        $stmtF->bind_param("i", $impl_id);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        while ($row = $resF->fetch_assoc()) {
            $path = __DIR__ . '/../../app/' . ltrim($row['url'], '/');
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $stmtF->close();

        $stmtD = $conn->prepare("
            DELETE FROM fotoVisita
            WHERE id_formularioQuestion = ?
        ");
        $stmtD->bind_param("i", $impl_id);
        $stmtD->execute();
        $stmtD->close();
    }

    // Función auxiliar para borrar todas las respuestas de encuesta de un usuario en este formulario/local
    function eliminarRespuestasEncuesta($conn, $formulario_id, $local_id, $user_id) {
        $stmt = $conn->prepare("
            DELETE fqr
            FROM form_question_responses fqr
            INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
            WHERE fq.id_formulario = ? AND fqr.id_local = ? AND fqr.id_usuario = ?
        ");
        $stmt->bind_param("iii", $formulario_id, $local_id, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    switch ($action) {
        // 4.1) Borrar TODAS las respuestas de encuesta de un usuario
        case 'clear_responses':
            if (!$user_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe especificar un usuario (user_id).'
                ]);
                exit;
            }
            $ok = eliminarRespuestasEncuesta($conn, $formulario_id, $local_id, $user_id);
            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Respuestas borradas correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al borrar respuestas.'
                ]);
            }
            exit;

        // 4.2) Recargar gestión: resetear TODOS los campos de implementación y BORRAR respuestas de encuesta
        case 'reset_local':
            if (!$user_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe especificar un usuario (user_id).'
                ]);
                exit;
            }
            // 1) Resetear las filas en formularioQuestion de este usuario
            $stmt1 = $conn->prepare("
                UPDATE formularioQuestion
                SET countVisita = 0,
                    valor        = NULL,
                    motivo       = NULL,
                    observacion  = NULL,
                    pregunta     = NULL,
                    fechaVisita  = NULL
                WHERE id_formulario = ? AND id_local = ? AND id_usuario = ?
            ");
            $stmt1->bind_param("iii", $formulario_id, $local_id, $user_id);
            $ok1 = $stmt1->execute();
            $stmt1->close();

            // 2) Borrar respuestas de encuesta
            $ok2 = eliminarRespuestasEncuesta($conn, $formulario_id, $local_id, $user_id);

            if ($ok1 && $ok2) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Gestión recargada correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al recargar la gestión.'
                ]);
            }
            exit;

        // 4.3) Eliminar una implementación individual (formularioQuestion + fotos asociadas)
        case 'delete_impl':
            $impl_id = intval($_POST['impl_id'] ?? 0);
            if (!$impl_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de implementación inválido.'
                ]);
                exit;
            }
            // 1) borrar fotos en disco y en DB
            borrarFotosDeImplementacion($conn, $impl_id);
            // 2) borrar el registro
            $stmtQ = $conn->prepare("
                DELETE FROM formularioQuestion
                WHERE id = ? AND id_formulario = ? AND id_local = ?
            ");
            $stmtQ->bind_param("iii", $impl_id, $formulario_id, $local_id);
            $ok = $stmtQ->execute();
            $stmtQ->close();

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Implementación eliminada correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al eliminar implementación.'
                ]);
            }
            exit;

        // 4.4) Eliminar una respuesta de encuesta individual
        case 'delete_resp':
            $resp_id = intval($_POST['resp_id'] ?? 0);
            if (!$resp_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de respuesta inválido.'
                ]);
                exit;
            }
            $stmtR = $conn->prepare("
                DELETE FROM form_question_responses
                WHERE id = ?
            ");
            $stmtR->bind_param("i", $resp_id);
            $ok = $stmtR->execute();
            $stmtR->close();

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Respuesta eliminada correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al eliminar respuesta.'
                ]);
            }
            exit;

        // 4.5) Limpiar/“reset” un solo material (fields + fotos) de una fila de formularioQuestion
        case 'clear_material':
            $impl_id = intval($_POST['impl_id'] ?? 0);
            if (!$impl_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de implementación inválido.'
                ]);
                exit;
            }
            // 1) Resetear campos de esa fila
            $stmtU = $conn->prepare("
                UPDATE formularioQuestion
                SET countVisita = 0,
                    valor        = NULL,
                    motivo       = NULL,
                    observacion  = NULL,
                    pregunta     = NULL,
                    fechaVisita  = NULL
                WHERE id = ? AND id_formulario = ? AND id_local = ?
            ");
            $stmtU->bind_param("iii", $impl_id, $formulario_id, $local_id);
            $ok1 = $stmtU->execute();
            $stmtU->close();

            // 2) Borrar fotos asociadas
            borrarFotosDeImplementacion($conn, $impl_id);

            if ($ok1) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Material limpiado correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al limpiar material.'
                ]);
            }
            exit;

        // 4.6) Actualizar inline el campo “material” de una fila de formularioQuestion
        case 'update_material':
            $impl_id  = intval($_POST['impl_id'] ?? 0);
            $material = trim($_POST['material'] ?? '');
            if (!$impl_id || $material === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de implementación o nombre de material inválido.'
                ]);
                exit;
            }
            $stmtM = $conn->prepare("
                UPDATE formularioQuestion
                SET material = ?
                WHERE id = ? AND id_formulario = ? AND id_local = ?
            ");
            $stmtM->bind_param("siii", $material, $impl_id, $formulario_id, $local_id);
            $ok = $stmtM->execute();
            $stmtM->close();

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Material actualizado correctamente.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error al actualizar material.'
                ]);
            }
            exit;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Acción desconocida.'
            ]);
            exit;
    }
}

// ---------------------------
// 5) LECTURA DE DATOS (GET?action=fetch)
// ---------------------------
$action = $_GET['action'] ?? '';
if ($action !== 'fetch') {
    echo json_encode([
        'success' => false,
        'message' => 'Acción no permitida en GET.'
    ]);
    exit;
}

// 5.1) Paginación para implementaciones
$page_impl     = max(1, intval($_GET['page_impl']     ?? 1));
$per_page_impl = max(1, intval($_GET['per_page_impl'] ?? 10));
$offset_impl   = ($page_impl - 1) * $per_page_impl;

// 5.2) Paginación para respuestas de encuesta
$page_resp     = max(1, intval($_GET['page_resp']     ?? 1));
$per_page_resp = max(1, intval($_GET['per_page_resp'] ?? 10));
$offset_resp   = ($page_resp - 1) * $per_page_resp;

// 5.3) Obtener lista de usuarios (“ejecutores”) distintos en este formulario/local
$sql_users = "
    SELECT DISTINCT u.id AS uid, u.usuario
    FROM (
      SELECT id_usuario
      FROM formularioQuestion
      WHERE id_formulario = ? AND id_local = ?

      UNION

      SELECT fqr.id_usuario
      FROM form_question_responses fqr
      INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
      WHERE fq.id_formulario = ? AND fqr.id_local = ?
    ) AS tmp
    JOIN usuario u ON u.id = tmp.id_usuario
    ORDER BY u.usuario ASC
";
$stmt = $conn->prepare($sql_users);
$stmt->bind_param("iiii",
    $formulario_id, $local_id,
    $formulario_id, $local_id
);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Si no se proporcionó user_id, devolvemos únicamente la lista de usuarios
$total_impl   = 0;
$implementaciones = [];
$total_resp   = 0;
$respuestas       = [];

if ($user_id) {
    // 5.4) Contar total implementaciones de este user
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM formularioQuestion
        WHERE id_formulario = ? AND id_local = ? AND id_usuario = ?
    ");
    $stmt->bind_param("iii", $formulario_id, $local_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($total_impl);
    $stmt->fetch();
    $stmt->close();

    // 5.5) Traer implementaciones paginadas + fotos concatenadas
    $stmt = $conn->prepare("
        SELECT
          fq.id,
          fq.id_usuario,
          u.usuario        AS nombre_usuario,
          fq.material,
          fq.valor_propuesto,
          fq.valor,
          fq.fechaVisita,
          fq.motivo,
          fq.observacion,
          GROUP_CONCAT(fv.url SEPARATOR ',') AS fotos_urls
        FROM formularioQuestion fq
        LEFT JOIN usuario u ON u.id = fq.id_usuario
        LEFT JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_formulario = ? AND fq.id_local = ? AND fq.id_usuario = ?
        GROUP BY fq.id
        ORDER BY fq.fechaVisita ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii",
        $formulario_id, $local_id, $user_id,
        $per_page_impl, $offset_impl
    );
    $stmt->execute();
    $implementaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 5.6) Contar total respuestas de encuesta de este user
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM form_question_responses fqr
        INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
        WHERE fq.id_formulario = ? AND fqr.id_local = ? AND fqr.id_usuario = ?
    ");
    $stmt->bind_param("iii", $formulario_id, $local_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($total_resp);
    $stmt->fetch();
    $stmt->close();

    // 5.7) Traer respuestas de encuesta paginadas
    // Asumimos que en la tabla form_question_responses hay columnas:
    //   - response_text (texto), response_photo_url (url de foto), response_value (numérico), created_at
    // Si tu BD solo tiene “answer_text” que puede ser foto o texto,
    // dejamos la misma lógica de “si termina en .jpg/.png, tratamos como foto”.
    $stmt = $conn->prepare("
        SELECT
          fqr.id,
          fqr.id_usuario,
          u.usuario      AS nombre_usuario,
          fq.question_text,
          fqr.answer_text AS answer_text,
          fqr.created_at
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        LEFT JOIN usuario u ON u.id = fqr.id_usuario
        WHERE fq.id_formulario = ? AND fqr.id_local = ? AND fqr.id_usuario = ?
        ORDER BY fqr.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bind_param("iiiii",
        $formulario_id, $local_id, $user_id,
        $per_page_resp, $offset_resp
    );
    $stmt->execute();
    $respuestas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 6) Armar JSON de respuesta
echo json_encode([
    'success' => true,
    'data' => [
        'users' => $users,
        'selected_user' => $user_id,
        'pagination' => [
            'implementaciones' => [
                'total'     => $total_impl,
                'page'      => $page_impl,
                'per_page'  => $per_page_impl,
                'last_page' => (int) ceil($total_impl / $per_page_impl)
            ],
            'respuestas' => [
                'total'     => $total_resp,
                'page'      => $page_resp,
                'per_page'  => $per_page_resp,
                'last_page' => (int) ceil($total_resp / $per_page_resp)
            ]
        ],
        'implementaciones' => $implementaciones,
        'respuestas'       => $respuestas
    ]
]);

<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Validar parámetros obligatorios
if (!isset($_GET['formulario_id']) || !isset($_GET['page'])) {
    exit();
}

$formulario_id = intval($_GET['formulario_id']);
$page = intval($_GET['page']);
$limit = 10;
$offset = ($page - 1) * $limit;
$base_url = "https://visibility.cl/visibility2/app/";

// Capturar filtros y view
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date   = isset($_GET['end_date'])   ? trim($_GET['end_date'])   : '';
$user_id    = isset($_GET['user_id'])    ? intval($_GET['user_id'])  : 0;
$view = isset($_GET['view']) ? trim($_GET['view']) : 'implementacion';

// Primero, obtener el tipo de formulario
$stmt_tipo = $conn->prepare("SELECT tipo FROM formulario WHERE id = ?");
$stmt_tipo->bind_param("i", $formulario_id);
$stmt_tipo->execute();
$stmt_tipo->bind_result($tipoForm);
if (!$stmt_tipo->fetch()) {
    echo json_encode(["html" => "", "groupData" => []]);
    exit();
}
$stmt_tipo->close();

// Armar la query según tipo y view
if ($tipoForm == 1) {
    if ($view === 'implementacion') {
        // Para campañas tipo 1, vista implementación: usar fotoVisita
        $sql = "
            SELECT
                fq.id AS fq_id,
                fq.material,
                fq.fechaVisita,
                fv.id AS foto_id,
                fv.url,
                l.codigo AS local_codigo,
                l.nombre AS local_nombre,
                l.direccion AS local_direccion,
                c.nombre AS cadena_nombre,
                f.nombre AS formulario_nombre,
                fv.id_usuario AS user_id,
                u.usuario AS user_name
            FROM formularioQuestion fq
            JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
            JOIN local l ON l.id = fq.id_local
            JOIN cadena c ON c.id = l.id_cadena
            JOIN formulario f ON f.id = fq.id_formulario
            LEFT JOIN usuario u ON u.id = fv.id_usuario
            WHERE f.id = ?
              AND fq.fechaVisita IS NOT NULL
        ";
        if (!empty($start_date)) {
            $sql .= " AND DATE(fq.fechaVisita) >= ? ";
        }
        if (!empty($end_date)) {
            $sql .= " AND DATE(fq.fechaVisita) <= ? ";
        }
        if ($user_id > 0) {
            $sql .= " AND fv.id_usuario = ? ";
        }
        $sql .= " ORDER BY fq.fechaVisita ASC LIMIT ? OFFSET ?";
    } else {
        // Para campañas tipo 1, vista encuesta: usar form_question_responses (sin filtrar id_local)
        $sql = "
            SELECT
                fqr.id AS foto_id,
                fqr.answer_text AS url,
                fqr.created_at AS fechaSubida,
                DATE(fqr.created_at) AS fechaDia,
                f.nombre AS formulario_nombre,
                fqr.id_usuario AS user_id,
                u.usuario AS user_name
            FROM form_question_responses fqr
            JOIN form_questions fp ON fp.id = fqr.id_form_question
            JOIN formulario f ON f.id = fp.id_formulario
            JOIN usuario u ON u.id = fqr.id_usuario
            WHERE f.id = ?
              AND fp.id_question_type = 7
            ORDER BY fqr.created_at ASC LIMIT ? OFFSET ?
        ";
    }
} else {
    // Campañas tipo 2: se usa form_question_responses con id_local = 0
    $sql = "
        SELECT
            fqr.id AS foto_id,
            fqr.answer_text AS url,
            fqr.created_at AS fechaSubida,
            DATE(fqr.created_at) AS fechaDia,
            f.nombre AS formulario_nombre,
            fqr.id_usuario AS user_id,
            u.usuario AS user_name
        FROM form_question_responses fqr
        JOIN form_questions fp ON fp.id = fqr.id_form_question
        JOIN formulario f ON f.id = fp.id_formulario
        JOIN usuario u ON u.id = fqr.id_usuario
        WHERE f.id = ?
          AND fp.id_question_type = 7
          AND fqr.id_local = 0
        ORDER BY fqr.created_at ASC LIMIT ? OFFSET ?
    ";
}

// Preparar parámetros
if ($tipoForm == 1 && $view === 'implementacion') {
    $stmt_types = 'i';
    $stmt_params = [$formulario_id];
    if (!empty($start_date)) {
        $stmt_types .= 's';
        $stmt_params[] = $start_date;
    }
    if (!empty($end_date)) {
        $stmt_types .= 's';
        $stmt_params[] = $end_date;
    }
    if ($user_id > 0) {
        $stmt_types .= 'i';
        $stmt_params[] = $user_id;
    }
    $stmt_types .= 'ii';
    $stmt_params[] = $limit;
    $stmt_params[] = $offset;
} else {
    // Para vista encuesta (tipo1 y tipo2)
    $stmt_types = 'i';
    $stmt_params = [$formulario_id];
    if (!empty($start_date)) {
        $stmt_types .= 's';
        $stmt_params[] = $start_date;
    }
    if (!empty($end_date)) {
        $stmt_types .= 's';
        $stmt_params[] = $end_date;
    }
    if ($user_id > 0) {
        $stmt_types .= 'i';
        $stmt_params[] = $user_id;
    }
    $stmt_types .= 'ii';
    $stmt_params[] = $limit;
    $stmt_params[] = $offset;
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["html" => "", "groupData" => []]);
    exit();
}
$stmt->bind_param($stmt_types, ...$stmt_params);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["html" => "", "groupData" => []]);
    exit();
}

$groupData = [];
if ($tipoForm == 1 && $view === 'implementacion') {
    // Agrupar por fq_id
    while ($row = $result->fetch_assoc()) {
        $fq_id = $row['fq_id'];
        if (!isset($groupData[$fq_id])) {
            $groupData[$fq_id] = [
                'fq_id'             => $fq_id,
                'material'          => $row['material'],
                'fechaVisita'       => $row['fechaVisita'],
                'local_codigo'      => $row['local_codigo'],
                'local_nombre'      => $row['local_nombre'],
                'local_direccion'   => $row['local_direccion'],
                'cadena_nombre'     => $row['cadena_nombre'],
                'formulario_nombre' => $row['formulario_nombre'],
                'fotos'             => []
            ];
        }
        $groupData[$fq_id]['fotos'][] = [
            'foto_id'   => $row['foto_id'],
            'url'       => $row['url'],
            'user_id'   => $row['user_id'],
            'user_name' => $row['user_name']
        ];
    }
} else {
    // Para vista encuesta (tipo1 y tipo2): agrupar por fechaDia
    while ($row = $result->fetch_assoc()) {
        $fechaDia = $row['fechaDia'];
        if (!isset($groupData[$fechaDia])) {
            $groupData[$fechaDia] = [
                'fechaDia'          => $fechaDia,
                'formulario_nombre' => $row['formulario_nombre'],
                'fotos'             => []
            ];
        }
        $groupData[$fechaDia]['fotos'][] = [
            'foto_id'    => $row['foto_id'],
            'url'        => $row['url'],
            'user_id'    => $row['user_id'],
            'user_name'  => $row['user_name'],
            'fechaSubida'=> $row['fechaSubida']
        ];
    }
}
$stmt->close();

// Funciones para formatear fechas
function formatearFecha($fecha) {
    if (!$fecha) return '';
    return date('d/m/Y H:i:s', strtotime($fecha));
}
function formatearFechaDia($fecha) {
    if (!$fecha) return '';
    return date('d/m/Y', strtotime($fecha));
}

// Para la salida, el HTML dependerá de si es vista implementacion (agrupado por fq_id) o encuesta (agrupado por fecha)
$html = "";
if ($tipoForm == 1 && $view === 'implementacion') {
    $i = $offset + 1;
    foreach ($groupData as $fq_id => $group) {
        $cantFotos = count($group['fotos']);
        $firstUrl = $base_url . $group['fotos'][0]['url'];
        $fechaVisita = formatearFecha($group['fechaVisita']);
        $html .= "<tr>";
        $html .= "<td>" . $i . "</td>";
        $html .= "<td class='custom-img-cell'>";
        $html .= "<a href='javascript:void(0)' onclick='openModalCarousel(" . (int)$fq_id . ")'>";
        $html .= "<img src='" . htmlspecialchars($firstUrl) . "' alt='Foto' class='thumbnail' loading='lazy'>";
        if ($cantFotos > 1) {
            $html .= "<span class='badge badge-pill badge-info photo-count-badge'>" . $cantFotos . "</span>";
        }
        $html .= "</a></td>";
        $html .= "<td><input type='checkbox' name='seleccionadosMaterial[]' value='" . (int)$fq_id . "'></td>";
        $html .= "<td>" . htmlspecialchars($group['local_codigo']) . "</td>";
        $html .= "<td>" . htmlspecialchars($group['local_nombre']) . "</td>";
        $html .= "<td>" . htmlspecialchars($group['local_direccion']) . "</td>";
        $html .= "<td>" . htmlspecialchars($group['material']) . "</td>";
        $html .= "<td>" . htmlspecialchars($group['cadena_nombre']) . "</td>";
        $html .= "<td>" . htmlspecialchars($fechaVisita) . "</td>";
        $html .= "</tr>";
        $i++;
    }
} else {
    // Vista encuesta (para tipo1 (encuesta) y tipo2)
    $i = $offset + 1;
    foreach ($groupData as $fechaDia => $group) {
        $html .= "<tr style='background-color:#f0f0f0;'><td colspan='5'><strong>Fecha: " . formatearFechaDia($fechaDia) . "</strong></td></tr>";
        foreach ($group['fotos'] as $foto) {
            $fotoUrl = $base_url . $foto['url'];
            $usuarioName = isset($foto['user_name']) ? htmlspecialchars($foto['user_name']) : 'N/A';
            $fechaSubida = isset($foto['fechaSubida']) ? formatearFecha($foto['fechaSubida']) : 'N/A';
            $fotoId = (int)$foto['foto_id'];
            $html .= "<tr>";
            $html .= "<td>" . $i . "</td>";
            $html .= "<td class='custom-img-cell'>";
            $html .= "<a href='javascript:void(0)' onclick='openModalCarousel(" . $fotoId . ")'>";
            $html .= "<img src='" . htmlspecialchars($fotoUrl) . "' alt='Foto' class='thumbnail' loading='lazy'>";
            $html .= "</a></td>";
            $html .= "<td><input type='checkbox' name='seleccionadosMaterial[]' value='" . $fotoId . "'></td>";
            $html .= "<td>" . $usuarioName . "</td>";
            $html .= "<td>" . $fechaSubida . "</td>";
            $html .= "</tr>";
            $i++;
        }
    }
}

echo json_encode(["html" => $html, "groupData" => $groupData]);
?>

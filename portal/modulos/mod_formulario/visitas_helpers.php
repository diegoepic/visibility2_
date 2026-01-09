<?php

function obtenerLocalesProgramados(mysqli $conn, int $formularioId): array
{
    $stmt = $conn->prepare(
        "SELECT fq.id, fq.id_local, l.codigo, l.nombre, l.direccion, fq.fechaPropuesta, fq.estado
         FROM formularioQuestion fq
         LEFT JOIN local l ON l.id = fq.id_local
         WHERE fq.id_formulario = ?
         ORDER BY fq.fechaPropuesta ASC, fq.id ASC"
    );
    $stmt->bind_param('i', $formularioId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function obtenerUsuariosVisitas(mysqli $conn, int $formularioId): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.id, u.usuario
         FROM visita v
         JOIN usuario u ON u.id = v.id_usuario
         WHERE v.id_formulario = ?
         ORDER BY u.usuario ASC"
    );
    $stmt->bind_param('i', $formularioId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function construirFiltrosVisitas(array $filters): array
{
    $where = "WHERE v.id_formulario = ?";
    $params = [(int)$filters['formulario_id']];
    $types = "i";

    if (!empty($filters['usuario_id'])) {
        $where .= " AND v.id_usuario = ?";
        $params[] = (int)$filters['usuario_id'];
        $types .= "i";
    }

    if (!empty($filters['fecha_desde'])) {
        $where .= " AND v.fecha_inicio >= ?";
        $params[] = $filters['fecha_desde'];
        $types .= "s";
    }

    if (!empty($filters['fecha_hasta'])) {
        $where .= " AND v.fecha_inicio <= ?";
        $params[] = $filters['fecha_hasta'];
        $types .= "s";
    }

    return [$where, $params, $types];
}

function obtenerListadoVisitas(mysqli $conn, array $filters): array
{
    $formularioId = (int)$filters['formulario_id'];
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 25));
    $offset = ($page - 1) * $perPage;

    [$where, $params, $types] = construirFiltrosVisitas($filters);

    $stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM visita v $where");
    $stmtCount->bind_param($types, ...$params);
    $stmtCount->execute();
    $resultCount = $stmtCount->get_result();
    $totalRows = (int)($resultCount->fetch_assoc()['total'] ?? 0);
    $stmtCount->close();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $sql = "
        SELECT
            v.id,
            v.fecha_inicio,
            v.fecha_fin,
            v.estado,
            l.id AS id_local,
            l.codigo,
            l.nombre AS local_nombre,
            l.direccion,
            ca.nombre AS cadena,
            u.usuario,
            COALESCE(NULLIF(v.estado, ''), gv.estado_gestion) AS estado_visita
        FROM visita v
        JOIN local l ON l.id = v.id_local
        LEFT JOIN cadena ca ON ca.id = l.id_cadena
        LEFT JOIN usuario u ON u.id = v.id_usuario
        LEFT JOIN (
            SELECT gv1.visita_id, gv1.estado_gestion
            FROM gestion_visita gv1
            JOIN (
                SELECT visita_id,
                       MAX(CONCAT(DATE_FORMAT(fecha_visita, '%Y%m%d%H%i%s'), LPAD(id, 10, '0'))) AS max_key
                FROM gestion_visita
                WHERE id_formulario = ?
                GROUP BY visita_id
            ) last_gv ON last_gv.visita_id = gv1.visita_id
              AND CONCAT(DATE_FORMAT(gv1.fecha_visita, '%Y%m%d%H%i%s'), LPAD(gv1.id, 10, '0')) = last_gv.max_key
            WHERE gv1.id_formulario = ?
        ) gv ON gv.visita_id = v.id
        $where
        ORDER BY v.fecha_inicio DESC, v.id DESC
        LIMIT ? OFFSET ?
    ";

    $paramsList = array_merge([$formularioId, $formularioId], $params, [$perPage, $offset]);
    $typesList = "ii" . $types . "ii";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typesList, ...$paramsList);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'rows' => $rows,
        'total' => $totalRows,
        'total_pages' => $totalPages,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function obtenerVisitasExportar(mysqli $conn, array $filters): array
{
    $formularioId = (int)$filters['formulario_id'];
    [$where, $params, $types] = construirFiltrosVisitas($filters);

    $sql = "
        SELECT
            v.id,
            v.fecha_inicio,
            v.fecha_fin,
            v.estado,
            l.codigo,
            l.nombre AS local_nombre,
            l.direccion,
            ca.nombre AS cadena,
            u.usuario,
            COALESCE(NULLIF(v.estado, ''), gv.estado_gestion) AS estado_visita
        FROM visita v
        JOIN local l ON l.id = v.id_local
        LEFT JOIN cadena ca ON ca.id = l.id_cadena
        LEFT JOIN usuario u ON u.id = v.id_usuario
        LEFT JOIN (
            SELECT gv1.visita_id, gv1.estado_gestion
            FROM gestion_visita gv1
            JOIN (
                SELECT visita_id,
                       MAX(CONCAT(DATE_FORMAT(fecha_visita, '%Y%m%d%H%i%s'), LPAD(id, 10, '0'))) AS max_key
                FROM gestion_visita
                WHERE id_formulario = ?
                GROUP BY visita_id
            ) last_gv ON last_gv.visita_id = gv1.visita_id
              AND CONCAT(DATE_FORMAT(gv1.fecha_visita, '%Y%m%d%H%i%s'), LPAD(gv1.id, 10, '0')) = last_gv.max_key
            WHERE gv1.id_formulario = ?
        ) gv ON gv.visita_id = v.id
        $where
        ORDER BY v.fecha_inicio DESC, v.id DESC
    ";

    $paramsList = array_merge([$formularioId, $formularioId], $params);
    $typesList = "ii" . $types;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($typesList, ...$paramsList);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function obtenerDetalleVisita(mysqli $conn, int $formularioId, int $visitaId): array
{
    $visitInfo = null;
    $stmt = $conn->prepare(
        "SELECT v.id, v.fecha_inicio, v.fecha_fin, v.latitud, v.longitud, v.estado,
                l.id AS id_local, l.codigo, l.nombre AS local_nombre, l.direccion,
                ca.nombre AS cadena, u.usuario
         FROM visita v
         JOIN local l ON l.id = v.id_local
         LEFT JOIN cadena ca ON ca.id = l.id_cadena
         LEFT JOIN usuario u ON u.id = v.id_usuario
         WHERE v.id = ? AND v.id_formulario = ?"
    );
    $stmt->bind_param('ii', $visitaId, $formularioId);
    $stmt->execute();
    $result = $stmt->get_result();
    $visitInfo = $result->fetch_assoc();
    $stmt->close();

    if (!$visitInfo) {
        $stmtFallback = $conn->prepare(
            "SELECT gv.visita_id AS id, gv.id_local, l.codigo, l.nombre AS local_nombre, l.direccion,
                    ca.nombre AS cadena, u.usuario
             FROM gestion_visita gv
             JOIN local l ON l.id = gv.id_local
             LEFT JOIN cadena ca ON ca.id = l.id_cadena
             LEFT JOIN usuario u ON u.id = gv.id_usuario
             WHERE gv.visita_id = ? AND gv.id_formulario = ?
             ORDER BY gv.fecha_visita DESC, gv.id DESC
             LIMIT 1"
        );
        $stmtFallback->bind_param('ii', $visitaId, $formularioId);
        $stmtFallback->execute();
        $resultFallback = $stmtFallback->get_result();
        $visitInfo = $resultFallback->fetch_assoc();
        $stmtFallback->close();
    }

    if (!$visitInfo) {
        return [
            'visit' => null,
            'estado_local' => [],
            'implementados' => [],
            'no_implementados' => [],
            'respuestas' => [],
        ];
    }

    $localId = (int)$visitInfo['id_local'];

    $stmtEstado = $conn->prepare(
        "SELECT gv.estado_gestion, gv.observacion, gv.foto_url
         FROM gestion_visita gv
         WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ? AND gv.id_formularioQuestion = 0"
    );
    $stmtEstado->bind_param('iii', $visitaId, $formularioId, $localId);
    $stmtEstado->execute();
    $estadoLocal = $stmtEstado->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtEstado->close();

    $stmtImpOk = $conn->prepare(
        "SELECT gv.id, gv.id_formularioQuestion, m.nombre AS material, gv.valor_real, gv.observacion
         FROM gestion_visita gv
         LEFT JOIN material m ON m.id = gv.id_material
         WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ? AND gv.valor_real > 0
         ORDER BY gv.fecha_visita ASC"
    );
    $stmtImpOk->bind_param('iii', $visitaId, $formularioId, $localId);
    $stmtImpOk->execute();
    $implementados = $stmtImpOk->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtImpOk->close();

    $stmtImpNo = $conn->prepare(
        "SELECT gv.id, gv.id_formularioQuestion, m.nombre AS material, gv.observacion AS observacion_no_impl
         FROM gestion_visita gv
         LEFT JOIN material m ON m.id = gv.id_material
         WHERE gv.visita_id = ? AND gv.id_formulario = ? AND gv.id_local = ? AND gv.valor_real = 0 AND gv.id_formularioQuestion <> 0
         ORDER BY gv.fecha_visita ASC"
    );
    $stmtImpNo->bind_param('iii', $visitaId, $formularioId, $localId);
    $stmtImpNo->execute();
    $noImplementados = $stmtImpNo->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtImpNo->close();

    $stmtResps = $conn->prepare(
        "SELECT fqr.id, fq.question_text, fqr.answer_text, fqr.created_at
         FROM form_question_responses fqr
         JOIN form_questions fq ON fq.id = fqr.id_form_question
         WHERE fqr.visita_id = ? AND fqr.id_local = ? AND fq.id_formulario = ?
         ORDER BY fqr.created_at ASC"
    );
    $stmtResps->bind_param('iii', $visitaId, $localId, $formularioId);
    $stmtResps->execute();
    $respuestas = $stmtResps->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtResps->close();

    return [
        'visit' => $visitInfo,
        'estado_local' => $estadoLocal,
        'implementados' => $implementados,
        'no_implementados' => $noImplementados,
        'respuestas' => $respuestas,
    ];
}

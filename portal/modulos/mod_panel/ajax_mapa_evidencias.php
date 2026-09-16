<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['usuario_id']) || empty($_SESSION['empresa_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'La sesión expiró. Vuelve a iniciar sesión.']);
    exit;
}
$mapaDetalleLocalId = (int)($_GET['id_local'] ?? 0);
if ($mapaDetalleLocalId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Local inválido.']);
    exit;
}

try {
    $_GET['buscar'] = 1;
    require __DIR__ . '/mapa_data.php';
    require __DIR__ . '/mapa_evidencias.php';
    session_write_close();
    if (!$locales) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'El local no está disponible con los filtros y permisos actuales.']);
        exit;
    }

    // Sólo campañas y ejecutores autorizados por la consulta del mapa.
    $pairs = [];
    foreach ($locales as $row) {
        $pairs[(int)$row['id_formulario'] . ':' . (int)$row['id_usuario']] = [(int)$row['id_formulario'], (int)$row['id_usuario']];
    }
    $scope = function (string $campaign, string $user) use ($pairs): array {
        $clauses = []; $params = [];
        foreach ($pairs as $pair) {
            $clauses[] = "($campaign = ? AND $user = ?)";
            $params = array_merge($params, $pair);
        }
        return ['(' . implode(' OR ', $clauses) . ')', str_repeat('ii', count($pairs)), $params];
    };
    $items = []; $seen = [];

    [$where, $types, $params] = $scope('fv.id_formulario', 'fv.id_usuario');
    $photos = fetchAllPrepared($conn, "
        SELECT fv.url, fv.visita_id, fv.id_formulario, fv.id_usuario,
               COALESCE(NULLIF(m.nombre, ''), NULLIF(fq.material, ''), 'Material sin nombre') AS etiqueta,
               f.nombre AS nombre_campana, u.usuario AS usuario_local,
               COALESCE(v.fecha_inicio, fq.fechaVisita) AS fecha
        FROM fotoVisita fv
        JOIN formulario f ON f.id = fv.id_formulario AND f.id_empresa = ?
        LEFT JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
            AND fq.id_formulario = fv.id_formulario AND fq.id_local = fv.id_local
        LEFT JOIN material m ON m.id = fv.id_material
        LEFT JOIN visita v ON v.id = fv.visita_id AND v.id_formulario = fv.id_formulario AND v.id_local = fv.id_local
        LEFT JOIN usuario u ON u.id = fv.id_usuario
        WHERE fv.id_local = ? AND $where
        ORDER BY fecha DESC, fv.id DESC", 'ii' . $types, array_merge([$id_empresa, $mapaDetalleLocalId], $params));
    foreach ($photos as $row) {
        mapaEvidenciaAgregar($items, $seen, $row, 'implementacion', $row['etiqueta'], (string)$row['url'], true);
    }

    [$where, $types, $params] = $scope('q.id_formulario', 'r.id_usuario');
    $answers = fetchAllPrepared($conn, "
        SELECT r.answer_text, r.visita_id, q.id_formulario, r.id_usuario,
               q.question_text AS etiqueta, q.id_question_type,
               f.nombre AS nombre_campana, u.usuario AS usuario_local, r.created_at AS fecha
        FROM form_question_responses r
        JOIN form_questions q ON q.id = r.id_form_question
        JOIN formulario f ON f.id = q.id_formulario AND f.id_empresa = ?
        LEFT JOIN usuario u ON u.id = r.id_usuario
        WHERE r.id_local = ? AND $where
        ORDER BY r.created_at DESC, r.id DESC", 'ii' . $types, array_merge([$id_empresa, $mapaDetalleLocalId], $params));
    foreach ($answers as $row) {
        mapaEvidenciaAgregar($items, $seen, $row, 'auditoria', $row['etiqueta'] ?: 'Pregunta sin título', (string)$row['answer_text'], (int)$row['id_question_type'] === 7);
    }

    [$where, $types, $params] = $scope('gv.id_formulario', 'gv.id_usuario');
    $gestiones = fetchAllPrepared($conn, "
        SELECT gv.foto_url, gv.observacion, gv.estado_gestion, gv.visita_id,
               gv.id_formulario, gv.id_usuario, gv.fecha_visita AS fecha,
               f.nombre AS nombre_campana, u.usuario AS usuario_local,
               m.nombre AS material
        FROM gestion_visita gv
        JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
        LEFT JOIN usuario u ON u.id = gv.id_usuario
        LEFT JOIN material m ON m.id = gv.id_material
        WHERE gv.id_local = ? AND $where
        ORDER BY gv.fecha_visita DESC, gv.id DESC", 'ii' . $types, array_merge([$id_empresa, $mapaDetalleLocalId], $params));
    foreach ($gestiones as $row) {
        $label = mapaEstadoEtiqueta((string)$row['estado_gestion'], (string)$row['observacion']);
        if (!empty($row['material'])) $label .= ' · ' . $row['material'];
        mapaEvidenciaAgregar($items, $seen, $row, 'estado_local', $label, (string)$row['foto_url'], true);
        mapaEvidenciaAgregar($items, $seen, $row, 'estado_local', $label, (string)$row['observacion']);
    }
    // Registros antiguos guardan la foto del impedimento dentro de la observación.
    foreach ($locales as $row) {
        $row['fecha'] = trim(($row['fechaVisita'] ?? '') . ' ' . ($row['horaVisita'] ?? ''));
        mapaEvidenciaAgregar($items, $seen, $row, 'estado_local', mapaEstadoEtiqueta((string)$row['pregunta'], (string)($row['observacion'] ?? '')), (string)($row['observacion'] ?? ''));
    }
    usort($items, function ($a, $b) { return strcmp((string)$b['fecha'], (string)$a['fecha']); });
    echo json_encode(['ok' => true, 'imagenes' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[ajax_mapa_evidencias] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudieron cargar las evidencias. Inténtalo nuevamente.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}

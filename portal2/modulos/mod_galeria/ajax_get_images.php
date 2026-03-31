    <?php
    session_start();
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    // -----------------------------------------------------------------------------
    // Funciones auxiliares
    // -----------------------------------------------------------------------------
    function refValues($arr) {
        if (strnatcmp(phpversion(), '5.3') >= 0) {
            $refs = [];
            foreach ($arr as $key => $value) {
                $refs[$key] = &$arr[$key];
            }
            return $refs;
        }
        return $arr;
    }
    
    function fixUrl($url, $base_url) {
        $prefix = "../app/";
        if (substr($url, 0, strlen($prefix)) === $prefix) {
            $url = substr($url, strlen($prefix));
        }
        return $base_url . $url;
    }
    
    function formatearFecha($f) {
        return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
    }
    
    // -----------------------------------------------------------------------------
    // Includes y validaciones iniciales
    // -----------------------------------------------------------------------------
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
    
    $division_id = isset($_SESSION['division_id']) ? intval($_SESSION['division_id']) : 0;
    $division   = isset($_GET['division'])   ? intval($_GET['division'])   : $division_id;
    $distrito   = isset($_GET['distrito'])   ? intval($_GET['distrito'])   : 0;
    
    // Validación para divisiones (tal como en tu código original)
    if ($division > 0 && $division != 1) {
        $stmtTipo = $conn->prepare("SELECT id_division FROM formulario WHERE id_division = ? AND tipo = 3 LIMIT 1");
        $stmtTipo->bind_param("i", $division);
        $stmtTipo->execute();
        $stmtTipo->bind_result($idDivision);
        if (!$stmtTipo->fetch()) {
            echo json_encode(["error" => "No se encontró un formulario de tipo 3 para esa división."]);
            exit();
        }
        $stmtTipo->close();
        $tipoForm = 3;
    } else {
        $tipoForm = 3;
    }
    
    // -----------------------------------------------------------------------------
    // Parámetros de filtrado y paginación
    // -----------------------------------------------------------------------------
    $start_date  = isset($_GET['start_date'])  ? trim($_GET['start_date'])  : '';
    $end_date    = isset($_GET['end_date'])    ? trim($_GET['end_date'])    : '';
    $user_id     = isset($_GET['user_id'])     ? (int)$_GET['user_id']      : 0;
    $material_id = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;
    $local_code  = isset($_GET['local_code'])  ? trim($_GET['local_code'])  : '';
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 25;
    if ($limit <= 0) { $limit = 25; }
    $page  = isset($_GET['page'])  ? intval($_GET['page']) : 1;
    if ($page < 1) { $page = 1; }
    $offset = ($page - 1) * $limit;
    
    $view = isset($_GET['view']) ? trim($_GET['view']) : 'implementacion';
    if ($tipoForm == 2) { $view = 'encuesta'; }
    
    $base_url = "https://visibility.cl/visibility2/app/";
    
    // Para evitar cargar todas las imágenes, si no se envían fechas se asigna la fecha de mañana.
    if (empty($start_date) && empty($end_date)) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $start_date = $tomorrow;
        $end_date   = $tomorrow;
    }
    
    // -----------------------------------------------------------------------------
    // Construir la consulta principal (en este ejemplo para implementación)
    // -----------------------------------------------------------------------------
    // Consulta para la vista "implementacion" agrupada por local
    // 5) Construir la consulta principal para las fotos
    $params = [];
    $types  = "i";
    // Usamos la variable $division (obtenida de GET o de la sesión)
    $params[] = $division;
    
    if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
        // Consulta agrupada por local
        $sql = "
          SELECT 
             l.id AS local_id,
             l.codigo AS local_codigo,
             l.nombre AS local_nombre,
             l.direccion AS local_direccion,
             c.nombre AS cadena_nombre,
             MIN(fv.url) AS thumbnail,      -- Imagen representativa
             COUNT(fv.id) AS photo_count,    -- Cantidad de fotos asociadas
             MAX(fq.fechaVisita) AS last_visit_date  -- Última fecha de visita
          FROM formularioQuestion fq
          JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
          JOIN local l ON l.id = fq.id_local
          JOIN cadena c ON c.id = l.id_cadena
          WHERE l.id_division = ?
            AND fq.fechaVisita IS NOT NULL
        ";
        if ($start_date !== '') {
            $sql .= " AND DATE(fq.fechaVisita) >= ? ";
            $types .= "s";
            $params[] = $start_date;
        }
        if ($end_date !== '') {
            $sql .= " AND DATE(fq.fechaVisita) <= ? ";
            $types .= "s";
            $params[] = $end_date;
        }
        if ($local_code !== '') {
            $sql .= " AND l.codigo = ? ";
            $types .= "s";
            $params[] = $local_code;
        }
        if ($user_id > 0) {
            $sql .= " AND fv.id_usuario = ? ";
            $types .= "i";
            $params[] = $user_id;
        }
        if ($material_id > 0) {
            $sql .= " AND fq.material = (SELECT nombre FROM material WHERE id = ? AND id_division = ?) ";
            $types .= "ii";
            $params[] = $material_id;
            $params[] = $division;
        }
        // Agrupar para que cada local aparezca solo una vez y ordenar según la última visita
        $sql .= " GROUP BY l.id, l.codigo, l.nombre, l.direccion, c.nombre ";
        $sql .= " ORDER BY last_visit_date DESC LIMIT ? OFFSET ? ";
        $types .= "ii";
        $params[] = $limit;
        $params[] = $offset;
    }  elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {
    // Consulta para la vista de encuesta agrupada por local
    $sql = "
      SELECT
        l.id AS local_id,
        l.codigo AS local_codigo,
        l.nombre AS local_nombre,
        l.direccion AS local_direccion,
        c.nombre AS cadena_nombre,        
        MIN(fqr.answer_text) AS thumbnail,  -- Imagen representativa (puedes elegir MAX o MIN)
        COUNT(fqr.id) AS photo_count,         -- Cantidad de fotos de encuesta
        MAX(fqr.created_at) AS last_visit_date,  -- Última fecha de encuesta
        MIN(fq.question_text) AS pregunta    -- O algún campo representativo, si lo necesitas
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN local l ON l.id = fqr.id_local
      JOIN cadena c ON c.id = l.id_cadena      
      WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
    if ($start_date !== '') {
        $sql .= " AND DATE(fqr.created_at) >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fqr.created_at) <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($local_code !== '') {
        $sql .= " AND l.codigo = ? ";
        $types .= "s";
        $params[] = $local_code;
    }
    if ($user_id > 0) {
        $sql .= " AND fqr.id_usuario = ? ";
        $types .= "i";
        $params[] = $user_id;
    }
    // Agrupar para que cada local aparezca solo una vez
    $sql .= " GROUP BY l.id, l.codigo, l.nombre, l.direccion ";
    $sql .= " ORDER BY last_visit_date DESC LIMIT ? OFFSET ? ";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
}
 else {
        // Consulta por defecto (fallback)
        $sql = "
          SELECT
            fqr.id AS foto_id,
            fqr.answer_text AS url,
            fqr.created_at AS fechaSubida,
            fq.question_text AS pregunta,
            'N/A' AS local_codigo,
            'N/A' AS local_nombre,
            'N/A' AS local_direccion
          FROM form_question_responses fqr
          JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f ON f.id = fq.id_formulario
          JOIN formularioQuestion fqn ON fqn.id_formulario = f.id
          JOIN local l ON l.id = fqn.id_local
          WHERE l.id_division = ?
            AND fq.id_question_type = 7
            AND fqr.id_local = 0
        ";
        if ($start_date !== '') {
            $sql .= " AND DATE(fqr.created_at) >= ? ";
            $types .= "s";
            $params[] = $start_date;
        }
        if ($end_date !== '') {
            $sql .= " AND DATE(fqr.created_at) <= ? ";
            $types .= "s";
            $params[] = $end_date;
        }
        if ($user_id > 0) {
            $sql .= " AND fqr.id_usuario = ? ";
            $types .= "i";
            $params[] = $user_id;
        }
        $sql .= " ORDER BY fqr.created_at DESC LIMIT ? OFFSET ? ";
        $types .= "ii";
        $params[] = $limit;
        $params[] = $offset;
    }
    
    $stmtMain = $conn->prepare($sql);
    if (!$stmtMain) {
        echo json_encode(["error" => "Error en la preparación: " . htmlspecialchars($conn->error)]);
        exit();
    }
    call_user_func_array([$stmtMain, 'bind_param'], array_merge([$types], refValues($params)));
    $stmtMain->execute();
    $result = $stmtMain->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        if ($view === 'implementacion' || $view === 'encuesta') {
            $row['thumbnail'] = fixUrl($row['thumbnail'], $base_url);
            $row['last_visit_date'] = formatearFecha($row['last_visit_date']);
        }
        $data[] = $row;
    }
    $stmtMain->close();
    
    
    header('Content-Type: application/json');
    echo json_encode([
        "data" => $data,
        "currentPage" => $page,
        // Puedes incluir totalPages si lo calculas
    ]);
    exit();
    
    ?>

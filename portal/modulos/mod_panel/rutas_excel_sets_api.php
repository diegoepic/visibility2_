<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$db = $conexion ?? $conn ?? $mysqli ?? null;
if (!($db instanceof mysqli)) {
    apiJson(['ok' => false, 'message' => 'No fue posible conectar con la base de datos.'], 500);
}
$db->set_charset('utf8mb4');

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'sets')));
$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
$sessionUserId = (int)($_SESSION['usuario_id'] ?? 0);
$sessionCompanyId = (int)($_SESSION['empresa_id'] ?? 0);
$sessionDivisionId = (int)($_SESSION['division_id'] ?? 0);
$canManage = in_array($profile, ['editor', 'coordinador', 'administrador', 'admin'], true);

if ($sessionUserId <= 0 || $profile === '') {
    apiJson(['ok' => false, 'message' => 'La sesión expiró. Vuelve a ingresar.'], 401);
}

function apiJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeText(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = strtolower($ascii);
    }
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function normalizeHeader(string $value): string
{
    $value = normalizeText($value);
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', $value), '_');
}

function columnFor(array $columns, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        $key = normalizeHeader($alias);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function parseVisitDate($value): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    try {
        if (is_numeric($value)) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value)->format('Y-m-d');
        }
        $raw = trim((string)$value);
        foreach (['!d-m-Y', '!d/m/Y', '!Y-m-d', '!Y/m/d'] as $format) {
            $date = DateTime::createFromFormat($format, $raw);
            if ($date instanceof DateTime && $date->format(str_replace('!', '', $format)) === $raw) {
                return $date->format('Y-m-d');
            }
        }
        return (new DateTime($raw))->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function requireManager(bool $canManage): void
{
    if (!$canManage) {
        apiJson(['ok' => false, 'message' => 'No tienes permisos para administrar sets de rutas.'], 403);
    }
}

function requireCsrf(): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        apiJson(['ok' => false, 'message' => 'Token de seguridad inválido. Recarga la página.'], 400);
    }
}

function isMcDivision(mysqli $db, int $divisionId): bool
{
    if ($divisionId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtoupper(trim((string)($row['nombre'] ?? ''))) === 'MC';
}

function canAccessDivision(mysqli $db, int $divisionId, int $sessionDivisionId, bool $canManage): bool
{
    return $canManage || isMcDivision($db, $sessionDivisionId) || ($sessionDivisionId > 0 && $divisionId === $sessionDivisionId);
}

function validateScope(mysqli $db, int $divisionId, int $subdivisionId, int $companyId): ?array
{
    $sql = 'SELECT d.id, d.nombre AS division, d.id_empresa, s.id AS id_subdivision, s.nombre AS subdivision
            FROM division_empresa d
            JOIN subdivision s ON s.id_division = d.id
            WHERE d.id = ? AND s.id = ? AND d.estado = 1';
    if ($companyId > 0) {
        $sql .= ' AND d.id_empresa = ?';
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($companyId > 0) {
        $stmt->bind_param('iii', $divisionId, $subdivisionId, $companyId);
    } else {
        $stmt->bind_param('ii', $divisionId, $subdivisionId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function getAccessibleSet(mysqli $db, int $setId, int $companyId, int $sessionDivisionId, bool $canManage): ?array
{
    $stmt = $db->prepare('SELECT * FROM rutas_excel_sets WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $setId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    if ($companyId > 0 && (int)$row['id_empresa'] !== $companyId) {
        return null;
    }
    if (!canAccessDivision($db, (int)$row['id_division'], $sessionDivisionId, $canManage)) {
        return null;
    }
    return $row;
}

if ($action === 'catalogs') {
    $divisions = [];
    $sql = 'SELECT id, nombre FROM division_empresa WHERE estado = 1';
    $params = [];
    $types = '';
    if ($sessionCompanyId > 0) {
        $sql .= ' AND id_empresa = ?';
        $params[] = $sessionCompanyId;
        $types .= 'i';
    }
    if (!$canManage && !isMcDivision($db, $sessionDivisionId)) {
        $sql .= ' AND id = ?';
        $params[] = $sessionDivisionId;
        $types .= 'i';
    }
    $sql .= ' ORDER BY nombre';
    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $divisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisions = [];
    if ($divisionId > 0 && canAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        $stmt = $db->prepare('SELECT id, nombre FROM subdivision WHERE id_division = ? ORDER BY nombre');
        $stmt->bind_param('i', $divisionId);
        $stmt->execute();
        $subdivisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    apiJson(['ok' => true, 'divisions' => $divisions, 'subdivisions' => $subdivisions, 'can_manage' => $canManage]);
}

if ($action === 'sets') {
    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisionId = (int)($_GET['id_subdivision'] ?? 0);
    $status = strtolower(trim((string)($_GET['estado'] ?? '')));
    $sql = 'SELECT rs.*, d.nombre AS division, s.nombre AS subdivision,
                   TRIM(CONCAT_WS(\' \', NULLIF(TRIM(u.nombre), \'\'), NULLIF(TRIM(u.apellido), \'\'))) AS creado_por_nombre
            FROM rutas_excel_sets rs
            JOIN division_empresa d ON d.id = rs.id_division
            JOIN subdivision s ON s.id = rs.id_subdivision
            LEFT JOIN usuario u ON u.id = rs.creado_por
            WHERE 1=1';
    $params = [];
    $types = '';
    if ($sessionCompanyId > 0) {
        $sql .= ' AND rs.id_empresa = ?';
        $params[] = $sessionCompanyId;
        $types .= 'i';
    }
    if (!$canManage && !isMcDivision($db, $sessionDivisionId)) {
        $sql .= ' AND rs.id_division = ?';
        $params[] = $sessionDivisionId;
        $types .= 'i';
    } elseif ($divisionId > 0) {
        $sql .= ' AND rs.id_division = ?';
        $params[] = $divisionId;
        $types .= 'i';
    }
    if ($subdivisionId > 0) {
        $sql .= ' AND rs.id_subdivision = ?';
        $params[] = $subdivisionId;
        $types .= 'i';
    }
    if (in_array($status, ['activo', 'finalizado'], true)) {
        $sql .= ' AND rs.estado = ?';
        $params[] = $status;
        $types .= 's';
    }
    $sql .= ' ORDER BY rs.created_at DESC, rs.id DESC';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        apiJson(['ok' => false, 'message' => 'No se pudo preparar el listado de sets: ' . $db->error], 500);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    apiJson(['ok' => true, 'data' => $rows, 'can_manage' => $canManage]);
}

if ($action === 'set_users') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    $stmt = $db->prepare(
        'SELECT
            u.id,
            u.usuario,
            TRIM(CONCAT_WS(\' \', NULLIF(TRIM(u.nombre), \'\'), NULLIF(TRIM(u.apellido), \'\'))) AS nombre,
            COUNT(*) AS total_locales,
            COUNT(DISTINCT rd.fecha_visita) AS total_fechas,
            MIN(rd.fecha_visita) AS fecha_desde,
            MAX(rd.fecha_visita) AS fecha_hasta
         FROM rutas_excel_set_detalle rd
         JOIN usuario u ON u.id = rd.id_usuario
         WHERE rd.id_set = ?
         GROUP BY u.id, u.usuario, u.nombre, u.apellido
         ORDER BY u.usuario, u.nombre, u.apellido'
    );
    if (!$stmt) {
        apiJson(['ok' => false, 'message' => 'No se pudieron preparar los usuarios del set: ' . $db->error], 500);
    }
    $stmt->bind_param('i', $setId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    apiJson(['ok' => true, 'set' => $set, 'data' => $rows]);
}

if ($action === 'details') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $userId = (int)($_GET['id_usuario'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    $sql = 'SELECT rd.id, rd.fecha_visita, rd.orden_visita,
                   l.id AS id_local, l.codigo AS codigo_local, l.nombre AS nombre_local,
                   COALESCE(NULLIF(l.direccion_google,\'\'), l.direccion) AS direccion,
                   l.lat, l.lng, c.comuna,
                   u.id AS id_usuario, u.usuario AS usuario,
                   TRIM(CONCAT_WS(\' \', NULLIF(TRIM(u.nombre), \'\'), NULLIF(TRIM(u.apellido), \'\'))) AS usuario_nombre
            FROM rutas_excel_set_detalle rd
            JOIN local l ON l.id = rd.id_local
            LEFT JOIN comuna c ON c.id = l.id_comuna
            JOIN usuario u ON u.id = rd.id_usuario
            WHERE rd.id_set = ?';
    if ($userId > 0) {
        $sql .= ' AND rd.id_usuario = ?';
    }
    $sql .= ' ORDER BY rd.fecha_visita, u.usuario, rd.orden_visita, l.codigo';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        apiJson(['ok' => false, 'message' => 'No se pudo preparar el detalle del set: ' . $db->error], 500);
    }
    if ($userId > 0) {
        $stmt->bind_param('ii', $setId, $userId);
    } else {
        $stmt->bind_param('i', $setId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    apiJson(['ok' => true, 'set' => $set, 'data' => $rows]);
}

if ($action === 'template') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Rutas');
    $sheet->fromArray([['CODIGO LOCAL', 'USUARIO', 'FECHA VISITA', 'ORDEN VISITA'], ['1000000', 'JPEREZ', date('Y-m-d'), 1]], null, 'A1');
    $sheet->getStyle('A1:D1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF004AAD');
    foreach ([18, 24, 18, 16] as $index => $width) {
        $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
    }
    $sheet->setCellValueExplicit('A2', '1000000', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_set_rutas.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

if ($action === 'download' || $action === 'rejections') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    $filename = ($action === 'download' ? 'set_rutas_' : 'rechazos_set_rutas_') . $setId . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit('No fue posible generar la descarga.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    if ($action === 'download') {
        $headers = ['SET', 'ESTADO', 'DIVISION', 'SUBDIVISION', 'CODIGO LOCAL', 'NOMBRE LOCAL', 'DIRECCION', 'COMUNA', 'LAT', 'LNG', 'USUARIO', 'NOMBRE USUARIO', 'FECHA VISITA', 'ORDEN VISITA'];
        fputcsv($output, $headers, ';');
        $stmt = $db->prepare('SELECT l.codigo, l.nombre, COALESCE(NULLIF(l.direccion_google,\'\'), l.direccion) AS direccion,
                                    c.comuna, l.lat, l.lng, u.usuario,
                                    TRIM(CONCAT_WS(\' \', NULLIF(TRIM(u.nombre), \'\'), NULLIF(TRIM(u.apellido), \'\'))) AS usuario_nombre,
                                    rd.fecha_visita, rd.orden_visita,
                                    d.nombre AS division, s.nombre AS subdivision
                             FROM rutas_excel_set_detalle rd
                             JOIN rutas_excel_sets rs ON rs.id = rd.id_set
                             JOIN division_empresa d ON d.id = rs.id_division
                             JOIN subdivision s ON s.id = rs.id_subdivision
                             JOIN local l ON l.id = rd.id_local
                             LEFT JOIN comuna c ON c.id = l.id_comuna
                             JOIN usuario u ON u.id = rd.id_usuario
                             WHERE rd.id_set = ? ORDER BY rd.fecha_visita, u.usuario, rd.orden_visita');
        if (!$stmt) {
            fclose($output);
            exit('No se pudo preparar la descarga: ' . $db->error);
        }
        $stmt->bind_param('i', $setId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            fputcsv($output, [$set['nombre'], strtoupper($set['estado']), $r['division'], $r['subdivision'], $r['codigo'], $r['nombre'], $r['direccion'], $r['comuna'], $r['lat'], $r['lng'], $r['usuario'], $r['usuario_nombre'], $r['fecha_visita'], $r['orden_visita']], ';');
        }
        $stmt->close();
    } else {
        fputcsv($output, ['FILA', 'CODIGO LOCAL', 'USUARIO', 'FECHA', 'MOTIVO'], ';');
        $stmt = $db->prepare('SELECT fila_archivo, codigo_local, usuario_archivo, fecha_archivo, motivo FROM rutas_excel_set_rechazos WHERE id_set = ? ORDER BY fila_archivo');
        $stmt->bind_param('i', $setId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) {
            fputcsv($output, array_values($r), ';');
        }
        $stmt->close();
    }
    fclose($output);
    exit;
}

if ($action === 'upload') {
    requireManager($canManage);
    requireCsrf();
    $name = trim((string)($_POST['nombre'] ?? ''));
    $description = trim((string)($_POST['descripcion'] ?? ''));
    $divisionId = (int)($_POST['id_division'] ?? 0);
    $subdivisionId = (int)($_POST['id_subdivision'] ?? 0);
    $status = strtolower(trim((string)($_POST['estado'] ?? 'activo')));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
        apiJson(['ok' => false, 'message' => 'Ingresa un nombre de set de hasta 150 caracteres.'], 422);
    }
    if (!in_array($status, ['activo', 'finalizado'], true)) {
        $status = 'activo';
    }
    $scope = validateScope($db, $divisionId, $subdivisionId, $sessionCompanyId);
    if (!$scope || !canAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        apiJson(['ok' => false, 'message' => 'La división/subdivisión no es válida o no está autorizada.'], 422);
    }
    if (!isset($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        apiJson(['ok' => false, 'message' => 'Selecciona un archivo Excel o CSV válido.'], 422);
    }
    $tmp = (string)$_FILES['archivo']['tmp_name'];
    $original = basename((string)$_FILES['archivo']['name']);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!is_uploaded_file($tmp) || !in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        apiJson(['ok' => false, 'message' => 'El archivo debe ser .xlsx, .xls o .csv.'], 422);
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
    try {
        if ($extension === 'csv') {
            $sample = (string)file_get_contents($tmp, false, null, 0, 4096);
            $delimiterCounts = [
                ';' => substr_count($sample, ';'),
                ',' => substr_count($sample, ','),
                "\t" => substr_count($sample, "\t"),
            ];
            arsort($delimiterCounts);
            $delimiter = (string)array_key_first($delimiterCounts);
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $reader->setDelimiter($delimiter);
            $reader->setInputEncoding('UTF-8');
            $spreadsheet = $reader->load($tmp);
        } else {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        }
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
    } catch (Throwable $e) {
        apiJson(['ok' => false, 'message' => 'No fue posible leer el archivo: ' . $e->getMessage()], 422);
    }
    if (count($rows) < 2) {
        apiJson(['ok' => false, 'message' => 'El archivo no contiene filas para cargar.'], 422);
    }
    $headerNo = array_key_first($rows);
    $columns = [];
    foreach ($rows[$headerNo] as $column => $label) {
        $key = normalizeHeader((string)$label);
        if ($key !== '') { $columns[$key] = $column; }
    }
    $codeColumn = columnFor($columns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo', 'Código Sala', 'Codigo Sala']);
    $userColumn = columnFor($columns, ['Usuario', 'Usuario Login', 'Login', 'ID Usuario', 'Nombre Usuario']);
    $dateColumn = columnFor($columns, ['Fecha Visita', 'Fecha Ruta', 'Fecha Planificada', 'Fecha']);
    $orderColumn = columnFor($columns, ['Orden Visita', 'Orden']);
    if (!$codeColumn || !$userColumn || !$dateColumn) {
        apiJson(['ok' => false, 'message' => 'Faltan columnas. Usa: CODIGO LOCAL, USUARIO y FECHA VISITA.'], 422);
    }

    $localMap = [];
    $stmt = $db->prepare('SELECT id, codigo FROM local WHERE id_division = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $localMap[normalizeText((string)$r['codigo'])] = (int)$r['id']; }
    $stmt->close();

    $userMap = [];
    $stmt = $db->prepare('SELECT id, usuario, nombre, apellido FROM usuario WHERE activo = 1 AND id_division = ? AND id_subdivision = ?');
    $stmt->bind_param('ii', $divisionId, $subdivisionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $payload = (int)$r['id'];
        $fullName = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
        $userMap[(string)$r['id']] = $payload;
        if (trim((string)$r['usuario']) !== '') { $userMap[normalizeText((string)$r['usuario'])] = $payload; }
        if ($fullName !== '') { $userMap[normalizeText($fullName)] = $payload; }
    }
    $stmt->close();

    $valid = [];
    $rejected = [];
    $seen = [];
    $routeSequence = [];
    foreach ($rows as $rowNo => $row) {
        if ((int)$rowNo === (int)$headerNo) { continue; }
        $code = trim((string)($row[$codeColumn] ?? ''));
        $user = trim((string)($row[$userColumn] ?? ''));
        $rawDate = $row[$dateColumn] ?? '';
        if ($code === '' && $user === '' && trim((string)$rawDate) === '') { continue; }
        $errors = [];
        $localId = $localMap[normalizeText($code)] ?? 0;
        $userId = $userMap[normalizeText($user)] ?? 0;
        $date = parseVisitDate($rawDate);
        if (!$localId) { $errors[] = 'Código de local no existe en la división seleccionada'; }
        if (!$userId) { $errors[] = 'Usuario no existe/está inactivo o no pertenece a la división y subdivisión'; }
        if (!$date) { $errors[] = 'Fecha de visita inválida'; }
        $key = $localId . '|' . $date;
        if (!$errors && isset($seen[$key])) { $errors[] = 'El local ya está asignado en la misma fecha dentro del archivo'; }
        if ($errors) {
            $rejected[] = [(int)$rowNo, $code, $user, trim((string)$rawDate), implode('; ', $errors)];
            continue;
        }
        $seen[$key] = true;
        $routeKey = $userId . '|' . $date;
        $routeSequence[$routeKey] = ($routeSequence[$routeKey] ?? 0) + 1;
        $uploadedOrder = max(0, (int)($orderColumn ? ($row[$orderColumn] ?? 0) : 0));
        $valid[] = [$localId, $userId, $date, $uploadedOrder > 0 ? $uploadedOrder : $routeSequence[$routeKey]];
    }
    if (!$valid) {
        apiJson(['ok' => false, 'message' => 'No hay filas válidas para crear el set.', 'rejections' => $rejected], 422);
    }

    $db->begin_transaction();
    try {
        $finalizedAt = $status === 'finalizado' ? date('Y-m-d H:i:s') : null;
        $total = count($valid) + count($rejected);
        $validCount = count($valid);
        $rejectedCount = count($rejected);
        $companyId = (int)$scope['id_empresa'];
        $stmt = $db->prepare('INSERT INTO rutas_excel_sets
            (nombre, descripcion, id_empresa, id_division, id_subdivision, estado, archivo_original, total_filas, filas_validas, filas_rechazadas, creado_por, finalized_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->bind_param('ssiiissiiiis', $name, $description, $companyId, $divisionId, $subdivisionId, $status, $original, $total, $validCount, $rejectedCount, $sessionUserId, $finalizedAt);
        if (!$stmt->execute()) { throw new RuntimeException($stmt->error); }
        $setId = (int)$db->insert_id;
        $stmt->close();
        $detailStmt = $db->prepare('INSERT INTO rutas_excel_set_detalle (id_set, id_local, id_usuario, fecha_visita, orden_visita) VALUES (?, ?, ?, ?, ?)');
        foreach ($valid as [$localId, $userId, $date, $order]) {
            $detailStmt->bind_param('iiisi', $setId, $localId, $userId, $date, $order);
            if (!$detailStmt->execute()) { throw new RuntimeException($detailStmt->error); }
        }
        $detailStmt->close();
        if ($rejected) {
            $rejectStmt = $db->prepare('INSERT INTO rutas_excel_set_rechazos (id_set, fila_archivo, codigo_local, usuario_archivo, fecha_archivo, motivo) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rejected as [$rowNo, $code, $user, $rawDate, $reason]) {
                $rejectStmt->bind_param('iissss', $setId, $rowNo, $code, $user, $rawDate, $reason);
                if (!$rejectStmt->execute()) { throw new RuntimeException($rejectStmt->error); }
            }
            $rejectStmt->close();
        }
        $db->commit();
        apiJson(['ok' => true, 'message' => 'Set creado correctamente.', 'id_set' => $setId, 'valid_rows' => $validCount, 'rejected_rows' => $rejectedCount]);
    } catch (Throwable $e) {
        $db->rollback();
        apiJson(['ok' => false, 'message' => 'No se pudo guardar el set: ' . $e->getMessage()], 500);
    }
}

if ($action === 'status') {
    requireManager($canManage);
    requireCsrf();
    $setId = (int)($_POST['id_set'] ?? 0);
    $status = strtolower(trim((string)($_POST['estado'] ?? '')));
    if (!in_array($status, ['activo', 'finalizado'], true)) {
        apiJson(['ok' => false, 'message' => 'Estado inválido.'], 422);
    }
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    $stmt = $db->prepare("UPDATE rutas_excel_sets SET estado = ?, finalized_at = CASE WHEN ? = 'finalizado' THEN NOW() ELSE NULL END, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $status, $status, $setId);
    $stmt->execute();
    $stmt->close();
    apiJson(['ok' => true, 'message' => 'Estado actualizado.', 'estado' => $status]);
}

apiJson(['ok' => false, 'message' => 'Acción no reconocida.'], 404);

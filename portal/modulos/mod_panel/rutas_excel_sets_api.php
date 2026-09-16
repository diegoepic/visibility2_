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
const ROUTE_UNASSIGNED_KEY = '__SIN_RUTA__';

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
    $stmt = $db->prepare('SELECT rs.*, CASE WHEN EXISTS (SELECT 1 FROM rutas_excel_set_detalle rd WHERE rd.id_set = rs.id) THEN \'planificacion\' ELSE \'rutas_base\' END AS tipo_contenido FROM rutas_excel_sets rs WHERE rs.id = ? LIMIT 1');
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

function routeSetStorageDirectory(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rutas_excel_sets';
}

function ensureRouteSetStorageDirectory(): string
{
    $directory = routeSetStorageDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear el almacenamiento privado de rutas.');
    }

    $protection = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($protection)) {
        @file_put_contents($protection, "Require all denied\nDeny from all\n");
    }

    $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n");
    }

    return $directory;
}

function routeSetStoredFilePath(int $setId, string $originalName): ?string
{
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if ($setId <= 0 || !in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        return null;
    }
    return routeSetStorageDirectory() . DIRECTORY_SEPARATOR . 'set_' . $setId . '.' . $extension;
}

function findPlanningWorksheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, bool $required = true): ?array
{
    $worksheets = $spreadsheet->getAllSheets();
    $sheetOrder = [];
    foreach ($worksheets as $index => $worksheet) {
        $sheetOrder[spl_object_id($worksheet)] = $index;
    }
    usort($worksheets, static function ($left, $right) use ($sheetOrder): int {
        $priority = static function ($worksheet): int {
            $title = normalizeHeader($worksheet->getTitle());
            if (preg_match('/^planificacion_ajustada(?:_\d+)?$/', $title)) {
                return 0;
            }
            return $title === 'planificacion' ? 1 : 2;
        };
        $comparison = $priority($left) <=> $priority($right);
        if ($comparison !== 0) {
            return $comparison;
        }
        return ($sheetOrder[spl_object_id($right)] ?? 0) <=> ($sheetOrder[spl_object_id($left)] ?? 0);
    });

    foreach ($worksheets as $worksheet) {
        $highestRow = min(10, max(1, (int)$worksheet->getHighestRow()));
        $highestColumn = $worksheet->getHighestColumn();
        $headerRows = $worksheet->rangeToArray(
            'A1:' . $highestColumn . $highestRow,
            null,
            true,
            true,
            true
        );

        foreach ($headerRows as $rowNumber => $headerRow) {
            $columns = [];
            foreach ($headerRow as $column => $label) {
                $key = normalizeHeader((string)$label);
                if ($key !== '') {
                    $columns[$key] = $column;
                }
            }

            $codeColumn = columnFor($columns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo', 'Código Sala', 'Codigo Sala']);
            $userColumn = columnFor($columns, ['Usuario', 'Usuario Login', 'Login', 'ID Usuario', 'Nombre Usuario']);
            $dateColumn = columnFor($columns, ['Fecha Visita', 'Fecha Ruta', 'Fecha Planificada', 'Fecha']);
            if ($codeColumn && $userColumn && $dateColumn) {
                return [$worksheet, (int)$rowNumber, $columns];
            }
        }
    }

    if ($required) {
        throw new RuntimeException('No se encontró una hoja de planificación con Código Local, Usuario y Fecha Ruta/Visita.');
    }
    return null;
}

function findRouteBaseWorksheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, bool $requireRouteColumns = true): ?array
{
    $worksheets = array_reverse($spreadsheet->getAllSheets());
    foreach (['/^rutas_base_ajustadas(?:_\d+)?$/', '/^rutas_base$/'] as $pattern) {
        foreach ($worksheets as $worksheet) {
            if (!preg_match($pattern, normalizeHeader($worksheet->getTitle()))) {
                continue;
            }
            $requiredColumns = [
                ['Código Local', 'Codigo Local', 'codigo_local', 'codigo', 'Código Sala', 'Codigo Sala'],
            ];
            if ($requireRouteColumns) {
                $requiredColumns[] = ['Usuario Login', 'Usuario', 'Login', 'ID Usuario', 'Usuario ID', 'Nombre Usuario'];
                $requiredColumns[] = ['Grupo Ruta', 'Ruta', 'Grupo'];
            }
            [$headerNo, $columns] = worksheetHeaderMap($worksheet, $requiredColumns);
            if ($columns) {
                return [$worksheet, $headerNo, $columns];
            }
        }
    }
    return null;
}

function routeWorksheetHasData(array $rows, int $headerNo): bool
{
    foreach ($rows as $rowNo => $row) {
        if ((int)$rowNo <= $headerNo) {
            continue;
        }
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
    }
    return false;
}

function readRouteSetUpload(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
{
    $planning = findPlanningWorksheet($spreadsheet, false);
    if ($planning !== null) {
        [$sheet, $headerNo, $columns] = $planning;
        $rows = $sheet->toArray(null, true, true, true);
        if (routeWorksheetHasData($rows, $headerNo)) {
            return ['type' => 'planificacion', 'sheet_name' => $sheet->getTitle(), 'header_no' => $headerNo, 'columns' => $columns, 'rows' => $rows];
        }
    }
    $base = findRouteBaseWorksheet($spreadsheet);
    if ($base !== null) {
        [$sheet, $headerNo, $columns] = $base;
        $rows = $sheet->toArray(null, true, true, true);
        if (routeWorksheetHasData($rows, $headerNo)) {
            return ['type' => 'rutas_base', 'sheet_name' => $sheet->getTitle(), 'header_no' => $headerNo, 'columns' => $columns, 'rows' => $rows];
        }
    }
    throw new RuntimeException('No hay visitas en Planificacion ni locales en una hoja Rutas Base con Código Local, Usuario y Grupo Ruta.');
}

function validateRouteSetRows(array $upload, array $localMap, array $userMap): array
{
    $isBaseOnly = $upload['type'] === 'rutas_base';
    $columns = $upload['columns'];
    $codeColumn = columnFor($columns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo', 'Código Sala', 'Codigo Sala']);
    $userColumn = columnFor($columns, $isBaseOnly
        ? ['Usuario Login', 'Usuario', 'Login', 'ID Usuario', 'Usuario ID', 'Nombre Usuario']
        : ['Usuario', 'Usuario Login', 'Login', 'ID Usuario', 'Nombre Usuario']);
    $userNameColumn = columnFor($columns, ['Usuario Nombre', 'Nombre Usuario', 'Nombre Responsable', 'Responsable Nombre']);
    $dateColumn = $isBaseOnly ? null : columnFor($columns, ['Fecha Visita', 'Fecha Ruta', 'Fecha Planificada', 'Fecha']);
    $groupColumn = columnFor($columns, ['Grupo Ruta', 'Ruta', 'Grupo']);
    $orderColumn = columnFor($columns, ['Orden Visita', 'Orden']);
    $valid = [];
    $rejected = [];
    $seen = [];
    $routeSequence = [];
    foreach ($upload['rows'] as $rowNo => $row) {
        if ((int)$rowNo <= (int)$upload['header_no']) { continue; }
        $code = trim((string)($row[$codeColumn] ?? ''));
        $user = trim((string)($row[$userColumn] ?? ''));
        $userName = trim((string)($userNameColumn ? ($row[$userNameColumn] ?? '') : ''));
        $rawDate = $dateColumn ? ($row[$dateColumn] ?? '') : '';
        $group = trim((string)($groupColumn ? ($row[$groupColumn] ?? '') : ''));
        if ($code === '' && $user === '' && $userName === '' && trim((string)$rawDate) === '' && (!$isBaseOnly || $group === '')) { continue; }
        if ($user === '') {
            $user = $userName !== '' ? $userName : 'SIN ASIGNAR';
        }
        $errors = [];
        $localId = $localMap[normalizeText($code)] ?? 0;
        $userId = $userMap[normalizeText($user)] ?? null;
        $date = $isBaseOnly ? null : parseVisitDate($rawDate);
        if (!$localId) { $errors[] = 'Código de local no existe en la división seleccionada'; }
        if (!$isBaseOnly && !$date) { $errors[] = 'Fecha de visita inválida'; }
        $routeKey = $isBaseOnly
            ? routeIdentityToken($user, canonicalRouteGroup($user, $group))
            : normalizeText($user) . '|' . $date;
        $key = $localId . '|' . ($isBaseOnly ? $routeKey : $date);
        if (!$errors && isset($seen[$key])) {
            $errors[] = $isBaseOnly
                ? 'El local está repetido para el mismo usuario y grupo de ruta dentro del archivo'
                : 'El local ya está asignado en la misma fecha dentro del archivo';
        }
        if ($errors) {
            $rejected[] = [(int)$rowNo, $code, $user, trim((string)$rawDate), implode('; ', $errors)];
            continue;
        }
        $seen[$key] = true;
        $routeSequence[$routeKey] = ($routeSequence[$routeKey] ?? 0) + 1;
        $uploadedOrder = max(0, (int)($orderColumn ? ($row[$orderColumn] ?? 0) : 0));
        $valid[] = [$localId, $userId, $user, $userName, $date, $uploadedOrder > 0 ? $uploadedOrder : $routeSequence[$routeKey]];
    }
    return ['valid' => $valid, 'rejected' => $rejected];
}

function routeDetailUserSchemaAvailable(mysqli $db): bool
{
    $result = $db->query("SHOW COLUMNS FROM rutas_excel_set_detalle WHERE Field IN ('usuario_archivo', 'usuario_nombre_archivo')");
    if (!$result) {
        return false;
    }
    $available = $result->num_rows === 2;
    $result->free();
    return $available;
}

function ensureRouteDetailUserSchema(mysqli $db): void
{
    $columns = [];
    $result = $db->query('SHOW COLUMNS FROM rutas_excel_set_detalle');
    if (!$result) {
        throw new RuntimeException('No fue posible revisar la estructura de rutas publicadas.');
    }
    while ($column = $result->fetch_assoc()) {
        $columns[(string)$column['Field']] = $column;
    }
    $result->free();

    if (($columns['id_usuario']['Null'] ?? 'NO') !== 'YES') {
        if (!$db->query('ALTER TABLE rutas_excel_set_detalle MODIFY id_usuario INT NULL')) {
            throw new RuntimeException('No fue posible permitir rutas para usuarios aún no registrados. Ejecuta la migración SQL del módulo.');
        }
    }
    if (!isset($columns['usuario_archivo'])) {
        if (!$db->query("ALTER TABLE rutas_excel_set_detalle ADD COLUMN usuario_archivo VARCHAR(255) NOT NULL DEFAULT '' AFTER id_usuario")) {
            throw new RuntimeException('No fue posible agregar el usuario informado en el archivo. Ejecuta la migración SQL del módulo.');
        }
    }
    if (!isset($columns['usuario_nombre_archivo'])) {
        if (!$db->query('ALTER TABLE rutas_excel_set_detalle ADD COLUMN usuario_nombre_archivo VARCHAR(255) NULL AFTER usuario_archivo')) {
            throw new RuntimeException('No fue posible agregar el nombre de usuario informado en el archivo. Ejecuta la migración SQL del módulo.');
        }
    }

    $db->query(
        "UPDATE rutas_excel_set_detalle rd
         LEFT JOIN usuario u ON u.id = rd.id_usuario
         SET rd.usuario_archivo = COALESCE(NULLIF(TRIM(rd.usuario_archivo), ''), NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR'),
             rd.usuario_nombre_archivo = COALESCE(NULLIF(TRIM(rd.usuario_nombre_archivo), ''), NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''))
         WHERE TRIM(COALESCE(rd.usuario_archivo, '')) = ''"
    );
}

function worksheetHeaderMap($worksheet, array $requiredColumns = []): array
{
    $highestRow = min(10, max(1, (int)$worksheet->getHighestRow()));
    $highestColumn = $worksheet->getHighestColumn();
    $headerRows = $worksheet->rangeToArray('A1:' . $highestColumn . $highestRow, null, true, true, true);
    foreach ($headerRows as $rowNumber => $headerRow) {
        $columns = [];
        foreach ($headerRow as $column => $label) {
            $key = normalizeHeader((string)$label);
            if ($key !== '') {
                $columns[$key] = $column;
            }
        }
        $hasRequiredColumns = true;
        foreach ($requiredColumns as $aliases) {
            if (columnFor($columns, $aliases) === null) {
                $hasRequiredColumns = false;
                break;
            }
        }
        if ($columns && $hasRequiredColumns) {
            return [(int)$rowNumber, $columns];
        }
    }
    return [1, []];
}

function routeRowValue(array $row, array $columns, array $aliases, $default = '')
{
    $column = columnFor($columns, $aliases);
    return $column ? ($row[$column] ?? $default) : $default;
}

function routeIdentityToken(string $user, string $group): string
{
    return substr(hash('sha256', normalizeText($user) . "\x1F" . normalizeText($group)), 0, 24);
}

function isRouteUnassigned(string $routeKey): bool
{
    return $routeKey === ROUTE_UNASSIGNED_KEY;
}

function canonicalRouteGroup(string $user, string $group): string
{
    $group = trim($group);
    $parts = array_map('trim', explode('|', $group));
    if (count($parts) > 1 && normalizeText((string)$parts[0]) === normalizeText($user)) {
        array_shift($parts);
        $withoutUser = trim(implode(' | ', $parts));
        if ($withoutUser !== '') {
            return $withoutUser;
        }
    }
    return $group;
}

function routeDisplayIdentifier(string $user, string $group): string
{
    $canonicalGroup = canonicalRouteGroup($user, $group);
    return trim($user) . ' | ' . $canonicalGroup;
}

function routeBaseRowKey(int $rowNumber, string $user, string $group, string $code): string
{
    return substr(hash('sha256', $rowNumber . "\x1F" . normalizeText($user) . "\x1F" . normalizeText($group) . "\x1F" . normalizeText($code)), 0, 32);
}

function extractPublishedRouteWorkbook(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
{
    $planning = findPlanningWorksheet($spreadsheet, false);
    $base = findRouteBaseWorksheet($spreadsheet, false);
    if ($planning === null && $base === null) {
        throw new RuntimeException('No se encontró una hoja Planificacion o Rutas Base con los encabezados requeridos.');
    }
    [$planningSheet, $planningHeaderRow, $planningColumns] = $planning ?? [null, 0, []];
    $planningRows = $planningSheet ? $planningSheet->toArray(null, true, true, true) : [];

    $schedules = [];
    $planningFallbackRows = [];
    $periodStart = null;
    $periodEnd = null;
    foreach ($planningRows as $rowNumber => $row) {
        if ((int)$rowNumber <= $planningHeaderRow) {
            continue;
        }
        $code = trim((string)routeRowValue($row, $planningColumns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo']));
        $user = trim((string)routeRowValue($row, $planningColumns, ['Usuario Login', 'Usuario', 'Login', 'Nombre Usuario']));
        $userName = trim((string)routeRowValue($row, $planningColumns, ['Usuario Nombre', 'Nombre Usuario']));
        $group = trim((string)routeRowValue($row, $planningColumns, ['Grupo Ruta', 'Ruta', 'Grupo']));
        $date = parseVisitDate(routeRowValue($row, $planningColumns, ['Fecha Ruta', 'Fecha Visita', 'Fecha Planificada', 'Fecha']));
        if ($code === '' || !$date) {
            continue;
        }
        if ($user === '') {
            $user = $userName !== '' ? $userName : 'SIN ASIGNAR';
        }
        $group = canonicalRouteGroup($user, $group);
        if ($group === '') {
            $group = 'RUTA ' . $date;
        }
        $routeToken = routeIdentityToken($user, $group);
        if (!isset($schedules[$routeToken])) {
            $schedules[$routeToken] = [
                'key' => $routeToken,
                'usuario_login' => $user,
                'usuario_nombre' => $userName !== '' ? $userName : $user,
                'grupo_ruta' => $group,
                'dates' => [],
            ];
        }
        $schedules[$routeToken]['dates'][$date] = [
            'fecha_ruta_sql' => $date,
            'usuario_id' => routeRowValue($row, $planningColumns, ['Usuario ID', 'ID Usuario']),
            'dia_plan' => routeRowValue($row, $planningColumns, ['Día Plan', 'Dia Plan']),
            'semana_plan' => routeRowValue($row, $planningColumns, ['Semana Plan']),
            'dia_semana_num' => routeRowValue($row, $planningColumns, ['Día Semana Nº', 'Dia Semana N', 'Dia Semana Numero']),
            'dia_semana' => routeRowValue($row, $planningColumns, ['Día Semana', 'Dia Semana']),
        ];
        $periodStart = $periodStart === null || $date < $periodStart ? $date : $periodStart;
        $periodEnd = $periodEnd === null || $date > $periodEnd ? $date : $periodEnd;
        $planningFallbackRows[] = [
            'row_number' => (int)$rowNumber,
            'codigo_local' => $code,
            'nombre' => trim((string)routeRowValue($row, $planningColumns, ['Nombre', 'Nombre Local'])),
            'direccion' => trim((string)routeRowValue($row, $planningColumns, ['Dirección', 'Direccion'])),
            'comuna' => trim((string)routeRowValue($row, $planningColumns, ['Comuna'])),
            'lat' => routeRowValue($row, $planningColumns, ['Lat', 'Latitud'], null),
            'lng' => routeRowValue($row, $planningColumns, ['Lng', 'Longitud', 'Lon'], null),
            'cantidad_objetivo_dia' => routeRowValue($row, $planningColumns, ['Cantidad Objetivo Día', 'Cantidad Objetivo Dia'], 0),
            'usuario_login' => $user,
            'usuario_nombre' => $userName !== '' ? $userName : $user,
            'grupo_ruta' => $group,
            'orden_visita' => (int)routeRowValue($row, $planningColumns, ['Orden Visita', 'Orden'], 0),
            'tamano_ruta' => (int)routeRowValue($row, $planningColumns, ['Tamaño Ruta', 'Tamano Ruta'], 0),
            'distancia_total_ruta_km' => (float)routeRowValue($row, $planningColumns, ['Distancia Total Ruta (KM)', 'Distancia Total Ruta'], 0),
            'primera_fecha_original' => $date,
            'ultima_fecha_original' => $date,
            'observacion' => trim((string)routeRowValue($row, $planningColumns, ['Observación', 'Observacion'])),
        ];
    }
    foreach ($schedules as &$schedule) {
        ksort($schedule['dates']);
        $schedule['dates'] = array_values($schedule['dates']);
    }
    unset($schedule);

    [$baseSheet, $baseHeaderRow, $baseColumns] = $base ?? [null, 0, []];
    $baseRows = [];
    if ($baseSheet !== null) {
        $sourceRows = $baseSheet->toArray(null, true, true, true);
        foreach ($sourceRows as $rowNumber => $row) {
            if ((int)$rowNumber <= $baseHeaderRow) {
                continue;
            }
            $code = trim((string)routeRowValue($row, $baseColumns, ['Código Local', 'Codigo Local', 'codigo_local', 'codigo', 'Código Sala', 'Codigo Sala']));
            $user = trim((string)routeRowValue($row, $baseColumns, ['Usuario Login', 'Usuario', 'Login', 'ID Usuario', 'Usuario ID', 'Nombre Usuario']));
            $userName = trim((string)routeRowValue($row, $baseColumns, ['Usuario Nombre', 'Nombre Usuario']));
            $group = trim((string)routeRowValue($row, $baseColumns, ['Grupo Ruta', 'Ruta', 'Grupo']));
            if ($code === '') {
                continue;
            }
            if ($user === '') {
                $user = $userName !== '' ? $userName : 'SIN ASIGNAR';
            }
            $group = canonicalRouteGroup($user, $group);
            $routeKey = $group === '' ? ROUTE_UNASSIGNED_KEY : routeIdentityToken($user, $group);
            $baseRows[] = [
                'row_number' => (int)$rowNumber,
                'row_key' => routeBaseRowKey((int)$rowNumber, $user, $group, $code),
                'route_key' => $routeKey,
                'codigo_local' => $code,
                'nombre' => trim((string)routeRowValue($row, $baseColumns, ['Nombre', 'Nombre Local'])),
                'direccion' => trim((string)routeRowValue($row, $baseColumns, ['Dirección', 'Direccion'])),
                'comuna' => trim((string)routeRowValue($row, $baseColumns, ['Comuna'])),
                'lat' => routeRowValue($row, $baseColumns, ['Lat', 'Latitud'], null),
                'lng' => routeRowValue($row, $baseColumns, ['Lng', 'Longitud', 'Lon'], null),
                'cantidad_objetivo_dia' => routeRowValue($row, $baseColumns, ['Cantidad Objetivo Día', 'Cantidad Objetivo Dia'], 0),
                'usuario_login' => $user,
                'usuario_nombre' => $userName !== '' ? $userName : $user,
                'grupo_ruta' => $group,
                'orden_visita' => (int)routeRowValue($row, $baseColumns, ['Orden Visita', 'Orden'], 0),
                'tamano_ruta' => (int)routeRowValue($row, $baseColumns, ['Tamaño Ruta', 'Tamano Ruta'], 0),
                'distancia_total_ruta_km' => (float)routeRowValue($row, $baseColumns, ['Distancia Total Ruta (KM)', 'Distancia Total Ruta'], 0),
                'primera_fecha_original' => (string)routeRowValue($row, $baseColumns, ['Primera Fecha Original'], ''),
                'ultima_fecha_original' => (string)routeRowValue($row, $baseColumns, ['Última Fecha Original', 'Ultima Fecha Original'], ''),
                'observacion' => trim((string)routeRowValue($row, $baseColumns, ['Observación', 'Observacion'])),
            ];
        }
    } else {
        $seenBase = [];
        foreach ($planningFallbackRows as $row) {
            $dedupeKey = routeIdentityToken($row['usuario_login'], $row['grupo_ruta']) . "\x1F" . normalizeText($row['codigo_local']);
            if (isset($seenBase[$dedupeKey])) {
                continue;
            }
            $seenBase[$dedupeKey] = true;
            $row['row_key'] = routeBaseRowKey((int)$row['row_number'], $row['usuario_login'], $row['grupo_ruta'], $row['codigo_local']);
            $row['route_key'] = routeIdentityToken($row['usuario_login'], $row['grupo_ruta']);
            unset($row['row_number']);
            $baseRows[] = $row;
        }
    }

    $targets = [];
    foreach ($baseRows as $row) {
        $key = $row['route_key'];
        if (isRouteUnassigned((string)$key)) {
            continue;
        }
        if (!isset($targets[$key])) {
            $schedule = $schedules[$key] ?? null;
            $targets[$key] = [
                'key' => $key,
                'usuario_login' => $row['usuario_login'],
                'usuario_nombre' => $row['usuario_nombre'],
                'grupo_ruta' => $row['grupo_ruta'],
                'locales' => 0,
                'repeticiones' => $schedule ? count($schedule['dates']) : 0,
                'fechas' => $schedule ? array_values(array_map(
                    static fn(array $dateMeta): string => (string)$dateMeta['fecha_ruta_sql'],
                    $schedule['dates']
                )) : [],
            ];
        }
        $targets[$key]['locales']++;
    }
    usort($baseRows, static fn(array $left, array $right): int => [normalizeText($left['usuario_login']), normalizeText($left['grupo_ruta']), (int)$left['orden_visita']] <=> [normalizeText($right['usuario_login']), normalizeText($right['grupo_ruta']), (int)$right['orden_visita']]);
    $targets = array_values($targets);
    usort($targets, static fn(array $left, array $right): int => [normalizeText($left['usuario_login']), normalizeText($left['grupo_ruta'])] <=> [normalizeText($right['usuario_login']), normalizeText($right['grupo_ruta'])]);

    return [
        'base_rows' => $baseRows,
        'schedules' => $schedules,
        'targets' => $targets,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'base_sheet_name' => $baseSheet ? $baseSheet->getTitle() : $planningSheet->getTitle(),
        'planning_sheet_name' => $planningSheet ? $planningSheet->getTitle() : '',
        'tipo_contenido' => $schedules ? 'planificacion' : 'rutas_base',
    ];
}

function routeDistanceKm($lat1, $lng1, $lat2, $lng2): ?float
{
    $lat1 = is_numeric($lat1) ? (float)$lat1 : null;
    $lng1 = is_numeric($lng1) ? (float)$lng1 : null;
    $lat2 = is_numeric($lat2) ? (float)$lat2 : null;
    $lng2 = is_numeric($lng2) ? (float)$lng2 : null;
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
        return null;
    }
    if (($lat1 === 0.0 && $lng1 === 0.0) || ($lat2 === 0.0 && $lng2 === 0.0)) {
        return null;
    }
    $earthRadius = 6371.0088;
    $latDelta = deg2rad($lat2 - $lat1);
    $lngDelta = deg2rad($lng2 - $lng1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

function reorderRouteBaseRows(array $rows, array $autoOrderRouteKeys = []): array
{
    $autoOrderSet = array_fill_keys(array_map('strval', $autoOrderRouteKeys), true);
    $groups = [];
    $unassignedRows = [];
    foreach ($rows as $row) {
        if (isRouteUnassigned((string)$row['route_key'])) {
            $row['grupo_ruta'] = '';
            $row['orden_visita'] = 0;
            $row['tamano_ruta'] = 0;
            $row['distancia_desde_anterior_km'] = 0.0;
            $row['distancia_total_ruta_km'] = 0.0;
            $row['primera_fecha_original'] = '';
            $row['ultima_fecha_original'] = '';
            $unassignedRows[] = $row;
            continue;
        }
        $groups[(string)$row['route_key']][] = $row;
    }

    $ordered = [];
    foreach ($groups as $routeRows) {
        usort($routeRows, static function (array $left, array $right): int {
            $orderComparison = ((int)$left['orden_visita']) <=> ((int)$right['orden_visita']);
            return $orderComparison !== 0 ? $orderComparison : strcmp((string)$left['row_key'], (string)$right['row_key']);
        });

        $routeKey = (string)($routeRows[0]['route_key'] ?? '');
        if (isset($autoOrderSet[$routeKey])) {
            $withCoordinates = [];
            $withoutCoordinates = [];
            foreach ($routeRows as $row) {
                $lat = is_numeric($row['lat']) ? (float)$row['lat'] : null;
                $lng = is_numeric($row['lng']) ? (float)$row['lng'] : null;
                if ($lat !== null && $lng !== null && !($lat === 0.0 && $lng === 0.0)) {
                    $withCoordinates[] = $row;
                } else {
                    $withoutCoordinates[] = $row;
                }
            }

            $routeOrder = [];
            if ($withCoordinates) {
                $current = array_shift($withCoordinates);
                $routeOrder[] = $current;
                while ($withCoordinates) {
                    $nearestIndex = 0;
                    $nearestDistance = INF;
                    foreach ($withCoordinates as $index => $candidate) {
                        $distance = routeDistanceKm($current['lat'], $current['lng'], $candidate['lat'], $candidate['lng']);
                        if ($distance !== null && $distance < $nearestDistance) {
                            $nearestDistance = $distance;
                            $nearestIndex = $index;
                        }
                    }
                    $current = $withCoordinates[$nearestIndex];
                    array_splice($withCoordinates, $nearestIndex, 1);
                    $routeOrder[] = $current;
                }
            }
            $routeOrder = array_merge($routeOrder, $withoutCoordinates);
        } else {
            $routeOrder = $routeRows;
        }

        $totalDistance = 0.0;
        $previous = null;
        foreach ($routeOrder as $index => &$row) {
            $segment = $previous === null
                ? 0.0
                : (routeDistanceKm($previous['lat'], $previous['lng'], $row['lat'], $row['lng']) ?? 0.0);
            $totalDistance += $segment;
            $row['orden_visita'] = $index + 1;
            $row['tamano_ruta'] = count($routeOrder);
            $row['distancia_desde_anterior_km'] = round($segment, 2);
            $previous = $row;
        }
        unset($row);
        foreach ($routeOrder as &$row) {
            $row['distancia_total_ruta_km'] = round($totalDistance, 2);
        }
        unset($row);
        $ordered = array_merge($ordered, $routeOrder);
    }
    $ordered = array_merge($ordered, $unassignedRows);

    usort($ordered, static function (array $left, array $right): int {
        $userComparison = strcmp(normalizeText((string)$left['usuario_login']), normalizeText((string)$right['usuario_login']));
        if ($userComparison !== 0) {
            return $userComparison;
        }
        $routeComparison = strcmp(normalizeText((string)$left['grupo_ruta']), normalizeText((string)$right['grupo_ruta']));
        return $routeComparison !== 0 ? $routeComparison : ((int)$left['orden_visita'] <=> (int)$right['orden_visita']);
    });
    return $ordered;
}

function uniqueRouteSheetTitle(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $preferred): string
{
    $preferred = mb_substr($preferred, 0, 31, 'UTF-8');
    $title = $preferred;
    $suffix = 2;
    while ($spreadsheet->sheetNameExists($title)) {
        $tail = ' ' . $suffix++;
        $title = mb_substr($preferred, 0, 31 - mb_strlen($tail, 'UTF-8'), 'UTF-8') . $tail;
    }
    return $title;
}

function styleRouteOutputSheet($sheet, string $lastColumn, int $lastRow): void
{
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:' . $lastColumn . max(1, $lastRow));
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF004AAD');
    $sheet->getStyle('A1:' . $lastColumn . max(1, $lastRow))->getAlignment()->setVertical('center');
    if ($lastRow > 1) {
        for ($row = 2; $row <= $lastRow; $row += 2) {
            $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF2F6FC');
        }
    }
}

function setRouteSheetWidths($sheet, array $widths): void
{
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
}

function loadPublishedRouteWorkbook(array $set): array
{
    $setId = (int)$set['id'];
    $storedPath = routeSetStoredFilePath($setId, (string)($set['archivo_original'] ?? ''));
    if ($storedPath === null || !is_file($storedPath) || !is_readable($storedPath)) {
        throw new RuntimeException('Esta planificación no conserva el Excel original. Actualízala desde el módulo administrativo para habilitar el editor de rutas base.');
    }
    require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/vendor/autoload.php';
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($storedPath);
    return [$spreadsheet, $storedPath];
}

function filterRejectedBaseRows(array $routeData, array $rejectedRowNumbers): array
{
    if ($routeData['tipo_contenido'] !== 'rutas_base' || !$rejectedRowNumbers) {
        return $routeData;
    }
    $rejected = array_fill_keys($rejectedRowNumbers, true);
    $routeData['base_rows'] = array_values(array_filter($routeData['base_rows'], static function (array $row) use ($rejected): bool {
        return !isset($rejected[(int)($row['row_number'] ?? 0)]);
    }));
    $counts = array_count_values(array_column($routeData['base_rows'], 'route_key'));
    $targets = [];
    foreach ($routeData['targets'] as $target) {
        if (isset($counts[$target['key']])) {
            $target['locales'] = $counts[$target['key']];
            $targets[] = $target;
        }
    }
    $routeData['targets'] = $targets;
    return $routeData;
}

function readPublishedRouteData(mysqli $db, array $set, \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
{
    $routeData = extractPublishedRouteWorkbook($spreadsheet);
    if ($routeData['tipo_contenido'] === 'rutas_base' && (int)($set['filas_rechazadas'] ?? 0) > 0) {
        $setId = (int)$set['id'];
        $stmt = $db->prepare('SELECT fila_archivo FROM rutas_excel_set_rechazos WHERE id_set = ?');
        $stmt->bind_param('i', $setId);
        $stmt->execute();
        $rejections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $routeData = filterRejectedBaseRows($routeData, array_column($rejections, 'fila_archivo'));
    }
    return $routeData;
}

if ($action === 'base_routes') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'La planificación no existe o no está autorizada.'], 404);
    }
    try {
        [$spreadsheet] = loadPublishedRouteWorkbook($set);
        $routeData = readPublishedRouteData($db, $set, $spreadsheet);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        apiJson([
            'ok' => true,
            'set' => $set,
            'data' => $routeData['base_rows'],
            'targets' => $routeData['targets'],
            'period_start' => $routeData['period_start'],
            'period_end' => $routeData['period_end'],
            'base_sheet_name' => $routeData['base_sheet_name'],
            'planning_sheet_name' => $routeData['planning_sheet_name'],
            'tipo_contenido' => $routeData['tipo_contenido'],
        ]);
    } catch (Throwable $e) {
        apiJson(['ok' => false, 'message' => 'No fue posible preparar las rutas base: ' . $e->getMessage()], 422);
    }
}

if ($action === 'latest_dates') {
    requireCsrf();
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) {
        apiJson(['ok' => false, 'message' => 'La lista de locales no es valida.'], 400);
    }

    $localIds = [];
    $localCodes = [];
    foreach (array_slice($items, 0, 20000) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $idLocal = (int)($item['id_local'] ?? 0);
        $codigo = trim((string)($item['codigo'] ?? ''));
        if ($idLocal > 0) {
            $localIds[$idLocal] = true;
        }
        if ($codigo !== '') {
            $localCodes[$codigo] = true;
        }
    }

    $byId = [];
    $byCode = [];
    $isMcSession = isMcDivision($db, $sessionDivisionId);

    $readLatestDates = static function (array $values, bool $byLocalId) use (
        $db,
        $sessionCompanyId,
        $sessionDivisionId,
        $isMcSession,
        &$byId,
        &$byCode
    ): void {
        foreach (array_chunk($values, 750) as $chunk) {
            if (!$chunk) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT l.id AS id_local, l.codigo, MAX(fq.fechaPropuesta) AS ultima_fecha_propuesta
                    FROM local l
                    JOIN division_empresa d ON d.id = l.id_division
                    JOIN formularioQuestion fq ON fq.id_local = l.id
                    WHERE l.deleted_at IS NULL
                      AND fq.fechaPropuesta IS NOT NULL
                      AND ' . ($byLocalId ? 'l.id' : 'l.codigo') . " IN ($placeholders)";
            $types = str_repeat($byLocalId ? 'i' : 's', count($chunk));
            $params = array_values($chunk);

            if ($sessionCompanyId > 0) {
                $sql .= ' AND d.id_empresa = ?';
                $types .= 'i';
                $params[] = $sessionCompanyId;
            }
            if (!$isMcSession && $sessionDivisionId > 0) {
                $sql .= ' AND l.id_division = ?';
                $types .= 'i';
                $params[] = $sessionDivisionId;
            }
            $sql .= ' GROUP BY l.id, l.codigo';

            $stmt = $db->prepare($sql);
            if (!$stmt) {
                apiJson(['ok' => false, 'message' => 'No fue posible preparar las ultimas fechas: ' . $db->error], 500);
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                apiJson(['ok' => false, 'message' => 'No fue posible consultar las ultimas fechas: ' . $message], 500);
            }
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $dateSql = substr((string)($row['ultima_fecha_propuesta'] ?? ''), 0, 10);
                if ($dateSql === '') {
                    continue;
                }
                $idKey = (string)(int)$row['id_local'];
                $codeKey = strtoupper(trim((string)$row['codigo']));
                if (!isset($byId[$idKey]) || $dateSql > $byId[$idKey]) {
                    $byId[$idKey] = $dateSql;
                }
                if ($codeKey !== '' && (!isset($byCode[$codeKey]) || $dateSql > $byCode[$codeKey])) {
                    $byCode[$codeKey] = $dateSql;
                }
            }
            $stmt->close();
        }
    };

    $readLatestDates(array_keys($localIds), true);
    $readLatestDates(array_keys($localCodes), false);

    apiJson([
        'ok' => true,
        'by_id' => $byId,
        'by_code' => $byCode,
        'matched' => count($byId),
    ]);
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
                   CASE WHEN EXISTS (SELECT 1 FROM rutas_excel_set_detalle rd WHERE rd.id_set = rs.id) THEN \'planificacion\' ELSE \'rutas_base\' END AS tipo_contenido,
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
    foreach ($rows as &$row) {
        $storedPath = routeSetStoredFilePath((int)$row['id'], (string)($row['archivo_original'] ?? ''));
        $row['archivo_original_disponible'] = $storedPath !== null && is_file($storedPath);
    }
    unset($row);
    apiJson(['ok' => true, 'data' => $rows, 'can_manage' => $canManage]);
}

if ($action === 'set_users') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    $rawUserSchema = routeDetailUserSchemaAvailable($db);
    $userExpression = $rawUserSchema
        ? "COALESCE(NULLIF(TRIM(rd.usuario_archivo), ''), NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')"
        : "COALESCE(NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')";
    $userNameExpression = $rawUserSchema
        ? "COALESCE(NULLIF(TRIM(rd.usuario_nombre_archivo), ''), NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)"
        : "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)";
    $userJoin = $rawUserSchema ? 'LEFT JOIN' : 'JOIN';
    $stmt = $db->prepare(
        "SELECT
            MIN(COALESCE(rd.id_usuario, 0)) AS id,
            $userExpression AS usuario,
            $userNameExpression AS nombre,
            COUNT(*) AS total_locales,
            COUNT(DISTINCT rd.fecha_visita) AS total_fechas,
            MIN(rd.fecha_visita) AS fecha_desde,
            MAX(rd.fecha_visita) AS fecha_hasta
         FROM rutas_excel_set_detalle rd
         $userJoin usuario u ON u.id = rd.id_usuario
         WHERE rd.id_set = ?
         GROUP BY $userExpression, $userNameExpression
         ORDER BY usuario, nombre"
    );
    if (!$stmt) {
        apiJson(['ok' => false, 'message' => 'No se pudieron preparar los usuarios del set: ' . $db->error], 500);
    }
    $stmt->bind_param('i', $setId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['key'] = (string)$row['usuario'];
    }
    unset($row);
    apiJson(['ok' => true, 'set' => $set, 'data' => $rows]);
}

if ($action === 'details') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $userId = (int)($_GET['id_usuario'] ?? 0);
    $userKey = trim((string)($_GET['usuario_clave'] ?? ''));
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }
    $rawUserSchema = routeDetailUserSchemaAvailable($db);
    $userExpression = $rawUserSchema
        ? "COALESCE(NULLIF(TRIM(rd.usuario_archivo), ''), NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')"
        : "COALESCE(NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')";
    $userNameExpression = $rawUserSchema
        ? "COALESCE(NULLIF(TRIM(rd.usuario_nombre_archivo), ''), NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)"
        : "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)";
    $userJoin = $rawUserSchema ? 'LEFT JOIN' : 'JOIN';
    $sql = 'SELECT rd.id, rd.fecha_visita, rd.orden_visita,
                   l.id AS id_local, l.codigo AS codigo_local, l.nombre AS nombre_local,
                   COALESCE(NULLIF(l.direccion_google,\'\'), l.direccion) AS direccion,
                   l.lat, l.lng, c.comuna,
                   COALESCE(rd.id_usuario, 0) AS id_usuario,
                   ' . $userExpression . ' AS usuario,
                   ' . $userNameExpression . ' AS usuario_nombre
            FROM rutas_excel_set_detalle rd
            JOIN local l ON l.id = rd.id_local
            LEFT JOIN comuna c ON c.id = l.id_comuna
            ' . $userJoin . ' usuario u ON u.id = rd.id_usuario
            WHERE rd.id_set = ?';
    if ($userKey !== '') {
        $sql .= ' AND ' . $userExpression . ' = ?';
    } elseif ($userId > 0) {
        $sql .= ' AND rd.id_usuario = ?';
    }
    $sql .= ' ORDER BY rd.fecha_visita, usuario, rd.orden_visita, l.codigo';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        apiJson(['ok' => false, 'message' => 'No se pudo preparar el detalle del set: ' . $db->error], 500);
    }
    if ($userKey !== '') {
        $stmt->bind_param('is', $setId, $userKey);
    } elseif ($userId > 0) {
        $stmt->bind_param('ii', $setId, $userId);
    } else {
        $stmt->bind_param('i', $setId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    apiJson(['ok' => true, 'set' => $set, 'data' => $rows]);
}

if ($action === 'download_base_adjusted') {
    requireCsrf();
    $setId = (int)($_POST['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'La planificación no existe o no está autorizada.'], 404);
    }

    $submittedRows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($submittedRows)) {
        apiJson(['ok' => false, 'message' => 'Los cambios enviados no tienen un formato válido.'], 422);
    }
    $submittedNewRoutes = json_decode((string)($_POST['new_routes'] ?? '[]'), true);
    if (!is_array($submittedNewRoutes)) {
        apiJson(['ok' => false, 'message' => 'Las rutas nuevas enviadas no tienen un formato válido.'], 422);
    }
    if (count($submittedNewRoutes) > 100) {
        apiJson(['ok' => false, 'message' => 'Se excedió el máximo de rutas nuevas permitido por descarga.'], 422);
    }

    try {
        [$spreadsheet] = loadPublishedRouteWorkbook($set);
        $routeData = readPublishedRouteData($db, $set, $spreadsheet);
        $originalRows = $routeData['base_rows'];
        if (!$originalRows) {
            throw new RuntimeException('El archivo no contiene rutas base utilizables.');
        }
        if (count($submittedRows) !== count($originalRows)) {
            throw new RuntimeException('La cantidad de locales editados no coincide con el rutero base publicado. Recarga la página e inténtalo nuevamente.');
        }

        $originalMap = [];
        foreach ($originalRows as $row) {
            $originalMap[(string)$row['row_key']] = $row;
        }
        $targetMap = [];
        foreach ($routeData['targets'] as $target) {
            $key = (string)$target['key'];
            $targetMap[$key] = $target;
        }
        $knownRouteNames = [];
        foreach ($targetMap as $target) {
            $knownRouteNames[normalizeText((string)$target['usuario_login']) . "\x1F" . normalizeText((string)$target['grupo_ruta'])] = true;
        }
        foreach ($submittedNewRoutes as $newRoute) {
            if (!is_array($newRoute)) {
                throw new RuntimeException('Se recibió una definición de ruta nueva inválida.');
            }
            $newKey = trim((string)($newRoute['key'] ?? ''));
            $group = trim((string)($newRoute['grupo_ruta'] ?? ''));
            $sourceKey = trim((string)($newRoute['source_route_key'] ?? ''));
            if (!preg_match('/^__NUEVA_RUTA__\d+_\d+$/', $newKey) || isset($targetMap[$newKey])) {
                throw new RuntimeException('Una de las rutas nuevas posee un identificador inválido o duplicado.');
            }
            if ($group === '' || mb_strlen($group, 'UTF-8') > 80) {
                throw new RuntimeException('El nombre de una ruta nueva está vacío o supera los 80 caracteres.');
            }
            $sourceTarget = $targetMap[$sourceKey] ?? null;
            $sourceSchedule = $routeData['schedules'][$sourceKey] ?? null;
            if (!$sourceTarget || !$sourceSchedule || empty($sourceSchedule['dates'])) {
                throw new RuntimeException('No fue posible obtener las fechas de referencia para una ruta nueva.');
            }
            $nameKey = normalizeText((string)$sourceTarget['usuario_login']) . "\x1F" . normalizeText($group);
            if (isset($knownRouteNames[$nameKey])) {
                throw new RuntimeException('Ya existe una ruta con el mismo nombre para el usuario seleccionado.');
            }
            $knownRouteNames[$nameKey] = true;
            $targetMap[$newKey] = [
                'key' => $newKey,
                'usuario_login' => (string)$sourceTarget['usuario_login'],
                'usuario_nombre' => (string)$sourceTarget['usuario_nombre'],
                'grupo_ruta' => $group,
                'locales' => 0,
                'repeticiones' => count($sourceSchedule['dates']),
            ];
            $routeData['schedules'][$newKey] = $sourceSchedule;
            $routeData['schedules'][$newKey]['key'] = $newKey;
            $routeData['schedules'][$newKey]['usuario_login'] = (string)$sourceTarget['usuario_login'];
            $routeData['schedules'][$newKey]['usuario_nombre'] = (string)$sourceTarget['usuario_nombre'];
            $routeData['schedules'][$newKey]['grupo_ruta'] = $group;
        }

        $seenRows = [];
        $adjustedRows = [];
        $autoOrderRouteKeys = [];
        foreach ($submittedRows as $submitted) {
            if (!is_array($submitted)) {
                throw new RuntimeException('Se recibió una fila de edición inválida.');
            }
            $rowKey = trim((string)($submitted['row_key'] ?? ''));
            $routeKey = trim((string)($submitted['route_key'] ?? ''));
            $leaveUnassigned = isRouteUnassigned($routeKey);
            if ($rowKey === '' || !isset($originalMap[$rowKey]) || isset($seenRows[$rowKey])) {
                throw new RuntimeException('Las filas editadas no corresponden al rutero base publicado. Recarga la página.');
            }
            if (!$leaveUnassigned && ($routeKey === '' || !isset($targetMap[$routeKey]))) {
                throw new RuntimeException('Una de las rutas de destino no existe en el rutero base publicado.');
            }
            if (!$leaveUnassigned && empty($routeData['schedules'][$routeKey]['dates']) && $routeKey !== (string)$originalMap[$rowKey]['route_key']) {
                throw new RuntimeException('Una de las rutas de destino no posee fechas en la planificación original.');
            }
            $seenRows[$rowKey] = true;
            $row = $originalMap[$rowKey];
            $target = $leaveUnassigned ? null : $targetMap[$routeKey];
            $submittedOrder = $leaveUnassigned ? 0 : max(1, (int)($submitted['orden_visita'] ?? $row['orden_visita'] ?? 1));
            if ($routeKey !== (string)$row['route_key'] || $submittedOrder !== (int)$row['orden_visita']) {
                if (!isRouteUnassigned((string)$row['route_key'])) {
                    $autoOrderRouteKeys[(string)$row['route_key']] = true;
                }
                if (!$leaveUnassigned) {
                    $autoOrderRouteKeys[$routeKey] = true;
                }
            }
            $row['route_key'] = $routeKey;
            if ($leaveUnassigned) {
                $row['grupo_ruta'] = '';
                $row['tamano_ruta'] = 0;
                $row['distancia_desde_anterior_km'] = 0.0;
                $row['distancia_total_ruta_km'] = 0.0;
                $row['primera_fecha_original'] = '';
                $row['ultima_fecha_original'] = '';
            } else {
                $row['usuario_login'] = (string)$target['usuario_login'];
                $row['usuario_nombre'] = (string)$target['usuario_nombre'];
                $row['grupo_ruta'] = (string)$target['grupo_ruta'];
            }
            $row['orden_visita'] = $submittedOrder;
            $adjustedRows[] = $row;
        }
        $adjustedRows = reorderRouteBaseRows($adjustedRows, array_keys($autoOrderRouteKeys));

        $adjustedByKey = [];
        foreach ($adjustedRows as $row) {
            $adjustedByKey[(string)$row['row_key']] = $row;
        }

        $baseHeaders = [
            'Usuario Login', 'Usuario Nombre', 'Grupo Ruta', 'Identificador Ruta', 'Código Local',
            'Nombre', 'Dirección', 'Comuna', 'Lat', 'Lng', 'Orden Visita', 'Tamaño Ruta',
            'Cantidad Objetivo Día', 'Distancia Total Ruta (KM)', 'Primera Fecha Original',
            'Última Fecha Original', 'Observación',
        ];
        $baseOutput = [];
        foreach ($adjustedRows as $row) {
            $leaveUnassigned = isRouteUnassigned((string)$row['route_key']);
            $scheduleDates = $leaveUnassigned ? [] : ($routeData['schedules'][$row['route_key']]['dates'] ?? []);
            $firstDate = $leaveUnassigned ? '' : ($scheduleDates ? (string)$scheduleDates[0]['fecha_ruta_sql'] : (string)$row['primera_fecha_original']);
            $lastDate = $leaveUnassigned ? '' : ($scheduleDates ? (string)$scheduleDates[count($scheduleDates) - 1]['fecha_ruta_sql'] : (string)$row['ultima_fecha_original']);
            $baseOutput[] = [
                $row['usuario_login'], $row['usuario_nombre'], $row['grupo_ruta'], $leaveUnassigned ? '' : $row['usuario_login'] . ' | ' . $row['grupo_ruta'],
                $row['codigo_local'], $row['nombre'], $row['direccion'], $row['comuna'], $row['lat'], $row['lng'],
                $row['orden_visita'], $row['tamano_ruta'], $row['cantidad_objetivo_dia'],
                $row['distancia_total_ruta_km'], $firstDate, $lastDate, $row['observacion'],
            ];
        }
        $baseSheet = $spreadsheet->createSheet();
        $baseSheet->setTitle(uniqueRouteSheetTitle($spreadsheet, 'Rutas Base Ajustadas'));
        $baseSheet->fromArray($baseHeaders, null, 'A1');
        if ($baseOutput) {
            $baseSheet->fromArray($baseOutput, null, 'A2');
            foreach ($baseOutput as $index => $outputRow) {
                $excelRow = $index + 2;
                $baseSheet->setCellValueExplicit('A' . $excelRow, (string)$outputRow[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $baseSheet->setCellValue('D' . $excelRow, '=IF(C' . $excelRow . '="","",A' . $excelRow . '&" | "&C' . $excelRow . ')');
                $baseSheet->setCellValueExplicit('E' . $excelRow, (string)$outputRow[4], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                foreach (['O' => $outputRow[14], 'P' => $outputRow[15]] as $column => $dateValue) {
                    $dateSql = parseVisitDate($dateValue);
                    if ($dateSql !== null) {
                        $baseSheet->setCellValue($column . $excelRow, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($dateSql . ' 12:00:00')));
                    }
                }
            }
        }
        $baseLastRow = count($baseOutput) + 1;
        styleRouteOutputSheet($baseSheet, 'Q', $baseLastRow);
        if ($baseLastRow > 1) {
            $baseSheet->getStyle('O2:P' . $baseLastRow)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        }
        setRouteSheetWidths($baseSheet, [
            'A' => 20, 'B' => 26, 'C' => 20, 'D' => 28, 'E' => 16, 'F' => 30, 'G' => 38,
            'H' => 20, 'I' => 13, 'J' => 13, 'K' => 14, 'L' => 14, 'M' => 22, 'N' => 23,
            'O' => 21, 'P' => 21, 'Q' => 36,
        ]);

        $planningHeaders = [
            'Código Local', 'Nombre', 'Dirección', 'Comuna', 'Lat', 'Lng', 'Cantidad Objetivo Día',
            'Días Planificados', 'Grupo Ruta', 'Grupo Ruta Sugerido', 'Fecha Ruta', 'Usuario ID',
            'Usuario Login', 'Usuario Nombre', 'Día Plan', 'Semana Plan', 'Día Semana Nº',
            'Día Semana', 'Orden Visita', 'Tamaño Ruta', 'Distancia Desde Anterior (KM)',
            'Distancia Total Ruta (KM)', 'Observación',
        ];
        $planningOutput = [];
        foreach ($adjustedRows as $row) {
            if (isRouteUnassigned((string)$row['route_key'])) {
                continue;
            }
            $schedule = $routeData['schedules'][$row['route_key']] ?? null;
            if (!$schedule || empty($schedule['dates'])) {
                continue;
            }
            $plannedDays = count($schedule['dates']);
            foreach ($schedule['dates'] as $dateMeta) {
                $planningOutput[] = [
                    $row['codigo_local'], $row['nombre'], $row['direccion'], $row['comuna'], $row['lat'], $row['lng'],
                    $row['cantidad_objetivo_dia'], $plannedDays, routeDisplayIdentifier($row['usuario_login'], $row['grupo_ruta']), '',
                    $dateMeta['fecha_ruta_sql'], $dateMeta['usuario_id'], $row['usuario_login'], $row['usuario_nombre'],
                    $dateMeta['dia_plan'], $dateMeta['semana_plan'], $dateMeta['dia_semana_num'], $dateMeta['dia_semana'],
                    $row['orden_visita'], $row['tamano_ruta'], $row['distancia_desde_anterior_km'],
                    $row['distancia_total_ruta_km'], $row['observacion'],
                ];
            }
        }
        usort($planningOutput, static function (array $left, array $right): int {
            foreach ([[10, false], [12, true], [8, true], [18, false]] as [$index, $normalized]) {
                $leftValue = $normalized ? normalizeText((string)$left[$index]) : $left[$index];
                $rightValue = $normalized ? normalizeText((string)$right[$index]) : $right[$index];
                $comparison = $leftValue <=> $rightValue;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
        $planningSheet = $spreadsheet->createSheet();
        $planningSheet->setTitle(uniqueRouteSheetTitle($spreadsheet, 'Planificacion Ajustada'));
        $planningSheet->fromArray($planningHeaders, null, 'A1');
        if ($planningOutput) {
            $planningSheet->fromArray($planningOutput, null, 'A2');
            foreach ($planningOutput as $index => $outputRow) {
                $excelRow = $index + 2;
                $planningSheet->setCellValueExplicit('A' . $excelRow, (string)$outputRow[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $planningSheet->setCellValueExplicit('L' . $excelRow, (string)$outputRow[11], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $planningSheet->setCellValueExplicit('M' . $excelRow, (string)$outputRow[12], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $dateSql = parseVisitDate($outputRow[10]);
                if ($dateSql !== null) {
                    $planningSheet->setCellValue('K' . $excelRow, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($dateSql . ' 12:00:00')));
                }
            }
        }
        $planningLastRow = count($planningOutput) + 1;
        styleRouteOutputSheet($planningSheet, 'W', $planningLastRow);
        if ($planningLastRow > 1) {
            $planningSheet->getStyle('K2:K' . $planningLastRow)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        }
        setRouteSheetWidths($planningSheet, [
            'A' => 16, 'B' => 30, 'C' => 38, 'D' => 20, 'E' => 13, 'F' => 13, 'G' => 22,
            'H' => 18, 'I' => 20, 'J' => 22, 'K' => 15, 'L' => 14, 'M' => 20, 'N' => 27,
            'O' => 14, 'P' => 15, 'Q' => 17, 'R' => 16, 'S' => 14, 'T' => 14, 'U' => 29,
            'V' => 26, 'W' => 36,
        ]);

        $changeHeaders = [
            'Código Local', 'Nombre', 'Usuario Origen', 'Ruta Origen', 'Orden Origen',
            'Usuario Destino', 'Ruta Destino', 'Orden Destino', 'Tipo de Cambio',
        ];
        $changeOutput = [];
        foreach ($originalRows as $original) {
            $adjusted = $adjustedByKey[$original['row_key']] ?? null;
            if (!$adjusted) {
                continue;
            }
            $routeChanged = $original['route_key'] !== $adjusted['route_key'];
            $orderChanged = (int)$original['orden_visita'] !== (int)$adjusted['orden_visita'];
            if (!$routeChanged && !$orderChanged) {
                continue;
            }
            $leaveUnassigned = isRouteUnassigned((string)$adjusted['route_key']);
            $type = $leaveUnassigned ? 'Sin ruta' : ($routeChanged && $orderChanged ? 'Ruta y orden' : ($routeChanged ? 'Ruta' : 'Orden'));
            $changeOutput[] = [
                $original['codigo_local'], $original['nombre'], $original['usuario_login'], $original['grupo_ruta'],
                $original['orden_visita'], $adjusted['usuario_login'], $leaveUnassigned ? 'SIN RUTA' : $adjusted['grupo_ruta'],
                $adjusted['orden_visita'], $type,
            ];
        }
        $changeSheet = $spreadsheet->createSheet();
        $changeSheet->setTitle(uniqueRouteSheetTitle($spreadsheet, 'Cambios Cliente'));
        $changeSheet->fromArray($changeHeaders, null, 'A1');
        if ($changeOutput) {
            $changeSheet->fromArray($changeOutput, null, 'A2');
            foreach ($changeOutput as $index => $outputRow) {
                $changeSheet->setCellValueExplicit('A' . ($index + 2), (string)$outputRow[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        styleRouteOutputSheet($changeSheet, 'I', count($changeOutput) + 1);
        setRouteSheetWidths($changeSheet, ['A' => 16, 'B' => 30, 'C' => 20, 'D' => 20, 'E' => 14, 'F' => 20, 'G' => 20, 'H' => 14, 'I' => 18]);

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($planningSheet));
        $filename = 'planificacion_ajustada_set_' . $setId . '_' . date('Ymd_His') . '.xlsx';
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    } catch (Throwable $e) {
        apiJson(['ok' => false, 'message' => 'No fue posible generar la planificación ajustada: ' . $e->getMessage()], 422);
    }
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

if ($action === 'download' || $action === 'export' || $action === 'rejections') {
    $setId = (int)($_GET['id_set'] ?? 0);
    $set = getAccessibleSet($db, $setId, $sessionCompanyId, $sessionDivisionId, $canManage);
    if (!$set) {
        apiJson(['ok' => false, 'message' => 'El set no existe o no está autorizado.'], 404);
    }

    if ($action === 'download') {
        $storedPath = routeSetStoredFilePath($setId, (string)($set['archivo_original'] ?? ''));
        if ($storedPath !== null && is_file($storedPath) && is_readable($storedPath)) {
            $extension = strtolower((string)pathinfo($storedPath, PATHINFO_EXTENSION));
            $contentTypes = [
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xls' => 'application/vnd.ms-excel',
                'csv' => 'text/csv; charset=UTF-8',
            ];
            $downloadName = str_replace(["\r", "\n", '"'], '', basename((string)$set['archivo_original']));
            if ($downloadName === '') {
                $downloadName = 'planificacion_rutas_' . $setId . '.' . $extension;
            }
            $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $downloadName);
            $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($asciiName !== false ? $asciiName : $downloadName));

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($storedPath));
            header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
            header('Cache-Control: private, no-store, no-cache, must-revalidate');
            readfile($storedPath);
            exit;
        }
    }

    $isRejections = $action === 'rejections';
    $baseExportRows = null;
    if (!$isRejections && $set['tipo_contenido'] === 'rutas_base') {
        try {
            [$spreadsheet] = loadPublishedRouteWorkbook($set);
            $baseExportRows = readPublishedRouteData($db, $set, $spreadsheet)['base_rows'];
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (Throwable $e) {
            apiJson(['ok' => false, 'message' => 'No fue posible exportar las rutas base: ' . $e->getMessage()], 422);
        }
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    $filename = ($isRejections ? 'rechazos_set_rutas_' : 'set_rutas_') . $setId . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit('No fue posible generar la descarga.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    if ($baseExportRows !== null) {
        fputcsv($output, ['CODIGO LOCAL', 'NOMBRE LOCAL', 'DIRECCION', 'COMUNA', 'LAT', 'LNG', 'USUARIO', 'NOMBRE USUARIO', 'GRUPO RUTA', 'ORDEN VISITA'], ';');
        foreach ($baseExportRows as $row) {
            fputcsv($output, [$row['codigo_local'], $row['nombre'], $row['direccion'], $row['comuna'], $row['lat'], $row['lng'], $row['usuario_login'], $row['usuario_nombre'], $row['grupo_ruta'], $row['orden_visita']], ';');
        }
    } elseif (!$isRejections) {
        $headers = ['SET', 'ESTADO', 'DIVISION', 'SUBDIVISION', 'CODIGO LOCAL', 'NOMBRE LOCAL', 'DIRECCION', 'COMUNA', 'LAT', 'LNG', 'USUARIO', 'NOMBRE USUARIO', 'FECHA VISITA', 'ORDEN VISITA'];
        fputcsv($output, $headers, ';');
        $rawUserSchema = routeDetailUserSchemaAvailable($db);
        $userExpression = $rawUserSchema
            ? "COALESCE(NULLIF(TRIM(rd.usuario_archivo), ''), NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')"
            : "COALESCE(NULLIF(TRIM(u.usuario), ''), 'SIN ASIGNAR')";
        $userNameExpression = $rawUserSchema
            ? "COALESCE(NULLIF(TRIM(rd.usuario_nombre_archivo), ''), NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)"
            : "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.apellido)), ''), $userExpression)";
        $userJoin = $rawUserSchema ? 'LEFT JOIN' : 'JOIN';
        $stmt = $db->prepare('SELECT l.codigo, l.nombre, COALESCE(NULLIF(l.direccion_google,\'\'), l.direccion) AS direccion,
                                    c.comuna, l.lat, l.lng, ' . $userExpression . ' AS usuario,
                                    ' . $userNameExpression . ' AS usuario_nombre,
                                    rd.fecha_visita, rd.orden_visita,
                                    d.nombre AS division, s.nombre AS subdivision
                             FROM rutas_excel_set_detalle rd
                             JOIN rutas_excel_sets rs ON rs.id = rd.id_set
                             JOIN division_empresa d ON d.id = rs.id_division
                             JOIN subdivision s ON s.id = rs.id_subdivision
                             JOIN local l ON l.id = rd.id_local
                             LEFT JOIN comuna c ON c.id = l.id_comuna
                             ' . $userJoin . ' usuario u ON u.id = rd.id_usuario
                             WHERE rd.id_set = ? ORDER BY rd.fecha_visita, usuario, rd.orden_visita');
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

if ($action === 'upload' || $action === 'replace') {
    requireManager($canManage);
    requireCsrf();
    $isReplace = $action === 'replace';
    $targetSet = null;
    if ($isReplace) {
        $targetSetId = (int)($_POST['id_set'] ?? 0);
        $targetSet = getAccessibleSet($db, $targetSetId, $sessionCompanyId, $sessionDivisionId, $canManage);
        if (!$targetSet) {
            apiJson(['ok' => false, 'message' => 'La planificación que deseas actualizar no existe o no está autorizada.'], 404);
        }
        $name = (string)$targetSet['nombre'];
        $description = (string)($targetSet['descripcion'] ?? '');
        $divisionId = (int)$targetSet['id_division'];
        $subdivisionId = (int)$targetSet['id_subdivision'];
        $status = (string)$targetSet['estado'];
    } else {
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
        $upload = readRouteSetUpload($spreadsheet);
    } catch (Throwable $e) {
        apiJson(['ok' => false, 'message' => 'No fue posible leer el archivo: ' . $e->getMessage()], 422);
    }
    $isBaseOnly = $upload['type'] === 'rutas_base';
    $planningSheetName = $upload['sheet_name'];
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    if (!$isBaseOnly) {
        try {
            ensureRouteDetailUserSchema($db);
        } catch (Throwable $e) {
            apiJson(['ok' => false, 'message' => $e->getMessage()], 500);
        }
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

    $validation = validateRouteSetRows($upload, $localMap, $userMap);
    $valid = $validation['valid'];
    $rejected = $validation['rejected'];
    unset($upload, $validation);
    if (!$valid) {
        apiJson(['ok' => false, 'message' => 'No hay filas válidas para crear el set.', 'rejections' => $rejected], 422);
    }

    $storedFilePath = null;
    $stagedFilePath = null;
    $previousFilePath = null;
    $backupFilePath = null;
    $finalFilePlaced = false;
    $db->begin_transaction();
    try {
        $finalizedAt = $status === 'finalizado' ? date('Y-m-d H:i:s') : null;
        $total = count($valid) + count($rejected);
        $validCount = count($valid);
        $rejectedCount = count($rejected);
        $companyId = (int)$scope['id_empresa'];
        if ($isReplace) {
            $setId = (int)$targetSet['id'];
            $previousFilePath = routeSetStoredFilePath($setId, (string)($targetSet['archivo_original'] ?? ''));
            foreach (['rutas_excel_set_detalle', 'rutas_excel_set_rechazos'] as $table) {
                $deleteStmt = $db->prepare("DELETE FROM $table WHERE id_set = ?");
                if (!$deleteStmt) { throw new RuntimeException($db->error); }
                $deleteStmt->bind_param('i', $setId);
                if (!$deleteStmt->execute()) { throw new RuntimeException($deleteStmt->error); }
                $deleteStmt->close();
            }
            $stmt = $db->prepare('UPDATE rutas_excel_sets
                SET archivo_original = ?, total_filas = ?, filas_validas = ?, filas_rechazadas = ?, updated_at = NOW()
                WHERE id = ?');
            $stmt->bind_param('siiii', $original, $total, $validCount, $rejectedCount, $setId);
            if (!$stmt->execute()) { throw new RuntimeException($stmt->error); }
            $stmt->close();
        } else {
            $stmt = $db->prepare('INSERT INTO rutas_excel_sets
                (nombre, descripcion, id_empresa, id_division, id_subdivision, estado, archivo_original, total_filas, filas_validas, filas_rechazadas, creado_por, finalized_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->bind_param('ssiiissiiiis', $name, $description, $companyId, $divisionId, $subdivisionId, $status, $original, $total, $validCount, $rejectedCount, $sessionUserId, $finalizedAt);
            if (!$stmt->execute()) { throw new RuntimeException($stmt->error); }
            $setId = (int)$db->insert_id;
            $stmt->close();
        }

        // El detalle guarda visitas con fecha; las rutas base se conservan en el Excel original.
        if (!$isBaseOnly) {
            $detailStmt = $db->prepare('INSERT INTO rutas_excel_set_detalle
            (id_set, id_local, id_usuario, usuario_archivo, usuario_nombre_archivo, fecha_visita, orden_visita)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
            if (!$detailStmt) { throw new RuntimeException($db->error); }
            foreach ($valid as [$localId, $userId, $user, $userName, $date, $order]) {
                $detailStmt->bind_param('iiisssi', $setId, $localId, $userId, $user, $userName, $date, $order);
                if (!$detailStmt->execute()) { throw new RuntimeException($detailStmt->error); }
            }
            $detailStmt->close();
        }
        if ($rejected) {
            $rejectStmt = $db->prepare('INSERT INTO rutas_excel_set_rechazos (id_set, fila_archivo, codigo_local, usuario_archivo, fecha_archivo, motivo) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rejected as [$rowNo, $code, $user, $rawDate, $reason]) {
                $rejectStmt->bind_param('iissss', $setId, $rowNo, $code, $user, $rawDate, $reason);
                if (!$rejectStmt->execute()) { throw new RuntimeException($rejectStmt->error); }
            }
            $rejectStmt->close();
        }
        $storageDirectory = ensureRouteSetStorageDirectory();
        $storedFilePath = $storageDirectory . DIRECTORY_SEPARATOR . 'set_' . $setId . '.' . $extension;
        if ($previousFilePath !== $storedFilePath && is_file($storedFilePath)) {
            throw new RuntimeException('Ya existe un archivo almacenado con la nueva extensión.');
        }
        $stagedFilePath = $storageDirectory . DIRECTORY_SEPARATOR . 'pending_' . $setId . '_' . bin2hex(random_bytes(8)) . '.tmp';
        if (!move_uploaded_file($tmp, $stagedFilePath)) {
            throw new RuntimeException('No fue posible conservar el archivo Excel original.');
        }
        if ($previousFilePath !== null && is_file($previousFilePath)) {
            $backupFilePath = $previousFilePath . '.backup_' . bin2hex(random_bytes(6));
            if (!rename($previousFilePath, $backupFilePath)) {
                throw new RuntimeException('No fue posible respaldar la versión anterior del archivo.');
            }
        }
        if (!rename($stagedFilePath, $storedFilePath)) {
            throw new RuntimeException('No fue posible publicar la nueva versión del archivo.');
        }
        $stagedFilePath = null;
        $finalFilePlaced = true;

        if (!$db->commit()) {
            throw new RuntimeException('No fue posible confirmar el set en la base de datos.');
        }
        if ($backupFilePath !== null && is_file($backupFilePath)) {
            @unlink($backupFilePath);
        }
        apiJson([
            'ok' => true,
            'message' => $isBaseOnly
                ? 'Rutas base publicadas sin fechas de visita y archivo original conservado.'
                : ($isReplace
                ? 'Planificación actualizada correctamente y archivo original reemplazado.'
                : 'Set creado correctamente y archivo original conservado.'),
            'id_set' => $setId,
            'valid_rows' => $validCount,
            'rejected_rows' => $rejectedCount,
            'planning_sheet' => $planningSheetName,
            'tipo_contenido' => $isBaseOnly ? 'rutas_base' : 'planificacion',
            'original_file' => $original,
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        if ($finalFilePlaced && $storedFilePath !== null && is_file($storedFilePath)) {
            @unlink($storedFilePath);
        }
        if ($backupFilePath !== null && $previousFilePath !== null && is_file($backupFilePath)) {
            @rename($backupFilePath, $previousFilePath);
        }
        if ($stagedFilePath !== null && is_file($stagedFilePath)) {
            @unlink($stagedFilePath);
        }
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

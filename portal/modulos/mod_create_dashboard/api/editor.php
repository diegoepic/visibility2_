<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store');

function editorJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function editorCanManage(string $profile): bool
{
    return in_array($profile, ['editor', 'coordinador', 'administrador', 'admin'], true);
}

function editorIsMc(mysqli $db, int $divisionId): bool
{
    if ($divisionId <= 0) return false;
    $stmt = $db->prepare('SELECT nombre FROM division_empresa WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtoupper(trim((string)($row['nombre'] ?? ''))) === 'MC';
}

function editorCanAccessDivision(mysqli $db, int $divisionId, int $sessionDivisionId, bool $canManage): bool
{
    return $canManage || editorIsMc($db, $sessionDivisionId) || ($sessionDivisionId > 0 && $divisionId === $sessionDivisionId);
}

function editorRequireCsrf(): void
{
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        editorJson(['ok' => false, 'message' => 'Token de seguridad inválido. Recarga la página.'], 400);
    }
}

function editorHasColumn(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function editorUpper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function editorReason(string $observation): string
{
    $normalized = str_replace('|', '-', $observation);
    $first = trim(explode('-', $normalized, 2)[0] ?? '');
    return editorUpper(str_replace('_', ' ', $first));
}

function editorAddState(array &$bucket, string $group, string $field, string $label, int $localId): void
{
    $label = trim($label);
    if ($label === '') return;
    $key = $group . '|' . $label;
    if (!isset($bucket[$key])) {
        $bucket[$key] = [
            'group' => $group,
            'field' => $field,
            'value' => $label,
            'label' => $label,
            'registros' => 0,
            '_locales' => [],
        ];
    }
    $bucket[$key]['registros']++;
    if ($localId > 0) $bucket[$key]['_locales'][$localId] = true;
}

function editorNormalizeAnswer(string $value): string
{
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if ($ascii !== false) $value = strtolower($ascii);
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function editorValidDate(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) return false;
    return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

function editorValidateScope(mysqli $db, int $divisionId, int $subdivisionId): ?array
{
    if ($subdivisionId === 0) {
        $stmt = $db->prepare('SELECT nombre AS division FROM division_empresa WHERE id = ? AND estado = 1 LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $divisionId);
        $stmt->execute();
        $scope = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if ($scope) $scope['subdivision'] = 'Todas las subdivisiones';
        return $scope;
    }
    $stmt = $db->prepare('SELECT d.nombre AS division, s.nombre AS subdivision
        FROM division_empresa d INNER JOIN subdivision s ON s.id_division = d.id
        WHERE d.id = ? AND s.id = ? AND d.estado = 1 LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $divisionId, $subdivisionId);
    $stmt->execute();
    $scope = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $scope;
}

function editorValidateFormScope(mysqli $db, int $formId, int $divisionId, int $subdivisionId): bool
{
    if ($formId === 0) return true;
    if ($formId < 0) return false;
    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $sql = 'SELECT 1 FROM formulario WHERE id = ? AND id_division = ?';
    if ($hasFormSubdivision && $subdivisionId > 0) $sql .= ' AND id_subdivision = ?';
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if ($hasFormSubdivision && $subdivisionId > 0) $stmt->bind_param('iii', $formId, $divisionId, $subdivisionId);
    else $stmt->bind_param('ii', $formId, $divisionId);
    $stmt->execute();
    $valid = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $valid;
}

function editorSurveyResponseRows(
    mysqli $db,
    int $divisionId,
    int $subdivisionId,
    int $formId,
    string $questionText,
    string $dateFrom = '',
    string $dateTo = ''
): array {
    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $allSubdivisions = $subdivisionId === 0;
    $userJoin = ($hasFormSubdivision || $allSubdivisions) ? '' : ' INNER JOIN usuario response_user ON response_user.id = fqr.id_usuario ';
    $scopeCondition = $hasFormSubdivision ? 'f.id_subdivision = ?' : 'response_user.id_subdivision = ?';
    $sql = "SELECT fqr.id_local, fqr.answer_text, fqr.valor, fqr.created_at,
                   COALESCE(chart_region.region, 'Sin región') AS region_name
            FROM formulario f
            INNER JOIN form_questions fp ON fp.id_formulario = f.id
            INNER JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
            LEFT JOIN local chart_local ON chart_local.id = fqr.id_local
            LEFT JOIN comuna chart_commune ON chart_commune.id = chart_local.id_comuna
            LEFT JOIN region chart_region ON chart_region.id = chart_commune.id_region
            {$userJoin}
            WHERE f.id_division = ?";
    if ($formId > 0) $sql .= ' AND f.id = ?';
    if (!$allSubdivisions) $sql .= " AND {$scopeCondition}";
    $sql .= ' AND TRIM(fp.question_text) = ?';
    if ($dateFrom !== '') $sql .= ' AND fqr.created_at >= ?';
    if ($dateTo !== '') $sql .= ' AND fqr.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('[perfect_store_editor][survey_rows] ' . $db->error);
        editorJson(['ok' => false, 'message' => 'No fue posible consultar las respuestas de la pregunta. Código: KPI-RESP'], 500);
    }
    $types = 'i';
    $params = [$divisionId];
    if ($formId > 0) { $types .= 'i'; $params[] = $formId; }
    if (!$allSubdivisions) { $types .= 'i'; $params[] = $subdivisionId; }
    $types .= 's'; $params[] = $questionText;
    if ($dateFrom !== '') { $types .= 's'; $params[] = $dateFrom; }
    if ($dateTo !== '') { $types .= 's'; $params[] = $dateTo; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function editorDistinctVisitedLocals(mysqli $db, int $divisionId, int $subdivisionId, int $formId, string $dateFrom): int
{
    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $allSubdivisions = $subdivisionId === 0;
    $userJoin = ($hasFormSubdivision || $allSubdivisions) ? '' : ' INNER JOIN usuario visit_user ON visit_user.id = fq.id_usuario ';
    $scopeCondition = $hasFormSubdivision ? 'f.id_subdivision = ?' : 'visit_user.id_subdivision = ?';
    $sql = "SELECT COUNT(DISTINCT fq.id_local) AS total
            FROM formularioQuestion fq
            INNER JOIN formulario f ON f.id = fq.id_formulario
            {$userJoin}
            WHERE f.id_division = ? AND fq.id_local > 0 AND fq.fechaVisita >= ?";
    if ($formId > 0) $sql .= ' AND f.id = ?';
    if (!$allSubdivisions) $sql .= " AND {$scopeCondition}";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('[perfect_store_editor][visited_total] ' . $db->error);
        editorJson(['ok' => false, 'message' => 'No fue posible calcular el total de visitas. Código: KPI-VISITAS'], 500);
    }
    $types = 'is';
    $params = [$divisionId, $dateFrom];
    if ($formId > 0) { $types .= 'i'; $params[] = $formId; }
    if (!$allSubdivisions) { $types .= 'i'; $params[] = $subdivisionId; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function editorActivityState(array $row): string
{
    $mode = strtolower(trim((string)($row['modalidad'] ?? '')));
    $stage = strtolower(trim((string)($row['etapa_material'] ?? '')));
    $value = (float)($row['valor'] ?? 0);
    if ($mode === 'implementacion_por_etapas') {
        return [
            'implementado' => 'IMPLEMENTADO', 'retirado' => 'RETIRADO',
            'entregado' => 'ENTREGADO', 'armado' => 'ARMADO',
        ][$stage] ?? 'SIN INICIAR';
    }
    if ($mode === 'retiro') return $value >= 1 ? 'RETIRADO' : 'NO RETIRADO';
    if ($mode === 'entrega') return $value >= 1 ? 'ENTREGADO' : 'NO ENTREGADO';
    return $value >= 1 ? 'IMPLEMENTADO' : 'NO IMPLEMENTADO';
}

function editorStateChartRows(
    mysqli $db,
    int $divisionId,
    int $subdivisionId,
    int $formId,
    string $dateFrom,
    string $dateTo = ''
): array {
    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $hasStage = editorHasColumn($db, 'formularioQuestion', 'etapa_material');
    $allSubdivisions = $subdivisionId === 0;
    $scopeJoin = ($hasFormSubdivision || $allSubdivisions) ? '' : ' INNER JOIN usuario chart_user ON chart_user.id = fq.id_usuario ';
    $scopeCondition = $hasFormSubdivision ? 'f.id_subdivision = ?' : 'chart_user.id_subdivision = ?';
    $stageColumn = $hasStage ? 'fq.etapa_material' : 'NULL';
    $sql = "SELECT fq.id_local, fq.fechaVisita, fq.fechaPropuesta, fq.valor, fq.pregunta,
                   fq.observacion, f.modalidad, {$stageColumn} AS etapa_material,
                   COALESCE(chart_region.region, 'Sin región') AS region_name
            FROM formularioQuestion fq
            INNER JOIN formulario f ON f.id = fq.id_formulario
            LEFT JOIN local chart_local ON chart_local.id = fq.id_local
            LEFT JOIN comuna chart_commune ON chart_commune.id = chart_local.id_comuna
            LEFT JOIN region chart_region ON chart_region.id = chart_commune.id_region
            {$scopeJoin}
            WHERE f.id_division = ? AND fq.id_local > 0";
    $types = 'i';
    $params = [$divisionId];
    if ($formId > 0) { $sql .= ' AND f.id = ?'; $types .= 'i'; $params[] = $formId; }
    if (!$allSubdivisions) { $sql .= " AND {$scopeCondition}"; $types .= 'i'; $params[] = $subdivisionId; }
    $sql .= ' AND (fq.fechaVisita >= ? OR fq.fechaPropuesta >= ?)';
    $types .= 'ss'; $params[] = $dateFrom; $params[] = $dateFrom;
    if ($dateTo !== '') {
        $sql .= ' AND (fq.fechaVisita < DATE_ADD(?, INTERVAL 1 DAY) OR fq.fechaPropuesta < DATE_ADD(?, INTERVAL 1 DAY))';
        $types .= 'ss'; $params[] = $dateTo; $params[] = $dateTo;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('[perfect_store_editor][chart_states] ' . $db->error);
        editorJson(['ok' => false, 'message' => 'No fue posible preparar los datos del gráfico. Código: GRA-EST'], 500);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log('[perfect_store_editor][chart_states_execute] ' . $stmt->error);
        $stmt->close();
        editorJson(['ok' => false, 'message' => 'No fue posible calcular el gráfico de estados.'], 500);
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function editorChartDate(string $value): string
{
    $date = substr(trim($value), 0, 10);
    return editorValidDate($date) ? $date : '';
}

function editorMatchStateChartRow(array $row, string $stateField, string $stateValue, string $dateFrom, string $dateTo): array
{
    $visitDate = editorChartDate((string)($row['fechaVisita'] ?? ''));
    $plannedDate = editorChartDate((string)($row['fechaPropuesta'] ?? ''));
    $isVisited = $visitDate !== '' && $visitDate >= $dateFrom && ($dateTo === '' || $visitDate <= $dateTo);
    $isPlannedByDate = $plannedDate !== '' && $plannedDate >= $dateFrom && ($dateTo === '' || $plannedDate <= $dateTo);
    $isPlanned = $isPlannedByDate || $isVisited;
    $matches = false;
    $bucketDate = $visitDate;
    if ($stateField === 'estado_planificacion') {
        $matches = $stateValue === 'PLANIFICADO' && $isPlanned;
        $bucketDate = $isPlannedByDate ? $plannedDate : $visitDate;
    } elseif ($stateField === 'estado_visita') {
        $calculated = $isVisited ? 'VISITADO' : ($isPlanned ? 'NO VISITADO' : '');
        $matches = $calculated === $stateValue;
        $bucketDate = $isVisited ? $visitDate : $plannedDate;
    } elseif ($stateField === 'estado_actividad') {
        $matches = $isVisited && editorActivityState($row) === $stateValue;
    } elseif ($stateField === 'motivo') {
        $question = strtolower(trim((string)($row['pregunta'] ?? '')));
        $value = (float)($row['valor'] ?? 0);
        $reason = ($value == 0.0 || in_array($question, ['en proceso', 'cancelado'], true))
            ? editorReason((string)($row['observacion'] ?? '')) : '';
        $matches = $isVisited && $reason === $stateValue;
    }
    return ['matches' => $matches, 'date' => $bucketDate];
}

function editorChartResult(array $buckets, string $dimension, string $unit, string $title): array
{
    $series = [];
    foreach ($buckets as $label => $bucket) {
        if (isset($bucket['_locals'])) $value = count($bucket['_locals']);
        elseif (isset($bucket['_values'])) {
            $values = $bucket['_values'];
            $metric = (string)($bucket['_metric'] ?? 'sum');
            $value = $metric === 'average' ? (count($values) ? array_sum($values) / count($values) : 0) : array_sum($values);
        } else $value = 0;
        $series[] = ['label' => (string)$label, 'value' => round((float)$value, 2), 'unit' => $unit];
    }
    if ($dimension === 'date') {
        if ($series) {
            $byDate = [];
            foreach ($series as $point) $byDate[$point['label']] = $point;
            $cursor = new DateTimeImmutable((string)min(array_keys($byDate)));
            $last = new DateTimeImmutable((string)max(array_keys($byDate)));
            $filled = [];
            while ($cursor <= $last) {
                $label = $cursor->format('Y-m-d');
                $filled[] = $byDate[$label] ?? ['label' => $label, 'value' => 0, 'unit' => $unit];
                $cursor = $cursor->modify('+1 day');
            }
            $series = $filled;
        }
        usort($series, static fn(array $a, array $b): int => $a['label'] <=> $b['label']);
    } else {
        usort($series, static function(array $a, array $b): int {
            $byValue = $b['value'] <=> $a['value'];
            return $byValue !== 0 ? $byValue : ($a['label'] <=> $b['label']);
        });
    }
    return ['ok' => true, 'title' => $title, 'dimension' => $dimension, 'unit' => $unit, 'series' => $series];
}

$connectionFile = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/visibility2/portal/con_.php';
if (!is_file($connectionFile)) {
    editorJson(['ok' => false, 'message' => 'No se encontró la conexión de Visibility.'], 500);
}
require_once $connectionFile;

$db = $conexion ?? $conn ?? $mysqli ?? null;
if (!($db instanceof mysqli)) {
    editorJson(['ok' => false, 'message' => 'No fue posible conectar con la base de datos.'], 500);
}
$db->set_charset('utf8mb4');

$profile = strtolower(trim((string)($_SESSION['perfil_nombre'] ?? '')));
$sessionUserId = (int)($_SESSION['usuario_id'] ?? 0);
$sessionCompanyId = (int)($_SESSION['empresa_id'] ?? 0);
$sessionDivisionId = (int)($_SESSION['division_id'] ?? 0);
$canManage = editorCanManage($profile);

if ($sessionUserId <= 0 || $profile === '') {
    editorJson(['ok' => false, 'message' => 'La sesión expiró. Vuelve a ingresar a Visibility.'], 401);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'catalogs')));

if ($action === 'catalogs') {
    $sql = 'SELECT id, nombre FROM division_empresa WHERE estado = 1';
    $params = [];
    $types = '';
    if ($sessionCompanyId > 0) {
        $sql .= ' AND id_empresa = ?';
        $params[] = $sessionCompanyId;
        $types .= 'i';
    }
    if (!$canManage && !editorIsMc($db, $sessionDivisionId)) {
        $sql .= ' AND id = ?';
        $params[] = $sessionDivisionId;
        $types .= 'i';
    }
    $sql .= ' ORDER BY nombre';
    $stmt = $db->prepare($sql);
    if (!$stmt) editorJson(['ok' => false, 'message' => 'No fue posible consultar las divisiones.'], 500);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $divisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisions = [];
    if ($divisionId > 0 && editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        $stmt = $db->prepare('SELECT id, nombre FROM subdivision WHERE id_division = ? ORDER BY nombre');
        if (!$stmt) editorJson(['ok' => false, 'message' => 'No fue posible consultar las subdivisiones.'], 500);
        $stmt->bind_param('i', $divisionId);
        $stmt->execute();
        $subdivisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    editorJson(['ok' => true, 'divisions' => $divisions, 'subdivisions' => $subdivisions]);
}

if ($action === 'activities') {
    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisionId = isset($_GET['id_subdivision']) ? (int)$_GET['id_subdivision'] : -1;
    if ($divisionId <= 0 || $subdivisionId < 0 || !editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage) || !editorValidateScope($db, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'El alcance para consultar actividades no es válido.'], 422);
    }
    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $sql = 'SELECT f.id, TRIM(f.nombre) AS nombre FROM formulario f WHERE f.id_division = ?';
    if ($hasFormSubdivision && $subdivisionId > 0) $sql .= ' AND f.id_subdivision = ?';
    $sql .= " AND f.nombre IS NOT NULL AND TRIM(f.nombre) <> '' ORDER BY f.nombre, f.id";
    $stmt = $db->prepare($sql);
    if (!$stmt) editorJson(['ok' => false, 'message' => 'No fue posible consultar las actividades.'], 500);
    if ($hasFormSubdivision && $subdivisionId > 0) $stmt->bind_param('ii', $divisionId, $subdivisionId);
    else $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    editorJson(['ok' => true, 'activities' => $activities]);
}

if ($action === 'question_options') {
    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisionId = isset($_GET['id_subdivision']) ? (int)$_GET['id_subdivision'] : -1;
    $formId = (int)($_GET['id_formulario'] ?? 0);
    $questionText = trim((string)($_GET['question_text'] ?? ''));
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    if ($divisionId <= 0 || $subdivisionId < 0 || $questionText === '' || !editorValidDate($dateFrom) || !editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        editorJson(['ok' => false, 'message' => 'La pregunta o el alcance no son válidos.'], 422);
    }
    if (!editorValidateScope($db, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'La división y subdivisión no corresponden.'], 422);
    }
    if (!editorValidateFormScope($db, $formId, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'La actividad seleccionada no corresponde al alcance.'], 422);
    }

    $rows = editorSurveyResponseRows($db, $divisionId, $subdivisionId, $formId, $questionText, $dateFrom);
    $optionMap = [];
    $numericCount = 0;
    $dateMin = null;
    $dateMax = null;
    foreach ($rows as $row) {
        $localId = (int)($row['id_local'] ?? 0);
        $answer = trim((string)($row['answer_text'] ?? ''));
        foreach (preg_split('/\s*;\s*/u', $answer) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $key = editorNormalizeAnswer($part);
            if (!isset($optionMap[$key])) $optionMap[$key] = ['label' => $part, 'registros' => 0, '_locales' => []];
            $optionMap[$key]['registros']++;
            if ($localId > 0) $optionMap[$key]['_locales'][$localId] = true;
        }
        $rawValue = $row['valor'] ?? null;
        if ($rawValue !== null && trim((string)$rawValue) !== '' && is_numeric($rawValue)) $numericCount++;
        $date = substr((string)($row['created_at'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            if ($dateMin === null || $date < $dateMin) $dateMin = $date;
            if ($dateMax === null || $date > $dateMax) $dateMax = $date;
        }
    }
    $options = [];
    foreach ($optionMap as $option) {
        $option['locales'] = count($option['_locales']);
        unset($option['_locales']);
        $options[] = $option;
    }
    usort($options, static fn(array $a, array $b): int => $b['registros'] <=> $a['registros']);
    $booleanKeys = ['si', 'no', 'yes', 'true', 'false'];
    $isBoolean = count($options) > 0 && count($options) <= 4;
    foreach ($options as $option) {
        if (!in_array(editorNormalizeAnswer((string)$option['label']), $booleanKeys, true)) $isBoolean = false;
    }
    $allOptionsNumeric = count($options) > 0;
    foreach ($options as $option) {
        if (!is_numeric(str_replace(',', '.', (string)$option['label']))) $allOptionsNumeric = false;
    }
    $type = $isBoolean ? 'boolean' : (($numericCount > 0 && (empty($options) || $allOptionsNumeric)) ? 'numeric' : 'multiple');

    editorJson([
        'ok' => true,
        'question_text' => $questionText,
        'type' => $type,
        'responses' => count($rows),
        'numeric_responses' => $numericCount,
        'options' => $options,
        'date_min' => $dateMin,
        'date_max' => $dateMax,
    ]);
}

if ($action === 'calculate_kpi') {
    editorRequireCsrf();
    $divisionId = (int)($_POST['id_division'] ?? 0);
    $subdivisionId = isset($_POST['id_subdivision']) ? (int)$_POST['id_subdivision'] : -1;
    $formId = (int)($_POST['id_formulario'] ?? 0);
    $questionText = trim((string)($_POST['question_text'] ?? ''));
    $metric = strtolower(trim((string)($_POST['metric'] ?? 'distinct_local')));
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $selectedAnswers = isset($_POST['selected_answers']) && is_array($_POST['selected_answers']) ? $_POST['selected_answers'] : [];
    if (!in_array($metric, ['distinct_local', 'ratio_visits', 'sum', 'average', 'min', 'max'], true)) $metric = 'distinct_local';
    if (!editorValidDate($dateFrom)) editorJson(['ok' => false, 'message' => 'Debes indicar la fecha inicial del informe.'], 422);
    foreach ([$dateFrom, $dateTo] as $date) {
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) editorJson(['ok' => false, 'message' => 'El rango de fecha no es válido.'], 422);
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) editorJson(['ok' => false, 'message' => 'La fecha desde no puede ser posterior a la fecha hasta.'], 422);
    if ($divisionId <= 0 || $subdivisionId < 0 || $questionText === '' || !editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        editorJson(['ok' => false, 'message' => 'La configuración del KPI no es válida.'], 422);
    }
    if (!editorValidateScope($db, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'La división y subdivisión no corresponden.'], 422);
    }
    if (!editorValidateFormScope($db, $formId, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'La actividad seleccionada no corresponde al alcance.'], 422);
    }

    $rows = editorSurveyResponseRows($db, $divisionId, $subdivisionId, $formId, $questionText, $dateFrom, $dateTo);
    $selectedNormalized = array_values(array_unique(array_filter(array_map(static fn($value): string => editorNormalizeAnswer((string)$value), $selectedAnswers))));
    $localIds = [];
    $numericValues = [];
    $matchedRecords = 0;
    foreach ($rows as $row) {
        $answerParts = array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/u', (string)($row['answer_text'] ?? '')) ?: [])));
        $answerKeys = array_map('editorNormalizeAnswer', $answerParts);
        $matchesAnswer = empty($selectedNormalized) || (bool)array_intersect($selectedNormalized, $answerKeys);
        if (!$matchesAnswer) continue;
        $matchedRecords++;
        $localId = (int)($row['id_local'] ?? 0);
        if ($localId > 0) $localIds[$localId] = true;
        $rawValue = $row['valor'] ?? null;
        if ($rawValue !== null && trim((string)$rawValue) !== '' && is_numeric($rawValue)) {
            $numericValues[] = (float)$rawValue;
        } elseif (count($answerParts) === 1 && is_numeric(str_replace(',', '.', $answerParts[0]))) {
            $numericValues[] = (float)str_replace(',', '.', $answerParts[0]);
        }
    }

    $denominator = null;
    if ($metric === 'ratio_visits') {
        $denominator = editorDistinctVisitedLocals($db, $divisionId, $subdivisionId, $formId, $dateFrom);
        $value = $denominator > 0 ? (count($localIds) / $denominator) * 100 : 0;
        $unit = '%';
    } elseif ($metric === 'distinct_local') {
        $value = count($localIds);
        $unit = 'locales';
    } elseif (empty($numericValues)) {
        $value = 0;
        $unit = 'sin valores';
    } elseif ($metric === 'sum') {
        $value = array_sum($numericValues);
        $unit = 'suma';
    } elseif ($metric === 'average') {
        $value = array_sum($numericValues) / count($numericValues);
        $unit = 'promedio';
    } elseif ($metric === 'min') {
        $value = min($numericValues);
        $unit = 'mínimo';
    } else {
        $value = max($numericValues);
        $unit = 'máximo';
    }
    $rounded = is_float($value) ? round($value, 2) : $value;
    $formatted = number_format((float)$rounded, fmod((float)$rounded, 1.0) !== 0.0 ? 2 : 0, ',', '.');
    editorJson([
        'ok' => true, 'value' => $rounded, 'formatted' => $formatted, 'unit' => $unit,
        'distinct_locales' => count($localIds), 'matched_records' => $matchedRecords,
        'denominator_visits' => $denominator,
    ]);
}

if ($action === 'calculate_chart') {
    editorRequireCsrf();
    $divisionId = (int)($_POST['id_division'] ?? 0);
    $subdivisionId = isset($_POST['id_subdivision']) ? (int)$_POST['id_subdivision'] : -1;
    $formId = (int)($_POST['id_formulario'] ?? 0);
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $sourceKind = strtolower(trim((string)($_POST['source_kind'] ?? 'state')));
    $dimension = strtolower(trim((string)($_POST['dimension'] ?? 'region')));
    $metric = strtolower(trim((string)($_POST['metric'] ?? 'distinct_local')));
    if (!in_array($sourceKind, ['state', 'survey'], true)) $sourceKind = 'state';
    if (!in_array($dimension, ['region', 'date'], true)) $dimension = 'region';
    if (!in_array($metric, ['distinct_local', 'sum', 'average'], true)) $metric = 'distinct_local';
    if ($divisionId <= 0 || $subdivisionId < 0 || !editorValidDate($dateFrom)
        || ($dateTo !== '' && !editorValidDate($dateTo)) || ($dateTo !== '' && $dateFrom > $dateTo)
        || !editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        editorJson(['ok' => false, 'message' => 'La configuración o el rango de fecha del gráfico no es válido.'], 422);
    }
    if (!editorValidateScope($db, $divisionId, $subdivisionId)
        || !editorValidateFormScope($db, $formId, $divisionId, $subdivisionId)) {
        editorJson(['ok' => false, 'message' => 'El alcance del gráfico no corresponde a la división seleccionada.'], 422);
    }

    $buckets = [];
    if ($sourceKind === 'state') {
        $allowedFields = ['estado_planificacion', 'estado_visita', 'estado_actividad', 'motivo'];
        $postedFields = isset($_POST['state_fields']) && is_array($_POST['state_fields']) ? $_POST['state_fields'] : [$_POST['state_field'] ?? ''];
        $postedValues = isset($_POST['state_values']) && is_array($_POST['state_values']) ? $_POST['state_values'] : [$_POST['state_value'] ?? ''];
        $definitions = [];
        foreach (array_slice($postedFields, 0, 8) as $index => $postedField) {
            $field = trim((string)$postedField);
            $value = editorUpper(trim((string)($postedValues[$index] ?? '')));
            if (in_array($field, $allowedFields, true) && $value !== '') $definitions[] = ['field' => $field, 'value' => $value];
        }
        if (!$definitions) {
            editorJson(['ok' => false, 'message' => 'Selecciona el estado que deseas graficar.'], 422);
        }
        $rows = editorStateChartRows($db, $divisionId, $subdivisionId, $formId, $dateFrom, $dateTo);
        $datasetBuckets = array_fill(0, count($definitions), []);
        foreach ($rows as $row) {
            foreach ($definitions as $index => $definition) {
                $match = editorMatchStateChartRow($row, $definition['field'], $definition['value'], $dateFrom, $dateTo);
                if (!$match['matches']) continue;
                $label = $dimension === 'date' ? (string)$match['date'] : trim((string)($row['region_name'] ?? 'Sin región'));
                if ($label === '') continue;
                $datasetBuckets[$index][$label]['_locals'][(int)$row['id_local']] = true;
            }
        }
        $datasets = [];
        foreach ($definitions as $index => $definition) {
            $result = editorChartResult($datasetBuckets[$index], $dimension, 'locales', $definition['value']);
            $datasets[] = ['label' => $definition['value'], 'unit' => 'locales', 'series' => $result['series']];
        }
        $firstSeries = $datasets[0]['series'] ?? [];
        editorJson([
            'ok' => true, 'title' => implode(' vs ', array_column($definitions, 'value')),
            'dimension' => $dimension, 'unit' => 'locales', 'series' => $firstSeries, 'datasets' => $datasets,
        ]);
    }

    $questionText = trim((string)($_POST['question_text'] ?? ''));
    $selectedAnswers = isset($_POST['selected_answers']) && is_array($_POST['selected_answers']) ? $_POST['selected_answers'] : [];
    if ($questionText === '') editorJson(['ok' => false, 'message' => 'Selecciona la pregunta que deseas graficar.'], 422);
    $selectedNormalized = array_values(array_unique(array_filter(array_map(static fn($value): string => editorNormalizeAnswer((string)$value), $selectedAnswers))));
    $rows = editorSurveyResponseRows($db, $divisionId, $subdivisionId, $formId, $questionText, $dateFrom, $dateTo);
    foreach ($rows as $row) {
        $answerParts = array_values(array_filter(array_map('trim', preg_split('/\s*;\s*/u', (string)($row['answer_text'] ?? '')) ?: [])));
        $answerKeys = array_map('editorNormalizeAnswer', $answerParts);
        if ($selectedNormalized && !(bool)array_intersect($selectedNormalized, $answerKeys)) continue;
        $date = editorChartDate((string)($row['created_at'] ?? ''));
        $label = $dimension === 'date' ? $date : trim((string)($row['region_name'] ?? 'Sin región'));
        if ($label === '') continue;
        if ($metric === 'distinct_local') {
            $localId = (int)($row['id_local'] ?? 0);
            if ($localId > 0) $buckets[$label]['_locals'][$localId] = true;
            continue;
        }
        $rawValue = $row['valor'] ?? null;
        $numericValue = null;
        if ($rawValue !== null && trim((string)$rawValue) !== '' && is_numeric($rawValue)) $numericValue = (float)$rawValue;
        elseif (count($answerParts) === 1 && is_numeric(str_replace(',', '.', $answerParts[0]))) $numericValue = (float)str_replace(',', '.', $answerParts[0]);
        if ($numericValue === null) continue;
        $buckets[$label]['_values'][] = $numericValue;
        $buckets[$label]['_metric'] = $metric;
    }
    $answerTitle = $selectedAnswers ? implode(' · ', array_map('strval', $selectedAnswers)) : $questionText;
    $unit = $metric === 'distinct_local' ? 'locales' : ($metric === 'average' ? 'promedio' : 'suma');
    editorJson(editorChartResult($buckets, $dimension, $unit, $answerTitle));
}

if ($action === 'questions') {
    $divisionId = (int)($_GET['id_division'] ?? 0);
    $subdivisionId = isset($_GET['id_subdivision']) ? (int)$_GET['id_subdivision'] : -1;
    $formId = (int)($_GET['id_formulario'] ?? 0);
    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    if ($divisionId <= 0 || $subdivisionId < 0 || !editorValidDate($dateFrom) || !editorCanAccessDivision($db, $divisionId, $sessionDivisionId, $canManage)) {
        editorJson(['ok' => false, 'message' => 'El alcance seleccionado no está autorizado.'], 403);
    }

    $scope = editorValidateScope($db, $divisionId, $subdivisionId);
    if (!$scope) editorJson(['ok' => false, 'message' => 'La división y subdivisión no corresponden.'], 422);
    if (!editorValidateFormScope($db, $formId, $divisionId, $subdivisionId)) editorJson(['ok' => false, 'message' => 'La actividad seleccionada no corresponde al alcance.'], 422);
    if ($formId > 0) {
        $formStmt = $db->prepare('SELECT nombre FROM formulario WHERE id = ? LIMIT 1');
        if (!$formStmt) editorJson(['ok' => false, 'message' => 'No fue posible validar la actividad.'], 500);
        $formStmt->bind_param('i', $formId);
        $formStmt->execute();
        $scope['activity'] = (string)($formStmt->get_result()->fetch_assoc()['nombre'] ?? 'Actividad seleccionada');
        $formStmt->close();
    } else {
        $scope['activity'] = 'Todas las actividades / gestiones';
    }

    $hasFormSubdivision = editorHasColumn($db, 'formulario', 'id_subdivision');
    $hasStage = editorHasColumn($db, 'formularioQuestion', 'etapa_material');
    $allSubdivisions = $subdivisionId === 0;
    $scopeJoin = ($hasFormSubdivision || $allSubdivisions) ? '' : ' INNER JOIN usuario scope_user ON scope_user.id = fq.id_usuario ';
    $scopeCondition = $hasFormSubdivision ? 'f.id_subdivision = ?' : 'scope_user.id_subdivision = ?';
    $stageColumn = $hasStage ? 'fq.etapa_material' : 'NULL';

    // Consulta simple: los estados se calculan en PHP para evitar diferencias entre MySQL y MariaDB.
    $rawSql = "SELECT fq.id_local, fq.id_usuario, info_local.id_comuna, info_comuna.id_region,
                      fq.fechaVisita, fq.fechaPropuesta, fq.material, f.modalidad, {$stageColumn} AS etapa_material,
                      fq.valor, fq.valor_propuesto, fq.pregunta, fq.observacion
               FROM formularioQuestion fq
               INNER JOIN formulario f ON f.id = fq.id_formulario
               INNER JOIN local info_local ON info_local.id = fq.id_local
               LEFT JOIN comuna info_comuna ON info_comuna.id = info_local.id_comuna
               {$scopeJoin}
               WHERE f.id_division = ?";
    if ($formId > 0) $rawSql .= ' AND f.id = ?';
    if (!$allSubdivisions) $rawSql .= " AND {$scopeCondition}";
    $rawSql .= ' AND (fq.fechaVisita >= ? OR fq.fechaPropuesta >= ?)';
    $stmt = $db->prepare($rawSql);
    if (!$stmt) {
        error_log('[perfect_store_editor][raw_states] ' . $db->error);
        editorJson(['ok' => false, 'message' => 'No fue posible leer los datos base de estados. Código: EST-BASE'], 500);
    }
    $rawTypes = 'i';
    $rawParams = [$divisionId];
    if ($formId > 0) { $rawTypes .= 'i'; $rawParams[] = $formId; }
    if (!$allSubdivisions) { $rawTypes .= 'i'; $rawParams[] = $subdivisionId; }
    $rawTypes .= 'ss'; $rawParams[] = $dateFrom; $rawParams[] = $dateFrom;
    $stmt->bind_param($rawTypes, ...$rawParams);
    if (!$stmt->execute()) {
        error_log('[perfect_store_editor][raw_states_execute] ' . $stmt->error);
        $stmt->close();
        editorJson(['ok' => false, 'message' => 'No fue posible consultar los datos base de estados.'], 500);
    }
    $rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $bucket = [];
    $materialBucket = [];
    $informationSets = ['communes' => [], 'regions' => [], 'users' => []];
    foreach ($rawRows as $row) {
        $localId = (int)($row['id_local'] ?? 0);
        $date = trim((string)($row['fechaVisita'] ?? ''));
        $plannedDate = trim((string)($row['fechaPropuesta'] ?? ''));
        $isVisited = $date !== '' && substr($date, 0, 10) !== '0000-00-00' && substr($date, 0, 10) >= $dateFrom;
        $isPlannedByDate = $plannedDate !== '' && substr($plannedDate, 0, 10) !== '0000-00-00' && substr($plannedDate, 0, 10) >= $dateFrom;
        // Todo local visitado pertenece al universo planificado, incluso si su fecha propuesta quedó vacía o anterior.
        $isPlanned = $isPlannedByDate || $isVisited;
        if ($isPlanned) editorAddState($bucket, 'Planificación', 'estado_planificacion', 'PLANIFICADO', $localId);
        if ($isVisited || $isPlanned) editorAddState($bucket, 'Estado visita', 'estado_visita', $isVisited ? 'VISITADO' : 'NO VISITADO', $localId);
        if ($isVisited || $isPlanned) {
            $material = editorUpper(trim((string)($row['material'] ?? '')));
            if ($material !== '') {
                $materialKey = editorNormalizeAnswer($material);
                if (!isset($materialBucket[$materialKey])) {
                    $materialBucket[$materialKey] = [
                        'group' => 'Materiales', 'field' => 'material', 'value' => $material,
                        'label' => $material, 'registros' => 0, '_locales' => [],
                        'implemented_value' => 0.0, 'planned_value' => 0.0,
                    ];
                }
                $materialBucket[$materialKey]['registros']++;
                if ($localId > 0) $materialBucket[$materialKey]['_locales'][$localId] = true;
                $materialBucket[$materialKey]['implemented_value'] += (float)($row['valor'] ?? 0);
                $materialBucket[$materialKey]['planned_value'] += (float)($row['valor_propuesto'] ?? 0);
            }
            $communeId = (int)($row['id_comuna'] ?? 0);
            $regionId = (int)($row['id_region'] ?? 0);
            $userId = (int)($row['id_usuario'] ?? 0);
            if ($communeId > 0) $informationSets['communes'][$communeId] = true;
            if ($regionId > 0) $informationSets['regions'][$regionId] = true;
            if ($userId > 0) $informationSets['users'][$userId] = true;
        }
        if (!$isVisited) continue;

        $mode = strtolower(trim((string)($row['modalidad'] ?? '')));
        $stage = strtolower(trim((string)($row['etapa_material'] ?? '')));
        $question = strtolower(trim((string)($row['pregunta'] ?? '')));
        $value = (float)($row['valor'] ?? 0);

        if ($mode === 'implementacion_por_etapas') {
            $activityState = [
                'implementado' => 'IMPLEMENTADO', 'retirado' => 'RETIRADO',
                'entregado' => 'ENTREGADO', 'armado' => 'ARMADO',
            ][$stage] ?? 'SIN INICIAR';
        } elseif ($mode === 'retiro') {
            $activityState = $value >= 1 ? 'RETIRADO' : 'NO RETIRADO';
        } elseif ($mode === 'entrega') {
            $activityState = $value >= 1 ? 'ENTREGADO' : 'NO ENTREGADO';
        } else {
            $activityState = $value >= 1 ? 'IMPLEMENTADO' : 'NO IMPLEMENTADO';
        }
        editorAddState($bucket, 'Estado actividad', 'estado_actividad', $activityState, $localId);

        if ($value == 0.0 || in_array($question, ['en proceso', 'cancelado'], true)) {
            $reason = editorReason((string)($row['observacion'] ?? ''));
            editorAddState($bucket, 'Motivo no implementación', 'motivo', $reason, $localId);
        }
    }

    $states = [];
    $plannedLocales = 0;
    foreach ($bucket as $item) {
        $item['locales'] = count($item['_locales']);
        if ($item['field'] === 'estado_planificacion' && $item['value'] === 'PLANIFICADO') $plannedLocales = $item['locales'];
        unset($item['_locales']);
        $states[] = $item;
    }
    // El catálogo del constructor debe mostrar también KPI con valor cero para
    // poder compararlos (por ejemplo, VISITADO vs NO VISITADO).
    $standardStates = [
        ['group' => 'Planificación', 'field' => 'estado_planificacion', 'value' => 'PLANIFICADO', 'label' => 'PLANIFICADO'],
        ['group' => 'Estado visita', 'field' => 'estado_visita', 'value' => 'VISITADO', 'label' => 'VISITADO'],
        ['group' => 'Estado visita', 'field' => 'estado_visita', 'value' => 'NO VISITADO', 'label' => 'NO VISITADO'],
        ['group' => 'Estado actividad', 'field' => 'estado_actividad', 'value' => 'IMPLEMENTADO', 'label' => 'IMPLEMENTADO'],
        ['group' => 'Estado actividad', 'field' => 'estado_actividad', 'value' => 'NO IMPLEMENTADO', 'label' => 'NO IMPLEMENTADO'],
    ];
    $availableStateKeys = [];
    foreach ($states as $item) $availableStateKeys[$item['field'] . '|' . $item['value']] = true;
    foreach ($standardStates as $standardState) {
        $key = $standardState['field'] . '|' . $standardState['value'];
        if (isset($availableStateKeys[$key])) continue;
        $states[] = $standardState + ['registros' => 0, 'locales' => 0];
    }
    usort($states, static fn(array $a, array $b): int => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

    $materials = [];
    foreach ($materialBucket as $item) {
        $item['locales'] = count($item['_locales']);
        $item['implemented_value'] = round((float)$item['implemented_value'], 2);
        $item['planned_value'] = round((float)$item['planned_value'], 2);
        $item['implementation_ratio'] = $item['planned_value'] > 0
            ? round(($item['implemented_value'] / $item['planned_value']) * 100, 2)
            : 0;
        unset($item['_locales']);
        $materials[] = $item;
    }
    usort($materials, static fn(array $a, array $b): int => $a['label'] <=> $b['label']);

    $information = [
        ['id' => 'unique_communes', 'label' => 'Comunas únicas', 'value' => count($informationSets['communes']), 'unit' => 'comunas', 'field' => 'local.id_comuna'],
        ['id' => 'unique_regions', 'label' => 'Regiones únicas', 'value' => count($informationSets['regions']), 'unit' => 'regiones', 'field' => 'comuna.id_region'],
        ['id' => 'unique_users', 'label' => 'Usuarios únicos', 'value' => count($informationSets['users']), 'unit' => 'usuarios', 'field' => 'formularioQuestion.id_usuario'],
    ];

    // Encuestas: misma fuente utilizada por descargar_encuesta_csv.php.
    $surveyUserJoin = ' INNER JOIN form_question_responses survey_response ON survey_response.id_form_question = fp.id ';
    if (!$hasFormSubdivision && !$allSubdivisions) {
        $surveyUserJoin .= ' INNER JOIN usuario survey_user ON survey_user.id = survey_response.id_usuario ';
    }
    $surveyScopeCondition = $hasFormSubdivision ? 'f.id_subdivision = ?' : 'survey_user.id_subdivision = ?';
    $surveySql = "SELECT MIN(fp.id) AS id, TRIM(fp.question_text) AS question_text,
                         COUNT(DISTINCT f.id) AS formularios
                  FROM formulario f
                  INNER JOIN form_questions fp ON fp.id_formulario = f.id
                  {$surveyUserJoin}
                  WHERE f.id_division = ?";
    if ($formId > 0) $surveySql .= ' AND f.id = ?';
    if (!$allSubdivisions) $surveySql .= " AND {$surveyScopeCondition}";
    $surveySql .= " AND survey_response.created_at >= ?
                    AND fp.question_text IS NOT NULL AND TRIM(fp.question_text) <> ''
                  GROUP BY TRIM(fp.question_text)
                  ORDER BY question_text";
    $stmt = $db->prepare($surveySql);
    if (!$stmt) {
        error_log('[perfect_store_editor][survey] ' . $db->error);
        editorJson(['ok' => false, 'message' => 'No fue posible preparar las preguntas de encuesta. Código: ENC-BASE'], 500);
    }
    $surveyTypes = 'i';
    $surveyParams = [$divisionId];
    if ($formId > 0) { $surveyTypes .= 'i'; $surveyParams[] = $formId; }
    if (!$allSubdivisions) { $surveyTypes .= 'i'; $surveyParams[] = $subdivisionId; }
    $surveyTypes .= 's'; $surveyParams[] = $dateFrom;
    $stmt->bind_param($surveyTypes, ...$surveyParams);
    $stmt->execute();
    $surveyQuestions = array_map(static function(array $row): array {
        return [
            'id' => (int)$row['id'], 'question_text' => (string)$row['question_text'],
            'formularios' => (int)$row['formularios'],
        ];
    }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    editorJson(['ok' => true, 'scope' => $scope, 'states' => $states, 'materials' => $materials, 'information' => $information, 'planned_locales' => $plannedLocales, 'survey_questions' => $surveyQuestions]);
}

if ($action === 'upload') {
    editorRequireCsrf();
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        editorJson(['ok' => false, 'message' => 'Selecciona una imagen.'], 400);
    }
    $file = $_FILES['image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        editorJson(['ok' => false, 'message' => 'No fue posible recibir la imagen.'], 400);
    }
    if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
        editorJson(['ok' => false, 'message' => 'La imagen supera el máximo de 10 MB.'], 413);
    }
    $mime = class_exists('finfo')
        ? (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name'])
        : (string)mime_content_type((string)$file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        editorJson(['ok' => false, 'message' => 'Formato no permitido. Usa JPG, PNG o WEBP.'], 415);
    }
    $uploadDir = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        editorJson(['ok' => false, 'message' => 'No fue posible crear la carpeta de imágenes.'], 500);
    }
    $filename = 'asset_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $uploadDir . '/' . $filename)) {
        editorJson(['ok' => false, 'message' => 'No fue posible guardar la imagen.'], 500);
    }
    editorJson(['ok' => true, 'url' => 'uploads/' . $filename, 'name' => basename((string)$file['name'])]);
}

editorJson(['ok' => false, 'message' => 'Acción no reconocida.'], 404);

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

function panelJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!($db instanceof mysqli)) {
    panelJson(['ok' => false, 'message' => 'No fue posible conectar con la base de datos.'], 500);
}
$db->set_charset('utf8mb4');

$sessionUserId = (int)($_SESSION['usuario_id'] ?? 0);
$sessionCompanyId = (int)($_SESSION['empresa_id'] ?? 0);
$sessionDivisionId = (int)($_SESSION['division_id'] ?? 0);

if ($sessionUserId <= 0) {
    panelJson(['ok' => false, 'message' => 'La sesión expiró. Vuelve a ingresar.'], 401);
}

function panelIsMcDivision(mysqli $db, int $divisionId): bool
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

function panelCanAccessDivision(mysqli $db, int $divisionId, int $sessionDivisionId): bool
{
    return panelIsMcDivision($db, $sessionDivisionId)
        || ($sessionDivisionId > 0 && $divisionId === $sessionDivisionId);
}

function panelDivisionExists(mysqli $db, int $divisionId, int $companyId): bool
{
    $sql = 'SELECT id FROM division_empresa WHERE id = ? AND estado = 1';
    if ($companyId > 0) {
        $sql .= ' AND id_empresa = ?';
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    if ($companyId > 0) {
        $stmt->bind_param('ii', $divisionId, $companyId);
    } else {
        $stmt->bind_param('i', $divisionId);
    }
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'catalogs')));

if ($action === 'catalogs') {
    $isMc = panelIsMcDivision($db, $sessionDivisionId);
    $divisions = [];
    $sql = 'SELECT id, nombre FROM division_empresa WHERE estado = 1';
    $params = [];
    $types = '';
    if ($sessionCompanyId > 0) {
        $sql .= ' AND id_empresa = ?';
        $params[] = $sessionCompanyId;
        $types .= 'i';
    }
    if (!$isMc) {
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
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $divisions[] = ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']];
    }
    $stmt->close();

    $subdivisions = [];
    if ($divisions) {
        $ids = implode(',', array_map(static fn(array $row): int => $row['id'], $divisions));
        $result = $db->query("SELECT id, id_division, nombre FROM subdivision WHERE id_division IN ({$ids}) ORDER BY nombre");
        while ($row = $result->fetch_assoc()) {
            $subdivisions[] = [
                'id' => (int)$row['id'],
                'id_division' => (int)$row['id_division'],
                'nombre' => (string)$row['nombre'],
            ];
        }
        $result->free();
    }

    panelJson([
        'ok' => true,
        'data' => [
            'divisions' => $divisions,
            'subdivisions' => $subdivisions,
            'default_division' => !$isMc ? $sessionDivisionId : 0,
        ],
    ]);
}

if ($action !== 'data') {
    panelJson(['ok' => false, 'message' => 'Acción no válida.'], 400);
}

$divisionId = (int)($_GET['id_division'] ?? 0);
$subdivisionId = (int)($_GET['id_subdivision'] ?? 0);
$campaignStatus = (int)($_GET['estado'] ?? 0);

if ($divisionId <= 0) {
    panelJson(['ok' => false, 'message' => 'Selecciona una división.'], 400);
}
if (!in_array($campaignStatus, [0, 1, 3], true)) {
    panelJson(['ok' => false, 'message' => 'El estado de campaña no es válido.'], 400);
}
if (!panelCanAccessDivision($db, $divisionId, $sessionDivisionId)
    || !panelDivisionExists($db, $divisionId, $sessionCompanyId)) {
    panelJson(['ok' => false, 'message' => 'No tienes acceso a la división seleccionada.'], 403);
}
if ($subdivisionId > 0) {
    $stmt = $db->prepare('SELECT id FROM subdivision WHERE id = ? AND id_division = ? LIMIT 1');
    $stmt->bind_param('ii', $subdivisionId, $divisionId);
    $stmt->execute();
    $validSubdivision = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$validSubdivision) {
        panelJson(['ok' => false, 'message' => 'La subdivisión no pertenece a la división seleccionada.'], 400);
    }
}

$where = ["f.id_division = {$divisionId}", 'f.tipo IN (1,3)'];
if ($subdivisionId > 0) {
    $where[] = "f.id_subdivision = {$subdivisionId}";
}
if ($campaignStatus > 0) {
    $where[] = "f.estado = {$campaignStatus}";
}
$whereSql = implode(' AND ', $where);
$normalizedQuestion = "UPPER(REPLACE(TRIM(COALESCE(fq.pregunta, '')), '_', ' '))";

$query = "
    SELECT
        CONCAT_WS('|', f.id, fq.id_local, fq.id_usuario) AS `key`,
        COALESCE(NULLIF(TRIM(u.usuario), ''), 'SIN EJECUTOR') AS usuario,
        DATE_FORMAT(
            MAX(
                CASE
                    WHEN fq.fechaPropuesta IS NOT NULL
                     AND TRIM(CAST(fq.fechaPropuesta AS CHAR)) <> ''
                     AND LEFT(CAST(fq.fechaPropuesta AS CHAR), 10) <> '0000-00-00'
                    THEN fq.fechaPropuesta
                    ELSE NULL
                END
            ),
            '%Y-%m-%d'
        ) AS fecha_planificada,
        MAX(CASE WHEN fq.fechaVisita IS NOT NULL
                  AND LEFT(CAST(fq.fechaVisita AS CHAR), 10) <> '0000-00-00'
                 THEN 1 ELSE 0 END) AS visitado,
        MAX(CASE WHEN UPPER(TRIM(COALESCE(fq.pregunta, ''))) IN
                         ('AUDITORIA','IMPLEMENTACION','IMPL/AUD','IMPLEMENTADO_AUDITADO','SOLO_AUDITORIA','SOLO_AUDITADO','SOLO_IMPLEMENTADO')
                 THEN 1 ELSE 0 END) AS ejecutado,
        CASE
            WHEN MAX(CASE WHEN UPPER(TRIM(COALESCE(fq.pregunta, ''))) IN
                                  ('AUDITORIA','IMPLEMENTACION','IMPL/AUD','IMPLEMENTADO_AUDITADO','SOLO_AUDITORIA','SOLO_AUDITADO','SOLO_IMPLEMENTADO')
                          THEN 1 ELSE 0 END) = 1
            THEN 'GESTIONADO'
            ELSE COALESCE(
                NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF({$normalizedQuestion}, '') ORDER BY fq.id DESC SEPARATOR '||'), '||', 1), ''),
                'SIN GESTIÓN'
            )
        END AS estado
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    INNER JOIN usuario u ON u.id = fq.id_usuario
    WHERE {$whereSql}
    GROUP BY f.id, fq.id_local, fq.id_usuario, u.usuario
    ORDER BY fecha_planificada ASC, usuario ASC
";

$result = $db->query($query);
if (!$result) {
    error_log('[panel_visitas_data] ' . $db->error);
    panelJson([
        'ok' => false,
        'message' => 'No fue posible consultar los registros del panel. Detalle: ' . $db->error,
    ], 500);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'key' => (string)$row['key'],
        'usuario' => (string)$row['usuario'],
        'fecha_planificada' => (string)($row['fecha_planificada'] ?? ''),
        'visitado' => (bool)$row['visitado'],
        'ejecutado' => (bool)$row['ejecutado'],
        'estado' => (string)$row['estado'],
    ];
}
$result->free();

panelJson(['ok' => true, 'data' => $rows, 'total' => count($rows)]);
